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

require_once __DIR__ . '/../../../config/TenantGuard.php';
$tid = TenantGuard::id();
$db = (new Database())->getConnection();

try {
    if ($action == 'editar') {
        $q = "SELECT s.id, s.nombre, s.id_grado, s.anno_lectivo, s.turno, g.nombre as grado_nombre, g.nivel
              FROM tbl_seccion s
              JOIN tbl_grado g ON s.id_grado = g.id
              WHERE s.id = :id AND s.id_institucion = :tid";
    } else {
        $q = "SELECT s.id, s.nombre, s.anno_lectivo, s.turno, g.nombre as grado_nombre, g.nivel,
                     COUNT(DISTINCT m.id) as total_estudiantes
              FROM tbl_seccion s
              JOIN tbl_grado g ON s.id_grado = g.id
              LEFT JOIN tbl_matricula m ON s.id = m.id_seccion AND m.estado = 'activo'
              WHERE s.id = :id AND s.id_institucion = :tid
              GROUP BY s.id";
    }

    $stmt = $db->prepare($q);
    $stmt->bindParam(':id', $id, PDO::PARAM_INT);
    $stmt->bindParam(':tid', $tid, PDO::PARAM_INT);
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
    echo json_encode(['success'=>false,'message'=>'Error de base de datos']); 
}
?>