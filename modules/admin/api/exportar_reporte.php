<?php
// api/exportar_reporte.php
session_start();
require_once __DIR__ . '/../../../config/database.php';

if (!isset($_SESSION['user_id']) || !in_array($_SESSION['rol'], ['admin', 'director'])) {
    exit('No autorizado');
}

$tipo = $_GET['tipo'] ?? 'resumen';
$anno = $_GET['anno'] ?? date('Y');

// Configurar headers para Excel
header('Content-Type: application/vnd.ms-excel');
header('Content-Disposition: attachment; filename="reporte_'.$tipo.'_'.$anno.'.xls"');

echo "Reporte General - Educación Plus\n";
echo "Año: $anno\nTipo: $tipo\n\n";

// Aquí iría la lógica para generar el Excel
// Puedes usar PhpSpreadsheet o simplemente output CSV

echo "ID\tNombre\tGrado\tSeccion\tDato\tValor\n";
// ... generar filas ...

exit;
?>