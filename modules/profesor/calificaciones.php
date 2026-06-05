<?php
session_start();
require_once __DIR__ . '/../../config/database.php';

// Verificar que sea profesor
if (!isset($_SESSION['user_id']) || $_SESSION['rol'] != 'profesor') {
    header("Location: " . BASE_URL . "/login.php");
    exit;
}

$database = new Database();
$db = $database->getConnection();
$user_id = $_SESSION['user_id'];

// Obtener datos del profesor
$query = "SELECT p.id as id_profesor, per.primer_nombre, per.primer_apellido, per.email
          FROM tbl_profesor p
          JOIN tbl_persona per ON p.id_persona = per.id
          WHERE per.id_usuario = :user_id";
$stmt = $db->prepare($query);
$stmt->bindValue(':user_id', $user_id, PDO::PARAM_INT);
$stmt->execute();
$profesor = $stmt->fetch(PDO::FETCH_ASSOC);
$id_profesor = $profesor['id_profesor'] ?? 0;

$mensaje = '';
$tipo_mensaje = '';

// ===== PROCESAR ACCIONES POST =====
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $accion = $_POST['accion'] ?? '';
    
    try {
        $db->beginTransaction();
        
        // === CALIFICAR ENTREGA ===
        if ($accion == 'calificar_entrega') {
            $id_entrega = filter_input(INPUT_POST, 'id_entrega', FILTER_VALIDATE_INT);
            $nota_obtenida = filter_input(INPUT_POST, 'nota_obtenida', FILTER_VALIDATE_FLOAT);
            $retroalimentacion = trim($_POST['retroalimentacion'] ?? '');
            $estado_entrega = $_POST['estado_entrega'] ?? 'calificado';
            
            // Verificar que la entrega pertenece a una actividad de este profesor
            $check = $db->prepare("
                SELECT ea.id, a.nota_maxima
                FROM tbl_entrega_actividad ea
                JOIN tbl_actividad a ON ea.id_actividad = a.id
                JOIN tbl_asignacion_docente ad ON a.id_asignacion_docente = ad.id
                WHERE ea.id = :id_entrega AND ad.id_profesor = :id_profesor
            ");
            $check->execute([':id_entrega' => $id_entrega, ':id_profesor' => $id_profesor]);
            $entrega = $check->fetch(PDO::FETCH_ASSOC);
            
            if ($entrega) {
                // Validar que la nota no exceda el máximo
                $nota_maxima = $entrega['nota_maxima'] ?? 10;
                if ($nota_obtenida > $nota_maxima) {
                    $nota_obtenida = $nota_maxima;
                }
                
                $query = "UPDATE tbl_entrega_actividad SET 
                          nota_obtenida = :nota, 
                          retroalimentacion = :retro, 
                          estado_entrega = :estado,
                          fecha_calificacion = NOW()
                          WHERE id = :id";
                $stmt = $db->prepare($query);
                $stmt->execute([
                    ':nota' => $nota_obtenida,
                    ':retro' => $retroalimentacion,
                    ':estado' => $estado_entrega,
                    ':id' => $id_entrega
                ]);
                
                $db->commit();
                $mensaje = 'Calificación guardada exitosamente';
                $tipo_mensaje = 'success';
            } else {
                throw new Exception("No tiene permiso para calificar esta entrega");
            }
            
        } elseif ($accion == 'calificar_multiple') {
            // Calificación masiva
            $calificaciones = $_POST['calificaciones'] ?? [];
            $id_actividad = filter_input(INPUT_POST, 'id_actividad', FILTER_VALIDATE_INT);
            
            // Verificar propiedad de la actividad
            $check = $db->prepare("
                SELECT a.id, a.nota_maxima 
                FROM tbl_actividad a
                JOIN tbl_asignacion_docente ad ON a.id_asignacion_docente = ad.id
                WHERE a.id = :id_actividad AND ad.id_profesor = :id_profesor
            ");
            $check->execute([':id_actividad' => $id_actividad, ':id_profesor' => $id_profesor]);
            
            if ($check->rowCount() > 0) {
                $actividad = $check->fetch(PDO::FETCH_ASSOC);
                $nota_maxima = $actividad['nota_maxima'] ?? 10;
                
                $query = "UPDATE tbl_entrega_actividad SET 
                          nota_obtenida = :nota, 
                          retroalimentacion = :retro, 
                          estado_entrega = 'calificado',
                          fecha_calificacion = NOW()
                          WHERE id = :id_entrega";
                $stmt = $db->prepare($query);
                
                $actualizadas = 0;
                foreach ($calificaciones as $id_entrega => $data) {
                    $nota = filter_var($data['nota'] ?? 0, FILTER_VALIDATE_FLOAT);
                    if ($nota !== false && $nota !== null) {
                        // Limitar nota al máximo
                        $nota = min($nota, $nota_maxima);
                        
                        $stmt->execute([
                            ':nota' => $nota,
                            ':retro' => $data['retroalimentacion'] ?? '',
                            ':id_entrega' => $id_entrega
                        ]);
                        $actualizadas++;
                    }
                }
                
                $db->commit();
                $mensaje = "$actualizadas calificaciones actualizadas";
                $tipo_mensaje = 'success';
            } else {
                throw new Exception("Actividad no válida");
            }
        }
        
    } catch (Exception $e) {
        $db->rollBack();
        error_log("Error en calificaciones.php: " . $e->getMessage());
        $mensaje = 'Error: ' . $e->getMessage();
        $tipo_mensaje = 'danger';
    }
}

// ===== OBTENER ASIGNACIONES DEL PROFESOR =====
$query = "SELECT ad.id, ad.anno, asig.nombre as asignatura_nombre, asig.codigo as asignatura_codigo,
          g.nombre as grado_nombre, s.nombre as seccion_nombre,
          COUNT(DISTINCT m.id) as total_estudiantes
          FROM tbl_asignacion_docente ad
          JOIN tbl_asignatura asig ON ad.id_asignatura = asig.id
          JOIN tbl_seccion s ON ad.id_seccion = s.id
          JOIN tbl_grado g ON s.id_grado = g.id
          LEFT JOIN tbl_matricula m ON s.id = m.id_seccion AND m.anno = ad.anno AND m.estado = 'activo'
          WHERE ad.id_profesor = :id_profesor
          GROUP BY ad.id
          ORDER BY g.nombre, s.nombre, asig.nombre";
$stmt = $db->prepare($query);
$stmt->bindValue(':id_profesor', $id_profesor, PDO::PARAM_INT);
$stmt->execute();
$asignaciones = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ===== FILTROS =====
$id_asignacion_filtro = $_GET['asignacion'] ?? ($asignaciones[0]['id'] ?? 0);
$id_actividad_filtro = $_GET['actividad'] ?? 0;
$busqueda = $_GET['busqueda'] ?? '';
$estado_filtro = $_GET['estado_entrega'] ?? 'todos';

// ===== OBTENER ACTIVIDADES DE LA ASIGNACIÓN =====
$actividades = [];
if ($id_asignacion_filtro) {
    $query_act = "SELECT a.id, a.titulo, a.tipo, a.nota_maxima, a.fecha_limite, a.estado,
                 COUNT(DISTINCT ea.id) as total_entregas,
                 COUNT(CASE WHEN ea.estado_entrega = 'calificado' THEN 1 END) as calificadas,
                 AVG(ea.nota_obtenida) as promedio_notas
                 FROM tbl_actividad a
                 LEFT JOIN tbl_entrega_actividad ea ON a.id = ea.id_actividad
                 WHERE a.id_asignacion_docente = :id_asignacion
                 AND a.estado IN ('publicado', 'activo', 'cerrado')
                 GROUP BY a.id
                 ORDER BY a.fecha_programada DESC";
    
    $stmt_act = $db->prepare($query_act);
    $stmt_act->execute([':id_asignacion' => $id_asignacion_filtro]);
    $actividades = $stmt_act->fetchAll(PDO::FETCH_ASSOC);
}

// ===== OBTENER ENTREGAS PARA CALIFICAR =====
$entregas = [];
$estadisticas_actividad = null;

if ($id_actividad_filtro) {
    try {
        $query_entregas = "SELECT 
                          ea.id as id_entrega,
                          ea.estado_entrega,
                          ea.nota_obtenida,
                          ea.retroalimentacion,
                          ea.fecha_entrega,
                          ea.fecha_calificacion,
                          e.id as id_estudiante,
                          p.primer_nombre,
                          p.primer_apellido,
                          p.email,
                          e.nie,
                          a.titulo as actividad_titulo,
                          a.nota_maxima,
                          a.tipo as actividad_tipo
                          FROM tbl_entrega_actividad ea
                          JOIN tbl_actividad a ON ea.id_actividad = a.id
                          JOIN tbl_estudiante e ON ea.id_estudiante = e.id
                          JOIN tbl_persona p ON e.id_persona = p.id
                          WHERE ea.id_actividad = :id_actividad";
        
        $params = [':id_actividad' => $id_actividad_filtro];
        
        // Filtro de búsqueda
        if (!empty($busqueda)) {
            $query_entregas .= " AND (p.primer_nombre LIKE :busqueda OR p.primer_apellido LIKE :busqueda OR e.nie LIKE :busqueda)";
            $params[':busqueda'] = "%$busqueda%";
        }
        
        // Filtro de estado
        if ($estado_filtro != 'todos') {
            $query_entregas .= " AND ea.estado_entrega = :estado";
            $params[':estado'] = $estado_filtro;
        }
        
        $query_entregas .= " ORDER BY p.primer_apellido, p.primer_nombre";
        
        $stmt_ent = $db->prepare($query_entregas);
        foreach ($params as $key => $value) {
            $stmt_ent->bindValue($key, $value);
        }
        $stmt_ent->execute();
        $entregas = $stmt_ent->fetchAll(PDO::FETCH_ASSOC);
        
        // Calcular estadísticas de la actividad
        if (!empty($entregas)) {
            $notas = array_filter(array_column($entregas, 'nota_obtenida'), fn($n) => $n !== null);
            $estadisticas_actividad = [
                'total' => count($entregas),
                'calificadas' => count(array_filter($entregas, fn($e) => $e['estado_entrega'] == 'calificado')),
                'pendientes' => count(array_filter($entregas, fn($e) => $e['estado_entrega'] != 'calificado')),
                'promedio' => !empty($notas) ? round(array_sum($notas) / count($notas), 2) : 0,
                'maxima' => !empty($notas) ? max($notas) : 0,
                'minima' => !empty($notas) ? min($notas) : 0
            ];
        }
        
    } catch (PDOException $e) {
        error_log("Error al obtener entregas: " . $e->getMessage());
    }
}

// Tipos de actividad para iconos
$tipos_actividad = [
    'tarea' => ['label' => 'Tarea', 'icon' => 'fa-clipboard-list', 'color' => 'warning'],
    'examen' => ['label' => 'Examen', 'icon' => 'fa-file-alt', 'color' => 'danger'],
    'video' => ['label' => 'Video', 'icon' => 'fa-video', 'color' => 'info'],
    'youtube' => ['label' => 'YouTube', 'icon' => 'fa-youtube', 'color' => 'danger'],
    'articulo' => ['label' => 'Artículo', 'icon' => 'fa-file-alt', 'color' => 'primary'],
    'referencia' => ['label' => 'Referencia', 'icon' => 'fa-book', 'color' => 'purple'],
    'podcast' => ['label' => 'Podcast', 'icon' => 'fa-podcast', 'color' => 'success'],
    'revista' => ['label' => 'Revista', 'icon' => 'fa-newspaper', 'color' => 'teal'],
    'enlace' => ['label' => 'Enlace', 'icon' => 'fa-link', 'color' => 'secondary']
];

// Estados de entrega
$estados_entrega = [
    'pendiente' => ['label' => 'Pendiente', 'class' => 'bg-secondary'],
    'entregado' => ['label' => 'Entregado', 'class' => 'bg-info'],
    'revisado' => ['label' => 'Revisado', 'class' => 'bg-warning'],
    'calificado' => ['label' => 'Calificado', 'class' => 'bg-success']
];
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Calificaciones - Educación Plus</title>
    
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet">
    
    <style>
        :root { --primary: #2c3e50; --secondary: #3498db; --success: #2ecc71; --warning: #f39c12; --danger: #e74c3c; --sidebar-width: 260px; }
        body { font-family: 'Segoe UI', sans-serif; background: #f5f7fa; }
        .sidebar { position: fixed; top: 0; left: 0; height: 100vh; width: var(--sidebar-width); background: var(--primary); color: white; padding-top: 20px; z-index: 1000; overflow-y: auto; }
        .sidebar .nav-link { color: rgba(255,255,255,0.85); padding: 12px 20px; margin: 2px 0; border-radius: 8px; }
        .sidebar .nav-link:hover, .sidebar .nav-link.active { color: white; background: rgba(255,255,255,0.15); }
        .sidebar .nav-link i { width: 24px; text-align: center; margin-right: 8px; }
        .main-content { margin-left: var(--sidebar-width); padding: 20px; }
        .card-custom { background: white; border-radius: 12px; box-shadow: 0 2px 12px rgba(0,0,0,0.08); border: none; margin-bottom: 20px; }
        .student-avatar { width: 40px; height: 40px; border-radius: 50%; background: linear-gradient(135deg, var(--secondary), var(--primary)); color: white; display: flex; align-items: center; justify-content: center; font-weight: 700; font-size: 0.9rem; }
        .stat-card { background: white; border-radius: 12px; padding: 20px; box-shadow: 0 2px 8px rgba(0,0,0,0.08); text-align: center; transition: transform 0.2s; }
        .stat-card:hover { transform: translateY(-3px); }
        .stat-card i { font-size: 2rem; margin-bottom: 8px; }
        .badge-estado { padding: 5px 12px; border-radius: 15px; font-size: 0.75rem; font-weight: 600; }
        .nota-input { width: 80px; text-align: center; font-weight: 600; }
        .nota-input.aprobado { color: var(--success); }
        .nota-input.reprobado { color: var(--danger); }
        .retro-textarea { min-height: 80px; resize: vertical; }
        .activity-selector { max-height: 300px; overflow-y: auto; }
        .table-hover tbody tr:hover { background: #f8f9fa; }
        .progress-thin { height: 6px; }
        @media (max-width: 992px) { .sidebar { transform: translateX(-100%); } .sidebar.active { transform: translateX(0); } .main-content { margin-left: 0; } }
    </style>
</head>
<body>
    <!-- Sidebar -->
    <div class="sidebar" id="sidebar">
        <div class="text-center mb-4 px-3">
            <div class="d-flex align-items-center justify-content-center gap-2 mb-2">
                <div class="bg-white text-primary rounded-circle d-flex align-items-center justify-content-center" style="width: 45px; height: 45px; font-weight: 700;">
                    <?= strtoupper(substr($profesor['primer_nombre'], 0, 1)) ?>
                </div>
                <div class="text-start">
                    <div class="fw-bold"><?= htmlspecialchars($profesor['primer_nombre']) ?></div>
                    <small class="text-white-50">Profesor</small>
                </div>
            </div>
        </div>
        
        <nav class="nav flex-column px-2">
            <a class="nav-link" href="aula_virtual.php"><i class="fas fa-chalkboard-teacher"></i> Aula Virtual</a>
            <a class="nav-link" href="gestionar_estudiantes.php"><i class="fas fa-user-graduate"></i> Estudiantes</a>
            <a class="nav-link active" href="#"><i class="fas fa-star"></i> Calificaciones</a>
            <a class="nav-link" href="reportes.php"><i class="fas fa-chart-bar"></i> Reportes</a>
            <a class="nav-link" href="../../logout.php"><i class="fas fa-sign-out-alt"></i> Cerrar Sesión</a>
        </nav>
        
        <div class="mt-4 px-3">
            <small class="text-white-50">Mis Asignaciones</small>
            <div class="mt-2 activity-selector">
                <?php foreach ($asignaciones as $asig): ?>
                <a href="?asignacion=<?= $asig['id'] ?>" class="d-block text-white-50 text-decoration-none py-1 px-2 rounded small <?= $id_asignacion_filtro == $asig['id'] ? 'bg-white-10 text-white' : 'hover-bg-white-10' ?>">
                    <i class="fas fa-chevron-right me-1 small"></i>
                    <?= htmlspecialchars($asig['asignatura_nombre']) ?> - <?= htmlspecialchars($asig['grado_nombre']) ?> <?= htmlspecialchars($asig['seccion_nombre']) ?>
                    <span class="badge bg-primary float-end"><?= $asig['total_estudiantes'] ?></span>
                </a>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <!-- Main Content -->
    <div class="main-content">
        <!-- Header -->
        <div class="d-flex justify-content-between align-items-center mb-4">
            <div>
                <h2 class="mb-1"><i class="fas fa-star"></i> Calificaciones</h2>
                <nav aria-label="breadcrumb">
                    <ol class="breadcrumb mb-0">
                        <li class="breadcrumb-item"><a href="aula_virtual.php">Aula Virtual</a></li>
                        <li class="breadcrumb-item active">Calificaciones</li>
                    </ol>
                </nav>
            </div>
        </div>

        <!-- Messages -->
        <?php if ($mensaje): ?>
        <div class="alert alert-<?= $tipo_mensaje ?> alert-dismissible fade show">
            <i class="fas fa-<?= $tipo_mensaje == 'success' ? 'check-circle' : 'exclamation-circle' ?>"></i>
            <?= htmlspecialchars($mensaje) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php endif; ?>

        <?php if (empty($asignaciones)): ?>
        <div class="card-custom p-5 text-center">
            <i class="fas fa-exclamation-triangle fa-3x text-warning mb-3"></i>
            <h4>No tienes asignaciones registradas</h4>
            <p class="text-muted">Contacta al administrador para que te asigne clases</p>
        </div>
        <?php else: ?>
        
        <!-- Selector de Actividad -->
        <div class="card-custom p-3 mb-4">
            <form method="GET" class="row g-3 align-items-end">
                <input type="hidden" name="asignacion" value="<?= $id_asignacion_filtro ?>">
                
                <div class="col-md-4">
                    <label class="form-label small text-muted">Asignación</label>
                    <select name="asignacion" class="form-select" onchange="this.form.submit()">
                        <?php foreach ($asignaciones as $asig): ?>
                        <option value="<?= $asig['id'] ?>" <?= $id_asignacion_filtro == $asig['id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($asig['asignatura_nombre']) ?> - <?= htmlspecialchars($asig['grado_nombre']) ?> <?= htmlspecialchars($asig['seccion_nombre']) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="col-md-4">
                    <label class="form-label small text-muted">Actividad a Calificar</label>
                    <select name="actividad" class="form-select" onchange="this.form.submit()">
                        <option value="">-- Seleccionar actividad --</option>
                        <?php foreach ($actividades as $act): 
                            $tipo = $tipos_actividad[$act['tipo']] ?? ['label' => $act['tipo'], 'icon' => 'fa-file'];
                        ?>
                        <option value="<?= $act['id'] ?>" <?= $id_actividad_filtro == $act['id'] ? 'selected' : '' ?>>
                            <i class="fas <?= $tipo['icon'] ?>"></i> <?= htmlspecialchars($act['titulo']) ?>
                            (<?= $act['calificadas'] ?>/<?= $act['total_entregas'] ?>)
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="col-md-2">
                    <label class="form-label small text-muted">Estado</label>
                    <select name="estado_entrega" class="form-select" onchange="this.form.submit()">
                        <option value="todos" <?= $estado_filtro == 'todos' ? 'selected' : '' ?>>Todos</option>
                        <option value="pendiente" <?= $estado_filtro == 'pendiente' ? 'selected' : '' ?>>Pendientes</option>
                        <option value="entregado" <?= $estado_filtro == 'entregado' ? 'selected' : '' ?>>Entregados</option>
                        <option value="calificado" <?= $estado_filtro == 'calificado' ? 'selected' : '' ?>>Calificados</option>
                    </select>
                </div>
                
                <div class="col-md-2">
                    <label class="form-label small text-muted">Buscar</label>
                    <div class="input-group">
                        <input type="text" name="busqueda" class="form-control" placeholder="Estudiante..." value="<?= htmlspecialchars($busqueda) ?>">
                        <button type="submit" class="btn btn-primary"><i class="fas fa-search"></i></button>
                    </div>
                </div>
            </form>
        </div>

        <?php if ($id_actividad_filtro && $estadisticas_actividad): ?>
        <!-- Estadísticas de la Actividad -->
        <div class="row g-3 mb-4">
            <div class="col-md-3">
                <div class="stat-card">
                    <i class="fas fa-users text-primary"></i>
                    <h4><?= $estadisticas_actividad['total'] ?></h4>
                    <small class="text-muted">Total Entregas</small>
                    <div class="progress progress-thin mt-2">
                        <div class="progress-bar" style="width: 100%"></div>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stat-card">
                    <i class="fas fa-check-circle text-success"></i>
                    <h4><?= $estadisticas_actividad['calificadas'] ?></h4>
                    <small class="text-muted">Calificadas</small>
                    <div class="progress progress-thin mt-2">
                        <div class="progress-bar bg-success" style="width: <?= ($estadisticas_actividad['calificadas']/$estadisticas_actividad['total'])*100 ?>%"></div>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stat-card">
                    <i class="fas fa-clock text-warning"></i>
                    <h4><?= $estadisticas_actividad['pendientes'] ?></h4>
                    <small class="text-muted">Pendientes</small>
                    <div class="progress progress-thin mt-2">
                        <div class="progress-bar bg-warning" style="width: <?= ($estadisticas_actividad['pendientes']/$estadisticas_actividad['total'])*100 ?>%"></div>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stat-card">
                    <i class="fas fa-chart-line text-info"></i>
                    <h4><?= $estadisticas_actividad['promedio'] ?></h4>
                    <small class="text-muted">Promedio General</small>
                    <small class="d-block text-muted">
                        Max: <?= $estadisticas_actividad['maxima'] ?> | Min: <?= $estadisticas_actividad['minima'] ?>
                    </small>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Tabla de Calificaciones -->
        <?php if (!$id_actividad_filtro): ?>
        <div class="card-custom p-5 text-center">
            <i class="fas fa-tasks fa-4x text-muted mb-3"></i>
            <h5>Selecciona una actividad para comenzar a calificar</h5>
            <p class="text-muted">Elige una asignación y luego una actividad del menú superior</p>
        </div>
        
        <?php elseif (empty($entregas)): ?>
        <div class="card-custom p-5 text-center">
            <i class="fas fa-inbox fa-4x text-muted mb-3"></i>
            <h5>No hay entregas para mostrar</h5>
            <p class="text-muted">
                <?php if ($estado_filtro != 'todos' || !empty($busqueda)): ?>
                Intenta limpiar los filtros de búsqueda
                <a href="?asignacion=<?= $id_asignacion_filtro ?>&actividad=<?= $id_actividad_filtro ?>" class="btn btn-sm btn-outline-primary ms-2">Limpiar filtros</a>
                <?php else: ?>
                Los estudiantes aún no han entregado esta actividad
                <?php endif; ?>
            </p>
        </div>
        
        <?php else: ?>
        <form method="POST" id="formCalificaciones">
            <input type="hidden" name="accion" value="calificar_multiple">
            <input type="hidden" name="id_actividad" value="<?= $id_actividad_filtro ?>">
            
            <div class="card-custom">
                <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
                    <h5 class="mb-0">
                        <i class="fas fa-list"></i> Entregas de Estudiantes
                        <span class="badge bg-primary ms-2"><?= count($entregas) ?></span>
                    </h5>
                    <div class="d-flex gap-2">
                        <button type="button" class="btn btn-outline-secondary btn-sm" onclick="llenarNotasAutomaticas()">
                            <i class="fas fa-magic"></i> Autollenar
                        </button>
                        <button type="submit" class="btn btn-primary btn-sm">
                            <i class="fas fa-save"></i> Guardar Todas
                        </button>
                    </div>
                </div>
                
                <div class="table-responsive">
                    <table class="table table-hover mb-0 align-middle">
                        <thead class="table-light">
                            <tr>
                                <th>Estudiante</th>
                                <th>Entrega</th>
                                <th>Estado</th>
                                <th>Nota (<?= $entregas[0]['nota_maxima'] ?>)</th>
                                <th>Retroalimentación</th>
                                <th>Acciones</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($entregas as $entrega): 
                                $iniciales = strtoupper(substr($entrega['primer_nombre'], 0, 1) . substr($entrega['primer_apellido'], 0, 1));
                                $nombre_completo = trim($entrega['primer_nombre'] . ' ' . $entrega['primer_apellido']);
                                $estado = $estados_entrega[$entrega['estado_entrega']] ?? ['label' => $entrega['estado_entrega'], 'class' => 'bg-secondary'];
                                $nota_clase = $entrega['nota_obtenida'] !== null ? 
                                    ($entrega['nota_obtenida'] >= ($entrega['nota_maxima']*0.6) ? 'aprobado' : 'reprobado') : '';
                            ?>
                            <tr>
                                <td>
                                    <div class="d-flex align-items-center gap-2">
                                        <div class="student-avatar"><?= $iniciales ?></div>
                                        <div>
                                            <strong><?= htmlspecialchars($nombre_completo) ?></strong>
                                            <br><small class="text-muted"><?= htmlspecialchars($entrega['nie']) ?></small>
                                        </div>
                                    </div>
                                </td>
                                <td>
                                    <small>
                                        <div><i class="fas fa-calendar"></i> <?= date('d/m/Y', strtotime($entrega['fecha_entrega'])) ?></div>
                                        <?php if ($entrega['fecha_calificacion']): ?>
                                        <div class="text-success"><i class="fas fa-check"></i> <?= date('d/m', strtotime($entrega['fecha_calificacion'])) ?></div>
                                        <?php endif; ?>
                                    </small>
                                </td>
                                <td>
                                    <span class="badge-estado <?= $estado['class'] ?>">
                                        <?= $estado['label'] ?>
                                    </span>
                                </td>
                                <td>
                                    <div class="input-group input-group-sm">
                                        <input type="number" 
                                               name="calificaciones[<?= $entrega['id_entrega'] ?>][nota]" 
                                               class="form-control nota-input <?= $nota_clase ?>"
                                               value="<?= $entrega['nota_obtenida'] ?? '' ?>"
                                               min="0" 
                                               max="<?= $entrega['nota_maxima'] ?>"
                                               step="0.1"
                                               placeholder="-"
                                               onchange="actualizarColorNota(this)">
                                        <span class="input-group-text">/<?= $entrega['nota_maxima'] ?></span>
                                    </div>
                                </td>
                                <td>
                                    <textarea name="calificaciones[<?= $entrega['id_entrega'] ?>][retroalimentacion]" 
                                              class="form-control form-control-sm retro-textarea"
                                              placeholder="Comentarios..."><?= htmlspecialchars($entrega['retroalimentacion'] ?? '') ?></textarea>
                                </td>
                                <td>
                                    <div class="btn-group btn-group-sm">
                                        <button type="button" class="btn btn-outline-primary" onclick="calificarIndividual(<?= $entrega['id_entrega'] ?>)" title="Guardar solo esta">
                                            <i class="fas fa-save"></i>
                                        </button>
                                        <button type="button" class="btn btn-outline-info" title="Ver entrega completa">
                                            <i class="fas fa-eye"></i>
                                        </button>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                
                <div class="card-footer bg-white">
                    <div class="d-flex justify-content-end gap-2">
                        <button type="button" class="btn btn-outline-secondary" onclick="window.history.back()">
                            <i class="fas fa-arrow-left"></i> Volver
                        </button>
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-save"></i> Guardar Todas las Calificaciones
                        </button>
                    </div>
                </div>
            </div>
        </form>
        <?php endif; ?>
        <?php endif; ?>
    </div>

    <!-- Modal Calificación Individual -->
    <div class="modal fade" id="modalCalificar" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title"><i class="fas fa-star"></i> Calificar Entrega</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" id="formCalificarIndividual">
                    <div class="modal-body">
                        <input type="hidden" name="accion" value="calificar_entrega">
                        <input type="hidden" name="id_entrega" id="modal_id_entrega">
                        
                        <div class="mb-3">
                            <label class="form-label">Estudiante</label>
                            <input type="text" class="form-control" id="modal_estudiante" readonly>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Nota Máxima: <span id="modal_nota_maxima">10</span></label>
                            <div class="input-group">
                                <input type="number" 
                                       name="nota_obtenida" 
                                       id="modal_nota" 
                                       class="form-control form-control-lg text-center"
                                       min="0" 
                                       max="10" 
                                       step="0.1"
                                       required>
                                <span class="input-group-text">pts</span>
                            </div>
                            <div class="form-text">La nota no puede exceder el máximo permitido</div>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Estado de la Entrega</label>
                            <select name="estado_entrega" class="form-select">
                                <option value="calificado" selected>✓ Calificado</option>
                                <option value="revisado">📝 Revisado (pendiente nota)</option>
                                <option value="entregado">📤 Entregado (sin revisar)</option>
                            </select>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Retroalimentación</label>
                            <textarea name="retroalimentacion" class="form-control" rows="4" placeholder="Comentarios para el estudiante..."></textarea>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                        <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Guardar Calificación</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Scripts -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
    
    <script>
    $(document).ready(function() {
        // Sidebar responsive
        if (window.innerWidth < 992) {
            $('#sidebar').addClass('active');
        }
        
        // Select2 para filtros
        $('select.form-select').select2({
            theme: 'bootstrap-5',
            width: '100%'
        });
        
        // Validación de notas en tiempo real
        $('input[name*="[nota]"]').on('input', function() {
            const max = $(this).attr('max');
            let val = parseFloat($(this).val());
            
            if (val > max) {
                $(this).val(max);
            }
            if (val < 0) {
                $(this).val(0);
            }
            actualizarColorNota(this);
        });
    });
    
    // Actualizar color de la nota según valor
    function actualizarColorNota(input) {
        const max = parseFloat($(input).attr('max')) || 10;
        const val = parseFloat($(input).val()) || 0;
        const threshold = max * 0.6; // 60% para aprobar
        
        $(input).removeClass('aprobado reprobado');
        if (val >= threshold) {
            $(input).addClass('aprobado');
        } else if (val > 0) {
            $(input).addClass('reprobado');
        }
    }
    
    // Calificar individualmente (abre modal)
    function calificarIndividual(idEntrega) {
        const row = $(`input[name*="[nota]"][value]`).closest('tr');
        const estudiante = row.find('strong').text();
        const notaActual = row.find(`input[name="calificaciones[${idEntrega}][nota]"]`).val();
        const retroActual = row.find(`textarea[name="calificaciones[${idEntrega}][retroalimentacion]"]`).val();
        const notaMax = row.find(`input[name*="[nota]"]`).attr('max');
        
        $('#modal_id_entrega').val(idEntrega);
        $('#modal_estudiante').val(estudiante);
        $('#modal_nota_maxima').text(notaMax);
        $('#modal_nota').val(notaActual).attr('max', notaMax);
        $('#modalCalificar textarea[name="retroalimentacion"]').val(retroActual);
        
        $('#modalCalificar').modal('show');
    }
    
    // Autollenar notas (para pruebas/demo)
    function llenarNotasAutomaticas() {
        if (!confirm('¿Generar notas aleatorias para todas las entregas?\n\n⚠️ Esto es solo para demostración. En producción, califica manualmente.')) {
            return;
        }
        
        $('input[name*="[nota]"]').each(function() {
            const max = parseFloat($(this).attr('max')) || 10;
            // Generar nota entre 50% y 100% del máximo
            const nota = (Math.random() * 0.5 + 0.5) * max;
            $(this).val(nota.toFixed(1));
            actualizarColorNota(this);
        });
        
        // Mensaje temporal
        const originalText = $('.btn-primary').html();
        $('.btn-primary').html('<i class="fas fa-check"></i> ¡Notas generadas!');
        setTimeout(() => $('.btn-primary').html(originalText), 2000);
    }
    
    // Exportar calificaciones a CSV
    function exportarCalificaciones() {
        let csv = 'Estudiante,NIE,Nota,Estado,Retroalimentación\n';
        
        $('tbody tr').each(function() {
            const nombre = $(this).find('strong').text();
            const nie = $(this).find('.text-muted').first().text();
            const nota = $(this).find('input[name*="[nota]"]').val() || '-';
            const estado = $(this).find('.badge-estado').text();
            const retro = $(this).find('textarea').val().replace(/\n/g, ' ');
            
            csv += `"${nombre}","${nie}","${nota}","${estado}","${retro}"\n`;
        });
        
        const blob = new Blob([csv], {type: 'text/csv;charset=utf-8;'});
        const link = document.createElement('a');
        link.href = URL.createObjectURL(blob);
        link.download = `calificaciones_${new Date().toISOString().slice(0,10)}.csv`;
        link.click();
    }
    
    // Confirmar antes de enviar formulario masivo
    $('#formCalificaciones').on('submit', function(e) {
        const notasLlenas = $('input[name*="[nota]"]').filter(function() {
            return $(this).val() !== '';
        }).length;
        
        if (notasLlenas === 0) {
            e.preventDefault();
            alert('⚠️ No has ingresado ninguna nota. Por favor califica al menos un estudiante.');
            return false;
        }
        
        if (!confirm(`¿Estás seguro de guardar ${notasLlenas} calificación(es)?\n\nEsta acción no se puede deshacer.`)) {
            e.preventDefault();
            return false;
        }
    });
    </script>
</body>
</html>