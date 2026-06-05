<?php
session_start();
require_once __DIR__ . '/../../config/database.php';

// Verificar autenticación y permisos
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
        
        match($accion) {
            'crear' => crearProfesor($db),
            'actualizar' => actualizarProfesor($db),
            'eliminar' => eliminarProfesor($db),
            'asignar_materias' => asignarMaterias($db),
            default => throw new Exception('Acción no válida')
        };
        
        $db->commit();
    } catch (Exception $e) {
        $db->rollBack();
        $mensaje = 'Error: ' . $e->getMessage();
        $tipo_mensaje = 'danger';
    }
}

// ===== FUNCIONES DE PROCESAMIENTO =====
function crearProfesor($db) {
    global $mensaje, $tipo_mensaje;
    
    // Validar datos requeridos
    $required = ['usuario', 'password', 'primer_nombre', 'primer_apellido', 'dui', 'fecha_nacimiento', 'sexo', 'email', 'celular', 'especialidad', 'titulo_academico'];
    foreach ($required as $field) {
        if (empty($_POST[$field])) {
            throw new Exception("El campo '$field' es requerido");
        }
    }
    
    // Verificar usuario único
    $stmt = $db->prepare("SELECT id FROM tbl_usuario WHERE usuario = :usuario");
    $stmt->execute([':usuario' => $_POST['usuario']]);
    if ($stmt->fetch()) {
        throw new Exception('El usuario ya existe');
    }
    
    // Crear usuario
    $query = "INSERT INTO tbl_usuario (usuario, password, rol, estado) VALUES (:usuario, :password, 'profesor', 1)";
    $stmt = $db->prepare($query);
    $stmt->execute([
        ':usuario' => $_POST['usuario'],
        ':password' => password_hash($_POST['password'], PASSWORD_DEFAULT)
    ]);
    $id_usuario = $db->lastInsertId();
    
    // Crear persona
    $query = "INSERT INTO tbl_persona (id_usuario, primer_nombre, segundo_nombre, primer_apellido, segundo_apellido, 
              dui, fecha_nacimiento, sexo, nacionalidad, direccion, telefono_fijo, celular, email) 
              VALUES (:id_usuario, :p_nombre, :s_nombre, :p_apellido, :s_apellido, :dui, :fecha_nac, :sexo, 
              :nacionalidad, :direccion, :tel_fijo, :celular, :email)";
    $stmt = $db->prepare($query);
    $stmt->execute([
        ':id_usuario' => $id_usuario,
        ':p_nombre' => $_POST['primer_nombre'],
        ':s_nombre' => $_POST['segundo_nombre'] ?? '',
        ':p_apellido' => $_POST['primer_apellido'],
        ':s_apellido' => $_POST['segundo_apellido'] ?? '',
        ':dui' => $_POST['dui'],
        ':fecha_nac' => $_POST['fecha_nacimiento'],
        ':sexo' => $_POST['sexo'],
        ':nacionalidad' => $_POST['nacionalidad'] ?? 'Salvadoreña',
        ':direccion' => $_POST['direccion'] ?? '',
        ':tel_fijo' => $_POST['telefono_fijo'] ?? '',
        ':celular' => $_POST['celular'],
        ':email' => $_POST['email']
    ]);
    $id_persona = $db->lastInsertId();
    
    // Crear profesor
    $query = "INSERT INTO tbl_profesor (id_persona, especialidad, titulo_academico) VALUES (:id_persona, :especialidad, :titulo)";
    $stmt = $db->prepare($query);
    $stmt->execute([
        ':id_persona' => $id_persona,
        ':especialidad' => $_POST['especialidad'],
        ':titulo' => $_POST['titulo_academico']
    ]);
    
    $mensaje = 'Profesor creado exitosamente';
    $tipo_mensaje = 'success';
}

function actualizarProfesor($db) {
    global $mensaje, $tipo_mensaje;
    
    $id_profesor = $_POST['id_profesor'] ?? 0;
    if (!$id_profesor) {
        throw new Exception('ID de profesor no válido');
    }
    
    // Actualizar persona
    $query = "UPDATE tbl_persona SET primer_nombre = :p_nombre, segundo_nombre = :s_nombre, 
              primer_apellido = :p_apellido, segundo_apellido = :s_apellido, dui = :dui, 
              fecha_nacimiento = :fecha_nac, sexo = :sexo, nacionalidad = :nacionalidad, 
              direccion = :direccion, telefono_fijo = :tel_fijo, celular = :celular, email = :email 
              WHERE id_usuario = (SELECT id_usuario FROM tbl_profesor WHERE id = :id_profesor)";
    $stmt = $db->prepare($query);
    $stmt->execute([
        ':p_nombre' => $_POST['primer_nombre'],
        ':s_nombre' => $_POST['segundo_nombre'] ?? '',
        ':p_apellido' => $_POST['primer_apellido'],
        ':s_apellido' => $_POST['segundo_apellido'] ?? '',
        ':dui' => $_POST['dui'],
        ':fecha_nac' => $_POST['fecha_nacimiento'],
        ':sexo' => $_POST['sexo'],
        ':nacionalidad' => $_POST['nacionalidad'] ?? 'Salvadoreña',
        ':direccion' => $_POST['direccion'] ?? '',
        ':tel_fijo' => $_POST['telefono_fijo'] ?? '',
        ':celular' => $_POST['celular'],
        ':email' => $_POST['email'],
        ':id_profesor' => $id_profesor
    ]);
    
    // Actualizar profesor
    $query = "UPDATE tbl_profesor SET especialidad = :especialidad, titulo_academico = :titulo WHERE id = :id_profesor";
    $stmt = $db->prepare($query);
    $stmt->execute([
        ':especialidad' => $_POST['especialidad'],
        ':titulo' => $_POST['titulo_academico'],
        ':id_profesor' => $id_profesor
    ]);
    
    // Actualizar contraseña si se proporcionó
    if (!empty($_POST['password'])) {
        $query = "UPDATE tbl_usuario u
                  JOIN tbl_persona p ON u.id = p.id_usuario
                  JOIN tbl_profesor pf ON p.id = pf.id_persona
                  SET u.password = :password WHERE pf.id = :id_profesor";
        $stmt = $db->prepare($query);
        $stmt->execute([
            ':password' => password_hash($_POST['password'], PASSWORD_DEFAULT),
            ':id_profesor' => $id_profesor
        ]);
    }
    
    $mensaje = 'Profesor actualizado exitosamente';
    $tipo_mensaje = 'success';
}

function eliminarProfesor($db) {
    global $mensaje, $tipo_mensaje;
    
    $id_profesor = $_POST['id_profesor'] ?? 0;
    if (!$id_profesor) {
        throw new Exception('ID de profesor no válido');
    }
    
    // Desactivar usuario (soft delete)
    $query = "UPDATE tbl_usuario u
              JOIN tbl_persona p ON u.id = p.id_usuario
              JOIN tbl_profesor pf ON p.id = pf.id_persona
              SET u.estado = 0 WHERE pf.id = :id_profesor";
    $stmt = $db->prepare($query);
    $stmt->execute([':id_profesor' => $id_profesor]);
    
    $mensaje = 'Profesor desactivado exitosamente';
    $tipo_mensaje = 'warning';
}

function asignarMaterias($db) {
    global $mensaje, $tipo_mensaje;
    
    $id_profesor = $_POST['id_profesor'] ?? 0;
    $asignaturas = $_POST['asignaturas'] ?? [];
    $secciones = $_POST['secciones'] ?? [];
    $periodo = $_POST['id_periodo'] ?? 1;
    $anno = $_POST['anno'] ?? date('Y');
    
    if (!$id_profesor) {
        throw new Exception('ID de profesor no válido');
    }
    
    $count = 0;
    foreach ($asignaturas as $key => $id_asignatura) {
        if (!empty($id_asignatura) && !empty($secciones[$key])) {
            $query = "INSERT INTO tbl_asignacion_docente (id_profesor, id_asignatura, id_seccion, id_periodo, anno) 
                      VALUES (:id_profesor, :id_asignatura, :id_seccion, :id_periodo, :anno)";
            $stmt = $db->prepare($query);
            $stmt->execute([
                ':id_profesor' => $id_profesor,
                ':id_asignatura' => $id_asignatura,
                ':id_seccion' => $secciones[$key],
                ':id_periodo' => $periodo,
                ':anno' => $anno
            ]);
            $count++;
        }
    }
    
    $mensaje = "$count asignaciones creadas exitosamente";
    $tipo_mensaje = 'success';
}

// ===== OBTENER DATOS =====
$filtros = [
    'especialidad' => $_GET['especialidad'] ?? '',
    'estado' => $_GET['estado'] ?? 'activo',
    'busqueda' => $_GET['busqueda'] ?? ''
];

// Obtener profesores
$query = "SELECT p.id, per.primer_nombre, per.segundo_nombre, per.primer_apellido, per.segundo_apellido, 
          per.dui, per.email, per.celular, p.especialidad, p.titulo_academico, u.estado as estado_usuario,
          COUNT(DISTINCT ad.id) as total_asignaciones,
          GROUP_CONCAT(DISTINCT a.nombre SEPARATOR ', ') as materias_nombre
          FROM tbl_profesor p
          JOIN tbl_persona per ON p.id_persona = per.id
          JOIN tbl_usuario u ON per.id_usuario = u.id
          LEFT JOIN tbl_asignacion_docente ad ON p.id = ad.id_profesor
          LEFT JOIN tbl_asignatura a ON ad.id_asignatura = a.id
          WHERE 1=1";

$params = [];

if ($filtros['estado'] === 'activo') {
    $query .= " AND u.estado = 1";
} elseif ($filtros['estado'] === 'inactivo') {
    $query .= " AND u.estado = 0";
}

if (!empty($filtros['especialidad'])) {
    $query .= " AND p.especialidad LIKE :especialidad";
    $params[':especialidad'] = "%{$filtros['especialidad']}%";
}

if (!empty($filtros['busqueda'])) {
    $query .= " AND (per.primer_nombre LIKE :busqueda OR per.primer_apellido LIKE :busqueda OR p.especialidad LIKE :busqueda)";
    $params[':busqueda'] = "%{$filtros['busqueda']}%";
}

$query .= " GROUP BY p.id ORDER BY per.primer_apellido, per.primer_nombre";

$stmt = $db->prepare($query);
foreach ($params as $key => $value) {
    $stmt->bindValue($key, $value);
}
$stmt->execute();
$profesores = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Obtener datos auxiliares
$especialidades = $db->query("SELECT DISTINCT especialidad FROM tbl_profesor WHERE especialidad IS NOT NULL AND especialidad != '' ORDER BY especialidad")->fetchAll(PDO::FETCH_COLUMN);
$asignaturas = $db->query("SELECT id, nombre, codigo FROM tbl_asignatura ORDER BY nombre")->fetchAll(PDO::FETCH_ASSOC);
$secciones = $db->query("SELECT id, nombre FROM tbl_seccion ORDER BY nombre")->fetchAll(PDO::FETCH_ASSOC);
$periodos = [1 => 'Primer Trimestre', 2 => 'Segundo Trimestre', 3 => 'Tercer Trimestre', 4 => 'Cuarto Trimestre'];
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gestión de Profesores - Educación Plus</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="https://cdn.datatables.net/1.13.4/css/dataTables.bootstrap5.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet">
    <style>
        :root { --primary: #2c3e50; --secondary: #3498db; --sidebar-width: 250px; }
        body { font-family: 'Segoe UI', sans-serif; background: #f8f9fa; }
        .sidebar { position: fixed; top: 0; left: 0; height: 100vh; width: var(--sidebar-width); background: var(--primary); color: white; padding-top: 60px; z-index: 1000; }
        .sidebar .nav-link { color: rgba(255,255,255,0.8); padding: 12px 20px; }
        .sidebar .nav-link:hover, .sidebar .nav-link.active { color: white; background: rgba(255,255,255,0.15); }
        .main-content { margin-left: var(--sidebar-width); padding: 20px; }
        .card-custom { background: white; border-radius: 10px; box-shadow: 0 2px 10px rgba(0,0,0,0.08); border: none; }
        .avatar-circle { width: 40px; height: 40px; border-radius: 50%; background: var(--secondary); color: white; display: flex; align-items: center; justify-content: center; font-weight: bold; }
        .asignatura-badge { background: #e3f2fd; color: #1976d2; padding: 3px 8px; border-radius: 15px; font-size: 0.75rem; margin: 2px; display: inline-block; }
        .status-badge { padding: 5px 12px; border-radius: 20px; font-size: 0.75rem; font-weight: 600; }
        .status-activo { background: #d4edda; color: #155724; }
        .status-inactivo { background: #f8d7da; color: #721c24; }
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
            <a class="nav-link active" href="gestionar_profesores.php"><i class="fas fa-chalkboard-teacher"></i> Profesores</a>
            <a class="nav-link" href="gestionar_grados.php"><i class="fas fa-layer-group"></i> Grados/Secciones</a>
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
                <h2><i class="fas fa-chalkboard-teacher"></i> Gestión de Profesores</h2>
                <p class="text-muted mb-0">Administrar información de docentes</p>
            </div>
            <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#modalProfesor" onclick="prepararModal('crear')">
                <i class="fas fa-plus"></i> Nuevo Profesor
            </button>
        </div>

        <!-- Stats -->
        <div class="row mb-4">
            <div class="col-md-3">
                <div class="card-custom p-3">
                    <h3 class="mb-0 text-primary"><?= count($profesores) ?></h3>
                    <small class="text-muted">Total Profesores</small>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card-custom p-3">
                    <h3 class="mb-0 text-success"><?= count(array_filter($profesores, fn($p) => $p['estado_usuario'] == 1)) ?></h3>
                    <small class="text-muted">Activos</small>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card-custom p-3">
                    <h3 class="mb-0 text-info"><?= count($asignaturas) ?></h3>
                    <small class="text-muted">Asignaturas</small>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card-custom p-3">
                    <h3 class="mb-0 text-warning"><?= count($secciones) ?></h3>
                    <small class="text-muted">Secciones</small>
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

        <!-- Filters -->
        <div class="card-custom p-4 mb-4">
            <form method="GET" class="row g-3">
                <div class="col-md-3">
                    <label class="form-label">Especialidad</label>
                    <select name="especialidad" class="form-select">
                        <option value="">Todas</option>
                        <?php foreach ($especialidades as $esp): ?>
                        <option value="<?= htmlspecialchars($esp) ?>" <?= $filtros['especialidad'] == $esp ? 'selected' : '' ?>><?= htmlspecialchars($esp) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Estado</label>
                    <select name="estado" class="form-select">
                        <option value="activo" <?= $filtros['estado'] == 'activo' ? 'selected' : '' ?>>Activos</option>
                        <option value="inactivo" <?= $filtros['estado'] == 'inactivo' ? 'selected' : '' ?>>Inactivos</option>
                        <option value="todos" <?= $filtros['estado'] == 'todos' ? 'selected' : '' ?>>Todos</option>
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label">Búsqueda</label>
                    <input type="text" name="busqueda" class="form-control" placeholder="Nombre, apellido o especialidad" value="<?= htmlspecialchars($filtros['busqueda']) ?>">
                </div>
                <div class="col-md-2 d-flex align-items-end gap-2">
                    <button type="submit" class="btn btn-secondary"><i class="fas fa-filter"></i> Filtrar</button>
                    <a href="gestionar_profesores.php" class="btn btn-outline-secondary"><i class="fas fa-redo"></i></a>
                </div>
            </form>
        </div>

        <!-- Table -->
        <div class="card-custom">
            <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
                <h5 class="mb-0"><i class="fas fa-list"></i> Lista de Profesores</h5>
                <span class="badge bg-primary"><?= count($profesores) ?> docentes</span>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover mb-0" id="tablaProfesores">
                        <thead class="table-light">
                            <tr>
                                <th>Profesor</th>
                                <th>Especialidad</th>
                                <th>Título</th>
                                <th>Materias</th>
                                <th>Contacto</th>
                                <th>Asignaciones</th>
                                <th>Estado</th>
                                <th>Acciones</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($profesores)): ?>
                            <tr><td colspan="8" class="text-center py-5 text-muted"><i class="fas fa-inbox fa-3x mb-3 d-block"></i>No hay profesores registrados</td></tr>
                            <?php else: ?>
                            <?php foreach ($profesores as $prof): ?>
                            <tr>
                                <td>
                                    <div class="d-flex align-items-center">
                                        <div class="avatar-circle me-2"><?= strtoupper(substr($prof['primer_nombre'], 0, 1)) ?></div>
                                        <div>
                                            <div class="fw-bold"><?= htmlspecialchars($prof['primer_nombre'] . ' ' . $prof['primer_apellido']) ?></div>
                                            <small class="text-muted">DUI: <?= htmlspecialchars($prof['dui']) ?></small>
                                        </div>
                                    </div>
                                </td>
                                <td><span class="badge bg-info"><?= htmlspecialchars($prof['especialidad'] ?? 'N/A') ?></span></td>
                                <td><small><?= htmlspecialchars($prof['titulo_academico'] ?? 'N/A') ?></small></td>
                                <td>
                                    <?php if (!empty($prof['materias_nombre'])): 
                                        $materias = explode(', ', $prof['materias_nombre']);
                                        foreach (array_slice($materias, 0, 2) as $materia): ?>
                                        <span class="asignatura-badge"><?= htmlspecialchars($materia) ?></span>
                                    <?php endforeach; 
                                        if (count($materias) > 2): ?>
                                        <span class="asignatura-badge">+<?= count($materias) - 2 ?></span>
                                    <?php endif; 
                                    else: ?>
                                    <span class="text-muted">Sin asignar</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <small><i class="fas fa-phone"></i> <?= htmlspecialchars($prof['celular']) ?></small><br>
                                    <small><i class="fas fa-envelope"></i> <?= htmlspecialchars($prof['email']) ?></small>
                                </td>
                                <td><span class="badge bg-warning"><?= $prof['total_asignaciones'] ?></span></td>
                                <td>
                                    <span class="status-badge <?= $prof['estado_usuario'] == 1 ? 'status-activo' : 'status-inactivo' ?>">
                                        <?= $prof['estado_usuario'] == 1 ? 'Activo' : 'Inactivo' ?>
                                    </span>
                                </td>
                                <td>
                                    <div class="btn-group btn-group-sm">
                                        <button class="btn btn-info" onclick="verProfesor(<?= $prof['id'] ?>)" title="Ver"><i class="fas fa-eye"></i></button>
                                        <button class="btn btn-warning" onclick="editarProfesor(<?= $prof['id'] ?>)" title="Editar"><i class="fas fa-edit"></i></button>
                                        <button class="btn btn-success" onclick="asignarMaterias(<?= $prof['id'] ?>)" title="Asignar"><i class="fas fa-book"></i></button>
                                        <button class="btn btn-danger" onclick="eliminarProfesor(<?= $prof['id'] ?>, '<?= htmlspecialchars($prof['primer_nombre'] . ' ' . $prof['primer_apellido']) ?>')" title="Eliminar"><i class="fas fa-trash"></i></button>
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

    <!-- Modal Profesor (Crear/Editar) -->
    <div class="modal fade" id="modalProfesor" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="modalTitle"><i class="fas fa-user-plus"></i> Nuevo Profesor</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" id="formProfesor">
                    <div class="modal-body">
                        <input type="hidden" name="accion" id="accion" value="crear">
                        <input type="hidden" name="id_profesor" id="id_profesor">
                        
                        <ul class="nav nav-tabs mb-3">
                            <li class="nav-item"><button class="nav-link active" data-bs-toggle="tab" data-bs-target="#datos-personales">Datos Personales</button></li>
                            <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#datos-academicos">Académicos</button></li>
                            <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#datos-contacto">Contacto</button></li>
                            <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#datos-cuenta">Cuenta</button></li>
                        </ul>
                        
                        <div class="tab-content">
                            <div class="tab-pane fade show active" id="datos-personales">
                                <div class="row g-3">
                                    <div class="col-md-6"><label class="form-label">Primer Nombre *</label><input type="text" name="primer_nombre" id="primer_nombre" class="form-control" required></div>
                                    <div class="col-md-6"><label class="form-label">Segundo Nombre</label><input type="text" name="segundo_nombre" id="segundo_nombre" class="form-control"></div>
                                    <div class="col-md-6"><label class="form-label">Primer Apellido *</label><input type="text" name="primer_apellido" id="primer_apellido" class="form-control" required></div>
                                    <div class="col-md-6"><label class="form-label">Segundo Apellido</label><input type="text" name="segundo_apellido" id="segundo_apellido" class="form-control"></div>
                                    <div class="col-md-6"><label class="form-label">DUI *</label><input type="text" name="dui" id="dui" class="form-control" required></div>
                                    <div class="col-md-6"><label class="form-label">Fecha Nacimiento *</label><input type="date" name="fecha_nacimiento" id="fecha_nacimiento" class="form-control" required></div>
                                    <div class="col-md-6"><label class="form-label">Sexo *</label><select name="sexo" id="sexo" class="form-select" required><option value="">Seleccionar</option><option value="M">Masculino</option><option value="F">Femenino</option></select></div>
                                    <div class="col-md-6"><label class="form-label">Nacionalidad</label><input type="text" name="nacionalidad" id="nacionalidad" class="form-control" value="Salvadoreña"></div>
                                    <div class="col-12"><label class="form-label">Dirección</label><textarea name="direccion" id="direccion" class="form-control" rows="2"></textarea></div>
                                </div>
                            </div>
                            
                            <div class="tab-pane fade" id="datos-academicos">
                                <div class="row g-3">
                                    <div class="col-md-6"><label class="form-label">Especialidad *</label><input type="text" name="especialidad" id="especialidad" class="form-control" required></div>
                                    <div class="col-md-6"><label class="form-label">Título Académico *</label><input type="text" name="titulo_academico" id="titulo_academico" class="form-control" required></div>
                                </div>
                            </div>
                            
                            <div class="tab-pane fade" id="datos-contacto">
                                <div class="row g-3">
                                    <div class="col-md-6"><label class="form-label">Email *</label><input type="email" name="email" id="email" class="form-control" required></div>
                                    <div class="col-md-6"><label class="form-label">Celular *</label><input type="text" name="celular" id="celular" class="form-control" required></div>
                                    <div class="col-md-6"><label class="form-label">Teléfono Fijo</label><input type="text" name="telefono_fijo" id="telefono_fijo" class="form-control"></div>
                                </div>
                            </div>
                            
                            <div class="tab-pane fade" id="datos-cuenta">
                                <div class="row g-3">
                                    <div class="col-md-6"><label class="form-label">Usuario *</label><input type="text" name="usuario" id="usuario" class="form-control" required></div>
                                    <div class="col-md-6"><label class="form-label">Contraseña <span id="label_password">*</span></label><input type="password" name="password" id="password" class="form-control"></div>
                                    <div class="col-md-6"><label class="form-label">Confirmar Contraseña <span id="label_confirm">*</span></label><input type="password" name="password_confirm" id="password_confirm" class="form-control"></div>
                                </div>
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

    <!-- Modal Ver Profesor -->
    <div class="modal fade" id="modalVerProfesor" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header bg-info text-white">
                    <h5 class="modal-title"><i class="fas fa-user"></i> Información del Profesor</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body" id="infoProfesor">
                    <div class="text-center py-5"><div class="spinner-border text-info" role="status"></div><p class="mt-2">Cargando...</p></div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cerrar</button>
                    <button type="button" class="btn btn-primary" onclick="window.print()"><i class="fas fa-print"></i> Imprimir</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Modal Asignar Materias -->
    <div class="modal fade" id="modalAsignarMaterias" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header bg-success text-white">
                    <h5 class="modal-title"><i class="fas fa-book"></i> Asignar Materias</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <div class="modal-body">
                        <input type="hidden" name="accion" value="asignar_materias">
                        <input type="hidden" name="id_profesor" id="asignar_id_profesor">
                        <div class="row g-3 mb-3">
                            <div class="col-md-6">
                                <label class="form-label">Período</label>
                                <select name="id_periodo" class="form-select">
                                    <?php foreach ($periodos as $key => $val): ?>
                                    <option value="<?= $key ?>"><?= $val ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Año Lectivo</label>
                                <input type="number" name="anno" class="form-control" value="<?= date('Y') ?>">
                            </div>
                        </div>
                        <hr>
                        <h6 class="mb-3">Asignaturas y Secciones</h6>
                        <div id="asignaturasContainer">
                            <div class="asignatura-row mb-3 p-3 border rounded">
                                <div class="row g-2">
                                    <div class="col-md-5">
                                        <label class="form-label">Asignatura</label>
                                        <select name="asignaturas[]" class="form-select select-asignatura">
                                            <option value="">Seleccionar</option>
                                            <?php foreach ($asignaturas as $asig): ?>
                                            <option value="<?= $asig['id'] ?>"><?= htmlspecialchars($asig['nombre']) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="col-md-5">
                                        <label class="form-label">Sección</label>
                                        <select name="secciones[]" class="form-select select-seccion">
                                            <option value="">Seleccionar</option>
                                            <?php foreach ($secciones as $sec): ?>
                                            <option value="<?= $sec['id'] ?>"><?= htmlspecialchars($sec['nombre']) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="col-md-2 d-flex align-items-end">
                                        <button type="button" class="btn btn-danger w-100" onclick="removeAsignatura(this)"><i class="fas fa-trash"></i></button>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <button type="button" class="btn btn-outline-primary" onclick="addAsignatura()"><i class="fas fa-plus"></i> Agregar Otra</button>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                        <button type="submit" class="btn btn-success"><i class="fas fa-save"></i> Guardar</button>
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
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
    <script>
        $(document).ready(function() {
            $('#tablaProfesores').DataTable({
                language: { url: '//cdn.datatables.net/plug-ins/1.13.4/i18n/es-ES.json' },
                pageLength: 10, order: [[0, 'asc']]
            });
            
            $('#formProfesor').submit(function(e) {
                const accion = $('#accion').val();
                const password = $('#password').val();
                const confirm = $('#password_confirm').val();
                
                if (accion === 'crear' || (accion === 'actualizar' && password !== '')) {
                    if (password !== confirm) {
                        e.preventDefault();
                        alert('❌ Las contraseñas no coinciden');
                        $('#password_confirm').addClass('is-invalid');
                        return false;
                    }
                    if (password.length < 6) {
                        e.preventDefault();
                        alert('❌ La contraseña debe tener al menos 6 caracteres');
                        $('#password').addClass('is-invalid');
                        return false;
                    }
                }
            });
            
            $('#password, #password_confirm').on('input', function() {
                $(this).removeClass('is-invalid');
            });
        });
        
        function prepararModal(modo) {
            $('#accion').val(modo === 'crear' ? 'crear' : 'actualizar');
            $('#id_profesor').val('');
            $('#modalTitle').html('<i class="fas fa-user-plus"></i> Nuevo Profesor');
            $('#formProfesor')[0].reset();
            $('#password, #password_confirm').prop('required', true);
            $('#label_password, #label_confirm').show();
        }
        
        function verProfesor(id) {
            const modal = new bootstrap.Modal($('#modalVerProfesor'));
            modal.show();
            $('#infoProfesor').html('<div class="text-center py-5"><div class="spinner-border text-info" role="status"></div><p class="mt-2">Cargando...</p></div>');
            
            $.ajax({
                url: 'api/get_profesor.php',
                method: 'GET',
                data: { id: id, action: 'ver' },
                dataType: 'json',
                success: function(res) {
                    if (res.success) {
                        const p = res.data;
                        $('#infoProfesor').html(`
                            <div class="text-center mb-4">
                                <div class="rounded-circle bg-info text-white d-flex align-items-center justify-content-center mx-auto mb-3" style="width:100px;height:100px;font-size:2.5rem;font-weight:bold">
                                    ${p.primer_nombre.charAt(0)}${p.primer_apellido.charAt(0)}
                                </div>
                                <h4>${p.primer_nombre} ${p.primer_apellido}</h4>
                                <p class="text-muted">${p.especialidad || 'Sin especialidad'}</p>
                                <span class="badge bg-${p.estado_usuario == 1 ? 'success' : 'secondary'}">${p.estado_usuario == 1 ? 'Activo' : 'Inactivo'}</span>
                            </div>
                            <div class="row"><div class="col-12"><h6 class="border-bottom pb-2 mb-3"><i class="fas fa-user text-info"></i> Datos Personales</h6></div></div>
                            <div class="row mb-3">
                                <div class="col-md-6"><small class="text-muted d-block">Nombres</small><strong>${p.primer_nombre} ${p.segundo_nombre || ''}</strong></div>
                                <div class="col-md-6"><small class="text-muted d-block">Apellidos</small><strong>${p.primer_apellido} ${p.segundo_apellido || ''}</strong></div>
                            </div>
                            <div class="row mb-3">
                                <div class="col-md-4"><small class="text-muted d-block">DUI</small><strong>${p.dui || 'No registrado'}</strong></div>
                                <div class="col-md-4"><small class="text-muted d-block">Fecha Nacimiento</small><strong>${p.fecha_nacimiento ? new Date(p.fecha_nacimiento).toLocaleDateString('es-ES') : 'No registrada'}</strong></div>
                                <div class="col-md-4"><small class="text-muted d-block">Sexo</small><strong>${p.sexo === 'M' ? 'Masculino' : p.sexo === 'F' ? 'Femenino' : 'No especificado'}</strong></div>
                            </div>
                            <div class="row"><div class="col-12"><h6 class="border-bottom pb-2 mb-3 mt-4"><i class="fas fa-graduation-cap text-info"></i> Datos Académicos</h6></div></div>
                            <div class="row mb-3">
                                <div class="col-md-6"><small class="text-muted d-block">Especialidad</small><strong>${p.especialidad || 'No registrada'}</strong></div>
                                <div class="col-md-6"><small class="text-muted d-block">Título Académico</small><strong>${p.titulo_academico || 'No registrado'}</strong></div>
                            </div>
                            <div class="row"><div class="col-12"><h6 class="border-bottom pb-2 mb-3 mt-4"><i class="fas fa-address-card text-info"></i> Contacto</h6></div></div>
                            <div class="row mb-3">
                                <div class="col-md-6"><small class="text-muted d-block"><i class="fas fa-envelope"></i> Email</small><strong>${p.email || 'No registrado'}</strong></div>
                                <div class="col-md-6"><small class="text-muted d-block"><i class="fas fa-phone"></i> Celular</small><strong>${p.celular || 'No registrado'}</strong></div>
                            </div>
                            ${p.telefono_fijo ? `<div class="row mb-3"><div class="col-md-6"><small class="text-muted d-block"><i class="fas fa-phone"></i> Teléfono Fijo</small><strong>${p.telefono_fijo}</strong></div></div>` : ''}
                        `);
                    } else {
                        $('#infoProfesor').html(`<div class="alert alert-danger text-center"><i class="fas fa-exclamation-triangle fa-3x mb-3"></i><p>${res.message || 'Error al cargar'}</p></div>`);
                    }
                },
                error: function(xhr, status, error) {
                    console.error('Error:', error, xhr.responseText);
                    $('#infoProfesor').html(`<div class="alert alert-danger text-center"><i class="fas fa-exclamation-triangle fa-3x mb-3"></i><p>Error de conexión<br><small>${xhr.status} ${xhr.statusText}</small></p></div>`);
                }
            });
        }
        
        function editarProfesor(id) {
            const modal = new bootstrap.Modal($('#modalProfesor'));
            modal.show();
            $('#modalTitle').html('<i class="fas fa-spinner fa-spin"></i> Cargando...');
            
            $.ajax({
                url: 'api/get_profesor.php',
                method: 'GET',
                data: { id: id, action: 'editar' },
                dataType: 'json',
                success: function(res) {
                    if (res.success) {
                        const p = res.data;
                        $('#accion').val('actualizar');
                        $('#id_profesor').val(id);
                        $('#modalTitle').html('<i class="fas fa-edit"></i> Editar Profesor');
                        
                        $('#primer_nombre').val(p.primer_nombre);
                        $('#segundo_nombre').val(p.segundo_nombre || '');
                        $('#primer_apellido').val(p.primer_apellido);
                        $('#segundo_apellido').val(p.segundo_apellido || '');
                        $('#dui').val(p.dui || '');
                        $('#fecha_nacimiento').val(p.fecha_nacimiento || '');
                        $('#sexo').val(p.sexo || '');
                        $('#nacionalidad').val(p.nacionalidad || 'Salvadoreña');
                        $('#direccion').val(p.direccion || '');
                        $('#especialidad').val(p.especialidad || '');
                        $('#titulo_academico').val(p.titulo_academico || '');
                        $('#email').val(p.email || '');
                        $('#celular').val(p.celular || '');
                        $('#telefono_fijo').val(p.telefono_fijo || '');
                        $('#usuario').val(p.usuario || '');
                        
                        $('#password').val('').prop('required', false);
                        $('#password_confirm').val('').prop('required', false);
                        $('#label_password, #label_confirm').hide();
                        
                        $('.nav-link').first().tab('show');
                    } else {
                        alert('❌ ' + (res.message || 'Error al cargar'));
                        modal.hide();
                    }
                },
                error: function(xhr, status, error) {
                    console.error('Error:', error, xhr.responseText);
                    alert('❌ Error al cargar los datos. Revisa la consola (F12)');
                    modal.hide();
                }
            });
        }
        
        function asignarMaterias(id) {
            $('#asignar_id_profesor').val(id);
            new bootstrap.Modal($('#modalAsignarMaterias')).show();
        }
        
        function addAsignatura() {
            const html = `
            <div class="asignatura-row mb-3 p-3 border rounded">
                <div class="row g-2">
                    <div class="col-md-5">
                        <label class="form-label">Asignatura</label>
                        <select name="asignaturas[]" class="form-select select-asignatura">
                            <option value="">Seleccionar</option>
                            <?php foreach ($asignaturas as $asig): ?>
                            <option value="<?= $asig['id'] ?>"><?= htmlspecialchars($asig['nombre']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-5">
                        <label class="form-label">Sección</label>
                        <select name="secciones[]" class="form-select select-seccion">
                            <option value="">Seleccionar</option>
                            <?php foreach ($secciones as $sec): ?>
                            <option value="<?= $sec['id'] ?>"><?= htmlspecialchars($sec['nombre']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-2 d-flex align-items-end">
                        <button type="button" class="btn btn-danger w-100" onclick="removeAsignatura(this)"><i class="fas fa-trash"></i></button>
                    </div>
                </div>
            </div>`;
            $('#asignaturasContainer').append(html);
            initSelect2($('#asignaturasContainer .asignatura-row:last .select-asignatura'), $('#modalAsignarMaterias'));
            initSelect2($('#asignaturasContainer .asignatura-row:last .select-seccion'), $('#modalAsignarMaterias'));
        }
        
        function removeAsignatura(btn) {
            $(btn).closest('.asignatura-row').remove();
        }
        
        function eliminarProfesor(id, nombre) {
            if (confirm(`¿Desactivar a ${nombre}?\n\n• Se desactivará su acceso\n• Se conservarán sus datos históricos\n\n¿Continuar?`)) {
                $('<form>', { method: 'POST', action: 'gestionar_profesores.php' })
                    .append($('<input>', { type: 'hidden', name: 'accion', value: 'eliminar' }))
                    .append($('<input>', { type: 'hidden', name: 'id_profesor', value: id }))
                    .appendTo('body').submit();
            }
        }
        
        function initSelect2($element, parent) {
            $element.select2({ placeholder: 'Seleccionar', allowClear: true, theme: 'bootstrap-5', width: '100%', dropdownParent: parent || $('body') });
        }
        
        $('#modalAsignarMaterias').on('shown.bs.modal', function() {
            const $modal = $(this);
            $modal.find('.select-asignatura, .select-seccion').each(function() {
                if ($(this).data('select2')) $(this).select2('destroy');
                initSelect2($(this), $modal);
            });
        }).on('hidden.bs.modal', function() {
            $(this).find('.select-asignatura, .select-seccion').select2('destroy');
            $(this).find('form')[0].reset();
        });
    </script>
</body>
</html>