<?php
// api/exportar_calificaciones.php — Exporta a Excel el cuadro de notas de
// una actividad (mismas columnas que la tabla en pantalla de
// calificaciones.php) y, si la actividad tiene una rúbrica asociada, una
// segunda hoja con el detalle criterio por criterio.
//
// Usa SimpleXLSXGen (libs/simplexlsx/), el ÚNICO exportador a Excel que de
// verdad funciona en todo el proyecto (mismo patrón que
// modules/admin/api/plantilla_estudiantes.php) -- los otros tres intentos
// de exportar notas en el proyecto (generar_reporte_notas.php con un
// vendor/autoload.php inexistente, los links 404 de reporte_notas.php, y el
// stub de exportar_reporte.php) están rotos y no se tocan ni se usan como
// referencia.

session_start();
require_once __DIR__ . '/../../../config/database.php';
require_once __DIR__ . '/../../../config/TenantGuard.php';
require_once __DIR__ . '/../../../libs/simplexlsx/SimpleXLSXGen.php';

use Shuchkin\SimpleXLSXGen;

if (!isset($_SESSION['user_id']) || $_SESSION['rol'] !== 'profesor') {
    http_response_code(403);
    echo 'No autorizado';
    exit;
}

$db = (new Database())->getConnection();
$tid = TenantGuard::id();
$user_id = $_SESSION['user_id'];

$stmtProf = $db->prepare("SELECT p.id FROM tbl_profesor p
                          JOIN tbl_persona per ON p.id_persona = per.id
                          WHERE per.id_usuario = :uid AND p.id_institucion = :tid");
$stmtProf->execute([':uid' => $user_id, ':tid' => $tid]);
$id_profesor = $stmtProf->fetchColumn();
if (!$id_profesor) {
    http_response_code(403);
    echo 'Perfil de profesor no encontrado';
    exit;
}

$id_actividad = filter_input(INPUT_GET, 'id_actividad', FILTER_VALIDATE_INT);

// Propiedad de la actividad -- mismo JOIN que calificar_multiple en
// calificaciones.php. tbl_actividad no tiene columna id_institucion propia;
// ad.id_profesor ya está tenant-verificado.
$stmtAct = $db->prepare("SELECT a.id, a.titulo, a.tipo, a.id_examen, a.nota_maxima, ad.id_seccion, ad.anno
                         FROM tbl_actividad a
                         JOIN tbl_asignacion_docente ad ON a.id_asignacion_docente = ad.id
                         WHERE a.id = :id AND ad.id_profesor = :prof");
$stmtAct->execute([':id' => $id_actividad, ':prof' => $id_profesor]);
$actividad = $stmtAct->fetch(PDO::FETCH_ASSOC);
if (!$actividad) {
    http_response_code(404);
    echo 'Actividad no encontrada';
    exit;
}

$esExamenAutocalificado = $actividad['tipo'] === 'examen' && !empty($actividad['id_examen']);

// ===== HOJA "Calificaciones" =====
$filas = [['Estudiante', 'NIE', $esExamenAutocalificado ? 'Puntaje' : 'Nota', $esExamenAutocalificado ? 'Porcentaje' : 'Nota máxima', 'Estado', 'Retroalimentación']];

if ($esExamenAutocalificado) {
    $stmt = $db->prepare("SELECT p.primer_nombre, p.primer_apellido, e.nie,
                          ie.puntaje_obtenido, ie.porcentaje, COALESCE(ie.estado, 'sin_iniciar') as estado_entrega
                          FROM tbl_matricula m
                          JOIN tbl_estudiante e ON m.id_estudiante = e.id
                          JOIN tbl_persona p ON e.id_persona = p.id
                          LEFT JOIN tbl_intento_examen ie ON ie.id_examen = :id_examen AND ie.id_matricula = m.id
                          WHERE m.id_seccion = :id_seccion AND m.anno = :anno AND m.estado = 'activo'
                          ORDER BY p.primer_apellido, p.primer_nombre");
    $stmt->execute([':id_examen' => $actividad['id_examen'], ':id_seccion' => $actividad['id_seccion'], ':anno' => $actividad['anno']]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $filas[] = [
            trim($r['primer_nombre'] . ' ' . $r['primer_apellido']), $r['nie'],
            $r['puntaje_obtenido'] !== null ? (float) $r['puntaje_obtenido'] : '',
            $r['porcentaje'] !== null ? (float) $r['porcentaje'] : '',
            $r['estado_entrega'], '',
        ];
    }
} else {
    $stmt = $db->prepare("SELECT p.primer_nombre, p.primer_apellido, e.nie,
                          ea.nota_obtenida, COALESCE(ea.estado_entrega, 'pendiente') as estado_entrega, ea.observacion_docente
                          FROM tbl_matricula m
                          JOIN tbl_estudiante e ON m.id_estudiante = e.id
                          JOIN tbl_persona p ON e.id_persona = p.id
                          LEFT JOIN tbl_entrega_actividad ea ON ea.id_actividad = :id_actividad AND ea.id_matricula = m.id
                          WHERE m.id_seccion = :id_seccion AND m.anno = :anno AND m.estado = 'activo'
                          ORDER BY p.primer_apellido, p.primer_nombre");
    $stmt->execute([':id_actividad' => $id_actividad, ':id_seccion' => $actividad['id_seccion'], ':anno' => $actividad['anno']]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $filas[] = [
            trim($r['primer_nombre'] . ' ' . $r['primer_apellido']), $r['nie'],
            $r['nota_obtenida'] !== null ? (float) $r['nota_obtenida'] : '',
            (float) $actividad['nota_maxima'],
            $r['estado_entrega'], $r['observacion_docente'] ?? '',
        ];
    }
}

$xlsx = SimpleXLSXGen::fromArray($filas, 'Calificaciones');
$xlsx->setColWidth('A:F', 24);
$xlsx->freezePanes('A2');

// ===== HOJA "Rúbrica" (solo si la actividad tiene una asociada) =====
$stmtRub = $db->prepare("SELECT id FROM tbl_rubrica WHERE id_actividad = :id");
$stmtRub->execute([':id' => $id_actividad]);
$id_rubrica = $stmtRub->fetchColumn();

if ($id_rubrica) {
    $filasRub = [['Estudiante', 'NIE', 'Criterio', 'Nivel otorgado', 'Puntaje', 'Comentario del criterio']];

    $stmtDet = $db->prepare("SELECT p.primer_nombre, p.primer_apellido, e.nie,
                             cr.nombre AS criterio_nombre, cr.orden AS criterio_orden,
                             niv.nombre AS nivel_nombre, erd.puntaje_otorgado, erd.comentario_criterio
                             FROM tbl_entrega_rubrica_detalle erd
                             JOIN tbl_entrega_actividad ea ON erd.id_entrega_actividad = ea.id
                             JOIN tbl_matricula m ON ea.id_matricula = m.id
                             JOIN tbl_estudiante e ON m.id_estudiante = e.id
                             JOIN tbl_persona p ON e.id_persona = p.id
                             JOIN tbl_rubrica_criterio cr ON erd.id_criterio = cr.id
                             JOIN tbl_rubrica_nivel niv ON erd.id_nivel = niv.id
                             WHERE ea.id_actividad = :id_actividad
                             ORDER BY p.primer_apellido, p.primer_nombre, cr.orden");
    $stmtDet->execute([':id_actividad' => $id_actividad]);
    foreach ($stmtDet->fetchAll(PDO::FETCH_ASSOC) as $d) {
        $filasRub[] = [
            trim($d['primer_nombre'] . ' ' . $d['primer_apellido']), $d['nie'],
            $d['criterio_nombre'], $d['nivel_nombre'], (float) $d['puntaje_otorgado'], $d['comentario_criterio'] ?? '',
        ];
    }

    if (count($filasRub) === 1) {
        $filasRub[] = ['(Todavía no hay estudiantes calificados con esta rúbrica)'];
    }

    $xlsx->addSheet($filasRub, 'Rúbrica');
    $xlsx->setColWidth('A:F', 24);
}

$nombreArchivo = 'calificaciones_' . preg_replace('/[^A-Za-z0-9_-]+/', '_', $actividad['titulo']) . '.xlsx';
$xlsx->downloadAs($nombreArchivo);
