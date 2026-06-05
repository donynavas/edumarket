<?php
session_start();
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/app.php';


// Verificar que sea profesor
if (!isset($_SESSION['user_id']) || $_SESSION['rol'] != 'profesor') {
    header("Location: " . BASE_URL . "/login.php");
    exit;
}


$database = new Database();
$db = $database->getConnection();
$user_id = $_SESSION['user_id'];

// Inicializar variables
$profesor = null;
$asignaciones = [];
$total_estudiantes = 0;
$total_actividades = 0;
$error_message = null;
$success_message = null;

try {
    // Obtener datos del profesor
    $query = "SELECT p.id as id_profesor, per.primer_nombre, per.primer_apellido, per.email
              FROM tbl_profesor p
              JOIN tbl_persona per ON p.id_persona = per.id
              WHERE per.id_usuario = :user_id";
    $stmt = $db->prepare($query);
    $stmt->bindValue(':user_id', $user_id, PDO::PARAM_INT);
    $stmt->execute();
    $profesor = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Si no existe el profesor, crear uno automáticamente
    if (!$profesor) {
        // Obtener id_persona
        $query = "SELECT id FROM tbl_persona WHERE id_usuario = :user_id";
        $stmt = $db->prepare($query);
        $stmt->bindValue(':user_id', $user_id, PDO::PARAM_INT);
        $stmt->execute();
        $persona = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($persona) {
            $id_persona = $persona['id'];
            
            // Verificar columnas de tbl_profesor
            $columns = $db->query("DESCRIBE tbl_profesor")->fetchAll(PDO::FETCH_COLUMN);
            
            // Crear profesor
            if (in_array('estado', $columns)) {
                $query = "INSERT INTO tbl_profesor (id_persona, especialidad, titulo_academico, estado)
                          VALUES (:id_persona, :especialidad, :titulo, :estado)";
                $stmt = $db->prepare($query);
                $stmt->bindValue(':id_persona', $id_persona, PDO::PARAM_INT);
                $stmt->bindValue(':especialidad', 'General', PDO::PARAM_STR);
                $stmt->bindValue(':titulo', 'Licenciatura', PDO::PARAM_STR);
                $stmt->bindValue(':estado', 1, PDO::PARAM_INT);
            } else {
                $query = "INSERT INTO tbl_profesor (id_persona, especialidad, titulo_academico)
                          VALUES (:id_persona, :especialidad, :titulo)";
                $stmt = $db->prepare($query);
                $stmt->bindValue(':id_persona', $id_persona, PDO::PARAM_INT);
                $stmt->bindValue(':especialidad', 'General', PDO::PARAM_STR);
                $stmt->bindValue(':titulo', 'Licenciatura', PDO::PARAM_STR);
            }
            $stmt->execute();
            
            $id_profesor = $db->lastInsertId();
            $success_message = 'Perfil de profesor creado exitosamente.';
            
            // Recargar datos del profesor
            $query = "SELECT p.id as id_profesor, per.primer_nombre, per.primer_apellido, per.email
                      FROM tbl_profesor p
                      JOIN tbl_persona per ON p.id_persona = per.id
                      WHERE per.id_usuario = :user_id";
            $stmt = $db->prepare($query);
            $stmt->bindValue(':user_id', $user_id, PDO::PARAM_INT);
            $stmt->execute();
            $profesor = $stmt->fetch(PDO::FETCH_ASSOC);
        } else {
            throw new Exception('No se encontró el perfil del usuario en la base de datos.');
        }
    }
    
    if ($profesor) {
        // Obtener asignaciones del profesor
        $query = "SELECT ad.id, asig.nombre as asignatura, g.nombre as grado, s.nombre as seccion,
                  ad.anno, COUNT(DISTINCT m.id) as total_estudiantes
                  FROM tbl_asignacion_docente ad
                  JOIN tbl_asignatura asig ON ad.id_asignatura = asig.id
                  JOIN tbl_seccion s ON ad.id_seccion = s.id
                  JOIN tbl_grado g ON s.id_grado = g.id
                  LEFT JOIN tbl_matricula m ON s.id = m.id_seccion AND m.anno = ad.anno AND m.estado = 'activo'
                  WHERE ad.id_profesor = :id_profesor
                  GROUP BY ad.id";
        $stmt = $db->prepare($query);
        $stmt->bindValue(':id_profesor', $profesor['id_profesor'], PDO::PARAM_INT);
        $stmt->execute();
        $asignaciones = $stmt->fetchAll(PDO::FETCH_ASSOC) ?? [];
        
        // Calcular total de estudiantes
        if (!empty($asignaciones)) {
            $total_estudiantes = array_sum(array_column($asignaciones, 'total_estudiantes')) ?? 0;
        }
        
        // Contar actividades
        $query = "SELECT COUNT(*) as total FROM tbl_actividad a
                  JOIN tbl_asignacion_docente ad ON a.id_asignacion_docente = ad.id
                  WHERE ad.id_profesor = :id_profesor AND a.estado IN ('publicado', 'activo')";
        $stmt = $db->prepare($query);
        $stmt->bindValue(':id_profesor', $profesor['id_profesor'], PDO::PARAM_INT);
        $stmt->execute();
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        $total_actividades = $result['total'] ?? 0;
    }
    
} catch (PDOException $e) {
    error_log("Error PDO: " . $e->getMessage());
    $error_message = 'Error de base de datos. Contacta al administrador.';
} catch (Exception $e) {
    error_log("Error: " . $e->getMessage());
    $error_message = $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Panel del Profesor - Educación Plus</title>
    
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    
    <style>
        :root { --primary: #2c3e50; --secondary: #3498db; --success: #2ecc71; --warning: #f39c12; --danger: #e74c3c; }
        body { font-family: 'Segoe UI', sans-serif; background: #f5f7fa; }
        .sidebar { position: fixed; top: 0; left: 0; height: 100vh; width: 260px; background: var(--primary); color: white; padding-top: 20px; z-index: 1000; overflow-y: auto; }
        .sidebar .brand { text-align: center; padding: 0 20px 20px; border-bottom: 1px solid rgba(255,255,255,0.1); margin-bottom: 20px; }
        .sidebar .brand h4 { margin: 0; font-size: 1.3rem; }
        .sidebar .brand small { color: rgba(255,255,255,0.7); font-size: 0.85rem; }
        .sidebar .nav-link { color: rgba(255,255,255,0.85); padding: 12px 20px; margin: 2px 10px; border-radius: 8px; transition: all 0.2s; }
        .sidebar .nav-link:hover, .sidebar .nav-link.active { color: white; background: rgba(255,255,255,0.15); }
        .sidebar .nav-link i { width: 24px; text-align: center; margin-right: 8px; }
        .main-content { margin-left: 260px; padding: 25px; }
        .card-custom { background: white; border-radius: 12px; box-shadow: 0 2px 12px rgba(0,0,0,0.08); border: none; margin-bottom: 24px; }
        .stat-card { border-left: 4px solid var(--secondary); transition: all 0.3s; }
        .stat-card:hover { transform: translateY(-3px); box-shadow: 0 6px 20px rgba(0,0,0,0.12); }
        .stat-card.success { border-left-color: var(--success); }
        .stat-card.warning { border-left-color: var(--warning); }
        .stat-card.danger { border-left-color: var(--danger); }
        .page-header { background: linear-gradient(135deg, var(--primary) 0%, var(--secondary) 100%); color: white; padding: 25px; border-radius: 12px; margin-bottom: 25px; }
        .btn-custom { padding: 10px 20px; border-radius: 8px; font-weight: 500; transition: all 0.2s; }
        .btn-custom:hover { transform: translateY(-2px); box-shadow: 0 4px 15px rgba(0,0,0,0.2); }
        @media (max-width: 992px) { .sidebar { transform: translateX(-100%); } .sidebar.active { transform: translateX(0); } .main-content { margin-left: 0; } }
    </style>
</head>
<body>
    <!-- Sidebar -->
    <div class="sidebar" id="sidebar">
        <div class="brand">
            <h4><i class="fas fa-graduation-cap"></i> Educación Plus</h4>
            <small>Panel del Profesor</small>
        </div>
        <nav class="nav flex-column">
            <a class="nav-link active" href="#"><i class="fas fa-home"></i> Dashboard</a>
            <a class="nav-link" href="modules/profesor/aula_virtual.php"><i class="fas fa-chalkboard"></i> Aula Virtual</a>
            <a class="nav-link" href="modules/profesor/gestionar_actividades.php"><i class="fas fa-tasks"></i> Actividades</a>
            <a class="nav-link" href="modules/profesor/calificaciones.php"><i class="fas fa-star"></i> Calificaciones</a>
            <a class="nav-link" href="modules/profesor/estudiantes.php"><i class="fas fa-users"></i> Estudiantes</a>
            <a class="nav-link" href="modules/profesor/asignar_examen.php"><i class="fas fa-file-alt"></i> Asignar Examen</a>
            <a class="nav-link" href="modules/profesor/tablon.php"><i class="fas fa-star"></i> Tablón</a>
            <a class="nav-link" href="logout.php"><i class="fas fa-sign-out-alt"></i> Cerrar Sesión</a>
        </nav>
    </div>

    <!-- Main Content -->
    <div class="main-content">
        <!-- Page Header -->
        <div class="page-header">
            <div class="d-flex justify-content-between align-items-center flex-wrap gap-3">
                <div>
                    <h2 class="mb-2"><i class="fas fa-chalkboard-teacher"></i> Panel del Profesor</h2>
                    <?php if ($profesor): ?>
                    <p class="mb-0 opacity-75">
                        <?= htmlspecialchars($profesor['primer_nombre'] . ' ' . $profesor['primer_apellido']) ?>
                    </p>
                    <?php endif; ?>
                </div>
                <button class="btn btn-light btn-custom" onclick="window.location.href='modules/profesor/aula_virtual.php'">
                    <i class="fas fa-plus"></i> Ir al Aula Virtual
                </button>
            </div>
        </div>

        <!-- Messages -->
        <?php if ($success_message): ?>
        <div class="alert alert-success alert-dismissible fade show">
            <i class="fas fa-check-circle"></i>
            <?= htmlspecialchars($success_message) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php endif; ?>

        <?php if ($error_message): ?>
        <div class="alert alert-danger alert-dismissible fade show">
            <i class="fas fa-exclamation-circle"></i>
            <?= htmlspecialchars($error_message) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php endif; ?>

        <?php if ($profesor): ?>
        <!-- Stats Cards -->
        <div class="row mb-4">
            <div class="col-lg-3 col-md-6 mb-3">
                <div class="card-custom p-4 stat-card">
                    <div class="d-flex justify-content-between">
                        <div>
                            <h3 class="mb-0 text-primary"><?= count($asignaciones) ?></h3>
                            <p class="mb-0 text-muted small">Asignaciones</p>
                        </div>
                        <i class="fas fa-book fa-2x text-primary opacity-25"></i>
                    </div>
                </div>
            </div>
            <div class="col-lg-3 col-md-6 mb-3">
                <div class="card-custom p-4 stat-card success">
                    <div class="d-flex justify-content-between">
                        <div>
                            <h3 class="mb-0 text-success"><?= $total_estudiantes ?></h3>
                            <p class="mb-0 text-muted small">Estudiantes</p>
                        </div>
                        <i class="fas fa-users fa-2x text-success opacity-25"></i>
                    </div>
                </div>
            </div>
            <div class="col-lg-3 col-md-6 mb-3">
                <div class="card-custom p-4 stat-card warning">
                    <div class="d-flex justify-content-between">
                        <div>
                            <h3 class="mb-0 text-warning"><?= $total_actividades ?></h3>
                            <p class="mb-0 text-muted small">Actividades</p>
                        </div>
                        <i class="fas fa-tasks fa-2x text-warning opacity-25"></i>
                    </div>
                </div>
            </div>
            <div class="col-lg-3 col-md-6 mb-3">
                <div class="card-custom p-4 stat-card danger">
                    <div class="d-flex justify-content-between">
                        <div>
                            <h3 class="mb-0 text-danger">0</h3>
                            <p class="mb-0 text-muted small">Pendientes</p>
                        </div>
                        <i class="fas fa-clock fa-2x text-danger opacity-25"></i>
                    </div>
                </div>
            </div>
        </div>

        <!-- Asignaciones -->
        <div class="card-custom">
            <div class="card-header bg-white py-3">
                <div class="d-flex justify-content-between align-items-center">
                    <h5 class="mb-0"><i class="fas fa-book text-primary"></i> Mis Asignaciones</h5>
                    <span class="badge bg-primary"><?= count($asignaciones) ?> clases</span>
                </div>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Asignatura</th>
                                <th>Grado</th>
                                <th>Sección</th>
                                <th>Año</th>
                                <th>Estudiantes</th>
                                <th>Acciones</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($asignaciones)): ?>
                            <tr>
                                <td colspan="6" class="text-center py-5 text-muted">
                                    <i class="fas fa-inbox fa-3x mb-3 d-block"></i>
                                    <p class="mb-0">No tienes asignaciones registradas</p>
                                    <small>Contacta al administrador para que te asigne clases</small>
                                </td>
                            </tr>
                            <?php else: ?>
                            <?php foreach ($asignaciones as $asig): ?>
                            <tr>
                                <td>
                                    <strong><?= htmlspecialchars($asig['asignatura'] ?? 'N/A') ?></strong>
                                </td>
                                <td><?= htmlspecialchars($asig['grado'] ?? 'N/A') ?></td>
                                <td>
                                    <span class="badge bg-info"><?= htmlspecialchars($asig['seccion'] ?? 'N/A') ?></span>
                                </td>
                                <td><?= htmlspecialchars($asig['anno'] ?? 'N/A') ?></td>
                                <td>
                                    <span class="badge bg-success"><?= $asig['total_estudiantes'] ?? 0 ?></span>
                                </td>
                                <td>
                                    <a href="aula_virtual.php?asignacion=<?= $asig['id'] ?>" class="btn btn-sm btn-primary btn-custom">
                                        <i class="fas fa-chalkboard"></i> Aula
                                    </a>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- Quick Actions -->
        <div class="card-custom">
            <div class="card-header bg-white py-3">
                <h5 class="mb-0"><i class="fas fa-bolt text-warning"></i> Acciones Rápidas</h5>
            </div>
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-md-3 col-sm-6">
                        <a href="aula_virtual.php" class="text-decoration-none">
                            <div class="card-custom p-3 text-center h-100">
                                <i class="fas fa-chalkboard fa-2x text-primary mb-2"></i>
                                <h6 class="mb-0">Aula Virtual</h6>
                                <small class="text-muted">Publicar recursos</small>
                            </div>
                        </a>
                    </div>
                    <div class="col-md-3 col-sm-6">
                        <a href="gestionar_actividades.php" class="text-decoration-none">
                            <div class="card-custom p-3 text-center h-100">
                                <i class="fas fa-tasks fa-2x text-warning mb-2"></i>
                                <h6 class="mb-0">Actividades</h6>
                                <small class="text-muted">Crear tareas/exámenes</small>
                            </div>
                        </a>
                    </div>
                    <div class="col-md-3 col-sm-6">
                        <a href="calificaciones.php" class="text-decoration-none">
                            <div class="card-custom p-3 text-center h-100">
                                <i class="fas fa-star fa-2x text-success mb-2"></i>
                                <h6 class="mb-0">Calificaciones</h6>
                                <small class="text-muted">Evaluar estudiantes</small>
                            </div>
                        </a>
                    </div>
                    <div class="col-md-3 col-sm-6">
                        <a href="estudiantes.php" class="text-decoration-none">
                            <div class="card-custom p-3 text-center h-100">
                                <i class="fas fa-users fa-2x text-info mb-2"></i>
                                <h6 class="mb-0">Estudiantes</h6>
                                <small class="text-muted">Ver lista de clase</small>
                            </div>
                        </a>
                    </div>
                </div>
            </div>
        </div>

        <?php else: ?>
        <!-- Si no hay datos del profesor -->
        <div class="alert alert-warning">
            <i class="fas fa-exclamation-triangle"></i>
            No se encontró el perfil del profesor. Por favor contacta al administrador.
            <br><small class="text-muted">User ID: <?= htmlspecialchars($user_id ?? 'desconocido') ?></small>
        </div>
        <?php endif; ?>
    </div>

    <!-- Scripts -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Sidebar toggle para móvil
        document.addEventListener('DOMContentLoaded', function() {
            const sidebar = document.getElementById('sidebar');
            
            // Auto-hide sidebar on mobile
            if (window.innerWidth < 992) {
                sidebar.classList.remove('active');
            }
            
            // Click outside to close sidebar on mobile
            document.addEventListener('click', function(event) {
                if (window.innerWidth < 992) {
                    const isClickInsideSidebar = sidebar.contains(event.target);
                    const isClickOnToggle = event.target.closest('#sidebarToggle');
                    
                    if (!isClickInsideSidebar && !isClickOnToggle && sidebar.classList.contains('active')) {
                        sidebar.classList.remove('active');
                    }
                }
            });
        });
    </script>
</body>
</html>