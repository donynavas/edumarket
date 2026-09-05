<?php
/**
 * Vista de impresión de Carnet Estudiantil -- recibe por POST los
 * id_matricula marcados en carnet_estudiantil.php y la vigencia elegida
 * para esa tanda, y arma una hoja imprimible con una tarjeta tamaño CR80
 * (85.6mm x 54mm, el tamaño estándar de credencial) por estudiante.
 *
 * IMPORTANTE: los ids que llegan por POST se vuelven a filtrar aquí por
 * id_institucion (nunca se confía en la lista que mandó el formulario) --
 * mismo criterio que el resto del sistema para no permitir que alguien
 * arme un POST a mano con ids de matrícula de otra institución.
 *
 * Impresión/PDF vía window.print() del navegador (mismo patrón ya usado en
 * Expediente Docente) -- sin dependencias nuevas de PHP para generar PDF.
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

$vigencia = trim($_POST['vigencia'] ?? '');
$ids = array_filter(array_map('intval', $_POST['ids'] ?? []));

if ($vigencia === '' || empty($ids)) {
    die('Faltan datos: selecciona al menos un estudiante y una vigencia, y vuelve a intentar desde Carnet Estudiantil.');
}

$stmtInst = $db->prepare("SELECT nombre_ce, logo_path, eslogan FROM tbl_institucion WHERE id = :tid");
$stmtInst->execute([':tid' => $tid]);
$institucion = $stmtInst->fetch(PDO::FETCH_ASSOC) ?: ['nombre_ce' => '', 'logo_path' => null, 'eslogan' => null];

$placeholders = implode(',', array_fill(0, count($ids), '?'));
$query = "SELECT e.nie,
        CONCAT_WS(' ', NULLIF(TRIM(per.primer_nombre), ''), NULLIF(TRIM(per.segundo_nombre), ''), NULLIF(TRIM(per.primer_apellido), ''), NULLIF(TRIM(per.segundo_apellido), '')) AS nombre_completo,
        g.nombre AS grado, s.nombre AS seccion, s.turno, m.anno
    FROM tbl_matricula m
    JOIN tbl_estudiante e ON m.id_estudiante = e.id
    JOIN tbl_persona per ON e.id_persona = per.id
    JOIN tbl_seccion s ON m.id_seccion = s.id
    JOIN tbl_grado g ON s.id_grado = g.id
    WHERE m.id IN ($placeholders) AND e.id_institucion = ? AND m.estado = 'activo'
    ORDER BY g.nombre, s.nombre, per.primer_apellido, per.primer_nombre";
$stmt = $db->prepare($query);
$stmt->execute([...$ids, $tid]);
$estudiantes = $stmt->fetchAll(PDO::FETCH_ASSOC);

$turnosLabel = ['matutino' => 'Matutino', 'vespertino' => 'Vespertino'];

if (empty($estudiantes)) {
    die('Ninguno de los estudiantes seleccionados pertenece a esta institución. Vuelve a intentar desde Carnet Estudiantil.');
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Carnets Estudiantiles - <?= htmlspecialchars($institucion['nombre_ce']) ?></title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <style>
        :root { --primary: #1b2a41; --accent: #f39c12; --secondary: #3498db; }
        /* Por defecto los navegadores NO imprimen colores/imágenes de fondo
           (la casilla "Gráficos de fondo" del diálogo de impresión viene
           desmarcada) -- esto es lo que hacía que el carnet impreso/PDF
           perdiera el header azul marino, los puntitos naranjas y el
           degradado del avatar, dejando solo bordes y texto. Esta
           propiedad fuerza a imprimir los fondos tal cual se ven en
           pantalla sin depender de esa casilla. */
        * { box-sizing: border-box; -webkit-print-color-adjust: exact; print-color-adjust: exact; color-adjust: exact; }
        body { font-family: 'Segoe UI', sans-serif; background: #e9ecef; margin: 0; padding: 24px; }

        .toolbar { max-width: 900px; margin: 0 auto 20px; display: flex; justify-content: space-between; align-items: center; }
        .toolbar h1 { font-size: 1.3rem; margin: 0; }
        .toolbar button, .toolbar a { border: none; border-radius: 6px; padding: 10px 18px; font-weight: 600; cursor: pointer; text-decoration: none; display: inline-block; }
        .btn-imprimir { background: var(--primary); color: white; }
        .btn-volver { background: white; color: var(--primary); border: 1px solid #ccc !important; margin-right: 8px; }

        .hoja { max-width: 900px; margin: 0 auto; display: grid; grid-template-columns: repeat(2, 1fr); gap: 24px; }

        /* ===== Tarjeta tamaño CR80 (85.6mm x 54mm) ===== */
        .carnet {
            width: 3.375in; height: 2.125in;
            border-radius: 14px; overflow: hidden; position: relative;
            background: white; box-shadow: 0 2px 10px rgba(0,0,0,0.15);
            font-family: 'Segoe UI', sans-serif;
            page-break-inside: avoid; break-inside: avoid;
        }
        .carnet-header {
            background: var(--primary); color: white;
            padding: 8px 12px; display: flex; align-items: center; gap: 8px;
            position: relative; z-index: 2;
        }
        .carnet-header img { height: 22px; width: 22px; object-fit: contain; border-radius: 4px; background: white; padding: 2px; }
        .carnet-header .nombre-inst { font-weight: 700; font-size: 0.72rem; letter-spacing: 0.3px; line-height: 1.15; }

        /* Puntitos decorativos (esquina superior derecha), mismo espíritu que la imagen de referencia */
        .carnet-dots {
            position: absolute; top: 0; right: 0; width: 70px; height: 46px;
            background-image: radial-gradient(var(--accent) 1.2px, transparent 1.2px);
            background-size: 8px 8px; opacity: 0.5; z-index: 1;
        }

        .carnet-body { padding: 10px 12px 8px; display: flex; gap: 10px; position: relative; }

        .avatar {
            width: 0.85in; height: 0.85in; border-radius: 50%; flex: 0 0 auto;
            background: linear-gradient(135deg, var(--secondary), var(--primary));
            color: white; display: flex; align-items: center; justify-content: center;
            font-weight: 700; font-size: 1.3rem; letter-spacing: 1px;
            border: 3px solid var(--accent); margin-top: 2px;
        }

        .info { flex: 1; min-width: 0; }
        .info .etiqueta-credencial { font-size: 0.58rem; font-weight: 700; letter-spacing: 1.2px; color: var(--accent); text-transform: uppercase; }
        .info .nombre-est { font-weight: 700; font-size: 0.82rem; color: var(--primary); margin: 1px 0 4px; line-height: 1.15; }

        .campos { display: grid; grid-template-columns: 1fr 1fr; gap: 4px 8px; }
        .campo { border-top: 1px solid #e3e6ea; padding-top: 2px; }
        .campo .valor { font-size: 0.68rem; font-weight: 700; color: #212529; line-height: 1.1; }
        .campo .etiqueta { font-size: 0.52rem; color: #6c757d; text-transform: uppercase; letter-spacing: 0.3px; }

        .carnet-footer {
            position: absolute; bottom: 5px; left: 12px; right: 12px;
            font-size: 0.5rem; font-style: italic; color: #8a8f98;
            white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
        }

        @media print {
            body { background: white; padding: 0; }
            .toolbar { display: none; }
            .hoja { max-width: none; gap: 0.2in; }
            .carnet { box-shadow: none; border: 1px solid #ddd; }
            @page { size: letter; margin: 0.4in; }
        }
    </style>
</head>
<body>
    <div class="toolbar">
        <h1><i class="fas fa-id-card"></i> <?= count($estudiantes) ?> carnet(s) -- <?= htmlspecialchars($institucion['nombre_ce']) ?></h1>
        <div>
            <a href="javascript:window.close()" class="btn-volver">Cerrar</a>
            <button class="btn-imprimir" onclick="window.print()"><i class="fas fa-print"></i> Imprimir / Guardar PDF</button>
        </div>
    </div>

    <div class="hoja">
        <?php foreach ($estudiantes as $est): ?>
        <div class="carnet">
            <div class="carnet-dots"></div>
            <div class="carnet-header">
                <?php if ($institucion['logo_path']): ?>
                <img src="<?= htmlspecialchars(BASE_URL . '/' . $institucion['logo_path']) ?>" alt="Logo">
                <?php endif; ?>
                <div class="nombre-inst"><?= htmlspecialchars($institucion['nombre_ce']) ?></div>
            </div>
            <div class="carnet-body">
                <div class="avatar"><?= htmlspecialchars(CarnetHelper::iniciales($est['nombre_completo'])) ?></div>
                <div class="info">
                    <div class="etiqueta-credencial">Credencial</div>
                    <div class="nombre-est"><?= htmlspecialchars($est['nombre_completo']) ?></div>
                    <div class="campos">
                        <div class="campo">
                            <div class="valor"><?= htmlspecialchars($est['nie'] ?: '—') ?></div>
                            <div class="etiqueta">Matrícula</div>
                        </div>
                        <div class="campo">
                            <div class="valor"><?= htmlspecialchars($est['grado']) ?> "<?= htmlspecialchars($est['seccion']) ?>"</div>
                            <div class="etiqueta">Grado/año</div>
                        </div>
                        <div class="campo">
                            <div class="valor"><?= htmlspecialchars($turnosLabel[$est['turno']] ?? 'Sin definir') ?></div>
                            <div class="etiqueta">Turno</div>
                        </div>
                        <div class="campo">
                            <div class="valor"><?= htmlspecialchars($vigencia) ?></div>
                            <div class="etiqueta">Vigencia</div>
                        </div>
                    </div>
                </div>
            </div>
            <?php if (!empty($institucion['eslogan'])): ?>
            <div class="carnet-footer"><?= htmlspecialchars($institucion['eslogan']) ?></div>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>
    </div>
</body>
</html>
