<?php
/**
 * API: Calificar ejercicio individual
 * Método: POST
 * Parámetros: id_ejercicio, respuesta_estudiante, id_estudiante
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

$id_ejercicio = $_POST['id_ejercicio'] ?? 0;
$respuesta_estudiante = $_POST['respuesta_estudiante'] ?? '';
$id_estudiante = $_POST['id_estudiante'] ?? 0;

if (!$id_ejercicio || !$id_estudiante) {
    echo json_encode([
        'success' => false,
        'message' => 'Parámetros incompletos'
    ]);
    exit;
}

try {
    $database = new Database();
    $db = $database->getConnection();
    
    // Obtener ejercicio
    $query = "SELECT * FROM tbl_ingles_ejercicio WHERE id = :id";
    $stmt = $db->prepare($query);
    $stmt->bindValue(':id', $id_ejercicio, PDO::PARAM_INT);
    $stmt->execute();
    $ejercicio = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$ejercicio) {
        throw new Exception('Ejercicio no encontrado');
    }
    
    // Verificar respuesta
    $es_correcta = ($respuesta_estudiante === $ejercicio['respuesta_correcta']);
    $puntaje_obtenido = $es_correcta ? intval($ejercicio['puntos']) : 0;
    
    // Obtener ID de lección para progreso
    $query = "SELECT id_leccion FROM tbl_ingles_ejercicio WHERE id = :id";
    $stmt = $db->prepare($query);
    $stmt->bindValue(':id', $id_ejercicio, PDO::PARAM_INT);
    $stmt->execute();
    $leccion_data = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Actualizar progreso si es necesario
    if ($leccion_data) {
        $query = "INSERT INTO tbl_ingles_progreso 
                  (id_estudiante, id_leccion, estado, puntaje, ultimo_intento)
                  VALUES (:id_estudiante, :id_leccion, 'en-progreso', :puntaje, NOW())
                  ON DUPLICATE KEY UPDATE puntaje = puntaje + :puntaje2, ultimo_intento = NOW()";
        
        $stmt = $db->prepare($query);
        $stmt->bindValue(':id_estudiante', $id_estudiante, PDO::PARAM_INT);
        $stmt->bindValue(':id_leccion', $leccion_data['id_leccion'], PDO::PARAM_INT);
        $stmt->bindValue(':puntaje', $puntaje_obtenido, PDO::PARAM_INT);
        $stmt->bindValue(':puntaje2', $puntaje_obtenido, PDO::PARAM_INT);
        $stmt->execute();
    }
    
    echo json_encode([
        'success' => true,
        'data' => [
            'es_correcta' => $es_correcta,
            'puntaje_obtenido' => $puntaje_obtenido,
            'respuesta_correcta' => $es_correcta ? null : $ejercicio['respuesta_correcta'],
            'explicacion' => $ejercicio['explicacion'] ?? null
        ]
    ]);
    
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => 'Error: ' . $e->getMessage()
    ]);
}
?>