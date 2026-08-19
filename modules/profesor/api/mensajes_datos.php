<?php
session_start();
require_once __DIR__ . '/../../../config/database.php';

header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['user_id']) || $_SESSION['rol'] != 'profesor') {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'No autorizado']);
    exit;
}

require_once __DIR__ . '/../../../config/TenantGuard.php';
require_once __DIR__ . '/../../../config/MensajeHelper.php';

$tid = TenantGuard::id();
$database = new Database();
$db = $database->getConnection();
$user_id = $_SESSION['user_id'];

$stmt = $db->prepare("SELECT p.id AS id_profesor FROM tbl_profesor p
                       JOIN tbl_persona per ON p.id_persona = per.id
                       WHERE per.id_usuario = :user_id AND p.id_institucion = :tid");
$stmt->execute([':user_id' => $user_id, ':tid' => $tid]);
$idProfesor = $stmt->fetchColumn();

if (!$idProfesor) {
    http_response_code(404);
    echo json_encode(['success' => false, 'error' => 'Perfil de profesor no encontrado']);
    exit;
}

$estudiantes = MensajeHelper::estudiantesDeProfesor($db, (int) $idProfesor);
$secciones = MensajeHelper::seccionesDeProfesor($db, (int) $idProfesor);

echo json_encode([
    'success' => true,
    'estudiantes' => $estudiantes,
    'secciones' => $secciones,
], JSON_UNESCAPED_UNICODE);
