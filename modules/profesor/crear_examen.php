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
$user_id = $_SESSION['user_id'];
$tid = TenantGuard::id();

// Obtener ID del profesor
$stmt = $db->prepare("SELECT p.id, per.primer_nombre FROM tbl_profesor p
                      JOIN tbl_persona per ON p.id_persona = per.id
                      WHERE per.id_usuario = :uid AND p.id_institucion = :tid");
$stmt->execute([':uid' => $user_id, ':tid' => $tid]);
$profesor = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
$id_profesor = $profesor['id'] ?? 0;

// Obtener asignaciones del profesor
$stmt = $db->prepare("SELECT ad.id, asig.nombre as asignatura, CONCAT(g.nombre, ' - ', s.nombre) as clase
                      FROM tbl_asignacion_docente ad
                      JOIN tbl_asignatura asig ON ad.id_asignatura = asig.id
                      JOIN tbl_seccion s ON ad.id_seccion = s.id
                      JOIN tbl_grado g ON s.id_grado = g.id
                      WHERE ad.id_profesor = :prof AND asig.id_institucion = :tid
                      ORDER BY asig.nombre");
$stmt->execute([':prof' => $id_profesor, ':tid' => $tid]);
$asignaciones = $stmt->fetchAll(PDO::FETCH_ASSOC);

$id_asignacion = $_GET['asignacion'] ?? ($asignaciones[0]['id'] ?? 0);
$examen_id = $_GET['examen'] ?? 0;

// Obtener datos del examen si estamos editando
$examen = null;
$preguntas = [];
if ($examen_id) {
    // tbl_examen no tiene columna id_institucion; se verifica que el examen
    // pertenezca a la asignación indicada Y que esa asignación sea del
    // profesor de la sesión (antes no se comprobaba lo segundo).
    $stmt = $db->prepare("SELECT e.* FROM tbl_examen e
                          JOIN tbl_asignacion_docente ad ON e.id_asignacion_docente = ad.id
                          WHERE e.id = :id AND e.id_asignacion_docente = :asig AND ad.id_profesor = :prof");
    $stmt->execute([':id' => $examen_id, ':asig' => $id_asignacion, ':prof' => $id_profesor]);
    $examen = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($examen) {
        $stmt = $db->prepare("SELECT p.*, GROUP_CONCAT(CONCAT(o.id,':',o.texto,':',o.es_correcta,':',o.orden) ORDER BY o.orden SEPARATOR '|') as opciones
                              FROM tbl_pregunta_examen p
                              LEFT JOIN tbl_opcion_respuesta o ON p.id = o.id_pregunta
                              WHERE p.id_examen = :id
                              GROUP BY p.id
                              ORDER BY p.numero_orden");
        $stmt->execute([':id' => $examen_id]);
        $preguntas = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
$activePage = 'examen';
$pageTitle = 'Crear Examen - Educación Plus';
ob_start();
?>
<style>
    .question-card { border-left: 4px solid var(--secondary); background: white; border-radius: 8px; padding: 20px; margin-bottom: 15px; box-shadow: 0 2px 8px rgba(0,0,0,0.05); }
    .option-item { display: flex; gap: 10px; align-items: center; margin-bottom: 8px; padding: 8px; background: #f8f9fa; border-radius: 6px; }
    .option-item.correct { background: #d4edda; border: 1px solid #c3e6cb; }
    .question-type-selector { display: flex; gap: 10px; flex-wrap: wrap; margin-bottom: 20px; }
    .type-btn { padding: 10px 20px; border: 2px solid #ddd; border-radius: 8px; cursor: pointer; transition: all 0.2s; background: white; }
    .type-btn:hover, .type-btn.active { border-color: var(--secondary); background: #e8f4fd; }
    .type-btn i { margin-right: 8px; }
    .preview-container { background: white; border-radius: 12px; padding: 20px; box-shadow: 0 2px 12px rgba(0,0,0,0.08); }
    .timer-display { font-size: 1.5rem; font-weight: bold; color: var(--secondary); }
    .editor-topbar { background: var(--primary); color: white; border-radius: 10px; padding: 14px 20px; margin-bottom: 20px; display: flex; align-items: center; justify-content: space-between; }
</style>
<?php
$extraHead = ob_get_clean();
require __DIR__ . '/partials/header.php';
?>
    <div class="editor-topbar">
        <div class="d-flex align-items-center">
            <i class="fas fa-file-alt fa-lg me-2"></i>
            <h5 class="mb-0">Editor de Exámenes</h5>
        </div>
        <div>
            <a href="asignar_examen.php" class="btn btn-outline-light btn-sm">
                <i class="fas fa-arrow-left"></i> Volver
            </a>
        </div>
    </div>

    <div class="container-fluid p-0">
        <div class="row">
            <!-- Left: Editor -->
            <div class="col-lg-8">
                <div class="card-custom p-4">
                    <h4 class="mb-4"><i class="fas fa-cog"></i> Configuración del Examen</h4>
                    
                    <form id="formExamenConfig">
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label">Asignación *</label>
                                <select name="id_asignacion" class="form-select" required>
                                    <option value="">Seleccionar</option>
                                    <?php foreach ($asignaciones as $asig): ?>
                                    <option value="<?= $asig['id'] ?>" <?= $id_asignacion == $asig['id'] ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($asig['asignatura']) ?> - <?= htmlspecialchars($asig['clase']) ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Título del Examen *</label>
                                <input type="text" name="titulo" class="form-control" required placeholder="Ej: Examen Parcial Unidad 3">
                            </div>
                            <div class="col-12">
                                <label class="form-label">Descripción</label>
                                <textarea name="descripcion" class="form-control" rows="2" placeholder="Breve descripción del examen..."></textarea>
                            </div>
                            <div class="col-12">
                                <label class="form-label">Instrucciones para Estudiantes</label>
                                <textarea name="instrucciones" class="form-control" rows="3" placeholder="Instrucciones claras sobre cómo responder..."></textarea>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Duración (minutos)</label>
                                <input type="number" name="duracion_minutos" class="form-control" value="60" min="1">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Nota Máxima</label>
                                <input type="number" name="nota_maxima" class="form-control" value="10" min="1" max="100" step="0.1">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Intentos Máximos</label>
                                <input type="number" name="intento_maximo" class="form-control" value="1" min="1">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Fecha Programada</label>
                                <input type="datetime-local" name="fecha_programada" class="form-control">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Fecha Límite</label>
                                <input type="datetime-local" name="fecha_limite" class="form-control">
                            </div>
                            <div class="col-12">
                                <div class="form-check form-switch">
                                    <input class="form-check-input" type="checkbox" name="mezclar_preguntas" id="mezclar_preguntas">
                                    <label class="form-check-label" for="mezclar_preguntas">Mezclar preguntas aleatoriamente</label>
                                </div>
                                <div class="form-check form-switch">
                                    <input class="form-check-input" type="checkbox" name="mezclar_opciones" id="mezclar_opciones">
                                    <label class="form-check-label" for="mezclar_opciones">Mezclar opciones de respuesta</label>
                                </div>
                                <div class="form-check form-switch">
                                    <input class="form-check-input" type="checkbox" name="mostrar_resultados" id="mostrar_resultados" checked>
                                    <label class="form-check-label" for="mostrar_resultados">Mostrar resultados inmediatos</label>
                                </div>
                            </div>
                        </div>
                    </form>
                </div>

                <!-- Selector de Tipo de Pregunta -->
                <div class="card-custom p-4 mt-4">
                    <h5 class="mb-3"><i class="fas fa-plus-circle"></i> Agregar Pregunta</h5>
                    <div class="question-type-selector">
                        <button type="button" class="type-btn" onclick="agregarPregunta('opcion_multiple')">
                            <i class="fas fa-list"></i> Opción Múltiple
                        </button>
                        <button type="button" class="type-btn" onclick="agregarPregunta('verdadero_falso')">
                            <i class="fas fa-check-circle"></i> Verdadero/Falso
                        </button>
                        <button type="button" class="type-btn" onclick="agregarPregunta('completar')">
                            <i class="fas fa-fill-drip"></i> Completar
                        </button>
                        <button type="button" class="type-btn" onclick="agregarPregunta('relacionar')">
                            <i class="fas fa-arrows-alt"></i> Relacionar
                        </button>
                        <button type="button" class="type-btn" onclick="agregarPregunta('respuesta_corta')">
                            <i class="fas fa-font"></i> Respuesta Corta
                        </button>
                    </div>
                    
                    <!-- Contenedor de Preguntas -->
                    <div id="preguntas-container">
                        <?php if (!empty($preguntas)): ?>
                            <?php foreach ($preguntas as $i => $preg): ?>
                                <?= renderPregunta($preg, $i + 1) ?>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Right: Preview & Actions -->
            <div class="col-lg-4">
                <div class="preview-container mb-4">
                    <h5 class="mb-3"><i class="fas fa-eye"></i> Vista Previa</h5>
                    <div id="preview-content" class="text-muted text-center py-5">
                        <i class="fas fa-file-alt fa-3x mb-3"></i>
                        <p>Las preguntas aparecerán aquí</p>
                    </div>
                </div>

                <div class="card-custom p-4">
                    <h5 class="mb-3"><i class="fas fa-save"></i> Acciones</h5>
                    <div class="d-grid gap-2">
                        <button class="btn btn-primary" onclick="guardarExamen()">
                            <i class="fas fa-save"></i> Guardar Examen
                        </button>
                        <button class="btn btn-outline-secondary" onclick="previewExamen()">
                            <i class="fas fa-eye"></i> Vista Previa Completa
                        </button>
                        <?php if ($examen_id): ?>
                        <a href="api/exportar_examen.php?id=<?= $examen_id ?>" class="btn btn-outline-success">
                            <i class="fas fa-file-export"></i> Exportar QTI
                        </a>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="card-custom p-4 mt-3">
                    <h6 class="mb-3"><i class="fas fa-chart-bar"></i> Estadísticas</h6>
                    <div class="d-flex justify-content-between mb-2">
                        <span>Preguntas:</span>
                        <strong id="total-preguntas"><?= count($preguntas) ?></strong>
                    </div>
                    <div class="d-flex justify-content-between mb-2">
                        <span>Puntaje Total:</span>
                        <strong id="total-puntaje"><?= array_sum(array_column($preguntas, 'puntaje')) ?></strong>
                    </div>
                    <div class="d-flex justify-content-between">
                        <span>Tiempo Estimado:</span>
                        <strong><?= count($preguntas) * 2 ?> min</strong>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <?php require __DIR__ . '/partials/scripts.php'; ?>
    <script>
        let preguntaCounter = <?= count($preguntas) ?>;
        
        function agregarPregunta(tipo) {
            preguntaCounter++;
            const container = document.getElementById('preguntas-container');
            const preguntaHTML = crearHTMLPregunta(tipo, preguntaCounter);
            container.insertAdjacentHTML('beforeend', preguntaHTML);
            actualizarEstadisticas();
        }
        
        function crearHTMLPregunta(tipo, numero) {
            const tipos = {
                'opcion_multiple': {
                    label: 'Opción Múltiple',
                    icon: 'fa-list',
                    campos: `
                        <div class="mb-3">
                            <label class="form-label">Enunciado *</label>
                            <textarea name="pregunta[${numero}][enunciado]" class="form-control" rows="2" required placeholder="Escribe la pregunta..."></textarea>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Opciones de Respuesta</label>
                            <div id="opciones-${numero}">
                                <div class="option-item">
                                    <input type="radio" name="pregunta[${numero}][correcta]" value="0" required>
                                    <input type="text" name="pregunta[${numero}][opciones][]" class="form-control" placeholder="Opción A" required>
                                    <button type="button" class="btn btn-sm btn-danger" onclick="eliminarOpcion(this)"><i class="fas fa-trash"></i></button>
                                </div>
                                <div class="option-item">
                                    <input type="radio" name="pregunta[${numero}][correcta]" value="1">
                                    <input type="text" name="pregunta[${numero}][opciones][]" class="form-control" placeholder="Opción B">
                                    <button type="button" class="btn btn-sm btn-danger" onclick="eliminarOpcion(this)"><i class="fas fa-trash"></i></button>
                                </div>
                            </div>
                            <button type="button" class="btn btn-sm btn-outline-primary mt-2" onclick="agregarOpcion(${numero})">
                                <i class="fas fa-plus"></i> Agregar Opción
                            </button>
                        </div>
                    `
                },
                'verdadero_falso': {
                    label: 'Verdadero/Falso',
                    icon: 'fa-check-circle',
                    campos: `
                        <div class="mb-3">
                            <label class="form-label">Enunciado *</label>
                            <textarea name="pregunta[${numero}][enunciado]" class="form-control" rows="2" required placeholder="Afirmación verdadero/falso..."></textarea>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Respuesta Correcta *</label>
                            <div class="form-check">
                                <input class="form-check-input" type="radio" name="pregunta[${numero}][correcta]" value="V" required>
                                <label class="form-check-label">Verdadero</label>
                            </div>
                            <div class="form-check">
                                <input class="form-check-input" type="radio" name="pregunta[${numero}][correcta]" value="F">
                                <label class="form-check-label">Falso</label>
                            </div>
                        </div>
                    `
                },
                'completar': {
                    label: 'Completar Espacios',
                    icon: 'fa-fill-drip',
                    campos: `
                        <div class="mb-3">
                            <label class="form-label">Enunciado con [respuesta] *</label>
                            <textarea name="pregunta[${numero}][enunciado]" class="form-control" rows="2" required placeholder="El agua hierve a [100] grados Celsius"></textarea>
                            <small class="text-muted">Usa [corchetes] para indicar la respuesta correcta</small>
                        </div>
                    `
                },
                'relacionar': {
                    label: 'Relacionar Columnas',
                    icon: 'fa-arrows-alt',
                    campos: `
                        <div class="mb-3">
                            <label class="form-label">Instrucciones</label>
                            <textarea name="pregunta[${numero}][enunciado]" class="form-control" rows="2" placeholder="Relaciona cada elemento de la izquierda con su correspondiente de la derecha"></textarea>
                        </div>
                        <div id="relaciones-${numero}">
                            <div class="row g-2 mb-2">
                                <div class="col-5"><input type="text" name="pregunta[${numero}][izquierda][]" class="form-control" placeholder="Elemento A" required></div>
                                <div class="col-2 text-center"><i class="fas fa-arrows-alt-h text-muted"></i></div>
                                <div class="col-5"><input type="text" name="pregunta[${numero}][derecha][]" class="form-control" placeholder="Correspondencia 1" required></div>
                            </div>
                        </div>
                        <button type="button" class="btn btn-sm btn-outline-primary mt-2" onclick="agregarRelacion(${numero})">
                            <i class="fas fa-plus"></i> Agregar Par
                        </button>
                    `
                },
                'respuesta_corta': {
                    label: 'Respuesta Corta',
                    icon: 'fa-font',
                    campos: `
                        <div class="mb-3">
                            <label class="form-label">Pregunta *</label>
                            <textarea name="pregunta[${numero}][enunciado]" class="form-control" rows="2" required placeholder="¿Cuál es la capital de Francia?"></textarea>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Respuesta Correcta *</label>
                            <input type="text" name="pregunta[${numero}][correcta]" class="form-control" placeholder="París" required>
                            <small class="text-muted">Se aceptarán variaciones de mayúsculas/minúsculas</small>
                        </div>
                    `
                }
            };
            
            const config = tipos[tipo];
            
            return `
                <div class="question-card" id="pregunta-${numero}">
                    <div class="d-flex justify-content-between align-items-start mb-3">
                        <h6 class="mb-0">
                            <span class="badge bg-primary me-2">P${numero}</span>
                            <i class="fas ${config.icon} text-secondary me-2"></i>
                            ${config.label}
                        </h6>
                        <div class="d-flex gap-2">
                            <input type="number" name="pregunta[${numero}][puntaje]" class="form-control form-control-sm" style="width: 80px;" value="1" min="0.1" step="0.1" title="Puntaje">
                            <button type="button" class="btn btn-sm btn-danger" onclick="eliminarPregunta(${numero})" title="Eliminar">
                                <i class="fas fa-trash"></i>
                            </button>
                        </div>
                    </div>
                    ${config.campos}
                </div>
            `;
        }
        
        function agregarOpcion(preguntaNum) {
            const container = document.getElementById(`opciones-${preguntaNum}`);
            const index = container.children.length;
            const optionHTML = `
                <div class="option-item">
                    <input type="radio" name="pregunta[${preguntaNum}][correcta]" value="${index}">
                    <input type="text" name="pregunta[${preguntaNum}][opciones][]" class="form-control" placeholder="Opción ${String.fromCharCode(65 + index)}">
                    <button type="button" class="btn btn-sm btn-danger" onclick="eliminarOpcion(this)"><i class="fas fa-trash"></i></button>
                </div>
            `;
            container.insertAdjacentHTML('beforeend', optionHTML);
        }
        
        function agregarRelacion(preguntaNum) {
            const container = document.getElementById(`relaciones-${preguntaNum}`);
            const relationHTML = `
                <div class="row g-2 mb-2">
                    <div class="col-5"><input type="text" name="pregunta[${preguntaNum}][izquierda][]" class="form-control" placeholder="Elemento"></div>
                    <div class="col-2 text-center"><i class="fas fa-arrows-alt-h text-muted"></i></div>
                    <div class="col-5"><input type="text" name="pregunta[${preguntaNum}][derecha][]" class="form-control" placeholder="Correspondencia"></div>
                </div>
            `;
            container.insertAdjacentHTML('beforeend', relationHTML);
        }
        
        function eliminarOpcion(btn) {
            btn.closest('.option-item').remove();
        }
        
        function eliminarPregunta(numero) {
            if (confirm('¿Eliminar esta pregunta?')) {
                document.getElementById(`pregunta-${numero}`).remove();
                actualizarEstadisticas();
            }
        }
        
        function actualizarEstadisticas() {
            const preguntas = document.querySelectorAll('.question-card');
            document.getElementById('total-preguntas').textContent = preguntas.length;
            
            let puntajeTotal = 0;
            preguntas.forEach(p => {
                const input = p.querySelector('input[name*="puntaje"]');
                if (input) puntajeTotal += parseFloat(input.value) || 0;
            });
            document.getElementById('total-puntaje').textContent = puntajeTotal.toFixed(1);
        }
        
        function guardarExamen() {
            const formData = new FormData(document.getElementById('formExamenConfig'));
            const preguntas = document.querySelectorAll('.question-card');
            
            if (preguntas.length === 0) {
                alert('Debes agregar al menos una pregunta');
                return;
            }
            
            // Recopilar datos de preguntas
            preguntas.forEach((preg, index) => {
                const num = index + 1;
                // Los datos ya están en el form por los name="pregunta[num][campo]"
            });
            
            // Agregar ID del examen si estamos editando
            <?php if ($examen_id): ?>
            formData.append('examen_id', <?= $examen_id ?>);
            <?php endif; ?>
            
            // Enviar al servidor
            fetch('api/guardar_examen.php', {
                method: 'POST',
                body: formData
            })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    alert('Examen guardado exitosamente');
                    if (data.examen_id && !<?= $examen_id ?>) {
                        window.location.href = '?asignacion=<?= $id_asignacion ?>&examen=' + data.examen_id;
                    }
                } else {
                    alert('Error: ' + data.message);
                }
            })
            .catch(err => {
                console.error(err);
                alert('Error al guardar el examen');
            });
        }
        
        function previewExamen() {
            const previewContent = document.getElementById('preview-content');
            const preguntas = document.querySelectorAll('.question-card');
            
            if (preguntas.length === 0) {
                previewContent.innerHTML = '<div class="text-center text-muted"><i class="fas fa-exclamation-circle fa-2x mb-2"></i><p>No hay preguntas para mostrar</p></div>';
                return;
            }
            
            let html = '<div class="p-3 bg-light rounded mb-3"><strong>Vista Previa del Examen</strong></div>';
            
            preguntas.forEach((preg, index) => {
                const enunciado = preg.querySelector('textarea[name*="enunciado"]')?.value || '';
                const tipo = preg.querySelector('.badge')?.textContent || '';
                
                html += `
                    <div class="mb-3 p-3 border rounded">
                        <small class="text-muted">Pregunta ${index + 1} - ${tipo}</small>
                        <p class="mb-2">${enunciado}</p>
                        <div class="text-muted fst-italic">[Respuesta del estudiante aquí]</div>
                    </div>
                `;
            });
            
            previewContent.innerHTML = html;
        }
        
        // Event listeners para actualizar estadísticas en tiempo real
        document.addEventListener('input', function(e) {
            if (e.target.name && e.target.name.includes('puntaje')) {
                actualizarEstadisticas();
            }
        });
    </script>
    </div>
</body>
</html>

<?php
// Función helper para renderizar preguntas existentes
function renderPregunta($preg, $numero) {
    $opciones = [];
    if ($preg['opciones']) {
        $opts = explode('|', $preg['opciones']);
        foreach ($opts as $opt) {
            $partes = explode(':', $opt);
            $id = $partes[0] ?? '';
            $texto = $partes[1] ?? '';
            $correcta = $partes[2] ?? 0;
            $orden = $partes[3] ?? 0;
            $opciones[] = ['id' => $id, 'texto' => $texto, 'correcta' => $correcta, 'orden' => $orden];
        }
    }
    
    $html = '<div class="question-card" id="pregunta-'.$numero.'">';
    $html .= '<div class="d-flex justify-content-between align-items-start mb-3">';
    $html .= '<h6 class="mb-0"><span class="badge bg-primary me-2">P'.$numero.'</span>';
    
    $iconos = [
        'opcion_multiple' => 'fa-list',
        'verdadero_falso' => 'fa-check-circle',
        'completar' => 'fa-fill-drip',
        'relacionar' => 'fa-arrows-alt',
        'respuesta_corta' => 'fa-font'
    ];
    $icon = $iconos[$preg['tipo']] ?? 'fa-question';
    $html .= '<i class="fas '.$icon.' text-secondary me-2"></i>';
    $html .= ucfirst(str_replace('_', ' ', $preg['tipo']));
    $html .= '</h6>';
    $html .= '<div class="d-flex gap-2">';
    $html .= '<input type="number" name="pregunta['.$numero.'][puntaje]" class="form-control form-control-sm" style="width: 80px;" value="'.$preg['puntaje'].'" min="0.1" step="0.1">';
    $html .= '<button type="button" class="btn btn-sm btn-danger" onclick="eliminarPregunta('.$numero.')" title="Eliminar"><i class="fas fa-trash"></i></button>';
    $html .= '</div></div>';
    
    // Enunciado
    $html .= '<div class="mb-3"><label class="form-label">Enunciado *</label>';
    $html .= '<textarea name="pregunta['.$numero.'][enunciado]" class="form-control" rows="2" required>'.htmlspecialchars($preg['enunciado']).'</textarea>';
    if ($preg['tipo'] === 'completar') {
        $html .= '<small class="text-muted">Usa [corchetes] para indicar la respuesta correcta</small>';
    }
    $html .= '</div>';

    // Campos según tipo
    if ($preg['tipo'] === 'opcion_multiple') {
        $html .= '<div class="mb-3"><label class="form-label">Opciones de Respuesta</label><div id="opciones-'.$numero.'">';
        foreach ($opciones as $i => $opt) {
            $checked = $opt['correcta'] ? 'checked' : '';
            $html .= '<div class="option-item '.($opt['correcta'] ? 'correct' : '').'">';
            $html .= '<input type="radio" name="pregunta['.$numero.'][correcta]" value="'.$i.'" '.$checked.'>';
            $html .= '<input type="text" name="pregunta['.$numero.'][opciones][]" class="form-control" value="'.htmlspecialchars($opt['texto']).'" required>';
            $html .= '<button type="button" class="btn btn-sm btn-danger" onclick="eliminarOpcion(this)"><i class="fas fa-trash"></i></button>';
            $html .= '</div>';
        }
        $html .= '</div>';
        $html .= '<button type="button" class="btn btn-sm btn-outline-primary mt-2" onclick="agregarOpcion('.$numero.')"><i class="fas fa-plus"></i> Agregar Opción</button></div>';
    } elseif ($preg['tipo'] === 'verdadero_falso') {
        $html .= '<div class="mb-3"><label class="form-label">Respuesta Correcta</label>';
        $html .= '<div class="form-check"><input class="form-check-input" type="radio" name="pregunta['.$numero.'][correcta]" value="V" '.($opciones[0]['correcta'] ? 'checked' : '').'><label class="form-check-label">Verdadero</label></div>';
        $html .= '<div class="form-check"><input class="form-check-input" type="radio" name="pregunta['.$numero.'][correcta]" value="F" '.(!$opciones[0]['correcta'] ? 'checked' : '').'><label class="form-check-label">Falso</label></div></div>';
    } elseif ($preg['tipo'] === 'respuesta_corta') {
        $respuesta = $opciones[0]['texto'] ?? '';
        $html .= '<div class="mb-3"><label class="form-label">Respuesta Correcta *</label>';
        $html .= '<input type="text" name="pregunta['.$numero.'][correcta]" class="form-control" value="'.htmlspecialchars($respuesta).'" required>';
        $html .= '<small class="text-muted">Se aceptarán variaciones de mayúsculas/minúsculas</small></div>';
    } elseif ($preg['tipo'] === 'relacionar') {
        // Las opciones se guardaron primero todas las de la izquierda (es_correcta=0)
        // y luego todas las de la derecha (es_correcta=1), en el mismo orden de emparejamiento.
        $izquierda = array_values(array_filter($opciones, fn($o) => !$o['correcta']));
        $derecha = array_values(array_filter($opciones, fn($o) => $o['correcta']));
        $pares = max(count($izquierda), count($derecha));

        $html .= '<div id="relaciones-'.$numero.'">';
        for ($i = 0; $i < $pares; $i++) {
            $val_izq = htmlspecialchars($izquierda[$i]['texto'] ?? '');
            $val_der = htmlspecialchars($derecha[$i]['texto'] ?? '');
            $html .= '<div class="row g-2 mb-2">';
            $html .= '<div class="col-5"><input type="text" name="pregunta['.$numero.'][izquierda][]" class="form-control" value="'.$val_izq.'" placeholder="Elemento A" required></div>';
            $html .= '<div class="col-2 text-center"><i class="fas fa-arrows-alt-h text-muted"></i></div>';
            $html .= '<div class="col-5"><input type="text" name="pregunta['.$numero.'][derecha][]" class="form-control" value="'.$val_der.'" placeholder="Correspondencia" required></div>';
            $html .= '</div>';
        }
        $html .= '</div>';
        $html .= '<button type="button" class="btn btn-sm btn-outline-primary mt-2" onclick="agregarRelacion('.$numero.')"><i class="fas fa-plus"></i> Agregar Par</button>';
    }

    $html .= '</div>';
    return $html;
}
?>