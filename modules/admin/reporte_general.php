<?php
session_start();
require_once __DIR__ . '/../../config/database.php';

// Verificar que sea admin o director
if (!isset($_SESSION['user_id']) || ($_SESSION['rol'] != 'admin' && $_SESSION['rol'] != 'director')) {
    header("Location: " . BASE_URL . "/login.php");
    exit;
}

$database = new Database();
$db = $database->getConnection();
$user_id = $_SESSION['user_id'];

// ===== FILTROS =====
$filtro_anno = $_GET['anno'] ?? date('Y');
$filtro_periodo = $_GET['periodo'] ?? '';
$filtro_grado = $_GET['grado'] ?? '';
$filtro_seccion = $_GET['seccion'] ?? '';
$filtro_tipo = $_GET['tipo'] ?? 'resumen'; // resumen, academico, asistencia, comportamiento

// ===== ESTADÍSTICAS GENERALES =====

// Total de estudiantes activos
$query = "SELECT COUNT(*) as total FROM tbl_estudiante e
          JOIN tbl_persona p ON e.id_persona = p.id
          JOIN tbl_usuario u ON p.id_usuario = u.id
          WHERE u.estado = 1";
$total_estudiantes = $db->query($query)->fetchColumn();

// Total de profesores activos
$query = "SELECT COUNT(*) as total FROM tbl_profesor p
          JOIN tbl_persona per ON p.id_persona = per.id
          JOIN tbl_usuario u ON per.id_usuario = u.id
          WHERE u.estado = 1";
$total_profesores = $db->query($query)->fetchColumn();

// Total de matrículas activas este año
$query = "SELECT COUNT(*) as total FROM tbl_matricula 
          WHERE anno = :anno AND estado = 'activo'";
$stmt = $db->prepare($query);
$stmt->execute([':anno' => $filtro_anno]);
$total_matriculas = $stmt->fetchColumn();

// Promedio general de notas
$query = "SELECT AVG(ea.nota_obtenida) as promedio 
          FROM tbl_entrega_actividad ea
          JOIN tbl_actividad act ON ea.id_actividad = act.id
          JOIN tbl_asignacion_docente ad ON act.id_asignacion_docente = ad.id
          WHERE ad.anno = :anno 
          AND ea.nota_obtenida IS NOT NULL 
          AND ea.estado_entrega = 'calificado'";
$stmt = $db->prepare($query);
$stmt->execute([':anno' => $filtro_anno]);
$promedio_general = round($stmt->fetchColumn() ?? 0, 2);

// Asistencia promedio
$query = "SELECT 
          COUNT(*) as total_registros,
          SUM(CASE WHEN a.estado = 'presente' THEN 1 ELSE 0 END) as presentes
          FROM tbl_asistencia a
          JOIN tbl_matricula m ON a.id_matricula = m.id
          WHERE m.anno = :anno";
$stmt = $db->prepare($query);
$stmt->execute([':anno' => $filtro_anno]);
$asistencia_data = $stmt->fetch(PDO::FETCH_ASSOC);
$porcentaje_asistencia = $asistencia_data['total_registros'] > 0 
    ? round(($asistencia_data['presentes'] / $asistencia_data['total_registros']) * 100, 1) 
    : 0;

// ===== DATOS PARA GRÁFICOS =====

// Estudiantes por grado
$query = "SELECT g.nombre as grado, COUNT(DISTINCT e.id) as cantidad
          FROM tbl_estudiante e
          JOIN tbl_matricula m ON e.id = m.id_estudiante
          JOIN tbl_seccion s ON m.id_seccion = s.id
          JOIN tbl_grado g ON s.id_grado = g.id
          WHERE m.anno = :anno AND m.estado = 'activo'
          GROUP BY g.id, g.nombre
          ORDER BY g.nivel, g.nombre";
$stmt = $db->prepare($query);
$stmt->execute([':anno' => $filtro_anno]);
$estudiantes_por_grado = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Rendimiento por materia
$query = "SELECT 
          asig.nombre as materia,
          AVG(ea.nota_obtenida) as promedio,
          COUNT(ea.id) as total_calificaciones
          FROM tbl_entrega_actividad ea
          JOIN tbl_actividad act ON ea.id_actividad = act.id
          JOIN tbl_asignacion_docente ad ON act.id_asignacion_docente = ad.id
          JOIN tbl_asignatura asig ON ad.id_asignatura = asig.id
          WHERE ad.anno = :anno 
          AND ea.nota_obtenida IS NOT NULL 
          AND ea.estado_entrega = 'calificado'
          GROUP BY asig.id, asig.nombre
          ORDER BY promedio DESC
          LIMIT 10";
$stmt = $db->prepare($query);
$stmt->execute([':anno' => $filtro_anno]);
$rendimiento_materias = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Asistencia por sección
$query = "SELECT 
          s.nombre as seccion,
          g.nombre as grado,
          COUNT(*) as total_registros,
          SUM(CASE WHEN a.estado = 'presente' THEN 1 ELSE 0 END) as presentes
          FROM tbl_asistencia a
          JOIN tbl_matricula m ON a.id_matricula = m.id
          JOIN tbl_seccion s ON m.id_seccion = s.id
          JOIN tbl_grado g ON s.id_grado = g.id
          WHERE m.anno = :anno
          GROUP BY s.id, s.nombre, g.nombre
          ORDER BY g.nombre, s.nombre";
$stmt = $db->prepare($query);
$stmt->execute([':anno' => $filtro_anno]);
$asistencia_por_seccion = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ===== LISTADO DETALLADO (según filtro) =====
$datos_detalle = [];

if ($filtro_tipo == 'academico') {
    // Reporte académico: notas por estudiante
    $query = "SELECT 
              p.primer_nombre, p.primer_apellido,
              g.nombre as grado, s.nombre as seccion,
              asig.nombre as materia,
              AVG(ea.nota_obtenida) as promedio_materia,
              COUNT(ea.id) as total_actividades
              FROM tbl_estudiante e
              JOIN tbl_persona p ON e.id_persona = p.id
              JOIN tbl_matricula m ON e.id = m.id_estudiante
              JOIN tbl_seccion s ON m.id_seccion = s.id
              JOIN tbl_grado g ON s.id_grado = g.id
              JOIN tbl_entrega_actividad ea ON m.id = ea.id_matricula
              JOIN tbl_actividad act ON ea.id_actividad = act.id
              JOIN tbl_asignacion_docente ad ON act.id_asignacion_docente = ad.id
              JOIN tbl_asignatura asig ON ad.id_asignatura = asig.id
              WHERE m.anno = :anno
              AND ea.nota_obtenida IS NOT NULL
              AND ea.estado_entrega = 'calificado'";
    
    $params = [':anno' => $filtro_anno];
    
    if ($filtro_periodo) {
        $query .= " AND ad.id_periodo = :periodo";
        $params[':periodo'] = $filtro_periodo;
    }
    if ($filtro_grado) {
        $query .= " AND g.id = :grado";
        $params[':grado'] = $filtro_grado;
    }
    if ($filtro_seccion) {
        $query .= " AND s.id = :seccion";
        $params[':seccion'] = $filtro_seccion;
    }
    
    $query .= " GROUP BY e.id, asig.id
                ORDER BY g.nombre, s.nombre, p.primer_apellido";
    
    $stmt = $db->prepare($query);
    foreach ($params as $k => $v) $stmt->bindValue($k, $v);
    $stmt->execute();
    $datos_detalle = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
} elseif ($filtro_tipo == 'asistencia') {
    // Reporte de asistencia
    $query = "SELECT 
              p.primer_nombre, p.primer_apellido,
              g.nombre as grado, s.nombre as seccion,
              COUNT(*) as total_dias,
              SUM(CASE WHEN a.estado = 'presente' THEN 1 ELSE 0 END) as dias_presentes,
              SUM(CASE WHEN a.estado = 'ausente' THEN 1 ELSE 0 END) as dias_ausentes,
              ROUND(SUM(CASE WHEN a.estado = 'presente' THEN 1 ELSE 0 END) * 100.0 / COUNT(*), 1) as porcentaje
              FROM tbl_estudiante e
              JOIN tbl_persona p ON e.id_persona = p.id
              JOIN tbl_matricula m ON e.id = m.id_estudiante
              JOIN tbl_seccion s ON m.id_seccion = s.id
              JOIN tbl_grado g ON s.id_grado = g.id
              LEFT JOIN tbl_asistencia a ON m.id = a.id_matricula
              WHERE m.anno = :anno";
    
    $params = [':anno' => $filtro_anno];
    
    if ($filtro_periodo) {
        $query .= " AND a.id_periodo = :periodo";
        $params[':periodo'] = $filtro_periodo;
    }
    if ($filtro_grado) {
        $query .= " AND g.id = :grado";
        $params[':grado'] = $filtro_grado;
    }
    if ($filtro_seccion) {
        $query .= " AND s.id = :seccion";
        $params[':seccion'] = $filtro_seccion;
    }
    
    $query .= " GROUP BY e.id, p.primer_nombre, p.primer_apellido, g.nombre, s.nombre
                HAVING COUNT(*) > 0
                ORDER BY porcentaje ASC, p.primer_apellido";
    
    $stmt = $db->prepare($query);
    foreach ($params as $k => $v) $stmt->bindValue($k, $v);
    $stmt->execute();
    $datos_detalle = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
} elseif ($filtro_tipo == 'comportamiento') {
    // Reporte de registro anecdótico/comportamiento
    $query = "SELECT 
              p.primer_nombre, p.primer_apellido,
              g.nombre as grado, s.nombre as seccion,
              ra.fecha, ra.descripcion, ra.tipo,
              per.primer_nombre as registrado_por
              FROM tbl_registro_anecdotico ra
              JOIN tbl_estudiante e ON ra.id_estudiante = e.id
              JOIN tbl_persona p ON e.id_persona = p.id
              JOIN tbl_matricula m ON e.id = m.id_estudiante
              JOIN tbl_seccion s ON m.id_seccion = s.id
              JOIN tbl_grado g ON s.id_grado = g.id
              JOIN tbl_persona per ON ra.id_registro_por = per.id
              WHERE m.anno = :anno";
    
    $params = [':anno' => $filtro_anno];
    
    if ($filtro_grado) {
        $query .= " AND g.id = :grado";
        $params[':grado'] = $filtro_grado;
    }
    if ($filtro_seccion) {
        $query .= " AND s.id = :seccion";
        $params[':seccion'] = $filtro_seccion;
    }
    
    $query .= " ORDER BY ra.fecha DESC";
    
    $stmt = $db->prepare($query);
    foreach ($params as $k => $v) $stmt->bindValue($k, $v);
    $stmt->execute();
    $datos_detalle = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Obtener datos para filtros
$query = "SELECT id, nombre FROM tbl_grado ORDER BY nivel, nombre";
$grados = $db->query($query)->fetchAll(PDO::FETCH_ASSOC);

$query = "SELECT id, nombre FROM tbl_seccion ORDER BY nombre";
$secciones = $db->query($query)->fetchAll(PDO::FETCH_ASSOC);

$periodos = [1 => '1er Trimestre', 2 => '2do Trimestre', 3 => '3er Trimestre', 4 => '4to Trimestre'];
$anios = range(date('Y') - 3, date('Y') + 1);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reportes Generales - Educación Plus</title>
    
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    
    <style>
        :root { --primary: #2c3e50; --sidebar-width: 250px; }
        body { font-family: 'Segoe UI', sans-serif; background: #f8f9fa; }
        .sidebar { position: fixed; top: 0; left: 0; height: 100vh; width: var(--sidebar-width); background: var(--primary); color: white; padding-top: 60px; z-index: 1000; }
        .sidebar .nav-link { color: rgba(255,255,255,0.8); padding: 12px 20px; }
        .sidebar .nav-link:hover, .sidebar .nav-link.active { color: white; background: rgba(255,255,255,0.1); }
        .main-content { margin-left: var(--sidebar-width); padding: 20px; }
        .card-custom { background: white; border-radius: 10px; box-shadow: 0 2px 10px rgba(0,0,0,0.08); margin-bottom: 20px; }
        .stat-card { background: white; border-radius: 10px; padding: 20px; box-shadow: 0 2px 10px rgba(0,0,0,0.08); border-left: 4px solid var(--primary); }
        .chart-container { position: relative; height: 300px; }
        .table-responsive { max-height: 500px; overflow-y: auto; }
        @media print {
            .sidebar, .no-print { display: none !important; }
            .main-content { margin-left: 0; }
            .card-custom { break-inside: avoid; }
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
            <small>Panel de Administración</small>
        </div>
        <nav class="nav flex-column">
            <a class="nav-link" href="../../index.php"><i class="fas fa-tachometer-alt"></i> Dashboard</a>
            <a class="nav-link" href="gestionar_estudiantes.php"><i class="fas fa-user-graduate"></i> Estudiantes</a>
            <a class="nav-link" href="gestionar_profesores.php"><i class="fas fa-chalkboard-teacher"></i> Profesores</a>
            <a class="nav-link" href="gestionar_grados.php"><i class="fas fa-layer-group"></i> Grados/Secciones</a>
            <a class="nav-link" href="gestionar_asignaturas.php"><i class="fas fa-book"></i> Asignaturas</a>
            <a class="nav-link" href="gestionar_matriculas.php"><i class="fas fa-file-signature"></i> Matrículas</a>
            <a class="nav-link active" href="reporte_general.php"><i class="fas fa-chart-bar"></i> Reportes</a>
            <a class="nav-link" href="../../logout.php"><i class="fas fa-sign-out-alt"></i> Cerrar Sesión</a>
        </nav>
    </div>

    <!-- Main Content -->
    <div class="main-content">
        <!-- Header -->
        <div class="d-flex justify-content-between align-items-center mb-4">
            <div>
                <h2><i class="fas fa-chart-bar"></i> Reportes Generales</h2>
                <p class="text-muted mb-0">Análisis estadístico del año <?= $filtro_anno ?></p>
            </div>
            <div class="d-flex gap-2 no-print">
                <button class="btn btn-outline-secondary btn-sm" onclick="window.print()">
                    <i class="fas fa-print"></i> Imprimir
                </button>
                <button class="btn btn-success btn-sm" onclick="exportarExcel()">
                    <i class="fas fa-file-excel"></i> Exportar Excel
                </button>
                <button class="btn btn-danger btn-sm" onclick="exportarPDF()">
                    <i class="fas fa-file-pdf"></i> Exportar PDF
                </button>
            </div>
        </div>

        <!-- Filtros -->
        <div class="card-custom p-4 mb-4 no-print">
            <form method="GET" class="row g-3">
                <div class="col-md-2">
                    <label class="form-label small">Año</label>
                    <select name="anno" class="form-select form-select-sm" onchange="this.form.submit()">
                        <?php foreach ($anios as $anio): ?>
                        <option value="<?= $anio ?>" <?= $filtro_anno == $anio ? 'selected' : '' ?>><?= $anio ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label small">Período</label>
                    <select name="periodo" class="form-select form-select-sm" onchange="this.form.submit()">
                        <option value="">Todos</option>
                        <?php foreach ($periodos as $k => $v): ?>
                        <option value="<?= $k ?>" <?= $filtro_periodo == $k ? 'selected' : '' ?>><?= $v ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label small">Grado</label>
                    <select name="grado" class="form-select form-select-sm" onchange="this.form.submit()">
                        <option value="">Todos</option>
                        <?php foreach ($grados as $g): ?>
                        <option value="<?= $g['id'] ?>" <?= $filtro_grado == $g['id'] ? 'selected' : '' ?>><?= $g['nombre'] ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label small">Sección</label>
                    <select name="seccion" class="form-select form-select-sm" onchange="this.form.submit()">
                        <option value="">Todas</option>
                        <?php foreach ($secciones as $s): ?>
                        <option value="<?= $s['id'] ?>" <?= $filtro_seccion == $s['id'] ? 'selected' : '' ?>><?= $s['nombre'] ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label small">Tipo de Reporte</label>
                    <select name="tipo" class="form-select form-select-sm" onchange="this.form.submit()">
                        <option value="resumen" <?= $filtro_tipo == 'resumen' ? 'selected' : '' ?>>📊 Resumen General</option>
                        <option value="academico" <?= $filtro_tipo == 'academico' ? 'selected' : '' ?>>📚 Rendimiento Académico</option>
                        <option value="asistencia" <?= $filtro_tipo == 'asistencia' ? 'selected' : '' ?>>✅ Asistencia</option>
                        <option value="comportamiento" <?= $filtro_tipo == 'comportamiento' ? 'selected' : '' ?>>📋 Comportamiento</option>
                    </select>
                </div>
                <div class="col-md-1 d-flex align-items-end">
                    <a href="reporte_general.php" class="btn btn-outline-secondary btn-sm w-100"><i class="fas fa-redo"></i></a>
                </div>
            </form>
        </div>

        <!-- Stats Cards -->
        <div class="row g-3 mb-4">
            <div class="col-md-3">
                <div class="stat-card">
                    <div class="d-flex justify-content-between">
                        <div>
                            <h3 class="mb-0 text-primary"><?= $total_estudiantes ?></h3>
                            <small class="text-muted">Estudiantes Activos</small>
                        </div>
                        <i class="fas fa-user-graduate fa-2x text-primary opacity-25"></i>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stat-card" style="border-left-color: #2ecc71;">
                    <div class="d-flex justify-content-between">
                        <div>
                            <h3 class="mb-0" style="color: #2ecc71;"><?= $total_profesores ?></h3>
                            <small class="text-muted">Profesores Activos</small>
                        </div>
                        <i class="fas fa-chalkboard-teacher fa-2x" style="color: #2ecc71; opacity: 0.25;"></i>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stat-card" style="border-left-color: #f39c12;">
                    <div class="d-flex justify-content-between">
                        <div>
                            <h3 class="mb-0" style="color: #f39c12;"><?= $total_matriculas ?></h3>
                            <small class="text-muted">Matrículas <?= $filtro_anno ?></small>
                        </div>
                        <i class="fas fa-file-signature fa-2x" style="color: #f39c12; opacity: 0.25;"></i>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stat-card" style="border-left-color: #e74c3c;">
                    <div class="d-flex justify-content-between">
                        <div>
                            <h3 class="mb-0" style="color: #e74c3c;"><?= $promedio_general ?></h3>
                            <small class="text-muted">Promedio General</small>
                        </div>
                        <i class="fas fa-star fa-2x" style="color: #e74c3c; opacity: 0.25;"></i>
                    </div>
                </div>
            </div>
        </div>

        <!-- Resumen Visual -->
        <?php if ($filtro_tipo == 'resumen'): ?>
        <div class="row g-4">
            <!-- Gráfico: Estudiantes por Grado -->
            <div class="col-lg-6">
                <div class="card-custom">
                    <div class="card-header bg-white py-3">
                        <h5 class="mb-0"><i class="fas fa-users"></i> Estudiantes por Grado</h5>
                    </div>
                    <div class="card-body">
                        <div class="chart-container">
                            <canvas id="chartGrados"></canvas>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Gráfico: Rendimiento por Materia -->
            <div class="col-lg-6">
                <div class="card-custom">
                    <div class="card-header bg-white py-3">
                        <h5 class="mb-0"><i class="fas fa-chart-line"></i> Rendimiento por Materia</h5>
                    </div>
                    <div class="card-body">
                        <div class="chart-container">
                            <canvas id="chartMaterias"></canvas>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Gráfico: Asistencia por Sección -->
            <div class="col-12">
                <div class="card-custom">
                    <div class="card-header bg-white py-3">
                        <h5 class="mb-0"><i class="fas fa-clipboard-check"></i> Asistencia por Sección</h5>
                    </div>
                    <div class="card-body">
                        <div class="chart-container" style="height: 250px;">
                            <canvas id="chartAsistencia"></canvas>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Tabla de Datos Detallados -->
        <div class="card-custom">
            <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
                <h5 class="mb-0">
                    <i class="fas fa-table"></i> 
                    <?= $filtro_tipo == 'academico' ? 'Rendimiento Académico' : 
                        ($filtro_tipo == 'asistencia' ? 'Reporte de Asistencia' : 
                        ($filtro_tipo == 'comportamiento' ? 'Registro de Comportamiento' : 'Resumen por Grado')) ?>
                </h5>
                <span class="badge bg-secondary"><?= count($datos_detalle) ?> registros</span>
            </div>
            <div class="card-body p-0">
                <?php if (empty($datos_detalle) && $filtro_tipo != 'resumen'): ?>
                <div class="text-center py-5 text-muted">
                    <i class="fas fa-inbox fa-3x mb-3"></i>
                    <p>No hay datos para mostrar con los filtros seleccionados.</p>
                </div>
                <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead class="table-light">
                            <?php if ($filtro_tipo == 'academico'): ?>
                            <tr>
                                <th>Estudiante</th>
                                <th>Grado/Sección</th>
                                <th>Materia</th>
                                <th class="text-center">Promedio</th>
                                <th class="text-center">Actividades</th>
                            </tr>
                            <?php elseif ($filtro_tipo == 'asistencia'): ?>
                            <tr>
                                <th>Estudiante</th>
                                <th>Grado/Sección</th>
                                <th class="text-center">Días Totales</th>
                                <th class="text-center">Presentes</th>
                                <th class="text-center">Ausentes</th>
                                <th class="text-center">Asistencia</th>
                            </tr>
                            <?php elseif ($filtro_tipo == 'comportamiento'): ?>
                            <tr>
                                <th>Estudiante</th>
                                <th>Grado/Sección</th>
                                <th>Fecha</th>
                                <th>Tipo</th>
                                <th>Descripción</th>
                                <th>Registrado por</th>
                            </tr>
                            <?php endif; ?>
                        </thead>
                        <tbody>
                            <?php foreach ($datos_detalle as $row): ?>
                            <tr>
                                <?php if ($filtro_tipo == 'academico'): ?>
                                <td><?= htmlspecialchars($row['primer_apellido'] . ', ' . $row['primer_nombre']) ?></td>
                                <td><?= htmlspecialchars($row['grado']) ?> - <?= htmlspecialchars($row['seccion']) ?></td>
                                <td><?= htmlspecialchars($row['materia']) ?></td>
                                <td class="text-center">
                                    <span class="badge bg-<?= $row['promedio_materia'] >= 9 ? 'success' : ($row['promedio_materia'] >= 7 ? 'primary' : ($row['promedio_materia'] >= 6 ? 'warning' : 'danger')) ?>">
                                        <?= number_format($row['promedio_materia'], 2) ?>
                                    </span>
                                </td>
                                <td class="text-center"><?= $row['total_actividades'] ?></td>
                                
                                <?php elseif ($filtro_tipo == 'asistencia'): ?>
                                <td><?= htmlspecialchars($row['primer_apellido'] . ', ' . $row['primer_nombre']) ?></td>
                                <td><?= htmlspecialchars($row['grado']) ?> - <?= htmlspecialchars($row['seccion']) ?></td>
                                <td class="text-center"><?= $row['total_dias'] ?></td>
                                <td class="text-center text-success"><?= $row['dias_presentes'] ?></td>
                                <td class="text-center text-danger"><?= $row['dias_ausentes'] ?></td>
                                <td class="text-center">
                                    <span class="badge bg-<?= $row['porcentaje'] >= 90 ? 'success' : ($row['porcentaje'] >= 80 ? 'primary' : ($row['porcentaje'] >= 70 ? 'warning' : 'danger')) ?>">
                                        <?= $row['porcentaje'] ?>%
                                    </span>
                                </td>
                                
                                <?php elseif ($filtro_tipo == 'comportamiento'): ?>
                                <td><?= htmlspecialchars($row['primer_apellido'] . ', ' . $row['primer_nombre']) ?></td>
                                <td><?= htmlspecialchars($row['grado']) ?> - <?= htmlspecialchars($row['seccion']) ?></td>
                                <td><?= date('d/m/Y', strtotime($row['fecha'])) ?></td>
                                <td>
                                    <span class="badge bg-<?= $row['tipo'] == 'positivo' ? 'success' : ($row['tipo'] == 'negativo' ? 'danger' : 'warning') ?>">
                                        <?= ucfirst($row['tipo']) ?>
                                    </span>
                                </td>
                                <td><?= htmlspecialchars(substr($row['descripcion'], 0, 100)) ?><?= strlen($row['descripcion']) > 100 ? '...' : '' ?></td>
                                <td><small><?= htmlspecialchars($row['registrado_por']) ?></small></td>
                                <?php endif; ?>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Scripts -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Toggle sidebar en móvil
        document.getElementById('sidebar')?.addEventListener('click', (e) => {
            if (e.target.closest('.nav-link')) {
                document.getElementById('sidebar').classList.remove('active');
            }
        });
        
        // Gráfico: Estudiantes por Grado
        <?php if (!empty($estudiantes_por_grado)): ?>
        new Chart(document.getElementById('chartGrados'), {
            type: 'doughnut',
            data: {
                labels: [<?= implode(',', array_map(fn($g) => "'".addslashes($g['grado'])."'", $estudiantes_por_grado)) ?>],
                datasets: [{
                    data: [<?= implode(',', array_column($estudiantes_por_grado, 'cantidad')) ?>],
                    backgroundColor: ['#4361ee', '#3f37c9', '#4895ef', '#4cc9f0', '#4ade80', '#f72585', '#7209b7']
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: { legend: { position: 'right' } }
            }
        });
        <?php endif; ?>
        
        // Gráfico: Rendimiento por Materia
        <?php if (!empty($rendimiento_materias)): ?>
        new Chart(document.getElementById('chartMaterias'), {
            type: 'bar',
            data: {
                labels: [<?= implode(',', array_map(fn($m) => "'".addslashes($m['materia'])."'", $rendimiento_materias)) ?>],
                datasets: [{
                    label: 'Promedio',
                    data: [<?= implode(',', array_column($rendimiento_materias, 'promedio')) ?>],
                    backgroundColor: 'rgba(67, 97, 238, 0.7)',
                    borderColor: '#4361ee',
                    borderWidth: 1
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                scales: {
                    y: { beginAtZero: true, max: 10, title: { display: true, text: 'Nota' } }
                }
            }
        });
        <?php endif; ?>
        
        // Gráfico: Asistencia por Sección
        <?php if (!empty($asistencia_por_seccion)): ?>
        new Chart(document.getElementById('chartAsistencia'), {
            type: 'line',
            data: {
                labels: [<?= implode(',', array_map(fn($s) => "'".addslashes($s['grado'].' - '.$s['seccion'])."'", $asistencia_por_seccion)) ?>],
                datasets: [{
                    label: '% Asistencia',
                    data: [<?= implode(',', array_map(fn($s) => $s['total_registros'] > 0 ? round(($s['presentes']/$s['total_registros'])*100,1) : 0, $asistencia_por_seccion)) ?>],
                    borderColor: '#2ecc71',
                    backgroundColor: 'rgba(46, 204, 113, 0.1)',
                    fill: true,
                    tension: 0.3
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                scales: {
                    y: { beginAtZero: true, max: 100, title: { display: true, text: 'Porcentaje' } }
                }
            }
        });
        <?php endif; ?>
        
        // Exportar a Excel
        function exportarExcel() {
            const params = new URLSearchParams(window.location.search);
            params.append('export', 'excel');
            window.location.href = 'api/exportar_reporte.php?' + params.toString();
        }
        
        // Exportar a PDF
        function exportarPDF() {
            window.print();
        }
    </script>
</body>
</html>