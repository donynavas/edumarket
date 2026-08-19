<?php
/**
 * Cuadro de Notas (criterio "e" del pedido del usuario): un control de
 * notas por asignatura, por estudiante matriculado, con reglas DISTINTAS
 * para educación básica y bachillerato -- fórmulas exactas leídas de los
 * Excel que el usuario compartió (Educacion_basica.xlsx / Educacion_media.xlsx),
 * ver el plan aprobado para el detalle celda a celda.
 *
 * Modos vía querystring: ?asignacion=<id_asignacion_docente>&periodo=<numero>&vista=ingreso|resumen
 * - vista=ingreso (default): grilla editable del periodo elegido.
 * - vista=resumen: solo para bachillerato, solo lectura, consolidado de los 4 periodos.
 *
 * El guardado real ocurre en api/guardar_nota_periodo.php (AJAX); esta
 * página siempre lee de la BD (nunca confía en lo que el usuario acaba de
 * escribir en el navegador), así que tras guardar se recarga para mostrar
 * los valores autoritativos que PHP calculó al escribir.
 */
session_start();
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/TenantGuard.php';
require_once __DIR__ . '/../../config/PeriodoHelper.php';

if (!isset($_SESSION['user_id']) || ($_SESSION['rol'] != 'admin' && $_SESSION['rol'] != 'director')) {
    header("Location: " . BASE_URL . "/login.php");
    exit;
}

$database = new Database();
$db = $database->getConnection();
$tid = TenantGuard::id();

$mensaje = '';
$tipo_mensaje = '';
if (isset($_GET['guardado'])) { $mensaje = 'Notas guardadas exitosamente.'; $tipo_mensaje = 'success'; }

// ===== ASIGNACIONES DOCENTES DEL TENANT (selector superior) =====
$stmtAsignaciones = $db->prepare("SELECT ad.id, ad.anno,
        CONCAT(per.primer_nombre, ' ', per.primer_apellido) as profesor_nombre,
        asig.nombre as asignatura_nombre,
        g.nombre as grado_nombre, g.nivel as grado_nivel, g.nota_minima_aprobacion,
        s.nombre as seccion_nombre, ad.id_seccion
        FROM tbl_asignacion_docente ad
        JOIN tbl_profesor p ON ad.id_profesor = p.id
        JOIN tbl_persona per ON p.id_persona = per.id
        JOIN tbl_asignatura asig ON ad.id_asignatura = asig.id
        JOIN tbl_seccion s ON ad.id_seccion = s.id
        JOIN tbl_grado g ON s.id_grado = g.id
        WHERE p.id_institucion = :tid AND ad.estado = 1
        ORDER BY g.nivel, g.nombre, s.nombre, asig.nombre");
$stmtAsignaciones->execute([':tid' => $tid]);
$asignaciones = $stmtAsignaciones->fetchAll(PDO::FETCH_ASSOC);

// El GET 'asignacion' se valida con TenantGuard ANTES de usarlo en
// cualquier consulta -- si es de otra institución, corta con 403 aquí
// mismo (assertOwner ya soporta tbl_asignacion_docente via tbl_profesor).
$id_asignacion = (int) ($_GET['asignacion'] ?? 0);
if ($id_asignacion) {
    TenantGuard::assertOwner($db, 'tbl_asignacion_docente', $id_asignacion);
} elseif (!empty($asignaciones)) {
    $id_asignacion = (int) $asignaciones[0]['id'];
}

$asignacion_actual = null;
foreach ($asignaciones as $a) {
    if ((int) $a['id'] === $id_asignacion) { $asignacion_actual = $a; break; }
}

$nivel = $asignacion_actual['grado_nivel'] ?? null;
$anno = (int) ($asignacion_actual['anno'] ?? date('Y'));
$id_seccion = (int) ($asignacion_actual['id_seccion'] ?? 0);
$niveles_label = ['basica' => 'Educación Básica', 'bachillerato' => 'Bachillerato'];

$periodos = [];
$periodo_actual = null;
$numero_periodo = 1;
$vista = 'ingreso';

if ($asignacion_actual) {
    // Siembra bajo demanda -- ver config/PeriodoHelper.php (Fase 5).
    PeriodoHelper::asegurar($db, $tid, $anno);

    $stmtPer = $db->prepare("SELECT id, numero, nombre, fecha_inicio, fecha_fin
        FROM tbl_periodo WHERE id_institucion = :tid AND anno = :anno AND nivel = :nivel ORDER BY numero");
    $stmtPer->execute([':tid' => $tid, ':anno' => $anno, ':nivel' => $nivel]);
    $periodos = $stmtPer->fetchAll(PDO::FETCH_ASSOC);

    $numero_periodo = (int) ($_GET['periodo'] ?? 1);
    if ($numero_periodo < 1 || $numero_periodo > count($periodos)) {
        $numero_periodo = 1;
    }
    foreach ($periodos as $p) {
        if ((int) $p['numero'] === $numero_periodo) { $periodo_actual = $p; break; }
    }

    // vista=resumen solo tiene sentido (y solo se ofrece) para bachillerato.
    $vista = (($_GET['vista'] ?? 'ingreso') === 'resumen' && $nivel === 'bachillerato') ? 'resumen' : 'ingreso';
}

// ===== ESTUDIANTES MATRICULADOS (arranca desde matrícula, mismo patrón
// que modules/profesor/calificaciones.php y asistencia.php, para que
// aparezcan todos los inscritos aunque aún no tengan ninguna nota) =====
$estudiantes = [];
if ($asignacion_actual) {
    $stmtMat = $db->prepare("SELECT m.id as id_matricula, e.nie, per2.primer_nombre, per2.primer_apellido
        FROM tbl_matricula m
        JOIN tbl_estudiante e ON m.id_estudiante = e.id
        JOIN tbl_persona per2 ON e.id_persona = per2.id
        WHERE m.id_seccion = :sec AND m.anno = :anno AND m.estado = 'activo'
        ORDER BY per2.primer_apellido, per2.primer_nombre");
    $stmtMat->execute([':sec' => $id_seccion, ':anno' => $anno]);
    $estudiantes = $stmtMat->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Trae las notas de tbl_nota_periodo de esta asignación+periodo, indexadas
 * por [id_matricula][bloque][numero_nota] = valor (float).
 */
function obtenerNotasPeriodo(PDO $db, int $idAsignacion, int $idPeriodo): array
{
    $stmt = $db->prepare("SELECT id_matricula, bloque, numero_nota, valor
        FROM tbl_nota_periodo WHERE id_asignacion_docente = :asig AND id_periodo = :per");
    $stmt->execute([':asig' => $idAsignacion, ':per' => $idPeriodo]);
    $out = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $out[(int) $row['id_matricula']][$row['bloque']][(int) $row['numero_nota']] = $row['valor'] !== null ? (float) $row['valor'] : null;
    }
    return $out;
}

/** Básica: promedio = AVERAGE(n1..n8), ignora celdas vacías (igual que Excel). */
function promedioBasica(array $notasUnico): ?float
{
    $vals = array_filter($notasUnico, fn($v) => $v !== null);
    if (empty($vals)) return null;
    return array_sum($vals) / count($vals);
}

/**
 * Bachillerato: a diferencia de básica, SUM trata vacío como 0 (igual que
 * el Excel) -- no es un promedio. suma/4*35% en cada bloque, ex*30%, P.F es
 * la suma de las tres columnas ponderadas.
 */
function calcularBachillerato(array $notasPorBloque): array
{
    $b1 = $notasPorBloque['bloque1'] ?? [];
    $b2 = $notasPorBloque['bloque2'] ?? [];
    $ex = $notasPorBloque['examen'][1] ?? null;

    $suma1 = ($b1[1] ?? 0) + ($b1[2] ?? 0) + ($b1[3] ?? 0) + ($b1[4] ?? 0);
    $suma2 = ($b2[1] ?? 0) + ($b2[2] ?? 0) + ($b2[3] ?? 0) + ($b2[4] ?? 0);
    $pct35_1 = ($suma1 / 4) * 0.35;
    $pct35_2 = ($suma2 / 4) * 0.35;
    $pct30 = ($ex ?? 0) * 0.30;
    $pf = $pct35_1 + $pct35_2 + $pct30;

    return ['suma1' => $suma1, 'pct35_1' => $pct35_1, 'suma2' => $suma2, 'pct35_2' => $pct35_2, 'ex' => $ex, 'pct30' => $pct30, 'pf' => $pf];
}

$filas_ingreso = [];
$resumen = []; // solo vista=resumen: [id_matricula => ['periodos'=>[numero=>calc], 'suma_anual'=>, 'nf'=>]]

if ($asignacion_actual && $vista === 'ingreso' && $periodo_actual) {
    $notas = obtenerNotasPeriodo($db, $id_asignacion, (int) $periodo_actual['id']);
    foreach ($estudiantes as $est) {
        $idMat = (int) $est['id_matricula'];
        $notasEst = $notas[$idMat] ?? [];
        $fila = [
            'id_matricula' => $idMat,
            'nie' => $est['nie'],
            'nombre' => trim($est['primer_nombre'] . ' ' . $est['primer_apellido']),
        ];
        if ($nivel === 'basica') {
            $unico = $notasEst['unico'] ?? [];
            for ($i = 1; $i <= 8; $i++) { $fila["n$i"] = $unico[$i] ?? null; }
            $fila['promedio'] = promedioBasica($unico);
        } else {
            $calc = calcularBachillerato($notasEst);
            $b1 = $notasEst['bloque1'] ?? [];
            $b2 = $notasEst['bloque2'] ?? [];
            for ($i = 1; $i <= 4; $i++) {
                $fila["b1n$i"] = $b1[$i] ?? null;
                $fila["b2n$i"] = $b2[$i] ?? null;
            }
            $fila['ex'] = $calc['ex'];
            $fila = array_merge($fila, $calc);
        }
        $filas_ingreso[] = $fila;
    }
} elseif ($asignacion_actual && $vista === 'resumen') {
    // Consolidado bachillerato: recorre los 4 periodos y arma, por
    // estudiante, 35%/35%/30%/P.F de cada uno + Suma anual + NF.
    $notasPorPeriodo = [];
    foreach ($periodos as $p) {
        $notasPorPeriodo[(int) $p['numero']] = obtenerNotasPeriodo($db, $id_asignacion, (int) $p['id']);
    }
    foreach ($estudiantes as $est) {
        $idMat = (int) $est['id_matricula'];
        $fila = [
            'id_matricula' => $idMat,
            'nie' => $est['nie'],
            'nombre' => trim($est['primer_nombre'] . ' ' . $est['primer_apellido']),
            'periodos' => [],
        ];
        $sumaAnual = 0;
        foreach ($periodos as $p) {
            $numero = (int) $p['numero'];
            $notasEst = $notasPorPeriodo[$numero][$idMat] ?? [];
            $calc = calcularBachillerato($notasEst);
            $fila['periodos'][$numero] = $calc;
            $sumaAnual += $calc['pf'];
        }
        $fila['suma_anual'] = $sumaAnual;
        $fila['nf'] = $sumaAnual / max(1, count($periodos));
        $resumen[] = $fila;
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Cuadro de Notas - Educación Plus</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <style>
        :root { --primary: #2c3e50; --secondary: #3498db; --success: #2ecc71; --warning: #f39c12; --danger: #e74c3c; --sidebar-width: 250px; }
        body { font-family: 'Segoe UI', sans-serif; background: #f8f9fa; }
        .sidebar { position: fixed; top: 0; left: 0; height: 100vh; width: var(--sidebar-width); background: var(--primary); color: white; padding-top: 60px; z-index: 1000; }
        .sidebar .nav-link { color: rgba(255,255,255,0.8); padding: 12px 20px; }
        .sidebar .nav-link:hover, .sidebar .nav-link.active { color: white; background: rgba(255,255,255,0.15); }
        .main-content { margin-left: var(--sidebar-width); padding: 20px; }
        .card-custom { background: white; border-radius: 10px; box-shadow: 0 2px 10px rgba(0,0,0,0.08); border: none; margin-bottom: 24px; }
        .badge-nivel { padding: 5px 12px; border-radius: 20px; font-size: 0.75rem; font-weight: 600; }
        .badge-nivel.basica { background: #d4edda; color: #155724; }
        .badge-nivel.bachillerato { background: #fff3cd; color: #856404; }
        .nota-input { width: 62px; text-align: center; padding: 2px 4px; }
        .nota-input.aprobado { color: var(--success); font-weight: 700; }
        .nota-input.reprobado { color: var(--danger); font-weight: 700; }
        .col-calc { background: #f1f3f5; font-weight: 700; text-align: center; }
        .col-calc.aprobado { color: var(--success); }
        .col-calc.reprobado { color: var(--danger); }
        .periodo-tabs .nav-link { border-radius: 20px; margin-right: 6px; }
        .table-notas th, .table-notas td { vertical-align: middle; white-space: nowrap; }
        .bloque-divider { border-left: 2px solid #dee2e6; }
        @media (max-width: 768px) { .sidebar { transform: translateX(-100%); } .sidebar.active { transform: translateX(0); } .main-content { margin-left: 0; } }
        @media print { .sidebar, .no-print, .btn { display: none !important; } .main-content { margin-left: 0; } }
    </style>
</head>
<body>
    <!-- Sidebar -->
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
            <a class="nav-link active" href="cuadro_notas.php"><i class="fas fa-clipboard-list"></i> Cuadro de Notas</a>
            <a class="nav-link" href="../../logout.php"><i class="fas fa-sign-out-alt"></i> Cerrar Sesión</a>
        </nav>
    </div>

    <!-- Main Content -->
    <div class="main-content">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <div>
                <h2><i class="fas fa-clipboard-list"></i> Cuadro de Notas</h2>
                <p class="text-muted mb-0">Control de notas por asignatura y estudiante matriculado</p>
            </div>
        </div>

        <?php if ($mensaje): ?>
        <div class="alert alert-<?= $tipo_mensaje ?> alert-dismissible fade show">
            <?= htmlspecialchars($mensaje) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php endif; ?>

        <?php if (empty($asignaciones)): ?>
        <div class="card-custom p-5 text-center">
            <i class="fas fa-exclamation-triangle fa-3x text-warning mb-3"></i>
            <h4>No hay asignaciones docentes registradas</h4>
            <p class="text-muted">Primero asigna un profesor a una asignatura y sección en <a href="gestionar_profesores.php">Profesores → Asignar Materias</a>.</p>
        </div>
        <?php else: ?>

        <!-- Selector de Asignación -->
        <div class="card-custom p-3">
            <form method="GET" class="row g-3 align-items-end" id="formSelector">
                <div class="col-md-8">
                    <label class="form-label small text-muted">Asignación (Profesor · Asignatura · Grado/Sección)</label>
                    <select name="asignacion" class="form-select" onchange="this.form.submit()">
                        <?php foreach ($asignaciones as $a): ?>
                        <option value="<?= $a['id'] ?>" <?= $id_asignacion == $a['id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($a['profesor_nombre']) ?> — <?= htmlspecialchars($a['asignatura_nombre']) ?> —
                            <?= htmlspecialchars($a['grado_nombre']) ?> "<?= htmlspecialchars($a['seccion_nombre']) ?>" (<?= $a['anno'] ?>)
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php if ($asignacion_actual): ?>
                <div class="col-md-4 text-md-end">
                    <span class="badge-nivel <?= $nivel ?>"><?= $niveles_label[$nivel] ?? $nivel ?></span>
                    <span class="badge bg-light text-dark border"><i class="fas fa-check-circle text-success"></i> Aprueba con <?= number_format($asignacion_actual['nota_minima_aprobacion'], 1) ?>/10</span>
                </div>
                <?php endif; ?>
            </form>
        </div>

        <?php if (!$asignacion_actual): ?>
        <div class="card-custom p-5 text-center">
            <i class="fas fa-exclamation-triangle fa-3x text-warning mb-3"></i>
            <h5>Selecciona una asignación válida</h5>
        </div>
        <?php else: ?>

        <!-- Tabs de Periodo + toggle Resumen -->
        <div class="card-custom p-3">
            <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
                <ul class="nav periodo-tabs mb-0">
                    <?php foreach ($periodos as $p): ?>
                    <li class="nav-item">
                        <a class="nav-link <?= ($vista === 'ingreso' && $numero_periodo == $p['numero']) ? 'active bg-primary text-white' : 'bg-light text-dark' ?>"
                           href="?asignacion=<?= $id_asignacion ?>&periodo=<?= $p['numero'] ?>&vista=ingreso"
                           title="<?= htmlspecialchars($p['fecha_inicio']) ?> a <?= htmlspecialchars($p['fecha_fin']) ?>">
                            <?= htmlspecialchars($p['nombre']) ?>
                        </a>
                    </li>
                    <?php endforeach; ?>
                </ul>
                <?php if ($nivel === 'bachillerato'): ?>
                <a class="btn btn-sm <?= $vista === 'resumen' ? 'btn-primary' : 'btn-outline-primary' ?>" href="?asignacion=<?= $id_asignacion ?>&vista=resumen">
                    <i class="fas fa-table"></i> Ver Resumen Anual
                </a>
                <?php endif; ?>
            </div>
        </div>

        <?php if (empty($estudiantes)): ?>
        <div class="card-custom p-5 text-center">
            <i class="fas fa-user-graduate fa-4x text-muted mb-3"></i>
            <h5>No hay estudiantes matriculados activos en esta sección</h5>
        </div>

        <?php elseif ($vista === 'ingreso' && $nivel === 'basica'): ?>
        <!-- ===== GRILLA BÁSICA: n1..n8 + promedio = AVERAGE ===== -->
        <form id="formNotas">
            <input type="hidden" name="id_asignacion" value="<?= $id_asignacion ?>">
            <input type="hidden" name="periodo" value="<?= $numero_periodo ?>">
            <div class="card-custom">
                <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
                    <h5 class="mb-0"><i class="fas fa-list"></i> <?= htmlspecialchars($periodo_actual['nombre'] ?? '') ?> <span class="badge bg-primary ms-2"><?= count($estudiantes) ?></span></h5>
                    <button type="submit" class="btn btn-primary btn-sm"><i class="fas fa-save"></i> Guardar Notas</button>
                </div>
                <div class="table-responsive">
                    <table class="table table-hover table-notas mb-0 align-middle">
                        <thead class="table-light">
                            <tr>
                                <th>NIE</th>
                                <th>Estudiante</th>
                                <?php for ($i = 1; $i <= 8; $i++): ?><th>n<?= $i ?></th><?php endfor; ?>
                                <th class="col-calc">Promedio</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($filas_ingreso as $fila): ?>
                            <tr data-id-matricula="<?= $fila['id_matricula'] ?>">
                                <td><?= htmlspecialchars($fila['nie']) ?></td>
                                <td><?= htmlspecialchars($fila['nombre']) ?></td>
                                <?php for ($i = 1; $i <= 8; $i++): ?>
                                <td><input type="number" class="form-control form-control-sm nota-input" min="0" max="10" step="0.1"
                                    name="notas[<?= $fila['id_matricula'] ?>][n<?= $i ?>]" value="<?= $fila["n$i"] ?? '' ?>"
                                    oninput="recalcularBasica(this)"></td>
                                <?php endfor; ?>
                                <td class="col-calc promedio-cell"><?= $fila['promedio'] !== null ? number_format($fila['promedio'], 2) : '—' ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <div class="card-footer bg-white text-end">
                    <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Guardar Notas</button>
                </div>
            </div>
        </form>

        <?php elseif ($vista === 'ingreso' && $nivel === 'bachillerato'): ?>
        <!-- ===== GRILLA BACHILLERATO: n1-4+suma+35% x2 bloques, Ex+30%, P.F ===== -->
        <form id="formNotas">
            <input type="hidden" name="id_asignacion" value="<?= $id_asignacion ?>">
            <input type="hidden" name="periodo" value="<?= $numero_periodo ?>">
            <div class="card-custom">
                <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
                    <h5 class="mb-0"><i class="fas fa-list"></i> <?= htmlspecialchars($periodo_actual['nombre'] ?? '') ?> <span class="badge bg-primary ms-2"><?= count($estudiantes) ?></span></h5>
                    <button type="submit" class="btn btn-primary btn-sm"><i class="fas fa-save"></i> Guardar Notas</button>
                </div>
                <div class="table-responsive">
                    <table class="table table-hover table-notas mb-0 align-middle">
                        <thead class="table-light">
                            <tr>
                                <th rowspan="2">NIE</th>
                                <th rowspan="2">Estudiante</th>
                                <th colspan="6" class="text-center bloque-divider">Bloque 1</th>
                                <th colspan="6" class="text-center bloque-divider">Bloque 2</th>
                                <th colspan="2" class="text-center bloque-divider">Examen</th>
                                <th rowspan="2" class="col-calc bloque-divider">P.F</th>
                            </tr>
                            <tr>
                                <th class="bloque-divider">n1</th><th>n2</th><th>n3</th><th>n4</th><th class="col-calc">Suma</th><th class="col-calc">35%</th>
                                <th class="bloque-divider">n1</th><th>n2</th><th>n3</th><th>n4</th><th class="col-calc">Suma</th><th class="col-calc">35%</th>
                                <th class="bloque-divider">Ex</th><th class="col-calc">30%</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($filas_ingreso as $fila): ?>
                            <tr data-id-matricula="<?= $fila['id_matricula'] ?>">
                                <td><?= htmlspecialchars($fila['nie']) ?></td>
                                <td><?= htmlspecialchars($fila['nombre']) ?></td>
                                <?php for ($i = 1; $i <= 4; $i++): ?>
                                <td class="<?= $i === 1 ? 'bloque-divider' : '' ?>"><input type="number" class="form-control form-control-sm nota-input" min="0" max="10" step="0.1"
                                    name="notas[<?= $fila['id_matricula'] ?>][b1n<?= $i ?>]" value="<?= $fila["b1n$i"] ?? '' ?>"
                                    oninput="recalcularBachillerato(this)"></td>
                                <?php endfor; ?>
                                <td class="col-calc suma1-cell"><?= number_format($fila['suma1'], 2) ?></td>
                                <td class="col-calc pct35-1-cell"><?= number_format($fila['pct35_1'], 2) ?></td>
                                <?php for ($i = 1; $i <= 4; $i++): ?>
                                <td class="<?= $i === 1 ? 'bloque-divider' : '' ?>"><input type="number" class="form-control form-control-sm nota-input" min="0" max="10" step="0.1"
                                    name="notas[<?= $fila['id_matricula'] ?>][b2n<?= $i ?>]" value="<?= $fila["b2n$i"] ?? '' ?>"
                                    oninput="recalcularBachillerato(this)"></td>
                                <?php endfor; ?>
                                <td class="col-calc suma2-cell"><?= number_format($fila['suma2'], 2) ?></td>
                                <td class="col-calc pct35-2-cell"><?= number_format($fila['pct35_2'], 2) ?></td>
                                <td class="bloque-divider"><input type="number" class="form-control form-control-sm nota-input" min="0" max="10" step="0.1"
                                    name="notas[<?= $fila['id_matricula'] ?>][ex]" value="<?= $fila['ex'] ?? '' ?>"
                                    oninput="recalcularBachillerato(this)"></td>
                                <td class="col-calc pct30-cell"><?= number_format($fila['pct30'], 2) ?></td>
                                <td class="col-calc bloque-divider pf-cell"><?= number_format($fila['pf'], 2) ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <div class="card-footer bg-white text-end">
                    <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Guardar Notas</button>
                </div>
            </div>
        </form>

        <?php else: ?>
        <!-- ===== RESUMEN ANUAL BACHILLERATO (solo lectura) ===== -->
        <div class="card-custom">
            <div class="card-header bg-white py-3">
                <h5 class="mb-0"><i class="fas fa-table"></i> Resumen Notas Bachillerato — <?= $anno ?> <span class="badge bg-primary ms-2"><?= count($resumen) ?></span></h5>
            </div>
            <div class="table-responsive">
                <table class="table table-hover table-notas mb-0 align-middle">
                    <thead class="table-light">
                        <tr>
                            <th rowspan="2">NIE</th>
                            <th rowspan="2">Estudiante</th>
                            <?php foreach ($periodos as $p): ?>
                            <th colspan="4" class="text-center bloque-divider"><?= htmlspecialchars($p['nombre']) ?></th>
                            <?php endforeach; ?>
                            <th rowspan="2" class="col-calc bloque-divider">Suma<br>Anual</th>
                            <th rowspan="2" class="col-calc">NF</th>
                        </tr>
                        <tr>
                            <?php foreach ($periodos as $p): ?>
                            <th class="bloque-divider">35%</th><th>35%</th><th>30%</th><th class="col-calc">P.F</th>
                            <?php endforeach; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($resumen as $fila): ?>
                        <tr>
                            <td><?= htmlspecialchars($fila['nie']) ?></td>
                            <td><?= htmlspecialchars($fila['nombre']) ?></td>
                            <?php foreach ($periodos as $p): $c = $fila['periodos'][(int) $p['numero']]; ?>
                            <td class="bloque-divider"><?= number_format($c['pct35_1'], 2) ?></td>
                            <td><?= number_format($c['pct35_2'], 2) ?></td>
                            <td><?= number_format($c['pct30'], 2) ?></td>
                            <td class="col-calc"><?= number_format($c['pf'], 2) ?></td>
                            <?php endforeach; ?>
                            <td class="col-calc bloque-divider"><?= number_format($fila['suma_anual'], 2) ?></td>
                            <td class="col-calc <?= $fila['nf'] >= $asignacion_actual['nota_minima_aprobacion'] ? 'aprobado' : 'reprobado' ?>"><?= number_format($fila['nf'], 2) ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endif; ?>
        <?php endif; ?>
        <?php endif; ?>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script>
    const UMBRAL_APROBACION = <?= json_encode((float) ($asignacion_actual['nota_minima_aprobacion'] ?? 6.0)) ?>;

    // Básica: promedio en vivo = AVERAGE(n1..n8), ignora vacías -- igual
    // que el cálculo autoritativo de PHP (promedioBasica()), solo que este
    // es feedback inmediato en el navegador; lo que de verdad se guarda lo
    // recalcula api/guardar_nota_periodo.php en el servidor.
    function recalcularBasica(input) {
        const fila = $(input).closest('tr');
        const valores = [];
        fila.find('.nota-input').each(function() {
            const v = parseFloat($(this).val());
            if (!isNaN(v)) valores.push(v);
        });
        const celda = fila.find('.promedio-cell');
        if (valores.length === 0) { celda.text('—'); return; }
        const prom = valores.reduce((a, b) => a + b, 0) / valores.length;
        celda.text(prom.toFixed(2));
        celda.toggleClass('aprobado', prom >= UMBRAL_APROBACION).toggleClass('reprobado', prom < UMBRAL_APROBACION);
    }

    // Bachillerato: SUM trata vacío como 0 (no es AVERAGE) -- igual que el
    // Excel y que calcularBachillerato() en PHP.
    function recalcularBachillerato(input) {
        const fila = $(input).closest('tr');
        const val = (name) => { const v = parseFloat(fila.find(`[name$="[${name}]"]`).val()); return isNaN(v) ? 0 : v; };

        const suma1 = val('b1n1') + val('b1n2') + val('b1n3') + val('b1n4');
        const suma2 = val('b2n1') + val('b2n2') + val('b2n3') + val('b2n4');
        const pct35_1 = (suma1 / 4) * 0.35;
        const pct35_2 = (suma2 / 4) * 0.35;
        const pct30 = val('ex') * 0.30;
        const pf = pct35_1 + pct35_2 + pct30;

        fila.find('.suma1-cell').text(suma1.toFixed(2));
        fila.find('.pct35-1-cell').text(pct35_1.toFixed(2));
        fila.find('.suma2-cell').text(suma2.toFixed(2));
        fila.find('.pct35-2-cell').text(pct35_2.toFixed(2));
        fila.find('.pct30-cell').text(pct30.toFixed(2));
        const pfCelda = fila.find('.pf-cell');
        pfCelda.text(pf.toFixed(2));
        pfCelda.toggleClass('aprobado', pf >= UMBRAL_APROBACION).toggleClass('reprobado', pf < UMBRAL_APROBACION);
    }

    // Colorea aprobado/reprobado de las celdas de promedio ya renderizadas al cargar la página.
    $(function() {
        $('.promedio-cell, .pf-cell').each(function() {
            const v = parseFloat($(this).text());
            if (!isNaN(v)) $(this).toggleClass('aprobado', v >= UMBRAL_APROBACION).toggleClass('reprobado', v < UMBRAL_APROBACION);
        });
    });

    $('#formNotas').on('submit', function(e) {
        e.preventDefault();
        const $btns = $(this).find('button[type="submit"]');
        $btns.prop('disabled', true);
        $.post('api/guardar_nota_periodo.php', $(this).serialize(), function(res) {
            if (res.success) {
                window.location.href = `cuadro_notas.php?asignacion=<?= $id_asignacion ?>&periodo=<?= $numero_periodo ?>&vista=ingreso&guardado=1`;
            } else {
                alert('❌ ' + (res.message || 'Error al guardar'));
                $btns.prop('disabled', false);
            }
        }, 'json').fail(function() {
            alert('❌ Error de conexión al guardar');
            $btns.prop('disabled', false);
        });
    });
    </script>
</body>
</html>
