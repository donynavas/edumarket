<?php
session_start();
require_once __DIR__ . '/../../config/database.php';

// Verificar autenticación
if (!isset($_SESSION['user_id']) || ($_SESSION['rol'] != 'admin' && $_SESSION['rol'] != 'director')) {
    header("Location: " . BASE_URL . "/login.php");
    exit;
}

$database = new Database();
$db = $database->getConnection();
$user_id = $_SESSION['user_id'];

$mensaje = '';
$tipo_mensaje = '';

// ===== PROCESAR FORMULARIO POST =====
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $accion = $_POST['accion'] ?? '';
    
    try {
        $db->beginTransaction();
        
        // === CREAR ESTUDIANTE ===
        if ($accion == 'crear') {
            $nie = strtoupper(trim($_POST['nie']));
            $usuario = trim($_POST['usuario']);
            $password = $_POST['clave'];
            
            if (empty($nie)) {
                throw new Exception('El NIE es obligatorio.');
            }
            
            $check = $db->prepare("SELECT id FROM tbl_estudiante WHERE nie = :nie");
            $check->bindValue(':nie', $nie, PDO::PARAM_STR);
            $check->execute();
            if ($check->fetch()) {
                throw new Exception('El NIE ya está registrado.');
            }
            
            $check = $db->prepare("SELECT id FROM tbl_usuario WHERE usuario = :usuario");
            $check->bindValue(':usuario', $usuario, PDO::PARAM_STR);
            $check->execute();
            if ($check->fetch()) {
                throw new Exception('El usuario ya está registrado.');
            }
            
            $password_hash = password_hash($password, PASSWORD_DEFAULT);
            $rol_usuario = 'estudiante';
            $email_usuario = $_POST['email'] ?? '';
            
            $query = "INSERT INTO tbl_usuario (nombre, usuario, password, email, rol, estado) 
                      VALUES (:nombre, :usuario, :password, :email, :rol, 1)";
            $stmt = $db->prepare($query);
            $stmt->bindValue(':nombre', $_POST['primer_nombre'] . ' ' . $_POST['primer_apellido'], PDO::PARAM_STR);
            $stmt->bindValue(':usuario', $usuario, PDO::PARAM_STR);
            $stmt->bindValue(':password', $password_hash, PDO::PARAM_STR);
            $stmt->bindValue(':email', $email_usuario, PDO::PARAM_STR);
            $stmt->bindValue(':rol', $rol_usuario, PDO::PARAM_STR);
            $stmt->execute();
            $id_usuario = $db->lastInsertId();
            
            $query = "INSERT INTO tbl_persona (primer_nombre, segundo_nombre, tercer_nombre, 
                      primer_apellido, segundo_apellido, dui, fecha_nacimiento, sexo, 
                      nacionalidad, direccion, telefono_fijo, celular, email, id_usuario) 
                      VALUES (:p_nombre, :s_nombre, :t_nombre, :p_apellido, :s_apellido, 
                              :dui, :fecha_nac, :sexo, :nacionalidad, :direccion, 
                              :tel_fijo, :celular, :email, :id_usuario)";
            $stmt = $db->prepare($query);
            $stmt->bindValue(':p_nombre', $_POST['primer_nombre'], PDO::PARAM_STR);
            $stmt->bindValue(':s_nombre', $_POST['segundo_nombre'] ?? '', PDO::PARAM_STR);
            $stmt->bindValue(':t_nombre', $_POST['tercer_nombre'] ?? '', PDO::PARAM_STR);
            $stmt->bindValue(':p_apellido', $_POST['primer_apellido'], PDO::PARAM_STR);
            $stmt->bindValue(':s_apellido', $_POST['segundo_apellido'] ?? '', PDO::PARAM_STR);
            $stmt->bindValue(':dui', $_POST['dui'] ?? '', PDO::PARAM_STR);
            $stmt->bindValue(':fecha_nac', $_POST['fecha_nacimiento'] ?? '', PDO::PARAM_STR);
            $stmt->bindValue(':sexo', $_POST['sexo'] ?? '', PDO::PARAM_STR);
            $stmt->bindValue(':nacionalidad', $_POST['nacionalidad'] ?? 'Salvadoreña', PDO::PARAM_STR);
            $stmt->bindValue(':direccion', $_POST['direccion'] ?? '', PDO::PARAM_STR);
            $stmt->bindValue(':tel_fijo', $_POST['telefono_fijo'] ?? '', PDO::PARAM_STR);
            $stmt->bindValue(':celular', $_POST['celular'] ?? '', PDO::PARAM_STR);
            $stmt->bindValue(':email', $email_usuario, PDO::PARAM_STR);
            $stmt->bindValue(':id_usuario', $id_usuario, PDO::PARAM_INT);
            $stmt->execute();
            $id_persona = $db->lastInsertId();
            
            $query = "INSERT INTO tbl_estudiante (id_persona, nie, estado_familiar, discapacidad, trabaja) 
                      VALUES (:id_persona, :nie, :estado_familiar, :discapacidad, :trabaja)";
            $stmt = $db->prepare($query);
            $stmt->bindValue(':id_persona', $id_persona, PDO::PARAM_INT);
            $stmt->bindValue(':nie', $nie, PDO::PARAM_STR);
            $stmt->bindValue(':estado_familiar', $_POST['estado_familiar'] ?? '', PDO::PARAM_STR);
            $stmt->bindValue(':discapacidad', $_POST['discapacidad'] ?? 'Ninguna', PDO::PARAM_STR);
            $stmt->bindValue(':trabaja', $_POST['trabaja'] ?? 0, PDO::PARAM_INT);
            $stmt->execute();
            
            $db->commit();
            $mensaje = 'Estudiante creado exitosamente';
            $tipo_mensaje = 'success';
            
        } elseif ($accion == 'actualizar') {
            $id_estudiante = $_POST['id_estudiante'];
            $nie = strtoupper(trim($_POST['nie']));
            
            if (empty($nie)) {
                throw new Exception('El NIE es obligatorio.');
            }
            
            $check = $db->prepare("SELECT id FROM tbl_estudiante WHERE nie = :nie AND id != :id");
            $check->bindValue(':nie', $nie, PDO::PARAM_STR);
            $check->bindValue(':id', $id_estudiante, PDO::PARAM_INT);
            $check->execute();
            if ($check->fetch()) {
                throw new Exception('El NIE ya está registrado por otro estudiante.');
            }
            
            $stmt = $db->prepare("SELECT id_persona FROM tbl_estudiante WHERE id = :id");
            $stmt->bindValue(':id', $id_estudiante, PDO::PARAM_INT);
            $stmt->execute();
            $persona = $stmt->fetch(PDO::FETCH_ASSOC);
            $id_persona = $persona['id_persona'];
            
            $stmt = $db->prepare("SELECT id_usuario FROM tbl_persona WHERE id = :id_persona");
            $stmt->bindValue(':id_persona', $id_persona, PDO::PARAM_INT);
            $stmt->execute();
            $usuario_data = $stmt->fetch(PDO::FETCH_ASSOC);
            $id_usuario = $usuario_data['id_usuario'];
            
            if (!empty($_POST['clave'])) {
                $password_hash = password_hash($_POST['clave'], PASSWORD_DEFAULT);
                $query = "UPDATE tbl_usuario SET usuario = :usuario, password = :password, email = :email WHERE id = :id_usuario";
                $stmt = $db->prepare($query);
                $stmt->bindValue(':usuario', $_POST['usuario'] ?? '', PDO::PARAM_STR);
                $stmt->bindValue(':password', $password_hash, PDO::PARAM_STR);
                $stmt->bindValue(':email', $_POST['email'] ?? '', PDO::PARAM_STR);
                $stmt->bindValue(':id_usuario', $id_usuario, PDO::PARAM_INT);
                $stmt->execute();
            } else {
                $query = "UPDATE tbl_usuario SET usuario = :usuario, email = :email WHERE id = :id_usuario";
                $stmt = $db->prepare($query);
                $stmt->bindValue(':usuario', $_POST['usuario'] ?? '', PDO::PARAM_STR);
                $stmt->bindValue(':email', $_POST['email'] ?? '', PDO::PARAM_STR);
                $stmt->bindValue(':id_usuario', $id_usuario, PDO::PARAM_INT);
                $stmt->execute();
            }
            
            $query = "UPDATE tbl_persona SET primer_nombre = :p_nombre, segundo_nombre = :s_nombre, 
                      tercer_nombre = :t_nombre, primer_apellido = :p_apellido, segundo_apellido = :s_apellido, 
                      dui = :dui, fecha_nacimiento = :fecha_nac, sexo = :sexo, nacionalidad = :nacionalidad, 
                      direccion = :direccion, telefono_fijo = :tel_fijo, celular = :celular, email = :email 
                      WHERE id = :id_persona";
            $stmt = $db->prepare($query);
            $stmt->bindValue(':p_nombre', $_POST['primer_nombre'], PDO::PARAM_STR);
            $stmt->bindValue(':s_nombre', $_POST['segundo_nombre'] ?? '', PDO::PARAM_STR);
            $stmt->bindValue(':t_nombre', $_POST['tercer_nombre'] ?? '', PDO::PARAM_STR);
            $stmt->bindValue(':p_apellido', $_POST['primer_apellido'], PDO::PARAM_STR);
            $stmt->bindValue(':s_apellido', $_POST['segundo_apellido'] ?? '', PDO::PARAM_STR);
            $stmt->bindValue(':dui', $_POST['dui'] ?? '', PDO::PARAM_STR);
            $stmt->bindValue(':fecha_nac', $_POST['fecha_nacimiento'] ?? '', PDO::PARAM_STR);
            $stmt->bindValue(':sexo', $_POST['sexo'] ?? '', PDO::PARAM_STR);
            $stmt->bindValue(':nacionalidad', $_POST['nacionalidad'] ?? 'Salvadoreña', PDO::PARAM_STR);
            $stmt->bindValue(':direccion', $_POST['direccion'] ?? '', PDO::PARAM_STR);
            $stmt->bindValue(':tel_fijo', $_POST['telefono_fijo'] ?? '', PDO::PARAM_STR);
            $stmt->bindValue(':celular', $_POST['celular'] ?? '', PDO::PARAM_STR);
            $stmt->bindValue(':email', $_POST['email'] ?? '', PDO::PARAM_STR);
            $stmt->bindValue(':id_persona', $id_persona, PDO::PARAM_INT);
            $stmt->execute();
            
            $query = "UPDATE tbl_estudiante SET nie = :nie, estado_familiar = :estado_familiar, 
                      discapacidad = :discapacidad, trabaja = :trabaja WHERE id = :id";
            $stmt = $db->prepare($query);
            $stmt->bindValue(':nie', $nie, PDO::PARAM_STR);
            $stmt->bindValue(':estado_familiar', $_POST['estado_familiar'] ?? '', PDO::PARAM_STR);
            $stmt->bindValue(':discapacidad', $_POST['discapacidad'] ?? 'Ninguna', PDO::PARAM_STR);
            $stmt->bindValue(':trabaja', $_POST['trabaja'] ?? 0, PDO::PARAM_INT);
            $stmt->bindValue(':id', $id_estudiante, PDO::PARAM_INT);
            $stmt->execute();
            
            $db->commit();
            $mensaje = 'Estudiante actualizado exitosamente';
            $tipo_mensaje = 'success';
            
        } elseif ($accion == 'eliminar') {
            $id_estudiante = $_POST['id_estudiante'];
            
            $query = "DELETE FROM tbl_matricula WHERE id_estudiante = :id";
            $stmt = $db->prepare($query);
            $stmt->bindValue(':id', $id_estudiante, PDO::PARAM_INT);
            $stmt->execute();
            
            $stmt = $db->prepare("SELECT id_persona FROM tbl_estudiante WHERE id = :id");
            $stmt->bindValue(':id', $id_estudiante, PDO::PARAM_INT);
            $stmt->execute();
            $persona = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($persona) {
                $stmt = $db->prepare("SELECT id_usuario FROM tbl_persona WHERE id = :id_persona");
                $stmt->bindValue(':id_persona', $persona['id_persona'], PDO::PARAM_INT);
                $stmt->execute();
                $usuario_data = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if ($usuario_data) {
                    $query_logs = "DELETE FROM tbl_logs_actividad WHERE id_usuario = :id_usuario";
                    $stmt_logs = $db->prepare($query_logs);
                    $stmt_logs->bindValue(':id_usuario', $usuario_data['id_usuario'], PDO::PARAM_INT);
                    $stmt_logs->execute();
                }
                
                $query = "DELETE FROM tbl_estudiante WHERE id = :id";
                $stmt = $db->prepare($query);
                $stmt->bindValue(':id', $id_estudiante, PDO::PARAM_INT);
                $stmt->execute();
                
                $query = "DELETE FROM tbl_persona WHERE id = :id_persona";
                $stmt = $db->prepare($query);
                $stmt->bindValue(':id_persona', $persona['id_persona'], PDO::PARAM_INT);
                $stmt->execute();
                
                if ($usuario_data) {
                    $query = "DELETE FROM tbl_usuario WHERE id = :id_usuario";
                    $stmt = $db->prepare($query);
                    $stmt->bindValue(':id_usuario', $usuario_data['id_usuario'], PDO::PARAM_INT);
                    $stmt->execute();
                }
            }
            
            $db->commit();
            $mensaje = 'Estudiante eliminado exitosamente';
            $tipo_mensaje = 'warning';
        }
        
    } catch (PDOException $e) {
        $db->rollBack();
        if ($e->errorInfo[1] == 1062) {
            $mensaje = "Registro duplicado. Verifica que el NIE o usuario no existan.";
        } else {
            $mensaje = 'Error de base de datos: ' . $e->getMessage();
        }
        $tipo_mensaje = 'danger';
    } catch (Exception $e) {
        $db->rollBack();
        $mensaje = 'Error: ' . $e->getMessage();
        $tipo_mensaje = 'danger';
    }
}

// ===== OBTENER LISTA DE ESTUDIANTES =====
$filtro_grado = $_GET['grado'] ?? '';
$filtro_seccion = $_GET['seccion'] ?? '';
$busqueda = $_GET['busqueda'] ?? '';

$query = "SELECT e.id, e.nie, e.estado_familiar, e.discapacidad, e.trabaja,
          p.primer_nombre, p.segundo_nombre, p.primer_apellido, p.segundo_apellido,
          p.dui, p.fecha_nacimiento, p.sexo, p.nacionalidad, p.direccion, 
          p.telefono_fijo, p.celular, p.email, p.id_usuario,
          u.usuario, u.rol, u.estado as estado_usuario,
          m.id_seccion, m.id_periodo, m.anno, m.estado as estado_matricula,
          g.nombre as grado_nombre, s.nombre as seccion_nombre
          FROM tbl_estudiante e
          JOIN tbl_persona p ON e.id_persona = p.id
          LEFT JOIN tbl_usuario u ON p.id_usuario = u.id
          LEFT JOIN tbl_matricula m ON e.id = m.id_estudiante AND m.estado = 'activo'
          LEFT JOIN tbl_seccion s ON m.id_seccion = s.id
          LEFT JOIN tbl_grado g ON s.id_grado = g.id
          WHERE 1=1";

$params = [];

if (!empty($filtro_grado)) {
    $query .= " AND g.id = :grado";
    $params[':grado'] = $filtro_grado;
}

if (!empty($filtro_seccion)) {
    $query .= " AND s.id = :seccion";
    $params[':seccion'] = $filtro_seccion;
}

if (!empty($busqueda)) {
    $query .= " AND (p.primer_nombre LIKE :busqueda OR p.primer_apellido LIKE :busqueda OR e.nie LIKE :busqueda OR u.usuario LIKE :busqueda)";
    $params[':busqueda'] = "%$busqueda%";
}

$query .= " ORDER BY p.primer_apellido, p.primer_nombre";

$stmt = $db->prepare($query);
foreach ($params as $key => $value) {
    $stmt->bindValue($key, $value);
}
$stmt->execute();
$estudiantes = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Obtener grados
$query = "SELECT id, nombre FROM tbl_grado ORDER BY nombre";
$grados = $db->query($query)->fetchAll(PDO::FETCH_ASSOC);

// Obtener secciones
$query = "SELECT id, nombre FROM tbl_seccion ORDER BY nombre";
$secciones = $db->query($query)->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gestión de Estudiantes - Educación Plus</title>
    
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="https://cdn.datatables.net/1.13.4/css/dataTables.bootstrap5.min.css" rel="stylesheet">
    
    <style>
        :root { --primary: #2c3e50; --secondary: #3498db; --sidebar-width: 250px; }
        body { font-family: 'Segoe UI', sans-serif; background: #f8f9fa; }
        .sidebar { position: fixed; top: 0; left: 0; height: 100vh; width: var(--sidebar-width); background: var(--primary); color: white; padding-top: 60px; z-index: 1000; }
        .sidebar .nav-link { color: rgba(255,255,255,0.8); padding: 12px 20px; }
        .sidebar .nav-link:hover, .sidebar .nav-link.active { color: white; background: rgba(255,255,255,0.1); }
        .main-content { margin-left: var(--sidebar-width); padding: 20px; }
        .card-custom { background: white; border-radius: 10px; box-shadow: 0 2px 10px rgba(0,0,0,0.08); border: none; }
        @media print {
            .sidebar, .btn, .modal-footer, .dataTables_wrapper { display: none !important; }
            .modal-content { box-shadow: none !important; border: none !important; }
            .modal-header { background: #17a2b8 !important; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
        }
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
            <a class="nav-link active" href="gestionar_estudiantes.php"><i class="fas fa-user-graduate"></i> Estudiantes</a>
            <a class="nav-link" href="gestionar_profesores.php"><i class="fas fa-chalkboard-teacher"></i> Profesores</a>
            <a class="nav-link" href="gestionar_grados.php"><i class="fas fa-layer-group"></i> Grados/Secciones</a>
            <a class="nav-link" href="gestionar_asignaturas.php"><i class="fas fa-book"></i> Asignaturas</a>
            <a class="nav-link" href="gestionar_matriculas.php"><i class="fas fa-file-signature"></i> Matrículas</a>
            <a class="nav-link" href="calificaciones.php"><i class="fas fa-star"></i> Calificaciones</a>
            <a class="nav-link" href="../../logout.php"><i class="fas fa-sign-out-alt"></i> Cerrar Sesión</a>
        </nav>
    </div>

    <!-- Main Content -->
    <div class="main-content">
        <!-- Header -->
        <div class="d-flex justify-content-between align-items-center mb-4">
            <div>
                <h2><i class="fas fa-user-graduate"></i> Gestión de Estudiantes</h2>
                <p class="text-muted mb-0">Administrar información de estudiantes</p>
            </div>
            <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#modalEstudiante" onclick="prepararModalCrear()">
                <i class="fas fa-plus"></i> Nuevo Estudiante
            </button>
        </div>

        <!-- Messages -->
        <?php if ($mensaje): ?>
        <div class="alert alert-<?= $tipo_mensaje ?> alert-dismissible fade show">
            <i class="fas fa-<?= $tipo_mensaje == 'success' ? 'check-circle' : 'exclamation-circle' ?>"></i>
            <?= htmlspecialchars($mensaje) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php endif; ?>

        <!-- Filters -->
        <div class="card-custom p-4 mb-4">
            <form method="GET" class="row g-3">
                <div class="col-md-3">
                    <label class="form-label">Grado</label>
                    <select name="grado" class="form-select">
                        <option value="">Todos</option>
                        <?php foreach ($grados as $grado): ?>
                        <option value="<?= $grado['id'] ?>" <?= $filtro_grado == $grado['id'] ? 'selected' : '' ?>><?= htmlspecialchars($grado['nombre']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Sección</label>
                    <select name="seccion" class="form-select">
                        <option value="">Todas</option>
                        <?php foreach ($secciones as $seccion): ?>
                        <option value="<?= $seccion['id'] ?>" <?= $filtro_seccion == $seccion['id'] ? 'selected' : '' ?>><?= htmlspecialchars($seccion['nombre']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label">Búsqueda</label>
                    <input type="text" name="busqueda" class="form-control" placeholder="Nombre, apellido, NIE o usuario" value="<?= htmlspecialchars($busqueda) ?>">
                </div>
                <div class="col-md-2 d-flex align-items-end">
                    <button type="submit" class="btn btn-secondary w-100"><i class="fas fa-filter"></i> Filtrar</button>
                </div>
            </form>
        </div>

        <!-- Students Table -->
        <div class="card-custom">
            <div class="card-header bg-white py-3">
                <div class="d-flex justify-content-between align-items-center">
                    <h5 class="mb-0"><i class="fas fa-list"></i> Lista de Estudiantes</h5>
                    <span class="badge bg-primary"><?= count($estudiantes) ?> estudiantes</span>
                </div>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover mb-0" id="tablaEstudiantes">
                        <thead class="table-light">
                            <tr>
                                <th>NIE</th>
                                <th>Nombre Completo</th>
                                <th>Usuario</th>
                                <th>Grado/Sección</th>
                                <th>Contacto</th>
                                <th>Estado</th>
                                <th>Acciones</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($estudiantes)): ?>
                            <tr><td colspan="7" class="text-center py-5 text-muted"><i class="fas fa-inbox fa-3x mb-3 d-block"></i>No hay estudiantes registrados</td></tr>
                            <?php else: ?>
                            <?php foreach ($estudiantes as $est): ?>
                            <tr>
                                <td><span class="badge bg-secondary"><?= htmlspecialchars($est['nie']) ?></span></td>
                                <td>
                                    <strong><?= htmlspecialchars($est['primer_nombre'] . ' ' . $est['primer_apellido']) ?></strong>
                                    <?php if ($est['segundo_nombre']): ?>
                                    <br><small class="text-muted"><?= htmlspecialchars($est['segundo_nombre']) ?></small>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($est['usuario']): ?>
                                    <span class="badge bg-info"><?= htmlspecialchars($est['usuario']) ?></span>
                                    <?php else: ?>
                                    <span class="text-muted">-</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($est['grado_nombre']): ?>
                                    <span class="badge bg-info"><?= htmlspecialchars($est['grado_nombre']) ?></span>
                                    <span class="badge bg-secondary"><?= htmlspecialchars($est['seccion_nombre']) ?></span>
                                    <?php else: ?>
                                    <span class="text-muted">Sin matrícula</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <small><i class="fas fa-phone"></i> <?= htmlspecialchars($est['celular'] ?? 'N/A') ?></small><br>
                                    <small><i class="fas fa-envelope"></i> <?= htmlspecialchars($est['email'] ?? 'N/A') ?></small>
                                </td>
                                <td>
                                    <span class="badge bg-<?= ($est['estado_usuario'] ?? 1) == 1 ? 'success' : 'secondary' ?>">
                                        <?= ($est['estado_usuario'] ?? 1) == 1 ? 'Activo' : 'Inactivo' ?>
                                    </span>
                                </td>
                                <td>
                                    <div class="btn-group btn-group-sm">
                                        <button class="btn btn-info" onclick="verEstudiante(<?= $est['id'] ?>)" title="Ver"><i class="fas fa-eye"></i></button>
                                        <button class="btn btn-warning" onclick="editarEstudiante(<?= $est['id'] ?>)" title="Editar"><i class="fas fa-edit"></i></button>
                                        <button class="btn btn-danger" onclick="eliminarEstudiante(<?= $est['id'] ?>, '<?= htmlspecialchars($est['primer_nombre'] . ' ' . $est['primer_apellido']) ?>')" title="Eliminar"><i class="fas fa-trash"></i></button>
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

    <!-- Modal Nuevo/Editar Estudiante -->
    <div class="modal fade" id="modalEstudiante" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title" id="modalTitle"><i class="fas fa-user-plus"></i> Nuevo Estudiante</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" id="formEstudiante">
                    <div class="modal-body">
                        <input type="hidden" name="accion" id="accion" value="crear">
                        <input type="hidden" name="id_estudiante" id="id_estudiante">
                        
                        <div class="alert alert-info">
                            <i class="fas fa-info-circle"></i> Los campos marcados con * son obligatorios.
                        </div>
                        
                        <h6 class="mb-3 text-primary"><i class="fas fa-user"></i> Datos Personales</h6>
                        <div class="row g-3 mb-4 p-3 bg-light rounded">
                            <div class="col-md-4">
                                <label class="form-label">NIE *</label>
                                <input type="text" name="nie" id="nie" class="form-control" required>
                                <small class="text-muted">Ingrese manualmente el NIE</small>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Primer Nombre *</label>
                                <input type="text" name="primer_nombre" id="primer_nombre" class="form-control" required>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Segundo Nombre</label>
                                <input type="text" name="segundo_nombre" id="segundo_nombre" class="form-control">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Tercer Nombre</label>
                                <input type="text" name="tercer_nombre" id="tercer_nombre" class="form-control">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Primer Apellido *</label>
                                <input type="text" name="primer_apellido" id="primer_apellido" class="form-control" required>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Segundo Apellido</label>
                                <input type="text" name="segundo_apellido" id="segundo_apellido" class="form-control">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">DUI</label>
                                <input type="text" name="dui" id="dui" class="form-control">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Fecha Nacimiento</label>
                                <input type="date" name="fecha_nacimiento" id="fecha_nacimiento" class="form-control">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Sexo</label>
                                <select name="sexo" id="sexo" class="form-select">
                                    <option value="">Seleccionar</option>
                                    <option value="M">Masculino</option>
                                    <option value="F">Femenino</option>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Email</label>
                                <input type="email" name="email" id="email" class="form-control">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Celular</label>
                                <input type="text" name="celular" id="celular" class="form-control">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Teléfono Fijo</label>
                                <input type="text" name="telefono_fijo" id="telefono_fijo" class="form-control">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Nacionalidad</label>
                                <input type="text" name="nacionalidad" id="nacionalidad" class="form-control" value="Salvadoreña">
                            </div>
                            <div class="col-12">
                                <label class="form-label">Dirección</label>
                                <input type="text" name="direccion" id="direccion" class="form-control">
                            </div>
                        </div>
                        
                        <h6 class="mb-3 text-primary"><i class="fas fa-graduation-cap"></i> Información Académica</h6>
                        <div class="row g-3 mb-4 p-3 bg-light rounded">
                            <div class="col-md-4">
                                <label class="form-label">Estado Familiar</label>
                                <select name="estado_familiar" id="estado_familiar" class="form-select">
                                    <option value="">Seleccionar</option>
                                    <option value="Convive con ambos padres">Convive con ambos padres</option>
                                    <option value="Convive con madre">Convive con madre</option>
                                    <option value="Convive con padre">Convive con padre</option>
                                    <option value="Otros">Otros</option>
                                </select>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Discapacidad</label>
                                <input type="text" name="discapacidad" id="discapacidad" class="form-control" value="Ninguna">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">¿Trabaja?</label>
                                <select name="trabaja" id="trabaja" class="form-select">
                                    <option value="0" selected>No</option>
                                    <option value="1">Sí</option>
                                </select>
                            </div>
                        </div>
                        
                        <h6 class="mb-3 text-primary"><i class="fas fa-lock"></i> Datos de Acceso</h6>
                        <div class="row g-3 p-3 bg-light rounded">
                            <div class="col-md-6">
                                <label class="form-label">Usuario *</label>
                                <input type="text" name="usuario" id="usuario" class="form-control" required minlength="3" maxlength="50">
                                <small class="text-muted">Debe ser único, mínimo 3 caracteres</small>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Contraseña <span id="passRequerido">*</span></label>
                                <input type="password" name="clave" id="clave" class="form-control" minlength="6">
                                <small class="text-muted" id="passHelp">Se guardará encriptada</small>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Confirmar Contraseña <span id="confirmRequerido">*</span></label>
                                <input type="password" name="confirmar_clave" id="confirmar_clave" class="form-control">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Rol</label>
                                <select name="rol" id="rol" class="form-select" disabled>
                                    <option value="estudiante" selected>Estudiante</option>
                                </select>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><i class="fas fa-times"></i> Cancelar</button>
                        <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Guardar Estudiante</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Modal Ver Estudiante -->
    <div class="modal fade" id="modalVerEstudiante" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header bg-info text-white">
                    <h5 class="modal-title"><i class="fas fa-user"></i> Información del Estudiante</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body" id="modalVerContent">
                    <div class="text-center py-5">
                        <div class="spinner-border text-info" role="status">
                            <span class="visually-hidden">Cargando...</span>
                        </div>
                        <p class="mt-2">Cargando información...</p>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><i class="fas fa-times"></i> Cerrar</button>
                    <button type="button" class="btn btn-primary" onclick="imprimirEstudiante()"><i class="fas fa-print"></i> Imprimir</button>
                </div>
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
            $('#tablaEstudiantes').DataTable({
                language: {
                    "sProcessing":     "Procesando...",
                    "sLengthMenu":     "Mostrar _MENU_ registros",
                    "sZeroRecords":    "No se encontraron resultados",
                    "sEmptyTable":     "Ningún dato disponible en esta tabla",
                    "sInfo":           "Mostrando registros del _START_ al _END_ de un total de _TOTAL_ registros",
                    "sInfoEmpty":      "Mostrando registros del 0 al 0 de un total de 0 registros",
                    "sInfoFiltered":   "(filtrado de un total de _MAX_ registros)",
                    "sSearch":         "Buscar:",
                    "sUrl":            "",
                    "sInfoThousands":  ",",
                    "sLoadingRecords": "Cargando...",
                    "oPaginate": {
                        "sFirst":    "Primero",
                        "sLast":     "Último",
                        "sNext":     "Siguiente",
                        "sPrevious": "Anterior"
                    }
                },
                pageLength: 10,
                order: [[0, 'asc']]
            });
            
            $('#formEstudiante').submit(function(e) {
                const accion = $('#accion').val();
                const clave = $('#clave').val();
                const confirmar = $('#confirmar_clave').val();
                
                if (accion === 'crear' || (accion === 'actualizar' && clave !== '')) {
                    if (clave !== confirmar) {
                        e.preventDefault();
                        alert('⚠️ Las contraseñas no coinciden');
                        $('#confirmar_clave').addClass('is-invalid');
                        return false;
                    }
                    if (clave.length < 6) {
                        e.preventDefault();
                        alert('⚠️ La contraseña debe tener al menos 6 caracteres');
                        $('#clave').addClass('is-invalid');
                        return false;
                    }
                }
            });
            
            $('#confirmar_clave').on('input', function() {
                const clave = $('#clave').val();
                const confirmar = $(this).val();
                if (clave !== confirmar) {
                    $(this).addClass('is-invalid').removeClass('is-valid');
                } else {
                    $(this).removeClass('is-invalid').addClass('is-valid');
                }
            });
        });
        
        function prepararModalCrear() {
            $('#accion').val('crear');
            $('#id_estudiante').val('');
            $('#modalTitle').html('<i class="fas fa-user-plus"></i> Nuevo Estudiante');
            $('#formEstudiante')[0].reset();
            $('#clave').prop('required', true);
            $('#confirmar_clave').prop('required', true);
            $('#passRequerido, #confirmRequerido').show();
            $('#nie').prop('disabled', false);
        }
        
        function editarEstudiante(id) {
            $('#modalTitle').html('<i class="fas fa-spinner fa-spin"></i> Cargando...');
            const modal = new bootstrap.Modal(document.getElementById('modalEstudiante'));
            modal.show();
            
            $.ajax({
                url: 'api/obtener_estudiante.php',
                method: 'GET',
                data: { id: id },
                dataType: 'json',
                success: function(response) {
                    if (response.success) {
                        const data = response.data;
                        $('#modalTitle').html('<i class="fas fa-user-edit"></i> Editar Estudiante');
                        $('#accion').val('actualizar');
                        $('#id_estudiante').val(data.id_estudiante);
                        
                        $('#nie').val(data.nie).prop('disabled', false);
                        $('#primer_nombre').val(data.primer_nombre);
                        $('#segundo_nombre').val(data.segundo_nombre || '');
                        $('#tercer_nombre').val(data.tercer_nombre || '');
                        $('#primer_apellido').val(data.primer_apellido);
                        $('#segundo_apellido').val(data.segundo_apellido || '');
                        $('#dui').val(data.dui || '');
                        $('#fecha_nacimiento').val(data.fecha_nacimiento || '');
                        $('#sexo').val(data.sexo || '');
                        $('#nacionalidad').val(data.nacionalidad || 'Salvadoreña');
                        $('#direccion').val(data.direccion || '');
                        $('#telefono_fijo').val(data.telefono_fijo || '');
                        $('#celular').val(data.celular || '');
                        $('#email').val(data.email || '');
                        $('#estado_familiar').val(data.estado_familiar || '');
                        $('#discapacidad').val(data.discapacidad || 'Ninguna');
                        $('#trabaja').val(data.trabaja || '0');
                        $('#usuario').val(data.usuario || '');
                        $('#clave').val('').prop('required', false);
                        $('#confirmar_clave').val('').prop('required', false);
                        $('#passRequerido, #confirmRequerido').hide();
                        $('#passHelp').text('Deje en blanco para mantener la actual');
                        
                    } else {
                        alert('❌ Error: ' + (response.error || 'No se pudo cargar'));
                        modal.hide();
                    }
                },
                error: function() {
                    alert('❌ Error al cargar los datos');
                    modal.hide();
                }
            });
        }
        
        function verEstudiante(id) {
            const modal = new bootstrap.Modal(document.getElementById('modalVerEstudiante'));
            modal.show();
            
            $('#modalVerContent').html(`
                <div class="text-center py-5">
                    <div class="spinner-border text-info" role="status"></div>
                    <p class="mt-2">Cargando...</p>
                </div>
            `);
            
            $.ajax({
                url: 'api/obtener_estudiante.php',
                method: 'GET',
                data: { id: id },
                dataType: 'json',
                success: function(response) {
                    if (response.success) {
                        const data = response.data;
                        const html = `
                            <div class="row">
                                <div class="col-md-12 text-center mb-4">
                                    <div class="rounded-circle bg-info text-white d-flex align-items-center justify-content-center mx-auto mb-3" style="width: 100px; height: 100px; font-size: 2.5rem; font-weight: bold;">
                                        ${data.primer_nombre.charAt(0)}${data.primer_apellido.charAt(0)}
                                    </div>
                                    <h4>${data.primer_nombre} ${data.primer_apellido}</h4>
                                    <p class="text-muted mb-0">NIE: <strong>${data.nie}</strong></p>
                                </div>
                            </div>
                            <div class="row">
                                <div class="col-md-12"><h6 class="border-bottom pb-2 mb-3"><i class="fas fa-user text-info"></i> Datos Personales</h6></div>
                            </div>
                            <div class="row mb-3">
                                <div class="col-md-4"><small class="text-muted d-block">Nombres</small><strong>${data.primer_nombre} ${data.segundo_nombre || ''} ${data.tercer_nombre || ''}</strong></div>
                                <div class="col-md-4"><small class="text-muted d-block">Apellidos</small><strong>${data.primer_apellido} ${data.segundo_apellido || ''}</strong></div>
                                <div class="col-md-4"><small class="text-muted d-block">DUI</small><strong>${data.dui || 'No registrado'}</strong></div>
                            </div>
                            <div class="row mb-3">
                                <div class="col-md-4"><small class="text-muted d-block">Fecha Nacimiento</small><strong>${data.fecha_nacimiento ? new Date(data.fecha_nacimiento).toLocaleDateString('es-ES') : 'No registrada'}</strong></div>
                                <div class="col-md-4"><small class="text-muted d-block">Sexo</small><strong>${data.sexo === 'M' ? 'Masculino' : data.sexo === 'F' ? 'Femenino' : 'No especificado'}</strong></div>
                                <div class="col-md-4"><small class="text-muted d-block">Nacionalidad</small><strong>${data.nacionalidad || 'No registrada'}</strong></div>
                            </div>
                            <div class="row mb-3">
                                <div class="col-md-12"><small class="text-muted d-block">Dirección</small><strong>${data.direccion || 'No registrada'}</strong></div>
                            </div>
                            <div class="row">
                                <div class="col-md-12"><h6 class="border-bottom pb-2 mb-3 mt-4"><i class="fas fa-phone text-info"></i> Contacto</h6></div>
                            </div>
                            <div class="row mb-3">
                                <div class="col-md-4"><small class="text-muted d-block"><i class="fas fa-envelope"></i> Email</small><strong>${data.email || 'No registrado'}</strong></div>
                                <div class="col-md-4"><small class="text-muted d-block"><i class="fas fa-mobile-alt"></i> Celular</small><strong>${data.celular || 'No registrado'}</strong></div>
                                <div class="col-md-4"><small class="text-muted d-block"><i class="fas fa-phone"></i> Teléfono</small><strong>${data.telefono_fijo || 'No registrado'}</strong></div>
                            </div>
                            <div class="row">
                                <div class="col-md-12"><h6 class="border-bottom pb-2 mb-3 mt-4"><i class="fas fa-graduation-cap text-info"></i> Académica</h6></div>
                            </div>
                            <div class="row mb-3">
                                <div class="col-md-4"><small class="text-muted d-block">Estado Familiar</small><strong>${data.estado_familiar || 'No registrado'}</strong></div>
                                <div class="col-md-4"><small class="text-muted d-block">Discapacidad</small><strong>${data.discapacidad || 'Ninguna'}</strong></div>
                                <div class="col-md-4"><small class="text-muted d-block">¿Trabaja?</small><strong>${data.trabaja == '1' ? 'Sí' : 'No'}</strong></div>
                            </div>
                            ${data.usuario ? `
                            <div class="row">
                                <div class="col-md-12"><h6 class="border-bottom pb-2 mb-3 mt-4"><i class="fas fa-lock text-info"></i> Acceso</h6></div>
                            </div>
                            <div class="row mb-3">
                                <div class="col-md-6"><small class="text-muted d-block">Usuario</small><strong>${data.usuario}</strong></div>
                                <div class="col-md-6"><small class="text-muted d-block">Estado</small><strong><span class="badge bg-${data.estado_usuario == '1' ? 'success' : 'secondary'}">${data.estado_usuario == '1' ? 'Activo' : 'Inactivo'}</span></strong></div>
                            </div>` : ''}
                        `;
                        $('#modalVerContent').html(html);
                    } else {
                        $('#modalVerContent').html('<div class="alert alert-danger text-center"><i class="fas fa-exclamation-triangle fa-3x mb-3"></i><h5>Error</h5><p>' + (response.error || 'No se pudo cargar') + '</p></div>');
                    }
                },
                error: function() {
                    $('#modalVerContent').html('<div class="alert alert-danger text-center"><i class="fas fa-exclamation-triangle fa-3x mb-3"></i><h5>Error</h5><p>No se pudo conectar</p></div>');
                }
            });
        }
        
        function imprimirEstudiante() {
            window.print();
        }
        
        function eliminarEstudiante(id, nombre) {
            if (confirm('¿Está seguro de eliminar a ' + nombre + '?\n\nEsta acción eliminará:\n• Datos personales\n• Información académica\n• Acceso de usuario\n\n¡No se puede deshacer!')) {
                const form = $('<form>', { method: 'POST', action: 'gestionar_estudiantes.php' });
                form.append($('<input>', { type: 'hidden', name: 'accion', value: 'eliminar' }));
                form.append($('<input>', { type: 'hidden', name: 'id_estudiante', value: id }));
                $('body').append(form);
                form.submit();
            }
        }
    </script>
</body>
</html>