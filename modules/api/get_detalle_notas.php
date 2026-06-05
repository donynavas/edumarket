<?php
session_start();
require_once __DIR__ . '/../../config/database.php';

if (!isset($_SESSION['user_id'])) {
    exit('Acceso denegado');
}

$database = new Database();
$db = $database->getConnection();

$id_matricula = $_GET['id_matricula'] ?? 0;

$query = "SELECT m.id, m.anno, m.id_periodo, m.estado,
          e.nie,
          p.primer_nombre, p.primer_apellido,
          g.nombre as grado_nombre, g.nivel, g.nota_minima_aprobacion,
          s.nombre as seccion_nombre,
          asig.nombre as asignatura_nombre,
          act.titulo as actividad_titulo, act.tipo, act.nota_maxima, act.fecha_programada,
          ea.nota_obtenida, ea.observacion_docente, ea.estado_entrega, ea.fecha_entrega
          FROM tbl_matricula m
          JOIN tbl_estudiante e ON m.id_estudiante = e.id
          JOIN tbl_persona p ON e.id_persona = p.id
          JOIN tbl_seccion s ON m.id_seccion = s.id
          JOIN tbl_grado g ON s.id_grado = g.id
          JOIN tbl_asignacion_docente ad ON s.id = ad.id_seccion AND m.anno = ad.anno
          JOIN tbl_asignatura asig ON ad.id_asignatura = asig.id
          LEFT JOIN tbl_actividad act ON ad.id = act.id_asignacion_docente
          LEFT JOIN tbl_entrega_actividad ea ON act.id = ea.id_actividad AND m.id = ea.id_matricula
          WHERE m.id = :id
          ORDER BY asig.nombre, act.fecha_programada";

$stmt = $db->prepare($query);
$stmt->bindParam(':id', $id_matricula);
$stmt->execute();
$detalle = $stmt->fetchAll(PDO::FETCH_ASSOC);

$periodos = [1 => 'Primer Trimestre', 2 => 'Segundo Trimestre', 3 => 'Tercer Trimestre', 4 => 'Cuarto Trimestre'];
$tipos_actividad = ['tarea' => 'Tarea', 'examen' => 'Examen', 'laboratorio' => 'Laboratorio', 'foro' => 'Foro', 'proyecto' => 'Proyecto'];

if (count($detalle) > 0):
?>
<div class="row mb-3">
    <div class="col-md-6">
        <h6><i class="fas fa-user-graduate"></i> Estudiante</h6>
        <p class="mb-1"><strong><?= htmlspecialchars($detalle[0]['primer_nombre'] . ' ' . $detalle[0]['primer_apellido']) ?></strong></p>
        <p class="mb-1">NIE: <?= htmlspecialchars($detalle[0]['nie']) ?></p>
    </div>
    <div class="col-md-6">
        <h6><i class="fas fa-school"></i> Información Académica</h6>
        <p class="mb-1"><strong><?= htmlspecialchars($detalle[0]['grado_nombre']) ?> - <?= htmlspecialchars($detalle[0]['seccion_nombre']) ?></strong></p>
        <p class="mb-1">Período: <?= $periodos[$detalle[0]['id_periodo']] ?> - <?= $detalle[0]['anno'] ?></p>
    </div>
</div>

<div class="table-responsive">
    <table class="table table-sm table-bordered">
        <thead class="table-light">
            <tr>
                <th>Asignatura</th>
                <th>Actividad</th>
                <th>Tipo</th>
                <th>Fecha</th>
                <th>Nota Máx</th>
                <th>Nota Obt</th>
                <th>Estado</th>
                <th>Observación</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($detalle as $row): ?>
            <tr>
                <td><?= htmlspecialchars($row['asignatura_nombre']) ?></td>
                <td><?= htmlspecialchars($row['actividad_titulo'] ?? 'N/A') ?></td>
                <td><span class="badge bg-info"><?= $tipos_actividad[$row['tipo']] ?? $row['tipo'] ?></span></td>
                <td><?= $row['fecha_programada'] ?? 'N/A' ?></td>
                <td><?= number_format($row['nota_maxima'] ?? 0, 1) ?></td>
                <td>
                    <?php if ($row['nota_obtenida']): ?>
                    <span class="fw-bold <?= $row['nota_obtenida'] >= $row['nota_minima_aprobacion'] ? 'text-success' : 'text-danger' ?>">
                        <?= number_format($row['nota_obtenida'], 2) ?>
                    </span>
                    <?php else: ?>
                    <span class="text-muted">-</span>
                    <?php endif; ?>
                </td>
                <td>
                    <?php if ($row['estado_entrega'] == 'calificado'): ?>
                    <span class="badge bg-success">Aprobado</span>
                    <?php elseif ($row['estado_entrega'] == 'reprobado'): ?>
                    <span class="badge bg-danger">Reprobado</span>
                    <?php else: ?>
                    <span class="badge bg-warning">Pendiente</span>
                    <?php endif; ?>
                </td>
                <td><small><?= htmlspecialchars($row['observacion_docente'] ?? '-') ?></small></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<?php else: ?>
<p class="text-muted text-center">No hay registros de calificaciones para este estudiante.</p>
<?php endif; ?>