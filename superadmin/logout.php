<?php
session_start();
require_once __DIR__ . '/../config/app.php';
$_SESSION = [];
session_destroy();
header('Location: ' . url('/superadmin/login.php'));
exit;
