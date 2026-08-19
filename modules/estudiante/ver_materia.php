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

// Obtener ID de la asignatura desde la URL
$id_asignacion = filter_input(INPUT_GET, 'id_asignacion', FILTER_VALIDATE_INT);
if (!$id_asignacion) {
    header("Location: mis_clases.php");
    exit;
}

// Obtener datos del estudiante y matrícula
$query = "SELECT
          e.id as id_estudiante,
          m.id as id_matricula, m.anno,
          s.id as id_seccion,
          p.primer_nombre, p.primer_apellido
          FROM tbl_estudiante e
          JOIN tbl_persona p ON e.id_persona = p.id
          JOIN tbl_matricula m ON e.id = m.id_estudiante
          JOIN tbl_seccion s ON m.id_seccion = s.id
          WHERE p.id_usuario = :user_id
          AND m.estado = 'activo'
          AND e.id_institucion = :tid
          LIMIT 1";

$stmt = $db->prepare($query);
$stmt->bindValue(':user_id', $user_id, PDO::PARAM_INT);
$stmt->bindValue(':tid', $tid, PDO::PARAM_INT);
$stmt->execute();
$datos = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$datos) {
    header("Location: " . BASE_URL . "/index.php");
    exit;
}

$id_estudiante = $datos['id_estudiante'];
$id_matricula = $datos['id_matricula'];
$id_seccion = $datos['id_seccion'];
$anno = $datos['anno'];
// NOTA: ya no se filtra por "período" -- ver estudiante_dashboard.php.

// ===== VERIFICAR QUE LA MATERIA PERTENECE AL ESTUDIANTE =====
// Igual que en actividades.php: los exámenes no tienen fila en
// tbl_entrega_actividad, su nota vive en tbl_intento_examen.
// vw_logro_estudiante (migrations/2026_08_16_vista_logro_estudiante.sql)
// unifica ambas fuentes.
$query_materia = "SELECT
    ad.id as id_asignacion,
    asig.id as id_asignatura, asig.nombre as asignatura, asig.codigo,
    pf.id as id_profesor, per.primer_nombre as prof_nombre, per.primer_apellido as prof_apellido, per.email as prof_email,
    pf.especialidad,
    g.nombre as grado_nombre, s.nombre as seccion_nombre,
    COUNT(DISTINCT act.id) as total_actividades,
    AVG(CASE WHEN v.estado_entrega = 'calificado' THEN v.nota_obtenida END) as promedio_materia
    FROM tbl_asignacion_docente ad
    JOIN tbl_asignatura asig ON ad.id_asignatura = asig.id
    JOIN tbl_profesor pf ON ad.id_profesor = pf.id
    JOIN tbl_persona per ON pf.id_persona = per.id
    JOIN tbl_seccion s ON ad.id_seccion = s.id
    JOIN tbl_grado g ON s.id_grado = g.id
    LEFT JOIN tbl_actividad act ON ad.id = act.id_asignacion_docente
        AND act.estado IN ('publicado', 'activo')
    LEFT JOIN vw_logro_estudiante v ON v.id_actividad = act.id AND v.id_matricula = :id_matricula
    WHERE ad.id = :id_asignacion
    AND ad.id_seccion = :id_seccion
    AND ad.anno = :anno
    AND asig.id_institucion = :tid
    GROUP BY ad.id";

$stmt_materia = $db->prepare($query_materia);
$stmt_materia->execute([
    ':id_asignacion' => $id_asignacion,
    ':id_seccion' => $id_seccion,
    ':anno' => $anno,
    ':id_matricula' => $id_matricula,
    ':tid' => $tid
]);
$materia = $stmt_materia->fetch(PDO::FETCH_ASSOC);

// Si no tiene acceso a esta materia
if (!$materia) {
    ?>
    <!DOCTYPE html>
    <html lang="es">
    <head>
        <meta charset="UTF-8">
        <title>Acceso Denegado - Educación Plus</title>
        <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    </head>
    <body class="bg-light">
        <div class="container mt-5">
            <div class="card shadow text-center py-5">
                <div class="card-body">
                    <i class="fas fa-lock fa-4x text-warning mb-3"></i>
                    <h4>Acceso Denegado</h4>
                    <p class="text-muted">No tienes acceso a esta materia o no está disponible en tu período actual.</p>
                    <a href="mis_clases.php" class="btn btn-primary">Volver a Mis Clases</a>
                </div>
            </div>
        </div>
    </body>
    </html>
    <?php
    exit;
}

// ===== OBTENER ACTIVIDADES DE LA MATERIA =====
$filtro_tipo = $_GET['tipo'] ?? 'todos';
$filtro_estado = $_GET['estado'] ?? 'todos';

$query_actividades = "SELECT
    act.id, act.id_examen, act.titulo, act.descripcion, act.tipo, act.fecha_programada, act.fecha_limite,
    act.nota_maxima, act.estado as estado_actividad, act.contenido, act.url_recurso,
    v.id_registro_origen as id_entrega,
    ea.archivo_url,
    v.estado_entrega,
    v.nota_obtenida,
    v.observacion_docente,
    v.fecha_entrega,
    DATEDIFF(act.fecha_limite, NOW()) as dias_restantes
    FROM tbl_actividad act
    LEFT JOIN tbl_entrega_actividad ea ON act.id = ea.id_actividad AND ea.id_matricula = :id_matricula
    LEFT JOIN vw_logro_estudiante v ON v.id_actividad = act.id AND v.id_matricula = :id_matricula_v
    WHERE act.id_asignacion_docente = :id_asignacion
    AND act.estado IN ('publicado', 'activo')";

$params = [
    ':id_matricula' => $id_matricula,
    ':id_matricula_v' => $id_matricula,
    ':id_asignacion' => $id_asignacion
];

if ($filtro_tipo != 'todos') {
    $query_actividades .= " AND act.tipo = :tipo";
    $params[':tipo'] = $filtro_tipo;
}

if ($filtro_estado == 'pendientes') {
    $query_actividades .= " AND (v.id_registro_origen IS NULL OR v.estado_entrega != 'calificado') AND act.fecha_limite >= CURDATE()";
} elseif ($filtro_estado == 'entregadas') {
    $query_actividades .= " AND v.id_registro_origen IS NOT NULL AND v.estado_entrega = 'entregado'";
} elseif ($filtro_estado == 'calificadas') {
    $query_actividades .= " AND v.id_registro_origen IS NOT NULL AND v.estado_entrega = 'calificado'";
}

$query_actividades .= " ORDER BY act.fecha_limite ASC";

$stmt_act = $db->prepare($query_actividades);
foreach ($params as $key => $value) {
    $stmt_act->bindValue($key, $value);
}
$stmt_act->execute();
$actividades = $stmt_act->fetchAll(PDO::FETCH_ASSOC);

// Estadísticas de la materia
$total_actividades = count($actividades);
$pendientes = count(array_filter($actividades, fn($a) => $a['id_entrega'] === null && strtotime($a['fecha_limite']) >= time()));
$entregadas = count(array_filter($actividades, fn($a) => $a['estado_entrega'] == 'entregado'));
$calificadas = count(array_filter($actividades, fn($a) => $a['estado_entrega'] == 'calificado'));
$promedio_materia = $materia['promedio_materia'] ?? 0;

$tipos_actividad = [
    'tarea' => ['label' => 'Tarea', 'icon' => 'fa-clipboard-list', 'color' => 'info'],
    'examen' => ['label' => 'Examen', 'icon' => 'fa-file-alt', 'color' => 'danger'],
    'laboratorio' => ['label' => 'Laboratorio', 'icon' => 'fa-flask', 'color' => 'warning'],
    'foro' => ['label' => 'Foro', 'icon' => 'fa-comments', 'color' => 'success'],
    'recurso' => ['label' => 'Recurso', 'icon' => 'fa-book', 'color' => 'secondary']
];
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($materia['asignatura']) ?> - Educación Plus</title>
    
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    
    <style>
        :root { --primary: #4361ee; --secondary: #3f37c9; --success: #2ecc71; --warning: #f39c12; --danger: #e74c3c; --sidebar-width: 260px; }
        body { font-family: 'Segoe UI', sans-serif; background: #f5f7fa; }
        .sidebar { position: fixed; top: 0; left: 0; height: 100vh; width: var(--sidebar-width); background: linear-gradient(180deg, #1d3557, #2a4365); color: white; z-index: 1000; }
        .sidebar .nav-link { color: rgba(255,255,255,0.85); padding: 12px 20px; border-radius: 8px; margin: 2px 0; }
        .sidebar .nav-link:hover, .sidebar .nav-link.active { background: rgba(255,255,255,0.15); color: white; }
        .main-content { margin-left: var(--sidebar-width); padding: 20px 30px; }
        .card-custom { background: white; border-radius: 12px; box-shadow: 0 2px 12px rgba(0,0,0,0.08); margin-bottom: 20px; }
        .profesor-card { background: linear-gradient(135deg, var(--primary), var(--secondary)); color: white; border-radius: 12px; padding: 20px; }
        .activity-card { border-left: 4px solid var(--primary); transition: all 0.2s; }
        .activity-card:hover { transform: translateY(-2px); box-shadow: 0 4px 15px rgba(0,0,0,0.1); }
        .badge-estado { padding: 5px 12px; border-radius: 15px; font-size: 0.75rem; font-weight: 600; }
        .estado-pendiente { background: #fff3cd; color: #856404; }
        .estado-entregado { background: #cce5ff; color: #004085; }
        .estado-calificado { background: #d4edda; color: #155724; }
        .vencida { background: #f8d7da; color: #721c24; }
        .progress-circle { width: 80px; height: 80px; border-radius: 50%; background: conic-gradient(var(--primary) 0%, #e9ecef 0%); display: flex; align-items: center; justify-content: center; position: relative; margin: 0 auto; }
        .progress-circle::before { content: ''; position: absolute; width: 65px; height: 65px; border-radius: 50%; background: white; }
        .progress-circle span { position: relative; z-index: 1; font-size: 1.2rem; font-weight: 700; color: var(--primary); }
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
            <div class="fw-bold small"><?= htmlspecialchars($datos['primer_nombre']) ?></div>
            <small class="text-white-50">Estudiante</small>
        </div>
        <nav class="nav flex-column p-2">
            <a class="nav-link" href="#"><i class="fas fa-home"></i> Dashboard</a>
            <a class="nav-link" href="mis_clases.php"><i class="fas fa-book"></i> Mis Clases</a>
            <a class="nav-link" href="actividades.php"><i class="fas fa-tasks"></i> Actividades</a>
            <a class="nav-link" href="mis_notas.php"><i class="fas fa-star"></i> Calificaciones</a>
            <a class="nav-link" href="../../logout.php"><i class="fas fa-sign-out-alt"></i> Cerrar Sesión</a>
        </nav>
    </aside>

    <!-- Main Content -->
    <main class="main-content">
        <!-- Header -->
        <div class="d-flex justify-content-between align-items-center mb-4">
            <div>
                <h2><i class="fas fa-book-open"></i> <?= htmlspecialchars($materia['asignatura']) ?></h2>
                <p class="text-muted mb-0"><?= htmlspecialchars($materia['grado_nombre']) ?> - <?= htmlspecialchars($materia['seccion_nombre']) ?> • <?= htmlspecialchars($materia['codigo']) ?></p>
            </div>
            <div class="d-flex gap-2">
                <a href="mis_clases.php" class="btn btn-outline-secondary btn-sm"><i class="fas fa-arrow-left"></i> Volver</a>
                <button class="btn btn-outline-primary btn-sm" id="sidebarToggle"><i class="fas fa-bars"></i></button>
            </div>
        </div>

        <!-- Info Materia y Profesor -->
        <div class="row g-3 mb-4">
            <div class="col-lg-8">
                <div class="card-custom p-4">
                    <h5 class="mb-3"><i class="fas fa-info-circle"></i> Información de la Materia</h5>
                    <p class="mb-0 text-muted">Esta materia forma parte de tu plan de estudios para el año <?= $anno ?>.</p>
                </div>
            </div>
            <div class="col-lg-4">
                <div class="profesor-card text-center">
                    <div class="rounded-circle bg-white text-primary d-flex align-items-center justify-content-center mx-auto mb-3" style="width: 70px; height: 70px; font-size: 1.8rem; font-weight: bold;">
                        <?= strtoupper(substr($materia['prof_nombre'], 0, 1)) ?>
                    </div>
                    <h5 class="mb-1">Prof. <?= htmlspecialchars($materia['prof_apellido']) ?></h5>
                    <small class="d-block mb-2"><?= htmlspecialchars($materia['especialidad'] ?? '') ?></small>
                    <a href="mailto:<?= htmlspecialchars($materia['prof_email']) ?>" class="btn btn-light btn-sm">
                        <i class="fas fa-envelope"></i> Contactar
                    </a>
                </div>
            </div>
        </div>

        <!-- Stats de la Materia -->
        <div class="row g-3 mb-4">
            <div class="col-md-3">
                <div class="card-custom p-3 text-center">
                    <div class="progress-circle mb-2" style="--progress: <?= min(($calificadas / max($total_actividades, 1)) * 100, 100) ?>%; background: conic-gradient(var(--success) <?= min(($calificadas / max($total_actividades, 1)) * 100, 100) ?>%, #e9ecef <?= min(($calificadas / max($total_actividades, 1)) * 100, 100) ?>%);">
                        <span><?= $calificadas ?>/<?= $total_actividades ?></span>
                    </div>
                    <small class="text-muted">Actividades Completadas</small>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card-custom p-3 text-center">
                    <h3 class="text-warning mb-0"><?= $pendientes ?></h3>
                    <small class="text-muted">Pendientes</small>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card-custom p-3 text-center">
                    <h3 class="text-info mb-0"><?= $entregadas ?></h3>
                    <small class="text-muted">Entregadas</small>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card-custom p-3 text-center">
                    <h3 class="text-primary mb-0"><?= number_format($promedio_materia, 1) ?></h3>
                    <small class="text-muted">Tu Promedio</small>
                </div>
            </div>
        </div>

        <!-- Filtros de Actividades -->
        <div class="card-custom p-3 mb-4">
            <form method="GET" class="row g-3">
                <input type="hidden" name="id_asignacion" value="<?= $id_asignacion ?>">
                <div class="col-md-4">
                    <select name="tipo" class="form-select" onchange="this.form.submit()">
                        <option value="todos" <?= $filtro_tipo == 'todos' ? 'selected' : '' ?>>Todos los tipos</option>
                        <?php foreach ($tipos_actividad as $key => $tipo): ?>
                        <option value="<?= $key ?>" <?= $filtro_tipo == $key ? 'selected' : '' ?>><?= $tipo['label'] ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-4">
                    <select name="estado" class="form-select" onchange="this.form.submit()">
                        <option value="todos" <?= $filtro_estado == 'todos' ? 'selected' : '' ?>>Todos los estados</option>
                        <option value="pendientes" <?= $filtro_estado == 'pendientes' ? 'selected' : '' ?>>Pendientes</option>
                        <option value="entregadas" <?= $filtro_estado == 'entregadas' ? 'selected' : '' ?>>Entregadas</option>
                        <option value="calificadas" <?= $filtro_estado == 'calificadas' ? 'selected' : '' ?>>Calificadas</option>
                    </select>
                </div>
                <div class="col-md-4">
                    <a href="?id_asignacion=<?= $id_asignacion ?>" class="btn btn-outline-secondary w-100"><i class="fas fa-redo"></i> Limpiar</a>
                </div>
            </form>
        </div>

        <!-- Lista de Actividades -->
        <div class="card-custom">
            <div class="card-header bg-white py-3">
                <h5 class="mb-0"><i class="fas fa-tasks"></i> Actividades de <?= htmlspecialchars($materia['asignatura']) ?></h5>
            </div>
            <div class="card-body p-0">
                <?php if (empty($actividades)): ?>
                <div class="text-center py-5 text-muted">
                    <i class="fas fa-clipboard-check fa-3x mb-3"></i>
                    <p>No hay actividades registradas para esta materia.</p>
                </div>
                <?php else: ?>
                <div class="list-group list-group-flush">
                    <?php foreach ($actividades as $act): 
                        $tipo = $tipos_actividad[$act['tipo']] ?? ['label' => $act['tipo'], 'icon' => 'fa-tasks', 'color' => 'secondary'];
                        $dias = $act['dias_restantes'];
                        
                        if ($act['estado_entrega'] == 'calificado') {
                            $estado_class = 'estado-calificado';
                            $estado_label = 'Calificada';
                        } elseif ($act['estado_entrega'] == 'entregado') {
                            $estado_class = 'estado-entregado';
                            $estado_label = 'Entregada';
                        } elseif ($dias < 0) {
                            $estado_class = 'vencida';
                            $estado_label = 'Vencida';
                        } else {
                            $estado_class = 'estado-pendiente';
                            $estado_label = 'Pendiente';
                        }
                    ?>
                    <div class="list-group-item activity-card p-3">
                        <div class="row align-items-center">
                            <div class="col-md-1 text-center">
                                <div class="bg-<?= $tipo['color'] ?> text-white rounded-circle d-flex align-items-center justify-content-center mx-auto" style="width: 45px; height: 45px;">
                                    <i class="fas <?= $tipo['icon'] ?>"></i>
                                </div>
                            </div>
                            <div class="col-md-5">
                                <h6 class="mb-1"><?= htmlspecialchars($act['titulo']) ?></h6>
                                <?php if ($act['descripcion']): ?>
                                <small class="text-muted"><?= htmlspecialchars(substr($act['descripcion'], 0, 80)) ?><?= strlen($act['descripcion']) > 80 ? '...' : '' ?></small>
                                <?php endif; ?>
                            </div>
                            <div class="col-md-3">
                                <small class="d-block"><i class="fas fa-calendar"></i> <?= $act['fecha_limite'] ? date('d/m/Y', strtotime($act['fecha_limite'])) : 'Sin fecha límite' ?></small>
                                <small class="d-block <?= $dias !== null && $dias <= 2 && $dias >= 0 ? 'text-danger fw-bold' : '' ?>">
                                    <i class="fas fa-clock"></i> <?= $dias === null ? '' : ($dias >= 0 ? $dias . ' días' : 'Vencida') ?>
                                </small>
                            </div>
                            <div class="col-md-2 text-center">
                                <span class="badge-estado <?= $estado_class ?>"><?= $estado_label ?></span>
                                <?php if ($act['nota_obtenida'] !== null): ?>
                                <div class="mt-1 fw-bold text-success"><?= $act['nota_obtenida'] ?>/<?= $act['nota_maxima'] ?></div>
                                <?php endif; ?>
                            </div>
                            <div class="col-md-1 text-end">
                                <button class="btn btn-sm btn-primary" onclick="verDetalle(<?= $act['id'] ?>)">
                                    <i class="fas fa-eye"></i>
                                </button>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </main>

    <!-- Modal Detalle Actividad -->
    <div class="modal fade" id="modalDetalle" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title"><i class="fas fa-tasks"></i> Detalle de Actividad</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body" id="modalBody">
                    <!-- Cargado vía AJAX -->
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script>
        document.getElementById('sidebarToggle')?.addEventListener('click', () => {
            document.getElementById('sidebar').classList.toggle('active');
        });
        
        function verDetalle(id) {
            alert('Ver detalle de actividad ID: ' + id);
        }
    </script>
</body>
</html>