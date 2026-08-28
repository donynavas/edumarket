<?php
// config/TenantGuard.php — Prioridad 0: aislamiento obligatorio de datos entre instituciones.
//
// Uso obligatorio en toda consulta que toque una tabla con columna id_institucion:
//
//   require_once __DIR__ . '/../../config/TenantGuard.php';
//   $tid = TenantGuard::id();               // id de la institución de la sesión actual
//   ... "WHERE ... AND id_institucion = :tid" ...
//   $stmt->execute([':tid' => $tid, ...]);
//
// Para operaciones sobre un registro específico recibido por GET/POST (edición,
// borrado, detalle) SIEMPRE verificar propiedad con TenantGuard::assertOwner()
// antes de tocar el registro, para evitar IDOR (que un usuario de la institución A
// edite/borre un registro de la institución B adivinando o iterando el ID).

class TenantGuard {

    /**
     * Devuelve el id_institucion de la sesión autenticada actual.
     * Corta la ejecución si no hay sesión de institución válida — nunca debe
     * ejecutarse una consulta a datos de tenant sin este valor.
     */
    public static function id(): int {
        if (empty($_SESSION['id_institucion'])) {
            http_response_code(403);
            die('Sesión inválida: institución no resuelta.');
        }
        return (int) $_SESSION['id_institucion'];
    }

    /**
     * Verifica que un registro específico pertenezca a la institución de la
     * sesión actual antes de permitir leerlo/editarlo/borrarlo.
     *
     * @param PDO    $db        Conexión activa.
     * @param string $table     Tabla a validar (debe tener columna id_institucion).
     * @param int    $recordId  Id del registro recibido del cliente (GET/POST).
     * @param string $idColumn  Nombre de la columna de id (por defecto "id").
     */
    /**
     * Algunas tablas no tienen columna id_institucion propia (se confirmó
     * contra el esquema real). Para esas, la pertenencia a la institución
     * se prueba a través de una tabla relacionada que sí la tiene. Sin este
     * mapa, assertOwner() lanzaría "Unknown column 'id_institucion'" en
     * cada acción (crear examen, matricular, guardar nota, etc.) que
     * dependiera de estas tablas.
     */
    private static array $viaTenantColumn = [
        'tbl_entrega_actividad'      => "JOIN tbl_matricula __r1 ON t.id_matricula = __r1.id JOIN tbl_seccion __r2 ON __r1.id_seccion = __r2.id",
        'tbl_asignacion_docente'     => "JOIN tbl_profesor __r1 ON t.id_profesor = __r1.id",
        'tbl_matricula'              => "JOIN tbl_seccion __r1 ON t.id_seccion = __r1.id",
        'tbl_examen'                 => "JOIN tbl_asignacion_docente __r1 ON t.id_asignacion_docente = __r1.id JOIN tbl_profesor __r2 ON __r1.id_profesor = __r2.id",
        'tbl_bienestar_alerta'       => "JOIN tbl_estudiante __r1 ON t.id_estudiante = __r1.id",
        'tbl_bienestar_seguimiento'  => "JOIN tbl_estudiante __r1 ON t.id_estudiante = __r1.id",
        'tbl_manual_convivencia_comite'  => "JOIN tbl_manual_convivencia __r1 ON t.id_manual = __r1.id",
        'tbl_manual_convivencia_seccion' => "JOIN tbl_manual_convivencia __r1 ON t.id_manual = __r1.id",
        'tbl_clase_recurso'              => "JOIN tbl_clase_impartida __r1 ON t.id_clase = __r1.id",
        'tbl_expediente_docente'         => "JOIN tbl_profesor __r1 ON t.id_profesor = __r1.id",
        'tbl_expediente_estudio'         => "JOIN tbl_profesor __r1 ON t.id_profesor = __r1.id",
        'tbl_expediente_capacitacion'    => "JOIN tbl_profesor __r1 ON t.id_profesor = __r1.id",
        'tbl_expediente_experiencia'     => "JOIN tbl_profesor __r1 ON t.id_profesor = __r1.id",
        'tbl_expediente_documento'       => "JOIN tbl_profesor __r1 ON t.id_profesor = __r1.id",
        'tbl_horario_clase'               => "JOIN tbl_asignacion_docente __r1 ON t.id_asignacion_docente = __r1.id JOIN tbl_profesor __r2 ON __r1.id_profesor = __r2.id",
    ];

    public static function assertOwner(PDO $db, string $table, int $recordId, string $idColumn = 'id'): void {
        if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $table) || !preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $idColumn)) {
            http_response_code(400);
            die('Parámetro inválido.');
        }
        $tid = self::id();
        if (isset(self::$viaTenantColumn[$table])) {
            $join = self::$viaTenantColumn[$table];
            // El último alias del join (__r1 o __r2) es el que aporta id_institucion.
            $tenantAlias = (strpos($join, '__r2') !== false) ? '__r2' : '__r1';
            $sql = "SELECT 1 FROM `$table` t $join WHERE t.`$idColumn` = :rid AND {$tenantAlias}.id_institucion = :tid LIMIT 1";
        } else {
            $sql = "SELECT 1 FROM `$table` WHERE `$idColumn` = :rid AND id_institucion = :tid LIMIT 1";
        }
        $stmt = $db->prepare($sql);
        $stmt->execute([':rid' => $recordId, ':tid' => $tid]);
        if (!$stmt->fetchColumn()) {
            http_response_code(403);
            die('No tiene permiso para acceder a este recurso.');
        }
    }

    /** Fragmento SQL reutilizable: " AND {alias}id_institucion = :tid" */
    public static function sql(string $aliasPrefix = ''): string {
        return " AND {$aliasPrefix}id_institucion = :tid ";
    }
}
