<?php
session_start();
require_once __DIR__ . '/../../../config/database.php';
require_once __DIR__ . '/../../../config/TenantGuard.php';
require_once __DIR__ . '/../../../config/CuadroNotasHelper.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id']) || $_SESSION['rol'] !== 'estudiante') {
    echo json_encode(['success' => false, 'message' => 'No autorizado']);
    exit;
}

$db = (new Database())->getConnection();
$tid = TenantGuard::id();

// El JS de tomar_examen.php llama a este mismo endpoint cada 30s con
// auto_save=1 para "guardar progreso". Antes NO se distinguía ese caso: cada
// auto-save calificaba y CERRABA el intento como si el estudiante hubiera
// entregado (estado -> entregado/calificado, con fecha_fin actualizada) --
// es decir, cualquier examen que durara más de 30s se autoentregaba de
// forma prematura y repetida, insertando filas duplicadas en
// tbl_respuesta_estudiante en cada ciclo. Por ahora auto_save simplemente
// no hace nada (no hay guardado de progreso parcial en el esquema actual);
// lo importante es que ya NO finaliza el intento.
if (!empty($_POST['auto_save'])) {
    echo json_encode(['success' => true, 'auto_save' => true]);
    exit;
}

try {
    $db->beginTransaction();

    $intento_id = $_POST['intento_id'];
    $respuestas = $_POST['respuesta'] ?? [];
    $tiempo_usado = $_POST['tiempo_usado'] ?? 0;

    // Obtener información del examen. tbl_intento_examen no tiene columna
    // id_institucion; el aislamiento se hace vía tbl_estudiante (est.id_institucion).
    $stmt = $db->prepare("SELECT e.*, ad.id_profesor, i.estado as estado_intento, i.id_matricula FROM tbl_intento_examen i
                          JOIN tbl_examen e ON i.id_examen = e.id
                          JOIN tbl_asignacion_docente ad ON e.id_asignacion_docente = ad.id
                          WHERE i.id = :intento AND i.id_estudiante = (
                              SELECT est.id FROM tbl_estudiante est
                              JOIN tbl_persona per ON est.id_persona = per.id
                              WHERE per.id_usuario = :user AND est.id_institucion = :tid2
                          )");
    $stmt->execute([':intento' => $intento_id, ':user' => $_SESSION['user_id'], ':tid2' => $tid]);
    $examen_data = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$examen_data) throw new Exception("Intento no válido");

    // Idempotencia: sin esta guarda, un doble clic en "Entregar" (o dos
    // pestañas del mismo examen) recalifica y duplica cada fila de
    // tbl_respuesta_estudiante en vez de fallar de forma segura.
    if (in_array($examen_data['estado_intento'], ['entregado', 'calificado'], true)) {
        throw new Exception("Este examen ya fue entregado anteriormente");
    }

    $puntaje_total = 0;
    $puntaje_maximo = 0;

    // Calificar cada pregunta
    foreach ($respuestas as $pregunta_id => $respuesta) {
        // Obtener pregunta (verificando que pertenezca a este examen, ya validado como propio del tenant)
        $stmt = $db->prepare("SELECT * FROM tbl_pregunta_examen WHERE id = :id AND id_examen = :examen");
        $stmt->execute([':id' => $pregunta_id, ':examen' => $examen_data['id']]);
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
                // guardar_examen.php guarda las opciones en dos grupos, cada
                // una ordenada por 'orden' dentro de su propio grupo:
                // izquierda (es_correcta=0, orden 0..n-1) y derecha
                // (es_correcta=1, orden n..n+m-1 -- OFFSET por count(izquierda),
                // ver guardar_examen.php linea ~162). El emparejamiento real
                // es por POSICIÓN dentro de cada grupo (izquierda[i] <->
                // derecha[i]), que es también el índice $j que usa el
                // <select name="respuesta[id][$j]"> en tomar_examen.php, y
                // el <option value="..."> de ese select es el ID de
                // tbl_opcion_respuesta, no su texto.
                //
                // ANTES: se comparaba $respuesta[$correcta['orden']] (índice
                // desplazado, nunca coincide con las llaves 0..n-1 que
                // realmente envía el formulario) contra $correcta['texto']
                // (un ID nunca es igual a un texto) -- el resultado era que
                // TODA pregunta de "relacionar" se calificaba como
                // incorrecta sin importar lo que respondiera el estudiante.
                $stmt = $db->prepare("SELECT id, texto FROM tbl_opcion_respuesta WHERE id_pregunta = :preg AND es_correcta = 0 ORDER BY orden");
                $stmt->execute([':preg' => $pregunta_id]);
                $izquierda_opts = $stmt->fetchAll(PDO::FETCH_ASSOC);

                $stmt = $db->prepare("SELECT id, texto FROM tbl_opcion_respuesta WHERE id_pregunta = :preg AND es_correcta = 1 ORDER BY orden");
                $stmt->execute([':preg' => $pregunta_id]);
                $derecha_opts = $stmt->fetchAll(PDO::FETCH_ASSOC);

                $total_pares = count($izquierda_opts);
                $aciertos = 0;
                for ($i = 0; $i < $total_pares; $i++) {
                    if (isset($derecha_opts[$i]) && isset($respuesta[$i]) && (string) $respuesta[$i] === (string) $derecha_opts[$i]['id']) {
                        $aciertos++;
                    }
                }
                $puntaje_obtenido = $total_pares > 0 ? ($aciertos / $total_pares) * $pregunta['puntaje'] : 0;
                $es_correcta = ($total_pares > 0 && $aciertos == $total_pares) ? 1 : 0;
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
    
    // tbl_intento_examen no tiene columna id_institucion; :id ya fue
    // verificado como propio de este estudiante/tenant en la consulta anterior.
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
    
    // Si el examen ya se autocalificó (mostrar_resultados) y la actividad
    // que lo contiene está vinculada a una casilla del Cuadro de Notas (ver
    // gestionar_actividades.php), reflejar el resultado ahí también. El
    // porcentaje ya es sobre 100, se convierte a la escala 0-10 del Cuadro
    // de Notas dividiendo entre 10.
    if ($examen_data['mostrar_resultados']) {
        $stmtActividad = $db->prepare("SELECT id FROM tbl_actividad WHERE id_examen = :id_examen");
        $stmtActividad->execute([':id_examen' => $examen_data['id']]);
        $id_actividad_vinculada = $stmtActividad->fetchColumn();
        if ($id_actividad_vinculada) {
            CuadroNotasHelper::sincronizar($db, (int) $id_actividad_vinculada, (int) $examen_data['id_matricula'], $porcentaje / 10);
        }
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