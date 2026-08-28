<?php
/**
 * Catálogo fijo para el módulo "Horario de Clases". Solo listas const,
 * sin acceso a BD -- mismo criterio que config/CatalogoAcademico.php.
 */
class CatalogoHorario
{
    /** Días hábiles válidos para un bloque de horario. */
    const DIAS_SEMANA = [
        1 => 'Lunes',
        2 => 'Martes',
        3 => 'Miércoles',
        4 => 'Jueves',
        5 => 'Viernes',
    ];

    /** Turnos válidos (propiedad de tbl_seccion). */
    const TURNOS = [
        'matutino' => 'Matutino',
        'vespertino' => 'Vespertino',
    ];

    /**
     * Semilla inicial de bloques horarios por turno -- se inserta UNA
     * SOLA VEZ por institución (ver HorarioHelper::asegurarBloquesPorDefecto),
     * y queda completamente editable/borrable después desde la pestaña
     * "Configurar Bloques". 6 bloques de clase + 1 receso por turno.
     */
    const BLOQUES_SEED_DEFAULT = [
        'matutino' => [
            ['numero' => 1, 'nombre' => 'Bloque 1', 'hora_inicio' => '07:00', 'hora_fin' => '07:40', 'es_receso' => 0],
            ['numero' => 2, 'nombre' => 'Bloque 2', 'hora_inicio' => '07:40', 'hora_fin' => '08:20', 'es_receso' => 0],
            ['numero' => 3, 'nombre' => 'Bloque 3', 'hora_inicio' => '08:20', 'hora_fin' => '09:00', 'es_receso' => 0],
            ['numero' => 4, 'nombre' => 'Recreo', 'hora_inicio' => '09:00', 'hora_fin' => '09:20', 'es_receso' => 1],
            ['numero' => 5, 'nombre' => 'Bloque 4', 'hora_inicio' => '09:20', 'hora_fin' => '10:00', 'es_receso' => 0],
            ['numero' => 6, 'nombre' => 'Bloque 5', 'hora_inicio' => '10:00', 'hora_fin' => '10:40', 'es_receso' => 0],
            ['numero' => 7, 'nombre' => 'Bloque 6', 'hora_inicio' => '10:40', 'hora_fin' => '11:20', 'es_receso' => 0],
        ],
        'vespertino' => [
            ['numero' => 1, 'nombre' => 'Bloque 1', 'hora_inicio' => '13:00', 'hora_fin' => '13:40', 'es_receso' => 0],
            ['numero' => 2, 'nombre' => 'Bloque 2', 'hora_inicio' => '13:40', 'hora_fin' => '14:20', 'es_receso' => 0],
            ['numero' => 3, 'nombre' => 'Bloque 3', 'hora_inicio' => '14:20', 'hora_fin' => '15:00', 'es_receso' => 0],
            ['numero' => 4, 'nombre' => 'Recreo', 'hora_inicio' => '15:00', 'hora_fin' => '15:20', 'es_receso' => 1],
            ['numero' => 5, 'nombre' => 'Bloque 4', 'hora_inicio' => '15:20', 'hora_fin' => '16:00', 'es_receso' => 0],
            ['numero' => 6, 'nombre' => 'Bloque 5', 'hora_inicio' => '16:00', 'hora_fin' => '16:40', 'es_receso' => 0],
            ['numero' => 7, 'nombre' => 'Bloque 6', 'hora_inicio' => '16:40', 'hora_fin' => '17:20', 'es_receso' => 0],
        ],
    ];
}
