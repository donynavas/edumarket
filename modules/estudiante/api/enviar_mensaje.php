<?php
session_start();
require_once __DIR__ . '/../../../config/database.php';

header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['user_id']) || $_SESSION['rol'] != 'estudiante') {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'No autorizado']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Método no permitido']);
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
$idEstudiante = (int) $stmt->fetchColumn();

if (!$idEstudiante) {
    http_response_code(404);
    echo json_encode(['success' => false, 'error' => 'Perfil de estudiante no encontrado']);
    exit;
}

$asunto = trim($_POST['asunto'] ?? '');
$cuerpo = trim($_POST['cuerpo'] ?? '');
$idProfesor = filter_input(INPUT_POST, 'id_profesor', FILTER_VALIDATE_INT);
$idMensajePadre = filter_input(INPUT_POST, 'id_mensaje_padre', FILTER_VALIDATE_INT) ?: null;

if ($asunto === '' || $cuerpo === '') {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'El asunto y el mensaje no pueden estar vacíos.']);
    exit;
}
if (mb_strlen($asunto) > 200) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'El asunto es demasiado largo (máximo 200 caracteres).']);
    exit;
}

if (!$idProfesor || !MensajeHelper::puedeEstudianteEscribirProfesor($db, $idEstudiante, $idProfesor)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Solo puedes escribirle a profesores que te dan clase.']);
    exit;
}

// El id_usuario destinatario se resuelve en el servidor, nunca se confía en
// uno que mande el cliente directamente.
$stmtU = $db->prepare("SELECT u.id FROM tbl_profesor pf
                        JOIN tbl_persona per ON pf.id_persona = per.id
                        JOIN tbl_usuario u ON per.id_usuario = u.id
                        WHERE pf.id = :id");
$stmtU->execute([':id' => $idProfesor]);
$idUsuarioDestino = $stmtU->fetchColumn();
if (!$idUsuarioDestino) {
    http_response_code(404);
    echo json_encode(['success' => false, 'error' => 'No se encontró el usuario del profesor.']);
    exit;
}

if ($idMensajePadre) {
    // :uid1/:uid2 -- mismo valor repetido dos veces; con prepares nativos
    // (PDO::ATTR_EMULATE_PREPARES=false) no se puede reutilizar un mismo
    // placeholder con nombre más de una vez en la misma consulta.
    $chk = $db->prepare("SELECT 1 FROM tbl_mensaje m
                          LEFT JOIN tbl_mensaje_destinatario d ON d.id_mensaje = m.id AND d.id_usuario_destinatario = :uid1
                          WHERE m.id = :id AND m.id_institucion = :tid AND (m.id_remitente = :uid2 OR d.id IS NOT NULL)
                          LIMIT 1");
    $chk->execute([':id' => $idMensajePadre, ':tid' => $tid, ':uid1' => $user_id, ':uid2' => $user_id]);
    if (!$chk->fetchColumn()) {
        $idMensajePadre = null;
    }
}

try {
    $db->beginTransaction();

    $stmt = $db->prepare("INSERT INTO tbl_mensaje (id_institucion, id_remitente, asunto, cuerpo, tipo, id_mensaje_padre)
                           VALUES (:tid, :remitente, :asunto, :cuerpo, 'individual', :padre)");
    $stmt->execute([
        ':tid' => $tid,
        ':remitente' => $user_id,
        ':asunto' => $asunto,
        ':cuerpo' => $cuerpo,
        ':padre' => $idMensajePadre,
    ]);
    $idMensaje = $db->lastInsertId();

    $stmtDest = $db->prepare("INSERT INTO tbl_mensaje_destinatario (id_mensaje, id_usuario_destinatario) VALUES (:mensaje, :usuario)");
    $stmtDest->execute([':mensaje' => $idMensaje, ':usuario' => $idUsuarioDestino]);

    $db->commit();
    echo json_encode(['success' => true, 'id_mensaje' => $idMensaje]);
} catch (PDOException $e) {
    $db->rollBack();
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Error de base de datos: ' . $e->getMessage()]);
}
