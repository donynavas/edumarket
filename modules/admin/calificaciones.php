<?php
session_start();
require_once __DIR__ . '/../../config/database.php';

// Verificar que sea admin, director o profesor
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['rol'], ['admin', 'director', 'profesor'])) {
    header("Location: " . BASE_URL . "/login.php");
    exit;
}

$database = new Database();
$db = $database->getConnection();
$user_id = $_SESSION['user_id'];
$rol = $_SESSION['rol'];

// Variables para mensajes
$mensaje = '';
$tipo_mensaje = '';

// Procesar acciones POST
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $accion = $_POST['accion'] ?? '';
    
    try {
        $db->beginTransaction();
        
        // GUARDAR/ACTUALIZAR NOTA DE ACTIVIDAD
        if ($accion == 'guardar_nota') {
            $id_entrega = $_POST['id_entrega'];
            $nota = floatval($_POST['nota_obtenida']);
            $observacion = $_POST['observacion_docente'] ?? '';
            
            // Obtener información de la entrega para validar nota máxima
            $query = "SELECT ea.*, a.nota_maxima, g.nota_minima_aprobacion, g.nivel
                      FROM tbl_entrega_actividad ea
                      JOIN tbl_actividad act ON ea.id_actividad = act.id
                      JOIN tbl_asignacion_docente ad ON act.id_asignacion_docente = ad.id
                      JOIN tbl_asignatura asig ON ad.id_asignatura = asig.id
                      JOIN tbl_matricula m ON ea.id_matricula = m.id
                      JOIN tbl_seccion s ON m.id_seccion = s.id
                      JOIN tbl_grado g ON s.id_grado = g.id
                      WHERE ea.id = :id";
            $stmt = $db->prepare($query);
            $stmt->bindValue(':id', $id_entrega, PDO::PARAM_INT);
            $stmt->execute();
            $info = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($info) {
                // Validar que la nota no exceda la máxima
                if ($nota > $info['nota_maxima']) {
                    throw new Exception('La nota no puede ser mayor a la nota máxima de la actividad (' . $info['nota_maxima'] . ')');
                }
                
                // Determinar estado según nota mínima del nivel
                $estado = ($nota >= $info['nota_minima_aprobacion']) ? 'calificado' : 'reprobado';
                
                $query = "UPDATE tbl_entrega_actividad SET nota_obtenida = :nota, 
                          observacion_docente = :observacion, estado_entrega = :estado, 
                          fecha_entrega = NOW() 
                          WHERE id = :id";
                $stmt = $db->prepare($query);
                $stmt->bindValue(':nota', $nota, PDO::PARAM_STR);
                $stmt->bindValue(':observacion', $observacion, PDO::PARAM_STR);
                $stmt->bindValue(':estado', $estado, PDO::PARAM_STR);
                $stmt->bindValue(':id', $id_entrega, PDO::PARAM_INT);
                $stmt->execute();
                
                // Registrar log de calificación
                $logQuery = "INSERT INTO tbl_logs_actividad (id_usuario, accion, ip_address) 
                            VALUES (:id, 'Calificar Actividad', :ip)";
                $logStmt = $db->prepare($logQuery);
                $logStmt->bindValue(':id', $user_id, PDO::PARAM_INT);
                $logStmt->bindValue(':ip', $_SERVER['REMOTE_ADDR'], PDO::PARAM_STR);
                $logStmt->execute();
            }
            
            $db->commit();
            $mensaje = 'Nota guardada exitosamente';
            $tipo_mensaje = 'success';
        }
        
        // GUARDAR MÚLTIPLES NOTAS
        elseif ($accion == 'guardar_notas_multiple') {
            $notas = $_POST['notas'] ?? [];
            $observaciones = $_POST['observaciones'] ?? [];
            
            foreach ($notas as $id_entrega => $nota) {
                if (!empty($nota)) {
                    $observacion = $observaciones[$id_entrega] ?? '';
                    
                    $query = "UPDATE tbl_entrega_actividad SET nota_obtenida = :nota, 
                              observacion_docente = :observacion, 
                              estado_entrega = CASE WHEN :nota >= (
                                  SELECT g.nota_minima_aprobacion 
                                  FROM tbl_entrega_actividad ea
                                  JOIN tbl_actividad act ON ea.id_actividad = act.id
                                  JOIN tbl_asignacion_docente ad ON act.id_asignacion_docente = ad.id
                                  JOIN tbl_matricula m ON ea.id_matricula = m.id
                                  JOIN tbl_seccion s ON m.id_seccion = s.id
                                  JOIN tbl_grado g ON s.id_grado = g.id
                                  WHERE ea.id = :id2
                              ) THEN 'calificado' ELSE 'reprobado' END
                              WHERE id = :id";
                    $stmt = $db->prepare($query);
                    $stmt->bindValue(':nota', $nota, PDO::PARAM_STR);
                    $stmt->bindValue(':observacion', $observacion, PDO::PARAM_STR);
                    $stmt->bindValue(':id', $id_entrega, PDO::PARAM_INT);
                    $stmt->bindValue(':id2', $id_entrega, PDO::PARAM_INT);
                    $stmt->execute();
                }
            }
            
            $db->commit();
            $mensaje = 'Notas guardadas exitosamente';
            $tipo_mensaje = 'success';
        }
        
    } catch (Exception $e) {
        $db->rollBack();
        $mensaje = 'Error: ' . $e->getMessage();
        $tipo_mensaje = 'danger';
    }
}

// ===== FILTROS =====
$filtro_grado = $_GET['grado'] ?? '';
$filtro_seccion = $_GET['seccion'] ?? '';
$filtro_asignatura = $_GET['asignatura'] ?? '';
$filtro_periodo = $_GET['periodo'] ?? 1;
$filtro_anno = $_GET['anno'] ?? date('Y');
$busqueda = $_GET['busqueda'] ?? '';

// Si es profesor, filtrar por sus asignaciones
if ($rol == 'profesor') {
    $queryAsignacion = "SELECT id FROM tbl_asignacion_docente WHERE id_profesor = (
                        SELECT id FROM tbl_profesor WHERE id_persona = (
                        SELECT id_persona FROM tbl_usuario WHERE id = :user_id))";
    $stmtAsignacion = $db->prepare($queryAsignacion);
    $stmtAsignacion->bindValue(':user_id', $user_id, PDO::PARAM_INT);
    $stmtAsignacion->execute();
    $asignaciones_profesor = $stmtAsignacion->fetchAll(PDO::FETCH_COLUMN);
    
    if (empty($filtro_asignatura) && !empty($asignaciones_profesor)) {
        $filtro_asignatura = $asignaciones_profesor[0];
    }
}

// ===== OBTENER CALIFICACIONES - ✅ CORREGIDO: SIN act.id_periodo =====
$query = "SELECT 
    m.id as id_matricula,
    m.anno, m.id_periodo, m.estado as estado_matricula,
    
    -- Datos del estudiante
    e.id as id_estudiante, e.nie,
    p.primer_nombre, p.segundo_nombre, p.primer_apellido, p.segundo_apellido,
    p.email as email_estudiante, p.celular,
    
    -- Datos académicos
    g.id as id_grado, g.nombre as grado_nombre, g.nivel, g.nota_minima_aprobacion,
    s.id as id_seccion, s.nombre as seccion_nombre,
    asig.id as id_asignatura, asig.nombre as asignatura_nombre, asig.codigo as codigo_asignatura,
    
    -- Estadísticas de calificaciones
    COUNT(DISTINCT act.id) as total_actividades,
    COUNT(DISTINCT CASE WHEN ea.nota_obtenida IS NOT NULL THEN ea.id END) as actividades_calificadas,
    AVG(ea.nota_obtenida) as promedio_notas,
    MIN(ea.nota_obtenida) as nota_minima,
    MAX(ea.nota_obtenida) as nota_maxima,
    
    -- Asistencia
    COUNT(DISTINCT CASE WHEN ast.estado = 'presente' THEN ast.id END) as asistencias_presente,
    COUNT(DISTINCT ast.id) as total_asistencias,
    
    -- Cálculo de aprobación
    CASE 
        WHEN AVG(ea.nota_obtenida) >= g.nota_minima_aprobacion THEN 'aprobado'
        WHEN AVG(ea.nota_obtenida) IS NOT NULL THEN 'reprobado'
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
    AND ad.id_periodo = :periodo  -- ✅ CORRECCIÓN: Usar ad.id_periodo, NO act.id_periodo
LEFT JOIN tbl_entrega_actividad ea ON act.id = ea.id_actividad AND m.id = ea.id_matricula
LEFT JOIN tbl_asistencia ast ON m.id = ast.id_matricula

WHERE m.anno = :anno
AND m.id_periodo = :periodo2  -- ✅ Usar m.id_periodo de tbl_matricula
AND m.estado = 'activo'";

$params = [
    ':anno' => $filtro_anno,
    ':periodo' => $filtro_periodo,
    ':periodo2' => $filtro_periodo
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

if (!empty($busqueda)) {
    $query .= " AND (p.primer_nombre LIKE :busqueda OR p.primer_apellido LIKE :busqueda OR e.nie LIKE :busqueda)";
    $params[':busqueda'] = "%$busqueda%";
}

$query .= " GROUP BY m.id, asig.id ORDER BY p.primer_apellido, p.primer_nombre";

$stmt = $db->prepare($query);

// ✅ CORRECCIÓN: Usar bindValue en lugar de bindParam
foreach ($params as $key => $value) {
    $stmt->bindValue($key, $value);
}

$stmt->execute();
$calificaciones = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ===== OBTENER ACTIVIDADES POR ASIGNACIÓN =====
$actividades_por_asignacion = [];
if (!empty($filtro_asignatura)) {
    // ✅ CORRECCIÓN: Usar ad.id_periodo, NO act.id_periodo
    $query_act = "SELECT 
        act.id, act.titulo, act.tipo, act.fecha_programada, act.fecha_limite, act.nota_maxima,
        ea.nota_obtenida, ea.observacion_docente, ea.estado_entrega,
        m.id as id_matricula
        FROM tbl_actividad act
        JOIN tbl_asignacion_docente ad ON act.id_asignacion_docente = ad.id
        LEFT JOIN tbl_entrega_actividad ea ON act.id = ea.id_actividad
        LEFT JOIN tbl_matricula m ON ea.id_matricula = m.id
        WHERE ad.id_asignatura = :asignatura
        AND ad.id_periodo = :periodo  -- ✅ CORRECCIÓN
        AND ad.anno = :anno
        ORDER BY act.fecha_programada";
    $stmt_act = $db->prepare($query_act);
    $stmt_act->bindValue(':asignatura', $filtro_asignatura, PDO::PARAM_INT);
    $stmt_act->bindValue(':periodo', $filtro_periodo, PDO::PARAM_INT);
    $stmt_act->bindValue(':anno', $filtro_anno, PDO::PARAM_STR);
    $stmt_act->execute();
    $actividades_por_asignacion = $stmt_act->fetchAll(PDO::FETCH_ASSOC);
}

// ===== DATOS PARA FILTROS =====

// Obtener grados
$query = "SELECT id, nombre, nivel, nota_minima_aprobacion FROM tbl_grado ORDER BY nombre";
$stmt = $db->prepare($query);
$stmt->execute();
$grados = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Obtener secciones
$query = "SELECT s.id, s.nombre, g.nombre as grado_nombre FROM tbl_seccion s
          JOIN tbl_grado g ON s.id_grado = g.id ORDER BY g.nombre, s.nombre";
$stmt = $db->prepare($query);
$stmt->execute();
$secciones = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Obtener asignaturas
$query = "SELECT id, nombre, codigo FROM tbl_asignatura ORDER BY nombre";
$stmt = $db->prepare($query);
$stmt->execute();
$asignaturas = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Períodos
$periodos = [1 => 'Primer Trimestre', 2 => 'Segundo Trimestre', 3 => 'Tercer Trimestre', 4 => 'Cuarto Trimestre'];
$anno_actual = date('Y');
$anios = range($anno_actual - 2, $anno_actual + 1);

// Tipos de actividad
$tipos_actividad = [
    'tarea' => ['label' => 'Tarea', 'icon' => 'fa-clipboard', 'color' => 'info'],
    'examen' => ['label' => 'Examen', 'icon' => 'fa-file-alt', 'color' => 'danger'],
    'laboratorio' => ['label' => 'Laboratorio', 'icon' => 'fa-flask', 'color' => 'warning'],
    'foro' => ['label' => 'Foro', 'icon' => 'fa-comments', 'color' => 'success'],
    'proyecto' => ['label' => 'Proyecto', 'icon' => 'fa-folder-open', 'color' => 'primary']
];

// Estados de aprobación
$estados_aprobacion = [
    'aprobado' => ['label' => 'Aprobado', 'class' => 'badge-approve', 'icon' => 'fa-check-circle'],
    'reprobado' => ['label' => 'Reprobado', 'class' => 'badge-fail', 'icon' => 'fa-times-circle'],
    'pendiente' => ['label' => 'Pendiente', 'class' => 'badge-pending', 'icon' => 'fa-clock']
];

// Estadísticas
$stats = [
    'total_estudiantes' => count($calificaciones),
    'aprobados' => count(array_filter($calificaciones, fn($r) => $r['estado_aprobacion'] == 'aprobado')),
    'reprobados' => count(array_filter($calificaciones, fn($r) => $r['estado_aprobacion'] == 'reprobado')),
    'pendientes' => count(array_filter($calificaciones, fn($r) => $r['estado_aprobacion'] == 'pendiente')),
    'promedio_general' => 0,
    'nota_mas_alta' => 0,
    'nota_mas_baja' => 10
];

$promedios_validos = array_column(array_filter($calificaciones, fn($r) => $r['promedio_notas']), 'promedio_notas');
if (!empty($promedios_validos)) {
    $stats['promedio_general'] = array_sum($promedios_validos) / count($promedios_validos);
    $stats['nota_mas_alta'] = max($promedios_validos);
    $stats['nota_mas_baja'] = min($promedios_validos);
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Calificaciones - Educación Plus</title>
    
    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Font Awesome -->
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <!-- DataTables -->
    <link href="https://cdn.datatables.net/1.13.4/css/dataTables.bootstrap5.min.css" rel="stylesheet">
    <!-- Select2 -->
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet">
    
    <style>
        :root {
            --primary: #2c3e50;
            --secondary: #3498db;
            --success: #2ecc71;
            --warning: #f39c12;
            --danger: #e74c3c;
            --sidebar-width: 250px;
        }
        
        body {
            font-family: 'Segoe UI', sans-serif;
            background: #f8f9fa;
        }
        
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
        }
        
        .sidebar .nav-link {
            color: rgba(255,255,255,0.8);
            padding: 12px 20px;
        }
        
        .sidebar .nav-link:hover,
        .sidebar .nav-link.active {
            color: white;
            background: rgba(255,255,255,0.15);
        }
        
        .main-content {
            margin-left: var(--sidebar-width);
            padding: 20px;
        }
        
        .card-custom {
            background: white;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.08);
            border: none;
            margin-bottom: 24px;
        }
        
        .stats-card {
            background: white;
            border-radius: 10px;
            padding: 20px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.06);
            border-left: 4px solid var(--secondary);
        }
        
        .stats-card.success { border-left-color: var(--success); }
        .stats-card.warning { border-left-color: var(--warning); }
        .stats-card.danger { border-left-color: var(--danger); }
        
        .stat-value {
            font-size: 2rem;
            font-weight: 700;
            color: var(--primary);
        }
        
        .stat-label {
            color: #666;
            font-size: 0.9rem;
        }
        
        .table-custom {
            background: white;
            border-radius: 12px;
            overflow: hidden;
        }
        
        .table-custom thead {
            background: linear-gradient(135deg, var(--primary) 0%, #34495e 100%);
            color: white;
        }
        
        .badge-approve {
            background: #d4edda;
            color: #155724;
            padding: 6px 14px;
            border-radius: 20px;
            font-weight: 600;
        }
        
        .badge-fail {
            background: #f8d7da;
            color: #721c24;
            padding: 6px 14px;
            border-radius: 20px;
            font-weight: 600;
        }
        
        .badge-pending {
            background: #fff3cd;
            color: #856404;
            padding: 6px 14px;
            border-radius: 20px;
            font-weight: 600;
        }
        
        .grade-display {
            text-align: center;
            font-weight: 700;
            padding: 8px 12px;
            border-radius: 10px;
            min-width: 60px;
            display: inline-block;
        }
        
        .grade-excellent { background: #d4edda; color: #155724; }
        .grade-good { background: #cce5ff; color: #004085; }
        .grade-regular { background: #fff3cd; color: #856404; }
        .grade-fail { background: #f8d7da; color: #721c24; }
        
        .filter-card {
            background: white;
            border-radius: 10px;
            padding: 20px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.08);
            margin-bottom: 24px;
        }
        
        @media (max-width: 768px) {
            .sidebar { transform: translateX(-100%); }
            .sidebar.active { transform: translateX(0); }
            .main-content { margin-left: 0; }
        }
    </style>
</head>
<body>
    <!-- Sidebar -->
    <div class="sidebar" id="sidebar">
        <div class="text-center mb-4">
            <h4><i class="fas fa-graduation-cap"></i> Educación Plus</h4>
            <small><?= ucfirst($rol) ?></small>
        </div>
        
        <nav class="nav flex-column">
            <a class="nav-link" href="../../index.php">
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
            <a class="nav-link active" href="calificaciones.php">
                <i class="fas fa-star"></i> Calificaciones
            </a>
            <a class="nav-link" href="reporte_notas.php">
                <i class="fas fa-chart-bar"></i> Reportes
            </a>
            <a class="nav-link" href="../../logout.php">
                <i class="fas fa-sign-out-alt"></i> Cerrar Sesión
            </a>
        </nav>
    </div>

    <!-- Main Content -->
    <div class="main-content">
        <!-- Header -->


        <!-- Header -->
<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h2><i class="fas fa-star text-warning"></i> Gestión de Calificaciones</h2>
        <p class="text-muted mb-0">Administrar notas y evaluaciones de estudiantes</p>
    </div>
    <div>
        <button class="btn btn-success me-2" onclick="guardarTodasLasNotas()">
            <i class="fas fa-save"></i> Guardar Todas
        </button>
        <!-- ✅ NUEVO BOTÓN: Generar Reporte -->
        <button class="btn btn-primary me-2" data-bs-toggle="modal" data-bs-target="#modalReporteNotas">
            <i class="fas fa-file-excel"></i> Reporte de Notas
        </button>
        <button class="btn btn-info" onclick="exportarExcel()">
            <i class="fas fa-file-excel"></i> Exportar
        </button>
    </div>
</div>
   

        <!-- Messages -->
        <?php if ($mensaje): ?>
        <div class="alert alert-<?= $tipo_mensaje ?> alert-dismissible fade show">
            <?= htmlspecialchars($mensaje) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php endif; ?>

        <!-- Stats Cards -->
        <div class="row mb-4">
            <div class="col-md-3 mb-3">
                <div class="stats-card">
                    <div class="stat-value"><?= $stats['total_estudiantes'] ?></div>
                    <div class="stat-label">Total Estudiantes</div>
                </div>
            </div>
            <div class="col-md-3 mb-3">
                <div class="stats-card success">
                    <div class="stat-value text-success"><?= $stats['aprobados'] ?></div>
                    <div class="stat-label">Aprobados</div>
                </div>
            </div>
            <div class="col-md-3 mb-3">
                <div class="stats-card danger">
                    <div class="stat-value text-danger"><?= $stats['reprobados'] ?></div>
                    <div class="stat-label">Reprobados</div>
                </div>
            </div>
            <div class="col-md-3 mb-3">
                <div class="stats-card warning">
                    <div class="stat-value text-warning"><?= $stats['pendientes'] ?></div>
                    <div class="stat-label">Pendientes</div>
                </div>
            </div>
        </div>

        <!-- Filters -->
        <div class="filter-card">
            <form method="GET" class="row g-3">
                <div class="col-md-2">
                    <label class="form-label">Año Lectivo</label>
                    <select name="anno" class="form-select">
                        <?php foreach ($anios as $anio): ?>
                        <option value="<?= $anio ?>" <?= $filtro_anno == $anio ? 'selected' : '' ?>><?= $anio ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label">Período</label>
                    <select name="periodo" class="form-select">
                        <?php foreach ($periodos as $key => $val): ?>
                        <option value="<?= $key ?>" <?= $filtro_periodo == $key ? 'selected' : '' ?>><?= $val ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label">Grado</label>
                    <select name="grado" class="form-select">
                        <option value="">Todos</option>
                        <?php foreach ($grados as $grado): ?>
                        <option value="<?= $grado['id'] ?>" <?= $filtro_grado == $grado['id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($grado['nombre']) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label">Sección</label>
                    <select name="seccion" class="form-select">
                        <option value="">Todas</option>
                        <?php foreach ($secciones as $seccion): ?>
                        <option value="<?= $seccion['id'] ?>" <?= $filtro_seccion == $seccion['id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($seccion['nombre']) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label">Asignatura</label>
                    <select name="asignatura" class="form-select select2-asignatura">
                        <option value="">Todas</option>
                        <?php foreach ($asignaturas as $asig): ?>
                        <option value="<?= $asig['id'] ?>" <?= $filtro_asignatura == $asig['id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($asig['nombre']) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label">Búsqueda</label>
                    <input type="text" name="busqueda" class="form-control" 
                           placeholder="Nombre o NIE" value="<?= htmlspecialchars($busqueda) ?>">
                </div>
                <div class="col-12">
                    <button type="submit" class="btn btn-secondary">
                        <i class="fas fa-filter"></i> Filtrar
                    </button>
                    <a href="calificaciones.php" class="btn btn-outline-secondary">
                        <i class="fas fa-redo"></i> Limpiar
                    </a>
                    <span class="ms-3 text-muted">
                        <i class="fas fa-info-circle"></i> 
                        Nota mínima: 
                        <span class="badge bg-warning">Básica 6.0</span>
                        <span class="badge bg-danger">Bachillerato 7.0</span>
                    </span>
                </div>
            </form>
        </div>

        <!-- Calificaciones Table -->
        <div class="card-custom">
            <div class="card-header bg-white py-3">
                <div class="d-flex justify-content-between align-items-center">
                    <h5 class="mb-0"><i class="fas fa-list"></i> Registro de Calificaciones - <?= $periodos[$filtro_periodo] ?> <?= $filtro_anno ?></h5>
                    <span class="badge bg-primary"><?= count($calificaciones) ?> estudiantes</span>
                </div>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-custom mb-0" id="tablaCalificaciones">
                        <thead>
                            <tr>
                                <th>Estudiante</th>
                                <th>Grado/Sección</th>
                                <th class="text-center">Actividades</th>
                                <th class="text-center">Promedio</th>
                                <th class="text-center">Asistencia</th>
                                <th class="text-center">Estado</th>
                                <th class="text-center">Acciones</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($calificaciones)): ?>
                            <tr>
                                <td colspan="7" class="text-center py-5 text-muted">
                                    <i class="fas fa-inbox fa-3x mb-3 d-block"></i>
                                    No se encontraron registros con los filtros seleccionados
                                </td>
                            </tr>
                            <?php else: ?>
                            <?php foreach ($calificaciones as $cal): 
                                $nota_min = $cal['nota_minima_aprobacion'];
                                $promedio = $cal['promedio_notas'];
                                $estado = $cal['estado_aprobacion'];
                                
                                $grade_class = 'grade-pending';
                                if ($promedio !== null) {
                                    if ($promedio >= 9) $grade_class = 'grade-excellent';
                                    elseif ($promedio >= 7) $grade_class = 'grade-good';
                                    elseif ($promedio >= $nota_min) $grade_class = 'grade-regular';
                                    else $grade_class = 'grade-fail';
                                }
                            ?>
                            <tr>
                                <td>
                                    <div class="d-flex align-items-center">
                                        <div class="rounded-circle bg-primary text-white d-flex align-items-center justify-content-center me-2" 
                                             style="width: 40px; height: 40px;">
                                            <?= strtoupper(substr($cal['primer_nombre'], 0, 1)) ?>
                                        </div>
                                        <div>
                                            <div class="fw-bold">
                                                <?= htmlspecialchars($cal['primer_apellido'] . ', ' . $cal['primer_nombre']) ?>
                                            </div>
                                            <small class="text-muted">NIE: <?= htmlspecialchars($cal['nie']) ?></small>
                                        </div>
                                    </div>
                                </td>
                                <td>
                                    <div class="fw-medium"><?= htmlspecialchars($cal['grado_nombre']) ?></div>
                                    <span class="badge bg-info"><?= htmlspecialchars($cal['seccion_nombre']) ?></span>
                                </td>
                                <td class="text-center">
                                    <span class="badge bg-warning"><?= $cal['actividades_calificadas'] ?>/<?= $cal['total_actividades'] ?></span>
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
                                    <?php if ($cal['total_asistencias'] > 0): 
                                        $porcentaje_asistencia = ($cal['asistencias_presente'] / $cal['total_asistencias']) * 100;
                                    ?>
                                    <div class="fw-medium"><?= round($porcentaje_asistencia) ?>%</div>
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
                                <td class="text-center">
                                    <div class="btn-group btn-group-sm">
                                        <button class="btn btn-info" onclick="verDetalle(<?= $cal['id_matricula'] ?>)" title="Ver Detalle">
                                            <i class="fas fa-eye"></i>
                                        </button>
                                        <button class="btn btn-success" onclick="generarBoletin(<?= $cal['id_matricula'] ?>)" title="Boletín">
                                            <i class="fas fa-file-pdf"></i>
                                        </button>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <!-- Modal Generar Reporte de Notas -->
<div class="modal fade" id="modalReporteNotas" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title"><i class="fas fa-file-excel"></i> Generar Reporte de Notas</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <form id="formReporteNotas">
                    <div class="row g-3">
                        <!-- Selección de Período -->
                        <div class="col-md-6">
                            <label class="form-label">Período a Reportar *</label>
                            <select name="periodo_reporte" id="periodo_reporte" class="form-select" required>
                                <option value="">Seleccionar</option>
                                <option value="1">Primer Trimestre</option>
                                <option value="2">Segundo Trimestre</option>
                                <option value="3">Tercer Trimestre</option>
                                <option value="4">Cuarto Trimestre</option>
                                <option value="todos">Consolidado Anual</option>
                            </select>
                        </div>
                        
                        <!-- Selección de Asignatura -->
                        <div class="col-md-6">
                            <label class="form-label">Asignatura *</label>
                            <select name="asignatura_reporte" id="asignatura_reporte" class="form-select select2" required>
                                <option value="">Seleccionar</option>
                                <?php foreach ($asignaturas as $asig): ?>
                                <option value="<?= $asig['id'] ?>"><?= htmlspecialchars($asig['nombre']) ?> (<?= htmlspecialchars($asig['codigo']) ?>)</option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <!-- Grado y Sección -->
                        <div class="col-md-6">
                            <label class="form-label">Grado</label>
                            <select name="grado_reporte" id="grado_reporte" class="form-select">
                                <option value="">Todos</option>
                                <?php foreach ($grados as $grado): ?>
                                <option value="<?= $grado['id'] ?>" <?= $filtro_grado == $grado['id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($grado['nombre']) ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="col-md-6">
                            <label class="form-label">Sección</label>
                            <select name="seccion_reporte" id="seccion_reporte" class="form-select">
                                <option value="">Todas</option>
                                <?php foreach ($secciones as $seccion): ?>
                                <option value="<?= $seccion['id'] ?>" <?= $filtro_seccion == $seccion['id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($seccion['nombre']) ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <!-- Formato de Exportación -->
                        <div class="col-md-6">
                            <label class="form-label">Formato de Exportación *</label>
                            <select name="formato" id="formato" class="form-select" required>
                                <option value="excel">📊 Excel (.xlsx)</option>
                                <option value="pdf">📄 PDF</option>
                                <option value="html">🌐 HTML (Imprimir)</option>
                            </select>
                        </div>
                        
                        <!-- Opciones Adicionales -->
                        <div class="col-md-6">
                            <label class="form-label">Opciones</label>
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" name="incluir_asistencia" id="incluir_asistencia" checked>
                                <label class="form-check-label" for="incluir_asistencia">Incluir Asistencia</label>
                            </div>
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" name="incluir_observaciones" id="incluir_observaciones">
                                <label class="form-check-label" for="incluir_observaciones">Incluir Observaciones</label>
                            </div>
                        </div>
                        
                        <!-- Información del Reporte -->
                        <div class="col-12">
                            <div class="alert alert-info mb-0">
                                <i class="fas fa-info-circle"></i> 
                                <strong>Formato Bachillerato:</strong> El reporte incluirá:
                                <ul class="mb-0 mt-1 small">
                                    <li>Áreas académicas con 4 actividades cada una (35% c/u)</li>
                                    <li>Examen final (30%)</li>
                                    <li>Cálculo automático de promedios</li>
                                    <li>Consolidado por período o anual</li>
                                </ul>
                            </div>
                        </div>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                    <i class="fas fa-times"></i> Cancelar
                </button>
                <button type="button" class="btn btn-primary" onclick="generarReporteNotas()">
                    <i class="fas fa-file-export"></i> Generar Reporte
                </button>
            </div>
        </div>
    </div>
</div>
    <!-- Scripts -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.4/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.4/js/dataTables.bootstrap5.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
    
    <script>
        $(document).ready(function() {
            $('#tablaCalificaciones').DataTable({
                language: { url: '//cdn.datatables.net/plug-ins/1.13.4/i18n/es-ES.json' },
                pageLength: 15,
                order: [[0, 'asc']]
            });
            
            $('.select2-asignatura').select2({
                placeholder: 'Seleccionar asignatura',
                allowClear: true,
                width: '100%'
            });
            
            $('#sidebarToggle').click(function() {
                $('#sidebar').toggleClass('active');
            });
        });
        
        function verDetalle(idMatricula) {
            alert('Ver detalle de estudiante ID: ' + idMatricula);
        }
        
        function generarBoletin(idMatricula) {
            window.open('reportes/generar_boletin.php?id_matricula=' + idMatricula, '_blank');
        }
        
        function guardarTodasLasNotas() {
            alert('Funcionalidad de guardado masivo');
        }
        
        function exportarExcel() {
            const params = new URLSearchParams(window.location.search);
            window.location.href = 'api/exportar_calificaciones_excel.php?' + params.toString();
        }

        // ✅ FUNCIÓN PARA GENERAR REPORTE DE NOTAS - FORMATO BACHILLERATO
function generarReporteNotas() {
    const periodo = $('#periodo_reporte').val();
    const asignatura = $('#asignatura_reporte').val();
    const grado = $('#grado_reporte').val();
    const seccion = $('#seccion_reporte').val();
    const formato = $('#formato').val();
    const incluirAsistencia = $('#incluir_asistencia').is(':checked');
    const incluirObservaciones = $('#incluir_observaciones').is(':checked');
    
    // Validaciones
    if (!periodo || !asignatura || !formato) {
        alert('⚠️ Por favor complete los campos obligatorios');
        return;
    }
    
    // Preparar parámetros
    const params = new URLSearchParams({
        periodo: periodo,
        asignatura: asignatura,
        grado: grado || '',
        seccion: seccion || '',
        formato: formato,
        asistencia: incluirAsistencia ? 1 : 0,
        observaciones: incluirObservaciones ? 1 : 0,
        anno: '<?= $filtro_anno ?>'
    });
    
    // Mostrar loading
    const btn = $('#modalReporteNotas .btn-primary');
    const originalText = btn.html();
    btn.prop('disabled', true).html('<i class="fas fa-spinner fa-spin"></i> Generando...');
    
    // Redirigir al endpoint de generación
    if (formato === 'html') {
        // Para HTML, abrir en nueva pestaña
        window.open('api/generar_reporte_notas.php?' + params.toString(), '_blank');
    } else {
        // Para Excel/PDF, descargar archivo
        window.location.href = 'api/generar_reporte_notas.php?' + params.toString();
    }
    
    // Restaurar botón después de 2 segundos
    setTimeout(() => {
        btn.prop('disabled', false).html(originalText);
        $('#modalReporteNotas').modal('hide');
    }, 2000);
}

// Inicializar Select2 en el modal
$('#modalReporteNotas').on('shown.bs.modal', function() {
    $('.select2').select2({ placeholder: 'Seleccionar', width: '100%' });
});
    </script>
</body>
</html>