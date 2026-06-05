<?php
session_start();
require_once __DIR__ . '/../config/db_global.php';

if (!isset($_SESSION['rol']) || $_SESSION['rol'] !== 'superadmin') {
    header("Location: " . url('/superadmin/login.php'));
    exit;
}

$db = (new DatabaseGlobal())->getConnection();
$mensaje = '';

// Procesar Crear Institución
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['accion'])) {
    if ($_POST['accion'] == 'crear') {
        $nombre = $_POST['nombre_ce'];
        $subdominio = $_POST['subdominio'];
        $email = $_POST['email'];
        
        try {
            $stmt = $db->prepare("INSERT INTO tbl_institucion (nombre_ce, subdominio, email_contacto, estado) VALUES (?, ?, ?, 'activo')");
            $stmt->execute([$nombre, $subdominio, $email]);
            $mensaje = 'Institución creada con éxito.';
        } catch (PDOException $e) {
            $mensaje = 'Error: ' . $e->getMessage();
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

        <?php if($mensaje): ?><div class="alert alert-info"><?= $mensaje ?></div><?php endif; ?>

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
                        <?php foreach($instituciones as $inst): ?>
                        <tr>
                            <td><?= $inst['id'] ?></td>
                            <td><strong><?= htmlspecialchars($inst['nombre_ce']) ?></strong></td>
                            <td><span class="badge bg-info"><?= htmlspecialchars($inst['subdominio']) ?></span></td>
                            <td><?= $inst['estado'] == 'activo' ? '<span class="text-success">Activo</span>' : '<span class="text-danger">Inactivo</span>' ?></td>
                            <td>
                                <button class="btn btn-sm btn-warning">Editar</button>
                                <button class="btn btn-sm btn-danger">Suspender</button>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</body>
</html>