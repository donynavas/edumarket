<?php
session_start();
require_once __DIR__ . '/../../config/database.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'No autorizado']);
    exit;
}

$database = new Database();
$db = $database->getConnection();
$id = $_GET['id'] ?? 0;

$query = "SELECT * FROM tbl_actividad WHERE id = :id AND tipo = 'examen'";
$stmt = $db->prepare($query);
$stmt->bindValue(':id', $id, PDO::PARAM_INT);
$stmt->execute();
$examen = $stmt->fetch(PDO::FETCH_ASSOC);

if ($examen) {
    echo json_encode(['success' => true, 'examen' => $examen]);
} else {
    echo json_encode(['success' => false, 'message' => 'Examen no encontrado']);
}
?>