<?php
session_start();
require_once __DIR__ . '/../../config/database.php';

if (!isset($_SESSION['user_id']) || $_SESSION['rol'] !== 'estudiante') {
    header("Location: " . BASE_URL . "/login.php");
    exit;
}

$database = new Database();
$db = $database->getConnection();
$user_id = $_SESSION['user_id'];

$examen_id = $_GET['id'] ?? 0;
if (!$examen_id) die("Examen no válido");

// Obtener datos del examen
$stmt = $db->prepare("SELECT e.*, a.nombre as asignatura 
                      FROM tbl_examen e
                      JOIN tbl_asignacion_docente ad ON e.id_asignacion_docente = ad.id
                      JOIN tbl_asignatura a ON ad.id_asignatura = a.id
                      WHERE e.id = :id AND e.estado = 'activo'");
$stmt->execute([':id' => $examen_id]);
$examen = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$examen) die("Examen no disponible");

// Verificar si el estudiante ya tiene un intento en progreso
$stmt = $db->prepare("SELECT id, fecha_inicio, tiempo_usado FROM tbl_intento_examen 
                      WHERE id_examen = :examen AND id_estudiante = (SELECT id FROM tbl_estudiante WHERE id_persona = (SELECT id_persona FROM tbl_usuario WHERE id = :user))
                      AND estado = 'en_progreso'
                      ORDER BY fecha_inicio DESC LIMIT 1");
$stmt->execute([':examen' => $examen_id, ':user' => $user_id]);
$intento = $stmt->fetch(PDO::FETCH_ASSOC);

// Si no hay intento, crear uno nuevo
if (!$intento) {
    // Obtener id_matricula y id_estudiante
    $stmt = $db->prepare("SELECT e.id as id_estudiante, m.id as id_matricula 
                          FROM tbl_estudiante e
                          JOIN tbl_persona p ON e.id_persona = p.id
                          JOIN tbl_matricula m ON e.id = m.id_estudiante
                          JOIN tbl_seccion s ON m.id_seccion = s.id
                          WHERE p.id_usuario = :user AND m.estado = 'activo' AND s.id = (SELECT id_seccion FROM tbl_asignacion_docente WHERE id = :asig)");
    $stmt->execute([':user' => $user_id, ':asig' => $examen['id_asignacion_docente']]);
    $matricula = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$matricula) die("No tienes matrícula activa para esta clase");
    
    // Crear nuevo intento
    $stmt = $db->prepare("INSERT INTO tbl_intento_examen (id_examen, id_estudiante, id_matricula, fecha_inicio, estado) 
                          VALUES (:examen, :estudiante, :matricula, NOW(), 'en_progreso')");
    $stmt->execute([
        ':examen' => $examen_id,
        ':estudiante' => $matricula['id_estudiante'],
        ':matricula' => $matricula['id_matricula']
    ]);
    $intento_id = $db->lastInsertId();
    
    $intento = ['id' => $intento_id, 'fecha_inicio' => date('Y-m-d H:i:s'), 'tiempo_usado' => 0];
} else {
    $intento_id = $intento['id'];
}

// Obtener preguntas del examen
$order = $examen['mezclar_preguntas'] ? 'RAND()' : 'numero_orden';
$stmt = $db->prepare("SELECT p.*, GROUP_CONCAT(CONCAT(o.id,':',o.texto,':',o.es_correcta) SEPARATOR '|') as opciones_data
                      FROM tbl_pregunta_examen p
                      LEFT JOIN tbl_opcion_respuesta o ON p.id = o.id_pregunta
                      WHERE p.id_examen = :examen
                      GROUP BY p.id
                      ORDER BY $order");
$stmt->execute([':examen' => $examen_id]);
$preguntas = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Parsear opciones
foreach ($preguntas as &$preg) {
    $preg['opciones'] = [];
    if ($preg['opciones_data']) {
        $opts = explode('|', $preg['opciones_data']);
        foreach ($opts as $opt) {
            list($id, $texto, $correcta) = explode(':', $opt);
            $preg['opciones'][] = ['id' => $id, 'texto' => $texto, 'correcta' => $correcta];
        }
        if ($examen['mezclar_opciones']) shuffle($preg['opciones']);
    }
}
unset($preg);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Tomar Examen - <?= htmlspecialchars($examen['titulo']) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <style>
        :root { --primary: #2c3e50; --secondary: #3498db; --success: #2ecc71; --warning: #f39c12; --danger: #e74c3c; }
        body { font-family: 'Segoe UI', sans-serif; background: #f5f7fa; }
        .timer-bar { position: sticky; top: 0; z-index: 1000; background: white; padding: 15px 0; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
        .timer-display { font-size: 1.8rem; font-weight: bold; color: var(--secondary); }
        .timer-display.warning { color: var(--warning); }
        .timer-display.danger { color: var(--danger); animation: pulse 1s infinite; }
        @keyframes pulse { 0%, 100% { opacity: 1; } 50% { opacity: 0.5; } }
        .question-card { background: white; border-radius: 12px; padding: 25px; margin-bottom: 20px; box-shadow: 0 2px 10px rgba(0,0,0,0.05); border-left: 4px solid var(--secondary); }
        .question-card.answered { border-left-color: var(--success); }
        .option-label { display: block; padding: 12px 15px; margin: 8px 0; background: #f8f9fa; border-radius: 8px; cursor: pointer; transition: all 0.2s; border: 2px solid transparent; }
        .option-label:hover { background: #e8f4fd; }
        .option-label.selected { background: #e8f4fd; border-color: var(--secondary); }
        .option-label input { margin-right: 10px; }
        .progress-indicator { position: fixed; bottom: 20px; right: 20px; background: white; padding: 15px; border-radius: 12px; box-shadow: 0 4px 20px rgba(0,0,0,0.15); z-index: 999; }
        .nav-preguntas { display: flex; gap: 8px; flex-wrap: wrap; max-width: 300px; }
        .nav-btn { width: 40px; height: 40px; border-radius: 8px; border: 2px solid #ddd; background: white; cursor: pointer; font-weight: bold; transition: all 0.2s; }
        .nav-btn.answered { background: var(--success); color: white; border-color: var(--success); }
        .nav-btn.current { border-color: var(--secondary); box-shadow: 0 0 0 3px rgba(52,152,219,0.3); }
    </style>
</head>
<body>
    <!-- Timer Bar -->
    <div class="timer-bar">
        <div class="container d-flex justify-content-between align-items-center">
            <div>
                <h5 class="mb-1"><?= htmlspecialchars($examen['titulo']) ?></h5>
                <small class="text-muted"><?= htmlspecialchars($examen['asignatura']) ?></small>
            </div>
            <div class="text-center">
                <div class="timer-display" id="timer">--:--</div>
                <small class="text-muted">Tiempo restante</small>
            </div>
            <button class="btn btn-danger" onclick="confirmarEntrega()">
                <i class="fas fa-paper-plane"></i> Entregar Examen
            </button>
        </div>
    </div>

    <!-- Instructions Modal (First time) -->
    <div class="modal fade" id="modalInstrucciones" data-bs-backdrop="static" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title"><i class="fas fa-info-circle"></i> Instrucciones del Examen</h5>
                </div>
                <div class="modal-body">
                    <?php if ($examen['instrucciones']): ?>
                    <div class="p-3 bg-light rounded mb-3"><?= nl2br(htmlspecialchars($examen['instrucciones'])) ?></div>
                    <?php endif; ?>
                    <ul class="list-unstyled">
                        <li class="mb-2"><i class="fas fa-clock text-primary me-2"></i> <strong>Duración:</strong> <?= $examen['duracion_minutos'] ?> minutos</li>
                        <li class="mb-2"><i class="fas fa-list-ol text-primary me-2"></i> <strong>Preguntas:</strong> <?= count($preguntas) ?></li>
                        <li class="mb-2"><i class="fas fa-star text-primary me-2"></i> <strong>Puntaje total:</strong> <?= array_sum(array_column($preguntas, 'puntaje')) ?> puntos</li>
                        <li class="mb-2"><i class="fas fa-exclamation-triangle text-warning me-2"></i> <strong>Intentos:</strong> <?= $examen['intento_maximo'] ?> máximo</li>
                    </ul>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-primary" data-bs-dismiss="modal" onclick="startExam()">
                        <i class="fas fa-play"></i> Comenzar Examen
                    </button>
                </div>
            </div>
        </div>
    </div>

    <div class="container py-4" style="margin-top: 80px;">
        <div class="row">
            <div class="col-lg-8">
                <form id="formExamen">
                    <?php foreach ($preguntas as $i => $preg): ?>
                    <div class="question-card" id="pregunta-<?= $preg['id'] ?>">
                        <div class="d-flex justify-content-between align-items-start mb-3">
                            <h6 class="mb-0">
                                <span class="badge bg-primary me-2">Pregunta <?= $i + 1 ?></span>
                                <span class="badge bg-secondary"><?= number_format($preg['puntaje'], 1) ?> pts</span>
                            </h6>
                        </div>
                        
                        <p class="mb-4"><?= htmlspecialchars($preg['enunciado']) ?></p>
                        
                        <?php if ($preg['tipo'] === 'opcion_multiple'): ?>
                        <div class="opciones">
                            <?php foreach ($preg['opciones'] as $j => $opt): ?>
                            <label class="option-label">
                                <input type="radio" name="respuesta[<?= $preg['id'] ?>]" value="<?= $opt['id'] ?>" onchange="marcarRespondida(<?= $preg['id'] ?>)">
                                <?= htmlspecialchars($opt['texto']) ?>
                            </label>
                            <?php endforeach; ?>
                        </div>
                        
                        <?php elseif ($preg['tipo'] === 'verdadero_falso'): ?>
                        <div class="opciones">
                            <label class="option-label">
                                <input type="radio" name="respuesta[<?= $preg['id'] ?>]" value="V" onchange="marcarRespondida(<?= $preg['id'] ?>)">
                                <i class="fas fa-check-circle text-success me-2"></i> Verdadero
                            </label>
                            <label class="option-label">
                                <input type="radio" name="respuesta[<?= $preg['id'] ?>]" value="F" onchange="marcarRespondida(<?= $preg['id'] ?>)">
                                <i class="fas fa-times-circle text-danger me-2"></i> Falso
                            </label>
                        </div>
                        
                        <?php elseif ($preg['tipo'] === 'completar'): ?>
                        <?php
                        $texto = $preg['enunciado'];
                        $respuestas = [];
                        preg_match_all('/\[(.*?)\]/', $texto, $matches);
                        $respuestas = $matches[1];
                        $texto_sin_corchetes = preg_replace('/\[(.*?)\]/', '______', $texto);
                        ?>
                        <p><?= nl2br(htmlspecialchars($texto_sin_corchetes)) ?></p>
                        <div class="row g-2">
                            <?php foreach ($respuestas as $j => $resp): ?>
                            <div class="col-md-6">
                                <input type="text" name="respuesta[<?= $preg['id'] ?>][<?= $j ?>]" class="form-control" placeholder="Respuesta <?= $j + 1 ?>" onchange="marcarRespondida(<?= $preg['id'] ?>)">
                            </div>
                            <?php endforeach; ?>
                        </div>
                        
                        <?php elseif ($preg['tipo'] === 'respuesta_corta'): ?>
                        <input type="text" name="respuesta[<?= $preg['id'] ?>]" class="form-control" placeholder="Escribe tu respuesta aquí..." onchange="marcarRespondida(<?= $preg['id'] ?>)">
                        
                        <?php elseif ($preg['tipo'] === 'relacionar'): ?>
                        <div class="row">
                            <div class="col-5"><strong>Columna A</strong></div>
                            <div class="col-2"></div>
                            <div class="col-5"><strong>Columna B</strong></div>
                        </div>
                        <?php
                        $izquierda = [];
                        $derecha = [];
                        foreach ($preg['opciones'] as $opt) {
                            if ($opt['correcta']) $derecha[] = $opt;
                            else $izquierda[] = $opt;
                        }
                        foreach ($izquierda as $j => $elem):
                        ?>
                        <div class="row g-2 mb-2 align-items-center">
                            <div class="col-5"><?= htmlspecialchars($elem['texto']) ?></div>
                            <div class="col-2 text-center"><i class="fas fa-arrows-alt-h text-muted"></i></div>
                            <div class="col-5">
                                <select name="respuesta[<?= $preg['id'] ?>][<?= $j ?>]" class="form-select" onchange="marcarRespondida(<?= $preg['id'] ?>)">
                                    <option value="">Seleccionar...</option>
                                    <?php foreach ($derecha as $k => $d): ?>
                                    <option value="<?= $d['id'] ?>"><?= htmlspecialchars($d['texto']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                    <?php endforeach; ?>
                </form>
            </div>
            
            <div class="col-lg-4">
                <div class="progress-indicator">
                    <h6 class="mb-3"><i class="fas fa-tasks"></i> Progreso</h6>
                    <div class="nav-preguntas" id="navPreguntas">
                        <?php foreach ($preguntas as $i => $preg): ?>
                        <button type="button" class="nav-btn" onclick="scrollToPregunta(<?= $preg['id'] ?>)" id="nav-<?= $preg['id'] ?>">
                            <?= $i + 1 ?>
                        </button>
                        <?php endforeach; ?>
                    </div>
                    <div class="mt-3">
                        <small class="text-muted">Respondidas: <strong id="contador-respondidas">0</strong> / <?= count($preguntas) ?></small>
                        <div class="progress mt-2" style="height: 8px;">
                            <div class="progress-bar bg-success" id="progress-bar" style="width: 0%"></div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Timer
        const duracion = <?= $examen['duracion_minutos'] ?> * 60;
        let tiempoRestante = duracion - (<?= $intento['tiempo_usado'] ?> || 0);
        let timerInterval;
        
        function updateTimer() {
            const horas = Math.floor(tiempoRestante / 3600);
            const minutos = Math.floor((tiempoRestante % 3600) / 60);
            const segundos = tiempoRestante % 60;
            
            const display = document.getElementById('timer');
            display.textContent = `${horas.toString().padStart(2, '0')}:${minutos.toString().padStart(2, '0')}:${segundos.toString().padStart(2, '0')}`;
            
            // Cambiar color según tiempo restante
            display.className = 'timer-display';
            if (tiempoRestante <= 300) display.classList.add('danger'); // 5 minutos
            else if (tiempoRestante <= 600) display.classList.add('warning'); // 10 minutos
            
            tiempoRestante--;
            if (tiempoRestante <= 0) {
                clearInterval(timerInterval);
                alert('¡Tiempo agotado! Tu examen será entregado automáticamente.');
                entregarExamen();
            }
        }
        
        function startExam() {
            document.getElementById('modalInstrucciones').querySelector('[data-bs-dismiss]').click();
            timerInterval = setInterval(updateTimer, 1000);
            updateTimer();
        }
        
        // Marcar preguntas como respondidas
        function marcarRespondida(preguntaId) {
            const card = document.getElementById(`pregunta-${preguntaId}`);
            card.classList.add('answered');
            document.getElementById(`nav-${preguntaId}`).classList.add('answered');
            actualizarContador();
        }
        
        function actualizarContador() {
            const respondidas = document.querySelectorAll('.question-card.answered').length;
            const total = document.querySelectorAll('.question-card').length;
            document.getElementById('contador-respondidas').textContent = respondidas;
            document.getElementById('progress-bar').style.width = `${(respondidas / total) * 100}%`;
        }
        
        function scrollToPregunta(preguntaId) {
            document.getElementById(`pregunta-${preguntaId}`).scrollIntoView({ behavior: 'smooth', block: 'center' });
            // Remover clase current de todos y agregar al actual
            document.querySelectorAll('.nav-btn').forEach(btn => btn.classList.remove('current'));
            document.getElementById(`nav-${preguntaId}`).classList.add('current');
        }
        
        function confirmarEntrega() {
            const respondidas = document.querySelectorAll('.question-card.answered').length;
            const total = document.querySelectorAll('.question-card').length;
            const pendientes = total - respondidas;
            
            if (pendientes > 0) {
                if (!confirm(`Tienes ${pendientes} pregunta(s) sin responder. ¿Deseas entregar de todos modos?`)) return;
            } else {
                if (!confirm('¿Estás seguro de entregar el examen? Esta acción no se puede deshacer.')) return;
            }
            
            entregarExamen();
        }
        
        function entregarExamen() {
            clearInterval(timerInterval);
            
            const formData = new FormData(document.getElementById('formExamen'));
            formData.append('intento_id', <?= $intento_id ?>);
            formData.append('tiempo_usado', <?= $examen['duracion_minutos'] ?> * 60 - tiempoRestante);
            
            fetch('api/entregar_examen.php', {
                method: 'POST',
                body: formData
            })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    window.location.href = 'resultado_examen.php?intento=' + data.intento_id;
                } else {
                    alert('Error: ' + data.message);
                }
            })
            .catch(err => {
                console.error(err);
                alert('Error al entregar el examen');
            });
        }
        
        // Auto-save cada 30 segundos
        setInterval(() => {
            const formData = new FormData(document.getElementById('formExamen'));
            formData.append('intento_id', <?= $intento_id ?>);
            formData.append('auto_save', 1);
            
            fetch('api/entregar_examen.php', {
                method: 'POST',
                body: formData
            }).catch(() => {}); // Silenciar errores de auto-save
        }, 30000);
        
        // Mostrar instrucciones al cargar
        window.addEventListener('load', () => {
            new bootstrap.Modal(document.getElementById('modalInstrucciones')).show();
        });
    </script>
</body>
</html>