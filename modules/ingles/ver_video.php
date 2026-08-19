<?php
session_start();
require_once __DIR__ . '/../../config/database.php';
include 'functions/video_functions.php';

// Verificar autenticación
if (!isset($_SESSION['user_id'])) {
    header("Location: " . BASE_URL . "/login.php");
    exit;
}

$database = new Database();
$db = $database->getConnection();

$id_video = $_GET['id'] ?? 0;
$id_leccion = $_GET['leccion'] ?? 0;

// Obtener información del video
$query = "SELECT v.*, l.titulo as leccion_titulo, l.tipo as leccion_tipo,
          c.nombre as curso_nombre, c.nivel as curso_nivel
          FROM tbl_ingles_video v
          LEFT JOIN tbl_ingles_leccion l ON v.id_leccion = l.id
          LEFT JOIN tbl_ingles_curso c ON l.id_curso = c.id
          WHERE v.id = :id";
$stmt = $db->prepare($query);
$stmt->bindValue(':id', $id_video, PDO::PARAM_INT);
$stmt->execute();
$video = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$video) {
    header("Location: ingles_dashboard.php");
    exit;
}

// Obtener videos relacionados
$query = "SELECT v.*, l.titulo as leccion_titulo 
          FROM tbl_ingles_video v
          LEFT JOIN tbl_ingles_leccion l ON v.id_leccion = l.id
          WHERE v.id != :id AND v.nivel = :nivel AND v.estado = 'activo'
          ORDER BY RAND() LIMIT 5";
$stmt = $db->prepare($query);
$stmt->bindValue(':id', $id_video, PDO::PARAM_INT);
$stmt->bindValue(':nivel', $video['nivel'], PDO::PARAM_STR);
$stmt->execute();
$videos_relacionados = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Registrar vista
$query = "UPDATE tbl_ingles_video SET vistas = vistas + 1 WHERE id = :id";
$stmt = $db->prepare($query);
$stmt->bindValue(':id', $id_video, PDO::PARAM_INT);
$stmt->execute();
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($video['titulo']) ?> - English Plus</title>
    
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    
    <style>
        body { background: #f5f7fa; }
        .video-player-container {
            background: #000;
            border-radius: 16px;
            overflow: hidden;
            box-shadow: 0 10px 40px rgba(0,0,0,0.3);
            margin-bottom: 30px;
        }
        .video-wrapper {
            position: relative;
            padding-bottom: 56.25%;
            height: 0;
        }
        .video-wrapper iframe {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
        }
        .video-info {
            background: white;
            border-radius: 16px;
            padding: 30px;
            margin-bottom: 30px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.08);
        }
        .video-stats {
            display: flex;
            gap: 20px;
            color: #666;
            font-size: 0.9rem;
            margin-top: 15px;
        }
        .video-stats i { margin-right: 5px; }
        .related-video {
            display: flex;
            gap: 15px;
            padding: 15px;
            background: white;
            border-radius: 12px;
            margin-bottom: 15px;
            cursor: pointer;
            transition: all 0.2s;
        }
        .related-video:hover {
            box-shadow: 0 4px 15px rgba(0,0,0,0.1);
            transform: translateX(5px);
        }
        .related-video img {
            width: 160px;
            height: 90px;
            object-fit: cover;
            border-radius: 8px;
        }
        .badge-nivel {
            padding: 5px 12px;
            border-radius: 15px;
            font-size: 0.75rem;
            font-weight: 600;
        }
        .progress-learning {
            height: 8px;
            border-radius: 10px;
            background: #e5e5e5;
            margin: 20px 0;
        }
        .progress-learning .progress-bar {
            background: linear-gradient(90deg, #58cc02, #46a302);
            border-radius: 10px;
        }
        .note-section {
            background: #fff9e6;
            border-left: 4px solid #ffc800;
            padding: 20px;
            border-radius: 8px;
            margin: 20px 0;
        }
    </style>
</head>
<body>
    <!-- Header -->
    <nav class="navbar navbar-light bg-white shadow-sm mb-4">
        <div class="container">
            <a class="navbar-brand" href="ingles_dashboard.php">
                <i class="fas fa-language text-success"></i> English Plus
            </a>
            <div>
                <a href="ingles_dashboard.php" class="btn btn-outline-secondary btn-sm">
                    <i class="fas fa-arrow-left"></i> Volver
                </a>
            </div>
        </div>
    </nav>

    <div class="container">
        <div class="row">
            <!-- Main Content -->
            <div class="col-lg-8">
                <!-- Video Player -->
                <div class="video-player-container">
                    <div class="video-wrapper">
                        <?= generarYouTubeEmbed($video['youtube_id'], [
                            'width' => '100%',
                            'height' => '100%',
                            'subtitles' => 1,
                            'rel' => 0
                        ]) ?>
                    </div>
                </div>

                <!-- Video Info -->
                <div class="video-info">
                    <div class="d-flex justify-content-between align-items-start mb-3">
                        <div>
                            <span class="badge-nivel bg-primary text-white mb-2 d-inline-block">
                                <i class="fas fa-graduation-cap"></i> <?= ucfirst($video['nivel'] ?? 'All Levels') ?>
                            </span>
                            <span class="badge-nivel bg-info text-white mb-2 d-inline-block">
                                <i class="fas fa-book"></i> <?= ucfirst($video['categoria'] ?? 'General') ?>
                            </span>
                        </div>
                        <div class="text-end">
                            <button class="btn btn-outline-success btn-sm" onclick="marcarCompletado()">
                                <i class="fas fa-check"></i> Marcar como Completado
                            </button>
                        </div>
                    </div>
                    
                    <h1 class="mb-3"><?= htmlspecialchars($video['titulo']) ?></h1>
                    
                    <?php if ($video['leccion_titulo']): ?>
                    <p class="text-muted mb-3">
                        <i class="fas fa-graduation-cap"></i> 
                        Lección: <?= htmlspecialchars($video['leccion_titulo']) ?>
                        <?php if ($video['curso_nombre']): ?>
                        | Curso: <?= htmlspecialchars($video['curso_nombre']) ?>
                        <?php endif; ?>
                    </p>
                    <?php endif; ?>
                    
                    <div class="video-stats">
                        <span><i class="fas fa-eye"></i> <?= number_format($video['vistas']) ?> vistas</span>
                        <span><i class="fas fa-clock"></i> <?= gmdate('i:s', $video['duracion_segundos']) ?></span>
                        <span><i class="fas fa-thumbs-up"></i> <?= $video['likes'] ?? 0 ?> likes</span>
                    </div>
                    
                    <?php if ($video['descripcion']): ?>
                    <div class="mt-4">
                        <h5><i class="fas fa-info-circle"></i> Descripción</h5>
                        <p class="text-muted"><?= nl2br(htmlspecialchars($video['descripcion'])) ?></p>
                    </div>
                    <?php endif; ?>
                    
                    <!-- Notas de Aprendizaje -->
                    <div class="note-section">
                        <h5><i class="fas fa-lightbulb text-warning"></i> Consejos de Aprendizaje</h5>
                        <ul class="mb-0">
                            <li>🎧 Usa audífonos para mejor comprensión auditiva</li>
                            <li>📝 Toma notas de vocabulario nuevo</li>
                            <li>🔁 Repite el video si no entiendes algo</li>
                            <li>🗣️ Practica en voz alta lo que escuches</li>
                            <li>⏸️ Pausa el video para repetir frases</li>
                        </ul>
                    </div>
                    
                    <!-- Progreso de Aprendizaje -->
                    <div class="mt-4">
                        <div class="d-flex justify-content-between mb-2">
                            <span>Tu Progreso en esta Lección</span>
                            <span id="progresoTexto">0%</span>
                        </div>
                        <div class="progress-learning">
                            <div class="progress-bar" id="progresoBar" style="width: 0%"></div>
                        </div>
                    </div>
                </div>

                <!-- Ejercicios del Video -->
                <div class="card mb-4">
                    <div class="card-header bg-white">
                        <h5 class="mb-0"><i class="fas fa-tasks"></i> Ejercicios de Comprensión</h5>
                    </div>
                    <div class="card-body">
                        <p class="text-muted">Responde estas preguntas después de ver el video</p>
                        <div id="ejerciciosContainer">
                            <!-- Los ejercicios se cargarían aquí -->
                            <button class="btn btn-primary" onclick="iniciarEjercicios()">
                                <i class="fas fa-play"></i> Comenzar Ejercicios
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Sidebar - Related Videos -->
            <div class="col-lg-4">
                <div class="card mb-4">
                    <div class="card-header bg-white">
                        <h5 class="mb-0"><i class="fas fa-list"></i> Videos Relacionados</h5>
                    </div>
                    <div class="card-body p-3">
                        <?php foreach ($videos_relacionados as $relacionado): ?>
                        <div class="related-video" onclick="window.location.href='ver_video.php?id=<?= $relacionado['id'] ?>'">
                            <img src="<?= obtenerYouTubeThumbnail($relacionado['youtube_id']) ?>" 
                                 alt="<?= htmlspecialchars($relacionado['titulo']) ?>">
                            <div>
                                <h6 class="mb-1"><?= htmlspecialchars($relacionado['titulo']) ?></h6>
                                <small class="text-muted">
                                    <i class="fas fa-clock"></i> <?= gmdate('i:s', $relacionado['duracion_segundos']) ?>
                                </small>
                                <?php if ($relacionado['leccion_titulo']): ?>
                                <div class="text-truncate" style="max-width: 150px;">
                                    <small class="text-muted"><?= htmlspecialchars($relacionado['leccion_titulo']) ?></small>
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>

                <!-- Playlist -->
                <div class="card">
                    <div class="card-header bg-white">
                        <h5 class="mb-0"><i class="fas fa-list-ul"></i> Playlist del Curso</h5>
                    </div>
                    <div class="card-body p-3">
                        <!-- Lista de videos de la playlist -->
                        <div class="list-group">
                            <?php
                            $query_playlist = "SELECT v.id, v.titulo, v.youtube_id, v.duracion_segundos
                                              FROM tbl_ingles_video v
                                              WHERE v.id_leccion = :id_leccion
                                              ORDER BY v.id ASC";
                            $stmt_playlist = $db->prepare($query_playlist);
                            $stmt_playlist->bindValue(':id_leccion', $id_leccion, PDO::PARAM_INT);
                            $stmt_playlist->execute();
                            $playlist_videos = $stmt_playlist->fetchAll(PDO::FETCH_ASSOC);
                            
                            foreach ($playlist_videos as $index => $playlist_video):
                            ?>
                            <a href="ver_video.php?id=<?= $playlist_video['id'] ?>&leccion=<?= $id_leccion ?>" 
                               class="list-group-item list-group-item-action <?= $playlist_video['id'] == $id_video ? 'active' : '' ?>">
                                <div class="d-flex justify-content-between align-items-center">
                                    <div>
                                        <small class="text-<?= $playlist_video['id'] == $id_video ? 'white' : 'muted' ?>">
                                            <?= $index + 1 ?>.
                                        </small>
                                        <span class="<?= $playlist_video['id'] == $id_video ? 'text-white' : '' ?>">
                                            <?= htmlspecialchars($playlist_video['titulo']) ?>
                                        </span>
                                    </div>
                                    <small class="<?= $playlist_video['id'] == $id_video ? 'text-white' : 'text-muted' ?>">
                                        <?= gmdate('i:s', $playlist_video['duracion_segundos']) ?>
                                    </small>
                                </div>
                            </a>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Scripts -->
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    
    <script>
        const CONFIG = {
            videoId: <?= $id_video ?>,
            leccionId: <?= $id_leccion ?>,
            apiUrl: '../api/ingles/'
        };
        
        // Marcar video como completado
        function marcarCompletado() {
            $.post(CONFIG.apiUrl + 'marcar_video_completado.php', {
                id_video: CONFIG.videoId,
                id_leccion: CONFIG.leccionId
            }, function(response) {
                if (response.success) {
                    $('#progresoBar').css('width', '100%');
                    $('#progresoTexto').text('100%');
                    
                    Swal.fire({
                        icon: 'success',
                        title: '¡Completado!',
                        text: 'Has marcado este video como completado',
                        timer: 2000
                    });
                }
            }, 'json');
        }
        
        // Iniciar ejercicios
        function iniciarEjercicios() {
            window.location.href = `ejercicios_video.php?id_video=${CONFIG.videoId}&id_leccion=${CONFIG.leccionId}`;
        }
        
        // Trackear progreso de visualización
        let videoProgress = 0;
        const checkInterval = setInterval(() => {
            // Aquí se integraría con la API de YouTube Player para trackear progreso real
            videoProgress += 1;
            if (videoProgress <= 100) {
                $('#progresoBar').css('width', videoProgress + '%');
                $('#progresoTexto').text(videoProgress + '%');
            }
        }, 3000); // Actualizar cada 3 segundos como demo
    </script>
</body>
</html>