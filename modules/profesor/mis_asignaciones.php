<?php
session_start();
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/TenantGuard.php';

// Verificar que sea profesor
if (!isset($_SESSION['user_id']) || $_SESSION['rol'] != 'profesor') {
    header("Location: " . BASE_URL . "/login.php");
    exit;
}

$database = new Database();
$db = $database->getConnection();
$user_id = $_SESSION['user_id'];
$tid = TenantGuard::id();

// Datos del profesor
$stmt = $db->prepare("SELECT p.id as id_profesor, CONCAT(per.primer_nombre, ' ', per.primer_apellido) as nombre, per.email
                      FROM tbl_profesor p
                      JOIN tbl_persona per ON p.id_persona = per.id
                      WHERE per.id_usuario = :uid AND p.id_institucion = :tid");
$stmt->execute([':uid' => $user_id, ':tid' => $tid]);
// $stmt->fetch() devuelve `false` (no `null`) cuando no encuentra ninguna fila,
// así que el valor por defecto debe activarse con "?:" y no con "??" — "??"
// solo reacciona a `null`, y con `false` deja pasar el valor tal cual. Esa
// diferencia era la causa del "Trying to access array offset on value of
// type bool" en la línea siguiente.
$profesor = $stmt->fetch(PDO::FETCH_ASSOC) ?: ['id_profesor' => 0, 'nombre' => 'Profesor', 'email' => ''];
$id_profesor = $profesor['id_profesor'];

// Todas las asignaciones (clases) del profesor, con conteo de estudiantes y actividades
$stmt = $db->prepare("SELECT ad.id, ad.anno, asig.nombre as asignatura, asig.codigo,
                             g.nombre as grado, s.nombre as seccion,
                             COUNT(DISTINCT m.id) as estudiantes,
                             COUNT(DISTINCT a.id) as actividades
                      FROM tbl_asignacion_docente ad
                      JOIN tbl_asignatura asig ON ad.id_asignatura = asig.id
                      JOIN tbl_seccion s ON ad.id_seccion = s.id
                      JOIN tbl_grado g ON s.id_grado = g.id
                      LEFT JOIN tbl_matricula m ON s.id = m.id_seccion AND m.anno = ad.anno AND m.estado = 'activo'
                      LEFT JOIN tbl_actividad a ON ad.id = a.id_asignacion_docente AND a.estado IN ('publicado','activo','programado')
                      WHERE ad.id_profesor = :prof
                      GROUP BY ad.id
                      ORDER BY ad.anno DESC, g.nombre, s.nombre, asig.nombre");
// Nota: tbl_asignacion_docente no tiene columna id_institucion (se
// comprobó contra el esquema real) — no hace falta filtrarla aparte,
// porque id_profesor por sí solo ya identifica a un profesor de una
// única institución.
$stmt->execute([':prof' => $id_profesor]);
$asignaciones = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Filtro por año
$anios = array_values(array_unique(array_column($asignaciones, 'anno')));
rsort($anios);
$filtro_anno = $_GET['anno'] ?? ($anios[0] ?? date('Y'));
$asignacionesFiltradas = array_values(array_filter($asignaciones, fn($a) => (string)$a['anno'] === (string)$filtro_anno));

$totalEstudiantes = array_sum(array_column($asignacionesFiltradas, 'estudiantes'));
$totalActividades  = array_sum(array_column($asignacionesFiltradas, 'actividades'));
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Mis Asignaciones - Educación Plus</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <style>
        :root {
            --primary: #2c3e50;
            --secondary: #3498db;
            --success: #2ecc71;
            --warning: #f39c12;
            --danger: #e74c3c;
            --sidebar-width: 260px;
        }
        body { font-family: 'Segoe UI', system-ui, -apple-system, sans-serif; background: #f0f2f5; }
        .sidebar { position: fixed; top: 0; left: 0; height: 100vh; width: var(--sidebar-width); background: var(--primary); color: white; padding-top: 20px; z-index: 1000; overflow-y: auto; }
        .sidebar .brand { text-align: center; padding: 0 20px 20px; border-bottom: 1px solid rgba(255,255,255,0.1); margin-bottom: 20px; }
        .sidebar .nav-link { color: rgba(255,255,255,0.85); padding: 12px 20px; margin: 2px 10px; border-radius: 8px; transition: all 0.2s; }
        .sidebar .nav-link:hover, .sidebar .nav-link.active { color: white; background: rgba(255,255,255,0.15); }
        .main-content { margin-left: var(--sidebar-width); padding: 20px; }
        @media (max-width: 992px) { .sidebar { transform: translateX(-100%); } .sidebar.active { transform: translateX(0); } .main-content { margin-left: 0; } }

        .page-header { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; padding: 30px; border-radius: 12px; margin-bottom: 25px; box-shadow: 0 4px 15px rgba(0,0,0,0.1); }
        .stat-card { background: white; border-radius: 12px; padding: 20px; box-shadow: 0 2px 8px rgba(0,0,0,0.08); text-align: center; }
        .stat-card .num { font-size: 2rem; font-weight: 700; color: var(--primary); }
        .asig-card { background: white; border-radius: 12px; padding: 22px; box-shadow: 0 2px 8px rgba(0,0,0,0.08); margin-bottom: 18px; border-left: 5px solid var(--secondary); transition: all .2s; }
        .asig-card:hover { transform: translateY(-2px); box-shadow: 0 6px 16px rgba(0,0,0,0.12); }
        .asig-card h5 { font-weight: 600; margin-bottom: 2px; }
        .asig-badge { font-size: .78rem; padding: 5px 10px; }
    </style>
</head>
<body>
    <div class="sidebar" id="sidebar">
        <div class="brand">
            <h4><i class="fas fa-graduation-cap"></i> Educación Plus</h4>
            <small>Panel del Profesor</small>
        </div>
        <nav class="nav flex-column">
            <a class="nav-link" href="profesor_dashboard.php"><i class="fas fa-home"></i> Dashboard</a>
            <a class="nav-link active" href="mis_asignaciones.php"><i class="fas fa-book"></i> Mis Asignaciones</a>
            <a class="nav-link" href="gestionar_actividades.php"><i class="fas fa-tasks"></i> Actividades</a>
            <a class="nav-link" href="asignar_examen.php"><i class="fas fa-file-alt"></i> Asignar Examen</a>
            <a class="nav-link" href="calificaciones.php"><i class="fas fa-star"></i> Calificaciones</a>
            <a class="nav-link" href="gestionar_estudiantes.php"><i class="fas fa-user-graduate"></i> Estudiantes</a>
            <a class="nav-link" href="banco_preguntas.php"><i class="fas fa-layer-group"></i> Banco de Preguntas</a>
            <a class="nav-link" href="../../logout.php"><i class="fas fa-sign-out-alt"></i> Cerrar Sesión</a>
        </nav>
    </div>

    <div class="main-content">
        <div class="page-header d-flex justify-content-between align-items-center flex-wrap gap-3">
            <div>
                <h2 class="mb-1"><i class="fas fa-book me-2"></i>Mis Asignaciones</h2>
                <div class="opacity-75">Todas las clases y secciones que tienes asignadas</div>
            </div>
            <form method="GET" class="d-flex gap-2">
                <select name="anno" class="form-select" onchange="this.form.submit()">
                    <?php foreach ($anios as $a): ?>
                        <option value="<?= (int)$a ?>" <?= (string)$a === (string)$filtro_anno ? 'selected' : '' ?>><?= (int)$a ?></option>
                    <?php endforeach; ?>
                </select>
            </form>
        </div>

        <div class="row g-3 mb-4">
            <div class="col-md-4">
                <div class="stat-card">
                    <div class="num"><?= count($asignacionesFiltradas) ?></div>
                    <div class="text-muted">Clases Asignadas</div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="stat-card">
                    <div class="num"><?= (int)$totalEstudiantes ?></div>
                    <div class="text-muted">Estudiantes en total</div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="stat-card">
                    <div class="num"><?= (int)$totalActividades ?></div>
                    <div class="text-muted">Actividades publicadas</div>
                </div>
            </div>
        </div>

        <?php if (empty($asignacionesFiltradas)): ?>
            <div class="text-center text-muted py-5">
                <i class="fas fa-inbox fa-3x mb-3 d-block"></i>
                No tienes clases asignadas para el año <?= (int)$filtro_anno ?>.
            </div>
        <?php else: ?>
            <?php foreach ($asignacionesFiltradas as $a): ?>
            <div class="asig-card">
                <div class="d-flex justify-content-between align-items-start flex-wrap gap-3">
                    <div>
                        <h5><?= htmlspecialchars($a['asignatura']) ?> <small class="text-muted">(<?= htmlspecialchars($a['codigo']) ?>)</small></h5>
                        <div class="text-muted"><?= htmlspecialchars($a['grado']) ?> - Sección <?= htmlspecialchars($a['seccion']) ?> · Año <?= (int)$a['anno'] ?></div>
                        <div class="mt-2">
                            <span class="badge bg-secondary asig-badge"><i class="fas fa-user-graduate me-1"></i><?= (int)$a['estudiantes'] ?> estudiantes</span>
                            <span class="badge bg-info asig-badge"><i class="fas fa-tasks me-1"></i><?= (int)$a['actividades'] ?> actividades</span>
                        </div>
                    </div>
                    <div class="d-flex gap-2 flex-wrap">
                        <a href="gestionar_actividades.php?asignacion=<?= (int)$a['id'] ?>" class="btn btn-sm btn-primary">
                            <i class="fas fa-tasks me-1"></i> Actividades
                        </a>
                        <a href="gestionar_estudiantes.php?asignacion=<?= (int)$a['id'] ?>" class="btn btn-sm btn-outline-secondary">
                            <i class="fas fa-user-graduate me-1"></i> Estudiantes
                        </a>
                        <a href="calificaciones.php?asignacion=<?= (int)$a['id'] ?>" class="btn btn-sm btn-outline-secondary">
                            <i class="fas fa-star me-1"></i> Calificaciones
                        </a>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
