<?php
session_start();
require_once __DIR__ . '/../../config/database.php';

// Verificar autenticación
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['rol'], ['admin', 'director', 'orientador'])) {
    header("Location: " . BASE_URL . "/login.php");
    exit;
}

$database = new Database();
$db = $database->getConnection();
$mensaje = '';
$tipo_mensaje = '';

// ===== PROCESAR ACCIONES POST =====
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $accion = $_POST['accion'] ?? '';
    
    try {
        $db->beginTransaction();
        
        if ($accion === 'crear_seguimiento') {
            $query = "INSERT INTO tbl_bienestar_seguimiento 
                      (id_estudiante, id_orientador, fecha_inicio, motivo, descripcion, estado, prioridad) 
                      VALUES (:estudiante, :orientador, :fecha, :motivo, :descripcion, :estado, :prioridad)";
            $stmt = $db->prepare($query);
            $stmt->execute([
                ':estudiante' => $_POST['id_estudiante'],
                ':orientador' => $_SESSION['user_id'],
                ':fecha' => $_POST['fecha_inicio'] ?? date('Y-m-d'),
                ':motivo' => $_POST['motivo'],
                ':descripcion' => $_POST['descripcion'] ?? '',
                ':estado' => $_POST['estado'] ?? 'activo',
                ':prioridad' => $_POST['prioridad'] ?? 'media'
            ]);
            $mensaje = 'Seguimiento creado exitosamente';
            $tipo_mensaje = 'success';
            
        } elseif ($accion === 'actualizar_seguimiento') {
            $query = "UPDATE tbl_bienestar_seguimiento SET 
                      fecha_inicio = :fecha, motivo = :motivo, descripcion = :descripcion, 
                      estado = :estado, prioridad = :prioridad, observaciones = :observaciones
                      WHERE id = :id";
            $stmt = $db->prepare($query);
            $stmt->execute([
                ':fecha' => $_POST['fecha_inicio'],
                ':motivo' => $_POST['motivo'],
                ':descripcion' => $_POST['descripcion'],
                ':estado' => $_POST['estado'],
                ':prioridad' => $_POST['prioridad'],
                ':observaciones' => $_POST['observaciones'] ?? '',
                ':id' => $_POST['id_seguimiento']
            ]);
            $mensaje = 'Seguimiento actualizado';
            $tipo_mensaje = 'success';
            
        } elseif ($accion === 'cerrar_seguimiento') {
            $stmt = $db->prepare("UPDATE tbl_bienestar_seguimiento 
                                  SET estado = 'cerrado', fecha_cierre = NOW() WHERE id = :id");
            $stmt->execute([':id' => $_POST['id_seguimiento']]);
            $mensaje = 'Seguimiento cerrado';
            $tipo_mensaje = 'info';
            
        } elseif ($accion === 'crear_sesion') {
            $query = "INSERT INTO tbl_bienestar_sesion 
                      (id_seguimiento, fecha, tipo, duracion_min, descripcion, acuerdos, proxima_sesion) 
                      VALUES (:seguimiento, :fecha, :tipo, :duracion, :descripcion, :acuerdos, :proxima)";
            $stmt = $db->prepare($query);
            $stmt->execute([
                ':seguimiento' => $_POST['id_seguimiento'],
                ':fecha' => $_POST['fecha'],
                ':tipo' => $_POST['tipo'],
                ':duracion' => $_POST['duracion_min'] ?? 30,
                ':descripcion' => $_POST['descripcion'] ?? '',
                ':acuerdos' => $_POST['acuerdos'] ?? '',
                ':proxima' => $_POST['proxima_sesion'] ?? null
            ]);
            $mensaje = 'Sesión registrada';
            $tipo_mensaje = 'success';
            
        } elseif ($accion === 'derivar_alerta') {
            $stmt = $db->prepare("UPDATE tbl_bienestar_alerta SET atendida = 1, id_seguimiento = :seg WHERE id = :id");
            $stmt->execute([':seg' => $_POST['id_seguimiento'], ':id' => $_POST['id_alerta']]);
            $mensaje = 'Alerta derivada a seguimiento';
            $tipo_mensaje = 'success';
        }
        
        $db->commit();
    } catch (Exception $e) {
        $db->rollBack();
        $mensaje = 'Error: ' . $e->getMessage();
        $tipo_mensaje = 'danger';
    }
}

// ===== OBTENER DATOS =====
$filtros = [
    'estado' => $_GET['estado'] ?? '',
    'motivo' => $_GET['motivo'] ?? '',
    'busqueda' => $_GET['busqueda'] ?? ''
];

// ✅ CONSULTA CORREGIDA: 
// El orientador está en tbl_usuario, y para obtener su nombre necesitamos:
// tbl_bienestar_seguimiento.id_orientador → tbl_usuario.id
// tbl_usuario → tbl_persona (a través de id_persona SI EXISTE, o buscar por usuario)
// Si tbl_usuario NO tiene id_persona, usamos el campo 'usuario' directamente

$query = "SELECT s.*, 
          CONCAT(p.primer_nombre, ' ', p.primer_apellido) as estudiante,
          e.nie,
          g.nombre as grado, 
          sec.nombre as seccion,
          u.usuario as orientador,
          COUNT(DISTINCT ses.id) as total_sesiones
          FROM tbl_bienestar_seguimiento s
          JOIN tbl_estudiante e ON s.id_estudiante = e.id
          JOIN tbl_persona p ON e.id_persona = p.id
          JOIN tbl_matricula m ON e.id = m.id_estudiante AND m.estado = 'activo'
          JOIN tbl_seccion sec ON m.id_seccion = sec.id
          JOIN tbl_grado g ON sec.id_grado = g.id
          JOIN tbl_usuario u ON s.id_orientador = u.id
          LEFT JOIN tbl_bienestar_sesion ses ON s.id = ses.id_seguimiento
          WHERE 1=1";

$params = [];

if (!empty($filtros['estado'])) {
    $query .= " AND s.estado = :estado";
    $params[':estado'] = $filtros['estado'];
}

if (!empty($filtros['motivo'])) {
    $query .= " AND s.motivo = :motivo";
    $params[':motivo'] = $filtros['motivo'];
}

if (!empty($filtros['busqueda'])) {
    $query .= " AND (p.primer_nombre LIKE :busqueda OR p.primer_apellido LIKE :busqueda OR s.descripcion LIKE :busqueda)";
    $params[':busqueda'] = "%{$filtros['busqueda']}%";
}

$query .= " GROUP BY s.id ORDER BY s.fecha_inicio DESC";

$stmt = $db->prepare($query);
foreach ($params as $k => $v) $stmt->bindValue($k, $v);
$stmt->execute();
$seguimientos = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Alertas pendientes
$alertas = $db->query("SELECT a.*, CONCAT(p.primer_nombre, ' ', p.primer_apellido) as estudiante, a.tipo
                       FROM tbl_bienestar_alerta a
                       JOIN tbl_estudiante e ON a.id_estudiante = e.id
                       JOIN tbl_persona p ON e.id_persona = p.id
                       WHERE a.atendida = 0
                       ORDER BY a.fecha DESC")->fetchAll(PDO::FETCH_ASSOC);

// Reportes docentes pendientes
$reportes = $db->query("SELECT r.*, CONCAT(p.primer_nombre, ' ', p.primer_apellido) as estudiante, 
                               u.usuario as docente
                        FROM tbl_bienestar_reporte_docente r
                        JOIN tbl_estudiante e ON r.id_estudiante = e.id
                        JOIN tbl_persona p ON e.id_persona = p.id
                        JOIN tbl_usuario u ON r.id_docente = u.id
                        WHERE r.atendido = 0
                        ORDER BY r.fecha DESC")->fetchAll(PDO::FETCH_ASSOC);

// Datos auxiliares
$motivos = ['academico' => 'Académico', 'conductual' => 'Conductual', 'emocional' => 'Emocional', 
            'familiar' => 'Familiar', 'social' => 'Social', 'otro' => 'Otro'];
$estados = ['activo' => 'Activo', 'resuelto' => 'Resuelto', 'derivado' => 'Derivado', 'cerrado' => 'Cerrado'];
$prioridades = ['alta' => 'Alta', 'media' => 'Media', 'baja' => 'Baja'];
$tipos_sesion = ['individual' => 'Individual', 'grupal' => 'Grupal', 'familiar' => 'Familiar', 
                 'telefonica' => 'Telefónica', 'virtual' => 'Virtual'];

$estudiantes = $db->query("SELECT e.id, CONCAT(p.primer_nombre, ' ', p.primer_apellido) as nombre, e.nie
                           FROM tbl_estudiante e
                           JOIN tbl_persona p ON e.id_persona = p.id
                           JOIN tbl_usuario u ON p.id_usuario = u.id
                           WHERE u.estado = 1 ORDER BY p.primer_apellido")->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Bienestar Estudiantil</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <style>
        :root { --primary: #2c3e50; --sidebar-width: 250px; }
        body { font-family: 'Segoe UI', sans-serif; background: #f8f9fa; }
        .sidebar { position: fixed; top: 0; left: 0; height: 100vh; width: var(--sidebar-width); background: var(--primary); color: white; padding-top: 60px; z-index: 1000; }
        .sidebar .nav-link { color: rgba(255,255,255,0.8); padding: 12px 20px; }
        .sidebar .nav-link:hover, .sidebar .nav-link.active { color: white; background: rgba(255,255,255,0.15); }
        .main-content { margin-left: var(--sidebar-width); padding: 20px; }
        .card-custom { background: white; border-radius: 10px; box-shadow: 0 2px 10px rgba(0,0,0,0.08); border: none; margin-bottom: 30px; }
        .seguimiento-card { border-left: 4px solid var(--primary); }
        .prioridad-alta { border-left-color: #e74c3c; }
        .prioridad-media { border-left-color: #f39c12; }
        .prioridad-baja { border-left-color: #2ecc71; }
        @media (max-width: 768px) { .sidebar { transform: translateX(-100%); } .sidebar.active { transform: translateX(0); } .main-content { margin-left: 0; } }
    </style>
</head>
<body>
    <!-- Sidebar -->
    <div class="sidebar" id="sidebar">
        <div class="text-center mb-4">
            <h4><i class="fas fa-graduation-cap"></i> Educación Plus</h4>
            <small>Panel</small>
        </div>
        <nav class="nav flex-column">
            <a class="nav-link" href="../../index.php"><i class="fas fa-tachometer-alt"></i> Dashboard</a>
            <a class="nav-link active" href="bienestar_estudiantil.php"><i class="fas fa-heart"></i> Bienestar</a>
            <a class="nav-link" href="gestionar_estudiantes.php"><i class="fas fa-user-graduate"></i> Estudiantes</a>
            <a class="nav-link" href="../../logout.php"><i class="fas fa-sign-out-alt"></i> Salir</a>
        </nav>
    </div>

    <!-- Main Content -->
    <div class="main-content">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <div>
                <h2><i class="fas fa-heart"></i> Bienestar Estudiantil</h2>
                <p class="text-muted mb-0">Seguimiento y orientación</p>
            </div>
            <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#modalSeguimiento" onclick="prepararModal('crear')">
                <i class="fas fa-plus"></i> Nuevo Seguimiento
            </button>
        </div>

        <!-- Stats -->
        <div class="row mb-4">
            <div class="col-md-3">
                <div class="card-custom p-3 text-center">
                    <h3 class="mb-0 text-primary"><?= count($seguimientos) ?></h3>
                    <small class="text-muted">Seguimientos</small>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card-custom p-3 text-center">
                    <h3 class="mb-0 text-danger"><?= count($alertas) ?></h3>
                    <small class="text-muted">Alertas Pendientes</small>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card-custom p-3 text-center">
                    <h3 class="mb-0 text-warning"><?= count($reportes) ?></h3>
                    <small class="text-muted">Reportes Docentes</small>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card-custom p-3 text-center">
                    <h3 class="mb-0 text-success"><?= count(array_filter($seguimientos, fn($s) => $s['estado'] === 'activo')) ?></h3>
                    <small class="text-muted">Activos</small>
                </div>
            </div>
        </div>

        <!-- Messages -->
        <?php if ($mensaje): ?>
        <div class="alert alert-<?= $tipo_mensaje ?> alert-dismissible fade show">
            <?= htmlspecialchars($mensaje) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php endif; ?>

        <!-- Alerts -->
        <?php if (!empty($alertas)): ?>
        <div class="card-custom border-danger mb-4">
            <div class="card-header bg-danger text-white">
                <h5 class="mb-0"><i class="fas fa-exclamation-triangle"></i> Alertas Pendientes</h5>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-sm">
                        <thead><tr><th>Estudiante</th><th>Tipo</th><th>Descripción</th><th>Nivel</th><th>Acción</th></tr></thead>
                        <tbody>
                            <?php foreach ($alertas as $a): ?>
                            <tr>
                                <td><?= htmlspecialchars($a['estudiante']) ?></td>
                                <td><span class="badge bg-<?= $a['nivel'] === 'alta' ? 'danger' : ($a['nivel'] === 'media' ? 'warning' : 'info') ?>"><?= ucfirst($a['tipo']) ?></span></td>
                                <td><?= htmlspecialchars($a['descripcion']) ?></td>
                                <td><?= ucfirst($a['nivel']) ?></td>
                                <td>
                                    <button class="btn btn-sm btn-success" onclick="derivarAlerta(<?= $a['id'] ?>)">
                                        <i class="fas fa-arrow-right"></i> Derivar
                                    </button>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Filters -->
        <div class="card-custom p-4">
            <form method="GET" class="row g-3">
                <div class="col-md-3">
                    <label class="form-label">Estado</label>
                    <select name="estado" class="form-select">
                        <option value="">Todos</option>
                        <?php foreach ($estados as $k => $v): ?>
                        <option value="<?= $k ?>" <?= $filtros['estado'] == $k ? 'selected' : '' ?>><?= $v ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Motivo</label>
                    <select name="motivo" class="form-select">
                        <option value="">Todos</option>
                        <?php foreach ($motivos as $k => $v): ?>
                        <option value="<?= $k ?>" <?= $filtros['motivo'] == $k ? 'selected' : '' ?>><?= $v ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label">Búsqueda</label>
                    <input type="text" name="busqueda" class="form-control" placeholder="Estudiante o descripción" value="<?= htmlspecialchars($filtros['busqueda']) ?>">
                </div>
                <div class="col-md-2 d-flex align-items-end gap-2">
                    <button type="submit" class="btn btn-secondary flex-fill"><i class="fas fa-filter"></i> Filtrar</button>
                    <a href="bienestar_estudiantil.php" class="btn btn-outline-secondary"><i class="fas fa-redo"></i></a>
                </div>
            </form>
        </div>

        <!-- Seguimientos -->
        <div class="card-custom">
            <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
                <h5 class="mb-0"><i class="fas fa-list"></i> Seguimientos</h5>
                <span class="badge bg-primary"><?= count($seguimientos) ?></span>
            </div>
            <div class="card-body p-0">
                <?php if (empty($seguimientos)): ?>
                <div class="text-center py-5 text-muted"><i class="fas fa-inbox fa-3x mb-3"></i><p>No hay seguimientos</p></div>
                <?php else: ?>
                <div class="list-group list-group-flush">
                    <?php foreach ($seguimientos as $s): ?>
                    <div class="list-group-item seguimiento-card prioridad-<?= $s['prioridad'] ?> p-3">
                        <div class="row align-items-center">
                            <div class="col-md-4">
                                <h6 class="mb-1"><?= htmlspecialchars($s['estudiante']) ?></h6>
                                <small class="text-muted"><?= htmlspecialchars($s['nie']) ?> • <?= htmlspecialchars($s['grado']) ?>-<?= htmlspecialchars($s['seccion']) ?></small>
                            </div>
                            <div class="col-md-3">
                                <span class="badge bg-<?= $s['estado'] === 'activo' ? 'success' : ($s['estado'] === 'cerrado' ? 'secondary' : 'info') ?>">
                                    <?= ucfirst($s['estado']) ?>
                                </span>
                                <span class="badge bg-<?= $s['prioridad'] === 'alta' ? 'danger' : ($s['prioridad'] === 'media' ? 'warning' : 'success') ?> ms-1">
                                    <?= ucfirst($s['prioridad']) ?>
                                </span>
                                <br>
                                <small class="text-muted"><i class="fas fa-calendar"></i> <?= date('d/m/Y', strtotime($s['fecha_inicio'])) ?></small>
                            </div>
                            <div class="col-md-3">
                                <small class="text-muted"><strong>Motivo:</strong> <?= $motivos[$s['motivo']] ?? $s['motivo'] ?></small><br>
                                <small><?= htmlspecialchars(substr($s['descripcion'] ?? '', 0, 80)) ?><?= strlen($s['descripcion'] ?? '') > 80 ? '...' : '' ?></small>
                            </div>
                            <div class="col-md-2 text-end">
                                <small class="text-muted d-block"><i class="fas fa-users"></i> <?= $s['total_sesiones'] ?> sesiones</small>
                                <div class="btn-group btn-group-sm mt-1">
                                    <button class="btn btn-info" onclick="verSeguimiento(<?= $s['id'] ?>)"><i class="fas fa-eye"></i></button>
                                    <?php if ($s['estado'] === 'activo'): ?>
                                    <button class="btn btn-success" onclick="cerrarSeguimiento(<?= $s['id'] ?>)"><i class="fas fa-check"></i></button>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Modal Seguimiento -->
    <div class="modal fade" id="modalSeguimiento" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="modalTitle"><i class="fas fa-plus"></i> Nuevo Seguimiento</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <div class="modal-body">
                        <input type="hidden" name="accion" id="accion" value="crear_seguimiento">
                        <input type="hidden" name="id_seguimiento" id="id_seguimiento">
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label">Estudiante *</label>
                                <select name="id_estudiante" class="form-select" required>
                                    <option value="">Seleccionar</option>
                                    <?php foreach ($estudiantes as $e): ?>
                                    <option value="<?= $e['id'] ?>"><?= htmlspecialchars($e['nombre']) ?> (<?= htmlspecialchars($e['nie']) ?>)</option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Motivo *</label>
                                <select name="motivo" class="form-select" required>
                                    <?php foreach ($motivos as $k => $v): ?>
                                    <option value="<?= $k ?>"><?= $v ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Prioridad *</label>
                                <select name="prioridad" class="form-select" required>
                                    <?php foreach ($prioridades as $k => $v): ?>
                                    <option value="<?= $k ?>"><?= $v ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Fecha Inicio *</label>
                                <input type="date" name="fecha_inicio" class="form-control" required value="<?= date('Y-m-d') ?>">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Estado *</label>
                                <select name="estado" class="form-select" required>
                                    <?php foreach ($estados as $k => $v): ?>
                                    <option value="<?= $k ?>"><?= $v ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-12">
                                <label class="form-label">Descripción</label>
                                <textarea name="descripcion" class="form-control" rows="3"></textarea>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                        <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Guardar</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script>
        function prepararModal(modo) {
            $('#accion').val(modo === 'crear' ? 'crear_seguimiento' : 'actualizar_seguimiento');
            $('#id_seguimiento').val('');
            $('#modalTitle').html('<i class="fas fa-plus"></i> Nuevo Seguimiento');
        }
        
        function verSeguimiento(id) {
            alert('Ver seguimiento ID: ' + id);
        }
        
        function cerrarSeguimiento(id) {
            if (confirm('¿Cerrar este seguimiento?')) {
                $('<form>', { method: 'POST', action: 'bienestar_estudiantil.php' })
                    .append($('<input>', { type: 'hidden', name: 'accion', value: 'cerrar_seguimiento' }))
                    .append($('<input>', { type: 'hidden', name: 'id_seguimiento', value: id }))
                    .appendTo('body').submit();
            }
        }
        
        function derivarAlerta(id) {
            if (confirm('¿Derivar esta alerta a seguimiento?')) {
                $('<form>', { method: 'POST', action: 'bienestar_estudiantil.php' })
                    .append($('<input>', { type: 'hidden', name: 'accion', value: 'derivar_alerta' }))
                    .append($('<input>', { type: 'hidden', name: 'id_alerta', value: id }))
                    .append($('<input>', { type: 'hidden', name: 'id_seguimiento', value: '' }))
                    .appendTo('body').submit();
            }
        }
    </script>
</body>
</html>