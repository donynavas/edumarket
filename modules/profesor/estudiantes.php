<?php
session_start();
require_once __DIR__ . '/../../config/database.php';

// Verificar que sea estudiante
if (!isset($_SESSION['user_id']) || $_SESSION['rol'] != 'estudiante') {
    header("Location: " . BASE_URL . "/login.php");
    exit;
}

$database = new Database();
$db = $database->getConnection();
$user_id = $_SESSION['user_id'];

// Obtener datos completos del estudiante
$query = "SELECT 
          e.id as id_estudiante, e.nie, e.estado_familiar, e.discapacidad, e.trabaja,
          p.primer_nombre, p.segundo_nombre, p.primer_apellido, p.segundo_apellido,
          p.dui, p.fecha_nacimiento, p.sexo, p.nacionalidad, p.direccion, 
          p.telefono_fijo, p.celular, p.email,
          g.id as id_grado, g.nombre as grado_nombre, g.nivel, g.nota_minima_aprobacion,
          s.id as id_seccion, s.nombre as seccion_nombre,
          m.id as id_matricula, m.anno, m.id_periodo, m.estado as estado_matricula,
          u.usuario
          FROM tbl_estudiante e
          JOIN tbl_persona p ON e.id_persona = p.id
          JOIN tbl_usuario u ON p.id_usuario = u.id
          JOIN tbl_matricula m ON e.id = m.id_estudiante
          JOIN tbl_seccion s ON m.id_seccion = s.id
          JOIN tbl_grado g ON s.id_grado = g.id
          WHERE p.id_usuario = :user_id
          AND m.estado = 'activo'
          ORDER BY m.anno DESC, m.id_periodo DESC
          LIMIT 1";

$stmt = $db->prepare($query);
$stmt->bindValue(':user_id', $user_id, PDO::PARAM_INT);
$stmt->execute();
$estudiante = $stmt->fetch(PDO::FETCH_ASSOC);

// Si no tiene matrícula activa
if (!$estudiante) {
    ?>
    <!DOCTYPE html>
    <html lang="es">
    <head>
        <meta charset="UTF-8">
        <title>Sin Matrícula - Educación Plus</title>
        <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    </head>
    <body class="bg-light">
        <div class="container mt-5">
            <div class="row justify-content-center">
                <div class="col-md-6">
                    <div class="card shadow text-center py-5">
                        <div class="card-body">
                            <i class="fas fa-exclamation-triangle fa-4x text-warning mb-3"></i>
                            <h4 class="mb-3">Sin Matrícula Activa</h4>
                            <p class="text-muted">No tienes una matrícula activa para este período. Contacta a administración para regularizar tu situación.</p>
                            <a href="../../logout.php" class="btn btn-secondary">
                                <i class="fas fa-sign-out-alt"></i> Cerrar Sesión
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </body>
    </html>
    <?php
    exit;
}

$id_estudiante = $estudiante['id_estudiante'];
$id_matricula = $estudiante['id_matricula'];
$id_seccion = $estudiante['id_seccion'];
$anno = $estudiante['anno'];
$periodo = $estudiante['id_periodo'];

$mensaje = '';
$tipo_mensaje = '';

// ===== ESTADÍSTICAS DEL ESTUDIANTE =====

// 1. Promedio general del período actual
$query_prom = "SELECT AVG(ea.nota_obtenida) as promedio, COUNT(ea.id) as total_calificadas
               FROM tbl_entrega_actividad ea
               JOIN tbl_actividad act ON ea.id_actividad = act.id
               JOIN tbl_asignacion_docente ad ON act.id_asignacion_docente = ad.id
               WHERE ea.id_estudiante = :id_estudiante
               AND act.id_periodo = :periodo
               AND ea.nota_obtenida IS NOT NULL";
$stmt_prom = $db->prepare($query_prom);
$stmt_prom->execute([
    ':id_estudiante' => $id_estudiante,
    ':periodo' => $periodo
]);
$stats_promedio = $stmt_prom->fetch(PDO::FETCH_ASSOC);
$promedio_general = $stats_promedio['promedio'] ?? 0;

// 2. Tareas pendientes
$query_pendientes = "SELECT COUNT(*) as pendientes
                     FROM tbl_actividad act
                     JOIN tbl_asignacion_docente ad ON act.id_asignacion_docente = ad.id
                     LEFT JOIN tbl_entrega_actividad ea ON act.id = ea.id_actividad 
                         AND ea.id_estudiante = :id_estudiante
                     WHERE ad.id_seccion = :id_seccion
                     AND act.id_periodo = :periodo
                     AND act.anno = :anno
                     AND act.tipo IN ('tarea', 'laboratorio', 'proyecto')
                     AND act.fecha_limite >= NOW()
                     AND (ea.id IS NULL OR ea.estado_entrega != 'calificado')";
$stmt_pendientes = $db->prepare($query_pendientes);
$stmt_pendientes->execute([
    ':id_estudiante' => $id_estudiante,
    ':id_seccion' => $id_seccion,
    ':periodo' => $periodo,
    ':anno' => $anno
]);
$tareas_pendientes = $stmt_pendientes->fetchColumn();

// 3. Próximos exámenes (próximos 7 días)
$query_examenes = "SELECT COUNT(*) as proximos
                   FROM tbl_actividad
                   WHERE id_asignacion_docente IN (
                       SELECT id FROM tbl_asignacion_docente 
                       WHERE id_seccion = :id_seccion AND anno = :anno
                   )
                   AND tipo = 'examen'
                   AND id_periodo = :periodo
                   AND fecha_programada BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL 7 DAY)
                   AND estado = 'activo'";
$stmt_examenes = $db->prepare($query_examenes);
$stmt_examenes->execute([
    ':id_seccion' => $id_seccion,
    ':anno' => $anno,
    ':periodo' => $periodo
]);
$examenes_proximos = $stmt_examenes->fetchColumn();

// 4. Asistencia del período
$query_asistencia = "SELECT 
    COUNT(*) as total_dias,
    SUM(CASE WHEN estado = 'presente' THEN 1 ELSE 0 END) as presentes
    FROM tbl_asistencia
    WHERE id_estudiante = :id_estudiante";
$stmt_asistencia = $db->prepare($query_asistencia);
$stmt_asistencia->execute([':id_estudiante' => $id_estudiante]);
$asistencia = $stmt_asistencia->fetch(PDO::FETCH_ASSOC);
$porcentaje_asistencia = $asistencia['total_dias'] > 0 
    ? round(($asistencia['presentes'] / $asistencia['total_dias']) * 100) 
    : 100;

// ===== CLASES/MATERIAS DEL ESTUDIANTE =====
$query_clases = "SELECT 
    ad.id as id_asignacion,
    asig.id as id_asignatura, asig.nombre as asignatura, asig.codigo,
    per.primer_nombre as profesor_nombre, per.primer_apellido as profesor_apellido,
    p.especialidad as profesor_especialidad,
    COUNT(DISTINCT act.id) as total_actividades,
    COUNT(DISTINCT CASE WHEN ea.id IS NOT NULL THEN ea.id END) as actividades_completadas,
    AVG(ea.nota_obtenida) as promedio_materia
    FROM tbl_asignacion_docente ad
    JOIN tbl_asignatura asig ON ad.id_asignatura = asig.id
    JOIN tbl_profesor prof ON ad.id_profesor = prof.id
    JOIN tbl_persona per ON prof.id_persona = per.id
    LEFT JOIN tbl_actividad act ON ad.id = act.id_asignacion_docente 
        AND act.id_periodo = :periodo
    LEFT JOIN tbl_entrega_actividad ea ON act.id = ea.id_actividad 
        AND ea.id_estudiante = :id_estudiante
    WHERE ad.id_seccion = :id_seccion
    AND ad.anno = :anno
    GROUP BY ad.id
    ORDER BY asig.nombre";

$stmt_clases = $db->prepare($query_clases);
$stmt_clases->execute([
    ':id_seccion' => $id_seccion,
    ':anno' => $anno,
    ':periodo' => $periodo,
    ':id_estudiante' => $id_estudiante
]);
$clases = $stmt_clases->fetchAll(PDO::FETCH_ASSOC);

// ===== ACTIVIDADES PENDIENTES =====
$query_actividades = "SELECT 
    act.id, act.titulo, act.tipo, act.descripcion, act.fecha_limite, act.nota_maxima,
    asig.nombre as asignatura, asig.codigo as codigo_asignatura,
    CASE WHEN ea.id IS NOT NULL THEN ea.estado_entrega ELSE 'pendiente' END as estado_entrega,
    ea.nota_obtenida
    FROM tbl_actividad act
    JOIN tbl_asignacion_docente ad ON act.id_asignacion_docente = ad.id
    JOIN tbl_asignatura asig ON ad.id_asignatura = asig.id
    LEFT JOIN tbl_entrega_actividad ea ON act.id = ea.id_actividad 
        AND ea.id_estudiante = :id_estudiante
    WHERE ad.id_seccion = :id_seccion
    AND act.id_periodo = :periodo
    AND act.anno = :anno
    AND act.fecha_limite >= CURDATE()
    AND (ea.id IS NULL OR ea.estado_entrega != 'calificado')
    ORDER BY act.fecha_limite ASC
    LIMIT 5";

$stmt_actividades = $db->prepare($query_actividades);
$stmt_actividades->execute([
    ':id_seccion' => $id_seccion,
    ':periodo' => $periodo,
    ':anno' => $anno,
    ':id_estudiante' => $id_estudiante
]);
$actividades_pendientes = $stmt_actividades->fetchAll(PDO::FETCH_ASSOC);

// ===== ÚLTIMAS CALIFICACIONES =====
$query_notas = "SELECT 
    act.titulo, act.tipo, act.fecha_programada,
    asig.nombre as asignatura,
    ea.nota_obtenida, ea.observacion_docente, ea.estado_entrega,
    g.nota_minima_aprobacion
    FROM tbl_entrega_actividad ea
    JOIN tbl_actividad act ON ea.id_actividad = act.id
    JOIN tbl_asignacion_docente ad ON act.id_asignacion_docente = ad.id
    JOIN tbl_asignatura asig ON ad.id_asignatura = asig.id
    JOIN tbl_seccion s ON ad.id_seccion = s.id
    JOIN tbl_grado g ON s.id_grado = g.id
    WHERE ea.id_estudiante = :id_estudiante
    AND ea.nota_obtenida IS NOT NULL
    ORDER BY ea.fecha_entrega DESC
    LIMIT 5";

$stmt_notas = $db->prepare($query_notas);
$stmt_notas->execute([':id_estudiante' => $id_estudiante]);
$ultimas_notas = $stmt_notas->fetchAll(PDO::FETCH_ASSOC);

// ===== PRÓXIMOS EXÁMENES =====
$query_proximos_examenes = "SELECT 
    act.id, act.titulo, act.fecha_programada, act.tiempo_limite_minutos,
    asig.nombre as asignatura,
    DATEDIFF(act.fecha_programada, NOW()) as dias_restantes
    FROM tbl_actividad act
    JOIN tbl_asignacion_docente ad ON act.id_asignacion_docente = ad.id
    JOIN tbl_asignatura asig ON ad.id_asignatura = asig.id
    WHERE ad.id_seccion = :id_seccion
    AND act.id_periodo = :periodo
    AND act.anno = :anno
    AND act.tipo = 'examen'
    AND act.estado = 'activo'
    AND act.fecha_programada >= NOW()
    ORDER BY act.fecha_programada ASC
    LIMIT 3";

$stmt_examenes_list = $db->prepare($query_proximos_examenes);
$stmt_examenes_list->execute([
    ':id_seccion' => $id_seccion,
    ':periodo' => $periodo,
    ':anno' => $anno
]);
$proximos_examenes = $stmt_examenes_list->fetchAll(PDO::FETCH_ASSOC);

// ===== CONFIGURACIÓN =====
$periodos = [1 => '1er Trimestre', 2 => '2do Trimestre', 3 => '3er Trimestre', 4 => '4to Trimestre'];
$tipos_actividad = [
    'tarea' => ['label' => 'Tarea', 'icon' => 'fa-clipboard', 'color' => 'info'],
    'examen' => ['label' => 'Examen', 'icon' => 'fa-file-alt', 'color' => 'danger'],
    'laboratorio' => ['label' => 'Laboratorio', 'icon' => 'fa-flask', 'color' => 'warning'],
    'foro' => ['label' => 'Foro', 'icon' => 'fa-comments', 'color' => 'success'],
    'proyecto' => ['label' => 'Proyecto', 'icon' => 'fa-folder-open', 'color' => 'primary'],
    'recurso' => ['label' => 'Recurso', 'icon' => 'fa-book', 'color' => 'secondary']
];

// Calcular edad
$fecha_nac = new DateTime($estudiante['fecha_nacimiento']);
$edad = (new DateTime())->diff($fecha_nac)->y;
$nombre_completo = trim("{$estudiante['primer_nombre']} {$estudiante['segundo_nombre']} {$estudiante['primer_apellido']} {$estudiante['segundo_apellido']}");
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Mi Perfil - Educación Plus</title>
    
    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Font Awesome -->
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <!-- FullCalendar -->
    <link href="https://cdn.jsdelivr.net/npm/fullcalendar@5.11.3/main.min.css" rel="stylesheet">
    
    <style>
        :root {
            --primary: #4361ee;
            --secondary: #3f37c9;
            --success: #4cc9f0;
            --info: #4895ef;
            --warning: #f72585;
            --danger: #e63946;
            --light: #f8f9fa;
            --dark: #1d3557;
            --sidebar-width: 260px;
            --card-shadow: 0 4px 20px rgba(0,0,0,0.08);
            --card-radius: 16px;
        }
        
        * { margin: 0; padding: 0; box-sizing: border-box; }
        
        body {
            font-family: 'Segoe UI', system-ui, -apple-system, sans-serif;
            background: linear-gradient(135deg, #f5f7fa 0%, #e4edf5 100%);
            min-height: 100vh;
            color: #333;
        }
        
        /* ===== SIDEBAR ===== */
        .sidebar {
            position: fixed;
            top: 0;
            left: 0;
            height: 100vh;
            width: var(--sidebar-width);
            background: linear-gradient(180deg, var(--dark) 0%, #2a4365 100%);
            color: white;
            z-index: 1000;
            display: flex;
            flex-direction: column;
            transition: transform 0.3s ease;
            box-shadow: 4px 0 20px rgba(0,0,0,0.1);
        }
        
        .sidebar-header {
            padding: 25px 20px;
            border-bottom: 1px solid rgba(255,255,255,0.1);
            text-align: center;
        }
        
        .sidebar-logo {
            width: 70px;
            height: 70px;
            background: white;
            border-radius: 16px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 15px;
            box-shadow: 0 8px 25px rgba(0,0,0,0.2);
        }
        
        .sidebar-logo i {
            font-size: 2rem;
            color: var(--primary);
        }
        
        .sidebar-header h5 {
            font-weight: 700;
            margin-bottom: 5px;
        }
        
        .sidebar-header small {
            opacity: 0.8;
            font-size: 0.85rem;
        }
        
        /* Student Profile in Sidebar */
        .sidebar-profile {
            padding: 20px;
            text-align: center;
            border-bottom: 1px solid rgba(255,255,255,0.1);
            background: rgba(255,255,255,0.05);
        }
        
        .profile-avatar {
            width: 70px;
            height: 70px;
            border-radius: 50%;
            background: linear-gradient(135deg, var(--primary), var(--info));
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
            font-weight: 600;
            margin: 0 auto 12px;
            border: 3px solid rgba(255,255,255,0.3);
        }
        
        .profile-name {
            font-weight: 600;
            font-size: 0.95rem;
            margin-bottom: 3px;
        }
        
        .profile-grade {
            font-size: 0.8rem;
            opacity: 0.85;
        }
        
        /* Navigation */
        .sidebar-nav {
            flex: 1;
            padding: 15px 10px;
            overflow-y: auto;
        }
        
        .nav-section {
            margin-bottom: 20px;
        }
        
        .nav-section-title {
            font-size: 0.75rem;
            text-transform: uppercase;
            letter-spacing: 1px;
            color: rgba(255,255,255,0.5);
            padding: 10px 15px 5px;
            font-weight: 600;
        }
        
        .nav-link {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 12px 15px;
            color: rgba(255,255,255,0.85);
            text-decoration: none;
            border-radius: 10px;
            margin: 3px 0;
            transition: all 0.2s ease;
            font-size: 0.95rem;
        }
        
        .nav-link:hover,
        .nav-link.active {
            background: rgba(255,255,255,0.15);
            color: white;
            transform: translateX(4px);
        }
        
        .nav-link.active {
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            box-shadow: 0 4px 15px rgba(67, 97, 238, 0.4);
        }
        
        .nav-link i {
            width: 20px;
            text-align: center;
            font-size: 1.1rem;
        }
        
        .nav-link .badge {
            margin-left: auto;
            font-size: 0.7rem;
            padding: 4px 8px;
        }
        
        /* Sidebar Footer */
        .sidebar-footer {
            padding: 15px 20px;
            border-top: 1px solid rgba(255,255,255,0.1);
        }
        
        .btn-logout {
            width: 100%;
            padding: 10px;
            background: rgba(230, 57, 70, 0.2);
            border: 1px solid rgba(230, 57, 70, 0.4);
            color: #ffcccc;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            text-decoration: none;
            transition: all 0.2s ease;
            font-size: 0.9rem;
        }
        
        .btn-logout:hover {
            background: var(--danger);
            color: white;
        }
        
        /* ===== MAIN CONTENT ===== */
        .main-content {
            margin-left: var(--sidebar-width);
            padding: 20px 30px;
            transition: margin-left 0.3s ease;
        }
        
        /* Top Bar */
        .top-bar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 15px 0;
            margin-bottom: 25px;
            flex-wrap: wrap;
            gap: 15px;
        }
        
        .page-title h2 {
            font-weight: 700;
            color: var(--dark);
            margin-bottom: 5px;
        }
        
        .page-title p {
            color: #666;
            margin: 0;
            font-size: 0.95rem;
        }
        
        .top-actions {
            display: flex;
            align-items: center;
            gap: 12px;
        }
        
        .btn-icon {
            width: 45px;
            height: 45px;
            border-radius: 12px;
            border: none;
            background: white;
            color: var(--dark);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.1rem;
            box-shadow: var(--card-shadow);
            transition: all 0.2s ease;
            position: relative;
            cursor: pointer;
        }
        
        .btn-icon:hover {
            transform: translateY(-3px);
            box-shadow: 0 8px 25px rgba(0,0,0,0.12);
            color: var(--primary);
        }
        
        .btn-icon .badge {
            position: absolute;
            top: -5px;
            right: -5px;
            padding: 4px 7px;
            font-size: 0.7rem;
        }
        
        .btn-primary-custom {
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            border: none;
            color: white;
            padding: 10px 22px;
            border-radius: 12px;
            font-weight: 500;
            display: flex;
            align-items: center;
            gap: 8px;
            box-shadow: 0 4px 15px rgba(67, 97, 238, 0.3);
            transition: all 0.2s ease;
        }
        
        .btn-primary-custom:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(67, 97, 238, 0.4);
            color: white;
        }
        
        /* ===== CARDS ===== */
        .card-custom {
            background: white;
            border-radius: var(--card-radius);
            box-shadow: var(--card-shadow);
            border: none;
            margin-bottom: 24px;
            transition: transform 0.2s ease, box-shadow 0.2s ease;
        }
        
        .card-custom:hover {
            transform: translateY(-4px);
            box-shadow: 0 12px 35px rgba(0,0,0,0.12);
        }
        
        .card-header-custom {
            padding: 18px 24px;
            border-bottom: 1px solid #eee;
            display: flex;
            justify-content: space-between;
            align-items: center;
            background: transparent;
        }
        
        .card-header-custom h5 {
            font-weight: 600;
            color: var(--dark);
            margin: 0;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .card-header-custom h5 i {
            color: var(--primary);
        }
        
        .card-body-custom {
            padding: 20px 24px;
        }
        
        /* ===== STATS CARDS ===== */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 25px;
        }
        
        .stat-card {
            background: white;
            border-radius: var(--card-radius);
            padding: 22px;
            box-shadow: var(--card-shadow);
            display: flex;
            align-items: center;
            gap: 15px;
            transition: all 0.3s ease;
            position: relative;
            overflow: hidden;
        }
        
        .stat-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 4px;
            height: 100%;
            background: var(--primary);
        }
        
        .stat-card.success::before { background: #4cc9f0; }
        .stat-card.warning::before { background: #f72585; }
        .stat-card.danger::before { background: #e63946; }
        
        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 12px 35px rgba(0,0,0,0.15);
        }
        
        .stat-icon {
            width: 55px;
            height: 55px;
            border-radius: 14px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.4rem;
            color: white;
        }
        
        .stat-icon.primary { background: linear-gradient(135deg, var(--primary), var(--secondary)); }
        .stat-icon.success { background: linear-gradient(135deg, #4cc9f0, #4895ef); }
        .stat-icon.warning { background: linear-gradient(135deg, #f72585, #b5179e); }
        .stat-icon.danger { background: linear-gradient(135deg, #e63946, #d90429); }
        
        .stat-info {
            flex: 1;
        }
        
        .stat-value {
            font-size: 1.8rem;
            font-weight: 700;
            color: var(--dark);
            line-height: 1.2;
        }
        
        .stat-label {
            color: #666;
            font-size: 0.9rem;
            margin-top: 3px;
        }
        
        .stat-trend {
            font-size: 0.8rem;
            margin-top: 5px;
        }
        
        .stat-trend.up { color: #4cc9f0; }
        .stat-trend.down { color: #e63946; }
        
        /* ===== CLASES GRID ===== */
        .classes-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
            gap: 20px;
        }
        
        .class-card {
            background: white;
            border-radius: var(--card-radius);
            padding: 20px;
            box-shadow: var(--card-shadow);
            border-left: 4px solid var(--primary);
            transition: all 0.2s ease;
            cursor: pointer;
        }
        
        .class-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 12px 35px rgba(0,0,0,0.12);
        }
        
        .class-card.mate { border-left-color: #4361ee; }
        .class-card.lengua { border-left-color: #f72585; }
        .class-card.ciencias { border-left-color: #4cc9f0; }
        .class-card.sociales { border-left-color: #7209b7; }
        .class-card.deporte { border-left-color: #4ade80; }
        
        .class-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 15px;
        }
        
        .class-code {
            background: #f0f4ff;
            color: var(--primary);
            padding: 5px 12px;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 600;
            font-family: monospace;
        }
        
        .class-title {
            font-weight: 600;
            font-size: 1.1rem;
            color: var(--dark);
            margin-bottom: 8px;
        }
        
        .class-professor {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 0.9rem;
            color: #666;
            margin-bottom: 15px;
        }
        
        .class-professor i {
            color: var(--primary);
        }
        
        .class-progress {
            margin-bottom: 12px;
        }
        
        .class-progress-label {
            display: flex;
            justify-content: space-between;
            font-size: 0.85rem;
            color: #666;
            margin-bottom: 6px;
        }
        
        .progress-custom {
            height: 8px;
            border-radius: 10px;
            background: #e9ecef;
            overflow: hidden;
        }
        
        .progress-custom .progress-bar {
            border-radius: 10px;
            background: linear-gradient(135deg, var(--primary), var(--info));
            transition: width 0.6s ease;
        }
        
        .class-stats {
            display: flex;
            justify-content: space-between;
            padding-top: 12px;
            border-top: 1px solid #eee;
            font-size: 0.85rem;
        }
        
        .class-stat {
            text-align: center;
        }
        
        .class-stat-value {
            font-weight: 600;
            color: var(--dark);
            display: block;
        }
        
        .class-stat-label {
            color: #999;
            font-size: 0.8rem;
        }
        
        /* ===== ACTIVITIES LIST ===== */
        .activity-item {
            display: flex;
            align-items: center;
            padding: 15px;
            border: 1px solid #eee;
            border-radius: 12px;
            margin-bottom: 12px;
            background: #fafafa;
            transition: all 0.2s ease;
        }
        
        .activity-item:hover {
            border-color: var(--primary);
            background: #f8fbff;
            transform: translateX(4px);
        }
        
        .activity-icon {
            width: 45px;
            height: 45px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            margin-right: 15px;
            flex-shrink: 0;
        }
        
        .activity-icon.info { background: linear-gradient(135deg, #4895ef, #3f37c9); }
        .activity-icon.danger { background: linear-gradient(135deg, #e63946, #d90429); }
        .activity-icon.warning { background: linear-gradient(135deg, #f72585, #b5179e); }
        .activity-icon.success { background: linear-gradient(135deg, #4cc9f0, #4361ee); }
        
        .activity-content {
            flex: 1;
            min-width: 0;
        }
        
        .activity-title {
            font-weight: 600;
            color: var(--dark);
            margin-bottom: 4px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        
        .activity-subject {
            font-size: 0.85rem;
            color: #666;
            margin-bottom: 5px;
        }
        
        .activity-meta {
            display: flex;
            align-items: center;
            gap: 15px;
            font-size: 0.8rem;
            color: #888;
        }
        
        .activity-meta i {
            margin-right: 4px;
        }
        
        .activity-actions {
            display: flex;
            gap: 8px;
        }
        
        .btn-activity {
            padding: 8px 16px;
            border-radius: 8px;
            font-size: 0.85rem;
            font-weight: 500;
            border: none;
            cursor: pointer;
            transition: all 0.2s ease;
            display: flex;
            align-items: center;
            gap: 5px;
        }
        
        .btn-activity.primary {
            background: var(--primary);
            color: white;
        }
        
        .btn-activity.primary:hover {
            background: var(--secondary);
            transform: translateY(-2px);
        }
        
        .btn-activity.outline {
            background: transparent;
            border: 2px solid var(--primary);
            color: var(--primary);
        }
        
        .btn-activity.outline:hover {
            background: var(--primary);
            color: white;
        }
        
        /* ===== GRADES TABLE ===== */
        .grades-table {
            width: 100%;
            border-collapse: collapse;
        }
        
        .grades-table th {
            background: #f8f9fa;
            padding: 12px 15px;
            text-align: left;
            font-weight: 600;
            color: var(--dark);
            font-size: 0.85rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            border-bottom: 2px solid #eee;
        }
        
        .grades-table td {
            padding: 14px 15px;
            border-bottom: 1px solid #eee;
            vertical-align: middle;
        }
        
        .grades-table tr:hover {
            background: #f8fbff;
        }
        
        .grade-badge {
            padding: 6px 14px;
            border-radius: 20px;
            font-weight: 600;
            font-size: 0.9rem;
            display: inline-block;
            min-width: 55px;
            text-align: center;
        }
        
        .grade-excellent { background: #d4edda; color: #155724; }
        .grade-good { background: #cce5ff; color: #004085; }
        .grade-regular { background: #fff3cd; color: #856404; }
        .grade-fail { background: #f8d7da; color: #721c24; }
        
        .grade-observation {
            max-width: 250px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            color: #666;
            font-size: 0.9rem;
        }
        
        /* ===== EXAMS SECTION ===== */
        .exam-card {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border-radius: var(--card-radius);
            padding: 20px;
            margin-bottom: 15px;
            position: relative;
            overflow: hidden;
        }
        
        .exam-card::before {
            content: '';
            position: absolute;
            top: -50%;
            right: -50%;
            width: 200%;
            height: 200%;
            background: radial-gradient(circle, rgba(255,255,255,0.1) 0%, transparent 70%);
            animation: pulse 4s ease-in-out infinite;
        }
        
        @keyframes pulse {
            0%, 100% { transform: scale(1); }
            50% { transform: scale(1.1); }
        }
        
        .exam-title {
            font-weight: 600;
            font-size: 1.1rem;
            margin-bottom: 8px;
            position: relative;
            z-index: 1;
        }
        
        .exam-subject {
            opacity: 0.9;
            font-size: 0.95rem;
            margin-bottom: 15px;
            position: relative;
            z-index: 1;
        }
        
        .exam-details {
            display: flex;
            gap: 20px;
            margin-bottom: 15px;
            position: relative;
            z-index: 1;
        }
        
        .exam-detail {
            display: flex;
            align-items: center;
            gap: 6px;
            font-size: 0.9rem;
        }
        
        .btn-exam {
            background: white;
            color: var(--primary);
            border: none;
            padding: 10px 24px;
            border-radius: 10px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s ease;
            display: flex;
            align-items: center;
            gap: 8px;
            position: relative;
            z-index: 1;
        }
        
        .btn-exam:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(0,0,0,0.2);
        }
        
        /* ===== CALENDAR ===== */
        #calendar {
            background: white;
            border-radius: var(--card-radius);
            padding: 20px;
            box-shadow: var(--card-shadow);
        }
        
        .fc {
            font-family: inherit;
        }
        
        .fc-toolbar-title {
            font-size: 1.2rem !important;
            font-weight: 600;
        }
        
        .fc-event {
            border-radius: 8px !important;
            border: none !important;
            padding: 4px 8px !important;
            font-size: 0.85rem !important;
            cursor: pointer;
        }
        
        .fc-event-exam {
            background: linear-gradient(135deg, #e63946, #d90429) !important;
        }
        
        .fc-event-tarea {
            background: linear-gradient(135deg, #f72585, #b5179e) !important;
        }
        
        .fc-event-entrega {
            background: linear-gradient(135deg, #4895ef, #3f37c9) !important;
        }
        
        /* ===== PROGRESS CIRCLE ===== */
        .progress-circle {
            width: 100px;
            height: 100px;
            border-radius: 50%;
            background: conic-gradient(var(--primary) 0%, #e9ecef 0%);
            display: flex;
            align-items: center;
            justify-content: center;
            position: relative;
            margin: 0 auto;
        }
        
        .progress-circle::before {
            content: '';
            position: absolute;
            width: 80px;
            height: 80px;
            border-radius: 50%;
            background: white;
        }
        
        .progress-circle span {
            position: relative;
            z-index: 1;
            font-size: 1.4rem;
            font-weight: 700;
            color: var(--dark);
        }
        
        .progress-circle-label {
            text-align: center;
            margin-top: 10px;
            color: #666;
            font-size: 0.9rem;
        }
        
        /* ===== QUICK ACTIONS ===== */
        .quick-actions {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(120px, 1fr));
            gap: 15px;
            margin-bottom: 25px;
        }
        
        .quick-action {
            background: white;
            border-radius: var(--card-radius);
            padding: 20px 15px;
            text-align: center;
            box-shadow: var(--card-shadow);
            text-decoration: none;
            color: var(--dark);
            transition: all 0.2s ease;
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 10px;
        }
        
        .quick-action:hover {
            transform: translateY(-5px);
            box-shadow: 0 12px 35px rgba(0,0,0,0.12);
            color: var(--primary);
        }
        
        .quick-action-icon {
            width: 50px;
            height: 50px;
            border-radius: 14px;
            background: linear-gradient(135deg, var(--primary), var(--info));
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.3rem;
        }
        
        .quick-action-label {
            font-size: 0.85rem;
            font-weight: 500;
        }
        
        /* ===== RESPONSIVE ===== */
        @media (max-width: 992px) {
            .sidebar {
                transform: translateX(-100%);
            }
            .sidebar.active {
                transform: translateX(0);
            }
            .main-content {
                margin-left: 0;
            }
            .top-bar {
                flex-direction: column;
                align-items: flex-start;
            }
        }
        
        @media (max-width: 768px) {
            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
            }
            .classes-grid {
                grid-template-columns: 1fr;
            }
            .exam-details {
                flex-direction: column;
                gap: 10px;
            }
        }
        
        /* ===== ANIMATIONS ===== */
        @keyframes fadeInUp {
            from {
                opacity: 0;
                transform: translateY(20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        .animate-fade {
            animation: fadeInUp 0.5s ease forwards;
            opacity: 0;
        }
        
        .animate-fade:nth-child(1) { animation-delay: 0.1s; }
        .animate-fade:nth-child(2) { animation-delay: 0.2s; }
        .animate-fade:nth-child(3) { animation-delay: 0.3s; }
        .animate-fade:nth-child(4) { animation-delay: 0.4s; }
        
        /* ===== TOAST NOTIFICATIONS ===== */
        .toast-container {
            position: fixed;
            top: 20px;
            right: 20px;
            z-index: 9999;
        }
        
        .toast-custom {
            background: white;
            border-radius: 12px;
            box-shadow: 0 10px 40px rgba(0,0,0,0.15);
            border-left: 4px solid var(--primary);
        }
        
        .toast-custom.success { border-left-color: #4cc9f0; }
        .toast-custom.warning { border-left-color: #f72585; }
        .toast-custom.error { border-left-color: #e63946; }
    </style>
</head>
<body>
    <!-- Toast Container -->
    <div class="toast-container" id="toastContainer"></div>

    <!-- Sidebar -->
    <aside class="sidebar" id="sidebar">
        <div class="sidebar-header">
            <div class="sidebar-logo">
                <i class="fas fa-graduation-cap"></i>
            </div>
            <h5>Educación Plus</h5>
            <small>Plataforma Educativa</small>
        </div>
        
        <div class="sidebar-profile">
            <div class="profile-avatar">
                <?= strtoupper(substr($estudiante['primer_nombre'], 0, 1)) ?>
            </div>
            <div class="profile-name">
                <?= htmlspecialchars($estudiante['primer_nombre'] . ' ' . $estudiante['primer_apellido']) ?>
            </div>
            <div class="profile-grade">
                <?= htmlspecialchars($estudiante['grado_nombre']) ?> - <?= htmlspecialchars($estudiante['seccion_nombre']) ?>
            </div>
        </div>
        
        <nav class="sidebar-nav">
            <div class="nav-section">
                <div class="nav-section-title">Principal</div>
                <a href="dashboard_estudiante.php" class="nav-link">
                    <i class="fas fa-home"></i> Dashboard
                </a>
                <a href="mis_clases.php" class="nav-link">
                    <i class="fas fa-book"></i> Mis Clases
                </a>
                <a href="calendario.php" class="nav-link">
                    <i class="fas fa-calendar-alt"></i> Calendario
                </a>
            </div>
            
            <div class="nav-section">
                <div class="nav-section-title">Académico</div>
                <a href="actividades.php" class="nav-link">
                    <i class="fas fa-tasks"></i> Actividades
                    <?php if ($tareas_pendientes > 0): ?>
                    <span class="badge bg-danger"><?= $tareas_pendientes ?></span>
                    <?php endif; ?>
                </a>
                <a href="mis_notas.php" class="nav-link">
                    <i class="fas fa-star"></i> Mis Notas
                </a>
                <a href="examenes.php" class="nav-link">
                    <i class="fas fa-file-alt"></i> Exámenes
                    <?php if ($examenes_proximos > 0): ?>
                    <span class="badge bg-warning text-dark"><?= $examenes_proximos ?></span>
                    <?php endif; ?>
                </a>
                <a href="asistencia.php" class="nav-link">
                    <i class="fas fa-clipboard-check"></i> Asistencia
                </a>
            </div>
            
            <div class="nav-section">
                <div class="nav-section-title">Comunicación</div>
                <a href="mensajes.php" class="nav-link">
                    <i class="fas fa-envelope"></i> Mensajes
                </a>
                <a href="foros.php" class="nav-link">
                    <i class="fas fa-comments"></i> Foros
                </a>
            </div>
            
            <div class="nav-section">
                <div class="nav-section-title">Recursos</div>
                <a href="recursos.php" class="nav-link">
                    <i class="fas fa-folder-open"></i> Materiales
                </a>
                <a href="reportes.php" class="nav-link">
                    <i class="fas fa-file-pdf"></i> Reportes
                </a>
                <a href="estudiantes.php" class="nav-link active">
                    <i class="fas fa-user-cog"></i> Mi Perfil
                </a>
            </div>
        </nav>
        
        <div class="sidebar-footer">
            <a href="../../logout.php" class="btn-logout" onclick="return confirm('¿Seguro que deseas cerrar sesión?')">
                <i class="fas fa-sign-out-alt"></i> Cerrar Sesión
            </a>
        </div>
    </aside>

    <!-- Main Content -->
    <main class="main-content">
        <!-- Top Bar -->
        <div class="top-bar">
            <div class="page-title">
                <h2>👤 Mi Perfil</h2>
                <p><?= $periodos[$periodo] ?> <?= $anno ?> • <?= ucfirst($estudiante['nivel']) ?></p>
            </div>
            
            <div class="top-actions">
                <button class="btn-icon" id="sidebarToggle" title="Menú">
                    <i class="fas fa-bars"></i>
                </button>
                <button class="btn-primary-custom" onclick="editarPerfil()">
                    <i class="fas fa-edit"></i> Editar Perfil
                </button>
            </div>
        </div>

        <!-- Stats Cards -->
        <div class="stats-grid">
            <div class="stat-card animate-fade">
                <div class="stat-icon primary">
                    <i class="fas fa-chart-line"></i>
                </div>
                <div class="stat-info">
                    <div class="stat-value"><?= number_format($promedio_general, 2) ?></div>
                    <div class="stat-label">Promedio General</div>
                    <div class="stat-trend <?= $promedio_general >= $estudiante['nota_minima_aprobacion'] ? 'up' : 'down' ?>">
                        <i class="fas fa-<?= $promedio_general >= $estudiante['nota_minima_aprobacion'] ? 'arrow-up' : 'arrow-down' ?>"></i>
                        <?= $promedio_general >= $estudiante['nota_minima_aprobacion'] ? 'Aprobado' : 'En riesgo' ?>
                    </div>
                </div>
            </div>
            
            <div class="stat-card success animate-fade">
                <div class="stat-icon success">
                    <i class="fas fa-tasks"></i>
                </div>
                <div class="stat-info">
                    <div class="stat-value"><?= $stats_promedio['total_calificadas'] ?></div>
                    <div class="stat-label">Actividades Calificadas</div>
                    <div class="stat-trend up">
                        <i class="fas fa-check"></i> Completadas
                    </div>
                </div>
            </div>
            
            <div class="stat-card warning animate-fade">
                <div class="stat-icon warning">
                    <i class="fas fa-clipboard-list"></i>
                </div>
                <div class="stat-info">
                    <div class="stat-value"><?= $tareas_pendientes ?></div>
                    <div class="stat-label">Tareas Pendientes</div>
                    <div class="stat-trend down">
                        <i class="fas fa-clock"></i> Por entregar
                    </div>
                </div>
            </div>
            
            <div class="stat-card danger animate-fade">
                <div class="stat-icon danger">
                    <i class="fas fa-user-check"></i>
                </div>
                <div class="stat-info">
                    <div class="stat-value"><?= $porcentaje_asistencia ?>%</div>
                    <div class="stat-label">Asistencia</div>
                    <div class="stat-trend <?= $porcentaje_asistencia >= 80 ? 'up' : 'down' ?>">
                        <i class="fas fa-<?= $porcentaje_asistencia >= 80 ? 'check' : 'exclamation' ?>"></i>
                        <?= $porcentaje_asistencia >= 80 ? 'Excelente' : 'Mejorar' ?>
                    </div>
                </div>
            </div>
        </div>

        <div class="row">
            <!-- Left Column: Profile Info & Classes -->
            <div class="col-lg-7">
                <!-- Profile Card -->
                <div class="card-custom animate-fade">
                    <div class="card-header-custom">
                        <h5><i class="fas fa-user"></i> Información Personal</h5>
                        <button class="btn btn-sm btn-outline-primary" onclick="editarPerfil()">
                            <i class="fas fa-edit"></i> Editar
                        </button>
                    </div>
                    <div class="card-body-custom">
                        <div class="row">
                            <div class="col-md-4 text-center mb-3 mb-md-0">
                                <div class="profile-avatar mx-auto mb-3" style="width: 100px; height: 100px; font-size: 2rem;">
                                    <?= strtoupper(substr($estudiante['primer_nombre'], 0, 1)) ?>
                                </div>
                                <h5 class="mb-1"><?= htmlspecialchars($nombre_completo) ?></h5>
                                <p class="text-muted mb-2"><?= $edad ?> años • <?= $estudiante['sexo'] == 'M' ? 'Masculino' : 'Femenino' ?></p>
                                <span class="badge bg-info"><?= htmlspecialchars($estudiante['nie']) ?></span>
                            </div>
                            <div class="col-md-8">
                                <div class="row g-3">
                                    <div class="col-6">
                                        <small class="text-muted d-block">Email</small>
                                        <strong><?= htmlspecialchars($estudiante['email']) ?></strong>
                                    </div>
                                    <div class="col-6">
                                        <small class="text-muted d-block">Celular</small>
                                        <strong><?= htmlspecialchars($estudiante['celular']) ?></strong>
                                    </div>
                                    <div class="col-6">
                                        <small class="text-muted d-block">DUI</small>
                                        <strong><?= htmlspecialchars($estudiante['dui']) ?></strong>
                                    </div>
                                    <div class="col-6">
                                        <small class="text-muted d-block">Nacionalidad</small>
                                        <strong><?= htmlspecialchars($estudiante['nacionalidad']) ?></strong>
                                    </div>
                                    <div class="col-12">
                                        <small class="text-muted d-block">Dirección</small>
                                        <strong><?= htmlspecialchars($estudiante['direccion']) ?></strong>
                                    </div>
                                    <div class="col-6">
                                        <small class="text-muted d-block">Estado Familiar</small>
                                        <strong><?= htmlspecialchars($estudiante['estado_familiar']) ?></strong>
                                    </div>
                                    <div class="col-6">
                                        <small class="text-muted d-block">¿Trabaja?</small>
                                        <strong><?= $estudiante['trabaja'] ? 'Sí' : 'No' ?></strong>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Mis Clases -->
                <div class="card-custom animate-fade">
                    <div class="card-header-custom">
                        <h5><i class="fas fa-book-open"></i> Mis Clases</h5>
                        <a href="mis_clases.php" class="btn btn-sm btn-outline-primary">Ver todas</a>
                    </div>
                    <div class="card-body-custom">
                        <div class="classes-grid">
                            <?php foreach (array_slice($clases, 0, 4) as $clase): 
                                $progreso = $clase['total_actividades'] > 0 
                                    ? round(($clase['actividades_completadas'] / $clase['total_actividades']) * 100) 
                                    : 0;
                                $clase_class = strtolower(preg_replace('/[^a-zA-Z]/', '', explode(' ', $clase['asignatura'])[0]));
                            ?>
                            <div class="class-card <?= $clase_class ?>" onclick="verClase(<?= $clase['id_asignacion'] ?>)">
                                <div class="class-header">
                                    <div>
                                        <div class="class-code"><?= htmlspecialchars($clase['codigo']) ?></div>
                                    </div>
                                    <span class="badge bg-<?= $progreso >= 80 ? 'success' : ($progreso >= 50 ? 'warning' : 'danger') ?>">
                                        <?= $progreso ?>%
                                    </span>
                                </div>
                                <div class="class-title"><?= htmlspecialchars($clase['asignatura']) ?></div>
                                <div class="class-professor">
                                    <i class="fas fa-chalkboard-teacher"></i>
                                    <span>Prof. <?= htmlspecialchars($clase['profesor_apellido']) ?></span>
                                </div>
                                <div class="class-progress">
                                    <div class="class-progress-label">
                                        <span>Progreso</span>
                                        <span><?= $clase['actividades_completadas'] ?>/<?= $clase['total_actividades'] ?></span>
                                    </div>
                                    <div class="progress progress-custom">
                                        <div class="progress-bar" style="width: <?= $progreso ?>%"></div>
                                    </div>
                                </div>
                                <div class="class-stats">
                                    <div class="class-stat">
                                        <span class="class-stat-value"><?= $clase['total_actividades'] ?></span>
                                        <span class="class-stat-label">Actividades</span>
                                    </div>
                                    <div class="class-stat">
                                        <span class="class-stat-value">
                                            <?= $clase['promedio_materia'] ? number_format($clase['promedio_materia'], 1) : '-' ?>
                                        </span>
                                        <span class="class-stat-label">Promedio</span>
                                    </div>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>

                <!-- Últimas Calificaciones -->
                <div class="card-custom animate-fade">
                    <div class="card-header-custom">
                        <h5><i class="fas fa-star"></i> Últimas Calificaciones</h5>
                        <a href="mis_notas.php" class="btn btn-sm btn-outline-primary">Ver historial</a>
                    </div>
                    <div class="card-body-custom">
                        <?php if (empty($ultimas_notas)): ?>
                        <p class="text-muted text-center">Aún no tienes calificaciones registradas.</p>
                        <?php else: ?>
                        <div class="table-responsive">
                            <table class="grades-table">
                                <thead>
                                    <tr>
                                        <th>Actividad</th>
                                        <th>Asignatura</th>
                                        <th>Nota</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($ultimas_notas as $nota): 
                                        $grade_class = $nota['nota_obtenida'] >= $nota['nota_minima_aprobacion'] 
                                            ? ($nota['nota_obtenida'] >= 9 ? 'grade-excellent' : 'grade-good')
                                            : ($nota['nota_obtenida'] >= $nota['nota_minima_aprobacion'] - 1 ? 'grade-regular' : 'grade-fail');
                                    ?>
                                    <tr>
                                        <td>
                                            <div class="fw-medium"><?= htmlspecialchars($nota['titulo']) ?></div>
                                            <?php if ($nota['observacion_docente']): ?>
                                            <div class="grade-observation" title="<?= htmlspecialchars($nota['observacion_docente']) ?>">
                                                💬 <?= htmlspecialchars($nota['observacion_docente']) ?>
                                            </div>
                                            <?php endif; ?>
                                        </td>
                                        <td><small class="text-muted"><?= htmlspecialchars($nota['asignatura']) ?></small></td>
                                        <td class="text-center">
                                            <span class="grade-badge <?= $grade_class ?>">
                                                <?= number_format($nota['nota_obtenida'], 2) ?>
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

            <!-- Right Column: Pending & Exams -->
            <div class="col-lg-5">
                <!-- Actividades Pendientes -->
                <div class="card-custom animate-fade">
                    <div class="card-header-custom">
                        <h5><i class="fas fa-bell"></i> Pendientes</h5>
                        <span class="badge bg-warning text-dark"><?= $tareas_pendientes ?></span>
                    </div>
                    <div class="card-body-custom">
                        <?php if (empty($actividades_pendientes)): ?>
                        <div class="text-center py-4 text-muted">
                            <i class="fas fa-check-circle fa-3x mb-3 text-success"></i>
                            <p>¡Felicidades! No tienes actividades pendientes.</p>
                        </div>
                        <?php else: ?>
                        <?php foreach ($actividades_pendientes as $act): 
                            $tipo = $tipos_actividad[$act['tipo']] ?? ['label' => $act['tipo'], 'icon' => 'fa-tasks', 'color' => 'secondary'];
                            $dias_restantes = ceil((strtotime($act['fecha_limite']) - time()) / (60 * 60 * 24));
                        ?>
                        <div class="activity-item">
                            <div class="activity-icon <?= $tipo['color'] ?>">
                                <i class="fas <?= $tipo['icon'] ?>"></i>
                            </div>
                            <div class="activity-content">
                                <div class="activity-title"><?= htmlspecialchars($act['titulo']) ?></div>
                                <div class="activity-subject">
                                    <i class="fas fa-book"></i> <?= htmlspecialchars($act['asignatura']) ?>
                                </div>
                                <div class="activity-meta">
                                    <span><i class="fas fa-calendar"></i> <?= date('d/m/Y', strtotime($act['fecha_limite'])) ?></span>
                                    <?php if ($dias_restantes <= 2): ?>
                                    <span class="text-danger fw-bold"><i class="fas fa-exclamation-triangle"></i> ¡<?= $dias_restantes ?> día(s)!</span>
                                    <?php else: ?>
                                    <span><i class="fas fa-clock"></i> <?= $dias_restantes ?> día(s)</span>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div class="activity-actions">
                                <?php if ($act['estado_entrega'] == 'pendiente'): ?>
                                <button class="btn-activity primary" onclick="entregarActividad(<?= $act['id'] ?>)">
                                    <i class="fas fa-upload"></i> Entregar
                                </button>
                                <?php else: ?>
                                <span class="badge bg-warning">Entregado</span>
                                <?php endif; ?>
                            </div>
                        </div>
                        <?php endforeach; ?>
                        <div class="text-center mt-3">
                            <a href="actividades.php" class="btn btn-sm btn-outline-primary">Ver todas</a>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Próximos Exámenes -->
                <div class="card-custom animate-fade">
                    <div class="card-header-custom">
                        <h5><i class="fas fa-file-alt"></i> Próximos Exámenes</h5>
                    </div>
                    <div class="card-body-custom">
                        <?php if (empty($proximos_examenes)): ?>
                        <div class="text-center py-4">
                            <i class="fas fa-calendar-check fa-3x mb-3 text-muted"></i>
                            <p class="text-muted">No hay exámenes programados próximamente.</p>
                        </div>
                        <?php else: ?>
                        <?php foreach ($proximos_examenes as $examen): ?>
                        <div class="exam-card">
                            <div class="exam-title"><?= htmlspecialchars($examen['titulo']) ?></div>
                            <div class="exam-subject"><?= htmlspecialchars($examen['asignatura']) ?></div>
                            <div class="exam-details">
                                <div class="exam-detail">
                                    <i class="fas fa-calendar"></i>
                                    <?= date('d/m/Y', strtotime($examen['fecha_programada'])) ?>
                                </div>
                                <div class="exam-detail">
                                    <i class="fas fa-clock"></i>
                                    <?= $examen['tiempo_limite_minutos'] ?> min
                                </div>
                            </div>
                            <?php if ($examen['dias_restantes'] <= 1): ?>
                            <span class="badge bg-danger mb-3">¡Mañana!</span>
                            <?php elseif ($examen['dias_restantes'] <= 3): ?>
                            <span class="badge bg-warning text-dark mb-3">En <?= $examen['dias_restantes'] ?> días</span>
                            <?php endif; ?>
                            <button class="btn-exam" onclick="prepararExamen(<?= $examen['id'] ?>)">
                                <i class="fas fa-play"></i> Preparar
                            </button>
                        </div>
                        <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Mini Calendario -->
                <div class="card-custom animate-fade">
                    <div class="card-header-custom">
                        <h5><i class="fas fa-calendar-alt"></i> Calendario</h5>
                    </div>
                    <div class="card-body-custom">
                        <div id="calendar"></div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Footer -->
        <footer class="text-center py-4 text-muted small">
            <p class="mb-1">© <?= date('Y') ?> Educación Plus - Plataforma de Gestión Educativa</p>
            <p class="mb-0">
                <a href="#" class="text-decoration-none">Términos</a> • 
                <a href="#" class="text-decoration-none">Privacidad</a> • 
                <a href="#" class="text-decoration-none">Soporte</a>
            </p>
        </footer>
    </main>

    <!-- Modal Editar Perfil -->
    <div class="modal fade" id="modalEditarPerfil" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title"><i class="fas fa-user-edit"></i> Editar Perfil</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" action="actualizar_perfil.php">
                    <div class="modal-body">
                        <input type="hidden" name="id_estudiante" value="<?= $id_estudiante ?>">
                        
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label">Primer Nombre</label>
                                <input type="text" name="primer_nombre" class="form-control" value="<?= htmlspecialchars($estudiante['primer_nombre']) ?>" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Primer Apellido</label>
                                <input type="text" name="primer_apellido" class="form-control" value="<?= htmlspecialchars($estudiante['primer_apellido']) ?>" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Email</label>
                                <input type="email" name="email" class="form-control" value="<?= htmlspecialchars($estudiante['email']) ?>" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Celular</label>
                                <input type="text" name="celular" class="form-control" value="<?= htmlspecialchars($estudiante['celular']) ?>">
                            </div>
                            <div class="col-12">
                                <label class="form-label">Dirección</label>
                                <input type="text" name="direccion" class="form-control" value="<?= htmlspecialchars($estudiante['direccion']) ?>">
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                        <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Guardar Cambios</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Scripts -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/fullcalendar@5.11.3/main.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/fullcalendar@5.11.3/locales/es.js"></script>
    
    <script>
        // ===== CONFIGURACIÓN GLOBAL =====
        const CONFIG = {
            studentId: <?= $id_estudiante ?>,
            matriculaId: <?= $id_matricula ?>,
            seccionId: <?= $id_seccion ?>,
            anno: <?= $anno ?>,
            periodo: <?= $periodo ?>
        };
        
        // ===== INICIALIZACIÓN =====
        document.addEventListener('DOMContentLoaded', function() {
            // Animaciones de entrada
            document.querySelectorAll('.animate-fade').forEach((el, i) => {
                setTimeout(() => {
                    el.style.opacity = '1';
                    el.style.transform = 'translateY(0)';
                }, i * 100);
            });
            
            // Toggle sidebar en móvil
            document.getElementById('sidebarToggle')?.addEventListener('click', function() {
                document.getElementById('sidebar').classList.toggle('active');
            });
            
            // Inicializar calendario
            initCalendar();
            
            // Mensaje de bienvenida
            showToast(`¡Bienvenido, <?= addslashes(explode(' ', $estudiante['primer_nombre'])[0]) ?>! 🎓`, 'success', 4000);
        });
        
        // ===== CALENDARIO =====
        function initCalendar() {
            const calendarEl = document.getElementById('calendar');
            if (!calendarEl) return;
            
            const calendar = new FullCalendar.Calendar(calendarEl, {
                initialView: 'dayGridMonth',
                locale: 'es',
                headerToolbar: {
                    left: 'prev,next today',
                    center: 'title',
                    right: 'dayGridMonth,listWeek'
                },
                events: [
                    // Ejemplo de eventos - en producción cargar desde API
                    { title: 'Examen de Matemáticas', start: '<?= date('Y-m-15') ?>', className: 'fc-event-exam' },
                    { title: 'Entrega de Proyecto', start: '<?= date('Y-m-20') ?>', className: 'fc-event-tarea' }
                ],
                eventClick: function(info) {
                    alert(info.event.title);
                },
                height: 'auto'
            });
            
            calendar.render();
        }
        
        // ===== FUNCIONES PRINCIPALES =====
        
        function verClase(idAsignacion) {
            window.location.href = `ver_materia.php?id_asignacion=${idAsignacion}`;
        }
        
        function entregarActividad(idActividad) {
            window.location.href = `entregar_tarea.php?id_actividad=${idActividad}`;
        }
        
        function prepararExamen(idExamen) {
            window.location.href = `tomar_examen.php?id_examen=${idExamen}`;
        }
        
        function editarPerfil() {
            new bootstrap.Modal(document.getElementById('modalEditarPerfil')).show();
        }
        
        // ===== TOAST NOTIFICATIONS =====
        function showToast(message, type = 'info', duration = 5000) {
            const container = document.getElementById('toastContainer');
            const toast = document.createElement('div');
            toast.className = `toast toast-custom ${type} show`;
            toast.innerHTML = `
                <div class="toast-body d-flex align-items-center gap-2">
                    <i class="fas fa-${type === 'success' ? 'check-circle' : type === 'warning' ? 'exclamation-triangle' : type === 'error' ? 'times-circle' : 'info-circle'}"></i>
                    <span>${message}</span>
                    <button type="button" class="btn-close ms-auto" onclick="this.parentElement.parentElement.remove()"></button>
                </div>
            `;
            
            container.appendChild(toast);
            
            setTimeout(() => {
                toast.classList.remove('show');
                setTimeout(() => toast.remove(), 300);
            }, duration);
        }
    </script>
</body>
</html>