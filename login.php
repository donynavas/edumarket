<?php
session_start();
require_once __DIR__ . '/config/app.php';
require_once __DIR__ . '/config/database.php';

// Ya autenticado
if (isset($_SESSION['user_id'])) {
    redirect('/index.php');
}

// Conexión + tenant
$database  = new Database();
$db        = $database->getConnection();
$tenantId  = TenantManager::getId();
$tenantName= TenantManager::getName();

$error = '';

// Verificar que haya institución configurada
if (!$tenantId) {
    $error = 'No hay ninguna institución activa configurada. Contacte al administrador.';
}

// ===== PROCESAR LOGIN =====
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $tenantId) {
    $usuario  = trim($_POST['usuario'] ?? '');
    $password = $_POST['password'] ?? '';

    if (empty($usuario) || empty($password)) {
        $error = 'Por favor complete todos los campos.';
    } else {
        try {
            $stmt = $db->prepare("
                SELECT u.id, u.usuario, u.password, u.rol, u.estado,
                       CONCAT(COALESCE(p.primer_nombre,''), ' ', COALESCE(p.primer_apellido,'')) AS nombre_completo
                FROM tbl_usuario u
                LEFT JOIN tbl_persona p ON u.id_persona = p.id
                WHERE u.usuario = :usuario
                  AND u.id_institucion = :id_inst
                  AND u.estado = 1
                LIMIT 1
            ");
            $stmt->execute([':usuario' => $usuario, ':id_inst' => $tenantId]);
            $user = $stmt->fetch();

            if ($user && password_verify($password, $user['password'])) {
                session_regenerate_id(true);

                // Actualizar último acceso
                $db->prepare("UPDATE tbl_usuario SET ultimo_acceso = NOW() WHERE id = :id")
                   ->execute([':id' => $user['id']]);

                $_SESSION['user_id']       = (int)$user['id'];
                $_SESSION['usuario']       = $user['usuario'];
                $_SESSION['rol']           = $user['rol'];
                $_SESSION['nombre']        = trim($user['nombre_completo']) ?: $user['usuario'];
                $_SESSION['id_institucion']= $tenantId;

                // Redirigir según rol
                $destinos = [
                    'admin'     => '/modules/dashboard/admin_dashboard.php',
                    'director'  => '/modules/dashboard/admin_dashboard.php',
                    'orientador'=> '/modules/admin/bienestar_estudiantil.php',
                    'profesor'  => '/modules/profesor/profesor_dashboard.php',
                    'estudiante'=> '/modules/estudiante/estudiante_dashboard.php',
                ];
                redirect($destinos[$user['rol']] ?? '/index.php');

            } else {
                $error = 'Usuario o contraseña incorrectos, o cuenta inactiva.';
            }
        } catch (PDOException $e) {
            error_log('[Login] ' . $e->getMessage());
            $error = 'Error de conexión. Intente nuevamente.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title>Iniciar Sesión — <?= e($tenantName) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">
    <style>
        :root { --primary: #2c3e50; --brand: #3498db; }
        body {
            background: linear-gradient(135deg, var(--primary) 0%, var(--brand) 100%);
            min-height: 100vh;
            display: flex; align-items: center; justify-content: center;
            font-family: 'Segoe UI', system-ui, sans-serif;
            padding: 1rem;
        }
        .login-card {
            max-width: 430px; width: 100%;
            border-radius: 1.25rem;
            box-shadow: 0 20px 60px rgba(0,0,0,.35);
            overflow: hidden; background: white;
        }
        .login-header {
            background: linear-gradient(135deg, var(--primary), var(--brand));
            padding: 2.5rem 2rem 2rem; text-align: center; color: white;
        }
        .login-header .icon {
            width: 72px; height: 72px; border-radius: 50%;
            background: rgba(255,255,255,.2);
            display: flex; align-items: center; justify-content: center;
            margin: 0 auto 1rem; font-size: 2rem;
        }
        .login-header h4 { font-weight: 700; margin: 0 0 .25rem; }
        .login-header p  { opacity: .8; font-size: .9rem; margin: 0; }
        .login-body { padding: 2rem; }
        .form-label { font-size: .8rem; font-weight: 700; color: #6b7280; text-transform: uppercase; }
        .input-group-text { background: #f8fafc; border-right: 0; }
        .form-control { border-left: 0; }
        .form-control:focus, .form-control:focus + .input-group-text {
            border-color: var(--brand);
            box-shadow: 0 0 0 .2rem rgba(52,152,219,.2);
        }
        .btn-login {
            background: linear-gradient(135deg, var(--primary), var(--brand));
            border: none; color: white; padding: .85rem;
            font-weight: 700; border-radius: .65rem; width: 100%;
            transition: all .2s;
        }
        .btn-login:hover {
            filter: brightness(1.1); transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(52,152,219,.4); color: white;
        }
        .login-footer { padding: 1rem 2rem 1.5rem; text-align: center;
                         border-top: 1px solid #f1f5f9; }
        .toggle-pass { cursor: pointer; background: #f8fafc; border-left: 0; }
    </style>
</head>
<body>
<div class="login-card">
    <div class="login-header">
        <div class="icon"><i class="fas fa-graduation-cap"></i></div>
        <h4>Educación Plus</h4>
        <p><?= e($tenantName) ?></p>
    </div>
    <div class="login-body">
        <?php if ($error): ?>
        <div class="alert alert-danger py-2 small d-flex align-items-center gap-2">
            <i class="fas fa-triangle-exclamation"></i>
            <?= e($error) ?>
        </div>
        <?php endif; ?>

        <form method="POST" autocomplete="off" onsubmit="this.querySelector('[type=submit]').disabled=true">
            <div class="mb-3">
                <label class="form-label">Usuario</label>
                <div class="input-group">
                    <span class="input-group-text"><i class="fas fa-user text-muted"></i></span>
                    <input type="text" name="usuario" class="form-control"
                           placeholder="Ingrese su usuario"
                           value="<?= e($_POST['usuario'] ?? '') ?>"
                           required autofocus autocomplete="username">
                </div>
            </div>
            <div class="mb-4">
                <label class="form-label">Contraseña</label>
                <div class="input-group">
                    <span class="input-group-text"><i class="fas fa-lock text-muted"></i></span>
                    <input type="password" name="password" id="pwd" class="form-control"
                           placeholder="Ingrese su contraseña" required autocomplete="current-password">
                    <button type="button" class="btn btn-outline-secondary toggle-pass"
                            onclick="togglePwd()">
                        <i class="fas fa-eye" id="eyeI"></i>
                    </button>
                </div>
            </div>
            <button type="submit" class="btn-login">
                <i class="fas fa-right-to-bracket me-2"></i> Ingresar al Sistema
            </button>
        </form>
    </div>
    <div class="login-footer">
        <small class="text-muted">
            <i class="fas fa-shield-halved me-1"></i>
            © <?= date('Y') ?> Educación Plus &bull;
            <a href="<?= url('/superadmin/login.php') ?>" class="text-muted">Admin Sistema</a>
        </small>
    </div>
</div>
<script>
function togglePwd(){
    const f=document.getElementById('pwd'),e=document.getElementById('eyeI');
    f.type=f.type==='password'?'text':'password';
    e.className=f.type==='password'?'fas fa-eye':'fas fa-eye-slash';
}
</script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
