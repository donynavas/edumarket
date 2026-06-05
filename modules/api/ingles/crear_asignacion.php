<?php
/**
 * API: Crear asignación para estudiantes
 * Método: POST
 * Parámetros: id_profesor, tipo, id_curso, id_leccion, id_seccion, fecha_limite, puntaje_minimo, instrucciones
 */

session_start();
header('Content-Type: application/json');
require_once __DIR__ . '/../../../config/database.php';

if (!isset($_SESSION['user_id']) || $_SESSION['rol'] != 'profesor') {
    echo json_encode([
        'success' => false,
        'message' => 'No autorizado. Solo profesores pueden crear asignaciones.'
    ]);
    exit;
}

$id_profesor = $_POST['id_profesor'] ?? 0;
$tipo = $_POST['tipo'] ?? 'curso'; // curso o leccion
$id_curso = $_POST['curso'] ?? null;
$id_leccion = $_POST['leccion'] ?? null;
$id_seccion = $_POST['id_seccion'] ?? null;
$fecha_limite = $_POST['fecha_limite'] ?? null;
$puntaje_minimo = $_POST['puntaje_minimo'] ?? 7.0;
$instrucciones = $_POST['instrucciones'] ?? '';

if (!$id_profesor) {
    echo json_encode([
        'success' => false,
        'message' => 'ID de profesor requerido'
    ]);
    exit;
}

try {
    $database = new Database();
    $db = $database->getConnection();
    $db->beginTransaction();
    
    // Verificar profesor
    $query = "SELECT id FROM tbl_profesor WHERE id = :id";
    $stmt = $db->prepare($query);
    $stmt->bindValue(':id', $id_profesor, PDO::PARAM_INT);
    $stmt->execute();
    
    if (!$stmt->fetch()) {
        throw new Exception('Profesor no encontrado');
    }
    
    // Validar curso o lección
    if ($tipo == 'curso' && !$id_curso) {
        throw new Exception('Curso requerido para asignación de tipo curso');
    }
    
    if ($tipo == 'leccion' && !$id_leccion) {
        throw new Exception('Lección requerida para asignación de tipo lección');
    }
    
    // Obtener estudiantes de la sección
    $estudiantes = [];
    if ($id_seccion) {
        $query = "SELECT e.id FROM tbl_estudiante e
                  JOIN tbl_matricula m ON e.id = m.id_estudiante
                  WHERE m.id_seccion = :id_seccion AND m.estado = 'activo'";
        $stmt = $db->prepare($query);
        $stmt->bindValue(':id_seccion', $id_seccion, PDO::PARAM_INT);
        $stmt->execute();
        $estudiantes = $stmt->fetchAll(PDO::FETCH_COLUMN);
    }
    
    // Crear asignación para cada estudiante
    $asignaciones_creadas = 0;
    foreach ($estudiantes as $id_estudiante) {
        $query = "INSERT INTO tbl_ingles_asignacion 
                  (id_profesor, id_curso, id_leccion, id_seccion, id_estudiante, 
                   fecha_asignacion, fecha_limite, estado, instrucciones, puntaje_minimo)
                  VALUES (:id_profesor, :id_curso, :id_leccion, :id_seccion, :id_estudiante,
                          NOW(), :fecha_limite, 'pendiente', :instrucciones, :puntaje_minimo)";
        
        $stmt = $db->prepare($query);
        $stmt->bindValue(':id_profesor', $id_profesor, PDO::PARAM_INT);
        $stmt->bindValue(':id_curso', $id_curso, $id_curso ? PDO::PARAM_INT : PDO::PARAM_NULL);
        $stmt->bindValue(':id_leccion', $id_leccion, $id_leccion ? PDO::PARAM_INT : PDO::PARAM_NULL);
        $stmt->bindValue(':id_seccion', $id_seccion, $id_seccion ? PDO::PARAM_INT : PDO::PARAM_NULL);
        $stmt->bindValue(':id_estudiante', $id_estudiante, PDO::PARAM_INT);
        $stmt->bindValue(':fecha_limite', $fecha_limite, $fecha_limite ? PDO::PARAM_STR : PDO::PARAM_NULL);
        $stmt->bindValue(':instrucciones', $instrucciones, PDO::PARAM_STR);
        $stmt->bindValue(':puntaje_minimo', $puntaje_minimo, PDO::PARAM_STR);
        $stmt->execute();
        
        $asignaciones_creadas++;
    }
    
    $db->commit();
    
    echo json_encode([
        'success' => true,
        'message' => "Asignación creada para {$asignaciones_creadas} estudiantes",
        'data' => [
            'tipo' => $tipo,
            'id_curso' => $id_curso,
            'id_leccion' => $id_leccion,
            'estudiantes_asignados' => $asignaciones_creadas,
            'fecha_limite' => $fecha_limite,
            'puntaje_minimo' => $puntaje_minimo
        ]
    ]);
    
} catch (Exception $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    
    echo json_encode([
        'success' => false,
        'message' => 'Error: ' . $e->getMessage()
    ]);
}
?>