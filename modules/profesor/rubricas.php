<?php
// modules/profesor/rubricas.php — Biblioteca personal de rúbricas
// reutilizables del profesor (matriz criterios × niveles con descriptores
// y puntaje por celda). Mismo patrón que banco_preguntas.php: listado +
// filtros server-side aquí, guardado real vía fetch() a
// api/guardar_rubrica.php (JSON), borrado por POST normal en esta misma
// página. Solo se listan/editan/borran PLANTILLAS (id_actividad IS NULL) --
// las instancias ya copiadas a una actividad (ver
// gestionar_actividades.php::copiarRubricaATactividad()) son de solo
// lectura y no aparecen aquí.

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

// ===== ELIMINAR (solo plantillas propias, nunca instancias ya usadas) =====
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['accion'] ?? '') === 'eliminar') {
    $id_rubrica = (int) ($_POST['id_rubrica'] ?? 0);
    $check = $db->prepare("SELECT id FROM tbl_rubrica WHERE id = :id AND id_profesor = :prof AND id_actividad IS NULL");
    $check->execute([':id' => $id_rubrica, ':prof' => $id_profesor]);
    if ($check->fetch()) {
        // ON DELETE CASCADE arrastra niveles/criterios/celdas.
        $db->prepare("DELETE FROM tbl_rubrica WHERE id = :id")->execute([':id' => $id_rubrica]);
        $mensaje = 'Rúbrica eliminada de la biblioteca';
        $tipo_mensaje = 'warning';
    } else {
        $mensaje = 'No tiene permiso para eliminar esta rúbrica';
        $tipo_mensaje = 'danger';
    }
}

// ===== LISTADO =====
$busqueda = trim($_GET['busqueda'] ?? '');
$query = "SELECT r.*,
          (SELECT COUNT(*) FROM tbl_rubrica_criterio WHERE id_rubrica = r.id) AS total_criterios,
          (SELECT COUNT(*) FROM tbl_rubrica_nivel WHERE id_rubrica = r.id) AS total_niveles,
          (SELECT COUNT(*) FROM tbl_rubrica ri WHERE ri.id_rubrica_origen = r.id) AS veces_usada
          FROM tbl_rubrica r
          WHERE r.id_profesor = :prof AND r.id_actividad IS NULL AND r.estado = 'activo'";
$params = [':prof' => $id_profesor];
if ($busqueda) {
    $query .= " AND r.nombre LIKE :busqueda";
    $params[':busqueda'] = "%$busqueda%";
}
$query .= " ORDER BY r.updated_at DESC";
$stmt = $db->prepare($query);
$stmt->execute($params);
$rubricas = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Niveles/criterios/celdas de TODAS las rúbricas listadas, para poder
// prellenar el modal de edición sin una llamada adicional por fila (mismo
// enfoque que opciones_por_pregunta en banco_preguntas.php).
$niveles_por_rubrica = [];
$criterios_por_rubrica = [];
$celdas_por_rubrica = []; // [id_rubrica][id_criterio][id_nivel] = ['descripcion'=>, 'puntaje'=>]
if ($rubricas) {
    $ids = array_column($rubricas, 'id');
    $in = implode(',', array_fill(0, count($ids), '?'));

    $stmtN = $db->prepare("SELECT * FROM tbl_rubrica_nivel WHERE id_rubrica IN ($in) ORDER BY orden");
    $stmtN->execute($ids);
    foreach ($stmtN->fetchAll(PDO::FETCH_ASSOC) as $n) {
        $niveles_por_rubrica[$n['id_rubrica']][] = $n;
    }

    $stmtC = $db->prepare("SELECT * FROM tbl_rubrica_criterio WHERE id_rubrica IN ($in) ORDER BY orden");
    $stmtC->execute($ids);
    $idsCriterios = [];
    foreach ($stmtC->fetchAll(PDO::FETCH_ASSOC) as $c) {
        $criterios_por_rubrica[$c['id_rubrica']][] = $c;
        $idsCriterios[] = $c['id'];
    }

    if ($idsCriterios) {
        $inCrit = implode(',', array_fill(0, count($idsCriterios), '?'));
        $stmtCe = $db->prepare("SELECT ce.*, cr.id_rubrica FROM tbl_rubrica_celda ce
                                JOIN tbl_rubrica_criterio cr ON ce.id_criterio = cr.id
                                WHERE ce.id_criterio IN ($inCrit)");
        $stmtCe->execute($idsCriterios);
        foreach ($stmtCe->fetchAll(PDO::FETCH_ASSOC) as $ce) {
            $celdas_por_rubrica[$ce['id_rubrica']][$ce['id_criterio']][$ce['id_nivel']] = [
                'descripcion' => $ce['descripcion'], 'puntaje' => $ce['puntaje'],
            ];
        }
    }
}

$activePage = 'rubricas';
$pageTitle = 'Rúbricas - Educación Plus';
ob_start();
?>
<style>
    .card-custom { background: white; border-radius: 10px; box-shadow: 0 2px 10px rgba(0,0,0,0.08); margin-bottom: 20px; }
    .rubrica-card { border-left: 4px solid var(--purple); }
    #tablaRubricaBuilder th, #tablaRubricaBuilder td { vertical-align: top; min-width: 180px; }
    #tablaRubricaBuilder .col-criterio { min-width: 220px; }
    .celda-rubrica textarea { font-size: 0.8rem; }
    .celda-rubrica input[type=number] { width: 90px; }
    .th-nivel-nombre { width: 100%; font-weight: 600; margin-bottom: 4px; }
</style>
<?php
$extraHead = ob_get_clean();
require __DIR__ . '/partials/header.php';
?>
        <div class="d-flex justify-content-between align-items-center mb-4">
            <div>
                <h2><i class="fas fa-th"></i> Rúbricas</h2>
                <p class="text-muted mb-0">Crea rúbricas una vez y reutilízalas en cualquier tarea. Al asociarlas se copian a la actividad -- editar la plantilla después no afecta lo ya calificado.</p>
            </div>
            <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#modalRubrica" onclick="nuevaRubrica()">
                <i class="fas fa-plus"></i> Nueva rúbrica
            </button>
        </div>

        <?php if ($mensaje): ?>
        <div class="alert alert-<?= $tipo_mensaje ?> alert-dismissible fade show">
            <?= htmlspecialchars($mensaje) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php endif; ?>

        <div class="card-custom p-3 mb-4">
            <form method="GET" class="row g-2">
                <div class="col-md-4">
                    <input type="text" name="busqueda" class="form-control" placeholder="Buscar por nombre..." value="<?= htmlspecialchars($busqueda) ?>">
                </div>
                <div class="col-md-2">
                    <button type="submit" class="btn btn-outline-primary w-100"><i class="fas fa-search"></i> Buscar</button>
                </div>
            </form>
        </div>

        <?php if (empty($rubricas)): ?>
        <div class="card-custom p-5 text-center">
            <i class="fas fa-th fa-4x text-muted mb-3"></i>
            <h5>Todavía no tienes rúbricas en tu biblioteca</h5>
            <p class="text-muted">Crea tu primera rúbrica reutilizable con el botón de arriba. Luego podrás asociarla a cualquier tarea desde "Gestionar Actividades".</p>
        </div>
        <?php else: ?>
        <div class="row g-3">
        <?php foreach ($rubricas as $r): ?>
        <div class="col-md-6">
            <div class="card-custom rubrica-card p-3 h-100">
                <div class="d-flex justify-content-between align-items-start">
                    <div class="flex-grow-1">
                        <h5 class="mb-1"><?= htmlspecialchars($r['nombre']) ?></h5>
                        <?php if ($r['descripcion']): ?><p class="text-muted small mb-2"><?= htmlspecialchars($r['descripcion']) ?></p><?php endif; ?>
                        <span class="badge bg-light text-dark border"><i class="fas fa-list"></i> <?= (int) $r['total_criterios'] ?> criterios</span>
                        <span class="badge bg-light text-dark border"><i class="fas fa-layer-group"></i> <?= (int) $r['total_niveles'] ?> niveles</span>
                        <?php if ($r['veces_usada'] > 0): ?><span class="badge bg-info text-dark"><i class="fas fa-recycle"></i> usada <?= (int) $r['veces_usada'] ?>x</span><?php endif; ?>
                    </div>
                    <div class="text-nowrap ms-2">
                        <button type="button" class="btn btn-sm btn-outline-primary" title="Editar"
                                onclick='editarRubrica(<?= json_encode($r, JSON_HEX_APOS | JSON_HEX_QUOT) ?>, <?= json_encode($niveles_por_rubrica[$r['id']] ?? [], JSON_HEX_APOS | JSON_HEX_QUOT) ?>, <?= json_encode($criterios_por_rubrica[$r['id']] ?? [], JSON_HEX_APOS | JSON_HEX_QUOT) ?>, <?= json_encode($celdas_por_rubrica[$r['id']] ?? [], JSON_HEX_APOS | JSON_HEX_QUOT) ?>)'>
                            <i class="fas fa-edit"></i>
                        </button>
                        <form method="POST" class="d-inline" onsubmit="return confirm('¿Eliminar esta rúbrica de la biblioteca? Las actividades que ya la usaron conservan su propia copia y no se ven afectadas.');">
                            <input type="hidden" name="accion" value="eliminar">
                            <input type="hidden" name="id_rubrica" value="<?= $r['id'] ?>">
                            <button type="submit" class="btn btn-sm btn-outline-danger" title="Eliminar"><i class="fas fa-trash"></i></button>
                        </form>
                    </div>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>

    <!-- Modal crear/editar rúbrica -->
    <div class="modal fade" id="modalRubrica" tabindex="-1">
        <div class="modal-dialog modal-xl modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="modalRubricaTitulo">Nueva rúbrica</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" id="f_id_rubrica">
                    <div class="row g-3 mb-3">
                        <div class="col-md-6">
                            <label class="form-label">Nombre *</label>
                            <input type="text" id="f_nombre" class="form-control" required placeholder="Ej: Ensayo argumentativo">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Descripción</label>
                            <input type="text" id="f_descripcion" class="form-control" placeholder="Opcional">
                        </div>
                    </div>

                    <div class="d-flex gap-2 mb-2">
                        <button type="button" class="btn btn-sm btn-outline-secondary" onclick="agregarNivel()"><i class="fas fa-plus"></i> Agregar nivel (columna)</button>
                        <button type="button" class="btn btn-sm btn-outline-secondary" onclick="agregarCriterio()"><i class="fas fa-plus"></i> Agregar criterio (fila)</button>
                    </div>
                    <small class="text-muted d-block mb-2">Cada celda lleva un descriptor y un puntaje propios -- no se calculan solos, se escriben directamente.</small>

                    <div class="table-responsive">
                        <table class="table table-bordered" id="tablaRubricaBuilder">
                            <thead class="table-light">
                                <tr id="filaEncabezadosNiveles">
                                    <th class="col-criterio">Criterio</th>
                                </tr>
                            </thead>
                            <tbody id="cuerpoCriterios"></tbody>
                        </table>
                    </div>
                    <div id="rubricaBuilderVacio" class="text-center text-muted py-4 border rounded bg-light">
                        <i class="fas fa-th fa-2x mb-2 d-block"></i> Agrega al menos un nivel y un criterio para armar la matriz.
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <button type="button" class="btn btn-primary" onclick="guardarRubrica()">Guardar rúbrica</button>
                </div>
            </div>
        </div>
    </div>

    <?php require __DIR__ . '/partials/scripts.php'; ?>
    <script>
        let contadorNivel = 0;
        let contadorCriterio = 0;

        function nuevaRubrica() {
            document.getElementById('f_id_rubrica').value = '';
            document.getElementById('f_nombre').value = '';
            document.getElementById('f_descripcion').value = '';
            document.getElementById('modalRubricaTitulo').textContent = 'Nueva rúbrica';
            reiniciarBuilder();
            agregarNivel('Excelente');
            agregarNivel('Aceptable');
            agregarNivel('Necesita mejorar');
            agregarCriterio();
        }

        function reiniciarBuilder() {
            document.getElementById('filaEncabezadosNiveles').innerHTML = '<th class="col-criterio">Criterio</th>';
            document.getElementById('cuerpoCriterios').innerHTML = '';
            contadorNivel = 0;
            contadorCriterio = 0;
            actualizarVisibilidadBuilder();
        }

        function actualizarVisibilidadBuilder() {
            const hayNiveles = document.querySelectorAll('#filaEncabezadosNiveles .th-nivel').length > 0;
            const hayCriterios = document.querySelectorAll('#cuerpoCriterios .fila-criterio').length > 0;
            document.getElementById('rubricaBuilderVacio').classList.toggle('d-none', hayNiveles && hayCriterios);
            document.getElementById('tablaRubricaBuilder').classList.toggle('d-none', !(hayNiveles && hayCriterios));
        }

        // Cada nivel/criterio nuevo lleva una clave temporal única SOLO para
        // emparejar filas x columnas en este formulario -- nunca se confía
        // en un id de base de datos que el cliente pudiera manipular; el
        // servidor (guardar_rubrica.php) reconstruye la rúbrica entera a
        // partir de estas claves, sin importar si eran nuevas o ya existían.
        function agregarNivel(nombreInicial) {
            contadorNivel++;
            const key = 'n' + contadorNivel;
            const th = document.createElement('th');
            th.className = 'th-nivel';
            th.dataset.nivelKey = key;
            th.innerHTML = `
                <input type="text" class="form-control form-control-sm th-nivel-nombre campo-nivel-nombre" placeholder="Nombre del nivel" value="${(nombreInicial || '').replace(/"/g, '&quot;')}">
                <button type="button" class="btn btn-sm btn-outline-danger w-100" onclick="quitarNivel('${key}')"><i class="fas fa-times"></i> Quitar nivel</button>
            `;
            document.getElementById('filaEncabezadosNiveles').appendChild(th);

            // Agregar la celda correspondiente a cada fila de criterio ya existente.
            document.querySelectorAll('#cuerpoCriterios .fila-criterio').forEach(fila => {
                fila.appendChild(crearCeldaRubrica(key));
            });
            actualizarVisibilidadBuilder();
        }

        function quitarNivel(key) {
            document.querySelector(`#filaEncabezadosNiveles .th-nivel[data-nivel-key="${key}"]`)?.remove();
            document.querySelectorAll(`#cuerpoCriterios td[data-nivel-key="${key}"]`).forEach(td => td.remove());
            actualizarVisibilidadBuilder();
        }

        function crearCeldaRubrica(nivelKey, descripcion, puntaje) {
            const td = document.createElement('td');
            td.className = 'celda-rubrica';
            td.dataset.nivelKey = nivelKey;
            td.innerHTML = `
                <textarea class="form-control form-control-sm mb-1 campo-celda-descripcion" rows="2" placeholder="Descriptor...">${descripcion ? descripcion.replace(/</g, '&lt;') : ''}</textarea>
                <input type="number" class="form-control form-control-sm campo-celda-puntaje" min="0" step="0.5" placeholder="Pts" value="${puntaje !== undefined && puntaje !== null ? puntaje : ''}">
            `;
            return td;
        }

        function agregarCriterio(nombreInicial, descripcionInicial) {
            contadorCriterio++;
            const key = 'c' + contadorCriterio;
            const tr = document.createElement('tr');
            tr.className = 'fila-criterio';
            tr.dataset.criterioKey = key;

            const tdCriterio = document.createElement('td');
            tdCriterio.className = 'col-criterio';
            tdCriterio.innerHTML = `
                <input type="text" class="form-control form-control-sm mb-1 campo-criterio-nombre" placeholder="Nombre del criterio" value="${(nombreInicial || '').replace(/"/g, '&quot;')}">
                <textarea class="form-control form-control-sm mb-1 campo-criterio-descripcion" rows="2" placeholder="Descripción (opcional)">${descripcionInicial || ''}</textarea>
                <button type="button" class="btn btn-sm btn-outline-danger w-100" onclick="quitarCriterio('${key}')"><i class="fas fa-trash"></i> Quitar criterio</button>
            `;
            tr.appendChild(tdCriterio);

            document.querySelectorAll('#filaEncabezadosNiveles .th-nivel').forEach(th => {
                tr.appendChild(crearCeldaRubrica(th.dataset.nivelKey));
            });

            document.getElementById('cuerpoCriterios').appendChild(tr);
            actualizarVisibilidadBuilder();
        }

        function quitarCriterio(key) {
            document.querySelector(`#cuerpoCriterios .fila-criterio[data-criterio-key="${key}"]`)?.remove();
            actualizarVisibilidadBuilder();
        }

        function editarRubrica(rubrica, niveles, criterios, celdasPorCriterioYNivel) {
            document.getElementById('f_id_rubrica').value = rubrica.id;
            document.getElementById('f_nombre').value = rubrica.nombre;
            document.getElementById('f_descripcion').value = rubrica.descripcion || '';
            document.getElementById('modalRubricaTitulo').textContent = 'Editar rúbrica';
            reiniciarBuilder();

            // Mapa id_nivel real (BD) -> clave temporal nueva de este formulario.
            const mapNivelIdAKey = {};
            niveles.forEach(n => {
                contadorNivel++;
                const key = 'n' + contadorNivel;
                mapNivelIdAKey[n.id] = key;
                const th = document.createElement('th');
                th.className = 'th-nivel';
                th.dataset.nivelKey = key;
                th.innerHTML = `
                    <input type="text" class="form-control form-control-sm th-nivel-nombre campo-nivel-nombre" placeholder="Nombre del nivel" value="${(n.nombre || '').replace(/"/g, '&quot;')}">
                    <button type="button" class="btn btn-sm btn-outline-danger w-100" onclick="quitarNivel('${key}')"><i class="fas fa-times"></i> Quitar nivel</button>
                `;
                document.getElementById('filaEncabezadosNiveles').appendChild(th);
            });

            criterios.forEach(c => {
                contadorCriterio++;
                const key = 'c' + contadorCriterio;
                const tr = document.createElement('tr');
                tr.className = 'fila-criterio';
                tr.dataset.criterioKey = key;

                const tdCriterio = document.createElement('td');
                tdCriterio.className = 'col-criterio';
                tdCriterio.innerHTML = `
                    <input type="text" class="form-control form-control-sm mb-1 campo-criterio-nombre" placeholder="Nombre del criterio" value="${(c.nombre || '').replace(/"/g, '&quot;')}">
                    <textarea class="form-control form-control-sm mb-1 campo-criterio-descripcion" rows="2" placeholder="Descripción (opcional)">${c.descripcion || ''}</textarea>
                    <button type="button" class="btn btn-sm btn-outline-danger w-100" onclick="quitarCriterio('${key}')"><i class="fas fa-trash"></i> Quitar criterio</button>
                `;
                tr.appendChild(tdCriterio);

                const celdasDeEsteCriterio = celdasPorCriterioYNivel[c.id] || {};
                niveles.forEach(n => {
                    const celda = celdasDeEsteCriterio[n.id] || {};
                    tr.appendChild(crearCeldaRubrica(mapNivelIdAKey[n.id], celda.descripcion, celda.puntaje));
                });

                document.getElementById('cuerpoCriterios').appendChild(tr);
            });

            actualizarVisibilidadBuilder();
            new bootstrap.Modal(document.getElementById('modalRubrica')).show();
        }

        function guardarRubrica() {
            const nombre = document.getElementById('f_nombre').value.trim();
            if (!nombre) { alert('El nombre es obligatorio'); return; }

            const niveles = [];
            document.querySelectorAll('#filaEncabezadosNiveles .th-nivel').forEach((th, i) => {
                const nombreNivel = th.querySelector('.campo-nivel-nombre').value.trim();
                if (nombreNivel) niveles.push({ key: th.dataset.nivelKey, nombre: nombreNivel, orden: i });
            });

            const criterios = [];
            document.querySelectorAll('#cuerpoCriterios .fila-criterio').forEach((fila, i) => {
                const nombreCriterio = fila.querySelector('.campo-criterio-nombre').value.trim();
                if (!nombreCriterio) return;
                const celdas = {};
                fila.querySelectorAll('.celda-rubrica').forEach(td => {
                    celdas[td.dataset.nivelKey] = {
                        descripcion: td.querySelector('.campo-celda-descripcion').value.trim(),
                        puntaje: parseFloat(td.querySelector('.campo-celda-puntaje').value) || 0,
                    };
                });
                criterios.push({
                    key: fila.dataset.criterioKey, nombre: nombreCriterio,
                    descripcion: fila.querySelector('.campo-criterio-descripcion').value.trim(),
                    orden: i, celdas,
                });
            });

            if (niveles.length === 0 || criterios.length === 0) {
                alert('Agrega al menos un nivel y un criterio antes de guardar');
                return;
            }

            const payload = {
                id_rubrica: document.getElementById('f_id_rubrica').value || null,
                nombre, descripcion: document.getElementById('f_descripcion').value.trim(),
                niveles, criterios,
            };

            fetch('api/guardar_rubrica.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload),
            })
                .then(r => r.json())
                .then(data => {
                    if (data.success) {
                        location.reload();
                    } else {
                        alert('Error: ' + data.message);
                    }
                })
                .catch(() => alert('Error de conexión al guardar la rúbrica'));
        }
    </script>
</body>
</html>
