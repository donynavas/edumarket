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

// Tablas que cuelgan de una institución vía id_institucion. Sólo tbl_usuario,
// tbl_seccion y tbl_banco_preguntas tienen FK real (RESTRICT) hacia
// tbl_institucion; las demás guardan id_institucion sin FK declarada, así
// que un DELETE directo no fallaría por ellas y dejaría filas huérfanas.
// Por eso "Eliminar" revisa las 8, no sólo las que tienen FK.
const TABLAS_DEPENDIENTES_INSTITUCION = [
    'tbl_usuario'        => 'usuarios',
    'tbl_estudiante'     => 'estudiantes',
    'tbl_profesor'       => 'profesores',
    'tbl_grado'          => 'grados',
    'tbl_seccion'        => 'secciones',
    'tbl_asignatura'     => 'asignaturas',
    'tbl_periodo'        => 'períodos',
    'tbl_banco_preguntas'=> 'preguntas del banco',
];

// Procesar Crear Institución
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['accion'])) {
    if ($_POST['accion'] == 'crear') {
        $nombre = trim($_POST['nombre_ce'] ?? '');
        $subdominio = trim($_POST['subdominio'] ?? '');
        $email = trim($_POST['email'] ?? '');

        if ($nombre === '' || $subdominio === '') {
            $mensaje = 'Nombre y subdominio son obligatorios.';
            $tipo_mensaje = 'danger';
        } else {
            try {
                // La tabla no tiene columna 'email_contacto' (sólo 'email');
                // el INSERT original apuntaba a una columna inexistente y
                // esto hacía fallar silenciosamente toda creación de
                // institución con un PDOException.
                $stmt = $db->prepare("INSERT INTO tbl_institucion (nombre_ce, subdominio, email, estado) VALUES (?, ?, ?, 'activo')");
                $stmt->execute([$nombre, $subdominio, $email ?: null]);
                $mensaje = 'Institución creada con éxito.';
                $tipo_mensaje = 'success';
            } catch (PDOException $e) {
                $mensaje = (str_contains($e->getMessage(), 'Duplicate'))
                    ? "El subdominio \"$subdominio\" ya está en uso por otra institución."
                    : 'Error al crear: ' . $e->getMessage();
                $tipo_mensaje = 'danger';
            }
        }
    }

    if ($_POST['accion'] == 'editar') {
        $id = (int) ($_POST['id'] ?? 0);
        $nombre = trim($_POST['nombre_ce'] ?? '');
        $subdominio = trim($_POST['subdominio'] ?? '');
        $email = trim($_POST['email'] ?? '');

        if (!$id || $nombre === '' || $subdominio === '') {
            $mensaje = 'Institución, nombre y subdominio son obligatorios.';
            $tipo_mensaje = 'danger';
        } else {
            try {
                $stmt = $db->prepare("UPDATE tbl_institucion SET nombre_ce = :nombre, subdominio = :subdominio, email = :email WHERE id = :id");
                $stmt->execute([
                    ':nombre' => $nombre,
                    ':subdominio' => $subdominio,
                    ':email' => $email ?: null,
                    ':id' => $id,
                ]);
                $mensaje = 'Institución actualizada con éxito.';
                $tipo_mensaje = 'success';
            } catch (PDOException $e) {
                $mensaje = (str_contains($e->getMessage(), 'Duplicate'))
                    ? "El subdominio \"$subdominio\" ya está en uso por otra institución."
                    : 'Error al actualizar: ' . $e->getMessage();
                $tipo_mensaje = 'danger';
            }
        }
    }

    if ($_POST['accion'] == 'cambiar_estado') {
        $id = (int) ($_POST['id'] ?? 0);
        $nuevo_estado = $_POST['nuevo_estado'] ?? '';

        if (!$id || !in_array($nuevo_estado, ['activo', 'inactivo'], true)) {
            $mensaje = 'Solicitud no válida.';
            $tipo_mensaje = 'danger';
        } else {
            $stmt = $db->prepare("UPDATE tbl_institucion SET estado = :estado WHERE id = :id");
            $stmt->execute([':estado' => $nuevo_estado, ':id' => $id]);
            $mensaje = $nuevo_estado === 'inactivo'
                ? 'Institución suspendida. Sus usuarios no podrán iniciar sesión mientras esté en este estado.'
                : 'Institución reactivada con éxito.';
            $tipo_mensaje = $nuevo_estado === 'inactivo' ? 'warning' : 'success';
        }
    }

    if ($_POST['accion'] == 'eliminar') {
        $id = (int) ($_POST['id'] ?? 0);
        if (!$id) {
            $mensaje = 'Institución no válida.';
            $tipo_mensaje = 'danger';
        } else {
            // No se permite borrar una institución con datos: perdería
            // matrículas, calificaciones, usuarios, etc. de forma
            // irreversible. Se revisan las 8 tablas tenant-scoped, no sólo
            // las 3 que tienen FK, porque las otras 5 no están protegidas
            // por la base de datos y dejarían filas huérfanas.
            $bloqueos = [];
            foreach (TABLAS_DEPENDIENTES_INSTITUCION as $tabla => $etiqueta) {
                $stmt = $db->prepare("SELECT COUNT(*) FROM `$tabla` WHERE id_institucion = :id");
                $stmt->execute([':id' => $id]);
                if ((int) $stmt->fetchColumn() > 0) {
                    $bloqueos[] = $etiqueta;
                }
            }

            if (!empty($bloqueos)) {
                $mensaje = 'No se puede eliminar: la institución todavía tiene ' . implode(', ', $bloqueos) . '. '
                          . 'Usa "Suspender" en su lugar si quieres quitarle el acceso sin perder sus datos.';
                $tipo_mensaje = 'danger';
            } else {
                try {
                    $stmt = $db->prepare("DELETE FROM tbl_institucion WHERE id = :id");
                    $stmt->execute([':id' => $id]);
                    $mensaje = 'Institución eliminada con éxito.';
                    $tipo_mensaje = 'success';
                } catch (PDOException $e) {
                    $mensaje = 'No se pudo eliminar: todavía tiene datos relacionados en el sistema.';
                    $tipo_mensaje = 'danger';
                }
            }
        }
    }
}

// Listar Instituciones
$instituciones = $db->query("SELECT * FROM tbl_institucion ORDER BY id DESC")->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Gestión de Instituciones</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
</head>
<body class="bg-light">
    <div class="container py-5">
        <div class="d-flex justify-content-between mb-4">
            <h2><i class="fas fa-university"></i> Gestión de Instituciones</h2>
            <a href="dashboard.php" class="btn btn-secondary">Volver al Dashboard</a>
        </div>

        <?php if ($mensaje): ?><div class="alert alert-<?= htmlspecialchars($tipo_mensaje ?: 'info') ?> alert-dismissible fade show"><?= htmlspecialchars($mensaje) ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div><?php endif; ?>

        <!-- Formulario Nueva Institución -->
        <div class="card mb-4">
            <div class="card-header bg-primary text-white">Nueva Institución (Cliente)</div>
            <div class="card-body">
                <form method="POST">
                    <input type="hidden" name="accion" value="crear">
                    <div class="row g-3">
                        <div class="col-md-4">
                            <label>Nombre del Colegio/Institución</label>
                            <input type="text" name="nombre_ce" class="form-control" required placeholder="Ej: Colegio San José">
                        </div>
                        <div class="col-md-4">
                            <label>Subdominio (Acceso)</label>
                            <input type="text" name="subdominio" class="form-control" required placeholder="Ej: sanjose">
                            <small class="text-muted">Accederá via: <span id="url-preview">sanjose</span>.tu-dominio.com</small>
                        </div>
                        <div class="col-md-4">
                            <label>Email de Contacto</label>
                            <input type="email" name="email" class="form-control" required>
                        </div>
                        <div class="col-12">
                            <button type="submit" class="btn btn-success"><i class="fas fa-plus"></i> Crear Institución</button>
                        </div>
                    </div>
                </form>
            </div>
        </div>

        <!-- Tabla de Instituciones -->
        <div class="card">
            <div class="card-body">
                <table class="table table-hover">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Nombre</th>
                            <th>Subdominio</th>
                            <th>Estado</th>
                            <th>Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($instituciones as $inst): ?>
                        <tr>
                            <td><?= (int) $inst['id'] ?></td>
                            <td><strong><?= htmlspecialchars($inst['nombre_ce']) ?></strong></td>
                            <td><span class="badge bg-info"><?= htmlspecialchars($inst['subdominio']) ?></span></td>
                            <td><?= $inst['estado'] == 'activo' ? '<span class="text-success">Activo</span>' : '<span class="text-danger">Inactivo</span>' ?></td>
                            <td class="text-nowrap">
                                <button type="button" class="btn btn-sm btn-warning"
                                        title="Editar"
                                        onclick='abrirEditar(<?= json_encode([
                                            'id' => (int) $inst['id'],
                                            'nombre_ce' => $inst['nombre_ce'],
                                            'subdominio' => $inst['subdominio'],
                                            'email' => $inst['email'],
                                        ], JSON_HEX_APOS | JSON_HEX_QUOT) ?>)'>
                                    Editar
                                </button>
                                <?php if ($inst['estado'] == 'activo'): ?>
                                <form method="POST" class="d-inline" onsubmit="return confirm('¿Suspender \'<?= htmlspecialchars(addslashes($inst['nombre_ce'])) ?>\'?\n\nSus usuarios no podrán iniciar sesión mientras esté suspendida. Sus datos NO se borran y puedes reactivarla después.');">
                                    <input type="hidden" name="accion" value="cambiar_estado">
                                    <input type="hidden" name="nuevo_estado" value="inactivo">
                                    <input type="hidden" name="id" value="<?= (int) $inst['id'] ?>">
                                    <button type="submit" class="btn btn-sm btn-danger" title="Suspender">Suspender</button>
                                </form>
                                <?php else: ?>
                                <form method="POST" class="d-inline" onsubmit="return confirm('¿Reactivar \'<?= htmlspecialchars(addslashes($inst['nombre_ce'])) ?>\'? Sus usuarios podrán volver a iniciar sesión.');">
                                    <input type="hidden" name="accion" value="cambiar_estado">
                                    <input type="hidden" name="nuevo_estado" value="activo">
                                    <input type="hidden" name="id" value="<?= (int) $inst['id'] ?>">
                                    <button type="submit" class="btn btn-sm btn-success" title="Reactivar">Reactivar</button>
                                </form>
                                <?php endif; ?>
                                <form method="POST" class="d-inline" onsubmit="return confirm('¿Eliminar \'<?= htmlspecialchars(addslashes($inst['nombre_ce'])) ?>\' de forma PERMANENTE?\n\nSólo se permite si la institución no tiene usuarios, estudiantes, profesores ni otros datos. No se puede deshacer.');">
                                    <input type="hidden" name="accion" value="eliminar">
                                    <input type="hidden" name="id" value="<?= (int) $inst['id'] ?>">
                                    <button type="submit" class="btn btn-sm btn-outline-danger" title="Eliminar"><i class="fas fa-trash"></i></button>
                                </form>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if (empty($instituciones)): ?>
                        <tr><td colspan="5" class="text-center text-muted py-4">No hay instituciones registradas.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Modal: Editar Institución -->
    <div class="modal fade" id="modalEditar" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog">
            <form method="POST" class="modal-content">
                <input type="hidden" name="accion" value="editar">
                <input type="hidden" name="id" id="edit_id">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title"><i class="fas fa-edit"></i> Editar Institución</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label>Nombre del Colegio/Institución</label>
                        <input type="text" name="nombre_ce" id="edit_nombre_ce" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label>Subdominio (Acceso)</label>
                        <input type="text" name="subdominio" id="edit_subdominio" class="form-control" required>
                        <small class="text-muted">Cambiar el subdominio cambia la URL con la que la institución accede a la plataforma.</small>
                    </div>
                    <div class="mb-3">
                        <label>Email de Contacto</label>
                        <input type="email" name="email" id="edit_email" class="form-control">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Guardar cambios</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        function abrirEditar(inst) {
            document.getElementById('edit_id').value = inst.id;
            document.getElementById('edit_nombre_ce').value = inst.nombre_ce;
            document.getElementById('edit_subdominio').value = inst.subdominio;
            document.getElementById('edit_email').value = inst.email || '';
            new bootstrap.Modal(document.getElementById('modalEditar')).show();
        }

        // Vista previa del subdominio en el formulario de "Nueva Institución"
        const subdominioInput = document.querySelector('input[name="subdominio"]');
        const preview = document.getElementById('url-preview');
        if (subdominioInput && preview) {
            subdominioInput.addEventListener('input', () => {
                preview.textContent = subdominioInput.value || 'sanjose';
            });
        }
    </script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
