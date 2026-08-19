<?php
session_start();
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/TenantGuard.php';

if (!isset($_SESSION['user_id']) || $_SESSION['rol'] !== 'estudiante') {
    header("Location: " . BASE_URL . "/login.php");
    exit;
}

$database = new Database();
$db = $database->getConnection();
$user_id = $_SESSION['user_id'];
$tid = TenantGuard::id();

$intento_id = (int) ($_GET['intento'] ?? 0);
if (!$intento_id) die("Intento no válido");

// Verificar que el intento pertenece al estudiante autenticado (mismo
// patrón de aislamiento que tomar_examen.php / entregar_examen.php:
// tbl_intento_examen no tiene columna id_institucion, así que el
// aislamiento se hace vía tbl_estudiante.id_institucion).
$stmt = $db->prepare("SELECT i.*, e.titulo, e.nota_maxima, e.mostrar_resultados, e.permitir_revision, a.nombre as asignatura
                      FROM tbl_intento_examen i
                      JOIN tbl_examen e ON i.id_examen = e.id
                      JOIN tbl_asignacion_docente ad ON e.id_asignacion_docente = ad.id
                      JOIN tbl_asignatura a ON ad.id_asignatura = a.id
                      WHERE i.id = :intento AND i.id_estudiante = (
                          SELECT est.id FROM tbl_estudiante est
                          JOIN tbl_persona per ON est.id_persona = per.id
                          WHERE per.id_usuario = :user AND est.id_institucion = :tid
                      )");
$stmt->execute([':intento' => $intento_id, ':user' => $user_id, ':tid' => $tid]);
$intento = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$intento) die("Resultado no disponible.");

// Nombre del estudiante (para la barra lateral, igual que en las otras
// páginas del módulo).
$stmt = $db->prepare("SELECT p.primer_nombre FROM tbl_persona p WHERE p.id_usuario = :user");
$stmt->execute([':user' => $user_id]);
$persona = $stmt->fetch(PDO::FETCH_ASSOC);

$en_progreso = ($intento['estado'] === 'en_progreso');
// Si el examen no muestra resultados automáticamente, entregar_examen.php
// deja el intento en 'entregado' (no 'calificado') -- en ese caso no se
// revela nada todavía, solo se confirma la entrega.
$resultados_disponibles = ($intento['estado'] === 'calificado');

$preguntas_feedback = [];
if ($resultados_disponibles) {
    $stmt = $db->prepare("SELECT r.*, p.tipo, p.enunciado, p.puntaje as puntaje_pregunta, p.numero_orden
                          FROM tbl_respuesta_estudiante r
                          JOIN tbl_pregunta_examen p ON r.id_pregunta = p.id
                          WHERE r.id_intento = :intento
                          ORDER BY p.numero_orden, p.id");
    $stmt->execute([':intento' => $intento_id]);
    $respuestas = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $stmtOpts = $db->prepare("SELECT id, texto, es_correcta, orden FROM tbl_opcion_respuesta WHERE id_pregunta = :preg ORDER BY orden");

    foreach ($respuestas as $r) {
        $stmtOpts->execute([':preg' => $r['id_pregunta']]);
        $opciones = $stmtOpts->fetchAll(PDO::FETCH_ASSOC);

        $dada = null;      // texto legible de lo que respondió el estudiante
        $correcta = null;  // texto legible de la respuesta correcta
        $pares = null;     // solo para 'relacionar': lista de [izquierda, dada, correcta, ok]

        switch ($r['tipo']) {
            case 'opcion_multiple':
                foreach ($opciones as $o) {
                    if ($o['id'] == $r['respuesta']) $dada = $o['texto'];
                    if ($o['es_correcta']) $correcta = $o['texto'];
                }
                if ($dada === null) $dada = '(sin responder)';
                break;

            case 'verdadero_falso':
                $dada = strtoupper((string) $r['respuesta']) === 'V' ? 'Verdadero' : (strtoupper((string) $r['respuesta']) === 'F' ? 'Falso' : '(sin responder)');
                foreach ($opciones as $o) {
                    if ($o['es_correcta']) $correcta = $o['texto'];
                }
                break;

            case 'completar':
                $dadas = json_decode((string) $r['respuesta'], true) ?: [];
                $correctas = array_column($opciones, 'texto');
                $partes = [];
                foreach ($correctas as $i => $c) {
                    $d = trim((string) ($dadas[$i] ?? ''));
                    $partes[] = [
                        'dada' => $d !== '' ? $d : '(sin responder)',
                        'correcta' => $c,
                        'ok' => $d !== '' && strcasecmp($d, $c) === 0,
                    ];
                }
                $pares = $partes;
                break;

            case 'respuesta_corta':
                $dada = trim((string) $r['respuesta']) !== '' ? $r['respuesta'] : '(sin responder)';
                $correcta = $opciones[0]['texto'] ?? null;
                break;

            case 'relacionar':
                // Mismo modelo que la calificación en entregar_examen.php:
                // izquierda = es_correcta=0 (ordenada por 'orden'), derecha =
                // es_correcta=1 (ordenada por 'orden'), emparejadas por
                // POSICIÓN dentro de cada grupo, no por el valor de 'orden'.
                $izquierda = array_values(array_filter($opciones, fn($o) => !$o['es_correcta']));
                $derecha = array_values(array_filter($opciones, fn($o) => $o['es_correcta']));
                $dadas = json_decode((string) $r['respuesta'], true) ?: [];
                $partes = [];
                foreach ($izquierda as $i => $izq) {
                    $correctaTexto = $derecha[$i]['texto'] ?? '—';
                    $idDado = $dadas[$i] ?? null;
                    $dadaTexto = '(sin responder)';
                    if ($idDado !== null) {
                        foreach ($derecha as $d) {
                            if ((string) $d['id'] === (string) $idDado) { $dadaTexto = $d['texto']; break; }
                        }
                    }
                    $partes[] = [
                        'izquierda' => $izq['texto'],
                        'dada' => $dadaTexto,
                        'correcta' => $correctaTexto,
                        'ok' => $idDado !== null && isset($derecha[$i]) && (string) $idDado === (string) $derecha[$i]['id'],
                    ];
                }
                $pares = $partes;
                break;

            case 'ensayo':
                $dada = trim((string) $r['respuesta']) !== '' ? $r['respuesta'] : '(sin responder)';
                $correcta = null; // no hay respuesta "correcta" única; se revisa manualmente
                break;
        }

        $preguntas_feedback[] = [
            'tipo' => $r['tipo'],
            'enunciado' => $r['enunciado'],
            'puntaje_pregunta' => $r['puntaje_pregunta'],
            'puntaje_obtenido' => $r['puntaje_obtenido'],
            'es_correcta' => $r['es_correcta'],
            'dada' => $dada,
            'correcta' => $correcta,
            'pares' => $pares,
        ];
    }
}

$tiene_ensayo = false;
foreach ($preguntas_feedback as $pf) {
    if ($pf['tipo'] === 'ensayo') { $tiene_ensayo = true; break; }
}

$porcentaje = (float) $intento['porcentaje'];
$colorPct = $porcentaje >= 70 ? 'success' : ($porcentaje >= 50 ? 'warning' : 'danger');
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Resultado del Examen - Educación Plus</title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">

    <style>
        :root { --primary: #4361ee; --success: #2ecc71; --warning: #f39c12; --danger: #e74c3c; --sidebar-width: 260px; }
        body { font-family: 'Segoe UI', sans-serif; background: #f5f7fa; }
        .sidebar { position: fixed; top: 0; left: 0; height: 100vh; width: var(--sidebar-width); background: linear-gradient(180deg, #1d3557, #2a4365); color: white; z-index: 1000; }
        .sidebar .nav-link { color: rgba(255,255,255,0.85); padding: 12px 20px; border-radius: 8px; margin: 2px 0; }
        .sidebar .nav-link:hover, .sidebar .nav-link.active { background: rgba(255,255,255,0.15); color: white; }
        .main-content { margin-left: var(--sidebar-width); padding: 20px 30px; }
        .card-custom { background: white; border-radius: 12px; box-shadow: 0 2px 12px rgba(0,0,0,0.08); margin-bottom: 20px; overflow: hidden; }
        .score-circle { width: 120px; height: 120px; border-radius: 50%; display: flex; align-items: center; justify-content: center; flex-direction: column; font-weight: bold; border: 6px solid; }
        .score-circle.success { border-color: var(--success); color: var(--success); }
        .score-circle.warning { border-color: var(--warning); color: var(--warning); }
        .score-circle.danger { border-color: var(--danger); color: var(--danger); }
        .pregunta-feedback { border-radius: 12px; padding: 20px; margin-bottom: 16px; border-left: 5px solid #ccc; background: white; box-shadow: 0 2px 8px rgba(0,0,0,0.05); }
        .pregunta-feedback.correcta { border-left-color: var(--success); }
        .pregunta-feedback.incorrecta { border-left-color: var(--danger); }
        .pregunta-feedback.pendiente { border-left-color: var(--warning); }
        .respuesta-linea { padding: 8px 12px; border-radius: 8px; margin: 4px 0; }
        .respuesta-linea.ok { background: #eafaf1; color: #1e8449; }
        .respuesta-linea.mal { background: #fdedec; color: #c0392b; }
        @media (max-width: 992px) { .sidebar { transform: translateX(-100%); } .sidebar.active { transform: translateX(0); } .main-content { margin-left: 0; } }
    </style>
</head>
<body>
    <aside class="sidebar" id="sidebar">
        <div class="text-center p-3 border-bottom">
            <h5><i class="fas fa-graduation-cap"></i> Educación Plus</h5>
        </div>
        <div class="p-3 text-center border-bottom">
            <div class="fw-bold small"><?= htmlspecialchars($persona['primer_nombre'] ?? '') ?></div>
            <small class="text-white-50">Estudiante</small>
        </div>
        <nav class="nav flex-column p-2">
            <a class="nav-link" href="../../index.php"><i class="fas fa-home"></i> Dashboard</a>
            <a class="nav-link" href="mis_clases.php"><i class="fas fa-book"></i> Mis Clases</a>
            <a class="nav-link" href="actividades.php"><i class="fas fa-tasks"></i> Actividades</a>
            <a class="nav-link active" href="mis_notas.php"><i class="fas fa-star"></i> Calificaciones</a>
            <a class="nav-link" href="../../logout.php"><i class="fas fa-sign-out-alt"></i> Cerrar Sesión</a>
        </nav>
    </aside>

    <main class="main-content">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <div>
                <h2><i class="fas fa-clipboard-check"></i> Resultado del Examen</h2>
                <p class="text-muted mb-0"><?= htmlspecialchars($intento['titulo']) ?> · <?= htmlspecialchars($intento['asignatura']) ?></p>
            </div>
            <a href="mis_notas.php" class="btn btn-outline-primary btn-sm"><i class="fas fa-arrow-left"></i> Volver a Calificaciones</a>
        </div>

        <?php if ($en_progreso): ?>
            <div class="alert alert-warning">
                <i class="fas fa-exclamation-triangle"></i> Este examen todavía está en progreso — no ha sido entregado.
            </div>

        <?php elseif (!$resultados_disponibles): ?>
            <div class="card-custom p-4 text-center">
                <i class="fas fa-check-circle text-success" style="font-size: 3rem;"></i>
                <h4 class="mt-3">¡Examen entregado!</h4>
                <p class="text-muted">Tu profesor(a) revisará tus respuestas. Los resultados se mostrarán aquí cuando estén disponibles.</p>
            </div>

        <?php else: ?>
            <div class="card-custom p-4">
                <div class="row align-items-center">
                    <div class="col-md-3 text-center mb-3 mb-md-0">
                        <div class="score-circle <?= $colorPct ?> mx-auto">
                            <div style="font-size:1.6rem;"><?= number_format($porcentaje, 1) ?>%</div>
                        </div>
                    </div>
                    <div class="col-md-9">
                        <h5 class="mb-2">Puntaje obtenido: <?= number_format((float) $intento['puntaje_obtenido'], 2) ?> / <?= number_format((float) $intento['nota_maxima'], 2) ?></h5>
                        <p class="text-muted mb-1"><i class="fas fa-clock"></i> Tiempo usado: <?= gmdate('H:i:s', (int) $intento['tiempo_usado']) ?></p>
                        <?php if ($tiene_ensayo): ?>
                        <div class="alert alert-warning mt-2 mb-0 py-2">
                            <i class="fas fa-pen"></i> Este examen incluye preguntas de ensayo que tu profesor(a) calificará manualmente. El puntaje mostrado no las incluye todavía.
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <h5 class="mb-3">Detalle por pregunta</h5>
            <?php foreach ($preguntas_feedback as $i => $pf): ?>
                <?php
                    $claseEstado = $pf['tipo'] === 'ensayo' ? 'pendiente' : ($pf['es_correcta'] ? 'correcta' : 'incorrecta');
                ?>
                <div class="pregunta-feedback <?= $claseEstado ?>">
                    <div class="d-flex justify-content-between align-items-start mb-2">
                        <h6 class="mb-0"><span class="badge bg-secondary me-2">Pregunta <?= $i + 1 ?></span><?= htmlspecialchars($pf['enunciado']) ?></h6>
                        <span class="badge bg-<?= $pf['tipo'] === 'ensayo' ? 'warning' : ($pf['es_correcta'] ? 'success' : 'danger') ?>">
                            <?php if ($pf['tipo'] === 'ensayo'): ?>
                                <i class="fas fa-hourglass-half"></i> Pendiente de revisión
                            <?php else: ?>
                                <?= number_format((float) $pf['puntaje_obtenido'], 1) ?> / <?= number_format((float) $pf['puntaje_pregunta'], 1) ?> pts
                            <?php endif; ?>
                        </span>
                    </div>

                    <?php if ($pf['pares'] !== null): ?>
                        <?php foreach ($pf['pares'] as $par): ?>
                            <div class="respuesta-linea <?= $par['ok'] ? 'ok' : 'mal' ?>">
                                <?php if (isset($par['izquierda'])): ?>
                                    <strong><?= htmlspecialchars($par['izquierda']) ?>:</strong>
                                <?php endif; ?>
                                Tu respuesta: <?= htmlspecialchars($par['dada']) ?>
                                <?php if (!$par['ok']): ?>
                                    &nbsp;·&nbsp; Correcta: <strong><?= htmlspecialchars($par['correcta']) ?></strong>
                                <?php else: ?>
                                    <i class="fas fa-check ms-2"></i>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    <?php elseif ($pf['tipo'] === 'ensayo'): ?>
                        <div class="respuesta-linea" style="background:#fff8e6;color:#7a5b00;">
                            Tu respuesta: <?= nl2br(htmlspecialchars($pf['dada'])) ?>
                        </div>
                    <?php else: ?>
                        <div class="respuesta-linea <?= $pf['es_correcta'] ? 'ok' : 'mal' ?>">
                            Tu respuesta: <strong><?= htmlspecialchars($pf['dada']) ?></strong>
                            <?php if (!$pf['es_correcta'] && $pf['correcta'] !== null): ?>
                                &nbsp;·&nbsp; Respuesta correcta: <strong><?= htmlspecialchars($pf['correcta']) ?></strong>
                            <?php else: ?>
                                <i class="fas fa-check ms-2"></i>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </main>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
