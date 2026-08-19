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

// Obtener datos del usuario
$nombre_usuario = 'Usuario';
$id_estudiante = 0;
$id_profesor = 0;

if ($rol == 'estudiante') {
    $query = "SELECT e.id as id_estudiante, p.primer_nombre, p.primer_apellido
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
} elseif ($rol == 'profesor') {
    $query = "SELECT p.id as id_profesor, per.primer_nombre, per.primer_apellido
              FROM tbl_profesor p
              JOIN tbl_persona per ON p.id_persona = per.id
              WHERE per.id_usuario = :user_id AND p.id_institucion = :tid";
    $stmt = $db->prepare($query);
    $stmt->bindValue(':user_id', $user_id, PDO::PARAM_INT);
    $stmt->bindValue(':tid', $tid, PDO::PARAM_INT);
    $stmt->execute();
    $profesor = $stmt->fetch(PDO::FETCH_ASSOC);
    $id_profesor = $profesor['id_profesor'] ?? 0;
    $nombre_usuario = $profesor['primer_nombre'] ?? 'Profesor';
}

// ===== ESTADÍSTICAS PARA ESTUDIANTE =====
$stats = ['lecciones_completadas' => 0, 'puntaje_total' => 0, 'promedio_puntaje' => 0, 'completadas' => 0];
$cursos = [];
$asignaciones = [];
$ultimas_lecciones = [];

if ($rol == 'estudiante' && $id_estudiante) {
    // Progreso general
    $query = "SELECT 
              COUNT(DISTINCT p.id_leccion) as lecciones_completadas,
              SUM(p.puntaje) as puntaje_total,
              AVG(p.puntaje) as promedio_puntaje,
              COUNT(DISTINCT CASE WHEN p.estado = 'completado' THEN p.id END) as completadas
              FROM tbl_ingles_progreso p
              WHERE p.id_estudiante = :id_estudiante";
    $stmt = $db->prepare($query);
    $stmt->bindValue(':id_estudiante', $id_estudiante, PDO::PARAM_INT);
    $stmt->execute();
    $stats = $stmt->fetch(PDO::FETCH_ASSOC) ?? $stats;
    
    // Cursos disponibles (con verificación de tabla)
    $cursos = [];
    try {
        $checkTable = $db->query("SHOW TABLES LIKE 'tbl_ingles_curso'");
        if ($checkTable->rowCount() > 0) {
            $query = "SELECT * FROM tbl_ingles_curso WHERE estado = 'activo' ORDER BY 
                      FIELD(nivel, 'beginner', 'elementary', 'pre-intermediate', 'intermediate', 'upper-intermediate', 'advanced', 'proficient')";
            $cursos = $db->query($query)->fetchAll(PDO::FETCH_ASSOC);
        }
    } catch (Exception $e) {
        error_log("Error cursos: " . $e->getMessage());
    }
    
    // Asignaciones pendientes
    $asignaciones = [];
    try {
        // tbl_ingles_asignacion no tiene columna id_institucion; no hace
        // falta filtrarla aparte porque $id_estudiante ya viene de una
        // consulta anterior filtrada por tbl_estudiante.id_institucion.
        $query = "SELECT a.*, l.titulo as leccion_titulo, c.nombre as curso_nombre
                  FROM tbl_ingles_asignacion a
                  LEFT JOIN tbl_ingles_leccion l ON a.id_leccion = l.id
                  LEFT JOIN tbl_ingles_curso c ON a.id_curso = c.id
                  WHERE a.id_estudiante = :id_estudiante AND a.estado IN ('pendiente', 'en-progreso')
                  ORDER BY a.fecha_limite ASC";
        $stmt = $db->prepare($query);
        $stmt->bindValue(':id_estudiante', $id_estudiante, PDO::PARAM_INT);
        $stmt->execute();
        $asignaciones = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        error_log("Error asignaciones: " . $e->getMessage());
    }

    // Últimas lecciones
    try {
        $query = "SELECT p.*, l.titulo, l.tipo, c.nombre as curso_nombre
                  FROM tbl_ingles_progreso p
                  JOIN tbl_ingles_leccion l ON p.id_leccion = l.id
                  JOIN tbl_ingles_curso c ON l.id_curso = c.id
                  WHERE p.id_estudiante = :id_estudiante
                  ORDER BY p.ultimo_intento DESC LIMIT 5";
        $stmt = $db->prepare($query);
        $stmt->bindValue(':id_estudiante', $id_estudiante, PDO::PARAM_INT);
        $stmt->execute();
        $ultimas_lecciones = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        error_log("Error lecciones: " . $e->getMessage());
    }
}

// ===== ESTADÍSTICAS PARA PROFESOR =====
if ($rol == 'profesor' && $id_profesor) {
    // tbl_ingles_asignacion no tiene columna id_institucion; id_profesor ya
    // está tenant-verificado (resuelto arriba vía p.id_institucion).
    $query = "SELECT COUNT(DISTINCT id_estudiante) as total_estudiantes
              FROM tbl_ingles_asignacion
              WHERE id_profesor = :id_profesor";
    $stmt = $db->prepare($query);
    $stmt->bindValue(':id_profesor', $id_profesor, PDO::PARAM_INT);
    $stmt->execute();
    $stats = $stmt->fetch(PDO::FETCH_ASSOC) ?? $stats;

    $query = "SELECT * FROM tbl_ingles_asignacion
              WHERE id_profesor = :id_profesor AND estado IN ('pendiente', 'en-progreso')
              ORDER BY fecha_asignacion DESC";
    $stmt = $db->prepare($query);
    $stmt->bindValue(':id_profesor', $id_profesor, PDO::PARAM_INT);
    $stmt->execute();
    $asignaciones = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $cursos = [];
    try {
        $cursos = $db->query("SELECT * FROM tbl_ingles_curso WHERE estado = 'activo'")->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {}
}

// ===== CONFIGURACIÓN VISUAL =====
$niveles_ingles = [
    'beginner' => ['label' => '🌱 Principiante A1', 'color' => 'success', 'gradient' => 'from-green-400 to-emerald-600'],
    'elementary' => ['label' => '📚 Básico A2', 'color' => 'info', 'gradient' => 'from-cyan-400 to-blue-600'],
    'pre-intermediate' => ['label' => '🚀 Pre-Intermedio B1', 'color' => 'primary', 'gradient' => 'from-blue-400 to-indigo-600'],
    'intermediate' => ['label' => '⭐ Intermedio B1+', 'color' => 'warning', 'gradient' => 'from-amber-400 to-orange-600'],
    'upper-intermediate' => ['label' => '🏆 Intermedio Alto B2', 'color' => 'orange', 'gradient' => 'from-orange-400 to-red-600'],
    'advanced' => ['label' => '🎓 Avanzado C1', 'color' => 'danger', 'gradient' => 'from-red-400 to-rose-600'],
    'proficient' => ['label' => '👑 Dominio C2', 'color' => 'purple', 'gradient' => 'from-purple-400 to-violet-600']
];

$tipos_leccion = [
    'grammar' => ['label' => 'Gramática', 'icon' => 'fa-spell-check', 'color' => 'primary', 'bg' => 'bg-primary/10'],
    'vocabulary' => ['label' => 'Vocabulario', 'icon' => 'fa-book-open', 'color' => 'info', 'bg' => 'bg-info/10'],
    'speaking' => ['label' => 'Speaking 🗣️', 'icon' => 'fa-microphone', 'color' => 'success', 'bg' => 'bg-success/10'],
    'reading' => ['label' => 'Reading 📖', 'icon' => 'fa-book-reader', 'color' => 'warning', 'bg' => 'bg-warning/10'],
    'listening' => ['label' => 'Listening 🎧', 'icon' => 'fa-headphones', 'color' => 'danger', 'bg' => 'bg-danger/10'],
    'writing' => ['label' => 'Writing ✍️', 'icon' => 'fa-pen', 'color' => 'purple', 'bg' => 'bg-purple-100'],
    'conversation' => ['label' => 'Conversación 💬', 'icon' => 'fa-comments', 'color' => 'teal', 'bg' => 'bg-teal-100'],
    'pronunciation' => ['label' => 'Pronunciación 🔊', 'icon' => 'fa-volume-up', 'color' => 'pink', 'bg' => 'bg-pink-100']
];

// Calcular nivel actual del estudiante
$nivel_actual = 'beginner';
$progreso_nivel = 0;
if ($rol == 'estudiante' && $stats['puntaje_total']) {
    if ($stats['puntaje_total'] >= 500) { $nivel_actual = 'proficient'; $progreso_nivel = 100; }
    elseif ($stats['puntaje_total'] >= 350) { $nivel_actual = 'advanced'; $progreso_nivel = min(100, ($stats['puntaje_total'] - 350) / 150 * 100); }
    elseif ($stats['puntaje_total'] >= 200) { $nivel_actual = 'upper-intermediate'; $progreso_nivel = min(100, ($stats['puntaje_total'] - 200) / 150 * 100); }
    elseif ($stats['puntaje_total'] >= 100) { $nivel_actual = 'intermediate'; $progreso_nivel = min(100, ($stats['puntaje_total'] - 100) / 100 * 100); }
    elseif ($stats['puntaje_total'] >= 50) { $nivel_actual = 'pre-intermediate'; $progreso_nivel = min(100, ($stats['puntaje_total'] - 50) / 50 * 100); }
    elseif ($stats['puntaje_total'] >= 20) { $nivel_actual = 'elementary'; $progreso_nivel = min(100, ($stats['puntaje_total'] - 20) / 30 * 100); }
    else { $progreso_nivel = min(100, $stats['puntaje_total'] / 20 * 100); }
}

// Cursos destacados (con imágenes placeholder)
$cursos_destacados = array_slice($cursos, 0, 3);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>🇺🇸 English Plus - Aprende Inglés</title>
    
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
            --gradient-secondary: linear-gradient(135deg, #2b70c9 0%, #1e40af 100%);
            --gradient-accent: linear-gradient(135deg, #ffc800 0%, #f59e0b 100%);
            --shadow-sm: 0 1px 2px rgba(0,0,0,0.05);
            --shadow: 0 4px 6px -1px rgba(0,0,0,0.1), 0 2px 4px -1px rgba(0,0,0,0.06);
            --shadow-lg: 0 10px 15px -3px rgba(0,0,0,0.1), 0 4px 6px -2px rgba(0,0,0,0.05);
            --shadow-xl: 0 25px 50px -12px rgba(0,0,0,0.25);
            --radius: 16px;
            --radius-lg: 24px;
        }
        
        * { margin: 0; padding: 0; box-sizing: border-box; }
        
        body {
            font-family: 'Poppins', 'Segoe UI', system-ui, sans-serif;
            background: linear-gradient(135deg, #f0f9ff 0%, #fef3c7 50%, #f0fdf4 100%);
            min-height: 100vh;
            color: var(--dark);
            overflow-x: hidden;
        }
        
        /* ===== HERO SECTION ===== */
        .hero-section {
            background: var(--gradient-primary);
            position: relative;
            overflow: hidden;
            padding: 60px 0 40px;
            margin-bottom: 30px;
        }
        
        .hero-section::before {
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
        
        .hero-section::after {
            content: '';
            position: absolute;
            bottom: -30%;
            left: -10%;
            width: 400px;
            height: 400px;
            background: radial-gradient(circle, rgba(255,255,255,0.1) 0%, transparent 70%);
            border-radius: 50%;
            animation: float 15s ease-in-out infinite reverse;
        }
        
        @keyframes float {
            0%, 100% { transform: translateY(0) rotate(0deg); }
            50% { transform: translateY(-30px) rotate(180deg); }
        }
        
        .hero-content {
            position: relative;
            z-index: 2;
        }
        
        .hero-badge {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            background: rgba(255,255,255,0.2);
            color: white;
            padding: 8px 20px;
            border-radius: 50px;
            font-size: 0.9rem;
            font-weight: 500;
            margin-bottom: 20px;
            backdrop-filter: blur(10px);
            border: 1px solid rgba(255,255,255,0.3);
        }
        
        .hero-title {
            font-size: 2.5rem;
            font-weight: 700;
            color: white;
            margin-bottom: 15px;
            line-height: 1.2;
        }
        
        .hero-subtitle {
            font-size: 1.1rem;
            color: rgba(255,255,255,0.9);
            margin-bottom: 30px;
            max-width: 500px;
        }
        
        .hero-stats {
            display: flex;
            gap: 30px;
            margin-top: 30px;
        }
        
        .hero-stat {
            text-align: center;
        }
        
        .hero-stat-value {
            font-size: 1.8rem;
            font-weight: 700;
            color: white;
            display: block;
        }
        
        .hero-stat-label {
            font-size: 0.85rem;
            color: rgba(255,255,255,0.8);
        }
        
        .user-profile-hero {
            display: flex;
            align-items: center;
            gap: 12px;
            background: rgba(255,255,255,0.15);
            padding: 10px 20px;
            border-radius: 50px;
            backdrop-filter: blur(10px);
            border: 1px solid rgba(255,255,255,0.3);
        }
        
        .user-avatar {
            width: 45px;
            height: 45px;
            border-radius: 50%;
            background: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 600;
            color: var(--primary);
            font-size: 1.2rem;
        }
        
        .user-name {
            color: white;
            font-weight: 600;
        }
        
        .user-level {
            font-size: 0.8rem;
            color: rgba(255,255,255,0.8);
        }
        
        /* ===== LEVEL PROGRESS BAR ===== */
        .level-progress {
            background: white;
            border-radius: var(--radius-lg);
            padding: 20px;
            margin-bottom: 30px;
            box-shadow: var(--shadow-lg);
            display: flex;
            align-items: center;
            gap: 20px;
        }
        
        .level-icon {
            width: 70px;
            height: 70px;
            border-radius: 20px;
            background: var(--gradient-primary);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 2rem;
            color: white;
            flex-shrink: 0;
        }
        
        .level-info {
            flex: 1;
        }
        
        .level-title {
            font-weight: 600;
            font-size: 1.1rem;
            margin-bottom: 5px;
        }
        
        .level-bar {
            height: 10px;
            background: #e2e8f0;
            border-radius: 10px;
            overflow: hidden;
            margin-bottom: 5px;
        }
        
        .level-fill {
            height: 100%;
            background: var(--gradient-primary);
            border-radius: 10px;
            transition: width 0.5s ease;
        }
        
        .level-text {
            font-size: 0.85rem;
            color: #64748b;
        }
        
        .level-next {
            text-align: right;
            font-size: 0.85rem;
            color: #64748b;
        }
        
        .level-next strong {
            color: var(--primary);
            font-weight: 600;
        }
        
        /* ===== QUICK ACTIONS ===== */
        .quick-actions {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(140px, 1fr));
            gap: 15px;
            margin-bottom: 30px;
        }
        
        .quick-action {
            background: white;
            border-radius: var(--radius);
            padding: 20px 15px;
            text-align: center;
            text-decoration: none;
            color: var(--dark);
            box-shadow: var(--shadow);
            transition: all 0.3s ease;
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 10px;
        }
        
        .quick-action:hover {
            transform: translateY(-5px);
            box-shadow: var(--shadow-xl);
            color: var(--primary);
        }
        
        .quick-action-icon {
            width: 55px;
            height: 55px;
            border-radius: 16px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.4rem;
            color: white;
        }
        
        .quick-action-icon.primary { background: var(--gradient-primary); }
        .quick-action-icon.secondary { background: var(--gradient-secondary); }
        .quick-action-icon.accent { background: var(--gradient-accent); }
        .quick-action-icon.success { background: linear-gradient(135deg, #4ade80, #22c55e); }
        .quick-action-icon.info { background: linear-gradient(135deg, #38bdf8, #0ea5e9); }
        .quick-action-icon.purple { background: linear-gradient(135deg, #a78bfa, #8b5cf6); }
        
        .quick-action-label {
            font-size: 0.85rem;
            font-weight: 500;
        }
        
        /* ===== COURSE CARDS ===== */
        .section-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 25px;
        }
        
        .section-title {
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--dark);
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .section-title i {
            color: var(--primary);
        }
        
        .view-all {
            color: var(--secondary);
            font-weight: 500;
            text-decoration: none;
            display: flex;
            align-items: center;
            gap: 5px;
            transition: gap 0.2s;
        }
        
        .view-all:hover {
            gap: 10px;
        }
        
        .courses-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(320px, 1fr));
            gap: 25px;
            margin-bottom: 40px;
        }
        
        .course-card {
            background: white;
            border-radius: var(--radius-lg);
            overflow: hidden;
            box-shadow: var(--shadow);
            transition: all 0.3s ease;
            cursor: pointer;
            position: relative;
        }
        
        .course-card:hover {
            transform: translateY(-8px);
            box-shadow: var(--shadow-xl);
        }
        
        .course-card::before {
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
        
        .course-card:hover::before {
            opacity: 1;
        }
        
        .course-image {
            height: 160px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            display: flex;
            align-items: center;
            justify-content: center;
            position: relative;
            overflow: hidden;
        }
        
        .course-image::before {
            content: '';
            position: absolute;
            top: -50%;
            left: -50%;
            width: 200%;
            height: 200%;
            background: radial-gradient(circle, rgba(255,255,255,0.1) 0%, transparent 70%);
            animation: pulse 4s ease-in-out infinite;
        }
        
        @keyframes pulse {
            0%, 100% { transform: scale(1); }
            50% { transform: scale(1.1); }
        }
        
        .course-image i {
            font-size: 4rem;
            color: rgba(255,255,255,0.8);
            position: relative;
            z-index: 1;
        }
        
        .course-badge {
            position: absolute;
            top: 15px;
            right: 15px;
            padding: 6px 14px;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
            color: white;
            z-index: 2;
        }
        
        .course-content {
            padding: 25px;
        }
        
        .course-title {
            font-weight: 600;
            font-size: 1.2rem;
            margin-bottom: 10px;
            color: var(--dark);
        }
        
        .course-desc {
            color: #64748b;
            font-size: 0.9rem;
            margin-bottom: 20px;
            line-height: 1.5;
        }
        
        .course-meta {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding-top: 15px;
            border-top: 1px solid #e2e8f0;
        }
        
        .course-lessons {
            display: flex;
            align-items: center;
            gap: 5px;
            color: #64748b;
            font-size: 0.9rem;
        }
        
        .btn-start {
            background: var(--gradient-primary);
            color: white;
            border: none;
            padding: 10px 24px;
            border-radius: 12px;
            font-weight: 600;
            font-size: 0.9rem;
            display: flex;
            align-items: center;
            gap: 8px;
            transition: all 0.2s;
            cursor: pointer;
        }
        
        .btn-start:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(88, 204, 2, 0.4);
        }
        
        /* ===== ASSIGNMENT CARDS ===== */
        .assignment-card {
            background: white;
            border-radius: var(--radius);
            padding: 20px;
            margin-bottom: 15px;
            border-left: 4px solid var(--accent);
            box-shadow: var(--shadow);
            transition: all 0.2s;
        }
        
        .assignment-card:hover {
            transform: translateX(5px);
            box-shadow: var(--shadow-lg);
        }
        
        .assignment-card.urgent {
            border-left-color: var(--danger);
            background: linear-gradient(135deg, #fff5f5 0%, #fef2f2 100%);
        }
        
        .assignment-title {
            font-weight: 600;
            font-size: 1.05rem;
            margin-bottom: 8px;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .assignment-meta {
            display: flex;
            gap: 20px;
            font-size: 0.85rem;
            color: #64748b;
            margin-bottom: 15px;
            flex-wrap: wrap;
        }
        
        .assignment-meta i {
            margin-right: 4px;
        }
        
        /* ===== PROGRESS STATS ===== */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 20px;
            margin-bottom: 40px;
        }
        
        .stat-card {
            background: white;
            border-radius: var(--radius);
            padding: 25px;
            text-align: center;
            box-shadow: var(--shadow);
            transition: all 0.3s ease;
        }
        
        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: var(--shadow-lg);
        }
        
        .stat-icon {
            width: 65px;
            height: 65px;
            border-radius: 20px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.6rem;
            color: white;
            margin: 0 auto 15px;
        }
        
        .stat-icon.completed { background: linear-gradient(135deg, #4ade80, #22c55e); }
        .stat-icon.points { background: linear-gradient(135deg, #ffc800, #f59e0b); }
        .stat-icon.average { background: linear-gradient(135deg, #38bdf8, #0ea5e9); }
        .stat-icon.streak { background: linear-gradient(135deg, #f97316, #ea580c); }
        
        .stat-value {
            font-size: 2rem;
            font-weight: 700;
            color: var(--dark);
            margin-bottom: 5px;
        }
        
        .stat-label {
            color: #64748b;
            font-size: 0.9rem;
        }
        
        /* ===== LESSON CARDS ===== */
        .lessons-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
            gap: 20px;
        }
        
        .lesson-card {
            background: white;
            border-radius: var(--radius);
            padding: 20px;
            box-shadow: var(--shadow);
            transition: all 0.2s;
            border-left: 4px solid var(--primary);
            cursor: pointer;
        }
        
        .lesson-card:hover {
            transform: translateX(5px);
            box-shadow: var(--shadow-lg);
        }
        
        .lesson-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 15px;
        }
        
        .lesson-type-badge {
            padding: 5px 12px;
            border-radius: 15px;
            font-size: 0.75rem;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 5px;
        }
        
        .lesson-title {
            font-weight: 600;
            margin-bottom: 10px;
            font-size: 1.05rem;
        }
        
        .lesson-desc {
            color: #64748b;
            font-size: 0.9rem;
            margin-bottom: 15px;
            display: -webkit-box;
            -webkit-line-clamp: 2;
            -webkit-box-orient: vertical;
            overflow: hidden;
        }
        
        .lesson-footer {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding-top: 15px;
            border-top: 1px solid #e2e8f0;
        }
        
        .lesson-course {
            font-size: 0.85rem;
            color: #64748b;
        }
        
        .btn-lesson {
            background: var(--primary);
            color: white;
            border: none;
            padding: 8px 18px;
            border-radius: 10px;
            font-size: 0.85rem;
            font-weight: 500;
            display: flex;
            align-items: center;
            gap: 5px;
            transition: all 0.2s;
        }
        
        .btn-lesson:hover {
            background: var(--primary-dark);
            transform: translateY(-2px);
        }
        
        /* ===== ACHIEVEMENTS ===== */
        .achievements-section {
            background: white;
            border-radius: var(--radius-lg);
            padding: 30px;
            margin-bottom: 40px;
            box-shadow: var(--shadow);
        }
        
        .achievements-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(100px, 1fr));
            gap: 20px;
            margin-top: 20px;
        }
        
        .achievement-item {
            text-align: center;
            padding: 15px;
            border-radius: var(--radius);
            background: #f8fafc;
            transition: all 0.2s;
        }
        
        .achievement-item:hover {
            background: var(--light);
            transform: translateY(-3px);
        }
        
        .achievement-icon {
            width: 60px;
            height: 60px;
            border-radius: 50%;
            background: var(--gradient-accent);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
            color: white;
            margin: 0 auto 10px;
            box-shadow: 0 4px 15px rgba(255, 200, 0, 0.4);
        }
        
        .achievement-name {
            font-size: 0.8rem;
            font-weight: 500;
            color: var(--dark);
        }
        
        .achievement-date {
            font-size: 0.7rem;
            color: #64748b;
            margin-top: 3px;
        }
        
        /* ===== PREMIUM BANNER ===== */
        .premium-banner {
            background: linear-gradient(135deg, #1e293b 0%, #334155 100%);
            border-radius: var(--radius-lg);
            padding: 30px;
            margin-bottom: 40px;
            color: white;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 30px;
            position: relative;
            overflow: hidden;
        }
        
        .premium-banner::before {
            content: '';
            position: absolute;
            top: -50%;
            right: -20%;
            width: 400px;
            height: 400px;
            background: radial-gradient(circle, rgba(255,255,255,0.1) 0%, transparent 70%);
            border-radius: 50%;
        }
        
        .premium-content {
            position: relative;
            z-index: 2;
            max-width: 60%;
        }
        
        .premium-title {
            font-size: 1.4rem;
            font-weight: 700;
            margin-bottom: 10px;
        }
        
        .premium-desc {
            color: rgba(255,255,255,0.8);
            margin-bottom: 20px;
            font-size: 0.95rem;
        }
        
        .btn-premium {
            background: var(--gradient-accent);
            color: var(--dark);
            border: none;
            padding: 12px 30px;
            border-radius: 12px;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: all 0.2s;
        }
        
        .btn-premium:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(255, 200, 0, 0.4);
        }
        
        .premium-icon {
            font-size: 4rem;
            opacity: 0.2;
            position: relative;
            z-index: 1;
        }
        
        /* ===== RESPONSIVE ===== */
        @media (max-width: 992px) {
            .hero-title { font-size: 2rem; }
            .hero-stats { flex-wrap: wrap; gap: 20px; }
            .courses-grid { grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); }
            .premium-banner { flex-direction: column; text-align: center; }
            .premium-content { max-width: 100%; }
        }
        
        @media (max-width: 768px) {
            .hero-section { padding: 40px 0 30px; }
            .hero-title { font-size: 1.75rem; }
            .level-progress { flex-direction: column; text-align: center; }
            .level-next { text-align: center; }
            .quick-actions { grid-template-columns: repeat(3, 1fr); }
            .courses-grid { grid-template-columns: 1fr; }
            .stats-grid { grid-template-columns: repeat(2, 1fr); }
        }
        
        @media (max-width: 480px) {
            .quick-actions { grid-template-columns: repeat(2, 1fr); }
            .stats-grid { grid-template-columns: 1fr 1fr; }
            .hero-stats { justify-content: center; }
        }
        
        /* ===== ANIMATIONS ===== */
        @keyframes fadeInUp {
            from { opacity: 0; transform: translateY(30px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        .animate-in {
            animation: fadeInUp 0.6s ease forwards;
            opacity: 0;
        }
        
        .animate-in:nth-child(1) { animation-delay: 0.1s; }
        .animate-in:nth-child(2) { animation-delay: 0.2s; }
        .animate-in:nth-child(3) { animation-delay: 0.3s; }
        .animate-in:nth-child(4) { animation-delay: 0.4s; }
        .animate-in:nth-child(5) { animation-delay: 0.5s; }
        
        /* ===== TABS ===== */
        .nav-pills .nav-link {
            color: #64748b;
            padding: 12px 24px;
            border-radius: 12px;
            margin: 0 4px;
            font-weight: 500;
            transition: all 0.2s;
        }
        
        .nav-pills .nav-link:hover {
            background: #f1f5f9;
            color: var(--dark);
        }
        
        .nav-pills .nav-link.active {
            background: var(--gradient-primary);
            color: white;
            box-shadow: 0 4px 15px rgba(88, 204, 2, 0.3);
        }
        
        .nav-pills .nav-link i {
            margin-right: 6px;
        }
        
        /* ===== VIDEO CONTAINER ===== */
        .video-card {
            background: white;
            border-radius: var(--radius);
            overflow: hidden;
            box-shadow: var(--shadow);
            margin-bottom: 20px;
        }
        
        .video-thumbnail {
            position: relative;
            padding-bottom: 56.25%;
            background: #000;
            cursor: pointer;
        }
        
        .video-thumbnail img {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            object-fit: cover;
        }
        
        .video-play {
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            width: 60px;
            height: 60px;
            border-radius: 50%;
            background: rgba(255,255,255,0.9);
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--primary);
            font-size: 1.5rem;
            transition: all 0.2s;
        }
        
        .video-thumbnail:hover .video-play {
            transform: translate(-50%, -50%) scale(1.1);
            background: white;
        }
        
        .video-info {
            padding: 15px 20px;
        }
        
        .video-title {
            font-weight: 600;
            margin-bottom: 5px;
            font-size: 1rem;
        }
        
        .video-meta {
            font-size: 0.85rem;
            color: #64748b;
        }
    </style>
</head>
<body>
    <!-- Hero Section -->
    <section class="hero-section">
        <div class="container">
            <div class="row align-items-center">
                <div class="col-lg-8 hero-content animate-in">
                    <div class="hero-badge">
                        <i class="fas fa-sparkles"></i>
                        Aprende inglés de forma divertida
                    </div>
                    <h1 class="hero-title">
                        🇺🇸 Domina el Inglés con Educación Plus
                    </h1>
                    <p class="hero-subtitle">
                        Lecciones interactivas, ejercicios prácticos y seguimiento personalizado. ¡Comienza tu viaje hoy!
                    </p>
                    
                    <div class="hero-stats">
                        <div class="hero-stat">
                            <span class="hero-stat-value"><?= $stats['lecciones_completadas'] ?? 0 ?></span>
                            <span class="hero-stat-label">Lecciones</span>
                        </div>
                        <div class="hero-stat">
                            <span class="hero-stat-value"><?= $stats['puntaje_total'] ?? 0 ?></span>
                            <span class="hero-stat-label">Puntos XP</span>
                        </div>
                        <div class="hero-stat">
                            <span class="hero-stat-value">🔥</span>
                            <span class="hero-stat-label">Racha</span>
                        </div>
                    </div>
                </div>
                
                <div class="col-lg-4 text-lg-end animate-in" style="animation-delay: 0.2s">
                    <div class="user-profile-hero ms-auto">
                        <div class="user-avatar">
                            <?= strtoupper(substr($nombre_usuario, 0, 1)) ?>
                        </div>
                        <div>
                            <div class="user-name"><?= htmlspecialchars($nombre_usuario) ?></div>
                            <div class="user-level">
                                <?= $niveles_ingles[$nivel_actual]['label'] ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- Main Content -->
    <div class="container mb-5">
        <!-- Level Progress -->
        <?php if ($rol == 'estudiante'): ?>
        <div class="level-progress animate-in">
            <div class="level-icon">
                <i class="fas <?= $niveles_ingles[$nivel_actual]['icon'] ?? 'fa-star' ?>"></i>
            </div>
            <div class="level-info">
                <div class="level-title">Tu Nivel: <?= $niveles_ingles[$nivel_actual]['label'] ?></div>
                <div class="level-bar">
                    <div class="level-fill" style="width: <?= $progreso_nivel ?>%"></div>
                </div>
                <div class="level-text"><?= round($progreso_nivel) ?>% completado</div>
            </div>
            <div class="level-next">
                Siguiente: <strong><?= $niveles_ingles[$nivel_actual === 'proficient' ? 'proficient' : array_keys($niveles_ingles)[array_search($nivel_actual, array_keys($niveles_ingles)) + 1] ?? 'proficient'] ?? '¡Maestro!' ?></strong>
            </div>
        </div>
        <?php endif; ?>

        <!-- Quick Actions -->
        <div class="quick-actions">
            <a href="#" class="quick-action animate-in" onclick="iniciarPractica('vocabulary')">
                <div class="quick-action-icon primary">
                    <i class="fas fa-book-open"></i>
                </div>
                <span class="quick-action-label">Vocabulario</span>
            </a>
            <a href="#" class="quick-action animate-in" onclick="iniciarPractica('grammar')">
                <div class="quick-action-icon secondary">
                    <i class="fas fa-spell-check"></i>
                </div>
                <span class="quick-action-label">Gramática</span>
            </a>
            <a href="#" class="quick-action animate-in" onclick="iniciarPractica('listening')">
                <div class="quick-action-icon accent">
                    <i class="fas fa-headphones"></i>
                </div>
                <span class="quick-action-label">Listening</span>
            </a>
            <a href="#" class="quick-action animate-in" onclick="iniciarPractica('speaking')">
                <div class="quick-action-icon success">
                    <i class="fas fa-microphone"></i>
                </div>
                <span class="quick-action-label">Speaking</span>
            </a>
            <a href="#" class="quick-action animate-in" onclick="window.location.href='reportes.php'">
                <div class="quick-action-icon info">
                    <i class="fas fa-chart-bar"></i>
                </div>
                <span class="quick-action-label">Mis Reportes</span>
            </a>
            <a href="#" class="quick-action animate-in" onclick="window.location.href='logros.php'">
                <div class="quick-action-icon purple">
                    <i class="fas fa-trophy"></i>
                </div>
                <span class="quick-action-label">Logros</span>
            </a>
        </div>

        <!-- Navigation Tabs -->
        <ul class="nav nav-pills mb-4" id="inglesTabs" role="tablist">
            <li class="nav-item">
                <a class="nav-link active" data-bs-toggle="pill" href="#cursos">
                    <i class="fas fa-book"></i> Cursos
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link" data-bs-toggle="pill" href="#lecciones">
                    <i class="fas fa-graduation-cap"></i> Lecciones
                </a>
            </li>
            <?php if ($rol == 'estudiante'): ?>
            <li class="nav-item position-relative">
                <a class="nav-link" data-bs-toggle="pill" href="#asignaciones">
                    <i class="fas fa-tasks"></i> Asignaciones
                    <?php if (!empty($asignaciones)): ?>
                    <span class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger" style="font-size: 0.7rem">
                        <?= count($asignaciones) ?>
                    </span>
                    <?php endif; ?>
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link" data-bs-toggle="pill" href="#progreso">
                    <i class="fas fa-chart-line"></i> Mi Progreso
                </a>
            </li>
            <?php endif; ?>
            <?php if ($rol == 'profesor'): ?>
            <li class="nav-item">
                <a class="nav-link" data-bs-toggle="pill" href="#asignar">
                    <i class="fas fa-clipboard-check"></i> Asignar
                </a>
            </li>
            <?php endif; ?>
            <li class="nav-item">
                <a class="nav-link" data-bs-toggle="pill" href="#practica">
                    <i class="fas fa-gamepad"></i> Práctica
                </a>
            </li>
        </ul>

        <!-- Tab Content -->
        <div class="tab-content" id="inglesTabsContent">
            <!-- Cursos Tab -->
            <div class="tab-pane fade show active" id="cursos">
                <div class="section-header">
                    <h3 class="section-title">
                        <i class="fas fa-graduation-cap"></i> Cursos Disponibles
                    </h3>
                    <a href="#" class="view-all">Ver todos <i class="fas fa-arrow-right"></i></a>
                </div>
                
                <div class="courses-grid">
                    <?php 
                    $cursos = $cursos ?? [];
                    if (empty($cursos)): 
                    ?>
                    <div class="col-12">
                        <div class="alert alert-info text-center py-5">
                            <i class="fas fa-book fa-3x mb-3"></i>
                            <h5>Próximamente</h5>
                            <p class="mb-0">Nuevos cursos de inglés en camino 🚀</p>
                        </div>
                    </div>
                    <?php else: ?>
                    <?php foreach ($cursos as $curso): 
                        $nivel = $niveles_ingles[$curso['nivel']] ?? ['label' => $curso['nivel'], 'color' => 'secondary', 'gradient' => 'from-gray-400 to-gray-600'];
                        
                        // Contar lecciones
                        $lecciones_count = 0;
                        try {
                            $q = "SELECT COUNT(*) as total FROM tbl_ingles_leccion WHERE id_curso = :id AND estado = 'publicado'";
                            $s = $db->prepare($q);
                            $s->bindValue(':id', $curso['id'], PDO::PARAM_INT);
                            $s->execute();
                            $lecciones_count = $s->fetch(PDO::FETCH_ASSOC)['total'] ?? 0;
                        } catch (Exception $e) {}
                    ?>
                    <div class="course-card animate-in" onclick="verCurso(<?= $curso['id'] ?>)">
                        <div class="course-image" style="background: linear-gradient(135deg, <?= $nivel['color'] == 'success' ? '#4ade80, #22c55e' : ($nivel['color'] == 'info' ? '#38bdf8, #0ea5e9' : ($nivel['color'] == 'warning' ? '#fbbf24, #f59e0b' : ($nivel['color'] == 'danger' ? '#f87171, #ef4444' : '#6366f1, #8b5cf6'))) ?>);">
                            <i class="fas fa-language"></i>
                            <span class="course-badge bg-white text-dark">
                                <?= $nivel['label'] ?>
                            </span>
                        </div>
                        <div class="course-content">
                            <h4 class="course-title"><?= htmlspecialchars($curso['nombre']) ?></h4>
                            <p class="course-desc"><?= htmlspecialchars(substr($curso['descripcion'] ?? 'Curso completo para mejorar tu inglés', 0, 120)) ?>...</p>
                            
                            <div class="course-meta">
                                <span class="course-lessons">
                                    <i class="fas fa-book-open"></i> <?= $lecciones_count ?> lecciones
                                </span>
                                <button class="btn-start">
                                    <i class="fas fa-play"></i> Comenzar
                                </button>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Lecciones Tab -->
            <div class="tab-pane fade" id="lecciones">
                <div class="section-header">
                    <h3 class="section-title">
                        <i class="fas fa-book-open"></i> Lecciones por Tipo
                    </h3>
                </div>
                
                <div class="btn-group flex-wrap mb-4" role="group">
                    <?php foreach ($tipos_leccion as $key => $tipo): ?>
                    <button type="button" class="btn btn-outline-<?= $tipo['color'] ?> m-1 px-3" onclick="filtrarLecciones('<?= $key ?>')">
                        <i class="fas <?= $tipo['icon'] ?>"></i> <?= $tipo['label'] ?>
                    </button>
                    <?php endforeach; ?>
                </div>
                
                <div class="lessons-grid" id="leccionesContainer">
                    <?php
                    try {
                        $query = "SELECT l.*, c.nombre as curso_nombre, c.nivel
                                  FROM tbl_ingles_leccion l
                                  JOIN tbl_ingles_curso c ON l.id_curso = c.id
                                  WHERE l.estado = 'publicado'
                                  ORDER BY l.orden ASC LIMIT 8";
                        $lecciones = $db->query($query)->fetchAll(PDO::FETCH_ASSOC);
                        
                        foreach ($lecciones as $leccion):
                            $tipo = $tipos_leccion[$leccion['tipo']] ?? ['label' => $leccion['tipo'], 'icon' => 'fa-book', 'color' => 'secondary', 'bg' => 'bg-secondary/10'];
                        ?>
                        <div class="lesson-card animate-in">
                            <div class="lesson-header">
                                <span class="lesson-type-badge <?= $tipo['bg'] ?> text-<?= $tipo['color'] ?>">
                                    <i class="fas <?= $tipo['icon'] ?>"></i> <?= $tipo['label'] ?>
                                </span>
                                <small class="text-muted">
                                    <i class="fas fa-clock"></i> <?= $leccion['duracion_minutos'] ?> min
                                </small>
                            </div>
                            <h5 class="lesson-title"><?= htmlspecialchars($leccion['titulo']) ?></h5>
                            <p class="lesson-desc"><?= htmlspecialchars(substr($leccion['descripcion'] ?? '', 0, 100)) ?>...</p>
                            <div class="lesson-footer">
                                <small class="lesson-course">
                                    <i class="fas fa-graduation-cap"></i> <?= htmlspecialchars($leccion['curso_nombre']) ?>
                                </small>
                                <button class="btn-lesson" onclick="iniciarLeccion(<?= $leccion['id'] ?>)">
                                    <i class="fas fa-play"></i> Iniciar
                                </button>
                            </div>
                        </div>
                        <?php endforeach;
                    } catch (Exception $e) {
                        echo '<p class="text-muted">Cargando lecciones...</p>';
                    }
                    ?>
                </div>
            </div>

            <?php if ($rol == 'estudiante'): ?>
            <!-- Asignaciones Tab -->
            <div class="tab-pane fade" id="asignaciones">
                <div class="section-header">
                    <h3 class="section-title">
                        <i class="fas fa-tasks"></i> Mis Asignaciones
                    </h3>
                </div>
                
                <?php if (empty($asignaciones)): ?>
                <div class="text-center py-5">
                    <div style="font-size: 4rem; margin-bottom: 20px">🎉</div>
                    <h4>¡Todo al día!</h4>
                    <p class="text-muted mb-4">No tienes asignaciones pendientes. ¡Sigue aprendiendo!</p>
                    <button class="btn btn-primary" onclick="window.location.href='#cursos'">
                        <i class="fas fa-book"></i> Explorar Cursos
                    </button>
                </div>
                <?php else: ?>
                <?php foreach ($asignaciones as $asignacion): 
                    $dias_restantes = $asignacion['fecha_limite'] ? ceil((strtotime($asignacion['fecha_limite']) - time()) / (60 * 60 * 24)) : 0;
                    $es_urgente = $dias_restantes <= 2 && $dias_restantes >= 0;
                ?>
                <div class="assignment-card <?= $es_urgente ? 'urgent' : '' ?> animate-in">
                    <div class="d-flex justify-content-between align-items-start">
                        <div>
                            <h5 class="assignment-title">
                                <i class="fas <?= $es_urgente ? 'fa-exclamation-triangle text-danger' : 'fa-clipboard-list' ?>"></i>
                                <?= htmlspecialchars($asignacion['leccion_titulo'] ?? $asignacion['curso_nombre'] ?? 'Asignación') ?>
                            </h5>
                            <p class="text-muted mb-2"><?= htmlspecialchars($asignacion['instrucciones'] ?? 'Sin instrucciones') ?></p>
                            <div class="assignment-meta">
                                <span><i class="fas fa-calendar"></i> <?= $asignacion['fecha_limite'] ? 'Entrega: ' . date('d/m/Y', strtotime($asignacion['fecha_limite'])) : 'Sin límite' ?></span>
                                <span><i class="fas fa-star"></i> Nota mínima: <?= $asignacion['puntaje_minimo'] ?></span>
                                <?php if ($dias_restantes > 0): ?>
                                <span class="<?= $es_urgente ? 'text-danger fw-bold' : '' ?>">
                                    <i class="fas fa-clock"></i> <?= $dias_restantes ?> día<?= $dias_restantes > 1 ? 's' : '' ?> restantes
                                </span>
                                <?php endif; ?>
                            </div>
                        </div>
                        <button class="btn btn-success" onclick="iniciarAsignacion(<?= $asignacion['id'] ?>)">
                            <i class="fas fa-play"></i> Comenzar
                        </button>
                    </div>
                </div>
                <?php endforeach; ?>
                <?php endif; ?>
            </div>

            <!-- Progreso Tab -->
            <div class="tab-pane fade" id="progreso">
                <div class="section-header">
                    <h3 class="section-title">
                        <i class="fas fa-chart-line"></i> Mi Progreso
                    </h3>
                </div>
                
                <!-- Stats -->
                <div class="stats-grid">
                    <div class="stat-card animate-in">
                        <div class="stat-icon completed">
                            <i class="fas fa-check-circle"></i>
                        </div>
                        <div class="stat-value"><?= $stats['lecciones_completadas'] ?? 0 ?></div>
                        <div class="stat-label">Lecciones Completadas</div>
                    </div>
                    <div class="stat-card animate-in">
                        <div class="stat-icon points">
                            <i class="fas fa-star"></i>
                        </div>
                        <div class="stat-value"><?= $stats['puntaje_total'] ?? 0 ?></div>
                        <div class="stat-label">Puntos XP Totales</div>
                    </div>
                    <div class="stat-card animate-in">
                        <div class="stat-icon average">
                            <i class="fas fa-trophy"></i>
                        </div>
                        <div class="stat-value"><?= number_format($stats['promedio_puntaje'] ?? 0, 1) ?></div>
                        <div class="stat-label">Promedio de Puntaje</div>
                    </div>
                    <div class="stat-card animate-in">
                        <div class="stat-icon streak">
                            <i class="fas fa-fire"></i>
                        </div>
                        <div class="stat-value">🔥</div>
                        <div class="stat-label">Racha Actual</div>
                    </div>
                </div>
                
                <!-- Achievements -->
                <div class="achievements-section animate-in">
                    <h5 class="mb-3"><i class="fas fa-medal text-warning"></i> Tus Logros</h5>
                    <div class="achievements-grid">
                        <div class="achievement-item">
                            <div class="achievement-icon">🎯</div>
                            <div class="achievement-name">Primer Paso</div>
                            <div class="achievement-date">Completado</div>
                        </div>
                        <div class="achievement-item">
                            <div class="achievement-icon">📚</div>
                            <div class="achievement-name">10 Lecciones</div>
                            <div class="achievement-date"><?= $stats['lecciones_completadas'] >= 10 ? '✓' : '0/'.$stats['lecciones_completadas'] ?></div>
                        </div>
                        <div class="achievement-item">
                            <div class="achievement-icon">⭐</div>
                            <div class="achievement-name">100 Puntos</div>
                            <div class="achievement-date"><?= ($stats['puntaje_total'] ?? 0) >= 100 ? '✓' : ($stats['puntaje_total'] ?? 0).'/100' ?></div>
                        </div>
                        <div class="achievement-item">
                            <div class="achievement-icon">🔥</div>
                            <div class="achievement-name">Racha 7 días</div>
                            <div class="achievement-date">En progreso</div>
                        </div>
                    </div>
                </div>
                
                <!-- Recent Lessons -->
                <div class="card border-0 shadow-sm">
                    <div class="card-header bg-white border-0">
                        <h5 class="mb-0"><i class="fas fa-history"></i> Últimas Lecciones</h5>
                    </div>
                    <div class="card-body">
                        <?php if (empty($ultimas_lecciones)): ?>
                        <p class="text-muted text-center py-3">Comienza tu primera lección para ver tu progreso aquí</p>
                        <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-hover align-middle">
                                <thead>
                                    <tr>
                                        <th>Lección</th>
                                        <th>Curso</th>
                                        <th>Tipo</th>
                                        <th>Puntaje</th>
                                        <th>Fecha</th>
                                        <th>Estado</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($ultimas_lecciones as $lec): ?>
                                    <tr>
                                        <td class="fw-medium"><?= htmlspecialchars($lec['titulo']) ?></td>
                                        <td><small class="text-muted"><?= htmlspecialchars($lec['curso_nombre']) ?></small></td>
                                        <td>
                                            <?php $tipo = $tipos_leccion[$lec['tipo']] ?? ['label' => $lec['tipo'], 'color' => 'secondary', 'bg' => 'bg-secondary/10']; ?>
                                            <span class="lesson-type-badge <?= $tipo['bg'] ?> text-<?= $tipo['color'] ?>"><?= $tipo['label'] ?></span>
                                        </td>
                                        <td>
                                            <strong class="<?= ($lec['puntaje'] ?? 0) >= 70 ? 'text-success' : 'text-warning' ?>">
                                                <?= $lec['puntaje'] ?? 0 ?> pts
                                            </strong>
                                        </td>
                                        <td><small><?= $lec['ultimo_intento'] ? date('d/m', strtotime($lec['ultimo_intento'])) : '-' ?></small></td>
                                        <td>
                                            <span class="badge bg-<?= ($lec['estado'] ?? '') == 'completado' ? 'success' : 'warning' ?> rounded-pill">
                                                <?= ucfirst(str_replace('-', ' ', $lec['estado'] ?? 'pendiente')) ?>
                                            </span>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <?php if ($rol == 'profesor'): ?>
            <!-- Asignar Tab (simplificado) -->
            <div class="tab-pane fade" id="asignar">
                <div class="section-header">
                    <h3 class="section-title">
                        <i class="fas fa-clipboard-check"></i> Asignar Lecciones
                    </h3>
                </div>
                <div class="card border-0 shadow-sm">
                    <div class="card-body p-4">
                        <form id="formAsignar" class="row g-3">
                            <div class="col-md-4">
                                <label class="form-label fw-medium">Tipo</label>
                                <select class="form-select" id="tipo_asignacion">
                                    <option value="curso">Curso Completo</option>
                                    <option value="leccion">Lección Específica</option>
                                </select>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label fw-medium">Curso</label>
                                <select class="form-select" id="curso_select">
                                    <option value="">Seleccionar curso</option>
                                    <?php foreach ($cursos as $curso): ?>
                                    <option value="<?= $curso['id'] ?>">
                                        <?= htmlspecialchars($curso['nombre']) ?> (<?= $niveles_ingles[$curso['nivel']]['label'] ?>)
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label fw-medium">Fecha Límite</label>
                                <input type="date" class="form-control" id="fecha_limite">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label fw-medium">Nota Mínima</label>
                                <input type="number" class="form-control" id="puntaje_minimo" value="7.0" min="0" max="10" step="0.1">
                            </div>
                            <div class="col-md-8">
                                <label class="form-label fw-medium">Instrucciones</label>
                                <textarea class="form-control" id="instrucciones" rows="2" placeholder="Instrucciones para los estudiantes..."></textarea>
                            </div>
                            <div class="col-12">
                                <button type="submit" class="btn btn-success px-4">
                                    <i class="fas fa-paper-plane"></i> Asignar a Estudiantes
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <!-- Práctica Libre Tab -->
            <div class="tab-pane fade" id="practica">
                <div class="section-header">
                    <h3 class="section-title">
                        <i class="fas fa-gamepad"></i> Práctica Libre
                    </h3>
                </div>
                
                <p class="text-muted mb-4">Practica inglés con ejercicios interactivos sin presión de calificación</p>
                
                <div class="row mb-5">
                    <div class="col-lg-3 col-md-6 mb-3">
                        <div class="quick-action" onclick="iniciarPractica('vocabulary')">
                            <div class="quick-action-icon info">
                                <i class="fas fa-book-open"></i>
                            </div>
                            <span class="quick-action-label fw-medium">Vocabulario</span>
                            <small class="text-muted">Flashcards interactivas</small>
                        </div>
                    </div>
                    <div class="col-lg-3 col-md-6 mb-3">
                        <div class="quick-action" onclick="iniciarPractica('grammar')">
                            <div class="quick-action-icon primary">
                                <i class="fas fa-spell-check"></i>
                            </div>
                            <span class="quick-action-label fw-medium">Gramática</span>
                            <small class="text-muted">Ejercicios rápidos</small>
                        </div>
                    </div>
                    <div class="col-lg-3 col-md-6 mb-3">
                        <div class="quick-action" onclick="iniciarPractica('listening')">
                            <div class="quick-action-icon danger">
                                <i class="fas fa-headphones"></i>
                            </div>
                            <span class="quick-action-label fw-medium">Listening</span>
                            <small class="text-muted">Comprensión auditiva</small>
                        </div>
                    </div>
                    <div class="col-lg-3 col-md-6 mb-3">
                        <div class="quick-action" onclick="iniciarPractica('speaking')">
                            <div class="quick-action-icon success">
                                <i class="fas fa-microphone"></i>
                            </div>
                            <span class="quick-action-label fw-medium">Speaking</span>
                            <small class="text-muted">Pronunciación con IA</small>
                        </div>
                    </div>
                </div>
                
                <!-- YouTube Karaoke Videos -->
                <h4 class="mb-4"><i class="fab fa-youtube text-danger"></i> Videos Karaoke</h4>
                <p class="text-muted mb-4">Canta y practica inglés con videos musicales</p>
                
                <div class="row">
                    <div class="col-lg-4 col-md-6 mb-4">
                        <div class="video-card">
                            <div class="video-thumbnail">
                                <img src="https://img.youtube.com/vi/dQw4w9WgXcQ/mqdefault.jpg" alt="English Song">
                                <div class="video-play"><i class="fas fa-play"></i></div>
                            </div>
                            <div class="video-info">
                                <h6 class="video-title">English Songs for Beginners 🎵</h6>
                                <p class="video-meta"><i class="fas fa-clock"></i> 15 min • Principiante</p>
                            </div>
                        </div>
                    </div>
                    <div class="col-lg-4 col-md-6 mb-4">
                        <div class="video-card">
                            <div class="video-thumbnail">
                                <img src="https://img.youtube.com/vi/dQw4w9WgXcQ/mqdefault.jpg" alt="Common Phrases">
                                <div class="video-play"><i class="fas fa-play"></i></div>
                            </div>
                            <div class="video-info">
                                <h6 class="video-title">Common Phrases in English 💬</h6>
                                <p class="video-meta"><i class="fas fa-clock"></i> 20 min • Básico</p>
                            </div>
                        </div>
                    </div>
                    <div class="col-lg-4 col-md-6 mb-4">
                        <div class="video-card">
                            <div class="video-thumbnail">
                                <img src="https://img.youtube.com/vi/dQw4w9WgXcQ/mqdefault.jpg" alt="Pronunciation">
                                <div class="video-play"><i class="fas fa-play"></i></div>
                            </div>
                            <div class="video-info">
                                <h6 class="video-title">American Pronunciation Guide 🗣️</h6>
                                <p class="video-meta"><i class="fas fa-clock"></i> 25 min • Intermedio</p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Premium Banner -->
        <?php if ($rol == 'estudiante'): ?>
        <div class="premium-banner animate-in">
            <div class="premium-content">
                <h4 class="premium-title">🚀 Desbloquea English Plus Premium</h4>
                <p class="premium-desc">Accede a lecciones ilimitadas, certificados oficiales, práctica offline y más funciones exclusivas.</p>
                <button class="btn-premium">
                    <i class="fas fa-crown"></i> Ver Planes Premium
                </button>
            </div>
            <i class="fas fa-gem premium-icon"></i>
        </div>
        <?php endif; ?>
    </div>

    <!-- Scripts -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    
    <script>
        const CONFIG = {
            apiUrl: '../api/ingles/',
            rol: '<?= $rol ?>',
            idEstudiante: <?= $id_estudiante ?? 0 ?>,
            idProfesor: <?= $id_profesor ?? 0 ?>
        };
        
        // Funciones principales
        function verCurso(id) {
            window.location.href = `ver_curso.php?id=${id}`;
        }
        
        function iniciarLeccion(id) {
            window.location.href = `leccion.php?id=${id}`;
        }
        
        function filtrarLecciones(tipo) {
            $('#leccionesContainer').html('<div class="text-center py-4"><div class="spinner-border text-primary"></div><p class="mt-2">Cargando...</p></div>');
            $.get(`ajax_lecciones.php?tipo=${tipo}`, function(data) {
                $('#leccionesContainer').html(data);
            });
        }
        
        <?php if ($rol == 'estudiante'): ?>
        function iniciarAsignacion(id) {
            window.location.href = `asignacion.php?id=${id}`;
        }
        <?php endif; ?>
        
        function iniciarPractica(tipo) {
            Swal.fire({
                title: `Practicar ${tipo.charAt(0).toUpperCase() + tipo.slice(1)}`,
                text: '¿Listo para practicar? ¡Vamos!',
                icon: 'info',
                showCancelButton: true,
                confirmButtonText: '¡Comenzar!',
                cancelButtonText: 'Cancelar',
                confirmButtonColor: '#58cc02'
            }).then((result) => {
                if (result.isConfirmed) {
                    window.location.href = `practica.php?tipo=${tipo}`;
                }
            });
        }
        
        <?php if ($rol == 'profesor'): ?>
        // Formulario de asignación
        $('#formAsignar').submit(function(e) {
            e.preventDefault();
            
            Swal.fire({
                title: '¿Confirmar asignación?',
                text: 'Se enviará esta tarea a tus estudiantes',
                icon: 'question',
                showCancelButton: true,
                confirmButtonText: 'Sí, asignar',
                cancelButtonText: 'Cancelar',
                confirmButtonColor: '#58cc02'
            }).then((result) => {
                if (result.isConfirmed) {
                    const data = {
                        tipo: $('#tipo_asignacion').val(),
                        curso: $('#curso_select').val(),
                        fecha_limite: $('#fecha_limite').val(),
                        puntaje_minimo: $('#puntaje_minimo').val(),
                        instrucciones: $('#instrucciones').val()
                    };
                    
                    $.post(CONFIG.apiUrl + 'crear_asignacion.php', data, function(response) {
                        if (response.success) {
                            Swal.fire('¡Éxito!', 'Asignación creada correctamente', 'success');
                        } else {
                            Swal.fire('Error', response.message, 'error');
                        }
                    }, 'json');
                }
            });
        });
        <?php endif; ?>
        
        // Animaciones al cargar
        $(document).ready(function() {
            $('.animate-in').css('opacity', '1');
            
            // Efecto hover en course cards
            $('.course-card').hover(
                function() { $(this).css('transform', 'translateY(-8px)'); },
                function() { $(this).css('transform', 'translateY(0)'); }
            );
        });
    </script>
</body>
</html>