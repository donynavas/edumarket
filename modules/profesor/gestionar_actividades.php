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
$query = "SELECT p.id as id_profesor, per.primer_nombre, per.primer_apellido 
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
        
        // === CREAR ACTIVIDAD ===
        if ($accion == 'crear') {
            $id_asignacion = filter_input(INPUT_POST, 'id_asignacion', FILTER_VALIDATE_INT);
            $titulo = trim($_POST['titulo'] ?? '');
            $descripcion = $_POST['descripcion'] ?? '';
            $tipo = $_POST['tipo'] ?? 'tarea';
            $fecha_programada = $_POST['fecha_programada'] ?? date('Y-m-d H:i:s');
            $fecha_limite = $_POST['fecha_limite'] ?? null;
            $duracion_minutos = filter_input(INPUT_POST, 'duracion_minutos', FILTER_VALIDATE_INT) ?: null;
            $nota_maxima = filter_input(INPUT_POST, 'nota_maxima', FILTER_VALIDATE_FLOAT) ?: 10;
            $contenido = $_POST['contenido'] ?? '';
            $url_recurso = filter_var($_POST['url_recurso'] ?? '', FILTER_VALIDATE_URL) ?: null;
            $recursos_url = $_POST['recursos_url'] ?? '';
            $estado = $_POST['estado'] ?? 'programado';
            
            // Verificar que la asignación pertenece al profesor
            $check = $db->prepare("SELECT id FROM tbl_asignacion_docente WHERE id = :id AND id_profesor = :prof");
            $check->execute([':id' => $id_asignacion, ':prof' => $id_profesor]);
            
            if ($check->rowCount() > 0 && !empty($titulo)) {
                $query = "INSERT INTO tbl_actividad (id_asignacion_docente, titulo, descripcion, tipo, 
                          fecha_programada, fecha_limite, duracion_minutos, nota_maxima, 
                          contenido, url_recurso, recursos_url, estado) 
                          VALUES (:id_asignacion, :titulo, :descripcion, :tipo, 
                                  :fecha_programada, :fecha_limite, :duracion, :nota_maxima, 
                                  :contenido, :url_recurso, :recursos, :estado)";
                $stmt = $db->prepare($query);
                $stmt->execute([
                    ':id_asignacion' => $id_asignacion,
                    ':titulo' => $titulo,
                    ':descripcion' => $descripcion,
                    ':tipo' => $tipo,
                    ':fecha_programada' => $fecha_programada,
                    ':fecha_limite' => $fecha_limite,
                    ':duracion' => $duracion_minutos,
                    ':nota_maxima' => $nota_maxima,
                    ':contenido' => $contenido,
                    ':url_recurso' => $url_recurso,
                    ':recursos' => $recursos_url,
                    ':estado' => $estado
                ]);
                
                $db->commit();
                $mensaje = 'Actividad creada exitosamente';
                $tipo_mensaje = 'success';
            } else {
                throw new Exception("Datos inválidos o no tiene permiso para esta asignación");
            }
            
        } elseif ($accion == 'actualizar') {
            $id_actividad = filter_input(INPUT_POST, 'id_actividad', FILTER_VALIDATE_INT);
            $id_asignacion = filter_input(INPUT_POST, 'id_asignacion', FILTER_VALIDATE_INT);
            $titulo = trim($_POST['titulo'] ?? '');
            $descripcion = $_POST['descripcion'] ?? '';
            $tipo = $_POST['tipo'] ?? 'tarea';
            $fecha_programada = $_POST['fecha_programada'] ?? date('Y-m-d H:i:s');
            $fecha_limite = $_POST['fecha_limite'] ?? null;
            $duracion_minutos = filter_input(INPUT_POST, 'duracion_minutos', FILTER_VALIDATE_INT) ?: null;
            $nota_maxima = filter_input(INPUT_POST, 'nota_maxima', FILTER_VALIDATE_FLOAT) ?: 10;
            $contenido = $_POST['contenido'] ?? '';
            $url_recurso = filter_var($_POST['url_recurso'] ?? '', FILTER_VALIDATE_URL) ?: null;
            $recursos_url = $_POST['recursos_url'] ?? '';
            $estado = $_POST['estado'] ?? 'programado';
            
            // Verificar propiedad
            $check = $db->prepare("SELECT a.id FROM tbl_actividad a
                                  JOIN tbl_asignacion_docente ad ON a.id_asignacion_docente = ad.id
                                  WHERE a.id = :id AND ad.id_profesor = :prof");
            $check->execute([':id' => $id_actividad, ':prof' => $id_profesor]);
            
            if ($check->rowCount() > 0 && !empty($titulo)) {
                $query = "UPDATE tbl_actividad SET 
                          titulo = :titulo, descripcion = :descripcion, tipo = :tipo,
                          fecha_programada = :fecha_programada, fecha_limite = :fecha_limite,
                          duracion_minutos = :duracion, nota_maxima = :nota_maxima,
                          contenido = :contenido, url_recurso = :url_recurso,
                          recursos_url = :recursos, estado = :estado
                          WHERE id = :id";
                $stmt = $db->prepare($query);
                $stmt->execute([
                    ':id' => $id_actividad,
                    ':titulo' => $titulo,
                    ':descripcion' => $descripcion,
                    ':tipo' => $tipo,
                    ':fecha_programada' => $fecha_programada,
                    ':fecha_limite' => $fecha_limite,
                    ':duracion' => $duracion_minutos,
                    ':nota_maxima' => $nota_maxima,
                    ':contenido' => $contenido,
                    ':url_recurso' => $url_recurso,
                    ':recursos' => $recursos_url,
                    ':estado' => $estado
                ]);
                
                $db->commit();
                $mensaje = 'Actividad actualizada exitosamente';
                $tipo_mensaje = 'success';
            } else {
                throw new Exception("No tiene permiso para editar esta actividad");
            }
            
        } elseif ($accion == 'eliminar') {
            $id_actividad = filter_input(INPUT_POST, 'id_actividad', FILTER_VALIDATE_INT);
            
            // Verificar propiedad y eliminar en cascada
            $check = $db->prepare("SELECT a.id FROM tbl_actividad a
                                  JOIN tbl_asignacion_docente ad ON a.id_asignacion_docente = ad.id
                                  WHERE a.id = :id AND ad.id_profesor = :prof");
            $check->execute([':id' => $id_actividad, ':prof' => $id_profesor]);
            
            if ($check->rowCount() > 0) {
                // 1. Eliminar entregas relacionadas primero
                $db->prepare("DELETE FROM tbl_entrega_actividad WHERE id_actividad = :id")
                   ->execute([':id' => $id_actividad]);
                
                // 2. Eliminar la actividad
                $db->prepare("DELETE FROM tbl_actividad WHERE id = :id")
                   ->execute([':id' => $id_actividad]);
                
                $db->commit();
                $mensaje = 'Actividad eliminada exitosamente';
                $tipo_mensaje = 'warning';
            } else {
                throw new Exception("No tiene permiso para eliminar esta actividad");
            }
        }
        
    } catch (Exception $e) {
        $db->rollBack();
        error_log("Error en gestionar_actividades.php: " . $e->getMessage());
        $mensaje = 'Error: ' . $e->getMessage();
        $tipo_mensaje = 'danger';
    }
}

// ===== OBTENER ASIGNACIONES DEL PROFESOR =====
$query = "SELECT ad.id, ad.anno, asig.nombre as asignatura_nombre,
          g.nombre as grado_nombre, s.nombre as seccion_nombre
          FROM tbl_asignacion_docente ad
          JOIN tbl_asignatura asig ON ad.id_asignatura = asig.id
          JOIN tbl_seccion s ON ad.id_seccion = s.id
          JOIN tbl_grado g ON s.id_grado = g.id
          WHERE ad.id_profesor = :id_profesor
          ORDER BY g.nombre, s.nombre, asig.nombre";
$stmt = $db->prepare($query);
$stmt->bindValue(':id_profesor', $id_profesor, PDO::PARAM_INT);
$stmt->execute();
$asignaciones = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ===== FILTROS =====
$id_asignacion_filtro = $_GET['asignacion'] ?? ($asignaciones[0]['id'] ?? 0);
$filtro_tipo = $_GET['tipo'] ?? 'todos';
$filtro_estado = $_GET['estado'] ?? 'todos';
$busqueda = $_GET['busqueda'] ?? '';

// ===== OBTENER ACTIVIDADES =====
$actividades = [];

if ($id_asignacion_filtro) {
    // ✅ CORREGIDO: Usar ea.id en lugar de ea.id_estudiante
    $query_act = "SELECT a.id, a.titulo, a.descripcion, a.tipo, a.contenido, a.url_recurso,
                 a.fecha_programada, a.fecha_limite, a.duracion_minutos, a.nota_maxima, a.estado,
                 COUNT(ea.id) as total_entregas,
                 AVG(ea.nota_obtenida) as promedio_notas
                 FROM tbl_actividad a
                 LEFT JOIN tbl_entrega_actividad ea ON a.id = ea.id_actividad
                 WHERE a.id_asignacion_docente = :id_asignacion";
    
    $params = [':id_asignacion' => $id_asignacion_filtro];
    
    if ($filtro_tipo != 'todos') {
        $query_act .= " AND a.tipo = :tipo";
        $params[':tipo'] = $filtro_tipo;
    }
    
    if ($filtro_estado != 'todos') {
        $query_act .= " AND a.estado = :estado";
        $params[':estado'] = $filtro_estado;
    }
    
    if (!empty($busqueda)) {
        $query_act .= " AND (a.titulo LIKE :busqueda OR a.descripcion LIKE :busqueda)";
        $params[':busqueda'] = "%$busqueda%";
    }
    
    $query_act .= " GROUP BY a.id ORDER BY a.fecha_programada DESC";
    
    $stmt_act = $db->prepare($query_act);
    foreach ($params as $key => $value) {
        $stmt_act->bindValue($key, $value);
    }
    $stmt_act->execute();
    $actividades = $stmt_act->fetchAll(PDO::FETCH_ASSOC);
}

// Tipos de actividad
$tipos_actividad = [
    'tarea' => ['label' => '📝 Tarea', 'icon' => 'fa-clipboard-list', 'color' => 'warning'],
    'examen' => ['label' => '📋 Examen', 'icon' => 'fa-file-alt', 'color' => 'danger'],
    'video' => ['label' => '🎬 Video', 'icon' => 'fa-video', 'color' => 'info'],
    'youtube' => ['label' => '📺 YouTube', 'icon' => 'fa-youtube', 'color' => 'danger'],
    'articulo' => ['label' => '📄 Artículo', 'icon' => 'fa-book-open', 'color' => 'primary'],
    'referencia' => ['label' => '📚 Referencia', 'icon' => 'fa-book', 'color' => 'purple'],
    'podcast' => ['label' => '🎧 Podcast', 'icon' => 'fa-podcast', 'color' => 'success'],
    'revista' => ['label' => '📰 Revista', 'icon' => 'fa-newspaper', 'color' => 'teal'],
    'enlace' => ['label' => '🔗 Enlace', 'icon' => 'fa-link', 'color' => 'secondary']
];

$estados_actividad = [
    'programado' => ['label' => 'Programado', 'class' => 'bg-secondary'],
    'publicado' => ['label' => 'Publicado', 'class' => 'bg-success'],
    'activo' => ['label' => 'Activo', 'class' => 'bg-primary'],
    'cerrado' => ['label' => 'Cerrado', 'class' => 'bg-dark'],
    'eliminado' => ['label' => 'Eliminado', 'class' => 'bg-light text-muted']
];
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gestionar Actividades - Educación Plus</title>
    
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet">
    
    <style>
        :root { --primary: #2c3e50; --secondary: #3498db; --success: #2ecc71; --warning: #f39c12; --danger: #e74c3c; --sidebar-width: 260px; }
        body { font-family: 'Segoe UI', sans-serif; background: #f5f7fa; }
        .sidebar { position: fixed; top: 0; left: 0; height: 100vh; width: var(--sidebar-width); background: var(--primary); color: white; padding-top: 20px; z-index: 1000; }
        .sidebar .nav-link { color: rgba(255,255,255,0.85); padding: 12px 20px; margin: 2px 0; border-radius: 8px; }
        .sidebar .nav-link:hover, .sidebar .nav-link.active { color: white; background: rgba(255,255,255,0.15); }
        .sidebar .nav-link i { width: 24px; text-align: center; margin-right: 8px; }
        .main-content { margin-left: var(--sidebar-width); padding: 20px; }
        .card-custom { background: white; border-radius: 12px; box-shadow: 0 2px 12px rgba(0,0,0,0.08); border: none; margin-bottom: 20px; }
        .activity-card { border-left: 4px solid var(--secondary); transition: all 0.2s; }
        .activity-card:hover { transform: translateY(-3px); box-shadow: 0 6px 20px rgba(0,0,0,0.12); }
        .activity-card.tipo-tarea { border-left-color: var(--warning); }
        .activity-card.tipo-examen { border-left-color: var(--danger); }
        .activity-card.tipo-video { border-left-color: var(--info); }
        .badge-actividad { padding: 6px 14px; border-radius: 20px; font-size: 0.85rem; font-weight: 600; }
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
            <a class="nav-link" href="calificaciones.php"><i class="fas fa-star"></i> Calificaciones</a>
            <a class="nav-link active" href="#"><i class="fas fa-tasks"></i> Actividades</a>
            <a class="nav-link" href="../../logout.php"><i class="fas fa-sign-out-alt"></i> Cerrar Sesión</a>
        </nav>
    </div>

    <!-- Main Content -->
    <div class="main-content">
        <!-- Header -->
        <div class="d-flex justify-content-between align-items-center mb-4">
            <div>
                <h2><i class="fas fa-tasks"></i> Gestionar Actividades</h2>
                <p class="text-muted mb-0">Crear, editar y organizar actividades para tus clases</p>
            </div>
            <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#modalActividad" onclick="prepararModalCrear()">
                <i class="fas fa-plus"></i> Nueva Actividad
            </button>
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
        
        <!-- Filtros -->
        <div class="card-custom p-3 mb-4">
            <form method="GET" class="row g-3">
                <input type="hidden" name="asignacion" value="<?= $id_asignacion_filtro ?>">
                <div class="col-md-3">
                    <label class="form-label small text-muted">Asignación</label>
                    <select name="asignacion" class="form-select" onchange="this.form.submit()">
                        <?php foreach ($asignaciones as $asig): ?>
                        <option value="<?= $asig['id'] ?>" <?= $id_asignacion_filtro == $asig['id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($asig['asignatura_nombre']) ?> - <?= htmlspecialchars($asig['grado_nombre']) ?> <?= htmlspecialchars($asig['seccion_nombre']) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label small text-muted">Tipo</label>
                    <select name="tipo" class="form-select" onchange="this.form.submit()">
                        <option value="todos" <?= $filtro_tipo == 'todos' ? 'selected' : '' ?>>Todos</option>
                        <?php foreach ($tipos_actividad as $key => $tipo): ?>
                        <option value="<?= $key ?>" <?= $filtro_tipo == $key ? 'selected' : '' ?>><?= $tipo['label'] ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label small text-muted">Estado</label>
                    <select name="estado" class="form-select" onchange="this.form.submit()">
                        <option value="todos" <?= $filtro_estado == 'todos' ? 'selected' : '' ?>>Todos</option>
                        <?php foreach ($estados_actividad as $key => $estado): ?>
                        <option value="<?= $key ?>" <?= $filtro_estado == $key ? 'selected' : '' ?>><?= $estado['label'] ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label small text-muted">Buscar</label>
                    <div class="input-group">
                        <input type="text" name="busqueda" class="form-control" placeholder="Título..." value="<?= htmlspecialchars($busqueda) ?>">
                        <button type="submit" class="btn btn-primary"><i class="fas fa-search"></i></button>
                    </div>
                </div>
            </form>
        </div>

        <!-- Lista de Actividades -->
        <?php if (empty($actividades)): ?>
        <div class="card-custom p-5 text-center">
            <i class="fas fa-clipboard-list fa-4x text-muted mb-3"></i>
            <h5>No hay actividades en esta asignación</h5>
            <p class="text-muted">Comienza creando tu primera actividad</p>
            <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#modalActividad" onclick="prepararModalCrear()">
                <i class="fas fa-plus"></i> Crear Primera Actividad
            </button>
        </div>
        <?php else: ?>
        <div class="row g-3">
            <?php foreach ($actividades as $act): 
                $tipo = $tipos_actividad[$act['tipo']] ?? ['label' => $act['tipo'], 'icon' => 'fa-file', 'color' => 'secondary'];
                $estado = $estados_actividad[$act['estado']] ?? ['label' => $act['estado'], 'class' => 'bg-secondary'];
            ?>
            <div class="col-lg-6">
                <div class="card-custom activity-card tipo-<?= htmlspecialchars($act['tipo']) ?> p-3">
                    <div class="d-flex justify-content-between align-items-start mb-2">
                        <span class="badge-actividad bg-<?= $tipo['color'] ?> text-white">
                            <i class="fas <?= $tipo['icon'] ?>"></i> <?= $tipo['label'] ?>
                        </span>
                        <div class="dropdown">
                            <button class="btn btn-sm btn-light" data-bs-toggle="dropdown"><i class="fas fa-ellipsis-v"></i></button>
                            <ul class="dropdown-menu dropdown-menu-end">
                                <li><a class="dropdown-item" href="#" onclick="editarActividad(<?= htmlspecialchars(json_encode($act)) ?>)"><i class="fas fa-edit"></i> Editar</a></li>
                                <li><a class="dropdown-item" href="calificaciones.php?actividad=<?= $act['id'] ?>"><i class="fas fa-star"></i> Calificar</a></li>
                                <li><hr class="dropdown-divider"></li>
                                <li><a class="dropdown-item text-danger" href="#" onclick="eliminarActividad(<?= $act['id'] ?>)"><i class="fas fa-trash"></i> Eliminar</a></li>
                            </ul>
                        </div>
                    </div>
                    <h6 class="mb-2"><?= htmlspecialchars($act['titulo']) ?></h6>
                    <?php if ($act['descripcion']): ?>
                    <p class="text-muted small mb-2"><?= nl2br(htmlspecialchars(substr($act['descripcion'], 0, 100))) ?><?= strlen($act['descripcion']) > 100 ? '...' : '' ?></p>
                    <?php endif; ?>
                    <div class="d-flex justify-content-between align-items-center small text-muted">
                        <span><i class="fas fa-calendar"></i> <?= date('d/m/Y', strtotime($act['fecha_programada'])) ?></span>
                        <?php if ($act['fecha_limite']): ?>
                        <span><i class="fas fa-clock"></i> <?= date('d/m', strtotime($act['fecha_limite'])) ?></span>
                        <?php endif; ?>
                        <span class="badge <?= $estado['class'] ?>"><?= $estado['label'] ?></span>
                    </div>
                    <?php if ($act['total_entregas'] > 0): ?>
                    <div class="mt-2 pt-2 border-top small">
                        <i class="fas fa-inbox text-primary"></i> <?= $act['total_entregas'] ?> entregas
                        <?php if ($act['promedio_notas']): ?>
                        • 📊 <?= number_format($act['promedio_notas'], 2) ?>/<?= $act['nota_maxima'] ?>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
        <?php endif; ?>
    </div>

    <!-- Modal Crear/Editar Actividad -->
    <div class="modal fade" id="modalActividad" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title" id="modalTitle"><i class="fas fa-plus"></i> Nueva Actividad</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" id="formActividad">
                    <div class="modal-body">
                        <input type="hidden" name="accion" id="accion" value="crear">
                        <input type="hidden" name="id_actividad" id="id_actividad">
                        <input type="hidden" name="id_asignacion" value="<?= $id_asignacion_filtro ?>">
                        
                        <div class="row g-3">
                            <div class="col-md-8">
                                <label class="form-label">Título *</label>
                                <input type="text" name="titulo" id="titulo" class="form-control" required placeholder="Título de la actividad">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Tipo *</label>
                                <select name="tipo" id="tipo" class="form-select" required onchange="mostrarCamposTipo()">
                                    <?php foreach ($tipos_actividad as $key => $tipo): ?>
                                    <option value="<?= $key ?>"><?= $tipo['label'] ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-12">
                                <label class="form-label">Descripción</label>
                                <textarea name="descripcion" id="descripcion" class="form-control" rows="3" placeholder="Instrucciones o descripción..."></textarea>
                            </div>
                            
                            <!-- Campos dinámicos según tipo -->
                            <div id="campo_url" class="col-12 d-none">
                                <label class="form-label" id="label_url">URL del Recurso</label>
                                <input type="url" name="url_recurso" id="url_recurso" class="form-control" placeholder="https://...">
                                <small class="text-muted" id="help_url">Enlace al video, artículo, etc.</small>
                            </div>
                            <div id="campo_contenido" class="col-12 d-none">
                                <label class="form-label">Contenido</label>
                                <textarea name="contenido" id="contenido" class="form-control" rows="4" placeholder="Texto del artículo, referencia, etc."></textarea>
                            </div>
                            
                            <div class="col-md-4">
                                <label class="form-label">Fecha Programada</label>
                                <input type="datetime-local" name="fecha_programada" id="fecha_programada" class="form-control" value="<?= date('Y-m-d\TH:i') ?>">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Fecha Límite</label>
                                <input type="datetime-local" name="fecha_limite" id="fecha_limite" class="form-control">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Duración (min)</label>
                                <input type="number" name="duracion_minutos" id="duracion_minutos" class="form-control" min="1" placeholder="Opcional">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Nota Máxima</label>
                                <input type="number" name="nota_maxima" id="nota_maxima" class="form-control" value="10" min="0" max="100" step="0.1">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Estado</label>
                                <select name="estado" id="estado" class="form-select">
                                    <option value="programado">Programado</option>
                                    <option value="publicado" selected>Publicado</option>
                                    <option value="activo">Activo</option>
                                    <option value="cerrado">Cerrado</option>
                                </select>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                        <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Guardar</button>
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
        function prepararModalCrear() {
            document.getElementById('accion').value = 'crear';
            document.getElementById('id_actividad').value = '';
            document.getElementById('modalTitle').innerHTML = '<i class="fas fa-plus"></i> Nueva Actividad';
            document.getElementById('formActividad').reset();
            document.getElementById('fecha_programada').value = new Date().toISOString().slice(0,16);
            mostrarCamposTipo();
        }
        
        function editarActividad(act) {
            document.getElementById('accion').value = 'actualizar';
            document.getElementById('id_actividad').value = act.id;
            document.getElementById('modalTitle').innerHTML = '<i class="fas fa-edit"></i> Editar Actividad';
            document.getElementById('titulo').value = act.titulo;
            document.getElementById('tipo').value = act.tipo;
            document.getElementById('descripcion').value = act.descripcion || '';
            document.getElementById('fecha_programada').value = act.fecha_programada ? act.fecha_programada.slice(0,16) : '';
            document.getElementById('fecha_limite').value = act.fecha_limite ? act.fecha_limite.slice(0,16) : '';
            document.getElementById('duracion_minutos').value = act.duracion_minutos || '';
            document.getElementById('nota_maxima').value = act.nota_maxima || 10;
            document.getElementById('contenido').value = act.contenido || '';
            document.getElementById('url_recurso').value = act.url_recurso || '';
            document.getElementById('estado').value = act.estado || 'publicado';
            mostrarCamposTipo();
            new bootstrap.Modal(document.getElementById('modalActividad')).show();
        }
        
        function mostrarCamposTipo() {
            const tipo = document.getElementById('tipo').value;
            const campoUrl = document.getElementById('campo_url');
            const campoContenido = document.getElementById('campo_contenido');
            const labelUrl = document.getElementById('label_url');
            const helpUrl = document.getElementById('help_url');
            
            campoUrl.classList.add('d-none');
            campoContenido.classList.add('d-none');
            
            if (['youtube', 'video', 'enlace', 'podcast'].includes(tipo)) {
                campoUrl.classList.remove('d-none');
                if (tipo === 'youtube') {
                    labelUrl.textContent = 'Enlace de YouTube';
                    helpUrl.textContent = 'https://www.youtube.com/watch?v=...';
                } else if (tipo === 'video') {
                    labelUrl.textContent = 'URL del Video';
                    helpUrl.textContent = 'Enlace directo al archivo MP4';
                } else {
                    labelUrl.textContent = 'URL del Recurso';
                    helpUrl.textContent = 'Enlace al recurso externo';
                }
            }
            if (['articulo', 'referencia', 'revista'].includes(tipo)) {
                campoContenido.classList.remove('d-none');
            }
        }
        
        function eliminarActividad(id) {
            if (confirm('¿Está seguro de eliminar esta actividad? Se eliminarán también las entregas de los estudiantes.')) {
                const form = document.createElement('form');
                form.method = 'POST';
                form.innerHTML = `<input type="hidden" name="accion" value="eliminar"><input type="hidden" name="id_actividad" value="${id}">`;
                document.body.appendChild(form);
                form.submit();
            }
        }
        
        // Inicializar
        document.addEventListener('DOMContentLoaded', function() {
            if (window.innerWidth < 992) $('#sidebar').addClass('active');
            mostrarCamposTipo();
        });
    </script>
</body>
</html>