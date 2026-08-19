<?php
session_start();
require_once __DIR__ . '/../config/db_global.php';

if (!isset($_SESSION['rol']) || $_SESSION['rol'] !== 'superadmin') {
    header("Location: " . url('/superadmin/login.php'));
    exit;
}

$db = (new DatabaseGlobal())->getConnection();
$mensaje = '';
$tipo_mensaje = '';

// Roles que el superadmin puede crear desde aquí. 'estudiante' se deja fuera:
// los estudiantes se matriculan desde el panel del propio colegio, no desde
// la plataforma global. El objetivo de esta pantalla es poder darle a una
// institución recién creada su primer usuario con acceso (admin/director),
// o agregar profesores puntualmente si hace falta.
$roles_permitidos = ['admin', 'director', 'profesor'];

// ===== Procesar creación de usuario =====
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['accion'] ?? '') === 'crear') {
    try {
        $id_institucion = (int) ($_POST['id_institucion'] ?? 0);
        $rol = $_POST['rol'] ?? '';
        $usuario = trim($_POST['usuario'] ?? '');
        $password = $_POST['password'] ?? '';
        $email = filter_var(trim($_POST['email'] ?? ''), FILTER_VALIDATE_EMAIL);
        $primer_nombre = trim($_POST['primer_nombre'] ?? '');
        $primer_apellido = trim($_POST['primer_apellido'] ?? '');
        $segundo_nombre = trim($_POST['segundo_nombre'] ?? '');
        $segundo_apellido = trim($_POST['segundo_apellido'] ?? '');
        $dui = trim($_POST['dui'] ?? '');
        $fecha_nacimiento = !empty($_POST['fecha_nacimiento']) ? $_POST['fecha_nacimiento'] : null;
        $sexo = !empty($_POST['sexo']) ? $_POST['sexo'] : null;
        $nacionalidad = trim($_POST['nacionalidad'] ?? '');
        $celular = trim($_POST['celular'] ?? '');
        $telefono_fijo = trim($_POST['telefono_fijo'] ?? '');
        $direccion = trim($_POST['direccion'] ?? '');
        $especialidad = trim($_POST['especialidad'] ?? '');
        $titulo_academico = trim($_POST['titulo_academico'] ?? '');
        $cargo = trim($_POST['cargo'] ?? '');

        $errores = [];
        if (!$id_institucion) $errores[] = 'Selecciona una institución.';
        if (!in_array($rol, $roles_permitidos, true)) $errores[] = 'Rol no válido.';
        if (strlen($usuario) < 3) $errores[] = 'El usuario debe tener al menos 3 caracteres.';
        if (strlen($password) < 6) $errores[] = 'La contraseña debe tener al menos 6 caracteres.';
        if (!$email) $errores[] = 'El email no es válido.';
        if (empty($primer_nombre) || empty($primer_apellido)) $errores[] = 'El nombre y apellido son obligatorios.';
        if ($rol === 'profesor' && empty($especialidad)) $errores[] = 'La especialidad es obligatoria para un profesor.';

        if (empty($errores)) {
            // La institución debe existir y estar activa
            $stmt = $db->prepare("SELECT id FROM tbl_institucion WHERE id = :id AND estado = 'activo'");
            $stmt->execute([':id' => $id_institucion]);
            if (!$stmt->fetch()) {
                $errores[] = 'La institución seleccionada no existe o está inactiva.';
            }
        }

        // Usuario/email únicos DENTRO de la institución elegida (igual que
        // login.php/registro.php: el nombre de usuario no es único global,
        // sólo por tenant, para no filtrar entre instituciones).
        if (empty($errores)) {
            $stmt = $db->prepare("SELECT id FROM tbl_usuario WHERE usuario = :usuario AND id_institucion = :tid");
            $stmt->execute([':usuario' => $usuario, ':tid' => $id_institucion]);
            if ($stmt->fetch()) $errores[] = 'Ese nombre de usuario ya existe en esa institución.';
        }
        if (empty($errores)) {
            $stmt = $db->prepare("SELECT id FROM tbl_usuario WHERE email = :email AND id_institucion = :tid");
            $stmt->execute([':email' => $email, ':tid' => $id_institucion]);
            if ($stmt->fetch()) $errores[] = 'Ese email ya está registrado en esa institución.';
        }

        if (!empty($errores)) {
            throw new Exception(implode(' ', $errores));
        }

        $db->beginTransaction();

        // PASO 1: tbl_usuario (incluye 'nombre': es NOT NULL sin default)
        $nombre_completo = trim("$primer_nombre $primer_apellido");
        $stmt = $db->prepare("INSERT INTO tbl_usuario (nombre, usuario, password, email, rol, estado, id_institucion)
                               VALUES (:nombre, :usuario, :password, :email, :rol, 1, :tid)");
        $stmt->execute([
            ':nombre' => $nombre_completo,
            ':usuario' => $usuario,
            ':password' => password_hash($password, PASSWORD_DEFAULT),
            ':email' => $email,
            ':rol' => $rol,
            ':tid' => $id_institucion,
        ]);
        $id_usuario = $db->lastInsertId();

        // PASO 2: tbl_persona (tercer_nombre es NOT NULL sin default -> '')
        $stmt = $db->prepare("INSERT INTO tbl_persona (
                id_usuario, primer_nombre, segundo_nombre, tercer_nombre, primer_apellido, segundo_apellido,
                dui, fecha_nacimiento, sexo, nacionalidad, direccion, telefono_fijo, celular, email, estado
            ) VALUES (
                :id_usuario, :p_nombre, :s_nombre, '', :p_apellido, :s_apellido,
                :dui, :fecha_nac, :sexo, :nacionalidad, :direccion, :tel_fijo, :celular, :email, 'activo'
            )");
        $stmt->execute([
            ':id_usuario' => $id_usuario,
            ':p_nombre' => $primer_nombre,
            ':s_nombre' => $segundo_nombre,
            ':p_apellido' => $primer_apellido,
            ':s_apellido' => $segundo_apellido,
            ':dui' => $dui,
            ':fecha_nac' => $fecha_nacimiento,
            ':sexo' => $sexo,
            ':nacionalidad' => $nacionalidad,
            ':direccion' => $direccion,
            ':tel_fijo' => $telefono_fijo,
            ':celular' => $celular,
            ':email' => $email,
        ]);
        $id_persona = $db->lastInsertId();

        // PASO 3: tabla específica del rol
        if ($rol === 'profesor') {
            // tbl_profesor.estado es varchar NOT NULL sin default
            $stmt = $db->prepare("INSERT INTO tbl_profesor (id_persona, estado, especialidad, titulo_academico, id_institucion)
                                   VALUES (:id_persona, 'activo', :especialidad, :titulo, :tid)");
            $stmt->execute([
                ':id_persona' => $id_persona,
                ':especialidad' => $especialidad,
                ':titulo' => $titulo_academico,
                ':tid' => $id_institucion,
            ]);
        } elseif ($rol === 'director') {
            $stmt = $db->prepare("INSERT INTO tbl_director (id_persona, cargo) VALUES (:id_persona, :cargo)");
            $stmt->execute([
                ':id_persona' => $id_persona,
                ':cargo' => $cargo ?: 'Director',
            ]);
        }
        // 'admin' no tiene tabla de perfil adicional en este esquema.

        $db->commit();
        $mensaje = 'Usuario creado con éxito.';
        $tipo_mensaje = 'success';
    } catch (Exception $e) {
        if ($db->inTransaction()) $db->rollBack();
        $mensaje = 'Error: ' . $e->getMessage();
        $tipo_mensaje = 'danger';
    }
}

// ===== Editar usuario (nombre, usuario, email, estado) =====
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['accion'] ?? '') === 'editar') {
    try {
        $id_usuario = (int) ($_POST['id_usuario'] ?? 0);
        $nombre = trim($_POST['edit_nombre'] ?? '');
        $usuario = trim($_POST['edit_usuario'] ?? '');
        $email = filter_var(trim($_POST['edit_email'] ?? ''), FILTER_VALIDATE_EMAIL);
        $estado = isset($_POST['edit_estado']) ? 1 : 0;

        $errores = [];
        if (!$id_usuario) $errores[] = 'Usuario no válido.';
        if (strlen($usuario) < 3) $errores[] = 'El usuario debe tener al menos 3 caracteres.';
        if (!$email) $errores[] = 'El email no es válido.';
        if (empty($nombre)) $errores[] = 'El nombre es obligatorio.';

        // Buscar la institución actual del usuario para validar duplicados
        // dentro de la misma institución (igual criterio que al crear).
        $actual = null;
        if (empty($errores)) {
            $stmt = $db->prepare("SELECT id_institucion FROM tbl_usuario WHERE id = :id");
            $stmt->execute([':id' => $id_usuario]);
            $actual = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$actual) $errores[] = 'El usuario ya no existe.';
        }

        if (empty($errores)) {
            $stmt = $db->prepare("SELECT id FROM tbl_usuario WHERE usuario = :usuario AND id_institucion = :tid AND id != :id");
            $stmt->execute([':usuario' => $usuario, ':tid' => $actual['id_institucion'], ':id' => $id_usuario]);
            if ($stmt->fetch()) $errores[] = 'Ese nombre de usuario ya existe en esa institución.';
        }
        if (empty($errores)) {
            $stmt = $db->prepare("SELECT id FROM tbl_usuario WHERE email = :email AND id_institucion = :tid AND id != :id");
            $stmt->execute([':email' => $email, ':tid' => $actual['id_institucion'], ':id' => $id_usuario]);
            if ($stmt->fetch()) $errores[] = 'Ese email ya está registrado en esa institución.';
        }

        if (!empty($errores)) {
            throw new Exception(implode(' ', $errores));
        }

        $stmt = $db->prepare("UPDATE tbl_usuario SET nombre = :nombre, usuario = :usuario, email = :email, estado = :estado WHERE id = :id");
        $stmt->execute([
            ':nombre' => $nombre,
            ':usuario' => $usuario,
            ':email' => $email,
            ':estado' => $estado,
            ':id' => $id_usuario,
        ]);

        // Mantener el email de tbl_persona en sincronía, si existe.
        $stmt = $db->prepare("UPDATE tbl_persona SET email = :email WHERE id_usuario = :id");
        $stmt->execute([':email' => $email, ':id' => $id_usuario]);

        $mensaje = 'Usuario actualizado con éxito.';
        $tipo_mensaje = 'success';
    } catch (Exception $e) {
        $mensaje = 'Error: ' . $e->getMessage();
        $tipo_mensaje = 'danger';
    }
}

// ===== Nueva contraseña =====
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['accion'] ?? '') === 'reset_password') {
    try {
        $id_usuario = (int) ($_POST['id_usuario'] ?? 0);
        $password = $_POST['nueva_password'] ?? '';

        if (!$id_usuario) throw new Exception('Usuario no válido.');
        if (strlen($password) < 6) throw new Exception('La contraseña debe tener al menos 6 caracteres.');

        $stmt = $db->prepare("UPDATE tbl_usuario SET password = :password WHERE id = :id");
        $stmt->execute([':password' => password_hash($password, PASSWORD_DEFAULT), ':id' => $id_usuario]);

        if ($stmt->rowCount() === 0) throw new Exception('El usuario ya no existe.');

        $mensaje = 'Contraseña actualizada con éxito.';
        $tipo_mensaje = 'success';
    } catch (Exception $e) {
        $mensaje = 'Error: ' . $e->getMessage();
        $tipo_mensaje = 'danger';
    }
}

// ===== Eliminar usuario (en cascada: rol específico -> persona -> usuario) =====
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['accion'] ?? '') === 'eliminar') {
    try {
        $id_usuario = (int) ($_POST['id_usuario'] ?? 0);
        if (!$id_usuario) throw new Exception('Usuario no válido.');

        $stmt = $db->prepare("SELECT rol FROM tbl_usuario WHERE id = :id");
        $stmt->execute([':id' => $id_usuario]);
        $u = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$u) throw new Exception('El usuario ya no existe.');

        $db->beginTransaction();

        $stmt = $db->prepare("SELECT id FROM tbl_persona WHERE id_usuario = :id");
        $stmt->execute([':id' => $id_usuario]);
        $persona = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($persona) {
            if ($u['rol'] === 'profesor') {
                $db->prepare("DELETE FROM tbl_profesor WHERE id_persona = :id")->execute([':id' => $persona['id']]);
            } elseif ($u['rol'] === 'director') {
                $db->prepare("DELETE FROM tbl_director WHERE id_persona = :id")->execute([':id' => $persona['id']]);
            }
            $db->prepare("DELETE FROM tbl_persona WHERE id = :id")->execute([':id' => $persona['id']]);
        }

        $db->prepare("DELETE FROM tbl_usuario WHERE id = :id")->execute([':id' => $id_usuario]);

        $db->commit();
        $mensaje = 'Usuario eliminado con éxito.';
        $tipo_mensaje = 'success';
    } catch (Exception $e) {
        if ($db->inTransaction()) $db->rollBack();
        $mensaje = 'Error: ' . $e->getMessage();
        $tipo_mensaje = 'danger';
    }
}

// ===== Datos para la vista =====
$instituciones = $db->query("SELECT id, nombre_ce, subdominio FROM tbl_institucion WHERE estado = 'activo' ORDER BY nombre_ce")->fetchAll(PDO::FETCH_ASSOC);

// Usuarios de staff (no estudiantes) con su institución, para revisar rápido
// qué se ha creado y detectar cuentas huérfanas (usuario sin persona).
$usuarios = $db->query("
    SELECT u.id, u.nombre, u.usuario, u.email, u.rol, u.estado, u.created_at,
           i.nombre_ce, i.subdominio,
           (per.id IS NOT NULL) as tiene_persona
    FROM tbl_usuario u
    LEFT JOIN tbl_institucion i ON u.id_institucion = i.id
    LEFT JOIN tbl_persona per ON per.id_usuario = u.id
    WHERE u.rol IN ('admin','director','profesor')
    ORDER BY u.created_at DESC
    LIMIT 100
")->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Usuarios Globales - Super Admin</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        body { background: #f4f6f9; }
        .badge-huerfano { background: #dc3545; }
    </style>
</head>
<body class="bg-light">
    <div class="container py-5">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h2><i class="fas fa-users-cog"></i> Usuarios Globales</h2>
            <div>
                <button type="button" class="btn btn-success" <?= empty($instituciones) ? 'disabled title="Crea primero una institución activa"' : 'onclick="abrirNuevoUsuario()"' ?>>
                    <i class="fas fa-user-plus"></i> Agregar Usuario
                </button>
                <a href="dashboard.php" class="btn btn-secondary">Volver al Dashboard</a>
            </div>
        </div>

        <?php if ($mensaje): ?>
        <div class="alert alert-<?= $tipo_mensaje === 'success' ? 'success' : 'danger' ?>"><?= htmlspecialchars($mensaje) ?></div>
        <?php endif; ?>

        <?php if (empty($instituciones)): ?>
        <div class="alert alert-warning">
            No hay instituciones activas todavía. Crea una primero en
            <a href="instituciones.php">Gestión de Instituciones</a>.
        </div>
        <?php endif; ?>

        <!-- Tabla de Usuarios -->
        <div class="card">
            <div class="card-header bg-white">Últimos usuarios de staff (admin / director / profesor)</div>
            <div class="card-body">
                <table class="table table-hover">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Nombre</th>
                            <th>Usuario</th>
                            <th>Institución</th>
                            <th>Rol</th>
                            <th>Estado</th>
                            <th>Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($usuarios as $u): ?>
                        <tr>
                            <td><?= $u['id'] ?></td>
                            <td><?= htmlspecialchars($u['nombre']) ?></td>
                            <td><?= htmlspecialchars($u['usuario']) ?></td>
                            <td><?= htmlspecialchars($u['nombre_ce'] ?? '—') ?></td>
                            <td><span class="badge bg-secondary"><?= htmlspecialchars($u['rol']) ?></span></td>
                            <td>
                                <?php if (!$u['tiene_persona']): ?>
                                <span class="badge badge-huerfano" title="Tiene tbl_usuario pero no tbl_persona: no podrá usar la plataforma hasta corregirse.">
                                    <i class="fas fa-exclamation-triangle"></i> Cuenta incompleta
                                </span>
                                <?php elseif (!$u['estado']): ?>
                                <span class="badge bg-secondary">Inactivo</span>
                                <?php else: ?>
                                <span class="badge bg-success">OK</span>
                                <?php endif; ?>
                            </td>
                            <td class="text-nowrap">
                                <button type="button" class="btn btn-sm btn-outline-primary"
                                        title="Editar"
                                        onclick="abrirEditar(<?= (int)$u['id'] ?>, <?= htmlspecialchars(json_encode($u['nombre']), ENT_QUOTES) ?>, <?= htmlspecialchars(json_encode($u['usuario']), ENT_QUOTES) ?>, <?= htmlspecialchars(json_encode($u['email']), ENT_QUOTES) ?>, <?= (int)$u['estado'] ?>)">
                                    <i class="fas fa-edit"></i>
                                </button>
                                <button type="button" class="btn btn-sm btn-outline-warning"
                                        title="Nueva contraseña"
                                        onclick="abrirPassword(<?= (int)$u['id'] ?>, <?= htmlspecialchars(json_encode($u['usuario']), ENT_QUOTES) ?>)">
                                    <i class="fas fa-key"></i>
                                </button>
                                <form method="POST" class="d-inline" onsubmit="return confirm('¿Eliminar a &quot;<?= htmlspecialchars(addslashes($u['usuario'])) ?>&quot;? Esto borra su cuenta, su perfil de persona y su registro de <?= htmlspecialchars($u['rol']) ?>. No se puede deshacer.');">
                                    <input type="hidden" name="accion" value="eliminar">
                                    <input type="hidden" name="id_usuario" value="<?= (int)$u['id'] ?>">
                                    <button type="submit" class="btn btn-sm btn-outline-danger" title="Eliminar">
                                        <i class="fas fa-trash"></i>
                                    </button>
                                </form>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if (empty($usuarios)): ?>
                        <tr><td colspan="7" class="text-center text-muted py-4">Todavía no hay usuarios de staff.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Modal: Nuevo Usuario -->
    <div class="modal fade" id="modalNuevoUsuario" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-scrollable">
            <form method="POST" class="modal-content" id="formUsuario">
                <input type="hidden" name="accion" value="crear">
                <div class="modal-header bg-primary text-white">
                    <div>
                        <h5 class="modal-title mb-0"><i class="fas fa-user-plus"></i> Agregar Usuario a una Institución</h5>
                        <small class="d-block mt-1">
                            Usa esto para darle a una institución recién creada su primer administrador,
                            o para agregar un director/profesor puntualmente.
                        </small>
                    </div>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-md-4">
                            <label>Institución *</label>
                            <select name="id_institucion" class="form-select" required>
                                <option value="">Seleccionar</option>
                                <?php foreach ($instituciones as $inst): ?>
                                <option value="<?= $inst['id'] ?>">
                                    <?= htmlspecialchars($inst['nombre_ce']) ?> (<?= htmlspecialchars($inst['subdominio'] ?? 'sin subdominio') ?>)
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label>Rol *</label>
                            <select name="rol" id="rolSelect" class="form-select" required onchange="toggleCamposRol()">
                                <option value="">Seleccionar</option>
                                <option value="admin">Administrador</option>
                                <option value="director">Director</option>
                                <option value="profesor">Profesor</option>
                            </select>
                        </div>
                        <div class="col-md-4"></div>

                        <div class="col-md-6">
                            <label>Primer Nombre *</label>
                            <input type="text" name="primer_nombre" class="form-control" required>
                        </div>
                        <div class="col-md-6">
                            <label>Segundo Nombre</label>
                            <input type="text" name="segundo_nombre" class="form-control">
                        </div>
                        <div class="col-md-6">
                            <label>Primer Apellido *</label>
                            <input type="text" name="primer_apellido" class="form-control" required>
                        </div>
                        <div class="col-md-6">
                            <label>Segundo Apellido</label>
                            <input type="text" name="segundo_apellido" class="form-control">
                        </div>

                        <div class="col-md-4">
                            <label>Email *</label>
                            <input type="email" name="email" class="form-control" required>
                        </div>
                        <div class="col-md-4">
                            <label>Celular</label>
                            <input type="text" name="celular" class="form-control">
                        </div>
                        <div class="col-md-4">
                            <label>DUI</label>
                            <input type="text" name="dui" class="form-control">
                        </div>

                        <div class="col-md-4">
                            <label>Fecha de Nacimiento</label>
                            <input type="date" name="fecha_nacimiento" class="form-control">
                        </div>
                        <div class="col-md-4">
                            <label>Sexo</label>
                            <select name="sexo" class="form-select">
                                <option value="">Seleccionar</option>
                                <option value="M">Masculino</option>
                                <option value="F">Femenino</option>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label>Nacionalidad</label>
                            <input type="text" name="nacionalidad" class="form-control" value="Salvadoreña">
                        </div>

                        <div class="col-md-6">
                            <label>Dirección</label>
                            <input type="text" name="direccion" class="form-control">
                        </div>
                        <div class="col-md-6">
                            <label>Teléfono Fijo</label>
                            <input type="text" name="telefono_fijo" class="form-control">
                        </div>

                        <!-- Campos específicos de Profesor -->
                        <div class="col-md-6 campo-rol campo-profesor" style="display:none;">
                            <label>Especialidad *</label>
                            <input type="text" name="especialidad" class="form-control">
                        </div>
                        <div class="col-md-6 campo-rol campo-profesor" style="display:none;">
                            <label>Título Académico</label>
                            <input type="text" name="titulo_academico" class="form-control">
                        </div>

                        <!-- Campo específico de Director -->
                        <div class="col-md-6 campo-rol campo-director" style="display:none;">
                            <label>Cargo</label>
                            <input type="text" name="cargo" class="form-control" placeholder="Ej: Director Académico">
                        </div>

                        <div class="col-md-6">
                            <label>Usuario (login) *</label>
                            <input type="text" name="usuario" class="form-control" required pattern="[a-zA-Z0-9_]{3,20}">
                        </div>
                        <div class="col-md-6">
                            <label>Contraseña *</label>
                            <input type="password" name="password" class="form-control" required minlength="6">
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-success"><i class="fas fa-user-plus"></i> Crear Usuario</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Modal: Editar usuario -->
    <div class="modal fade" id="modalEditar" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog">
            <form method="POST" class="modal-content">
                <input type="hidden" name="accion" value="editar">
                <input type="hidden" name="id_usuario" id="edit_id_usuario">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-edit"></i> Editar usuario</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label>Nombre completo</label>
                        <input type="text" name="edit_nombre" id="edit_nombre" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label>Usuario (login)</label>
                        <input type="text" name="edit_usuario" id="edit_usuario" class="form-control" required pattern="[a-zA-Z0-9_]{3,20}">
                    </div>
                    <div class="mb-3">
                        <label>Email</label>
                        <input type="email" name="edit_email" id="edit_email" class="form-control" required>
                    </div>
                    <div class="form-check">
                        <input type="checkbox" name="edit_estado" id="edit_estado" class="form-check-input" value="1">
                        <label class="form-check-label" for="edit_estado">Cuenta activa</label>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Actualizar</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Modal: Nueva contraseña -->
    <div class="modal fade" id="modalPassword" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog">
            <form method="POST" class="modal-content">
                <input type="hidden" name="accion" value="reset_password">
                <input type="hidden" name="id_usuario" id="pwd_id_usuario">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-key"></i> Nueva contraseña para <span id="pwd_usuario_nombre"></span></h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label>Nueva contraseña</label>
                        <input type="password" name="nueva_password" class="form-control" required minlength="6">
                        <small class="text-muted">Mínimo 6 caracteres.</small>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-warning"><i class="fas fa-key"></i> Actualizar contraseña</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        function abrirNuevoUsuario() {
            const form = document.getElementById('formUsuario');
            form.reset();
            toggleCamposRol();
            new bootstrap.Modal(document.getElementById('modalNuevoUsuario')).show();
        }

        function abrirEditar(id, nombre, usuario, email, estado) {
            document.getElementById('edit_id_usuario').value = id;
            document.getElementById('edit_nombre').value = nombre;
            document.getElementById('edit_usuario').value = usuario;
            document.getElementById('edit_email').value = email;
            document.getElementById('edit_estado').checked = !!estado;
            new bootstrap.Modal(document.getElementById('modalEditar')).show();
        }

        function abrirPassword(id, usuario) {
            document.getElementById('pwd_id_usuario').value = id;
            document.getElementById('pwd_usuario_nombre').textContent = usuario;
            new bootstrap.Modal(document.getElementById('modalPassword')).show();
        }

        function toggleCamposRol() {
            const rol = document.getElementById('rolSelect').value;
            document.querySelectorAll('.campo-profesor').forEach(el => {
                el.style.display = (rol === 'profesor') ? '' : 'none';
                el.querySelector('input')?.toggleAttribute('required', rol === 'profesor' && el.querySelector('label').textContent.includes('*'));
            });
            document.querySelectorAll('.campo-director').forEach(el => {
                el.style.display = (rol === 'director') ? '' : 'none';
            });
        }
    </script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
