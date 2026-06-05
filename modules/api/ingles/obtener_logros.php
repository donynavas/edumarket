<?php
/**
 * API: Obtener logros del estudiante
 * Método: GET
 * Parámetros: id_estudiante
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

$id_estudiante = $_GET['id_estudiante'] ?? 0;

if (!$id_estudiante) {
    echo json_encode([
        'success' => false,
        'message' => 'ID de estudiante requerido'
    ]);
    exit;
}

try {
    $database = new Database();
    $db = $database->getConnection();
    
    // Logros obtenidos
    $query = "SELECT l.*, le.fecha_obtenido
              FROM tbl_ingles_logros l
              JOIN tbl_ingles_logros_estudiante le ON l.id = le.id_logro
              WHERE le.id_estudiante = :id_estudiante
              ORDER BY le.fecha_obtenido DESC";
    $stmt = $db->prepare($query);
    $stmt->bindValue(':id_estudiante', $id_estudiante, PDO::PARAM_INT);
    $stmt->execute();
    $logros_obtenidos = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Logros disponibles
    $query = "SELECT * FROM tbl_ingles_logros ORDER BY puntos_requeridos ASC, lecciones_requeridos ASC";
    $logros_disponibles = $db->query($query)->fetchAll(PDO::FETCH_ASSOC);
    
    // Progreso hacia próximos logros
    $query = "SELECT 
              COUNT(*) as lecciones_completadas,
              SUM(puntaje) as puntaje_total
              FROM tbl_ingles_progreso
              WHERE id_estudiante = :id_estudiante AND estado = 'completado'";
    $stmt = $db->prepare($query);
    $stmt->bindValue(':id_estudiante', $id_estudiante, PDO::PARAM_INT);
    $stmt->execute();
    $progreso = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Calcular logros disponibles para obtener
    $logros_pendientes = [];
    foreach ($logros_disponibles as $logro) {
        $ya_obtenido = false;
        foreach ($logros_obtenidos as $obtenido) {
            if ($obtenido['id'] == $logro['id']) {
                $ya_obtenido = true;
                break;
            }
        }
        
        if (!$ya_obtenido) {
            $progreso_logro = 0;
            if ($logro['tipo'] == 'lecciones') {
                $progreso_logro = min(100, round(($progreso['lecciones_completadas'] / $logro['lecciones_requeridos']) * 100));
            } elseif ($logro['tipo'] == 'puntos') {
                $progreso_logro = min(100, round(($progreso['puntaje_total'] / $logro['puntos_requeridos']) * 100));
            }
            
            $logro['progreso'] = $progreso_logro;
            $logros_pendientes[] = $logro;
        }
    }
    
    echo json_encode([
        'success' => true,
        'data' => [
            'logros_obtenidos' => $logros_obtenidos,
            'logros_pendientes' => $logros_pendientes,
            'progreso' => $progreso,
            'total_obtenidos' => count($logros_obtenidos),
            'total_disponibles' => count($logros_disponibles)
        ]
    ]);
    
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => 'Error: ' . $e->getMessage()
    ]);
}
?>