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

$query = "SELECT m.*, e.nie, e.id as id_estudiante,
          p.primer_nombre, p.segundo_nombre, p.primer_apellido, p.segundo_apellido, 
          p.celular, p.email, p.direccion,
          g.id as id_grado, g.nombre as grado_nombre, g.nivel, g.nota_minima_aprobacion,
          s.id as id_seccion, s.nombre as seccion_nombre,
          COUNT(DISTINCT ea.id) as total_actividades,
          AVG(ea.nota_obtenida) as promedio_notas,
          COUNT(DISTINCT aa.id) as total_asistencias
          FROM tbl_matricula m
          JOIN tbl_estudiante e ON m.id_estudiante = e.id
          JOIN tbl_persona p ON e.id_persona = p.id
          JOIN tbl_seccion s ON m.id_seccion = s.id
          JOIN tbl_grado g ON s.id_grado = g.id
          LEFT JOIN tbl_entrega_actividad ea ON m.id = ea.id_matricula
          LEFT JOIN tbl_asistencia aa ON m.id = aa.id_matricula
          WHERE m.id = :id
          GROUP BY m.id";

$stmt = $db->prepare($query);
$stmt->bindParam(':id', $id);
$stmt->execute();
$matricula = $stmt->fetch(PDO::FETCH_ASSOC);

$periodos = [1 => 'Primer Trimestre', 2 => 'Segundo Trimestre', 3 => 'Tercer Trimestre', 4 => 'Cuarto Trimestre'];

if ($editar) {
    echo json_encode($matricula);
} else {
    ?>
    <div class="row">
        <div class="col-md-6">
            <h6><i class="fas fa-user-graduate"></i> Información del Estudiante</h6>
            <p><strong>Nombre:</strong> <?= htmlspecialchars($matricula['primer_nombre'] . ' ' . $matricula['primer_apellido']) ?></p>
            <p><strong>NIE:</strong> <?= htmlspecialchars($matricula['nie']) ?></p>
            <p><strong>Email:</strong> <?= htmlspecialchars($matricula['email']) ?></p>
            <p><strong>Celular:</strong> <?= htmlspecialchars($matricula['celular']) ?></p>
        </div>
        <div class="col-md-6">
            <h6><i class="fas fa-school"></i> Información Académica</h6>
            <p><strong>Grado:</strong> <?= htmlspecialchars($matricula['grado_nombre']) ?></p>
            <p><strong>Sección:</strong> <?= htmlspecialchars($matricula['seccion_nombre']) ?></p>
            <p><strong>Período:</strong> <?= $periodos[$matricula['id_periodo']] ?? 'N/A' ?></p>
            <p><strong>Año:</strong> <?= $matricula['anno'] ?></p>
        </div>
        <div class="col-md-6">
            <h6><i class="fas fa-chart-bar"></i> Rendimiento Académico</h6>
            <p><strong>Actividades:</strong> <?= $matricula['total_actividades'] ?></p>
            <p><strong>Promedio:</strong> 
                <?php if ($matricula['promedio_notas']): ?>
                <span class="badge <?= $matricula['promedio_notas'] >= $matricula['nota_minima_aprobacion'] ? 'bg-success' : 'bg-danger' ?>">
                    <?= number_format($matricula['promedio_notas'], 2) ?>
                </span>
                <?php else: ?>
                <span class="text-muted">N/A</span>
                <?php endif; ?>
            </p>
            <p><strong>Nota Mínima:</strong> <?= number_format($matricula['nota_minima_aprobacion'], 1) ?></p>
        </div>
        <div class="col-md-6">
            <h6><i class="fas fa-clipboard-check"></i> Estado</h6>
            <p><strong>Estado:</strong> 
                <span class="badge bg-<?= $matricula['estado'] == 'activo' ? 'success' : 'danger' ?>">
                    <?= ucfirst($matricula['estado']) ?>
                </span>
            </p>
            <p><strong>Asistencias:</strong> <?= $matricula['total_asistencias'] ?></p>
        </div>
    </div>
    <?php
}
?>