<?php
session_start();
require_once __DIR__ . '/../../../config/database.php';

if (!isset($_SESSION['user_id']) || !in_array($_SESSION['rol'], ['admin', 'director'])) {
    exit('No autorizado');
}

$id_asignatura = $_GET['id_asignatura'] ?? 0;
if (!$id_asignatura) {
    exit('ID de asignatura requerido');
}

try {
    $db = (new Database())->getConnection();
    
    $stmt = $db->prepare("SELECT 
        per.primer_nombre, 
        per.primer_apellido, 
        p.especialidad,
        s.nombre as seccion, 
        g.nombre as grado, 
        ad.id_periodo, 
        ad.anno
        FROM tbl_asignacion_docente ad
        JOIN tbl_profesor p ON ad.id_profesor = p.id
        JOIN tbl_persona per ON p.id_persona = per.id
        JOIN tbl_seccion s ON ad.id_seccion = s.id
        JOIN tbl_grado g ON s.id_grado = g.id
        WHERE ad.id_asignatura = :id
        ORDER BY per.primer_apellido, per.primer_nombre");
    
    $stmt->execute([':id' => $id_asignatura]);
    $profesores = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $periodos = [1 => '1er Trimestre', 2 => '2do Trimestre', 3 => '3er Trimestre', 4 => '4to Trimestre'];
    
    ?>
    <div class="row">
        <div class="col-12 mb-3">
            <h5><i class="fas fa-users"></i> Profesores Asignados</h5>
        </div>
    </div>
    <?php if (empty($profesores)): ?>
    <div class="alert alert-info text-center">
        <i class="fas fa-info-circle"></i> No hay profesores asignados a esta materia
    </div>
    <?php else: ?>
    <div class="table-responsive">
        <table class="table table-sm table-hover">
            <thead class="table-light">
                <tr>
                    <th>Profesor</th>
                    <th>Especialidad</th>
                    <th>Sección</th>
                    <th>Período</th>
                    <th>Año</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($profesores as $p): ?>
                <tr>
                    <td><?= htmlspecialchars($p['primer_nombre'] . ' ' . $p['primer_apellido']) ?></td>
                    <td><span class="badge bg-info"><?= htmlspecialchars($p['especialidad']) ?></span></td>
                    <td><?= htmlspecialchars($p['grado'] . ' - ' . $p['seccion']) ?></td>
                    <td><?= $periodos[$p['id_periodo']] ?? 'N/A' ?></td>
                    <td><?= $p['anno'] ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
    
<?php
} catch (PDOException $e) {
    echo '<div class="alert alert-danger">Error de base de datos: ' . htmlspecialchars($e->getMessage()) . '</div>';
} catch (Exception $e) {
    echo '<div class="alert alert-danger">Error: ' . htmlspecialchars($e->getMessage()) . '</div>';
}
?>