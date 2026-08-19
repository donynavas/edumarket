<?php
session_start();
require_once __DIR__ . '/../../config/database.php';

if (!isset($_SESSION['user_id'])) {
    exit('Acceso denegado');
}

require_once __DIR__ . '/../../config/TenantGuard.php';
$tid = TenantGuard::id();
$database = new Database();
$db = $database->getConnection();

$id = $_GET['id'] ?? 0;
$editar = $_GET['editar'] ?? false;

$query = "SELECT g.*,
          COUNT(DISTINCT s.id) as total_secciones,
          COUNT(DISTINCT m.id) as total_estudiantes
          FROM tbl_grado g
          LEFT JOIN tbl_seccion s ON g.id = s.id_grado
          LEFT JOIN tbl_matricula m ON s.id = m.id_seccion AND m.estado = 'activo'
          WHERE g.id = :id AND g.id_institucion = :tid
          GROUP BY g.id";

$stmt = $db->prepare($query);
$stmt->bindParam(':id', $id);
$stmt->bindParam(':tid', $tid);
$stmt->execute();
$grado = $stmt->fetch(PDO::FETCH_ASSOC);

if ($editar) {
    echo json_encode($grado);
} else {
    ?>
    <div class="row">
        <div class="col-md-6">
            <h6><i class="fas fa-layer-group"></i> Información del Grado</h6>
            <p><strong>Nombre:</strong> <?= htmlspecialchars($grado['nombre']) ?></p>
            <p><strong>Nivel:</strong> 
                <?php if ($grado['nivel'] == 'basica'): ?>
                <span class="badge bg-success">Educación Básica</span>
                <?php else: ?>
                <span class="badge bg-warning">Bachillerato</span>
                <?php endif; ?>
            </p>
        </div>
        <div class="col-md-6">
            <h6><i class="fas fa-star"></i> Evaluación</h6>
            <p><strong>Nota Mínima:</strong> 
                <span class="badge <?= $grado['nota_minima_aprobacion'] >= 7 ? 'bg-danger' : 'bg-warning' ?>">
                    <?= number_format($grado['nota_minima_aprobacion'], 1) ?>
                </span>
            </p>
        </div>
        <div class="col-md-6">
            <h6><i class="fas fa-users"></i> Estadísticas</h6>
            <p><strong>Secciones:</strong> <?= $grado['total_secciones'] ?></p>
            <p><strong>Estudiantes:</strong> <?= $grado['total_estudiantes'] ?></p>
        </div>
    </div>
    <?php
}
?>