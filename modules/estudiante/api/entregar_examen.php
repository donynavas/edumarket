<?php
session_start();
require_once __DIR__ . '/../../../config/database.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id']) || $_SESSION['rol'] !== 'estudiante') {
    echo json_encode(['success' => false, 'message' => 'No autorizado']);
    exit;
}

$db = (new Database())->getConnection();

try {
    $db->beginTransaction();
    
    $intento_id = $_POST['intento_id'];
    $respuestas = $_POST['respuesta'] ?? [];
    $tiempo_usado = $_POST['tiempo_usado'] ?? 0;
    
    // Obtener información del examen
    $stmt = $db->prepare("SELECT e.*, ad.id_profesor FROM tbl_intento_examen i
                          JOIN tbl_examen e ON i.id_examen = e.id
                          JOIN tbl_asignacion_docente ad ON e.id_asignacion_docente = ad.id
                          WHERE i.id = :intento AND i.id_estudiante = (SELECT id FROM tbl_estudiante WHERE id_persona = (SELECT id_persona FROM tbl_usuario WHERE id = :user))");
    $stmt->execute([':intento' => $intento_id, ':user' => $_SESSION['user_id']]);
    $examen_data = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$examen_data) throw new Exception("Intento no válido");
    
    $puntaje_total = 0;
    $puntaje_maximo = 0;
    
    // Calificar cada pregunta
    foreach ($respuestas as $pregunta_id => $respuesta) {
        // Obtener pregunta
        $stmt = $db->prepare("SELECT * FROM tbl_pregunta_examen WHERE id = :id");
        $stmt->execute([':id' => $pregunta_id]);
        $pregunta = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$pregunta) continue;
        
        $puntaje_maximo += $pregunta['puntaje'];
        $puntaje_obtenido = 0;
        $es_correcta = null;
        
        // Calificar según tipo
        switch ($pregunta['tipo']) {
            case 'opcion_multiple':
                $stmt = $db->prepare("SELECT id FROM tbl_opcion_respuesta WHERE id_pregunta = :preg AND es_correcta = 1");
                $stmt->execute([':preg' => $pregunta_id]);
                $correcta = $stmt->fetchColumn();
                if ($respuesta == $correcta) {
                    $puntaje_obtenido = $pregunta['puntaje'];
                    $es_correcta = 1;
                } else {
                    $es_correcta = 0;
                }
                break;
                
            case 'verdadero_falso':
                $stmt = $db->prepare("SELECT texto FROM tbl_opcion_respuesta WHERE id_pregunta = :preg AND es_correcta = 1");
                $stmt->execute([':preg' => $pregunta_id]);
                $correcta = $stmt->fetchColumn();
                if (strtoupper($respuesta) == strtoupper(substr($correcta, 0, 1))) {
                    $puntaje_obtenido = $pregunta['puntaje'];
                    $es_correcta = 1;
                } else {
                    $es_correcta = 0;
                }
                break;
                
            case 'completar':
                $stmt = $db->prepare("SELECT texto FROM tbl_opcion_respuesta WHERE id_pregunta = :preg ORDER BY orden");
                $stmt->execute([':preg' => $pregunta_id]);
                $correctas = $stmt->fetchAll(PDO::FETCH_COLUMN);
                $aciertos = 0;
                foreach ($correctas as $i => $correcta) {
                    if (isset($respuesta[$i]) && strcasecmp(trim($respuesta[$i]), trim($correcta)) == 0) {
                        $aciertos++;
                    }
                }
                $puntaje_obtenido = ($aciertos / count($correctas)) * $pregunta['puntaje'];
                $es_correcta = ($aciertos == count($correctas)) ? 1 : 0;
                $respuesta = json_encode($respuesta);
                break;
                
            case 'respuesta_corta':
                $stmt = $db->prepare("SELECT texto FROM tbl_opcion_respuesta WHERE id_pregunta = :preg");
                $stmt->execute([':preg' => $pregunta_id]);
                $correcta = $stmt->fetchColumn();
                if (strcasecmp(trim($respuesta), trim($correcta)) == 0) {
                    $puntaje_obtenido = $pregunta['puntaje'];
                    $es_correcta = 1;
                } else {
                    $es_correcta = 0;
                }
                break;
                
            case 'relacionar':
                // Lógica de calificación para relacionar
                $stmt = $db->prepare("SELECT texto, orden FROM tbl_opcion_respuesta WHERE id_pregunta = :preg AND es_correcta = 1 ORDER BY orden");
                $stmt->execute([':preg' => $pregunta_id]);
                $correctas = $stmt->fetchAll(PDO::FETCH_ASSOC);
                $aciertos = 0;
                foreach ($correctas as $correcta) {
                    if (isset($respuesta[$correcta['orden']]) && $respuesta[$correcta['orden']] == $correcta['texto']) {
                        $aciertos++;
                    }
                }
                $puntaje_obtenido = ($aciertos / count($correctas)) * $pregunta['puntaje'];
                $es_correcta = ($aciertos == count($correctas)) ? 1 : 0;
                $respuesta = json_encode($respuesta);
                break;
        }
        
        // Guardar respuesta del estudiante
        $stmt = $db->prepare("INSERT INTO tbl_respuesta_estudiante (id_intento, id_pregunta, respuesta, es_correcta, puntaje_obtenido) 
                              VALUES (:intento, :pregunta, :respuesta, :correcta, :puntaje)");
        $stmt->execute([
            ':intento' => $intento_id,
            ':pregunta' => $pregunta_id,
            ':respuesta' => is_array($respuesta) ? json_encode($respuesta) : $respuesta,
            ':correcta' => $es_correcta,
            ':puntaje' => $puntaje_obtenido
        ]);
        
        $puntaje_total += $puntaje_obtenido;
    }
    
    // Actualizar intento
    $porcentaje = $puntaje_maximo > 0 ? ($puntaje_total / $puntaje_maximo) * 100 : 0;
    $estado = $examen_data['mostrar_resultados'] ? 'calificado' : 'entregado';
    
    $stmt = $db->prepare("UPDATE tbl_intento_examen SET 
                          fecha_fin = NOW(), puntaje_obtenido = :puntaje, porcentaje = :porcentaje,
                          tiempo_usado = :tiempo, estado = :estado
                          WHERE id = :id");
    $stmt->execute([
        ':puntaje' => $puntaje_total,
        ':porcentaje' => $porcentaje,
        ':tiempo' => $tiempo_usado,
        ':estado' => $estado,
        ':id' => $intento_id
    ]);
    
    // Si el examen tiene auto-calificación, actualizar actividad en tbl_actividad
    if ($examen_data['mostrar_resultados']) {
        // Aquí se podría crear un registro en tbl_entrega_actividad si el examen está vinculado a una actividad
    }
    
    $db->commit();
    
    echo json_encode([
        'success' => true,
        'intento_id' => $intento_id,
        'puntaje' => number_format($puntaje_total, 2),
        'porcentaje' => number_format($porcentaje, 1)
    ]);
    
} catch (Exception $e) {
    $db->rollBack();
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>