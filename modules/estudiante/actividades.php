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

// Obtener datos del estudiante
$query = "SELECT
          e.id as id_estudiante,
          m.id as id_matricula, m.anno,
          s.id as id_seccion,
          p.primer_nombre, p.primer_apellido
          FROM tbl_estudiante e
          JOIN tbl_persona p ON e.id_persona = p.id
          JOIN tbl_matricula m ON e.id = m.id_estudiante
          JOIN tbl_seccion s ON m.id_seccion = s.id
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
// NOTA: ya no se filtra por "período" -- ver estudiante_dashboard.php.

// ===== PROCESAR ENTREGA DE ACTIVIDAD =====
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['accion'])) {
    try {
        $db->beginTransaction();
        
        if ($_POST['accion'] == 'entregar') {
            $id_actividad = (int) $_POST['id_actividad'];
            $respuesta = $_POST['respuesta'] ?? '';
            $archivo_url = '';

            // La actividad DEBE pertenecer a una asignación de la sección/período del estudiante.
            // tbl_asignacion_docente no tiene columna id_institucion; no hace
            // falta filtrarla aparte porque $id_seccion ya viene de una
            // consulta anterior filtrada por tbl_estudiante.id_institucion.
            $checkAct = $db->prepare("SELECT act.id FROM tbl_actividad act
                                      JOIN tbl_asignacion_docente ad ON act.id_asignacion_docente = ad.id
                                      WHERE act.id = :id_actividad
                                      AND ad.id_seccion = :id_seccion
                                      AND ad.anno = :anno");
            $checkAct->execute([
                ':id_actividad' => $id_actividad,
                ':id_seccion' => $id_seccion,
                ':anno' => $anno
            ]);
            if ($checkAct->rowCount() === 0) {
                throw new Exception('No tiene permiso para entregar esta actividad');
            }

            // Subir archivo si existe
            if (isset($_FILES['archivo']) && $_FILES['archivo']['error'] == 0) {
                $upload_dir = '../../uploads/entregas/';
                if (!file_exists($upload_dir)) {
                    mkdir($upload_dir, 0777, true);
                }
                
                $file_ext = pathinfo($_FILES['archivo']['name'], PATHINFO_EXTENSION);
                $new_filename = 'entrega_' . $id_actividad . '_' . $id_estudiante . '_' . time() . '.' . $file_ext;
                $upload_path = $upload_dir . $new_filename;
                
                if (move_uploaded_file($_FILES['archivo']['tmp_name'], $upload_path)) {
                    $archivo_url = 'uploads/entregas/' . $new_filename;
                }
            }
            
            // Verificar si ya existe entrega. tbl_entrega_actividad no tiene
            // columna id_institucion; :id_matricula ya está tenant-verificado
            // (viene de la consulta inicial filtrada por e.id_institucion).
            $check = $db->prepare("SELECT id FROM tbl_entrega_actividad
                                  WHERE id_actividad = :id_actividad
                                  AND id_matricula = :id_matricula");
            $check->execute([
                ':id_actividad' => $id_actividad,
                ':id_matricula' => $id_matricula
            ]);

            if ($check->rowCount() > 0) {
                // Actualizar entrega existente
                $query = "UPDATE tbl_entrega_actividad SET
                         observacion_docente = :respuesta,
                         archivo_url = :archivo_url,
                         estado_entrega = 'entregado',
                         fecha_entrega = NOW()
                         WHERE id_actividad = :id_actividad
                         AND id_matricula = :id_matricula";
            } else {
                // Crear nueva entrega
                $query = "INSERT INTO tbl_entrega_actividad
                         (id_actividad, id_matricula, observacion_docente, archivo_url, estado_entrega, fecha_entrega)
                         VALUES (:id_actividad, :id_matricula, :respuesta, :archivo_url, 'entregado', NOW())";
            }

            $stmt = $db->prepare($query);
            $stmt->execute([
                ':id_actividad' => $id_actividad,
                ':id_matricula' => $id_matricula,
                ':respuesta' => $respuesta,
                ':archivo_url' => $archivo_url
            ]);
            
            $db->commit();
            $mensaje = 'Actividad entregada exitosamente';
            $tipo_mensaje = 'success';
        }
        
    } catch (Exception $e) {
        $db->rollBack();
        $mensaje = 'Error al entregar: ' . $e->getMessage();
        $tipo_mensaje = 'danger';
    }
}

// ===== OBTENER ACTIVIDADES =====
$filtro_tipo = $_GET['tipo'] ?? 'todos';
$filtro_estado = $_GET['estado'] ?? 'todos';
$filtro_materia = $_GET['materia'] ?? '';

// ✅ CORREGIDO: Usando la estructura real de la BD
//
// Las actividades de tipo 'examen' NUNCA tienen fila en
// tbl_entrega_actividad (esa tabla es solo para tareas calificadas
// manualmente por el profesor) -- su entrega/nota vive en
// tbl_intento_examen. vw_logro_estudiante (migrations/2026_08_16_vista_
// logro_estudiante.sql) unifica ambas fuentes -- ver esa migración para
// el porqué. archivo_url es exclusivo de tareas (adjunto que sube el
// estudiante), así que se sigue leyendo aparte de tbl_entrega_actividad;
// todo lo demás (si está entregada, su nota, su estado) sale de la vista.
$query_actividades = "SELECT
    act.id, act.id_examen, act.titulo, act.descripcion, act.tipo, act.fecha_programada, act.fecha_limite,
    act.nota_maxima, act.estado as estado_actividad, act.contenido, act.url_recurso,
    asig.id as id_asignatura, asig.nombre as asignatura,
    v.id_registro_origen as id_entrega,
    ea.archivo_url,
    v.estado_entrega,
    v.nota_obtenida,
    v.observacion_docente,
    v.fecha_entrega,
    DATEDIFF(act.fecha_limite, NOW()) as dias_restantes
    FROM tbl_actividad act
    JOIN tbl_asignacion_docente ad ON act.id_asignacion_docente = ad.id
    JOIN tbl_asignatura asig ON ad.id_asignatura = asig.id
    LEFT JOIN tbl_entrega_actividad ea ON act.id = ea.id_actividad AND ea.id_matricula = :id_matricula
    LEFT JOIN vw_logro_estudiante v ON v.id_actividad = act.id AND v.id_matricula = :id_matricula_v
    WHERE ad.id_seccion = :id_seccion
    AND ad.anno = :anno
    AND act.estado IN ('publicado', 'activo')";

$params = [
    ':id_matricula' => $id_matricula,
    ':id_matricula_v' => $id_matricula,
    ':id_seccion' => $id_seccion,
    ':anno' => $anno
];

if ($filtro_tipo != 'todos') {
    $query_actividades .= " AND act.tipo = :tipo";
    $params[':tipo'] = $filtro_tipo;
}

if (!empty($filtro_materia)) {
    $query_actividades .= " AND asig.id = :materia";
    $params[':materia'] = $filtro_materia;
}

if ($filtro_estado == 'pendientes') {
    $query_actividades .= " AND (v.id_registro_origen IS NULL OR v.estado_entrega != 'calificado') AND act.fecha_limite >= CURDATE()";
} elseif ($filtro_estado == 'entregadas') {
    $query_actividades .= " AND v.id_registro_origen IS NOT NULL AND v.estado_entrega = 'entregado'";
} elseif ($filtro_estado == 'calificadas') {
    $query_actividades .= " AND v.id_registro_origen IS NOT NULL AND v.estado_entrega = 'calificado'";
}

$query_actividades .= " ORDER BY act.fecha_limite ASC";

$stmt = $db->prepare($query_actividades);
foreach ($params as $key => $value) {
    $stmt->bindValue($key, $value);
}
$stmt->execute();
$actividades = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Obtener materias para filtro
$query_materias = "SELECT DISTINCT asig.id, asig.nombre 
                   FROM tbl_asignacion_docente ad
                   JOIN tbl_asignatura asig ON ad.id_asignatura = asig.id
                   WHERE ad.id_seccion = :id_seccion AND ad.anno = :anno
                   ORDER BY asig.nombre";
$stmt = $db->prepare($query_materias);
$stmt->execute([':id_seccion' => $id_seccion, ':anno' => $anno]);
$materias = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Estadísticas
$total_actividades = count($actividades);
$pendientes = count(array_filter($actividades, fn($a) => $a['id_entrega'] === null && strtotime($a['fecha_limite']) >= time()));
$entregadas = count(array_filter($actividades, fn($a) => $a['estado_entrega'] == 'entregado'));
$calificadas = count(array_filter($actividades, fn($a) => $a['estado_entrega'] == 'calificado'));

// ✅ CORREGIDO: Tipos según ENUM real de la BD
$tipos_actividad = [
    'tarea' => ['label' => 'Tarea', 'icon' => 'fa-clipboard-list', 'color' => 'info'],
    'examen' => ['label' => 'Examen', 'icon' => 'fa-file-alt', 'color' => 'danger'],
    'laboratorio' => ['label' => 'Laboratorio', 'icon' => 'fa-flask', 'color' => 'warning'],
    'foro' => ['label' => 'Foro', 'icon' => 'fa-comments', 'color' => 'success'],
    'recurso' => ['label' => 'Recurso', 'icon' => 'fa-book', 'color' => 'secondary']
];
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Mis Actividades - Educación Plus</title>
    
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    
    <style>
        :root { --primary: #4361ee; --sidebar-width: 260px; }
        body { font-family: 'Segoe UI', sans-serif; background: #f5f7fa; }
        .sidebar { position: fixed; top: 0; left: 0; height: 100vh; width: var(--sidebar-width); background: linear-gradient(180deg, #1d3557, #2a4365); color: white; z-index: 1000; }
        .sidebar .nav-link { color: rgba(255,255,255,0.85); padding: 12px 20px; border-radius: 8px; margin: 2px 0; }
        .sidebar .nav-link:hover, .sidebar .nav-link.active { background: rgba(255,255,255,0.15); color: white; }
        .main-content { margin-left: var(--sidebar-width); padding: 20px 30px; }
        .card-custom { background: white; border-radius: 12px; box-shadow: 0 2px 12px rgba(0,0,0,0.08); margin-bottom: 20px; }
        .activity-card { border-left: 4px solid var(--primary); transition: all 0.2s; }
        .activity-card:hover { transform: translateY(-2px); box-shadow: 0 4px 15px rgba(0,0,0,0.1); }
        .badge-estado { padding: 5px 12px; border-radius: 15px; font-size: 0.75rem; font-weight: 600; }
        .estado-pendiente { background: #fff3cd; color: #856404; }
        .estado-entregado { background: #cce5ff; color: #004085; }
        .estado-calificado { background: #d4edda; color: #155724; }
        .vencida { background: #f8d7da; color: #721c24; }
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
            <a class="nav-link active" href="actividades.php"><i class="fas fa-tasks"></i> Actividades</a>
            <a class="nav-link" href="mis_notas.php"><i class="fas fa-star"></i> Calificaciones</a>
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
                <h2><i class="fas fa-tasks"></i> Mis Actividades</h2>
                <p class="text-muted mb-0">Gestiona tus tareas y entregas</p>
            </div>
            <button class="btn btn-outline-primary btn-sm" id="sidebarToggle"><i class="fas fa-bars"></i></button>
        </div>

        <?php if (isset($mensaje)): ?>
        <div class="alert alert-<?= $tipo_mensaje ?> alert-dismissible fade show">
            <?= htmlspecialchars($mensaje) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php endif; ?>

        <!-- Stats -->
        <div class="row g-3 mb-4">
            <div class="col-md-3">
                <div class="card-custom p-3 text-center">
                    <h3 class="text-primary mb-0"><?= $total_actividades ?></h3>
                    <small class="text-muted">Total Actividades</small>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card-custom p-3 text-center">
                    <h3 class="text-warning mb-0"><?= $pendientes ?></h3>
                    <small class="text-muted">Pendientes</small>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card-custom p-3 text-center">
                    <h3 class="text-info mb-0"><?= $entregadas ?></h3>
                    <small class="text-muted">Entregadas</small>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card-custom p-3 text-center">
                    <h3 class="text-success mb-0"><?= $calificadas ?></h3>
                    <small class="text-muted">Calificadas</small>
                </div>
            </div>
        </div>

        <!-- Filtros -->
        <div class="card-custom p-3 mb-4">
            <form method="GET" class="row g-3">
                <div class="col-md-3">
                    <select name="tipo" class="form-select" onchange="this.form.submit()">
                        <option value="todos" <?= $filtro_tipo == 'todos' ? 'selected' : '' ?>>Todos los tipos</option>
                        <?php foreach ($tipos_actividad as $key => $tipo): ?>
                        <option value="<?= $key ?>" <?= $filtro_tipo == $key ? 'selected' : '' ?>><?= $tipo['label'] ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <select name="estado" class="form-select" onchange="this.form.submit()">
                        <option value="todos" <?= $filtro_estado == 'todos' ? 'selected' : '' ?>>Todos los estados</option>
                        <option value="pendientes" <?= $filtro_estado == 'pendientes' ? 'selected' : '' ?>>Pendientes</option>
                        <option value="entregadas" <?= $filtro_estado == 'entregadas' ? 'selected' : '' ?>>Entregadas</option>
                        <option value="calificadas" <?= $filtro_estado == 'calificadas' ? 'selected' : '' ?>>Calificadas</option>
                    </select>
                </div>
                <div class="col-md-4">
                    <select name="materia" class="form-select" onchange="this.form.submit()">
                        <option value="">Todas las materias</option>
                        <?php foreach ($materias as $mat): ?>
                        <option value="<?= $mat['id'] ?>" <?= $filtro_materia == $mat['id'] ? 'selected' : '' ?>><?= htmlspecialchars($mat['nombre']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <a href="actividades.php" class="btn btn-outline-secondary w-100"><i class="fas fa-redo"></i></a>
                </div>
            </form>
        </div>

        <!-- Lista de Actividades -->
        <?php if (empty($actividades)): ?>
        <div class="card-custom p-5 text-center">
            <i class="fas fa-clipboard-check fa-4x text-muted mb-3"></i>
            <h5>No hay actividades registradas</h5>
            <p class="text-muted">No hay actividades para mostrar con los filtros seleccionados.</p>
        </div>
        <?php else: ?>
        <div class="row g-3">
            <?php foreach ($actividades as $act): 
                $tipo = $tipos_actividad[$act['tipo']] ?? ['label' => $act['tipo'], 'icon' => 'fa-tasks', 'color' => 'secondary'];
                $dias = $act['dias_restantes'];
                
                if ($act['estado_entrega'] == 'calificado') {
                    $estado_class = 'estado-calificado';
                    $estado_label = 'Calificada';
                } elseif ($act['estado_entrega'] == 'entregado') {
                    $estado_class = 'estado-entregado';
                    $estado_label = 'Entregada';
                } elseif ($dias < 0) {
                    $estado_class = 'vencida';
                    $estado_label = 'Vencida';
                } else {
                    $estado_class = 'estado-pendiente';
                    $estado_label = 'Pendiente';
                }
            ?>
            <div class="col-12">
                <div class="card-custom activity-card p-3">
                    <div class="row align-items-center">
                        <div class="col-md-1 text-center">
                            <div class="bg-<?= $tipo['color'] ?> text-white rounded-circle d-flex align-items-center justify-content-center mx-auto" style="width: 50px; height: 50px;">
                                <i class="fas <?= $tipo['icon'] ?>"></i>
                            </div>
                        </div>
                        <div class="col-md-5">
                            <h6 class="mb-1"><?= htmlspecialchars($act['titulo']) ?></h6>
                            <small class="text-muted">
                                <i class="fas fa-book"></i> <?= htmlspecialchars($act['asignatura']) ?>
                            </small>
                            <?php if ($act['descripcion']): ?>
                            <p class="mb-0 mt-2 small text-muted"><?= htmlspecialchars(substr($act['descripcion'], 0, 100)) ?><?= strlen($act['descripcion']) > 100 ? '...' : '' ?></p>
                            <?php endif; ?>
                        </div>
                        <div class="col-md-3">
                            <small class="d-block"><i class="fas fa-calendar"></i> Entrega: <?= $act['fecha_limite'] ? date('d/m/Y', strtotime($act['fecha_limite'])) : 'Sin fecha límite' ?></small>
                            <small class="d-block"><i class="fas fa-clock"></i> <?= $dias === null ? 'Sin fecha límite' : ($dias >= 0 ? $dias . ' días restantes' : 'Vencida') ?></small>
                            <?php if ($act['nota_maxima']): ?>
                            <small class="d-block"><i class="fas fa-star"></i> Valor: <?= $act['nota_maxima'] ?> pts</small>
                            <?php endif; ?>
                        </div>
                        <div class="col-md-2 text-center">
                            <span class="badge-estado <?= $estado_class ?>"><?= $estado_label ?></span>
                            <?php if ($act['nota_obtenida'] !== null): ?>
                            <div class="mt-2 fw-bold text-success">Nota: <?= $act['nota_obtenida'] ?>/<?= $act['nota_maxima'] ?></div>
                            <?php endif; ?>
                        </div>
                        <div class="col-md-1 text-end">
                            <?php if ($act['tipo'] === 'examen' && !empty($act['id_examen'])): ?>
                            <a href="tomar_examen.php?id=<?= $act['id_examen'] ?>" class="btn btn-sm btn-danger" title="Tomar examen">
                                <i class="fas fa-file-signature"></i>
                            </a>
                            <?php else: ?>
                            <button class="btn btn-sm btn-primary" onclick="verDetalle(<?= $act['id'] ?>)">
                                <i class="fas fa-eye"></i>
                            </button>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </main>

    <!-- Modal Detalle Actividad -->
    <div class="modal fade" id="modalDetalle" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title"><i class="fas fa-tasks"></i> Detalle de Actividad</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body" id="modalBody">
                    <!-- Cargado vía AJAX -->
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script>
        document.getElementById('sidebarToggle')?.addEventListener('click', () => {
            document.getElementById('sidebar').classList.toggle('active');
        });
        
        function verDetalle(id) {
            alert('Ver detalle de actividad ID: ' + id);
            // Aquí se implementaría la carga AJAX del detalle
        }
    </script>
</body>
</html>