<?php
session_start();
require_once __DIR__ . '/../../../config/database.php';

if (!isset($_SESSION['user_id']) || !in_array($_SESSION['rol'], ['admin', 'director'])) {
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

$db = (new Database())->getConnection();

try {
    if ($action === 'editar') {
        $q = "SELECT p.id, per.primer_nombre, per.segundo_nombre, per.primer_apellido, per.segundo_apellido,
              per.dui, per.fecha_nacimiento, per.sexo, per.nacionalidad, per.direccion,
              per.telefono_fijo, per.celular, per.email,
              p.especialidad, p.titulo_academico,
              u.usuario, u.estado as estado_usuario
              FROM tbl_profesor p
              JOIN tbl_persona per ON p.id_persona = per.id
              JOIN tbl_usuario u ON per.id_usuario = u.id
              WHERE p.id = :id";
    } else {
        $q = "SELECT p.id, per.primer_nombre, per.segundo_nombre, per.primer_apellido, per.segundo_apellido,
              per.dui, per.fecha_nacimiento, per.sexo, per.nacionalidad, per.direccion,
              per.telefono_fijo, per.celular, per.email,
              p.especialidad, p.titulo_academico,
              u.estado as estado_usuario
              FROM tbl_profesor p
              JOIN tbl_persona per ON p.id_persona = per.id
              JOIN tbl_usuario u ON per.id_usuario = u.id
              WHERE p.id = :id";
    }
    
    $stmt = $db->prepare($q);
    $stmt->bindParam(':id', $id, PDO::PARAM_INT);
    $stmt->execute();
    $data = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($data) {
        echo json_encode(['success' => true, 'data' => $data]);
    } else {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Profesor no encontrado']);
    }
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Error de base de datos']);
}
?>