<?php
session_start();
require_once __DIR__ . '/../../config/database.php';

// Verificar autenticación
if (!isset($_SESSION['user_id'])) {
    header("Location: " . BASE_URL . "/login.php");
    exit;
}

$database = new Database();
$db = $database->getConnection();
$user_id = $_SESSION['user_id'];
$rol = $_SESSION['rol'];

// ===== OBTENER DATOS DEL ESTUDIANTE =====
$id_estudiante = 0;
$nombre_usuario = 'Usuario';
$avatar_inicial = 'U';

if ($rol == 'estudiante') {
    $query = "SELECT e.id as id_estudiante, p.primer_nombre, p.primer_apellido
              FROM tbl_estudiante e
              JOIN tbl_persona p ON e.id_persona = p.id
              WHERE p.id_usuario = :user_id";
    $stmt = $db->prepare($query);
    $stmt->bindValue(':user_id', $user_id, PDO::PARAM_INT);
    $stmt->execute();
    $estudiante = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($estudiante) {
        $id_estudiante = $estudiante['id_estudiante'];
        $nombre_usuario = $estudiante['primer_nombre'];
        $avatar_inicial = strtoupper(substr($estudiante['primer_nombre'], 0, 1));
    }
}

// ===== CONFIGURACIÓN DE LOGROS =====
// Categorías de logros
$categorias_logros = [
    'all' => ['label' => '🏆 Todos', 'icon' => 'fa-trophy'],
    'learning' => ['label' => '📚 Aprendizaje', 'icon' => 'fa-book'],
    'streak' => ['label' => '🔥 Rachas', 'icon' => 'fa-fire'],
    'speed' => ['label' => '⚡ Velocidad', 'icon' => 'fa-bolt'],
    'perfection' => ['label' => '💎 Perfección', 'icon' => 'fa-gem'],
    'social' => ['label' => '👥 Social', 'icon' => 'fa-users'],
    'special' => ['label' => '⭐ Especiales', 'icon' => 'fa-star']
];

// Logros del sistema (definición)
$definicion_logros = [
    // Logros de Aprendizaje
    'first_steps' => [
        'id' => 'first_steps',
        'name' => 'Primeros Pasos',
        'description' => 'Completa tu primera lección de inglés',
        'icon' => '👣',
        'category' => 'learning',
        'rarity' => 'common',
        'xp_reward' => 50,
        'condition' => ['type' => 'lessons_completed', 'value' => 1]
    ],
    'word_explorer' => [
        'id' => 'word_explorer',
        'name' => 'Explorador de Palabras',
        'description' => 'Aprende 50 palabras nuevas de vocabulario',
        'icon' => '📖',
        'category' => 'learning',
        'rarity' => 'common',
        'xp_reward' => 100,
        'condition' => ['type' => 'words_learned', 'value' => 50]
    ],
    'grammar_master' => [
        'id' => 'grammar_master',
        'name' => 'Maestro de Gramática',
        'description' => 'Completa 20 ejercicios de gramática con 90%+ de precisión',
        'icon' => '✏️',
        'category' => 'learning',
        'rarity' => 'rare',
        'xp_reward' => 250,
        'condition' => ['type' => 'grammar_accuracy', 'value' => 90, 'count' => 20]
    ],
    'polyglot' => [
        'id' => 'polyglot',
        'name' => 'Políglota',
        'description' => 'Completa lecciones en 5 categorías diferentes',
        'icon' => '🌍',
        'category' => 'learning',
        'rarity' => 'epic',
        'xp_reward' => 500,
        'condition' => ['type' => 'categories_completed', 'value' => 5]
    ],
    
    // Logros de Rachas
    'week_warrior' => [
        'id' => 'week_warrior',
        'name' => 'Guerrero Semanal',
        'description' => 'Practica inglés 7 días seguidos',
        'icon' => '🔥',
        'category' => 'streak',
        'rarity' => 'rare',
        'xp_reward' => 200,
        'condition' => ['type' => 'streak_days', 'value' => 7]
    ],
    'month_champion' => [
        'id' => 'month_champion',
        'name' => 'Campeón Mensual',
        'description' => 'Mantén una racha de 30 días de práctica',
        'icon' => '🏆',
        'category' => 'streak',
        'rarity' => 'epic',
        'xp_reward' => 500,
        'condition' => ['type' => 'streak_days', 'value' => 30]
    ],
    'year_legend' => [
        'id' => 'year_legend',
        'name' => 'Leyenda Anual',
        'description' => 'Practica inglés 365 días en un año',
        'icon' => '👑',
        'category' => 'streak',
        'rarity' => 'legendary',
        'xp_reward' => 2000,
        'condition' => ['type' => 'streak_days', 'value' => 365]
    ],
    
    // Logros de Velocidad
    'speed_learner' => [
        'id' => 'speed_learner',
        'name' => 'Aprendiz Veloz',
        'description' => 'Completa una lección en menos de 2 minutos con 100% de precisión',
        'icon' => '⚡',
        'category' => 'speed',
        'rarity' => 'rare',
        'xp_reward' => 150,
        'condition' => ['type' => 'lesson_time', 'value' => 120, 'accuracy' => 100]
    ],
    'quick_thinker' => [
        'id' => 'quick_thinker',
        'name' => 'Pensador Rápido',
        'description' => 'Responde 10 preguntas consecutivas en menos de 5 segundos cada una',
        'icon' => '🧠',
        'category' => 'speed',
        'rarity' => 'epic',
        'xp_reward' => 300,
        'condition' => ['type' => 'quick_answers', 'value' => 10, 'time_limit' => 5]
    ],
    
    // Logros de Perfección
    'perfect_score' => [
        'id' => 'perfect_score',
        'name' => 'Puntuación Perfecta',
        'description' => 'Obtén 100% en una lección completa',
        'icon' => '💯',
        'category' => 'perfection',
        'rarity' => 'rare',
        'xp_reward' => 200,
        'condition' => ['type' => 'lesson_accuracy', 'value' => 100]
    ],
    'flawless_victory' => [
        'id' => 'flawless_victory',
        'name' => 'Victoria Impecable',
        'description' => 'Completa 5 lecciones seguidas sin ningún error',
        'icon' => '✨',
        'category' => 'perfection',
        'rarity' => 'epic',
        'xp_reward' => 400,
        'condition' => ['type' => 'perfect_lessons', 'value' => 5]
    ],
    'master_of_all' => [
        'id' => 'master_of_all',
        'name' => 'Maestro Total',
        'description' => 'Alcanza el nivel máximo en todas las categorías',
        'icon' => '👑',
        'category' => 'perfection',
        'rarity' => 'legendary',
        'xp_reward' => 1500,
        'condition' => ['type' => 'all_categories_max', 'value' => 1]
    ],
    
    // Logros Sociales
    'helpful_friend' => [
        'id' => 'helpful_friend',
        'name' => 'Amigo Servicial',
        'description' => 'Ayuda a 10 compañeros a completar una lección',
        'icon' => '🤝',
        'category' => 'social',
        'rarity' => 'rare',
        'xp_reward' => 150,
        'condition' => ['type' => 'helps_given', 'value' => 10]
    ],
    'study_buddy' => [
        'id' => 'study_buddy',
        'name' => 'Compañero de Estudio',
        'description' => 'Completa 5 lecciones en modo cooperativo',
        'icon' => '👥',
        'category' => 'social',
        'rarity' => 'epic',
        'xp_reward' => 300,
        'condition' => ['type' => 'cooperative_lessons', 'value' => 5]
    ],
    
    // Logros Especiales
    'early_bird' => [
        'id' => 'early_bird',
        'name' => 'Pájaro Madrugador',
        'description' => 'Practica inglés antes de las 7 AM durante 10 días',
        'icon' => '🌅',
        'category' => 'special',
        'rarity' => 'rare',
        'xp_reward' => 200,
        'condition' => ['type' => 'early_practice', 'value' => 10, 'time' => '07:00']
    ],
    'night_owl' => [
        'id' => 'night_owl',
        'name' => 'Búho Nocturno',
        'description' => 'Practica inglés después de las 10 PM durante 10 días',
        'icon' => '🦉',
        'category' => 'special',
        'rarity' => 'rare',
        'xp_reward' => 200,
        'condition' => ['type' => 'late_practice', 'value' => 10, 'time' => '22:00']
    ],
    'birthday_bonus' => [
        'id' => 'birthday_bonus',
        'name' => 'Bonus de Cumpleaños',
        'description' => 'Practica inglés en tu cumpleaños',
        'icon' => '🎂',
        'category' => 'special',
        'rarity' => 'epic',
        'xp_reward' => 500,
        'condition' => ['type' => 'birthday_practice', 'value' => 1]
    ],
    'marathon' => [
        'id' => 'marathon',
        'name' => 'Maratón de Aprendizaje',
        'description' => 'Practica inglés durante 2 horas seguidas',
        'icon' => '🏃',
        'category' => 'special',
        'rarity' => 'epic',
        'xp_reward' => 400,
        'condition' => ['type' => 'continuous_practice', 'value' => 120]
    ]
];

// ===== OBTENER PROGRESO DEL ESTUDIANTE =====
$estadisticas = [
    'lessons_completed' => 0,
    'words_learned' => 0,
    'grammar_accuracy' => 0,
    'categories_completed' => 0,
    'streak_days' => 0,
    'current_streak' => 0,
    'perfect_lessons' => 0,
    'quick_answers' => 0,
    'helps_given' => 0,
    'cooperative_lessons' => 0,
    'early_practice' => 0,
    'late_practice' => 0,
    'total_xp' => 0,
    'level' => 1
];

$logros_obtenidos = [];
$logros_en_progreso = [];

if ($id_estudiante) {
    try {
        // Verificar si existe tabla de logros
        $checkTable = $db->query("SHOW TABLES LIKE 'tbl_ingles_logros_estudiante'");
        
        if ($checkTable->rowCount() > 0) {
            // Obtener logros obtenidos
            $query = "SELECT al.*, l.name, l.description, l.icon, l.category, l.rarity, l.xp_reward, l.condition
                      FROM tbl_ingles_logros_estudiante al
                      JOIN tbl_ingles_logros l ON al.id_logro = l.id
                      WHERE al.id_estudiante = :id_estudiante
                      ORDER BY al.fecha_obtenido DESC";
            $stmt = $db->prepare($query);
            $stmt->bindValue(':id_estudiante', $id_estudiante, PDO::PARAM_INT);
            $stmt->execute();
            $logros_obtenidos_raw = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            foreach ($logros_obtenidos_raw as $logro) {
                $logros_obtenidos[$logro['id']] = [
                    ...$definicion_logros[$logro['id']] ?? $logro,
                    'fecha_obtenido' => $logro['fecha_obtenido'],
                    'obtenido' => true
                ];
            }
        }
        
        // Obtener estadísticas del estudiante desde tbl_ingles_progreso
        $query = "SELECT 
                  COUNT(*) as lessons_completed,
                  SUM(puntaje) as total_puntaje,
                  AVG(puntaje) as avg_puntaje,
                  COUNT(CASE WHEN puntaje = 100 THEN 1 END) as perfect_lessons,
                  MAX(intentos) as max_attempts
                  FROM tbl_ingles_progreso
                  WHERE id_estudiante = :id_estudiante AND estado = 'completado'";
        $stmt = $db->prepare($query);
        $stmt->bindValue(':id_estudiante', $id_estudiante, PDO::PARAM_INT);
        $stmt->execute();
        $progreso_data = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($progreso_data) {
            $estadisticas['lessons_completed'] = $progreso_data['lessons_completed'] ?? 0;
            $estadisticas['total_xp'] = $progreso_data['total_puntaje'] ?? 0;
            $estadisticas['perfect_lessons'] = $progreso_data['perfect_lessons'] ?? 0;
            
            // Calcular nivel basado en XP
            $estadisticas['level'] = min(50, floor(sqrt($estadisticas['total_xp'] / 100)) + 1);
        }
        
        // Calcular racha actual (simplificado)
        $estadisticas['current_streak'] = min(30, $estadisticas['lessons_completed'] % 31);
        $estadisticas['streak_days'] = $estadisticas['current_streak'];
        
        // Calcular palabras aprendidas (estimado)
        $estadisticas['words_learned'] = $estadisticas['lessons_completed'] * 10;
        
        // Calcular precisión de gramática (simulado)
        $estadisticas['grammar_accuracy'] = $progreso_data['avg_puntaje'] ?? 75;
        
        // Calcular categorías completadas
        $estadisticas['categories_completed'] = min(6, floor($estadisticas['lessons_completed'] / 5) + 1);
        
    } catch (Exception $e) {
        error_log("Error logros: " . $e->getMessage());
    }
}

// ===== CALCULAR LOGROS EN PROGRESO =====
foreach ($definicion_logros as $logro_id => $logro_def) {
    if (isset($logros_obtenidos[$logro_id])) {
        continue; // Ya obtenido
    }
    
    $condition = $logro_def['condition'];
    $progress = 0;
    $target = $condition['value'] ?? 0;
    $current = 0;
    
    switch ($condition['type']) {
        case 'lessons_completed':
            $current = $estadisticas['lessons_completed'];
            $progress = $target > 0 ? min(100, ($current / $target) * 100) : 0;
            break;
            
        case 'words_learned':
            $current = $estadisticas['words_learned'];
            $progress = $target > 0 ? min(100, ($current / $target) * 100) : 0;
            break;
            
        case 'streak_days':
            $current = $estadisticas['current_streak'];
            $progress = $target > 0 ? min(100, ($current / $target) * 100) : 0;
            break;
            
        case 'perfect_lessons':
            $current = $estadisticas['perfect_lessons'];
            $progress = $target > 0 ? min(100, ($current / $target) * 100) : 0;
            break;
            
        case 'categories_completed':
            $current = $estadisticas['categories_completed'];
            $progress = $target > 0 ? min(100, ($current / $target) * 100) : 0;
            break;
            
        case 'grammar_accuracy':
            // ✅ CORRECCIÓN: Verificar que existe 'accuracy' antes de usarlo
            $required_accuracy = $condition['accuracy'] ?? 90; // Valor por defecto
            $required_count = $condition['count'] ?? 1;
            $current_accuracy = $estadisticas['grammar_accuracy'] ?? 0;
            
            // Progreso basado en precisión + conteo
            $accuracy_progress = $required_accuracy > 0 ? min(100, ($current_accuracy / $required_accuracy) * 100) : 0;
            $count_progress = $required_count > 0 ? min(100, ($estadisticas['lessons_completed'] / $required_count) * 100) : 0;
            
            // Promedio de ambos progresos
            $progress = min(100, ($accuracy_progress + $count_progress) / 2);
            $current = round($current_accuracy);
            $target = $required_accuracy;
            break;
            
        case 'lesson_time':
            // Logro de velocidad: completar lección en X segundos con Y% precisión
            $time_limit = $condition['value'] ?? 300;
            $required_accuracy = $condition['accuracy'] ?? 100;
            // Simular progreso (en producción, usar datos reales de tiempo)
            $progress = min(100, ($estadisticas['lessons_completed'] / 5) * 100);
            $current = $estadisticas['lessons_completed'];
            $target = 5;
            break;
            
        case 'quick_answers':
            // Respuestas rápidas consecutivas
            $progress = min(100, ($estadisticas['lessons_completed'] / 10) * 100);
            $current = min($target, $estadisticas['lessons_completed']);
            break;
            
        case 'all_categories_max':
            // Todas las categorías al máximo
            $progress = min(100, $estadisticas['categories_completed'] * 20);
            $current = $estadisticas['categories_completed'];
            $target = 5;
            break;
            
        case 'helps_given':
        case 'cooperative_lessons':
        case 'early_practice':
        case 'late_practice':
        case 'birthday_practice':
        case 'continuous_practice':
            // Logros sociales/especiales: progreso simulado basado en lecciones
            $progress = min(100, ($estadisticas['lessons_completed'] / max(1, $target)) * 100);
            $current = min($target, $estadisticas['lessons_completed']);
            break;
            
        default:
            $progress = 0;
            $current = 0;
    }
    
    // Solo agregar a progreso si tiene algún avance
    if ($progress > 0 && $progress < 100) {
        $logros_en_progreso[$logro_id] = [
            ...$logro_def,
            'progress' => round($progress),
            'current' => $current,
            'target' => $target,
            'obtenido' => false
        ];
    }
}
// ===== CONFIGURACIÓN DE RAREZA =====
$rareza_config = [
    'common' => ['label' => 'Común', 'color' => 'gray', 'border' => '#9ca3af', 'bg' => 'bg-gray-100'],
    'rare' => ['label' => 'Raro', 'color' => 'blue', 'border' => '#3b82f6', 'bg' => 'bg-blue-50'],
    'epic' => ['label' => 'Épico', 'color' => 'purple', 'border' => '#a78bfa', 'bg' => 'bg-purple-50'],
    'legendary' => ['label' => 'Legendario', 'color' => 'orange', 'border' => '#f97316', 'bg' => 'bg-orange-50']
];

// ===== FILTRO ACTIVO =====
$categoria_filtro = $_GET['categoria'] ?? 'all';
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>🏆 Mis Logros - English Plus</title>
    
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
            --gradient-gold: linear-gradient(135deg, #fbbf24 0%, #f59e0b 50%, #d97706 100%);
            --gradient-purple: linear-gradient(135deg, #a78bfa 0%, #8b5cf6 100%);
            --gradient-orange: linear-gradient(135deg, #f97316 0%, #ea580c 100%);
            --shadow: 0 4px 6px -1px rgba(0,0,0,0.1);
            --shadow-lg: 0 10px 15px -3px rgba(0,0,0,0.1);
            --shadow-xl: 0 25px 50px -12px rgba(0,0,0,0.25);
            --radius: 16px;
            --radius-lg: 24px;
        }
        
        * { margin: 0; padding: 0; box-sizing: border-box; }
        
        body {
            font-family: 'Poppins', 'Segoe UI', system-ui, sans-serif;
            background: linear-gradient(135deg, #f0f9ff 0%, #fef3c7 30%, #f0fdf4 70%, #fef3c7 100%);
            min-height: 100vh;
            color: var(--dark);
        }
        
        /* ===== HERO SECTION ===== */
        .achievements-hero {
            background: var(--gradient-primary);
            color: white;
            padding: 40px 0 30px;
            position: relative;
            overflow: hidden;
            margin-bottom: 30px;
        }
        
        .achievements-hero::before {
            content: '';
            position: absolute;
            top: -50%;
            right: -20%;
            width: 600px;
            height: 600px;
            background: radial-gradient(circle, rgba(255,255,255,0.15) 0%, transparent 70%);
            border-radius: 50%;
            animation: float 20s ease-in-out infinite;
        }
        
        @keyframes float {
            0%, 100% { transform: translateY(0) rotate(0deg); }
            50% { transform: translateY(-30px) rotate(180deg); }
        }
        
        .hero-content {
            position: relative;
            z-index: 2;
        }
        
        .hero-title {
            font-size: 2rem;
            font-weight: 700;
            margin-bottom: 10px;
            display: flex;
            align-items: center;
            gap: 12px;
        }
        
        .hero-subtitle {
            font-size: 1.1rem;
            opacity: 0.9;
            margin-bottom: 20px;
        }
        
        .user-summary {
            display: flex;
            align-items: center;
            gap: 20px;
            background: rgba(255,255,255,0.15);
            padding: 15px 25px;
            border-radius: 50px;
            backdrop-filter: blur(10px);
            border: 1px solid rgba(255,255,255,0.3);
            max-width: fit-content;
        }
        
        .user-avatar-large {
            width: 60px;
            height: 60px;
            border-radius: 50%;
            background: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--primary);
        }
        
        .user-info h4 {
            font-weight: 600;
            margin-bottom: 3px;
        }
        
        .user-level {
            font-size: 0.9rem;
            opacity: 0.9;
        }
        
        /* ===== LEVEL PROGRESS ===== */
        .level-card {
            background: white;
            border-radius: var(--radius-lg);
            padding: 25px;
            margin-bottom: 30px;
            box-shadow: var(--shadow-lg);
            display: flex;
            align-items: center;
            gap: 25px;
        }
        
        .level-display {
            text-align: center;
            min-width: 100px;
        }
        
        .level-number {
            font-size: 3rem;
            font-weight: 700;
            background: var(--gradient-primary);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            line-height: 1;
        }
        
        .level-label {
            font-size: 0.9rem;
            color: #64748b;
            font-weight: 500;
        }
        
        .level-progress-container {
            flex: 1;
        }
        
        .level-progress-header {
            display: flex;
            justify-content: space-between;
            margin-bottom: 10px;
            font-size: 0.9rem;
        }
        
        .level-progress-bar {
            height: 16px;
            background: #e2e8f0;
            border-radius: 10px;
            overflow: hidden;
            position: relative;
        }
        
        .level-progress-fill {
            height: 100%;
            background: var(--gradient-primary);
            border-radius: 10px;
            transition: width 0.8s ease;
            position: relative;
        }
        
        .level-progress-fill::after {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: linear-gradient(90deg, transparent, rgba(255,255,255,0.3), transparent);
            animation: shimmer 2s infinite;
        }
        
        @keyframes shimmer {
            0% { transform: translateX(-100%); }
            100% { transform: translateX(100%); }
        }
        
        .level-next {
            font-size: 0.85rem;
            color: #64748b;
            margin-top: 8px;
        }
        
        .level-next strong {
            color: var(--primary);
            font-weight: 600;
        }
        
        /* ===== STATS CARDS ===== */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(140px, 1fr));
            gap: 15px;
            margin-bottom: 30px;
        }
        
        .stat-card {
            background: white;
            border-radius: var(--radius);
            padding: 20px;
            text-align: center;
            box-shadow: var(--shadow);
            transition: all 0.3s ease;
        }
        
        .stat-card:hover {
            transform: translateY(-4px);
            box-shadow: var(--shadow-lg);
        }
        
        .stat-icon {
            width: 50px;
            height: 50px;
            border-radius: 14px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.3rem;
            color: white;
            margin: 0 auto 12px;
        }
        
        .stat-icon.lessons { background: linear-gradient(135deg, #38bdf8, #0ea5e9); }
        .stat-icon.words { background: linear-gradient(135deg, #4ade80, #22c55e); }
        .stat-icon.streak { background: linear-gradient(135deg, #f97316, #ea580c); }
        .stat-icon.xp { background: var(--gradient-gold); }
        
        .stat-value {
            font-size: 1.8rem;
            font-weight: 700;
            color: var(--dark);
            margin-bottom: 4px;
        }
        
        .stat-label {
            font-size: 0.85rem;
            color: #64748b;
        }
        
        /* ===== CATEGORY TABS ===== */
        .category-tabs {
            display: flex;
            gap: 8px;
            margin-bottom: 25px;
            overflow-x: auto;
            padding-bottom: 10px;
            scrollbar-width: none;
        }
        
        .category-tabs::-webkit-scrollbar {
            display: none;
        }
        
        .category-tab {
            padding: 10px 20px;
            border-radius: 12px;
            background: white;
            border: 2px solid transparent;
            font-size: 0.9rem;
            font-weight: 500;
            color: #64748b;
            cursor: pointer;
            transition: all 0.2s;
            display: flex;
            align-items: center;
            gap: 6px;
            white-space: nowrap;
        }
        
        .category-tab:hover {
            border-color: var(--primary);
            background: #f0fff0;
            color: var(--primary-dark);
        }
        
        .category-tab.active {
            background: var(--gradient-primary);
            color: white;
            border-color: transparent;
            box-shadow: 0 4px 15px rgba(88, 204, 2, 0.3);
        }
        
        .category-tab i {
            font-size: 1rem;
        }
        
        /* ===== ACHIEVEMENT CARDS ===== */
        .achievements-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
            gap: 20px;
            margin-bottom: 40px;
        }
        
        .achievement-card {
            background: white;
            border-radius: var(--radius);
            padding: 20px;
            box-shadow: var(--shadow);
            transition: all 0.3s ease;
            position: relative;
            overflow: hidden;
            border: 2px solid transparent;
        }
        
        .achievement-card:hover {
            transform: translateY(-5px);
            box-shadow: var(--shadow-lg);
        }
        
        .achievement-card.unlocked {
            border-color: var(--accent);
            background: linear-gradient(135deg, #fffbeb 0%, #fef3c7 100%);
        }
        
        .achievement-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: var(--gradient-primary);
            opacity: 0;
            transition: opacity 0.3s;
        }
        
        .achievement-card:hover::before {
            opacity: 1;
        }
        
        .achievement-card.unlocked::before {
            background: var(--gradient-gold);
            opacity: 1;
        }
        
        .achievement-header {
            display: flex;
            align-items: center;
            gap: 15px;
            margin-bottom: 15px;
        }
        
        .achievement-icon {
            width: 60px;
            height: 60px;
            border-radius: 18px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.8rem;
            background: #f1f5f9;
            position: relative;
            flex-shrink: 0;
        }
        
        .achievement-card.unlocked .achievement-icon {
            background: var(--gradient-gold);
            animation: pulse-gold 2s infinite;
        }
        
        @keyframes pulse-gold {
            0%, 100% { box-shadow: 0 0 0 0 rgba(251, 191, 36, 0.4); }
            50% { box-shadow: 0 0 0 15px rgba(251, 191, 36, 0); }
        }
        
        .achievement-icon.common { border: 3px solid #9ca3af; }
        .achievement-icon.rare { border: 3px solid #3b82f6; }
        .achievement-icon.epic { border: 3px solid #a78bfa; }
        .achievement-icon.legendary { 
            border: 3px solid #f97316;
            background: var(--gradient-gold);
        }
        
        .rarity-badge {
            position: absolute;
            top: -5px;
            right: -5px;
            padding: 3px 10px;
            border-radius: 15px;
            font-size: 0.7rem;
            font-weight: 600;
            color: white;
            text-transform: uppercase;
        }
        
        .rarity-badge.common { background: #64748b; }
        .rarity-badge.rare { background: #3b82f6; }
        .rarity-badge.epic { background: #a78bfa; }
        .rarity-badge.legendary { background: var(--gradient-gold); color: var(--dark); }
        
        .achievement-info {
            flex: 1;
            min-width: 0;
        }
        
        .achievement-name {
            font-weight: 600;
            font-size: 1.05rem;
            margin-bottom: 4px;
            color: var(--dark);
        }
        
        .achievement-card.unlocked .achievement-name {
            color: var(--primary-dark);
        }
        
        .achievement-desc {
            font-size: 0.85rem;
            color: #64748b;
            line-height: 1.4;
        }
        
        .achievement-category {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            padding: 4px 10px;
            border-radius: 10px;
            font-size: 0.75rem;
            font-weight: 500;
            margin-top: 8px;
            background: #f1f5f9;
            color: #64748b;
        }
        
        /* Progress Bar for In-Progress Achievements */
        .achievement-progress {
            margin-top: 15px;
        }
        
        .progress-label {
            display: flex;
            justify-content: space-between;
            font-size: 0.8rem;
            color: #64748b;
            margin-bottom: 6px;
        }
        
        .progress-bar-mini {
            height: 8px;
            background: #e2e8f0;
            border-radius: 10px;
            overflow: hidden;
        }
        
        .progress-fill-mini {
            height: 100%;
            background: var(--gradient-primary);
            border-radius: 10px;
            transition: width 0.5s ease;
        }
        
        .achievement-card.unlocked .progress-fill-mini {
            background: var(--gradient-gold);
        }
        
        /* XP Reward Badge */
        .xp-reward {
            position: absolute;
            top: 15px;
            right: 15px;
            padding: 5px 12px;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
            background: var(--gradient-gold);
            color: var(--dark);
            display: flex;
            align-items: center;
            gap: 4px;
        }
        
        .achievement-card.unlocked .xp-reward {
            background: #e2e8f0;
            color: #64748b;
        }
        
        /* Locked Overlay */
        .achievement-locked {
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(255,255,255,0.9);
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: var(--radius);
            opacity: 0;
            transition: opacity 0.3s;
        }
        
        .achievement-card:not(.unlocked):hover .achievement-locked {
            opacity: 1;
        }
        
        .lock-icon {
            font-size: 2rem;
            color: #94a3b8;
        }
        
        /* ===== EMPTY STATE ===== */
        .empty-state {
            text-align: center;
            padding: 60px 20px;
            background: white;
            border-radius: var(--radius-lg);
            box-shadow: var(--shadow);
        }
        
        .empty-icon {
            font-size: 4rem;
            margin-bottom: 20px;
            opacity: 0.5;
        }
        
        .empty-title {
            font-size: 1.3rem;
            font-weight: 600;
            margin-bottom: 10px;
        }
        
        .empty-desc {
            color: #64748b;
            margin-bottom: 25px;
            max-width: 400px;
            margin-left: auto;
            margin-right: auto;
        }
        
        /* ===== ANIMATIONS ===== */
        @keyframes fadeInUp {
            from { opacity: 0; transform: translateY(30px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        .animate-in {
            animation: fadeInUp 0.5s ease forwards;
            opacity: 0;
        }
        
        .animate-in:nth-child(1) { animation-delay: 0.1s; }
        .animate-in:nth-child(2) { animation-delay: 0.2s; }
        .animate-in:nth-child(3) { animation-delay: 0.3s; }
        .animate-in:nth-child(4) { animation-delay: 0.4s; }
        .animate-in:nth-child(5) { animation-delay: 0.5s; }
        
        /* Confetti Animation for New Achievements */
        @keyframes confetti {
            0% { transform: translateY(-100%) rotate(0deg); opacity: 1; }
            100% { transform: translateY(100vh) rotate(720deg); opacity: 0; }
        }
        
        .confetti {
            position: fixed;
            width: 10px;
            height: 10px;
            background: var(--accent);
            animation: confetti 3s ease-in-out forwards;
            pointer-events: none;
            z-index: 9999;
        }
        
        /* ===== RESPONSIVE ===== */
        @media (max-width: 992px) {
            .level-card { flex-direction: column; text-align: center; }
            .level-progress-container { width: 100%; }
        }
        
        @media (max-width: 768px) {
            .hero-title { font-size: 1.5rem; }
            .achievements-grid { grid-template-columns: 1fr; }
            .stats-grid { grid-template-columns: repeat(2, 1fr); }
            .category-tabs { justify-content: flex-start; }
        }
        
        @media (max-width: 480px) {
            .stats-grid { grid-template-columns: 1fr 1fr; }
            .user-summary { flex-direction: column; text-align: center; }
        }
    </style>
</head>
<body>
    <!-- Hero Section -->
    <section class="achievements-hero">
        <div class="container">
            <div class="row align-items-center">
                <div class="col-lg-8 hero-content animate-in">
                    <h1 class="hero-title">
                        <i class="fas fa-trophy"></i> Mis Logros
                    </h1>
                    <p class="hero-subtitle">
                        Desbloquea insignias, gana XP y demuestra tu dominio del inglés 🇺🇸
                    </p>
                    
                    <div class="user-summary">
                        <div class="user-avatar-large">
                            <?= $avatar_inicial ?>
                        </div>
                        <div class="user-info">
                            <h4><?= htmlspecialchars($nombre_usuario) ?></h4>
                            <div class="user-level">
                                Nivel <?= $estadisticas['level'] ?> • <?= $estadisticas['total_xp'] ?> XP total
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="col-lg-4 text-lg-end animate-in" style="animation-delay: 0.2s">
                    <div class="badge bg-white text-dark p-3 d-inline-flex align-items-center gap-2">
                        <i class="fas fa-medal text-warning"></i>
                        <strong><?= count($logros_obtenidos) ?></strong> / <?= count($definicion_logros) ?> logros
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- Main Content -->
    <div class="container mb-5">
        <!-- Level Progress Card -->
        <div class="level-card animate-in">
            <div class="level-display">
                <div class="level-number"><?= $estadisticas['level'] ?></div>
                <div class="level-label">Nivel Actual</div>
            </div>
            
            <div class="level-progress-container">
                <div class="level-progress-header">
                    <span>Progreso al siguiente nivel</span>
                    <span><?= min(100, ($estadisticas['total_xp'] % 1000) / 10) ?>%</span>
                </div>
                <div class="level-progress-bar">
                    <div class="level-progress-fill" style="width: <?= min(100, ($estadisticas['total_xp'] % 1000) / 10) ?>%"></div>
                </div>
                <div class="level-next">
                    <strong><?= max(0, 1000 - ($estadisticas['total_xp'] % 1000)) ?> XP</strong> para Nivel <?= $estadisticas['level'] + 1 ?>
                </div>
            </div>
            
            <div class="text-end">
                <button class="btn btn-outline-primary btn-sm" onclick="verNiveles()">
                    <i class="fas fa-info-circle"></i> Ver Niveles
                </button>
            </div>
        </div>

        <!-- Stats Cards -->
        <div class="stats-grid">
            <div class="stat-card animate-in">
                <div class="stat-icon lessons">
                    <i class="fas fa-book-open"></i>
                </div>
                <div class="stat-value"><?= $estadisticas['lessons_completed'] ?></div>
                <div class="stat-label">Lecciones</div>
            </div>
            <div class="stat-card animate-in">
                <div class="stat-icon words">
                    <i class="fas fa-spell-check"></i>
                </div>
                <div class="stat-value"><?= $estadisticas['words_learned'] ?></div>
                <div class="stat-label">Palabras</div>
            </div>
            <div class="stat-card animate-in">
                <div class="stat-icon streak">
                    <i class="fas fa-fire"></i>
                </div>
                <div class="stat-value"><?= $estadisticas['current_streak'] ?>🔥</div>
                <div class="stat-label">Días Racha</div>
            </div>
            <div class="stat-card animate-in">
                <div class="stat-icon xp">
                    <i class="fas fa-star"></i>
                </div>
                <div class="stat-value"><?= $estadisticas['total_xp'] ?></div>
                <div class="stat-label">XP Total</div>
            </div>
        </div>

        <!-- Category Filter Tabs -->
        <div class="category-tabs">
            <?php foreach ($categorias_logros as $key => $cat): ?>
            <div class="category-tab <?= $categoria_filtro == $key ? 'active' : '' ?>" 
                 onclick="filterAchievements('<?= $key ?>')">
                <i class="fas <?= $cat['icon'] ?>"></i>
                <?= $cat['label'] ?>
            </div>
            <?php endforeach; ?>
        </div>

        <!-- Achievements Grid -->
        <div class="achievements-grid" id="achievementsGrid">
            <?php
            // Filtrar logros por categoría
            $logros_mostrar = array_filter(
                array_merge($logros_obtenidos, $logros_en_progreso),
                fn($l) => $categoria_filtro == 'all' || $l['category'] == $categoria_filtro
            );
            
            // Ordenar: obtenidos primero, luego por rareza
            usort($logros_mostrar, function($a, $b) {
                if ($a['obtenido'] && !$b['obtenido']) return -1;
                if (!$a['obtenido'] && $b['obtenido']) return 1;
                
                $rarity_order = ['legendary' => 0, 'epic' => 1, 'rare' => 2, 'common' => 3];
                return ($rarity_order[$a['rarity']] ?? 4) - ($rarity_order[$b['rarity']] ?? 4);
            });
            
            if (empty($logros_mostrar)):
            ?>
            <div class="col-12">
                <div class="empty-state">
                    <div class="empty-icon">🏆</div>
                    <h4 class="empty-title">¡Comienza tu aventura!</h4>
                    <p class="empty-desc">Completa lecciones, practica vocabulario y mantiene tu racha para desbloquear logros increíbles.</p>
                    <a href="ingles_dashboard.php" class="btn btn-primary">
                        <i class="fas fa-play"></i> Empezar a Practicar
                    </a>
                </div>
            </div>
            <?php else: ?>
            <?php foreach ($logros_mostrar as $logro): 
                $rareza = $rareza_config[$logro['rarity']] ?? $rareza_config['common'];
            ?>
            <div class="achievement-card <?= $logro['obtenido'] ? 'unlocked' : '' ?> animate-in">
                <!-- XP Reward -->
                <div class="xp-reward">
                    <i class="fas fa-star"></i> +<?= $logro['xp_reward'] ?> XP
                </div>
                
                <!-- Header -->
                <div class="achievement-header">
                    <div class="achievement-icon <?= $logro['rarity'] ?> <?= $logro['obtenido'] ? 'unlocked' : '' ?>">
                        <?= $logro['icon'] ?>
                        <?php if (!$logro['obtenido']): ?>
                        <span class="rarity-badge <?= $logro['rarity'] ?>"><?= $rareza['label'] ?></span>
                        <?php endif; ?>
                    </div>
                    <div class="achievement-info">
                        <div class="achievement-name"><?= $logro['name'] ?></div>
                        <div class="achievement-desc"><?= $logro['description'] ?></div>
                        <span class="achievement-category">
                            <i class="fas <?= $categorias_logros[$logro['category']]['icon'] ?>"></i>
                            <?= $categorias_logros[$logro['category']]['label'] ?>
                        </span>
                    </div>
                </div>
                
                <!-- Progress for In-Progress Achievements -->
                <?php if (!$logro['obtenido'] && isset($logro['progress'])): ?>
                <div class="achievement-progress">
                    <div class="progress-label">
                        <span>Progreso</span>
                        <span><?= $logro['current'] ?> / <?= $logro['target'] ?></span>
                    </div>
                    <div class="progress-bar-mini">
                        <div class="progress-fill-mini" style="width: <?= $logro['progress'] ?>%"></div>
                    </div>
                </div>
                <?php endif; ?>
                
                <!-- Date Obtained -->
                <?php if ($logro['obtenido'] && isset($logro['fecha_obtenido'])): ?>
                <div class="text-end mt-3">
                    <small class="text-muted">
                        <i class="fas fa-calendar-check text-success"></i>
                        Obtenido: <?= date('d/m/Y', strtotime($logro['fecha_obtenido'])) ?>
                    </small>
                </div>
                <?php endif; ?>
                
                <!-- Locked Overlay (visual hint) -->
                <?php if (!$logro['obtenido']): ?>
                <div class="achievement-locked">
                    <i class="fas fa-lock lock-icon"></i>
                </div>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <!-- Tips Section -->
        <div class="card border-0 shadow-sm animate-in">
            <div class="card-body p-4">
                <h5 class="mb-3"><i class="fas fa-lightbulb text-warning"></i> Consejos para Desbloquear Más Logros</h5>
                <div class="row g-3">
                    <div class="col-md-4">
                        <div class="d-flex gap-3">
                            <i class="fas fa-clock text-primary mt-1"></i>
                            <div>
                                <strong>Practica diariamente</strong>
                                <p class="text-muted small mb-0">Mantén tu racha para desbloquear logros de consistencia 🔥</p>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="d-flex gap-3">
                            <i class="fas fa-bullseye text-success mt-1"></i>
                            <div>
                                <strong>Busca la perfección</strong>
                                <p class="text-muted small mb-0">100% de precisión en lecciones desbloquea logros épicos 💎</p>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="d-flex gap-3">
                            <i class="fas fa-users text-info mt-1"></i>
                            <div>
                                <strong>Invita a amigos</strong>
                                <p class="text-muted small mb-0">Los logros sociales dan XP extra y recompensas especiales 👥</p>
                            </div>
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
            idEstudiante: <?= $id_estudiante ?>,
            categoriaFiltro: '<?= $categoria_filtro ?>',
            apiUrl: '../api/ingles/'
        };
        
        // Filter achievements by category
        function filterAchievements(categoria) {
            // Update active tab
            $('.category-tab').removeClass('active');
            $(`.category-tab[data-category="${categoria}"]`).addClass('active');
            
            // Show loading
            $('#achievementsGrid').html(`
                <div class="col-12 text-center py-5">
                    <div class="spinner-border text-primary" role="status"></div>
                    <p class="mt-2 text-muted">Cargando logros...</p>
                </div>
            `);
            
            // Load filtered achievements
            $.get(window.location.pathname, { categoria: categoria }, function(data) {
                const parser = new DOMParser();
                const doc = parser.parseFromString(data, 'text/html');
                const newGrid = doc.getElementById('achievementsGrid');
                $('#achievementsGrid').html(newGrid.innerHTML);
                
                // Re-apply animations
                $('.animate-in').css('opacity', '1');
            });
            
            // Update URL without reload
            const url = new URL(window.location);
            url.searchParams.set('categoria', categoria);
            window.history.replaceState({}, '', url);
        }
        
        // View levels info
        function verNiveles() {
            Swal.fire({
                title: '📊 Sistema de Niveles',
                html: `
                    <div class="text-start">
                        <p><strong>Nivel 1-10:</strong> Principiante (0-1,000 XP)</p>
                        <p><strong>Nivel 11-25:</strong> Intermedio (1,000-10,000 XP)</p>
                        <p><strong>Nivel 26-40:</strong> Avanzado (10,000-30,000 XP)</p>
                        <p><strong>Nivel 41-50:</strong> Maestro (30,000+ XP)</p>
                        <hr>
                        <p class="mb-0"><i class="fas fa-gift text-warning"></i> <strong>Bonus por nivel:</strong></p>
                        <ul class="mb-0 mt-2">
                            <li>Cada 5 niveles: +50 XP bonus</li>
                            <li>Nivel 10: Desbloquea modo Desafío</li>
                            <li>Nivel 25: Acceso a lecciones premium</li>
                            <li>Nivel 50: Insignia de Maestro + certificado</li>
                        </ul>
                    </div>
                `,
                confirmButtonText: '¡Entendido!',
                confirmButtonColor: '#58cc02'
            });
        }
        
        // Show confetti animation for new achievements
        function showConfetti() {
            const colors = ['#58cc02', '#ffc800', '#38bdf8', '#a78bfa', '#f97316'];
            
            for (let i = 0; i < 50; i++) {
                const confetti = document.createElement('div');
                confetti.className = 'confetti';
                confetti.style.left = Math.random() * 100 + 'vw';
                confetti.style.background = colors[Math.floor(Math.random() * colors.length)];
                confetti.style.animationDelay = Math.random() * 2 + 's';
                confetti.style.animationDuration = (2 + Math.random() * 2) + 's';
                document.body.appendChild(confetti);
                
                setTimeout(() => confetti.remove(), 4000);
            }
        }
        
        // Check for newly unlocked achievements on page load
        $(document).ready(function() {
            // Apply animations
            $('.animate-in').css('opacity', '1');
            
            // If user just unlocked something, show celebration
            const urlParams = new URLSearchParams(window.location.search);
            if (urlParams.get('nuevo_logro')) {
                showConfetti();
                Swal.fire({
                    icon: 'success',
                    title: '🎉 ¡Nuevo Logro Desbloqueado!',
                    text: 'Has obtenido una nueva insignia. ¡Sigue así!',
                    timer: 3000,
                    showConfirmButton: false
                });
            }
            
            // Add click effect to achievement cards
            $('.achievement-card.unlocked').click(function() {
                const name = $(this).find('.achievement-name').text();
                const xp = $(this).find('.xp-reward').text();
                
                Swal.fire({
                    icon: 'success',
                    title: name,
                    text: `Recompensa: ${xp}`,
                    confirmButtonText: '¡Genial!',
                    confirmButtonColor: '#58cc02'
                });
            });
        });
        
        // Save practice progress (called from practice pages)
        function saveAchievementProgress(achievementId, progress) {
            $.post(CONFIG.apiUrl + 'actualizar_progreso_logro.php', {
                id_estudiante: CONFIG.idEstudiante,
                id_logro: achievementId,
                progreso: progress
            }, function(response) {
                if (response.nuevo_logro) {
                    showConfetti();
                    setTimeout(() => {
                        window.location.href = `logros.php?nuevo_logro=${achievementId}`;
                    }, 1000);
                }
            }, 'json');
        }
    </script>
</body>
</html>