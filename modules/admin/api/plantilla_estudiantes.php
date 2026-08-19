<?php
session_start();
require_once __DIR__ . '/../../../config/database.php';

// Verificar autenticación
if (!isset($_SESSION['user_id']) || ($_SESSION['rol'] != 'admin' && $_SESSION['rol'] != 'director')) {
    http_response_code(403);
    echo 'No autorizado';
    exit;
}

require_once __DIR__ . '/../../../config/TenantGuard.php';
require_once __DIR__ . '/../../../libs/simplexlsx/SimpleXLSXGen.php';

use Shuchkin\SimpleXLSXGen;

$tid = TenantGuard::id();
$database = new Database();
$db = $database->getConnection();

// Grados/Secciones que esta institución ya tiene creados -- son los únicos
// valores válidos para las columnas opcionales Grado/Sección al matricular
// desde la plantilla (no los 15 del catálogo completo, sino solo los que
// tienen sección propia en esta institución, igual que en gestionar_estudiantes.php).
$stmt = $db->prepare("SELECT g.nombre as grado, s.nombre as seccion, s.anno_lectivo
                       FROM tbl_seccion s
                       JOIN tbl_grado g ON s.id_grado = g.id
                       WHERE s.id_institucion = :tid
                       ORDER BY g.nivel, g.nombre, s.nombre");
$stmt->execute([':tid' => $tid]);
$secciones_disponibles = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ===== HOJA 1: Instrucciones =====
$instrucciones = [
    ['Cómo usar esta plantilla para importar estudiantes'],
    [''],
    ['1. Llena la hoja "Estudiantes" (la segunda pestaña, abajo). Cada fila es un estudiante.'],
    ['2. Borra la fila de ejemplo antes de subir el archivo.'],
    ['3. Columnas obligatorias: NIE, Primer Nombre, Primer Apellido. Las demás son opcionales.'],
    ['4. Fecha Nacimiento: escríbela como texto en formato AAAA-MM-DD, por ejemplo 2010-05-14.'],
    ['5. Sexo: solo M o F. Trabaja: solo Si o No.'],
    ['6. Estado Familiar: "Convive con ambos padres", "Convive con madre", "Convive con padre" u "Otros".'],
    ['7. Usuario y Contraseña son opcionales. Si los dejas en blanco, el sistema genera unos automáticamente'],
    ['   y al final de la importación te los muestra para que se los entregues al estudiante.'],
    ['8. Grado, Sección y Año son opcionales. Si llenas los tres, el estudiante queda matriculado de una vez.'],
    ['   Si los dejas en blanco, el estudiante se crea sin matrícula y la matriculas después en "Matrículas".'],
    ['9. El Grado y la Sección deben coincidir EXACTAMENTE (mismas mayúsculas/tildes) con alguna combinación'],
    ['   de la lista de la tercera pestaña "Grados y secciones disponibles". Si no existe la sección que necesitas,'],
    ['   créala primero en "Grados/Secciones" y luego vuelve a esta plantilla.'],
    ['10. Si una fila tiene un error (NIE duplicado, sección que no existe, etc.) esa fila específica no se'],
    ['    importa, pero el resto de filas válidas sí -- al final se muestra un reporte fila por fila.'],
    ['11. Si algún NIE, DUI o teléfono empieza con 0, selecciona esa columna en Excel y cambia su formato a'],
    ['    "Texto" antes de escribir los datos, para que Excel no borre el cero inicial.'],
];

$header = [
    'NIE *', 'Primer Nombre *', 'Segundo Nombre', 'Tercer Nombre', 'Primer Apellido *', 'Segundo Apellido',
    'DUI', 'Fecha Nacimiento (AAAA-MM-DD)', 'Sexo (M/F)', 'Email', 'Celular', 'Teléfono Fijo',
    'Nacionalidad', 'Dirección', 'Estado Familiar', 'Discapacidad', 'Trabaja (Si/No)',
    'Usuario', 'Contraseña', 'Grado', 'Sección', 'Año',
];
$ejemplo = [
    '20260001', 'María José', 'Elena', '', 'Hernández', 'López',
    '01234567-8', '2010-05-14', 'F', 'maria.hernandez@example.com', '78901234', '',
    'Salvadoreña', 'Col. Ejemplo, San Salvador', 'Convive con ambos padres', 'Ninguna', 'No',
    '', '', 'Séptimo', 'A', (string) date('Y'),
];
$estudiantes = [$header, $ejemplo];

// ===== HOJA 3: Grados y secciones disponibles (referencia, solo lectura) =====
$refGrados = [['Grado', 'Sección', 'Año Lectivo']];
if (empty($secciones_disponibles)) {
    $refGrados[] = ['(Esta institución todavía no tiene ninguna sección creada. Ve a "Grados/Secciones" para crear una antes de matricular por esta vía.)'];
} else {
    foreach ($secciones_disponibles as $s) {
        $refGrados[] = [$s['grado'], $s['seccion'], (string) $s['anno_lectivo']];
    }
}

$xlsx = SimpleXLSXGen::fromArray($instrucciones, 'Instrucciones');
$xlsx->setColWidth('A', 100);

$xlsx->addSheet($estudiantes, 'Estudiantes');
$xlsx->setColWidth('A:V', 20);
$xlsx->freezePanes('A2');

$xlsx->addSheet($refGrados, 'Grados y secciones disponibles');
$xlsx->setColWidth('A:C', 22);

$xlsx->downloadAs('plantilla_importar_estudiantes.xlsx');
