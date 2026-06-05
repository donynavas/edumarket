<?php
session_start();
require_once __DIR__ . '/../../config/database.php';

if (!isset($_SESSION['user_id'])) {
    exit('Acceso denegado');
}

$database = new Database();
$db = $database->getConnection();

$id = $_GET['id'] ?? 0;
$editar = $_GET['editar'] ?? false;

$query = "SELECT s.*, g.nombre as grado_nombre, g.nivel,
          COUNT(DISTINCT m.id) as total_estudiantes
          FROM tbl_seccion s
          JOIN tbl_grado g ON s.id_grado = g.id
          LEFT JOIN tbl_matricula m ON s.id = m.id_seccion AND m.estado = 'activo'
          WHERE s.id = :id
          GROUP BY s.id";

$stmt = $db->prepare($query);
$stmt->bindParam(':id', $id);
$stmt->execute();
$seccion = $stmt->fetch(PDO::FETCH_ASSOC);

if ($editar) {
    echo json_encode($seccion);
} else {
    ?>
    <div class="row">
        <div class="col-md-6">
            <h6><i class="fas fa-users"></i> Información de Sección</h6>
            <p><strong>Nombre:</strong> <?= htmlspecialchars($seccion['nombre']) ?></p>
            <p><strong>Grado:</strong> <?= htmlspecialchars($seccion['grado_nombre']) ?></p>
        </div>
        <div class="col-md-6">
            <h6><i class="fas fa-calendar"></i> Período</h6>
            <p><strong>Año Lectivo:</strong> <?= $seccion['anno_lectivo'] ?></p>
            <p><strong>Nivel:</strong> <?= ucfirst($seccion['nivel']) ?></p>
        </div>
        <div class="col-12">
            <h6><i class="fas fa-chart-bar"></i> Estadísticas</h6>
            <p><strong>Total Estudiantes:</strong> <?= $seccion['total_estudiantes'] ?></p>
        </div>
    </div>
    <?php
}
?>