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

$id_asignatura = $_GET['id_asignatura'] ?? 0;

$query = "SELECT ad.id, per.primer_nombre, per.primer_apellido, p.especialidad,
          s.nombre as seccion, g.nombre as grado, ad.id_periodo, ad.anno
          FROM tbl_asignacion_docente ad
          JOIN tbl_profesor p ON ad.id_profesor = p.id
          JOIN tbl_persona per ON p.id_persona = per.id
          JOIN tbl_seccion s ON ad.id_seccion = s.id
          JOIN tbl_grado g ON s.id_grado = g.id
          WHERE ad.id_asignatura = :id_asignatura AND p.id_institucion = :tid
          ORDER BY per.primer_apellido";

$stmt = $db->prepare($query);
$stmt->bindParam(':id_asignatura', $id_asignatura);
$stmt->bindParam(':tid', $tid);
$stmt->execute();
$profesores = $stmt->fetchAll(PDO::FETCH_ASSOC);

$periodos = [1 => 'Primer Trimestre', 2 => 'Segundo Trimestre', 3 => 'Tercer Trimestre', 4 => 'Cuarto Trimestre'];
?>

<h6><i class="fas fa-users"></i> Profesores Asignados</h6>
<?php if (count($profesores) > 0): ?>
<div class="table-responsive">
    <table class="table table-sm">
        <thead>
            <tr>
                <th>Profesor</th>
                <th>Especialidad</th>
                <th>Grado/Sección</th>
                <th>Período</th>
                <th>Año</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($profesores as $prof): ?>
            <tr>
                <td><?= htmlspecialchars($prof['primer_nombre'] . ' ' . $prof['primer_apellido']) ?></td>
                <td><?= htmlspecialchars($prof['especialidad']) ?></td>
                <td><?= htmlspecialchars($prof['grado'] . ' - ' . $prof['seccion']) ?></td>
                <td><?= $periodos[$prof['id_periodo']] ?? 'N/A' ?></td>
                <td><?= $prof['anno'] ?></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php else: ?>
<p class="text-muted">No hay profesores asignados a esta asignatura.</p>
<?php endif; ?>