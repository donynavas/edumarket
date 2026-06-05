<?php
session_start();
require_once __DIR__ . '/../../../config/database.php';
header('Content-Type: application/json');

if (!isset($_SESSION['user_id']) || $_SESSION['rol'] !== 'profesor') {
    echo json_encode(['error' => 'No autorizado']); exit;
}

$db = (new Database())->getConnection();
$id_asignacion = $_GET['id_asignacion'] ?? $_POST['id_asignacion'] ?? 0;
$mensaje = $_POST['mensaje'] ?? '';

// Crear tabla si no existe
$db->exec("CREATE TABLE IF NOT EXISTS tbl_chat_clase (
    id INT AUTO_INCREMENT PRIMARY KEY,
    id_asignacion INT NOT NULL,
    id_usuario INT NOT NULL,
    mensaje TEXT NOT NULL,
    fecha_envio DATETIME DEFAULT NOW(),
    FOREIGN KEY (id_asignacion) REFERENCES tbl_asignacion_docente(id),
    FOREIGN KEY (id_usuario) REFERENCES tbl_usuario(id),
    INDEX idx_asig (id_asignacion)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    // Obtener mensajes
    $stmt = $db->prepare("SELECT c.*, CONCAT(p.primer_nombre, ' ', p.primer_apellido) as nombre
                          FROM tbl_chat_clase c
                          JOIN tbl_usuario u ON c.id_usuario = u.id
                          JOIN tbl_persona p ON u.id_persona = p.id
                          WHERE c.id_asignacion = :asig
                          ORDER BY c.fecha_envio ASC LIMIT 50");
    $stmt->execute([':asig' => $id_asignacion]);
    
    echo json_encode(array_map(fn($m) => [
        'sender' => $m['nombre'],
        'text' => $m['mensaje'],
        'time' => date('H:i', strtotime($m['fecha_envio']))
    ], $stmt->fetchAll(PDO::FETCH_ASSOC)));
    
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && $mensaje) {
    // Enviar mensaje
    $stmt = $db->prepare("INSERT INTO tbl_chat_clase (id_asignacion, id_usuario, mensaje) VALUES (:asig, :usr, :msg)");
    $stmt->execute([':asig' => $id_asignacion, ':usr' => $_SESSION['user_id'], ':msg' => $mensaje]);
    echo json_encode(['success' => true]);
}
?>