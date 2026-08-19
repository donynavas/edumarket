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

$query = "SELECT p.*, per.*, u.usuario, u.estado as estado_usuario,
          GROUP_CONCAT(DISTINCT a.nombre SEPARATOR ', ') as materias_nombre,
          COUNT(DISTINCT ad.id) as total_asignaciones
          FROM tbl_profesor p
          JOIN tbl_persona per ON p.id_persona = per.id
          JOIN tbl_usuario u ON per.id_usuario = u.id
          LEFT JOIN tbl_asignacion_docente ad ON p.id = ad.id_profesor
          LEFT JOIN tbl_asignatura a ON ad.id_asignatura = a.id
          WHERE p.id = :id AND p.id_institucion = :tid
          GROUP BY p.id";

$stmt = $db->prepare($query);
$stmt->bindParam(':id', $id);
$stmt->bindParam(':tid', $tid);
$stmt->execute();
$profesor = $stmt->fetch(PDO::FETCH_ASSOC);

if ($editar) {
    echo json_encode($profesor);
} else {
    ?>
    <div class="row">
        <div class="col-md-6">
            <h6><i class="fas fa-user"></i> Datos Personales</h6>
            <p><strong>Nombre:</strong> <?= htmlspecialchars($profesor['primer_nombre'] . ' ' . $profesor['primer_apellido']) ?></p>
            <p><strong>DUI:</strong> <?= htmlspecialchars($profesor['dui']) ?></p>
            <p><strong>Fecha Nacimiento:</strong> <?= $profesor['fecha_nacimiento'] ?></p>
            <p><strong>Sexo:</strong> <?= $profesor['sexo'] == 'M' ? 'Masculino' : 'Femenino' ?></p>
        </div>
        <div class="col-md-6">
            <h6><i class="fas fa-graduation-cap"></i> Datos Académicos</h6>
            <p><strong>Especialidad:</strong> <?= htmlspecialchars($profesor['especialidad']) ?></p>
            <p><strong>Título:</strong> <?= htmlspecialchars($profesor['titulo_academico']) ?></p>
            <p><strong>Asignaciones:</strong> <?= $profesor['total_asignaciones'] ?></p>
            <p><strong>Estado:</strong> <?= $profesor['estado_usuario'] == 1 ? '<span class="badge bg-success">Activo</span>' : '<span class="badge bg-danger">Inactivo</span>' ?></p>
        </div>
        <div class="col-md-6">
            <h6><i class="fas fa-address-book"></i> Contacto</h6>
            <p><strong>Email:</strong> <?= htmlspecialchars($profesor['email']) ?></p>
            <p><strong>Celular:</strong> <?= htmlspecialchars($profesor['celular']) ?></p>
            <p><strong>Teléfono:</strong> <?= htmlspecialchars($profesor['telefono_fijo'] ?? 'N/A') ?></p>
        </div>
        <div class="col-md-6">
            <h6><i class="fas fa-book"></i> Materias Asignadas</h6>
            <p><?= !empty($profesor['materias_nombre']) ? htmlspecialchars($profesor['materias_nombre']) : 'Sin asignar' ?></p>
        </div>
    </div>
    <?php
}
?>