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

$tid = TenantGuard::id();
$database = new Database();
$db = $database->getConnection();
$user_id = $_SESSION['user_id'];

$idMensaje = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if (!$idMensaje) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'ID de mensaje requerido']);
    exit;
}

// Visible solo si el usuario es el remitente o tiene una fila propia en
// tbl_mensaje_destinatario -- además de la institución, que debe coincidir.
// id_profesor_remitente: si quien escribió es un profesor (siempre el caso
// cuando el estudiante está viendo un mensaje recibido), permite al JS
// preseleccionar el destinatario correcto al usar "Responder".
$stmt = $db->prepare("SELECT m.id, m.asunto, m.cuerpo, m.tipo, m.fecha_envio, m.id_remitente, m.id_mensaje_padre,
                       per.primer_nombre AS remitente_nombre, per.primer_apellido AS remitente_apellido,
                       s.nombre AS seccion_nombre, g.nombre AS grado_nombre,
                       d.id AS id_destinatario_row, d.leido,
                       pf.id AS id_profesor_remitente
                       FROM tbl_mensaje m
                       JOIN tbl_usuario ru ON m.id_remitente = ru.id
                       JOIN tbl_persona per ON per.id_usuario = ru.id
                       LEFT JOIN tbl_profesor pf ON pf.id_persona = per.id
                       LEFT JOIN tbl_seccion s ON m.id_seccion_destino = s.id
                       LEFT JOIN tbl_grado g ON s.id_grado = g.id
                       LEFT JOIN tbl_mensaje_destinatario d ON d.id_mensaje = m.id AND d.id_usuario_destinatario = :uid1
                       WHERE m.id = :id AND m.id_institucion = :tid
                       AND (m.id_remitente = :uid2 OR d.id IS NOT NULL)");
// Nota: :uid1/:uid2 son el mismo valor repetido dos veces -- con
// PDO::ATTR_EMULATE_PREPARES=false (ver config/database.php) MySQL usa
// prepares nativos, que NO permiten reutilizar el mismo placeholder con
// nombre más de una vez en la misma consulta (a diferencia del modo
// emulado). Hay que darle un nombre distinto a cada aparición.
$stmt->execute([':id' => $idMensaje, ':tid' => $tid, ':uid1' => $user_id, ':uid2' => $user_id]);
$msg = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$msg) {
    http_response_code(404);
    echo json_encode(['success' => false, 'error' => 'Mensaje no encontrado']);
    exit;
}

// Si el que consulta es un destinatario y todavía no lo había leído, se marca ahora.
if ($msg['id_destinatario_row'] && !$msg['leido']) {
    $upd = $db->prepare("UPDATE tbl_mensaje_destinatario SET leido = 1, fecha_lectura = NOW() WHERE id = :id");
    $upd->execute([':id' => $msg['id_destinatario_row']]);
    $msg['leido'] = 1;
}

echo json_encode(['success' => true, 'data' => $msg], JSON_UNESCAPED_UNICODE);
