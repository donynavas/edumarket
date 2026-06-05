<?php
session_start();
require_once __DIR__ . '/../../../config/database.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'No autorizado']);
    exit;
}

$id_video = $_POST['id_video'] ?? 0;
$id_leccion = $_POST['id_leccion'] ?? 0;
$user_id = $_SESSION['user_id'];

// Obtener ID del estudiante
$query = "SELECT id FROM tbl_estudiante WHERE id_persona = (
          SELECT id_persona FROM tbl_usuario WHERE id = :user_id)";
$stmt = $db->prepare($query);
$stmt->bindValue(':user_id', $user_id, PDO::PARAM_INT);
$stmt->execute();
$estudiante = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$estudiante) {
    echo json_encode(['success' => false, 'message' => 'Estudiante no encontrado']);
    exit;
}

// Registrar progreso
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