<?php
/**
 * Helper del módulo "Expediente Docente" (hoja de vida esencial, lado
 * director). Igual que config/ManualConvivenciaHelper.php para su dominio:
 * siembra bajo demanda de la cabecera 1:1 con tbl_profesor, y manejo
 * centralizado de subida/borrado de archivos (títulos, diplomas,
 * documentos), validando siempre el MIME real -- nunca la extensión ni el
 * Content-Type que manda el navegador (mismo criterio que
 * modules/profesor/api/clase_recurso.php).
 */
class ExpedienteDocenteHelper
{
    const UPLOAD_DIR_RELATIVO = 'assets/uploads/docentes/';
    const TAMANO_MAXIMO_BYTES = 5 * 1024 * 1024; // 5 MB, límite a nivel de aplicación

    /** MIME permitidos para documentos (título, diploma, constancia, etc.): imagen o PDF. */
    const MIMES_DOCUMENTO = ['image/jpeg', 'image/png', 'image/webp', 'application/pdf'];

    /** MIME permitidos para la foto de perfil de la cabecera: solo imagen. */
    const MIMES_FOTO = ['image/jpeg', 'image/png', 'image/webp'];

    private const EXTENSIONES = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        'application/pdf' => 'pdf',
    ];

    /**
     * Obtiene el id de la cabecera del expediente del profesor,
     * creándola vacía si todavía no existe. Idempotente -- se llama en
     * cada carga de modules/admin/expediente_docente.php, así que un
     * profesor nuevo obtiene su fila de expediente la primera vez que el
     * director abre la página, sin necesitar un paso manual de "inicializar".
     */
    public static function asegurarCabecera(PDO $db, int $idProfesor): int
    {
        $stmt = $db->prepare("SELECT id FROM tbl_expediente_docente WHERE id_profesor = :prof");
        $stmt->execute([':prof' => $idProfesor]);
        $id = $stmt->fetchColumn();
        if ($id !== false) {
            return (int) $id;
        }

        $insert = $db->prepare("INSERT INTO tbl_expediente_docente (id_profesor) VALUES (:prof)");
        $insert->execute([':prof' => $idProfesor]);
        return (int) $db->lastInsertId();
    }

    /**
     * Valida (MIME real vía finfo, nunca extensión/Content-Type del
     * navegador) y guarda un archivo subido en assets/uploads/docentes/,
     * con un nombre generado en el servidor. Devuelve la ruta RELATIVA
     * (desde la raíz del proyecto) que debe guardarse en BD.
     *
     * @param array $file Un elemento de $_FILES (ya con error === UPLOAD_ERR_OK verificado por el caller, o se valida aquí también)
     * @param string $prefijo Prefijo del nombre de archivo (ej. 'exp_estudio_'), distinto por sección para depurar más fácil
     * @param array $mimesPermitidos Lista blanca de MIME reales aceptados (usar self::MIMES_DOCUMENTO o self::MIMES_FOTO)
     */
    public static function validarYGuardarArchivo(array $file, string $prefijo, array $mimesPermitidos): string
    {
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            throw new Exception('Error al subir el archivo.');
        }
        if (($file['size'] ?? 0) > self::TAMANO_MAXIMO_BYTES) {
            throw new Exception('El archivo supera el tamaño máximo permitido (5 MB).');
        }

        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->file($file['tmp_name']);
        if (!in_array($mime, $mimesPermitidos, true)) {
            throw new Exception('Formato no permitido (usa JPG, PNG, WEBP o PDF).');
        }

        $ext = self::EXTENSIONES[$mime];
        $nombreArchivo = uniqid($prefijo, true) . '.' . $ext;
        $uploadDir = __DIR__ . '/../' . self::UPLOAD_DIR_RELATIVO;
        // La carpeta puede no existir todavía en un despliegue nuevo (las
        // carpetas vacías no viajan dentro del ZIP de entrega) -- se crea
        // aquí mismo si hace falta, mismo criterio que ya usa
        // modules/estudiante/actividades.php para su carpeta de subidas.
        if (!is_dir($uploadDir) && !mkdir($uploadDir, 0755, true) && !is_dir($uploadDir)) {
            throw new Exception('No se pudo crear la carpeta de subida del archivo.');
        }
        if (!move_uploaded_file($file['tmp_name'], $uploadDir . $nombreArchivo)) {
            throw new Exception('No se pudo guardar el archivo.');
        }

        return self::UPLOAD_DIR_RELATIVO . $nombreArchivo;
    }

    /**
     * Borra el archivo físico asociado a una ruta guardada en BD, solo si
     * la ruta cae dentro del directorio de subida esperado -- mismo guard
     * que clase_recurso.php usa antes de unlink(), para nunca borrar una
     * ruta arbitraria si el dato en BD estuviera corrupto o manipulado.
     */
    public static function borrarArchivoFisico(?string $ruta): void
    {
        if (!$ruta || !str_starts_with($ruta, self::UPLOAD_DIR_RELATIVO)) {
            return;
        }
        $rutaAbsoluta = __DIR__ . '/../' . $ruta;
        if (is_file($rutaAbsoluta)) {
            @unlink($rutaAbsoluta);
        }
    }
}
