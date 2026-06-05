<?php
session_start();
require_once __DIR__ . '/../config/db_global.php';

// Si ya es superadmin, redirigir
if (isset($_SESSION['rol']) && $_SESSION['rol'] === 'superadmin') {
    header('Location: ' . url('/superadmin/dashboard.php'));
    exit;
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $usuario  = trim($_POST['usuario'] ?? '');
    $password = $_POST['password'] ?? '';

    if (empty($usuario) || empty($password)) {
        $error = 'Complete todos los campos.';
    } else {
        $db = (new DatabaseGlobal())->getConnection();

        $stmt = $db->prepare(
            "SELECT * FROM tbl_usuario WHERE usuario = ? AND rol = 'superadmin' AND estado = 1"
        );
        $stmt->execute([$usuario]);
        $user = $stmt->fetch();

        if ($user && password_verify($password, $user['password'])) {
            session_regenerate_id(true);
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['usuario'] = $user['usuario'];
            $_SESSION['rol']     = 'superadmin';
            $_SESSION['nombre']  = $user['nombre'] ?: 'Super Admin';
            header('Location: ' . url('/superadmin/dashboard.php'));
            exit;
        } else {
            $error = 'Credenciales incorrectas o sin privilegios de Super Admin.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Super Admin — Educación Plus</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">
    <style>
        body { background:#2c3e50; display:flex; align-items:center; justify-content:center; min-height:100vh; font-family:'Segoe UI',system-ui,sans-serif; }
        .box { background:white; padding:2.5rem; border-radius:1rem; width:400px; box-shadow:0 20px 50px rgba(0,0,0,.5); }
        .shield { font-size:3rem; color:#e74c3c; text-align:center; margin-bottom:1rem; }
        .btn-primary { background:#e74c3c; border:none; }
        .btn-primary:hover { background:#c0392b; }
    </style>
</head>
<body>
<div class="box">
    <div class="shield"><i class="fas fa-shield-halved"></i></div>
    <h5 class="text-center fw-bold mb-1">Acceso Restringido</h5>
    <p class="text-center text-muted small mb-4">Super Admin — Educación Plus</p>

    <?php if ($error): ?>
    <div class="alert alert-danger py-2 small"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <form method="POST">
        <div class="mb-3">
            <label class="form-label fw-bold small">Usuario</label>
            <input type="text" name="usuario" class="form-control" required autofocus>
        </div>
        <div class="mb-4">
            <label class="form-label fw-bold small">Contraseña</label>
            <input type="password" name="password" class="form-control" required>
        </div>
        <button type="submit" class="btn btn-primary w-100 py-2 fw-bold">
            <i class="fas fa-right-to-bracket me-2"></i> Ingresar
        </button>
    </form>
    <div class="text-center mt-3">
        <a href="<?= url('/login.php') ?>" class="text-muted small">
            <i class="fas fa-arrow-left me-1"></i> Volver al login
        </a>
    </div>
</div>
</body>
</html>
