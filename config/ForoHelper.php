<?php
/**
 * Helper del "Foro de la Clase" -- un muro de mensajes simple (sin hilos
 * anidados, texto plano con saltos de línea, mismo criterio que
 * config/MensajeHelper.php/tbl_mensaje) colgado de cada fila de
 * tbl_clase_impartida, para que el profesor comparta algo con los
 * estudiantes de esa clase y ellos puedan responder.
 */
class ForoHelper
{
    const MENSAJE_MAX_LARGO = 3000;

    /**
     * Mensajes de una clase, del más antiguo al más nuevo (orden de
     * conversación). Incluye el nombre del autor vía tbl_persona.id_usuario
     * (funciona igual para profesor o estudiante, sin tener que resolver
     * primero de qué tabla viene).
     */
    public static function mensajesDeClase(PDO $db, int $idClase): array
    {
        $stmt = $db->prepare(
            "SELECT fm.id, fm.mensaje, fm.autor_rol, fm.created_at, fm.id_usuario,
                    per.primer_nombre, per.primer_apellido
             FROM tbl_foro_mensaje fm
             JOIN tbl_persona per ON per.id_usuario = fm.id_usuario
             WHERE fm.id_clase = :clase
             ORDER BY fm.created_at ASC, fm.id ASC"
        );
        $stmt->execute([':clase' => $idClase]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Publica un mensaje nuevo en el foro de una clase. $idClase/$tid ya
     * deben haber sido verificados por el llamador (resolverClasePropia()
     * del lado profesor, o estudianteAccesoClase() de este helper del lado
     * estudiante) -- esta función no vuelve a validar pertenencia, solo
     * contenido. Devuelve el id del mensaje creado.
     */
    public static function publicar(PDO $db, int $tid, int $idClase, int $idUsuario, string $rol, string $mensaje): int
    {
        $mensaje = trim($mensaje);
        if ($mensaje === '') {
            throw new Exception('El mensaje no puede estar vacío.');
        }
        if (mb_strlen($mensaje) > self::MENSAJE_MAX_LARGO) {
            throw new Exception('El mensaje es demasiado largo (máximo ' . self::MENSAJE_MAX_LARGO . ' caracteres).');
        }
        if (!in_array($rol, ['profesor', 'estudiante'], true)) {
            throw new Exception('Rol de autor no válido.');
        }

        $stmt = $db->prepare(
            "INSERT INTO tbl_foro_mensaje (id_clase, id_institucion, id_usuario, autor_rol, mensaje)
             VALUES (:clase, :tid, :usuario, :rol, :mensaje)"
        );
        $stmt->execute([
            ':clase' => $idClase, ':tid' => $tid, ':usuario' => $idUsuario,
            ':rol' => $rol, ':mensaje' => $mensaje,
        ]);
        return (int) $db->lastInsertId();
    }

    /**
     * Verifica que $idEstudiante (matrícula activa este año) tenga acceso
     * al foro de $idClase -- es decir, que la clase cuelgue de una
     * asignación docente cuya sección sea la sección donde el estudiante
     * está matriculado ese mismo año. Devuelve los datos de la clase (con
     * asignatura/grado/sección/profesor) si tiene acceso, o null si no.
     */
    public static function estudianteAccesoClase(PDO $db, int $idClase, int $idEstudiante, int $tid): ?array
    {
        $stmt = $db->prepare(
            "SELECT c.id, c.numero_clase, c.fecha_clase, c.objetivo, c.estado,
                    ad.id AS id_asignacion_docente, ad.anno,
                    asig.nombre AS asignatura_nombre, g.nombre AS grado_nombre, s.nombre AS seccion_nombre,
                    per.primer_nombre AS prof_nombre, per.primer_apellido AS prof_apellido
             FROM tbl_clase_impartida c
             JOIN tbl_asignacion_docente ad ON c.id_asignacion_docente = ad.id
             JOIN tbl_asignatura asig ON ad.id_asignatura = asig.id
             JOIN tbl_seccion s ON ad.id_seccion = s.id
             JOIN tbl_grado g ON s.id_grado = g.id
             JOIN tbl_profesor p ON ad.id_profesor = p.id
             JOIN tbl_persona per ON p.id_persona = per.id
             JOIN tbl_matricula m ON m.id_seccion = ad.id_seccion AND m.anno = ad.anno AND m.estado = 'activo'
             WHERE c.id = :clase AND c.id_institucion = :tid AND m.id_estudiante = :estudiante"
        );
        $stmt->execute([':clase' => $idClase, ':tid' => $tid, ':estudiante' => $idEstudiante]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /**
     * Bitácora de clases (id, numero_clase, fecha_clase, cantidad de
     * mensajes del foro) de una asignación, para listarlas en
     * ver_materia.php -- mismo criterio de "todas las clases creadas, sin
     * filtrar por estado" que impartir_clase.php le muestra al profesor:
     * el foro existe desde que el profesor crea/guarda la clase, no solo
     * cuando la marca como impartida.
     */
    public static function bitacoraConForo(PDO $db, int $idAsignacionDocente): array
    {
        $stmt = $db->prepare(
            "SELECT c.id, c.numero_clase, c.fecha_clase, c.estado,
                    (SELECT COUNT(*) FROM tbl_foro_mensaje fm WHERE fm.id_clase = c.id) AS total_mensajes
             FROM tbl_clase_impartida c
             WHERE c.id_asignacion_docente = :asig
             ORDER BY c.fecha_clase DESC, c.id DESC"
        );
        $stmt->execute([':asig' => $idAsignacionDocente]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
