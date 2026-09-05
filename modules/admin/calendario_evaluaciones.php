<?php
session_start();
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/TenantGuard.php';

// Verificar que sea admin o director
if (!isset($_SESSION['user_id']) || ($_SESSION['rol'] != 'admin' && $_SESSION['rol'] != 'director')) {
    header("Location: " . BASE_URL . "/login.php");
    exit;
}

$database = new Database();
$db = $database->getConnection();
$user_id = $_SESSION['user_id'];
$tid = TenantGuard::id();

// ===== FILTROS =====
$filtro_anno = $_GET['anno'] ?? date('Y');
$filtro_periodo = $_GET['periodo'] ?? '';
$filtro_grado = $_GET['grado'] ?? '';
$filtro_seccion = $_GET['seccion'] ?? '';
$filtro_profesor = $_GET['profesor'] ?? '';
$filtro_tipo = $_GET['tipo'] ?? 'todos';
$filtro_fecha_inicio = $_GET['fecha_inicio'] ?? date('Y-m-01');
$filtro_fecha_fin = $_GET['fecha_fin'] ?? date('Y-m-t');

// ===== OBTENER DATOS PARA FILTROS =====
$query_grados = "SELECT id, nombre FROM tbl_grado ORDER BY nivel, nombre";
$grados = $db->query($query_grados)->fetchAll(PDO::FETCH_ASSOC);

$query_secciones = "SELECT s.id, s.nombre, g.nombre as grado_nombre
                    FROM tbl_seccion s JOIN tbl_grado g ON s.id_grado = g.id
                    WHERE s.id_institucion = :tid
                    ORDER BY g.nombre, s.nombre";
$stmt_sec = $db->prepare($query_secciones);
$stmt_sec->bindValue(':tid', $tid, PDO::PARAM_INT);
$stmt_sec->execute();
$secciones = $stmt_sec->fetchAll(PDO::FETCH_ASSOC);

$query_profesores = "SELECT p.id, per.primer_nombre, per.primer_apellido
                     FROM tbl_profesor p JOIN tbl_persona per ON p.id_persona = per.id
                     JOIN tbl_usuario u ON per.id_usuario = u.id
                     WHERE u.estado = 1 AND p.id_institucion = :tid ORDER BY per.primer_apellido";
$stmt_prof = $db->prepare($query_profesores);
$stmt_prof->bindValue(':tid', $tid, PDO::PARAM_INT);
$stmt_prof->execute();
$profesores = $stmt_prof->fetchAll(PDO::FETCH_ASSOC);

$periodos = [1 => '1er Trimestre', 2 => '2do Trimestre', 3 => '3er Trimestre', 4 => '4to Trimestre'];

// ===== CONSULTA PRINCIPAL DE EVALUACIONES =====
$query = "SELECT 
    act.id, act.titulo, act.descripcion, act.tipo, act.fecha_programada, act.fecha_limite, act.nota_maxima, act.estado,
    asig.nombre as asignatura, asig.codigo,
    per.primer_nombre as prof_nombre, per.primer_apellido as prof_apellido,
    g.nombre as grado, s.nombre as seccion,
    COUNT(DISTINCT ea.id) as total_entregas,
    COUNT(DISTINCT CASE WHEN ea.estado_entrega = 'calificado' THEN ea.id END) as total_calificadas
    FROM tbl_actividad act
    JOIN tbl_asignacion_docente ad ON act.id_asignacion_docente = ad.id
    JOIN tbl_asignatura asig ON ad.id_asignatura = asig.id
    JOIN tbl_profesor prof ON ad.id_profesor = prof.id
    JOIN tbl_persona per ON prof.id_persona = per.id
    JOIN tbl_seccion s ON ad.id_seccion = s.id
    JOIN tbl_grado g ON s.id_grado = g.id
    LEFT JOIN tbl_entrega_actividad ea ON act.id = ea.id_actividad
    WHERE ad.anno = :anno AND asig.id_institucion = :tid";

$params = [':anno' => $filtro_anno, ':tid' => $tid];

// Filtros dinámicos
if ($filtro_periodo) { $query .= " AND ad.id_periodo = :periodo"; $params[':periodo'] = $filtro_periodo; }
if ($filtro_grado) { $query .= " AND g.id = :grado"; $params[':grado'] = $filtro_grado; }
if ($filtro_seccion) { $query .= " AND s.id = :seccion"; $params[':seccion'] = $filtro_seccion; }
if ($filtro_profesor) { $query .= " AND prof.id = :profesor"; $params[':profesor'] = $filtro_profesor; }
if ($filtro_tipo != 'todos') { $query .= " AND act.tipo = :tipo"; $params[':tipo'] = $filtro_tipo; }

$query .= " AND (act.fecha_programada BETWEEN :f_ini AND :f_fin OR act.fecha_limite BETWEEN :f_ini2 AND :f_fin2)";
$params[':f_ini'] = $filtro_fecha_inicio . ' 00:00:00';
$params[':f_fin'] = $filtro_fecha_fin . ' 23:59:59';
$params[':f_ini2'] = $filtro_fecha_inicio . ' 00:00:00';
$params[':f_fin2'] = $filtro_fecha_fin . ' 23:59:59';

$query .= " GROUP BY act.id ORDER BY act.fecha_programada ASC";

$stmt = $db->prepare($query);
$stmt->execute($params);
$evaluaciones = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ===== ESTADÍSTICAS GLOBALES =====
$total_eval = count($evaluaciones);
$total_examenes = count(array_filter($evaluaciones, fn($e) => $e['tipo'] == 'examen'));
$total_tareas = count(array_filter($evaluaciones, fn($e) => $e['tipo'] == 'tarea'));
$pendientes_hoy = count(array_filter($evaluaciones, fn($e) => date('Y-m-d', strtotime($e['fecha_limite'] ?? $e['fecha_programada'])) == date('Y-m-d')));
$profesores_involucrados = count(array_unique(array_column($evaluaciones, 'prof_nombre') + array_column($evaluaciones, 'prof_apellido')));

// ===== PREPARAR EVENTOS PARA FULLCALENDAR =====
$eventos_calendar = [];
foreach ($evaluaciones as $eval) {
    $fecha = $eval['fecha_limite'] ?? $eval['fecha_programada'];
    $color = match($eval['tipo']) {
        'examen' => '#e63946',
        'tarea' => '#f72585',
        'laboratorio' => '#4cc9f0',
        'proyecto' => '#7209b7',
        default => '#6c757d'
    };
    
    $eventos_calendar[] = [
        'id' => $eval['id'],
        'title' => $eval['titulo'],
        'start' => $fecha,
        'color' => $color,
        'extendedProps' => [
            'tipo' => ucfirst($eval['tipo']),
            'asignatura' => $eval['asignatura'],
            'codigo' => $eval['codigo'],
            'profesor' => $eval['prof_nombre'] . ' ' . $eval['prof_apellido'],
            'grado_seccion' => $eval['grado'] . ' - ' . $eval['seccion'],
            'descripcion' => $eval['descripcion'] ?? '',
            'nota_maxima' => $eval['nota_maxima'],
            'entregas' => $eval['total_entregas'],
            'calificadas' => $eval['total_calificadas'],
            'estado' => $eval['estado']
        ]
    ];
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Calendario Institucional - Educación Plus</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/fullcalendar@5.11.3/main.min.css" rel="stylesheet">
    <style>
        :root { --primary: #2c3e50; --secondary: #3498db; --success: #2ecc71; --warning: #f39c12; --danger: #e74c3c; --sidebar-width: 250px; }
        body { font-family: 'Segoe UI', sans-serif; background: #f4f6f9; }
        .sidebar { position: fixed; top: 0; left: 0; height: 100vh; width: var(--sidebar-width); background: var(--primary); color: white; padding-top: 20px; z-index: 1000; }
        .sidebar .nav-link { color: rgba(255,255,255,0.8); padding: 12px 20px; margin: 2px 0; border-radius: 8px; }
        .sidebar .nav-link:hover, .sidebar .nav-link.active { background: rgba(255,255,255,0.15); color: white; }
        .main-content { margin-left: var(--sidebar-width); padding: 20px; }
        .card-custom { background: white; border-radius: 10px; box-shadow: 0 2px 10px rgba(0,0,0,0.05); margin-bottom: 20px; }
        .stat-card { background: white; border-radius: 10px; padding: 15px; box-shadow: 0 2px 8px rgba(0,0,0,0.05); text-align: center; border-left: 4px solid var(--secondary); }
        #calendar { background: white; border-radius: 10px; padding: 15px; box-shadow: 0 2px 10px rgba(0,0,0,0.05); }
        .fc { font-family: inherit; }
        .fc-toolbar-title { font-size: 1.2rem !important; font-weight: 600; }
        .fc-event { border: none !important; border-radius: 5px !important; padding: 3px 6px !important; font-size: 0.75rem !important; cursor: pointer; }
        .modal-header.admin { background: var(--primary); color: white; }
        .badge-tipo { padding: 4px 10px; border-radius: 15px; font-size: 0.8rem; font-weight: 600; }
        .badge-examen { background: #ffe5e5; color: #c0392b; }
        .badge-tarea { background: #ffe5f0; color: #c2185b; }
        .badge-laboratorio { background: #e3f2fd; color: #1976d2; }
        .badge-proyecto { background: #f3e5f5; color: #7b1fa2; }
        @media (max-width: 992px) { .sidebar { transform: translateX(-100%); } .sidebar.active { transform: translateX(0); } .main-content { margin-left: 0; } }
    </style>
</head>
<body>
    <!-- Sidebar -->
    <div class="sidebar" id="sidebar">
        <div class="text-center mb-4 px-3">
            <h5><i class="fas fa-shield-alt"></i> Panel Admin</h5>
            <small class="text-white-50">Educación Plus</small>
        </div>
        <nav class="nav flex-column px-2">
            <a class="nav-link" href="../../index.php"><i class="fas fa-tachometer-alt"></i> Dashboard</a>
            <a class="nav-link" href="gestionar_estudiantes.php"><i class="fas fa-user-graduate"></i> Estudiantes</a>
            <a class="nav-link" href="gestionar_profesores.php"><i class="fas fa-chalkboard-teacher"></i> Profesores</a>
            <a class="nav-link" href="gestionar_grados.php"><i class="fas fa-layer-group"></i> Grados/Secciones</a>
            <a class="nav-link" href="gestionar_asignaturas.php"><i class="fas fa-book"></i> Asignaturas</a>
            <a class="nav-link" href="horario_clases.php"><i class="fas fa-calendar-week"></i> Horario</a>
            <a class="nav-link" href="carnet_estudiantil.php"><i class="fas fa-id-card"></i> Carnet Estudiantil</a>
            <a class="nav-link active" href="calendario_evaluaciones.php"><i class="fas fa-calendar-alt"></i> Calendario Eval.</a>
            <a class="nav-link" href="manual_convivencia.php"><i class="fas fa-handshake"></i> Convivencia Escolar</a>
            <a class="nav-link" href="../../logout.php"><i class="fas fa-sign-out-alt"></i> Cerrar Sesión</a>
        </nav>
    </div>

    <!-- Main Content -->
    <div class="main-content">
        <!-- Header -->
        <div class="d-flex justify-content-between align-items-center mb-4">
            <div>
                <h2><i class="fas fa-calendar-check"></i> Calendario Institucional de Evaluaciones</h2>
                <p class="text-muted mb-0">Supervisión global de actividades académicas • <?= $filtro_anno ?></p>
            </div>
            <button class="btn btn-outline-primary btn-sm d-lg-none" id="sidebarToggle"><i class="fas fa-bars"></i></button>
        </div>

        <!-- Stats -->
        <div class="row g-3 mb-4">
            <div class="col-md-3 col-6">
                <div class="stat-card">
                    <h3 class="mb-1"><?= $total_eval ?></h3>
                    <small class="text-muted">Evaluaciones Totales</small>
                </div>
            </div>
            <div class="col-md-3 col-6">
                <div class="stat-card" style="border-left-color: var(--danger);">
                    <h3 class="mb-1"><?= $total_examenes ?></h3>
                    <small class="text-muted">Exámenes Programados</small>
                </div>
            </div>
            <div class="col-md-3 col-6">
                <div class="stat-card" style="border-left-color: var(--warning);">
                    <h3 class="mb-1"><?= $total_tareas ?></h3>
                    <small class="text-muted">Tareas Asignadas</small>
                </div>
            </div>
            <div class="col-md-3 col-6">
                <div class="stat-card" style="border-left-color: var(--success);">
                    <h3 class="mb-1"><?= $pendientes_hoy ?></h3>
                    <small class="text-muted">Vencen Hoy</small>
                </div>
            </div>
        </div>

        <!-- Filtros -->
        <div class="card-custom p-3 mb-4">
            <form method="GET" class="row g-2">
                <div class="col-md-2 col-6">
                    <select name="anno" class="form-select form-select-sm">
                        <?php for($y=date('Y'); $y>=date('Y')-3; $y--): ?>
                        <option value="<?= $y ?>" <?= $filtro_anno == $y ? 'selected' : '' ?>><?= $y ?></option>
                        <?php endfor; ?>
                    </select>
                </div>
                <div class="col-md-2 col-6">
                    <select name="periodo" class="form-select form-select-sm">
                        <option value="">Todos los períodos</option>
                        <?php foreach($periodos as $k => $v): ?>
                        <option value="<?= $k ?>" <?= $filtro_periodo == $k ? 'selected' : '' ?>><?= $v ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2 col-6">
                    <select name="grado" class="form-select form-select-sm">
                        <option value="">Todos los grados</option>
                        <?php foreach($grados as $g): ?>
                        <option value="<?= $g['id'] ?>" <?= $filtro_grado == $g['id'] ? 'selected' : '' ?>><?= $g['nombre'] ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2 col-6">
                    <select name="seccion" class="form-select form-select-sm">
                        <option value="">Todas las secciones</option>
                        <?php foreach($secciones as $s): ?>
                        <option value="<?= $s['id'] ?>" <?= $filtro_seccion == $s['id'] ? 'selected' : '' ?>><?= $s['nombre'] ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2 col-6">
                    <select name="profesor" class="form-select form-select-sm">
                        <option value="">Todos los profesores</option>
                        <?php foreach($profesores as $p): ?>
                        <option value="<?= $p['id'] ?>" <?= $filtro_profesor == $p['id'] ? 'selected' : '' ?>><?= $p['primer_apellido'] ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2 col-6">
                    <select name="tipo" class="form-select form-select-sm">
                        <option value="todos">Todos los tipos</option>
                        <option value="examen" <?= $filtro_tipo=='examen'?'selected':'' ?>>Exámenes</option>
                        <option value="tarea" <?= $filtro_tipo=='tarea'?'selected':'' ?>>Tareas</option>
                        <option value="laboratorio" <?= $filtro_tipo=='laboratorio'?'selected':'' ?>>Laboratorios</option>
                        <option value="proyecto" <?= $filtro_tipo=='proyecto'?'selected':'' ?>>Proyectos</option>
                    </select>
                </div>
                <div class="col-md-3 col-6">
                    <input type="date" name="fecha_inicio" class="form-control form-control-sm" value="<?= $filtro_fecha_inicio ?>">
                </div>
                <div class="col-md-3 col-6">
                    <input type="date" name="fecha_fin" class="form-control form-control-sm" value="<?= $filtro_fecha_fin ?>">
                </div>
                <div class="col-md-3 col-6 d-flex gap-2">
                    <button type="submit" class="btn btn-primary btn-sm flex-grow-1"><i class="fas fa-filter"></i> Filtrar</button>
                    <a href="calendario_evaluaciones.php" class="btn btn-outline-secondary btn-sm"><i class="fas fa-redo"></i></a>
                </div>
            </form>
        </div>

        <!-- Calendario -->
        <div class="card-custom">
            <div class="card-body">
                <div id="calendar"></div>
            </div>
        </div>
    </div>

    <!-- Modal Detalle Admin -->
    <div class="modal fade" id="modalDetalle" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header admin">
                    <h5 class="modal-title"><i class="fas fa-clipboard-list"></i> Detalle de Evaluación</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="row">
                        <div class="col-md-8">
                            <h4 id="m_titulo" class="mb-2"></h4>
                            <p class="text-muted mb-3">
                                <span class="badge-tipo" id="m_tipo"></span>
                                <span id="m_asignatura" class="fw-bold"></span> 
                                <small class="text-muted">(<span id="m_codigo"></span>)</small>
                            </p>
                            <p id="m_desc" class="mb-3"></p>
                        </div>
                        <div class="col-md-4">
                            <div class="bg-light rounded p-3 mb-2">
                                <small class="text-muted d-block">Profesor</small>
                                <strong id="m_profesor"></strong>
                            </div>
                            <div class="bg-light rounded p-3 mb-2">
                                <small class="text-muted d-block">Grado / Sección</small>
                                <strong id="m_grado_sec"></strong>
                            </div>
                            <div class="bg-light rounded p-3 mb-2">
                                <small class="text-muted d-block">Fecha Límite</small>
                                <strong id="m_fecha"></strong>
                            </div>
                            <div class="bg-light rounded p-3">
                                <small class="text-muted d-block">Estado</small>
                                <strong id="m_estado" class="text-capitalize"></strong>
                            </div>
                        </div>
                    </div>
                    <hr>
                    <div class="row text-center">
                        <div class="col-md-4">
                            <h5 id="m_entregas" class="mb-0">0</h5>
                            <small class="text-muted">Entregas Recibidas</small>
                        </div>
                        <div class="col-md-4">
                            <h5 id="m_calificadas" class="mb-0">0</h5>
                            <small class="text-muted">Calificadas</small>
                        </div>
                        <div class="col-md-4">
                            <h5 id="m_nota_max" class="mb-0">0</h5>
                            <small class="text-muted">Nota Máxima</small>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cerrar</button>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/fullcalendar@5.11.3/main.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/fullcalendar@5.11.3/locales/es.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            document.getElementById('sidebarToggle')?.addEventListener('click', () => {
                document.getElementById('sidebar').classList.toggle('active');
            });

            const calendarEl = document.getElementById('calendar');
            const calendar = new FullCalendar.Calendar(calendarEl, {
                initialView: 'dayGridMonth',
                locale: 'es',
                headerToolbar: {
                    left: 'prev,next today',
                    center: 'title',
                    right: 'dayGridMonth,timeGridWeek,listMonth'
                },
                events: <?= json_encode($eventos_calendar, JSON_UNESCAPED_UNICODE) ?>,
                eventClick: function(info) {
                    const d = info.event.extendedProps;
                    document.getElementById('m_titulo').textContent = info.event.title;
                    document.getElementById('m_tipo').textContent = d.tipo;
                    document.getElementById('m_tipo').className = `badge-tipo badge-${d.tipo.toLowerCase()}`;
                    document.getElementById('m_asignatura').textContent = d.asignatura;
                    document.getElementById('m_codigo').textContent = d.codigo;
                    document.getElementById('m_desc').textContent = d.descripcion || 'Sin descripción detallada.';
                    document.getElementById('m_profesor').textContent = d.profesor;
                    document.getElementById('m_grado_sec').textContent = d.grado_seccion;
                    document.getElementById('m_fecha').textContent = new Date(info.event.start).toLocaleDateString('es-ES', { weekday: 'long', year: 'numeric', month: 'long', day: 'numeric', hour: '2-digit', minute:'2-digit' });
                    document.getElementById('m_estado').textContent = d.estado;
                    document.getElementById('m_entregas').textContent = d.entregas;
                    document.getElementById('m_calificadas').textContent = d.calificadas;
                    document.getElementById('m_nota_max').textContent = d.nota_maxima;
                    new bootstrap.Modal(document.getElementById('modalDetalle')).show();
                },
                height: 'auto'
            });
            calendar.render();
        });
    </script>
</body>
</html>