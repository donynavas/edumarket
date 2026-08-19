<?php
/**
 * API: Calificar ejercicio individual
 * Método: POST
 * Parámetros: id_ejercicio, respuesta_estudiante, id_estudiante
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
$id_ejercicio = $_POST['id_ejercicio'] ?? 0;
$respuesta_estudiante = $_POST['respuesta_estudiante'] ?? '';

if (!$id_ejercicio) {
    echo json_encode([
        'success' => false,
        'message' => 'Parámetros incompletos'
    ]);
    exit;
}

try {
    $database = new Database();
    $db = $database->getConnection();

    // El id_estudiante SIEMPRE se deriva de la sesión autenticada — nunca se
    // confía en un id_estudiante enviado por el cliente (evita que un usuario
    // registre puntaje a nombre de otro estudiante).
    $stmtEst = $db->prepare(
        "SELECT e.id FROM tbl_estudiante e
         JOIN tbl_persona p ON e.id_persona = p.id
         WHERE p.id_usuario = :uid AND e.id_institucion = :tid"
    );
    $stmtEst->execute([':uid' => $_SESSION['user_id'], ':tid' => $tid]);
    $id_estudiante = $stmtEst->fetchColumn();
    if (!$id_estudiante) {
        throw new Exception('No se pudo resolver el estudiante de la sesión actual');
    }

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
        // tbl_ingles_progreso no tiene columna id_institucion; id_estudiante
        // ya se resolvió arriba vía la sesión (tenant-verificado).
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