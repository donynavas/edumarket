<?php
// demeritos.php — Control de Deméritos (Reglamento de Cortesía Escolar,
// MINEDUCYT), lado del profesor. Mismo esqueleto que asistencia.php:
// cualquier profesor con una asignación activa en la sección puede
// registrar/ver deméritos de esos estudiantes (no existe un "docente
// responsable de sección" en el esquema).
//
// A diferencia de asistencia.php (que guarda TODA la sección de un golpe
// con fallos silenciosos por fila), aquí cada acción (demérito/redención/
// consecuencia) es un POST individual por estudiante -- un fallo de
// validación debe ser un mensaje explícito, no un ignorar silencioso, para
// no confundir al profesor sobre si su registro se guardó o no.

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

$query = "SELECT p.id as id_profesor, per.primer_nombre, per.primer_apellido
          FROM tbl_profesor p
          JOIN tbl_persona per ON p.id_persona = per.id
          WHERE per.id_usuario = :user_id AND p.id_institucion = :tid";
$stmt = $db->prepare($query);
$stmt->bindValue(':user_id', $user_id, PDO::PARAM_INT);
$stmt->bindValue(':tid', $tid, PDO::PARAM_INT);
$stmt->execute();
$profesor = $stmt->fetch(PDO::FETCH_ASSOC);
$id_profesor = $profesor['id_profesor'] ?? 0;

$mensaje = '';
$tipo_mensaje = '';

// ===== REGISTRAR ACCIÓN (demérito / redención / consecuencia) =====
if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($_POST['accion'] ?? '', ['registrar_demerito', 'registrar_redencion', 'registrar_consecuencia'], true)) {
    $accion = $_POST['accion'];
    $id_asignacion = filter_input(INPUT_POST, 'id_asignacion', FILTER_VALIDATE_INT);
    $id_matricula = filter_input(INPUT_POST, 'id_matricula', FILTER_VALIDATE_INT);
    $fecha = $_POST['fecha'] ?? '';
    $hora = $_POST['hora'] ?? '';

    try {
        if (!$fecha || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha)) {
            throw new Exception('Fecha inválida');
        }
        // tbl_demerito_consecuencia no tiene columna hora -- solo se exige
        // para demérito/redención, cuyos modales sí piden ese campo.
        if ($accion !== 'registrar_consecuencia' && (!$hora || !preg_match('/^\d{2}:\d{2}$/', $hora))) {
            throw new Exception('Hora inválida');
        }

        // La asignación indicada DEBE pertenecer a este profesor (mismo
        // patrón que asistencia.php: tbl_asignacion_docente no tiene
        // columna id_institucion propia, id_profesor ya está
        // tenant-verificado por la consulta de arriba).
        $checkAsig = $db->prepare("SELECT id_seccion, anno FROM tbl_asignacion_docente WHERE id = :id AND id_profesor = :prof");
        $checkAsig->execute([':id' => $id_asignacion, ':prof' => $id_profesor]);
        $asig = $checkAsig->fetch(PDO::FETCH_ASSOC);
        if (!$asig) {
            throw new Exception('No tiene permiso sobre esta asignación');
        }

        // La matrícula debe pertenecer de verdad a la sección/año de esta
        // asignación -- evita registrar sobre un estudiante ajeno aunque
        // se manipule el id_matricula en el POST.
        $checkMatricula = $db->prepare("SELECT id FROM tbl_matricula WHERE id = :id_matricula AND id_seccion = :id_seccion AND anno = :anno AND estado = 'activo'");
        $checkMatricula->execute([':id_matricula' => $id_matricula, ':id_seccion' => $asig['id_seccion'], ':anno' => $asig['anno']]);
        if (!$checkMatricula->fetch()) {
            throw new Exception('El estudiante no pertenece a esta sección');
        }

        if ($accion === 'registrar_demerito') {
            $categoria = $_POST['categoria'] ?? '';
            if (!array_key_exists($categoria, Demeritos::CATEGORIAS)) {
                throw new Exception('Categoría de demérito inválida');
            }
            $ins = $db->prepare("INSERT INTO tbl_demerito (id_institucion, id_matricula, categoria, fecha, hora, id_profesor_registro)
                                 VALUES (:tid, :mat, :cat, :fecha, :hora, :prof)");
            $ins->execute([':tid' => $tid, ':mat' => $id_matricula, ':cat' => $categoria, ':fecha' => $fecha, ':hora' => $hora, ':prof' => $id_profesor]);
            $mensaje = 'Demérito registrado';
        } elseif ($accion === 'registrar_redencion') {
            $actividad = $_POST['actividad'] ?? '';
            if (!array_key_exists($actividad, Demeritos::ACTIVIDADES_REDENCION)) {
                throw new Exception('Actividad de redención inválida');
            }
            $cantidad = filter_input(INPUT_POST, 'cantidad_redimida', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if ($cantidad === false || $cantidad === null) {
                throw new Exception('La cantidad a redimir debe ser un número entero mayor a 0');
            }
            $ins = $db->prepare("INSERT INTO tbl_demerito_redencion (id_institucion, id_matricula, actividad, fecha, hora, cantidad_redimida, id_profesor_registro)
                                 VALUES (:tid, :mat, :act, :fecha, :hora, :cant, :prof)");
            $ins->execute([':tid' => $tid, ':mat' => $id_matricula, ':act' => $actividad, ':fecha' => $fecha, ':hora' => $hora, ':cant' => $cantidad, ':prof' => $id_profesor]);
            $mensaje = 'Redención registrada';
        } else { // registrar_consecuencia
            $descripcion = trim($_POST['descripcion'] ?? '');
            if ($descripcion === '') {
                throw new Exception('La descripción de la consecuencia es obligatoria');
            }
            if (mb_strlen($descripcion) > 500) {
                $descripcion = mb_substr($descripcion, 0, 500);
            }
            $ins = $db->prepare("INSERT INTO tbl_demerito_consecuencia (id_institucion, id_matricula, fecha, descripcion, id_profesor_registro)
                                 VALUES (:tid, :mat, :fecha, :desc, :prof)");
            $ins->execute([':tid' => $tid, ':mat' => $id_matricula, ':fecha' => $fecha, ':desc' => $descripcion, ':prof' => $id_profesor]);
            $mensaje = 'Consecuencia registrada';
        }
        $tipo_mensaje = 'success';
    } catch (Exception $e) {
        $mensaje = 'Error: ' . $e->getMessage();
        $tipo_mensaje = 'danger';
    }
}

// ===== ASIGNACIONES DEL PROFESOR =====
$query = "SELECT ad.id, ad.anno, ad.id_seccion, asig.nombre as asignatura_nombre,
          g.nombre as grado_nombre, s.nombre as seccion_nombre
          FROM tbl_asignacion_docente ad
          JOIN tbl_asignatura asig ON ad.id_asignatura = asig.id
          JOIN tbl_seccion s ON ad.id_seccion = s.id
          JOIN tbl_grado g ON s.id_grado = g.id
          WHERE ad.id_profesor = :id_profesor AND asig.id_institucion = :tid
          ORDER BY g.nombre, s.nombre, asig.nombre";
$stmt = $db->prepare($query);
$stmt->bindValue(':id_profesor', $id_profesor, PDO::PARAM_INT);
$stmt->bindValue(':tid', $tid, PDO::PARAM_INT);
$stmt->execute();
$asignaciones = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ===== FILTROS =====
$id_asignacion_filtro = $_GET['asignacion'] ?? ($asignaciones[0]['id'] ?? 0);
$mes_filtro = $_GET['mes'] ?? date('Y-m');
if (!preg_match('/^\d{4}-\d{2}$/', $mes_filtro)) {
    $mes_filtro = date('Y-m');
}
$primerDiaMes = $mes_filtro . '-01';
$ultimoDiaMes = date('Y-m-t', strtotime($primerDiaMes));

$asignacion_actual = null;
foreach ($asignaciones as $asig) {
    if ($asig['id'] == $id_asignacion_filtro) { $asignacion_actual = $asig; break; }
}

// ===== ROSTER + CONTEOS DEL MES =====
$estudiantes = [];
if ($asignacion_actual) {
    $query_est = "SELECT
                 e.id as id_estudiante, m.id as id_matricula,
                 p.primer_nombre, p.primer_apellido, e.nie,
                 COALESCE(dm.total_demeritos, 0) as total_demeritos,
                 COALESCE(rd.total_redimidos, 0) as total_redimidos
                 FROM tbl_matricula m
                 JOIN tbl_estudiante e ON m.id_estudiante = e.id
                 JOIN tbl_persona p ON e.id_persona = p.id
                 LEFT JOIN (
                     SELECT id_matricula, COUNT(*) as total_demeritos
                     FROM tbl_demerito
                     WHERE fecha BETWEEN :ini1 AND :fin1
                     GROUP BY id_matricula
                 ) dm ON dm.id_matricula = m.id
                 LEFT JOIN (
                     SELECT id_matricula, SUM(cantidad_redimida) as total_redimidos
                     FROM tbl_demerito_redencion
                     WHERE fecha BETWEEN :ini2 AND :fin2
                     GROUP BY id_matricula
                 ) rd ON rd.id_matricula = m.id
                 WHERE m.id_seccion = :id_seccion AND m.anno = :anno AND m.estado = 'activo'
                 AND e.id_institucion = :tid
                 ORDER BY p.primer_apellido, p.primer_nombre";
    $stmt_est = $db->prepare($query_est);
    $stmt_est->execute([
        ':ini1' => $primerDiaMes, ':fin1' => $ultimoDiaMes,
        ':ini2' => $primerDiaMes, ':fin2' => $ultimoDiaMes,
        ':id_seccion' => $asignacion_actual['id_seccion'],
        ':anno' => $asignacion_actual['anno'],
        ':tid' => $tid,
    ]);
    $estudiantes = $stmt_est->fetchAll(PDO::FETCH_ASSOC);
}

$activePage = 'demeritos';
$pageTitle = 'Deméritos - Educación Plus';
$mostrarAsignacionesSidebar = true;
$idAsignacionFiltro = $id_asignacion_filtro;
ob_start();
?>
<style>
    .card-custom { background: white; border-radius: 12px; box-shadow: 0 2px 12px rgba(0,0,0,0.08); border: none; margin-bottom: 20px; }
    .student-avatar { width: 40px; height: 40px; border-radius: 50%; background: linear-gradient(135deg, var(--secondary), var(--primary)); color: white; display: flex; align-items: center; justify-content: center; font-weight: 700; font-size: 0.9rem; }
    .badge-neto-alto { background: var(--danger) !important; }
    .badge-neto-medio { background: var(--warning) !important; }
    .badge-neto-bajo { background: var(--success) !important; }
</style>
<?php
$extraHead = ob_get_clean();
require __DIR__ . '/partials/header.php';
?>
        <div class="d-flex justify-content-between align-items-center mb-4">
            <div>
                <h2 class="mb-1"><i class="fas fa-exclamation-triangle"></i> Deméritos</h2>
                <nav aria-label="breadcrumb">
                    <ol class="breadcrumb mb-0">
                        <li class="breadcrumb-item"><a href="profesor_dashboard.php">Dashboard</a></li>
                        <li class="breadcrumb-item active">Deméritos</li>
                    </ol>
                </nav>
            </div>
            <?php if ($asignacion_actual): ?>
            <a class="btn btn-outline-secondary" target="_blank" href="resumen_seccion_demeritos.php?asignacion=<?= (int) $id_asignacion_filtro ?>&mes=<?= htmlspecialchars($mes_filtro) ?>">
                <i class="fas fa-print"></i> Ver resumen de sección
            </a>
            <?php endif; ?>
        </div>

        <?php if ($mensaje): ?>
        <div class="alert alert-<?= $tipo_mensaje ?> alert-dismissible fade show">
            <i class="fas fa-<?= $tipo_mensaje == 'success' ? 'check-circle' : 'exclamation-circle' ?>"></i>
            <?= htmlspecialchars($mensaje) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php endif; ?>

        <?php if (empty($asignaciones)): ?>
        <div class="card-custom p-5 text-center">
            <i class="fas fa-exclamation-triangle fa-3x text-warning mb-3"></i>
            <h4>No tienes asignaciones registradas</h4>
            <p class="text-muted">Contacta al administrador para que te asigne clases</p>
        </div>
        <?php else: ?>

        <div class="card-custom p-3 mb-4">
            <form method="GET" class="row g-3 align-items-end">
                <div class="col-md-6">
                    <label class="form-label small text-muted">Asignación</label>
                    <select name="asignacion" class="form-select" onchange="this.form.submit()">
                        <?php foreach ($asignaciones as $asig): ?>
                        <option value="<?= $asig['id'] ?>" <?= $id_asignacion_filtro == $asig['id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($asig['asignatura_nombre']) ?> - <?= htmlspecialchars($asig['grado_nombre']) ?> <?= htmlspecialchars($asig['seccion_nombre']) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label small text-muted">Mes</label>
                    <input type="month" name="mes" class="form-control" value="<?= htmlspecialchars($mes_filtro) ?>" onchange="this.form.submit()">
                </div>
                <div class="col-md-3">
                    <button type="submit" class="btn btn-outline-primary w-100"><i class="fas fa-sync"></i> Actualizar</button>
                </div>
            </form>
        </div>

        <?php if ($asignacion_actual && !empty($estudiantes)): ?>
        <div class="card-custom">
            <div class="card-header bg-white py-3">
                <h5 class="mb-0">
                    <i class="fas fa-list"></i> Lista de Estudiantes
                    <span class="badge bg-primary ms-2"><?= count($estudiantes) ?></span>
                </h5>
            </div>
            <div class="table-responsive">
                <table class="table table-hover mb-0 align-middle">
                    <thead class="table-light">
                        <tr>
                            <th>Estudiante</th>
                            <th class="text-center">Deméritos (mes)</th>
                            <th class="text-center">Redimidos (mes)</th>
                            <th class="text-center">Neto</th>
                            <th class="text-center">Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($estudiantes as $est):
                            $iniciales = strtoupper(substr($est['primer_nombre'], 0, 1) . substr($est['primer_apellido'], 0, 1));
                            $nombre_completo = trim($est['primer_nombre'] . ' ' . $est['primer_apellido']);
                            $neto = max(0, (int) $est['total_demeritos'] - (int) $est['total_redimidos']);
                            $claseNeto = $neto >= 10 ? 'badge-neto-alto' : ($neto >= 6 ? 'badge-neto-medio' : 'badge-neto-bajo');
                        ?>
                        <tr>
                            <td>
                                <div class="d-flex align-items-center gap-2">
                                    <div class="student-avatar"><?= $iniciales ?></div>
                                    <div>
                                        <strong><?= htmlspecialchars($nombre_completo) ?></strong>
                                        <br><small class="text-muted"><?= htmlspecialchars($est['nie']) ?></small>
                                    </div>
                                </div>
                            </td>
                            <td class="text-center"><?= (int) $est['total_demeritos'] ?></td>
                            <td class="text-center"><?= (int) $est['total_redimidos'] ?></td>
                            <td class="text-center"><span class="badge <?= $claseNeto ?>"><?= $neto ?></span></td>
                            <td class="text-center">
                                <div class="btn-group">
                                    <button type="button" class="btn btn-sm btn-outline-danger" title="Registrar demérito"
                                            onclick="abrirModal('modalDemerito', <?= (int) $est['id_matricula'] ?>, '<?= htmlspecialchars($nombre_completo, ENT_QUOTES) ?>')">
                                        <i class="fas fa-minus-circle"></i>
                                    </button>
                                    <button type="button" class="btn btn-sm btn-outline-success" title="Registrar redención"
                                            onclick="abrirModal('modalRedencion', <?= (int) $est['id_matricula'] ?>, '<?= htmlspecialchars($nombre_completo, ENT_QUOTES) ?>')">
                                        <i class="fas fa-hand-holding-heart"></i>
                                    </button>
                                    <button type="button" class="btn btn-sm btn-outline-warning" title="Registrar consecuencia"
                                            onclick="abrirModal('modalConsecuencia', <?= (int) $est['id_matricula'] ?>, '<?= htmlspecialchars($nombre_completo, ENT_QUOTES) ?>')">
                                        <i class="fas fa-gavel"></i>
                                    </button>
                                    <a class="btn btn-sm btn-outline-secondary" title="Ver tarjeta" target="_blank"
                                       href="tarjeta_demerito.php?id_matricula=<?= (int) $est['id_matricula'] ?>&mes=<?= htmlspecialchars($mes_filtro) ?>">
                                        <i class="fas fa-id-card"></i>
                                    </a>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <?php elseif ($asignacion_actual): ?>
        <div class="card-custom p-5 text-center">
            <i class="fas fa-user-slash fa-4x text-muted mb-3"></i>
            <h5>No hay estudiantes matriculados en esta sección</h5>
        </div>
        <?php endif; ?>
        <?php endif; ?>
    </div>

    <!-- Modal Demérito -->
    <div class="modal fade" id="modalDemerito" tabindex="-1">
        <div class="modal-dialog">
            <form method="POST" class="modal-content">
                <input type="hidden" name="accion" value="registrar_demerito">
                <input type="hidden" name="id_asignacion" value="<?= (int) $id_asignacion_filtro ?>">
                <input type="hidden" name="id_matricula" id="demerito_id_matricula">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-minus-circle text-danger"></i> Registrar demérito — <span id="demerito_nombre_estudiante"></span></h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <label class="form-label">Categoría</label>
                    <?php foreach (Demeritos::CATEGORIAS as $key => $label): ?>
                    <div class="form-check">
                        <input class="form-check-input" type="radio" name="categoria" id="cat_<?= $key ?>" value="<?= $key ?>" required <?= $key === array_key_first(Demeritos::CATEGORIAS) ? 'checked' : '' ?>>
                        <label class="form-check-label" for="cat_<?= $key ?>"><?= htmlspecialchars($label) ?></label>
                    </div>
                    <?php endforeach; ?>
                    <div class="row g-2 mt-2">
                        <div class="col-6">
                            <label class="form-label small text-muted">Fecha</label>
                            <input type="date" name="fecha" class="form-control" value="<?= date('Y-m-d') ?>" required>
                        </div>
                        <div class="col-6">
                            <label class="form-label small text-muted">Hora</label>
                            <input type="time" name="hora" class="form-control" value="<?= date('H:i') ?>" required>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-danger">Registrar</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Modal Redención -->
    <div class="modal fade" id="modalRedencion" tabindex="-1">
        <div class="modal-dialog">
            <form method="POST" class="modal-content">
                <input type="hidden" name="accion" value="registrar_redencion">
                <input type="hidden" name="id_asignacion" value="<?= (int) $id_asignacion_filtro ?>">
                <input type="hidden" name="id_matricula" id="redencion_id_matricula">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-hand-holding-heart text-success"></i> Registrar redención — <span id="redencion_nombre_estudiante"></span></h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <label class="form-label">Actividad</label>
                    <?php foreach (Demeritos::ACTIVIDADES_REDENCION as $key => $label): ?>
                    <div class="form-check">
                        <input class="form-check-input" type="radio" name="actividad" id="act_<?= $key ?>" value="<?= $key ?>" required <?= $key === array_key_first(Demeritos::ACTIVIDADES_REDENCION) ? 'checked' : '' ?>>
                        <label class="form-check-label" for="act_<?= $key ?>"><?= htmlspecialchars($label) ?></label>
                    </div>
                    <?php endforeach; ?>
                    <div class="mt-2">
                        <label class="form-label small text-muted">Deméritos a redimir</label>
                        <input type="number" name="cantidad_redimida" class="form-control" min="1" value="1" required>
                    </div>
                    <div class="row g-2 mt-2">
                        <div class="col-6">
                            <label class="form-label small text-muted">Fecha</label>
                            <input type="date" name="fecha" class="form-control" value="<?= date('Y-m-d') ?>" required>
                        </div>
                        <div class="col-6">
                            <label class="form-label small text-muted">Hora</label>
                            <input type="time" name="hora" class="form-control" value="<?= date('H:i') ?>" required>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-success">Registrar</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Modal Consecuencia -->
    <div class="modal fade" id="modalConsecuencia" tabindex="-1">
        <div class="modal-dialog">
            <form method="POST" class="modal-content">
                <input type="hidden" name="accion" value="registrar_consecuencia">
                <input type="hidden" name="id_asignacion" value="<?= (int) $id_asignacion_filtro ?>">
                <input type="hidden" name="id_matricula" id="consecuencia_id_matricula">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-gavel text-warning"></i> Registrar consecuencia — <span id="consecuencia_nombre_estudiante"></span></h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <label class="form-label small text-muted">Fecha</label>
                    <input type="date" name="fecha" class="form-control mb-2" value="<?= date('Y-m-d') ?>" required>
                    <label class="form-label small text-muted">Descripción</label>
                    <textarea name="descripcion" class="form-control" maxlength="500" rows="3" required></textarea>
                    <small class="text-muted">Escala de referencia: <?= htmlspecialchars(implode(' · ', Demeritos::ESCALA_CONSECUENCIAS)) ?></small>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-warning">Registrar</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Scripts -->
    <?php require __DIR__ . '/partials/scripts.php'; ?>
    <script>
        function abrirModal(idModal, idMatricula, nombreEstudiante) {
            const modalEl = document.getElementById(idModal);
            modalEl.querySelector('input[name="id_matricula"]').value = idMatricula;
            const spanNombre = modalEl.querySelector('.modal-title span');
            if (spanNombre) spanNombre.textContent = nombreEstudiante;
            new bootstrap.Modal(modalEl).show();
        }
    </script>
</body>
</html>
