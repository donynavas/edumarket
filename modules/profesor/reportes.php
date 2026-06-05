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
$query = "SELECT p.id as id_profesor, per.primer_nombre, per.primer_apellido, per.email
          FROM tbl_profesor p
          JOIN tbl_persona per ON p.id_persona = per.id
          WHERE per.id_usuario = :user_id";
$stmt = $db->prepare($query);
$stmt->bindValue(':user_id', $user_id, PDO::PARAM_INT);
$stmt->execute();
$profesor = $stmt->fetch(PDO::FETCH_ASSOC);
$id_profesor = $profesor['id_profesor'] ?? 0;

$mensaje = '';
$tipo_mensaje = '';

// ===== OBTENER ASIGNACIONES DEL PROFESOR =====
$query = "SELECT ad.id, ad.anno, asig.nombre as asignatura_nombre, asig.codigo as asignatura_codigo,
          g.nombre as grado_nombre, s.nombre as seccion_nombre,
          COUNT(DISTINCT m.id) as total_estudiantes
          FROM tbl_asignacion_docente ad
          JOIN tbl_asignatura asig ON ad.id_asignatura = asig.id
          JOIN tbl_seccion s ON ad.id_seccion = s.id
          JOIN tbl_grado g ON s.id_grado = g.id
          LEFT JOIN tbl_matricula m ON s.id = m.id_seccion AND m.anno = ad.anno AND m.estado = 'activo'
          WHERE ad.id_profesor = :id_profesor
          GROUP BY ad.id
          ORDER BY g.nombre, s.nombre, asig.nombre";
$stmt = $db->prepare($query);
$stmt->bindValue(':id_profesor', $id_profesor, PDO::PARAM_INT);
$stmt->execute();
$asignaciones = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ===== FILTROS =====
$id_asignacion_filtro = $_GET['asignacion'] ?? ($asignaciones[0]['id'] ?? 0);

// ===== INICIALIZAR VARIABLES DE ESTADÍSTICAS =====
$stats = [
    'total_estudiantes' => 0,
    'total_actividades' => 0,
    'promedio_general' => 0,
    'tasa_aprobacion' => 0
];

$datos_grado = []; // Para el gráfico de barras
$datos_distribucion = ['0-4' => 0, '5-6' => 0, '7-8' => 0, '9-10' => 0]; // Histograma
$datos_entregas = ['Entregados' => 0, 'Pendientes' => 0, 'Calificados' => 0]; // Pie chart

$estudiantes_detalle = [];

if ($id_asignacion_filtro) {
    try {
        // 1. Información básica de la asignación
        $query_info = "SELECT ad.id, COUNT(DISTINCT m.id) as total_estudiantes, 
                              COUNT(DISTINCT a.id) as total_actividades
                       FROM tbl_asignacion_docente ad
                       LEFT JOIN tbl_matricula m ON m.id_seccion = ad.id_seccion AND m.anno = ad.anno AND m.estado = 'activo'
                       LEFT JOIN tbl_actividad a ON a.id_asignacion_docente = ad.id AND a.estado IN ('publicado', 'activo', 'cerrado')
                       WHERE ad.id = :id_asignacion
                       GROUP BY ad.id";
        
        $stmt_info = $db->prepare($query_info);
        $stmt_info->execute([':id_asignacion' => $id_asignacion_filtro]);
        $info = $stmt_info->fetch(PDO::FETCH_ASSOC);
        
        if ($info) {
            $stats['total_estudiantes'] = $info['total_estudiantes'];
            $stats['total_actividades'] = $info['total_actividades'];
        }

        // 2. Recopilar todas las calificaciones para análisis
        $query_grades = "SELECT ea.nota_obtenida, ea.estado_entrega
                         FROM tbl_entrega_actividad ea
                         JOIN tbl_actividad a ON ea.id_actividad = a.id
                         WHERE a.id_asignacion_docente = :id_asignacion";
        
        $stmt_grades = $db->prepare($query_grades);
        $stmt_grades->execute([':id_asignacion' => $id_asignacion_filtro]);
        $all_grades = $stmt_grades->fetchAll(PDO::FETCH_ASSOC);

        // Procesar datos en PHP (Más rápido y flexible que SQL complejo)
        $suma_notas = 0;
        $count_notas = 0;
        $aprobados = 0;
        $total_evaluaciones = count($all_grades);

        foreach ($all_grades as $row) {
            // Distribución de notas (Histograma)
            if ($row['nota_obtenida'] !== null) {
                $nota = floatval($row['nota_obtenida']);
                $suma_notas += $nota;
                $count_notas++;
                
                if ($nota >= 7) $aprobados++; // Asumiendo 7 como aprobación base

                if ($nota <= 4) $datos_distribucion['0-4']++;
                elseif ($nota <= 6) $datos_distribucion['5-6']++;
                elseif ($nota <= 8) $datos_distribucion['7-8']++;
                else $datos_distribucion['9-10']++;
            }

            // Estado de entregas (Pie Chart)
            if ($row['estado_entrega'] == 'calificado') {
                $datos_entregas['Calificados']++;
            } elseif ($row['estado_entrega'] == 'entregado') {
                $datos_entregas['Entregados']++;
            } else {
                $datos_entregas['Pendientes']++;
            }
        }

        // Calcular promedios
        $stats['promedio_general'] = $count_notas > 0 ? round($suma_notas / $count_notas, 2) : 0;
        $stats['tasa_aprobacion'] = $count_notas > 0 ? round(($aprobados / $count_notas) * 100, 1) : 0;
        
        // Ajustar conteo de entregados (Los calificados ya fueron entregados)
        $datos_entregas['Entregados'] += $datos_entregas['Calificados']; 
        // Nota: En lógica de negocio, si está calificado, cuenta como entregado. 
        // Ajustamos para que la gráfica muestre: Entregados (incluye calificados) vs Pendientes
        $datos_entregas['Entregados'] = $total_evaluaciones - $datos_entregas['Pendientes'];


        // 3. Listado de Estudiantes con su Promedio Individual
        $query_estudiantes = "SELECT 
                            e.id, p.primer_nombre, p.primer_apellido, p.email, e.nie,
                            m.estado as estado_matricula,
                            AVG(ea.nota_obtenida) as promedio_estudiante,
                            COUNT(ea.id) as entregas_realizadas
                            FROM tbl_estudiante e
                            JOIN tbl_persona p ON e.id_persona = p.id
                            JOIN tbl_matricula m ON e.id = m.id_estudiante
                            LEFT JOIN tbl_entrega_actividad ea ON e.id = ea.id_estudiante
                            LEFT JOIN tbl_actividad a ON ea.id_actividad = a.id AND a.id_asignacion_docente = :id_asignacion
                            WHERE m.id_seccion = (SELECT id_seccion FROM tbl_asignacion_docente WHERE id = :id_asig)
                            AND m.anno = (SELECT anno FROM tbl_asignacion_docente WHERE id = :id_asig2)
                            AND m.estado = 'activo'
                            GROUP BY e.id
                            ORDER BY promedio_estudiante DESC";
        
        $stmt_est = $db->prepare($query_estudiantes);
        $stmt_est->execute([
            ':id_asignacion' => $id_asignacion_filtro, 
            ':id_asig' => $id_asignacion_filtro, 
            ':id_asig2' => $id_asignacion_filtro
        ]);
        $estudiantes_detalle = $stmt_est->fetchAll(PDO::FETCH_ASSOC);

    } catch (PDOException $e) {
        error_log("Error en reportes: " . $e->getMessage());
        $mensaje = "Error al cargar los datos del reporte";
        $tipo_mensaje = "danger";
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reportes - Educación Plus</title>
    
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet">
    
    <!-- Chart.js -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    
    <style>
        :root { --primary: #2c3e50; --secondary: #3498db; --success: #2ecc71; --warning: #f39c12; --danger: #e74c3c; --sidebar-width: 260px; }
        body { font-family: 'Segoe UI', sans-serif; background: #f5f7fa; }
        .sidebar { position: fixed; top: 0; left: 0; height: 100vh; width: var(--sidebar-width); background: var(--primary); color: white; padding-top: 20px; z-index: 1000; overflow-y: auto; }
        .sidebar .nav-link { color: rgba(255,255,255,0.85); padding: 12px 20px; margin: 2px 0; border-radius: 8px; }
        .sidebar .nav-link:hover, .sidebar .nav-link.active { color: white; background: rgba(255,255,255,0.15); }
        .sidebar .nav-link i { width: 24px; text-align: center; margin-right: 8px; }
        .main-content { margin-left: var(--sidebar-width); padding: 20px; }
        .card-custom { background: white; border-radius: 12px; box-shadow: 0 2px 12px rgba(0,0,0,0.08); border: none; margin-bottom: 20px; }
        .stat-card { background: white; border-radius: 12px; padding: 20px; box-shadow: 0 2px 8px rgba(0,0,0,0.05); border-left: 4px solid var(--secondary); transition: transform 0.2s; }
        .stat-card:hover { transform: translateY(-3px); }
        .stat-card h3 { font-weight: 700; color: var(--primary); }
        .student-avatar { width: 35px; height: 35px; border-radius: 50%; background: linear-gradient(135deg, var(--secondary), var(--primary)); color: white; display: flex; align-items: center; justify-content: center; font-weight: 600; font-size: 0.8rem; }
        .progress-thin { height: 6px; }
        @media (max-width: 992px) { .sidebar { transform: translateX(-100%); } .sidebar.active { transform: translateX(0); } .main-content { margin-left: 0; } }
    </style>
</head>
<body>
    <!-- Sidebar -->
    <div class="sidebar" id="sidebar">
        <div class="text-center mb-4 px-3">
            <div class="d-flex align-items-center justify-content-center gap-2 mb-2">
                <div class="bg-white text-primary rounded-circle d-flex align-items-center justify-content-center" style="width: 45px; height: 45px; font-weight: 700;">
                    <?= strtoupper(substr($profesor['primer_nombre'], 0, 1)) ?>
                </div>
                <div class="text-start">
                    <div class="fw-bold"><?= htmlspecialchars($profesor['primer_nombre']) ?></div>
                    <small class="text-white-50">Profesor</small>
                </div>
            </div>
        </div>
        
        <nav class="nav flex-column px-2">
            <a class="nav-link" href="aula_virtual.php"><i class="fas fa-chalkboard-teacher"></i> Aula Virtual</a>
            <a class="nav-link" href="gestionar_estudiantes.php"><i class="fas fa-user-graduate"></i> Estudiantes</a>
            <a class="nav-link" href="calificaciones.php"><i class="fas fa-star"></i> Calificaciones</a>
            <a class="nav-link active" href="#"><i class="fas fa-chart-bar"></i> Reportes</a>
            <a class="nav-link" href="../../logout.php"><i class="fas fa-sign-out-alt"></i> Cerrar Sesión</a>
        </nav>
        
        <div class="mt-4 px-3">
            <small class="text-white-50">Mis Asignaciones</small>
            <div class="mt-2">
                <?php foreach ($asignaciones as $asig): ?>
                <a href="?asignacion=<?= $asig['id'] ?>" class="d-block text-white-50 text-decoration-none py-1 px-2 rounded small <?= $id_asignacion_filtro == $asig['id'] ? 'bg-white-10 text-white' : 'hover-bg-white-10' ?>">
                    <i class="fas fa-chevron-right me-1 small"></i>
                    <?= htmlspecialchars($asig['asignatura_nombre']) ?> - <?= htmlspecialchars($asig['grado_nombre']) ?> <?= htmlspecialchars($asig['seccion_nombre']) ?>
                </a>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <!-- Main Content -->
    <div class="main-content">
        <!-- Header -->
        <div class="d-flex justify-content-between align-items-center mb-4">
            <div>
                <h2 class="mb-1"><i class="fas fa-chart-pie"></i> Reportes y Analítica</h2>
                <nav aria-label="breadcrumb">
                    <ol class="breadcrumb mb-0">
                        <li class="breadcrumb-item"><a href="aula_virtual.php">Aula Virtual</a></li>
                        <li class="breadcrumb-item active">Reportes</li>
                    </ol>
                </nav>
            </div>
            <div>
                <button class="btn btn-outline-primary" onclick="exportarTabla()">
                    <i class="fas fa-file-csv"></i> Exportar CSV
                </button>
            </div>
        </div>

        <!-- Filtros -->
        <div class="card-custom p-3 mb-4">
            <form method="GET" class="row g-3 align-items-end">
                <div class="col-md-6">
                    <label class="form-label small text-muted">Seleccionar Asignación</label>
                    <select name="asignacion" class="form-select" onchange="this.form.submit()">
                        <?php foreach ($asignaciones as $asig): ?>
                        <option value="<?= $asig['id'] ?>" <?= $id_asignacion_filtro == $asig['id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($asig['asignatura_nombre']) ?> - <?= htmlspecialchars($asig['grado_nombre']) ?> <?= htmlspecialchars($asig['seccion_nombre']) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </form>
        </div>

        <?php if (empty($asignaciones)): ?>
            <div class="card-custom p-5 text-center">
                <i class="fas fa-exclamation-triangle fa-3x text-warning mb-3"></i>
                <h4>No hay datos disponibles</h4>
            </div>
        <?php else: ?>
        
        <!-- Tarjetas de Estadísticas -->
        <div class="row g-4 mb-4">
            <div class="col-md-3">
                <div class="stat-card" style="border-left-color: var(--secondary);">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <h6 class="text-muted mb-1">Estudiantes</h6>
                            <h3><?= $stats['total_estudiantes'] ?></h3>
                        </div>
                        <i class="fas fa-users fa-2x text-secondary opacity-50"></i>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stat-card" style="border-left-color: var(--success);">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <h6 class="text-muted mb-1">Promedio General</h6>
                            <h3><?= $stats['promedio_general'] > 0 ? $stats['promedio_general'] : '-' ?></h3>
                        </div>
                        <i class="fas fa-chart-line fa-2x text-success opacity-50"></i>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stat-card" style="border-left-color: var(--warning);">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <h6 class="text-muted mb-1">Tasa Aprobación</h6>
                            <h3><?= $stats['tasa_aprobacion'] ?>%</h3>
                        </div>
                        <i class="fas fa-medal fa-2x text-warning opacity-50"></i>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stat-card" style="border-left-color: var(--primary);">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <h6 class="text-muted mb-1">Actividades</h6>
                            <h3><?= $stats['total_actividades'] ?></h3>
                        </div>
                        <i class="fas fa-tasks fa-2x text-primary opacity-50"></i>
                    </div>
                </div>
            </div>
        </div>

        <!-- Gráficos -->
        <div class="row g-4 mb-4">
            <div class="col-lg-6">
                <div class="card-custom p-4 h-100">
                    <h5 class="mb-4"><i class="fas fa-chart-bar text-primary"></i> Distribución de Calificaciones</h5>
                    <div style="position: relative; height: 250px;">
                        <canvas id="gradeChart"></canvas>
                    </div>
                </div>
            </div>
            <div class="col-lg-6">
                <div class="card-custom p-4 h-100">
                    <h5 class="mb-4"><i class="fas fa-chart-pie text-info"></i> Estado de Entregas</h5>
                    <div style="position: relative; height: 250px;">
                        <canvas id="submissionChart"></canvas>
                    </div>
                </div>
            </div>
        </div>

        <!-- Tabla Detallada -->
        <div class="card-custom">
            <div class="card-header bg-white py-3">
                <h5 class="mb-0"><i class="fas fa-list"></i> Rendimiento por Estudiante</h5>
            </div>
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0" id="tablaReportes">
                    <thead class="table-light">
                        <tr>
                            <th>Estudiante</th>
                            <th>NIE</th>
                            <th>Estado Matrícula</th>
                            <th>Entregas</th>
                            <th>Promedio</th>
                            <th>Barra</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($estudiantes_detalle as $est): 
                            $nombre = trim($est['primer_nombre'] . ' ' . $est['primer_apellido']);
                            $iniciales = strtoupper(substr($est['primer_nombre'], 0, 1) . substr($est['primer_apellido'], 0, 1));
                            $promedio = $est['promedio_estudiante'] ?? 0;
                            $entregas = $est['entregas_realizadas'] ?? 0;
                            
                            // Color de la barra
                            $color = 'bg-danger';
                            if ($promedio >= 7) $color = 'bg-success';
                            elseif ($promedio >= 5) $color = 'bg-warning';
                        ?>
                        <tr>
                            <td>
                                <div class="d-flex align-items-center gap-2">
                                    <div class="student-avatar"><?= $iniciales ?></div>
                                    <strong><?= htmlspecialchars($nombre) ?></strong>
                                </div>
                            </td>
                            <td><small class="text-muted"><?= htmlspecialchars($est['nie']) ?></small></td>
                            <td>
                                <span class="badge bg-<?= $est['estado_matricula'] == 'activo' ? 'success' : 'secondary' ?>">
                                    <?= ucfirst($est['estado_matricula']) ?>
                                </span>
                            </td>
                            <td><?= $entregas ?></td>
                            <td>
                                <?php if ($promedio > 0): ?>
                                    <strong class="<?= $promedio >= 7 ? 'text-success' : 'text-danger' ?>"><?= number_format($promedio, 2) ?></strong>
                                <?php else: ?>
                                    <span class="text-muted">-</span>
                                <?php endif; ?>
                            </td>
                            <td style="width: 150px;">
                                <div class="progress progress-thin">
                                    <div class="progress-bar <?= $color ?>" role="progressbar" style="width: <?= min($promedio * 10, 100) ?>%"></div>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <!-- Scripts -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
    
    <script>
        // Datos PHP a JS
        const datosDistribucion = <?= json_encode($datos_distribucion) ?>;
        const datosEntregas = <?= json_encode($datos_entregas) ?>;

        // Configuración de Chart.js
        document.addEventListener('DOMContentLoaded', function() {
            // Gráfico de Barras (Calificaciones)
            const ctxGrade = document.getElementById('gradeChart').getContext('2d');
            new Chart(ctxGrade, {
                type: 'bar',
                data: {
                    labels: ['0 - 4 (Bajo)', '5 - 6 (Regular)', '7 - 8 (Bueno)', '9 - 10 (Excelente)'],
                    datasets: [{
                        label: 'Cantidad de Estudiantes',
                        data: Object.values(datosDistribucion),
                        backgroundColor: [
                            'rgba(231, 76, 60, 0.7)',
                            'rgba(243, 156, 18, 0.7)',
                            'rgba(52, 152, 219, 0.7)',
                            'rgba(46, 204, 113, 0.7)'
                        ],
                        borderColor: [
                            'rgba(231, 76, 60, 1)',
                            'rgba(243, 156, 18, 1)',
                            'rgba(52, 152, 219, 1)',
                            'rgba(46, 204, 113, 1)'
                        ],
                        borderWidth: 1,
                        borderRadius: 5
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    scales: {
                        y: { beginAtZero: true, ticks: { stepSize: 1 } }
                    }
                }
            });

            // Gráfico de Torta (Entregas)
            const ctxSub = document.getElementById('submissionChart').getContext('2d');
            new Chart(ctxSub, {
                type: 'doughnut',
                data: {
                    labels: ['Pendientes', 'Entregados', 'Calificados'],
                    datasets: [{
                        data: Object.values(datosEntregas),
                        backgroundColor: [
                            'rgba(149, 165, 166, 0.7)',
                            'rgba(52, 152, 219, 0.7)',
                            'rgba(46, 204, 113, 0.7)'
                        ],
                        borderColor: '#fff',
                        borderWidth: 2
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: { position: 'bottom' }
                    }
                }
            });

            // Sidebar responsive
            if (window.innerWidth < 992) {
                $('#sidebar').addClass('active');
            }
        });

        // Función para exportar tabla a CSV
        function exportarTabla() {
            let csv = [];
            let rows = document.querySelectorAll("table tr");
            
            for (let i = 0; i < rows.length; i++) {
                let row = [], cols = rows[i].querySelectorAll("td, th");
                for (let j = 0; j < cols.length - 1; j++) { // -1 para ignorar la columna de barra visual
                    let data = cols[j].innerText.replace(/(\r\n|\n|\r)/gm, "").replace(/(\s\s)/gm, " ");
                    data = data.replace(/"/g, '""');
                    row.push('"' + data + '"');
                }
                csv.push(row.join(","));
            }

            let csvFile = new Blob([csv.join("\n")], {type: "text/csv"});
            let downloadLink = document.createElement("a");
            downloadLink.download = "reporte_calificaciones_" + new Date().toISOString().slice(0,10) + ".csv";
            downloadLink.href = window.URL.createObjectURL(csvFile);
            downloadLink.style.display = "none";
            document.body.appendChild(downloadLink);
            downloadLink.click();
        }
    </script>
</body>
</html>