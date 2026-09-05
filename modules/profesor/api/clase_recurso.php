<?php
// api/clase_recurso.php — Agregar/eliminar un recurso (imagen/sitio
// web/artículo/video YT) de una clase de modules/profesor/impartir_clase.php.
// Recibe FormData (no JSON) porque "imagen" necesita $_FILES; el resto de
// tipos reutiliza el mismo endpoint por simplicidad -- un solo lugar donde
// vive la validación de propiedad de la clase.
//
// tbl_clase_recurso NO tiene columna id_institucion propia; su pertenencia
// se valida vía JOIN a tbl_clase_impartida (que sí la tiene), igual que
// TenantGuard::$viaTenantColumn ya resuelve para 'tbl_clase_recurso'.

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

$stmtProf = $db->prepare("SELECT p.id FROM tbl_profesor p
                          JOIN tbl_persona per ON p.id_persona = per.id
                          WHERE per.id_usuario = :uid AND p.id_institucion = :tid");
$stmtProf->execute([':uid' => $user_id, ':tid' => $tid]);
$id_profesor = (int) $stmtProf->fetchColumn();
if (!$id_profesor) {
    echo json_encode(['success' => false, 'message' => 'Perfil de profesor no encontrado']);
    exit;
}

/** Verifica que $idClase pertenezca a esta institución Y a una asignación de este profesor. */
function claseEsPropia(PDO $db, int $idClase, int $tid, int $idProfesor): bool
{
    $stmt = $db->prepare("SELECT 1 FROM tbl_clase_impartida c
                          JOIN tbl_asignacion_docente ad ON c.id_asignacion_docente = ad.id
                          WHERE c.id = :id AND c.id_institucion = :tid AND ad.id_profesor = :prof");
    $stmt->execute([':id' => $idClase, ':tid' => $tid, ':prof' => $idProfesor]);
    return (bool) $stmt->fetchColumn();
}

$accion = $_POST['accion'] ?? 'agregar';

try {
    if ($accion === 'eliminar') {
        $id_recurso = (int) ($_POST['id_recurso'] ?? 0);
        // TenantGuard::assertOwner() ya cubre 'tbl_clase_recurso' vía su
        // mapa $viaTenantColumn -- pero también hace falta confirmar que la
        // clase dueña del recurso es de ESTE profesor (assertOwner solo
        // valida institución, no profesor), así que se revalida aparte.
        TenantGuard::assertOwner($db, 'tbl_clase_recurso', $id_recurso);
        $stmtGet = $db->prepare("SELECT id_clase, tipo, url FROM tbl_clase_recurso WHERE id = :id");
        $stmtGet->execute([':id' => $id_recurso]);
        $recurso = $stmtGet->fetch(PDO::FETCH_ASSOC);
        if (!$recurso || !claseEsPropia($db, (int) $recurso['id_clase'], $tid, $id_profesor)) {
            throw new Exception('No tiene permiso para modificar esta clase.');
        }

        $db->prepare("DELETE FROM tbl_clase_recurso WHERE id = :id")->execute([':id' => $id_recurso]);

        // Si era una imagen subida (no una URL externa), borrar también el archivo físico.
        if ($recurso['tipo'] === 'imagen' && $recurso['url'] && str_starts_with($recurso['url'], 'assets/uploads/clases/')) {
            $ruta = __DIR__ . '/../../../' . $recurso['url'];
            if (is_file($ruta)) { @unlink($ruta); }
        }

        echo json_encode(['success' => true]);
        exit;
    }

    // === AGREGAR ===
    $id_clase = (int) ($_POST['id_clase'] ?? 0);
    if (!claseEsPropia($db, $id_clase, $tid, $id_profesor)) {
        throw new Exception('No tiene permiso para modificar esta clase.');
    }

    $tipo = $_POST['tipo'] ?? '';
    if (!in_array($tipo, ['imagen', 'sitio_web', 'articulo', 'video_yt'], true)) {
        throw new Exception('Tipo de recurso no válido.');
    }

    $titulo = trim($_POST['titulo'] ?? '') ?: null;
    $url = null;
    $contenido = null;

    if ($tipo === 'imagen') {
        if (empty($_FILES['archivo']) || $_FILES['archivo']['error'] !== UPLOAD_ERR_OK) {
            throw new Exception('Selecciona un archivo de imagen.');
        }
        $file = $_FILES['archivo'];
        $permitidos = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->file($file['tmp_name']);
        if (!in_array($mime, $permitidos, true)) {
            throw new Exception('Formato de imagen no permitido (usa JPG, PNG, GIF o WEBP).');
        }
        $ext = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif', 'image/webp' => 'webp'][$mime];
        $nombreArchivo = uniqid('clase_', true) . '.' . $ext;
        $uploadDir = __DIR__ . '/../../../assets/uploads/clases/';
        // La carpeta puede no existir todavía en un despliegue nuevo (las
        // carpetas vacías no viajan dentro del ZIP de entrega) -- se crea
        // aquí mismo si hace falta.
        if (!is_dir($uploadDir) && !mkdir($uploadDir, 0755, true) && !is_dir($uploadDir)) {
            throw new Exception('No se pudo crear la carpeta de subida del archivo.');
        }
        if (!move_uploaded_file($file['tmp_name'], $uploadDir . $nombreArchivo)) {
            throw new Exception('No se pudo guardar el archivo.');
        }
        $url = 'assets/uploads/clases/' . $nombreArchivo;
    } elseif ($tipo === 'sitio_web' || $tipo === 'video_yt') {
        $url = filter_var($_POST['url'] ?? '', FILTER_VALIDATE_URL) ?: null;
        if (!$url) {
            throw new Exception('Ingresa una URL válida.');
        }
    } elseif ($tipo === 'articulo') {
        $contenido = trim($_POST['contenido'] ?? '');
        if ($titulo === null && $contenido === '') {
            throw new Exception('Ingresa un título o contenido para el artículo.');
        }
        $url = filter_var($_POST['url'] ?? '', FILTER_VALIDATE_URL) ?: null;
    }

    $stmtOrden = $db->prepare("SELECT COALESCE(MAX(orden), -1) + 1 FROM tbl_clase_recurso WHERE id_clase = :id");
    $stmtOrden->execute([':id' => $id_clase]);
    $orden = (int) $stmtOrden->fetchColumn();

    $stmt = $db->prepare("INSERT INTO tbl_clase_recurso (id_clase, tipo, titulo, url, contenido, orden)
                          VALUES (:clase, :tipo, :titulo, :url, :contenido, :orden)");
    $stmt->execute([
        ':clase' => $id_clase, ':tipo' => $tipo, ':titulo' => $titulo,
        ':url' => $url, ':contenido' => $contenido ?: null, ':orden' => $orden,
    ]);

    echo json_encode(['success' => true, 'id_recurso' => (int) $db->lastInsertId()]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
