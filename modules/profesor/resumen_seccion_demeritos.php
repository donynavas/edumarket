<?php
// resumen_seccion_demeritos.php — Formulario 2: "Resumen Mensual de
// Sección" (MINEDUCYT), vista del profesor, impresión con
// @media print + window.print(). ?asignacion=<id_asignacion_docente>&mes=YYYY-MM
//
// Ownership idéntica a asistencia.php: la asignación debe pertenecer a
// este profesor. Los conteos se obtienen con DOS consultas agrupadas
// separadas (una por tabla de log: tbl_demerito y tbl_demerito_redencion)
// para no multiplicar filas con un JOIN doble, mezcladas en PHP por
// id_matricula. "Deméritos al mes reportado" es el NETO de ESE mes
// solamente (no el acumulado que sí muestra la Tarjeta individual).

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

$id_asignacion = filter_input(INPUT_GET, 'asignacion', FILTER_VALIDATE_INT);
$mes_filtro = $_GET['mes'] ?? date('Y-m');
if (!preg_match('/^\d{4}-\d{2}$/', $mes_filtro)) {
    $mes_filtro = date('Y-m');
}
$primerDiaMes = $mes_filtro . '-01';
$ultimoDiaMes = date('Y-m-t', strtotime($primerDiaMes));
$mes_num = (int) substr($mes_filtro, 5, 2);
$anno_mes = (int) substr($mes_filtro, 0, 4);

$stmtAsig = $db->prepare("SELECT ad.id_seccion, ad.anno, asig.nombre AS asignatura_nombre,
                          g.nombre AS grado_nombre, s.nombre AS seccion_nombre,
                          per.primer_nombre, per.primer_apellido
                          FROM tbl_asignacion_docente ad
                          JOIN tbl_asignatura asig ON ad.id_asignatura = asig.id
                          JOIN tbl_seccion s ON ad.id_seccion = s.id
                          JOIN tbl_grado g ON s.id_grado = g.id
                          JOIN tbl_profesor p ON ad.id_profesor = p.id
                          JOIN tbl_persona per ON p.id_persona = per.id
                          WHERE ad.id = :id AND ad.id_profesor = :prof");
$stmtAsig->execute([':id' => $id_asignacion, ':prof' => $id_profesor]);
$asignacion = $stmtAsig->fetch(PDO::FETCH_ASSOC);
if (!$asignacion) {
    http_response_code(403);
    die('No tiene permiso para ver este resumen');
}

// ===== ROSTER DE LA SECCIÓN =====
$stmtRoster = $db->prepare("SELECT m.id AS id_matricula, e.nie, p.primer_nombre, p.primer_apellido
                            FROM tbl_matricula m
                            JOIN tbl_estudiante e ON m.id_estudiante = e.id
                            JOIN tbl_persona p ON e.id_persona = p.id
                            WHERE m.id_seccion = :sec AND m.anno = :anno AND m.estado = 'activo'
                            AND e.id_institucion = :tid
                            ORDER BY p.primer_apellido, p.primer_nombre
                            LIMIT 45");
$stmtRoster->execute([':sec' => $asignacion['id_seccion'], ':anno' => $asignacion['anno'], ':tid' => $tid]);
$roster = $stmtRoster->fetchAll(PDO::FETCH_ASSOC);

// ===== CONTEOS: DEMÉRITOS POR CATEGORÍA =====
$stmtDem = $db->prepare("SELECT m.id AS id_matricula,
    SUM(d.categoria = 'no_saludar')     AS c_no_saludar,
    SUM(d.categoria = 'omitir_favor')   AS c_omitir_favor,
    SUM(d.categoria = 'omitir_gracias') AS c_omitir_gracias,
    SUM(d.categoria = 'tono_grosero')   AS c_tono_grosero
    FROM tbl_matricula m
    JOIN tbl_demerito d ON d.id_matricula = m.id AND d.fecha BETWEEN :ini1 AND :fin1
    WHERE m.id_seccion = :sec1 AND m.anno = :anno1 AND m.estado = 'activo'
    GROUP BY m.id");
$stmtDem->execute([':ini1' => $primerDiaMes, ':fin1' => $ultimoDiaMes, ':sec1' => $asignacion['id_seccion'], ':anno1' => $asignacion['anno']]);
$conteosDemerito = [];
foreach ($stmtDem->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $conteosDemerito[$row['id_matricula']] = $row;
}

// ===== CONTEOS: REDENCIÓN POR ACTIVIDAD =====
$stmtRed = $db->prepare("SELECT m.id AS id_matricula,
    SUM(CASE WHEN r.actividad = 'semana_cortesia' THEN r.cantidad_redimida ELSE 0 END)      AS r_semana_cortesia,
    SUM(CASE WHEN r.actividad = 'apoyo_orden_limpieza' THEN r.cantidad_redimida ELSE 0 END) AS r_apoyo_orden_limpieza,
    SUM(CASE WHEN r.actividad = 'campana_valores' THEN r.cantidad_redimida ELSE 0 END)      AS r_campana_valores
    FROM tbl_matricula m
    JOIN tbl_demerito_redencion r ON r.id_matricula = m.id AND r.fecha BETWEEN :ini2 AND :fin2
    WHERE m.id_seccion = :sec2 AND m.anno = :anno2 AND m.estado = 'activo'
    GROUP BY m.id");
$stmtRed->execute([':ini2' => $primerDiaMes, ':fin2' => $ultimoDiaMes, ':sec2' => $asignacion['id_seccion'], ':anno2' => $asignacion['anno']]);
$conteosRedencion = [];
foreach ($stmtRed->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $conteosRedencion[$row['id_matricula']] = $row;
}

// ===== MEZCLAR EN PHP + TOTALES =====
$filas = [];
$totales = ['no_saludar' => 0, 'omitir_favor' => 0, 'omitir_gracias' => 0, 'tono_grosero' => 0,
            'semana_cortesia' => 0, 'apoyo_orden_limpieza' => 0, 'campana_valores' => 0, 'neto' => 0];

foreach ($roster as $est) {
    $mat = $est['id_matricula'];
    $d = $conteosDemerito[$mat] ?? ['c_no_saludar' => 0, 'c_omitir_favor' => 0, 'c_omitir_gracias' => 0, 'c_tono_grosero' => 0];
    $r = $conteosRedencion[$mat] ?? ['r_semana_cortesia' => 0, 'r_apoyo_orden_limpieza' => 0, 'r_campana_valores' => 0];

    $totalCategorias = (int) $d['c_no_saludar'] + (int) $d['c_omitir_favor'] + (int) $d['c_omitir_gracias'] + (int) $d['c_tono_grosero'];
    $totalRedimido = (int) $r['r_semana_cortesia'] + (int) $r['r_apoyo_orden_limpieza'] + (int) $r['r_campana_valores'];
    $neto = max(0, $totalCategorias - $totalRedimido);

    $fila = [
        'nombre' => trim($est['primer_nombre'] . ' ' . $est['primer_apellido']),
        'nie' => $est['nie'],
        'no_saludar' => (int) $d['c_no_saludar'], 'omitir_favor' => (int) $d['c_omitir_favor'],
        'omitir_gracias' => (int) $d['c_omitir_gracias'], 'tono_grosero' => (int) $d['c_tono_grosero'],
        'semana_cortesia' => (int) $r['r_semana_cortesia'], 'apoyo_orden_limpieza' => (int) $r['r_apoyo_orden_limpieza'],
        'campana_valores' => (int) $r['r_campana_valores'], 'neto' => $neto,
    ];
    $filas[] = $fila;

    foreach ($totales as $k => $v) {
        $totales[$k] += $fila[$k];
    }
}

$stmtInst = $db->prepare("SELECT nombre_ce, codigo_infra FROM tbl_institucion WHERE id = :tid");
$stmtInst->execute([':tid' => $tid]);
$institucion = $stmtInst->fetch(PDO::FETCH_ASSOC);

$mesTexto = Demeritos::MESES_ES[$mes_num] . ' ' . $anno_mes;
$docenteNombre = trim($asignacion['primer_nombre'] . ' ' . $asignacion['primer_apellido']);
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>Resumen de Sección - Deméritos</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
<style>
    body { font-family: 'Segoe UI', system-ui, -apple-system, sans-serif; background: #f5f7fa; padding: 20px; }
    .hoja { max-width: 1100px; margin: 0 auto; background: white; padding: 30px; border-radius: 8px; box-shadow: 0 2px 12px rgba(0,0,0,0.08); }
    .hoja-titulo { text-align: center; font-weight: 700; font-size: 1.2rem; margin-bottom: 4px; }
    .hoja-subtitulo { text-align: center; color: #666; margin-bottom: 20px; }
    .encabezado-datos { display: grid; grid-template-columns: 1fr 1fr; gap: 4px 20px; margin-bottom: 16px; font-size: 0.9rem; }
    .encabezado-datos div { border-bottom: 1px dotted #999; padding: 2px 0; }
    table.form-tabla { width: 100%; border-collapse: collapse; font-size: 0.68rem; table-layout: fixed; }
    table.form-tabla th, table.form-tabla td { border: 1px solid #999; padding: 3px 5px; text-align: center; word-wrap: break-word; }
    table.form-tabla th { background: #eef1f4; font-weight: 600; line-height: 1.2; }
    table.form-tabla td:first-child, table.form-tabla th:first-child { text-align: left; }
    tr.fila-totales { font-weight: 700; background: #f8f9fa; }
    .firma-linea { border-top: 1px solid #333; margin-top: 60px; padding-top: 4px; text-align: center; font-size: 0.85rem; }
    @media print {
        body { background: white; padding: 0; }
        .hoja { box-shadow: none; border-radius: 0; max-width: 100%; margin: 0; padding: 10px; }
        .no-print { display: none !important; }
    }
</style>
</head>
<body>

<div class="text-end no-print mb-2" style="max-width: 1100px; margin: 0 auto;">
    <a href="demeritos.php?asignacion=<?= (int) $id_asignacion ?>" class="btn btn-outline-secondary btn-sm"><i class="fas fa-arrow-left"></i> Volver</a>
    <button class="btn btn-primary btn-sm" onclick="window.print()"><i class="fas fa-print"></i> Imprimir</button>
</div>

<div class="hoja">
    <div class="hoja-titulo">RESUMEN MENSUAL DE SECCIÓN</div>
    <div class="hoja-subtitulo">Reglamento para la Promoción de la Cortesía Escolar</div>

    <div class="encabezado-datos">
        <div><strong>Código:</strong> <?= htmlspecialchars($institucion['codigo_infra'] ?? '') ?></div>
        <div><strong>Centro educativo:</strong> <?= htmlspecialchars($institucion['nombre_ce'] ?? '') ?></div>
        <div><strong>Mes reportado:</strong> <?= htmlspecialchars($mesTexto) ?></div>
        <div><strong>Docente:</strong> <?= htmlspecialchars($docenteNombre) ?> — <?= htmlspecialchars($asignacion['grado_nombre'] . ' ' . $asignacion['seccion_nombre']) ?></div>
    </div>

    <table class="form-tabla">
        <thead>
            <tr>
                <th rowspan="2">Estudiante</th><th rowspan="2">NIE</th>
                <th colspan="4">Deméritos</th>
                <th colspan="3">Redenciones</th>
                <th rowspan="2">Deméritos al mes reportado</th>
            </tr>
            <tr>
                <?php foreach (Demeritos::CATEGORIAS as $label): ?>
                <th><?= htmlspecialchars($label) ?></th>
                <?php endforeach; ?>
                <?php foreach (Demeritos::ACTIVIDADES_REDENCION as $label): ?>
                <th><?= htmlspecialchars($label) ?></th>
                <?php endforeach; ?>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($filas)): ?>
            <tr><td colspan="9">No hay estudiantes matriculados en esta sección</td></tr>
            <?php else: ?>
            <?php foreach ($filas as $fila): ?>
            <tr>
                <td><?= htmlspecialchars($fila['nombre']) ?></td>
                <td><?= htmlspecialchars($fila['nie']) ?></td>
                <td><?= $fila['no_saludar'] ?></td><td><?= $fila['omitir_favor'] ?></td>
                <td><?= $fila['omitir_gracias'] ?></td><td><?= $fila['tono_grosero'] ?></td>
                <td><?= $fila['semana_cortesia'] ?></td><td><?= $fila['apoyo_orden_limpieza'] ?></td><td><?= $fila['campana_valores'] ?></td>
                <td><strong><?= $fila['neto'] ?></strong></td>
            </tr>
            <?php endforeach; ?>
            <tr class="fila-totales">
                <td colspan="2">TOTALES</td>
                <td><?= $totales['no_saludar'] ?></td><td><?= $totales['omitir_favor'] ?></td>
                <td><?= $totales['omitir_gracias'] ?></td><td><?= $totales['tono_grosero'] ?></td>
                <td><?= $totales['semana_cortesia'] ?></td><td><?= $totales['apoyo_orden_limpieza'] ?></td><td><?= $totales['campana_valores'] ?></td>
                <td><?= $totales['neto'] ?></td>
            </tr>
            <?php endif; ?>
        </tbody>
    </table>

    <div class="row mt-5">
        <div class="col-6"><div class="firma-linea">DIRECTOR DEL CENTRO EDUCATIVO</div></div>
        <div class="col-6"><div class="firma-linea">DOCENTE RESPONSABLE DE SECCIÓN</div></div>
    </div>
</div>

</body>
</html>
