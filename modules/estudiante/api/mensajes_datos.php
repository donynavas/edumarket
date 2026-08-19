<?php
session_start();
require_once __DIR__ . '/../../../config/database.php';

header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['user_id']) || $_SESSION['rol'] != 'estudiante') {
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

$stmt = $db->prepare("SELECT e.id AS id_estudiante FROM tbl_estudiante e
                       JOIN tbl_persona per ON e.id_persona = per.id
                       WHERE per.id_usuario = :user_id AND e.id_institucion = :tid");
$stmt->execute([':user_id' => $user_id, ':tid' => $tid]);
$idEstudiante = $stmt->fetchColumn();

if (!$idEstudiante) {
    http_response_code(404);
    echo json_encode(['success' => false, 'error' => 'Perfil de estudiante no encontrado']);
    exit;
}

$profesores = MensajeHelper::profesoresDeEstudiante($db, (int) $idEstudiante);

echo json_encode(['success' => true, 'profesores' => $profesores], JSON_UNESCAPED_UNICODE);
