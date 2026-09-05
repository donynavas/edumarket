<?php
/**
 * Expediente Docente -- hoja de vida esencial del profesor (lado director).
 *
 * Alcance decidido con el usuario: NO replica la estructura burocrática
 * completa del CV Docente de MINEDUCYT (NUP/INPEP/IPSFA/ISBM, escalafón,
 * concurso de plazas en propiedad, cotización de seguridad social, etc.),
 * sino una hoja de vida interna simplificada: datos de contacto de
 * emergencia + foto, Estudios Académicos, Formación Continua, Experiencia
 * Laboral (las 3 repetibles, cada una con documento adjunto opcional), y
 * una lista libre de Documentos Adjuntos.
 *
 * Se llega aquí SIEMPRE con un ?id_profesor= de contexto desde el botón
 * "Expediente" de gestionar_profesores.php -- no existe una vista general
 * de "todos los expedientes" (mismo criterio que "Asignar Materias" en esa
 * misma pantalla, que tampoco tiene entrada de nav propia).
 *
 * Patrón CRUD: row-by-row con POST + recarga de página (modelo:
 * modules/admin/manual_convivencia.php, comité de convivencia), no
 * AJAX/JSON -- es una página de pestañas CRUD simple, y POST estándar
 * multipart/form-data maneja $_FILES sin complicaciones de FormData/fetch.
 *
 * Vista Previa/Impresión: mismo patrón .hoja + @media print que el resto
 * del proyecto (no hay librería de PDF instalada; el usuario usa "Guardar
 * como PDF" del diálogo de impresión del navegador).
 */
session_start();
require_once __DIR__ . '/../../config/database.php';

if (!isset($_SESSION['user_id']) || !in_array($_SESSION['rol'], ['admin', 'director'], true)) {
    header("Location: " . BASE_URL . "/login.php");
    exit;
}

require_once __DIR__ . '/../../config/TenantGuard.php';
require_once __DIR__ . '/../../config/CatalogoExpedienteDocente.php';
require_once __DIR__ . '/../../config/ExpedienteDocenteHelper.php';

$tid = TenantGuard::id();
$db = (new Database())->getConnection();

$idProfesor = (int) ($_GET['id_profesor'] ?? $_POST['id_profesor'] ?? 0);
if (!$idProfesor) {
    die('Profesor no especificado.');
}
// Gate único: protege TODOS los SELECT/INSERT/UPDATE/DELETE de esta
// página, porque todas las tablas hijas cuelgan de tbl_profesor y toda
// consulta más abajo se filtra por este $idProfesor ya verificado.
TenantGuard::assertOwner($db, 'tbl_profesor', $idProfesor);

$idExpediente = ExpedienteDocenteHelper::asegurarCabecera($db, $idProfesor);

$mensaje = '';
$tipo_mensaje = '';

// ===== PROCESAR ACCIONES POST =====
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $accion = $_POST['accion'] ?? '';

    try {
        $db->beginTransaction();

        match ($accion) {
            'actualizar_cabecera'     => actualizarCabecera($db, $idProfesor, $idExpediente),
            'crear_estudio'           => crearEstudio($db, $idProfesor),
            'actualizar_estudio'      => actualizarEstudio($db, $idProfesor),
            'eliminar_estudio'        => eliminarEstudio($db, $idProfesor),
            'crear_capacitacion'      => crearCapacitacion($db, $idProfesor),
            'actualizar_capacitacion' => actualizarCapacitacion($db, $idProfesor),
            'eliminar_capacitacion'   => eliminarCapacitacion($db, $idProfesor),
            'crear_experiencia'       => crearExperiencia($db, $idProfesor),
            'actualizar_experiencia'  => actualizarExperiencia($db, $idProfesor),
            'eliminar_experiencia'    => eliminarExperiencia($db, $idProfesor),
            'crear_documento'         => crearDocumento($db, $idProfesor),
            'actualizar_documento'    => actualizarDocumento($db, $idProfesor),
            'eliminar_documento'      => eliminarDocumento($db, $idProfesor),
            default => throw new Exception('Acción no válida'),
        };

        $db->commit();
    } catch (Exception $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        $mensaje = 'Error: ' . $e->getMessage();
        $tipo_mensaje = 'danger';
    }
}

// ===== FUNCIONES DE PROCESAMIENTO =====

function actualizarCabecera(PDO $db, int $idProfesor, int $idExpediente): void
{
    global $mensaje, $tipo_mensaje;

    $stmt = $db->prepare("SELECT foto_ruta FROM tbl_expediente_docente WHERE id = :id");
    $stmt->execute([':id' => $idExpediente]);
    $fotoActual = $stmt->fetchColumn();
    $fotoRuta = $fotoActual !== false ? $fotoActual : null;

    if (!empty($_FILES['foto']['name'])) {
        $nueva = ExpedienteDocenteHelper::validarYGuardarArchivo($_FILES['foto'], 'exp_foto_', ExpedienteDocenteHelper::MIMES_FOTO);
        ExpedienteDocenteHelper::borrarArchivoFisico($fotoRuta);
        $fotoRuta = $nueva;
    }

    $parentesco = $_POST['contacto_emergencia_parentesco'] ?? '';
    if ($parentesco !== '' && !in_array($parentesco, CatalogoExpedienteDocente::PARENTESCOS, true)) {
        throw new Exception('Parentesco no válido.');
    }

    $stmt = $db->prepare(
        "UPDATE tbl_expediente_docente SET
            foto_ruta = :foto, contacto_emergencia_nombre = :nombre, contacto_emergencia_telefono = :telefono,
            contacto_emergencia_parentesco = :parentesco, notas = :notas
         WHERE id = :id"
    );
    $stmt->execute([
        ':foto' => $fotoRuta,
        ':nombre' => trim($_POST['contacto_emergencia_nombre'] ?? '') ?: null,
        ':telefono' => trim($_POST['contacto_emergencia_telefono'] ?? '') ?: null,
        ':parentesco' => $parentesco ?: null,
        ':notas' => trim($_POST['notas'] ?? '') ?: null,
        ':id' => $idExpediente,
    ]);

    $mensaje = 'Datos personales del expediente guardados';
    $tipo_mensaje = 'success';
}

/** Verifica que la fila $id de $tabla exista y pertenezca a $idProfesor (defensa en profundidad además de assertOwner). */
function filaEsDelProfesor(PDO $db, string $tabla, int $id, int $idProfesor): array
{
    $stmt = $db->prepare("SELECT * FROM `$tabla` WHERE id = :id");
    $stmt->execute([':id' => $id]);
    $fila = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$fila || (int) $fila['id_profesor'] !== $idProfesor) {
        throw new Exception('El registro no pertenece a este docente.');
    }
    return $fila;
}

// ----- ESTUDIOS ACADÉMICOS -----

function crearEstudio(PDO $db, int $idProfesor): void
{
    global $mensaje, $tipo_mensaje;

    $grado = $_POST['grado_academico'] ?? '';
    $titulo = trim($_POST['titulo'] ?? '');
    $institucion = trim($_POST['institucion_educativa'] ?? '');
    if (!in_array($grado, CatalogoExpedienteDocente::GRADOS_ACADEMICOS, true)) {
        throw new Exception('Grado académico no válido.');
    }
    if ($titulo === '' || $institucion === '') {
        throw new Exception('Título e institución educativa son obligatorios.');
    }

    $rutaDocumento = null;
    if (!empty($_FILES['documento']['name'])) {
        $rutaDocumento = ExpedienteDocenteHelper::validarYGuardarArchivo($_FILES['documento'], 'exp_estudio_', ExpedienteDocenteHelper::MIMES_DOCUMENTO);
    }

    $stmtOrden = $db->prepare("SELECT COALESCE(MAX(orden), -1) + 1 FROM tbl_expediente_estudio WHERE id_profesor = :prof");
    $stmtOrden->execute([':prof' => $idProfesor]);
    $orden = (int) $stmtOrden->fetchColumn();

    $stmt = $db->prepare(
        "INSERT INTO tbl_expediente_estudio (id_profesor, grado_academico, titulo, institucion_educativa, anio_graduacion, ruta_documento, orden)
         VALUES (:prof, :grado, :titulo, :inst, :anio, :ruta, :orden)"
    );
    $stmt->execute([
        ':prof' => $idProfesor,
        ':grado' => $grado,
        ':titulo' => $titulo,
        ':inst' => $institucion,
        ':anio' => !empty($_POST['anio_graduacion']) ? (int) $_POST['anio_graduacion'] : null,
        ':ruta' => $rutaDocumento,
        ':orden' => $orden,
    ]);

    $mensaje = 'Estudio académico agregado';
    $tipo_mensaje = 'success';
}

function actualizarEstudio(PDO $db, int $idProfesor): void
{
    global $mensaje, $tipo_mensaje;

    $id = (int) ($_POST['id_estudio'] ?? 0);
    TenantGuard::assertOwner($db, 'tbl_expediente_estudio', $id);
    $fila = filaEsDelProfesor($db, 'tbl_expediente_estudio', $id, $idProfesor);

    $grado = $_POST['grado_academico'] ?? '';
    $titulo = trim($_POST['titulo'] ?? '');
    $institucion = trim($_POST['institucion_educativa'] ?? '');
    if (!in_array($grado, CatalogoExpedienteDocente::GRADOS_ACADEMICOS, true)) {
        throw new Exception('Grado académico no válido.');
    }
    if ($titulo === '' || $institucion === '') {
        throw new Exception('Título e institución educativa son obligatorios.');
    }

    $rutaDocumento = $fila['ruta_documento'];
    if (!empty($_FILES['documento']['name'])) {
        $nueva = ExpedienteDocenteHelper::validarYGuardarArchivo($_FILES['documento'], 'exp_estudio_', ExpedienteDocenteHelper::MIMES_DOCUMENTO);
        ExpedienteDocenteHelper::borrarArchivoFisico($rutaDocumento);
        $rutaDocumento = $nueva;
    }

    $stmt = $db->prepare(
        "UPDATE tbl_expediente_estudio SET grado_academico = :grado, titulo = :titulo, institucion_educativa = :inst,
            anio_graduacion = :anio, ruta_documento = :ruta WHERE id = :id"
    );
    $stmt->execute([
        ':grado' => $grado,
        ':titulo' => $titulo,
        ':inst' => $institucion,
        ':anio' => !empty($_POST['anio_graduacion']) ? (int) $_POST['anio_graduacion'] : null,
        ':ruta' => $rutaDocumento,
        ':id' => $id,
    ]);

    $mensaje = 'Estudio académico actualizado';
    $tipo_mensaje = 'success';
}

function eliminarEstudio(PDO $db, int $idProfesor): void
{
    global $mensaje, $tipo_mensaje;

    $id = (int) ($_POST['id_estudio'] ?? 0);
    TenantGuard::assertOwner($db, 'tbl_expediente_estudio', $id);
    $fila = filaEsDelProfesor($db, 'tbl_expediente_estudio', $id, $idProfesor);

    $db->prepare("DELETE FROM tbl_expediente_estudio WHERE id = :id")->execute([':id' => $id]);
    ExpedienteDocenteHelper::borrarArchivoFisico($fila['ruta_documento']);

    $mensaje = 'Estudio académico eliminado';
    $tipo_mensaje = 'warning';
}

// ----- FORMACIÓN CONTINUA / CAPACITACIONES -----

function crearCapacitacion(PDO $db, int $idProfesor): void
{
    global $mensaje, $tipo_mensaje;

    $institucion = trim($_POST['institucion'] ?? '');
    $nombre = trim($_POST['nombre_capacitacion'] ?? '');
    $anio = (int) ($_POST['anio'] ?? 0);
    if ($institucion === '' || $nombre === '' || !$anio) {
        throw new Exception('Institución, nombre de la capacitación y año son obligatorios.');
    }

    $rutaDocumento = null;
    if (!empty($_FILES['documento']['name'])) {
        $rutaDocumento = ExpedienteDocenteHelper::validarYGuardarArchivo($_FILES['documento'], 'exp_capacitacion_', ExpedienteDocenteHelper::MIMES_DOCUMENTO);
    }

    $stmtOrden = $db->prepare("SELECT COALESCE(MAX(orden), -1) + 1 FROM tbl_expediente_capacitacion WHERE id_profesor = :prof");
    $stmtOrden->execute([':prof' => $idProfesor]);
    $orden = (int) $stmtOrden->fetchColumn();

    $stmt = $db->prepare(
        "INSERT INTO tbl_expediente_capacitacion (id_profesor, institucion, nombre_capacitacion, anio, duracion_horas, ruta_documento, orden)
         VALUES (:prof, :inst, :nombre, :anio, :horas, :ruta, :orden)"
    );
    $stmt->execute([
        ':prof' => $idProfesor,
        ':inst' => $institucion,
        ':nombre' => $nombre,
        ':anio' => $anio,
        ':horas' => !empty($_POST['duracion_horas']) ? (int) $_POST['duracion_horas'] : null,
        ':ruta' => $rutaDocumento,
        ':orden' => $orden,
    ]);

    $mensaje = 'Capacitación agregada';
    $tipo_mensaje = 'success';
}

function actualizarCapacitacion(PDO $db, int $idProfesor): void
{
    global $mensaje, $tipo_mensaje;

    $id = (int) ($_POST['id_capacitacion'] ?? 0);
    TenantGuard::assertOwner($db, 'tbl_expediente_capacitacion', $id);
    $fila = filaEsDelProfesor($db, 'tbl_expediente_capacitacion', $id, $idProfesor);

    $institucion = trim($_POST['institucion'] ?? '');
    $nombre = trim($_POST['nombre_capacitacion'] ?? '');
    $anio = (int) ($_POST['anio'] ?? 0);
    if ($institucion === '' || $nombre === '' || !$anio) {
        throw new Exception('Institución, nombre de la capacitación y año son obligatorios.');
    }

    $rutaDocumento = $fila['ruta_documento'];
    if (!empty($_FILES['documento']['name'])) {
        $nueva = ExpedienteDocenteHelper::validarYGuardarArchivo($_FILES['documento'], 'exp_capacitacion_', ExpedienteDocenteHelper::MIMES_DOCUMENTO);
        ExpedienteDocenteHelper::borrarArchivoFisico($rutaDocumento);
        $rutaDocumento = $nueva;
    }

    $stmt = $db->prepare(
        "UPDATE tbl_expediente_capacitacion SET institucion = :inst, nombre_capacitacion = :nombre, anio = :anio,
            duracion_horas = :horas, ruta_documento = :ruta WHERE id = :id"
    );
    $stmt->execute([
        ':inst' => $institucion,
        ':nombre' => $nombre,
        ':anio' => $anio,
        ':horas' => !empty($_POST['duracion_horas']) ? (int) $_POST['duracion_horas'] : null,
        ':ruta' => $rutaDocumento,
        ':id' => $id,
    ]);

    $mensaje = 'Capacitación actualizada';
    $tipo_mensaje = 'success';
}

function eliminarCapacitacion(PDO $db, int $idProfesor): void
{
    global $mensaje, $tipo_mensaje;

    $id = (int) ($_POST['id_capacitacion'] ?? 0);
    TenantGuard::assertOwner($db, 'tbl_expediente_capacitacion', $id);
    $fila = filaEsDelProfesor($db, 'tbl_expediente_capacitacion', $id, $idProfesor);

    $db->prepare("DELETE FROM tbl_expediente_capacitacion WHERE id = :id")->execute([':id' => $id]);
    ExpedienteDocenteHelper::borrarArchivoFisico($fila['ruta_documento']);

    $mensaje = 'Capacitación eliminada';
    $tipo_mensaje = 'warning';
}

// ----- EXPERIENCIA LABORAL -----

function crearExperiencia(PDO $db, int $idProfesor): void
{
    global $mensaje, $tipo_mensaje;

    $institucion = trim($_POST['institucion'] ?? '');
    $cargo = trim($_POST['cargo'] ?? '');
    $desde = $_POST['fecha_desde'] ?? '';
    if ($institucion === '' || $cargo === '' || $desde === '') {
        throw new Exception('Institución, cargo y fecha de inicio son obligatorios.');
    }
    $hasta = $_POST['fecha_hasta'] ?: null;
    if ($hasta !== null && $hasta < $desde) {
        throw new Exception('La fecha final no puede ser anterior a la fecha de inicio.');
    }

    $rutaDocumento = null;
    if (!empty($_FILES['documento']['name'])) {
        $rutaDocumento = ExpedienteDocenteHelper::validarYGuardarArchivo($_FILES['documento'], 'exp_experiencia_', ExpedienteDocenteHelper::MIMES_DOCUMENTO);
    }

    $stmtOrden = $db->prepare("SELECT COALESCE(MAX(orden), -1) + 1 FROM tbl_expediente_experiencia WHERE id_profesor = :prof");
    $stmtOrden->execute([':prof' => $idProfesor]);
    $orden = (int) $stmtOrden->fetchColumn();

    $stmt = $db->prepare(
        "INSERT INTO tbl_expediente_experiencia (id_profesor, institucion, cargo, fecha_desde, fecha_hasta, ruta_documento, orden)
         VALUES (:prof, :inst, :cargo, :desde, :hasta, :ruta, :orden)"
    );
    $stmt->execute([
        ':prof' => $idProfesor,
        ':inst' => $institucion,
        ':cargo' => $cargo,
        ':desde' => $desde,
        ':hasta' => $hasta,
        ':ruta' => $rutaDocumento,
        ':orden' => $orden,
    ]);

    $mensaje = 'Experiencia laboral agregada';
    $tipo_mensaje = 'success';
}

function actualizarExperiencia(PDO $db, int $idProfesor): void
{
    global $mensaje, $tipo_mensaje;

    $id = (int) ($_POST['id_experiencia'] ?? 0);
    TenantGuard::assertOwner($db, 'tbl_expediente_experiencia', $id);
    $fila = filaEsDelProfesor($db, 'tbl_expediente_experiencia', $id, $idProfesor);

    $institucion = trim($_POST['institucion'] ?? '');
    $cargo = trim($_POST['cargo'] ?? '');
    $desde = $_POST['fecha_desde'] ?? '';
    if ($institucion === '' || $cargo === '' || $desde === '') {
        throw new Exception('Institución, cargo y fecha de inicio son obligatorios.');
    }
    $hasta = $_POST['fecha_hasta'] ?: null;
    if ($hasta !== null && $hasta < $desde) {
        throw new Exception('La fecha final no puede ser anterior a la fecha de inicio.');
    }

    $rutaDocumento = $fila['ruta_documento'];
    if (!empty($_FILES['documento']['name'])) {
        $nueva = ExpedienteDocenteHelper::validarYGuardarArchivo($_FILES['documento'], 'exp_experiencia_', ExpedienteDocenteHelper::MIMES_DOCUMENTO);
        ExpedienteDocenteHelper::borrarArchivoFisico($rutaDocumento);
        $rutaDocumento = $nueva;
    }

    $stmt = $db->prepare(
        "UPDATE tbl_expediente_experiencia SET institucion = :inst, cargo = :cargo, fecha_desde = :desde,
            fecha_hasta = :hasta, ruta_documento = :ruta WHERE id = :id"
    );
    $stmt->execute([
        ':inst' => $institucion,
        ':cargo' => $cargo,
        ':desde' => $desde,
        ':hasta' => $hasta,
        ':ruta' => $rutaDocumento,
        ':id' => $id,
    ]);

    $mensaje = 'Experiencia laboral actualizada';
    $tipo_mensaje = 'success';
}

function eliminarExperiencia(PDO $db, int $idProfesor): void
{
    global $mensaje, $tipo_mensaje;

    $id = (int) ($_POST['id_experiencia'] ?? 0);
    TenantGuard::assertOwner($db, 'tbl_expediente_experiencia', $id);
    $fila = filaEsDelProfesor($db, 'tbl_expediente_experiencia', $id, $idProfesor);

    $db->prepare("DELETE FROM tbl_expediente_experiencia WHERE id = :id")->execute([':id' => $id]);
    ExpedienteDocenteHelper::borrarArchivoFisico($fila['ruta_documento']);

    $mensaje = 'Experiencia laboral eliminada';
    $tipo_mensaje = 'warning';
}

// ----- DOCUMENTOS ADJUNTOS (lista libre) -----

function crearDocumento(PDO $db, int $idProfesor): void
{
    global $mensaje, $tipo_mensaje;

    $etiqueta = trim($_POST['etiqueta'] ?? '');
    if ($etiqueta === '') {
        throw new Exception('La etiqueta del documento es obligatoria.');
    }
    if (empty($_FILES['archivo']['name']) || ($_FILES['archivo']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        throw new Exception('Selecciona un archivo.');
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->file($_FILES['archivo']['tmp_name']);
    $tamano = (int) $_FILES['archivo']['size'];
    $ruta = ExpedienteDocenteHelper::validarYGuardarArchivo($_FILES['archivo'], 'exp_doc_', ExpedienteDocenteHelper::MIMES_DOCUMENTO);

    $stmt = $db->prepare(
        "INSERT INTO tbl_expediente_documento (id_profesor, etiqueta, ruta_archivo, mime, tamano_bytes)
         VALUES (:prof, :etiqueta, :ruta, :mime, :tamano)"
    );
    $stmt->execute([
        ':prof' => $idProfesor,
        ':etiqueta' => $etiqueta,
        ':ruta' => $ruta,
        ':mime' => $mime,
        ':tamano' => $tamano,
    ]);

    $mensaje = 'Documento adjuntado';
    $tipo_mensaje = 'success';
}

function actualizarDocumento(PDO $db, int $idProfesor): void
{
    global $mensaje, $tipo_mensaje;

    $id = (int) ($_POST['id_documento'] ?? 0);
    TenantGuard::assertOwner($db, 'tbl_expediente_documento', $id);
    $fila = filaEsDelProfesor($db, 'tbl_expediente_documento', $id, $idProfesor);

    $etiqueta = trim($_POST['etiqueta'] ?? '');
    if ($etiqueta === '') {
        throw new Exception('La etiqueta del documento es obligatoria.');
    }

    $ruta = $fila['ruta_archivo'];
    $mime = $fila['mime'];
    $tamano = $fila['tamano_bytes'];
    if (!empty($_FILES['archivo']['name'])) {
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->file($_FILES['archivo']['tmp_name']);
        $tamano = (int) $_FILES['archivo']['size'];
        $nueva = ExpedienteDocenteHelper::validarYGuardarArchivo($_FILES['archivo'], 'exp_doc_', ExpedienteDocenteHelper::MIMES_DOCUMENTO);
        ExpedienteDocenteHelper::borrarArchivoFisico($ruta);
        $ruta = $nueva;
    }

    $stmt = $db->prepare("UPDATE tbl_expediente_documento SET etiqueta = :etiqueta, ruta_archivo = :ruta, mime = :mime, tamano_bytes = :tamano WHERE id = :id");
    $stmt->execute([':etiqueta' => $etiqueta, ':ruta' => $ruta, ':mime' => $mime, ':tamano' => $tamano, ':id' => $id]);

    $mensaje = 'Documento actualizado';
    $tipo_mensaje = 'success';
}

function eliminarDocumento(PDO $db, int $idProfesor): void
{
    global $mensaje, $tipo_mensaje;

    $id = (int) ($_POST['id_documento'] ?? 0);
    TenantGuard::assertOwner($db, 'tbl_expediente_documento', $id);
    $fila = filaEsDelProfesor($db, 'tbl_expediente_documento', $id, $idProfesor);

    $db->prepare("DELETE FROM tbl_expediente_documento WHERE id = :id")->execute([':id' => $id]);
    ExpedienteDocenteHelper::borrarArchivoFisico($fila['ruta_archivo']);

    $mensaje = 'Documento eliminado';
    $tipo_mensaje = 'warning';
}

// ===== OBTENER DATOS PARA RENDER =====

$stmtProfesor = $db->prepare(
    "SELECT p.id, p.especialidad, p.titulo_academico, per.primer_nombre, per.segundo_nombre,
            per.primer_apellido, per.segundo_apellido, per.dui, per.email, per.celular,
            per.telefono_fijo, per.direccion
     FROM tbl_profesor p JOIN tbl_persona per ON p.id_persona = per.id
     WHERE p.id = :id"
);
$stmtProfesor->execute([':id' => $idProfesor]);
$profesor = $stmtProfesor->fetch(PDO::FETCH_ASSOC);
if (!$profesor) {
    die('Profesor no encontrado.');
}
$nombreCompleto = trim($profesor['primer_nombre'] . ' ' . $profesor['segundo_nombre'] . ' ' . $profesor['primer_apellido'] . ' ' . $profesor['segundo_apellido']);
$nombreCompleto = preg_replace('/\s+/', ' ', $nombreCompleto);

$stmtCabecera = $db->prepare("SELECT * FROM tbl_expediente_docente WHERE id = :id");
$stmtCabecera->execute([':id' => $idExpediente]);
$cabecera = $stmtCabecera->fetch(PDO::FETCH_ASSOC);

$stmtEstudios = $db->prepare("SELECT * FROM tbl_expediente_estudio WHERE id_profesor = :prof ORDER BY orden, id");
$stmtEstudios->execute([':prof' => $idProfesor]);
$estudios = $stmtEstudios->fetchAll(PDO::FETCH_ASSOC);

$stmtCapacitaciones = $db->prepare("SELECT * FROM tbl_expediente_capacitacion WHERE id_profesor = :prof ORDER BY orden, id");
$stmtCapacitaciones->execute([':prof' => $idProfesor]);
$capacitaciones = $stmtCapacitaciones->fetchAll(PDO::FETCH_ASSOC);

$stmtExperiencias = $db->prepare("SELECT * FROM tbl_expediente_experiencia WHERE id_profesor = :prof ORDER BY orden, id");
$stmtExperiencias->execute([':prof' => $idProfesor]);
$experiencias = $stmtExperiencias->fetchAll(PDO::FETCH_ASSOC);

$stmtDocumentos = $db->prepare("SELECT * FROM tbl_expediente_documento WHERE id_profesor = :prof ORDER BY created_at DESC, id DESC");
$stmtDocumentos->execute([':prof' => $idProfesor]);
$documentos = $stmtDocumentos->fetchAll(PDO::FETCH_ASSOC);

$anioActual = (int) date('Y');
$aniosGraduacion = range($anioActual + 1, 1960);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Expediente Docente - <?= htmlspecialchars($nombreCompleto) ?> - Educación Plus</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <style>
        :root { --primary: #2c3e50; --secondary: #3498db; --sidebar-width: 250px; }
        body { font-family: 'Segoe UI', sans-serif; background: #f8f9fa; }
        .sidebar { position: fixed; top: 0; left: 0; height: 100vh; width: var(--sidebar-width); background: var(--primary); color: white; padding-top: 60px; z-index: 1000; }
        .sidebar .nav-link { color: rgba(255,255,255,0.8); padding: 12px 20px; }
        .sidebar .nav-link:hover, .sidebar .nav-link.active { color: white; background: rgba(255,255,255,0.15); }
        .main-content { margin-left: var(--sidebar-width); padding: 20px; }
        .card-custom { background: white; border-radius: 10px; box-shadow: 0 2px 10px rgba(0,0,0,0.08); border: none; margin-bottom: 24px; }
        .nav-tabs .nav-link { color: var(--primary); }
        .nav-tabs .nav-link.active { font-weight: 700; }
        .foto-perfil { width: 110px; height: 110px; object-fit: cover; border-radius: 50%; border: 3px solid var(--secondary); }
        .foto-perfil-placeholder { width: 110px; height: 110px; border-radius: 50%; background: var(--secondary); color: white; display: flex; align-items: center; justify-content: center; font-size: 2.2rem; font-weight: bold; }
        .hoja { background: white; max-width: 900px; margin: 0 auto; padding: 30px; border-radius: 10px; box-shadow: 0 2px 10px rgba(0,0,0,0.08); }
        .hoja h3 { color: var(--primary); border-bottom: 2px solid var(--secondary); padding-bottom: 6px; margin-top: 30px; }
        .hoja h3:first-child { margin-top: 0; }
        .hoja table { border-collapse: collapse; width: 100%; margin: 10px 0; }
        .hoja table td, .hoja table th, .hoja table tr { border: 1px solid #ccc; padding: 6px; }
        @media (max-width: 768px) { .sidebar { transform: translateX(-100%); } .sidebar.active { transform: translateX(0); } .main-content { margin-left: 0; } }
        @media print { .sidebar, .no-print, .btn { display: none !important; } .main-content { margin-left: 0; padding: 0; } .tab-pane { display: none !important; } #tab-previa.tab-pane { display: block !important; } .hoja { box-shadow: none; max-width: 100%; } }
    </style>
</head>
<body>
    <!-- Sidebar -->
    <div class="sidebar" id="sidebar">
        <div class="text-center mb-4">
            <h4><i class="fas fa-graduation-cap"></i> Educación Plus</h4>
            <small>Panel de Administración</small>
        </div>
        <nav class="nav flex-column">
            <a class="nav-link" href="../../index.php"><i class="fas fa-tachometer-alt"></i> Dashboard</a>
            <a class="nav-link" href="gestionar_estudiantes.php"><i class="fas fa-user-graduate"></i> Estudiantes</a>
            <a class="nav-link active" href="gestionar_profesores.php"><i class="fas fa-chalkboard-teacher"></i> Profesores</a>
            <a class="nav-link" href="gestionar_grados.php"><i class="fas fa-layer-group"></i> Grados/Secciones</a>
            <a class="nav-link" href="gestionar_asignaturas.php"><i class="fas fa-book"></i> Asignaturas</a>
            <a class="nav-link" href="horario_clases.php"><i class="fas fa-calendar-week"></i> Horario</a>
            <a class="nav-link" href="carnet_estudiantil.php"><i class="fas fa-id-card"></i> Carnet Estudiantil</a>
            <a class="nav-link" href="gestionar_matriculas.php"><i class="fas fa-file-signature"></i> Matrículas</a>
            <a class="nav-link" href="cuadro_notas.php"><i class="fas fa-clipboard-list"></i> Cuadro de Notas</a>
            <a class="nav-link" href="manual_convivencia.php"><i class="fas fa-handshake"></i> Convivencia Escolar</a>
            <a class="nav-link" href="../../logout.php"><i class="fas fa-sign-out-alt"></i> Cerrar Sesión</a>
        </nav>
    </div>

    <!-- Main Content -->
    <div class="main-content">
        <div class="d-flex justify-content-between align-items-center mb-3 no-print">
            <div>
                <a href="gestionar_profesores.php" class="text-decoration-none small"><i class="fas fa-arrow-left"></i> Volver a Profesores</a>
                <h2 class="mt-1"><i class="fas fa-id-card"></i> Expediente Docente</h2>
                <p class="text-muted mb-0"><?= htmlspecialchars($nombreCompleto) ?> -- <?= htmlspecialchars($profesor['especialidad'] ?? 'Sin especialidad') ?></p>
            </div>
            <button class="btn btn-primary" onclick="irAVistaPreviaEImprimir()"><i class="fas fa-print"></i> Imprimir / Descargar PDF</button>
        </div>

        <?php if ($mensaje): ?>
        <div class="alert alert-<?= $tipo_mensaje ?> alert-dismissible fade show no-print">
            <?= htmlspecialchars($mensaje) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php endif; ?>

        <ul class="nav nav-tabs no-print flex-wrap mb-3" id="tabsExpediente" role="tablist">
            <li class="nav-item"><button class="nav-link active" data-bs-toggle="tab" data-bs-target="#tab-personales" type="button"><i class="fas fa-user"></i> Datos Personales</button></li>
            <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-estudios" type="button"><i class="fas fa-graduation-cap"></i> Estudios Académicos</button></li>
            <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-capacitaciones" type="button"><i class="fas fa-certificate"></i> Formación Continua</button></li>
            <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-experiencia" type="button"><i class="fas fa-briefcase"></i> Experiencia Laboral</button></li>
            <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-documentos" type="button"><i class="fas fa-paperclip"></i> Documentos Adjuntos</button></li>
            <li class="nav-item"><button class="nav-link" id="tab-btn-previa" data-bs-toggle="tab" data-bs-target="#tab-previa" type="button"><i class="fas fa-eye"></i> Vista Previa</button></li>
        </ul>

        <div class="tab-content">

            <!-- ===== DATOS PERSONALES / CONTACTO DE EMERGENCIA ===== -->
            <div class="tab-pane fade show active" id="tab-personales">
                <div class="card-custom p-4">
                    <div class="row g-4 mb-4">
                        <div class="col-auto">
                            <?php if (!empty($cabecera['foto_ruta'])): ?>
                            <img src="<?= htmlspecialchars(BASE_URL . '/' . $cabecera['foto_ruta']) ?>" class="foto-perfil" alt="Foto">
                            <?php else: ?>
                            <div class="foto-perfil-placeholder"><?= strtoupper(substr($profesor['primer_nombre'], 0, 1)) ?></div>
                            <?php endif; ?>
                        </div>
                        <div class="col">
                            <h6 class="text-muted mb-2">Datos de contacto (editables en <a href="gestionar_profesores.php">Gestión de Profesores</a>)</h6>
                            <p class="mb-1"><strong>DUI:</strong> <?= htmlspecialchars($profesor['dui'] ?? 'No registrado') ?></p>
                            <p class="mb-1"><strong>Email:</strong> <?= htmlspecialchars($profesor['email'] ?? 'No registrado') ?> &nbsp; <strong>Celular:</strong> <?= htmlspecialchars($profesor['celular'] ?? 'No registrado') ?></p>
                            <p class="mb-0"><strong>Dirección:</strong> <?= htmlspecialchars($profesor['direccion'] ?: 'No registrada') ?></p>
                        </div>
                    </div>
                    <hr>
                    <form method="POST" enctype="multipart/form-data">
                        <input type="hidden" name="accion" value="actualizar_cabecera">
                        <input type="hidden" name="id_profesor" value="<?= $idProfesor ?>">
                        <div class="row g-3">
                            <div class="col-md-4">
                                <label class="form-label">Fotografía</label>
                                <input type="file" name="foto" class="form-control" accept="image/jpeg,image/png,image/webp">
                                <small class="text-muted">JPG, PNG o WEBP. Deja vacío para conservar la actual.</small>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Contacto de emergencia -- Nombre</label>
                                <input type="text" name="contacto_emergencia_nombre" class="form-control" value="<?= htmlspecialchars($cabecera['contacto_emergencia_nombre'] ?? '') ?>">
                            </div>
                            <div class="col-md-2">
                                <label class="form-label">Teléfono</label>
                                <input type="text" name="contacto_emergencia_telefono" class="form-control" value="<?= htmlspecialchars($cabecera['contacto_emergencia_telefono'] ?? '') ?>">
                            </div>
                            <div class="col-md-2">
                                <label class="form-label">Parentesco</label>
                                <select name="contacto_emergencia_parentesco" class="form-select">
                                    <option value="">-- Ninguno --</option>
                                    <?php foreach (CatalogoExpedienteDocente::PARENTESCOS as $p): ?>
                                    <option value="<?= htmlspecialchars($p) ?>" <?= ($cabecera['contacto_emergencia_parentesco'] ?? '') === $p ? 'selected' : '' ?>><?= htmlspecialchars($p) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-12">
                                <label class="form-label">Notas</label>
                                <textarea name="notas" class="form-control" rows="2"><?= htmlspecialchars($cabecera['notas'] ?? '') ?></textarea>
                            </div>
                        </div>
                        <button type="submit" class="btn btn-primary mt-3"><i class="fas fa-save"></i> Guardar</button>
                    </form>
                </div>
            </div>

            <!-- ===== ESTUDIOS ACADÉMICOS ===== -->
            <div class="tab-pane fade" id="tab-estudios">
                <div class="card-custom p-4">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <h5 class="mb-0"><i class="fas fa-graduation-cap"></i> Estudios Académicos</h5>
                        <button class="btn btn-success" data-bs-toggle="modal" data-bs-target="#modalEstudio" onclick="prepararModalEstudio('crear')"><i class="fas fa-plus"></i> Agregar</button>
                    </div>
                    <div class="table-responsive">
                        <table class="table table-hover">
                            <thead class="table-light">
                                <tr><th>Grado</th><th>Título</th><th>Institución</th><th>Año</th><th>Documento</th><th class="no-print">Acciones</th></tr>
                            </thead>
                            <tbody>
                                <?php foreach ($estudios as $e): ?>
                                <tr>
                                    <td><?= htmlspecialchars($e['grado_academico']) ?></td>
                                    <td><?= htmlspecialchars($e['titulo']) ?></td>
                                    <td><?= htmlspecialchars($e['institucion_educativa']) ?></td>
                                    <td><?= htmlspecialchars((string) ($e['anio_graduacion'] ?? '-')) ?></td>
                                    <td><?= $e['ruta_documento'] ? '<a href="' . htmlspecialchars(BASE_URL . '/' . $e['ruta_documento']) . '" target="_blank"><i class="fas fa-file-alt"></i> Ver</a>' : '<span class="text-muted">-</span>' ?></td>
                                    <td class="no-print">
                                        <button type="button" class="btn btn-sm btn-warning" onclick='prepararModalEstudio("editar", <?= json_encode($e, JSON_HEX_APOS | JSON_HEX_QUOT) ?>)'><i class="fas fa-edit"></i></button>
                                        <form method="POST" style="display:inline" onsubmit="return confirm('¿Eliminar este estudio académico?');">
                                            <input type="hidden" name="accion" value="eliminar_estudio">
                                            <input type="hidden" name="id_profesor" value="<?= $idProfesor ?>">
                                            <input type="hidden" name="id_estudio" value="<?= $e['id'] ?>">
                                            <button type="submit" class="btn btn-sm btn-danger"><i class="fas fa-trash"></i></button>
                                        </form>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                                <?php if (empty($estudios)): ?>
                                <tr><td colspan="6" class="text-center text-muted py-3">Todavía no hay estudios académicos registrados.</td></tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- ===== FORMACIÓN CONTINUA ===== -->
            <div class="tab-pane fade" id="tab-capacitaciones">
                <div class="card-custom p-4">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <h5 class="mb-0"><i class="fas fa-certificate"></i> Formación Continua / Capacitaciones</h5>
                        <button class="btn btn-success" data-bs-toggle="modal" data-bs-target="#modalCapacitacion" onclick="prepararModalCapacitacion('crear')"><i class="fas fa-plus"></i> Agregar</button>
                    </div>
                    <div class="table-responsive">
                        <table class="table table-hover">
                            <thead class="table-light">
                                <tr><th>Institución</th><th>Capacitación</th><th>Año</th><th>Duración</th><th>Documento</th><th class="no-print">Acciones</th></tr>
                            </thead>
                            <tbody>
                                <?php foreach ($capacitaciones as $c): ?>
                                <tr>
                                    <td><?= htmlspecialchars($c['institucion']) ?></td>
                                    <td><?= htmlspecialchars($c['nombre_capacitacion']) ?></td>
                                    <td><?= htmlspecialchars((string) $c['anio']) ?></td>
                                    <td><?= $c['duracion_horas'] ? htmlspecialchars($c['duracion_horas']) . ' h' : '-' ?></td>
                                    <td><?= $c['ruta_documento'] ? '<a href="' . htmlspecialchars(BASE_URL . '/' . $c['ruta_documento']) . '" target="_blank"><i class="fas fa-file-alt"></i> Ver</a>' : '<span class="text-muted">-</span>' ?></td>
                                    <td class="no-print">
                                        <button type="button" class="btn btn-sm btn-warning" onclick='prepararModalCapacitacion("editar", <?= json_encode($c, JSON_HEX_APOS | JSON_HEX_QUOT) ?>)'><i class="fas fa-edit"></i></button>
                                        <form method="POST" style="display:inline" onsubmit="return confirm('¿Eliminar esta capacitación?');">
                                            <input type="hidden" name="accion" value="eliminar_capacitacion">
                                            <input type="hidden" name="id_profesor" value="<?= $idProfesor ?>">
                                            <input type="hidden" name="id_capacitacion" value="<?= $c['id'] ?>">
                                            <button type="submit" class="btn btn-sm btn-danger"><i class="fas fa-trash"></i></button>
                                        </form>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                                <?php if (empty($capacitaciones)): ?>
                                <tr><td colspan="6" class="text-center text-muted py-3">Todavía no hay capacitaciones registradas.</td></tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- ===== EXPERIENCIA LABORAL ===== -->
            <div class="tab-pane fade" id="tab-experiencia">
                <div class="card-custom p-4">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <h5 class="mb-0"><i class="fas fa-briefcase"></i> Experiencia Laboral</h5>
                        <button class="btn btn-success" data-bs-toggle="modal" data-bs-target="#modalExperiencia" onclick="prepararModalExperiencia('crear')"><i class="fas fa-plus"></i> Agregar</button>
                    </div>
                    <div class="table-responsive">
                        <table class="table table-hover">
                            <thead class="table-light">
                                <tr><th>Institución</th><th>Cargo</th><th>Desde</th><th>Hasta</th><th>Documento</th><th class="no-print">Acciones</th></tr>
                            </thead>
                            <tbody>
                                <?php foreach ($experiencias as $x): ?>
                                <tr>
                                    <td><?= htmlspecialchars($x['institucion']) ?></td>
                                    <td><?= htmlspecialchars($x['cargo']) ?></td>
                                    <td><?= htmlspecialchars($x['fecha_desde']) ?></td>
                                    <td><?= $x['fecha_hasta'] ? htmlspecialchars($x['fecha_hasta']) : '<span class="badge bg-success">Vigente</span>' ?></td>
                                    <td><?= $x['ruta_documento'] ? '<a href="' . htmlspecialchars(BASE_URL . '/' . $x['ruta_documento']) . '" target="_blank"><i class="fas fa-file-alt"></i> Ver</a>' : '<span class="text-muted">-</span>' ?></td>
                                    <td class="no-print">
                                        <button type="button" class="btn btn-sm btn-warning" onclick='prepararModalExperiencia("editar", <?= json_encode($x, JSON_HEX_APOS | JSON_HEX_QUOT) ?>)'><i class="fas fa-edit"></i></button>
                                        <form method="POST" style="display:inline" onsubmit="return confirm('¿Eliminar esta experiencia laboral?');">
                                            <input type="hidden" name="accion" value="eliminar_experiencia">
                                            <input type="hidden" name="id_profesor" value="<?= $idProfesor ?>">
                                            <input type="hidden" name="id_experiencia" value="<?= $x['id'] ?>">
                                            <button type="submit" class="btn btn-sm btn-danger"><i class="fas fa-trash"></i></button>
                                        </form>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                                <?php if (empty($experiencias)): ?>
                                <tr><td colspan="6" class="text-center text-muted py-3">Todavía no hay experiencia laboral registrada.</td></tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- ===== DOCUMENTOS ADJUNTOS ===== -->
            <div class="tab-pane fade" id="tab-documentos">
                <div class="card-custom p-4">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <h5 class="mb-0"><i class="fas fa-paperclip"></i> Documentos Adjuntos</h5>
                        <button class="btn btn-success" data-bs-toggle="modal" data-bs-target="#modalDocumento" onclick="prepararModalDocumento('crear')"><i class="fas fa-plus"></i> Agregar</button>
                    </div>
                    <p class="text-muted small">Lista libre para cualquier documento que no encaje en las secciones anteriores (DUI, NIT, antecedentes, solvencias, etc.).</p>
                    <div class="table-responsive">
                        <table class="table table-hover">
                            <thead class="table-light">
                                <tr><th>Etiqueta</th><th>Archivo</th><th>Tamaño</th><th class="no-print">Acciones</th></tr>
                            </thead>
                            <tbody>
                                <?php foreach ($documentos as $d): ?>
                                <tr>
                                    <td><?= htmlspecialchars($d['etiqueta']) ?></td>
                                    <td><a href="<?= htmlspecialchars(BASE_URL . '/' . $d['ruta_archivo']) ?>" target="_blank"><i class="fas fa-file-alt"></i> Ver</a></td>
                                    <td><?= $d['tamano_bytes'] ? round($d['tamano_bytes'] / 1024) . ' KB' : '-' ?></td>
                                    <td class="no-print">
                                        <button type="button" class="btn btn-sm btn-warning" onclick='prepararModalDocumento("editar", <?= json_encode($d, JSON_HEX_APOS | JSON_HEX_QUOT) ?>)'><i class="fas fa-edit"></i></button>
                                        <form method="POST" style="display:inline" onsubmit="return confirm('¿Eliminar este documento?');">
                                            <input type="hidden" name="accion" value="eliminar_documento">
                                            <input type="hidden" name="id_profesor" value="<?= $idProfesor ?>">
                                            <input type="hidden" name="id_documento" value="<?= $d['id'] ?>">
                                            <button type="submit" class="btn btn-sm btn-danger"><i class="fas fa-trash"></i></button>
                                        </form>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                                <?php if (empty($documentos)): ?>
                                <tr><td colspan="4" class="text-center text-muted py-3">Todavía no hay documentos adjuntos.</td></tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- ===== VISTA PREVIA / IMPRESIÓN ===== -->
            <div class="tab-pane fade" id="tab-previa">
                <div class="hoja">
                    <div class="text-center mb-4">
                        <?php if (!empty($cabecera['foto_ruta'])): ?>
                        <img src="<?= htmlspecialchars(BASE_URL . '/' . $cabecera['foto_ruta']) ?>" class="foto-perfil mb-2" alt="Foto">
                        <?php endif; ?>
                        <h2><?= htmlspecialchars($nombreCompleto) ?></h2>
                        <p class="text-muted"><?= htmlspecialchars($profesor['especialidad'] ?? '') ?><?= $profesor['titulo_academico'] ? ' -- ' . htmlspecialchars($profesor['titulo_academico']) : '' ?></p>
                    </div>

                    <h3>Datos de Contacto</h3>
                    <p>
                        <strong>DUI:</strong> <?= htmlspecialchars($profesor['dui'] ?? 'No registrado') ?><br>
                        <strong>Dirección:</strong> <?= htmlspecialchars($profesor['direccion'] ?: 'No registrada') ?><br>
                        <strong>Teléfono:</strong> <?= htmlspecialchars($profesor['celular'] ?: $profesor['telefono_fijo'] ?: 'No registrado') ?><br>
                        <strong>Email:</strong> <?= htmlspecialchars($profesor['email'] ?? 'No registrado') ?><br>
                        <strong>Contacto de emergencia:</strong>
                        <?= !empty($cabecera['contacto_emergencia_nombre'])
                            ? htmlspecialchars($cabecera['contacto_emergencia_nombre']) . ' (' . htmlspecialchars($cabecera['contacto_emergencia_parentesco'] ?: 'Sin parentesco') . ') -- ' . htmlspecialchars($cabecera['contacto_emergencia_telefono'] ?: 'Sin teléfono')
                            : 'No registrado' ?>
                    </p>

                    <h3>Estudios Académicos</h3>
                    <table class="table table-sm table-bordered">
                        <thead><tr><th>Grado</th><th>Título</th><th>Institución</th><th>Año</th></tr></thead>
                        <tbody>
                            <?php foreach ($estudios as $e): ?>
                            <tr>
                                <td><?= htmlspecialchars($e['grado_academico']) ?></td>
                                <td><?= htmlspecialchars($e['titulo']) ?></td>
                                <td><?= htmlspecialchars($e['institucion_educativa']) ?></td>
                                <td><?= htmlspecialchars((string) ($e['anio_graduacion'] ?? '-')) ?></td>
                            </tr>
                            <?php endforeach; ?>
                            <?php if (empty($estudios)): ?>
                            <tr><td colspan="4" class="text-muted">Sin registros.</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>

                    <h3>Formación Continua</h3>
                    <table class="table table-sm table-bordered">
                        <thead><tr><th>Institución</th><th>Capacitación</th><th>Año</th><th>Duración</th></tr></thead>
                        <tbody>
                            <?php foreach ($capacitaciones as $c): ?>
                            <tr>
                                <td><?= htmlspecialchars($c['institucion']) ?></td>
                                <td><?= htmlspecialchars($c['nombre_capacitacion']) ?></td>
                                <td><?= htmlspecialchars((string) $c['anio']) ?></td>
                                <td><?= $c['duracion_horas'] ? htmlspecialchars($c['duracion_horas']) . ' h' : '-' ?></td>
                            </tr>
                            <?php endforeach; ?>
                            <?php if (empty($capacitaciones)): ?>
                            <tr><td colspan="4" class="text-muted">Sin registros.</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>

                    <h3>Experiencia Laboral</h3>
                    <table class="table table-sm table-bordered">
                        <thead><tr><th>Institución</th><th>Cargo</th><th>Desde</th><th>Hasta</th></tr></thead>
                        <tbody>
                            <?php foreach ($experiencias as $x): ?>
                            <tr>
                                <td><?= htmlspecialchars($x['institucion']) ?></td>
                                <td><?= htmlspecialchars($x['cargo']) ?></td>
                                <td><?= htmlspecialchars($x['fecha_desde']) ?></td>
                                <td><?= $x['fecha_hasta'] ? htmlspecialchars($x['fecha_hasta']) : 'Vigente' ?></td>
                            </tr>
                            <?php endforeach; ?>
                            <?php if (empty($experiencias)): ?>
                            <tr><td colspan="4" class="text-muted">Sin registros.</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>

                    <h3>Documentos Adjuntos</h3>
                    <ul>
                        <?php foreach ($documentos as $d): ?>
                        <li><?= htmlspecialchars($d['etiqueta']) ?></li>
                        <?php endforeach; ?>
                        <?php if (empty($documentos)): ?>
                        <li class="text-muted">Sin documentos adjuntos.</li>
                        <?php endif; ?>
                    </ul>
                </div>
            </div>

        </div>
    </div>

    <!-- Modal Estudio Académico -->
    <div class="modal fade" id="modalEstudio" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="modalTitleEstudio"><i class="fas fa-plus"></i> Nuevo estudio académico</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" id="formEstudio" enctype="multipart/form-data">
                    <div class="modal-body">
                        <input type="hidden" name="accion" id="accion_estudio" value="crear_estudio">
                        <input type="hidden" name="id_profesor" value="<?= $idProfesor ?>">
                        <input type="hidden" name="id_estudio" id="id_estudio">
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label">Grado académico *</label>
                                <select name="grado_academico" id="ee_grado" class="form-select" required>
                                    <?php foreach (CatalogoExpedienteDocente::GRADOS_ACADEMICOS as $g): ?>
                                    <option value="<?= htmlspecialchars($g) ?>"><?= htmlspecialchars($g) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Año de graduación</label>
                                <select name="anio_graduacion" id="ee_anio" class="form-select">
                                    <option value="">-- Sin especificar --</option>
                                    <?php foreach ($aniosGraduacion as $a): ?>
                                    <option value="<?= $a ?>"><?= $a ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-12">
                                <label class="form-label">Título / especialidad *</label>
                                <input type="text" name="titulo" id="ee_titulo" class="form-control" required>
                            </div>
                            <div class="col-12">
                                <label class="form-label">Institución educativa *</label>
                                <input type="text" name="institucion_educativa" id="ee_institucion" class="form-control" required>
                            </div>
                            <div class="col-12">
                                <label class="form-label">Documento (título/diploma)</label>
                                <input type="file" name="documento" class="form-control" accept="image/jpeg,image/png,image/webp,application/pdf">
                                <div id="ee_doc_actual" class="form-text"></div>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                        <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Guardar</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Modal Capacitación -->
    <div class="modal fade" id="modalCapacitacion" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="modalTitleCapacitacion"><i class="fas fa-plus"></i> Nueva capacitación</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" id="formCapacitacion" enctype="multipart/form-data">
                    <div class="modal-body">
                        <input type="hidden" name="accion" id="accion_capacitacion" value="crear_capacitacion">
                        <input type="hidden" name="id_profesor" value="<?= $idProfesor ?>">
                        <input type="hidden" name="id_capacitacion" id="id_capacitacion">
                        <div class="row g-3">
                            <div class="col-12">
                                <label class="form-label">Institución *</label>
                                <input type="text" name="institucion" id="ec_institucion" class="form-control" required>
                            </div>
                            <div class="col-12">
                                <label class="form-label">Nombre de la capacitación *</label>
                                <input type="text" name="nombre_capacitacion" id="ec_nombre" class="form-control" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Año *</label>
                                <input type="number" name="anio" id="ec_anio" class="form-control" min="1960" max="<?= $anioActual + 1 ?>" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Duración (horas)</label>
                                <input type="number" name="duracion_horas" id="ec_horas" class="form-control" min="0">
                            </div>
                            <div class="col-12">
                                <label class="form-label">Documento (diploma)</label>
                                <input type="file" name="documento" class="form-control" accept="image/jpeg,image/png,image/webp,application/pdf">
                                <div id="ec_doc_actual" class="form-text"></div>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                        <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Guardar</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Modal Experiencia Laboral -->
    <div class="modal fade" id="modalExperiencia" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="modalTitleExperiencia"><i class="fas fa-plus"></i> Nueva experiencia laboral</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" id="formExperiencia" enctype="multipart/form-data">
                    <div class="modal-body">
                        <input type="hidden" name="accion" id="accion_experiencia" value="crear_experiencia">
                        <input type="hidden" name="id_profesor" value="<?= $idProfesor ?>">
                        <input type="hidden" name="id_experiencia" id="id_experiencia">
                        <div class="row g-3">
                            <div class="col-12">
                                <label class="form-label">Institución / Centro educativo *</label>
                                <input type="text" name="institucion" id="ex_institucion" class="form-control" required>
                            </div>
                            <div class="col-12">
                                <label class="form-label">Cargo *</label>
                                <input type="text" name="cargo" id="ex_cargo" class="form-control" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Desde *</label>
                                <input type="date" name="fecha_desde" id="ex_desde" class="form-control" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Hasta</label>
                                <input type="date" name="fecha_hasta" id="ex_hasta" class="form-control">
                                <small class="text-muted">Deja vacío si el cargo está vigente.</small>
                            </div>
                            <div class="col-12">
                                <label class="form-label">Documento (constancia laboral)</label>
                                <input type="file" name="documento" class="form-control" accept="image/jpeg,image/png,image/webp,application/pdf">
                                <div id="ex_doc_actual" class="form-text"></div>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                        <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Guardar</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Modal Documento Adjunto -->
    <div class="modal fade" id="modalDocumento" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="modalTitleDocumento"><i class="fas fa-plus"></i> Nuevo documento</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" id="formDocumento" enctype="multipart/form-data">
                    <div class="modal-body">
                        <input type="hidden" name="accion" id="accion_documento" value="crear_documento">
                        <input type="hidden" name="id_profesor" value="<?= $idProfesor ?>">
                        <input type="hidden" name="id_documento" id="id_documento">
                        <div class="mb-3">
                            <label class="form-label">Etiqueta *</label>
                            <input type="text" name="etiqueta" id="ed_etiqueta" class="form-control" list="listaEtiquetas" required>
                            <datalist id="listaEtiquetas">
                                <?php foreach (CatalogoExpedienteDocente::ETIQUETAS_DOCUMENTO_SUGERIDAS as $et): ?>
                                <option value="<?= htmlspecialchars($et) ?>">
                                <?php endforeach; ?>
                            </datalist>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Archivo <span id="ed_archivo_req">*</span></label>
                            <input type="file" name="archivo" id="ed_archivo" class="form-control" accept="image/jpeg,image/png,image/webp,application/pdf">
                            <div id="ed_doc_actual" class="form-text"></div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                        <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Guardar</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Scripts -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function prepararModalEstudio(modo, data) {
            document.getElementById('formEstudio').reset();
            document.getElementById('ee_doc_actual').innerHTML = '';
            if (modo === 'crear') {
                document.getElementById('accion_estudio').value = 'crear_estudio';
                document.getElementById('id_estudio').value = '';
                document.getElementById('modalTitleEstudio').innerHTML = '<i class="fas fa-plus"></i> Nuevo estudio académico';
            } else {
                document.getElementById('accion_estudio').value = 'actualizar_estudio';
                document.getElementById('id_estudio').value = data.id;
                document.getElementById('ee_grado').value = data.grado_academico || '';
                document.getElementById('ee_titulo').value = data.titulo || '';
                document.getElementById('ee_institucion').value = data.institucion_educativa || '';
                document.getElementById('ee_anio').value = data.anio_graduacion || '';
                if (data.ruta_documento) {
                    document.getElementById('ee_doc_actual').innerHTML = 'Documento actual: <a href="<?= htmlspecialchars(BASE_URL) ?>/' + data.ruta_documento + '" target="_blank">ver</a> -- selecciona un archivo solo si deseas reemplazarlo.';
                }
                document.getElementById('modalTitleEstudio').innerHTML = '<i class="fas fa-edit"></i> Editar estudio académico';
            }
        }

        function prepararModalCapacitacion(modo, data) {
            document.getElementById('formCapacitacion').reset();
            document.getElementById('ec_doc_actual').innerHTML = '';
            if (modo === 'crear') {
                document.getElementById('accion_capacitacion').value = 'crear_capacitacion';
                document.getElementById('id_capacitacion').value = '';
                document.getElementById('modalTitleCapacitacion').innerHTML = '<i class="fas fa-plus"></i> Nueva capacitación';
            } else {
                document.getElementById('accion_capacitacion').value = 'actualizar_capacitacion';
                document.getElementById('id_capacitacion').value = data.id;
                document.getElementById('ec_institucion').value = data.institucion || '';
                document.getElementById('ec_nombre').value = data.nombre_capacitacion || '';
                document.getElementById('ec_anio').value = data.anio || '';
                document.getElementById('ec_horas').value = data.duracion_horas || '';
                if (data.ruta_documento) {
                    document.getElementById('ec_doc_actual').innerHTML = 'Documento actual: <a href="<?= htmlspecialchars(BASE_URL) ?>/' + data.ruta_documento + '" target="_blank">ver</a> -- selecciona un archivo solo si deseas reemplazarlo.';
                }
                document.getElementById('modalTitleCapacitacion').innerHTML = '<i class="fas fa-edit"></i> Editar capacitación';
            }
        }

        function prepararModalExperiencia(modo, data) {
            document.getElementById('formExperiencia').reset();
            document.getElementById('ex_doc_actual').innerHTML = '';
            if (modo === 'crear') {
                document.getElementById('accion_experiencia').value = 'crear_experiencia';
                document.getElementById('id_experiencia').value = '';
                document.getElementById('modalTitleExperiencia').innerHTML = '<i class="fas fa-plus"></i> Nueva experiencia laboral';
            } else {
                document.getElementById('accion_experiencia').value = 'actualizar_experiencia';
                document.getElementById('id_experiencia').value = data.id;
                document.getElementById('ex_institucion').value = data.institucion || '';
                document.getElementById('ex_cargo').value = data.cargo || '';
                document.getElementById('ex_desde').value = data.fecha_desde || '';
                document.getElementById('ex_hasta').value = data.fecha_hasta || '';
                if (data.ruta_documento) {
                    document.getElementById('ex_doc_actual').innerHTML = 'Documento actual: <a href="<?= htmlspecialchars(BASE_URL) ?>/' + data.ruta_documento + '" target="_blank">ver</a> -- selecciona un archivo solo si deseas reemplazarlo.';
                }
                document.getElementById('modalTitleExperiencia').innerHTML = '<i class="fas fa-edit"></i> Editar experiencia laboral';
            }
        }

        function prepararModalDocumento(modo, data) {
            document.getElementById('formDocumento').reset();
            document.getElementById('ed_doc_actual').innerHTML = '';
            if (modo === 'crear') {
                document.getElementById('accion_documento').value = 'crear_documento';
                document.getElementById('id_documento').value = '';
                document.getElementById('ed_archivo').setAttribute('required', 'required');
                document.getElementById('ed_archivo_req').textContent = '*';
                document.getElementById('modalTitleDocumento').innerHTML = '<i class="fas fa-plus"></i> Nuevo documento';
            } else {
                document.getElementById('accion_documento').value = 'actualizar_documento';
                document.getElementById('id_documento').value = data.id;
                document.getElementById('ed_etiqueta').value = data.etiqueta || '';
                document.getElementById('ed_archivo').removeAttribute('required');
                document.getElementById('ed_archivo_req').textContent = '';
                if (data.ruta_archivo) {
                    document.getElementById('ed_doc_actual').innerHTML = 'Archivo actual: <a href="<?= htmlspecialchars(BASE_URL) ?>/' + data.ruta_archivo + '" target="_blank">ver</a> -- selecciona un archivo solo si deseas reemplazarlo.';
                }
                document.getElementById('modalTitleDocumento').innerHTML = '<i class="fas fa-edit"></i> Editar documento';
            }
        }

        // El botón de imprimir siempre exporta el documento completo desde
        // la pestaña Vista Previa, sin importar en qué pestaña esté el
        // usuario -- cambia de pestaña primero y luego llama a print().
        function irAVistaPreviaEImprimir() {
            const tabPrevia = new bootstrap.Tab(document.getElementById('tab-btn-previa'));
            tabPrevia.show();
            setTimeout(() => window.print(), 150);
        }
    </script>
</body>
</html>
