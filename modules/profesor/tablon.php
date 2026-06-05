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
$query = "SELECT p.id as id_profesor FROM tbl_profesor p
          JOIN tbl_persona per ON p.id_persona = per.id
          WHERE per.id_usuario = :user_id";
$stmt = $db->prepare($query);
$stmt->bindValue(':user_id', $user_id, PDO::PARAM_INT);
$stmt->execute();
$profesor = $stmt->fetch(PDO::FETCH_ASSOC);
$id_profesor = $profesor['id_profesor'] ?? 0;

// Parámetros de filtro
$id_asignacion = $_GET['asignacion'] ?? 0;
$filtro_tipo = $_GET['tipo'] ?? 'todas';
$filtro_estado = $_GET['estado'] ?? 'todas';
$busqueda = $_GET['busqueda'] ?? '';

// Obtener asignaciones del profesor
$query = "SELECT ad.id, ad.anno,
                 asig.nombre as asignatura, 
                 g.nombre as grado, 
                 s.nombre as seccion
          FROM tbl_asignacion_docente ad
          JOIN tbl_asignatura asig ON ad.id_asignatura = asig.id
          JOIN tbl_seccion s ON ad.id_seccion = s.id
          JOIN tbl_grado g ON s.id_grado = g.id  
          WHERE ad.id_profesor = :id_profesor
          ORDER BY asig.nombre, g.nombre, s.nombre";

$stmt = $db->prepare($query);
$stmt->bindValue(':id_profesor', $id_profesor, PDO::PARAM_INT);
$stmt->execute();
$asignaciones = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Obtener actividades del tablón
if ($id_asignacion) {
   $query = "SELECT a.id, a.titulo, a.descripcion, a.tipo, a.contenido, a.url_recurso,
          a.fecha_programada, a.fecha_limite, a.duracion_minutos, a.nota_maxima, a.estado,
          ad.id as id_asignacion, asig.nombre as asignatura, g.nombre as grado, s.nombre as seccion,
          COUNT(DISTINCT ea.id) as total_entregas,
          SUM(CASE WHEN ea.estado_entrega = 'calificado' THEN 1 ELSE 0 END) as calificadas,
          AVG(ea.nota_obtenida) as promedio_notas
          FROM tbl_actividad a
          JOIN tbl_asignacion_docente ad ON a.id_asignacion_docente = ad.id
          JOIN tbl_asignatura asig ON ad.id_asignatura = asig.id
          JOIN tbl_seccion s ON ad.id_seccion = s.id
          JOIN tbl_grado g ON s.id_grado = g.id
          LEFT JOIN tbl_entrega_actividad ea ON a.id = ea.id_actividad
          WHERE ad.id_profesor = :id_profesor AND ad.id = :id_asignacion";
    
    $params = [':id_profesor' => $id_profesor, ':id_asignacion' => $id_asignacion];
    
    if ($filtro_tipo != 'todas') {
        $query .= " AND a.tipo = :tipo";
        $params[':tipo'] = $filtro_tipo;
    }
    
    if ($filtro_estado != 'todas') {
        $query .= " AND a.estado = :estado";
        $params[':estado'] = $filtro_estado;
    }
    
    if (!empty($busqueda)) {
        $query .= " AND (a.titulo LIKE :busqueda OR a.descripcion LIKE :busqueda)";
        $params[':busqueda'] = "%$busqueda%";
    }
    
    $query .= " GROUP BY a.id ORDER BY a.fecha_programada DESC";
    
    $stmt = $db->prepare($query);
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value);
    }
    $stmt->execute();
    $actividades = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Obtener estudiantes de esta asignación
    $query = "SELECT e.id, p.primer_nombre, p.primer_apellido, p.email, e.nie,
              m.id as id_matricula
              FROM tbl_matricula m
              JOIN tbl_estudiante e ON m.id_estudiante = e.id
              JOIN tbl_persona p ON e.id_persona = p.id
              WHERE m.id_seccion = (SELECT id_seccion FROM tbl_asignacion_docente WHERE id = :id_asig)
              AND m.anno = (SELECT anno FROM tbl_asignacion_docente WHERE id = :id_asig2)
              AND m.estado = 'activo'
              ORDER BY p.primer_apellido, p.primer_nombre";
    $stmt = $db->prepare($query);
    $stmt->bindValue(':id_asig', $id_asignacion, PDO::PARAM_INT);
    $stmt->bindValue(':id_asig2', $id_asignacion, PDO::PARAM_INT);
    $stmt->execute();
    $estudiantes = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Tipos de actividad
$tipos_actividad = [
    'tarea' => ['label' => 'Tarea', 'icon' => 'fa-clipboard-list', 'color' => 'warning', 'bg' => 'bg-warning'],
    'examen' => ['label' => 'Examen', 'icon' => 'fa-file-alt', 'color' => 'danger', 'bg' => 'bg-danger'],
    'video' => ['label' => 'Video', 'icon' => 'fa-video', 'color' => 'info', 'bg' => 'bg-info'],
    'youtube' => ['label' => 'YouTube', 'icon' => 'fa-youtube', 'color' => 'danger', 'bg' => 'bg-danger'],
    'articulo' => ['label' => 'Artículo', 'icon' => 'fa-file-alt', 'color' => 'primary', 'bg' => 'bg-primary'],
    'referencia' => ['label' => 'Referencia', 'icon' => 'fa-book', 'color' => 'purple', 'bg' => 'bg-purple'],
    'podcast' => ['label' => 'Podcast', 'icon' => 'fa-podcast', 'color' => 'success', 'bg' => 'bg-success'],
    'revista' => ['label' => 'Revista', 'icon' => 'fa-newspaper', 'color' => 'teal', 'bg' => 'bg-teal'],
    'enlace' => ['label' => 'Enlace', 'icon' => 'fa-link', 'color' => 'secondary', 'bg' => 'bg-secondary']
];

$estados_actividad = [
    'programado' => ['label' => 'Programado', 'class' => 'bg-secondary'],
    'publicado' => ['label' => 'Publicado', 'class' => 'bg-success'],
    'activo' => ['label' => 'Activo', 'class' => 'bg-primary'],
    'cerrado' => ['label' => 'Cerrado', 'class' => 'bg-dark']
];
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Tablón de Clase - Educación Plus</title>
    
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet">
    
    <style>
        :root {
            --primary: #2c3e50;
            --secondary: #3498db;
            --success: #2ecc71;
            --warning: #f39c12;
            --danger: #e74c3c;
            --info: #17a2b8;
            --purple: #9b59b6;
            --sidebar-width: 260px;
        }
        
        body {
            font-family: 'Segoe UI', system-ui, -apple-system, sans-serif;
            background: #f0f2f5;
        }
        
        .sidebar {
            position: fixed;
            top: 0;
            left: 0;
            height: 100vh;
            width: var(--sidebar-width);
            background: var(--primary);
            color: white;
            padding-top: 20px;
            z-index: 1000;
            overflow-y: auto;
        }
        
        .sidebar .brand {
            text-align: center;
            padding: 0 20px 20px;
            border-bottom: 1px solid rgba(255,255,255,0.1);
            margin-bottom: 20px;
        }
        
        .sidebar .nav-link {
            color: rgba(255,255,255,0.85);
            padding: 12px 20px;
            margin: 2px 10px;
            border-radius: 8px;
            transition: all 0.2s;
        }
        
        .sidebar .nav-link:hover, .sidebar .nav-link.active {
            color: white;
            background: rgba(255,255,255,0.15);
        }
        
        .main-content {
            margin-left: var(--sidebar-width);
            padding: 20px;
        }
        
        /* Header de clase */
        .class-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 30px;
            border-radius: 12px;
            margin-bottom: 25px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.1);
        }
        
        .class-header h2 {
            font-weight: 600;
            margin-bottom: 5px;
        }
        
        /* Filtros */
        .filter-bar {
            background: white;
            padding: 20px;
            border-radius: 12px;
            margin-bottom: 25px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.08);
        }
        
        /* Tarjetas de actividad - Estilo Google Classroom */
        .activity-card {
            background: white;
            border-radius: 12px;
            margin-bottom: 20px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.08);
            transition: all 0.3s ease;
            border-left: 5px solid var(--secondary);
            overflow: hidden;
        }
        
        .activity-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 6px 20px rgba(0,0,0,0.12);
        }
        
        .activity-card.tipo-tarea { border-left-color: var(--warning); }
        .activity-card.tipo-examen { border-left-color: var(--danger); }
        .activity-card.tipo-video { border-left-color: var(--info); }
        .activity-card.tipo-youtube { border-left-color: var(--danger); }
        .activity-card.tipo-articulo { border-left-color: var(--primary); }
        .activity-card.tipo-referencia { border-left-color: var(--purple); }
        
        .activity-header {
            padding: 20px;
            border-bottom: 1px solid #f0f2f5;
        }
        
        .activity-type-badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 6px 14px;
            border-radius: 20px;
            font-size: 0.85rem;
            font-weight: 600;
            margin-bottom: 12px;
        }
        
        .activity-title {
            font-size: 1.25rem;
            font-weight: 600;
            color: #1a1a1a;
            margin-bottom: 8px;
        }
        
        .activity-meta {
            display: flex;
            gap: 20px;
            color: #666;
            font-size: 0.9rem;
            flex-wrap: wrap;
        }
        
        .activity-meta i {
            margin-right: 5px;
            width: 16px;
        }
        
        .activity-body {
            padding: 20px;
        }
        
        .activity-description {
            color: #444;
            line-height: 1.6;
            margin-bottom: 15px;
        }
        
        .activity-footer {
            padding: 15px 20px;
            background: #f8f9fa;
            border-top: 1px solid #f0f2f5;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 15px;
        }
        
        .stats-pill {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 8px 16px;
            background: white;
            border-radius: 20px;
            font-size: 0.9rem;
            font-weight: 500;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
        }
        
        .btn-activity {
            padding: 8px 20px;
            border-radius: 8px;
            font-weight: 500;
            transition: all 0.2s;
        }
        
        .btn-activity:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
        }
        
        /* Estados de entrega */
        .entrega-status {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 4px 12px;
            border-radius: 12px;
            font-size: 0.85rem;
            font-weight: 600;
        }
        
        .entrega-pendiente { background: #fff3cd; color: #856404; }
        .entrega-entregado { background: #d1ecf1; color: #0c5460; }
        .entrega-calificado { background: #d4edda; color: #155724; }
        
        /* Empty state */
        .empty-state {
            text-align: center;
            padding: 60px 20px;
            background: white;
            border-radius: 12px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.08);
        }
        
        .empty-state i {
            font-size: 4rem;
            color: #ddd;
            margin-bottom: 20px;
        }
        
        @media (max-width: 992px) {
            .sidebar { transform: translateX(-100%); }
            .sidebar.active { transform: translateX(0); }
            .main-content { margin-left: 0; }
        }
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
            <a class="nav-link" href="profesor_dashboard.php"><i class="fas fa-home"></i> Dashboard</a>
            <a class="nav-link active" href="tablon.php"><i class="fas fa-chalkboard"></i> Tablón</a>
            <a class="nav-link" href="aula_virtual.php"><i class="fas fa-video"></i> Aula Virtual</a>
            <a class="nav-link" href="gestionar_actividades.php"><i class="fas fa-tasks"></i> Actividades</a>
            <a class="nav-link" href="calificaciones.php"><i class="fas fa-star"></i> Calificaciones</a>
            
            <a class="nav-link" href="../../logout.php"><i class="fas fa-sign-out-alt"></i> Cerrar Sesión</a>
        </nav>
    </div>

    <!-- Main Content -->
    <div class="main-content">
        <?php if (!$id_asignacion): ?>
        <!-- Selección de Clase -->
        <div class="class-header">
            <h2><i class="fas fa-chalkboard"></i> Selecciona una Clase</h2>
            <p class="mb-0 opacity-75">Elige una de tus asignaciones para ver el tablón de actividades</p>
        </div>
        
        <div class="row g-4">
            <?php if (empty($asignaciones)): ?>
            <div class="col-12">
                <div class="empty-state">
                    <i class="fas fa-inbox"></i>
                    <h4>No tienes asignaciones registradas</h4>
                    <p class="text-muted">Contacta al administrador para que te asigne clases</p>
                </div>
            </div>
            <?php else: ?>
            <?php foreach ($asignaciones as $asig): ?>
            <div class="col-lg-4 col-md-6">
                <a href="?asignacion=<?= $asig['id'] ?>" class="text-decoration-none">
                    <div class="card-custom p-4 h-100" style="background: white; border-radius: 12px; box-shadow: 0 2px 8px rgba(0,0,0,0.08); transition: all 0.3s;">
                        <div class="d-flex align-items-center gap-3 mb-3">
                            <div class="bg-primary text-white rounded-circle d-flex align-items-center justify-content-center" style="width: 50px; height: 50px;">
                                <i class="fas fa-book fa-lg"></i>
                            </div>
                            <div>
                                <h5 class="mb-0"><?= htmlspecialchars($asig['asignatura']) ?></h5>
                                <small class="text-muted"><?= htmlspecialchars($asig['grado']) ?> - <?= htmlspecialchars($asig['seccion']) ?></small>
                            </div>
                        </div>
                        <div class="d-flex justify-content-between align-items-center">
                            <span class="badge bg-primary">Ver Tablón</span>
                            <i class="fas fa-arrow-right text-muted"></i>
                        </div>
                    </div>
                </a>
            </div>
            <?php endforeach; ?>
            <?php endif; ?>
        </div>
        
        <?php else: ?>
        <!-- Tablón de Clase -->
        <?php 
        $asignacion_actual = current(array_filter($asignaciones, fn($a) => $a['id'] == $id_asignacion));
        ?>
        
        <div class="class-header">
            <div class="d-flex justify-content-between align-items-start flex-wrap gap-3">
                <div>
                    <h2 class="mb-2"><i class="fas fa-chalkboard"></i> <?= htmlspecialchars($asignacion_actual['asignatura']) ?></h2>
                    <p class="mb-0 opacity-75">
                        <i class="fas fa-layer-group"></i> <?= htmlspecialchars($asignacion_actual['grado']) ?> 
                        <span class="mx-2">•</span>
                        <i class="fas fa-users"></i> <?= htmlspecialchars($asignacion_actual['seccion']) ?>
                    </p>
                </div>
                <div class="d-flex gap-2">
                    <a href="gestionar_actividades.php?asignacion=<?= $id_asignacion ?>" class="btn btn-light btn-custom">
                        <i class="fas fa-plus"></i> Nueva Actividad
                    </a>
                    <a href="tablon.php" class="btn btn-outline-light">
                        <i class="fas fa-arrow-left"></i> Cambiar Clase
                    </a>
                </div>
            </div>
        </div>
        
        <!-- Filtros -->
        <div class="filter-bar">
            <form method="GET" class="row g-3">
                <input type="hidden" name="asignacion" value="<?= $id_asignacion ?>">
                
                <div class="col-md-3">
                    <label class="form-label small text-muted">Tipo de Actividad</label>
                    <select name="tipo" class="form-select" onchange="this.form.submit()">
                        <option value="todas" <?= $filtro_tipo == 'todas' ? 'selected' : '' ?>>Todas</option>
                        <?php foreach ($tipos_actividad as $key => $tipo): ?>
                        <option value="<?= $key ?>" <?= $filtro_tipo == $key ? 'selected' : '' ?>>
                            <i class="fas <?= $tipo['icon'] ?>"></i> <?= $tipo['label'] ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="col-md-3">
                    <label class="form-label small text-muted">Estado</label>
                    <select name="estado" class="form-select" onchange="this.form.submit()">
                        <option value="todas" <?= $filtro_estado == 'todas' ? 'selected' : '' ?>>Todos</option>
                        <?php foreach ($estados_actividad as $key => $estado): ?>
                        <option value="<?= $key ?>" <?= $filtro_estado == $key ? 'selected' : '' ?>><?= $estado['label'] ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="col-md-4">
                    <label class="form-label small text-muted">Buscar</label>
                    <div class="input-group">
                        <input type="text" name="busqueda" class="form-control" placeholder="Buscar actividades..." value="<?= htmlspecialchars($busqueda) ?>">
                        <button type="submit" class="btn btn-primary"><i class="fas fa-search"></i></button>
                    </div>
                </div>
                
                <div class="col-md-2 d-flex align-items-end">
                    <a href="?asignacion=<?= $id_asignacion ?>" class="btn btn-outline-secondary w-100">
                        <i class="fas fa-redo"></i> Limpiar
                    </a>
                </div>
            </form>
        </div>
        
        <!-- Lista de Actividades -->
        <?php if (empty($actividades)): ?>
        <div class="empty-state">
            <i class="fas fa-clipboard-list"></i>
            <h4>No hay actividades publicadas</h4>
            <p class="text-muted">Comienza creando tu primera actividad para esta clase</p>
            <a href="gestionar_actividades.php?asignacion=<?= $id_asignacion ?>" class="btn btn-primary btn-lg">
                <i class="fas fa-plus"></i> Crear Primera Actividad
            </a>
        </div>
        <?php else: ?>
        <div class="activity-stream">
            <?php foreach ($actividades as $actividad): 
                $tipo = $tipos_actividad[$actividad['tipo']] ?? ['label' => $actividad['tipo'], 'icon' => 'fa-file', 'color' => 'secondary', 'bg' => 'bg-secondary'];
                $estado = $estados_actividad[$actividad['estado']] ?? ['label' => $actividad['estado'], 'class' => 'bg-secondary'];
                $porcentaje_calificadas = $actividad['total_entregas'] > 0 ? round(($actividad['calificadas'] / $actividad['total_entregas']) * 100) : 0;
            ?>
            <div class="activity-card tipo-<?= htmlspecialchars($actividad['tipo']) ?>">
                <div class="activity-header">
                    <span class="activity-type-badge bg-<?= $tipo['color'] ?> text-white">
                        <i class="fas <?= $tipo['icon'] ?>"></i>
                        <?= $tipo['label'] ?>
                    </span>
                    <h3 class="activity-title"><?= htmlspecialchars($actividad['titulo']) ?></h3>
                    <div class="activity-meta">
                        <span><i class="fas fa-calendar"></i> <?= date('d/m/Y', strtotime($actividad['fecha_programada'])) ?></span>
                        <?php if ($actividad['fecha_limite']): ?>
                        <span><i class="fas fa-clock"></i> Entrega: <?= date('d/m/Y H:i', strtotime($actividad['fecha_limite'])) ?></span>
                        <?php endif; ?>
                        <?php if ($actividad['duracion_minutos']): ?>
                        <span><i class="fas fa-stopwatch"></i> <?= $actividad['duracion_minutos'] ?> min</span>
                        <?php endif; ?>
                        <?php if ($actividad['nota_maxima']): ?>
                        <span><i class="fas fa-star"></i> Máx: <?= $actividad['nota_maxima'] ?> pts</span>
                        <?php endif; ?>
                        <span class="badge <?= $estado['class'] ?>"><?= $estado['label'] ?></span>
                    </div>
                </div>
                
                <?php if ($actividad['descripcion']): ?>
                <div class="activity-body">
                    <p class="activity-description"><?= nl2br(htmlspecialchars($actividad['descripcion'])) ?></p>
                    
                    <?php if ($actividad['url_recurso'] && $actividad['tipo'] == 'youtube'): 
                        preg_match('/[\\?\\&]v=([^\\?\\&]+)/', $actividad['url_recurso'], $matches);
                        $video_id = $matches[1] ?? '';
                        if ($video_id):
                    ?>
                    <div class="embed-container mb-3" style="position: relative; padding-bottom: 56.25%; height: 0; overflow: hidden; border-radius: 8px;">
                        <iframe src="https://www.youtube.com/embed/<?= htmlspecialchars($video_id) ?>" style="position: absolute; top: 0; left: 0; width: 100%; height: 100%; border: 0;" allowfullscreen></iframe>
                    </div>
                    <?php endif; endif; ?>
                </div>
                <?php endif; ?>
                
                <div class="activity-footer">
                    <div class="d-flex gap-3 flex-wrap">
                        <div class="stats-pill">
                            <i class="fas fa-users text-primary"></i>
                            <strong><?= $actividad['total_entregas'] ?></strong> entregas
                            <span class="text-muted">/ <?= count($estudiantes) ?> estudiantes</span>
                        </div>
                        
                        <div class="stats-pill">
                            <i class="fas fa-check-circle text-success"></i>
                            <strong><?= $actividad['calificadas'] ?></strong> calificadas
                            <span class="text-muted">(<?= $porcentaje_calificadas ?>%)</span>
                        </div>
                        
                        <?php if ($actividad['promedio_notas']): ?>
                        <div class="stats-pill">
                            <i class="fas fa-chart-line text-info"></i>
                            Promedio: <strong><?= number_format($actividad['promedio_notas'], 2) ?></strong>
                        </div>
                        <?php endif; ?>
                    </div>
                    
                    <div class="d-flex gap-2">
                        <a href="ver_actividad.php?id=<?= $actividad['id'] ?>" class="btn btn-outline-primary btn-activity">
                            <i class="fas fa-eye"></i> Ver
                        </a>
                        <a href="calificaciones.php?actividad=<?= $actividad['id'] ?>" class="btn btn-primary btn-activity">
                            <i class="fas fa-star"></i> Calificar
                        </a>
                        <div class="dropdown">
                            <button class="btn btn-outline-secondary btn-activity" data-bs-toggle="dropdown">
                                <i class="fas fa-ellipsis-v"></i>
                            </button>
                            <ul class="dropdown-menu dropdown-menu-end">
                                <li><a class="dropdown-item" href="gestionar_actividades.php?editar=<?= $actividad['id'] ?>"><i class="fas fa-edit"></i> Editar</a></li>
                                <li><a class="dropdown-item" href="entregas.php?actividad=<?= $actividad['id'] ?>"><i class="fas fa-inbox"></i> Ver Entregas</a></li>
                                <li><hr class="dropdown-divider"></li>
                                <li><a class="dropdown-item text-danger" href="#" onclick="eliminarActividad(<?= $actividad['id'] ?>)"><i class="fas fa-trash"></i> Eliminar</a></li>
                            </ul>
                        </div>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
        <?php endif; ?>
    </div>

    <!-- Scripts -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
    
    <script>
        // Inicializar Select2
        $(document).ready(function() {
            $('select').select2({
                theme: 'bootstrap-5',
                width: '100%'
            });
        });
        
        // Eliminar actividad
        function eliminarActividad(id) {
            if (confirm('¿Estás seguro de eliminar esta actividad? Esta acción no se puede deshacer.')) {
                // Implementar eliminación vía AJAX o redirección
                window.location.href = 'eliminar_actividad.php?id=' + id;
            }
        }
        
        // Sidebar toggle
        document.addEventListener('DOMContentLoaded', function() {
            if (window.innerWidth < 992) {
                document.getElementById('sidebar').classList.remove('active');
            }
        });
    </script>
</body>
</html>