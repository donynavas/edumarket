<?php
session_start();
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/TenantGuard.php';

// Verificar que sea estudiante
if (!isset($_SESSION['user_id']) || $_SESSION['rol'] != 'estudiante') {
    header("Location: " . BASE_URL . "/login.php");
    exit;
}

$database = new Database();
$db = $database->getConnection();
$user_id = $_SESSION['user_id'];
$tid = TenantGuard::id();

require_once __DIR__ . '/../../config/MensajeHelper.php';
$totalNoLeidos = MensajeHelper::contarNoLeidos($db, (int) $user_id);

// Obtener datos del estudiante y matrícula
$query = "SELECT
          e.id as id_estudiante,
          m.id as id_matricula, m.anno, m.id_periodo,
          s.id as id_seccion,
          p.primer_nombre, p.primer_apellido,
          g.nota_minima_aprobacion
          FROM tbl_estudiante e
          JOIN tbl_persona p ON e.id_persona = p.id
          JOIN tbl_matricula m ON e.id = m.id_estudiante
          JOIN tbl_seccion s ON m.id_seccion = s.id
          JOIN tbl_grado g ON s.id_grado = g.id
          WHERE p.id_usuario = :user_id
          AND m.estado = 'activo'
          AND e.id_institucion = :tid
          LIMIT 1";

$stmt = $db->prepare($query);
$stmt->bindValue(':user_id', $user_id, PDO::PARAM_INT);
$stmt->bindValue(':tid', $tid, PDO::PARAM_INT);
$stmt->execute();
$datos = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$datos) {
    header("Location: " . BASE_URL . "/index.php");
    exit;
}

$id_estudiante = $datos['id_estudiante'];
$id_matricula = $datos['id_matricula'];
$id_seccion = $datos['id_seccion'];
$anno = $datos['anno'];
$periodo = $datos['id_periodo'];
$nota_minima = $datos['nota_minima_aprobacion'] ?? 7.0;

// ===== OBTENER CALIFICACIONES =====
$filtro_materia = $_GET['materia'] ?? '';

// ✅ CORREGIDO: Eliminado 'asig.color' que no existe
//
// Las notas de examen NO viven en tbl_entrega_actividad (esa tabla es
// solo para tareas calificadas por el profesor) -- viven en
// tbl_intento_examen. vw_logro_estudiante (migrations/2026_08_16_vista_
// logro_estudiante.sql) unifica ambas fuentes en una sola vista de
// lectura -- ver esa migración para el detalle de por qué existe. Un
// simple JOIN a la vista reemplaza el UNION ALL manual que tenía antes
// esta consulta.
$query_notas = "SELECT
    act.titulo,
    act.nota_maxima,
    act.tipo,
    act.fecha_limite,
    v.nota_obtenida,
    v.estado_entrega,
    v.observacion_docente,
    asig.id as id_asignatura,
    asig.nombre as asignatura,
    prof.primer_nombre as prof_nombre,
    prof.primer_apellido as prof_apellido
    FROM vw_logro_estudiante v
    JOIN tbl_actividad act ON v.id_actividad = act.id
    JOIN tbl_asignacion_docente ad ON act.id_asignacion_docente = ad.id
    JOIN tbl_asignatura asig ON ad.id_asignatura = asig.id
    JOIN tbl_profesor pf ON ad.id_profesor = pf.id
    JOIN tbl_persona prof ON pf.id_persona = prof.id
    WHERE v.id_matricula = :id_matricula
    AND act.estado = 'activo'";

$params = [':id_matricula' => $id_matricula];

if (!empty($filtro_materia)) {
    $query_notas .= " AND asig.id = :materia";
    $params[':materia'] = $filtro_materia;
}

$query_notas .= " ORDER BY act.fecha_limite DESC";

$stmt_notas = $db->prepare($query_notas);
foreach ($params as $key => $value) {
    $stmt_notas->bindValue($key, $value);
}
$stmt_notas->execute();
$notas = $stmt_notas->fetchAll(PDO::FETCH_ASSOC);

// ===== ESTADÍSTICAS Y PROMEDIOS =====
$suma_notas = 0;
$suma_maximas = 0;
$calificadas = 0;

foreach ($notas as $n) {
    if ($n['nota_obtenida'] !== null && $n['estado_entrega'] == 'calificado') {
        $suma_notas += $n['nota_obtenida'];
        $suma_maximas += $n['nota_maxima'];
        $calificadas++;
    }
}

$promedio_general = ($suma_maximas > 0) ? ($suma_notas / $suma_maximas) * 10 : 0;

// Promedios por materia
$promedios_materias = [];
foreach ($notas as $n) {
    if ($n['nota_obtenida'] !== null && $n['estado_entrega'] == 'calificado') {
        if (!isset($promedios_materias[$n['asignatura']])) {
            $promedios_materias[$n['asignatura']] = ['suma' => 0, 'max' => 0, 'count' => 0];
        }
        $promedios_materias[$n['asignatura']]['suma'] += $n['nota_obtenida'];
        $promedios_materias[$n['asignatura']]['max'] += $n['nota_maxima'];
        $promedios_materias[$n['asignatura']]['count']++;
    }
}

// Obtener materias para filtro
$query_materias = "SELECT DISTINCT asig.id, asig.nombre 
                   FROM tbl_asignacion_docente ad
                   JOIN tbl_asignatura asig ON ad.id_asignatura = asig.id
                   WHERE ad.id_seccion = :id_seccion AND ad.anno = :anno
                   ORDER BY asig.nombre";
$stmt_materias = $db->prepare($query_materias);
$stmt_materias->execute([':id_seccion' => $id_seccion, ':anno' => $anno]);
$materias = $stmt_materias->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Mis Calificaciones - Educación Plus</title>
    
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    
    <style>
        :root { --primary: #4361ee; --success: #2ecc71; --warning: #f39c12; --danger: #e74c3c; --sidebar-width: 260px; }
        body { font-family: 'Segoe UI', sans-serif; background: #f5f7fa; }
        .sidebar { position: fixed; top: 0; left: 0; height: 100vh; width: var(--sidebar-width); background: linear-gradient(180deg, #1d3557, #2a4365); color: white; z-index: 1000; }
        .sidebar .nav-link { color: rgba(255,255,255,0.85); padding: 12px 20px; border-radius: 8px; margin: 2px 0; }
        .sidebar .nav-link:hover, .sidebar .nav-link.active { background: rgba(255,255,255,0.15); color: white; }
        .main-content { margin-left: var(--sidebar-width); padding: 20px 30px; }
        .card-custom { background: white; border-radius: 12px; box-shadow: 0 2px 12px rgba(0,0,0,0.08); margin-bottom: 20px; overflow: hidden; }
        .nota-badge { width: 45px; height: 45px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-weight: bold; font-size: 1.1rem; color: white; }
        .nota-alta { background: var(--success); }
        .nota-media { background: var(--warning); }
        .nota-baja { background: var(--danger); }
        .table thead th { background-color: #f8f9fa; border-bottom: 2px solid #e9ecef; color: #495057; }
        @media (max-width: 992px) { .sidebar { transform: translateX(-100%); } .sidebar.active { transform: translateX(0); } .main-content { margin-left: 0; } }
    </style>
</head>
<body>
    <!-- Sidebar -->
    <aside class="sidebar" id="sidebar">
        <div class="text-center p-3 border-bottom">
            <h5><i class="fas fa-graduation-cap"></i> Educación Plus</h5>
        </div>
        <div class="p-3 text-center border-bottom">
            <div class="fw-bold small"><?= htmlspecialchars($datos['primer_nombre']) ?></div>
            <small class="text-white-50">Estudiante</small>
        </div>
        <nav class="nav flex-column p-2">
            <a class="nav-link" href="../../index.php"><i class="fas fa-home"></i> Dashboard</a>
            <a class="nav-link" href="mis_clases.php"><i class="fas fa-book"></i> Mis Clases</a>
            <a class="nav-link" href="actividades.php"><i class="fas fa-tasks"></i> Actividades</a>
            <a class="nav-link active" href="mis_notas.php"><i class="fas fa-star"></i> Calificaciones</a>
            <a class="nav-link" href="mensajes.php">
                <i class="fas fa-envelope"></i> Mensajes
                <?php if ($totalNoLeidos > 0): ?><span class="badge bg-danger rounded-pill float-end"><?= $totalNoLeidos ?></span><?php endif; ?>
            </a>
            <a class="nav-link" href="../../logout.php"><i class="fas fa-sign-out-alt"></i> Cerrar Sesión</a>
        </nav>
    </aside>

    <!-- Main Content -->
    <main class="main-content">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <div>
                <h2><i class="fas fa-star"></i> Mis Calificaciones</h2>
                <p class="text-muted mb-0">Resumen de tu rendimiento académico</p>
            </div>
            <button class="btn btn-outline-primary btn-sm" id="sidebarToggle"><i class="fas fa-bars"></i></button>
        </div>

        <!-- Resumen Promedios -->
        <div class="row g-3 mb-4">
            <div class="col-md-4">
                <div class="card-custom p-3 d-flex align-items-center">
                    <div class="rounded-circle bg-primary text-white d-flex align-items-center justify-content-center me-3" style="width: 60px; height: 60px; font-size: 1.5rem;">
                        <i class="fas fa-chart-line"></i>
                    </div>
                    <div>
                        <h3 class="mb-0 fw-bold"><?= number_format($promedio_general, 1) ?></h3>
                        <small class="text-muted">Promedio General</small>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card-custom p-3 d-flex align-items-center">
                    <div class="rounded-circle bg-success text-white d-flex align-items-center justify-content-center me-3" style="width: 60px; height: 60px; font-size: 1.5rem;">
                        <i class="fas fa-check-circle"></i>
                    </div>
                    <div>
                        <h3 class="mb-0 fw-bold"><?= $calificadas ?></h3>
                        <small class="text-muted">Actividades Calificadas</small>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card-custom p-3 d-flex align-items-center">
                    <div class="rounded-circle bg-warning text-white d-flex align-items-center justify-content-center me-3" style="width: 60px; height: 60px; font-size: 1.5rem;">
                        <i class="fas fa-clipboard-check"></i>
                    </div>
                    <div>
                        <h3 class="mb-0 fw-bold"><?= count($promedios_materias) ?></h3>
                        <small class="text-muted">Materias con Notas</small>
                    </div>
                </div>
            </div>
        </div>

        <!-- Filtros -->
        <div class="card-custom p-3 mb-4">
            <form method="GET" class="row g-3 align-items-end">
                <div class="col-md-8">
                    <label class="form-label small text-muted">Filtrar por materia</label>
                    <select name="materia" class="form-select" onchange="this.form.submit()">
                        <option value="">Todas las materias</option>
                        <?php foreach ($materias as $mat): ?>
                        <option value="<?= $mat['id'] ?>" <?= $filtro_materia == $mat['id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($mat['nombre']) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-4">
                    <a href="mis_notas.php" class="btn btn-outline-secondary w-100"><i class="fas fa-redo"></i> Ver todas</a>
                </div>
            </form>
        </div>

        <!-- Tabla de Notas -->
        <div class="card-custom">
            <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
                <h5 class="mb-0"><i class="fas fa-list"></i> Historial de Calificaciones</h5>
                <span class="badge bg-light text-dark"><?= count($notas) ?> registros</span>
            </div>
            <div class="table-responsive">
                <table class="table table-hover mb-0 align-middle">
                    <thead>
                        <tr>
                            <th>Materia</th>
                            <th>Actividad</th>
                            <th>Fecha</th>
                            <th>Estado</th>
                            <th class="text-center">Nota</th>
                            <th>Observaciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($notas)): ?>
                        <tr>
                            <td colspan="6" class="text-center py-4 text-muted">
                                <i class="fas fa-inbox fa-2x mb-2 d-block"></i>
                                No hay calificaciones registradas todavía.
                            </td>
                        </tr>
                        <?php else: ?>
                        <?php foreach ($notas as $nota): 
                            $nota_val = $nota['nota_obtenida'];
                            $max_val = $nota['nota_maxima'];
                            
                            // Determinar clase del badge según nota
                            $badge_class = 'bg-secondary';
                            if ($nota['estado_entrega'] == 'calificado') {
                                $porcentaje = ($nota_val / $max_val) * 100;
                                if ($porcentaje >= 90) $badge_class = 'bg-success';
                                elseif ($porcentaje >= 70) $badge_class = 'bg-primary';
                                elseif ($porcentaje >= 60) $badge_class = 'bg-warning';
                                else $badge_class = 'bg-danger';
                            } elseif ($nota['estado_entrega'] == 'entregado') {
                                $badge_class = 'bg-info';
                            }
                        ?>
                        <tr>
                            <td>
                                <div class="fw-bold"><?= htmlspecialchars($nota['asignatura']) ?></div>
                                <small class="text-muted">Prof. <?= htmlspecialchars($nota['prof_apellido']) ?></small>
                            </td>
                            <td>
                                <div><?= htmlspecialchars($nota['titulo']) ?></div>
                                <span class="badge bg-light text-dark border"><?= ucfirst($nota['tipo']) ?></span>
                            </td>
                            <td>
                                <small><i class="fas fa-calendar-alt"></i> <?= date('d/m/Y', strtotime($nota['fecha_limite'])) ?></small>
                            </td>
                            <td>
                                <span class="badge <?= $badge_class ?>">
                                    <?= ucfirst($nota['estado_entrega']) ?>
                                </span>
                            </td>
                            <td class="text-center">
                                <?php if ($nota['estado_entrega'] == 'calificado'): ?>
                                    <span class="fw-bold fs-5"><?= $nota_val ?></span>
                                    <small class="text-muted">/ <?= $max_val ?></small>
                                <?php else: ?>
                                    <span class="text-muted">-</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <small class="text-muted">
                                    <?= htmlspecialchars($nota['observacion_docente'] ?? '') ?>
                                </small>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </main>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        document.getElementById('sidebarToggle')?.addEventListener('click', () => {
            document.getElementById('sidebar').classList.toggle('active');
        });
    </script>
</body>
</html>