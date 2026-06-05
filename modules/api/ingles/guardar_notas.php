<?php
/**
 * API: Guardar notas del estudiante
 * Método: POST
 * Parámetros: id_video, id_estudiante, notas
 */

session_start();
header('Content-Type: application/json');
require_once __DIR__ . '/../../../config/database.php';

if (!isset($_SESSION['user_id'])) {
    echo json_encode([
        'success' => false,
        'message' => 'No autorizado'
    ]);
    exit;
}

$id_video = $_POST['id_video'] ?? 0;
$id_estudiante = $_POST['id_estudiante'] ?? 0;
$notas = $_POST['notas'] ?? '';

if (!$id_video || !$id_estudiante) {
    echo json_encode([
        'success' => false,
        'message' => 'Parámetros incompletos'
    ]);
    exit;
}

try {
    $database = new Database();
    $db = $database->getConnection();
    
    // Verificar si existe tabla de notas (crear si no existe)
    $query = "CREATE TABLE IF NOT EXISTS tbl_ingles_notas_estudiante (
              id INT AUTO_INCREMENT PRIMARY KEY,
              id_estudiante INT NOT NULL,
              id_video INT NOT NULL,
              notas TEXT,
              fecha_creacion TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
              fecha_actualizacion TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
              FOREIGN KEY (id_estudiante) REFERENCES tbl_estudiante(id) ON DELETE CASCADE,
              FOREIGN KEY (id_video) REFERENCES tbl_ingles_video(id) ON DELETE CASCADE,
              UNIQUE KEY unique_nota (id_estudiante, id_video)
              ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
    $db->exec($query);
    
    // Insertar o actualizar notas
    $query = "INSERT INTO tbl_ingles_notas_estudiante (id_estudiante, id_video, notas)
              VALUES (:id_estudiante, :id_video, :notas)
              ON DUPLICATE KEY UPDATE notas = :notas2, fecha_actualizacion = NOW()";
    
    $stmt = $db->prepare($query);
    $stmt->bindValue(':id_estudiante', $id_estudiante, PDO::PARAM_INT);
    $stmt->bindValue(':id_video', $id_video, PDO::PARAM_INT);
    $stmt->bindValue(':notas', $notas, PDO::PARAM_STR);
    $stmt->bindValue(':notas2', $notas, PDO::PARAM_STR);
    $stmt->execute();
    
    echo json_encode([
        'success' => true,
        'message' => 'Notas guardadas exitosamente',
        'data' => [
            'id_video' => $id_video,
            'fecha_guardado' => date('Y-m-d H:i:s')
        ]
    ]);
    
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => 'Error: ' . $e->getMessage()
    ]);
}
?>