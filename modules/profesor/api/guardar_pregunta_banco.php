<?php
// api/guardar_pregunta_banco.php — Crear/actualizar una pregunta del banco
// reutilizable de un profesor (Motor de Evaluaciones, Fase 1).
//
// Nota de diseño: tbl_banco_preguntas SÍ tiene columna id_institucion propia
// (a diferencia de tbl_examen/tbl_pregunta_examen), así que aquí sí se
// guarda directamente — se confirmó en la migración 2026_08_15_banco_preguntas.sql.

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

$tipos_validos = ['opcion_multiple', 'verdadero_falso', 'completar', 'relacionar', 'respuesta_corta', 'ensayo'];
$dificultades_validas = ['facil', 'medio', 'dificil'];
$tipos_con_opciones = ['opcion_multiple', 'relacionar'];

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

    $tipo = $_POST['tipo'] ?? '';
    if (!in_array($tipo, $tipos_validos)) {
        throw new Exception('Tipo de pregunta inválido');
    }
    $dificultad = $_POST['dificultad'] ?? 'medio';
    if (!in_array($dificultad, $dificultades_validas)) {
        $dificultad = 'medio';
    }
    $enunciado = trim($_POST['enunciado'] ?? '');
    if ($enunciado === '') {
        throw new Exception('El enunciado es obligatorio');
    }
    $tema = trim($_POST['tema'] ?? '');
    $tema = $tema !== '' ? $tema : null;
    $id_asignatura = !empty($_POST['id_asignatura']) ? (int) $_POST['id_asignatura'] : null;
    $puntaje_sugerido = is_numeric($_POST['puntaje_sugerido'] ?? null) ? (float) $_POST['puntaje_sugerido'] : 1.0;

    // Si se indicó asignatura, debe pertenecer a este profesor.
    if ($id_asignatura !== null) {
        $stmtAsig = $db->prepare("SELECT 1 FROM tbl_asignacion_docente WHERE id_profesor = :prof AND id_asignatura = :asig LIMIT 1");
        $stmtAsig->execute([':prof' => $id_profesor, ':asig' => $id_asignatura]);
        if (!$stmtAsig->fetchColumn()) {
            throw new Exception('No tiene permiso para usar esa asignatura');
        }
    }

    $opciones = [];
    if (isset($_POST['opciones'])) {
        $decoded = json_decode($_POST['opciones'], true);
        if (is_array($decoded)) {
            $opciones = $decoded;
        }
    }

    // Para los tipos que requieren opciones estructuradas, exigir al menos dos.
    if (in_array($tipo, $tipos_con_opciones)) {
        $opcionesValidas = array_filter($opciones, fn($o) => trim($o['texto'] ?? '') !== '');
        if (count($opcionesValidas) < 2) {
            throw new Exception('Este tipo de pregunta requiere al menos dos opciones');
        }
    }

    $id_pregunta = !empty($_POST['id_pregunta']) ? (int) $_POST['id_pregunta'] : 0;

    if ($id_pregunta > 0) {
        // Editar — sólo su propia pregunta, dentro de su institución.
        TenantGuard::assertOwner($db, 'tbl_banco_preguntas', $id_pregunta);
        $stmtCheck = $db->prepare("SELECT id FROM tbl_banco_preguntas WHERE id = :id AND id_profesor = :prof");
        $stmtCheck->execute([':id' => $id_pregunta, ':prof' => $id_profesor]);
        if (!$stmtCheck->fetch()) {
            throw new Exception('No tiene permiso para editar esta pregunta');
        }

        $stmt = $db->prepare("UPDATE tbl_banco_preguntas SET
            id_asignatura = :asig, tema = :tema, tipo = :tipo, dificultad = :dif,
            enunciado = :enunciado, puntaje_sugerido = :puntaje
            WHERE id = :id AND id_profesor = :prof");
        $stmt->execute([
            ':asig' => $id_asignatura, ':tema' => $tema, ':tipo' => $tipo, ':dif' => $dificultad,
            ':enunciado' => $enunciado, ':puntaje' => $puntaje_sugerido,
            ':id' => $id_pregunta, ':prof' => $id_profesor
        ]);

        // Reemplazar opciones existentes (la pregunta ya fue verificada como propia)
        $db->prepare("DELETE FROM tbl_banco_opcion WHERE id_banco_pregunta = :id")->execute([':id' => $id_pregunta]);
    } else {
        $stmt = $db->prepare("INSERT INTO tbl_banco_preguntas
            (id_institucion, id_profesor, id_asignatura, tema, tipo, dificultad, enunciado, puntaje_sugerido)
            VALUES (:tid, :prof, :asig, :tema, :tipo, :dif, :enunciado, :puntaje)");
        $stmt->execute([
            ':tid' => $tid, ':prof' => $id_profesor, ':asig' => $id_asignatura, ':tema' => $tema,
            ':tipo' => $tipo, ':dif' => $dificultad, ':enunciado' => $enunciado, ':puntaje' => $puntaje_sugerido
        ]);
        $id_pregunta = (int) $db->lastInsertId();
    }

    // Guardar opciones (si el tipo las usa)
    if (in_array($tipo, $tipos_con_opciones)) {
        $orden = 0;
        foreach ($opciones as $op) {
            $texto = trim($op['texto'] ?? '');
            if ($texto === '') continue;
            $es_correcta = !empty($op['es_correcta']) ? 1 : 0;
            $stmt = $db->prepare("INSERT INTO tbl_banco_opcion (id_banco_pregunta, texto, es_correcta, orden)
                                  VALUES (:preg, :texto, :correcta, :orden)");
            $stmt->execute([':preg' => $id_pregunta, ':texto' => $texto, ':correcta' => $es_correcta, ':orden' => $orden]);
            $orden++;
        }
    }

    $db->commit();
    echo json_encode(['success' => true, 'id_pregunta' => $id_pregunta, 'message' => 'Pregunta guardada en el banco']);

} catch (Exception $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
