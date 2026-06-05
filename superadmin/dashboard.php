<?php
session_start();
require_once __DIR__ . '/../config/db_global.php';

// Verificar Super Admin
if (!isset($_SESSION['rol']) || $_SESSION['rol'] !== 'superadmin') {
    header("Location: " . url('/superadmin/login.php'));
    exit;
}

$db = (new DatabaseGlobal())->getConnection();

// Estadísticas Globales
$total_instituciones = $db->query("SELECT COUNT(*) FROM tbl_institucion WHERE estado='activo'")->fetchColumn();
$total_estudiantes = $db->query("SELECT COUNT(*) FROM tbl_estudiante")->fetchColumn();
$total_profesores = $db->query("SELECT COUNT(*) FROM tbl_profesor")->fetchColumn();
$total_usuarios = $db->query("SELECT COUNT(*) FROM tbl_usuario")->fetchColumn();
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Super Admin - Dashboard</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        body { background: #f4f6f9; }
        .sidebar { min-height: 100vh; background: #343a40; color: white; }
        .sidebar a { color: #c2c7d0; text-decoration: none; padding: 10px 20px; display: block; }
        .sidebar a:hover, .sidebar a.active { background: #007bff; color: white; }
        .stat-card { border-radius: 10px; color: white; padding: 20px; }
    </style>
</head>
<body>
    <div class="container-fluid">
        <div class="row">
            <!-- Sidebar -->
            <div class="col-md-2 sidebar p-0">
                <div class="p-3 text-center bg-dark">
                    <h5>🛡️ Super Admin</h5>
                </div>
                <a href="dashboard.php" class="active"><i class="fas fa-tachometer-alt"></i> Dashboard</a>
                <a href="instituciones.php"><i class="fas fa-university"></i> Instituciones</a>
                <a href="usuarios.php"><i class="fas fa-users-cog"></i> Usuarios Globales</a>
                <a href="../login.php" class="text-danger"><i class="fas fa-sign-out-alt"></i> Salir al Login</a>
            </div>

            <!-- Content -->
            <div class="col-md-10 p-4">
                <h2 class="mb-4">Panel de Control del Sistema</h2>
                
                <div class="row g-3">
                    <div class="col-md-3">
                        <div class="stat-card bg-primary">
                            <h3><?= $total_instituciones ?></h3>
                            <span>Instituciones Activas</span>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="stat-card bg-success">
                            <h3><?= $total_estudiantes ?></h3>
                            <span>Total Estudiantes</span>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="stat-card bg-warning">
                            <h3><?= $total_profesores ?></h3>
                            <span>Total Profesores</span>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="stat-card bg-info">
                            <h3><?= $total_usuarios ?></h3>
                            <span>Usuarios Totales</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</body>
</html>