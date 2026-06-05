<?php
session_start();
require_once __DIR__ . '/../../config/database.php';

// Verificar autenticación
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['rol'], ['admin', 'director'])) {
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
        
        switch($accion) {
            case 'crear':
                $nombre = trim($_POST['nombre']);
                $codigo = trim(strtoupper($_POST['codigo']));
                
                $stmt = $db->prepare("SELECT id FROM tbl_asignatura WHERE codigo = :codigo");
                $stmt->execute([':codigo' => $codigo]);
                if ($stmt->fetch()) throw new Exception('El código ya existe');
                
                $stmt = $db->prepare("INSERT INTO tbl_asignatura (nombre, codigo) VALUES (:nombre, :codigo)");
                $stmt->execute([':nombre' => $nombre, ':codigo' => $codigo]);
                $mensaje = 'Asignatura creada';
                break;
                
            case 'actualizar':
                $id = $_POST['id_asignatura'];
                $nombre = trim($_POST['nombre']);
                $codigo = trim(strtoupper($_POST['codigo']));
                
                $stmt = $db->prepare("SELECT id FROM tbl_asignatura WHERE codigo = :codigo AND id != :id");
                $stmt->execute([':codigo' => $codigo, ':id' => $id]);
                if ($stmt->fetch()) throw new Exception('El código ya existe');
                
                $stmt = $db->prepare("UPDATE tbl_asignatura SET nombre = :nombre, codigo = :codigo WHERE id = :id");
                $stmt->execute([':nombre' => $nombre, ':codigo' => $codigo, ':id' => $id]);
                $mensaje = 'Asignatura actualizada';
                break;
                
            case 'eliminar':
                $id = $_POST['id_asignatura'];
                $db->exec("DELETE act FROM tbl_actividad act JOIN tbl_asignacion_docente ad ON act.id_asignacion_docente = ad.id WHERE ad.id_asignatura = $id");
                $db->prepare("DELETE FROM tbl_asignacion_docente WHERE id_asignatura = :id")->execute([':id' => $id]);
                $db->prepare("DELETE FROM tbl_asignatura WHERE id = :id")->execute([':id' => $id]);
                $mensaje = 'Asignatura eliminada';
                $tipo_mensaje = 'warning';
                break;
                
            case 'eliminar_asignacion':
                $id = $_POST['id_asignacion'];
                $db->prepare("DELETE FROM tbl_actividad WHERE id_asignacion_docente = :id")->execute([':id' => $id]);
                $db->prepare("DELETE FROM tbl_asignacion_docente WHERE id = :id")->execute([':id' => $id]);
                $mensaje = 'Asignación eliminada';
                $tipo_mensaje = 'warning';
                break;
                
            case 'asignar_profesor':
                $stmt = $db->prepare("INSERT INTO tbl_asignacion_docente (id_profesor, id_asignatura, id_seccion, id_periodo, anno) VALUES (:prof, :asig, :sec, :per, :anno)");
                $stmt->execute([
                    ':prof' => $_POST['id_profesor'],
                    ':asig' => $_POST['id_asignatura'],
                    ':sec' => $_POST['id_seccion'],
                    ':per' => $_POST['id_periodo'],
                    ':anno' => $_POST['anno']
                ]);
                $mensaje = 'Profesor asignado';
                break;
                
            default:
                throw new Exception('Acción no válida');
        }
        
        $db->commit();
        $tipo_mensaje = $tipo_mensaje ?: 'success';
        
    } catch (Exception $e) {
        $db->rollBack();
        $mensaje = 'Error: ' . $e->getMessage();
        $tipo_mensaje = 'danger';
    }
}

// ===== OBTENER DATOS =====
$busqueda = $_GET['busqueda'] ?? '';

$query = "SELECT a.id, a.nombre, a.codigo,
          COUNT(DISTINCT ad.id) as total_asignaciones,
          COUNT(DISTINCT ad.id_profesor) as total_profesores,
          COUNT(DISTINCT act.id) as total_actividades
          FROM tbl_asignatura a
          LEFT JOIN tbl_asignacion_docente ad ON a.id = ad.id_asignatura
          LEFT JOIN tbl_actividad act ON ad.id = act.id_asignacion_docente
          WHERE 1=1";

$params = [];
if (!empty($busqueda)) {
    $query .= " AND (a.nombre LIKE :busqueda OR a.codigo LIKE :busqueda)";
    $params[':busqueda'] = "%{$busqueda}%";
}

$query .= " GROUP BY a.id ORDER BY a.nombre";
$stmt = $db->prepare($query);
foreach ($params as $k => $v) $stmt->bindValue($k, $v);
$stmt->execute();
$asignaturas = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Datos auxiliares
$profesores = $db->query("SELECT p.id, CONCAT(per.primer_nombre, ' ', per.primer_apellido) as nombre_completo, p.especialidad
                          FROM tbl_profesor p
                          JOIN tbl_persona per ON p.id_persona = per.id
                          JOIN tbl_usuario u ON per.id_usuario = u.id
                          WHERE u.estado = 1 ORDER BY per.primer_apellido")->fetchAll(PDO::FETCH_ASSOC);

$secciones = $db->query("SELECT s.id, CONCAT(g.nombre, ' - ', s.nombre) as nombre_completo
                         FROM tbl_seccion s
                         JOIN tbl_grado g ON s.id_grado = g.id
                         ORDER BY g.nombre, s.nombre")->fetchAll(PDO::FETCH_ASSOC);

$asignacionesDocentes = $db->query("SELECT ad.id, CONCAT(per.primer_nombre, ' ', per.primer_apellido) as profesor, 
                                    a.nombre as asignatura, CONCAT(g.nombre, ' - ', s.nombre) as seccion, 
                                    ad.id_periodo, ad.anno
                                    FROM tbl_asignacion_docente ad
                                    JOIN tbl_profesor p ON ad.id_profesor = p.id
                                    JOIN tbl_persona per ON p.id_persona = per.id
                                    JOIN tbl_asignatura a ON ad.id_asignatura = a.id
                                    JOIN tbl_seccion s ON ad.id_seccion = s.id
                                    JOIN tbl_grado g ON s.id_grado = g.id
                                    ORDER BY per.primer_apellido, a.nombre")->fetchAll(PDO::FETCH_ASSOC);

$periodos = [1 => 'Primer Trimestre', 2 => 'Segundo Trimestre', 3 => 'Tercer Trimestre', 4 => 'Cuarto Trimestre'];
$anno_actual = date('Y');
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gestión de Asignaturas</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="https://cdn.datatables.net/1.13.4/css/dataTables.bootstrap5.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet">
    <style>
        :root { --primary: #2c3e50; --sidebar-width: 250px; }
        body { font-family: 'Segoe UI', sans-serif; background: #f8f9fa; }
        .sidebar { position: fixed; top: 0; left: 0; height: 100vh; width: var(--sidebar-width); background: var(--primary); color: white; padding-top: 60px; z-index: 1000; }
        .sidebar .nav-link { color: rgba(255,255,255,0.8); padding: 12px 20px; }
        .sidebar .nav-link:hover, .sidebar .nav-link.active { color: white; background: rgba(255,255,255,0.15); }
        .main-content { margin-left: var(--sidebar-width); padding: 20px; }
        .card-custom { background: white; border-radius: 10px; box-shadow: 0 2px 10px rgba(0,0,0,0.08); border: none; margin-bottom: 30px; }
        .codigo-badge { background: #e3f2fd; color: #1976d2; padding: 5px 12px; border-radius: 15px; font-family: monospace; font-weight: bold; }
        @media (max-width: 768px) { .sidebar { transform: translateX(-100%); } .sidebar.active { transform: translateX(0); } .main-content { margin-left: 0; } }
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
            <a class="nav-link active" href="gestionar_asignaturas.php"><i class="fas fa-book"></i> Asignaturas</a>
            <a class="nav-link" href="gestionar_matriculas.php"><i class="fas fa-file-signature"></i> Matrículas</a>
            <a class="nav-link" href="../../logout.php"><i class="fas fa-sign-out-alt"></i> Cerrar Sesión</a>
        </nav>
    </div>

    <!-- Main Content -->
    <div class="main-content">
        <!-- Header -->
        <div class="d-flex justify-content-between align-items-center mb-4">
            <div>
                <h2><i class="fas fa-book"></i> Gestión de Asignaturas</h2>
                <p class="text-muted mb-0">Administrar materias y asignaciones</p>
            </div>
            <div>
                <button class="btn btn-primary me-2" data-bs-toggle="modal" data-bs-target="#modalAsignatura" onclick="prepararModal('crear')">
                    <i class="fas fa-plus"></i> Nueva
                </button>
                <button class="btn btn-success" data-bs-toggle="modal" data-bs-target="#modalAsignarProfesor">
                    <i class="fas fa-user-plus"></i> Asignar Profesor
                </button>
            </div>
        </div>

        <!-- Stats -->
        <div class="row mb-4">
            <div class="col-md-3"><div class="card-custom p-3 text-center"><h3 class="mb-0 text-primary"><?= count($asignaturas) ?></h3><small class="text-muted">Total</small></div></div>
            <div class="col-md-3"><div class="card-custom p-3 text-center"><h3 class="mb-0 text-success"><?= array_sum(array_column($asignaturas, 'total_asignaciones')) ?></h3><small class="text-muted">Asignaciones</small></div></div>
            <div class="col-md-3"><div class="card-custom p-3 text-center"><h3 class="mb-0 text-warning"><?= count($profesores) ?></h3><small class="text-muted">Profesores</small></div></div>
            <div class="col-md-3"><div class="card-custom p-3 text-center"><h3 class="mb-0 text-danger"><?= array_sum(array_column($asignaturas, 'total_actividades')) ?></h3><small class="text-muted">Actividades</small></div></div>
        </div>

        <!-- Messages -->
        <?php if ($mensaje): ?>
        <div class="alert alert-<?= $tipo_mensaje ?> alert-dismissible fade show">
            <i class="fas fa-<?= $tipo_mensaje === 'success' ? 'check' : ($tipo_mensaje === 'warning' ? 'exclamation-triangle' : 'times') ?>"></i>
            <?= htmlspecialchars($mensaje) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php endif; ?>

        <!-- Filters -->
        <div class="card-custom p-4">
            <form method="GET" class="row g-3">
                <div class="col-md-8">
                    <label class="form-label">Búsqueda</label>
                    <input type="text" name="busqueda" class="form-control" placeholder="Buscar por nombre o código" value="<?= htmlspecialchars($busqueda) ?>">
                </div>
                <div class="col-md-4 d-flex align-items-end gap-2">
                    <button type="submit" class="btn btn-secondary flex-fill"><i class="fas fa-search"></i> Buscar</button>
                    <a href="gestionar_asignaturas.php" class="btn btn-outline-secondary"><i class="fas fa-redo"></i></a>
                </div>
            </form>
        </div>

        <!-- Tabla Asignaturas -->
        <div class="card-custom">
            <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
                <h5 class="mb-0"><i class="fas fa-list"></i> Asignaturas</h5>
                <span class="badge bg-primary"><?= count($asignaturas) ?></span>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover mb-0" id="tablaAsignaturas">
                        <thead class="table-light">
                            <tr><th>Código</th><th>Asignatura</th><th>Profesores</th><th>Asignaciones</th><th>Actividades</th><th>Acciones</th></tr>
                        </thead>
                        <tbody>
                            <?php if (empty($asignaturas)): ?>
                            <tr><td colspan="6" class="text-center py-5 text-muted"><i class="fas fa-inbox fa-3x mb-3 d-block"></i>No hay asignaturas</td></tr>
                            <?php else: ?>
                            <?php foreach ($asignaturas as $a): ?>
                            <tr>
                                <td><span class="codigo-badge"><?= htmlspecialchars($a['codigo']) ?></span></td>
                                <td class="fw-bold"><?= htmlspecialchars($a['nombre']) ?></td>
                                <td><span class="badge bg-info"><?= $a['total_profesores'] ?></span></td>
                                <td><span class="badge bg-warning"><?= $a['total_asignaciones'] ?></span></td>
                                <td><span class="badge bg-success"><?= $a['total_actividades'] ?></span></td>
                                <td>
                                    <div class="btn-group btn-group-sm">
                                        <button class="btn btn-info" onclick="verAsignatura(<?= $a['id'] ?>)" title="Ver"><i class="fas fa-eye"></i></button>
                                        <button class="btn btn-warning" onclick="editarAsignatura(<?= $a['id'] ?>)" title="Editar"><i class="fas fa-edit"></i></button>
                                        <button class="btn btn-success" onclick="verProfesores(<?= $a['id'] ?>)" title="Profesores"><i class="fas fa-users"></i></button>
                                        <button class="btn btn-danger" onclick="eliminarAsignatura(<?= $a['id'] ?>, '<?= htmlspecialchars($a['nombre']) ?>', <?= $a['total_asignaciones'] ?>)" title="Eliminar"><i class="fas fa-trash"></i></button>
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

        <!-- Tabla Asignaciones Docentes -->
        <div class="card-custom">
            <div class="card-header bg-white py-3">
                <h5 class="mb-0"><i class="fas fa-chalkboard-teacher"></i> Asignaciones Docentes</h5>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover mb-0" id="tablaAsignaciones">
                        <thead class="table-light">
                            <tr><th>Profesor</th><th>Asignatura</th><th>Sección</th><th>Período</th><th>Año</th><th>Acciones</th></tr>
                        </thead>
                        <tbody>
                            <?php if (empty($asignacionesDocentes)): ?>
                            <tr><td colspan="6" class="text-center py-5 text-muted"><i class="fas fa-inbox fa-3x mb-3 d-block"></i>No hay asignaciones</td></tr>
                            <?php else: ?>
                            <?php foreach ($asignacionesDocentes as $ad): ?>
                            <tr>
                                <td><?= htmlspecialchars($ad['profesor']) ?></td>
                                <td><?= htmlspecialchars($ad['asignatura']) ?></td>
                                <td><?= htmlspecialchars($ad['seccion']) ?></td>
                                <td><?= $periodos[$ad['id_periodo']] ?? 'N/A' ?></td>
                                <td><?= $ad['anno'] ?></td>
                                <td><button class="btn btn-sm btn-danger" onclick="eliminarAsignacion(<?= $ad['id'] ?>, '<?= htmlspecialchars(explode(' ', $ad['profesor'])[0]) ?>', '<?= htmlspecialchars($ad['asignatura']) ?>')"><i class="fas fa-trash"></i></button></td>
                            </tr>
                            <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <!-- Modal Asignatura -->
    <div class="modal fade" id="modalAsignatura" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="modalTitleAsignatura"><i class="fas fa-plus"></i> Nueva Asignatura</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" id="formAsignatura">
                    <div class="modal-body">
                        <input type="hidden" name="accion" id="accion_asignatura" value="crear">
                        <input type="hidden" name="id_asignatura" id="id_asignatura">
                        <div class="mb-3">
                            <label class="form-label">Nombre *</label>
                            <input type="text" name="nombre" id="nombre_asignatura" class="form-control" required placeholder="Ej: Matemáticas">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Código *</label>
                            <input type="text" name="codigo" id="codigo_asignatura" class="form-control" required placeholder="Ej: MAT-001" maxlength="20">
                            <small class="text-muted">Se genera automáticamente o ingrese manualmente</small>
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

    <!-- Modal Asignar Profesor -->
    <div class="modal fade" id="modalAsignarProfesor" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header bg-success text-white">
                    <h5 class="modal-title"><i class="fas fa-user-plus"></i> Asignar Profesor</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <div class="modal-body">
                        <input type="hidden" name="accion" value="asignar_profesor">
                        <div class="mb-3">
                            <label class="form-label">Profesor *</label>
                            <select name="id_profesor" class="form-select select2" required>
                                <option value="">Seleccionar</option>
                                <?php foreach ($profesores as $p): ?>
                                <option value="<?= $p['id'] ?>"><?= htmlspecialchars($p['nombre_completo']) ?> (<?= htmlspecialchars($p['especialidad']) ?>)</option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Asignatura *</label>
                            <select name="id_asignatura" class="form-select select2" required>
                                <option value="">Seleccionar</option>
                                <?php foreach ($asignaturas as $a): ?>
                                <option value="<?= $a['id'] ?>"><?= htmlspecialchars($a['nombre']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Sección *</label>
                            <select name="id_seccion" class="form-select select2" required>
                                <option value="">Seleccionar</option>
                                <?php foreach ($secciones as $s): ?>
                                <option value="<?= $s['id'] ?>"><?= htmlspecialchars($s['nombre_completo']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Período *</label>
                                <select name="id_periodo" class="form-select" required>
                                    <?php foreach ($periodos as $k => $v): ?>
                                    <option value="<?= $k ?>"><?= $v ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Año *</label>
                                <input type="number" name="anno" class="form-control" value="<?= $anno_actual ?>" required>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                        <button type="submit" class="btn btn-success"><i class="fas fa-save"></i> Guardar</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Modal Ver -->
    <div class="modal fade" id="modalVerAsignatura" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header bg-info text-white">
                    <h5 class="modal-title"><i class="fas fa-eye"></i> Información</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body" id="infoAsignatura">
                    <div class="text-center py-5"><div class="spinner-border text-info"></div><p class="mt-2">Cargando...</p></div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cerrar</button>
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
            $('#tablaAsignaturas, #tablaAsignaciones').DataTable({
                language: { url: '//cdn.datatables.net/plug-ins/1.13.4/i18n/es-ES.json' },
                pageLength: 10, order: [[0, 'asc']], responsive: true
            });
            $('.select2').select2({ placeholder: 'Seleccionar', width: '100%' });
            $('#nombre_asignatura').on('input', function() {
                const codigo = $(this).val().toUpperCase().substring(0, 3) + '-' + Math.floor(Math.random() * 900 + 100);
                $('#codigo_asignatura').val(codigo);
            });
        });
        
        function prepararModal(modo) {
            $('#accion_asignatura').val(modo === 'crear' ? 'crear' : 'actualizar');
            $('#id_asignatura').val('');
            $('#modalTitleAsignatura').html('<i class="fas fa-plus"></i> Nueva Asignatura');
            $('#formAsignatura')[0].reset();
        }
        
        function verAsignatura(id) {
            const modal = new bootstrap.Modal($('#modalVerAsignatura'));
            modal.show();
            $('#infoAsignatura').html('<div class="text-center py-5"><div class="spinner-border text-info"></div><p class="mt-2">Cargando...</p></div>');
            
            $.ajax({
                url: 'api/get_asignatura.php',
                method: 'GET',
                 { id: id },
                dataType: 'json',
                success: function(res) {
                    if (res.success) {
                        $('#infoAsignatura').html(`
                            <div class="text-center mb-4">
                                <div class="bg-info text-white rounded-circle d-inline-flex align-items-center justify-content-center mb-3" style="width:80px;height:80px;font-size:2rem"><i class="fas fa-book"></i></div>
                                <h4>${res.data.nombre}</h4>
                                <span class="badge bg-primary">${res.data.codigo}</span>
                            </div>
                            <div class="row text-center">
                                <div class="col-4"><i class="fas fa-users fa-2x text-info mb-2"></i><h5>${res.data.total_profesores||0}</h5><small>Profesores</small></div>
                                <div class="col-4"><i class="fas fa-chalkboard-teacher fa-2x text-warning mb-2"></i><h5>${res.data.total_asignaciones||0}</h5><small>Asignaciones</small></div>
                                <div class="col-4"><i class="fas fa-tasks fa-2x text-success mb-2"></i><h5>${res.data.total_actividades||0}</h5><small>Actividades</small></div>
                            </div>
                        `);
                    } else $('#infoAsignatura').html(`<div class="alert alert-danger">${res.message||'Error'}</div>`);
                },
                error: function(xhr) {
                    console.error('Error:', xhr.responseText);
                    $('#infoAsignatura').html(`<div class="alert alert-danger">Error ${xhr.status}</div>`);
                }
            });
        }
        
        function editarAsignatura(id) {
            const modal = new bootstrap.Modal($('#modalAsignatura'));
            modal.show();
            $('#modalTitleAsignatura').html('<i class="fas fa-spinner fa-spin"></i> Cargando...');
            
            $.ajax({
                url: 'api/get_asignatura.php',
                method: 'GET',
                 { id: id, action: 'editar' },
                dataType: 'json',
                success: function(res) {
                    if (res.success) {
                        $('#accion_asignatura').val('actualizar');
                        $('#id_asignatura').val(res.data.id);
                        $('#nombre_asignatura').val(res.data.nombre);
                        $('#codigo_asignatura').val(res.data.codigo);
                        $('#modalTitleAsignatura').html('<i class="fas fa-edit"></i> Editar Asignatura');
                    } else {
                        alert('Error: ' + res.message);
                        modal.hide();
                    }
                },
                error: function(xhr) {
                    console.error('Error:', xhr.responseText);
                    alert('Error al cargar');
                    modal.hide();
                }
            });
        }
        
        function verProfesores(id) {
            const modal = new bootstrap.Modal($('#modalVerAsignatura'));
            modal.show();
            $('#infoAsignatura').html('<div class="text-center py-5"><div class="spinner-border text-info"></div><p class="mt-2">Cargando...</p></div>');
            
            $.ajax({
                url: 'api/get_profesores_asignatura.php',
                method: 'GET',
                 { id_asignatura: id },
                success: function(html) { $('#infoAsignatura').html(html); },
                error: function(xhr) {
                    console.error('Error:', xhr.responseText);
                    $('#infoAsignatura').html(`<div class="alert alert-danger">Error ${xhr.status}</div>`);
                }
            });
        }
        
        function eliminarAsignatura(id, nombre, total) {
            let msg = `¿Eliminar "${nombre}"?\n\n`;
            if (total > 0) msg += `⚠️ También eliminará:\n• ${total} asignación(es)\n• Todas las actividades\n\n`;
            if (confirm(msg)) {
                $('<form>', { method: 'POST', action: 'gestionar_asignaturas.php' })
                    .append($('<input>', { type: 'hidden', name: 'accion', value: 'eliminar' }))
                    .append($('<input>', { type: 'hidden', name: 'id_asignatura', value: id }))
                    .appendTo('body').submit();
            }
        }
        
        function eliminarAsignacion(id, prof, asig) {
            if (confirm(`¿Eliminar asignación de "${prof}" en "${asig}"?\n\nSe eliminarán las actividades asociadas.`)) {
                $('<form>', { method: 'POST', action: 'gestionar_asignaturas.php' })
                    .append($('<input>', { type: 'hidden', name: 'accion', value: 'eliminar_asignacion' }))
                    .append($('<input>', { type: 'hidden', name: 'id_asignacion', value: id }))
                    .appendTo('body').submit();
            }
        }
    </script>
</body>
</html>