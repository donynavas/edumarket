<?php
// resumen_centro_demeritos.php — Formulario 3: "Resumen Mensual del Centro
// Educativo" (MINEDUCYT), vista del director/admin, impresión con
// @media print + window.print(). ?mes=YYYY-MM
//
// Mismo enfoque de dos consultas agrupadas separadas que
// resumen_seccion_demeritos.php, pero agregadas por sección
// (m.id_seccion) en vez de por matrícula, para todo el centro educativo.
//
// El bloque <nav> de este archivo es local a esta página (no existe un
// partial de cabecera compartido en modules/admin/, a diferencia de
// modules/profesor/partials/header.php) -- agregar el enlace aquí no lo
// propaga a las demás páginas de admin (limitación preexistente, ver
// gestionar_grados.php).

session_start();
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/TenantGuard.php';
require_once __DIR__ . '/../../config/Demeritos.php';

if (!isset($_SESSION['user_id']) || ($_SESSION['rol'] != 'admin' && $_SESSION['rol'] != 'director')) {
    header("Location: " . BASE_URL . "/login.php");
    exit;
}

$database = new Database();
$db = $database->getConnection();
$tid = TenantGuard::id();

$mes_filtro = $_GET['mes'] ?? date('Y-m');
if (!preg_match('/^\d{4}-\d{2}$/', $mes_filtro)) {
    $mes_filtro = date('Y-m');
}
$primerDiaMes = $mes_filtro . '-01';
$ultimoDiaMes = date('Y-m-t', strtotime($primerDiaMes));
$mes_num = (int) substr($mes_filtro, 5, 2);
$anno_mes = (int) substr($mes_filtro, 0, 4);

// ===== SECCIONES DEL CENTRO (año lectivo del mes reportado) =====
$stmtSec = $db->prepare("SELECT s.id AS id_seccion, s.nombre AS seccion_nombre, g.nombre AS grado_nombre
                         FROM tbl_seccion s
                         JOIN tbl_grado g ON s.id_grado = g.id
                         WHERE s.id_institucion = :tid AND s.anno_lectivo = :anno
                         ORDER BY g.nombre, s.nombre
                         LIMIT 25");
$stmtSec->execute([':tid' => $tid, ':anno' => $anno_mes]);
$secciones = $stmtSec->fetchAll(PDO::FETCH_ASSOC);

// ===== CONTEOS: DEMÉRITOS POR CATEGORÍA, AGREGADOS POR SECCIÓN =====
$stmtDem = $db->prepare("SELECT m.id_seccion,
    SUM(d.categoria = 'no_saludar')     AS c_no_saludar,
    SUM(d.categoria = 'omitir_favor')   AS c_omitir_favor,
    SUM(d.categoria = 'omitir_gracias') AS c_omitir_gracias,
    SUM(d.categoria = 'tono_grosero')   AS c_tono_grosero
    FROM tbl_matricula m
    JOIN tbl_demerito d ON d.id_matricula = m.id AND d.fecha BETWEEN :ini1 AND :fin1
    WHERE m.anno = :anno1 AND m.estado = 'activo' AND d.id_institucion = :tid1
    GROUP BY m.id_seccion");
$stmtDem->execute([':ini1' => $primerDiaMes, ':fin1' => $ultimoDiaMes, ':anno1' => $anno_mes, ':tid1' => $tid]);
$conteosDemerito = [];
foreach ($stmtDem->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $conteosDemerito[$row['id_seccion']] = $row;
}

// ===== CONTEOS: REDENCIÓN POR ACTIVIDAD, AGREGADOS POR SECCIÓN =====
$stmtRed = $db->prepare("SELECT m.id_seccion,
    SUM(CASE WHEN r.actividad = 'semana_cortesia' THEN r.cantidad_redimida ELSE 0 END)      AS r_semana_cortesia,
    SUM(CASE WHEN r.actividad = 'apoyo_orden_limpieza' THEN r.cantidad_redimida ELSE 0 END) AS r_apoyo_orden_limpieza,
    SUM(CASE WHEN r.actividad = 'campana_valores' THEN r.cantidad_redimida ELSE 0 END)      AS r_campana_valores
    FROM tbl_matricula m
    JOIN tbl_demerito_redencion r ON r.id_matricula = m.id AND r.fecha BETWEEN :ini2 AND :fin2
    WHERE m.anno = :anno2 AND m.estado = 'activo' AND r.id_institucion = :tid2
    GROUP BY m.id_seccion");
$stmtRed->execute([':ini2' => $primerDiaMes, ':fin2' => $ultimoDiaMes, ':anno2' => $anno_mes, ':tid2' => $tid]);
$conteosRedencion = [];
foreach ($stmtRed->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $conteosRedencion[$row['id_seccion']] = $row;
}

// ===== MEZCLAR EN PHP + TOTALES =====
$filas = [];
$totales = ['no_saludar' => 0, 'omitir_favor' => 0, 'omitir_gracias' => 0, 'tono_grosero' => 0,
            'semana_cortesia' => 0, 'apoyo_orden_limpieza' => 0, 'campana_valores' => 0, 'neto' => 0];

foreach ($secciones as $sec) {
    $id_sec = $sec['id_seccion'];
    $d = $conteosDemerito[$id_sec] ?? ['c_no_saludar' => 0, 'c_omitir_favor' => 0, 'c_omitir_gracias' => 0, 'c_tono_grosero' => 0];
    $r = $conteosRedencion[$id_sec] ?? ['r_semana_cortesia' => 0, 'r_apoyo_orden_limpieza' => 0, 'r_campana_valores' => 0];

    $totalCategorias = (int) $d['c_no_saludar'] + (int) $d['c_omitir_favor'] + (int) $d['c_omitir_gracias'] + (int) $d['c_tono_grosero'];
    $totalRedimido = (int) $r['r_semana_cortesia'] + (int) $r['r_apoyo_orden_limpieza'] + (int) $r['r_campana_valores'];
    $neto = max(0, $totalCategorias - $totalRedimido);

    $fila = [
        'grado' => $sec['grado_nombre'], 'seccion' => $sec['seccion_nombre'],
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

$stmtDirector = $db->prepare("SELECT nombre FROM tbl_usuario WHERE id_institucion = :tid AND rol = 'director' AND estado = 1 LIMIT 1");
$stmtDirector->execute([':tid' => $tid]);
$directorNombre = (string) $stmtDirector->fetchColumn();

$mesTexto = Demeritos::MESES_ES[$mes_num] . ' ' . $anno_mes;
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>Resumen del Centro Educativo - Deméritos</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
<style>
    :root { --primary: #2c3e50; --secondary: #3498db; --sidebar-width: 260px; }
    body { font-family: 'Segoe UI', system-ui, -apple-system, sans-serif; background: #f5f7fa; }
    .sidebar { position: fixed; top: 0; left: 0; height: 100vh; width: var(--sidebar-width); background: var(--primary); color: white; padding-top: 20px; z-index: 1000; }
    .sidebar .nav-link { color: rgba(255,255,255,0.8); padding: 12px 20px; }
    .sidebar .nav-link:hover, .sidebar .nav-link.active { color: white; background: rgba(255,255,255,0.15); }
    .main-content { margin-left: var(--sidebar-width); padding: 20px; }
    .hoja { max-width: 1150px; background: white; padding: 24px; border-radius: 8px; box-shadow: 0 2px 12px rgba(0,0,0,0.08); }
    .hoja-titulo { text-align: center; font-weight: 700; font-size: 1.2rem; margin-bottom: 4px; }
    .hoja-subtitulo { text-align: center; color: #666; margin-bottom: 16px; }
    .encabezado-datos { display: grid; grid-template-columns: 1fr 1fr; gap: 4px 20px; margin-bottom: 14px; font-size: 0.85rem; }
    .encabezado-datos div { border-bottom: 1px dotted #999; padding: 2px 0; }
    table.form-tabla { width: 100%; border-collapse: collapse; font-size: 0.65rem; table-layout: fixed; }
    table.form-tabla th, table.form-tabla td { border: 1px solid #999; padding: 2px 4px; text-align: center; word-wrap: break-word; }
    table.form-tabla th { background: #eef1f4; font-weight: 600; line-height: 1.2; }
    table.form-tabla td:first-child, table.form-tabla th:first-child { text-align: left; }
    tr.fila-totales { font-weight: 700; background: #f8f9fa; }
    .firma-linea { border-top: 1px solid #333; margin-top: 40px; padding-top: 4px; text-align: center; font-size: 0.85rem; width: 300px; margin-left: auto; margin-right: auto; }
    @media (max-width: 992px) { .sidebar { transform: translateX(-100%); } .main-content { margin-left: 0; } }
    @media print { .sidebar, .no-print { display: none !important; } .main-content { margin-left: 0; padding: 0; } .hoja { box-shadow: none; border-radius: 0; max-width: 100%; padding: 10px; } }
</style>
</head>
<body>
    <div class="sidebar" id="sidebar">
        <div class="text-center mb-4">
            <h4><i class="fas fa-graduation-cap"></i> Educación Plus</h4>
            <small>Panel de Administración</small>
        </div>
        <nav class="nav flex-column">
            <a class="nav-link" href="../../index.php"><i class="fas fa-tachometer-alt"></i> Dashboard</a>
            <a class="nav-link" href="gestionar_estudiantes.php"><i class="fas fa-user-graduate"></i> Estudiantes</a>
            <a class="nav-link" href="gestionar_profesores.php"><i class="fas fa-chalkboard-teacher"></i> Profesores</a>
            <a class="nav-link" href="gestionar_grados.php"><i class="fas fa-layer-group"></i> Grados/Secciones</a>
            <a class="nav-link" href="gestionar_asignaturas.php"><i class="fas fa-book"></i> Asignaturas</a>
            <a class="nav-link" href="gestionar_matriculas.php"><i class="fas fa-file-signature"></i> Matrículas</a>
            <a class="nav-link" href="cuadro_notas.php"><i class="fas fa-clipboard-list"></i> Cuadro de Notas</a>
            <a class="nav-link active" href="resumen_centro_demeritos.php"><i class="fas fa-exclamation-triangle"></i> Deméritos</a>
            <a class="nav-link" href="manual_convivencia.php"><i class="fas fa-handshake"></i> Convivencia Escolar</a>
            <a class="nav-link" href="../../logout.php"><i class="fas fa-sign-out-alt"></i> Cerrar Sesión</a>
        </nav>
    </div>

    <div class="main-content">
        <div class="d-flex justify-content-between align-items-center mb-3 no-print">
            <h2><i class="fas fa-exclamation-triangle"></i> Resumen del Centro Educativo — Deméritos</h2>
            <div class="d-flex gap-2 align-items-end">
                <form method="GET" class="d-flex gap-2 align-items-end">
                    <div>
                        <label class="form-label small text-muted mb-0">Mes</label>
                        <input type="month" name="mes" class="form-control" value="<?= htmlspecialchars($mes_filtro) ?>" onchange="this.form.submit()">
                    </div>
                </form>
                <button class="btn btn-primary" onclick="window.print()"><i class="fas fa-print"></i> Imprimir</button>
            </div>
        </div>

        <div class="hoja">
            <div class="hoja-titulo">RESUMEN MENSUAL DEL CENTRO EDUCATIVO</div>
            <div class="hoja-subtitulo">Reglamento para la Promoción de la Cortesía Escolar</div>

            <div class="encabezado-datos">
                <div><strong>Código:</strong> <?= htmlspecialchars($institucion['codigo_infra'] ?? '') ?></div>
                <div><strong>Institución:</strong> <?= htmlspecialchars($institucion['nombre_ce'] ?? '') ?></div>
                <div><strong>Mes:</strong> <?= htmlspecialchars($mesTexto) ?></div>
                <div><strong>Director/a:</strong> <?= htmlspecialchars($directorNombre) ?></div>
            </div>

            <table class="form-tabla">
                <thead>
                    <tr>
                        <th rowspan="2">Grado</th>
                        <th rowspan="2">Sección</th>
                        <th colspan="4">Deméritos</th>
                        <th colspan="3">Redención de deméritos</th>
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
                    <tr><td colspan="9">No hay secciones registradas para este año lectivo</td></tr>
                    <?php else: ?>
                    <?php foreach ($filas as $fila): ?>
                    <tr>
                        <td><?= htmlspecialchars($fila['grado']) ?></td>
                        <td><?= htmlspecialchars($fila['seccion']) ?></td>
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

            <div class="firma-linea">DIRECTOR/A</div>
        </div>
    </div>
</body>
</html>
