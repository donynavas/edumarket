<?php
/**
 * Carnet Estudiantil (lado director) -- genera carnets imprimibles para los
 * estudiantes matriculados en la institución, en el formato de tarjeta
 * (CR80) que pidió el usuario, a partir de una imagen de referencia.
 *
 * Decisiones confirmadas con el usuario (AskUserQuestion):
 * - Sin foto de estudiante por ahora (el sistema no la guarda en ningún
 *   lado) -- el círculo del carnet muestra las iniciales del estudiante
 *   (ver CarnetHelper::iniciales()) en vez de una silueta genérica.
 * - Nombre real de la institución + logo subible + eslogan editable, en vez
 *   de repetir el texto de ejemplo de la imagen ("ALTA PINTA" / "Preparados
 *   para el futuro") -- funciona automáticamente para cualquier institución
 *   nueva sin texto hardcodeado.
 * - La "Vigencia" no se calcula sola: el director la escribe al generar
 *   cada tanda de carnets (aplica igual a todos los seleccionados en esa
 *   tanda).
 *
 * La generación en sí es sin estado (no hay tabla tbl_carnet): es una
 * exportación/impresión, igual que "Exportar Excel" en otros módulos --
 * nada que trackear entre corridas.
 */
session_start();
require_once __DIR__ . '/../../config/database.php';

if (!isset($_SESSION['user_id']) || !in_array($_SESSION['rol'], ['admin', 'director'], true)) {
    header("Location: " . BASE_URL . "/login.php");
    exit;
}

require_once __DIR__ . '/../../config/TenantGuard.php';
require_once __DIR__ . '/../../config/CarnetHelper.php';

$tid = TenantGuard::id();
$db = (new Database())->getConnection();

$mensaje = '';
$tipo_mensaje = '';

// ===== PROCESAR CONFIGURACIÓN (logo + eslogan) =====
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['accion'] ?? '') === 'guardar_config') {
    try {
        $eslogan = trim($_POST['eslogan'] ?? '');
        if (mb_strlen($eslogan) > 200) {
            throw new Exception('El eslogan no puede superar 200 caracteres.');
        }

        $stmtActual = $db->prepare("SELECT logo_path FROM tbl_institucion WHERE id = :tid");
        $stmtActual->execute([':tid' => $tid]);
        $logoActual = $stmtActual->fetchColumn();
        $nuevoLogo = $logoActual ?: null;

        if (!empty($_FILES['logo']['name'])) {
            $nuevoLogo = CarnetHelper::validarYGuardarLogo($_FILES['logo'], $tid);
            CarnetHelper::borrarArchivoFisico($logoActual ?: null);
        }

        $stmt = $db->prepare("UPDATE tbl_institucion SET logo_path = :logo, eslogan = :eslogan WHERE id = :tid");
        $stmt->execute([':logo' => $nuevoLogo, ':eslogan' => $eslogan ?: null, ':tid' => $tid]);

        $mensaje = 'Configuración del carnet actualizada';
        $tipo_mensaje = 'success';
    } catch (Exception $e) {
        $mensaje = 'Error: ' . $e->getMessage();
        $tipo_mensaje = 'danger';
    }
}

// ===== DATOS DE LA INSTITUCIÓN (encabezado del carnet) =====
$stmtInst = $db->prepare("SELECT nombre_ce, logo_path, eslogan FROM tbl_institucion WHERE id = :tid");
$stmtInst->execute([':tid' => $tid]);
$institucion = $stmtInst->fetch(PDO::FETCH_ASSOC) ?: ['nombre_ce' => '', 'logo_path' => null, 'eslogan' => null];

// ===== FILTROS (Grado -> Sección, Año) =====
$idGradoFiltro = isset($_GET['id_grado']) && $_GET['id_grado'] !== '' ? (int) $_GET['id_grado'] : null;
$idSeccionFiltro = isset($_GET['id_seccion']) && $_GET['id_seccion'] !== '' ? (int) $_GET['id_seccion'] : null;
$annoFiltro = isset($_GET['anno']) && $_GET['anno'] !== '' ? (int) $_GET['anno'] : null;

$grados = $db->query("SELECT id, nombre FROM tbl_grado ORDER BY nombre")->fetchAll(PDO::FETCH_ASSOC);

$stmtSecciones = $db->prepare("SELECT id, id_grado, nombre, turno FROM tbl_seccion WHERE id_institucion = :tid ORDER BY id_grado, nombre");
$stmtSecciones->execute([':tid' => $tid]);
$secciones_por_grado = [];
foreach ($stmtSecciones->fetchAll(PDO::FETCH_ASSOC) as $s) {
    $secciones_por_grado[$s['id_grado']][] = $s;
}

$stmtAnnos = $db->prepare("SELECT DISTINCT m.anno FROM tbl_matricula m
    JOIN tbl_estudiante e ON m.id_estudiante = e.id
    WHERE e.id_institucion = :tid AND m.estado = 'activo' ORDER BY m.anno DESC");
$stmtAnnos->execute([':tid' => $tid]);
$annosDisponibles = $stmtAnnos->fetchAll(PDO::FETCH_COLUMN);

// ===== ESTUDIANTES MATRICULADOS QUE CUMPLEN EL FILTRO =====
$where = ["e.id_institucion = :tid", "m.estado = 'activo'"];
$params = [':tid' => $tid];
if ($idGradoFiltro) { $where[] = "g.id = :id_grado"; $params[':id_grado'] = $idGradoFiltro; }
if ($idSeccionFiltro) { $where[] = "s.id = :id_seccion"; $params[':id_seccion'] = $idSeccionFiltro; }
if ($annoFiltro) { $where[] = "m.anno = :anno"; $params[':anno'] = $annoFiltro; }

$query = "SELECT m.id AS id_matricula, e.nie,
        CONCAT_WS(' ', NULLIF(TRIM(per.primer_nombre), ''), NULLIF(TRIM(per.segundo_nombre), ''), NULLIF(TRIM(per.primer_apellido), ''), NULLIF(TRIM(per.segundo_apellido), '')) AS nombre_completo,
        g.nombre AS grado, s.nombre AS seccion, s.turno, m.anno
    FROM tbl_matricula m
    JOIN tbl_estudiante e ON m.id_estudiante = e.id
    JOIN tbl_persona per ON e.id_persona = per.id
    JOIN tbl_seccion s ON m.id_seccion = s.id
    JOIN tbl_grado g ON s.id_grado = g.id
    WHERE " . implode(' AND ', $where) . "
    ORDER BY g.nombre, s.nombre, per.primer_apellido, per.primer_nombre";
$stmt = $db->prepare($query);
$stmt->execute($params);
$estudiantes = $stmt->fetchAll(PDO::FETCH_ASSOC);

$turnosLabel = ['matutino' => 'Matutino', 'vespertino' => 'Vespertino'];
$activePage = 'carnet';
$pageTitle = 'Carnet Estudiantil - Educación Plus';
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($pageTitle) ?></title>
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
        .logo-preview { width: 64px; height: 64px; object-fit: contain; border: 1px solid #dee2e6; border-radius: 8px; background: #f8f9fa; padding: 4px; }
        .tabla-estudiantes th, .tabla-estudiantes td { vertical-align: middle; }
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
            <a class="nav-link" href="horario_clases.php"><i class="fas fa-calendar-week"></i> Horario</a>
            <a class="nav-link" href="gestionar_matriculas.php"><i class="fas fa-file-signature"></i> Matrículas</a>
            <a class="nav-link" href="cuadro_notas.php"><i class="fas fa-clipboard-list"></i> Cuadro de Notas</a>
            <a class="nav-link" href="manual_convivencia.php"><i class="fas fa-handshake"></i> Convivencia Escolar</a>
            <a class="nav-link active" href="carnet_estudiantil.php"><i class="fas fa-id-card"></i> Carnet Estudiantil</a>
            <a class="nav-link" href="../../logout.php"><i class="fas fa-sign-out-alt"></i> Cerrar Sesión</a>
        </nav>
    </div>

    <!-- Main Content -->
    <div class="main-content">
        <div class="mb-4">
            <h2><i class="fas fa-id-card"></i> Carnet Estudiantil</h2>
            <p class="text-muted mb-0">Genera carnets imprimibles para los estudiantes matriculados en la institución.</p>
        </div>

        <?php if ($mensaje): ?>
        <div class="alert alert-<?= $tipo_mensaje ?> alert-dismissible fade show">
            <?= htmlspecialchars($mensaje) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php endif; ?>

        <!-- ===== CONFIGURACIÓN DEL CARNET (logo + eslogan) ===== -->
        <div class="card-custom p-4">
            <h5 class="mb-3"><i class="fas fa-palette"></i> Configuración del Carnet</h5>
            <p class="text-muted small">Se usa el nombre real de la institución (<strong><?= htmlspecialchars($institucion['nombre_ce']) ?></strong>) en el encabezado. El logo y el eslogan son opcionales -- se guardan una vez y se reutilizan en todos los carnets.</p>
            <form method="POST" enctype="multipart/form-data" class="row g-3 align-items-end">
                <input type="hidden" name="accion" value="guardar_config">
                <div class="col-auto">
                    <?php if ($institucion['logo_path']): ?>
                    <img src="<?= htmlspecialchars(BASE_URL . '/' . $institucion['logo_path']) ?>" class="logo-preview" alt="Logo">
                    <?php else: ?>
                    <div class="logo-preview d-flex align-items-center justify-content-center text-muted"><i class="fas fa-image"></i></div>
                    <?php endif; ?>
                </div>
                <div class="col-md-4">
                    <label class="form-label">Logo (JPG, PNG o WEBP, máx. 3 MB)</label>
                    <input type="file" name="logo" accept="image/jpeg,image/png,image/webp" class="form-control">
                </div>
                <div class="col-md-5">
                    <label class="form-label">Eslogan (opcional)</label>
                    <input type="text" name="eslogan" maxlength="200" class="form-control" placeholder="Ej: Preparados para el futuro" value="<?= htmlspecialchars($institucion['eslogan'] ?? '') ?>">
                </div>
                <div class="col-md-2">
                    <button type="submit" class="btn btn-primary w-100"><i class="fas fa-save"></i> Guardar</button>
                </div>
            </form>
        </div>

        <!-- ===== FILTRO ===== -->
        <div class="card-custom p-4">
            <h5 class="mb-3"><i class="fas fa-filter"></i> Seleccionar Estudiantes</h5>
            <form method="GET" class="row g-3 align-items-end">
                <div class="col-md-3">
                    <label class="form-label">Grado</label>
                    <select id="filtro_grado" name="id_grado" class="form-select" onchange="actualizarSeccionesFiltro()">
                        <option value="">Todos</option>
                        <?php foreach ($grados as $g): ?>
                        <option value="<?= $g['id'] ?>" <?= $idGradoFiltro === (int) $g['id'] ? 'selected' : '' ?>><?= htmlspecialchars($g['nombre']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Sección</label>
                    <select id="filtro_seccion" name="id_seccion" class="form-select">
                        <option value="">Todas</option>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Año lectivo</label>
                    <select name="anno" class="form-select">
                        <option value="">Todos</option>
                        <?php foreach ($annosDisponibles as $a): ?>
                        <option value="<?= $a ?>" <?= $annoFiltro === (int) $a ? 'selected' : '' ?>><?= $a ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <button type="submit" class="btn btn-outline-primary w-100"><i class="fas fa-search"></i> Buscar</button>
                </div>
            </form>
        </div>

        <!-- ===== RESULTADOS + GENERAR ===== -->
        <div class="card-custom p-4">
            <form method="POST" action="imprimir_carnets.php" target="_blank" id="formGenerar">
                <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
                    <h5 class="mb-0"><i class="fas fa-users"></i> <?= count($estudiantes) ?> estudiante(s) encontrados</h5>
                    <div class="d-flex gap-2 align-items-end flex-wrap">
                        <div>
                            <label class="form-label small mb-0">Vigencia del carnet</label>
                            <input type="text" name="vigencia" class="form-control form-control-sm" placeholder="Ej: 31/12/2026" required style="min-width: 180px;">
                        </div>
                        <button type="submit" class="btn btn-success" <?= empty($estudiantes) ? 'disabled' : '' ?>>
                            <i class="fas fa-id-card"></i> Generar Carnets (PDF)
                        </button>
                    </div>
                </div>

                <?php if (empty($estudiantes)): ?>
                <div class="text-center py-5 text-muted">
                    <i class="fas fa-inbox fa-3x mb-3"></i>
                    <p>No hay estudiantes matriculados que cumplan este filtro.</p>
                </div>
                <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover tabla-estudiantes">
                        <thead class="table-light">
                            <tr>
                                <th style="width: 40px;"><input type="checkbox" id="marcarTodos" checked onchange="marcarTodosCambio()"></th>
                                <th>Estudiante</th>
                                <th>Matrícula (NIE)</th>
                                <th>Grado</th>
                                <th>Sección</th>
                                <th>Turno</th>
                                <th>Año</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($estudiantes as $est): ?>
                            <tr>
                                <td><input type="checkbox" name="ids[]" value="<?= $est['id_matricula'] ?>" class="chk-estudiante" checked></td>
                                <td><?= htmlspecialchars($est['nombre_completo']) ?></td>
                                <td><?= htmlspecialchars($est['nie'] ?: '—') ?></td>
                                <td><?= htmlspecialchars($est['grado']) ?></td>
                                <td><?= htmlspecialchars($est['seccion']) ?></td>
                                <td><?= htmlspecialchars($turnosLabel[$est['turno']] ?? 'Sin definir') ?></td>
                                <td><?= (int) $est['anno'] ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>
            </form>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        const SECCIONES_POR_GRADO = <?= json_encode($secciones_por_grado, JSON_HEX_TAG | JSON_HEX_APOS) ?>;
        const SECCION_SELECCIONADA = <?= $idSeccionFiltro ? (int) $idSeccionFiltro : 'null' ?>;
        const TURNOS_LABEL = <?= json_encode($turnosLabel, JSON_HEX_TAG | JSON_HEX_APOS) ?>;

        function actualizarSeccionesFiltro() {
            const idGrado = document.getElementById('filtro_grado').value;
            const select = document.getElementById('filtro_seccion');
            const secciones = SECCIONES_POR_GRADO[idGrado] || [];
            select.innerHTML = '<option value="">Todas</option>';
            secciones.forEach(s => {
                const opt = document.createElement('option');
                opt.value = s.id;
                opt.textContent = s.nombre + (s.turno ? ' (' + (TURNOS_LABEL[s.turno] || s.turno) + ')' : '');
                if (SECCION_SELECCIONADA === parseInt(s.id, 10)) opt.selected = true;
                select.appendChild(opt);
            });
        }

        function marcarTodosCambio() {
            const marcado = document.getElementById('marcarTodos').checked;
            document.querySelectorAll('.chk-estudiante').forEach(chk => chk.checked = marcado);
        }

        // Preseleccionar Grado/Sección si la URL ya trae un filtro (recarga con ?id_grado=...)
        document.addEventListener('DOMContentLoaded', () => {
            const idGradoActual = document.getElementById('filtro_grado').value;
            if (idGradoActual) actualizarSeccionesFiltro();
        });

        // Bloquear "Generar Carnets" si no hay ningún estudiante marcado
        document.getElementById('formGenerar').addEventListener('submit', (e) => {
            const marcados = document.querySelectorAll('.chk-estudiante:checked').length;
            if (marcados === 0) {
                e.preventDefault();
                alert('Selecciona al menos un estudiante.');
            }
        });
    </script>
</body>
</html>
