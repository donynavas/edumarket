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

$query = "SELECT a.*, 
          COUNT(DISTINCT ad.id) as total_asignaciones,
          COUNT(DISTINCT ad.id_profesor) as total_profesores,
          COUNT(DISTINCT act.id) as total_actividades
          FROM tbl_asignatura a
          LEFT JOIN tbl_asignacion_docente ad ON a.id = ad.id_asignatura
          LEFT JOIN tbl_actividad act ON ad.id = act.id_asignacion_docente
          WHERE a.id = :id
          GROUP BY a.id";

$stmt = $db->prepare($query);
$stmt->bindParam(':id', $id);
$stmt->execute();
$asignatura = $stmt->fetch(PDO::FETCH_ASSOC);

if ($editar) {
    echo json_encode($asignatura);
} else {
    ?>
    <div class="row">
        <div class="col-md-6">
            <h6><i class="fas fa-book"></i> Información General</h6>
            <p><strong>Nombre:</strong> <?= htmlspecialchars($asignatura['nombre']) ?></p>
            <p><strong>Código:</strong> <span class="codigo-badge"><?= htmlspecialchars($asignatura['codigo']) ?></span></p>
        </div>
        <div class="col-md-6">
            <h6><i class="fas fa-chart-bar"></i> Estadísticas</h6>
            <p><strong>Profesores:</strong> <?= $asignatura['total_profesores'] ?></p>
            <p><strong>Asignaciones:</strong> <?= $asignatura['total_asignaciones'] ?></p>
            <p><strong>Actividades:</strong> <?= $asignatura['total_actividades'] ?></p>
        </div>
    </div>
    <?php
}
?>