<?php
/**
 * Modo Vista en Vivo / Proyección de una clase (pantalla completa, sin
 * sidebar) -- la pieza "innovar para impartir la clase" del plan: el resto
 * del módulo (impartir_clase.php) es planeación, esta pantalla es para
 * PROYECTAR en el aula mientras se imparte: 4 pasos grandes navegables
 * (Objetivo → Desarrollo → Recursos → Cierre) y un cronómetro de clase.
 */
session_start();
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/TenantGuard.php';

if (!isset($_SESSION['user_id']) || $_SESSION['rol'] != 'profesor') {
    header("Location: " . BASE_URL . "/login.php");
    exit;
}

$database = new Database();
$db = $database->getConnection();
$user_id = $_SESSION['user_id'];
$tid = TenantGuard::id();

$stmtProf = $db->prepare("SELECT p.id as id_profesor, per.primer_nombre, per.primer_apellido
                          FROM tbl_profesor p JOIN tbl_persona per ON p.id_persona = per.id
                          WHERE per.id_usuario = :uid AND p.id_institucion = :tid");
$stmtProf->execute([':uid' => $user_id, ':tid' => $tid]);
$profesor = $stmtProf->fetch(PDO::FETCH_ASSOC);
$id_profesor = (int) ($profesor['id_profesor'] ?? 0);

$id_clase = (int) ($_GET['clase'] ?? 0);

function cargarClaseVivo(PDO $db, int $idClase, int $tid, int $idProfesor): ?array
{
    $stmt = $db->prepare("SELECT c.*, asig.nombre AS asignatura_nombre, g.nombre AS grado_nombre, s.nombre AS seccion_nombre
                          FROM tbl_clase_impartida c
                          JOIN tbl_asignacion_docente ad ON c.id_asignacion_docente = ad.id
                          JOIN tbl_asignatura asig ON ad.id_asignatura = asig.id
                          JOIN tbl_seccion s ON ad.id_seccion = s.id
                          JOIN tbl_grado g ON s.id_grado = g.id
                          WHERE c.id = :id AND c.id_institucion = :tid AND ad.id_profesor = :prof");
    $stmt->execute([':id' => $idClase, ':tid' => $tid, ':prof' => $idProfesor]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $accion = $_POST['accion'] ?? '';
    $claseCheck = cargarClaseVivo($db, $id_clase, $tid, $id_profesor);
    if ($claseCheck) {
        if ($accion === 'iniciar') {
            $db->prepare("UPDATE tbl_clase_impartida SET estado = 'impartida', iniciada_en = NOW(), finalizada_en = NULL WHERE id = :id")
               ->execute([':id' => $id_clase]);
        } elseif ($accion === 'finalizar') {
            $db->prepare("UPDATE tbl_clase_impartida SET finalizada_en = NOW() WHERE id = :id")
               ->execute([':id' => $id_clase]);
        }
    }
    header("Location: impartir_clase_vivo.php?clase=" . $id_clase);
    exit;
}

$clase = cargarClaseVivo($db, $id_clase, $tid, $id_profesor);
if (!$clase) {
    http_response_code(404);
    die('Clase no encontrada o no tiene permiso para verla. <a href="impartir_clase.php">Volver</a>');
}

$stmtRec = $db->prepare("SELECT * FROM tbl_clase_recurso WHERE id_clase = :id ORDER BY orden, id");
$stmtRec->execute([':id' => $clase['id']]);
$recursos = $stmtRec->fetchAll(PDO::FETCH_ASSOC);

$iconosRecurso = ['imagen' => 'fa-image', 'sitio_web' => 'fa-globe', 'articulo' => 'fa-file-alt', 'video_yt' => 'fa-video'];
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Impartiendo: <?= htmlspecialchars($clase['asignatura_nombre']) ?> - Educación Plus</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
<style>
    body { background: #12181f; color: #eef2f6; font-family: 'Segoe UI', system-ui, sans-serif; }
    .barra-superior { background: #1b232d; padding: 14px 24px; display: flex; justify-content: space-between; align-items: center; border-bottom: 2px solid #2c3e50; }
    .cronometro { font-size: 1.6rem; font-weight: 700; font-variant-numeric: tabular-nums; }
    .escenario { min-height: 70vh; display: flex; flex-direction: column; justify-content: center; padding: 40px 8vw; }
    .paso { display: none; }
    .paso.activo { display: block; animation: aparecer 0.25s ease; }
    @keyframes aparecer { from { opacity: 0; transform: translateY(8px); } to { opacity: 1; transform: translateY(0); } }
    .paso h2 { font-size: 2.2rem; color: #5dade2; margin-bottom: 24px; }
    /* Objetivo/Desarrollo/Cierre ahora vienen como HTML ya sanitizado del
       editor de texto enriquecido (ver impartir_clase.php) -- sin
       white-space:pre-wrap, que era para cuando estos campos eran texto
       plano; las etiquetas <p>/<br> del editor ya manejan el espaciado. */
    .paso .contenido { font-size: 1.6rem; line-height: 1.6; }
    .paso .contenido table { color: #eef2f6; border-color: #34495e; }
    .recurso-btn { display: inline-flex; align-items: center; gap: 10px; background: #1b232d; border: 1px solid #34495e; border-radius: 10px; padding: 16px 22px; margin: 8px; font-size: 1.2rem; color: #eef2f6; text-decoration: none; }
    .recurso-btn:hover { background: #223142; color: #eef2f6; }
    .recurso-btn img { max-height: 60vh; max-width: 100%; border-radius: 8px; display: block; margin: 12px auto; }
    .barra-inferior { position: fixed; bottom: 0; left: 0; right: 0; background: #1b232d; padding: 14px 24px; display: flex; justify-content: space-between; align-items: center; border-top: 2px solid #2c3e50; }
    .indicador-pasos span { display: inline-block; width: 10px; height: 10px; border-radius: 50%; background: #34495e; margin: 0 4px; }
    .indicador-pasos span.activo { background: #5dade2; }
</style>
</head>
<body>
    <div class="barra-superior">
        <div>
            <strong><?= htmlspecialchars($clase['asignatura_nombre']) ?></strong> —
            <?= htmlspecialchars($clase['grado_nombre']) ?> <?= htmlspecialchars($clase['seccion_nombre']) ?>
            <?php if ($clase['numero_clase']): ?> · Clase <?= htmlspecialchars($clase['numero_clase']) ?><?php endif; ?>
        </div>
        <div class="d-flex align-items-center gap-3">
            <span id="cronometro" class="cronometro">00:00</span>
            <?php if ($clase['estado'] !== 'impartida' || $clase['finalizada_en']): ?>
            <form method="POST"><input type="hidden" name="accion" value="iniciar"><button class="btn btn-success btn-sm"><i class="fas fa-play"></i> Iniciar clase</button></form>
            <?php else: ?>
            <form method="POST"><input type="hidden" name="accion" value="finalizar"><button class="btn btn-danger btn-sm"><i class="fas fa-stop"></i> Finalizar clase</button></form>
            <?php endif; ?>
            <a href="impartir_clase.php?clase=<?= $clase['id'] ?>" class="btn btn-outline-light btn-sm"><i class="fas fa-times"></i> Salir</a>
        </div>
    </div>

    <div class="escenario">
        <div class="paso activo" data-paso="0">
            <h2><i class="fas fa-bullseye"></i> Objetivo de la Clase</h2>
            <div class="contenido"><?= $clase['objetivo'] ? $clase['objetivo'] : 'Sin objetivo registrado.' ?></div>
        </div>
        <div class="paso" data-paso="1">
            <h2><i class="fas fa-chalkboard"></i> Desarrollo de la Clase</h2>
            <div class="contenido"><?= $clase['desarrollo'] ? $clase['desarrollo'] : 'Sin desarrollo registrado.' ?></div>
        </div>
        <div class="paso" data-paso="2">
            <h2><i class="fas fa-book-open"></i> Recursos</h2>
            <?php if (empty($recursos)): ?>
            <p class="text-muted">No se agregaron recursos a esta clase.</p>
            <?php else: ?>
            <div>
                <?php foreach ($recursos as $r): ?>
                    <?php if ($r['tipo'] === 'imagen' && $r['url']): ?>
                    <div class="text-center"><img src="../../<?= htmlspecialchars($r['url']) ?>" alt="<?= htmlspecialchars($r['titulo'] ?: 'Imagen') ?>"></div>
                    <?php elseif ($r['url']): ?>
                    <a class="recurso-btn" href="<?= htmlspecialchars($r['url']) ?>" target="_blank" rel="noopener">
                        <i class="fas <?= $iconosRecurso[$r['tipo']] ?? 'fa-link' ?>"></i> <?= htmlspecialchars($r['titulo'] ?: $r['url']) ?>
                    </a>
                    <?php else: ?>
                    <div class="recurso-btn" style="display:block; cursor:default;">
                        <div><i class="fas <?= $iconosRecurso[$r['tipo']] ?? 'fa-link' ?>"></i> <strong><?= htmlspecialchars($r['titulo'] ?: 'Artículo') ?></strong></div>
                        <?php if ($r['contenido']): ?><div class="mt-2" style="font-size:1rem; white-space:pre-wrap;"><?= htmlspecialchars($r['contenido']) ?></div><?php endif; ?>
                    </div>
                    <?php endif; ?>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>
        <div class="paso" data-paso="3">
            <h2><i class="fas fa-flag-checkered"></i> Cierre</h2>
            <div class="contenido"><?= $clase['cierre'] ? $clase['cierre'] : 'Sin cierre registrado.' ?></div>
        </div>
    </div>

    <div class="barra-inferior">
        <button class="btn btn-outline-light" id="btnAnterior"><i class="fas fa-chevron-left"></i> Anterior</button>
        <div class="indicador-pasos" id="indicadorPasos">
            <span class="activo"></span><span></span><span></span><span></span>
        </div>
        <button class="btn btn-outline-light" id="btnSiguiente">Siguiente <i class="fas fa-chevron-right"></i></button>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        (function () {
            const pasos = document.querySelectorAll('.paso');
            const puntos = document.querySelectorAll('#indicadorPasos span');
            let actual = 0;
            function mostrar(i) {
                actual = Math.max(0, Math.min(pasos.length - 1, i));
                pasos.forEach((p, idx) => p.classList.toggle('activo', idx === actual));
                puntos.forEach((p, idx) => p.classList.toggle('activo', idx === actual));
            }
            document.getElementById('btnAnterior').addEventListener('click', () => mostrar(actual - 1));
            document.getElementById('btnSiguiente').addEventListener('click', () => mostrar(actual + 1));
            document.addEventListener('keydown', (e) => {
                if (e.key === 'ArrowRight') mostrar(actual + 1);
                if (e.key === 'ArrowLeft') mostrar(actual - 1);
            });

            // Cronómetro: cuenta desde iniciada_en si la clase está en curso
            // (impartida y sin finalizar). Se recalcula desde el timestamp del
            // servidor en cada carga -- no depende de que el navegador quede
            // abierto sin recargar.
            const iniciadaEn = <?= $clase['iniciada_en'] && !$clase['finalizada_en'] ? json_encode(strtotime($clase['iniciada_en']) * 1000) : 'null' ?>;
            const cronEl = document.getElementById('cronometro');
            if (iniciadaEn) {
                setInterval(() => {
                    const segs = Math.max(0, Math.floor((Date.now() - iniciadaEn) / 1000));
                    const mm = String(Math.floor(segs / 60)).padStart(2, '0');
                    const ss = String(segs % 60).padStart(2, '0');
                    cronEl.textContent = mm + ':' + ss;
                }, 1000);
            }
        })();
    </script>
</body>
</html>
