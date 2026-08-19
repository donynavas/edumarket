<?php
session_start();
require_once __DIR__ . '/../../../config/database.php';

// Verificar autenticación
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['rol'], ['admin', 'director', 'profesor'])) {
    header("Location: " . BASE_URL . "/login.php");
    exit;
}

require_once __DIR__ . '/../../../config/TenantGuard.php';
$tid = TenantGuard::id();
$database = new Database();
$db = $database->getConnection();

// ===== PARÁMETROS =====
$periodo = $_GET['periodo'] ?? 1;
$asignatura = $_GET['asignatura'] ?? 0;
$grado = $_GET['grado'] ?? '';
$seccion = $_GET['seccion'] ?? '';
$formato = $_GET['formato'] ?? 'excel';
$incluir_asistencia = $_GET['asistencia'] ?? 1;
$incluir_observaciones = $_GET['observaciones'] ?? 0;
$anno = $_GET['anno'] ?? date('Y');

// ===== OBTENER DATOS DEL REPORTE =====

// Consulta principal para obtener calificaciones
$query = "SELECT 
    m.id as id_matricula, m.anno, m.id_periodo,
    e.id as id_estudiante, e.nie,
    CONCAT(p.primer_nombre, ' ', p.primer_apellido) as nombre_estudiante,
    g.nombre as grado, g.nivel, g.nota_minima_aprobacion,
    s.nombre as seccion,
    asig.nombre as asignatura, asig.codigo,
    
    -- Obtener notas por área (simulando Area 1, Area 2, Area 3)
    -- En producción, esto vendría de tbl_actividad agrupadas por tipo/área
    AVG(CASE WHEN act.tipo IN ('tarea', 'laboratorio') THEN ea.nota_obtenida END) as area1_prom,
    AVG(CASE WHEN act.tipo IN ('proyecto', 'foro') THEN ea.nota_obtenida END) as area2_prom,
    AVG(CASE WHEN act.tipo = 'examen' THEN ea.nota_obtenida END) as examen_prom,
    
    -- Contar actividades por área
    COUNT(DISTINCT CASE WHEN act.tipo IN ('tarea', 'laboratorio') THEN act.id END) as area1_actividades,
    COUNT(DISTINCT CASE WHEN act.tipo IN ('proyecto', 'foro') THEN act.id END) as area2_actividades,
    COUNT(DISTINCT CASE WHEN act.tipo = 'examen' THEN act.id END) as examen_actividades,
    
    -- Asistencia
    COUNT(DISTINCT CASE WHEN ast.estado = 'presente' THEN ast.id END) as asistencias,
    COUNT(DISTINCT ast.id) as total_asistencias,
    
    -- Promedio general
    AVG(ea.nota_obtenida) as promedio_general,
    
    -- Estado de aprobación
    CASE WHEN AVG(ea.nota_obtenida) >= g.nota_minima_aprobacion THEN 'Aprobado' ELSE 'Reprobado' END as estado

FROM tbl_matricula m
JOIN tbl_estudiante e ON m.id_estudiante = e.id
JOIN tbl_persona p ON e.id_persona = p.id
JOIN tbl_seccion s ON m.id_seccion = s.id
JOIN tbl_grado g ON s.id_grado = g.id
JOIN tbl_asignacion_docente ad ON s.id = ad.id_seccion AND m.anno = ad.anno
JOIN tbl_asignatura asig ON ad.id_asignatura = asig.id
LEFT JOIN tbl_actividad act ON ad.id = act.id_asignacion_docente AND ad.id_periodo = :periodo
LEFT JOIN tbl_entrega_actividad ea ON act.id = ea.id_actividad AND m.id = ea.id_matricula
LEFT JOIN tbl_asistencia ast ON m.id = ast.id_matricula AND ast.fecha BETWEEN 
    CASE :periodo WHEN 1 THEN CONCAT(:anno, '-01-01') WHEN 2 THEN CONCAT(:anno, '-04-01') WHEN 3 THEN CONCAT(:anno, '-07-01') ELSE CONCAT(:anno, '-10-01') END
    AND 
    CASE :periodo WHEN 1 THEN CONCAT(:anno, '-03-31') WHEN 2 THEN CONCAT(:anno, '-06-30') WHEN 3 THEN CONCAT(:anno, '-09-30') ELSE CONCAT(:anno, '-12-31') END
WHERE m.anno = :anno
AND m.id_periodo = :periodo
AND m.estado = 'activo'
AND ad.id_asignatura = :asignatura
AND g.id_institucion = :tid";

$params = [':anno' => $anno, ':periodo' => $periodo, ':asignatura' => $asignatura, ':tid' => $tid];

if ($grado) { $query .= " AND g.id = :grado"; $params[':grado'] = $grado; }
if ($seccion) { $query .= " AND s.id = :seccion"; $params[':seccion'] = $seccion; }

$query .= " GROUP BY m.id ORDER BY p.primer_apellido, p.primer_nombre";

$stmt = $db->prepare($query);
foreach ($params as $k => $v) $stmt->bindValue($k, $v);
$stmt->execute();
$estudiantes = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ===== GENERAR REPORTE SEGÚN FORMATO =====

if ($formato === 'excel') {
    generarExcel($estudiantes, $anno, $periodo, $incluir_asistencia, $incluir_observaciones);
} elseif ($formato === 'pdf') {
    generarPDF($estudiantes, $anno, $periodo, $incluir_asistencia, $incluir_observaciones);
} else {
    generarHTML($estudiantes, $anno, $periodo, $incluir_asistencia, $incluir_observaciones);
}

// ===== FUNCIONES DE GENERACIÓN =====

function generarExcel($datos, $anno, $periodo, $asistencia, $observaciones) {
    // Headers para descarga de Excel
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="Reporte_Notas_Bachillerato_' . $anno . '_P' . $periodo . '.xlsx"');
    header('Cache-Control: max-age=0');
    
    // Crear nuevo objeto PHPExcel (o usar PhpSpreadsheet)
    require '../../../vendor/autoload.php';
    
    $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
    $sheet = $spreadsheet->getActiveSheet();
    
    // ===== HOJA 1: PERÍODO ACTUAL =====
    $sheet->setTitle('PERIODO ' . $periodo);
    
    // Encabezados del reporte
    $sheet->setCellValue('A1', 'REPORTE DE NOTAS - BACHILLERATO');
    $sheet->mergeCells('A1:Q1');
    $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);
    $sheet->getStyle('A1')->getAlignment()->setHorizontal('center');
    
    $sheet->setCellValue('A2', 'Año Lectivo: ' . $anno);
    $sheet->setCellValue('B2', 'Período: ' . $periodo);
    $sheet->setCellValue('A3', 'Asignatura: ' . ($datos[0]['asignatura'] ?? ''));
    $sheet->mergeCells('A3:D3');
    
    // Espacio
    $sheet->getRowDimension(5)->setRowHeight(10);
    
    // Encabezados de áreas
    $sheet->setCellValue('C6', 'AREA 1');
    $sheet->mergeCells('C6:H6');
    $sheet->setCellValue('I6', 'AREA 2');
    $sheet->mergeCells('I6:N6');
    $sheet->setCellValue('O6', 'AREA 3');
    $sheet->mergeCells('O6:Q6');
    
    // Sub-encabezados
    $headers = ['No.', 'Estudiante', 'Act1', 'Act2', 'Act3', 'Act4', 'Sum', '35%', 'Act1', 'Act2', 'Act3', 'Act4', 'Sum', '35%', 'Examen', '30%', 'PROM'];
    $col = 'A';
    foreach ($headers as $header) {
        $sheet->setCellValue($col . '7', $header);
        $col++;
    }
    
    // Estilos de encabezado
    $sheet->getStyle('A7:Q7')->getFont()->setBold(true);
    $sheet->getStyle('A7:Q7')->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID);
    $sheet->getStyle('A7:Q7')->getFill()->getStartColor()->setARGB('FFD9E1F2');
    $sheet->getStyle('A7:Q7')->getAlignment()->setHorizontal('center');
    
    // Datos de estudiantes
    $row = 8;
    $num = 1;
    foreach ($datos as $est) {
        $sheet->setCellValue('A' . $row, $num);
        $sheet->setCellValue('B' . $row, $est['nombre_estudiante']);
        
        // AREA 1 - Actividades simuladas (en producción, consultar actividades reales)
        for ($i = 0; $i < 4; $i++) {
            $sheet->setCellValue(chr(67 + $i) . $row, rand(7, 10)); // Valores de ejemplo
        }
        // ✅ Corregido: paréntesis mal colocado rompía la sintaxis del archivo
        // (faltaba cerrar la fórmula de Excel antes de cerrar la llamada a PHP).
        $sheet->setCellValue('G' . $row, '=SUM(C' . $row . ':F' . $row . ')');
        $sheet->setCellValue('H' . $row, '=G' . $row . '*0.35/4');

        // AREA 2
        for ($i = 0; $i < 4; $i++) {
            $sheet->setCellValue(chr(73 + $i) . $row, rand(7, 10));
        }
        $sheet->setCellValue('N' . $row, '=SUM(I' . $row . ':M' . $row . ')');
        $sheet->setCellValue('O' . $row, '=N' . $row . '*0.35/4');
        
        // AREA 3 / EXAMEN
        $sheet->setCellValue('P' . $row, rand(7, 10));
        $sheet->setCellValue('Q' . $row, '=H' . $row . '+O' . $row . '+(P' . $row . '*0.30)');
        
        // Formato de número para notas
        $sheet->getStyle('C' . $row . ':Q' . $row)->getNumberFormat()->setFormatCode('0.00');
        
        $row++;
        $num++;
    }
    
    // Ajustar columnas
    foreach (range('A', 'Q') as $col) {
        $sheet->getColumnDimension($col)->setAutoSize(true);
    }
    
    // ===== HOJA 2: CONSOLIDADO (si es período "todos") =====
    if ($periodo === 'todos') {
        $sheet2 = $spreadsheet->createSheet();
        $sheet2->setTitle('CONSOLIDADO');
        // ... lógica similar para consolidado ...
    }
    
    // Guardar y enviar al navegador
    $writer = \PhpOffice\PhpSpreadsheet\IOFactory::createWriter($spreadsheet, 'Xlsx');
    $writer->save('php://output');
    exit;
}

function generarPDF($datos, $anno, $periodo, $asistencia, $observaciones) {
    // Implementar con TCPDF o Dompdf
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="Reporte_Notas.pdf"');
    
    echo "PDF Generation - Format similar to Excel";
    exit;
}

function generarHTML($datos, $anno, $periodo, $asistencia, $observaciones) {
    // Generar HTML para imprimir
    ?>
    <!DOCTYPE html>
    <html>
    <head>
        <meta charset="UTF-8">
        <title>Reporte de Notas - Bachillerato</title>
        <style>
            body { font-family: Arial, sans-serif; font-size: 10px; }
            table { border-collapse: collapse; width: 100%; }
            th, td { border: 1px solid #000; padding: 4px; text-align: center; }
            th { background: #f0f0f0; font-weight: bold; }
            .area-header { background: #e0e0ff; font-weight: bold; }
            .prom-header { background: #ffe0e0; }
            @media print { body { font-size: 9px; } }
        </style>
    </head>
    <body>
        <h2 style="text-align:center">REPORTE DE NOTAS - BACHILLERATO</h2>
        <p style="text-align:center">Año: <?= $anno ?> | Período: <?= $periodo ?> | Asignatura: <?= $datos[0]['asignatura'] ?? '' ?></p>
        
        <table>
            <thead>
                <tr>
                    <th rowspan="2">No.</th>
                    <th rowspan="2">Estudiante</th>
                    <th colspan="6" class="area-header">AREA 1</th>
                    <th colspan="6" class="area-header">AREA 2</th>
                    <th colspan="3" class="area-header">AREA 3</th>
                    <th rowspan="2" class="prom-header">PROM</th>
                </tr>
                <tr>
                    <?php for ($i = 1; $i <= 4; $i++): ?><th>Act<?= $i ?></th><?php endfor; ?>
                    <th>Sum</th><th>35%</th>
                    <?php for ($i = 1; $i <= 4; $i++): ?><th>Act<?= $i ?></th><?php endfor; ?>
                    <th>Sum</th><th>35%</th>
                    <th>Act1</th><th>Act2</th><th>30%</th>
                </tr>
            </thead>
            <tbody>
                <?php $num = 1; foreach ($datos as $est): ?>
                <tr>
                    <td><?= $num++ ?></td>
                    <td style="text-align:left"><?= htmlspecialchars($est['nombre_estudiante']) ?></td>
                    <!-- AREA 1 -->
                    <?php for ($i = 0; $i < 4; $i++): ?><td><?= rand(7,10) ?></td><?php endfor; ?>
                    <td>-</td><td>-</td>
                    <!-- AREA 2 -->
                    <?php for ($i = 0; $i < 4; $i++): ?><td><?= rand(7,10) ?></td><?php endfor; ?>
                    <td>-</td><td>-</td>
                    <!-- AREA 3 -->
                    <td><?= rand(7,10) ?></td><td>-</td><td>-</td>
                    <!-- PROMEDIO -->
                    <td class="prom-header"><?= number_format($est['promedio_general'] ?? 0, 2) ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        
        <script>window.print();</script>
    </body>
    </html>
    <?php
    exit;
}
?>