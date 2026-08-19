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

require_once __DIR__ . '/../../config/MensajeHelper.php';
$totalNoLeidos = MensajeHelper::contarNoLeidos($db, (int) $user_id);

// Obtener datos del estudiante
$query = "SELECT
          e.id as id_estudiante, e.nie,
          p.primer_nombre, p.primer_apellido,
          g.id as id_grado, g.nombre as grado_nombre, g.nivel, g.nota_minima_aprobacion,
          s.id as id_seccion, s.nombre as seccion_nombre,
          m.id as id_matricula, m.anno, m.estado
          FROM tbl_estudiante e
          JOIN tbl_persona p ON e.id_persona = p.id
          JOIN tbl_matricula m ON e.id = m.id_estudiante
          JOIN tbl_seccion s ON m.id_seccion = s.id
          JOIN tbl_grado g ON s.id_grado = g.id
          WHERE p.id_usuario = :user_id
          AND m.estado = 'activo'
          AND e.id_institucion = :tid
          ORDER BY m.anno DESC, m.id DESC
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
                    <p class="text-muted">No tienes clases asignadas para este período.</p>
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

// ===== OBTENER CLASES/MATERIAS =====
$filtro_busqueda = $_GET['busqueda'] ?? '';

// NOTA: ya no se filtra por "período" -- una asignación docente dura el año
// lectivo completo (ver nota igual en estudiante_dashboard.php).
//
// Igual que en actividades.php/ver_materia.php: un examen calificado no
// tiene fila en tbl_entrega_actividad, así que sin vw_logro_estudiante
// (migrations/2026_08_16_vista_logro_estudiante.sql) la tarjeta de la
// materia mostraba "0/N completadas" y "0%" de progreso aunque el
// estudiante ya hubiera presentado y aprobado el examen.
$query_clases = "SELECT
    ad.id as id_asignacion,
    asig.id as id_asignatura, asig.nombre as asignatura, asig.codigo,
    per.primer_nombre as profesor_nombre, per.primer_apellido as profesor_apellido, per.email as profesor_email,
    prof.especialidad as profesor_especialidad,
    COUNT(DISTINCT act.id) as total_actividades,
    COUNT(DISTINCT CASE WHEN v.estado_entrega = 'calificado' THEN act.id END) as actividades_calificadas,
    COUNT(DISTINCT CASE WHEN v.id_registro_origen IS NOT NULL AND v.estado_entrega != 'calificado' THEN act.id END) as actividades_pendientes,
    AVG(CASE WHEN v.estado_entrega = 'calificado' THEN v.nota_obtenida END) as promedio_materia,
    MAX(act.fecha_limite) as proxima_entrega
    FROM tbl_asignacion_docente ad
    JOIN tbl_asignatura asig ON ad.id_asignatura = asig.id
    JOIN tbl_profesor prof ON ad.id_profesor = prof.id
    JOIN tbl_persona per ON prof.id_persona = per.id
    LEFT JOIN tbl_actividad act ON ad.id = act.id_asignacion_docente
        AND act.estado IN ('publicado', 'activo')
    LEFT JOIN vw_logro_estudiante v ON v.id_actividad = act.id AND v.id_matricula = :id_matricula
    WHERE ad.id_seccion = :id_seccion
    AND ad.anno = :anno
    GROUP BY ad.id
    ORDER BY asig.nombre";

$params = [
    ':id_seccion' => $id_seccion,
    ':anno' => $anno,
    ':id_matricula' => $id_matricula
];

if (!empty($filtro_busqueda)) {
    $query_clases .= " HAVING asig.nombre LIKE :busqueda OR asig.codigo LIKE :busqueda";
    $params[':busqueda'] = "%$filtro_busqueda%";
}

$stmt_clases = $db->prepare($query_clases);
$stmt_clases->execute($params);
$clases = $stmt_clases->fetchAll(PDO::FETCH_ASSOC);

// Estadísticas generales
$total_clases = count($clases);
$total_actividades = array_sum(array_column($clases, 'total_actividades'));
$promedio_general = array_filter(array_column($clases, 'promedio_materia'), fn($n) => $n !== null);
$promedio_general = !empty($promedio_general) ? round(array_sum($promedio_general) / count($promedio_general), 2) : 0;

// Colores por materia (para diseño)
$colores_materias = [
    'matematica' => '#4361ee', 'lengua' => '#f72585', 'ciencia' => '#4cc9f0',
    'historia' => '#7209b7', 'arte' => '#f8961e', 'deporte' => '#4ade80',
    'ingles' => '#4895ef', 'quimica' => '#43aa8b', 'fisica' => '#577590'
];

function getColorMateria($nombre) {
    global $colores_materias;
    $nombre_lower = strtolower($nombre);
    foreach ($colores_materias as $key => $color) {
        if (strpos($nombre_lower, $key) !== false) return $color;
    }
    return '#6c757d'; // Color por defecto
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Mis Clases - Educación Plus</title>
    
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    
    <style>
        :root { --primary: #4361ee; --secondary: #3f37c9; --success: #4cc9f0; --warning: #f72585; --danger: #e63946; --sidebar-width: 260px; }
        body { font-family: 'Segoe UI', sans-serif; background: #f5f7fa; }
        .sidebar { position: fixed; top: 0; left: 0; height: 100vh; width: var(--sidebar-width); background: linear-gradient(180deg, #1d3557, #2a4365); color: white; z-index: 1000; }
        .sidebar .nav-link { color: rgba(255,255,255,0.85); padding: 12px 20px; border-radius: 8px; margin: 2px 0; }
        .sidebar .nav-link:hover, .sidebar .nav-link.active { background: rgba(255,255,255,0.15); color: white; }
        .main-content { margin-left: var(--sidebar-width); padding: 20px 30px; }
        .card-custom { background: white; border-radius: 12px; box-shadow: 0 2px 12px rgba(0,0,0,0.08); margin-bottom: 20px; transition: transform 0.2s; }
        .card-custom:hover { transform: translateY(-4px); box-shadow: 0 8px 25px rgba(0,0,0,0.12); }
        .class-card { border-left: 5px solid var(--primary); }
        .class-header { padding: 20px; border-bottom: 1px solid #eee; }
        .class-body { padding: 20px; }
        .progress-custom { height: 8px; border-radius: 10px; }
        .badge-progreso { font-size: 0.75rem; padding: 4px 10px; }
        .profesor-avatar { width: 40px; height: 40px; border-radius: 50%; background: linear-gradient(135deg, var(--primary), var(--secondary)); color: white; display: flex; align-items: center; justify-content: center; font-weight: 600; font-size: 0.9rem; }
        .stats-mini { display: flex; gap: 15px; font-size: 0.85rem; color: #666; }
        .stats-mini i { margin-right: 4px; }
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
            <div class="profesor-avatar mx-auto mb-2" style="width: 50px; height: 50px; font-size: 1.2rem;">
                <?= strtoupper(substr($estudiante['primer_nombre'] ?? 'E', 0, 1)) ?>
            </div>
            <div class="fw-bold small"><?= htmlspecialchars($estudiante['primer_nombre']) ?></div>
            <small class="text-white-50"><?= htmlspecialchars($estudiante['grado_nombre']) ?> - <?= htmlspecialchars($estudiante['seccion_nombre']) ?></small>
        </div>
        <nav class="nav flex-column p-2">
            <a class="nav-link" href="../../index.php"><i class="fas fa-home"></i> Dashboard</a>
            <a class="nav-link active" href="mis_clases.php"><i class="fas fa-book"></i> Mis Clases</a>
            <a class="nav-link" href="actividades.php"><i class="fas fa-tasks"></i> Actividades</a>
            <a class="nav-link" href="mis_notas.php"><i class="fas fa-star"></i> Calificaciones</a>
            <a class="nav-link" href="mensajes.php">
                <i class="fas fa-envelope"></i> Mensajes
                <?php if ($totalNoLeidos > 0): ?><span class="badge bg-danger rounded-pill float-end"><?= $totalNoLeidos ?></span><?php endif; ?>
            </a>
            <a class="nav-link" href="../../logout.php"><i class="fas fa-sign-out-alt"></i> Cerrar Sesión</a>
        </nav>
    </aside>

    <!-- Main Content -->
    <main class="main-content">
        <!-- Header -->
        <div class="d-flex justify-content-between align-items-center mb-4">
            <div>
                <h2><i class="fas fa-book-open"></i> Mis Clases</h2>
                <p class="text-muted mb-0"><?= $total_clases ?> materias inscritas • Año lectivo <?= $anno ?></p>
            </div>
            <button class="btn btn-outline-primary btn-sm" id="sidebarToggle"><i class="fas fa-bars"></i></button>
        </div>

        <!-- Stats -->
        <div class="row g-3 mb-4">
            <div class="col-md-4">
                <div class="card-custom p-3 text-center">
                    <h3 class="text-primary mb-0"><?= $total_clases ?></h3>
                    <small class="text-muted">Materias Activas</small>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card-custom p-3 text-center">
                    <h3 class="text-success mb-0"><?= $total_actividades ?></h3>
                    <small class="text-muted">Total Actividades</small>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card-custom p-3 text-center">
                    <h3 class="text-warning mb-0"><?= number_format($promedio_general, 2) ?></h3>
                    <small class="text-muted">Promedio General</small>
                </div>
            </div>
        </div>

        <!-- Filtros -->
        <div class="card-custom p-3 mb-4">
            <form method="GET" class="row g-3">
                <div class="col-md-8">
                    <div class="input-group">
                        <span class="input-group-text"><i class="fas fa-search"></i></span>
                        <input type="text" name="busqueda" class="form-control" placeholder="Buscar materia..." value="<?= htmlspecialchars($filtro_busqueda) ?>">
                        <button type="submit" class="btn btn-primary">Buscar</button>
                    </div>
                </div>
                <div class="col-md-4">
                    <a href="mis_clases.php" class="btn btn-outline-secondary w-100"><i class="fas fa-redo"></i> Limpiar</a>
                </div>
            </form>
        </div>

        <!-- Lista de Clases -->
        <?php if (empty($clases)): ?>
        <div class="card-custom p-5 text-center">
            <i class="fas fa-book fa-4x text-muted mb-3"></i>
            <h5>No tienes materias asignadas</h5>
            <p class="text-muted">Contacta a administración para inscribirte en clases.</p>
        </div>
        <?php else: ?>
        <div class="row g-3">
            <?php foreach ($clases as $clase): 
                $progreso = $clase['total_actividades'] > 0 
                    ? round(($clase['actividades_calificadas'] / $clase['total_actividades']) * 100) 
                    : 0;
                $color_borde = getColorMateria($clase['asignatura']);
            ?>
            <div class="col-lg-6">
                <div class="card-custom class-card" style="border-left-color: <?= $color_borde ?>;">
                    <div class="class-header d-flex justify-content-between align-items-start">
                        <div>
                            <span class="badge bg-light text-dark border mb-2"><?= htmlspecialchars($clase['codigo']) ?></span>
                            <h5 class="mb-1"><?= htmlspecialchars($clase['asignatura']) ?></h5>
                        </div>
                        <span class="badge-progreso bg-<?= $progreso >= 80 ? 'success' : ($progreso >= 50 ? 'warning' : 'secondary') ?> text-white rounded-pill">
                            <?= $progreso ?>%
                        </span>
                    </div>
                    
                    <div class="class-body">
                        <!-- Profesor -->
                        <div class="d-flex align-items-center mb-3 pb-3 border-bottom">
                            <div class="profesor-avatar me-3">
                                <?= strtoupper(substr($clase['profesor_nombre'], 0, 1)) ?>
                            </div>
                            <div>
                                <div class="fw-bold small">
                                    Prof. <?= htmlspecialchars($clase['profesor_apellido']) ?>
                                </div>
                                <small class="text-muted"><?= htmlspecialchars($clase['profesor_especialidad'] ?? '') ?></small>
                            </div>
                            <a href="mailto:<?= htmlspecialchars($clase['profesor_email']) ?>" class="btn btn-sm btn-outline-primary ms-auto" title="Enviar mensaje">
                                <i class="fas fa-envelope"></i>
                            </a>
                        </div>
                        
                        <!-- Progreso -->
                        <div class="mb-3">
                            <div class="d-flex justify-content-between small text-muted mb-1">
                                <span>Progreso</span>
                                <span><?= $clase['actividades_calificadas'] ?>/<?= $clase['total_actividades'] ?> completadas</span>
                            </div>
                            <div class="progress progress-custom">
                                <div class="progress-bar" style="width: <?= $progreso ?>%; background: <?= $color_borde ?>;"></div>
                            </div>
                        </div>
                        
                        <!-- Stats -->
                        <div class="stats-mini mb-3">
                            <span><i class="fas fa-tasks text-primary"></i> <?= $clase['total_actividades'] ?> actividades</span>
                            <span><i class="fas fa-clock text-warning"></i> <?= $clase['actividades_pendientes'] ?> pendientes</span>
                            <?php if ($clase['promedio_materia']): ?>
                            <span><i class="fas fa-star text-success"></i> <?= number_format($clase['promedio_materia'], 2) ?></span>
                            <?php endif; ?>
                        </div>
                        
                        <!-- Próxima entrega -->
                        <?php if ($clase['proxima_entrega']): 
                            $dias = ceil((strtotime($clase['proxima_entrega']) - time()) / 86400);
                        ?>
                        <div class="alert alert-light small mb-3 py-2">
                            <i class="fas fa-calendar-alt text-primary"></i> 
                            Próxima entrega: <strong><?= date('d/m/Y', strtotime($clase['proxima_entrega'])) ?></strong>
                            <?php if ($dias <= 3): ?>
                            <span class="text-danger fw-bold ms-1">(¡<?= $dias ?> día<?= $dias > 1 ? 's' : '' ?>!)</span>
                            <?php endif; ?>
                        </div>
                        <?php endif; ?>
                        
                        <!-- Acciones -->
                        <div class="d-flex gap-2">
                            <a href="ver_materia.php?id_asignacion=<?= $clase['id_asignacion'] ?>" class="btn btn-primary flex-grow-1 btn-sm">
                                <i class="fas fa-eye"></i> Ver Materia
                            </a>
                            <a href="actividades.php?id_asignacion=<?= $clase['id_asignacion'] ?>" class="btn btn-outline-secondary btn-sm">
                                <i class="fas fa-tasks"></i>
                            </a>
                        </div>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <!-- Footer -->
        <footer class="text-center py-4 text-muted small mt-4">
            <p class="mb-0">© <?= date('Y') ?> Educación Plus</p>
        </footer>
    </main>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script>
        // Toggle sidebar en móvil
        document.getElementById('sidebarToggle')?.addEventListener('click', () => {
            document.getElementById('sidebar').classList.toggle('active');
        });
    </script>
</body>
</html>