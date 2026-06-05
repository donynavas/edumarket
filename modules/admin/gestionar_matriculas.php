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

$mensaje = '';
$tipo_mensaje = '';

// Procesar acciones POST
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $accion = $_POST['accion'] ?? '';
    
    try {
        $db->beginTransaction();
        
        // CREAR NUEVA MATRÍCULA
        if ($accion == 'crear') {
            // Verificar si ya existe matrícula activa
            $checkQuery = "SELECT COUNT(*) as total FROM tbl_matricula 
                          WHERE id_estudiante = :id_estudiante 
                          AND anno = :anno 
                          AND estado = 'activo'";
            $checkStmt = $db->prepare($checkQuery);
            $checkStmt->bindValue(':id_estudiante', $_POST['id_estudiante'], PDO::PARAM_INT);
            $checkStmt->bindValue(':anno', $_POST['anno'], PDO::PARAM_STR);
            $checkStmt->execute();
            $result = $checkStmt->fetch(PDO::FETCH_ASSOC);
            
            if ($result['total'] > 0) {
                throw new Exception('El estudiante ya tiene una matrícula activa para este año.');
            }
            
            $query = "INSERT INTO tbl_matricula (id_estudiante, id_seccion, id_periodo, anno, estado) 
                      VALUES (:id_estudiante, :id_seccion, :id_periodo, :anno, :estado)";
            $stmt = $db->prepare($query);
            $stmt->bindValue(':id_estudiante', $_POST['id_estudiante'], PDO::PARAM_INT);
            $stmt->bindValue(':id_seccion', $_POST['id_seccion'], PDO::PARAM_INT);
            $stmt->bindValue(':id_periodo', $_POST['id_periodo'], PDO::PARAM_INT);
            $stmt->bindValue(':anno', $_POST['anno'], PDO::PARAM_STR);
            $stmt->bindValue(':estado', $_POST['estado'] ?? 'activo', PDO::PARAM_STR);
            $stmt->execute();
            
            $db->commit();
            $mensaje = 'Matrícula creada exitosamente';
            $tipo_mensaje = 'success';
        }
        
        // ACTUALIZAR MATRÍCULA
        elseif ($accion == 'actualizar') {
            $query = "UPDATE tbl_matricula SET id_seccion = :id_seccion, id_periodo = :id_periodo, 
                      estado = :estado WHERE id = :id";
            $stmt = $db->prepare($query);
            $stmt->bindValue(':id_seccion', $_POST['id_seccion'], PDO::PARAM_INT);
            $stmt->bindValue(':id_periodo', $_POST['id_periodo'], PDO::PARAM_INT);
            $stmt->bindValue(':estado', $_POST['estado'], PDO::PARAM_STR);
            $stmt->bindValue(':id', $_POST['id_matricula'], PDO::PARAM_INT);
            $stmt->execute();
            
            $db->commit();
            $mensaje = 'Matrícula actualizada exitosamente';
            $tipo_mensaje = 'success';
        }
        
        // ELIMINAR MATRÍCULA (Marcar como retirado)
        elseif ($accion == 'eliminar') {
            $id_matricula = $_POST['id_matricula'];
            
            $query = "UPDATE tbl_matricula SET estado = 'retirado' WHERE id = :id";
            $stmt = $db->prepare($query);
            $stmt->bindValue(':id', $id_matricula, PDO::PARAM_INT);
            $stmt->execute();
            
            $db->commit();
            $mensaje = 'Matrícula marcada como retirada';
            $tipo_mensaje = 'warning';
        }
        
    } catch (Exception $e) {
        $db->rollBack();
        $mensaje = 'Error: ' . $e->getMessage();
        $tipo_mensaje = 'danger';
    }
}

// ===== FILTROS =====
$filtro_anno = $_GET['anno'] ?? date('Y');
$filtro_grado = $_GET['grado'] ?? '';
$filtro_seccion = $_GET['seccion'] ?? '';
$filtro_estado = $_GET['estado'] ?? 'activo';
$busqueda = $_GET['busqueda'] ?? '';

// ===== DEBUG INFO =====
$debug_info = [];
$debug_info['total_estudiantes'] = $db->query("SELECT COUNT(*) FROM tbl_estudiante")->fetchColumn();
$debug_info['con_matricula'] = $db->prepare("SELECT COUNT(*) FROM tbl_matricula WHERE anno = ? AND estado = 'activo'");
$debug_info['con_matricula']->execute([$filtro_anno]);
$debug_info['con_matricula'] = $debug_info['con_matricula']->fetchColumn();
$debug_info['total_secciones'] = $db->query("SELECT COUNT(*) FROM tbl_seccion")->fetchColumn();

// ===== OBTENER ESTUDIANTES SIN MATRÍCULA =====
$queryEstudiantes = "SELECT e.id, p.primer_nombre, p.segundo_nombre, p.primer_apellido, e.nie
                     FROM tbl_estudiante e
                     JOIN tbl_persona p ON e.id_persona = p.id
                     WHERE e.id NOT IN (
                         SELECT id_estudiante FROM tbl_matricula 
                         WHERE anno = :anno AND estado = 'activo'
                     )
                     ORDER BY p.primer_apellido, p.primer_nombre";

$stmtEstudiantes = $db->prepare($queryEstudiantes);
$stmtEstudiantes->execute([':anno' => $filtro_anno]);
$estudiantes_sin_matricula = $stmtEstudiantes->fetchAll(PDO::FETCH_ASSOC);

// ===== OBTENER SECCIONES =====
$query_secciones = "SELECT s.id, s.nombre, g.id as id_grado, g.nombre as grado_nombre
                   FROM tbl_seccion s
                   JOIN tbl_grado g ON s.id_grado = g.id
                   ORDER BY g.nombre, s.nombre";
$secciones = $db->query($query_secciones)->fetchAll(PDO::FETCH_ASSOC);

// ===== OBTENER GRADOS =====
$grados = $db->query("SELECT id, nombre FROM tbl_grado ORDER BY nombre")->fetchAll(PDO::FETCH_ASSOC);

// ===== OBTENER MATRÍCULAS =====
$query = "SELECT m.id, m.anno, m.estado, m.id_periodo,
          e.id as id_estudiante, e.nie,
          p.primer_nombre, p.segundo_nombre, p.primer_apellido,
          g.id as id_grado, g.nombre as grado_nombre,
          s.id as id_seccion, s.nombre as seccion_nombre
          FROM tbl_matricula m
          JOIN tbl_estudiante e ON m.id_estudiante = e.id
          JOIN tbl_persona p ON e.id_persona = p.id
          JOIN tbl_seccion s ON m.id_seccion = s.id
          JOIN tbl_grado g ON s.id_grado = g.id
          WHERE m.anno = :anno";

$params = [':anno' => $filtro_anno];

if ($filtro_estado == 'activo') {
    $query .= " AND m.estado = 'activo'";
} elseif ($filtro_estado == 'retirado') {
    $query .= " AND m.estado = 'retirado'";
}

if (!empty($filtro_grado)) {
    $query .= " AND g.id = :grado";
    $params[':grado'] = $filtro_grado;
}

if (!empty($filtro_seccion)) {
    $query .= " AND s.id = :seccion";
    $params[':seccion'] = $filtro_seccion;
}

if (!empty($busqueda)) {
    $query .= " AND (p.primer_nombre LIKE :busqueda OR p.primer_apellido LIKE :busqueda OR e.nie LIKE :busqueda)";
    $params[':busqueda'] = "%$busqueda%";
}

$query .= " ORDER BY p.primer_apellido, p.primer_nombre";

$stmt = $db->prepare($query);
foreach ($params as $key => $value) {
    $stmt->bindValue($key, $value);
}
$stmt->execute();
$matriculas = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Datos auxiliares
$periodos = [1 => 'Primer Trimestre', 2 => 'Segundo Trimestre', 3 => 'Tercer Trimestre', 4 => 'Cuarto Trimestre'];
$anios = range(date('Y') - 2, date('Y') + 1);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gestión de Matrículas - Educación Plus</title>
    
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="https://cdn.datatables.net/1.13.4/css/dataTables.bootstrap5.min.css" rel="stylesheet">
    
    <style>
        :root { --primary-color: #2c3e50; --sidebar-width: 250px; }
        body { font-family: 'Segoe UI', sans-serif; background-color: #f8f9fa; }
        .sidebar { position: fixed; top: 0; left: 0; height: 100vh; width: var(--sidebar-width); background: var(--primary-color); color: white; padding-top: 60px; z-index: 1000; }
        .sidebar .nav-link { color: rgba(255,255,255,0.8); padding: 12px 20px; }
        .sidebar .nav-link:hover, .sidebar .nav-link.active { color: white; background: rgba(255,255,255,0.1); }
        .main-content { margin-left: var(--sidebar-width); padding: 20px; }
        .card-custom { background: white; border-radius: 10px; box-shadow: 0 2px 10px rgba(0,0,0,0.08); border: none; margin-bottom: 20px; }
        .debug-box { background: #fff3cd; border: 2px solid #ffc107; padding: 15px; margin: 20px 0; border-radius: 8px; }
        .estado-badge { padding: 5px 12px; border-radius: 20px; font-size: 0.75rem; font-weight: 600; }
        .estado-activo { background: #d4edda; color: #155724; }
        .estado-retirado { background: #f8d7da; color: #721c24; }
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
            <a class="nav-link active" href="gestionar_matriculas.php"><i class="fas fa-file-signature"></i> Matrículas</a>
            <a class="nav-link" href="../../logout.php"><i class="fas fa-sign-out-alt"></i> Cerrar Sesión</a>
        </nav>
    </div>

    <!-- Main Content -->
    <div class="main-content">
        <!-- Header -->
        <div class="d-flex justify-content-between align-items-center mb-4">
            <div>
                <h2><i class="fas fa-file-signature"></i> Gestión de Matrículas</h2>
                <p class="text-muted mb-0">Administrar inscripciones de estudiantes</p>
            </div>
            <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#modalMatricula">
                <i class="fas fa-plus"></i> Nueva Matrícula
            </button>
        </div>

        <!-- DEBUG INFO -->
        <div class="debug-box">
            <h6><i class="fas fa-info-circle"></i> Información del Sistema</h6>
            <div class="row">
                <div class="col-md-3"><strong>Total Estudiantes:</strong> <?= $debug_info['total_estudiantes'] ?></div>
                <div class="col-md-3"><strong>Con Matrícula <?= $filtro_anno ?>:</strong> <?= $debug_info['con_matricula'] ?></div>
                <div class="col-md-3"><strong>Disponibles:</strong> <?= count($estudiantes_sin_matricula) ?></div>
                <div class="col-md-3"><strong>Secciones:</strong> <?= $debug_info['total_secciones'] ?></div>
            </div>
            <?php if (count($estudiantes_sin_matricula) == 0 && $debug_info['total_estudiantes'] > 0): ?>
            <div class="alert alert-warning mt-2 mb-0 small">
                <i class="fas fa-exclamation-triangle"></i> Todos los estudiantes ya tienen matrícula activa en <?= $filtro_anno ?>. 
                Cambia el año o crea más estudiantes.
            </div>
            <?php endif; ?>
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
            <div class="col-md-3">
                <div class="card-custom p-3 text-center">
                    <h3 class="mb-0"><?= count($matriculas) ?></h3>
                    <small class="text-muted">Total Matrículas</small>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card-custom p-3 text-center">
                    <h3 class="mb-0 text-success"><?= count(array_filter($matriculas, fn($m) => $m['estado'] == 'activo')) ?></h3>
                    <small class="text-muted">Activas</small>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card-custom p-3 text-center">
                    <h3 class="mb-0 text-warning"><?= count($estudiantes_sin_matricula) ?></h3>
                    <small class="text-muted">Sin Matricular</small>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card-custom p-3 text-center">
                    <h3 class="mb-0 text-danger"><?= count(array_filter($matriculas, fn($m) => $m['estado'] == 'retirado')) ?></h3>
                    <small class="text-muted">Retirados</small>
                </div>
            </div>
        </div>

        <!-- Filters -->
        <div class="card-custom p-4">
            <form method="GET" class="row g-3">
                <div class="col-md-2">
                    <label class="form-label">Año</label>
                    <select name="anno" class="form-select">
                        <?php foreach ($anios as $anio): ?>
                        <option value="<?= $anio ?>" <?= $filtro_anno == $anio ? 'selected' : '' ?>><?= $anio ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label">Grado</label>
                    <select name="grado" class="form-select">
                        <option value="">Todos</option>
                        <?php foreach ($grados as $grado): ?>
                        <option value="<?= $grado['id'] ?>" <?= $filtro_grado == $grado['id'] ? 'selected' : '' ?>><?= htmlspecialchars($grado['nombre']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label">Sección</label>
                    <select name="seccion" class="form-select">
                        <option value="">Todas</option>
                        <?php foreach ($secciones as $seccion): ?>
                        <option value="<?= $seccion['id'] ?>" <?= $filtro_seccion == $seccion['id'] ? 'selected' : '' ?>><?= htmlspecialchars($seccion['nombre']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label">Estado</label>
                    <select name="estado" class="form-select">
                        <option value="activo" <?= $filtro_estado == 'activo' ? 'selected' : '' ?>>Activos</option>
                        <option value="retirado" <?= $filtro_estado == 'retirado' ? 'selected' : '' ?>>Retirados</option>
                        <option value="todos" <?= $filtro_estado == 'todos' ? 'selected' : '' ?>>Todos</option>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Búsqueda</label>
                    <input type="text" name="busqueda" class="form-control" placeholder="Nombre o NIE" value="<?= htmlspecialchars($busqueda) ?>">
                </div>
                <div class="col-md-1 d-flex align-items-end">
                    <button type="submit" class="btn btn-secondary w-100"><i class="fas fa-filter"></i></button>
                </div>
            </form>
        </div>

        <!-- Matrículas Table -->
        <div class="card-custom">
            <div class="card-header bg-white py-3">
                <h5 class="mb-0"><i class="fas fa-list"></i> Lista de Matrículas - <?= $filtro_anno ?></h5>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover mb-0" id="tablaMatriculas">
                        <thead class="table-light">
                            <tr>
                                <th>Estudiante</th>
                                <th>NIE</th>
                                <th>Grado/Sección</th>
                                <th>Período</th>
                                <th>Estado</th>
                                <th>Acciones</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($matriculas)): ?>
                            <tr><td colspan="6" class="text-center py-5 text-muted"><i class="fas fa-inbox fa-3x mb-3 d-block"></i>No hay matrículas registradas</td></tr>
                            <?php else: ?>
                            <?php foreach ($matriculas as $mat): ?>
                            <tr>
                                <td>
                                    <div class="d-flex align-items-center">
                                        <div class="rounded-circle bg-primary text-white d-flex align-items-center justify-content-center me-2" style="width: 35px; height: 35px; font-weight: bold;">
                                            <?= strtoupper(substr($mat['primer_nombre'], 0, 1)) ?>
                                        </div>
                                        <div>
                                            <div class="fw-bold"><?= htmlspecialchars($mat['primer_nombre'] . ' ' . $mat['primer_apellido']) ?></div>
                                            <?php if ($mat['segundo_nombre']): ?>
                                            <small class="text-muted"><?= htmlspecialchars($mat['segundo_nombre']) ?></small>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </td>
                                <td><span class="badge bg-secondary"><?= htmlspecialchars($mat['nie']) ?></span></td>
                                <td>
                                    <div><strong><?= htmlspecialchars($mat['grado_nombre']) ?></strong></div>
                                    <span class="badge bg-info"><?= htmlspecialchars($mat['seccion_nombre']) ?></span>
                                </td>
                                <td><?= $periodos[$mat['id_periodo']] ?? 'N/A' ?></td>
                                <td><span class="estado-badge estado-<?= $mat['estado'] ?>"><?= ucfirst($mat['estado']) ?></span></td>
                                <td>
                                    <button class="btn btn-sm btn-danger" onclick="eliminarMatricula(<?= $mat['id'] ?>, '<?= htmlspecialchars($mat['primer_nombre']) ?>')" title="Marcar como retirado">
                                        <i class="fas fa-trash"></i>
                                    </button>
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

    <!-- Modal Nueva Matrícula -->
    <div class="modal fade" id="modalMatricula" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title"><i class="fas fa-plus"></i> Nueva Matrícula</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <div class="modal-body">
                        <input type="hidden" name="accion" value="crear">
                        
                        <!-- Estudiante -->
                        <div class="mb-3">
                            <label class="form-label">Estudiante *</label>
                            <?php if (empty($estudiantes_sin_matricula)): ?>
                            <div class="alert alert-warning small py-2">
                                <i class="fas fa-exclamation-triangle"></i> 
                                <?php if ($debug_info['total_estudiantes'] == 0): ?>
                                    No hay estudiantes registrados. <a href="gestionar_estudiantes.php" target="_blank" class="alert-link">Crear estudiante</a>
                                <?php else: ?>
                                    Todos los estudiantes ya tienen matrícula activa en <?= $filtro_anno ?>
                                <?php endif; ?>
                            </div>
                            <?php endif; ?>
                            <select name="id_estudiante" class="form-select" required <?= empty($estudiantes_sin_matricula) ? 'disabled' : '' ?>>
                                <option value="">Seleccionar estudiante...</option>
                                <?php foreach ($estudiantes_sin_matricula as $est): ?>
                                <option value="<?= $est['id'] ?>">
                                    <?= htmlspecialchars($est['primer_apellido'] . ', ' . $est['primer_nombre'] . ' ' . $est['segundo_nombre']) ?> (NIE: <?= htmlspecialchars($est['nie']) ?>)
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <!-- Sección -->
                        <div class="mb-3">
                            <label class="form-label">Sección *</label>
                            <?php if (empty($secciones)): ?>
                            <div class="alert alert-warning small py-2">
                                <i class="fas fa-exclamation-triangle"></i> No hay secciones disponibles. <a href="gestionar_grados.php" target="_blank" class="alert-link">Crear sección</a>
                            </div>
                            <?php endif; ?>
                            <select name="id_seccion" class="form-select" required <?= empty($secciones) ? 'disabled' : '' ?>>
                                <option value="">Seleccionar sección...</option>
                                <?php foreach ($secciones as $sec): ?>
                                <option value="<?= $sec['id'] ?>">
                                    <?= htmlspecialchars($sec['grado_nombre'] . ' - ' . $sec['nombre']) ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <!-- Período -->
                        <div class="mb-3">
                            <label class="form-label">Período *</label>
                            <select name="id_periodo" class="form-select" required>
                                <option value="">Seleccionar período</option>
                                <?php foreach ($periodos as $key => $val): ?>
                                <option value="<?= $key ?>"><?= $val ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <!-- Año -->
                        <div class="mb-3">
                            <label class="form-label">Año Lectivo *</label>
                            <select name="anno" class="form-select" required>
                                <?php foreach ($anios as $anio): ?>
                                <option value="<?= $anio ?>" <?= $anio == $filtro_anno ? 'selected' : '' ?>><?= $anio ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <!-- Estado -->
                        <div class="mb-3">
                            <label class="form-label">Estado *</label>
                            <select name="estado" class="form-select" required>
                                <option value="activo" selected>Activo</option>
                                <option value="inactivo">Inactivo</option>
                            </select>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                        <button type="submit" class="btn btn-primary" <?= (empty($estudiantes_sin_matricula) || empty($secciones)) ? 'disabled' : '' ?>>
                            <i class="fas fa-save"></i> Guardar Matrícula
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Scripts -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.4/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.4/js/dataTables.bootstrap5.min.js"></script>
    
    <script>
        $(document).ready(function() {
            $('#tablaMatriculas').DataTable({
                language: {
                    url: '//cdn.datatables.net/plug-ins/1.13.4/i18n/es-ES.json'
                },
                pageLength: 10,
                order: [[0, 'asc']]
            });
            
            // Toggle sidebar en móvil
            $('#sidebarToggle').click(function() {
                $('#sidebar').toggleClass('active');
            });
        });
        
        function eliminarMatricula(id, nombre) {
            if (confirm('¿Está seguro de marcar la matrícula de "' + nombre + '" como retirada?\n\nEsta acción no eliminará el registro, solo cambiará su estado a "Retirado".')) {
                const form = $('<form>', {
                    method: 'POST',
                    action: 'gestionar_matriculas.php'
                });
                form.append($('<input>', { type: 'hidden', name: 'accion', value: 'eliminar' }));
                form.append($('<input>', { type: 'hidden', name: 'id_matricula', value: id }));
                $('body').append(form);
                form.submit();
            }
        }
    </script>
</body>
</html>