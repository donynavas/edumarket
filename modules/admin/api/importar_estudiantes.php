<?php
session_start();
require_once __DIR__ . '/../../../config/database.php';

header('Content-Type: application/json; charset=utf-8');

// Verificar autenticación
if (!isset($_SESSION['user_id']) || ($_SESSION['rol'] != 'admin' && $_SESSION['rol'] != 'director')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'No autorizado']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Método no permitido']);
    exit;
}

require_once __DIR__ . '/../../../config/TenantGuard.php';
require_once __DIR__ . '/../../../libs/simplexlsx/SimpleXLSX.php';

use Shuchkin\SimpleXLSX;

$tid = TenantGuard::id();
$database = new Database();
$db = $database->getConnection();

// ===== VALIDAR ARCHIVO SUBIDO =====
if (empty($_FILES['archivo']) || $_FILES['archivo']['error'] !== UPLOAD_ERR_OK) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'No se recibió ningún archivo, o hubo un error al subirlo.']);
    exit;
}

$archivo = $_FILES['archivo'];
$extension = strtolower(pathinfo($archivo['name'], PATHINFO_EXTENSION));
if ($extension !== 'xlsx') {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'El archivo debe ser un Excel .xlsx (usa la plantilla descargada desde esta pantalla).']);
    exit;
}

if ($archivo['size'] > 5 * 1024 * 1024) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'El archivo es demasiado grande (máximo 5 MB).']);
    exit;
}

$xlsx = SimpleXLSX::parse($archivo['tmp_name']);
if (!$xlsx) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'No se pudo leer el archivo: ' . SimpleXLSX::parseError() . '. Asegúrate de subir un .xlsx válido (no lo guardes como .xls ni .csv).']);
    exit;
}

// La hoja de datos se llama "Estudiantes" en la plantilla oficial; si el
// usuario renombró o reordenó las pestañas, la buscamos por nombre y si no
// aparece, usamos la segunda hoja (índice 1) que es donde vive en la
// plantilla, y como último recurso la primera.
$indiceHoja = 0;
$nombresHojas = $xlsx->sheetNames();
foreach ($nombresHojas as $i => $nombre) {
    if (mb_strtolower(trim($nombre)) === 'estudiantes') {
        $indiceHoja = $i;
        break;
    }
    if ($i === 1) {
        $indiceHoja = 1; // valor por defecto si no se encuentra por nombre
    }
}

$filas = $xlsx->rows($indiceHoja);
if (empty($filas)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'La hoja "Estudiantes" está vacía.']);
    exit;
}

$header = array_map(fn($v) => trim((string) $v), $filas[0]);
$col0 = mb_strtolower($header[0] ?? '');
$col1 = mb_strtolower($header[1] ?? '');
if (count($header) < 22 || strpos($col0, 'nie') === false || strpos($col1, 'primer nombre') === false) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'El formato de la hoja no coincide con la plantilla oficial. Descárgala de nuevo con el botón "Descargar plantilla" y no cambies el orden de las columnas.']);
    exit;
}

// ===== HELPERS =====

function celda($fila, $idx) {
    $v = $fila[$idx] ?? '';
    if ($v === null) return '';
    return trim((string) $v);
}

// Genera un usuario legible (sin tildes/espacios) a partir del nombre; si
// queda vacío (nombre con solo caracteres raros) usa el NIE como respaldo.
function sugerirUsuario($primerNombre, $primerApellido, $nie) {
    $mapa = [
        'á'=>'a','é'=>'e','í'=>'i','ó'=>'o','ú'=>'u','ñ'=>'n','ü'=>'u',
        'Á'=>'a','É'=>'e','Í'=>'i','Ó'=>'o','Ú'=>'u','Ñ'=>'n','Ü'=>'u',
    ];
    $base = strtr(mb_strtolower(trim($primerNombre . '.' . $primerApellido)), $mapa);
    $base = preg_replace('/[^a-z0-9.]/', '', $base);
    $base = trim($base, '.');
    if ($base === '') {
        $base = 'est' . preg_replace('/[^a-zA-Z0-9]/', '', $nie);
    }
    return $base;
}

function claveAleatoria(int $largo = 10): string {
    // Sin caracteres ambiguos (0/O, 1/l/I) para que sea fácil de transcribir a mano.
    $charset = 'ABCDEFGHJKMNPQRSTUVWXYZabcdefghjkmnpqrstuvwxyz23456789';
    $clave = '';
    for ($i = 0; $i < $largo; $i++) {
        $clave .= $charset[random_int(0, strlen($charset) - 1)];
    }
    return $clave;
}

// ===== CATÁLOGO DE SECCIONES DE ESTA INSTITUCIÓN (para resolver Grado/Sección) =====
$stmtSec = $db->prepare("SELECT s.id, s.anno_lectivo, g.nombre as grado, s.nombre as seccion
                          FROM tbl_seccion s
                          JOIN tbl_grado g ON s.id_grado = g.id
                          WHERE s.id_institucion = :tid");
$stmtSec->execute([':tid' => $tid]);
$seccionesPorClave = [];
foreach ($stmtSec->fetchAll(PDO::FETCH_ASSOC) as $s) {
    $clave = mb_strtolower(trim($s['grado'])) . '|' . mb_strtolower(trim($s['seccion'])) . '|' . trim((string) $s['anno_lectivo']);
    $seccionesPorClave[$clave] = (int) $s['id'];
}

// ===== PROCESAR FILAS =====
const MAX_FILAS = 1000;
$totalFilasHoja = count($filas) - 1;
$limitado = $totalFilasHoja > MAX_FILAS;

$reporte = [];
$creados = 0;
$conErrores = 0;
$omitidas = 0; // filas completamente vacías, ni creadas ni con error -- para que los contadores cuadren
$niesEnLote = [];     // detecta NIE repetido dentro del mismo archivo
$usuariosEnLote = [];  // detecta usuario repetido/generado dentro del mismo archivo

for ($f = 1; $f < count($filas); $f++) {
    if ($f > MAX_FILAS) break; // ver aviso $limitado más abajo
    $filaExcel = $f + 1; // +1 porque la fila 1 de Excel es el encabezado
    $fila = $filas[$f];

    $nie = strtoupper(celda($fila, 0));
    $primerNombre = celda($fila, 1);
    $segundoNombre = celda($fila, 2);
    $tercerNombre = celda($fila, 3);
    $primerApellido = celda($fila, 4);
    $segundoApellido = celda($fila, 5);
    $dui = celda($fila, 6);
    $fechaNacimiento = celda($fila, 7);
    $sexo = strtoupper(celda($fila, 8));
    $email = celda($fila, 9);
    $celular = celda($fila, 10);
    $telefonoFijo = celda($fila, 11);
    $nacionalidad = celda($fila, 12);
    $direccion = celda($fila, 13);
    $estadoFamiliar = celda($fila, 14);
    $discapacidad = celda($fila, 15);
    $trabajaTxt = mb_strtolower(celda($fila, 16));
    $usuario = celda($fila, 17);
    $clave = celda($fila, 18);
    $grado = celda($fila, 19);
    $seccion = celda($fila, 20);
    $anno = celda($fila, 21);

    // Fila completamente vacía (p.ej. filas de relleno al final de la
    // plantilla) -- se ignora sin reportarla como error.
    if ($nie === '' && $primerNombre === '' && $primerApellido === '') {
        $omitidas++;
        continue;
    }

    // ---- Validaciones de campos obligatorios ----
    $errores = [];
    if ($nie === '') $errores[] = 'falta el NIE';
    if ($primerNombre === '') $errores[] = 'falta el Primer Nombre';
    if ($primerApellido === '') $errores[] = 'falta el Primer Apellido';

    if ($sexo !== '' && !in_array($sexo, ['M', 'F'], true)) {
        $errores[] = 'Sexo debe ser M, F o quedar vacío';
    }

    $trabaja = 0;
    if (in_array($trabajaTxt, ['si', 'sí', '1', 'true'], true)) {
        $trabaja = 1;
    } elseif ($trabajaTxt !== '' && !in_array($trabajaTxt, ['no', '0', 'false'], true)) {
        $errores[] = 'Trabaja debe ser "Si", "No" o quedar vacío';
    }

    if ($fechaNacimiento !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}/', $fechaNacimiento)) {
        $errores[] = 'Fecha Nacimiento debe tener formato AAAA-MM-DD';
    } else if ($fechaNacimiento !== '') {
        // SimpleXLSX puede devolver fechas como "AAAA-MM-DD HH:MM:SS" aunque
        // la celda se haya escrito solo con la fecha -- se recorta a la fecha.
        $fechaNacimiento = substr($fechaNacimiento, 0, 10);
    }

    if ($clave !== '' && strlen($clave) < 6) {
        $errores[] = 'la Contraseña debe tener al menos 6 caracteres (o déjala vacía para autogenerarla)';
    }

    // NIE duplicado (en el archivo o ya existente en la base de datos)
    if ($nie !== '') {
        if (isset($niesEnLote[$nie])) {
            $errores[] = "el NIE ya aparece en la fila {$niesEnLote[$nie]} de este mismo archivo";
        } else {
            $check = $db->prepare("SELECT id FROM tbl_estudiante WHERE nie = :nie");
            $check->execute([':nie' => $nie]);
            if ($check->fetch()) {
                $errores[] = 'el NIE ya está registrado en el sistema';
            }
        }
    }

    if (!empty($errores)) {
        $conErrores++;
        $reporte[] = [
            'fila' => $filaExcel,
            'nie' => $nie,
            'nombre' => trim("$primerNombre $primerApellido"),
            'estado' => 'error',
            'mensaje' => 'No se creó: ' . implode('; ', $errores) . '.',
            'matricula' => null,
        ];
        continue;
    }
    $niesEnLote[$nie] = $filaExcel;

    // ---- Usuario/contraseña: usar los de la hoja o autogenerar ----
    $usuarioGenerado = false;
    if ($usuario === '') {
        $usuario = sugerirUsuario($primerNombre, $primerApellido, $nie);
        $usuarioGenerado = true;
    }
    // Asegurar unicidad del usuario (en el archivo y en la base de datos),
    // igual si vino de la hoja que si se autogeneró.
    $usuarioBase = $usuario;
    $sufijo = 1;
    while (true) {
        $yaEnLote = isset($usuariosEnLote[mb_strtolower($usuario)]);
        $check = $db->prepare("SELECT id FROM tbl_usuario WHERE usuario = :u");
        $check->execute([':u' => $usuario]);
        $yaEnBD = (bool) $check->fetch();
        if (!$yaEnLote && !$yaEnBD) break;
        if (!$usuarioGenerado) {
            // El admin especificó este usuario a propósito -- no lo alteramos en silencio.
            $conErrores++;
            $reporte[] = [
                'fila' => $filaExcel,
                'nie' => $nie,
                'nombre' => trim("$primerNombre $primerApellido"),
                'estado' => 'error',
                'mensaje' => "No se creó: el usuario \"$usuarioBase\" ya está en uso.",
                'matricula' => null,
            ];
            continue 2; // pasa a la siguiente fila del for exterior
        }
        $sufijo++;
        $usuario = $usuarioBase . $sufijo;
    }
    $usuariosEnLote[mb_strtolower($usuario)] = true;

    $claveGenerada = false;
    if ($clave === '') {
        $clave = claveAleatoria();
        $claveGenerada = true;
    }

    // ---- Resolver Grado/Sección/Año para matrícula (opcional) ----
    $idSeccion = null;
    $mensajeMatricula = 'No solicitada (deja Grado/Sección/Año vacíos si no quieres matricular todavía).';
    $llenos = ($grado !== '') + ($seccion !== '') + ($anno !== '');
    if ($llenos === 3) {
        $clave3 = mb_strtolower($grado) . '|' . mb_strtolower($seccion) . '|' . $anno;
        if (isset($seccionesPorClave[$clave3])) {
            $idSeccion = $seccionesPorClave[$clave3];
            $mensajeMatricula = null; // se define después de insertar
        } else {
            $mensajeMatricula = "No se pudo matricular: no existe la sección \"$grado\" \"$seccion\" año $anno en esta institución (revisa la pestaña \"Grados y secciones disponibles\"). El estudiante sí se creó.";
        }
    } elseif ($llenos > 0) {
        $mensajeMatricula = 'No se pudo matricular: llena Grado, Sección y Año los tres, o déjalos los tres vacíos. El estudiante sí se creó.';
    }

    // ---- Crear estudiante (usuario + persona + estudiante), en su propia transacción ----
    try {
        $db->beginTransaction();

        $passwordHash = password_hash($clave, PASSWORD_DEFAULT);
        $stmt = $db->prepare("INSERT INTO tbl_usuario (nombre, usuario, password, email, rol, estado, id_institucion)
                               VALUES (:nombre, :usuario, :password, :email, 'estudiante', 1, :tid)");
        $stmt->execute([
            ':nombre' => trim("$primerNombre $primerApellido"),
            ':usuario' => $usuario,
            ':password' => $passwordHash,
            ':email' => $email,
            ':tid' => $tid,
        ]);
        $idUsuario = $db->lastInsertId();

        $stmt = $db->prepare("INSERT INTO tbl_persona (primer_nombre, segundo_nombre, tercer_nombre,
                               primer_apellido, segundo_apellido, dui, fecha_nacimiento, sexo,
                               nacionalidad, direccion, telefono_fijo, celular, email, id_usuario)
                               VALUES (:p_nombre, :s_nombre, :t_nombre, :p_apellido, :s_apellido,
                                       :dui, :fecha_nac, :sexo, :nacionalidad, :direccion,
                                       :tel_fijo, :celular, :email, :id_usuario)");
        $stmt->execute([
            ':p_nombre' => $primerNombre,
            ':s_nombre' => $segundoNombre,
            ':t_nombre' => $tercerNombre, // tbl_persona.tercer_nombre es NOT NULL -- '' es válido, null no
            ':p_apellido' => $primerApellido,
            ':s_apellido' => $segundoApellido,
            ':dui' => $dui,
            ':fecha_nac' => $fechaNacimiento ?: null,
            ':sexo' => $sexo ?: null,
            ':nacionalidad' => $nacionalidad ?: 'Salvadoreña',
            ':direccion' => $direccion,
            ':tel_fijo' => $telefonoFijo,
            ':celular' => $celular,
            ':email' => $email,
            ':id_usuario' => $idUsuario,
        ]);
        $idPersona = $db->lastInsertId();

        $stmt = $db->prepare("INSERT INTO tbl_estudiante (id_persona, nie, estado_familiar, discapacidad, trabaja, id_institucion)
                               VALUES (:id_persona, :nie, :estado_familiar, :discapacidad, :trabaja, :tid)");
        $stmt->execute([
            ':id_persona' => $idPersona,
            ':nie' => $nie,
            ':estado_familiar' => $estadoFamiliar,
            ':discapacidad' => $discapacidad ?: 'Ninguna',
            ':trabaja' => $trabaja,
            ':tid' => $tid,
        ]);
        $idEstudiante = $db->lastInsertId();

        $db->commit();
    } catch (PDOException $e) {
        $db->rollBack();
        $conErrores++;
        $msg = ($e->errorInfo[1] ?? null) == 1062
            ? 'Registro duplicado (NIE o usuario ya existente).'
            : ('Error de base de datos: ' . $e->getMessage());
        $reporte[] = [
            'fila' => $filaExcel,
            'nie' => $nie,
            'nombre' => trim("$primerNombre $primerApellido"),
            'estado' => 'error',
            'mensaje' => "No se creó: $msg",
            'matricula' => null,
        ];
        continue;
    }

    // ---- Matricular, si se resolvió una sección válida ----
    // Deliberadamente FUERA de la transacción del estudiante: si esto falla,
    // el estudiante ya creado no debe perderse (ver decisión del plan: crear
    // siempre que se pueda, y solo la matrícula queda pendiente si algo falla).
    if ($idSeccion !== null) {
        try {
            $stmt = $db->prepare("INSERT INTO tbl_matricula (id_estudiante, id_seccion, anno, estado)
                                   VALUES (:id_estudiante, :id_seccion, :anno, 'activo')");
            $stmt->execute([
                ':id_estudiante' => $idEstudiante,
                ':id_seccion' => $idSeccion,
                ':anno' => $anno,
            ]);
            $mensajeMatricula = "Matriculado en $grado \"$seccion\" ($anno).";
        } catch (PDOException $e) {
            $mensajeMatricula = 'El estudiante se creó, pero la matrícula falló: ' . $e->getMessage() . ' (puedes matricularlo manualmente en "Matrículas").';
        }
    }

    $creados++;
    $reporte[] = [
        'fila' => $filaExcel,
        'nie' => $nie,
        'nombre' => trim("$primerNombre $primerApellido"),
        'estado' => 'creado',
        'mensaje' => 'Estudiante creado correctamente.',
        'matricula' => $mensajeMatricula,
        'usuario' => $usuario,
        'clave_generada' => $claveGenerada ? $clave : null,
        'usuario_generado' => $usuarioGenerado,
    ];
}

echo json_encode([
    'success' => true,
    'total_filas' => $totalFilasHoja,
    'creados' => $creados,
    'con_errores' => $conErrores,
    'omitidas' => $omitidas,
    'limitado' => $limitado,
    'max_filas' => MAX_FILAS,
    'detalle' => $reporte,
], JSON_UNESCAPED_UNICODE);
