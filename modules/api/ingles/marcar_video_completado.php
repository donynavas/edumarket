<?php
session_start();
require_once __DIR__ . '/../../../config/database.php';
require_once __DIR__ . '/../../../config/TenantGuard.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'No autorizado']);
    exit;
}

// ✅ Corregido: faltaba instanciar la conexión ($db no existía) y el join
// usaba u.id_persona, columna que no existe en tbl_usuario (la relación real
// es tbl_persona.id_usuario -> tbl_usuario.id).
$tid = TenantGuard::id();
$database = new Database();
$db = $database->getConnection();

$id_video = $_POST['id_video'] ?? 0;
$id_leccion = $_POST['id_leccion'] ?? 0;
$user_id = $_SESSION['user_id'];

// Obtener ID del estudiante a partir de la sesión (nunca confiar en un id
// enviado por el cliente), acotado a la institución actual.
$query = "SELECT e.id FROM tbl_estudiante e
          JOIN tbl_persona p ON e.id_persona = p.id
          WHERE p.id_usuario = :user_id AND e.id_institucion = :tid";
$stmt = $db->prepare($query);
$stmt->bindValue(':user_id', $user_id, PDO::PARAM_INT);
$stmt->bindValue(':tid', $tid, PDO::PARAM_INT);
$stmt->execute();
$estudiante = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$estudiante) {
    echo json_encode(['success' => false, 'message' => 'Estudiante no encontrado']);
    exit;
}

// Registrar progreso. tbl_ingles_progreso no tiene columna id_institucion;
// id_estudiante ya se resolvió arriba vía la sesión (tenant-verificado).
$query = "INSERT INTO tbl_ingles_progreso (id_estudiante, id_leccion, estado, puntaje, ultimo_intento)
          VALUES (:id_estudiante, :id_leccion, 'completado', 10, NOW())
          ON DUPLICATE KEY UPDATE estado = 'completado', ultimo_intento = NOW()";
$stmt = $db->prepare($query);
$stmt->bindValue(':id_estudiante', $estudiante['id'], PDO::PARAM_INT);
$stmt->bindValue(':id_leccion', $id_leccion, PDO::PARAM_INT);
$stmt->execute();

// Registrar like/vista
$query = "UPDATE tbl_ingles_video SET likes = likes + 1 WHERE id = :id";
$stmt = $db->prepare($query);
$stmt->bindValue(':id', $id_video, PDO::PARAM_INT);
$stmt->execute();

echo json_encode(['success' => true, 'message' => 'Progreso guardado']);
?>