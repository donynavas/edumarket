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

// ===== PROCESAR ACCIONES POST =====
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $accion = $_POST['accion'] ?? '';
    
    try {
        $db->beginTransaction();
        
        // CREAR GRADO
        if ($accion == 'crear_grado') {
            $nombre = trim($_POST['nombre']);
            $nivel = $_POST['nivel'];
            $nota_minima = $_POST['nota_minima'];
            
            $query = "INSERT INTO tbl_grado (nombre, nivel, nota_minima_aprobacion) VALUES (:nombre, :nivel, :nota_minima)";
            $stmt = $db->prepare($query);
            $stmt->execute([':nombre' => $nombre, ':nivel' => $nivel, ':nota_minima' => $nota_minima]);
            
            $db->commit();
            $mensaje = 'Grado creado exitosamente';
            $tipo_mensaje = 'success';
        }
        
        // ACTUALIZAR GRADO
        elseif ($accion == 'actualizar_grado') {
            $id = $_POST['id_grado'];
            $nombre = trim($_POST['nombre']);
            $nivel = $_POST['nivel'];
            $nota_minima = $_POST['nota_minima'];
            
            $query = "UPDATE tbl_grado SET nombre = :nombre, nivel = :nivel, nota_minima_aprobacion = :nota_minima WHERE id = :id";
            $stmt = $db->prepare($query);
            $stmt->execute([':nombre' => $nombre, ':nivel' => $nivel, ':nota_minima' => $nota_minima, ':id' => $id]);
            
            $db->commit();
            $mensaje = 'Grado actualizado exitosamente';
            $tipo_mensaje = 'success';
        }
        
        // ELIMINAR GRADO
        elseif ($accion == 'eliminar_grado') {
            $id = $_POST['id_grado'];
            
            $check = $db->prepare("SELECT COUNT(*) FROM tbl_seccion WHERE id_grado = :id");
            $check->execute([':id' => $id]);
            if ($check->fetchColumn() > 0) {
                throw new Exception('No se puede eliminar: tiene secciones asignadas.');
            }
            
            $db->prepare("DELETE FROM tbl_grado WHERE id = :id")->execute([':id' => $id]);
            $db->commit();
            $mensaje = 'Grado eliminado exitosamente';
            $tipo_mensaje = 'warning';
        }
        
        // CREAR SECCIÓN
        elseif ($accion == 'crear_seccion') {
            $nombre = trim($_POST['nombre']);
            $id_grado = $_POST['id_grado'];
            $anno = $_POST['anno_lectivo'] ?? date('Y');
            
            // Obtener o crear institución por defecto
            $id_inst = $_POST['id_institucion'] ?? null;
            if (empty($id_inst)) {
                $inst = $db->query("SELECT id FROM tbl_institucion LIMIT 1")->fetch();
                $id_inst = $inst['id'] ?? null;
                if (!$id_inst) {
                    $db->prepare("INSERT INTO tbl_institucion (nombre_ce, direccion, departamento, municipio, telefono, email) 
                                 VALUES ('Institución por Defecto', 'Dirección Temporal', 'San Salvador', 'San Salvador', '0000-0000', 'default@edu.sv')")
                       ->execute();
                    $id_inst = $db->lastInsertId();
                }
            }
            
            $query = "INSERT INTO tbl_seccion (nombre, id_grado, id_institucion, anno_lectivo) VALUES (:nombre, :grado, :inst, :anno)";
            $db->prepare($query)->execute([':nombre' => $nombre, ':grado' => $id_grado, ':inst' => $id_inst, ':anno' => $anno]);
            
            $db->commit();
            $mensaje = 'Sección creada exitosamente';
            $tipo_mensaje = 'success';
        }
        
        // ACTUALIZAR SECCIÓN
        elseif ($accion == 'actualizar_seccion') {
            $id = $_POST['id_seccion'];
            $nombre = trim($_POST['nombre']);
            $id_grado = $_POST['id_grado'];
            $anno = $_POST['anno_lectivo'];
            
            $query = "UPDATE tbl_seccion SET nombre = :nombre, id_grado = :grado, anno_lectivo = :anno WHERE id = :id";
            $db->prepare($query)->execute([':nombre' => $nombre, ':grado' => $id_grado, ':anno' => $anno, ':id' => $id]);
            
            $db->commit();
            $mensaje = 'Sección actualizada exitosamente';
            $tipo_mensaje = 'success';
        }
        
        // ELIMINAR SECCIÓN
        elseif ($accion == 'eliminar_seccion') {
            $id = $_POST['id_seccion'];
            
            $check = $db->prepare("SELECT COUNT(*) FROM tbl_matricula WHERE id_seccion = :id");
            $check->execute([':id' => $id]);
            if ($check->fetchColumn() > 0) {
                throw new Exception('No se puede eliminar: tiene estudiantes matriculados.');
            }
            
            $db->prepare("DELETE FROM tbl_seccion WHERE id = :id")->execute([':id' => $id]);
            $db->commit();
            $mensaje = 'Sección eliminada exitosamente';
            $tipo_mensaje = 'warning';
        }
        
    } catch (Exception $e) {
        $db->rollBack();
        $mensaje = 'Error: ' . $e->getMessage();
        $tipo_mensaje = 'danger';
    }
}

// ===== OBTENER DATOS =====
$filtro_nivel = $_GET['nivel'] ?? '';
$busqueda = $_GET['busqueda'] ?? '';

// Grados
$query = "SELECT g.id, g.nombre, g.nivel, g.nota_minima_aprobacion,
          COUNT(DISTINCT s.id) as total_secciones,
          COUNT(DISTINCT m.id) as total_estudiantes
          FROM tbl_grado g
          LEFT JOIN tbl_seccion s ON g.id = s.id_grado
          LEFT JOIN tbl_matricula m ON s.id = m.id_seccion AND m.estado = 'activo'
          WHERE 1=1";
$params = [];
if ($filtro_nivel) { $query .= " AND g.nivel = :nivel"; $params[':nivel'] = $filtro_nivel; }
if ($busqueda) { $query .= " AND g.nombre LIKE :busqueda"; $params[':busqueda'] = "%$busqueda%"; }
$query .= " GROUP BY g.id ORDER BY g.nivel, g.nombre";

$stmt = $db->prepare($query);
foreach ($params as $k => $v) $stmt->bindValue($k, $v);
$stmt->execute();
$grados = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Secciones
$secciones = $db->query("SELECT s.id, s.nombre, s.anno_lectivo, g.nombre as grado_nombre, g.nivel,
                        COUNT(DISTINCT m.id) as total_estudiantes
                        FROM tbl_seccion s
                        JOIN tbl_grado g ON s.id_grado = g.id
                        LEFT JOIN tbl_matricula m ON s.id = m.id_seccion AND m.estado = 'activo'
                        GROUP BY s.id ORDER BY g.nombre, s.nombre")->fetchAll(PDO::FETCH_ASSOC);

// Institución
$institucion = $db->query("SELECT id, nombre_ce FROM tbl_institucion LIMIT 1")->fetch(PDO::FETCH_ASSOC);

$niveles = ['basica' => 'Educación Básica', 'bachillerato' => 'Bachillerato'];
$anios = range(date('Y') - 2, date('Y') + 1);
$anno_actual = date('Y');
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gestión de Grados y Secciones - Educación Plus</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="https://cdn.datatables.net/1.13.4/css/dataTables.bootstrap5.min.css" rel="stylesheet">
    <style>
        :root { --primary: #2c3e50; --secondary: #3498db; --success: #2ecc71; --warning: #f39c12; --danger: #e74c3c; --sidebar-width: 250px; }
        body { font-family: 'Segoe UI', sans-serif; background: #f8f9fa; }
        .sidebar { position: fixed; top: 0; left: 0; height: 100vh; width: var(--sidebar-width); background: var(--primary); color: white; padding-top: 60px; z-index: 1000; }
        .sidebar .nav-link { color: rgba(255,255,255,0.8); padding: 12px 20px; }
        .sidebar .nav-link:hover, .sidebar .nav-link.active { color: white; background: rgba(255,255,255,0.15); }
        .main-content { margin-left: var(--sidebar-width); padding: 20px; }
        .card-custom { background: white; border-radius: 10px; box-shadow: 0 2px 10px rgba(0,0,0,0.08); border: none; margin-bottom: 24px; }
        .stats-card { background: white; border-radius: 10px; padding: 20px; box-shadow: 0 4px 15px rgba(0,0,0,0.06); border-left: 4px solid var(--secondary); }
        .stats-card.success { border-left-color: var(--success); }
        .stats-card.warning { border-left-color: var(--warning); }
        .stats-card.danger { border-left-color: var(--danger); }
        .stat-value { font-size: 2rem; font-weight: 700; color: var(--primary); }
        .stat-label { color: #666; font-size: 0.9rem; }
        .badge-nivel { padding: 5px 12px; border-radius: 20px; font-size: 0.75rem; font-weight: 600; }
        .badge-nivel.basica { background: #d4edda; color: #155724; }
        .badge-nivel.bachillerato { background: #fff3cd; color: #856404; }
        @media (max-width: 768px) { .sidebar { transform: translateX(-100%); } .sidebar.active { transform: translateX(0); } .main-content { margin-left: 0; } }
        @media print { .sidebar, .no-print, .btn { display: none !important; } .main-content { margin-left: 0; } }
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
            <a class="nav-link active" href="gestionar_grados.php"><i class="fas fa-layer-group"></i> Grados/Secciones</a>
            <a class="nav-link" href="gestionar_asignaturas.php"><i class="fas fa-book"></i> Asignaturas</a>
            <a class="nav-link" href="gestionar_matriculas.php"><i class="fas fa-file-signature"></i> Matrículas</a>
            <a class="nav-link" href="../../logout.php"><i class="fas fa-sign-out-alt"></i> Cerrar Sesión</a>
        </nav>
    </div>

    <!-- Main Content -->
    <div class="main-content">
        <!-- Header -->
        <div class="d-flex justify-content-between align-items-center mb-4">
            <div>
                <h2><i class="fas fa-layer-group"></i> Gestión de Grados y Secciones</h2>
                <p class="text-muted mb-0">Administrar estructura académica</p>
            </div>
            <div class="no-print">
                <button class="btn btn-primary me-2" data-bs-toggle="modal" data-bs-target="#modalGrado" onclick="prepararModalGrado('crear')">
                    <i class="fas fa-plus"></i> Nuevo Grado
                </button>
                <button class="btn btn-success" data-bs-toggle="modal" data-bs-target="#modalSeccion" onclick="prepararModalSeccion('crear')">
                    <i class="fas fa-plus"></i> Nueva Sección
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

        <!-- Stats -->
        <div class="row mb-4">
            <div class="col-md-3 mb-3">
                <div class="stats-card">
                    <div class="stat-value"><?= count($grados) ?></div>
                    <div class="stat-label">Total Grados</div>
                </div>
            </div>
            <div class="col-md-3 mb-3">
                <div class="stats-card success">
                    <div class="stat-value"><?= count($secciones) ?></div>
                    <div class="stat-label">Total Secciones</div>
                </div>
            </div>
            <div class="col-md-3 mb-3">
                <div class="stats-card warning">
                    <div class="stat-value"><?= count(array_filter($grados, fn($g) => $g['nivel'] == 'basica')) ?></div>
                    <div class="stat-label">Educación Básica</div>
                </div>
            </div>
            <div class="col-md-3 mb-3">
                <div class="stats-card danger">
                    <div class="stat-value"><?= count(array_filter($grados, fn($g) => $g['nivel'] == 'bachillerato')) ?></div>
                    <div class="stat-label">Bachillerato</div>
                </div>
            </div>
        </div>

        <!-- Filters -->
        <div class="card-custom p-4 no-print">
            <form method="GET" class="row g-3">
                <div class="col-md-4">
                    <label class="form-label">Nivel</label>
                    <select name="nivel" class="form-select">
                        <option value="">Todos</option>
                        <?php foreach ($niveles as $k => $v): ?>
                        <option value="<?= $k ?>" <?= $filtro_nivel == $k ? 'selected' : '' ?>><?= $v ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-5">
                    <label class="form-label">Búsqueda</label>
                    <input type="text" name="busqueda" class="form-control" placeholder="Buscar por nombre de grado" value="<?= htmlspecialchars($busqueda) ?>">
                </div>
                <div class="col-md-3 d-flex align-items-end">
                    <button type="submit" class="btn btn-secondary me-2"><i class="fas fa-filter"></i> Filtrar</button>
                    <a href="gestionar_grados.php" class="btn btn-outline-secondary"><i class="fas fa-redo"></i></a>
                </div>
            </form>
        </div>

        <!-- Tabla Grados -->
        <div class="card-custom">
            <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
                <h5 class="mb-0"><i class="fas fa-layer-group"></i> Grados Académicos</h5>
                <span class="badge bg-primary"><?= count($grados) ?> grados</span>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover mb-0" id="tablaGrados">
                        <thead class="table-light">
                            <tr>
                                <th>Grado</th>
                                <th>Nivel</th>
                                <th>Nota Mínima</th>
                                <th>Secciones</th>
                                <th>Estudiantes</th>
                                <th class="no-print">Acciones</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($grados)): ?>
                            <tr><td colspan="6" class="text-center py-5 text-muted"><i class="fas fa-inbox fa-3x mb-3 d-block"></i>No hay grados registrados</td></tr>
                            <?php else: ?>
                            <?php foreach ($grados as $g): ?>
                            <tr>
                                <td class="fw-bold"><?= htmlspecialchars($g['nombre']) ?></td>
                                <td><span class="badge-nivel <?= $g['nivel'] ?>"><?= $niveles[$g['nivel']] ?? $g['nivel'] ?></span></td>
                                <td><span class="badge <?= $g['nota_minima_aprobacion'] >= 7 ? 'bg-danger' : 'bg-warning' ?>"><?= number_format($g['nota_minima_aprobacion'], 1) ?></span></td>
                                <td><span class="badge bg-info"><?= $g['total_secciones'] ?></span></td>
                                <td><span class="badge bg-success"><?= $g['total_estudiantes'] ?></span></td>
                                <td class="no-print">
                                    <div class="btn-group btn-group-sm">
                                        <button class="btn btn-info" onclick="verGrado(<?= $g['id'] ?>)" title="Ver"><i class="fas fa-eye"></i></button>
                                        <button class="btn btn-warning" onclick="editarGrado(<?= $g['id'] ?>)" title="Editar"><i class="fas fa-edit"></i></button>
                                        <button class="btn btn-danger" onclick="eliminarGrado(<?= $g['id'] ?>)" title="Eliminar"><i class="fas fa-trash"></i></button>
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

        <!-- Tabla Secciones -->
        <div class="card-custom">
            <div class="card-header bg-white py-3">
                <h5 class="mb-0"><i class="fas fa-users"></i> Secciones</h5>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover mb-0" id="tablaSecciones">
                        <thead class="table-light">
                            <tr>
                                <th>Sección</th>
                                <th>Grado</th>
                                <th>Nivel</th>
                                <th>Año Lectivo</th>
                                <th>Estudiantes</th>
                                <th class="no-print">Acciones</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($secciones)): ?>
                            <tr><td colspan="6" class="text-center py-5 text-muted"><i class="fas fa-inbox fa-3x mb-3 d-block"></i>No hay secciones registradas</td></tr>
                            <?php else: ?>
                            <?php foreach ($secciones as $s): ?>
                            <tr>
                                <td class="fw-bold"><?= htmlspecialchars($s['nombre']) ?></td>
                                <td><?= htmlspecialchars($s['grado_nombre']) ?></td>
                                <td><span class="badge-nivel <?= $s['nivel'] ?>"><?= $niveles[$s['nivel']] ?? $s['nivel'] ?></span></td>
                                <td><?= $s['anno_lectivo'] ?></td>
                                <td><span class="badge bg-success"><?= $s['total_estudiantes'] ?></span></td>
                                <td class="no-print">
                                    <div class="btn-group btn-group-sm">
                                        <button class="btn btn-info" onclick="verSeccion(<?= $s['id'] ?>)" title="Ver"><i class="fas fa-eye"></i></button>
                                        <button class="btn btn-warning" onclick="editarSeccion(<?= $s['id'] ?>)" title="Editar"><i class="fas fa-edit"></i></button>
                                        <button class="btn btn-danger" onclick="eliminarSeccion(<?= $s['id'] ?>)" title="Eliminar"><i class="fas fa-trash"></i></button>
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

    <!-- Modal Grado -->
    <div class="modal fade" id="modalGrado" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="modalTitleGrado"><i class="fas fa-plus"></i> Nuevo Grado</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" id="formGrado">
                    <div class="modal-body">
                        <input type="hidden" name="accion" id="accion_grado" value="crear_grado">
                        <input type="hidden" name="id_grado" id="id_grado">
                        <div class="mb-3">
                            <label class="form-label">Nombre del Grado *</label>
                            <input type="text" name="nombre" id="nombre_grado" class="form-control" required placeholder="Ej: 1ro Básico">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Nivel Académico *</label>
                            <select name="nivel" id="nivel_grado" class="form-select" required onchange="actualizarNotaMinima()">
                                <option value="">Seleccionar</option>
                                <?php foreach ($niveles as $k => $v): ?><option value="<?= $k ?>"><?= $v ?></option><?php endforeach; ?>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Nota Mínima *</label>
                            <input type="number" name="nota_minima" id="nota_minima" class="form-control" step="0.1" min="0" max="10" required value="6.0">
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

    <!-- Modal Sección -->
    <div class="modal fade" id="modalSeccion" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="modalTitleSeccion"><i class="fas fa-plus"></i> Nueva Sección</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" id="formSeccion">
                    <div class="modal-body">
                        <input type="hidden" name="accion" id="accion_seccion" value="crear_seccion">
                        <input type="hidden" name="id_seccion" id="id_seccion">
                        <div class="mb-3">
                            <label class="form-label">Nombre de Sección *</label>
                            <input type="text" name="nombre" id="nombre_seccion" class="form-control" required placeholder="Ej: A, B, C">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Grado Académico *</label>
                            <select name="id_grado" id="id_grado_seccion" class="form-select" required>
                                <option value="">Seleccionar</option>
                                <?php foreach ($grados as $g): ?><option value="<?= $g['id'] ?>"><?= htmlspecialchars($g['nombre']) ?></option><?php endforeach; ?>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Año Lectivo *</label>
                            <select name="anno_lectivo" id="anno_lectivo" class="form-select" required>
                                <?php foreach ($anios as $a): ?><option value="<?= $a ?>" <?= $a == $anno_actual ? 'selected' : '' ?>><?= $a ?></option><?php endforeach; ?>
                            </select>
                        </div>
                        <?php if ($institucion): ?>
                        <input type="hidden" name="id_institucion" value="<?= $institucion['id'] ?>">
                        <div class="alert alert-info small"><i class="fas fa-info-circle"></i> Institución: <?= htmlspecialchars($institucion['nombre_ce']) ?></div>
                        <?php endif; ?>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                        <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Guardar</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Modal Ver Grado -->
    <div class="modal fade" id="modalVerGrado" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header bg-info text-white">
                    <h5 class="modal-title"><i class="fas fa-eye"></i> Información del Grado</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body" id="infoGradoContent">
                    <div class="text-center py-4"><div class="spinner-border text-info" role="status"></div><p class="mt-2">Cargando...</p></div>
                </div>
                <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cerrar</button></div>
            </div>
        </div>
    </div>

    <!-- Modal Ver Sección -->
    <div class="modal fade" id="modalVerSeccion" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header bg-info text-white">
                    <h5 class="modal-title"><i class="fas fa-eye"></i> Información de la Sección</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body" id="infoSeccionContent">
                    <div class="text-center py-4"><div class="spinner-border text-info" role="status"></div><p class="mt-2">Cargando...</p></div>
                </div>
                <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cerrar</button></div>
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
            const dtConfig = {
                language: { sProcessing: "Procesando...", sLengthMenu: "Mostrar _MENU_", sZeroRecords: "No se encontraron resultados", sEmptyTable: "Ningún dato disponible", sInfo: "Mostrando _START_ a _END_ de _TOTAL_", sInfoEmpty: "0 registros", sInfoFiltered: "(filtrado de _MAX_)", sSearch: "Buscar:", oPaginate: { sFirst: "Primero", sLast: "Último", sNext: "Siguiente", sPrevious: "Anterior" } },
                pageLength: 10, order: [[0, 'asc']], responsive: true
            };
            $('#tablaGrados, #tablaSecciones').DataTable(dtConfig);
        });
        
        function actualizarNotaMinima() {
            const nivel = $('#nivel_grado').val();
            $('#nota_minima').val(nivel === 'basica' ? '6.0' : '7.0');
        }
        
        function prepararModalGrado(modo) {
            $('#accion_grado').val(modo === 'crear' ? 'crear_grado' : 'actualizar_grado');
            $('#id_grado').val('');
            $('#modalTitleGrado').html('<i class="fas fa-plus"></i> Nuevo Grado');
            $('#formGrado')[0].reset();
            $('#nota_minima').val('6.0');
        }
        
        function prepararModalSeccion(modo) {
            $('#accion_seccion').val(modo === 'crear' ? 'crear_seccion' : 'actualizar_seccion');
            $('#id_seccion').val('');
            $('#modalTitleSeccion').html('<i class="fas fa-plus"></i> Nueva Sección');
            $('#formSeccion')[0].reset();
        }
        
        function verGrado(id) {
            const modal = new bootstrap.Modal($('#modalVerGrado'));
            modal.show();
            $('#infoGradoContent').html('<div class="text-center py-4"><div class="spinner-border text-info"></div><p class="mt-2">Cargando...</p></div>');
            
                $.getJSON('../../modules/admin/api/get_grado.php', { id: id }, function(res) {
                if (res.success) {
                    const g = res.data;
                    const nivel = g.nivel === 'basica' ? 'Educación Básica' : 'Bachillerato';
                    const icon = g.nivel === 'basica' ? 'fa-school' : 'fa-university';
                    $('#infoGradoContent').html(`
                        <div class="text-center mb-4">
                            <div class="bg-info text-white rounded-circle d-flex align-items-center justify-content-center mx-auto mb-3" style="width:80px;height:80px;font-size:2rem">
                                <i class="fas fa-layer-group"></i>
                            </div>
                            <h4>${g.nombre}</h4>
                            <span class="badge bg-${g.nivel === 'basica' ? 'success' : 'warning'}"><i class="fas ${icon}"></i> ${nivel}</span>
                        </div>
                        <div class="row g-3">
                            <div class="col-6"><div class="p-3 bg-light rounded text-center"><i class="fas fa-star text-warning fa-2x mb-2"></i><h5>${parseFloat(g.nota_minima_aprobacion).toFixed(1)}</h5><small class="text-muted">Nota Mínima</small></div></div>
                            <div class="col-6"><div class="p-3 bg-light rounded text-center"><i class="fas fa-users text-success fa-2x mb-2"></i><h5>${g.total_estudiantes||0}</h5><small class="text-muted">Estudiantes</small></div></div>
                            <div class="col-12"><div class="p-3 bg-light rounded text-center"><i class="fas fa-door-open text-primary fa-2x mb-2"></i><h5>${g.total_secciones||0}</h5><small class="text-muted">Secciones</small></div></div>
                        </div>`);
                } else {
                    $('#infoGradoContent').html(`<div class="alert alert-danger text-center"><i class="fas fa-exclamation-triangle fa-3x mb-3"></i><p>${res.message||'Error'}</p></div>`);
                }
            }).fail(() => $('#infoGradoContent').html('<div class="alert alert-danger text-center"><i class="fas fa-exclamation-triangle fa-3x mb-3"></i><p>Error de conexión</p></div>'));
        }
        
        function editarGrado(id) {
            const modal = new bootstrap.Modal($('#modalGrado'));
            modal.show();
            $('#modalTitleGrado').html('<i class="fas fa-spinner fa-spin"></i> Cargando...');
            
            $.getJSON('api/get_grado.php', { id: id, action: 'editar' }, function(res) {
                if (res.success) {
                    const g = res.data;
                    $('#accion_grado').val('actualizar_grado');
                    $('#id_grado').val(g.id);
                    $('#nombre_grado').val(g.nombre);
                    $('#nivel_grado').val(g.nivel);
                    $('#nota_minima').val(g.nota_minima_aprobacion);
                    $('#modalTitleGrado').html('<i class="fas fa-edit"></i> Editar Grado');
                } else {
                    alert('❌ ' + (res.message || 'Error al cargar'));
                    modal.hide();
                }
            }).fail(() => { alert('❌ Error de conexión'); modal.hide(); });
        }
        
        function eliminarGrado(id) {
            if (confirm('¿Eliminar este grado?\n\nNo se puede deshacer.')) {
                $('<form>', { method: 'POST', action: 'gestionar_grados.php' })
                    .append($('<input>', { type: 'hidden', name: 'accion', value: 'eliminar_grado' }))
                    .append($('<input>', { type: 'hidden', name: 'id_grado', value: id }))
                    .appendTo('body').submit();
            }
        }
        
        function verSeccion(id) {
            const modal = new bootstrap.Modal($('#modalVerSeccion'));
            modal.show();
            $('#infoSeccionContent').html('<div class="text-center py-4"><div class="spinner-border text-info"></div><p class="mt-2">Cargando...</p></div>');
            
            $.getJSON('api/get_seccion.php', { id: id }, function(res) {
                if (res.success) {
                    const s = res.data;
                    const nivel = s.nivel === 'basica' ? 'Educación Básica' : 'Bachillerato';
                    $('#infoSeccionContent').html(`
                        <div class="text-center mb-4">
                            <div class="bg-info text-white rounded-circle d-flex align-items-center justify-content-center mx-auto mb-3" style="width:80px;height:80px;font-size:2rem">
                                <i class="fas fa-users"></i>
                            </div>
                            <h4>Sección ${s.nombre}</h4>
                            <p class="text-muted mb-0">${s.grado_nombre}</p>
                            <span class="badge bg-${s.nivel === 'basica' ? 'success' : 'warning'}">${nivel}</span>
                        </div>
                        <div class="row g-3">
                            <div class="col-6"><div class="p-3 bg-light rounded text-center"><i class="fas fa-calendar text-primary fa-2x mb-2"></i><h5>${s.anno_lectivo}</h5><small class="text-muted">Año Lectivo</small></div></div>
                            <div class="col-6"><div class="p-3 bg-light rounded text-center"><i class="fas fa-user-graduate text-success fa-2x mb-2"></i><h5>${s.total_estudiantes||0}</h5><small class="text-muted">Estudiantes</small></div></div>
                        </div>`);
                } else {
                    $('#infoSeccionContent').html(`<div class="alert alert-danger text-center"><i class="fas fa-exclamation-triangle fa-3x mb-3"></i><p>${res.message||'Error'}</p></div>`);
                }
            }).fail(() => $('#infoSeccionContent').html('<div class="alert alert-danger text-center"><i class="fas fa-exclamation-triangle fa-3x mb-3"></i><p>Error de conexión</p></div>'));
        }
        
        function editarSeccion(id) {
            const modal = new bootstrap.Modal($('#modalSeccion'));
            modal.show();
            $('#modalTitleSeccion').html('<i class="fas fa-spinner fa-spin"></i> Cargando...');
            
            $.getJSON('api/get_seccion.php', { id: id, action: 'editar' }, function(res) {
                if (res.success) {
                    const s = res.data;
                    $('#accion_seccion').val('actualizar_seccion');
                    $('#id_seccion').val(s.id);
                    $('#nombre_seccion').val(s.nombre);
                    $('#id_grado_seccion').val(s.id_grado);
                    $('#anno_lectivo').val(s.anno_lectivo);
                    $('#modalTitleSeccion').html('<i class="fas fa-edit"></i> Editar Sección');
                } else {
                    alert('❌ ' + (res.message || 'Error al cargar'));
                    modal.hide();
                }
            }).fail(() => { alert('❌ Error de conexión'); modal.hide(); });
        }
        
        function eliminarSeccion(id) {
            if (confirm('¿Eliminar esta sección?\n\nNo se puede deshacer.')) {
                $('<form>', { method: 'POST', action: 'gestionar_grados.php' })
                    .append($('<input>', { type: 'hidden', name: 'accion', value: 'eliminar_seccion' }))
                    .append($('<input>', { type: 'hidden', name: 'id_seccion', value: id }))
                    .appendTo('body').submit();
            }
        }
    </script>
</body>
</html>