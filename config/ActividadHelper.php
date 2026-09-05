<?php
/**
 * Helper reusable para crear/editar actividades (tarea/examen/recurso) y su
 * vínculo opcional al Cuadro de Notas. Extraído de
 * modules/profesor/gestionar_actividades.php (donde vivía como funciones
 * planas del script de la página) para que otros módulos -- como
 * modules/profesor/impartir_clase.php -- puedan reutilizar exactamente la
 * misma lógica sin duplicarla ni tener que hacer require_once sobre una
 * página completa (que re-ejecutaría su propio auth/session al cargarse).
 * gestionar_actividades.php sigue siendo el único lugar donde se llama a
 * estos métodos desde el flujo de "Actividades"; el comportamiento es
 * idéntico a como estaba antes del refactor -- ver el historial de git de
 * gestionar_actividades.php si hace falta comparar.
 */
class ActividadHelper
{
    /**
     * Subconjunto de tipos de actividad "de referencia" (sin tarea/examen,
     * que tienen su propio flujo especial) -- usado por el botón "Asignar
     * Actividad" de modules/profesor/impartir_clase.php. Deliberadamente
     * más liviano que el arreglo $tipos_actividad de gestionar_actividades.php
     * (que además trae icono/color para las tarjetas de esa pantalla);
     * aquí solo hace falta clave => etiqueta para poblar un <select>.
     */
    public static function tiposReferencia(): array
    {
        return [
            'video' => '🎬 Video',
            'youtube' => '📺 YouTube',
            'articulo' => '📄 Artículo',
            'referencia' => '📚 Referencia',
            'podcast' => '🎧 Podcast',
            'revista' => '📰 Revista',
            'enlace' => '🔗 Enlace',
        ];
    }

    // Las actividades tipo "examen" se enlazan a un registro real en
    // tbl_examen (mismas tablas que usan asignar_examen.php/crear_examen.php/
    // tomar_examen.php), para que el examen creado aquí sea genuinamente
    // calificable y el estudiante pueda tomarlo -- no es sólo un anuncio.
    // Los tipos de pregunta soportados son los 5 autocalificables; "ensayo"
    // (pregunta abierta) queda excluido a propósito.
    const TIPOS_PREGUNTA_EXAMEN_PERMITIDOS = ['opcion_multiple', 'verdadero_falso', 'completar', 'relacionar', 'respuesta_corta'];

    public static function mapEstadoActividadAExamen(string $estadoActividad): string
    {
        // tbl_examen no tiene estado 'publicado'; 'publicado' y 'activo' en la
        // actividad significan lo mismo para el examen: disponible para tomar
        // (tomar_examen.php exige estado = 'activo').
        return match ($estadoActividad) {
            'publicado', 'activo' => 'activo',
            'cerrado' => 'cerrado',
            'eliminado' => 'eliminado',
            default => 'programado',
        };
    }

    /**
     * Inserta las preguntas (y sus opciones) de un examen a partir del arreglo
     * ya decodificado del campo oculto preguntas_json del formulario. Asume
     * que el examen (fila tbl_examen) ya existe y que, si es una edición, las
     * preguntas viejas de ese examen ya fueron borradas por el llamador.
     */
    public static function guardarPreguntasExamen(PDO $db, int $id_examen, array $preguntas): void
    {
        $orden = 1;
        foreach ($preguntas as $preg) {
            $tipo = $preg['tipo'] ?? '';
            $enunciado = trim($preg['enunciado'] ?? '');
            if ($enunciado === '' || !in_array($tipo, self::TIPOS_PREGUNTA_EXAMEN_PERMITIDOS, true)) {
                continue;
            }
            $puntaje = is_numeric($preg['puntaje'] ?? null) ? (float) $preg['puntaje'] : 1.0;

            $stmt = $db->prepare("INSERT INTO tbl_pregunta_examen (id_examen, numero_orden, tipo, enunciado, puntaje)
                                  VALUES (:examen, :orden, :tipo, :enunciado, :puntaje)");
            $stmt->execute([
                ':examen' => $id_examen, ':orden' => $orden, ':tipo' => $tipo,
                ':enunciado' => $enunciado, ':puntaje' => $puntaje
            ]);
            $id_pregunta = (int) $db->lastInsertId();

            if ($tipo === 'opcion_multiple') {
                $opciones = array_filter($preg['opciones'] ?? [], fn($o) => trim($o['texto'] ?? '') !== '');
                $i = 0;
                foreach ($opciones as $op) {
                    $stmt = $db->prepare("INSERT INTO tbl_opcion_respuesta (id_pregunta, texto, es_correcta, orden)
                                          VALUES (:preg, :texto, :correcta, :orden)");
                    $stmt->execute([
                        ':preg' => $id_pregunta, ':texto' => trim($op['texto']),
                        ':correcta' => !empty($op['es_correcta']) ? 1 : 0, ':orden' => $i
                    ]);
                    $i++;
                }
            } elseif ($tipo === 'verdadero_falso') {
                $correcta = $preg['correcta_vf'] ?? 'V';
                $stmt = $db->prepare("INSERT INTO tbl_opcion_respuesta (id_pregunta, texto, es_correcta, orden) VALUES (:preg, 'Verdadero', :v, 0)");
                $stmt->execute([':preg' => $id_pregunta, ':v' => ($correcta === 'V' ? 1 : 0)]);
                $stmt = $db->prepare("INSERT INTO tbl_opcion_respuesta (id_pregunta, texto, es_correcta, orden) VALUES (:preg, 'Falso', :f, 1)");
                $stmt->execute([':preg' => $id_pregunta, ':f' => ($correcta === 'F' ? 1 : 0)]);
            } elseif ($tipo === 'completar') {
                // Las respuestas correctas van entre corchetes dentro del enunciado.
                preg_match_all('/\[(.*?)\]/', $enunciado, $matches);
                foreach ($matches[1] as $i => $respuesta) {
                    $stmt = $db->prepare("INSERT INTO tbl_opcion_respuesta (id_pregunta, texto, es_correcta, orden) VALUES (:preg, :texto, 1, :orden)");
                    $stmt->execute([':preg' => $id_pregunta, ':texto' => $respuesta, ':orden' => $i]);
                }
            } elseif ($tipo === 'respuesta_corta') {
                $correcta = trim($preg['respuesta_correcta'] ?? '');
                $stmt = $db->prepare("INSERT INTO tbl_opcion_respuesta (id_pregunta, texto, es_correcta, orden) VALUES (:preg, :texto, 1, 0)");
                $stmt->execute([':preg' => $id_pregunta, ':texto' => $correcta]);
            } elseif ($tipo === 'relacionar') {
                $izquierda = array_values(array_filter($preg['izquierda'] ?? [], fn($v) => trim($v) !== ''));
                $derecha = array_values(array_filter($preg['derecha'] ?? [], fn($v) => trim($v) !== ''));
                foreach ($izquierda as $i => $elem) {
                    $stmt = $db->prepare("INSERT INTO tbl_opcion_respuesta (id_pregunta, texto, es_correcta, orden) VALUES (:preg, :texto, 0, :orden)");
                    $stmt->execute([':preg' => $id_pregunta, ':texto' => $elem, ':orden' => $i]);
                }
                foreach ($derecha as $i => $elem) {
                    $stmt = $db->prepare("INSERT INTO tbl_opcion_respuesta (id_pregunta, texto, es_correcta, orden) VALUES (:preg, :texto, 1, :orden)");
                    $stmt->execute([':preg' => $id_pregunta, ':texto' => $elem, ':orden' => count($izquierda) + $i]);
                }
            }

            $orden++;
        }
    }

    /**
     * true si al menos un estudiante ya inició un intento de este examen
     * (tbl_intento_examen). Usado antes de borrar/reemplazar sus preguntas
     * (tbl_pregunta_examen): una vez que existe un intento, sus respuestas
     * individuales (tbl_respuesta_estudiante) referencian esas preguntas por
     * FK (tbl_respuesta_estudiante_ibfk_2, SIN ON DELETE) -- borrarlas rompe
     * la base de datos con "Cannot delete or update a parent row" (#1451) y,
     * aunque no rompiera, perdería silenciosamente las respuestas/notas ya
     * registradas del estudiante. Llamado desde gestionar_actividades.php y
     * modules/profesor/api/guardar_examen.php (los dos lugares donde se
     * edita un examen ya existente).
     */
    public static function examenTieneIntentos(PDO $db, int $id_examen): bool
    {
        $stmt = $db->prepare("SELECT COUNT(*) FROM tbl_intento_examen WHERE id_examen = :id");
        $stmt->execute([':id' => $id_examen]);
        return ((int) $stmt->fetchColumn()) > 0;
    }

    /**
     * Resuelve y valida la vinculación opcional de una actividad al Cuadro de
     * Notas (tbl_actividad.id_periodo/bloque_notas/numero_nota). $idAsignacion
     * es la asignación REAL de la actividad (nunca la del filtro de la URL).
     * Devuelve ['id_periodo'=>, 'bloque_notas'=>, 'numero_nota'=>] con todo NULL
     * si el profesor eligió "No vincular". Lanza Exception si el período no
     * pertenece a esta institución/año/nivel, si la casilla no es válida para
     * el nivel del grado, o si otra actividad de la misma asignación ya usa
     * esa misma casilla (para no pisarle la nota sin que el profesor lo sepa).
     */
    public static function resolverVinculoCuadroNotas(PDO $db, int $tid, int $idAsignacion, int $anno, string $nivel, ?int $idPeriodoPost, ?string $casillaPost, ?int $idActividadActual): array
    {
        $vacio = ['id_periodo' => null, 'bloque_notas' => null, 'numero_nota' => null];

        if (!$idPeriodoPost || !$casillaPost) {
            return $vacio;
        }

        $stmtPer = $db->prepare("SELECT id FROM tbl_periodo WHERE id = :id AND id_institucion = :tid AND anno = :anno AND nivel = :nivel");
        $stmtPer->execute([':id' => $idPeriodoPost, ':tid' => $tid, ':anno' => $anno, ':nivel' => $nivel]);
        if (!$stmtPer->fetch()) {
            throw new Exception('El período elegido no es válido para esta asignación.');
        }

        $casilla = CuadroNotasHelper::resolverCasilla($nivel, $casillaPost);
        if (!$casilla) {
            throw new Exception('La casilla del Cuadro de Notas elegida no es válida para este nivel.');
        }

        $stmtColision = $db->prepare("SELECT titulo FROM tbl_actividad
            WHERE id_asignacion_docente = :asig AND id_periodo = :per AND bloque_notas = :bloque AND numero_nota = :num
            AND id != :actual");
        $stmtColision->execute([
            ':asig' => $idAsignacion,
            ':per' => $idPeriodoPost,
            ':bloque' => $casilla['bloque'],
            ':num' => $casilla['numero_nota'],
            ':actual' => $idActividadActual ?? 0,
        ]);
        $conflicto = $stmtColision->fetchColumn();
        if ($conflicto) {
            throw new Exception("Esa casilla ya la usa la actividad \"$conflicto\". Elige otra casilla o quita el vínculo de esa actividad primero.");
        }

        return ['id_periodo' => $idPeriodoPost, 'bloque_notas' => $casilla['bloque'], 'numero_nota' => $casilla['numero_nota']];
    }

    /**
     * Crea UNA actividad (con su vínculo opcional al Cuadro de Notas y su
     * examen real + preguntas si tipo='examen') en la asignación docente
     * $idAsignacion. Usado tanto por la rama "crear" de gestionar_actividades.php
     * (una vez por sección cuando el profesor elige "Publicar a: Todo el
     * grado") como por modules/profesor/impartir_clase.php (botones Asignar
     * tarea/examen/Actividad). $preguntas ya viene decodificado.
     */
    public static function crearActividadEnAsignacion(
        PDO $db, int $tid, int $idAsignacion, int $anno, string $nivel,
        string $titulo, string $descripcion, string $tipo,
        string $fecha_programada, ?string $fecha_limite, ?int $duracion_minutos,
        float $nota_maxima, string $contenido, ?string $url_recurso, string $recursos_url,
        string $estado, ?int $id_periodo_post, ?string $casilla_post, ?array $preguntas,
        int $idProfesor = 0, ?int $idRubricaPlantilla = null
    ): int {
        // Antes solo se permitía vincular tarea/examen al Cuadro de Notas; el
        // usuario pidió explícitamente que "cualquier actividad" pueda
        // marcarse como nota evaluada, así que se resuelve el vínculo sin
        // filtrar por tipo (resolverVinculoCuadroNotas() ya devuelve
        // NULL/NULL/NULL si el profesor dejó ambos selects en "No vincular").
        $vinculo = self::resolverVinculoCuadroNotas($db, $tid, $idAsignacion, $anno, $nivel, $id_periodo_post, $casilla_post, null);

        $id_examen = null;
        if ($tipo === 'examen') {
            $stmtExamen = $db->prepare("INSERT INTO tbl_examen
                (id_asignacion_docente, titulo, descripcion, duracion_minutos, nota_maxima,
                 fecha_programada, fecha_limite, estado)
                VALUES (:asig, :titulo, :descripcion, :duracion, :nota_maxima, :fecha_prog, :fecha_limite, :estado)");
            $stmtExamen->execute([
                ':asig' => $idAsignacion, ':titulo' => $titulo, ':descripcion' => $descripcion,
                ':duracion' => $duracion_minutos, ':nota_maxima' => $nota_maxima,
                ':fecha_prog' => $fecha_programada, ':fecha_limite' => $fecha_limite,
                ':estado' => self::mapEstadoActividadAExamen($estado)
            ]);
            $id_examen = (int) $db->lastInsertId();
            self::guardarPreguntasExamen($db, $id_examen, $preguntas ?? []);
        }

        // tbl_actividad no tiene columna id_institucion. duracion_minutos es
        // TIME en el esquema real (no INT); se convierte con SEC_TO_TIME desde
        // los minutos del formulario.
        $stmt = $db->prepare("INSERT INTO tbl_actividad (id_asignacion_docente, id_examen, id_periodo, bloque_notas, numero_nota,
                  titulo, descripcion, tipo,
                  fecha_programada, fecha_limite, duracion_minutos, nota_maxima,
                  contenido, url_recurso, recursos_url, estado)
                  VALUES (:id_asignacion, :id_examen, :id_periodo, :bloque_notas, :numero_nota,
                          :titulo, :descripcion, :tipo,
                          :fecha_programada, :fecha_limite, SEC_TO_TIME(:duracion * 60), :nota_maxima,
                          :contenido, :url_recurso, :recursos, :estado)");
        $stmt->execute([
            ':id_asignacion' => $idAsignacion,
            ':id_examen' => $id_examen,
            ':id_periodo' => $vinculo['id_periodo'],
            ':bloque_notas' => $vinculo['bloque_notas'],
            ':numero_nota' => $vinculo['numero_nota'],
            ':titulo' => $titulo,
            ':descripcion' => $descripcion,
            ':tipo' => $tipo,
            ':fecha_programada' => $fecha_programada,
            ':fecha_limite' => $fecha_limite,
            ':duracion' => $duracion_minutos,
            ':nota_maxima' => $nota_maxima,
            ':contenido' => $contenido,
            ':url_recurso' => $url_recurso,
            ':recursos' => $recursos_url,
            ':estado' => $estado
        ]);

        $idActividadNueva = (int) $db->lastInsertId();

        // Rúbrica opcional (solo tiene sentido en tareas -- ver #bloque_rubrica
        // en el modal, visible solo cuando tipo === 'tarea'). Cada sección
        // destino recibe su PROPIA copia independiente, igual que cada sección
        // ya recibe su propio examen+preguntas independientes cuando "Publicar
        // a: Todo el grado" está activo -- así, calificar/editar la instancia
        // de una sección nunca afecta a otra.
        if ($tipo === 'tarea' && $idRubricaPlantilla) {
            self::copiarRubricaATactividad($db, $tid, $idProfesor, $idRubricaPlantilla, $idActividadNueva);
        }

        return $idActividadNueva;
    }

    /**
     * Copia una plantilla de rúbrica (id_actividad IS NULL, propiedad de
     * $idProfesor) en una instancia propia de $idActividadNueva. La instancia
     * es una copia completa e independiente -- niveles, criterios y celdas
     * propios -- para que editar la plantilla más adelante nunca afecte
     * retroactivamente una actividad ya creada/calificada (mismo espíritu que
     * la copia tbl_banco_preguntas -> tbl_pregunta_examen ya existente en el
     * proyecto). Devuelve el id de la nueva instancia (tbl_rubrica.id).
     */
    public static function copiarRubricaATactividad(PDO $db, int $tid, int $idProfesor, int $idRubricaPlantilla, int $idActividadNueva): int
    {
        $stmtTpl = $db->prepare("SELECT id, nombre, descripcion FROM tbl_rubrica
            WHERE id = :id AND id_profesor = :prof AND id_institucion = :tid AND id_actividad IS NULL AND estado = 'activo'");
        $stmtTpl->execute([':id' => $idRubricaPlantilla, ':prof' => $idProfesor, ':tid' => $tid]);
        $tpl = $stmtTpl->fetch(PDO::FETCH_ASSOC);
        if (!$tpl) {
            throw new Exception('La rúbrica seleccionada no es válida.');
        }

        $stmtIns = $db->prepare("INSERT INTO tbl_rubrica (id_institucion, id_profesor, id_actividad, id_rubrica_origen, nombre, descripcion, estado)
            VALUES (:tid, :prof, :act, :origen, :nombre, :descripcion, 'activo')");
        $stmtIns->execute([
            ':tid' => $tid, ':prof' => $idProfesor, ':act' => $idActividadNueva, ':origen' => $tpl['id'],
            ':nombre' => $tpl['nombre'], ':descripcion' => $tpl['descripcion'],
        ]);
        $idInstancia = (int) $db->lastInsertId();

        // Niveles (columnas de la matriz)
        $mapNivel = [];
        $stmtNiveles = $db->prepare("SELECT id, nombre, orden FROM tbl_rubrica_nivel WHERE id_rubrica = :id ORDER BY orden");
        $stmtNiveles->execute([':id' => $tpl['id']]);
        $stmtInsNivel = $db->prepare("INSERT INTO tbl_rubrica_nivel (id_rubrica, nombre, orden) VALUES (:rub, :nombre, :orden)");
        foreach ($stmtNiveles->fetchAll(PDO::FETCH_ASSOC) as $niv) {
            $stmtInsNivel->execute([':rub' => $idInstancia, ':nombre' => $niv['nombre'], ':orden' => $niv['orden']]);
            $mapNivel[$niv['id']] = (int) $db->lastInsertId();
        }

        // Criterios (filas de la matriz)
        $mapCriterio = [];
        $stmtCriterios = $db->prepare("SELECT id, nombre, descripcion, orden FROM tbl_rubrica_criterio WHERE id_rubrica = :id ORDER BY orden");
        $stmtCriterios->execute([':id' => $tpl['id']]);
        $stmtInsCriterio = $db->prepare("INSERT INTO tbl_rubrica_criterio (id_rubrica, nombre, descripcion, orden) VALUES (:rub, :nombre, :descripcion, :orden)");
        foreach ($stmtCriterios->fetchAll(PDO::FETCH_ASSOC) as $crit) {
            $stmtInsCriterio->execute([':rub' => $idInstancia, ':nombre' => $crit['nombre'], ':descripcion' => $crit['descripcion'], ':orden' => $crit['orden']]);
            $mapCriterio[$crit['id']] = (int) $db->lastInsertId();
        }

        // Celdas (descriptor + puntaje de cada intersección criterio×nivel).
        // Se leen todas las celdas de la plantilla ORIGEN de una sola vez
        // (join a criterio para acotar por id_rubrica) y se remapean con
        // $mapCriterio/$mapNivel -- nunca se confía en un id ajeno.
        $stmtCeldas = $db->prepare("SELECT ce.id_criterio, ce.id_nivel, ce.descripcion, ce.puntaje
            FROM tbl_rubrica_celda ce
            JOIN tbl_rubrica_criterio cr ON ce.id_criterio = cr.id
            WHERE cr.id_rubrica = :id");
        $stmtCeldas->execute([':id' => $tpl['id']]);
        $stmtInsCelda = $db->prepare("INSERT INTO tbl_rubrica_celda (id_criterio, id_nivel, descripcion, puntaje) VALUES (:crit, :niv, :descripcion, :puntaje)");
        foreach ($stmtCeldas->fetchAll(PDO::FETCH_ASSOC) as $celda) {
            if (!isset($mapCriterio[$celda['id_criterio']], $mapNivel[$celda['id_nivel']])) {
                continue; // fila huérfana defensiva; no debería ocurrir con datos consistentes
            }
            $stmtInsCelda->execute([
                ':crit' => $mapCriterio[$celda['id_criterio']],
                ':niv' => $mapNivel[$celda['id_nivel']],
                ':descripcion' => $celda['descripcion'],
                ':puntaje' => $celda['puntaje'],
            ]);
        }

        return $idInstancia;
    }
}
