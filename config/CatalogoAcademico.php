<?php
/**
 * Catálogo académico fijo (El Salvador): nombres canónicos de Grado, nota
 * mínima de aprobación por defecto según nivel, y letras válidas de Sección.
 *
 * tbl_grado es un catálogo GLOBAL a propósito (ver comentario en
 * migrations/002_backfill_columnas_preexistentes.sql) -- no varía por
 * institución, así que esta lista es la única fuente de verdad usada tanto
 * por la migración de siembra como por el formulario "Nuevo Grado" en
 * modules/admin/gestionar_grados.php.
 */
class CatalogoAcademico
{
    /** nombre canónico => nivel */
    const GRADOS = [
        'Parvularia 4' => 'basica',
        'Parvularia 5' => 'basica',
        'Parvularia 6' => 'basica',
        'Primero'      => 'basica',
        'Segundo'      => 'basica',
        'Tercero'      => 'basica',
        'Cuarto'       => 'basica',
        'Quinto'       => 'basica',
        'Sexto'        => 'basica',
        'Séptimo'      => 'basica',
        'Octavo'       => 'basica',
        'Noveno'       => 'basica',
        'Primer año'   => 'bachillerato',
        'Segundo año'  => 'bachillerato',
        'Tercer año'   => 'bachillerato',
    ];

    const NOTA_MINIMA_DEFAULT = [
        'basica'       => 6.0,
        'bachillerato' => 7.0,
    ];

    /** Letras válidas de Sección, en orden. */
    const SECCION_LETRAS = ['A','B','C','D','E','F','G','H','I','J','K','L','M'];
}
