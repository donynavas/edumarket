<?php
session_start();
require_once __DIR__ . '/../../../config/database.php';

// Verificar autenticación
if (!isset($_SESSION['user_id']) || ($_SESSION['rol'] != 'admin' && $_SESSION['rol'] != 'director')) {
    http_response_code(403);
    echo json_encode(['error' => 'No autorizado']);
    exit;
}

$id_estudiante = $_GET['id'] ?? 0;

if (!$id_estudiante) {
    http_response_code(400);
    echo json_encode(['error' => 'ID de estudiante requerido']);
    exit;
}

require_once __DIR__ . '/../../../config/TenantGuard.php';
$tid = TenantGuard::id();
$database = new Database();
$db = $database->getConnection();

try {
    // Obtener datos completos del estudiante
    $query = "SELECT
              e.id as id_estudiante, e.nie, e.estado_familiar, e.discapacidad, e.trabaja,
              p.primer_nombre, p.segundo_nombre, p.tercer_nombre, p.primer_apellido, p.segundo_apellido,
              p.dui, p.fecha_nacimiento, p.sexo, p.nacionalidad, p.direccion,
              p.telefono_fijo, p.celular, p.email, p.id_usuario,
              u.usuario, u.estado as estado_usuario
              FROM tbl_estudiante e
              JOIN tbl_persona p ON e.id_persona = p.id
              LEFT JOIN tbl_usuario u ON p.id_usuario = u.id
              WHERE e.id = :id_estudiante AND e.id_institucion = :tid";

    $stmt = $db->prepare($query);
    $stmt->bindValue(':id_estudiante', $id_estudiante, PDO::PARAM_INT);
    $stmt->bindValue(':tid', $tid, PDO::PARAM_INT);
    $stmt->execute();
    
    $estudiante = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$estudiante) {
        http_response_code(404);
        echo json_encode(['error' => 'Estudiante no encontrado']);
        exit;
    }
    
    echo json_encode(['success' => true, 'data' => $estudiante]);
    
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Error de base de datos: ' . $e->getMessage()]);
}
?>