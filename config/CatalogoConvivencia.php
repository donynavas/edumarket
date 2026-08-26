<?php
/**
 * Catálogo fijo del Manual de Convivencia Escolar (Fase 1).
 *
 * Fuente: "Guía para Elaborar el Plan de Convivencia Escolar" (MINED, 2ª
 * edición) -- esquema oficial de contenidos (pág. 28), composición mínima
 * del Comité de Convivencia Escolar (págs. 23-24), y marco legal (págs.
 * 15-16), este último actualizado con la Ley Crecer Juntos (Decreto 431),
 * que sustituyó a la antigua Ley de Protección Integral de la Niñez y
 * Adolescencia (LEPINA) y creó el Instituto Crecer Juntos (ICJ).
 *
 * Esta clase es la única fuente de verdad tanto para el formulario de
 * modules/admin/manual_convivencia.php como para la siembra en runtime
 * que hace config/ManualConvivenciaHelper.php -- igual que
 * CatalogoAcademico::GRADOS para tbl_grado.
 */
class CatalogoConvivencia
{
    /**
     * Secciones II a X del Plan de Convivencia Escolar (la sección I,
     * Generalidades, vive directamente en tbl_manual_convivencia por ser
     * campos únicos a nivel institución, no una sección repetible).
     *
     * tipo:
     *  - 'narrativo'   => contenido libre en tbl_manual_convivencia_seccion.contenido
     *  - 'estructurado' => campos propios en tbl_manual_convivencia_seccion.datos_json
     *    (por ahora solo la sección III, Objetivos, es estructurada)
     */
    const SECCIONES = [
        'II'   => ['titulo' => 'Caracterización de la convivencia escolar', 'tipo' => 'narrativo'],
        'III'  => ['titulo' => 'Objetivos', 'tipo' => 'estructurado'],
        'IV'   => ['titulo' => 'Alcance del Plan de Convivencia Escolar', 'tipo' => 'narrativo'],
        'V'    => ['titulo' => 'Acuerdos de convivencia', 'tipo' => 'narrativo'],
        'VI'   => ['titulo' => 'Metas', 'tipo' => 'narrativo'],
        'VII'  => ['titulo' => 'Principales acciones que se implementarán y tiempo (Cronograma)', 'tipo' => 'narrativo'],
        'VIII' => ['titulo' => 'Estrategias', 'tipo' => 'narrativo'],
        'IX'   => ['titulo' => 'Mecanismos para el seguimiento y evaluación del plan', 'tipo' => 'narrativo'],
        'X'    => ['titulo' => 'Anexo: Retos pendientes para el seguimiento', 'tipo' => 'narrativo'],
    ];

    /** Roles válidos de integrantes del Comité de Convivencia Escolar. */
    const COMITE_ROLES = [
        'estudiante'     => 'Estudiante',
        'docente'        => 'Docente',
        'administrativo' => 'Personal administrativo',
        'familia'        => 'Madre/Padre/Referente familiar',
    ];

    /**
     * Mínimos sugeridos por la guía (pág. 23): al menos 15 integrantes en
     * total, distribuidos así. Se usan SOLO para mostrar un aviso -- no
     * bloquean el guardado (ver ManualConvivenciaHelper::checklistComite()).
     */
    const COMITE_MINIMOS = [
        'estudiante'     => 8,
        'docente'        => 3,
        'administrativo' => 1,
        'familia'        => 3,
    ];

    const COMITE_TOTAL_MINIMO = 15;

    /**
     * Marco legal por defecto con el que se siembra el catálogo editable
     * de cada institución (tbl_manual_convivencia_marco_legal) la primera
     * vez que abre el Manual de Convivencia. El director puede editar,
     * desactivar o agregar más filas después -- esto es solo el punto de
     * partida.
     */
    const MARCO_LEGAL_SEED = [
        ['orden' => 1, 'nombre_norma' => 'Constitución de la República', 'articulo_referencia' => 'Arts. 1, 53, 55', 'descripcion' => 'Reconoce a la persona humana como el origen y el fin de la actividad del Estado; establece el derecho a la educación como inherente a la persona humana.'],
        ['orden' => 2, 'nombre_norma' => 'Convención sobre los Derechos del Niño', 'articulo_referencia' => 'Arts. 12, 13, 14, 15, 19, 23, 28, 29', 'descripcion' => 'Marco internacional de protección de los derechos de niñas, niños y adolescentes.'],
        ['orden' => 3, 'nombre_norma' => 'Convención sobre la Eliminación de Todas las Formas de Discriminación contra la Mujer (CEDAW)', 'articulo_referencia' => 'Art. 10', 'descripcion' => 'Derecho a la educación libre de discriminación por razones de género.'],
        ['orden' => 4, 'nombre_norma' => 'Convención Interamericana para Prevenir, Sancionar y Erradicar la Violencia contra la Mujer (Belém do Pará)', 'articulo_referencia' => 'Arts. 3, 6, 8 literal b', 'descripcion' => 'Derecho de las mujeres a una vida libre de violencia.'],
        ['orden' => 5, 'nombre_norma' => 'Ley Marco para la Convivencia Ciudadana y Contravenciones Administrativas', 'articulo_referencia' => 'Art. 2 literales a y b, Art. 4 literales c y d', 'descripcion' => 'Principios de convivencia ciudadana aplicables al entorno escolar.'],
        ['orden' => 6, 'nombre_norma' => 'Ley Crecer Juntos para la Protección Integral de la Primera Infancia, Niñez y Adolescencia (Decreto 431)', 'articulo_referencia' => 'Decreto Legislativo N.º 431', 'descripcion' => 'Sustituye a la antigua Ley de Protección Integral de la Niñez y Adolescencia (LEPINA) y crea el Instituto Crecer Juntos (ICJ) como ente rector de la protección integral de la niñez y adolescencia -- marco vigente de referencia obligatoria para el Plan de Convivencia Escolar.'],
        ['orden' => 7, 'nombre_norma' => 'Ley Especial Integral para una Vida Libre de Violencia para las Mujeres', 'articulo_referencia' => 'Arts. 2, 3, 20', 'descripcion' => 'Prevención, atención y sanción de la violencia contra las mujeres.'],
        ['orden' => 8, 'nombre_norma' => 'Ley de Igualdad, Equidad y Erradicación de la Discriminación contra las Mujeres', 'articulo_referencia' => 'Art. 16 literales a, b, c; Art. 17', 'descripcion' => 'Igualdad sustantiva y no discriminación por razones de género en el ámbito educativo.'],
        ['orden' => 9, 'nombre_norma' => 'Ley General de Educación', 'articulo_referencia' => 'Art. 2 (Fines), Art. 3 (Objetivos), Art. 89 (Deberes de los educandos), Art. 90 (Derechos de los educandos), Arts. 92-94 (Deberes de los padres de familia)', 'descripcion' => 'Marco general de derechos y deberes de la comunidad educativa.'],
        ['orden' => 10, 'nombre_norma' => 'Decreto 735 -- Reformas a la Ley General de Educación', 'articulo_referencia' => 'Arts. 5A, 76-A, 79-A', 'descripcion' => 'Reformas sobre equidad de género y continuidad educativa (p. ej. estudiantes en condición de embarazo).'],
        ['orden' => 11, 'nombre_norma' => 'Ley de la Carrera Docente y su Reglamento', 'articulo_referencia' => 'Art. 3-A; Reglamento Arts. 36, 37, 57', 'descripcion' => 'Deberes docentes relacionados con la convivencia escolar y funcionamiento del Consejo de Profesores.'],
        ['orden' => 12, 'nombre_norma' => 'Ley General de Juventud', 'articulo_referencia' => 'Art. 9 literales d y e', 'descripcion' => 'Derechos de participación de adolescentes y jóvenes.'],
        ['orden' => 13, 'nombre_norma' => 'Política Nacional para la Convivencia Escolar y Cultura de Paz', 'articulo_referencia' => null, 'descripcion' => 'Marco ético del MINED del que se derivan los enfoques y principios del Plan de Convivencia Escolar.'],
    ];
}
