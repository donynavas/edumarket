<?php
// api/ver_rubrica.php — Detalle de rúbrica (criterio por criterio) de una
// actividad calificada, para el estudiante dueño de esa entrega. Mismo
// patrón de "404 ambiguo" que leer_mensaje.php: una sola consulta combina
// la verificación de propiedad (¿esta matrícula es del usuario logueado?)
// con la de existencia (¿hay una entrega calificada con rúbrica para esta
// actividad?), así que "no es tuyo" y "no existe/no está calificado
// todavía" son indistinguibles en la respuesta -- nunca se filtra cuál de
// los dos casos aplicó.

session_start();
require_once __DIR__ . '/../../../config/database.php';

header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['user_id']) || $_SESSION['rol'] != 'estudiante') {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'No autorizado']);
    exit;
}

require_once __DIR__ . '/../../../config/TenantGuard.php';

$tid = TenantGuard::id();
$database = new Database();
$db = $database->getConnection();
$user_id = $_SESSION['user_id'];

$idActividad = filter_input(INPUT_GET, 'id_actividad', FILTER_VALIDATE_INT);
if (!$idActividad) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'ID de actividad requerido']);
    exit;
}

$stmt = $db->prepare("
    SELECT ea.id AS id_entrega, ea.nota_obtenida, act.titulo, act.nota_maxima
    FROM tbl_actividad act
    JOIN tbl_rubrica r ON r.id_actividad = act.id
    JOIN tbl_asignacion_docente ad ON act.id_asignacion_docente = ad.id
    JOIN tbl_matricula m ON m.id_seccion = ad.id_seccion AND m.anno = ad.anno
    JOIN tbl_estudiante e ON m.id_estudiante = e.id
    JOIN tbl_persona p ON e.id_persona = p.id
    JOIN tbl_entrega_actividad ea ON ea.id_actividad = act.id AND ea.id_matricula = m.id
    WHERE act.id = :id_actividad
    AND p.id_usuario = :uid
    AND e.id_institucion = :tid
    AND ea.estado_entrega = 'calificado'
");
$stmt->execute([':id_actividad' => $idActividad, ':uid' => $user_id, ':tid' => $tid]);
$entrega = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$entrega) {
    http_response_code(404);
    echo json_encode(['success' => false, 'error' => 'Rúbrica no encontrada']);
    exit;
}

$stmtDet = $db->prepare("
    SELECT cr.nombre AS criterio, cr.descripcion AS criterio_descripcion,
           niv.nombre AS nivel, erd.puntaje_otorgado, erd.comentario_criterio
    FROM tbl_entrega_rubrica_detalle erd
    JOIN tbl_rubrica_criterio cr ON erd.id_criterio = cr.id
    JOIN tbl_rubrica_nivel niv ON erd.id_nivel = niv.id
    WHERE erd.id_entrega_actividad = :id_entrega
    ORDER BY cr.orden
");
$stmtDet->execute([':id_entrega' => $entrega['id_entrega']]);
$detalle = $stmtDet->fetchAll(PDO::FETCH_ASSOC);

echo json_encode([
    'success' => true,
    'titulo' => $entrega['titulo'],
    'nota_obtenida' => $entrega['nota_obtenida'] !== null ? (float) $entrega['nota_obtenida'] : null,
    'nota_maxima' => (float) $entrega['nota_maxima'],
    'detalle' => $detalle,
]);
