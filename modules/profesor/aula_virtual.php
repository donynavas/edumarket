<?php
session_start();
require_once __DIR__ . '/../../config/database.php';

// Verificar autenticación
if (!isset($_SESSION['user_id']) || $_SESSION['rol'] !== 'profesor') {
    header("Location: " . BASE_URL . "/login.php");
    exit;
}

$database = new Database();
$db = $database->getConnection();
$user_id = $_SESSION['user_id'];

// Obtener datos del profesor
$stmt = $db->prepare("SELECT p.id, CONCAT(per.primer_nombre, ' ', per.primer_apellido) as nombre, per.email
                      FROM tbl_profesor p
                      JOIN tbl_persona per ON p.id_persona = per.id
                      WHERE per.id_usuario = :uid");
$stmt->execute([':uid' => $user_id]);
$profesor = $stmt->fetch(PDO::FETCH_ASSOC) ?? ['id' => 0, 'nombre' => 'Profesor', 'email' => ''];
$id_profesor = $profesor['id'];

$mensaje = '';
$tipo_mensaje = '';

// ===== PROCESAR ACCIONES POST =====
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $accion = $_POST['accion'] ?? '';
    
    try {
        $db->beginTransaction();
        
        $acciones = [
            'publicar_recurso' => fn() => publicarRecurso($db, $id_profesor),
            'crear_tarea' => fn() => crearTarea($db, $id_profesor),
            'crear_examen' => fn() => crearExamen($db, $id_profesor),
            'eliminar_recurso' => fn() => eliminarRecurso($db, $id_profesor),
        ];
        
        if (isset($acciones[$accion])) {
            $acciones[$accion]();
            $db->commit();
        }
        
    } catch (Exception $e) {
        $db->rollBack();
        $mensaje = 'Error: ' . $e->getMessage();
        $tipo_mensaje = 'danger';
        error_log("Aula Virtual Error: " . $e->getMessage());
    }
}

// ===== FUNCIONES DE PROCESAMIENTO =====
function publicarRecurso($db, $id_profesor) {
    global $mensaje, $tipo_mensaje;
    
    $id_asignacion = filter_input(INPUT_POST, 'id_asignacion', FILTER_VALIDATE_INT);
    $titulo = trim($_POST['titulo'] ?? '');
    $tipo = $_POST['tipo_recurso'] ?? '';
    $descripcion = $_POST['descripcion'] ?? '';
    $url = filter_var($_POST['url_recurso'] ?? '', FILTER_VALIDATE_URL) ?: null;
    $contenido = $_POST['contenido'] ?? '';
    
    // Verificar propiedad de la asignación
    $stmt = $db->prepare("SELECT 1 FROM tbl_asignacion_docente WHERE id = ? AND id_profesor = ?");
    $stmt->execute([$id_asignacion, $id_profesor]);
    if (!$stmt->fetch()) throw new Exception("Asignación no válida");
    
    $stmt = $db->prepare("INSERT INTO tbl_actividad 
        (id_asignacion_docente, titulo, descripcion, tipo, contenido, url_recurso, fecha_programada, estado) 
        VALUES (:asig, :titulo, :desc, :tipo, :cont, :url, NOW(), 'publicado')");
    $stmt->execute([
        ':asig' => $id_asignacion, ':titulo' => $titulo, ':desc' => $descripcion,
        ':tipo' => $tipo, ':cont' => $contenido, ':url' => $url
    ]);
    
    $mensaje = 'Recurso publicado';
    $tipo_mensaje = 'success';
}

function crearTarea($db, $id_profesor) {
    global $mensaje, $tipo_mensaje;
    
    $id_asignacion = filter_input(INPUT_POST, 'id_asignacion', FILTER_VALIDATE_INT);
    $titulo = trim($_POST['titulo'] ?? '');
    $descripcion = $_POST['descripcion'] ?? '';
    $fecha_limite = $_POST['fecha_entrega'] ?? '';
    $nota_maxima = filter_input(INPUT_POST, 'nota_maxima', FILTER_VALIDATE_FLOAT) ?: 10;
    
    $stmt = $db->prepare("SELECT 1 FROM tbl_asignacion_docente WHERE id = ? AND id_profesor = ?");
    $stmt->execute([$id_asignacion, $id_profesor]);
    if (!$stmt->fetch()) throw new Exception("Asignación no válida");
    
    $stmt = $db->prepare("INSERT INTO tbl_actividad 
        (id_asignacion_docente, titulo, descripcion, tipo, fecha_limite, nota_maxima, estado) 
        VALUES (:asig, :titulo, :desc, 'tarea', :limite, :nota, 'activo')");
    $stmt->execute([
        ':asig' => $id_asignacion, ':titulo' => $titulo, ':desc' => $descripcion,
        ':limite' => $fecha_limite, ':nota' => $nota_maxima
    ]);
    
    $mensaje = 'Tarea creada';
    $tipo_mensaje = 'success';
}

function crearExamen($db, $id_profesor) {
    global $mensaje, $tipo_mensaje;
    
    $id_asignacion = filter_input(INPUT_POST, 'id_asignacion', FILTER_VALIDATE_INT);
    $titulo = trim($_POST['titulo'] ?? '');
    $descripcion = $_POST['descripcion'] ?? '';
    $fecha_prog = $_POST['fecha_programada'] ?? '';
    $duracion = filter_input(INPUT_POST, 'duracion_minutos', FILTER_VALIDATE_INT) ?: 60;
    $nota_maxima = filter_input(INPUT_POST, 'nota_maxima', FILTER_VALIDATE_FLOAT) ?: 10;
    
    $stmt = $db->prepare("SELECT 1 FROM tbl_asignacion_docente WHERE id = ? AND id_profesor = ?");
    $stmt->execute([$id_asignacion, $id_profesor]);
    if (!$stmt->fetch()) throw new Exception("Asignación no válida");
    
    $stmt = $db->prepare("INSERT INTO tbl_actividad 
        (id_asignacion_docente, titulo, descripcion, tipo, fecha_programada, duracion_minutos, nota_maxima, estado) 
        VALUES (:asig, :titulo, :desc, 'examen', :prog, :dur, :nota, 'programado')");
    $stmt->execute([
        ':asig' => $id_asignacion, ':titulo' => $titulo, ':desc' => $descripcion,
        ':prog' => $fecha_prog, ':dur' => $duracion, ':nota' => $nota_maxima
    ]);
    
    $mensaje = 'Examen programado';
    $tipo_mensaje = 'success';
}

function eliminarRecurso($db, $id_profesor) {
    global $mensaje, $tipo_mensaje;
    
    $id_actividad = filter_input(INPUT_POST, 'id_actividad', FILTER_VALIDATE_INT);
    
    $stmt = $db->prepare("SELECT a.id FROM tbl_actividad a
                          JOIN tbl_asignacion_docente ad ON a.id_asignacion_docente = ad.id
                          WHERE a.id = :id AND ad.id_profesor = :prof");
    $stmt->execute([':id' => $id_actividad, ':prof' => $id_profesor]);
    
    if ($stmt->fetch()) {
        $db->prepare("UPDATE tbl_actividad SET estado = 'eliminado' WHERE id = :id")
           ->execute([':id' => $id_actividad]);
        $mensaje = 'Recurso eliminado';
        $tipo_mensaje = 'warning';
    }
}

// ===== OBTENER DATOS =====
$id_asignacion = $_GET['asignacion'] ?? 0;
$filtro_tipo = $_GET['tipo'] ?? 'todos';

// Asignaciones del profesor
$stmt = $db->prepare("SELECT ad.id, asig.nombre as asignatura, asig.codigo, g.nombre as grado, s.nombre as seccion,
                             COUNT(DISTINCT m.id) as estudiantes, COUNT(DISTINCT a.id) as actividades
                      FROM tbl_asignacion_docente ad
                      JOIN tbl_asignatura asig ON ad.id_asignatura = asig.id
                      JOIN tbl_seccion s ON ad.id_seccion = s.id
                      JOIN tbl_grado g ON s.id_grado = g.id
                      LEFT JOIN tbl_matricula m ON s.id = m.id_seccion AND m.anno = ad.anno AND m.estado = 'activo'
                      LEFT JOIN tbl_actividad a ON ad.id = a.id_asignacion_docente AND a.estado IN ('publicado','activo','programado')
                      WHERE ad.id_profesor = :prof
                      GROUP BY ad.id ORDER BY g.nombre, s.nombre, asig.nombre");
$stmt->execute([':prof' => $id_profesor]);
$asignaciones = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Si no hay asignación seleccionada, tomar la primera
if (!$id_asignacion && $asignaciones) {
    $id_asignacion = $asignaciones[0]['id'];
}

// Actividades de la asignación seleccionada
$actividades = [];
$estudiantes = [];
$asignacion_actual = null;

// Actividades de la asignación seleccionada
if ($id_asignacion) {
    $asignacion_actual = current(array_filter($asignaciones, fn($a) => $a['id'] == $id_asignacion));
    
    // ✅ CONSULTA CORREGIDA: Usar ea.id_matricula
    $query = "SELECT a.*, 
              COUNT(DISTINCT ea.id_matricula) as entregas, 
              AVG(ea.nota_obtenida) as promedio
              FROM tbl_actividad a
              LEFT JOIN tbl_entrega_actividad ea ON a.id = ea.id_actividad
              WHERE a.id_asignacion_docente = :asig 
              AND a.estado IN ('publicado','activo','programado')";
    
    $params = [':asig' => $id_asignacion];
    
    if ($filtro_tipo !== 'todos') {
        $query .= " AND a.tipo = :tipo";
        $params[':tipo'] = $filtro_tipo;
    }
    $query .= " GROUP BY a.id ORDER BY a.fecha_programada DESC";
    
    $stmt = $db->prepare($query);
    foreach ($params as $k => $v) $stmt->bindValue($k, $v);
    $stmt->execute();
    $actividades = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Estudiantes (esta consulta ya es correcta)
    $stmt = $db->prepare("SELECT e.id, CONCAT(p.primer_nombre, ' ', p.primer_apellido) as nombre, p.email, e.nie
                          FROM tbl_matricula m
                          JOIN tbl_estudiante e ON m.id_estudiante = e.id
                          JOIN tbl_persona p ON e.id_persona = p.id
                          WHERE m.id_seccion = (SELECT id_seccion FROM tbl_asignacion_docente WHERE id = :asig)
                          AND m.anno = (SELECT anno FROM tbl_asignacion_docente WHERE id = :asig)
                          AND m.estado = 'activo'
                          ORDER BY p.primer_apellido");
    $stmt->execute([':asig' => $id_asignacion]);
    $estudiantes = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Configuración
$tipos_recurso = [
    'video' => ['label' => '🎬 Video', 'icon' => 'fa-video', 'color' => 'danger'],
    'youtube' => ['label' => '📺 YouTube', 'icon' => 'fa-youtube', 'color' => 'danger'],
    'articulo' => ['label' => '📄 Artículo', 'icon' => 'fa-file-alt', 'color' => 'info'],
    'referencia' => ['label' => '📚 Referencia', 'icon' => 'fa-book', 'color' => 'primary'],
    'podcast' => ['label' => '🎧 Podcast', 'icon' => 'fa-podcast', 'color' => 'orange'],
    'revista' => ['label' => '📰 Revista', 'icon' => 'fa-newspaper', 'color' => 'warning'],
    'enlace' => ['label' => '🔗 Enlace', 'icon' => 'fa-link', 'color' => 'secondary'],
    'tarea' => ['label' => '📝 Tarea', 'icon' => 'fa-clipboard-list', 'color' => 'warning'],
    'examen' => ['label' => '📋 Examen', 'icon' => 'fa-file-alt', 'color' => 'danger']
];

$estados_actividad = [
    'publicado' => ['label' => 'Publicado', 'class' => 'bg-success'],
    'activo' => ['label' => 'Activo', 'class' => 'bg-primary'],
    'programado' => ['label' => 'Programado', 'class' => 'bg-secondary'],
    'cerrado' => ['label' => 'Cerrado', 'class' => 'bg-dark'],
    'eliminado' => ['label' => 'Eliminado', 'class' => 'bg-light text-muted']
];

// Token único para sala Jitsi
$sala_token = hash('sha256', "aula-{$id_asignacion}-" . date('Y-m-d'));
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Aula Virtual - <?= htmlspecialchars($profesor['nombre']) ?></title>
    
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet">
    <script src="https://meet.jit.si/external_api.js"></script>
    
    <style>
        :root { --primary: #2c3e50; --secondary: #3498db; --success: #2ecc71; --warning: #f39c12; --danger: #e74c3c; --sidebar-width: 260px; }
        body { font-family: 'Segoe UI', sans-serif; background: #f5f7fa; overflow-x: hidden; }
        
        /* Layout */
        .sidebar { position: fixed; top: 0; left: 0; height: 100vh; width: var(--sidebar-width); background: var(--primary); color: white; padding-top: 20px; z-index: 1050; overflow-y: auto; }
        .sidebar .nav-link { color: rgba(255,255,255,0.85); padding: 12px 20px; margin: 2px 0; border-radius: 8px; }
        .sidebar .nav-link:hover, .sidebar .nav-link.active { color: white; background: rgba(255,255,255,0.15); }
        .sidebar .nav-link i { width: 24px; text-align: center; margin-right: 8px; }
        .main-content { margin-left: var(--sidebar-width); padding: 20px; min-height: 100vh; }
        
        /* Cards */
        .card-custom { background: white; border-radius: 12px; box-shadow: 0 2px 12px rgba(0,0,0,0.08); border: none; margin-bottom: 20px; }
        .resource-card { border-left: 4px solid var(--secondary); transition: all 0.2s; }
        .resource-card:hover { transform: translateY(-2px); box-shadow: 0 4px 20px rgba(0,0,0,0.12); }
        .resource-card.video { border-left-color: #e74c3c; }
        .resource-card.youtube { border-left-color: #ff0000; }
        .resource-card.tarea { border-left-color: #f39c12; }
        .resource-card.examen { border-left-color: #c0392b; }
        
        /* Header */
        .class-header { background: linear-gradient(135deg, var(--primary) 0%, var(--secondary) 100%); color: white; padding: 25px; border-radius: 12px; margin-bottom: 25px; }
        
        /* Quick Actions */
        .quick-action { padding: 15px; border-radius: 10px; background: white; text-align: center; cursor: pointer; transition: all 0.2s; border: 2px solid transparent; }
        .quick-action:hover { border-color: var(--secondary); transform: translateY(-3px); box-shadow: 0 4px 15px rgba(0,0,0,0.1); }
        .quick-action i { font-size: 1.5rem; margin-bottom: 8px; display: block; }
        
        /* Badges */
        .badge-recurso { padding: 4px 12px; border-radius: 15px; font-size: 0.75rem; font-weight: 600; }
        .student-avatar { width: 36px; height: 36px; border-radius: 50%; background: var(--secondary); color: white; display: flex; align-items: center; justify-content: center; font-weight: 600; font-size: 0.9rem; }
        
        /* Video Container */
        .embed-container { position: relative; padding-bottom: 56.25%; height: 0; overflow: hidden; border-radius: 8px; background: #000; }
        .embed-container iframe, .embed-container video { position: absolute; top: 0; left: 0; width: 100%; height: 100%; border: 0; }
        
        /* Aula Virtual Layout */
        .aula-grid { display: grid; grid-template-columns: 2fr 1fr; gap: 20px; }
        @media (max-width: 992px) { .aula-grid { grid-template-columns: 1fr; } }
        
        /* Video Conference */
        #videoContainer { background: #000; border-radius: 12px; overflow: hidden; position: relative; min-height: 400px; }
        #jitsiMeet { width: 100%; height: 400px; }
        .video-controls { position: absolute; bottom: 15px; left: 50%; transform: translateX(-50%); display: flex; gap: 10px; background: rgba(0,0,0,0.7); padding: 10px 20px; border-radius: 30px; z-index: 10; }
        .video-controls button { background: rgba(255,255,255,0.9); border: none; width: 40px; height: 40px; border-radius: 50%; cursor: pointer; transition: all 0.2s; color: #333; }
        .video-controls button:hover { background: white; transform: scale(1.1); }
        .video-controls button.active { background: #e74c3c; color: white; }
        
        /* Whiteboard */
        #whiteboardContainer { background: white; border-radius: 12px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); display: flex; flex-direction: column; }
        #whiteboard { flex: 1; cursor: crosshair; touch-action: none; background: #fff; }
        .wb-toolbar { padding: 10px; border-bottom: 1px solid #eee; display: flex; gap: 8px; flex-wrap: wrap; align-items: center; }
        .wb-tool { width: 36px; height: 36px; border: 2px solid #ddd; border-radius: 8px; background: white; cursor: pointer; display: flex; align-items: center; justify-content: center; transition: all 0.2s; }
        .wb-tool.active, .wb-tool:hover { border-color: var(--primary); background: #e8f4fd; }
        .wb-color { width: 24px; height: 24px; border-radius: 50%; border: 2px solid white; cursor: pointer; box-shadow: 0 2px 4px rgba(0,0,0,0.2); }
        .wb-color.active { border-color: var(--primary); transform: scale(1.1); }
        
        /* Chat */
        #chatContainer { background: white; border-radius: 12px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); display: flex; flex-direction: column; height: 100%; max-height: 500px; }
        #chatMessages { flex: 1; overflow-y: auto; padding: 15px; display: flex; flex-direction: column; gap: 12px; }
        .chat-message { max-width: 85%; padding: 10px 14px; border-radius: 18px; font-size: 0.9rem; animation: fadeIn 0.2s; }
        @keyframes fadeIn { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: translateY(0); } }
        .chat-message.own { align-self: flex-end; background: var(--primary); color: white; border-bottom-right-radius: 4px; }
        .chat-message.other { align-self: flex-start; background: #f1f3f5; border-bottom-left-radius: 4px; }
        .chat-message .sender { font-weight: 600; font-size: 0.8rem; margin-bottom: 4px; opacity: 0.9; }
        .chat-message .time { font-size: 0.7rem; opacity: 0.7; margin-top: 4px; }
        #chatInput { padding: 12px 15px; border-top: 1px solid #eee; display: flex; gap: 10px; }
        #chatInput input { flex: 1; border: 1px solid #ddd; border-radius: 25px; padding: 8px 15px; }
        #chatInput button { background: var(--primary); color: white; border: none; width: 40px; height: 40px; border-radius: 50%; cursor: pointer; }
        
        /* Participants */
        .participant-list { list-style: none; padding: 0; margin: 0; max-height: 200px; overflow-y: auto; }
        .participant-list li { padding: 10px; border-bottom: 1px solid #eee; display: flex; align-items: center; gap: 10px; }
        .participant-list .avatar { width: 32px; height: 32px; border-radius: 50%; background: var(--primary); color: white; display: flex; align-items: center; justify-content: center; font-size: 0.8rem; font-weight: bold; }
        
        /* Tabs */
        .aula-tabs { display: flex; gap: 5px; margin-bottom: 15px; }
        .aula-tab { flex: 1; padding: 10px; text-align: center; border: none; background: #f1f3f5; border-radius: 8px 8px 0 0; cursor: pointer; font-weight: 500; transition: all 0.2s; }
        .aula-tab.active { background: white; border-bottom: 3px solid var(--primary); }
        .tab-content { display: none; }
        .tab-content.active { display: block; }
        
        /* Select2 en modales */
        .select2-container { z-index: 10060 !important; }
        .select2-dropdown { z-index: 10060 !important; border-radius: 8px; box-shadow: 0 4px 20px rgba(0,0,0,0.15); }
        
        @media (max-width: 992px) { 
            .sidebar { transform: translateX(-100%); } 
            .sidebar.active { transform: translateX(0); } 
            .main-content { margin-left: 0; }
            #jitsiMeet { height: 300px; }
        }
    </style>
</head>
<body>
    <!-- Sidebar -->
    <div class="sidebar" id="sidebar">
        <div class="text-center mb-4 px-3">
            <div class="d-flex align-items-center justify-content-center gap-2 mb-2">
                <div class="bg-white text-primary rounded-circle d-flex align-items-center justify-content-center" style="width: 45px; height: 45px; font-weight: 700;">
                    <?= strtoupper(substr($profesor['nombre'], 0, 1)) ?>
                </div>
                <div class="text-start">
                    <div class="fw-bold"><?= htmlspecialchars($profesor['nombre']) ?></div>
                    <small class="text-white-50">Profesor</small>
                </div>
            </div>
        </div>
        <nav class="nav flex-column px-2">
            <a class="nav-link" href="../../index.php"><i class="fas fa-tachometer-alt"></i> Dashboard</a>
            <a class="nav-link active" href="#"><i class="fas fa-chalkboard"></i> Aula Virtual</a>
            <a class="nav-link" href="gestionar_estudiantes.php"><i class="fas fa-user-graduate"></i> Estudiantes</a>
            <a class="nav-link" href="calificaciones.php"><i class="fas fa-star"></i> Calificaciones</a>
            <a class="nav-link" href="../../logout.php"><i class="fas fa-sign-out-alt"></i> Salir</a>
        </nav>
        <div class="mt-4 px-3">
            <small class="text-white-50">Mis Clases</small>
            <div class="mt-2">
                <?php foreach ($asignaciones as $asig): ?>
                <a href="?asignacion=<?= $asig['id'] ?>" class="d-block text-white-50 text-decoration-none py-1 px-2 rounded small <?= $id_asignacion == $asig['id'] ? 'bg-white-10 text-white' : '' ?>">
                    <i class="fas fa-chevron-right me-1 small"></i>
                    <?= htmlspecialchars($asig['asignatura']) ?> - <?= htmlspecialchars($asig['grado']) ?> <?= htmlspecialchars($asig['seccion']) ?>
                </a>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <!-- Main Content -->
    <div class="main-content">
        <!-- Header -->
        <div class="class-header">
            <div class="d-flex justify-content-between align-items-start flex-wrap gap-3">
                <div>
                    <h3 class="mb-2"><i class="fas fa-chalkboard"></i> Aula Virtual</h3>
                    <?php if ($asignacion_actual): ?>
                    <p class="mb-1 opacity-75">
                        <i class="fas fa-book"></i> <?= htmlspecialchars($asignacion_actual['asignatura']) ?>
                        <span class="mx-2">•</span>
                        <i class="fas fa-layer-group"></i> <?= htmlspecialchars($asignacion_actual['grado']) ?> <?= htmlspecialchars($asignacion_actual['seccion']) ?>
                    </p>
                    <p class="mb-0 small opacity-75">
                        <i class="fas fa-users"></i> <?= $asignacion_actual['estudiantes'] ?? 0 ?> estudiantes • 
                        <i class="fas fa-tasks"></i> <?= count($actividades) ?> actividades
                    </p>
                    <?php endif; ?>
                </div>
                <div class="d-flex gap-2">
                    <button class="btn btn-light btn-sm" onclick="toggleVideo()">
                        <i class="fas fa-video"></i> <span class="d-none d-md-inline">Video</span>
                    </button>
                    <button class="btn btn-light btn-sm" onclick="toggleWhiteboard()">
                        <i class="fas fa-chalkboard"></i> <span class="d-none d-md-inline">Pizarra</span>
                    </button>
                    <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#modalActividad">
                        <i class="fas fa-plus"></i> <span class="d-none d-md-inline">Nueva Actividad</span>
                    </button>
                </div>
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

        <?php if (!$id_asignacion): ?>
        <div class="text-center py-5">
            <i class="fas fa-chalkboard-teacher fa-4x text-muted mb-3"></i>
            <h4>Selecciona una clase para comenzar</h4>
            <p class="text-muted">Elige una de tus asignaciones en el menú lateral</p>
        </div>
        <?php else: ?>
        
        <!-- Aula Grid: Video/Pizarra + Chat -->
        <div class="aula-grid">
            <!-- Left: Video + Whiteboard + Recursos -->
            <div>
                <!-- Video Conference (oculto por defecto) -->
                <div id="videoSection" class="d-none mb-4">
                    <div id="videoContainer">
                        <div id="jitsiMeet"></div>
                        <div class="video-controls">
                            <button id="btnMic" class="active" onclick="toggleMic()" title="Micrófono"><i class="fas fa-microphone"></i></button>
                            <button id="btnCam" class="active" onclick="toggleCam()" title="Cámara"><i class="fas fa-video"></i></button>
                            <button onclick="toggleScreen()" title="Compartir pantalla"><i class="fas fa-desktop"></i></button>
                            <button onclick="endClass()" class="text-danger" title="Finalizar"><i class="fas fa-phone-slash"></i></button>
                        </div>
                    </div>
                </div>
                
                <!-- Tabs -->
                <div class="aula-tabs">
                    <button class="aula-tab active" onclick="showTab('recursos')"><i class="fas fa-folder-open"></i> Recursos</button>
                    <button class="aula-tab" onclick="showTab('whiteboard')"><i class="fas fa-chalkboard"></i> Pizarra</button>
                    <button class="aula-tab" onclick="showTab('estudiantes')"><i class="fas fa-users"></i> Estudiantes</button>
                </div>
                
                <!-- Tab: Recursos -->
                <div id="tab-recursos" class="tab-content active">
                    <!-- Filtros -->
                    <div class="card-custom p-3 mb-4">
                        <div class="d-flex flex-wrap gap-2 align-items-center">
                            <span class="text-muted small">Filtrar:</span>
                            <?php foreach ($tipos_recurso as $key => $tipo): ?>
                            <a href="?asignacion=<?= $id_asignacion ?>&tipo=<?= $key ?>" 
                               class="badge <?= $filtro_tipo == $key ? 'bg-primary' : 'bg-light text-dark' ?> text-decoration-none">
                                <i class="fas <?= $tipo['icon'] ?>"></i> <?= $tipo['label'] ?>
                            </a>
                            <?php endforeach; ?>
                            <?php if ($filtro_tipo != 'todos'): ?>
                            <a href="?asignacion=<?= $id_asignacion ?>" class="badge bg-secondary text-decoration-none"><i class="fas fa-times"></i></a>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Lista de Recursos -->
                    <?php if (empty($actividades)): ?>
                    <div class="card-custom p-5 text-center">
                        <i class="fas fa-inbox fa-4x text-muted mb-3"></i>
                        <h5>Sin recursos aún</h5>
                        <p class="text-muted">Publica videos, artículos o crea tareas</p>
                        <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#modalActividad">
                            <i class="fas fa-plus"></i> Crear Primera Actividad
                        </button>
                    </div>
                    <?php else: ?>
                    <div class="row g-3">
                        <?php foreach ($actividades as $act): 
                            $tipo = $tipos_recurso[$act['tipo']] ?? ['label' => $act['tipo'], 'icon' => 'fa-file', 'color' => 'secondary'];
                            $estado = $estados_actividad[$act['estado']] ?? ['label' => $act['estado'], 'class' => 'bg-secondary'];
                        ?>
                        <div class="col-lg-6">
                            <div class="card-custom resource-card <?= htmlspecialchars($act['tipo']) ?> p-3">
                                <div class="d-flex justify-content-between align-items-start mb-2">
                                    <div class="d-flex align-items-center gap-2">
                                        <span class="badge-recurso bg-<?= $tipo['color'] ?> text-white">
                                            <i class="fas <?= $tipo['icon'] ?>"></i> <?= $tipo['label'] ?>
                                        </span>
                                        <span class="badge <?= $estado['class'] ?>"><?= $estado['label'] ?></span>
                                    </div>
                                    <div class="dropdown">
                                        <button class="btn btn-sm btn-light" data-bs-toggle="dropdown"><i class="fas fa-ellipsis-v"></i></button>
                                        <ul class="dropdown-menu dropdown-menu-end">
                                            <li><a class="dropdown-item" href="#"><i class="fas fa-eye"></i> Ver</a></li>
                                            <li><a class="dropdown-item" href="#"><i class="fas fa-edit"></i> Editar</a></li>
                                            <li><hr class="dropdown-divider"></li>
                                            <li>
                                                <form method="POST" class="d-inline" onsubmit="return confirm('¿Eliminar?');">
                                                    <input type="hidden" name="accion" value="eliminar_recurso">
                                                    <input type="hidden" name="id_actividad" value="<?= $act['id'] ?>">
                                                    <button type="submit" class="dropdown-item text-danger"><i class="fas fa-trash"></i> Eliminar</button>
                                                </form>
                                            </li>
                                        </ul>
                                    </div>
                                </div>
                                <h6 class="mb-2"><?= htmlspecialchars($act['titulo']) ?></h6>
                                <?php if ($act['descripcion']): ?>
                                <p class="text-muted small mb-2"><?= nl2br(htmlspecialchars(substr($act['descripcion'], 0, 120))) ?><?= strlen($act['descripcion']) > 120 ? '...' : '' ?></p>
                                <?php endif; ?>
                                
                                <!-- Preview según tipo -->
                                <?php if ($act['tipo'] === 'youtube' && $act['url_recurso']): 
                                    preg_match('/[\\?\\&]v=([^\\?\\&]+)/', $act['url_recurso'], $m);
                                    if (!empty($m[1])): ?>
                                    <div class="embed-container mb-2">
                                        <iframe src="https://www.youtube.com/embed/<?= htmlspecialchars($m[1]) ?>" allowfullscreen></iframe>
                                    </div>
                                <?php endif; endif; ?>
                                
                                <?php if ($act['tipo'] === 'video' && $act['url_recurso']): ?>
                                <div class="embed-container mb-2">
                                    <video controls class="w-100 rounded">
                                        <source src="<?= htmlspecialchars($act['url_recurso']) ?>" type="video/mp4">
                                    </video>
                                </div>
                                <?php endif; ?>
                                
                                <div class="d-flex justify-content-between align-items-center mt-2 pt-2 border-top">
                                    <small class="text-muted">
                                        <i class="fas fa-calendar"></i> <?= date('d/m/Y', strtotime($act['fecha_programada'])) ?>
                                        <?php if ($act['fecha_limite']): ?> • <i class="fas fa-clock"></i> <?= date('d/m', strtotime($act['fecha_limite'])) ?> <?php endif; ?>
                                        <?php if ($act['duracion_minutos']): ?> • <i class="fas fa-stopwatch"></i> <?= $act['duracion_minutos'] ?>min <?php endif; ?>
                                    </small>
                                    <?php if ($act['entregas'] > 0): ?>
                                    <small class="text-primary"><i class="fas fa-inbox"></i> <?= $act['entregas'] ?> entregas</small>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                </div>
                
                <!-- Tab: Pizarra -->
                <div id="tab-whiteboard" class="tab-content">
                    <div id="whiteboardContainer" style="height: 400px;">
                        <div class="wb-toolbar">
                            <button class="wb-tool active" data-tool="pen" title="Lápiz"><i class="fas fa-pen"></i></button>
                            <button class="wb-tool" data-tool="eraser" title="Borrador"><i class="fas fa-eraser"></i></button>
                            <button class="wb-tool" data-tool="line" title="Línea"><i class="fas fa-minus"></i></button>
                            <button class="wb-tool" data-tool="rect" title="Rectángulo"><i class="far fa-square"></i></button>
                            <button class="wb-tool" onclick="clearWhiteboard()" title="Limpiar"><i class="fas fa-trash"></i></button>
                            <div class="vr mx-2"></div>
                            <div class="wb-color active" style="background:#000" data-color="#000"></div>
                            <div class="wb-color" style="background:#e74c3c" data-color="#e74c3c"></div>
                            <div class="wb-color" style="background:#3498db" data-color="#3498db"></div>
                            <div class="wb-color" style="background:#2ecc71" data-color="#2ecc71"></div>
                            <div class="vr mx-2"></div>
                            <input type="range" id="wbSize" min="1" max="20" value="3" style="width:80px">
                            <small>Tamaño</small>
                        </div>
                        <canvas id="whiteboard"></canvas>
                    </div>
                </div>
                
                <!-- Tab: Estudiantes -->
                <div id="tab-estudiantes" class="tab-content">
                    <div class="card-custom">
                        <div class="card-body p-0">
                            <table class="table table-hover mb-0">
                                <thead class="table-light">
                                    <tr><th>Estudiante</th><th>NIE</th><th>Contacto</th><th>Acciones</th></tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($estudiantes)): ?>
                                    <tr><td colspan="4" class="text-center py-4 text-muted">Sin estudiantes</td></tr>
                                    <?php else: ?>
                                    <?php foreach ($estudiantes as $est): ?>
                                    <tr>
                                        <td>
                                            <div class="d-flex align-items-center gap-2">
                                                <div class="student-avatar"><?= strtoupper(substr($est['nombre'], 0, 1)) ?></div>
                                                <strong><?= htmlspecialchars($est['nombre']) ?></strong>
                                            </div>
                                        </td>
                                        <td><small class="text-muted"><?= htmlspecialchars($est['nie']) ?></small></td>
                                        <td><small><i class="fas fa-envelope"></i> <?= htmlspecialchars(substr($est['email'] ?? '', 0, 20)) ?><?= strlen($est['email'] ?? '') > 20 ? '...' : '' ?></small></td>
                                        <td><button class="btn btn-sm btn-outline-primary" onclick="verEstudiante(<?= $est['id'] ?>)"><i class="fas fa-eye"></i></button></td>
                                    </tr>
                                    <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Right: Chat + Participantes -->
            <div class="d-flex flex-column gap-3">
                <!-- Participantes -->
                <div class="card-custom p-3" style="flex: 0 0 auto;">
                    <h6 class="mb-3"><i class="fas fa-users"></i> En línea (<?= count($estudiantes) + 1 ?>)</h6>
                    <ul class="participant-list">
                        <li>
                            <div class="avatar">P</div>
                            <div><strong><?= htmlspecialchars($profesor['nombre']) ?></strong><br><small class="text-muted">Profesor</small></div>
                            <span class="badge bg-success ms-auto">En línea</span>
                        </li>
                        <?php foreach ($estudiantes as $est): ?>
                        <li>
                            <div class="avatar"><?= strtoupper(substr($est['nombre'], 0, 1)) ?></div>
                            <div><strong><?= htmlspecialchars($est['nombre']) ?></strong><br><small class="text-muted">Estudiante</small></div>
                            <span class="badge bg-secondary ms-auto">Esperando</span>
                        </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
                
                <!-- Chat -->
                <div id="chatContainer">
                    <div class="p-3 border-bottom"><h6 class="mb-0"><i class="fas fa-comments"></i> Chat</h6></div>
                    <div id="chatMessages">
                        <div class="chat-message other">
                            <div class="sender">Sistema</div>
                            <div>¡Bienvenidos a clase! 🎓</div>
                            <div class="time"><?= date('H:i') ?></div>
                        </div>
                    </div>
                    <div id="chatInput">
                        <input type="text" id="chatMessage" placeholder="Escribe..." onkeypress="if(event.key==='Enter')sendChat()">
                        <button onclick="sendChat()"><i class="fas fa-paper-plane"></i></button>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <!-- Modal Actividad (Recursos/Tareas/Exámenes) -->
    <div class="modal fade" id="modalActividad" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title" id="modalTitle"><i class="fas fa-plus"></i> Nueva Actividad</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" id="formActividad">
                    <div class="modal-body">
                        <input type="hidden" name="accion" id="accion_actividad" value="publicar_recurso">
                        <input type="hidden" name="id_asignacion" value="<?= $id_asignacion ?>">
                        
                        <!-- Tipo de Actividad -->
                        <div class="mb-4">
                            <label class="form-label">Tipo de Actividad *</label>
                            <div class="btn-group w-100" role="group">
                                <input type="radio" class="btn-check" name="tipo_actividad" id="tipo_recurso" value="recurso" checked onchange="cambiarTipoActividad('recurso')">
                                <label class="btn btn-outline-primary" for="tipo_recurso"><i class="fas fa-folder-open"></i> Recurso</label>
                                <input type="radio" class="btn-check" name="tipo_actividad" id="tipo_tarea" value="tarea" onchange="cambiarTipoActividad('tarea')">
                                <label class="btn btn-outline-warning" for="tipo_tarea"><i class="fas fa-clipboard-list"></i> Tarea</label>
                                <input type="radio" class="btn-check" name="tipo_actividad" id="tipo_examen" value="examen" onchange="cambiarTipoActividad('examen')">
                                <label class="btn btn-outline-danger" for="tipo_examen"><i class="fas fa-file-alt"></i> Examen</label>
                            </div>
                        </div>
                        
                        <!-- Campos Comunes -->
                        <div class="mb-3">
                            <label class="form-label">Título *</label>
                            <input type="text" name="titulo" id="titulo_actividad" class="form-control" required placeholder="Título de la actividad">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Descripción / Instrucciones *</label>
                            <textarea name="descripcion" id="descripcion_actividad" class="form-control" rows="3" required placeholder="Describe la actividad..."></textarea>
                        </div>
                        
                        <!-- Campos para Recurso -->
                        <div id="campos_recurso">
                            <div class="mb-3">
                                <label class="form-label">Tipo de Recurso *</label>
                                <select name="tipo_recurso" class="form-select select-recurso">
                                    <option value="">Seleccionar</option>
                                    <?php foreach ($tipos_recurso as $k => $t): if (!in_array($k, ['tarea','examen'])): ?>
                                    <option value="<?= $k ?>"><i class="fas <?= $t['icon'] ?>"></i> <?= $t['label'] ?></option>
                                    <?php endif; endforeach; ?>
                                </select>
                            </div>
                            <div class="mb-3" id="campo_url">
                                <label class="form-label">URL / Enlace *</label>
                                <input type="url" name="url_recurso" class="form-control" placeholder="https://...">
                                <small class="text-muted">Enlace al video, artículo o recurso externo</small>
                            </div>
                            <div class="mb-3" id="campo_contenido">
                                <label class="form-label">Contenido</label>
                                <textarea name="contenido" class="form-control" rows="3" placeholder="Texto del artículo, referencia, etc."></textarea>
                            </div>
                        </div>
                        
                        <!-- Campos para Tarea -->
                        <div id="campos_tarea" class="d-none">
                            <div class="row g-3">
                                <div class="col-md-6">
                                    <label class="form-label">Fecha Límite *</label>
                                    <input type="datetime-local" name="fecha_entrega" class="form-control">
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">Nota Máxima *</label>
                                    <input type="number" name="nota_maxima" class="form-control" value="10" min="0" max="100" step="0.1">
                                </div>
                            </div>
                            <div class="mb-3 mt-3">
                                <label class="form-label">Archivo/Instrucciones Adjuntas</label>
                                <input type="text" name="archivo_adjunto" class="form-control" placeholder="URL del archivo o instrucciones adicionales">
                            </div>
                        </div>
                        
                        <!-- Campos para Examen -->
                        <div id="campos_examen" class="d-none">
                            <div class="row g-3">
                                <div class="col-md-6">
                                    <label class="form-label">Fecha Programada *</label>
                                    <input type="datetime-local" name="fecha_programada" class="form-control">
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">Duración (minutos)</label>
                                    <input type="number" name="duracion_minutos" class="form-control" value="60" min="1">
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">Nota Máxima *</label>
                                    <input type="number" name="nota_maxima" class="form-control" value="10" min="0" max="100" step="0.1">
                                </div>
                            </div>
                        </div>
                        
                        <!-- Preview -->
                        <div class="alert alert-info small mb-0">
                            <i class="fas fa-eye"></i> <strong>Vista previa:</strong> 
                            <span id="preview_text">Los estudiantes verán esta actividad en su panel</span>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                        <button type="submit" class="btn btn-primary"><i class="fas fa-paper-plane"></i> Publicar</button>
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
        // ===== CONFIGURACIÓN =====
        const CONFIG = {
            jitsiDomain: 'meet.jit.si',
            roomName: 'EducacionPlus-<?= $sala_token ?>',
            profesor: '<?= addslashes($profesor['nombre']) ?>',
            idAsignacion: <?= $id_asignacion ?: 0 ?>,
            apiUrl: 'api/'
        };
        
        let jitsiApi = null, isMicOn = true, isCamOn = true;
        let whiteboard, chatPoll;
        
        // ===== INICIALIZACIÓN =====
        $(document).ready(function() {
            // Select2 con soporte para modales
            function initSelect2($el, parent) {
                $el.select2({ theme: 'bootstrap-5', width: '100%', dropdownParent: parent || $('body') });
            }
            initSelect2($('select.form-select:not([multiple])'));
            
            $('#modalActividad').on('shown.bs.modal', function() {
                $(this).find('select').each(function() {
                    if ($(this).data('select2')) $(this).select2('destroy');
                    initSelect2($(this), $(this).closest('.modal'));
                });
            }).on('hidden.bs.modal', function() {
                $(this).find('select').select2('destroy');
                $(this).find('form')[0].reset();
                $('#campos_tarea, #campos_examen').addClass('d-none');
                $('#campos_recurso').removeClass('d-none');
            });
            
            // Pizarra
            initWhiteboard();
            
            // Chat polling (simulado - usar WebSocket en producción)
            startChatPolling();
            
            // Sidebar responsive
            if (window.innerWidth < 992) $('#sidebar').addClass('active');
        });
        
        // ===== JITSI MEET =====
        function initJitsi() {
            if (!CONFIG.idAsignacion) return;
            jitsiApi = new JitsiMeetExternalAPI(CONFIG.jitsiDomain, {
                roomName: CONFIG.roomName,
                width: '100%', height: 400,
                parentNode: document.querySelector('#jitsiMeet'),
                userInfo: { displayName: CONFIG.profesor + ' 👨‍🏫' },
                configOverwrite: { startWithAudioMuted: false, startWithVideoMuted: false },
                interfaceConfigOverwrite: { 
                    SHOW_JITSI_WATERMARK: false, 
                    TOOLBAR_BUTTONS: ['microphone','camera','desktop','chat','raisehand','fullscreen']
                }
            });
        }
        
        function toggleVideo() {
            const section = document.getElementById('videoSection');
            section.classList.toggle('d-none');
            if (!section.classList.contains('d-none') && !jitsiApi) initJitsi();
        }
        
        function toggleMic() {
            isMicOn = !isMicOn;
            jitsiApi?.executeCommand('toggleAudio');
            document.getElementById('btnMic').classList.toggle('active', isMicOn);
            document.getElementById('btnMic').innerHTML = isMicOn ? '<i class="fas fa-microphone"></i>' : '<i class="fas fa-microphone-slash"></i>';
        }
        
        function toggleCam() {
            isCamOn = !isCamOn;
            jitsiApi?.executeCommand('toggleVideo');
            document.getElementById('btnCam').classList.toggle('active', isCamOn);
            document.getElementById('btnCam').innerHTML = isCamOn ? '<i class="fas fa-video"></i>' : '<i class="fas fa-video-slash"></i>';
        }
        
        function toggleScreen() { jitsiApi?.executeCommand('toggleScreenSharing'); }
        function endClass() { if (confirm('¿Finalizar clase?')) { jitsiApi?.executeCommand('hangup'); } }
        
        // ===== PIZARRA =====
        function initWhiteboard() {
            const canvas = document.getElementById('whiteboard');
            if (!canvas) return;
            const ctx = canvas.getContext('2d');
            let isDrawing = false, lastX = 0, lastY = 0;
            let tool = 'pen', color = '#000', size = 3;
            
            function resize() {
                const container = canvas.parentElement;
                canvas.width = container.clientWidth - 20;
                canvas.height = container.clientHeight - 60;
                ctx.lineCap = ctx.lineJoin = 'round';
                updateCtx();
            }
            
            function updateCtx() {
                ctx.strokeStyle = tool === 'eraser' ? '#fff' : color;
                ctx.lineWidth = tool === 'eraser' ? size * 3 : size;
            }
            
            function getCoords(e) {
                const rect = canvas.getBoundingClientRect();
                const x = e.touches ? e.touches[0].clientX : e.clientX;
                const y = e.touches ? e.touches[0].clientY : e.clientY;
                return [x - rect.left, y - rect.top];
            }
            
            function start(e) { isDrawing = true; [lastX, lastY] = getCoords(e); }
            function draw(e) {
                if (!isDrawing) return;
                e.preventDefault();
                const [x, y] = getCoords(e);
                if (tool === 'pen' || tool === 'eraser') {
                    ctx.beginPath(); ctx.moveTo(lastX, lastY); ctx.lineTo(x, y); ctx.stroke();
                }
                [lastX, lastY] = [x, y];
            }
            function stop() { isDrawing = false; }
            
            // Events
            canvas.addEventListener('mousedown', start);
            canvas.addEventListener('mousemove', draw);
            canvas.addEventListener('mouseup', stop);
            canvas.addEventListener('mouseout', stop);
            canvas.addEventListener('touchstart', e => { e.preventDefault(); start(e.touches[0]); }, {passive:false});
            canvas.addEventListener('touchmove', e => { e.preventDefault(); draw(e.touches[0]); }, {passive:false});
            canvas.addEventListener('touchend', stop);
            
            // Toolbar
            document.querySelectorAll('.wb-tool[data-tool]').forEach(btn => {
                btn.onclick = function() {
                    document.querySelectorAll('.wb-tool').forEach(b => b.classList.remove('active'));
                    this.classList.add('active');
                    tool = this.dataset.tool;
                    updateCtx();
                };
            });
            document.querySelectorAll('.wb-color').forEach(c => {
                c.onclick = function() {
                    document.querySelectorAll('.wb-color').forEach(x => x.classList.remove('active'));
                    this.classList.add('active');
                    color = this.dataset.color;
                    updateCtx();
                };
            });
            document.getElementById('wbSize').oninput = function() { size = this.value; updateCtx(); };
            
            window.clearWhiteboard = function() {
                if (confirm('¿Limpiar pizarra?')) ctx.clearRect(0, 0, canvas.width, canvas.height);
            };
            
            resize();
            window.addEventListener('resize', resize);
            window.whiteboard = { ctx, canvas };
        }
        
        function toggleWhiteboard() { showTab('whiteboard'); }
        
        // ===== CHAT =====
        function sendChat() {
            const input = document.getElementById('chatMessage');
            const msg = input.value.trim();
            if (!msg) return;
            
            addChat(CONFIG.profesor, msg, true);
            input.value = '';
            scrollToBottom();
            
            // Enviar al servidor (simulado)
            fetch(CONFIG.apiUrl + 'chat.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: `id_asignacion=${CONFIG.idAsignacion}&mensaje=${encodeURIComponent(msg)}`
            }).catch(() => {});
        }
        
        function addChat(sender, text, isOwn) {
            const container = document.getElementById('chatMessages');
            const div = document.createElement('div');
            div.className = `chat-message ${isOwn ? 'own' : 'other'}`;
            div.innerHTML = `<div class="sender">${sender}</div><div>${text}</div><div class="time">${new Date().toLocaleTimeString('es-ES',{hour:'2-digit',minute:'2-digit'})}</div>`;
            container.appendChild(div);
        }
        
        function scrollToBottom() {
            const chat = document.getElementById('chatMessages');
            chat.scrollTop = chat.scrollHeight;
        }
        
        function startChatPolling() {
            chatPoll = setInterval(() => {
                fetch(CONFIG.apiUrl + `chat.php?id_asignacion=${CONFIG.idAsignacion}`)
                    .then(r => r.json())
                    .then(msgs => {
                        msgs.forEach(m => { if (m.sender !== CONFIG.profesor) addChat(m.sender, m.text, false); });
                        scrollToBottom();
                    }).catch(() => {});
            }, 3000);
        }
        
        // ===== TABS =====
        function showTab(name) {
            document.querySelectorAll('.aula-tab').forEach(t => t.classList.remove('active'));
            document.querySelectorAll('.tab-content').forEach(t => t.classList.remove('active'));
            event.currentTarget?.classList.add('active');
            document.getElementById(`tab-${name}`).classList.add('active');
            if (name === 'whiteboard' && window.whiteboard) {
                // Trigger resize
                const evt = new Event('resize');
                window.dispatchEvent(evt);
            }
        }
        
        // ===== MODAL ACTIVIDAD =====
        function cambiarTipoActividad(tipo) {
            document.getElementById('accion_actividad').value = tipo === 'recurso' ? 'publicar_recurso' : (tipo === 'tarea' ? 'crear_tarea' : 'crear_examen');
            document.getElementById('campos_recurso').classList.toggle('d-none', tipo !== 'recurso');
            document.getElementById('campos_tarea').classList.toggle('d-none', tipo !== 'tarea');
            document.getElementById('campos_examen').classList.toggle('d-none', tipo !== 'examen');
            
            const titles = { recurso: '📁 Publicar Recurso', tarea: '📝 Nueva Tarea', examen: '📋 Nuevo Examen' };
            document.getElementById('modalTitle').innerHTML = `<i class="fas fa-plus"></i> ${titles[tipo]}`;
            
            // Actualizar preview
            const preview = {
                recurso: 'Los estudiantes verán este recurso en su panel',
                tarea: 'Los estudiantes podrán entregar esta tarea antes de la fecha límite',
                examen: 'El examen estará disponible en la fecha programada'
            };
            document.getElementById('preview_text').textContent = preview[tipo];
        }
        
        // Validación del formulario
        document.getElementById('formActividad').onsubmit = function(e) {
            const tipo = document.querySelector('input[name="tipo_actividad"]:checked').value;
            const titulo = document.getElementById('titulo_actividad').value.trim();
            const desc = document.getElementById('descripcion_actividad').value.trim();
            
            if (!titulo || !desc) {
                e.preventDefault();
                alert('Título y descripción son obligatorios');
                return false;
            }
            
            if (tipo === 'recurso') {
                const tipoRecurso = document.querySelector('select[name="tipo_recurso"]').value;
                const urlReq = ['youtube','video','enlace','podcast'];
                const url = document.querySelector('input[name="url_recurso"]').value;
                if (urlReq.includes(tipoRecurso) && !url) {
                    e.preventDefault();
                    alert('La URL es requerida para este tipo de recurso');
                    return false;
                }
            }
            return true;
        };
        
        // Quick actions
        document.querySelectorAll('.quick-action').forEach(btn => {
            btn.onclick = function() {
                const tipo = this.dataset.tipo;
                if (tipo) {
                    if (['video','youtube','articulo','referencia','podcast','revista','enlace'].includes(tipo)) {
                        document.querySelector('input[value="recurso"]').checked = true;
                        document.querySelector('select[name="tipo_recurso"]').value = tipo;
                        cambiarTipoActividad('recurso');
                    } else if (tipo === 'tarea') {
                        document.querySelector('input[value="tarea"]').checked = true;
                        cambiarTipoActividad('tarea');
                    }
                    $('#modalActividad').modal('show');
                }
            };
        });
        
        // Funciones globales
        window.verEstudiante = function(id) { console.log('Ver estudiante', id); };
    </script>
</body>
</html>