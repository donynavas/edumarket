<?php
session_start();
require_once __DIR__ . '/config/app.php';

if (!isset($_SESSION['user_id'])) {
    redirect('/welcome.php');
}

$rol = $_SESSION['rol'];

switch ($rol) {
    case 'superadmin':
        redirect('/superadmin/dashboard.php');
        break;
    case 'admin':
    case 'director':
    case 'orientador':
        redirect('/modules/dashboard/admin_dashboard.php');
        break;
    case 'profesor':
        redirect('/modules/profesor/profesor_dashboard.php');
        break;
    case 'estudiante':
        redirect('/modules/estudiante/estudiante_dashboard.php');
        break;
    default:
        session_destroy();
        redirect('/login.php');
}
