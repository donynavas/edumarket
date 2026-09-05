<?php
/**
 * Helper del módulo "Carnet Estudiantil" (lado director). Reutiliza el mismo
 * patrón de subida/validación de archivos ya usado por
 * config/ExpedienteDocenteHelper.php (validar MIME real vía finfo, nunca la
 * extensión ni el Content-Type que manda el navegador) para el logo de la
 * institución, y centraliza el cálculo de los datos que va a mostrar cada
 * carnet.
 */
class CarnetHelper
{
    const UPLOAD_DIR_RELATIVO = 'assets/uploads/instituciones/';
    const TAMANO_MAXIMO_BYTES = 3 * 1024 * 1024; // 3 MB, alcanza de sobra para un logo

    const MIMES_LOGO = ['image/jpeg', 'image/png', 'image/webp'];

    private const EXTENSIONES = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    ];

    /**
     * Valida (MIME real) y guarda el logo subido, con un nombre generado en
     * el servidor y prefijado con el id de institución (así dos logos de
     * instituciones distintas nunca colisionan de nombre). Devuelve la ruta
     * RELATIVA (desde la raíz del proyecto) que debe guardarse en
     * tbl_institucion.logo_path.
     */
    public static function validarYGuardarLogo(array $file, int $tid): string
    {
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            throw new Exception('Error al subir el logo.');
        }
        if (($file['size'] ?? 0) > self::TAMANO_MAXIMO_BYTES) {
            throw new Exception('El logo supera el tamaño máximo permitido (3 MB).');
        }

        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->file($file['tmp_name']);
        if (!in_array($mime, self::MIMES_LOGO, true)) {
            throw new Exception('Formato no permitido (usa JPG, PNG o WEBP).');
        }

        $ext = self::EXTENSIONES[$mime];
        $nombreArchivo = 'logo_inst' . $tid . '_' . uniqid() . '.' . $ext;
        $uploadDir = __DIR__ . '/../' . self::UPLOAD_DIR_RELATIVO;
        // La carpeta puede no existir todavía en un despliegue nuevo (las
        // carpetas vacías no viajan dentro del ZIP de entrega) -- se crea
        // aquí mismo si hace falta, mismo criterio que ya usa
        // modules/estudiante/actividades.php para su carpeta de subidas.
        if (!is_dir($uploadDir) && !mkdir($uploadDir, 0755, true) && !is_dir($uploadDir)) {
            throw new Exception('No se pudo crear la carpeta de subida del logo.');
        }
        if (!move_uploaded_file($file['tmp_name'], $uploadDir . $nombreArchivo)) {
            throw new Exception('No se pudo guardar el logo.');
        }

        return self::UPLOAD_DIR_RELATIVO . $nombreArchivo;
    }

    /**
     * Borra el archivo físico de un logo anterior, solo si la ruta cae
     * dentro del directorio de subida esperado (mismo guard defensivo que
     * ExpedienteDocenteHelper::borrarArchivoFisico() antes de unlink()).
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

    /**
     * Iniciales de un nombre completo (hasta 2 letras) para el avatar
     * circular del carnet -- el director decidió no manejar fotos de
     * estudiante todavía, así que el círculo de foto muestra las iniciales
     * en vez de una silueta genérica sin nada de información.
     */
    public static function iniciales(string $nombreCompleto): string
    {
        $partes = preg_split('/\s+/', trim($nombreCompleto), -1, PREG_SPLIT_NO_EMPTY);
        $iniciales = '';
        foreach (array_slice($partes, 0, 2) as $parte) {
            $iniciales .= mb_strtoupper(mb_substr($parte, 0, 1));
        }
        return $iniciales !== '' ? $iniciales : '?';
    }
}
