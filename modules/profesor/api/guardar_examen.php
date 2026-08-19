<?php
session_start();
require_once __DIR__ . '/../../../config/database.php';
require_once __DIR__ . '/../../../config/TenantGuard.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id']) || $_SESSION['rol'] !== 'profesor') {
    echo json_encode(['success' => false, 'message' => 'No autorizado']);
    exit;
}

$db = (new Database())->getConnection();
$tid = TenantGuard::id();
$user_id = $_SESSION['user_id'];

try {
    $db->beginTransaction();

    // Resolver el profesor autenticado dentro de su institución
    $stmtProf = $db->prepare("SELECT p.id FROM tbl_profesor p
                              JOIN tbl_persona per ON p.id_persona = per.id
                              WHERE per.id_usuario = :uid AND p.id_institucion = :tid");
    $stmtProf->execute([':uid' => $user_id, ':tid' => $tid]);
    $id_profesor = $stmtProf->fetchColumn();
    if (!$id_profesor) {
        throw new Exception('Perfil de profesor no encontrado');
    }

    $id_asignacion = (int) $_POST['id_asignacion'];
    $titulo = trim($_POST['titulo'] ?? '');
    if ($titulo === '') {
        throw new Exception('El título del examen es obligatorio');
    }
    $descripcion = $_POST['descripcion'] ?? '';
    $instrucciones = $_POST['instrucciones'] ?? '';
    $duracion = $_POST['duracion_minutos'] ?? 60;
    $nota_maxima = $_POST['nota_maxima'] ?? 10;
    $fecha_programada = $_POST['fecha_programada'] ?: null;
    $fecha_limite = $_POST['fecha_limite'] ?: null;
    $intento_maximo = $_POST['intento_maximo'] ?? 1;
    $mezclar_preguntas = isset($_POST['mezclar_preguntas']) ? 1 : 0;
    $mezclar_opciones = isset($_POST['mezclar_opciones']) ? 1 : 0;
    $mostrar_resultados = isset($_POST['mostrar_resultados']) ? 1 : 0;

    // La asignación docente indicada DEBE pertenecer a este profesor.
    // tbl_asignacion_docente no tiene columna id_institucion; id_profesor
    // ya está tenant-verificado (se resolvió arriba vía p.id_institucion).
    $stmtAsig = $db->prepare("SELECT id FROM tbl_asignacion_docente WHERE id = :id AND id_profesor = :prof");
    $stmtAsig->execute([':id' => $id_asignacion, ':prof' => $id_profesor]);
    if ($stmtAsig->rowCount() === 0) {
        throw new Exception('No tiene permiso para usar esta asignación');
    }

    // Guardar examen
    if (!empty($_POST['examen_id'])) {
        $examen_id = (int) $_POST['examen_id'];

        // Verificar que el examen a editar pertenece a esta asignación.
        // tbl_examen tampoco tiene columna id_institucion.
        TenantGuard::assertOwner($db, 'tbl_examen', $examen_id);
        $stmtCheckExamen = $db->prepare("SELECT id FROM tbl_examen WHERE id = :id AND id_asignacion_docente = :asig");
        $stmtCheckExamen->execute([':id' => $examen_id, ':asig' => $id_asignacion]);
        if ($stmtCheckExamen->rowCount() === 0) {
            throw new Exception('No tiene permiso para editar este examen');
        }

        // Actualizar
        $stmt = $db->prepare("UPDATE tbl_examen SET
            titulo = :titulo, descripcion = :descripcion, instrucciones = :instrucciones,
            duracion_minutos = :duracion, nota_maxima = :nota_maxima,
            fecha_programada = :fecha_prog, fecha_limite = :fecha_limite,
            intento_maximo = :intentos, mezclar_preguntas = :mezclar_preg,
            mezclar_opciones = :mezclar_opt, mostrar_resultados = :mostrar_res
            WHERE id = :id AND id_asignacion_docente = :asig");
        $stmt->execute([
            ':titulo' => $titulo, ':descripcion' => $descripcion, ':instrucciones' => $instrucciones,
            ':duracion' => $duracion, ':nota_maxima' => $nota_maxima,
            ':fecha_prog' => $fecha_programada, ':fecha_limite' => $fecha_limite,
            ':intentos' => $intento_maximo, ':mezclar_preg' => $mezclar_preguntas,
            ':mezclar_opt' => $mezclar_opciones, ':mostrar_res' => $mostrar_resultados,
            ':id' => $examen_id, ':asig' => $id_asignacion
        ]);

        // Eliminar preguntas existentes (el examen ya fue verificado como propio)
        $db->prepare("DELETE FROM tbl_pregunta_examen WHERE id_examen = :id")->execute([':id' => $examen_id]);
    } else {
        // Crear nuevo. tbl_examen no tiene columna id_institucion (se
        // confirmó contra el esquema real) — insertarla aquí rompía CADA
        // creación de examen nuevo con "Unknown column 'id_institucion'".
        $stmt = $db->prepare("INSERT INTO tbl_examen
            (id_asignacion_docente, titulo, descripcion, instrucciones, duracion_minutos, nota_maxima,
             fecha_programada, fecha_limite, intento_maximo, mezclar_preguntas, mezclar_opciones, mostrar_resultados, estado)
            VALUES (:asig, :titulo, :descripcion, :instrucciones, :duracion, :nota_maxima,
                    :fecha_prog, :fecha_limite, :intentos, :mezclar_preg, :mezclar_opt, :mostrar_res, 'borrador')");
        $stmt->execute([
            ':asig' => $id_asignacion, ':titulo' => $titulo, ':descripcion' => $descripcion,
            ':instrucciones' => $instrucciones, ':duracion' => $duracion, ':nota_maxima' => $nota_maxima,
            ':fecha_prog' => $fecha_programada, ':fecha_limite' => $fecha_limite,
            ':intentos' => $intento_maximo, ':mezclar_preg' => $mezclar_preguntas,
            ':mezclar_opt' => $mezclar_opciones, ':mostrar_res' => $mostrar_resultados
        ]);
        $examen_id = $db->lastInsertId();
    }
    
    // Guardar preguntas
    $preguntas = $_POST['pregunta'] ?? [];
    $orden = 1;
    
    foreach ($preguntas as $num => $preg) {
        $enunciado = trim($preg['enunciado']);
        if (empty($enunciado)) continue;
        
        $tipo = determinarTipoPregunta($preg);
        $puntaje = $preg['puntaje'] ?? 1;
        
        // Insertar pregunta
        $stmt = $db->prepare("INSERT INTO tbl_pregunta_examen (id_examen, numero_orden, tipo, enunciado, puntaje) 
                              VALUES (:examen, :orden, :tipo, :enunciado, :puntaje)");
        $stmt->execute([
            ':examen' => $examen_id, ':orden' => $orden, ':tipo' => $tipo,
            ':enunciado' => $enunciado, ':puntaje' => $puntaje
        ]);
        $pregunta_id = $db->lastInsertId();
        
        // Insertar opciones según tipo
        if ($tipo === 'opcion_multiple') {
            $opciones = $preg['opciones'] ?? [];
            $correcta = $preg['correcta'] ?? 0;
            foreach ($opciones as $i => $opcion) {
                $es_correcta = ($i == $correcta) ? 1 : 0;
                $stmt = $db->prepare("INSERT INTO tbl_opcion_respuesta (id_pregunta, texto, es_correcta, orden) 
                                      VALUES (:preg, :texto, :correcta, :orden)");
                $stmt->execute([':preg' => $pregunta_id, ':texto' => $opcion, ':correcta' => $es_correcta, ':orden' => $i]);
            }
        } elseif ($tipo === 'verdadero_falso') {
            $correcta = $preg['correcta'] ?? 'V';
            $stmt = $db->prepare("INSERT INTO tbl_opcion_respuesta (id_pregunta, texto, es_correcta, orden) VALUES (:preg, 'Verdadero', :v_correcto, 0)");
            $stmt->execute([':preg' => $pregunta_id, ':v_correcto' => ($correcta === 'V' ? 1 : 0)]);
            $stmt = $db->prepare("INSERT INTO tbl_opcion_respuesta (id_pregunta, texto, es_correcta, orden) VALUES (:preg, 'Falso', :f_correcto, 1)");
            $stmt->execute([':preg' => $pregunta_id, ':f_correcto' => ($correcta === 'F' ? 1 : 0)]);
        } elseif ($tipo === 'completar') {
            // Las respuestas correctas están entre corchetes
            preg_match_all('/\[(.*?)\]/', $enunciado, $matches);
            foreach ($matches[1] as $i => $respuesta) {
                $stmt = $db->prepare("INSERT INTO tbl_opcion_respuesta (id_pregunta, texto, es_correcta, orden) VALUES (:preg, :texto, 1, :orden)");
                $stmt->execute([':preg' => $pregunta_id, ':texto' => $respuesta, ':orden' => $i]);
            }
        } elseif ($tipo === 'respuesta_corta') {
            $correcta = $preg['correcta'] ?? '';
            $stmt = $db->prepare("INSERT INTO tbl_opcion_respuesta (id_pregunta, texto, es_correcta, orden) VALUES (:preg, :texto, 1, 0)");
            $stmt->execute([':preg' => $pregunta_id, ':texto' => $correcta]);
        } elseif ($tipo === 'relacionar') {
            $izquierda = $preg['izquierda'] ?? [];
            $derecha = $preg['derecha'] ?? [];
            foreach ($izquierda as $i => $elem) {
                $stmt = $db->prepare("INSERT INTO tbl_opcion_respuesta (id_pregunta, texto, es_correcta, orden) VALUES (:preg, :texto, 0, :orden)");
                $stmt->execute([':preg' => $pregunta_id, ':texto' => $elem, ':orden' => $i]);
            }
            foreach ($derecha as $i => $elem) {
                $stmt = $db->prepare("INSERT INTO tbl_opcion_respuesta (id_pregunta, texto, es_correcta, orden) VALUES (:preg, :texto, 1, :orden)");
                $stmt->execute([':preg' => $pregunta_id, ':texto' => $elem, ':orden' => count($izquierda) + $i]);
            }
        }
        
        $orden++;
    }
    
    $db->commit();
    echo json_encode(['success' => true, 'examen_id' => $examen_id, 'message' => 'Examen guardado']);
    
} catch (Exception $e) {
    $db->rollBack();
    echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
}

function determinarTipoPregunta($preg) {
    if (isset($preg['opciones'])) return 'opcion_multiple';
    if (isset($preg['correcta']) && in_array($preg['correcta'], ['V', 'F'])) return 'verdadero_falso';
    if (strpos($preg['enunciado'], '[') !== false) return 'completar';
    if (isset($preg['izquierda'])) return 'relacionar';
    return 'respuesta_corta';
}
?>