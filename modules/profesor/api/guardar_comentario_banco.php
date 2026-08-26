<?php
// api/guardar_comentario_banco.php — Crear/editar/eliminar un comentario del
// banco reutilizable de un profesor (biblioteca personal, mismo espíritu
// que tbl_banco_preguntas -- ver guardar_pregunta_banco.php). A diferencia
// de las preguntas del banco, aquí SÍ se maneja el borrado en este mismo
// endpoint (accion='eliminar') porque el banco de comentarios se administra
// desde un modal dentro de calificaciones.php, no desde una página propia
// con su propio <form method="POST"> de borrado.
//
// tbl_banco_comentario SÍ tiene columna id_institucion propia (igual que
// tbl_banco_preguntas/tbl_rubrica), confirmado en la migración
// 2026_08_20_rubricas_y_banco_comentarios.sql.

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
    $stmtProf = $db->prepare("SELECT p.id FROM tbl_profesor p
                              JOIN tbl_persona per ON p.id_persona = per.id
                              WHERE per.id_usuario = :uid AND p.id_institucion = :tid");
    $stmtProf->execute([':uid' => $user_id, ':tid' => $tid]);
    $id_profesor = $stmtProf->fetchColumn();
    if (!$id_profesor) {
        throw new Exception('Perfil de profesor no encontrado');
    }

    $accion = $_POST['accion'] ?? 'guardar';

    if ($accion === 'eliminar') {
        $id_comentario = (int) ($_POST['id_comentario'] ?? 0);
        $check = $db->prepare("SELECT id FROM tbl_banco_comentario WHERE id = :id AND id_profesor = :prof");
        $check->execute([':id' => $id_comentario, ':prof' => $id_profesor]);
        if (!$check->fetch()) {
            throw new Exception('No tiene permiso para eliminar este comentario');
        }
        $db->prepare("DELETE FROM tbl_banco_comentario WHERE id = :id")->execute([':id' => $id_comentario]);
        echo json_encode(['success' => true, 'message' => 'Comentario eliminado del banco']);
        exit;
    }

    // accion === 'guardar' (crear o editar)
    $texto = trim($_POST['texto'] ?? '');
    if ($texto === '') {
        throw new Exception('El texto del comentario es obligatorio');
    }
    $categoria = trim($_POST['categoria'] ?? '');
    $categoria = $categoria !== '' ? $categoria : null;
    $id_comentario = !empty($_POST['id_comentario']) ? (int) $_POST['id_comentario'] : 0;

    if ($id_comentario > 0) {
        $check = $db->prepare("SELECT id FROM tbl_banco_comentario WHERE id = :id AND id_profesor = :prof");
        $check->execute([':id' => $id_comentario, ':prof' => $id_profesor]);
        if (!$check->fetch()) {
            throw new Exception('No tiene permiso para editar este comentario');
        }
        $db->prepare("UPDATE tbl_banco_comentario SET texto = :texto, categoria = :categoria WHERE id = :id")
           ->execute([':texto' => $texto, ':categoria' => $categoria, ':id' => $id_comentario]);
    } else {
        $stmt = $db->prepare("INSERT INTO tbl_banco_comentario (id_institucion, id_profesor, texto, categoria)
                              VALUES (:tid, :prof, :texto, :categoria)");
        $stmt->execute([':tid' => $tid, ':prof' => $id_profesor, ':texto' => $texto, ':categoria' => $categoria]);
        $id_comentario = (int) $db->lastInsertId();
    }

    echo json_encode(['success' => true, 'id_comentario' => $id_comentario, 'message' => 'Comentario guardado en el banco']);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
