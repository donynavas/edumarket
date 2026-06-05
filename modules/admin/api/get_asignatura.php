<?php
session_start();
require_once __DIR__ . '/../../../config/database.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'No autorizado']);
    exit;
}

$id = $_GET['id'] ?? 0;
$action = $_GET['action'] ?? 'ver';

if (!$id) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'ID requerido']);
    exit;
}

try {
    $db = (new Database())->getConnection();
    
    if ($action === 'editar') {
        // ✅ ELIMINADA la columna 'area' que no existe
        $q = "SELECT id, nombre, codigo FROM tbl_asignatura WHERE id = :id";
    } else {
        $q = "SELECT a.id, a.nombre, a.codigo,
                     COUNT(DISTINCT ad.id) as total_asignaciones,
                     COUNT(DISTINCT ad.id_profesor) as total_profesores,
                     COUNT(DISTINCT act.id) as total_actividades
              FROM tbl_asignatura a
              LEFT JOIN tbl_asignacion_docente ad ON a.id = ad.id_asignatura
              LEFT JOIN tbl_actividad act ON ad.id = act.id_asignacion_docente
              WHERE a.id = :id
              GROUP BY a.id";
    }
    
    $stmt = $db->prepare($q);
    $stmt->bindParam(':id', $id, PDO::PARAM_INT);
    $stmt->execute();
    $data = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($data) {
        echo json_encode(['success' => true, 'data' => $data]);
    } else {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'No encontrado']);
    }
    
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Error BD: ' . $e->getMessage()]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
}
?>