<?php
session_start();
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/TenantGuard.php';

// Verificar autenticación
if (!isset($_SESSION['user_id'])) {
    header("Location: " . BASE_URL . "/login.php");
    exit;
}

$database = new Database();
$db = $database->getConnection();
$user_id = $_SESSION['user_id'];
$rol = $_SESSION['rol'];
$tid = TenantGuard::id();

// Obtener IDs
$id_video = $_GET['id_video'] ?? 0;
$id_leccion = $_GET['id_leccion'] ?? 0;

// Validar parámetros
if (!$id_video || !$id_leccion) {
    $_SESSION['error'] = 'Parámetros incompletos';
    header("Location: ver_video.php");
    exit;
}

// ===== OBTENER INFORMACIÓN DEL VIDEO Y LECCIÓN =====
$query = "SELECT v.titulo as video_titulo, v.youtube_id, v.descripcion as video_descripcion,
          l.titulo as leccion_titulo, l.descripcion as leccion_descripcion, l.tipo as leccion_tipo,
          c.nombre as curso_nombre, c.nivel as curso_nivel
          FROM tbl_ingles_video v
          JOIN tbl_ingles_leccion l ON v.id_leccion = l.id
          JOIN tbl_ingles_curso c ON l.id_curso = c.id
          WHERE v.id = :id_video";
$stmt = $db->prepare($query);
$stmt->bindValue(':id_video', $id_video, PDO::PARAM_INT);
$stmt->execute();
$video_info = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$video_info) {
    $_SESSION['error'] = 'Video no encontrado';
    header("Location: ingles_dashboard.php");
    exit;
}

// ===== OBTENER DATOS DEL ESTUDIANTE =====
$id_estudiante = 0;
if ($rol == 'estudiante') {
    $query = "SELECT e.id FROM tbl_estudiante e
              JOIN tbl_persona p ON e.id_persona = p.id
              WHERE p.id_usuario = :user_id AND e.id_institucion = :tid";
    $stmt = $db->prepare($query);
    $stmt->bindValue(':user_id', $user_id, PDO::PARAM_INT);
    $stmt->bindValue(':tid', $tid, PDO::PARAM_INT);
    $stmt->execute();
    $estudiante_data = $stmt->fetch(PDO::FETCH_ASSOC);
    $id_estudiante = $estudiante_data['id'] ?? 0;
}

// ===== OBTENER EJERCICIOS DE LA LECCIÓN =====
$query = "SELECT * FROM tbl_ingles_ejercicio 
          WHERE id_leccion = :id_leccion 
          ORDER BY orden ASC";
$stmt = $db->prepare($query);
$stmt->bindValue(':id_leccion', $id_leccion, PDO::PARAM_INT);
$stmt->execute();
$ejercicios = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ===== OBTENER PROGRESO PREVIO =====
$progreso_anterior = null;
$respuestas_anteriores = [];
if ($id_estudiante) {
    $query = "SELECT * FROM tbl_ingles_progreso
              WHERE id_estudiante = :id_estudiante AND id_leccion = :id_leccion";
    $stmt = $db->prepare($query);
    $stmt->bindValue(':id_estudiante', $id_estudiante, PDO::PARAM_INT);
    $stmt->bindValue(':id_leccion', $id_leccion, PDO::PARAM_INT);
    $stmt->execute();
    $progreso_anterior = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($progreso_anterior && $progreso_anterior['respuestas_json']) {
        $respuestas_anteriores = json_decode($progreso_anterior['respuestas_json'], true) ?? [];
    }
}

// ===== CONFIGURACIÓN DE EJERCICIOS =====
$tipos_ejercicio = [
    'multiple-choice' => [
        'label' => 'Opción Múltiple',
        'icon' => 'fa-list-ul',
        'color' => 'primary',
        'template' => 'multiple_choice'
    ],
    'fill-blank' => [
        'label' => 'Completar Espacios',
        'icon' => 'fa-pen',
        'color' => 'success',
        'template' => 'fill_blank'
    ],
    'matching' => [
        'label' => 'Emparejar',
        'icon' => 'fa-link',
        'color' => 'info',
        'template' => 'matching'
    ],
    'ordering' => [
        'label' => 'Ordenar',
        'icon' => 'fa-sort',
        'color' => 'warning',
        'template' => 'ordering'
    ],
    'listening' => [
        'label' => 'Comprensión Auditiva',
        'icon' => 'fa-headphones',
        'color' => 'danger',
        'template' => 'listening'
    ],
    'reading' => [
        'label' => 'Comprensión Lectora',
        'icon' => 'fa-book-reader',
        'color' => 'purple',
        'template' => 'reading'
    ],
    'speaking' => [
        'label' => 'Expresión Oral',
        'icon' => 'fa-microphone',
        'color' => 'teal',
        'template' => 'speaking'
    ],
    'writing' => [
        'label' => 'Expresión Escrita',
        'icon' => 'fa-keyboard',
        'color' => 'pink',
        'template' => 'writing'
    ]
];

$niveles_ingles = [
    'beginner' => ['label' => 'Principiante (A1)', 'color' => 'success'],
    'elementary' => ['label' => 'Básico (A2)', 'color' => 'info'],
    'pre-intermediate' => ['label' => 'Pre-Intermedio (B1)', 'color' => 'primary'],
    'intermediate' => ['label' => 'Intermedio (B1+)', 'color' => 'warning'],
    'upper-intermediate' => ['label' => 'Intermedio Alto (B2)', 'color' => 'orange'],
    'advanced' => ['label' => 'Avanzado (C1)', 'color' => 'danger'],
    'proficient' => ['label' => 'Dominio (C2)', 'color' => 'purple']
];
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Ejercicios - <?= htmlspecialchars($video_info['video_titulo']) ?> - English Plus</title>
    
    <!-- Bootstrap 5 -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Font Awesome -->
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <!-- Animate.css -->
    <link href="https://cdn.jsdelivr.net/npm/animate.css@4.1.1/animate.min.css" rel="stylesheet">
    <!-- SortableJS para ejercicios de ordenar -->
    <script src="https://cdn.jsdelivr.net/npm/sortablejs@1.15.0/Sortable.min.js"></script>
    
    <style>
        :root {
            --primary: #58cc02;
            --secondary: #2b70c9;
            --dark: #3c3c3c;
            --light: #f7f7f7;
        }
        
        body {
            background: linear-gradient(135deg, #f5f7fa 0%, #e4edf5 100%);
            font-family: 'Segoe UI', system-ui, sans-serif;
            min-height: 100vh;
        }
        
        /* Header */
        .exercises-header {
            background: linear-gradient(135deg, var(--primary) 0%, #46a302 100%);
            color: white;
            padding: 30px 0;
            margin-bottom: 30px;
            box-shadow: 0 4px 20px rgba(88, 204, 2, 0.3);
        }
        
        /* Progress Bar */
        .progress-container {
            position: sticky;
            top: 0;
            z-index: 1000;
            background: white;
            padding: 15px 0;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            margin-bottom: 30px;
        }
        
        .progress-bar-duo {
            height: 12px;
            border-radius: 10px;
            background: #e5e5e5;
            overflow: hidden;
        }
        
        .progress-fill {
            height: 100%;
            background: linear-gradient(90deg, var(--primary), #46a302);
            transition: width 0.5s ease;
        }
        
        /* Exercise Cards */
        .exercise-card {
            background: white;
            border-radius: 16px;
            padding: 30px;
            margin-bottom: 20px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.08);
            display: none;
            animation: fadeIn 0.5s ease;
        }
        
        .exercise-card.active {
            display: block;
        }
        
        .exercise-card.completed {
            border: 2px solid var(--primary);
            background: #f0fff0;
        }
        
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        /* Exercise Types */
        .option-btn {
            width: 100%;
            padding: 15px 20px;
            margin: 10px 0;
            border: 2px solid #e5e5e5;
            border-radius: 12px;
            background: white;
            text-align: left;
            transition: all 0.2s;
            cursor: pointer;
            font-size: 1rem;
        }
        
        .option-btn:hover {
            border-color: var(--primary);
            background: #f0fff0;
            transform: translateX(5px);
        }
        
        .option-btn.selected {
            border-color: var(--primary);
            background: var(--primary);
            color: white;
        }
        
        .option-btn.correct {
            border-color: var(--primary);
            background: var(--primary);
            color: white;
            animation: pulse 0.5s;
        }
        
        .option-btn.incorrect {
            border-color: #ff4b4b;
            background: #ff4b4b;
            color: white;
            animation: shake 0.5s;
        }
        
        @keyframes pulse {
            0%, 100% { transform: scale(1); }
            50% { transform: scale(1.02); }
        }
        
        @keyframes shake {
            0%, 100% { transform: translateX(0); }
            25% { transform: translateX(-5px); }
            75% { transform: translateX(5px); }
        }
        
        /* Fill Blank */
        .blank-input {
            display: inline-block;
            width: 150px;
            border: none;
            border-bottom: 2px solid var(--primary);
            padding: 5px 10px;
            margin: 0 5px;
            font-size: 1rem;
            text-align: center;
            background: transparent;
        }
        
        .blank-input:focus {
            outline: none;
            border-bottom-color: var(--secondary);
        }
        
        /* Matching */
        .matching-item {
            padding: 15px;
            margin: 10px 0;
            border: 2px solid #e5e5e5;
            border-radius: 12px;
            cursor: pointer;
            transition: all 0.2s;
        }
        
        .matching-item:hover {
            border-color: var(--primary);
            background: #f0fff0;
        }
        
        .matching-item.selected {
            border-color: var(--primary);
            background: var(--primary);
            color: white;
        }
        
        .matching-item.matched {
            border-color: var(--primary);
            background: #d4edda;
            opacity: 0.6;
            pointer-events: none;
        }
        
        /* Ordering */
        .order-item {
            padding: 15px;
            margin: 10px 0;
            border: 2px solid #e5e5e5;
            border-radius: 12px;
            cursor: move;
            background: white;
            transition: all 0.2s;
        }
        
        .order-item:hover {
            border-color: var(--primary);
            box-shadow: 0 4px 15px rgba(0,0,0,0.1);
        }
        
        .order-item .drag-handle {
            margin-right: 10px;
            color: #999;
        }
        
        /* Feedback */
        .feedback-box {
            padding: 20px;
            border-radius: 12px;
            margin-top: 20px;
            display: none;
        }
        
        .feedback-box.correct {
            background: #d4edda;
            border: 2px solid #28a745;
            color: #155724;
        }
        
        .feedback-box.incorrect {
            background: #f8d7da;
            border: 2px solid #dc3545;
            color: #721c24;
        }
        
        /* Navigation Buttons */
        .nav-buttons {
            display: flex;
            justify-content: space-between;
            margin-top: 30px;
            gap: 15px;
        }
        
        .btn-nav {
            padding: 12px 30px;
            border-radius: 12px;
            font-weight: 600;
            transition: all 0.2s;
        }
        
        .btn-nav:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 15px rgba(0,0,0,0.2);
        }
        
        /* Results Modal */
        .results-summary {
            text-align: center;
            padding: 40px;
        }
        
        .score-circle {
            width: 150px;
            height: 150px;
            border-radius: 50%;
            background: linear-gradient(135deg, var(--primary), #46a302);
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 3rem;
            font-weight: 700;
            margin: 0 auto 30px;
            box-shadow: 0 10px 40px rgba(88, 204, 2, 0.4);
        }
        
        /* Responsive */
        @media (max-width: 768px) {
            .exercise-card {
                padding: 20px;
            }
            
            .blank-input {
                width: 100px;
            }
            
            .nav-buttons {
                flex-direction: column;
            }
            
            .btn-nav {
                width: 100%;
            }
        }
    </style>
</head>
<body>
    <!-- Header -->
    <div class="exercises-header">
        <div class="container">
            <div class="row align-items-center">
                <div class="col-md-8">
                    <h1 class="mb-2">
                        <i class="fas fa-tasks"></i> Ejercicios de Comprensión
                    </h1>
                    <p class="mb-0 opacity-75">
                        <?= htmlspecialchars($video_info['leccion_titulo']) ?> • 
                        <?= htmlspecialchars($video_info['curso_nombre']) ?>
                    </p>
                </div>
                <div class="col-md-4 text-md-end">
                    <a href="ver_video.php?id=<?= $id_video ?>&leccion=<?= $id_leccion ?>" 
                       class="btn btn-outline-light">
                        <i class="fas fa-arrow-left"></i> Volver al Video
                    </a>
                </div>
            </div>
        </div>
    </div>

    <!-- Progress Bar -->
    <div class="progress-container">
        <div class="container">
            <div class="d-flex justify-content-between align-items-center mb-2">
                <span class="fw-bold">
                    <i class="fas fa-chart-line"></i> Progreso
                </span>
                <span id="progressText">0/<?= count($ejercicios) ?> ejercicios</span>
            </div>
            <div class="progress-bar-duo">
                <div class="progress-fill" id="progressFill" style="width: 0%"></div>
            </div>
        </div>
    </div>

    <div class="container">
        <!-- Instructions -->
        <?php if (empty($ejercicios)): ?>
        <div class="alert alert-warning text-center">
            <i class="fas fa-exclamation-triangle fa-3x mb-3"></i>
            <h4>No hay ejercicios disponibles</h4>
            <p>Esta lección aún no tiene ejercicios configurados.</p>
            <a href="ver_video.php?id=<?= $id_video ?>&leccion=<?= $id_leccion ?>" class="btn btn-primary">
                <i class="fas fa-arrow-left"></i> Volver al Video
            </a>
        </div>
        <?php else: ?>
        
        <div class="alert alert-info mb-4">
            <i class="fas fa-info-circle"></i> 
            <strong>Instrucciones:</strong> Responde todas las preguntas. Puedes revisar tus respuestas antes de enviar.
            <?php if ($progreso_anterior): ?>
            <br><small class="text-muted">
                <i class="fas fa-history"></i> 
                Intento anterior: <?= $progreso_anterior['puntaje'] ?> puntos - 
                <?= date('d/m/Y H:i', strtotime($progreso_anterior['ultimo_intento'])) ?>
            </small>
            <?php endif; ?>
        </div>

        <!-- Exercise Cards -->
        <form id="exercisesForm">
            <?php foreach ($ejercicios as $index => $ejercicio): 
                $tipo = $tipos_ejercicio[$ejercicio['tipo']] ?? ['label' => $ejercicio['tipo'], 'icon' => 'fa-question', 'color' => 'secondary'];
                $opciones = json_decode($ejercicio['opciones_json'], true) ?? [];
                $respuesta_guardada = $respuestas_anteriores[$index] ?? null;
            ?>
            <div class="exercise-card <?= $index === 0 ? 'active' : '' ?>" data-index="<?= $index ?>">
                <!-- Exercise Header -->
                <div class="d-flex justify-content-between align-items-start mb-4">
                    <div>
                        <span class="badge bg-<?= $tipo['color'] ?> mb-2">
                            <i class="fas <?= $tipo['icon'] ?>"></i> <?= $tipo['label'] ?>
                        </span>
                        <h4 class="mb-0">
                            <span class="text-muted">Ejercicio <?= $index + 1 ?>:</span>
                            <?= htmlspecialchars($ejercicio['pregunta']) ?>
                        </h4>
                    </div>
                    <div class="text-end">
                        <span class="badge bg-warning text-dark">
                            <i class="fas fa-star"></i> <?= $ejercicio['puntos'] ?> puntos
                        </span>
                    </div>
                </div>

                <!-- Exercise Content Based on Type -->
                <div class="exercise-content">
                    <?php if ($ejercicio['tipo'] == 'multiple-choice'): ?>
                        <!-- Multiple Choice -->
                        <div class="options-container">
                            <?php foreach ($opciones as $opcion): ?>
                            <button type="button" class="option-btn" 
                                    onclick="selectOption(<?= $index ?>, '<?= htmlspecialchars($opcion, ENT_QUOTES) ?>')">
                                <?= htmlspecialchars($opcion) ?>
                            </button>
                            <?php endforeach; ?>
                        </div>
                        <input type="hidden" id="respuesta_<?= $index ?>" name="respuesta_<?= $index ?>" 
                               value="<?= htmlspecialchars($respuesta_guardada ?? '') ?>">
                    
                    <?php elseif ($ejercicio['tipo'] == 'fill-blank'): ?>
                        <!-- Fill in the Blank -->
                        <div class="fill-blank-container">
                            <?php
                            // Reemplazar ___ con input
                            $texto = $ejercicio['pregunta'];
                            $texto = preg_replace('/___+/', '<input type="text" class="blank-input" oninput="updateBlankAnswer('.$index.', this.value)">', $texto);
                            ?>
                            <p class="lead"><?= $texto ?></p>
                            <input type="hidden" id="respuesta_<?= $index ?>" name="respuesta_<?= $index ?>" 
                                   value="<?= htmlspecialchars($respuesta_guardada ?? '') ?>">
                        </div>
                    
                    <?php elseif ($ejercicio['tipo'] == 'matching'): ?>
                        <!-- Matching -->
                        <div class="row">
                            <div class="col-md-6">
                                <h6>Columna A</h6>
                                <?php foreach (array_chunk($opciones, 2) as $i => $par): ?>
                                <div class="matching-item" data-column="A" data-index="<?= $i ?>" 
                                     onclick="selectMatching('A', <?= $i ?>, '<?= htmlspecialchars($par[0] ?? '', ENT_QUOTES) ?>')">
                                    <?= htmlspecialchars($par[0] ?? '') ?>
                                </div>
                                <?php endforeach; ?>
                            </div>
                            <div class="col-md-6">
                                <h6>Columna B</h6>
                                <?php foreach (array_chunk($opciones, 2) as $i => $par): ?>
                                <div class="matching-item" data-column="B" data-index="<?= $i ?>" 
                                     onclick="selectMatching('B', <?= $i ?>, '<?= htmlspecialchars($par[1] ?? '', ENT_QUOTES) ?>')">
                                    <?= htmlspecialchars($par[1] ?? '') ?>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <input type="hidden" id="respuesta_<?= $index ?>" name="respuesta_<?= $index ?>" 
                               value="<?= htmlspecialchars(json_encode($respuesta_guardada ?? [])) ?>">
                    
                    <?php elseif ($ejercicio['tipo'] == 'ordering'): ?>
                        <!-- Ordering -->
                        <div class="ordering-container" id="ordering_<?= $index ?>">
                            <?php foreach ($opciones as $i => $item): ?>
                            <div class="order-item" data-index="<?= $i ?>">
                                <i class="fas fa-grip-vertical drag-handle"></i>
                                <?= htmlspecialchars($item) ?>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <input type="hidden" id="respuesta_<?= $index ?>" name="respuesta_<?= $index ?>" 
                               value="<?= htmlspecialchars(json_encode($respuesta_guardada ?? [])) ?>">
                    
                    <?php elseif ($ejercicio['tipo'] == 'listening'): ?>
                        <!-- Listening -->
                        <?php if ($ejercicio['audio_url']): ?>
                        <div class="text-center mb-4">
                            <audio controls class="mb-3">
                                <source src="<?= htmlspecialchars($ejercicio['audio_url']) ?>" type="audio/mpeg">
                                Tu navegador no soporta el elemento de audio.
                            </audio>
                            <p class="text-muted"><i class="fas fa-headphones"></i> Escucha y responde</p>
                        </div>
                        <?php endif; ?>
                        <textarea class="form-control" rows="3" 
                                  oninput="updateTextAnswer(<?= $index ?>, this.value)"
                                  placeholder="Escribe tu respuesta aquí..."><?= htmlspecialchars($respuesta_guardada ?? '') ?></textarea>
                        <input type="hidden" id="respuesta_<?= $index ?>" name="respuesta_<?= $index ?>" 
                               value="<?= htmlspecialchars($respuesta_guardada ?? '') ?>">
                    
                    <?php elseif ($ejercicio['tipo'] == 'writing'): ?>
                        <!-- Writing -->
                        <textarea class="form-control" rows="5" 
                                  oninput="updateTextAnswer(<?= $index ?>, this.value)"
                                  placeholder="Escribe tu respuesta aquí..."><?= htmlspecialchars($respuesta_guardada ?? '') ?></textarea>
                        <input type="hidden" id="respuesta_<?= $index ?>" name="respuesta_<?= $index ?>" 
                               value="<?= htmlspecialchars($respuesta_guardada ?? '') ?>">
                        <small class="text-muted">Mínimo 50 palabras</small>
                    
                    <?php else: ?>
                        <!-- Default (Reading/Speaking) -->
                        <textarea class="form-control" rows="3" 
                                  oninput="updateTextAnswer(<?= $index ?>, this.value)"
                                  placeholder="Escribe tu respuesta aquí..."><?= htmlspecialchars($respuesta_guardada ?? '') ?></textarea>
                        <input type="hidden" id="respuesta_<?= $index ?>" name="respuesta_<?= $index ?>" 
                               value="<?= htmlspecialchars($respuesta_guardada ?? '') ?>">
                    <?php endif; ?>
                </div>

                <!-- Feedback Box -->
                <div class="feedback-box" id="feedback_<?= $index ?>">
                    <div class="d-flex align-items-center gap-3">
                        <i class="fas fa-2x" id="feedback_icon_<?= $index ?>"></i>
                        <div>
                            <h5 class="mb-1" id="feedback_title_<?= $index ?>"></h5>
                            <p class="mb-0" id="feedback_message_<?= $index ?>"></p>
                        </div>
                    </div>
                </div>

                <!-- Navigation -->
                <div class="nav-buttons">
                    <?php if ($index > 0): ?>
                    <button type="button" class="btn btn-outline-secondary btn-nav" onclick="previousExercise()">
                        <i class="fas fa-arrow-left"></i> Anterior
                    </button>
                    <?php else: ?>
                    <div></div>
                    <?php endif; ?>
                    
                    <?php if ($index < count($ejercicios) - 1): ?>
                    <button type="button" class="btn btn-primary btn-nav" onclick="nextExercise()">
                        Siguiente <i class="fas fa-arrow-right"></i>
                    </button>
                    <?php else: ?>
                    <button type="button" class="btn btn-success btn-nav" onclick="submitExercises()">
                        <i class="fas fa-paper-plane"></i> Enviar Todas las Respuestas
                    </button>
                    <?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>
        </form>
        <?php endif; ?>
    </div>

    <!-- Results Modal -->
    <div class="modal fade" id="modalResultados" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header bg-success text-white">
                    <h5 class="modal-title">
                        <i class="fas fa-trophy"></i> Resultados
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="results-summary">
                        <div class="score-circle" id="scoreCircle">
                            0%
                        </div>
                        <h3 class="mb-3">¡Ejercicios Completados!</h3>
                        <p class="lead mb-4">
                            Puntaje: <strong id="scoreText">0</strong> / <span id="totalScore">0</span> puntos
                        </p>
                        <div class="row mb-4">
                            <div class="col-4">
                                <div class="text-success">
                                    <i class="fas fa-check-circle fa-2x mb-2"></i>
                                    <div id="correctCount">0</div>
                                    <small>Correctas</small>
                                </div>
                            </div>
                            <div class="col-4">
                                <div class="text-danger">
                                    <i class="fas fa-times-circle fa-2x mb-2"></i>
                                    <div id="incorrectCount">0</div>
                                    <small>Incorrectas</small>
                                </div>
                            </div>
                            <div class="col-4">
                                <div class="text-warning">
                                    <i class="fas fa-star fa-2x mb-2"></i>
                                    <div id="percentageText">0%</div>
                                    <small>Porcentaje</small>
                                </div>
                            </div>
                        </div>
                        <div class="d-grid gap-2">
                            <a href="ver_video.php?id=<?= $id_video ?>&leccion=<?= $id_leccion ?>" 
                               class="btn btn-outline-primary">
                                <i class="fas fa-video"></i> Volver al Video
                            </a>
                            <button class="btn btn-success" onclick="window.location.href='ingles_dashboard.php'">
                                <i class="fas fa-home"></i> Ir al Dashboard
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Scripts -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    
    <script>
        const CONFIG = {
            idVideo: <?= $id_video ?>,
            idLeccion: <?= $id_leccion ?>,
            idEstudiante: <?= $id_estudiante ?>,
            totalEjercicios: <?= count($ejercicios) ?>,
            ejercicios: <?= json_encode($ejercicios) ?>,
            apiUrl: '../api/ingles/'
        };
        
        let ejercicioActual = 0;
        let respuestas = {};
        let ejerciciosCompletados = 0;
        
        // Initialize ordering exercises with SortableJS
        $(document).ready(function() {
            <?php foreach ($ejercicios as $index => $ejercicio): ?>
            <?php if ($ejercicio['tipo'] == 'ordering'): ?>
            new Sortable(document.getElementById('ordering_<?= $index ?>'), {
                animation: 150,
                onEnd: function() {
                    updateOrderingAnswer(<?= $index ?>);
                }
            });
            <?php endif; ?>
            <?php endforeach; ?>
            
            // Keyboard navigation
            $(document).keydown(function(e) {
                if (e.key === 'ArrowRight') {
                    nextExercise();
                } else if (e.key === 'ArrowLeft') {
                    previousExercise();
                }
            });
        });
        
        // Select option for multiple choice
        function selectOption(ejercicioIndex, opcion) {
            $(`.exercise-card[data-index="${ejercicioIndex}"] .option-btn`).removeClass('selected');
            $(`.exercise-card[data-index="${ejercicioIndex}"] .option-btn`).filter(function() {
                return $(this).text().trim() === opcion;
            }).addClass('selected');
            
            respuestas[ejercicioIndex] = opcion;
            $(`#respuesta_${ejercicioIndex}`).val(opcion);
        }
        
        // Update blank answer
        function updateBlankAnswer(ejercicioIndex, value) {
            respuestas[ejercicioIndex] = value;
            $(`#respuesta_${ejercicioIndex}`).val(value);
        }
        
        // Update text answer
        function updateTextAnswer(ejercicioIndex, value) {
            respuestas[ejercicioIndex] = value;
            $(`#respuesta_${ejercicioIndex}`).val(value);
        }
        
        // Update ordering answer
        function updateOrderingAnswer(ejercicioIndex) {
            const order = [];
            $(`#ordering_${ejercicioIndex} .order-item`).each(function() {
                order.push($(this).text().trim());
            });
            respuestas[ejercicioIndex] = JSON.stringify(order);
            $(`#respuesta_${ejercicioIndex}`).val(JSON.stringify(order));
        }
        
        // Select matching items
        let matchingSelection = { A: null, B: null };
        function selectMatching(columna, index, valor) {
            $(`.matching-item[data-column="${columna}"]`).removeClass('selected');
            $(`.matching-item[data-column="${columna}"][data-index="${index}"]`).addClass('selected');
            
            matchingSelection[columna] = { index, valor };
            
            // Check if both columns selected
            if (matchingSelection.A && matchingSelection.B) {
                // Check if match
                const ejercicio = CONFIG.ejercicios[ejercicioActual];
                const opciones = JSON.parse(ejercicio.opciones_json || '[]');
                const parCorrecto = opciones.find(p => p[0] === matchingSelection.A.valor && p[1] === matchingSelection.B.valor);
                
                if (parCorrecto) {
                    $(`.matching-item[data-column="A"][data-index="${matchingSelection.A.index}"]`).addClass('matched');
                    $(`.matching-item[data-column="B"][data-index="${matchingSelection.B.index}"]`).addClass('matched');
                    
                    // Save match
                    if (!respuestas[ejercicioActual]) {
                        respuestas[ejercicioActual] = [];
                    }
                    respuestas[ejercicioActual].push({
                        A: matchingSelection.A.valor,
                        B: matchingSelection.B.valor
                    });
                }
                
                matchingSelection = { A: null, B: null };
            }
        }
        
        // Navigate exercises
        function showExercise(index) {
            $('.exercise-card').removeClass('active');
            $(`.exercise-card[data-index="${index}"]`).addClass('active');
            ejercicioActual = index;
            updateProgress();
        }
        
        function nextExercise() {
            if (ejercicioActual < CONFIG.totalEjercicios - 1) {
                showExercise(ejercicioActual + 1);
            }
        }
        
        function previousExercise() {
            if (ejercicioActual > 0) {
                showExercise(ejercicioActual - 1);
            }
        }
        
        function updateProgress() {
            const porcentaje = ((ejercicioActual + 1) / CONFIG.totalEjercicios) * 100;
            $('#progressFill').css('width', porcentaje + '%');
            $('#progressText').text(`${ejercicioActual + 1}/${CONFIG.totalEjercicios} ejercicios`);
        }
        
        // Verify single exercise
        function verifyExercise() {
            const ejercicio = CONFIG.ejercicios[ejercicioActual];
            const respuesta = respuestas[ejercicioActual];
            
            if (!respuesta) {
                Swal.fire({
                    icon: 'warning',
                    title: 'Sin respuesta',
                    text: 'Debes responder esta pregunta primero'
                });
                return;
            }
            
            // Check answer
            const esCorrecta = (respuesta === ejercicio.respuesta_correcta);
            
            // Show feedback
            const feedbackBox = $(`#feedback_${ejercicioActual}`);
            const feedbackIcon = $(`#feedback_icon_${ejercicioActual}`);
            const feedbackTitle = $(`#feedback_title_${ejercicioActual}`);
            const feedbackMessage = $(`#feedback_message_${ejercicioActual}`);
            
            feedbackBox.removeClass('correct incorrect').addClass(esCorrecta ? 'correct' : 'incorrect').fadeIn();
            feedbackIcon.removeClass('fa-check-circle fa-times-circle').addClass(esCorrecta ? 'fa-check-circle' : 'fa-times-circle');
            feedbackTitle.text(esCorrecta ? '¡Correcto!' : 'Incorrecto');
            feedbackMessage.text(esCorrecta ? ejercicio.explicacion || 'Muy bien hecho' : `La respuesta correcta es: ${ejercicio.respuesta_correcta}`);
            
            if (esCorrecta) {
                ejerciciosCompletados++;
            }
            
            // Auto advance after 2 seconds
            setTimeout(() => {
                if (ejercicioActual < CONFIG.totalEjercicios - 1) {
                    nextExercise();
                }
            }, 2000);
        }
        
        // Submit all exercises
        function submitExercises() {
            // Check all answered
            const unanswered = [];
            CONFIG.ejercicios.forEach((ej, i) => {
                if (!respuestas[i]) {
                    unanswered.push(i + 1);
                }
            });
            
            if (unanswered.length > 0) {
                Swal.fire({
                    icon: 'warning',
                    title: 'Faltan respuestas',
                    html: `No has respondido los ejercicios: <strong>${unanswered.join(', ')}</strong><br><br>¿Deseas enviar de todos modos?`,
                    showCancelButton: true,
                    confirmButtonText: 'Sí, enviar',
                    cancelButtonText: 'Cancelar'
                }).then((result) => {
                    if (result.isConfirmed) {
                        sendToAPI();
                    }
                });
            } else {
                sendToAPI();
            }
        }
        
        // Send to API
        function sendToAPI() {
            // Calculate score
            let puntaje = 0;
            let correctas = 0;
            
            CONFIG.ejercicios.forEach((ej, i) => {
                if (respuestas[i] === ej.respuesta_correcta) {
                    puntaje += parseInt(ej.puntos);
                    correctas++;
                }
            });
            
            const totalPosible = CONFIG.ejercicios.reduce((sum, ej) => sum + parseInt(ej.puntos), 0);
            const porcentaje = totalPosible > 0 ? Math.round((puntaje / totalPosible) * 100) : 0;
            
            $.post(CONFIG.apiUrl + 'guardar_ejercicios.php', {
                id_video: CONFIG.idVideo,
                id_leccion: CONFIG.idLeccion,
                id_estudiante: CONFIG.idEstudiante,
                respuestas: JSON.stringify(respuestas),
                puntaje: puntaje
            }, function(response) {
                if (response.success) {
                    // Show results
                    $('#scoreCircle').text(`${porcentaje}%`);
                    $('#scoreText').text(puntaje);
                    $('#totalScore').text(totalPosible);
                    $('#correctCount').text(correctas);
                    $('#incorrectCount').text(CONFIG.totalEjercicios - correctas);
                    $('#percentageText').text(`${porcentaje}%`);
                    
                    // Color code score
                    if (porcentaje >= 80) {
                        $('#scoreCircle').css('background', 'linear-gradient(135deg, #28a745, #20c997)');
                    } else if (porcentaje >= 60) {
                        $('#scoreCircle').css('background', 'linear-gradient(135deg, #ffc107, #fd7e14)');
                    } else {
                        $('#scoreCircle').css('background', 'linear-gradient(135deg, #dc3545, #c82333)');
                    }
                    
                    new bootstrap.Modal(document.getElementById('modalResultados')).show();
                    
                    // Show achievement if any
                    if (response.data.logros_obtenidos && response.data.logros_obtenidos.length > 0) {
                        setTimeout(() => {
                            Swal.fire({
                                icon: 'success',
                                title: '¡Logro Desbloqueado!',
                                text: response.data.logros_obtenidos.join(', '),
                                timer: 3000
                            });
                        }, 1000);
                    }
                } else {
                    Swal.fire({
                        icon: 'error',
                        title: 'Error',
                        text: response.message
                    });
                }
            }, 'json').fail(function() {
                Swal.fire({
                    icon: 'error',
                    title: 'Error de Conexión',
                    text: 'No se pudo guardar tu progreso. Verifica tu conexión.'
                });
            });
        }
    </script>
</body>
</html>