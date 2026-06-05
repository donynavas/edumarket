<?php
session_start();
require_once __DIR__ . '/../../../config/database.php';

if (!isset($_SESSION['user_id']) || !in_array($_SESSION['rol'], ['admin', 'director'])) { 
    http_response_code(403); 
    echo json_encode(['success'=>false,'message'=>'No autorizado']); 
    exit; 
}

$id = $_GET['id'] ?? 0; 
$action = $_GET['action'] ?? 'ver';

if (!$id) { 
    http_response_code(400); 
    echo json_encode(['success'=>false,'message'=>'ID requerido']); 
    exit; 
}

$db = (new Database())->getConnection();

try {
    if ($action == 'editar') {
        $q = "SELECT id, nombre, nivel, nota_minima_aprobacion FROM tbl_grado WHERE id = :id";
    } else {
        $q = "SELECT g.id, g.nombre, g.nivel, g.nota_minima_aprobacion, 
                     COUNT(DISTINCT s.id) as total_secciones, 
                     COUNT(DISTINCT m.id) as total_estudiantes 
              FROM tbl_grado g 
              LEFT JOIN tbl_seccion s ON g.id = s.id_grado 
              LEFT JOIN tbl_matricula m ON s.id = m.id_seccion AND m.estado = 'activo' 
              WHERE g.id = :id 
              GROUP BY g.id";
    }
    
    $stmt = $db->prepare($q);
    $stmt->bindParam(':id', $id, PDO::PARAM_INT);
    $stmt->execute();
    $data = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // ✅ LÍNEA 18 CORREGIDA
    if ($data) {
        echo json_encode(['success' => true, 'data' => $data]);
    } else {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'No encontrado']);
    }
    
} catch (PDOException $e) { 
    http_response_code(500); 
    echo json_encode(['success'=>false,'message'=>'Error BD']); 
}
?>