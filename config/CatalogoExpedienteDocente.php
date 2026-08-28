<?php
/**
 * Catálogo fijo para el módulo "Expediente Docente" (hoja de vida esencial
 * del profesor, lado director). Solo listas const, sin acceso a BD -- mismo
 * criterio que config/CatalogoAcademico.php: fuente única de verdad para los
 * <select>/<datalist> del formulario.
 */
class CatalogoExpedienteDocente
{
    /** Grados académicos disponibles en "Estudios Académicos". */
    const GRADOS_ACADEMICOS = [
        'Bachillerato',
        'Profesorado',
        'Técnico',
        'Tecnólogo',
        'Licenciatura',
        'Ingeniería',
        'Maestría',
        'Doctorado',
        'Otro',
    ];

    /** Parentescos disponibles para el contacto de emergencia. */
    const PARENTESCOS = [
        'Cónyuge',
        'Padre',
        'Madre',
        'Hijo/a',
        'Hermano/a',
        'Otro familiar',
        'Amigo/a',
        'Otro',
    ];

    /** Etiquetas sugeridas para la lista libre de "Documentos Adjuntos" (no restrictivas, solo autocompletar). */
    const ETIQUETAS_DOCUMENTO_SUGERIDAS = [
        'DUI',
        'NIT',
        'Antecedentes Penales',
        'Solvencia Municipal',
        'Constancia de Salud',
        'Carné MINED',
        'Contrato Laboral',
        'Otro',
    ];
}
