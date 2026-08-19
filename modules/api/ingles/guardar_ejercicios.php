<?php
/**
 * API: Guardar respuestas de ejercicios
 * Método: POST
 * Parámetros: id_video, id_leccion, id_estudiante, respuestas, puntaje
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
$id_video = $_POST['id_video'] ?? 0;
$id_leccion = $_POST['id_leccion'] ?? 0;
$respuestas = $_POST['respuestas'] ?? '[]';
$puntaje = $_POST['puntaje'] ?? 0;

if (!$id_video || !$id_leccion) {
    echo json_encode([
        'success' => false,
        'message' => 'Parámetros incompletos'
    ]);
    exit;
}

try {
    $database = new Database();
    $db = $database->getConnection();
    $db->beginTransaction();

    // El id_estudiante SIEMPRE se deriva de la sesión, nunca del cliente.
    $stmtEst = $db->prepare(
        "SELECT e.id FROM tbl_estudiante e
         JOIN tbl_persona p ON e.id_persona = p.id
         WHERE p.id_usuario = :uid AND e.id_institucion = :tid"
    );
    $stmtEst->execute([':uid' => $_SESSION['user_id'], ':tid' => $tid]);
    $id_estudiante = $stmtEst->fetchColumn();
    if (!$id_estudiante) {
        throw new Exception('Estudiante no encontrado');
    }

    // Validar lección
    $query = "SELECT id FROM tbl_ingles_leccion WHERE id = :id AND estado = 'publicado'";
    $stmt = $db->prepare($query);
    $stmt->bindValue(':id', $id_leccion, PDO::PARAM_INT);
    $stmt->execute();
    
    if (!$stmt->fetch()) {
        throw new Exception('Lección no encontrada o no publicada');
    }
    
    // Obtener ejercicios para validar respuestas
    $query = "SELECT id, respuesta_correcta, puntos FROM tbl_ingles_ejercicio WHERE id_leccion = :id";
    $stmt = $db->prepare($query);
    $stmt->bindValue(':id', $id_leccion, PDO::PARAM_INT);
    $stmt->execute();
    $ejercicios = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Calcular puntaje real
    $respuestas_array = json_decode($respuestas, true);
    $puntaje_calculado = 0;
    
    foreach ($ejercicios as $ejercicio) {
        $index = array_search($ejercicio['id'], array_column($ejercicios, 'id'));
        if (isset($respuestas_array[$index]) && $respuestas_array[$index] === $ejercicio['respuesta_correcta']) {
            $puntaje_calculado += intval($ejercicio['puntos']);
        }
    }
    
    // Usar el puntaje calculado (más seguro que el enviado por el cliente)
    $puntaje_final = $puntaje_calculado;
    
    // Actualizar progreso. tbl_ingles_progreso no tiene columna
    // id_institucion; id_estudiante ya se resolvió arriba vía la sesión.
    $query = "INSERT INTO tbl_ingles_progreso
              (id_estudiante, id_leccion, estado, puntaje, intentos, ultimo_intento, respuestas_json, tiempo_empleado)
              VALUES (:id_estudiante, :id_leccion, 'completado', :puntaje, 1, NOW(), :respuestas, 0)
              ON DUPLICATE KEY UPDATE
              estado = 'completado',
              puntaje = GREATEST(puntaje, :puntaje2),
              intentos = intentos + 1,
              ultimo_intento = NOW(),
              respuestas_json = :respuestas2";

    $stmt = $db->prepare($query);
    $stmt->bindValue(':id_estudiante', $id_estudiante, PDO::PARAM_INT);
    $stmt->bindValue(':id_leccion', $id_leccion, PDO::PARAM_INT);
    $stmt->bindValue(':puntaje', $puntaje_final, PDO::PARAM_INT);
    $stmt->bindValue(':puntaje2', $puntaje_final, PDO::PARAM_INT);
    $stmt->bindValue(':respuestas', $respuestas, PDO::PARAM_STR);
    $stmt->bindValue(':respuestas2', $respuestas, PDO::PARAM_STR);
    $stmt->execute();

    // Verificar si obtuvo logro
    $query = "SELECT * FROM tbl_ingles_logros WHERE tipo = 'lecciones' AND lecciones_requeridos <= (
              SELECT COUNT(*) FROM tbl_ingles_progreso WHERE id_estudiante = :id AND estado = 'completado')";
    $stmt = $db->prepare($query);
    $stmt->bindValue(':id', $id_estudiante, PDO::PARAM_INT);
    $stmt->execute();
    $logros = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $logros_obtenidos = [];
    foreach ($logros as $logro) {
        // tbl_ingles_logros_estudiante tampoco tiene columna id_institucion.
        $query = "INSERT IGNORE INTO tbl_ingles_logros_estudiante (id_estudiante, id_logro, fecha_obtenido)
                  VALUES (:id_estudiante, :id_logro, NOW())";
        $stmt = $db->prepare($query);
        $stmt->bindValue(':id_estudiante', $id_estudiante, PDO::PARAM_INT);
        $stmt->bindValue(':id_logro', $logro['id'], PDO::PARAM_INT);
        $stmt->execute();
        
        if ($stmt->rowCount() > 0) {
            $logros_obtenidos[] = $logro['nombre'];
        }
    }
    
    $db->commit();
    
    echo json_encode([
        'success' => true,
        'message' => 'Ejercicios guardados exitosamente',
        'data' => [
            'puntaje' => $puntaje_final,
            'total_ejercicios' => count($ejercicios),
            'porcentaje' => count($ejercicios) > 0 ? round(($puntaje_final / (count($ejercicios) * 10)) * 100) : 0,
            'logros_obtenidos' => $logros_obtenidos
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