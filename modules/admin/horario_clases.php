<?php
/**
 * Horario de Clases -- creador manual con validación de choques (lado
 * director). NO es un generador 100% automático (decisión confirmada
 * con el usuario): el director arma la cuadrícula semanal casilla por
 * casilla (Grado→Sección→Turno→Día→Bloque) y el servidor bloquea
 * cualquier choque de docente o de sección antes de guardar.
 *
 * Turno es propiedad de la Sección (tbl_seccion.turno) -- todo el
 * horario de una sección hereda ese turno; los bloques horarios
 * (tbl_bloque_horario) son un catálogo editable por turno, sembrado
 * con valores por defecto la primera vez que se abre este módulo (ver
 * HorarioHelper::asegurarBloquesPorDefecto).
 *
 * Cada casilla de la cuadrícula cuelga de una fila de
 * tbl_asignacion_docente (el "contrato" profesor+materia+sección+año
 * que ya usa el resto del sistema) -- se busca o se crea al vuelo
 * (HorarioHelper::buscarOCrearAsignacion), nunca se duplica.
 */
session_start();
require_once __DIR__ . '/../../config/database.php';

if (!isset($_SESSION['user_id']) || !in_array($_SESSION['rol'], ['admin', 'director'], true)) {
    header("Location: " . BASE_URL . "/login.php");
    exit;
}

require_once __DIR__ . '/../../config/TenantGuard.php';
require_once __DIR__ . '/../../config/CatalogoHorario.php';
require_once __DIR__ . '/../../config/HorarioHelper.php';

$tid = TenantGuard::id();
$db = (new Database())->getConnection();

// Siembra bajo demanda -- un tenant nuevo obtiene sus bloques por
// defecto (matutino y vespertino) la primera vez que el director abre
// esta página, sin necesitar un paso manual de "inicializar".
HorarioHelper::asegurarBloquesPorDefecto($db, $tid);

$mensaje = '';
$tipo_mensaje = '';

// ===== PROCESAR ACCIONES POST =====
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $accion = $_POST['accion'] ?? '';

    try {
        $db->beginTransaction();

        match ($accion) {
            'asignar_clase'      => asignarClase($db, $tid),
            'eliminar_clase'     => eliminarClase($db, $tid),
            'crear_bloque'       => crearBloque($db, $tid),
            'actualizar_bloque'  => actualizarBloque($db, $tid),
            'eliminar_bloque'    => eliminarBloque($db, $tid),
            default => throw new Exception('Acción no válida'),
        };

        $db->commit();
    } catch (Exception $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        $mensaje = 'Error: ' . $e->getMessage();
        $tipo_mensaje = 'danger';
    }
}

// ===== FUNCIONES DE PROCESAMIENTO =====

function asignarClase(PDO $db, int $tid): void
{
    global $mensaje, $tipo_mensaje;

    $idSeccion = (int) ($_POST['id_seccion'] ?? 0);
    $idProfesor = (int) ($_POST['id_profesor'] ?? 0);
    $idAsignatura = (int) ($_POST['id_asignatura'] ?? 0);
    $diaSemana = (int) ($_POST['dia_semana'] ?? 0);
    $idBloque = (int) ($_POST['id_bloque'] ?? 0);

    if (!$idSeccion || !$idProfesor || !$idAsignatura || !$diaSemana || !$idBloque) {
        throw new Exception('Todos los campos son obligatorios.');
    }
    if (!array_key_exists($diaSemana, CatalogoHorario::DIAS_SEMANA)) {
        throw new Exception('Día no válido.');
    }

    // Propiedad de las 4 entidades referenciadas (evita IDOR entre
    // instituciones vía manipulación del formulario).
    TenantGuard::assertOwner($db, 'tbl_seccion', $idSeccion);
    TenantGuard::assertOwner($db, 'tbl_profesor', $idProfesor);
    TenantGuard::assertOwner($db, 'tbl_asignatura', $idAsignatura);
    TenantGuard::assertOwner($db, 'tbl_bloque_horario', $idBloque);

    $stmtSec = $db->prepare("SELECT anno_lectivo, turno FROM tbl_seccion WHERE id = :id");
    $stmtSec->execute([':id' => $idSeccion]);
    $seccion = $stmtSec->fetch(PDO::FETCH_ASSOC);
    if (!$seccion || !$seccion['turno']) {
        throw new Exception('Esta sección no tiene turno definido. Edítala primero en Grados/Secciones.');
    }

    $stmtBloque = $db->prepare("SELECT turno, es_receso FROM tbl_bloque_horario WHERE id = :id");
    $stmtBloque->execute([':id' => $idBloque]);
    $bloque = $stmtBloque->fetch(PDO::FETCH_ASSOC);
    if ($bloque['turno'] !== $seccion['turno']) {
        throw new Exception('El bloque seleccionado no corresponde al turno de la sección.');
    }
    if ((int) $bloque['es_receso'] === 1) {
        throw new Exception('No se puede asignar una clase en un bloque de receso.');
    }

    $anno = (int) $seccion['anno_lectivo'];

    // Validación de choques -- aborta con mensaje descriptivo si el
    // docente o la sección ya tienen otra clase en ese día+bloque.
    HorarioHelper::validarConflicto($db, $idProfesor, $idSeccion, $anno, $diaSemana, $idBloque);

    $idAsignacion = HorarioHelper::buscarOCrearAsignacion($db, $idProfesor, $idAsignatura, $idSeccion, $anno);

    try {
        $db->prepare(
            "INSERT INTO tbl_horario_clase (id_asignacion_docente, dia_semana, id_bloque) VALUES (:asig, :dia, :bloque)"
        )->execute([':asig' => $idAsignacion, ':dia' => $diaSemana, ':bloque' => $idBloque]);
    } catch (PDOException $e) {
        if ($e->getCode() == 23000) {
            throw new Exception('Ya existe una clase en ese día y bloque para esta asignación.');
        }
        throw $e;
    }

    $mensaje = 'Clase asignada al horario';
    $tipo_mensaje = 'success';
}

function eliminarClase(PDO $db, int $tid): void
{
    global $mensaje, $tipo_mensaje;

    $id = (int) ($_POST['id_horario'] ?? 0);
    TenantGuard::assertOwner($db, 'tbl_horario_clase', $id);
    // No se borra la fila de tbl_asignacion_docente: puede tener
    // actividades/notas ya ligadas -- el horario es solo el "cuándo",
    // no el "si se imparte".
    $db->prepare("DELETE FROM tbl_horario_clase WHERE id = :id")->execute([':id' => $id]);

    $mensaje = 'Clase eliminada del horario';
    $tipo_mensaje = 'warning';
}

function crearBloque(PDO $db, int $tid): void
{
    global $mensaje, $tipo_mensaje;

    $turno = $_POST['turno'] ?? '';
    if (!array_key_exists($turno, CatalogoHorario::TURNOS)) {
        throw new Exception('Turno no válido.');
    }
    $numero = (int) ($_POST['numero'] ?? 0);
    $nombre = trim($_POST['nombre'] ?? '');
    $inicio = $_POST['hora_inicio'] ?? '';
    $fin = $_POST['hora_fin'] ?? '';
    $receso = !empty($_POST['es_receso']) ? 1 : 0;
    if ($numero < 1 || $nombre === '' || !$inicio || !$fin) {
        throw new Exception('Número, nombre y horas de inicio/fin son obligatorios.');
    }
    if ($fin <= $inicio) {
        throw new Exception('La hora de fin debe ser posterior a la hora de inicio.');
    }

    try {
        $db->prepare(
            "INSERT INTO tbl_bloque_horario (id_institucion, turno, numero, nombre, hora_inicio, hora_fin, es_receso)
             VALUES (:tid, :turno, :numero, :nombre, :inicio, :fin, :receso)"
        )->execute([
            ':tid' => $tid, ':turno' => $turno, ':numero' => $numero, ':nombre' => $nombre,
            ':inicio' => $inicio, ':fin' => $fin, ':receso' => $receso,
        ]);
    } catch (PDOException $e) {
        if ($e->getCode() == 23000) {
            throw new Exception('Ya existe un bloque con ese número en ese turno.');
        }
        throw $e;
    }

    $mensaje = 'Bloque horario creado';
    $tipo_mensaje = 'success';
}

function actualizarBloque(PDO $db, int $tid): void
{
    global $mensaje, $tipo_mensaje;

    $id = (int) ($_POST['id_bloque'] ?? 0);
    TenantGuard::assertOwner($db, 'tbl_bloque_horario', $id);

    $numero = (int) ($_POST['numero'] ?? 0);
    $nombre = trim($_POST['nombre'] ?? '');
    $inicio = $_POST['hora_inicio'] ?? '';
    $fin = $_POST['hora_fin'] ?? '';
    $receso = !empty($_POST['es_receso']) ? 1 : 0;
    if ($numero < 1 || $nombre === '' || !$inicio || !$fin) {
        throw new Exception('Número, nombre y horas de inicio/fin son obligatorios.');
    }
    if ($fin <= $inicio) {
        throw new Exception('La hora de fin debe ser posterior a la hora de inicio.');
    }

    try {
        $db->prepare(
            "UPDATE tbl_bloque_horario SET numero = :numero, nombre = :nombre, hora_inicio = :inicio, hora_fin = :fin, es_receso = :receso
             WHERE id = :id"
        )->execute([
            ':numero' => $numero, ':nombre' => $nombre, ':inicio' => $inicio, ':fin' => $fin,
            ':receso' => $receso, ':id' => $id,
        ]);
    } catch (PDOException $e) {
        if ($e->getCode() == 23000) {
            throw new Exception('Ya existe un bloque con ese número en ese turno.');
        }
        throw $e;
    }

    $mensaje = 'Bloque horario actualizado';
    $tipo_mensaje = 'success';
}

function eliminarBloque(PDO $db, int $tid): void
{
    global $mensaje, $tipo_mensaje;

    $id = (int) ($_POST['id_bloque'] ?? 0);
    TenantGuard::assertOwner($db, 'tbl_bloque_horario', $id);

    $check = $db->prepare("SELECT COUNT(*) FROM tbl_horario_clase WHERE id_bloque = :id");
    $check->execute([':id' => $id]);
    if ((int) $check->fetchColumn() > 0) {
        throw new Exception('No se puede eliminar: hay clases ya asignadas a ese bloque. Reasígnalas primero.');
    }

    $db->prepare("DELETE FROM tbl_bloque_horario WHERE id = :id")->execute([':id' => $id]);

    $mensaje = 'Bloque horario eliminado';
    $tipo_mensaje = 'warning';
}

// ===== OBTENER DATOS PARA RENDER =====

$tabActiva = in_array($_GET['tab'] ?? '', ['seccion', 'docente', 'bloques'], true) ? $_GET['tab'] : 'seccion';

// Catálogo compartido por las 3 pestañas.
$grados = $db->query("SELECT id, nombre FROM tbl_grado ORDER BY nombre")->fetchAll(PDO::FETCH_ASSOC);

$stmtSecciones = $db->prepare(
    "SELECT s.id, s.id_grado, s.nombre, s.anno_lectivo, s.turno
     FROM tbl_seccion s WHERE s.id_institucion = :tid ORDER BY s.anno_lectivo DESC, s.nombre"
);
$stmtSecciones->execute([':tid' => $tid]);
$todasSecciones = $stmtSecciones->fetchAll(PDO::FETCH_ASSOC);
$secciones_por_grado = [];
foreach ($todasSecciones as $s) {
    $secciones_por_grado[$s['id_grado']][] = $s;
}

$stmtProfesores = $db->prepare(
    "SELECT p.id, per.primer_nombre, per.primer_apellido
     FROM tbl_profesor p JOIN tbl_persona per ON p.id_persona = per.id
     WHERE p.id_institucion = :tid ORDER BY per.primer_apellido, per.primer_nombre"
);
$stmtProfesores->execute([':tid' => $tid]);
$profesores = $stmtProfesores->fetchAll(PDO::FETCH_ASSOC);

$stmtAsignaturas = $db->prepare("SELECT id, nombre FROM tbl_asignatura WHERE id_institucion = :tid ORDER BY nombre");
$stmtAsignaturas->execute([':tid' => $tid]);
$asignaturas = $stmtAsignaturas->fetchAll(PDO::FETCH_ASSOC);

/** Arma un mapa [dia_semana][id_bloque] => fila de horario, para una condición WHERE dada sobre tbl_asignacion_docente. */
function construirGridHorario(PDO $db, string $whereClause, array $params): array
{
    $stmt = $db->prepare(
        "SELECT hc.id, hc.dia_semana, hc.id_bloque, a.nombre AS asignatura_nombre,
                per.primer_nombre, per.primer_apellido, s.nombre AS seccion_nombre, g.nombre AS grado_nombre
         FROM tbl_horario_clase hc
         JOIN tbl_asignacion_docente ad ON hc.id_asignacion_docente = ad.id
         JOIN tbl_asignatura a ON ad.id_asignatura = a.id
         JOIN tbl_profesor p ON ad.id_profesor = p.id
         JOIN tbl_persona per ON p.id_persona = per.id
         JOIN tbl_seccion s ON ad.id_seccion = s.id
         JOIN tbl_grado g ON s.id_grado = g.id
         WHERE $whereClause"
    );
    $stmt->execute($params);
    $grid = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $grid[(int) $row['dia_semana']][(int) $row['id_bloque']] = $row;
    }
    return $grid;
}

// ----- Pestaña: Cuadrícula por Sección -----
$idSeccionSel = (int) ($_GET['id_seccion'] ?? 0);
$seccionSel = null;
$bloquesSeccion = [];
$gridSeccion = [];
if ($tabActiva === 'seccion' && $idSeccionSel) {
    TenantGuard::assertOwner($db, 'tbl_seccion', $idSeccionSel);
    $stmt = $db->prepare(
        "SELECT s.id, s.nombre, s.anno_lectivo, s.turno, g.nombre AS grado_nombre
         FROM tbl_seccion s JOIN tbl_grado g ON s.id_grado = g.id WHERE s.id = :id"
    );
    $stmt->execute([':id' => $idSeccionSel]);
    $seccionSel = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($seccionSel && $seccionSel['turno']) {
        $stmtB = $db->prepare("SELECT * FROM tbl_bloque_horario WHERE id_institucion = :tid AND turno = :turno ORDER BY numero");
        $stmtB->execute([':tid' => $tid, ':turno' => $seccionSel['turno']]);
        $bloquesSeccion = $stmtB->fetchAll(PDO::FETCH_ASSOC);

        $gridSeccion = construirGridHorario($db, "ad.id_seccion = :sec AND ad.anno = :anno", [':sec' => $idSeccionSel, ':anno' => (int) $seccionSel['anno_lectivo']]);
    }
}

// ----- Pestaña: Vista por Docente (solo lectura) -----
$idProfesorSel = (int) ($_GET['id_profesor'] ?? 0);
$profesorSel = null;
$bloquesPorTurno = [];
$gridDocentePorTurno = [];
$annoVistaDocente = (int) date('Y');
if ($tabActiva === 'docente' && $idProfesorSel) {
    TenantGuard::assertOwner($db, 'tbl_profesor', $idProfesorSel);
    $stmt = $db->prepare(
        "SELECT p.id, per.primer_nombre, per.primer_apellido
         FROM tbl_profesor p JOIN tbl_persona per ON p.id_persona = per.id WHERE p.id = :id"
    );
    $stmt->execute([':id' => $idProfesorSel]);
    $profesorSel = $stmt->fetch(PDO::FETCH_ASSOC);

    foreach (array_keys(CatalogoHorario::TURNOS) as $turno) {
        $stmtB = $db->prepare("SELECT * FROM tbl_bloque_horario WHERE id_institucion = :tid AND turno = :turno ORDER BY numero");
        $stmtB->execute([':tid' => $tid, ':turno' => $turno]);
        $bloquesPorTurno[$turno] = $stmtB->fetchAll(PDO::FETCH_ASSOC);

        $gridDocentePorTurno[$turno] = construirGridHorario(
            $db,
            "ad.id_profesor = :prof AND ad.anno = :anno AND s.turno = :turno",
            [':prof' => $idProfesorSel, ':anno' => $annoVistaDocente, ':turno' => $turno]
        );
    }
}

// ----- Pestaña: Configurar Bloques -----
$turnoBloquesSel = array_key_exists($_GET['turno'] ?? '', CatalogoHorario::TURNOS) ? $_GET['turno'] : 'matutino';
$bloquesConfig = [];
if ($tabActiva === 'bloques') {
    $stmt = $db->prepare("SELECT * FROM tbl_bloque_horario WHERE id_institucion = :tid AND turno = :turno ORDER BY numero");
    $stmt->execute([':tid' => $tid, ':turno' => $turnoBloquesSel]);
    $bloquesConfig = $stmt->fetchAll(PDO::FETCH_ASSOC);
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Horario de Clases - Educación Plus</title>
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
        .nav-tabs .nav-link { color: var(--primary); }
        .nav-tabs .nav-link.active { font-weight: 700; background: white; }
        .tabla-horario { border-collapse: collapse; width: 100%; }
        .tabla-horario th, .tabla-horario td { border: 1px solid #dee2e6; padding: 6px; vertical-align: middle; text-align: center; }
        .tabla-horario th { background: #f1f3f5; }
        .tabla-horario td.celda-bloque { text-align: left; white-space: nowrap; background: #f8f9fa; font-size: 0.85rem; }
        .tabla-horario tr.fila-receso td { background: #fff8e1; color: #8a6d3b; font-style: italic; }
        .celda-ocupada { background: #eaf4ff; font-size: 0.82rem; text-align: left; padding: 6px 8px; }
        .celda-ocupada .materia { font-weight: 700; color: var(--primary); }
        .celda-vacia { min-width: 90px; }
        @media (max-width: 768px) { .sidebar { transform: translateX(-100%); } .sidebar.active { transform: translateX(0); } .main-content { margin-left: 0; } }
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
            <a class="nav-link active" href="horario_clases.php"><i class="fas fa-calendar-week"></i> Horario</a>
            <a class="nav-link" href="carnet_estudiantil.php"><i class="fas fa-id-card"></i> Carnet Estudiantil</a>
            <a class="nav-link" href="gestionar_matriculas.php"><i class="fas fa-file-signature"></i> Matrículas</a>
            <a class="nav-link" href="cuadro_notas.php"><i class="fas fa-clipboard-list"></i> Cuadro de Notas</a>
            <a class="nav-link" href="manual_convivencia.php"><i class="fas fa-handshake"></i> Convivencia Escolar</a>
            <a class="nav-link" href="../../logout.php"><i class="fas fa-sign-out-alt"></i> Cerrar Sesión</a>
        </nav>
    </div>

    <!-- Main Content -->
    <div class="main-content">
        <div class="mb-4">
            <h2><i class="fas fa-calendar-week"></i> Horario de Clases</h2>
            <p class="text-muted mb-0">Creador manual de horario por sección, con validación automática de choques de docente y de sección.</p>
        </div>

        <?php if ($mensaje): ?>
        <div class="alert alert-<?= $tipo_mensaje ?> alert-dismissible fade show">
            <?= htmlspecialchars($mensaje) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php endif; ?>

        <ul class="nav nav-tabs flex-wrap mb-3">
            <li class="nav-item"><a class="nav-link <?= $tabActiva === 'seccion' ? 'active' : '' ?>" href="?tab=seccion"><i class="fas fa-table"></i> Cuadrícula por Sección</a></li>
            <li class="nav-item"><a class="nav-link <?= $tabActiva === 'docente' ? 'active' : '' ?>" href="?tab=docente"><i class="fas fa-chalkboard-teacher"></i> Vista por Docente</a></li>
            <li class="nav-item"><a class="nav-link <?= $tabActiva === 'bloques' ? 'active' : '' ?>" href="?tab=bloques"><i class="fas fa-clock"></i> Configurar Bloques</a></li>
        </ul>

        <?php if ($tabActiva === 'seccion'): ?>
        <!-- ===== CUADRÍCULA POR SECCIÓN ===== -->
        <div class="card-custom p-4">
            <form method="GET" class="row g-3 align-items-end mb-2">
                <input type="hidden" name="tab" value="seccion">
                <div class="col-md-4">
                    <label class="form-label">Grado</label>
                    <select id="filtro_grado" class="form-select" onchange="actualizarSeccionesFiltro()">
                        <option value="">Seleccionar</option>
                        <?php foreach ($grados as $g): ?>
                        <option value="<?= $g['id'] ?>"><?= htmlspecialchars($g['nombre']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-5">
                    <label class="form-label">Sección</label>
                    <select name="id_seccion" id="filtro_seccion" class="form-select" onchange="this.form.submit()">
                        <option value="">Elegir grado primero</option>
                    </select>
                </div>
            </form>

            <?php if ($idSeccionSel && !$seccionSel): ?>
            <div class="alert alert-warning">Sección no encontrada.</div>
            <?php elseif ($idSeccionSel && !$seccionSel['turno']): ?>
            <div class="alert alert-warning">
                Esta sección no tiene turno definido. <a href="gestionar_grados.php">Edítala en Grados/Secciones</a> antes de armar su horario.
            </div>
            <?php elseif ($seccionSel): ?>
            <hr>
            <div class="d-flex justify-content-between align-items-center mb-3">
                <h5 class="mb-0">
                    <?= htmlspecialchars($seccionSel['grado_nombre']) ?> "<?= htmlspecialchars($seccionSel['nombre']) ?>"
                    <span class="badge bg-info"><?= CatalogoHorario::TURNOS[$seccionSel['turno']] ?></span>
                    <span class="badge bg-secondary"><?= $seccionSel['anno_lectivo'] ?></span>
                </h5>
            </div>
            <?php if (empty($bloquesSeccion)): ?>
            <div class="alert alert-warning">No hay bloques horarios configurados para el turno <?= CatalogoHorario::TURNOS[$seccionSel['turno']] ?>. Ve a "Configurar Bloques".</div>
            <?php else: ?>
            <div class="table-responsive">
                <table class="tabla-horario">
                    <thead>
                        <tr>
                            <th>Bloque</th>
                            <?php foreach (CatalogoHorario::DIAS_SEMANA as $dia): ?>
                            <th><?= $dia ?></th>
                            <?php endforeach; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($bloquesSeccion as $b): ?>
                        <tr class="<?= $b['es_receso'] ? 'fila-receso' : '' ?>">
                            <td class="celda-bloque"><?= htmlspecialchars($b['nombre']) ?><br><small><?= substr($b['hora_inicio'], 0, 5) ?>-<?= substr($b['hora_fin'], 0, 5) ?></small></td>
                            <?php if ($b['es_receso']): ?>
                            <td colspan="5">Recreo</td>
                            <?php else: foreach (array_keys(CatalogoHorario::DIAS_SEMANA) as $dia): ?>
                                <?php $ocupada = $gridSeccion[$dia][$b['id']] ?? null; ?>
                                <td class="<?= $ocupada ? 'celda-ocupada' : 'celda-vacia' ?>">
                                    <?php if ($ocupada): ?>
                                        <div class="materia"><?= htmlspecialchars($ocupada['asignatura_nombre']) ?></div>
                                        <div><?= htmlspecialchars($ocupada['primer_nombre'] . ' ' . $ocupada['primer_apellido']) ?></div>
                                        <form method="POST" onsubmit="return confirm('¿Quitar esta clase del horario?');">
                                            <input type="hidden" name="accion" value="eliminar_clase">
                                            <input type="hidden" name="id_horario" value="<?= $ocupada['id'] ?>">
                                            <button type="submit" class="btn btn-sm btn-outline-danger mt-1"><i class="fas fa-times"></i></button>
                                        </form>
                                    <?php else: ?>
                                        <button type="button" class="btn btn-sm btn-outline-primary" onclick="abrirModalAsignar(<?= $dia ?>, <?= $b['id'] ?>, '<?= CatalogoHorario::DIAS_SEMANA[$dia] ?>', '<?= htmlspecialchars($b['nombre'], ENT_QUOTES) ?>')"><i class="fas fa-plus"></i></button>
                                    <?php endif; ?>
                                </td>
                            <?php endforeach; endif; ?>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
            <?php endif; ?>
        </div>

        <?php elseif ($tabActiva === 'docente'): ?>
        <!-- ===== VISTA POR DOCENTE (solo lectura) ===== -->
        <div class="card-custom p-4">
            <form method="GET" class="row g-3 align-items-end mb-2">
                <input type="hidden" name="tab" value="docente">
                <div class="col-md-6">
                    <label class="form-label">Docente</label>
                    <select name="id_profesor" class="form-select" onchange="this.form.submit()">
                        <option value="">Seleccionar</option>
                        <?php foreach ($profesores as $p): ?>
                        <option value="<?= $p['id'] ?>" <?= $idProfesorSel === (int) $p['id'] ? 'selected' : '' ?>><?= htmlspecialchars($p['primer_nombre'] . ' ' . $p['primer_apellido']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-4">
                    <small class="text-muted">Año lectivo actual: <?= $annoVistaDocente ?></small>
                </div>
            </form>

            <?php if ($idProfesorSel && $profesorSel): ?>
            <hr>
            <h5><?= htmlspecialchars($profesorSel['primer_nombre'] . ' ' . $profesorSel['primer_apellido']) ?></h5>
            <?php foreach (CatalogoHorario::TURNOS as $turnoKey => $turnoLabel): ?>
            <h6 class="mt-4"><i class="fas fa-clock"></i> <?= $turnoLabel ?></h6>
            <?php if (empty($bloquesPorTurno[$turnoKey])): ?>
            <p class="text-muted small">Sin bloques configurados para este turno.</p>
            <?php else: ?>
            <div class="table-responsive">
                <table class="tabla-horario">
                    <thead>
                        <tr>
                            <th>Bloque</th>
                            <?php foreach (CatalogoHorario::DIAS_SEMANA as $dia): ?>
                            <th><?= $dia ?></th>
                            <?php endforeach; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($bloquesPorTurno[$turnoKey] as $b): ?>
                        <tr class="<?= $b['es_receso'] ? 'fila-receso' : '' ?>">
                            <td class="celda-bloque"><?= htmlspecialchars($b['nombre']) ?><br><small><?= substr($b['hora_inicio'], 0, 5) ?>-<?= substr($b['hora_fin'], 0, 5) ?></small></td>
                            <?php if ($b['es_receso']): ?>
                            <td colspan="5">Recreo</td>
                            <?php else: foreach (array_keys(CatalogoHorario::DIAS_SEMANA) as $dia): ?>
                                <?php $ocupada = $gridDocentePorTurno[$turnoKey][$dia][$b['id']] ?? null; ?>
                                <td class="<?= $ocupada ? 'celda-ocupada' : '' ?>">
                                    <?php if ($ocupada): ?>
                                        <div class="materia"><?= htmlspecialchars($ocupada['asignatura_nombre']) ?></div>
                                        <div><?= htmlspecialchars($ocupada['grado_nombre'] . ' "' . $ocupada['seccion_nombre'] . '"') ?></div>
                                    <?php endif; ?>
                                </td>
                            <?php endforeach; endif; ?>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
            <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <?php else: ?>
        <!-- ===== CONFIGURAR BLOQUES ===== -->
        <div class="card-custom p-4">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <ul class="nav nav-pills">
                    <?php foreach (CatalogoHorario::TURNOS as $k => $v): ?>
                    <li class="nav-item"><a class="nav-link <?= $turnoBloquesSel === $k ? 'active' : '' ?>" href="?tab=bloques&turno=<?= $k ?>"><?= $v ?></a></li>
                    <?php endforeach; ?>
                </ul>
                <button class="btn btn-success" data-bs-toggle="modal" data-bs-target="#modalBloque" onclick="prepararModalBloque('crear', '<?= $turnoBloquesSel ?>')"><i class="fas fa-plus"></i> Agregar Bloque</button>
            </div>
            <div class="table-responsive">
                <table class="table table-hover">
                    <thead class="table-light">
                        <tr><th>#</th><th>Nombre</th><th>Inicio</th><th>Fin</th><th>Receso</th><th>Acciones</th></tr>
                    </thead>
                    <tbody>
                        <?php foreach ($bloquesConfig as $b): ?>
                        <tr class="<?= $b['es_receso'] ? 'table-warning' : '' ?>">
                            <td><?= $b['numero'] ?></td>
                            <td><?= htmlspecialchars($b['nombre']) ?></td>
                            <td><?= substr($b['hora_inicio'], 0, 5) ?></td>
                            <td><?= substr($b['hora_fin'], 0, 5) ?></td>
                            <td><?= $b['es_receso'] ? '<i class="fas fa-check text-warning"></i>' : '' ?></td>
                            <td>
                                <button type="button" class="btn btn-sm btn-warning" onclick='prepararModalBloque("editar", "<?= $turnoBloquesSel ?>", <?= json_encode($b, JSON_HEX_APOS | JSON_HEX_QUOT) ?>)'><i class="fas fa-edit"></i></button>
                                <form method="POST" style="display:inline" onsubmit="return confirm('¿Eliminar este bloque?');">
                                    <input type="hidden" name="accion" value="eliminar_bloque">
                                    <input type="hidden" name="id_bloque" value="<?= $b['id'] ?>">
                                    <button type="submit" class="btn btn-sm btn-danger"><i class="fas fa-trash"></i></button>
                                </form>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if (empty($bloquesConfig)): ?>
                        <tr><td colspan="6" class="text-center text-muted py-3">Todavía no hay bloques para este turno.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <!-- Modal Asignar Clase -->
    <div class="modal fade" id="modalAsignarClase" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-plus"></i> Asignar Clase</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" id="formAsignarClase">
                    <div class="modal-body">
                        <input type="hidden" name="accion" value="asignar_clase">
                        <input type="hidden" name="id_seccion" value="<?= $idSeccionSel ?>">
                        <input type="hidden" name="dia_semana" id="ac_dia_semana">
                        <input type="hidden" name="id_bloque" id="ac_id_bloque">
                        <p class="text-muted" id="ac_info"></p>
                        <div class="mb-3">
                            <label class="form-label">Docente *</label>
                            <select name="id_profesor" class="form-select" required>
                                <option value="">Seleccionar</option>
                                <?php foreach ($profesores as $p): ?>
                                <option value="<?= $p['id'] ?>"><?= htmlspecialchars($p['primer_nombre'] . ' ' . $p['primer_apellido']) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <small class="text-muted">Un docente puede impartir varias materias -- aparece siempre en la lista completa.</small>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Materia / Asignatura *</label>
                            <select name="id_asignatura" class="form-select" required>
                                <option value="">Seleccionar</option>
                                <?php foreach ($asignaturas as $a): ?>
                                <option value="<?= $a['id'] ?>"><?= htmlspecialchars($a['nombre']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                        <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Asignar</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Modal Bloque Horario -->
    <div class="modal fade" id="modalBloque" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="modalTitleBloque"><i class="fas fa-plus"></i> Nuevo Bloque</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" id="formBloque">
                    <div class="modal-body">
                        <input type="hidden" name="accion" id="accion_bloque" value="crear_bloque">
                        <input type="hidden" name="id_bloque" id="id_bloque">
                        <input type="hidden" name="turno" id="bloque_turno">
                        <div class="row g-3">
                            <div class="col-md-4">
                                <label class="form-label">Número *</label>
                                <input type="number" name="numero" id="bloque_numero" class="form-control" min="1" required>
                            </div>
                            <div class="col-md-8">
                                <label class="form-label">Nombre *</label>
                                <input type="text" name="nombre" id="bloque_nombre" class="form-control" required placeholder='ej. "Bloque 1" o "Recreo"'>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Hora inicio *</label>
                                <input type="time" name="hora_inicio" id="bloque_inicio" class="form-control" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Hora fin *</label>
                                <input type="time" name="hora_fin" id="bloque_fin" class="form-control" required>
                            </div>
                            <div class="col-12">
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" name="es_receso" id="bloque_receso" value="1">
                                    <label class="form-check-label" for="bloque_receso">Es un receso (no se pueden asignar clases)</label>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                        <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Guardar</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Scripts -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Secciones agrupadas por grado (con año lectivo y turno), para la
        // cascada Grado->Sección de la pestaña "Cuadrícula por Sección" --
        // mismo patrón que SECCIONES_POR_GRADO en gestionar_profesores.php.
        const SECCIONES_POR_GRADO = <?= json_encode($secciones_por_grado, JSON_HEX_TAG | JSON_HEX_APOS) ?>;
        const TURNOS_LABEL = <?= json_encode(CatalogoHorario::TURNOS) ?>;
        const SECCION_SELECCIONADA = <?= (int) $idSeccionSel ?>;

        function actualizarSeccionesFiltro() {
            const idGrado = document.getElementById('filtro_grado').value;
            const secciones = SECCIONES_POR_GRADO[idGrado] || [];
            const $select = document.getElementById('filtro_seccion');
            $select.innerHTML = '';
            if (!idGrado) {
                $select.appendChild(new Option('Elegir grado primero', ''));
                return;
            }
            $select.appendChild(new Option('Seleccionar', ''));
            secciones.forEach(function (s) {
                const label = s.nombre + ' - ' + s.anno_lectivo + (s.turno ? ' (' + TURNOS_LABEL[s.turno] + ')' : ' (sin turno)');
                const opt = new Option(label, s.id);
                if (parseInt(s.id, 10) === SECCION_SELECCIONADA) opt.selected = true;
                $select.appendChild(opt);
            });
        }

        // Al cargar, si ya hay una sección seleccionada (recarga por GET),
        // preseleccionar su grado y repoblar el select de sección.
        document.addEventListener('DOMContentLoaded', function () {
            if (SECCION_SELECCIONADA) {
                for (const idGrado in SECCIONES_POR_GRADO) {
                    if (SECCIONES_POR_GRADO[idGrado].some(s => parseInt(s.id, 10) === SECCION_SELECCIONADA)) {
                        document.getElementById('filtro_grado').value = idGrado;
                        actualizarSeccionesFiltro();
                        break;
                    }
                }
            }
        });

        function abrirModalAsignar(dia, idBloque, diaLabel, bloqueLabel) {
            document.getElementById('formAsignarClase').reset();
            document.getElementById('ac_dia_semana').value = dia;
            document.getElementById('ac_id_bloque').value = idBloque;
            document.getElementById('ac_info').textContent = diaLabel + ' -- ' + bloqueLabel;
            new bootstrap.Modal(document.getElementById('modalAsignarClase')).show();
        }

        function prepararModalBloque(modo, turno, data) {
            document.getElementById('formBloque').reset();
            document.getElementById('bloque_turno').value = turno;
            if (modo === 'crear') {
                document.getElementById('accion_bloque').value = 'crear_bloque';
                document.getElementById('id_bloque').value = '';
                document.getElementById('modalTitleBloque').innerHTML = '<i class="fas fa-plus"></i> Nuevo Bloque (' + TURNOS_LABEL[turno] + ')';
            } else {
                document.getElementById('accion_bloque').value = 'actualizar_bloque';
                document.getElementById('id_bloque').value = data.id;
                document.getElementById('bloque_numero').value = data.numero;
                document.getElementById('bloque_nombre').value = data.nombre;
                document.getElementById('bloque_inicio').value = data.hora_inicio.substring(0, 5);
                document.getElementById('bloque_fin').value = data.hora_fin.substring(0, 5);
                document.getElementById('bloque_receso').checked = !!Number(data.es_receso);
                document.getElementById('modalTitleBloque').innerHTML = '<i class="fas fa-edit"></i> Editar Bloque';
            }
        }
    </script>
</body>
</html>
