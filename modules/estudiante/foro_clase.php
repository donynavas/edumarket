<?php
/**
 * Foro de una clase puntual (tbl_clase_impartida), lado estudiante. Se
 * llega aquí desde la lista de "Clases" en ver_materia.php. El acceso se
 * valida SIEMPRE contra la matrícula activa del estudiante (nunca se
 * confía en el id_clase de la URL) vía ForoHelper::estudianteAccesoClase().
 */
session_start();
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/TenantGuard.php';
require_once __DIR__ . '/../../config/ForoHelper.php';
require_once __DIR__ . '/../../config/MensajeHelper.php';

if (!isset($_SESSION['user_id']) || $_SESSION['rol'] != 'estudiante') {
    header("Location: " . BASE_URL . "/login.php");
    exit;
}

$database = new Database();
$db = $database->getConnection();
$user_id = $_SESSION['user_id'];
$tid = TenantGuard::id();

$stmtEst = $db->prepare("SELECT e.id AS id_estudiante, per.primer_nombre
                          FROM tbl_estudiante e
                          JOIN tbl_persona per ON e.id_persona = per.id
                          WHERE per.id_usuario = :uid AND e.id_institucion = :tid");
$stmtEst->execute([':uid' => $user_id, ':tid' => $tid]);
$estudianteInfo = $stmtEst->fetch(PDO::FETCH_ASSOC);
if (!$estudianteInfo) {
    header("Location: " . BASE_URL . "/index.php");
    exit;
}
$id_estudiante = (int) $estudianteInfo['id_estudiante'];
$totalNoLeidos = MensajeHelper::contarNoLeidos($db, (int) $user_id);

$id_clase = filter_input(INPUT_GET, 'id_clase', FILTER_VALIDATE_INT) ?: (int) ($_POST['id_clase'] ?? 0);

$mensajeFlash = '';
$tipoFlash = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['accion'] ?? '') === 'publicar_foro_mensaje') {
    $claseCheck = ForoHelper::estudianteAccesoClase($db, $id_clase, $id_estudiante, $tid);
    if (!$claseCheck) {
        http_response_code(403);
        die('No tiene permiso para publicar en el foro de esta clase.');
    }
    try {
        ForoHelper::publicar($db, $tid, $id_clase, $user_id, 'estudiante', $_POST['mensaje'] ?? '');
        $mensajeFlash = 'Mensaje publicado.';
        $tipoFlash = 'success';
    } catch (Exception $e) {
        $mensajeFlash = 'Error: ' . $e->getMessage();
        $tipoFlash = 'danger';
    }
}

$clase = ForoHelper::estudianteAccesoClase($db, $id_clase, $id_estudiante, $tid);
if (!$clase) {
    ?>
    <!DOCTYPE html>
    <html lang="es">
    <head>
        <meta charset="UTF-8">
        <title>Acceso Denegado - Educación Plus</title>
        <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    </head>
    <body class="bg-light">
        <div class="container mt-5">
            <div class="card shadow text-center py-5">
                <div class="card-body">
                    <i class="fas fa-lock fa-4x text-warning mb-3"></i>
                    <h4>Acceso Denegado</h4>
                    <p class="text-muted">No tienes acceso al foro de esta clase.</p>
                    <a href="mis_clases.php" class="btn btn-primary">Volver a Mis Clases</a>
                </div>
            </div>
        </div>
    </body>
    </html>
    <?php
    exit;
}

$mensajesForo = ForoHelper::mensajesDeClase($db, $id_clase);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Foro - <?= htmlspecialchars($clase['asignatura_nombre']) ?> - Educación Plus</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <style>
        :root { --primary: #4361ee; --secondary: #3f37c9; --sidebar-width: 260px; }
        body { font-family: 'Segoe UI', sans-serif; background: #f5f7fa; }
        .sidebar { position: fixed; top: 0; left: 0; height: 100vh; width: var(--sidebar-width); background: linear-gradient(180deg, #1d3557, #2a4365); color: white; z-index: 1000; }
        .sidebar .nav-link { color: rgba(255,255,255,0.85); padding: 12px 20px; border-radius: 8px; margin: 2px 0; }
        .sidebar .nav-link:hover, .sidebar .nav-link.active { background: rgba(255,255,255,0.15); color: white; }
        .main-content { margin-left: var(--sidebar-width); padding: 20px 30px; }
        .card-custom { background: white; border-radius: 12px; box-shadow: 0 2px 12px rgba(0,0,0,0.08); margin-bottom: 20px; }
        .mensaje-item { border-left: 3px solid var(--secondary); padding: 8px 12px; margin-bottom: 10px; }
        .mensaje-item.propio { border-left-color: #2ecc71; background: #f4fdf7; }
        @media (max-width: 992px) { .sidebar { transform: translateX(-100%); } .main-content { margin-left: 0; } }
    </style>
</head>
<body>
    <aside class="sidebar">
        <div class="text-center p-3 border-bottom">
            <h5><i class="fas fa-graduation-cap"></i> Educación Plus</h5>
        </div>
        <nav class="nav flex-column p-2">
            <a class="nav-link" href="../../index.php"><i class="fas fa-home"></i> Dashboard</a>
            <a class="nav-link" href="mis_clases.php"><i class="fas fa-book"></i> Mis Clases</a>
            <a class="nav-link" href="actividades.php"><i class="fas fa-tasks"></i> Actividades</a>
            <a class="nav-link" href="mis_notas.php"><i class="fas fa-star"></i> Calificaciones</a>
            <a class="nav-link" href="mensajes.php">
                <i class="fas fa-envelope"></i> Mensajes
                <?php if ($totalNoLeidos > 0): ?><span class="badge bg-danger rounded-pill float-end"><?= $totalNoLeidos ?></span><?php endif; ?>
            </a>
            <a class="nav-link" href="../../logout.php"><i class="fas fa-sign-out-alt"></i> Cerrar Sesión</a>
        </nav>
    </aside>

    <main class="main-content">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <div>
                <h2><i class="fas fa-comments"></i> Foro de la Clase</h2>
                <p class="text-muted mb-0">
                    <?= htmlspecialchars($clase['asignatura_nombre']) ?> —
                    <?= htmlspecialchars($clase['grado_nombre']) ?> <?= htmlspecialchars($clase['seccion_nombre']) ?>
                    <?php if ($clase['numero_clase']): ?> · Clase <?= htmlspecialchars($clase['numero_clase']) ?><?php endif; ?>
                    · <?= date('d/m/Y', strtotime($clase['fecha_clase'])) ?>
                </p>
            </div>
            <a href="ver_materia.php?id_asignacion=<?= (int) $clase['id_asignacion_docente'] ?>" class="btn btn-outline-secondary btn-sm"><i class="fas fa-arrow-left"></i> Volver a la materia</a>
        </div>

        <?php if ($mensajeFlash): ?>
        <div class="alert alert-<?= $tipoFlash ?> alert-dismissible fade show">
            <?= htmlspecialchars($mensajeFlash) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php endif; ?>

        <?php if ($clase['objetivo']): ?>
        <div class="card-custom p-3">
            <h6 class="text-muted mb-1"><i class="fas fa-bullseye"></i> Objetivo de esta clase</h6>
            <div><?= $clase['objetivo'] ?></div>
        </div>
        <?php endif; ?>

        <div class="card-custom p-4">
            <h5 class="mb-3">Mensajes</h5>
            <?php if (empty($mensajesForo)): ?>
            <p class="text-muted">Todavía no hay mensajes. ¡Sé el primero en compartir algo!</p>
            <?php else: ?>
            <?php foreach ($mensajesForo as $fm): ?>
            <div class="mensaje-item <?= (int) $fm['id_usuario'] === (int) $user_id ? 'propio' : '' ?>">
                <div class="d-flex justify-content-between">
                    <strong class="small"><?= htmlspecialchars(trim($fm['primer_nombre'] . ' ' . $fm['primer_apellido'])) ?></strong>
                    <span class="text-muted small"><?= date('d/m/Y h:i A', strtotime($fm['created_at'])) ?></span>
                </div>
                <span class="badge <?= $fm['autor_rol'] === 'profesor' ? 'bg-primary' : 'bg-secondary' ?> mb-1"><?= $fm['autor_rol'] === 'profesor' ? 'Docente' : 'Estudiante' ?></span>
                <div style="white-space: pre-wrap;"><?= htmlspecialchars($fm['mensaje']) ?></div>
            </div>
            <?php endforeach; ?>
            <?php endif; ?>

            <form method="POST" class="mt-3">
                <input type="hidden" name="accion" value="publicar_foro_mensaje">
                <input type="hidden" name="id_clase" value="<?= (int) $clase['id'] ?>">
                <div class="input-group">
                    <textarea name="mensaje" class="form-control" rows="2" maxlength="3000" placeholder="Escribe un mensaje..." required></textarea>
                    <button type="submit" class="btn btn-primary"><i class="fas fa-paper-plane"></i> Publicar</button>
                </div>
            </form>
        </div>
    </main>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
