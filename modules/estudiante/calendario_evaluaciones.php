<?php
session_start();
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/TenantGuard.php';

// Verificar que sea estudiante
if (!isset($_SESSION['user_id']) || $_SESSION['rol'] != 'estudiante') {
    header("Location: " . BASE_URL . "/login.php");
    exit;
}

$database = new Database();
$db = $database->getConnection();
$user_id = $_SESSION['user_id'];
$tid = TenantGuard::id();

// Obtener datos del estudiante
$query = "SELECT
          e.id as id_estudiante, e.nie,
          p.primer_nombre, p.primer_apellido,
          g.id as id_grado, g.nombre as grado_nombre,
          s.id as id_seccion, s.nombre as seccion_nombre,
          m.id as id_matricula, m.anno
          FROM tbl_estudiante e
          JOIN tbl_persona p ON e.id_persona = p.id
          JOIN tbl_matricula m ON e.id = m.id_estudiante
          JOIN tbl_seccion s ON m.id_seccion = s.id
          JOIN tbl_grado g ON s.id_grado = g.id
          WHERE p.id_usuario = :user_id
          AND e.id_institucion = :tid
          AND m.estado = 'activo'
          LIMIT 1";

$stmt = $db->prepare($query);
$stmt->bindValue(':user_id', $user_id, PDO::PARAM_INT);
$stmt->bindValue(':tid', $tid, PDO::PARAM_INT);
$stmt->execute();
$estudiante = $stmt->fetch(PDO::FETCH_ASSOC);

// Si no tiene matrícula activa
if (!$estudiante) {
    ?>
    <!DOCTYPE html>
    <html lang="es">
    <head>
        <meta charset="UTF-8">
        <title>Sin Matrícula - Educación Plus</title>
        <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    </head>
    <body class="bg-light">
        <div class="container mt-5">
            <div class="card shadow text-center py-5">
                <div class="card-body">
                    <i class="fas fa-exclamation-triangle fa-4x text-warning mb-3"></i>
                    <h4>Sin Matrícula Activa</h4>
                    <p class="text-muted">No tienes evaluaciones asignadas para este período.</p>
                    <a href="../../index.php" class="btn btn-primary">Volver al Inicio</a>
                </div>
            </div>
        </div>
    </body>
    </html>
    <?php
    exit;
}

// Extraer variables
$id_estudiante = $estudiante['id_estudiante'];
$id_matricula = $estudiante['id_matricula'];
$id_seccion = $estudiante['id_seccion'];
$anno = $estudiante['anno'];
// NOTA: ya no se filtra por "período" -- ver estudiante_dashboard.php.

// ===== OBTENER EVALUACIONES =====
$filtro_tipo = $_GET['tipo'] ?? 'todos';
$filtro_materia = $_GET['materia'] ?? '';
$filtro_fecha_inicio = $_GET['fecha_inicio'] ?? date('Y-m-01');
$filtro_fecha_fin = $_GET['fecha_fin'] ?? date('Y-m-t');

// Obtener materias para el filtro
$query_materias = "SELECT asig.id, asig.nombre 
                   FROM tbl_asignacion_docente ad
                   JOIN tbl_asignatura asig ON ad.id_asignatura = asig.id
                   WHERE ad.id_seccion = :id_seccion AND ad.anno = :anno
                   ORDER BY asig.nombre";
$stmt_materias = $db->prepare($query_materias);
$stmt_materias->execute([
    ':id_seccion' => $id_seccion,
    ':anno' => $anno
]);
$materias = $stmt_materias->fetchAll(PDO::FETCH_ASSOC);

// Consulta principal de evaluaciones
//
// Igual que en el resto de la sección estudiante: un examen calificado no
// deja fila en tbl_entrega_actividad, su nota vive en
// tbl_intento_examen. vw_logro_estudiante (migrations/2026_08_16_vista_
// logro_estudiante.sql) unifica ambas fuentes.
$query_evaluaciones = "SELECT
    act.id, act.id_examen, act.titulo, act.descripcion, act.tipo, act.fecha_programada, act.fecha_limite,
    act.nota_maxima, act.estado as estado_actividad,
    asig.nombre as asignatura, asig.codigo,
    per.primer_nombre as profesor_nombre, per.primer_apellido as profesor_apellido,
    v.id_registro_origen as id_entrega,
    v.nota_obtenida,
    v.estado_entrega,
    v.observacion_docente as retroalimentacion,
    CASE
        WHEN act.tipo = 'examen' THEN 'Examen'
        WHEN act.tipo = 'tarea' THEN 'Tarea'
        WHEN act.tipo = 'laboratorio' THEN 'Laboratorio'
        WHEN act.tipo = 'proyecto' THEN 'Proyecto'
        WHEN act.tipo = 'foro' THEN 'Foro'
        ELSE 'Actividad'
    END as tipo_label
    FROM tbl_actividad act
    JOIN tbl_asignacion_docente ad ON act.id_asignacion_docente = ad.id
    JOIN tbl_asignatura asig ON ad.id_asignatura = asig.id
    JOIN tbl_profesor prof ON ad.id_profesor = prof.id
    JOIN tbl_persona per ON prof.id_persona = per.id
    LEFT JOIN vw_logro_estudiante v ON v.id_actividad = act.id AND v.id_matricula = :id_matricula
    WHERE ad.id_seccion = :id_seccion
    AND ad.anno = :anno
    AND act.tipo IN ('examen', 'tarea', 'laboratorio', 'proyecto')
    AND act.estado IN ('publicado', 'activo')
    AND (act.fecha_programada BETWEEN :fecha_inicio AND :fecha_fin OR act.fecha_limite BETWEEN :fecha_inicio2 AND :fecha_fin2)";

$params = [
    ':id_matricula' => $id_matricula,
    ':id_seccion' => $id_seccion,
    ':anno' => $anno,
    ':fecha_inicio' => $filtro_fecha_inicio . ' 00:00:00',
    ':fecha_fin' => $filtro_fecha_fin . ' 23:59:59',
    ':fecha_inicio2' => $filtro_fecha_inicio . ' 00:00:00',
    ':fecha_fin2' => $filtro_fecha_fin . ' 23:59:59'
];

if ($filtro_tipo != 'todos') {
    $query_evaluaciones .= " AND act.tipo = :tipo";
    $params[':tipo'] = $filtro_tipo;
}

if (!empty($filtro_materia)) {
    $query_evaluaciones .= " AND asig.id = :materia";
    $params[':materia'] = $filtro_materia;
}

$query_evaluaciones .= " ORDER BY act.fecha_programada ASC";

$stmt_eval = $db->prepare($query_evaluaciones);
$stmt_eval->execute($params);
$evaluaciones = $stmt_eval->fetchAll(PDO::FETCH_ASSOC);

// Preparar eventos para FullCalendar
$eventos_calendar = [];
foreach ($evaluaciones as $eval) {
    $fecha = $eval['fecha_limite'] ?? $eval['fecha_programada'];
    
    // Determinar color según tipo y estado
    $color = '#4361ee'; // Default azul
    if ($eval['tipo'] == 'examen') $color = '#e63946';
    elseif ($eval['tipo'] == 'tarea') $color = '#f72585';
    elseif ($eval['tipo'] == 'laboratorio') $color = '#4cc9f0';
    elseif ($eval['tipo'] == 'proyecto') $color = '#7209b7';
    
    // Si ya está calificado, verde
    if ($eval['estado_entrega'] == 'calificado') $color = '#2ecc71';
    // Si está entregado pero no calificado, amarillo
    elseif ($eval['estado_entrega'] == 'entregado') $color = '#f39c12';
    // Si está atrasada, rojo oscuro
    elseif ($fecha < date('Y-m-d H:i:s') && $eval['estado_entrega'] != 'calificado') $color = '#c0392b';
    
    $eventos_calendar[] = [
        'title' => htmlspecialchars($eval['titulo']),
        'start' => $fecha,
        'end' => $eval['fecha_limite'] ? date('Y-m-d H:i:s', strtotime($eval['fecha_limite']) + 3600) : null,
        'color' => $color,
        'extendedProps' => [
            'id' => $eval['id'],
            'tipo' => $eval['tipo_label'],
            'asignatura' => htmlspecialchars($eval['asignatura']),
            'codigo' => htmlspecialchars($eval['codigo']),
            'profesor' => htmlspecialchars($eval['profesor_nombre'] . ' ' . $eval['profesor_apellido']),
            'descripcion' => htmlspecialchars($eval['descripcion'] ?? ''),
            'nota_maxima' => $eval['nota_maxima'],
            'nota_obtenida' => $eval['nota_obtenida'],
            'estado_entrega' => $eval['estado_entrega'],
            'retroalimentacion' => htmlspecialchars($eval['retroalimentacion'] ?? '')
        ]
    ];
}

// Estadísticas rápidas
$total_evaluaciones = count($evaluaciones);
$examenes_pendientes = count(array_filter($evaluaciones, fn($e) => $e['tipo'] == 'examen' && $e['estado_entrega'] != 'calificado'));
$tareas_pendientes = count(array_filter($evaluaciones, fn($e) => $e['tipo'] == 'tarea' && $e['estado_entrega'] != 'calificado'));
$proxima_evaluacion = !empty($evaluaciones) ? $evaluaciones[0] : null;
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Calendario de Evaluaciones - Educación Plus</title>
    
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/fullcalendar@5.11.3/main.min.css" rel="stylesheet">
    
    <style>
        :root { --primary: #4361ee; --secondary: #3f37c9; --success: #2ecc71; --warning: #f39c12; --danger: #e63946; --sidebar-width: 260px; }
        body { font-family: 'Segoe UI', sans-serif; background: #f5f7fa; }
        .sidebar { position: fixed; top: 0; left: 0; height: 100vh; width: var(--sidebar-width); background: linear-gradient(180deg, #1d3557, #2a4365); color: white; z-index: 1000; }
        .sidebar .nav-link { color: rgba(255,255,255,0.85); padding: 12px 20px; border-radius: 8px; margin: 2px 0; }
        .sidebar .nav-link:hover, .sidebar .nav-link.active { background: rgba(255,255,255,0.15); color: white; }
        .main-content { margin-left: var(--sidebar-width); padding: 20px 30px; }
        .card-custom { background: white; border-radius: 12px; box-shadow: 0 2px 12px rgba(0,0,0,0.08); margin-bottom: 20px; }
        .stat-card { background: white; border-radius: 12px; padding: 20px; box-shadow: 0 2px 8px rgba(0,0,0,0.08); text-align: center; }
        .stat-icon { width: 50px; height: 50px; border-radius: 12px; display: flex; align-items: center; justify-content: center; color: white; margin: 0 auto 10px; }
        .stat-icon.examen { background: linear-gradient(135deg, #e63946, #d90429); }
        .stat-icon.tarea { background: linear-gradient(135deg, #f72585, #b5179e); }
        .stat-icon.pendiente { background: linear-gradient(135deg, #f39c12, #d35400); }
        #calendar { background: white; border-radius: 12px; padding: 20px; box-shadow: 0 2px 12px rgba(0,0,0,0.08); }
        .fc { font-family: inherit; }
        .fc-toolbar-title { font-size: 1.3rem !important; font-weight: 600; }
        .fc-event { border: none !important; border-radius: 6px !important; padding: 4px 8px !important; font-size: 0.8rem !important; cursor: pointer; }
        .fc-event:hover { opacity: 0.9; transform: scale(1.02); transition: all 0.2s; }
        .event-badge { display: inline-block; padding: 2px 8px; border-radius: 10px; font-size: 0.7rem; font-weight: 600; margin-right: 5px; }
        .badge-examen { background: #ffe5e5; color: #c0392b; }
        .badge-tarea { background: #ffe5f0; color: #c2185b; }
        .badge-laboratorio { background: #e3f2fd; color: #1976d2; }
        .badge-proyecto { background: #f3e5f5; color: #7b1fa2; }
        .modal-header.examen { background: linear-gradient(135deg, #e63946, #d90429); color: white; }
        .modal-header.tarea { background: linear-gradient(135deg, #f72585, #b5179e); color: white; }
        .modal-header.laboratorio { background: linear-gradient(135deg, #4cc9f0, #4895ef); color: white; }
        .modal-header.proyecto { background: linear-gradient(135deg, #7209b7, #560bad); color: white; }
        .nota-badge { padding: 5px 15px; border-radius: 20px; font-weight: 600; font-size: 1.1rem; }
        .nota-excellent { background: #d4edda; color: #155724; }
        .nota-good { background: #cce5ff; color: #004085; }
        .nota-regular { background: #fff3cd; color: #856404; }
        .nota-fail { background: #f8d7da; color: #721c24; }
        .nota-pendiente { background: #e9ecef; color: #6c757d; }
        @media (max-width: 992px) { .sidebar { transform: translateX(-100%); } .sidebar.active { transform: translateX(0); } .main-content { margin-left: 0; } }
    </style>
</head>
<body>
    <!-- Sidebar -->
    <aside class="sidebar" id="sidebar">
        <div class="text-center p-3 border-bottom">
            <h5><i class="fas fa-graduation-cap"></i> Educación Plus</h5>
        </div>
        <div class="p-3 text-center border-bottom">
            <div class="fw-bold"><?= htmlspecialchars($estudiante['primer_nombre']) ?> <?= htmlspecialchars($estudiante['primer_apellido']) ?></div>
            <small class="text-white-50"><?= htmlspecialchars($estudiante['grado_nombre']) ?> - <?= htmlspecialchars($estudiante['seccion_nombre']) ?></small>
        </div>
        <nav class="nav flex-column p-2">
            <a class="nav-link" href="estudiante_dashboard.php"><i class="fas fa-home"></i> Dashboard</a>
            <a class="nav-link" href="mis_clases.php"><i class="fas fa-book"></i> Mis Clases</a>
            <a class="nav-link" href="actividades.php"><i class="fas fa-tasks"></i> Actividades</a>
            <a class="nav-link" href="mis_notas.php"><i class="fas fa-star"></i> Calificaciones</a>
            <a class="nav-link active" href="calendario_evaluaciones.php"><i class="fas fa-calendar-alt"></i> Calendario</a>
            <a class="nav-link" href="../../logout.php"><i class="fas fa-sign-out-alt"></i> Cerrar Sesión</a>
        </nav>
    </aside>

    <!-- Main Content -->
    <main class="main-content">
        <!-- Header -->
        <div class="d-flex justify-content-between align-items-center mb-4">
            <div>
                <h2><i class="fas fa-calendar-check"></i> Calendario de Evaluaciones</h2>
                <p class="text-muted mb-0"><?= htmlspecialchars($estudiante['grado_nombre']) ?> • <?= $anno ?></p>
            </div>
            <button class="btn btn-outline-primary btn-sm" id="sidebarToggle"><i class="fas fa-bars"></i></button>
        </div>

        <!-- Stats -->
        <div class="row g-3 mb-4">
            <div class="col-md-3">
                <div class="stat-card">
                    <div class="stat-icon examen"><i class="fas fa-file-alt"></i></div>
                    <h4 class="mb-0"><?= $examenes_pendientes ?></h4>
                    <small class="text-muted">Exámenes Pendientes</small>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stat-card">
                    <div class="stat-icon tarea"><i class="fas fa-clipboard-list"></i></div>
                    <h4 class="mb-0"><?= $tareas_pendientes ?></h4>
                    <small class="text-muted">Tareas Pendientes</small>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stat-card">
                    <div class="stat-icon pendiente"><i class="fas fa-clock"></i></div>
                    <h4 class="mb-0"><?= $total_evaluaciones ?></h4>
                    <small class="text-muted">Total Evaluaciones</small>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stat-card">
                    <div class="stat-icon" style="background: linear-gradient(135deg, var(--primary), var(--secondary));">
                        <i class="fas fa-bell"></i>
                    </div>
                    <h4 class="mb-0"><?= $proxima_evaluacion ? 'Próximo' : 'N/A' ?></h4>
                    <small class="text-muted"><?= $proxima_evaluacion ? date('d/m', strtotime($proxima_evaluacion['fecha_limite'] ?? $proxima_evaluacion['fecha_programada'])) : '-' ?></small>
                </div>
            </div>
        </div>

        <!-- Filtros -->
        <div class="card-custom p-3 mb-4">
            <form method="GET" class="row g-3">
                <div class="col-md-3">
                    <label class="form-label small text-muted">Tipo</label>
                    <select name="tipo" class="form-select" onchange="this.form.submit()">
                        <option value="todos" <?= $filtro_tipo == 'todos' ? 'selected' : '' ?>>Todos</option>
                        <option value="examen" <?= $filtro_tipo == 'examen' ? 'selected' : '' ?>>Exámenes</option>
                        <option value="tarea" <?= $filtro_tipo == 'tarea' ? 'selected' : '' ?>>Tareas</option>
                        <option value="laboratorio" <?= $filtro_tipo == 'laboratorio' ? 'selected' : '' ?>>Laboratorios</option>
                        <option value="proyecto" <?= $filtro_tipo == 'proyecto' ? 'selected' : '' ?>>Proyectos</option>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label small text-muted">Materia</label>
                    <select name="materia" class="form-select" onchange="this.form.submit()">
                        <option value="">Todas</option>
                        <?php foreach ($materias as $mat): ?>
                        <option value="<?= $mat['id'] ?>" <?= $filtro_materia == $mat['id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($mat['nombre']) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label small text-muted">Desde</label>
                    <input type="date" name="fecha_inicio" class="form-control" value="<?= $filtro_fecha_inicio ?>" onchange="this.form.submit()">
                </div>
                <div class="col-md-3">
                    <label class="form-label small text-muted">Hasta</label>
                    <input type="date" name="fecha_fin" class="form-control" value="<?= $filtro_fecha_fin ?>" onchange="this.form.submit()">
                </div>
                <div class="col-12 text-end">
                    <a href="calendario_evaluaciones.php" class="btn btn-outline-secondary btn-sm"><i class="fas fa-redo"></i> Limpiar filtros</a>
                </div>
            </form>
        </div>

        <!-- Calendario -->
        <div class="card-custom">
            <div class="card-body">
                <div id="calendar"></div>
            </div>
        </div>

        <!-- Lista de Próximas Evaluaciones (para móvil) -->
        <div class="card-custom d-lg-none mt-4">
            <div class="card-header bg-white py-3">
                <h5 class="mb-0"><i class="fas fa-list"></i> Próximas Evaluaciones</h5>
            </div>
            <div class="card-body p-0">
                <?php if (empty($evaluaciones)): ?>
                <p class="text-muted text-center py-4">No hay evaluaciones en este período.</p>
                <?php else: ?>
                <?php foreach (array_slice($evaluaciones, 0, 5) as $eval): 
                    $fecha = $eval['fecha_limite'] ?? $eval['fecha_programada'];
                    $dias = ceil((strtotime($fecha) - time()) / 86400);
                ?>
                <div class="p-3 border-bottom d-flex justify-content-between align-items-center">
                    <div>
                        <span class="event-badge badge-<?= $eval['tipo'] ?>"><?= $eval['tipo_label'] ?></span>
                        <strong class="d-block"><?= htmlspecialchars($eval['titulo']) ?></strong>
                        <small class="text-muted"><?= htmlspecialchars($eval['asignatura']) ?></small>
                    </div>
                    <div class="text-end">
                        <small class="d-block text-<?= $dias <= 2 ? 'danger' : 'muted' ?>">
                            <?= date('d/m', strtotime($fecha)) ?>
                        </small>
                        <?php if ($dias <= 3): ?>
                        <small class="text-danger fw-bold">¡<?= $dias ?>d!</small>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </main>

    <!-- Modal Detalle de Evaluación -->
    <div class="modal fade" id="modalEvaluacion" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header examen" id="modalHeader">
                    <h5 class="modal-title"><i class="fas fa-info-circle"></i> <span id="modalTitulo">Detalle</span></h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="row">
                        <div class="col-md-8">
                            <h4 id="modalEvaluacionTitulo" class="mb-2"></h4>
                            <p class="text-muted mb-3">
                                <span class="event-badge" id="modalTipoBadge"></span>
                                <span id="modalAsignatura"></span>
                            </p>
                            <p id="modalDescripcion" class="mb-3"></p>
                        </div>
                        <div class="col-md-4 text-end">
                            <div class="bg-light rounded p-3 mb-3">
                                <small class="text-muted d-block">Fecha Límite</small>
                                <strong id="modalFecha" class="d-block"></strong>
                            </div>
                            <div class="bg-light rounded p-3 mb-3">
                                <small class="text-muted d-block">Profesor</small>
                                <strong id="modalProfesor" class="d-block"></strong>
                            </div>
                            <div class="bg-light rounded p-3">
                                <small class="text-muted d-block">Nota Máxima</small>
                                <strong id="modalNotaMaxima" class="d-block"></strong>
                            </div>
                        </div>
                    </div>
                    
                    <hr>
                    
                    <!-- Estado de entrega -->
                    <div id="seccionEntrega">
                        <h6><i class="fas fa-clipboard-check"></i> Tu Entrega</h6>
                        <div class="d-flex align-items-center gap-3 mb-3">
                            <span class="nota-badge nota-pendiente" id="modalEstadoEntrega">Pendiente</span>
                            <span id="modalNotaObtenida" class="fw-bold"></span>
                        </div>
                        <div id="modalRetroalimentacion" class="alert alert-light small"></div>
                        <a href="#" id="btnEntregar" class="btn btn-primary"><i class="fas fa-upload"></i> Entregar Actividad</a>
                    </div>
                    
                    <div id="seccionPendiente" class="text-center py-3">
                        <p class="text-muted">Esta evaluación aún no está disponible para entrega.</p>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cerrar</button>
                    <a href="#" id="btnVerMateria" class="btn btn-outline-primary">Ver Materia</a>
                </div>
            </div>
        </div>
    </div>

    <!-- Scripts -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/fullcalendar@5.11.3/main.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/fullcalendar@5.11.3/locales/es.js"></script>
    
    <script>
        // Datos del calendario
        const eventosCalendar = <?= json_encode($eventos_calendar, JSON_UNESCAPED_UNICODE) ?>;
        
        document.addEventListener('DOMContentLoaded', function() {
            // Toggle sidebar
            document.getElementById('sidebarToggle')?.addEventListener('click', () => {
                document.getElementById('sidebar').classList.toggle('active');
            });
            
            // Inicializar FullCalendar
            const calendarEl = document.getElementById('calendar');
            const calendar = new FullCalendar.Calendar(calendarEl, {
                initialView: 'dayGridMonth',
                locale: 'es',
                headerToolbar: {
                    left: 'prev,next today',
                    center: 'title',
                    right: 'dayGridMonth,timeGridWeek,listMonth'
                },
                events: eventosCalendar,
                eventClick: function(info) {
                    mostrarDetalleEvaluacion(info.event.extendedProps);
                },
                eventDidMount: function(info) {
                    // Tooltip simple
                    info.el.title = info.event.title + ' - ' + info.event.extendedProps.asignatura;
                },
                height: 'auto',
                buttonText: {
                    today: 'Hoy',
                    month: 'Mes',
                    week: 'Semana',
                    list: 'Lista'
                }
            });
            calendar.render();
            
            // Si hay una evaluación muy próxima, mostrar notificación
            const hoy = new Date();
            eventosCalendar.forEach(evento => {
                const fechaEvento = new Date(evento.start);
                const diffDias = Math.ceil((fechaEvento - hoy) / (1000 * 60 * 60 * 24));
                if (diffDias >= 0 && diffDias <= 2 && evento.extendedProps.estado_entrega !== 'calificado') {
                    showToast(`📅 ${evento.title} en ${diffDias === 0 ? 'hoy' : diffDias + ' día(s)'}`, 'warning', 6000);
                }
            });
        });
        
        // Mostrar modal con detalle de evaluación
        function mostrarDetalleEvaluacion(data) {
            // Header color según tipo
            const header = document.getElementById('modalHeader');
            header.className = `modal-header ${data.tipo.toLowerCase()}`;
            
            // Llenar datos
            document.getElementById('modalTitulo').textContent = data.tipo;
            document.getElementById('modalEvaluacionTitulo').textContent = data.title;
            document.getElementById('modalTipoBadge').textContent = data.tipo;
            document.getElementById('modalTipoBadge').className = `event-badge badge-${data.tipo.toLowerCase()}`;
            document.getElementById('modalAsignatura').textContent = `${data.asignatura} (${data.codigo})`;
            document.getElementById('modalDescripcion').textContent = data.descripcion || 'Sin descripción';
            document.getElementById('modalFecha').textContent = formatDate(data.start);
            document.getElementById('modalProfesor').textContent = data.profesor;
            document.getElementById('modalNotaMaxima').textContent = `${data.nota_maxima} puntos`;
            
            // Estado de entrega
            const seccionEntrega = document.getElementById('seccionEntrega');
            const seccionPendiente = document.getElementById('seccionPendiente');
            const estadoBadge = document.getElementById('modalEstadoEntrega');
            const notaObtenida = document.getElementById('modalNotaObtenida');
            const retro = document.getElementById('modalRetroalimentacion');
            const btnEntregar = document.getElementById('btnEntregar');
            
            if (data.estado_entrega === 'calificado') {
                estadoBadge.textContent = 'Calificado';
                estadoBadge.className = 'nota-badge nota-excellent';
                notaObtenida.textContent = `${data.nota_obtenida}/${data.nota_maxima}`;
                notaObtenida.className = data.nota_obtenida >= data.nota_maxima * 0.9 ? 'nota-badge nota-excellent' :
                                         data.nota_obtenida >= data.nota_maxima * 0.7 ? 'nota-badge nota-good' :
                                         data.nota_obtenida >= data.nota_maxima * 0.6 ? 'nota-badge nota-regular' : 'nota-badge nota-fail';
                retro.innerHTML = data.retroalimentacion ? `<strong>Retroalimentación:</strong><br>${data.retroalimentacion}` : '';
                btnEntregar.style.display = 'none';
                seccionEntrega.style.display = 'block';
                seccionPendiente.style.display = 'none';
            } else if (data.estado_entrega === 'entregado') {
                estadoBadge.textContent = 'Entregado';
                estadoBadge.className = 'nota-badge nota-regular';
                notaObtenida.textContent = 'En calificación...';
                notaObtenida.className = 'nota-badge nota-pendiente';
                retro.innerHTML = '';
                btnEntregar.style.display = 'none';
                seccionEntrega.style.display = 'block';
                seccionPendiente.style.display = 'none';
            } else {
                estadoBadge.textContent = 'Pendiente';
                estadoBadge.className = 'nota-badge nota-pendiente';
                notaObtenida.textContent = '-';
                retro.innerHTML = '';
                btnEntregar.href = `entregar_tarea.php?id_actividad=${data.id}`;
                btnEntregar.style.display = 'inline-block';
                
                // Verificar si ya pasó la fecha
                const fechaLimite = new Date(data.start);
                if (fechaLimite < new Date()) {
                    seccionPendiente.querySelector('p').textContent = '⚠️ Esta evaluación ya venció. Contacta a tu profesor.';
                    btnEntregar.style.display = 'none';
                }
                seccionEntrega.style.display = 'block';
                seccionPendiente.style.display = fechaLimite < new Date() ? 'block' : 'none';
            }
            
            // Botón ver materia
            document.getElementById('btnVerMateria').href = `ver_materia.php?id_asignacion=${data.id_asignacion || ''}`;
            
            // Mostrar modal
            new bootstrap.Modal(document.getElementById('modalEvaluacion')).show();
        }
        
        // Formatear fecha
        function formatDate(dateStr) {
            const date = new Date(dateStr);
            return date.toLocaleDateString('es-ES', { 
                weekday: 'long', year: 'numeric', month: 'long', day: 'numeric', hour: '2-digit', minute: '2-digit' 
            });
        }
        
        // Toast notifications
        function showToast(message, type = 'info', duration = 5000) {
            const toast = document.createElement('div');
            toast.className = `position-fixed bottom-0 end-0 p-3`;
            toast.style.zIndex = '9999';
            toast.innerHTML = `
                <div class="toast show bg-${type === 'warning' ? 'warning' : type === 'success' ? 'success' : 'primary'} text-white">
                    <div class="toast-body d-flex align-items-center gap-2">
                        <i class="fas fa-${type === 'warning' ? 'exclamation-triangle' : type === 'success' ? 'check-circle' : 'info-circle'}"></i>
                        <span>${message}</span>
                        <button type="button" class="btn-close btn-close-white ms-auto" onclick="this.parentElement.parentElement.remove()"></button>
                    </div>
                </div>
            `;
            document.body.appendChild(toast);
            setTimeout(() => toast.remove(), duration);
        }
    </script>
</body>
</html>