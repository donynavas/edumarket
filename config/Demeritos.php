<?php
/**
 * Reglamento para la Promoción de la Cortesía Escolar (MINEDUCYT): textos
 * fijos por normativa (categorías de demérito, actividades de redención,
 * escala de consecuencias, principios del reglamento). No son
 * configurables por colegio, así que viven como constantes PHP -- mismo
 * espíritu que CatalogoAcademico::GRADOS -- en vez de una tabla catálogo
 * en base de datos.
 *
 * MESES_ES es un array simple en español (no IntlDateFormatter) para no
 * depender de que la extensión "intl" esté habilitada en el hosting real
 * del usuario -- se confirmó que sí está disponible en este sandbox, pero
 * no hay garantía de que lo esté en producción.
 */
class Demeritos
{
    /** slug interno (ENUM de las tablas) => texto exacto del formulario oficial */
    const CATEGORIAS = [
        'no_saludar'     => 'No saludar al entrar o salir al aula',
        'omitir_favor'   => 'Omitir "por favor" al hacer una petición',
        'omitir_gracias' => 'Omitir "gracias" al recibir un favor, material o atención',
        'tono_grosero'   => 'Usar un tono grosero o irrespetuoso hacia compañeros, docentes o personal',
    ];

    const ACTIVIDADES_REDENCION = [
        'semana_cortesia'      => 'Cumplir una semana completa con saludos y expresiones de cortesía ejemplares',
        'apoyo_orden_limpieza' => 'Apoyar voluntariamente en actividades de orden y limpieza escolar',
        'campana_valores'      => 'Participar en campañas de valores organizadas por el centro educativo',
    ];

    /** Solo texto de referencia impreso en la Tarjeta -- no hay lógica que dependa de estos valores. */
    const ESCALA_CONSECUENCIAS = [
        'Advertencia verbal y reflexión escrita (3 D)',
        'Comunicación a la familia y tarea correctiva (6 D)',
        'Suspensión de privilegios escolares (10 D)',
        'Reunión con la dirección y la familia (11-14 D)',
        'El estudiante no podrá ser promovido de grado (15 D)',
    ];

    const PRINCIPIOS = [
        'Respeto mútuo' => 'cada estudiante debe dirigirse a sus compañeros, docentes y autoridades con cortesía.',
        'Responsabilidad personal' => 'el uso u omisión de expresiones de cortesía tendrá consecuencias.',
        'Carácter formativo' => 'los deméritos no buscan castigo, sino corrección y formación de valores.',
    ];

    const MESES_ES = [
        1 => 'Enero', 2 => 'Febrero', 3 => 'Marzo', 4 => 'Abril',
        5 => 'Mayo', 6 => 'Junio', 7 => 'Julio', 8 => 'Agosto',
        9 => 'Septiembre', 10 => 'Octubre', 11 => 'Noviembre', 12 => 'Diciembre',
    ];
}
