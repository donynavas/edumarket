<?php
session_start();
require_once __DIR__ . '/../../../config/database.php';

header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['user_id']) || $_SESSION['rol'] != 'profesor') {
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

$stmt = $db->prepare("SELECT p.id AS id_profesor FROM tbl_profesor p
                       JOIN tbl_persona per ON p.id_persona = per.id
                       WHERE per.id_usuario = :user_id AND p.id_institucion = :tid");
$stmt->execute([':user_id' => $user_id, ':tid' => $tid]);
$idProfesor = (int) $stmt->fetchColumn();

if (!$idProfesor) {
    http_response_code(404);
    echo json_encode(['success' => false, 'error' => 'Perfil de profesor no encontrado']);
    exit;
}

$tipo = ($_POST['tipo'] ?? '') === 'seccion' ? 'seccion' : 'individual';
$asunto = trim($_POST['asunto'] ?? '');
$cuerpo = trim($_POST['cuerpo'] ?? '');
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

// Si es respuesta a otro mensaje, ese mensaje debe ser uno que este
// profesor de verdad pueda ver (remitente o destinatario) -- igual chequeo
// que hace leer_mensaje.php, para no permitir "colgar" mensajes de un hilo
// ajeno adivinando su id.
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
        $idMensajePadre = null; // referencia inválida -- se ignora en vez de romper el envío
    }
}

$idsUsuarioDestino = [];
$idSeccionDestino = null;

if ($tipo === 'individual') {
    $idEstudiante = filter_input(INPUT_POST, 'id_estudiante', FILTER_VALIDATE_INT);
    if (!$idEstudiante || !MensajeHelper::puedeProfesorEscribirEstudiante($db, $idProfesor, $idEstudiante)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Solo puedes escribirle a estudiantes de tus propias secciones.']);
        exit;
    }
    // El id_usuario destinatario SIEMPRE se resuelve en el servidor a
    // partir del id_estudiante ya validado -- nunca se confía en un
    // id_usuario que mande el cliente directamente.
    $stmtU = $db->prepare("SELECT u.id FROM tbl_estudiante e
                            JOIN tbl_persona per ON e.id_persona = per.id
                            JOIN tbl_usuario u ON per.id_usuario = u.id
                            WHERE e.id = :id");
    $stmtU->execute([':id' => $idEstudiante]);
    $idUsuarioDestino = $stmtU->fetchColumn();
    if (!$idUsuarioDestino) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'No se encontró el usuario del estudiante.']);
        exit;
    }
    $idsUsuarioDestino = [(int) $idUsuarioDestino];
} else {
    $idSeccion = filter_input(INPUT_POST, 'id_seccion', FILTER_VALIDATE_INT);
    if (!$idSeccion || !MensajeHelper::puedeProfesorEscribirSeccion($db, $idProfesor, $idSeccion)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Solo puedes enviar avisos a secciones donde impartes clase.']);
        exit;
    }
    $idsUsuarioDestino = array_map('intval', MensajeHelper::estudiantesDeSeccion($db, $idSeccion));
    if (empty($idsUsuarioDestino)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Esa sección no tiene estudiantes matriculados activos este año.']);
        exit;
    }
    $idSeccionDestino = $idSeccion;
}

try {
    $db->beginTransaction();

    $stmt = $db->prepare("INSERT INTO tbl_mensaje (id_institucion, id_remitente, asunto, cuerpo, tipo, id_seccion_destino, id_mensaje_padre)
                           VALUES (:tid, :remitente, :asunto, :cuerpo, :tipo, :seccion, :padre)");
    $stmt->execute([
        ':tid' => $tid,
        ':remitente' => $user_id,
        ':asunto' => $asunto,
        ':cuerpo' => $cuerpo,
        ':tipo' => $tipo,
        ':seccion' => $idSeccionDestino,
        ':padre' => $idMensajePadre,
    ]);
    $idMensaje = $db->lastInsertId();

    $stmtDest = $db->prepare("INSERT INTO tbl_mensaje_destinatario (id_mensaje, id_usuario_destinatario) VALUES (:mensaje, :usuario)");
    foreach (array_unique($idsUsuarioDestino) as $idUsuario) {
        $stmtDest->execute([':mensaje' => $idMensaje, ':usuario' => $idUsuario]);
    }

    $db->commit();
    echo json_encode(['success' => true, 'id_mensaje' => $idMensaje, 'destinatarios' => count(array_unique($idsUsuarioDestino))]);
} catch (PDOException $e) {
    $db->rollBack();
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Error de base de datos: ' . $e->getMessage()]);
}
