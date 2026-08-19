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

// ===== OBTENER DATOS DEL USUARIO =====
$id_estudiante = 0;
$nombre_usuario = 'Usuario';

if ($rol == 'estudiante') {
    $query = "SELECT e.id as id_estudiante, p.primer_nombre
              FROM tbl_estudiante e
              JOIN tbl_persona p ON e.id_persona = p.id
              WHERE p.id_usuario = :user_id AND e.id_institucion = :tid";
    $stmt = $db->prepare($query);
    $stmt->bindValue(':user_id', $user_id, PDO::PARAM_INT);
    $stmt->bindValue(':tid', $tid, PDO::PARAM_INT);
    $stmt->execute();
    $estudiante = $stmt->fetch(PDO::FETCH_ASSOC);
    $id_estudiante = $estudiante['id_estudiante'] ?? 0;
    $nombre_usuario = $estudiante['primer_nombre'] ?? 'Estudiante';
}

// ===== PARÁMETROS DE PRÁCTICA =====
$tipo_practica = $_GET['tipo'] ?? 'vocabulary'; // vocabulary, grammar, listening, speaking, reading, writing
$nivel_dificultad = $_GET['nivel'] ?? 'beginner'; // beginner, elementary, intermediate, advanced
$modo_practica = $_GET['modo'] ?? 'normal'; // normal, timed, challenge

// Configuración de tipos de práctica
$tipos_practica = [
    'vocabulary' => [
        'title' => '📚 Vocabulario',
        'subtitle' => 'Aprende nuevas palabras con flashcards interactivas',
        'icon' => 'fa-book-open',
        'color' => 'info',
        'gradient' => 'from-cyan-400 to-blue-600',
        'description' => 'Practica vocabulario esencial con imágenes, audio y ejemplos contextuales'
    ],
    'grammar' => [
        'title' => '✏️ Gramática',
        'subtitle' => 'Domina las reglas gramaticales con ejercicios prácticos',
        'icon' => 'fa-spell-check',
        'color' => 'primary',
        'gradient' => 'from-blue-400 to-indigo-600',
        'description' => 'Ejercicios de verbos, tiempos, preposiciones y estructuras'
    ],
    'listening' => [
        'title' => '🎧 Listening',
        'subtitle' => 'Mejora tu comprensión auditiva con audio nativo',
        'icon' => 'fa-headphones',
        'color' => 'danger',
        'gradient' => 'from-red-400 to-rose-600',
        'description' => 'Escucha conversaciones reales y responde preguntas de comprensión'
    ],
    'speaking' => [
        'title' => '🗣️ Speaking',
        'subtitle' => 'Practica tu pronunciación con reconocimiento de voz',
        'icon' => 'fa-microphone',
        'color' => 'success',
        'gradient' => 'from-green-400 to-emerald-600',
        'description' => 'Repite frases y recibe feedback instantáneo de pronunciación'
    ],
    'reading' => [
        'title' => '📖 Reading',
        'subtitle' => 'Desarrolla tu comprensión lectora con textos adaptados',
        'icon' => 'fa-book-reader',
        'color' => 'warning',
        'gradient' => 'from-amber-400 to-orange-600',
        'description' => 'Lee historias cortas y responde preguntas de comprensión'
    ],
    'writing' => [
        'title' => '✍️ Writing',
        'subtitle' => 'Mejora tu expresión escrita con ejercicios guiados',
        'icon' => 'fa-pen',
        'color' => 'purple',
        'gradient' => 'from-purple-400 to-violet-600',
        'description' => 'Escribe oraciones y párrafos con corrección automática'
    ]
];

$practica_actual = $tipos_practica[$tipo_practica] ?? $tipos_practica['vocabulary'];

// ===== OBTENER VOCABULARIO PARA PRÁCTICA =====
$vocabulario = [];
if ($tipo_practica == 'vocabulary') {
    try {
        // Verificar si existe la tabla
        $checkTable = $db->query("SHOW TABLES LIKE 'tbl_ingles_vocabulario'");
        if ($checkTable->rowCount() > 0) {
            $query = "SELECT * FROM tbl_ingles_vocabulario 
                      WHERE nivel = :nivel OR nivel IS NULL
                      ORDER BY RAND() 
                      LIMIT 20";
            $stmt = $db->prepare($query);
            $stmt->bindValue(':nivel', $nivel_dificultad, PDO::PARAM_STR);
            $stmt->execute();
            $vocabulario = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
    } catch (Exception $e) {
        error_log("Error vocabulario: " . $e->getMessage());
    }
    
    // Datos de ejemplo si la tabla está vacía
    if (empty($vocabulario)) {
        $vocabulario = [
            ['palabra_ingles' => 'Hello', 'palabra_espanol' => 'Hola', 'categoria' => 'Greetings', 'ejemplo_ingles' => 'Hello, how are you?', 'ejemplo_espanol' => 'Hola, ¿cómo estás?', 'audio_pronunciacion' => 'hello.mp3'],
            ['palabra_ingles' => 'Book', 'palabra_espanol' => 'Libro', 'categoria' => 'Objects', 'ejemplo_ingles' => 'I read a book every day.', 'ejemplo_espanol' => 'Leo un libro todos los días.', 'audio_pronunciacion' => 'book.mp3'],
            ['palabra_ingles' => 'Happy', 'palabra_espanol' => 'Feliz', 'categoria' => 'Emotions', 'ejemplo_ingles' => 'She is very happy today.', 'ejemplo_espanol' => 'Ella está muy feliz hoy.', 'audio_pronunciacion' => 'happy.mp3'],
            ['palabra_ingles' => 'Water', 'palabra_espanol' => 'Agua', 'categoria' => 'Food & Drink', 'ejemplo_ingles' => 'I drink water every morning.', 'ejemplo_espanol' => 'Bebo agua cada mañana.', 'audio_pronunciacion' => 'water.mp3'],
            ['palabra_ingles' => 'Friend', 'palabra_espanol' => 'Amigo', 'categoria' => 'People', 'ejemplo_ingles' => 'He is my best friend.', 'ejemplo_espanol' => 'Él es mi mejor amigo.', 'audio_pronunciacion' => 'friend.mp3'],
            ['palabra_ingles' => 'School', 'palabra_espanol' => 'Escuela', 'categoria' => 'Places', 'ejemplo_ingles' => 'I go to school every day.', 'ejemplo_espanol' => 'Voy a la escuela todos los días.', 'audio_pronunciacion' => 'school.mp3'],
            ['palabra_ingles' => 'Beautiful', 'palabra_espanol' => 'Hermoso', 'categoria' => 'Adjectives', 'ejemplo_ingles' => 'The sunset is beautiful.', 'ejemplo_espanol' => 'El atardecer es hermoso.', 'audio_pronunciacion' => 'beautiful.mp3'],
            ['palabra_ingles' => 'Tomorrow', 'palabra_espanol' => 'Mañana', 'categoria' => 'Time', 'ejemplo_ingles' => 'See you tomorrow!', 'ejemplo_espanol' => '¡Nos vemos mañana!', 'audio_pronunciacion' => 'tomorrow.mp3'],
            ['palabra_ingles' => 'Family', 'palabra_espanol' => 'Familia', 'categoria' => 'People', 'ejemplo_ingles' => 'I love my family.', 'ejemplo_espanol' => 'Amo a mi familia.', 'audio_pronunciacion' => 'family.mp3'],
            ['palabra_ingles' => 'Learn', 'palabra_espanol' => 'Aprender', 'categoria' => 'Verbs', 'ejemplo_ingles' => 'I want to learn English.', 'ejemplo_espanol' => 'Quiero aprender inglés.', 'audio_pronunciacion' => 'learn.mp3']
        ];
    }
}

// ===== EJERCICIOS DE GRAMÁTICA (ejemplo) =====
$ejercicios_grammar = [
    [
        'question' => 'Choose the correct verb: She ___ to school every day.',
        'options' => ['go', 'goes', 'going', 'gone'],
        'correct' => 'goes',
        'explanation' => 'Usamos "goes" para tercera persona del singular en presente simple.'
    ],
    [
        'question' => 'Complete: They ___ watching TV right now.',
        'options' => ['is', 'are', 'am', 'be'],
        'correct' => 'are',
        'explanation' => '"They" requiere el verbo "are" en presente continuo.'
    ],
    [
        'question' => 'Select the correct past tense: Yesterday, I ___ to the park.',
        'options' => ['go', 'goes', 'went', 'gone'],
        'correct' => 'went',
        'explanation' => 'El pasado de "go" es "went" (verbo irregular).'
    ]
];

// ===== CONFIGURACIÓN DE MODO =====
$modos_practica = [
    'normal' => ['label' => '🐢 Normal', 'time_limit' => null, 'description' => 'Practica a tu propio ritmo'],
    'timed' => ['label' => '⏱️ Contrarreloj', 'time_limit' => 300, 'description' => '5 minutos para completar'],
    'challenge' => ['label' => '🔥 Desafío', 'time_limit' => 180, 'description' => '3 minutos, máxima puntuación']
];

$modo_actual = $modos_practica[$modo_practica] ?? $modos_practica['normal'];
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $practica_actual['title'] ?> - English Plus</title>
    
    <!-- Bootstrap 5 -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Font Awesome -->
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <!-- Animate.css -->
    <link href="https://cdn.jsdelivr.net/npm/animate.css@4.1.1/animate.min.css" rel="stylesheet">
    <!-- Google Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    
    <style>
        :root {
            --primary: #58cc02;
            --primary-dark: #46a302;
            --secondary: #2b70c9;
            --accent: #ffc800;
            --success: #4ade80;
            --warning: #fbbf24;
            --danger: #f87171;
            --info: #38bdf8;
            --purple: #a78bfa;
            --dark: #1e293b;
            --light: #f8fafc;
            --gradient-primary: linear-gradient(135deg, #58cc02 0%, #46a302 100%);
            --shadow: 0 4px 6px -1px rgba(0,0,0,0.1);
            --shadow-lg: 0 10px 15px -3px rgba(0,0,0,0.1);
            --radius: 16px;
            --radius-lg: 24px;
        }
        
        * { margin: 0; padding: 0; box-sizing: border-box; }
        
        body {
            font-family: 'Poppins', 'Segoe UI', system-ui, sans-serif;
            background: linear-gradient(135deg, #f0f9ff 0%, #fef3c7 50%, #f0fdf4 100%);
            min-height: 100vh;
            color: var(--dark);
        }
        
        /* Header */
        .practice-header {
            background: var(--gradient-primary);
            color: white;
            padding: 25px 0;
            position: relative;
            overflow: hidden;
        }
        
        .practice-header::before {
            content: '';
            position: absolute;
            top: -50%;
            right: -20%;
            width: 500px;
            height: 500px;
            background: radial-gradient(circle, rgba(255,255,255,0.15) 0%, transparent 70%);
            border-radius: 50%;
            animation: float 20s ease-in-out infinite;
        }
        
        @keyframes float {
            0%, 100% { transform: translateY(0) rotate(0deg); }
            50% { transform: translateY(-30px) rotate(180deg); }
        }
        
        .practice-title {
            font-size: 1.8rem;
            font-weight: 700;
            margin-bottom: 5px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .practice-subtitle {
            font-size: 1rem;
            opacity: 0.9;
            margin-bottom: 15px;
        }
        
        .practice-meta {
            display: flex;
            gap: 20px;
            font-size: 0.9rem;
            opacity: 0.85;
        }
        
        /* Progress Bar */
        .progress-header {
            background: white;
            padding: 15px 0;
            position: sticky;
            top: 0;
            z-index: 1000;
            box-shadow: var(--shadow);
        }
        
        .progress-bar-duo {
            height: 10px;
            border-radius: 10px;
            background: #e2e8f0;
            overflow: hidden;
        }
        
        .progress-fill {
            height: 100%;
            background: var(--gradient-primary);
            border-radius: 10px;
            transition: width 0.5s ease;
        }
        
        .progress-info {
            display: flex;
            justify-content: space-between;
            font-size: 0.85rem;
            color: #64748b;
            margin-bottom: 8px;
        }
        
        /* Flashcard */
        .flashcard-container {
            perspective: 1000px;
            max-width: 500px;
            margin: 30px auto;
            cursor: pointer;
        }
        
        .flashcard {
            position: relative;
            width: 100%;
            height: 300px;
            transform-style: preserve-3d;
            transition: transform 0.6s ease;
            border-radius: var(--radius-lg);
        }
        
        .flashcard.flipped {
            transform: rotateY(180deg);
        }
        
        .flashcard-face {
            position: absolute;
            width: 100%;
            height: 100%;
            backface-visibility: hidden;
            border-radius: var(--radius-lg);
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            padding: 30px;
            text-align: center;
            box-shadow: var(--shadow-lg);
        }
        
        .flashcard-front {
            background: white;
            border: 3px solid var(--primary);
        }
        
        .flashcard-back {
            background: var(--gradient-primary);
            color: white;
            transform: rotateY(180deg);
        }
        
        .word-english {
            font-size: 2.5rem;
            font-weight: 700;
            margin-bottom: 15px;
            color: var(--dark);
        }
        
        .flashcard-back .word-english {
            color: white;
        }
        
        .word-spanish {
            font-size: 2rem;
            font-weight: 600;
            margin-bottom: 20px;
        }
        
        .word-category {
            padding: 5px 15px;
            border-radius: 20px;
            background: rgba(255,255,255,0.2);
            font-size: 0.85rem;
            margin-bottom: 15px;
        }
        
        .word-example {
            font-style: italic;
            opacity: 0.9;
            margin-bottom: 20px;
            line-height: 1.4;
        }
        
        .word-example-en {
            font-weight: 500;
        }
        
        .audio-btn {
            width: 50px;
            height: 50px;
            border-radius: 50%;
            background: white;
            border: none;
            color: var(--primary);
            font-size: 1.2rem;
            cursor: pointer;
            transition: all 0.2s;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-top: 10px;
        }
        
        .audio-btn:hover {
            transform: scale(1.1);
            box-shadow: 0 4px 15px rgba(0,0,0,0.2);
        }
        
        .flashcard-back .audio-btn {
            background: rgba(255,255,255,0.2);
            color: white;
        }
        
        /* Controls */
        .practice-controls {
            display: flex;
            justify-content: center;
            gap: 15px;
            margin: 30px 0;
            flex-wrap: wrap;
        }
        
        .btn-practice {
            padding: 15px 35px;
            border-radius: 14px;
            font-weight: 600;
            font-size: 1rem;
            display: flex;
            align-items: center;
            gap: 8px;
            transition: all 0.2s;
            border: none;
            cursor: pointer;
        }
        
        .btn-know {
            background: var(--gradient-primary);
            color: white;
        }
        
        .btn-know:hover {
            transform: translateY(-3px);
            box-shadow: 0 8px 25px rgba(88, 204, 2, 0.4);
        }
        
        .btn-learn {
            background: white;
            color: var(--dark);
            border: 2px solid #e2e8f0;
        }
        
        .btn-learn:hover {
            border-color: var(--primary);
            background: #f0fff0;
            transform: translateY(-3px);
        }
        
        .btn-skip {
            background: #f1f5f9;
            color: #64748b;
        }
        
        .btn-skip:hover {
            background: #e2e8f0;
        }
        
        /* Quiz Mode */
        .quiz-container {
            background: white;
            border-radius: var(--radius-lg);
            padding: 30px;
            max-width: 600px;
            margin: 30px auto;
            box-shadow: var(--shadow-lg);
        }
        
        .quiz-question {
            font-size: 1.3rem;
            font-weight: 600;
            margin-bottom: 25px;
            text-align: center;
        }
        
        .quiz-options {
            display: grid;
            gap: 12px;
        }
        
        .quiz-option {
            padding: 15px 20px;
            border: 2px solid #e2e8f0;
            border-radius: 12px;
            background: white;
            text-align: left;
            cursor: pointer;
            transition: all 0.2s;
            font-size: 1rem;
        }
        
        .quiz-option:hover {
            border-color: var(--primary);
            background: #f0fff0;
        }
        
        .quiz-option.selected {
            border-color: var(--primary);
            background: var(--primary);
            color: white;
        }
        
        .quiz-option.correct {
            border-color: var(--success);
            background: var(--success);
            color: white;
            animation: pulse 0.5s;
        }
        
        .quiz-option.incorrect {
            border-color: var(--danger);
            background: var(--danger);
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
        
        .quiz-feedback {
            margin-top: 20px;
            padding: 15px;
            border-radius: 12px;
            display: none;
        }
        
        .quiz-feedback.correct {
            background: #dcfce7;
            border: 2px solid #22c55e;
            color: #15803d;
        }
        
        .quiz-feedback.incorrect {
            background: #fee2e2;
            border: 2px solid #ef4444;
            color: #b91c1c;
        }
        
        /* Timer */
        .timer-badge {
            position: fixed;
            top: 20px;
            right: 20px;
            background: var(--danger);
            color: white;
            padding: 10px 20px;
            border-radius: 50px;
            font-weight: 600;
            font-size: 1.1rem;
            display: none;
            animation: pulse 2s infinite;
            z-index: 1001;
        }
        
        .timer-badge.warning {
            background: var(--warning);
            color: var(--dark);
            animation: none;
        }
        
        /* Results Modal */
        .results-summary {
            text-align: center;
            padding: 40px 20px;
        }
        
        .score-display {
            width: 180px;
            height: 180px;
            border-radius: 50%;
            background: var(--gradient-primary);
            color: white;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            margin: 0 auto 30px;
            box-shadow: 0 10px 40px rgba(88, 204, 2, 0.4);
        }
        
        .score-number {
            font-size: 3.5rem;
            font-weight: 700;
            line-height: 1;
        }
        
        .score-label {
            font-size: 1rem;
            opacity: 0.9;
        }
        
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 20px;
            margin: 30px 0;
        }
        
        .stat-item {
            text-align: center;
            padding: 20px;
            background: #f8fafc;
            border-radius: var(--radius);
        }
        
        .stat-value {
            font-size: 2rem;
            font-weight: 700;
            color: var(--dark);
        }
        
        .stat-label {
            font-size: 0.85rem;
            color: #64748b;
        }
        
        /* Category Tags */
        .category-tags {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
            margin-bottom: 20px;
            justify-content: center;
        }
        
        .category-tag {
            padding: 6px 16px;
            border-radius: 20px;
            background: white;
            font-size: 0.85rem;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.2s;
            border: 2px solid transparent;
        }
        
        .category-tag:hover,
        .category-tag.active {
            border-color: var(--primary);
            background: #f0fff0;
            color: var(--primary-dark);
        }
        
        /* Responsive */
        @media (max-width: 768px) {
            .practice-title { font-size: 1.4rem; }
            .flashcard { height: 250px; }
            .word-english { font-size: 2rem; }
            .word-spanish { font-size: 1.5rem; }
            .btn-practice { padding: 12px 25px; font-size: 0.9rem; }
            .stats-grid { grid-template-columns: 1fr; }
        }
        
        /* Animations */
        @keyframes fadeInUp {
            from { opacity: 0; transform: translateY(30px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        .animate-in {
            animation: fadeInUp 0.5s ease forwards;
        }
    </style>
</head>
<body>
    <!-- Timer Badge (for timed modes) -->
    <?php if ($modo_actual['time_limit']): ?>
    <div class="timer-badge" id="timerBadge">
        <i class="fas fa-clock"></i> <span id="timerDisplay">5:00</span>
    </div>
    <?php endif; ?>

    <!-- Header -->
    <header class="practice-header">
        <div class="container">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <a href="ingles_dashboard.php" class="btn btn-outline-light btn-sm mb-3">
                        <i class="fas fa-arrow-left"></i> Volver
                    </a>
                    <h1 class="practice-title">
                        <i class="fas <?= $practica_actual['icon'] ?>"></i>
                        <?= $practica_actual['title'] ?>
                    </h1>
                    <p class="practice-subtitle"><?= $practica_actual['subtitle'] ?></p>
                    <div class="practice-meta">
                        <span><i class="fas fa-graduation-cap"></i> <?= ucfirst($nivel_dificultad) ?></span>
                        <span><i class="fas fa-bolt"></i> <?= $modo_actual['label'] ?></span>
                        <span><i class="fas fa-user"></i> <?= htmlspecialchars($nombre_usuario) ?></span>
                    </div>
                </div>
                <div class="text-end">
                    <div class="badge bg-white text-dark p-3">
                        <i class="fas fa-star text-warning"></i>
                        <strong>XP: <span id="xpCounter">0</span></strong>
                    </div>
                </div>
            </div>
        </div>
    </header>

    <!-- Progress Bar -->
    <div class="progress-header">
        <div class="container">
            <div class="progress-info">
                <span>Progreso</span>
                <span><span id="currentWord">1</span>/<span id="totalWords"><?= count($vocabulario) ?></span></span>
            </div>
            <div class="progress-bar-duo">
                <div class="progress-fill" id="progressFill" style="width: 0%"></div>
            </div>
        </div>
    </div>

    <!-- Main Content -->
    <main class="container py-4">
        <!-- Category Filter (for vocabulary) -->
        <?php if ($tipo_practica == 'vocabulary'): ?>
        <div class="category-tags mb-4">
            <span class="category-tag active" data-category="all">Todas</span>
            <span class="category-tag" data-category="Greetings">Greetings</span>
            <span class="category-tag" data-category="Objects">Objects</span>
            <span class="category-tag" data-category="Emotions">Emotions</span>
            <span class="category-tag" data-category="Food & Drink">Food</span>
            <span class="category-tag" data-category="People">People</span>
            <span class="category-tag" data-category="Places">Places</span>
            <span class="category-tag" data-category="Verbs">Verbs</span>
        </div>
        <?php endif; ?>

        <!-- VOCABULARY MODE: Flashcards -->
        <?php if ($tipo_practica == 'vocabulary'): ?>
        <div id="flashcardMode">
            <div class="flashcard-container" onclick="flipCard()">
                <div class="flashcard" id="flashcard">
                    <!-- Front: English word -->
                    <div class="flashcard-face flashcard-front">
                        <span class="word-category" id="wordCategory">Category</span>
                        <div class="word-english" id="wordEnglish">Hello</div>
                        <button class="audio-btn" onclick="playAudio(event, 'hello')">
                            <i class="fas fa-volume-up"></i>
                        </button>
                        <p class="text-muted mt-3">👆 Toca para ver la traducción</p>
                    </div>
                    <!-- Back: Spanish translation -->
                    <div class="flashcard-face flashcard-back">
                        <span class="word-category" id="wordCategoryBack">Category</span>
                        <div class="word-spanish" id="wordSpanish">Hola</div>
                        <p class="word-example">
                            <span class="word-example-en" id="exampleEn">"Hello, how are you?"</span><br>
                            <span id="exampleEs">"Hola, ¿cómo estás?"</span>
                        </p>
                        <button class="audio-btn" onclick="playAudio(event, 'hello')">
                            <i class="fas fa-volume-up"></i>
                        </button>
                    </div>
                </div>
            </div>

            <!-- Controls -->
            <div class="practice-controls">
                <button class="btn-practice btn-learn" onclick="markWord('dont-know')">
                    <i class="fas fa-times"></i> Aún no la sé
                </button>
                <button class="btn-practice btn-know" onclick="markWord('know')">
                    <i class="fas fa-check"></i> ¡La sé! +10 XP
                </button>
                <button class="btn-practice btn-skip" onclick="skipWord()">
                    <i class="fas fa-forward"></i> Saltar
                </button>
            </div>

            <!-- Word Info -->
            <div class="text-center text-muted">
                <small>Palabra <span id="wordIndex">1</span> de <span id="wordTotal"><?= count($vocabulario) ?></span></small>
            </div>
        </div>
        <?php endif; ?>

        <!-- GRAMMAR MODE: Quiz -->
        <?php if ($tipo_practica == 'grammar'): ?>
        <div id="quizMode" class="quiz-container animate-in">
            <div class="quiz-question" id="quizQuestion">
                Choose the correct verb: She ___ to school every day.
            </div>
            <div class="quiz-options" id="quizOptions">
                <!-- Options will be loaded dynamically -->
            </div>
            <div class="quiz-feedback" id="quizFeedback">
                <strong id="feedbackTitle"></strong>
                <p class="mb-0" id="feedbackMessage"></p>
            </div>
            <div class="d-flex justify-content-center mt-4">
                <button class="btn btn-primary btn-lg px-5" id="btnNextQuestion" onclick="nextQuestion()" style="display: none;">
                    Siguiente <i class="fas fa-arrow-right"></i>
                </button>
            </div>
        </div>
        <?php endif; ?>

        <!-- LISTENING MODE: Audio Quiz -->
        <?php if ($tipo_practica == 'listening'): ?>
        <div class="quiz-container animate-in text-center">
            <div class="mb-4">
                <i class="fas fa-headphones fa-4x text-primary mb-3"></i>
                <h4>Escucha y selecciona lo que oyes</h4>
            </div>
            <button class="btn btn-lg btn-primary mb-4" onclick="playListeningAudio()">
                <i class="fas fa-play"></i> ▶ Reproducir Audio
            </button>
            <div class="quiz-options">
                <button class="quiz-option" onclick="selectListeningAnswer('Hello, how are you?')">Hello, how are you?</button>
                <button class="quiz-option" onclick="selectListeningAnswer('Where are you going?')">Where are you going?</button>
                <button class="quiz-option" onclick="selectListeningAnswer('What time is it?')">What time is it?</button>
                <button class="quiz-option" onclick="selectListeningAnswer('I love learning English!')">I love learning English!</button>
            </div>
            <div class="quiz-feedback mt-3" id="listeningFeedback"></div>
        </div>
        <?php endif; ?>

        <!-- SPEAKING MODE: Voice Practice -->
        <?php if ($tipo_practica == 'speaking'): ?>
        <div class="quiz-container animate-in text-center">
            <div class="mb-4">
                <i class="fas fa-microphone fa-4x text-success mb-3"></i>
                <h4>Repite la frase en voz alta</h4>
            </div>
            <div class="bg-light p-4 rounded mb-4">
                <p class="fs-4 fw-bold mb-0" id="speakingPhrase">"Hello, my name is..."</p>
            </div>
            <button class="btn btn-lg btn-success mb-3" id="btnRecord" onclick="toggleRecording()">
                <i class="fas fa-microphone"></i> <span id="recordText">Grabar mi voz</span>
            </button>
            <div id="recordingStatus" class="text-muted small"></div>
            <div class="quiz-feedback mt-3" id="speakingFeedback"></div>
            <button class="btn btn-outline-primary mt-3" onclick="nextSpeakingPhrase()">
                Siguiente frase <i class="fas fa-arrow-right"></i>
            </button>
        </div>
        <?php endif; ?>

        <!-- READING MODE: Text Comprehension -->
        <?php if ($tipo_practica == 'reading'): ?>
        <div class="quiz-container animate-in">
            <h5 class="mb-3">📖 Lee el texto y responde</h5>
            <div class="bg-light p-4 rounded mb-4" style="max-height: 200px; overflow-y: auto;">
                <p class="mb-2"><strong>My Daily Routine</strong></p>
                <p class="mb-0">I wake up at 7 AM every morning. First, I brush my teeth and take a shower. Then, I have breakfast with my family. After breakfast, I go to school by bus. At school, I learn English, math, and science. I like English class the most because it's fun! After school, I do my homework and play with my friends. In the evening, I have dinner and watch TV before bed.</p>
            </div>
            <div class="quiz-question">What time does the person wake up?</div>
            <div class="quiz-options">
                <button class="quiz-option" onclick="selectReadingAnswer('6 AM')">6 AM</button>
                <button class="quiz-option" onclick="selectReadingAnswer('7 AM')">7 AM ✓</button>
                <button class="quiz-option" onclick="selectReadingAnswer('8 AM')">8 AM</button>
                <button class="quiz-option" onclick="selectReadingAnswer('9 AM')">9 AM</button>
            </div>
            <div class="quiz-feedback mt-3" id="readingFeedback"></div>
        </div>
        <?php endif; ?>

        <!-- WRITING MODE: Sentence Builder -->
        <?php if ($tipo_practica == 'writing'): ?>
        <div class="quiz-container animate-in">
            <h5 class="mb-3">✍️ Construye la oración</h5>
            <p class="text-muted mb-4">Ordena las palabras para formar una oración correcta:</p>
            <div class="d-flex flex-wrap gap-2 mb-4 justify-content-center" id="wordBank">
                <span class="badge bg-light text-dark p-3" style="cursor: move;" draggable="true" data-word="I">I</span>
                <span class="badge bg-light text-dark p-3" style="cursor: move;" draggable="true" data-word="love">love</span>
                <span class="badge bg-light text-dark p-3" style="cursor: move;" draggable="true" data-word="learning">learning</span>
                <span class="badge bg-light text-dark p-3" style="cursor: move;" draggable="true" data-word="English">English</span>
                <span class="badge bg-light text-dark p-3" style="cursor: move;" draggable="true" data-word="!">!</span>
            </div>
            <div class="border rounded p-3 mb-3 min-vh-10" id="answerArea" style="min-height: 60px;">
                <span class="text-muted small">Arrastra las palabras aquí...</span>
            </div>
            <button class="btn btn-primary" onclick="checkWritingAnswer()">
                <i class="fas fa-check"></i> Verificar
            </button>
            <div class="quiz-feedback mt-3" id="writingFeedback"></div>
        </div>
        <?php endif; ?>

        <!-- Navigation for all modes -->
        <div class="text-center mt-5">
            <button class="btn btn-outline-secondary" onclick="changePracticeType()">
                <i class="fas fa-sync"></i> Cambiar tipo de práctica
            </button>
        </div>
    </main>

    <!-- Results Modal -->
    <div class="modal fade" id="modalResults" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header bg-success text-white">
                    <h5 class="modal-title">
                        <i class="fas fa-trophy"></i> ¡Práctica Completada!
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="results-summary">
                        <div class="score-display">
                            <span class="score-number" id="finalScore">0</span>
                            <span class="score-label">XP Ganados</span>
                        </div>
                        <h4 class="mb-4">¡Excelente trabajo, <?= htmlspecialchars($nombre_usuario) ?>! 🎉</h4>
                        
                        <div class="stats-grid">
                            <div class="stat-item">
                                <div class="stat-value text-success" id="correctCount">0</div>
                                <div class="stat-label">Correctas</div>
                            </div>
                            <div class="stat-item">
                                <div class="stat-value text-danger" id="incorrectCount">0</div>
                                <div class="stat-label">Incorrectas</div>
                            </div>
                            <div class="stat-item">
                                <div class="stat-value text-primary" id="streakCount">0</div>
                                <div class="stat-label">Racha Máx</div>
                            </div>
                        </div>
                        
                        <div class="d-grid gap-2">
                            <button class="btn btn-success btn-lg" onclick="restartPractice()">
                                <i class="fas fa-redo"></i> Practicar de Nuevo
                            </button>
                            <a href="ingles_dashboard.php" class="btn btn-outline-primary">
                                <i class="fas fa-home"></i> Volver al Dashboard
                            </a>
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
    <!-- SortableJS for writing mode -->
    <script src="https://cdn.jsdelivr.net/npm/sortablejs@1.15.0/Sortable.min.js"></script>
    
    <script>
        const CONFIG = {
            tipo: '<?= $tipo_practica ?>',
            nivel: '<?= $nivel_dificultad ?>',
            modo: '<?= $modo_practica ?>',
            idEstudiante: <?= $id_estudiante ?>,
            vocabulario: <?= json_encode($vocabulario) ?>,
            ejerciciosGrammar: <?= json_encode($ejercicios_grammar) ?>,
            apiUrl: '../api/ingles/',
            timeLimit: <?= $modo_actual['time_limit'] ?? 'null' ?>
        };
        
        // ===== VOCABULARY MODE VARIABLES =====
        let currentIndex = 0;
        let xpEarned = 0;
        let correctCount = 0;
        let incorrectCount = 0;
        let currentStreak = 0;
        let maxStreak = 0;
        let filteredVocab = [...CONFIG.vocabulario];
        
        // ===== TIMER VARIABLES =====
        let timerInterval = null;
        let timeRemaining = CONFIG.timeLimit ? CONFIG.timeLimit * 60 : null;
        
        // ===== INITIALIZATION =====
        $(document).ready(function() {
            // Load first word
            if (CONFIG.tipo === 'vocabulary' && filteredVocab.length > 0) {
                loadWord(0);
            }
            
            // Load grammar quiz
            if (CONFIG.tipo === 'grammar' && CONFIG.ejerciciosGrammar.length > 0) {
                loadGrammarQuestion(0);
            }
            
            // Category filter
            $('.category-tag').click(function() {
                $('.category-tag').removeClass('active');
                $(this).addClass('active');
                filterVocabulary($(this).data('category'));
            });
            
            // Start timer if in timed mode
            if (CONFIG.timeLimit) {
                $('#timerBadge').show();
                startTimer();
            }
            
            // Keyboard shortcuts
            $(document).keydown(function(e) {
                if (CONFIG.tipo === 'vocabulary') {
                    if (e.key === ' ') {
                        e.preventDefault();
                        flipCard();
                    } else if (e.key === 'ArrowRight') {
                        markWord('know');
                    } else if (e.key === 'ArrowLeft') {
                        markWord('dont-know');
                    }
                }
            });
            
            // Initialize Sortable for writing mode
            if (CONFIG.tipo === 'writing') {
                initWritingMode();
            }
        });
        
        // ===== VOCABULARY FUNCTIONS =====
        function loadWord(index) {
            if (index >= filteredVocab.length) {
                showResults();
                return;
            }
            
            const word = filteredVocab[index];
            $('#wordEnglish').text(word.palabra_ingles);
            $('#wordSpanish').text(word.palabra_espanol);
            $('#wordCategory').text(word.categoria || 'General');
            $('#wordCategoryBack').text(word.categoria || 'General');
            $('#exampleEn').text(`"${word.ejemplo_ingles || ''}"`);
            $('#exampleEs').text(`"${word.ejemplo_espanol || ''}"`);
            
            // Reset card
            $('#flashcard').removeClass('flipped');
            
            // Update progress
            $('#currentWord').text(index + 1);
            $('#wordIndex').text(index + 1);
            $('#wordTotal').text(filteredVocab.length);
            $('#progressFill').css('width', `${((index) / filteredVocab.length) * 100}%`);
            
            currentIndex = index;
        }
        
        function flipCard() {
            $('#flashcard').toggleClass('flipped');
        }
        
        function playAudio(event, word) {
            event.stopPropagation();
            // In production, use Web Speech API or actual audio files
            if ('speechSynthesis' in window) {
                const utterance = new SpeechSynthesisUtterance(word);
                utterance.lang = 'en-US';
                speechSynthesis.speak(utterance);
            } else {
                Swal.fire({
                    icon: 'info',
                    title: '🔊 Audio',
                    text: `Pronunciación: ${word}`,
                    timer: 2000
                });
            }
        }
        
        function markWord(result) {
            if (result === 'know') {
                xpEarned += 10;
                correctCount++;
                currentStreak++;
                maxStreak = Math.max(maxStreak, currentStreak);
                
                // Visual feedback
                $('#flashcard').addClass('flipped');
                setTimeout(() => {
                    showFeedback('¡Correcto! +10 XP 🎉', 'success');
                }, 300);
            } else {
                incorrectCount++;
                currentStreak = 0;
                showFeedback('¡Sigue practicando! 💪', 'info');
            }
            
            // Update XP counter
            $('#xpCounter').text(xpEarned);
            
            // Next word after delay
            setTimeout(() => {
                loadWord(currentIndex + 1);
            }, 1500);
        }
        
        function skipWord() {
            loadWord(currentIndex + 1);
        }
        
        function filterVocabulary(category) {
            if (category === 'all') {
                filteredVocab = [...CONFIG.vocabulario];
            } else {
                filteredVocab = CONFIG.vocabulario.filter(w => w.categoria === category);
            }
            currentIndex = 0;
            loadWord(0);
        }
        
        function showFeedback(message, type) {
            // Could show a toast or inline message
            console.log(`${type}: ${message}`);
        }
        
        // ===== GRAMMAR FUNCTIONS =====
        let currentGrammarIndex = 0;
        
        function loadGrammarQuestion(index) {
            if (index >= CONFIG.ejerciciosGrammar.length) {
                showResults();
                return;
            }
            
            const question = CONFIG.ejerciciosGrammar[index];
            $('#quizQuestion').text(question.question);
            
            const optionsHtml = question.options.map(opt => 
                `<button class="quiz-option" onclick="selectGrammarAnswer('${opt.replace(/'/g, "\\'")}')">${opt}</button>`
            ).join('');
            $('#quizOptions').html(optionsHtml);
            
            $('#quizFeedback').hide();
            $('#btnNextQuestion').hide();
        }
        
        function selectGrammarAnswer(answer) {
            const question = CONFIG.ejerciciosGrammar[currentGrammarIndex];
            const isCorrect = answer === question.correct;
            
            // Visual feedback
            $('.quiz-option').each(function() {
                if ($(this).text() === answer) {
                    $(this).addClass(isCorrect ? 'correct' : 'incorrect');
                }
                if ($(this).text() === question.correct) {
                    $(this).addClass('correct');
                }
            });
            
            // Show feedback
            $('#feedbackTitle').text(isCorrect ? '✓ ¡Correcto!' : '✗ Incorrecto');
            $('#feedbackMessage').text(question.explanation);
            $('#quizFeedback').removeClass('correct incorrect').addClass(isCorrect ? 'correct' : 'incorrect').fadeIn();
            $('#btnNextQuestion').show();
            
            // Update score
            if (isCorrect) {
                xpEarned += 15;
                correctCount++;
                currentStreak++;
                maxStreak = Math.max(maxStreak, currentStreak);
            } else {
                incorrectCount++;
                currentStreak = 0;
            }
            
            $('#xpCounter').text(xpEarned);
        }
        
        function nextQuestion() {
            currentGrammarIndex++;
            loadGrammarQuestion(currentGrammarIndex);
        }
        
        // ===== LISTENING FUNCTIONS =====
        function playListeningAudio() {
            // Simulate audio playback
            Swal.fire({
                icon: 'info',
                title: '🎧 Escuchando...',
                text: '"Hello, how are you today?"',
                timer: 3000,
                showConfirmButton: false
            });
            
            setTimeout(() => {
                Swal.fire({
                    icon: 'question',
                    title: '¿Qué escuchaste?',
                    text: 'Selecciona la opción correcta',
                    confirmButtonText: 'Entendido'
                });
            }, 3000);
        }
        
        function selectListeningAnswer(answer) {
            const correct = 'Hello, how are you?';
            const isCorrect = answer === correct;
            
            $('#listeningFeedback').removeClass('correct incorrect')
                .addClass(isCorrect ? 'correct' : 'incorrect')
                .html(`<strong>${isCorrect ? '✓ Correcto' : '✗ Intenta de nuevo'}</strong><br>${isCorrect ? '+10 XP' : 'La respuesta era: "Hello, how are you?"'}`)
                .fadeIn();
            
            if (isCorrect) {
                xpEarned += 10;
                correctCount++;
                $('#xpCounter').text(xpEarned);
            } else {
                incorrectCount++;
            }
        }
        
        // ===== SPEAKING FUNCTIONS =====
        let isRecording = false;
        const speakingPhrases = [
            "Hello, my name is...",
            "I am learning English.",
            "Today is a beautiful day.",
            "I love to practice speaking."
        ];
        let currentSpeakingIndex = 0;
        
        function toggleRecording() {
            if (!('webkitSpeechRecognition' in window) && !('SpeechRecognition' in window)) {
                Swal.fire({
                    icon: 'warning',
                    title: 'Reconocimiento de voz no disponible',
                    text: 'Tu navegador no soporta reconocimiento de voz. Practica repitiendo en voz alta.',
                    confirmButtonText: 'Entendido'
                });
                return;
            }
            
            if (isRecording) {
                stopRecording();
            } else {
                startRecording();
            }
        }
        
        function startRecording() {
            isRecording = true;
            $('#btnRecord').html('<i class="fas fa-stop"></i> Detener').removeClass('btn-success').addClass('btn-danger');
            $('#recordText').text('Grabando...');
            $('#recordingStatus').text('🎤 Habla ahora...');
            
            // In production, use Web Speech API here
            setTimeout(() => {
                stopRecording();
                simulateSpeechRecognition();
            }, 5000);
        }
        
        function stopRecording() {
            isRecording = false;
            $('#btnRecord').html('<i class="fas fa-microphone"></i> Grabar mi voz').removeClass('btn-danger').addClass('btn-success');
            $('#recordText').text('Grabar mi voz');
            $('#recordingStatus').text('');
        }
        
        function simulateSpeechRecognition() {
            // Simulate recognition result
            const phrases = ["hello", "english", "learning", "practice"];
            const randomPhrase = phrases[Math.floor(Math.random() * phrases.length)];
            
            $('#speakingFeedback').removeClass('correct incorrect').addClass('correct')
                .html(`<strong>✓ Buen intento!</strong><br>Palabra detectada: "${randomPhrase}"<br>+5 XP`)
                .fadeIn();
            
            xpEarned += 5;
            correctCount++;
            $('#xpCounter').text(xpEarned);
        }
        
        function nextSpeakingPhrase() {
            currentSpeakingIndex = (currentSpeakingIndex + 1) % speakingPhrases.length;
            $('#speakingPhrase').text(`"${speakingPhrases[currentSpeakingIndex]}"`);
            $('#speakingFeedback').fadeOut();
        }
        
        // ===== READING FUNCTIONS =====
        function selectReadingAnswer(answer) {
            const correct = '7 AM';
            const isCorrect = answer === correct;
            
            $('#readingFeedback').removeClass('correct incorrect')
                .addClass(isCorrect ? 'correct' : 'incorrect')
                .html(`<strong>${isCorrect ? '✓ Correcto' : '✗ Incorrecto'}</strong><br>${isCorrect ? '+10 XP' : 'Relee el texto: "I wake up at 7 AM"'}`)
                .fadeIn();
            
            if (isCorrect) {
                xpEarned += 10;
                correctCount++;
                $('#xpCounter').text(xpEarned);
            } else {
                incorrectCount++;
            }
        }
        
        // ===== WRITING FUNCTIONS =====
        function initWritingMode() {
            const answerArea = document.getElementById('answerArea');
            const wordBank = document.getElementById('wordBank');
            
            // Make answer area droppable
            new Sortable(answerArea, {
                group: 'words',
                animation: 150
            });
            
            // Make word bank sortable
            new Sortable(wordBank, {
                group: 'words',
                animation: 150
            });
        }
        
        function checkWritingAnswer() {
            const answerArea = document.getElementById('answerArea');
            const words = Array.from(answerArea.querySelectorAll('[data-word]')).map(el => el.dataset.word);
            const userAnswer = words.join(' ').trim();
            const correctAnswer = 'I love learning English !';
            
            const isCorrect = userAnswer.replace(/\s+/g, ' ') === correctAnswer.replace(/\s+/g, ' ');
            
            $('#writingFeedback').removeClass('correct incorrect')
                .addClass(isCorrect ? 'correct' : 'incorrect')
                .html(`<strong>${isCorrect ? '✓ ¡Perfecto!' : '✗ Casi'}</strong><br>${isCorrect ? '+15 XP' : 'Respuesta: "I love learning English!"'}`)
                .fadeIn();
            
            if (isCorrect) {
                xpEarned += 15;
                correctCount++;
                $('#xpCounter').text(xpEarned);
            } else {
                incorrectCount++;
            }
        }
        
        // ===== TIMER FUNCTIONS =====
        function startTimer() {
            updateTimerDisplay();
            timerInterval = setInterval(() => {
                timeRemaining--;
                updateTimerDisplay();
                
                if (timeRemaining <= 30) {
                    $('#timerBadge').addClass('warning');
                }
                
                if (timeRemaining <= 0) {
                    clearInterval(timerInterval);
                    showResults();
                }
            }, 1000);
        }
        
        function updateTimerDisplay() {
            const minutes = Math.floor(timeRemaining / 60);
            const seconds = timeRemaining % 60;
            $('#timerDisplay').text(`${minutes}:${seconds.toString().padStart(2, '0')}`);
        }
        
        // ===== RESULTS & NAVIGATION =====
        function showResults() {
            if (timerInterval) clearInterval(timerInterval);
            
            const percentage = filteredVocab.length > 0 ? Math.round((correctCount / filteredVocab.length) * 100) : 0;
            
            $('#finalScore').text(xpEarned);
            $('#correctCount').text(correctCount);
            $('#incorrectCount').text(incorrectCount);
            $('#streakCount').text(maxStreak);
            
            // Color code the score
            const scoreCircle = $('.score-display');
            if (percentage >= 80) {
                scoreCircle.css('background', 'linear-gradient(135deg, #22c55e, #16a34a)');
            } else if (percentage >= 60) {
                scoreCircle.css('background', 'linear-gradient(135deg, #fbbf24, #f59e0b)');
            } else {
                scoreCircle.css('background', 'linear-gradient(135deg, #f87171, #ef4444)');
            }
            
            new bootstrap.Modal(document.getElementById('modalResults')).show();
            
            // Save progress to API
            saveProgress();
        }
        
        function saveProgress() {
            $.post(CONFIG.apiUrl + 'guardar_practica.php', {
                tipo: CONFIG.tipo,
                id_estudiante: CONFIG.idEstudiante,
                xp_ganado: xpEarned,
                correctas: correctCount,
                incorrectas: incorrectCount,
                nivel: CONFIG.nivel
            }, function(response) {
                console.log('Progreso guardado:', response);
            }, 'json');
        }
        
        function restartPractice() {
            // Reset variables
            currentIndex = 0;
            xpEarned = 0;
            correctCount = 0;
            incorrectCount = 0;
            currentStreak = 0;
            maxStreak = 0;
            timeRemaining = CONFIG.timeLimit ? CONFIG.timeLimit * 60 : null;
            
            // Reset UI
            $('#xpCounter').text('0');
            $('#progressFill').css('width', '0%');
            $('#timerBadge').removeClass('warning');
            
            // Reload content
            if (CONFIG.tipo === 'vocabulary') {
                loadWord(0);
            } else if (CONFIG.tipo === 'grammar') {
                currentGrammarIndex = 0;
                loadGrammarQuestion(0);
            }
            
            // Restart timer if needed
            if (CONFIG.timeLimit) {
                startTimer();
            }
            
            // Close modal
            bootstrap.Modal.getInstance(document.getElementById('modalResults')).hide();
        }
        
        function changePracticeType() {
            const types = Object.keys(CONFIG.tipos_practica || {});
            const current = CONFIG.tipo;
            const next = types[(types.indexOf(current) + 1) % types.length];
            window.location.href = `practica.php?tipo=${next}&nivel=${CONFIG.nivel}`;
        }
    </script>
</body>
</html>