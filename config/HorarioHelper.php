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
}
