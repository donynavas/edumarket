<?php
session_start();
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/TenantGuard.php';

if (!isset($_SESSION['user_id']) || $_SESSION['rol'] !== 'profesor') {
    header("Location: " . BASE_URL . "/login.php");
    exit;
}

$database = new Database();
$db = $database->getConnection();
$tid = TenantGuard::id();
$user_id = $_SESSION['user_id'];

// Resolver el profesor autenticado dentro de su institución
$stmt = $db->prepare("SELECT p.id, per.primer_nombre FROM tbl_profesor p
                      JOIN tbl_persona per ON p.id_persona = per.id
                      WHERE per.id_usuario = :uid AND p.id_institucion = :tid");
$stmt->execute([':uid' => $user_id, ':tid' => $tid]);
$profesor = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
$id_profesor = $profesor['id'] ?? 0;

if (!$id_profesor) {
    $_SESSION['error'] = "Perfil de profesor no encontrado";
    header("Location: " . BASE_URL . "/logout.php");
    exit;
}

$mensaje = '';
$tipo_mensaje = '';

$tipos_pregunta = [
    'opcion_multiple' => 'Opción múltiple',
    'verdadero_falso' => 'Verdadero / Falso',
    'completar' => 'Completar espacios',
    'relacionar' => 'Relacionar columnas',
    'respuesta_corta' => 'Respuesta corta',
    'ensayo' => 'Ensayo (revisión manual)',
];
$dificultades = ['facil' => 'Fácil', 'medio' => 'Medio', 'dificil' => 'Difícil'];

// ===== ELIMINAR (solo por este método, vía POST — el resto se maneja en la API) =====
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['accion'] ?? '') === 'eliminar') {
    $id_pregunta = (int) ($_POST['id_pregunta'] ?? 0);
    // Sólo puede borrar preguntas de su propio banco.
    $check = $db->prepare("SELECT id FROM tbl_banco_preguntas WHERE id = :id AND id_profesor = :prof");
    $check->execute([':id' => $id_pregunta, ':prof' => $id_profesor]);
    if ($check->fetch()) {
        $db->prepare("DELETE FROM tbl_banco_preguntas WHERE id = :id")->execute([':id' => $id_pregunta]);
        $mensaje = 'Pregunta eliminada del banco';
        $tipo_mensaje = 'warning';
    } else {
        $mensaje = 'No tiene permiso para eliminar esta pregunta';
        $tipo_mensaje = 'danger';
    }
}

// ===== FILTROS =====
$filtro_asignatura = $_GET['asignatura'] ?? '';
$filtro_tipo = $_GET['tipo'] ?? '';
$filtro_dificultad = $_GET['dificultad'] ?? '';
$filtro_tema = trim($_GET['tema'] ?? '');
$busqueda = trim($_GET['busqueda'] ?? '');

$query = "SELECT bp.*, a.nombre as asignatura_nombre
          FROM tbl_banco_preguntas bp
          LEFT JOIN tbl_asignatura a ON bp.id_asignatura = a.id
          WHERE bp.id_profesor = :prof AND bp.estado = 'activo'";
$params = [':prof' => $id_profesor];

if ($filtro_asignatura) { $query .= " AND bp.id_asignatura = :asig"; $params[':asig'] = $filtro_asignatura; }
if ($filtro_tipo) { $query .= " AND bp.tipo = :tipo"; $params[':tipo'] = $filtro_tipo; }
if ($filtro_dificultad) { $query .= " AND bp.dificultad = :dif"; $params[':dif'] = $filtro_dificultad; }
if ($filtro_tema) { $query .= " AND bp.tema LIKE :tema"; $params[':tema'] = "%$filtro_tema%"; }
if ($busqueda) { $query .= " AND bp.enunciado LIKE :busqueda"; $params[':busqueda'] = "%$busqueda%"; }

$query .= " ORDER BY bp.updated_at DESC";
$stmt = $db->prepare($query);
$stmt->execute($params);
$preguntas = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Opciones de cada pregunta (para mostrar preview y para editar)
$opciones_por_pregunta = [];
if ($preguntas) {
    $ids = array_column($preguntas, 'id');
    $in = implode(',', array_fill(0, count($ids), '?'));
    $stmtOp = $db->prepare("SELECT * FROM tbl_banco_opcion WHERE id_banco_pregunta IN ($in) ORDER BY orden");
    $stmtOp->execute($ids);
    foreach ($stmtOp->fetchAll(PDO::FETCH_ASSOC) as $op) {
        $opciones_por_pregunta[$op['id_banco_pregunta']][] = $op;
    }
}

// Asignaturas del profesor (para el filtro y el formulario)
$stmtAsig = $db->prepare("SELECT DISTINCT a.id, a.nombre FROM tbl_asignacion_docente ad
                          JOIN tbl_asignatura a ON ad.id_asignatura = a.id
                          WHERE ad.id_profesor = :prof ORDER BY a.nombre");
$stmtAsig->execute([':prof' => $id_profesor]);
$asignaturas = $stmtAsig->fetchAll(PDO::FETCH_ASSOC);

// Estadísticas rápidas
$stmtStats = $db->prepare("SELECT tipo, COUNT(*) as total FROM tbl_banco_preguntas WHERE id_profesor = :prof AND estado = 'activo' GROUP BY tipo");
$stmtStats->execute([':prof' => $id_profesor]);
$stats_por_tipo = $stmtStats->fetchAll(PDO::FETCH_KEY_PAIR);
$total_preguntas = array_sum($stats_por_tipo);
$activePage = 'banco';
$pageTitle = 'Banco de Preguntas - Educación Plus';
ob_start();
?>
<style>
    .card-custom { background: white; border-radius: 10px; box-shadow: 0 2px 10px rgba(0,0,0,0.08); margin-bottom: 20px; }
    .pregunta-card { border-left: 4px solid var(--secondary); }
    .badge-dif-facil { background: #d4edda; color: #155724; }
    .badge-dif-medio { background: #fff3cd; color: #856404; }
    .badge-dif-dificil { background: #f8d7da; color: #721c24; }
</style>
<?php
$extraHead = ob_get_clean();
require __DIR__ . '/partials/header.php';
?>
        <div class="d-flex justify-content-between align-items-center mb-4">
            <div>
                <h2><i class="fas fa-layer-group"></i> Banco de Preguntas</h2>
                <p class="text-muted mb-0">Crea preguntas una vez y reutilízalas en cualquier examen.</p>
            </div>
            <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#modalPregunta" onclick="nuevaPregunta()">
                <i class="fas fa-plus"></i> Nueva pregunta
            </button>
        </div>

        <?php if ($mensaje): ?>
        <div class="alert alert-<?= $tipo_mensaje ?> alert-dismissible fade show">
            <?= htmlspecialchars($mensaje) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php endif; ?>

        <div class="row g-3 mb-4">
            <div class="col-md-3">
                <div class="card-custom p-3 text-center">
                    <h3 class="text-primary mb-0"><?= $total_preguntas ?></h3>
                    <small class="text-muted">Preguntas en el banco</small>
                </div>
            </div>
            <div class="col-md-9">
                <div class="card-custom p-3">
                    <small class="text-muted d-block mb-2">Por tipo</small>
                    <?php foreach ($tipos_pregunta as $key => $label): ?>
                        <span class="badge bg-light text-dark border me-2 mb-1">
                            <?= $label ?>: <?= $stats_por_tipo[$key] ?? 0 ?>
                        </span>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>

        <!-- Filtros -->
        <div class="card-custom p-3 mb-4">
            <form method="GET" class="row g-3">
                <div class="col-md-3">
                    <select name="asignatura" class="form-select" onchange="this.form.submit()">
                        <option value="">Todas las asignaturas</option>
                        <?php foreach ($asignaturas as $a): ?>
                        <option value="<?= $a['id'] ?>" <?= $filtro_asignatura == $a['id'] ? 'selected' : '' ?>><?= htmlspecialchars($a['nombre']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <select name="tipo" class="form-select" onchange="this.form.submit()">
                        <option value="">Todos los tipos</option>
                        <?php foreach ($tipos_pregunta as $key => $label): ?>
                        <option value="<?= $key ?>" <?= $filtro_tipo == $key ? 'selected' : '' ?>><?= $label ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <select name="dificultad" class="form-select" onchange="this.form.submit()">
                        <option value="">Toda dificultad</option>
                        <?php foreach ($dificultades as $key => $label): ?>
                        <option value="<?= $key ?>" <?= $filtro_dificultad == $key ? 'selected' : '' ?>><?= $label ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <input type="text" name="tema" class="form-control" placeholder="Tema" value="<?= htmlspecialchars($filtro_tema) ?>">
                </div>
                <div class="col-md-2">
                    <input type="text" name="busqueda" class="form-control" placeholder="Buscar enunciado..." value="<?= htmlspecialchars($busqueda) ?>">
                </div>
                <div class="col-md-1">
                    <button type="submit" class="btn btn-outline-primary w-100"><i class="fas fa-search"></i></button>
                </div>
            </form>
        </div>

        <!-- Lista de preguntas -->
        <?php if (empty($preguntas)): ?>
        <div class="card-custom p-5 text-center">
            <i class="fas fa-inbox fa-4x text-muted mb-3"></i>
            <h5>Todavía no tienes preguntas en el banco</h5>
            <p class="text-muted">Crea tu primera pregunta reutilizable con el botón de arriba, o impórtalas al crear un examen.</p>
        </div>
        <?php else: ?>
        <?php foreach ($preguntas as $p): ?>
        <div class="card-custom pregunta-card p-3">
            <div class="d-flex justify-content-between align-items-start">
                <div class="flex-grow-1">
                    <div class="mb-1">
                        <span class="badge bg-secondary"><?= $tipos_pregunta[$p['tipo']] ?? $p['tipo'] ?></span>
                        <span class="badge badge-dif-<?= $p['dificultad'] ?>"><?= $dificultades[$p['dificultad']] ?></span>
                        <?php if ($p['asignatura_nombre']): ?><span class="badge bg-light text-dark border"><?= htmlspecialchars($p['asignatura_nombre']) ?></span><?php endif; ?>
                        <?php if ($p['tema']): ?><span class="badge bg-light text-dark border"><i class="fas fa-tag"></i> <?= htmlspecialchars($p['tema']) ?></span><?php endif; ?>
                        <?php if ($p['veces_usada'] > 0): ?><span class="badge bg-info text-dark"><i class="fas fa-recycle"></i> usada <?= $p['veces_usada'] ?>x</span><?php endif; ?>
                    </div>
                    <p class="mb-1"><?= htmlspecialchars($p['enunciado']) ?></p>
                    <small class="text-muted">Puntaje sugerido: <?= number_format($p['puntaje_sugerido'], 1) ?> pts</small>
                </div>
                <div class="text-nowrap ms-3">
                    <button type="button" class="btn btn-sm btn-outline-primary" title="Editar"
                            onclick='editarPregunta(<?= json_encode($p, JSON_HEX_APOS | JSON_HEX_QUOT) ?>, <?= json_encode($opciones_por_pregunta[$p['id']] ?? [], JSON_HEX_APOS | JSON_HEX_QUOT) ?>)'>
                        <i class="fas fa-edit"></i>
                    </button>
                    <form method="POST" class="d-inline" onsubmit="return confirm('¿Eliminar esta pregunta del banco? Los exámenes que ya la usaron no se ven afectados.');">
                        <input type="hidden" name="accion" value="eliminar">
                        <input type="hidden" name="id_pregunta" value="<?= $p['id'] ?>">
                        <button type="submit" class="btn btn-sm btn-outline-danger" title="Eliminar"><i class="fas fa-trash"></i></button>
                    </form>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <!-- Modal crear/editar pregunta -->
    <div class="modal fade" id="modalPregunta" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <form id="formPregunta">
                    <div class="modal-header">
                        <h5 class="modal-title" id="modalPreguntaTitulo">Nueva pregunta</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <input type="hidden" name="id_pregunta" id="f_id_pregunta">
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label">Asignatura</label>
                                <select name="id_asignatura" id="f_asignatura" class="form-select">
                                    <option value="">Sin asignatura específica</option>
                                    <?php foreach ($asignaturas as $a): ?>
                                    <option value="<?= $a['id'] ?>"><?= htmlspecialchars($a['nombre']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Tipo de pregunta</label>
                                <select name="tipo" id="f_tipo" class="form-select" onchange="actualizarFormularioTipo()" required>
                                    <?php foreach ($tipos_pregunta as $key => $label): ?>
                                    <option value="<?= $key ?>"><?= $label ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Tema / competencia</label>
                                <input type="text" name="tema" id="f_tema" class="form-control" placeholder="Ej: Ecuaciones de primer grado">
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Dificultad</label>
                                <select name="dificultad" id="f_dificultad" class="form-select">
                                    <?php foreach ($dificultades as $key => $label): ?>
                                    <option value="<?= $key ?>" <?= $key === 'medio' ? 'selected' : '' ?>><?= $label ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Puntaje sugerido</label>
                                <input type="number" step="0.5" min="0.5" name="puntaje_sugerido" id="f_puntaje" class="form-control" value="1">
                            </div>
                            <div class="col-12">
                                <label class="form-label">Enunciado</label>
                                <textarea name="enunciado" id="f_enunciado" class="form-control" rows="2" required></textarea>
                                <small class="text-muted" id="ayuda_completar" style="display:none">Para "completar", encierra las respuestas correctas entre corchetes. Ej: La capital de Francia es [París].</small>
                            </div>
                            <div class="col-12" id="bloque_opciones">
                                <label class="form-label">Opciones de respuesta</label>
                                <div id="lista_opciones"></div>
                                <button type="button" class="btn btn-sm btn-outline-secondary mt-1" id="btn_add_opcion" onclick="agregarOpcion()">
                                    <i class="fas fa-plus"></i> Agregar opción
                                </button>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                        <button type="submit" class="btn btn-primary">Guardar en el banco</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <?php require __DIR__ . '/partials/scripts.php'; ?>
    <script>
        document.getElementById('sidebarToggle')?.addEventListener('click', () => {
            document.getElementById('sidebar').classList.toggle('active');
        });

        function nuevaPregunta() {
            document.getElementById('formPregunta').reset();
            document.getElementById('f_id_pregunta').value = '';
            document.getElementById('modalPreguntaTitulo').textContent = 'Nueva pregunta';
            document.getElementById('lista_opciones').innerHTML = '';
            actualizarFormularioTipo();
        }

        function editarPregunta(pregunta, opciones) {
            document.getElementById('f_id_pregunta').value = pregunta.id;
            document.getElementById('f_asignatura').value = pregunta.id_asignatura || '';
            document.getElementById('f_tipo').value = pregunta.tipo;
            document.getElementById('f_tema').value = pregunta.tema || '';
            document.getElementById('f_dificultad').value = pregunta.dificultad;
            document.getElementById('f_puntaje').value = pregunta.puntaje_sugerido;
            document.getElementById('f_enunciado').value = pregunta.enunciado;
            document.getElementById('modalPreguntaTitulo').textContent = 'Editar pregunta';
            actualizarFormularioTipo(opciones);
            new bootstrap.Modal(document.getElementById('modalPregunta')).show();
        }

        function actualizarFormularioTipo(opcionesExistentes) {
            const tipo = document.getElementById('f_tipo').value;
            const bloqueOpciones = document.getElementById('bloque_opciones');
            const ayudaCompletar = document.getElementById('ayuda_completar');
            const listaOpciones = document.getElementById('lista_opciones');
            listaOpciones.innerHTML = '';
            ayudaCompletar.style.display = 'none';

            if (tipo === 'opcion_multiple' || tipo === 'relacionar') {
                bloqueOpciones.style.display = '';
                if (opcionesExistentes && opcionesExistentes.length) {
                    opcionesExistentes.forEach(o => agregarOpcion(o.texto, !!parseInt(o.es_correcta)));
                } else {
                    agregarOpcion(); agregarOpcion();
                }
            } else if (tipo === 'verdadero_falso') {
                bloqueOpciones.style.display = 'none';
            } else if (tipo === 'completar') {
                bloqueOpciones.style.display = 'none';
                ayudaCompletar.style.display = '';
            } else {
                // respuesta_corta, ensayo: sin opciones estructuradas
                bloqueOpciones.style.display = 'none';
            }
        }

        function agregarOpcion(texto, correcta) {
            const div = document.createElement('div');
            div.className = 'input-group mb-2';
            div.innerHTML = `
                <div class="input-group-text">
                    <input type="checkbox" class="form-check-input mt-0 opcion-correcta" ${correcta ? 'checked' : ''}>
                </div>
                <input type="text" class="form-control opcion-texto" placeholder="Texto de la opción" value="${texto ? texto.replace(/"/g,'&quot;') : ''}">
                <button type="button" class="btn btn-outline-danger" onclick="this.closest('.input-group').remove()"><i class="fas fa-times"></i></button>
            `;
            document.getElementById('lista_opciones').appendChild(div);
        }

        document.getElementById('formPregunta').addEventListener('submit', function(e) {
            e.preventDefault();
            const opciones = [];
            document.querySelectorAll('#lista_opciones .input-group').forEach(row => {
                opciones.push({
                    texto: row.querySelector('.opcion-texto').value,
                    es_correcta: row.querySelector('.opcion-correcta').checked ? 1 : 0
                });
            });

            const formData = new FormData(this);
            formData.append('opciones', JSON.stringify(opciones));

            fetch('api/guardar_pregunta_banco.php', { method: 'POST', body: formData })
                .then(r => r.json())
                .then(data => {
                    if (data.success) {
                        location.reload();
                    } else {
                        alert('Error: ' + data.message);
                    }
                })
                .catch(() => alert('Error de conexión al guardar la pregunta'));
        });
    </script>
</body>
</html>
