<?php
session_start();
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/TenantGuard.php';

// Verificar autenticación y roles permitidos
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['rol'], ['admin', 'director', 'profesor'])) {
    header("Location: " . BASE_URL . "/login.php");
    exit;
}

$database = new Database();
$db = $database->getConnection();
$user_id = $_SESSION['user_id'];
$rol = $_SESSION['rol'];
$tid = TenantGuard::id();

// ===== FUNCIÓN AUXILIAR: Verificar si existe una tabla =====
function tableExists($db, $tableName) {
    try {
        $stmt = $db->prepare("SELECT COUNT(*) FROM information_schema.tables 
                             WHERE table_schema = DATABASE() AND table_name = :table");
        $stmt->bindValue(':table', $tableName, PDO::PARAM_STR);
        $stmt->execute();
        return $stmt->fetchColumn() > 0;
    } catch (Exception $e) {
        return false;
    }
}

// ===== CONFIGURACIÓN DE FILTROS =====
$filtro_anno = $_GET['anno'] ?? date('Y');
$filtro_grado = $_GET['grado'] ?? '';
$filtro_seccion = $_GET['seccion'] ?? '';
$filtro_asignatura = $_GET['asignatura'] ?? '';
$filtro_estudiante = $_GET['estudiante'] ?? '';
$filtro_estado = $_GET['estado'] ?? 'todos';
$ordenar_por = $_GET['ordenar'] ?? 'apellido';

// ===== DATOS PARA FILTROS =====
// NOTA: ya no hay selector de "período" (entero legado 1-4, sin relación con
// tbl_periodo real) -- una asignación docente y una matrícula duran el año
// lectivo completo. Ver la misma nota en estudiante_dashboard.php.
$anios = range(date('Y') - 3, date('Y') + 1);

// Obtener grados
$query = "SELECT id, nombre, nivel, nota_minima_aprobacion FROM tbl_grado ORDER BY nombre";
$grados = $db->query($query)->fetchAll(PDO::FETCH_ASSOC);

// Obtener secciones
$query = "SELECT s.id, s.nombre, g.nombre as grado_nombre, g.nivel
          FROM tbl_seccion s
          JOIN tbl_grado g ON s.id_grado = g.id
          WHERE s.id_institucion = :tid
          ORDER BY g.nombre, s.nombre";
$stmt = $db->prepare($query);
$stmt->bindValue(':tid', $tid, PDO::PARAM_INT);
$stmt->execute();
$secciones = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Obtener asignaturas
$query = "SELECT id, nombre, codigo FROM tbl_asignatura WHERE id_institucion = :tid ORDER BY nombre";
$stmt = $db->prepare($query);
$stmt->bindValue(':tid', $tid, PDO::PARAM_INT);
$stmt->execute();
$asignaturas = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Si es profesor, filtrar por sus asignaciones
$asignaciones_profesor = [];
if ($rol == 'profesor') {
    $query = "SELECT ad.id, ad.id_asignatura
              FROM tbl_asignacion_docente ad
              JOIN tbl_profesor p ON ad.id_profesor = p.id
              JOIN tbl_persona per ON p.id_persona = per.id
              WHERE per.id_usuario = :user_id AND p.id_institucion = :tid";
    $stmt = $db->prepare($query);
    $stmt->bindParam(':user_id', $user_id);
    $stmt->bindValue(':tid', $tid, PDO::PARAM_INT);
    $stmt->execute();
    $asignaciones_profesor = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (empty($filtro_asignatura) && !empty($asignaciones_profesor)) {
        $filtro_asignatura = $asignaciones_profesor[0]['id_asignatura'];
    }
}

// ===== CONSULTA PRINCIPAL DE REPORTES =====
$query = "SELECT
    m.id as id_matricula,
    m.anno, m.estado as estado_matricula,
    
    -- Datos del estudiante
    e.id as id_estudiante, e.nie,
    p.primer_nombre, p.segundo_nombre, p.primer_apellido, p.segundo_apellido,
    p.email as email_estudiante, p.celular,
    
    -- Datos académicos
    g.id as id_grado, g.nombre as grado_nombre, g.nivel, g.nota_minima_aprobacion,
    s.id as id_seccion, s.nombre as seccion_nombre,
    asig.id as id_asignatura, asig.nombre as asignatura_nombre, asig.codigo as codigo_asignatura,
    
    -- Estadísticas de calificaciones
    --
    -- Un examen calificado no deja fila en tbl_entrega_actividad (su nota
    -- vive en tbl_intento_examen) -- sin vw_logro_estudiante
    -- (migrations/2026_08_16_vista_logro_estudiante.sql) este reporte
    -- tampoco veía exámenes calificados: promedio en NULL y estado
    -- pendiente aunque el estudiante ya hubiera presentado y aprobado un
    -- examen.
    COUNT(DISTINCT act.id) as total_actividades,
    COUNT(DISTINCT CASE WHEN v.estado_entrega = 'calificado' THEN act.id END) as actividades_calificadas,
    AVG(CASE WHEN v.estado_entrega = 'calificado' THEN v.nota_obtenida END) as promedio_notas,
    MIN(CASE WHEN v.estado_entrega = 'calificado' THEN v.nota_obtenida END) as nota_minima,
    MAX(CASE WHEN v.estado_entrega = 'calificado' THEN v.nota_obtenida END) as nota_maxima,

    -- Asistencia
    COUNT(DISTINCT CASE WHEN ast.estado = 'presente' THEN ast.id END) as asistencias_presente,
    COUNT(DISTINCT ast.id) as total_asistencias,

    -- Cálculo de aprobación
    CASE
        WHEN AVG(CASE WHEN v.estado_entrega = 'calificado' THEN v.nota_obtenida END) >= g.nota_minima_aprobacion THEN 'aprobado'
        WHEN AVG(CASE WHEN v.estado_entrega = 'calificado' THEN v.nota_obtenida END) IS NOT NULL THEN 'reprobado'
        ELSE 'pendiente'
    END as estado_aprobacion

FROM tbl_matricula m
JOIN tbl_estudiante e ON m.id_estudiante = e.id
JOIN tbl_persona p ON e.id_persona = p.id
JOIN tbl_seccion s ON m.id_seccion = s.id
JOIN tbl_grado g ON s.id_grado = g.id
JOIN tbl_asignacion_docente ad ON s.id = ad.id_seccion AND m.anno = ad.anno
JOIN tbl_asignatura asig ON ad.id_asignatura = asig.id
LEFT JOIN tbl_actividad act ON ad.id = act.id_asignacion_docente
LEFT JOIN vw_logro_estudiante v ON act.id = v.id_actividad AND m.id = v.id_matricula
LEFT JOIN tbl_asistencia ast ON m.id = ast.id_matricula

WHERE m.anno = :anno
AND m.estado = 'activo'
AND e.id_institucion = :tid";

$params = [
    ':anno' => $filtro_anno,
    ':tid' => $tid
];

// Filtros adicionales
if ($rol == 'profesor' && !empty($asignaciones_profesor)) {
    $asig_ids = array_column($asignaciones_profesor, 'id');
    $placeholders = implode(',', array_fill(0, count($asig_ids), '?'));
    $query .= " AND ad.id IN ($placeholders)";
    foreach ($asig_ids as $key => $id) {
        $params[':asig_' . $key] = $id;
    }
}

if (!empty($filtro_grado)) {
    $query .= " AND g.id = :grado";
    $params[':grado'] = $filtro_grado;
}

if (!empty($filtro_seccion)) {
    $query .= " AND s.id = :seccion";
    $params[':seccion'] = $filtro_seccion;
}

if (!empty($filtro_asignatura)) {
    $query .= " AND asig.id = :asignatura";
    $params[':asignatura'] = $filtro_asignatura;
}

if (!empty($filtro_estudiante)) {
    $query .= " AND e.id = :estudiante";
    $params[':estudiante'] = $filtro_estudiante;
}

if ($filtro_estado == 'aprobados') {
    $query .= " HAVING promedio_notas >= g.nota_minima_aprobacion";
} elseif ($filtro_estado == 'reprobados') {
    $query .= " HAVING promedio_notas < g.nota_minima_aprobacion AND promedio_notas IS NOT NULL";
} elseif ($filtro_estado == 'pendientes') {
    $query .= " HAVING promedio_notas IS NULL";
}

// Ordenamiento
$order_clauses = [
    'apellido' => 'p.primer_apellido, p.primer_nombre',
    'promedio' => 'promedio_notas DESC, p.primer_apellido',
    'nie' => 'e.nie',
    'grado' => 'g.nombre, s.nombre, p.primer_apellido'
];
$query .= " GROUP BY m.id, asig.id ORDER BY " . ($order_clauses[$ordenar_por] ?? $order_clauses['apellido']);

$stmt = $db->prepare($query);
foreach ($params as $key => $value) {
    $stmt->bindValue($key, $value);
}
$stmt->execute();
$reporte = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ===== ESTADÍSTICAS GENERALES =====
$stats = [
    'total_estudiantes' => count($reporte),
    'aprobados' => count(array_filter($reporte, fn($r) => $r['estado_aprobacion'] == 'aprobado')),
    'reprobados' => count(array_filter($reporte, fn($r) => $r['estado_aprobacion'] == 'reprobado')),
    'pendientes' => count(array_filter($reporte, fn($r) => $r['estado_aprobacion'] == 'pendiente')),
    'promedio_general' => 0,
    'nota_mas_alta' => 0,
    'nota_mas_baja' => 10
];

$promedios_validos = array_column(array_filter($reporte, fn($r) => $r['promedio_notas']), 'promedio_notas');
if (!empty($promedios_validos)) {
    $stats['promedio_general'] = array_sum($promedios_validos) / count($promedios_validos);
    $stats['nota_mas_alta'] = max($promedios_validos);
    $stats['nota_mas_baja'] = min($promedios_validos);
}

// ===== DETALLE DE ACTIVIDADES POR ESTUDIANTE =====
$actividades_detalle = [];
if (!empty($filtro_asignatura)) {
    $query_act = "SELECT
        act.id, act.titulo, act.tipo, act.fecha_programada, act.nota_maxima,
        v.nota_obtenida, v.observacion_docente, v.estado_entrega,
        m.id as id_matricula
        FROM tbl_actividad act
        JOIN tbl_asignacion_docente ad ON act.id_asignacion_docente = ad.id
        JOIN tbl_asignatura asig ON ad.id_asignatura = asig.id
        LEFT JOIN vw_logro_estudiante v ON act.id = v.id_actividad
        LEFT JOIN tbl_matricula m ON v.id_matricula = m.id
        WHERE ad.id_asignatura = :asignatura
        AND ad.anno = :anno
        AND asig.id_institucion = :tid
        ORDER BY act.fecha_programada";
    $stmt_act = $db->prepare($query_act);
    $stmt_act->execute([
        ':asignatura' => $filtro_asignatura,
        ':anno' => $filtro_anno,
        ':tid' => $tid
    ]);
    $actividades_detalle = $stmt_act->fetchAll(PDO::FETCH_ASSOC);
}

// ===== TIPOS Y ESTADOS PARA VISUALIZACIÓN =====
$tipos_actividad = [
    'tarea' => ['label' => 'Tarea', 'icon' => 'fa-clipboard', 'color' => 'info'],
    'examen' => ['label' => 'Examen', 'icon' => 'fa-file-alt', 'color' => 'danger'],
    'laboratorio' => ['label' => 'Laboratorio', 'icon' => 'fa-flask', 'color' => 'warning'],
    'foro' => ['label' => 'Foro', 'icon' => 'fa-comments', 'color' => 'success'],
    'proyecto' => ['label' => 'Proyecto', 'icon' => 'fa-folder-open', 'color' => 'primary'],
    'otro' => ['label' => 'Otro', 'icon' => 'fa-ellipsis-h', 'color' => 'secondary']
];

$estados_aprobacion = [
    'aprobado' => ['label' => 'Aprobado', 'class' => 'badge-approve', 'icon' => 'fa-check-circle'],
    'reprobado' => ['label' => 'Reprobado', 'class' => 'badge-fail', 'icon' => 'fa-times-circle'],
    'pendiente' => ['label' => 'Pendiente', 'class' => 'badge-pending', 'icon' => 'fa-clock']
];
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reporte de Notas - Educación Plus</title>
    
    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Font Awesome -->
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <!-- DataTables -->
    <link href="https://cdn.datatables.net/1.13.4/css/dataTables.bootstrap5.min.css" rel="stylesheet">
    <link href="https://cdn.datatables.net/buttons/2.3.6/css/buttons.bootstrap5.min.css" rel="stylesheet">
    <!-- Select2 -->
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet">
    
    <style>
        :root {
            --primary: #2c3e50;
            --secondary: #3498db;
            --success: #2ecc71;
            --warning: #f39c12;
            --danger: #e74c3c;
            --info: #17a2b8;
            --light: #f8f9fa;
            --dark: #343a40;
            --sidebar-width: 250px;
        }
        
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: #f5f7fa;
            color: #333;
        }
        
        /* Sidebar Styles */
        .sidebar {
            position: fixed;
            top: 0;
            left: 0;
            height: 100vh;
            width: var(--sidebar-width);
            background: var(--primary);
            color: white;
            padding-top: 60px;
            z-index: 1000;
            transition: transform 0.3s ease;
        }
        
        .sidebar .nav-link {
            color: rgba(255,255,255,0.85);
            padding: 12px 20px;
            margin: 2px 0;
            border-radius: 8px;
            transition: all 0.2s ease;
        }
        
        .sidebar .nav-link:hover,
        .sidebar .nav-link.active {
            color: white;
            background: rgba(255,255,255,0.15);
            transform: translateX(5px);
        }
        
        .sidebar .nav-link i {
            width: 20px;
            margin-right: 10px;
            text-align: center;
        }
        
        /* Main Content */
        .main-content {
            margin-left: var(--sidebar-width);
            padding: 20px 30px;
            transition: margin-left 0.3s ease;
        }
        
        /* Cards */
        .card-report {
            background: white;
            border-radius: 16px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.08);
            border: none;
            margin-bottom: 24px;
        }
        
        .card-header-custom {
            background: linear-gradient(135deg, var(--primary) 0%, var(--secondary) 100%);
            color: white;
            border-radius: 16px 16px 0 0 !important;
            padding: 18px 24px;
            border: none;
        }
        
        /* Stats Cards */
        .stat-card {
            background: white;
            border-radius: 16px;
            padding: 20px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.06);
            border-left: 4px solid var(--secondary);
            transition: all 0.3s ease;
        }
        
        .stat-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 8px 25px rgba(0,0,0,0.1);
        }
        
        .stat-card.success { border-left-color: var(--success); }
        .stat-card.warning { border-left-color: var(--warning); }
        .stat-card.danger { border-left-color: var(--danger); }
        
        .stat-value {
            font-size: 2rem;
            font-weight: 700;
            color: var(--primary);
            margin: 5px 0;
        }
        
        .stat-label {
            color: #666;
            font-size: 0.9rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        .stat-icon {
            font-size: 2.5rem;
            opacity: 0.15;
            position: absolute;
            right: 20px;
            top: 50%;
            transform: translateY(-50%);
        }
        
        /* Table Styles */
        .table-report {
            background: white;
            border-radius: 12px;
            overflow: hidden;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
        }
        
        .table-report thead {
            background: linear-gradient(135deg, var(--primary) 0%, #34495e 100%);
            color: white;
        }
        
        .table-report th {
            font-weight: 600;
            border: none;
            padding: 14px 12px;
            font-size: 0.85rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        .table-report td {
            padding: 12px;
            vertical-align: middle;
            border-color: #eee;
        }
        
        .table-report tbody tr:hover {
            background: #f8f9ff;
            cursor: pointer;
        }
        
        /* Badges */
        .badge-approve {
            background: linear-gradient(135deg, #2ecc71, #27ae60);
            color: white;
            padding: 6px 14px;
            border-radius: 20px;
            font-weight: 500;
            font-size: 0.8rem;
        }
        
        .badge-fail {
            background: linear-gradient(135deg, #e74c3c, #c0392b);
            color: white;
            padding: 6px 14px;
            border-radius: 20px;
            font-weight: 500;
            font-size: 0.8rem;
        }
        
        .badge-pending {
            background: linear-gradient(135deg, #f39c12, #d35400);
            color: white;
            padding: 6px 14px;
            border-radius: 20px;
            font-weight: 500;
            font-size: 0.8rem;
        }
        
        .badge-nivel {
            padding: 4px 10px;
            border-radius: 12px;
            font-size: 0.75rem;
            font-weight: 600;
        }
        
        .badge-nivel.basica { background: #d4edda; color: #155724; }
        .badge-nivel.bachillerato { background: #fff3cd; color: #856404; }
        
        /* Grade Display */
        .grade-display {
            text-align: center;
            font-weight: 700;
            font-size: 1.1rem;
            padding: 8px 12px;
            border-radius: 10px;
            min-width: 60px;
            display: inline-block;
        }
        
        .grade-excellent { background: #d4edda; color: #155724; }
        .grade-good { background: #cce5ff; color: #004085; }
        .grade-regular { background: #fff3cd; color: #856404; }
        .grade-fail { background: #f8d7da; color: #721c24; }
        .grade-pending { background: #e2e3e5; color: #383d41; }
        
        /* Progress Bars */
        .progress-custom {
            height: 8px;
            border-radius: 10px;
            background: #e9ecef;
            overflow: hidden;
        }
        
        .progress-custom .progress-bar {
            border-radius: 10px;
            transition: width 0.6s ease;
        }
        
        /* Filters */
        .filter-card {
            background: white;
            border-radius: 16px;
            padding: 20px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.06);
            margin-bottom: 24px;
        }
        
        .filter-label {
            font-weight: 500;
            color: var(--primary);
            font-size: 0.9rem;
            margin-bottom: 6px;
        }
        
        /* Buttons */
        .btn-report {
            border-radius: 10px;
            padding: 10px 20px;
            font-weight: 500;
            transition: all 0.2s ease;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }
        
        .btn-report:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
        }
        
        .btn-export {
            background: linear-gradient(135deg, #2ecc71, #27ae60);
            border: none;
            color: white;
        }
        
        .btn-print {
            background: linear-gradient(135deg, #3498db, #2980b9);
            border: none;
            color: white;
        }
        
        .btn-pdf {
            background: linear-gradient(135deg, #e74c3c, #c0392b);
            border: none;
            color: white;
        }
        
        /* Modal Styles */
        .modal-content {
            border-radius: 16px;
            border: none;
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
        }
        
        .modal-header {
            background: linear-gradient(135deg, var(--primary) 0%, var(--secondary) 100%);
            color: white;
            border-radius: 16px 16px 0 0;
            border: none;
            padding: 20px 24px;
        }
        
        .modal-header .btn-close {
            filter: brightness(0) invert(1);
        }
        
        /* Avatar */
        .avatar-student {
            width: 45px;
            height: 45px;
            border-radius: 50%;
            background: linear-gradient(135deg, var(--secondary), var(--primary));
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 600;
            font-size: 1.1rem;
            margin-right: 12px;
        }
        
        .student-info {
            display: flex;
            align-items: center;
        }
        
        .student-name {
            font-weight: 600;
            color: var(--primary);
        }
        
        .student-nie {
            font-size: 0.8rem;
            color: #666;
        }
        
        /* Charts Container */
        .chart-container {
            background: white;
            border-radius: 16px;
            padding: 20px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.06);
            margin-bottom: 24px;
            height: 300px;
        }
        
        /* Print Styles */
        @media print {
            .sidebar, .no-print, .filter-card, .btn-report {
                display: none !important;
            }
            .main-content {
                margin-left: 0;
                padding: 0;
            }
            .card-report {
                box-shadow: none;
                border: 1px solid #ddd;
            }
            body {
                background: white;
                color: black;
            }
        }
        
        /* Responsive */
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
        }
        
        /* Animations */
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(10px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        .animate-fade {
            animation: fadeIn 0.4s ease forwards;
            opacity: 0;
        }
        
        .animate-fade:nth-child(1) { animation-delay: 0.1s; }
        .animate-fade:nth-child(2) { animation-delay: 0.2s; }
        .animate-fade:nth-child(3) { animation-delay: 0.3s; }
        .animate-fade:nth-child(4) { animation-delay: 0.4s; }
    </style>
</head>
<body>
    <!-- Sidebar -->
    <div class="sidebar" id="sidebar">
        <div class="text-center mb-4 py-3">
            <i class="fas fa-graduation-cap fa-2x"></i>
            <h5 class="mt-2 fw-bold">Educación Plus</h5>
            <small class="text-white-50"><?= ucfirst($rol) ?></small>
        </div>
        
        <nav class="nav flex-column px-2">
            <a class="nav-link" href="admin_dashboard.php">
                <i class="fas fa-tachometer-alt"></i> Dashboard
            </a>
            <a class="nav-link" href="gestionar_estudiantes.php">
                <i class="fas fa-user-graduate"></i> Estudiantes
            </a>
            <a class="nav-link" href="gestionar_profesores.php">
                <i class="fas fa-chalkboard-teacher"></i> Profesores
            </a>
            <a class="nav-link" href="gestionar_grados.php">
                <i class="fas fa-layer-group"></i> Grados/Secciones
            </a>
            <a class="nav-link" href="gestionar_asignaturas.php">
                <i class="fas fa-book"></i> Asignaturas
            </a>
            <a class="nav-link" href="gestionar_matriculas.php">
                <i class="fas fa-file-signature"></i> Matrículas
            </a>
            <a class="nav-link" href="calificaciones.php">
                <i class="fas fa-star"></i> Calificaciones
            </a>
            <a class="nav-link" href="cuadro_notas.php">
                <i class="fas fa-clipboard-list"></i> Cuadro de Notas
            </a>
            <a class="nav-link active" href="reporte_notas.php">
                <i class="fas fa-chart-bar"></i> Reportes
            </a>
            <hr class="my-2 border-secondary">
            <a class="nav-link" href="manual_convivencia.php">
                <i class="fas fa-handshake"></i> Convivencia Escolar
            </a>
            <a class="nav-link" href="../../logout.php">
                <i class="fas fa-sign-out-alt"></i> Cerrar Sesión
            </a>
        </nav>
    </div>

    <!-- Main Content -->
    <div class="main-content">
        <!-- Header -->
        <div class="d-flex justify-content-between align-items-center mb-4">
            <div>
                <h2 class="fw-bold mb-1">
                    <i class="fas fa-chart-bar text-primary"></i> Reporte de Calificaciones
                </h2>
                <p class="text-muted mb-0">Análisis detallado del rendimiento académico</p>
            </div>
            <div class="d-flex gap-2 no-print">
                <button class="btn btn-report btn-export" onclick="exportarExcel()">
                    <i class="fas fa-file-excel"></i> Excel
                </button>
                <button class="btn btn-report btn-pdf" onclick="exportarPDF()">
                    <i class="fas fa-file-pdf"></i> PDF
                </button>
                <button class="btn btn-report btn-print" onclick="window.print()">
                    <i class="fas fa-print"></i> Imprimir
                </button>
            </div>
        </div>

        <!-- Stats Cards -->
        <div class="row mb-4">
            <div class="col-md-3 col-sm-6 mb-3 animate-fade">
                <div class="stat-card position-relative">
                    <div class="stat-label">Total Estudiantes</div>
                    <div class="stat-value"><?= $stats['total_estudiantes'] ?></div>
                    <i class="fas fa-users stat-icon"></i>
                </div>
            </div>
            <div class="col-md-3 col-sm-6 mb-3 animate-fade">
                <div class="stat-card success position-relative">
                    <div class="stat-label">Aprobados</div>
                    <div class="stat-value text-success"><?= $stats['aprobados'] ?></div>
                    <i class="fas fa-check-circle stat-icon text-success"></i>
                </div>
            </div>
            <div class="col-md-3 col-sm-6 mb-3 animate-fade">
                <div class="stat-card danger position-relative">
                    <div class="stat-label">Reprobados</div>
                    <div class="stat-value text-danger"><?= $stats['reprobados'] ?></div>
                    <i class="fas fa-times-circle stat-icon text-danger"></i>
                </div>
            </div>
            <div class="col-md-3 col-sm-6 mb-3 animate-fade">
                <div class="stat-card warning position-relative">
                    <div class="stat-label">Pendientes</div>
                    <div class="stat-value text-warning"><?= $stats['pendientes'] ?></div>
                    <i class="fas fa-clock stat-icon text-warning"></i>
                </div>
            </div>
        </div>

        <!-- Additional Stats -->
        <div class="row mb-4">
            <div class="col-md-4 mb-3">
                <div class="card-report p-3">
                    <h6 class="text-muted mb-3"><i class="fas fa-chart-line"></i> Estadísticas de Notas</h6>
                    <div class="d-flex justify-content-between align-items-center mb-2">
                        <span>Promedio General:</span>
                        <strong class="fs-5"><?= number_format($stats['promedio_general'], 2) ?></strong>
                    </div>
                    <div class="progress progress-custom mb-3">
                        <div class="progress-bar bg-success" style="width: <?= min($stats['promedio_general'] * 10, 100) ?>%"></div>
                    </div>
                    <div class="row text-center">
                        <div class="col-6">
                            <small class="text-muted">Nota Máxima</small>
                            <div class="fw-bold text-success"><?= number_format($stats['nota_mas_alta'], 2) ?></div>
                        </div>
                        <div class="col-6">
                            <small class="text-muted">Nota Mínima</small>
                            <div class="fw-bold text-danger"><?= number_format($stats['nota_mas_baja'], 2) ?></div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-md-4 mb-3">
                <div class="card-report p-3">
                    <h6 class="text-muted mb-3"><i class="fas fa-percentage"></i> Porcentaje de Aprobación</h6>
                    <div class="text-center py-3">
                        <div class="display-4 fw-bold text-primary">
                            <?= $stats['total_estudiantes'] > 0 ? round(($stats['aprobados'] / $stats['total_estudiantes']) * 100) : 0 ?>%
                        </div>
                        <small class="text-muted">Estudiantes aprobados</small>
                    </div>
                    <div class="progress progress-custom">
                        <div class="progress-bar bg-success" style="width: <?= $stats['total_estudiantes'] > 0 ? ($stats['aprobados'] / $stats['total_estudiantes']) * 100 : 0 ?>%"></div>
                    </div>
                </div>
            </div>
            <div class="col-md-4 mb-3">
                <div class="card-report p-3">
                    <h6 class="text-muted mb-3"><i class="fas fa-tasks"></i> Actividades Evaluadas</h6>
                    <div class="d-flex justify-content-between mb-2">
                        <span>Total Actividades:</span>
                        <strong><?= array_sum(array_column($reporte, 'total_actividades')) ?></strong>
                    </div>
                    <div class="d-flex justify-content-between mb-2">
                        <span>Calificadas:</span>
                        <strong class="text-success"><?= array_sum(array_column($reporte, 'actividades_calificadas')) ?></strong>
                    </div>
                    <div class="d-flex justify-content-between">
                        <span>Pendientes:</span>
                        <strong class="text-warning"><?= array_sum(array_column($reporte, 'total_actividades')) - array_sum(array_column($reporte, 'actividades_calificadas')) ?></strong>
                    </div>
                </div>
            </div>
        </div>

        <!-- Filters -->
        <div class="filter-card">
            <form method="GET" class="row g-3">
                <div class="col-lg-2 col-md-4 col-sm-6">
                    <label class="filter-label">Año Lectivo</label>
                    <select name="anno" class="form-select form-select-sm" onchange="this.form.submit()">
                        <?php foreach ($anios as $anio): ?>
                        <option value="<?= $anio ?>" <?= $filtro_anno == $anio ? 'selected' : '' ?>><?= $anio ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-lg-2 col-md-4 col-sm-6">
                    <label class="filter-label">Grado</label>
                    <select name="grado" class="form-select form-select-sm" onchange="this.form.submit()">
                        <option value="">Todos</option>
                        <?php foreach ($grados as $grado): ?>
                        <option value="<?= $grado['id'] ?>" <?= $filtro_grado == $grado['id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($grado['nombre']) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-lg-2 col-md-4 col-sm-6">
                    <label class="filter-label">Sección</label>
                    <select name="seccion" class="form-select form-select-sm" onchange="this.form.submit()">
                        <option value="">Todas</option>
                        <?php foreach ($secciones as $seccion): ?>
                        <option value="<?= $seccion['id'] ?>" <?= $filtro_seccion == $seccion['id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($seccion['nombre']) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-lg-2 col-md-4 col-sm-6">
                    <label class="filter-label">Asignatura</label>
                    <select name="asignatura" class="form-select form-select-sm select2-asignatura" onchange="this.form.submit()">
                        <option value="">Todas</option>
                        <?php foreach ($asignaturas as $asig): ?>
                        <option value="<?= $asig['id'] ?>" <?= $filtro_asignatura == $asig['id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($asig['nombre']) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-lg-2 col-md-4 col-sm-6">
                    <label class="filter-label">Estado</label>
                    <select name="estado" class="form-select form-select-sm" onchange="this.form.submit()">
                        <option value="todos" <?= $filtro_estado == 'todos' ? 'selected' : '' ?>>Todos</option>
                        <option value="aprobados" <?= $filtro_estado == 'aprobados' ? 'selected' : '' ?>>Aprobados</option>
                        <option value="reprobados" <?= $filtro_estado == 'reprobados' ? 'selected' : '' ?>>Reprobados</option>
                        <option value="pendientes" <?= $filtro_estado == 'pendientes' ? 'selected' : '' ?>>Pendientes</option>
                    </select>
                </div>
                <div class="col-12">
                    <div class="d-flex gap-2 flex-wrap">
                        <button type="submit" class="btn btn-sm btn-primary">
                            <i class="fas fa-filter"></i> Aplicar Filtros
                        </button>
                        <a href="reporte_notas.php" class="btn btn-sm btn-outline-secondary">
                            <i class="fas fa-redo"></i> Limpiar
                        </a>
                        <div class="vr mx-2"></div>
                        <label class="filter-label d-flex align-items-center gap-2">
                            Ordenar por:
                            <select name="ordenar" class="form-select form-select-sm" style="width: auto;" onchange="this.form.submit()">
                                <option value="apellido" <?= $ordenar_por == 'apellido' ? 'selected' : '' ?>>Apellido</option>
                                <option value="promedio" <?= $ordenar_por == 'promedio' ? 'selected' : '' ?>>Promedio</option>
                                <option value="nie" <?= $ordenar_por == 'nie' ? 'selected' : '' ?>>NIE</option>
                                <option value="grado" <?= $ordenar_por == 'grado' ? 'selected' : '' ?>>Grado/Sección</option>
                            </select>
                        </label>
                    </div>
                </div>
            </form>
        </div>

        <!-- Report Table -->
        <div class="card-report">
            <div class="card-header-custom d-flex justify-content-between align-items-center">
                <h5 class="mb-0">
                    <i class="fas fa-list"></i> Detalle de Calificaciones
                </h5>
                <span class="badge bg-light text-primary">
                    <?= count($reporte) ?> registros
                </span>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-report mb-0" id="tablaReporte">
                        <thead>
                            <tr>
                                <th>Estudiante</th>
                                <th>Grado/Sección</th>
                                <th class="text-center">Actividades</th>
                                <th class="text-center">Promedio</th>
                                <th class="text-center">Asistencia</th>
                                <th class="text-center">Estado</th>
                                <th class="text-center no-print">Acciones</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php // Sin fila manual de "sin datos": con DataTables, una tbody con
                            // una sola fila de 1 <td colspan> frente a un thead de más columnas
                            // dispara "Incorrect column count" (tn/18). Con tbody vacío, DataTables
                            // muestra su propio mensaje localizado (idioma es-ES cargado abajo). ?>
                            <?php foreach ($reporte as $row):
                                $nota_min = $row['nota_minima_aprobacion'];
                                $promedio = $row['promedio_notas'];
                                $estado = $row['estado_aprobacion'];
                                
                                $grade_class = 'grade-pending';
                                if ($promedio !== null) {
                                    if ($promedio >= 9) $grade_class = 'grade-excellent';
                                    elseif ($promedio >= 7) $grade_class = 'grade-good';
                                    elseif ($promedio >= $nota_min) $grade_class = 'grade-regular';
                                    else $grade_class = 'grade-fail';
                                }
                            ?>
                            <tr onclick="verDetalleEstudiante(<?= $row['id_matricula'] ?>)" style="cursor: pointer;">
                                <td>
                                    <div class="student-info">
                                        <div class="avatar-student">
                                            <?= strtoupper(substr($row['primer_nombre'], 0, 1)) ?>
                                        </div>
                                        <div>
                                            <div class="student-name">
                                                <?= htmlspecialchars($row['primer_apellido'] . ', ' . $row['primer_nombre']) ?>
                                            </div>
                                            <div class="student-nie">NIE: <?= htmlspecialchars($row['nie']) ?></div>
                                        </div>
                                    </div>
                                </td>
                                <td>
                                    <div class="fw-medium"><?= htmlspecialchars($row['grado_nombre']) ?></div>
                                    <span class="badge bg-info"><?= htmlspecialchars($row['seccion_nombre']) ?></span>
                                    <br>
                                    <span class="badge-nivel <?= $row['nivel'] ?>">
                                        <?= ucfirst($row['nivel']) ?>
                                    </span>
                                </td>
                                <td class="text-center">
                                    <span class="badge bg-warning text-dark">
                                        <?= $row['actividades_calificadas'] ?>/<?= $row['total_actividades'] ?>
                                    </span>
                                    <div class="progress progress-custom mt-1" style="height: 4px;">
                                        <div class="progress-bar bg-warning" style="width: <?= $row['total_actividades'] > 0 ? ($row['actividades_calificadas'] / $row['total_actividades']) * 100 : 0 ?>%"></div>
                                    </div>
                                </td>
                                <td class="text-center">
                                    <?php if ($promedio !== null): ?>
                                    <div class="grade-display <?= $grade_class ?>">
                                        <?= number_format($promedio, 2) ?>
                                    </div>
                                    <?php else: ?>
                                    <span class="text-muted">-</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-center">
                                    <?php if ($row['total_asistencias'] > 0): 
                                        $porcentaje_asistencia = ($row['asistencias_presente'] / $row['total_asistencias']) * 100;
                                    ?>
                                    <div class="fw-medium"><?= round($porcentaje_asistencia) ?>%</div>
                                    <div class="progress progress-custom mt-1" style="height: 4px;">
                                        <div class="progress-bar <?= $porcentaje_asistencia >= 80 ? 'bg-success' : ($porcentaje_asistencia >= 60 ? 'bg-warning' : 'bg-danger') ?>" 
                                             style="width: <?= $porcentaje_asistencia ?>%"></div>
                                    </div>
                                    <?php else: ?>
                                    <span class="text-muted">N/A</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-center">
                                    <span class="<?= $estados_aprobacion[$estado]['class'] ?>">
                                        <i class="fas <?= $estados_aprobacion[$estado]['icon'] ?>"></i>
                                        <?= $estados_aprobacion[$estado]['label'] ?>
                                    </span>
                                </td>
                                <td class="text-center no-print">
                                    <div class="btn-group btn-group-sm">
                                        <button class="btn btn-info" onclick="event.stopPropagation(); verDetalleEstudiante(<?= $row['id_matricula'] ?>)" title="Ver Detalle">
                                            <i class="fas fa-eye"></i>
                                        </button>
                                        <button class="btn btn-success" onclick="event.stopPropagation(); generarBoletin(<?= $row['id_matricula'] ?>)" title="Boletín PDF">
                                            <i class="fas fa-file-pdf"></i>
                                        </button>
                                        <button class="btn btn-primary" onclick="event.stopPropagation(); enviarNotificacion(<?= $row['id_estudiante'] ?>)" title="Notificar">
                                            <i class="fas fa-bell"></i>
                                        </button>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- Gráficos (si hay datos) -->
        <?php if (!empty($reporte) && !empty($filtro_asignatura)): ?>
        <div class="row">
            <div class="col-lg-8 mb-4">
                <div class="chart-container">
                    <canvas id="chartNotas"></canvas>
                </div>
            </div>
            <div class="col-lg-4 mb-4">
                <div class="chart-container">
                    <canvas id="chartEstados"></canvas>
                </div>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <!-- Modal Detalle Estudiante -->
    <div class="modal fade" id="modalDetalle" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="fas fa-user-graduate"></i> Detalle de Calificaciones
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body" id="modalDetalleContent">
                    <div class="text-center py-4">
                        <div class="spinner-border text-primary" role="status"></div>
                        <p class="mt-2 text-muted">Cargando información...</p>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cerrar</button>
                    <button type="button" class="btn btn-primary" onclick="imprimirDetalle()">
                        <i class="fas fa-print"></i> Imprimir
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Bootstrap 5 JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <!-- jQuery -->
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <!-- DataTables -->
    <script src="https://cdn.datatables.net/1.13.4/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.4/js/dataTables.bootstrap5.min.js"></script>
    <script src="https://cdn.datatables.net/buttons/2.3.6/js/dataTables.buttons.min.js"></script>
    <script src="https://cdn.datatables.net/buttons/2.3.6/js/buttons.bootstrap5.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jszip/3.10.1/jszip.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/pdfmake/0.2.7/pdfmake.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/pdfmake/0.2.7/vfs_fonts.js"></script>
    <script src="https://cdn.datatables.net/buttons/2.3.6/js/buttons.html5.min.js"></script>
    <script src="https://cdn.datatables.net/buttons/2.3.6/js/buttons.print.min.js"></script>
    <!-- Select2 -->
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
    <!-- Chart.js -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.3.0"></script>
    
    <script>
        $(document).ready(function() {
            // Inicializar DataTable con botones de exportación
            const table = $('#tablaReporte').DataTable({
                language: {
                    url: '//cdn.datatables.net/plug-ins/1.13.4/i18n/es-ES.json'
                },
                pageLength: 15,
                order: [[0, 'asc']],
                dom: 'Bfrtip',
                buttons: [
                    {
                        extend: 'excelHtml5',
                        text: '<i class="fas fa-file-excel"></i> Excel',
                        className: 'btn btn-success btn-sm',
                        exportOptions: {
                            columns: ':not(.no-print)'
                        }
                    },
                    {
                        extend: 'pdfHtml5',
                        text: '<i class="fas fa-file-pdf"></i> PDF',
                        className: 'btn btn-danger btn-sm',
                        orientation: 'landscape',
                        pageSize: 'A4',
                        exportOptions: {
                            columns: ':not(.no-print)'
                        }
                    },
                    {
                        extend: 'print',
                        text: '<i class="fas fa-print"></i> Imprimir',
                        className: 'btn btn-primary btn-sm',
                        exportOptions: {
                            columns: ':not(.no-print)'
                        }
                    }
                ]
            });
            
            // Inicializar Select2
            $('.select2-asignatura').select2({
                placeholder: 'Seleccionar asignatura',
                allowClear: true,
                width: '100%',
                dropdownParent: $('.filter-card')
            });
            
            // Sidebar toggle en móvil
            $('#sidebarToggle').click(function() {
                $('#sidebar').toggleClass('active');
            });
            
            // Marcar nav link activo
            const currentPath = window.location.pathname;
            $('.sidebar .nav-link').each(function() {
                if (currentPath.includes($(this).attr('href'))) {
                    $(this).addClass('active');
                }
            });
            
            // Inicializar gráficos si existen
            initCharts();
        });
        
        // ===== FUNCIONES PRINCIPALES =====
        
        function verDetalleEstudiante(idMatricula) {
            $('#modalDetalle').modal('show');
            
            $.ajax({
                url: 'api/get_detalle_reporte.php',
                method: 'GET',
                data: { id_matricula: idMatricula },
                success: function(response) {
                    $('#modalDetalleContent').html(response);
                },
                error: function() {
                    $('#modalDetalleContent').html(`
                        <div class="alert alert-danger">
                            <i class="fas fa-exclamation-circle"></i> 
                            Error al cargar el detalle. Intente nuevamente.
                        </div>
                    `);
                }
            });
        }
        
        function generarBoletin(idMatricula) {
            window.open(`reportes/generar_boletin.php?id_matricula=${idMatricula}`, '_blank');
        }
        
        function enviarNotificacion(idEstudiante) {
            if (confirm('¿Enviar notificación de calificaciones al estudiante y responsable?')) {
                $.post('api/enviar_notificacion.php', {
                    id_estudiante: idEstudiante,
                    tipo: 'calificaciones'
                }, function(response) {
                    alert(response.mensaje || 'Notificación enviada correctamente');
                }).fail(function() {
                    alert('Error al enviar la notificación');
                });
            }
        }
        
        function exportarExcel() {
            const params = new URLSearchParams(window.location.search);
            window.location.href = `api/exportar_reporte_excel.php?${params.toString()}`;
        }
        
        function exportarPDF() {
            const params = new URLSearchParams(window.location.search);
            window.open(`api/exportar_reporte_pdf.php?${params.toString()}`, '_blank');
        }
        
        function imprimirDetalle() {
            const contenido = document.getElementById('modalDetalleContent').innerHTML;
            const ventana = window.open('', '_blank');
            ventana.document.write(`
                <html>
                <head>
                    <title>Detalle de Calificaciones - Educación Plus</title>
                    <style>
                        body { font-family: Arial, sans-serif; padding: 20px; }
                        .header { text-align: center; margin-bottom: 30px; border-bottom: 2px solid #333; padding-bottom: 20px; }
                        table { width: 100%; border-collapse: collapse; margin: 20px 0; }
                        th, td { border: 1px solid #ddd; padding: 10px; text-align: left; }
                        th { background: #2c3e50; color: white; }
                        .nota-aprobada { color: #2ecc71; font-weight: bold; }
                        .nota-reprobada { color: #e74c3c; font-weight: bold; }
                    </style>
                </head>
                <body>
                    <div class="header">
                        <h2>Educación Plus</h2>
                        <p>Reporte Detallado de Calificaciones</p>
                        <p>Fecha: ${new Date().toLocaleDateString('es-SV')}</p>
                    </div>
                    ${contenido}
                    <script>window.onload = function() { window.print(); window.close(); }<\/script>
                </body>
                </html>
            `);
            ventana.document.close();
        }
        
        // ===== GRÁFICOS =====
        function initCharts() {
            const ctxNotas = document.getElementById('chartNotas');
            const ctxEstados = document.getElementById('chartEstados');
            
            if (ctxNotas && ctxEstados) {
                const labels = <?= json_encode(array_column(array_slice($reporte, 0, 10), 'primer_apellido')) ?>;
                const dataNotas = <?= json_encode(array_map(fn($r) => $r['promedio_notas'] ?? 0, array_slice($reporte, 0, 10))) ?>;
                
                new Chart(ctxNotas, {
                    type: 'bar',
                    data: {
                        labels: labels,
                        datasets: [{
                            label: 'Promedio de Notas',
                            data: dataNotas,
                            backgroundColor: dataNotas.map(n => 
                                n >= 7 ? 'rgba(46, 204, 113, 0.7)' : 'rgba(231, 76, 60, 0.7)'
                            ),
                            borderColor: dataNotas.map(n => 
                                n >= 7 ? '#2ecc71' : '#e74c3c'
                            ),
                            borderWidth: 1
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        plugins: {
                            title: {
                                display: true,
                                text: 'Distribución de Promedios por Estudiante'
                            },
                            legend: { display: false }
                        },
                        scales: {
                            y: {
                                beginAtZero: true,
                                max: 10,
                                title: { display: true, text: 'Nota' }
                            }
                        }
                    }
                });
                
                const estados = {
                    aprobados: <?= $stats['aprobados'] ?>,
                    reprobados: <?= $stats['reprobados'] ?>,
                    pendientes: <?= $stats['pendientes'] ?>
                };
                
                new Chart(ctxEstados, {
                    type: 'doughnut',
                    data: {
                        labels: ['Aprobados', 'Reprobados', 'Pendientes'],
                        datasets: [{
                            data: [estados.aprobados, estados.reprobados, estados.pendientes],
                            backgroundColor: ['#2ecc71', '#e74c3c', '#f39c12'],
                            borderWidth: 2,
                            borderColor: '#fff'
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        plugins: {
                            title: {
                                display: true,
                                text: 'Estado de Aprobación'
                            },
                            legend: {
                                position: 'bottom'
                            }
                        }
                    }
                });
            }
        }
    </script>
</body>
</html>