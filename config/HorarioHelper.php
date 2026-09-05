<?php
/**
 * Helper del módulo "Horario de Clases". Igual que config/PeriodoHelper.php
 * y config/ManualConvivenciaHelper.php: siembra bajo demanda (los bloques
 * horarios por defecto) + lógica de negocio (buscar/crear la asignación
 * docente subyacente, y validar choques de horario) reutilizable desde
 * modules/admin/horario_clases.php.
 */
require_once __DIR__ . '/CatalogoHorario.php';

class HorarioHelper
{
    /**
     * Siembra los bloques horarios por defecto (CatalogoHorario::BLOQUES_SEED_DEFAULT)
     * SOLO si la institución todavía no tiene ningún bloque -- a
     * diferencia de las secciones del Manual de Convivencia (que se
     * siembran por código, con ON DUPLICATE KEY), aquí no hay una llave
     * natural fija: el director puede editar/borrar libremente los
     * bloques después, así que la siembra debe ser de una sola vez (mismo
     * criterio que ManualConvivenciaHelper::asegurarMarcoLegal()).
     */
    public static function asegurarBloquesPorDefecto(PDO $db, int $tid): void
    {
        $count = $db->prepare("SELECT COUNT(*) FROM tbl_bloque_horario WHERE id_institucion = :tid");
        $count->execute([':tid' => $tid]);
        if ((int) $count->fetchColumn() > 0) {
            return;
        }

        $insert = $db->prepare(
            "INSERT INTO tbl_bloque_horario (id_institucion, turno, numero, nombre, hora_inicio, hora_fin, es_receso)
             VALUES (:tid, :turno, :numero, :nombre, :inicio, :fin, :receso)"
        );
        foreach (CatalogoHorario::BLOQUES_SEED_DEFAULT as $turno => $bloques) {
            foreach ($bloques as $b) {
                $insert->execute([
                    ':tid' => $tid,
                    ':turno' => $turno,
                    ':numero' => $b['numero'],
                    ':nombre' => $b['nombre'],
                    ':inicio' => $b['hora_inicio'],
                    ':fin' => $b['hora_fin'],
                    ':receso' => $b['es_receso'],
                ]);
            }
        }
    }

    /**
     * Busca una fila existente de tbl_asignacion_docente para
     * profesor+asignatura+sección+año; si no existe, la crea. Mismo
     * INSERT que asignarMaterias() en gestionar_profesores.php, pero
     * deduplicando -- esa función no valida duplicados, y aquí sí importa
     * porque el director puede reabrir el horario y volver a intentar
     * asignar la misma combinación sin querer crear una fila repetida.
     */
    public static function buscarOCrearAsignacion(PDO $db, int $idProfesor, int $idAsignatura, int $idSeccion, int $anno): int
    {
        $stmt = $db->prepare(
            "SELECT id FROM tbl_asignacion_docente
             WHERE id_profesor = :prof AND id_asignatura = :asig AND id_seccion = :sec AND anno = :anno AND estado = 1"
        );
        $stmt->execute([':prof' => $idProfesor, ':asig' => $idAsignatura, ':sec' => $idSeccion, ':anno' => $anno]);
        $id = $stmt->fetchColumn();
        if ($id !== false) {
            return (int) $id;
        }

        $insert = $db->prepare(
            "INSERT INTO tbl_asignacion_docente (id_profesor, id_asignatura, id_seccion, anno, estado)
             VALUES (:prof, :asig, :sec, :anno, 1)"
        );
        $insert->execute([':prof' => $idProfesor, ':asig' => $idAsignatura, ':sec' => $idSeccion, ':anno' => $anno]);
        return (int) $db->lastInsertId();
    }

    /**
     * Valida que asignar a $idProfesor una clase el día $diaSemana en el
     * bloque $idBloque no choque con: (a) otra clase que YA tenga ese
     * mismo profesor a esa hora (en cualquier sección), o (b) otra clase
     * que YA tenga esa misma sección a esa hora (con cualquier profesor).
     * Lanza Exception con un mensaje descriptivo si hay choque; no
     * retorna nada si está libre. $excluirIdHorario permite excluir la
     * propia fila al reasignar (hoy el módulo no reasigna in-place, pero
     * se deja listo para no tener que tocar la firma después).
     */
    public static function validarConflicto(
        PDO $db,
        int $idProfesor,
        int $idSeccion,
        int $anno,
        int $diaSemana,
        int $idBloque,
        ?int $excluirIdHorario = null
    ): void {
        // (a) Choque de DOCENTE: mismo profesor, mismo día+bloque, en
        // cualquier otra sección.
        $sqlProfesor = "SELECT per.primer_nombre, per.primer_apellido, s.nombre AS seccion_nombre, g.nombre AS grado_nombre, a.nombre AS asignatura_nombre
             FROM tbl_horario_clase hc
             JOIN tbl_asignacion_docente ad ON hc.id_asignacion_docente = ad.id
             JOIN tbl_profesor p ON ad.id_profesor = p.id
             JOIN tbl_persona per ON p.id_persona = per.id
             JOIN tbl_seccion s ON ad.id_seccion = s.id
             JOIN tbl_grado g ON s.id_grado = g.id
             JOIN tbl_asignatura a ON ad.id_asignatura = a.id
             WHERE ad.id_profesor = :prof AND ad.anno = :anno AND hc.dia_semana = :dia AND hc.id_bloque = :bloque";
        $paramsProfesor = [':prof' => $idProfesor, ':anno' => $anno, ':dia' => $diaSemana, ':bloque' => $idBloque];
        if ($excluirIdHorario !== null) {
            $sqlProfesor .= " AND hc.id != :excluir";
            $paramsProfesor[':excluir'] = $excluirIdHorario;
        }
        $stmt = $db->prepare($sqlProfesor);
        $stmt->execute($paramsProfesor);
        $choque = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($choque) {
            throw new Exception(
                'El docente ' . $choque['primer_nombre'] . ' ' . $choque['primer_apellido']
                . ' ya tiene clase de ' . $choque['asignatura_nombre'] . ' con ' . $choque['grado_nombre'] . ' "' . $choque['seccion_nombre'] . '"'
                . ' en ese día y bloque.'
            );
        }

        // (b) Choque de SECCIÓN: misma sección, mismo día+bloque, con
        // cualquier otro profesor/materia.
        $sqlSeccion = "SELECT per.primer_nombre, per.primer_apellido, a.nombre AS asignatura_nombre
             FROM tbl_horario_clase hc
             JOIN tbl_asignacion_docente ad ON hc.id_asignacion_docente = ad.id
             JOIN tbl_profesor p ON ad.id_profesor = p.id
             JOIN tbl_persona per ON p.id_persona = per.id
             JOIN tbl_asignatura a ON ad.id_asignatura = a.id
             WHERE ad.id_seccion = :sec AND ad.anno = :anno AND hc.dia_semana = :dia AND hc.id_bloque = :bloque";
        $paramsSeccion = [':sec' => $idSeccion, ':anno' => $anno, ':dia' => $diaSemana, ':bloque' => $idBloque];
        if ($excluirIdHorario !== null) {
            $sqlSeccion .= " AND hc.id != :excluir";
            $paramsSeccion[':excluir'] = $excluirIdHorario;
        }
        $stmt = $db->prepare($sqlSeccion);
        $stmt->execute($paramsSeccion);
        $choque = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($choque) {
            throw new Exception(
                'Esa sección ya tiene clase de ' . $choque['asignatura_nombre'] . ' con '
                . $choque['primer_nombre'] . ' ' . $choque['primer_apellido'] . ' en ese día y bloque.'
            );
        }
    }

    /**
     * Días de la semana (1=Lunes..5=Viernes, mismo criterio que
     * CatalogoHorario::DIAS_SEMANA y que PHP date('N')) en los que esta
     * asignación docente tiene clase programada según el Horario de
     * Clases -- sin duplicados aunque tenga más de un bloque el mismo
     * día (ej. una materia con doble hora un lunes solo cuenta "lunes"
     * una vez, porque tbl_clase_impartida es una bitácora por FECHA, no
     * por bloque). Devuelve un array vacío si la asignación todavía no
     * tiene ningún horario asignado.
     */
    public static function diasConHorario(PDO $db, int $idAsignacionDocente): array
    {
        $stmt = $db->prepare(
            "SELECT DISTINCT dia_semana FROM tbl_horario_clase WHERE id_asignacion_docente = :asig ORDER BY dia_semana"
        );
        $stmt->execute([':asig' => $idAsignacionDocente]);
        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    /**
     * Genera automáticamente una fila de bitácora (tbl_clase_impartida,
     * estado='borrador') por cada fecha entre $fechaInicio y $fechaFin
     * cuyo día de la semana esté en el Horario de Clases de esta
     * asignación -- idea tomada de "escuela" (Cursos::crearClases(), que
     * recorre día a día un rango de fechas comparando contra el horario
     * semanal), adaptada al modelo real de este proyecto: en vez de
     * confiar en datos posteados, aquí SIEMPRE se relee el horario ya
     * guardado en tbl_horario_clase (fuente única de verdad) y solo se
     * usan $fechaInicio/$fechaFin como rango, evitando duplicar una
     * sesión que ya exista para esa fecha (reintentar el mismo rango dos
     * veces es un no-op para las fechas ya generadas).
     *
     * Devuelve ['creadas' => int, 'omitidas' => int] -- "omitidas" son
     * fechas que caían en un día con horario pero que ya tenían una
     * clase registrada para esta asignación.
     *
     * Lanza Exception si: la asignación no tiene ningún horario
     * configurado, el rango es inválido (fin antes de inicio), o el
     * rango supera un año (tope defensivo contra un typo de año que
     * generaría miles de filas).
     */
    public static function generarSesiones(PDO $db, int $tid, int $idAsignacionDocente, int $userId, string $fechaInicio, string $fechaFin): array
    {
        $dias = self::diasConHorario($db, $idAsignacionDocente);
        if (empty($dias)) {
            throw new Exception('Esta asignación todavía no tiene ningún horario configurado. Ve a Horario de Clases y agrégale al menos un día antes de generar sesiones.');
        }

        $inicio = DateTime::createFromFormat('Y-m-d', $fechaInicio) ?: null;
        $fin = DateTime::createFromFormat('Y-m-d', $fechaFin) ?: null;
        if (!$inicio || !$fin) {
            throw new Exception('Fecha de inicio o fin inválida.');
        }
        if ($fin < $inicio) {
            throw new Exception('La fecha de fin no puede ser anterior a la fecha de inicio.');
        }
        if ($inicio->diff($fin)->days > 366) {
            throw new Exception('El rango de fechas no puede superar un año -- revisa que las fechas sean correctas.');
        }

        // Fechas que ya tienen una clase registrada para esta asignación,
        // para no duplicar si el director/profesor repite el mismo rango.
        $stmtExistentes = $db->prepare("SELECT fecha_clase FROM tbl_clase_impartida WHERE id_asignacion_docente = :asig");
        $stmtExistentes->execute([':asig' => $idAsignacionDocente]);
        $existentes = array_flip($stmtExistentes->fetchAll(PDO::FETCH_COLUMN));

        // Continúa la numeración después del número más alto ya usado
        // (si los números existentes no son todos numéricos, ej. porque
        // se escribieron a mano como "3-A", simplemente empieza en 1).
        $stmtMax = $db->prepare("SELECT numero_clase FROM tbl_clase_impartida WHERE id_asignacion_docente = :asig");
        $stmtMax->execute([':asig' => $idAsignacionDocente]);
        $siguienteNumero = 1;
        foreach ($stmtMax->fetchAll(PDO::FETCH_COLUMN) as $n) {
            if (ctype_digit((string) $n) && (int) $n >= $siguienteNumero) {
                $siguienteNumero = (int) $n + 1;
            }
        }

        $insert = $db->prepare(
            "INSERT INTO tbl_clase_impartida (id_institucion, id_asignacion_docente, numero_clase, fecha_clase, estado, created_by)
             VALUES (:tid, :asig, :num, :fecha, 'borrador', :creator)"
        );

        $creadas = 0;
        $omitidas = 0;
        $cursor = clone $inicio;
        while ($cursor <= $fin) {
            $diaSemana = (int) $cursor->format('N'); // 1=Lunes..7=Domingo, igual que dia_semana
            $fechaStr = $cursor->format('Y-m-d');
            if (in_array($diaSemana, $dias, true)) {
                if (isset($existentes[$fechaStr])) {
                    $omitidas++;
                } else {
                    $insert->execute([
                        ':tid' => $tid, ':asig' => $idAsignacionDocente, ':num' => (string) $siguienteNumero,
                        ':fecha' => $fechaStr, ':creator' => $userId,
                    ]);
                    $existentes[$fechaStr] = true;
                    $siguienteNumero++;
                    $creadas++;
                }
            }
            $cursor->modify('+1 day');
        }

        return ['creadas' => $creadas, 'omitidas' => $omitidas];
    }
}
