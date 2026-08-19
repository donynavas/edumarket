<?php
session_start();
require_once __DIR__ . '/../../../config/database.php';
require_once __DIR__ . '/../../../config/TenantGuard.php';
header('Content-Type: application/json');

if (!isset($_SESSION['user_id']) || $_SESSION['rol'] !== 'profesor') {
    echo json_encode(['error' => 'No autorizado']); exit;
}

$db = (new Database())->getConnection();
$tid = TenantGuard::id();
$id_asignacion = (int) ($_GET['id_asignacion'] ?? $_POST['id_asignacion'] ?? 0);
$mensaje = $_POST['mensaje'] ?? '';

// Resolver el profesor autenticado dentro de su institución
$stmtProf = $db->prepare("SELECT p.id FROM tbl_profesor p
                          JOIN tbl_persona per ON p.id_persona = per.id
                          WHERE per.id_usuario = :uid AND p.id_institucion = :tid");
$stmtProf->execute([':uid' => $_SESSION['user_id'], ':tid' => $tid]);
$id_profesor = $stmtProf->fetchColumn();

if (!$id_profesor) {
    echo json_encode(['error' => 'Perfil de profesor no encontrado']); exit;
}

// La asignación indicada DEBE pertenecer a este profesor.
// tbl_asignacion_docente no tiene columna id_institucion; id_profesor ya
// está tenant-verificado (se resolvió arriba filtrando por p.id_institucion).
$stmtAsig = $db->prepare("SELECT id FROM tbl_asignacion_docente WHERE id = :id AND id_profesor = :prof");
$stmtAsig->execute([':id' => $id_asignacion, ':prof' => $id_profesor]);
if ($stmtAsig->rowCount() === 0) {
    echo json_encode(['error' => 'No tiene permiso para esta asignación']); exit;
}

// Crear tabla si no existe. NOTA: en la base real esta tabla ya existía sin
// columna id_institucion, así que este CREATE TABLE IF NOT EXISTS no la
// agrega ahí; por eso el aislamiento por tenant se apoya únicamente en que
// $id_asignacion ya fue verificado arriba como propiedad de este profesor.
$db->exec("CREATE TABLE IF NOT EXISTS tbl_chat_clase (
    id INT AUTO_INCREMENT PRIMARY KEY,
    id_asignacion INT NOT NULL,
    id_usuario INT NOT NULL,
    mensaje TEXT NOT NULL,
    fecha_envio DATETIME DEFAULT NOW(),
    FOREIGN KEY (id_asignacion) REFERENCES tbl_asignacion_docente(id),
    FOREIGN KEY (id_usuario) REFERENCES tbl_usuario(id),
    INDEX idx_asig (id_asignacion)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    // Obtener mensajes
    $stmt = $db->prepare("SELECT c.*, CONCAT(p.primer_nombre, ' ', p.primer_apellido) as nombre
                          FROM tbl_chat_clase c
                          JOIN tbl_usuario u ON c.id_usuario = u.id
                          JOIN tbl_persona p ON p.id_usuario = u.id
                          WHERE c.id_asignacion = :asig
                          ORDER BY c.fecha_envio ASC LIMIT 50");
    $stmt->execute([':asig' => $id_asignacion]);

    echo json_encode(array_map(fn($m) => [
        'sender' => $m['nombre'],
        'text' => $m['mensaje'],
        'time' => date('H:i', strtotime($m['fecha_envio']))
    ], $stmt->fetchAll(PDO::FETCH_ASSOC)));

} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && $mensaje) {
    // Enviar mensaje
    $stmt = $db->prepare("INSERT INTO tbl_chat_clase (id_asignacion, id_usuario, mensaje) VALUES (:asig, :usr, :msg)");
    $stmt->execute([':asig' => $id_asignacion, ':usr' => $_SESSION['user_id'], ':msg' => $mensaje]);
    echo json_encode(['success' => true]);
}
?>