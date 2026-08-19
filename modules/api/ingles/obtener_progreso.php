<?php
/**
 * API: Obtener progreso del estudiante
 * Método: GET
 * Parámetros: id_estudiante, id_leccion (opcional)
 */

session_start();
header('Content-Type: application/json');
require_once __DIR__ . '/../../../config/database.php';
require_once __DIR__ . '/../../../config/TenantGuard.php';

if (!isset($_SESSION['user_id'])) {
    echo json_encode([
        'success' => false,
        'message' => 'No autorizado'
    ]);
    exit;
}

$tid = TenantGuard::id();
$id_estudiante = $_GET['id_estudiante'] ?? 0;
$id_leccion = $_GET['id_leccion'] ?? null;

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
    TenantGuard::assertOwner($db, 'tbl_estudiante', (int)$id_estudiante);

    // Progreso general
    $query = "SELECT 
              COUNT(*) as total_lecciones,
              SUM(CASE WHEN estado = 'completado' THEN 1 ELSE 0 END) as completadas,
              SUM(CASE WHEN estado = 'en-progreso' THEN 1 ELSE 0 END) as en_progreso,
              AVG(puntaje) as promedio_puntaje,
              SUM(puntaje) as puntaje_total
              FROM tbl_ingles_progreso
              WHERE id_estudiante = :id_estudiante";
    
    $stmt = $db->prepare($query);
    $stmt->bindValue(':id_estudiante', $id_estudiante, PDO::PARAM_INT);
    $stmt->execute();
    $progreso_general = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Progreso por lección específica
    $progreso_leccion = null;
    if ($id_leccion) {
        $query = "SELECT * FROM tbl_ingles_progreso 
                  WHERE id_estudiante = :id_estudiante AND id_leccion = :id_leccion";
        $stmt = $db->prepare($query);
        $stmt->bindValue(':id_estudiante', $id_estudiante, PDO::PARAM_INT);
        $stmt->bindValue(':id_leccion', $id_leccion, PDO::PARAM_INT);
        $stmt->execute();
        $progreso_leccion = $stmt->fetch(PDO::FETCH_ASSOC);
    }
    
    // Logros obtenidos
    $query = "SELECT l.*, le.fecha_obtenido
              FROM tbl_ingles_logros l
              JOIN tbl_ingles_logros_estudiante le ON l.id = le.id_logro
              WHERE le.id_estudiante = :id_estudiante
              ORDER BY le.fecha_obtenido DESC";
    $stmt = $db->prepare($query);
    $stmt->bindValue(':id_estudiante', $id_estudiante, PDO::PARAM_INT);
    $stmt->execute();
    $logros = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Racha actual (días consecutivos)
    $query = "SELECT DATEDIFF(MAX(ultimo_intento), NOW()) as dias_desde_ultimo
              FROM tbl_ingles_progreso
              WHERE id_estudiante = :id_estudiante AND estado = 'completado'";
    $stmt = $db->prepare($query);
    $stmt->bindValue(':id_estudiante', $id_estudiante, PDO::PARAM_INT);
    $stmt->execute();
    $racha_data = $stmt->fetch(PDO::FETCH_ASSOC);
    
    echo json_encode([
        'success' => true,
        'data' => [
            'progreso_general' => $progreso_general,
            'progreso_leccion' => $progreso_leccion,
            'logros' => $logros,
            'racha' => [
                'dias' => $racha_data ? abs($racha_data['dias_desde_ultimo']) : 0,
                'ultima_actividad' => $progreso_leccion['ultimo_intento'] ?? null
            ],
            'porcentaje_completado' => $progreso_general['total_lecciones'] > 0 
                ? round(($progreso_general['completadas'] / $progreso_general['total_lecciones']) * 100) 
                : 0
        ]
    ]);
    
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => 'Error: ' . $e->getMessage()
    ]);
}
?>