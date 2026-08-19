<?php
// config/MensajeHelper.php — Correo interno profesor <-> estudiante.
//
// Centraliza en un solo lugar la regla de "a quién le puedo escribir", para
// que la lista que se le muestra al usuario en el formulario de "Nuevo
// Mensaje" y la revalidación que ocurre al enviar de verdad usen exactamente
// la misma consulta -- si un día cambia la regla (p.ej. qué cuenta como
// "mi estudiante"), se cambia en un solo sitio y ambos lados quedan
// consistentes.
//
// Nunca hay que confiar en el id_usuario que mande el cliente al componer
// un mensaje: el endpoint que procesa el envío SIEMPRE debe volver a llamar
// a puedeProfesorEscribirEstudiante() / puedeProfesorEscribirSeccion() /
// puedeEstudianteEscribirProfesor() antes de insertar nada.

class MensajeHelper {

    /**
     * Estudiantes propios de un profesor: matriculados (activo) este año en
     * alguna sección donde el profesor tenga una asignación docente activa.
     * Devuelve id_estudiante, id_usuario (destinatario real del mensaje),
     * nombre, nie, y datos de sección/grado para agrupar en el selector.
     */
    public static function estudiantesDeProfesor(PDO $db, int $idProfesor, ?int $anno = null): array {
        $anno = $anno ?? (int) date('Y');
        $stmt = $db->prepare("SELECT DISTINCT e.id AS id_estudiante, u.id AS id_usuario, e.nie,
                               per.primer_nombre, per.primer_apellido,
                               s.id AS id_seccion, g.nombre AS grado_nombre, s.nombre AS seccion_nombre
                               FROM tbl_asignacion_docente ad
                               JOIN tbl_seccion s ON ad.id_seccion = s.id
                               JOIN tbl_grado g ON s.id_grado = g.id
                               JOIN tbl_matricula m ON m.id_seccion = s.id AND m.anno = ad.anno AND m.estado = 'activo'
                               JOIN tbl_estudiante e ON m.id_estudiante = e.id
                               JOIN tbl_persona per ON e.id_persona = per.id
                               JOIN tbl_usuario u ON per.id_usuario = u.id
                               WHERE ad.id_profesor = :id_profesor AND ad.anno = :anno AND ad.estado = 1
                               ORDER BY g.nombre, s.nombre, per.primer_apellido, per.primer_nombre");
        $stmt->execute([':id_profesor' => $idProfesor, ':anno' => $anno]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** Secciones propias de un profesor este año (para el envío grupal). */
    public static function seccionesDeProfesor(PDO $db, int $idProfesor, ?int $anno = null): array {
        $anno = $anno ?? (int) date('Y');
        $stmt = $db->prepare("SELECT DISTINCT s.id AS id_seccion, g.nombre AS grado_nombre, s.nombre AS seccion_nombre
                               FROM tbl_asignacion_docente ad
                               JOIN tbl_seccion s ON ad.id_seccion = s.id
                               JOIN tbl_grado g ON s.id_grado = g.id
                               WHERE ad.id_profesor = :id_profesor AND ad.anno = :anno AND ad.estado = 1
                               ORDER BY g.nombre, s.nombre");
        $stmt->execute([':id_profesor' => $idProfesor, ':anno' => $anno]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** ¿Este profesor da clase (asignación activa este año) a este estudiante? */
    public static function puedeProfesorEscribirEstudiante(PDO $db, int $idProfesor, int $idEstudiante, ?int $anno = null): bool {
        $anno = $anno ?? (int) date('Y');
        $stmt = $db->prepare("SELECT 1 FROM tbl_asignacion_docente ad
                               JOIN tbl_matricula m ON m.id_seccion = ad.id_seccion AND m.anno = ad.anno AND m.estado = 'activo'
                               WHERE ad.id_profesor = :id_profesor AND ad.anno = :anno AND ad.estado = 1
                               AND m.id_estudiante = :id_estudiante LIMIT 1");
        $stmt->execute([':id_profesor' => $idProfesor, ':anno' => $anno, ':id_estudiante' => $idEstudiante]);
        return (bool) $stmt->fetchColumn();
    }

    /** ¿Este profesor tiene asignación docente activa este año en esta sección? */
    public static function puedeProfesorEscribirSeccion(PDO $db, int $idProfesor, int $idSeccion, ?int $anno = null): bool {
        $anno = $anno ?? (int) date('Y');
        $stmt = $db->prepare("SELECT 1 FROM tbl_asignacion_docente
                               WHERE id_profesor = :id_profesor AND id_seccion = :id_seccion AND anno = :anno AND estado = 1 LIMIT 1");
        $stmt->execute([':id_profesor' => $idProfesor, ':id_seccion' => $idSeccion, ':anno' => $anno]);
        return (bool) $stmt->fetchColumn();
    }

    /**
     * id_usuario de todos los estudiantes matriculados (activo) en una
     * sección este año -- para armar las filas de tbl_mensaje_destinatario
     * de un envío grupal.
     */
    public static function estudiantesDeSeccion(PDO $db, int $idSeccion, ?int $anno = null): array {
        $anno = $anno ?? (int) date('Y');
        $stmt = $db->prepare("SELECT u.id AS id_usuario
                               FROM tbl_matricula m
                               JOIN tbl_estudiante e ON m.id_estudiante = e.id
                               JOIN tbl_persona per ON e.id_persona = per.id
                               JOIN tbl_usuario u ON per.id_usuario = u.id
                               WHERE m.id_seccion = :id_seccion AND m.anno = :anno AND m.estado = 'activo'");
        $stmt->execute([':id_seccion' => $idSeccion, ':anno' => $anno]);
        return array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'id_usuario');
    }

    /**
     * Profesores propios de un estudiante: los que tienen asignación
     * docente activa en la sección/año de su matrícula activa. El año se
     * toma de la matrícula del estudiante, no hace falta que el llamador
     * lo indique.
     */
    public static function profesoresDeEstudiante(PDO $db, int $idEstudiante): array {
        $stmt = $db->prepare("SELECT DISTINCT pf.id AS id_profesor, u.id AS id_usuario,
                               per.primer_nombre, per.primer_apellido, asig.nombre AS asignatura
                               FROM tbl_matricula m
                               JOIN tbl_asignacion_docente ad ON ad.id_seccion = m.id_seccion AND ad.anno = m.anno AND ad.estado = 1
                               JOIN tbl_profesor pf ON ad.id_profesor = pf.id
                               JOIN tbl_persona per ON pf.id_persona = per.id
                               JOIN tbl_usuario u ON per.id_usuario = u.id
                               JOIN tbl_asignatura asig ON ad.id_asignatura = asig.id
                               WHERE m.id_estudiante = :id_estudiante AND m.estado = 'activo'
                               ORDER BY per.primer_apellido, per.primer_nombre");
        $stmt->execute([':id_estudiante' => $idEstudiante]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** ¿Este profesor le da clase a este estudiante en su matrícula activa actual? */
    public static function puedeEstudianteEscribirProfesor(PDO $db, int $idEstudiante, int $idProfesor): bool {
        $stmt = $db->prepare("SELECT 1 FROM tbl_matricula m
                               JOIN tbl_asignacion_docente ad ON ad.id_seccion = m.id_seccion AND ad.anno = m.anno AND ad.estado = 1
                               WHERE m.id_estudiante = :id_estudiante AND m.estado = 'activo' AND ad.id_profesor = :id_profesor LIMIT 1");
        $stmt->execute([':id_estudiante' => $idEstudiante, ':id_profesor' => $idProfesor]);
        return (bool) $stmt->fetchColumn();
    }

    /** Cantidad de mensajes no leídos de un usuario (badge en el sidebar). */
    public static function contarNoLeidos(PDO $db, int $idUsuario): int {
        $stmt = $db->prepare("SELECT COUNT(*) FROM tbl_mensaje_destinatario WHERE id_usuario_destinatario = :id_usuario AND leido = 0");
        $stmt->execute([':id_usuario' => $idUsuario]);
        return (int) $stmt->fetchColumn();
    }
}
