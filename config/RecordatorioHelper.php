<?php
// config/RecordatorioHelper.php — Recordatorios automáticos de fecha_limite
// próxima en tbl_actividad, entregados por el correo interno (tbl_mensaje /
// tbl_mensaje_destinatario, ver config/MensajeHelper.php).
//
// Este entorno no tiene un cron real corriendo en el servidor, así que el
// barrido se dispara de forma síncrona en cada carga de página, desde dos
// puntos: modules/profesor/partials/header.php (toda página del profesor) y
// modules/estudiante/estudiante_dashboard.php (página de aterrizaje del
// estudiante tras el login). procesarRecordatoriosPendientes() es
// idempotente vía tbl_actividad.recordatorio_enviado -- llamarla muchas
// veces no genera mensajes duplicados.
//
// No existe un usuario "sistema" en este esquema: el recordatorio se manda
// con id_remitente = el propio profesor dueño de la actividad (mismo patrón
// que un aviso de sección normal en enviar_mensaje.php). El profesor puede
// verse a sí mismo como remitente de su propio recordatorio en su bandeja;
// es cosmético, no funcional.

class RecordatorioHelper {

    /**
     * Revisa TODAS las actividades de la institución $tid cuya fecha_limite
     * vence dentro de los próximos $diasAntes días y que no se han
     * notificado todavía, y genera un recordatorio por cada una.
     */
    public static function procesarRecordatoriosPendientes(PDO $db, int $tid, int $diasAntes = 2): void {
        $stmt = $db->prepare("SELECT a.id, a.titulo, a.fecha_limite, ad.id_seccion, ad.anno,
                               up.id AS id_usuario_profesor
                               FROM tbl_actividad a
                               JOIN tbl_asignacion_docente ad ON a.id_asignacion_docente = ad.id
                               JOIN tbl_profesor pf ON ad.id_profesor = pf.id
                               JOIN tbl_persona perp ON pf.id_persona = perp.id
                               JOIN tbl_usuario up ON perp.id_usuario = up.id
                               WHERE pf.id_institucion = :tid
                               AND a.recordatorio_enviado = 0
                               AND a.estado NOT IN ('eliminado','cerrado')
                               AND a.fecha_limite IS NOT NULL
                               AND a.fecha_limite BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL :dias DAY)");
        $stmt->bindValue(':tid', $tid, PDO::PARAM_INT);
        $stmt->bindValue(':dias', $diasAntes, PDO::PARAM_INT);
        $stmt->execute();
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $act) {
            self::generarRecordatorio($db, $tid, $act);
        }
    }

    /**
     * Genera el recordatorio de UNA actividad: un tbl_mensaje tipo='seccion'
     * dirigido a los estudiantes matriculados activos de esa sección más el
     * profesor dueño de la actividad, y marca recordatorio_enviado = 1.
     * Transacción propia por actividad (mismo patrón "una transacción por
     * fila" que modules/admin/api/importar_estudiantes.php) -- si una
     * actividad falla, no bloquea el resto del barrido.
     */
    private static function generarRecordatorio(PDO $db, int $tid, array $act): void {
        require_once __DIR__ . '/MensajeHelper.php';
        $destinatarios = array_unique(array_merge(
            array_map('intval', MensajeHelper::estudiantesDeSeccion($db, (int) $act['id_seccion'], (int) $act['anno'])),
            [(int) $act['id_usuario_profesor']]
        ));

        try {
            $db->beginTransaction();

            $fechaFmt = date('d/m/Y H:i', strtotime($act['fecha_limite']));
            $stmtMsg = $db->prepare("INSERT INTO tbl_mensaje (id_institucion, id_remitente, asunto, cuerpo, tipo, id_seccion_destino)
                                      VALUES (:tid, :remitente, :asunto, :cuerpo, 'seccion', :seccion)");
            $stmtMsg->execute([
                ':tid' => $tid,
                ':remitente' => (int) $act['id_usuario_profesor'],
                ':asunto' => 'Recordatorio: "' . $act['titulo'] . '" vence pronto',
                ':cuerpo' => 'La actividad "' . $act['titulo'] . '" vence el ' . $fechaFmt . '.',
                ':seccion' => (int) $act['id_seccion'],
            ]);
            $idMensaje = (int) $db->lastInsertId();

            $stmtDest = $db->prepare("INSERT INTO tbl_mensaje_destinatario (id_mensaje, id_usuario_destinatario) VALUES (:msg, :usr)");
            foreach ($destinatarios as $idUsr) {
                $stmtDest->execute([':msg' => $idMensaje, ':usr' => $idUsr]);
            }

            $db->prepare("UPDATE tbl_actividad SET recordatorio_enviado = 1 WHERE id = :id")
               ->execute([':id' => $act['id']]);

            $db->commit();
        } catch (Exception $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            error_log('[RecordatorioHelper] actividad ' . $act['id'] . ': ' . $e->getMessage());
        }
    }
}
