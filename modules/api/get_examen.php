<?php
session_start();
require_once __DIR__ . '/../../config/database.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'No autorizado']);
    exit;
}

require_once __DIR__ . '/../../config/TenantGuard.php';
$tid = TenantGuard::id();
$database = new Database();
$db = $database->getConnection();
$id = $_GET['id'] ?? 0;

// tbl_actividad no tiene columna id_institucion; se acota por institución
// vía tbl_asignatura (a través de tbl_asignacion_docente).
$query = "SELECT act.* FROM tbl_actividad act
          JOIN tbl_asignacion_docente ad ON act.id_asignacion_docente = ad.id
          JOIN tbl_asignatura asig ON ad.id_asignatura = asig.id
          WHERE act.id = :id AND act.tipo = 'examen' AND asig.id_institucion = :tid";
$stmt = $db->prepare($query);
$stmt->bindValue(':id', $id, PDO::PARAM_INT);
$stmt->bindValue(':tid', $tid, PDO::PARAM_INT);
$stmt->execute();
$examen = $stmt->fetch(PDO::FETCH_ASSOC);

// tbl_actividad.duracion_minutos es TIME en el esquema real; convertir a
// minutos (entero) para que el formulario que consume este JSON lo entienda.
if ($examen && isset($examen['duracion_minutos'])) {
    [$h, $m, $s] = array_map('intval', explode(':', $examen['duracion_minutos'] ?: '00:00:00'));
    $examen['duracion_minutos'] = $h * 60 + $m + intdiv($s, 60);
}

if ($examen) {
    echo json_encode(['success' => true, 'examen' => $examen]);
} else {
    echo json_encode(['success' => false, 'message' => 'Examen no encontrado']);
}
?>