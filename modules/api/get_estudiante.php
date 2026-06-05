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

$query = "SELECT e.*, p.*, u.usuario, u.estado as estado_usuario,
          g.nombre as grado, s.nombre as seccion, m.anno, m.id_periodo
          FROM tbl_estudiante e
          JOIN tbl_persona p ON e.id_persona = p.id
          JOIN tbl_usuario u ON p.id_usuario = u.id
          LEFT JOIN tbl_matricula m ON e.id = m.id_estudiante AND m.estado = 'activo'
          LEFT JOIN tbl_seccion s ON m.id_seccion = s.id
          LEFT JOIN tbl_grado g ON s.id_grado = g.id
          WHERE e.id = :id";

$stmt = $db->prepare($query);
$stmt->bindParam(':id', $id);
$stmt->execute();
$estudiante = $stmt->fetch(PDO::FETCH_ASSOC);

if ($editar) {
    echo json_encode($estudiante);
} else {
    ?>
    <div class="row">
        <div class="col-md-6">
            <h6>Datos Personales</h6>
            <p><strong>Nombre:</strong> <?= htmlspecialchars($estudiante['primer_nombre'] . ' ' . $estudiante['primer_apellido']) ?></p>
            <p><strong>DUI:</strong> <?= htmlspecialchars($estudiante['dui']) ?></p>
            <p><strong>NIE:</strong> <?= htmlspecialchars($estudiante['nie']) ?></p>
            <p><strong>Fecha Nacimiento:</strong> <?= $estudiante['fecha_nacimiento'] ?></p>
        </div>
        <div class="col-md-6">
            <h6>Información Académica</h6>
            <p><strong>Grado:</strong> <?= htmlspecialchars($estudiante['grado'] ?? 'N/A') ?></p>
            <p><strong>Sección:</strong> <?= htmlspecialchars($estudiante['seccion'] ?? 'N/A') ?></p>
            <p><strong>Año:</strong> <?= $estudiante['anno'] ?? 'N/A' ?></p>
            <p><strong>Estado:</strong> <?= $estudiante['estado_usuario'] == 1 ? '<span class="badge bg-success">Activo</span>' : '<span class="badge bg-danger">Inactivo</span>' ?></p>
        </div>
        <div class="col-md-6">
            <h6>Contacto</h6>
            <p><strong>Email:</strong> <?= htmlspecialchars($estudiante['email']) ?></p>
            <p><strong>Celular:</strong> <?= htmlspecialchars($estudiante['celular']) ?></p>
            <p><strong>Dirección:</strong> <?= htmlspecialchars($estudiante['direccion']) ?></p>
        </div>
        <div class="col-md-6">
            <h6>Información Adicional</h6>
            <p><strong>Estado Familiar:</strong> <?= htmlspecialchars($estudiante['estado_familiar']) ?></p>
            <p><strong>Discapacidad:</strong> <?= htmlspecialchars($estudiante['discapacidad']) ?></p>
            <p><strong>Trabaja:</strong> <?= $estudiante['trabaja'] == 1 ? 'Sí' : 'No' ?></p>
        </div>
    </div>
    <?php
}
?>