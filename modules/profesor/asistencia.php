<?php
session_start();
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/TenantGuard.php';

// Verificar que sea profesor
if (!isset($_SESSION['user_id']) || $_SESSION['rol'] != 'profesor') {
    header("Location: " . BASE_URL . "/login.php");
    exit;
}

$database = new Database();
$db = $database->getConnection();
$user_id = $_SESSION['user_id'];
$tid = TenantGuard::id();

// Obtener datos del profesor
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

$ESTADOS_ASISTENCIA = [
    'presente' => ['label' => 'Presente', 'icon' => 'fa-check', 'btn' => 'success'],
    'ausente'  => ['label' => 'Ausente',  'icon' => 'fa-times', 'btn' => 'danger'],
    'permiso'  => ['label' => 'Permiso',  'icon' => 'fa-file-signature', 'btn' => 'warning'],
];

// ===== GUARDAR ASISTENCIA =====
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['accion'] ?? '') === 'guardar_asistencia') {
    $id_asignacion = filter_input(INPUT_POST, 'id_asignacion', FILTER_VALIDATE_INT);
    $fecha = $_POST['fecha'] ?? '';
    $registros = $_POST['asistencia'] ?? []; // [id_matricula => estado]

    if (!$fecha || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha)) {
        $mensaje = 'Fecha inválida';
        $tipo_mensaje = 'danger';
    } else {
        try {
            $db->beginTransaction();

            // La asignación indicada DEBE pertenecer a este profesor.
            // tbl_asignacion_docente no tiene columna id_institucion;
            // id_profesor ya está tenant-verificado (viene de la consulta de
            // arriba, filtrada por p.id_institucion).
            $checkAsig = $db->prepare("SELECT id_seccion, anno FROM tbl_asignacion_docente WHERE id = :id AND id_profesor = :prof");
            $checkAsig->execute([':id' => $id_asignacion, ':prof' => $id_profesor]);
            $asig = $checkAsig->fetch(PDO::FETCH_ASSOC);

            if (!$asig) {
                throw new Exception('No tiene permiso para pasar asistencia en esta asignación');
            }

            // Sólo se aceptan matrículas que de verdad pertenezcan a la
            // sección/año de esta asignación — evita marcar asistencia de un
            // estudiante ajeno aunque se manipule el id_matricula en el POST.
            $checkMatricula = $db->prepare("
                SELECT id FROM tbl_matricula
                WHERE id = :id_matricula AND id_seccion = :id_seccion AND anno = :anno AND estado = 'activo'
            ");

            $query = "INSERT INTO tbl_asistencia (id_matricula, fecha, estado)
                      VALUES (:id_matricula, :fecha, :estado)
                      ON DUPLICATE KEY UPDATE estado = VALUES(estado)";
            $stmt = $db->prepare($query);

            $guardados = 0;
            foreach ($registros as $id_matricula => $estado) {
                $id_matricula = (int) $id_matricula;
                if (!array_key_exists($estado, $ESTADOS_ASISTENCIA)) {
                    continue; // valor fuera de los 3 botones válidos: se ignora
                }
                $checkMatricula->execute([
                    ':id_matricula' => $id_matricula,
                    ':id_seccion' => $asig['id_seccion'],
                    ':anno' => $asig['anno'],
                ]);
                if (!$checkMatricula->fetch()) {
                    continue; // matrícula ajena a esta sección/año: se ignora
                }
                $stmt->execute([
                    ':id_matricula' => $id_matricula,
                    ':fecha' => $fecha,
                    ':estado' => $estado,
                ]);
                $guardados++;
            }

            $db->commit();
            $mensaje = "Asistencia guardada para $guardados estudiante(s)";
            $tipo_mensaje = 'success';
        } catch (Exception $e) {
            $db->rollBack();
            error_log("Error en asistencia.php: " . $e->getMessage());
            $mensaje = 'Error: ' . $e->getMessage();
            $tipo_mensaje = 'danger';
        }
    }
}

// ===== OBTENER ASIGNACIONES DEL PROFESOR =====
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
$fecha_filtro = $_GET['fecha'] ?? date('Y-m-d');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha_filtro)) {
    $fecha_filtro = date('Y-m-d');
}

$asignacion_actual = null;
foreach ($asignaciones as $asig) {
    if ($asig['id'] == $id_asignacion_filtro) { $asignacion_actual = $asig; break; }
}

// ===== LISTA DE ESTUDIANTES + ASISTENCIA DE LA FECHA =====
$estudiantes = [];
$resumen = ['presente' => 0, 'ausente' => 0, 'permiso' => 0, 'sin_marcar' => 0];
if ($asignacion_actual) {
    $query_est = "SELECT
                 e.id as id_estudiante, m.id as id_matricula,
                 p.primer_nombre, p.primer_apellido, e.nie,
                 asi.estado as estado_asistencia
                 FROM tbl_matricula m
                 JOIN tbl_estudiante e ON m.id_estudiante = e.id
                 JOIN tbl_persona p ON e.id_persona = p.id
                 LEFT JOIN tbl_asistencia asi ON asi.id_matricula = m.id AND asi.fecha = :fecha
                 WHERE m.id_seccion = :id_seccion AND m.anno = :anno AND m.estado = 'activo'
                 AND e.id_institucion = :tid
                 ORDER BY p.primer_apellido, p.primer_nombre";
    $stmt_est = $db->prepare($query_est);
    $stmt_est->execute([
        ':fecha' => $fecha_filtro,
        ':id_seccion' => $asignacion_actual['id_seccion'],
        ':anno' => $asignacion_actual['anno'],
        ':tid' => $tid,
    ]);
    $estudiantes = $stmt_est->fetchAll(PDO::FETCH_ASSOC);

    foreach ($estudiantes as $est) {
        if (isset($resumen[$est['estado_asistencia']])) {
            $resumen[$est['estado_asistencia']]++;
        } else {
            $resumen['sin_marcar']++;
        }
    }
}

$activePage = 'asistencia';
$pageTitle = 'Asistencia - Educación Plus';
$mostrarAsignacionesSidebar = true;
$idAsignacionFiltro = $id_asignacion_filtro;
ob_start();
?>
<style>
    .card-custom { background: white; border-radius: 12px; box-shadow: 0 2px 12px rgba(0,0,0,0.08); border: none; margin-bottom: 20px; }
    .student-avatar { width: 40px; height: 40px; border-radius: 50%; background: linear-gradient(135deg, var(--secondary), var(--primary)); color: white; display: flex; align-items: center; justify-content: center; font-weight: 700; font-size: 0.9rem; }
    .stat-card { background: white; border-radius: 12px; padding: 20px; box-shadow: 0 2px 8px rgba(0,0,0,0.08); text-align: center; }
    .stat-card i { font-size: 1.8rem; margin-bottom: 6px; }
    /* min-height explícito: un botón que sólo lleva un ícono de Font Awesome
       (sin texto) puede colapsar a una franja delgada si el CDN de FA
       tarda o falla en cargar (el ícono no aporta altura por sí solo). */
    .asistencia-btn { min-width: 46px; min-height: 42px; display: inline-flex; align-items: center; justify-content: center; }
    .asistencia-btn.active-presente { background: var(--success); color: white; border-color: var(--success); }
    .asistencia-btn.active-ausente { background: var(--danger); color: white; border-color: var(--danger); }
    .asistencia-btn.active-permiso { background: var(--warning); color: white; border-color: var(--warning); }
</style>
<?php
$extraHead = ob_get_clean();
require __DIR__ . '/partials/header.php';
?>
        <!-- Header -->
        <div class="d-flex justify-content-between align-items-center mb-4">
            <div>
                <h2 class="mb-1"><i class="fas fa-clipboard-check"></i> Asistencia</h2>
                <nav aria-label="breadcrumb">
                    <ol class="breadcrumb mb-0">
                        <li class="breadcrumb-item"><a href="profesor_dashboard.php">Dashboard</a></li>
                        <li class="breadcrumb-item active">Asistencia</li>
                    </ol>
                </nav>
            </div>
        </div>

        <!-- Mensajes -->
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

        <!-- Selector de Asignación y Fecha -->
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
                    <label class="form-label small text-muted">Fecha</label>
                    <input type="date" name="fecha" class="form-control" value="<?= htmlspecialchars($fecha_filtro) ?>" onchange="this.form.submit()">
                </div>
                <div class="col-md-3">
                    <button type="submit" class="btn btn-outline-primary w-100"><i class="fas fa-sync"></i> Actualizar</button>
                </div>
            </form>
        </div>

        <?php if ($asignacion_actual && !empty($estudiantes)): ?>
        <!-- Resumen -->
        <div class="row g-3 mb-4">
            <div class="col-md-3">
                <div class="stat-card">
                    <i class="fas fa-check text-success"></i>
                    <h4><?= $resumen['presente'] ?></h4>
                    <small class="text-muted">Presentes</small>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stat-card">
                    <i class="fas fa-times text-danger"></i>
                    <h4><?= $resumen['ausente'] ?></h4>
                    <small class="text-muted">Ausentes</small>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stat-card">
                    <i class="fas fa-file-signature text-warning"></i>
                    <h4><?= $resumen['permiso'] ?></h4>
                    <small class="text-muted">Con permiso</small>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stat-card">
                    <i class="fas fa-question text-muted"></i>
                    <h4><?= $resumen['sin_marcar'] ?></h4>
                    <small class="text-muted">Sin marcar</small>
                </div>
            </div>
        </div>

        <form method="POST" id="formAsistencia">
            <input type="hidden" name="accion" value="guardar_asistencia">
            <input type="hidden" name="id_asignacion" value="<?= $id_asignacion_filtro ?>">
            <input type="hidden" name="fecha" value="<?= htmlspecialchars($fecha_filtro) ?>">

            <div class="card-custom">
                <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
                    <h5 class="mb-0">
                        <i class="fas fa-list"></i> Lista de Estudiantes
                        <span class="badge bg-primary ms-2"><?= count($estudiantes) ?></span>
                        <small class="text-muted ms-2"><?= date('d/m/Y', strtotime($fecha_filtro)) ?></small>
                    </h5>
                    <div class="d-flex gap-2">
                        <button type="button" class="btn btn-outline-success btn-sm" onclick="marcarTodos('presente')">
                            <i class="fas fa-check-double"></i> Todos presentes
                        </button>
                        <button type="submit" class="btn btn-primary btn-sm">
                            <i class="fas fa-save"></i> Guardar Asistencia
                        </button>
                    </div>
                </div>

                <div class="table-responsive">
                    <table class="table table-hover mb-0 align-middle">
                        <thead class="table-light">
                            <tr>
                                <th>Estudiante</th>
                                <th class="text-center">Asistencia</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($estudiantes as $est):
                                $iniciales = strtoupper(substr($est['primer_nombre'], 0, 1) . substr($est['primer_apellido'], 0, 1));
                                $nombre_completo = trim($est['primer_nombre'] . ' ' . $est['primer_apellido']);
                                $estado_actual = $est['estado_asistencia'] ?? '';
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
                                <td>
                                    <input type="hidden" name="asistencia[<?= $est['id_matricula'] ?>]" id="asis_<?= $est['id_matricula'] ?>" value="<?= htmlspecialchars($estado_actual) ?>">
                                    <div class="btn-group d-flex justify-content-center" role="group">
                                        <?php foreach ($ESTADOS_ASISTENCIA as $key => $info): ?>
                                        <button type="button"
                                                class="btn btn-outline-<?= $info['btn'] ?> asistencia-btn <?= $estado_actual === $key ? 'active-' . $key : '' ?>"
                                                title="<?= $info['label'] ?>"
                                                onclick="marcarAsistencia(<?= $est['id_matricula'] ?>, '<?= $key ?>', this)">
                                            <i class="fas <?= $info['icon'] ?>"></i>
                                        </button>
                                        <?php endforeach; ?>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <div class="card-footer bg-white text-end">
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-save"></i> Guardar Asistencia
                    </button>
                </div>
            </div>
        </form>

        <?php elseif ($asignacion_actual): ?>
        <div class="card-custom p-5 text-center">
            <i class="fas fa-user-slash fa-4x text-muted mb-3"></i>
            <h5>No hay estudiantes matriculados en esta sección</h5>
        </div>
        <?php endif; ?>
        <?php endif; ?>
    </div>

    <!-- Scripts -->
    <?php require __DIR__ . '/partials/scripts.php'; ?>
    <script>
        function marcarAsistencia(idMatricula, estado, btn) {
            document.getElementById('asis_' + idMatricula).value = estado;
            const grupo = btn.closest('.btn-group');
            grupo.querySelectorAll('.asistencia-btn').forEach(b => {
                b.classList.remove('active-presente', 'active-ausente', 'active-permiso');
            });
            btn.classList.add('active-' + estado);
        }

        function marcarTodos(estado) {
            document.querySelectorAll('.asistencia-btn').forEach(btn => {
                if (btn.title.toLowerCase() === (estado === 'presente' ? 'presente' : estado)) {
                    // no-op, se maneja abajo con el data del propio botón
                }
            });
            document.querySelectorAll('input[id^="asis_"]').forEach(input => {
                const idMatricula = input.id.replace('asis_', '');
                const grupo = input.nextElementSibling;
                const btnObjetivo = Array.from(grupo.querySelectorAll('.asistencia-btn'))
                    .find(b => b.getAttribute('onclick').includes(`'${estado}'`));
                if (btnObjetivo) {
                    marcarAsistencia(idMatricula, estado, btnObjetivo);
                }
            });
        }
    </script>
</body>
</html>
