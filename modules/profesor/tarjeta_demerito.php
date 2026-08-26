<?php
// tarjeta_demerito.php — Formulario 1: "Tarjeta de Deméritos de Estudiante"
// (MINEDUCYT), impresión lista con @media print + window.print(), tal cual
// el PDF oficial adjuntado por el usuario. ?id_matricula=<id>&mes=YYYY-MM
//
// La tarjeta es del estudiante, compartida entre TODOS los profesores de
// su sección (no existe "docente responsable de sección" en el esquema),
// así que la propiedad se verifica contra CUALQUIER asignación activa del
// profesor en la sección/año de la matrícula -- no necesariamente la que
// originó el link desde demeritos.php.

session_start();
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/TenantGuard.php';
require_once __DIR__ . '/../../config/Demeritos.php';

if (!isset($_SESSION['user_id']) || $_SESSION['rol'] != 'profesor') {
    header("Location: " . BASE_URL . "/login.php");
    exit;
}

$database = new Database();
$db = $database->getConnection();
$user_id = $_SESSION['user_id'];
$tid = TenantGuard::id();

$stmtProf = $db->prepare("SELECT p.id FROM tbl_profesor p
                          JOIN tbl_persona per ON p.id_persona = per.id
                          WHERE per.id_usuario = :uid AND p.id_institucion = :tid");
$stmtProf->execute([':uid' => $user_id, ':tid' => $tid]);
$id_profesor = $stmtProf->fetchColumn();
if (!$id_profesor) {
    http_response_code(403);
    die('Perfil de profesor no encontrado');
}

$id_matricula = filter_input(INPUT_GET, 'id_matricula', FILTER_VALIDATE_INT) ?: filter_input(INPUT_POST, 'id_matricula', FILTER_VALIDATE_INT);
$mes_filtro = $_GET['mes'] ?? $_POST['mes'] ?? date('Y-m');
if (!preg_match('/^\d{4}-\d{2}$/', $mes_filtro)) {
    $mes_filtro = date('Y-m');
}
if (!$id_matricula) {
    http_response_code(400);
    die('id_matricula requerido');
}

// ===== OWNERSHIP: la matrícula debe ser de este tenant, y el profesor debe
// tener CUALQUIER asignación activa en la sección/año de esa matrícula =====
$stmtMat = $db->prepare("SELECT m.id_seccion, m.anno, e.nie,
                         p.primer_nombre, p.primer_apellido,
                         g.nombre AS grado_nombre, s.nombre AS seccion_nombre
                         FROM tbl_matricula m
                         JOIN tbl_estudiante e ON m.id_estudiante = e.id
                         JOIN tbl_persona p ON e.id_persona = p.id
                         JOIN tbl_seccion s ON m.id_seccion = s.id
                         JOIN tbl_grado g ON s.id_grado = g.id
                         WHERE m.id = :id_matricula AND e.id_institucion = :tid");
$stmtMat->execute([':id_matricula' => $id_matricula, ':tid' => $tid]);
$matricula = $stmtMat->fetch(PDO::FETCH_ASSOC);
if (!$matricula) {
    http_response_code(403);
    die('No tiene permiso para ver esta tarjeta');
}

$stmtAsig = $db->prepare("SELECT 1 FROM tbl_asignacion_docente WHERE id_profesor = :prof AND id_seccion = :sec AND anno = :anno LIMIT 1");
$stmtAsig->execute([':prof' => $id_profesor, ':sec' => $matricula['id_seccion'], ':anno' => $matricula['anno']]);
if (!$stmtAsig->fetchColumn()) {
    http_response_code(403);
    die('No tiene permiso para ver esta tarjeta');
}

$anno_mes = (int) substr($mes_filtro, 0, 4);
$mes_num = (int) substr($mes_filtro, 5, 2);
$primerDiaMes = $mes_filtro . '-01';
$ultimoDiaMes = date('Y-m-t', strtotime($primerDiaMes));

// ===== GUARDAR OBSERVACIÓN (upsert) =====
$mensajeObs = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['accion'] ?? '') === 'guardar_observacion') {
    $texto = trim($_POST['texto'] ?? '');
    if (mb_strlen($texto) > 1000) {
        $texto = mb_substr($texto, 0, 1000);
    }
    $up = $db->prepare("INSERT INTO tbl_demerito_observacion (id_institucion, id_matricula, anno, mes, texto, id_profesor_registro)
                        VALUES (:tid, :mat, :anno, :mes, :texto, :prof)
                        ON DUPLICATE KEY UPDATE texto = VALUES(texto), id_profesor_registro = VALUES(id_profesor_registro)");
    $up->execute([':tid' => $tid, ':mat' => $id_matricula, ':anno' => $anno_mes, ':mes' => $mes_num, ':texto' => $texto, ':prof' => $id_profesor]);
    header('Location: tarjeta_demerito.php?id_matricula=' . $id_matricula . '&mes=' . urlencode($mes_filtro) . '&guardado=1');
    exit;
}

// ===== CONSOLIDADO =====
$stmtAnt = $db->prepare("SELECT
    (SELECT COUNT(*) FROM tbl_demerito WHERE id_matricula = :mat1 AND fecha < :antes1) AS total_ant,
    (SELECT COALESCE(SUM(cantidad_redimida),0) FROM tbl_demerito_redencion WHERE id_matricula = :mat2 AND fecha < :antes2) AS redimido_ant");
$stmtAnt->execute([':mat1' => $id_matricula, ':antes1' => $primerDiaMes, ':mat2' => $id_matricula, ':antes2' => $primerDiaMes]);
$rowAnt = $stmtAnt->fetch(PDO::FETCH_ASSOC);
$acumulado_mes_anterior = max(0, (int) $rowAnt['total_ant'] - (int) $rowAnt['redimido_ant']);

$stmtPresente = $db->prepare("SELECT COUNT(*) FROM tbl_demerito WHERE id_matricula = :mat AND fecha BETWEEN :ini AND :fin");
$stmtPresente->execute([':mat' => $id_matricula, ':ini' => $primerDiaMes, ':fin' => $ultimoDiaMes]);
$presente_mes = (int) $stmtPresente->fetchColumn();

$stmtRedimidos = $db->prepare("SELECT COALESCE(SUM(cantidad_redimida),0) FROM tbl_demerito_redencion WHERE id_matricula = :mat AND fecha BETWEEN :ini AND :fin");
$stmtRedimidos->execute([':mat' => $id_matricula, ':ini' => $primerDiaMes, ':fin' => $ultimoDiaMes]);
$redimidos_mes = (int) $stmtRedimidos->fetchColumn();

$mes_actual = max(0, $acumulado_mes_anterior + $presente_mes - $redimidos_mes);

// ===== FILAS =====
$stmtDem = $db->prepare("SELECT categoria, fecha, hora FROM tbl_demerito WHERE id_matricula = :mat AND fecha BETWEEN :ini AND :fin ORDER BY fecha, hora LIMIT 15");
$stmtDem->execute([':mat' => $id_matricula, ':ini' => $primerDiaMes, ':fin' => $ultimoDiaMes]);
$filasDemerito = $stmtDem->fetchAll(PDO::FETCH_ASSOC);

$stmtRed = $db->prepare("SELECT actividad, fecha, hora, cantidad_redimida FROM tbl_demerito_redencion WHERE id_matricula = :mat AND fecha BETWEEN :ini AND :fin ORDER BY fecha, hora LIMIT 15");
$stmtRed->execute([':mat' => $id_matricula, ':ini' => $primerDiaMes, ':fin' => $ultimoDiaMes]);
$filasRedencion = $stmtRed->fetchAll(PDO::FETCH_ASSOC);

$stmtCons = $db->prepare("SELECT fecha, descripcion FROM tbl_demerito_consecuencia WHERE id_matricula = :mat AND fecha BETWEEN :ini AND :fin ORDER BY fecha LIMIT 5");
$stmtCons->execute([':mat' => $id_matricula, ':ini' => $primerDiaMes, ':fin' => $ultimoDiaMes]);
$filasConsecuencia = $stmtCons->fetchAll(PDO::FETCH_ASSOC);

$stmtObs = $db->prepare("SELECT texto FROM tbl_demerito_observacion WHERE id_matricula = :mat AND anno = :anno AND mes = :mes");
$stmtObs->execute([':mat' => $id_matricula, ':anno' => $anno_mes, ':mes' => $mes_num]);
$observacion = (string) $stmtObs->fetchColumn();

$stmtInst = $db->prepare("SELECT nombre_ce, codigo_infra FROM tbl_institucion WHERE id = :tid");
$stmtInst->execute([':tid' => $tid]);
$institucion = $stmtInst->fetch(PDO::FETCH_ASSOC);

$nombreEstudiante = trim($matricula['primer_nombre'] . ' ' . $matricula['primer_apellido']);
$mesTexto = Demeritos::MESES_ES[$mes_num] . ' ' . $anno_mes;
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>Tarjeta de Deméritos - <?= htmlspecialchars($nombreEstudiante) ?></title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
<style>
    body { font-family: 'Segoe UI', system-ui, -apple-system, sans-serif; background: #f5f7fa; padding: 20px; }
    .hoja { max-width: 850px; margin: 0 auto 20px; background: white; padding: 30px; border-radius: 8px; box-shadow: 0 2px 12px rgba(0,0,0,0.08); }
    .hoja-titulo { text-align: center; font-weight: 700; font-size: 1.2rem; margin-bottom: 4px; }
    .hoja-subtitulo { text-align: center; color: #666; margin-bottom: 20px; }
    table.form-tabla { width: 100%; border-collapse: collapse; margin-bottom: 16px; font-size: 0.85rem; }
    table.form-tabla th, table.form-tabla td { border: 1px solid #999; padding: 4px 6px; text-align: center; }
    table.form-tabla th { background: #eef1f4; }
    table.tabla-encabezado { width: 100%; border-collapse: collapse; margin-bottom: 10px; font-size: 0.9rem; }
    table.tabla-encabezado th, table.tabla-encabezado td { border: 1px solid #999; padding: 5px 8px; text-align: left; }
    table.tabla-encabezado th { width: 110px; background: #eef1f4; white-space: nowrap; }
    .titulo-seccion { display: flex; justify-content: space-between; align-items: flex-end; margin-top: 14px; margin-bottom: 6px; }
    .titulo-seccion h6 { margin: 0; }
    .titulo-seccion small { color: #666; }
    .caja-acumulado { border: 1px solid #999; padding: 4px 10px; font-size: 0.8rem; white-space: nowrap; }
    .leyenda-columna { font-size: 0.65rem; font-weight: 400; text-align: left; line-height: 1.3; }
    .leyenda-columna ul { margin: 2px 0 0; padding-left: 14px; }
    .caja-consolidado { border: 2px solid #333; border-radius: 6px; padding: 12px; margin-bottom: 16px; }
    .caja-consolidado table { width: 100%; }
    .caja-consolidado td { padding: 4px 8px; }
    .firma-linea { border-top: 1px solid #333; margin-top: 50px; padding-top: 4px; text-align: center; font-size: 0.85rem; }
    .caja-principios { border: 1px solid #999; border-radius: 6px; padding: 12px; font-size: 0.8rem; margin-top: 16px; }
    .salto-pagina { page-break-after: always; }
    @media print {
        body { background: white; padding: 0; }
        .hoja { box-shadow: none; border-radius: 0; max-width: 100%; margin: 0; padding: 10px; }
        .no-print { display: none !important; }
    }
</style>
</head>
<body>

<div class="text-end no-print mb-2" style="max-width: 850px; margin: 0 auto;">
    <a href="demeritos.php" class="btn btn-outline-secondary btn-sm"><i class="fas fa-arrow-left"></i> Volver</a>
    <button class="btn btn-primary btn-sm" onclick="window.print()"><i class="fas fa-print"></i> Imprimir</button>
</div>

<?php if (isset($_GET['guardado'])): ?>
<div class="alert alert-success no-print" style="max-width: 850px; margin: 0 auto 10px;">Observación guardada.</div>
<?php endif; ?>

<!-- ===== PÁGINA 1 ===== -->
<div class="hoja salto-pagina">
    <div class="hoja-titulo">TARJETA DE DEMÉRITOS DE ESTUDIANTE</div>
    <div class="hoja-subtitulo">Reglamento para la Promoción de la Cortesía Escolar</div>

    <table class="tabla-encabezado">
        <tr><th>Código:</th><td><?= htmlspecialchars($institucion['codigo_infra'] ?? '') ?></td><th>Centro educativo:</th><td><?= htmlspecialchars($institucion['nombre_ce'] ?? '') ?></td></tr>
        <tr><th>NIE:</th><td><?= htmlspecialchars($matricula['nie']) ?></td><th>Estudiante:</th><td><?= htmlspecialchars($nombreEstudiante) ?></td></tr>
        <tr><th>Mes:</th><td><?= htmlspecialchars($mesTexto) ?></td><th>Grado/sección:</th><td><?= htmlspecialchars($matricula['grado_nombre'] . ' ' . $matricula['seccion_nombre']) ?></td></tr>
    </table>

    <div class="titulo-seccion">
        <h6>REGISTRO DE DEMÉRITOS <small>(Marcar el demérito que corresponda)</small></h6>
        <div class="caja-acumulado"><strong>Deméritos acumulados del mes anterior:</strong> <?= $acumulado_mes_anterior ?></div>
    </div>
    <table class="form-tabla">
        <thead>
            <tr>
                <th>No.</th><th>Fecha</th><th>Hora</th>
                <?php foreach (Demeritos::CATEGORIAS as $label): ?>
                <th><?= htmlspecialchars($label) ?></th>
                <?php endforeach; ?>
            </tr>
        </thead>
        <tbody>
            <?php for ($i = 0; $i < 15; $i++):
                $fila = $filasDemerito[$i] ?? null;
            ?>
            <tr>
                <td><?= $i + 1 ?></td>
                <td><?= $fila ? date('d/m/Y', strtotime($fila['fecha'])) : '' ?></td>
                <td><?= $fila ? substr($fila['hora'], 0, 5) : '' ?></td>
                <?php foreach (Demeritos::CATEGORIAS as $catKey => $catLabel): ?>
                <td><?= ($fila && $fila['categoria'] === $catKey) ? '✓' : '' ?></td>
                <?php endforeach; ?>
            </tr>
            <?php endfor; ?>
        </tbody>
    </table>

    <h6 class="mt-3">REDENCIÓN DE DEMÉRITOS</h6>
    <table class="form-tabla">
        <thead>
            <tr>
                <th>No.</th><th>Fecha</th><th>Hora</th>
                <th class="leyenda-columna" rowspan="16">
                    Actividades:
                    <ul>
                        <?php foreach (Demeritos::ACTIVIDADES_REDENCION as $label): ?>
                        <li><?= htmlspecialchars($label) ?></li>
                        <?php endforeach; ?>
                    </ul>
                </th>
                <th>Deméritos redimidos</th>
            </tr>
        </thead>
        <tbody>
            <?php for ($i = 0; $i < 15; $i++):
                $fila = $filasRedencion[$i] ?? null;
            ?>
            <tr>
                <td><?= $i + 1 ?></td>
                <td><?= $fila ? date('d/m/Y', strtotime($fila['fecha'])) : '' ?></td>
                <td><?= $fila ? substr($fila['hora'], 0, 5) : '' ?></td>
                <td><?= $fila ? (int) $fila['cantidad_redimida'] : '' ?></td>
            </tr>
            <?php endfor; ?>
        </tbody>
    </table>
</div>

<!-- ===== PÁGINA 2 ===== -->
<div class="hoja">
    <h6>REGISTRO DE CONSECUENCIAS</h6>
    <table class="form-tabla">
        <thead>
            <tr>
                <th>No.</th><th>Fecha</th>
                <th class="leyenda-columna">
                    Escala de consecuencias:
                    <ul>
                        <?php foreach (Demeritos::ESCALA_CONSECUENCIAS as $item): ?>
                        <li><?= htmlspecialchars($item) ?></li>
                        <?php endforeach; ?>
                    </ul>
                </th>
                <th>Firma de notificación</th>
            </tr>
        </thead>
        <tbody>
            <?php for ($i = 0; $i < 5; $i++):
                $fila = $filasConsecuencia[$i] ?? null;
            ?>
            <tr>
                <td><?= $i + 1 ?></td>
                <td><?= $fila ? date('d/m/Y', strtotime($fila['fecha'])) : '' ?></td>
                <td style="text-align: left;"><?= $fila ? htmlspecialchars($fila['descripcion']) : '' ?></td>
                <td>&nbsp;</td>
            </tr>
            <?php endfor; ?>
        </tbody>
    </table>

    <h6>Consolidado de Deméritos - Redenciones</h6>
    <div class="caja-consolidado">
        <table>
            <tr><td>Deméritos acumulados del mes anterior</td><td class="text-end">(+) <?= $acumulado_mes_anterior ?></td></tr>
            <tr><td>Deméritos del presente mes</td><td class="text-end">(+) <?= $presente_mes ?></td></tr>
            <tr><td>Deméritos redimidos</td><td class="text-end">(-) <?= $redimidos_mes ?></td></tr>
            <tr style="border-top: 1px solid #333; font-weight: 700;"><td>Deméritos al mes actual</td><td class="text-end">(=) <?= $mes_actual ?></td></tr>
        </table>
    </div>

    <h6>Observaciones</h6>
    <div class="no-print mb-2">
        <form method="POST" class="d-flex gap-2">
            <input type="hidden" name="accion" value="guardar_observacion">
            <input type="hidden" name="mes" value="<?= htmlspecialchars($mes_filtro) ?>">
            <textarea name="texto" class="form-control" maxlength="1000" rows="2"><?= htmlspecialchars($observacion) ?></textarea>
            <button type="submit" class="btn btn-primary">Guardar</button>
        </form>
    </div>
    <div style="min-height: 60px; border-bottom: 1px solid #999; padding: 4px 0;">
        <?= nl2br(htmlspecialchars($observacion)) ?>
    </div>

    <div class="row mt-5">
        <div class="col-6"><div class="firma-linea">DOCENTE</div></div>
        <div class="col-6"><div class="firma-linea">PADRE / MADRE / ENCARGADO</div></div>
    </div>

    <div class="caja-principios">
        <strong>Principios del Reglamento para la promoción de la cortesía escolar</strong>
        <ul class="mb-0 mt-1">
            <?php foreach (Demeritos::PRINCIPIOS as $titulo => $texto): ?>
            <li><strong><?= htmlspecialchars($titulo) ?>:</strong> <?= htmlspecialchars($texto) ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
</div>

</body>
</html>
