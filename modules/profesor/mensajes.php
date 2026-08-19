<?php
session_start();
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/TenantGuard.php';

if (!isset($_SESSION['user_id']) || $_SESSION['rol'] != 'profesor') {
    header("Location: " . BASE_URL . "/login.php");
    exit;
}

$database = new Database();
$db = $database->getConnection();
$user_id = $_SESSION['user_id'];
$tid = TenantGuard::id();

$stmt = $db->prepare("SELECT p.id as id_profesor, per.primer_nombre, per.primer_apellido
                       FROM tbl_profesor p
                       JOIN tbl_persona per ON p.id_persona = per.id
                       WHERE per.id_usuario = :user_id AND p.id_institucion = :tid");
$stmt->bindValue(':user_id', $user_id, PDO::PARAM_INT);
$stmt->bindValue(':tid', $tid, PDO::PARAM_INT);
$stmt->execute();
$profesor = $stmt->fetch(PDO::FETCH_ASSOC);

// ===== RECIBIDOS =====
$stmt = $db->prepare("SELECT m.id, m.asunto, m.tipo, m.fecha_envio, d.leido,
                       per.primer_nombre AS remitente_nombre, per.primer_apellido AS remitente_apellido
                       FROM tbl_mensaje_destinatario d
                       JOIN tbl_mensaje m ON d.id_mensaje = m.id
                       JOIN tbl_usuario ru ON m.id_remitente = ru.id
                       JOIN tbl_persona per ON per.id_usuario = ru.id
                       WHERE d.id_usuario_destinatario = :uid
                       ORDER BY m.fecha_envio DESC");
$stmt->execute([':uid' => $user_id]);
$recibidos = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ===== ENVIADOS =====
$stmt = $db->prepare("SELECT m.id, m.asunto, m.tipo, m.fecha_envio,
                       s.nombre AS seccion_nombre, g.nombre AS grado_nombre,
                       (SELECT COUNT(*) FROM tbl_mensaje_destinatario WHERE id_mensaje = m.id) AS total_destinatarios,
                       (SELECT COUNT(*) FROM tbl_mensaje_destinatario WHERE id_mensaje = m.id AND leido = 1) AS total_leidos,
                       (CASE WHEN m.tipo = 'individual' THEN (
                            SELECT CONCAT(p2.primer_nombre, ' ', p2.primer_apellido)
                            FROM tbl_mensaje_destinatario d2
                            JOIN tbl_usuario u2 ON d2.id_usuario_destinatario = u2.id
                            JOIN tbl_persona p2 ON p2.id_usuario = u2.id
                            WHERE d2.id_mensaje = m.id LIMIT 1
                       ) END) AS destinatario_nombre
                       FROM tbl_mensaje m
                       LEFT JOIN tbl_seccion s ON m.id_seccion_destino = s.id
                       LEFT JOIN tbl_grado g ON s.id_grado = g.id
                       WHERE m.id_remitente = :uid
                       ORDER BY m.fecha_envio DESC");
$stmt->execute([':uid' => $user_id]);
$enviados = $stmt->fetchAll(PDO::FETCH_ASSOC);

$totalNoLeidos = count(array_filter($recibidos, fn($m) => !$m['leido']));

$activePage = 'mensajes';
$pageTitle = 'Mensajes - Educación Plus';
ob_start();
?>
<style>
    .card-custom { background: white; border-radius: 12px; box-shadow: 0 2px 12px rgba(0,0,0,0.08); border: none; }
    .fila-mensaje { cursor: pointer; }
    .fila-mensaje.no-leido { font-weight: 600; background: #f0f7ff; }
    .fila-mensaje:hover { background: #f8f9fa; }
</style>
<?php
$extraHead = ob_get_clean();
require __DIR__ . '/partials/header.php';
?>
        <div class="d-flex justify-content-between align-items-center mb-4">
            <div>
                <h2><i class="fas fa-envelope"></i> Mensajes</h2>
                <p class="text-muted mb-0">Correo interno con tus estudiantes</p>
            </div>
            <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#modalNuevoMensaje" onclick="prepararNuevoMensaje()">
                <i class="fas fa-plus"></i> Nuevo Mensaje
            </button>
        </div>

        <ul class="nav nav-tabs mb-3">
            <li class="nav-item">
                <button class="nav-link active" data-bs-toggle="tab" data-bs-target="#tabRecibidos">
                    Recibidos <?php if ($totalNoLeidos > 0): ?><span class="badge bg-danger rounded-pill"><?= $totalNoLeidos ?></span><?php endif; ?>
                </button>
            </li>
            <li class="nav-item">
                <button class="nav-link" data-bs-toggle="tab" data-bs-target="#tabEnviados">Enviados</button>
            </li>
        </ul>

        <div class="tab-content">
            <div class="tab-pane fade show active" id="tabRecibidos">
                <div class="card-custom">
                    <div class="table-responsive">
                        <table class="table table-hover mb-0">
                            <thead class="table-light">
                                <tr><th>De</th><th>Asunto</th><th>Fecha</th><th></th></tr>
                            </thead>
                            <tbody>
                                <?php if (empty($recibidos)): ?>
                                <tr><td colspan="4" class="text-center text-muted py-4">No tienes mensajes recibidos.</td></tr>
                                <?php endif; ?>
                                <?php foreach ($recibidos as $m): ?>
                                <tr class="fila-mensaje<?= $m['leido'] ? '' : ' no-leido' ?>" onclick="verMensaje(<?= $m['id'] ?>)">
                                    <td><?= htmlspecialchars($m['remitente_nombre'] . ' ' . $m['remitente_apellido']) ?></td>
                                    <td><?= htmlspecialchars($m['asunto']) ?> <?php if ($m['tipo'] === 'seccion'): ?><span class="badge bg-info">Aviso de sección</span><?php endif; ?></td>
                                    <td><small><?= date('d/m/Y H:i', strtotime($m['fecha_envio'])) ?></small></td>
                                    <td><?php if (!$m['leido']): ?><span class="badge bg-primary">Nuevo</span><?php endif; ?></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
            <div class="tab-pane fade" id="tabEnviados">
                <div class="card-custom">
                    <div class="table-responsive">
                        <table class="table table-hover mb-0">
                            <thead class="table-light">
                                <tr><th>Para</th><th>Asunto</th><th>Fecha</th><th>Leído</th></tr>
                            </thead>
                            <tbody>
                                <?php if (empty($enviados)): ?>
                                <tr><td colspan="4" class="text-center text-muted py-4">No has enviado mensajes todavía.</td></tr>
                                <?php endif; ?>
                                <?php foreach ($enviados as $m): ?>
                                <tr class="fila-mensaje" onclick="verMensaje(<?= $m['id'] ?>)">
                                    <td>
                                        <?php if ($m['tipo'] === 'seccion'): ?>
                                        <span class="badge bg-info">Sección <?= htmlspecialchars($m['grado_nombre'] . ' ' . $m['seccion_nombre']) ?></span>
                                        <?php else: ?>
                                        <?= htmlspecialchars($m['destinatario_nombre'] ?? '(estudiante eliminado)') ?>
                                        <?php endif; ?>
                                    </td>
                                    <td><?= htmlspecialchars($m['asunto']) ?></td>
                                    <td><small><?= date('d/m/Y H:i', strtotime($m['fecha_envio'])) ?></small></td>
                                    <td><small><?= (int) $m['total_leidos'] ?>/<?= (int) $m['total_destinatarios'] ?></small></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <!-- Modal Nuevo Mensaje -->
        <div class="modal fade" id="modalNuevoMensaje" tabindex="-1">
            <div class="modal-dialog modal-lg">
                <div class="modal-content">
                    <div class="modal-header bg-primary text-white">
                        <h5 class="modal-title"><i class="fas fa-pen"></i> Nuevo Mensaje</h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <input type="hidden" id="idMensajePadre" value="">
                        <div class="mb-3">
                            <label class="form-label d-block">Enviar a</label>
                            <div class="btn-group" role="group">
                                <input type="radio" class="btn-check" name="tipoDestino" id="tipoIndividual" value="individual" checked onchange="cambiarTipoDestino()">
                                <label class="btn btn-outline-primary" for="tipoIndividual"><i class="fas fa-user"></i> Un estudiante</label>
                                <input type="radio" class="btn-check" name="tipoDestino" id="tipoSeccion" value="seccion" onchange="cambiarTipoDestino()">
                                <label class="btn btn-outline-primary" for="tipoSeccion"><i class="fas fa-users"></i> Toda una sección</label>
                            </div>
                        </div>
                        <div class="mb-3" id="grupoEstudiante">
                            <label class="form-label">Estudiante</label>
                            <select class="form-select" id="selectEstudiante"><option value="">Cargando...</option></select>
                        </div>
                        <div class="mb-3 d-none" id="grupoSeccion">
                            <label class="form-label">Sección</label>
                            <select class="form-select" id="selectSeccion"><option value="">Cargando...</option></select>
                            <small class="text-muted">Se enviará a todos los estudiantes matriculados activos de esa sección.</small>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Asunto</label>
                            <input type="text" class="form-control" id="inputAsunto" maxlength="200">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Mensaje</label>
                            <textarea class="form-control" id="inputCuerpo" rows="5"></textarea>
                        </div>
                        <div id="errorEnvio" class="alert alert-danger d-none"></div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                        <button type="button" class="btn btn-primary" onclick="enviarMensaje()"><i class="fas fa-paper-plane"></i> Enviar</button>
                    </div>
                </div>
            </div>
        </div>

        <!-- Modal Ver Mensaje -->
        <div class="modal fade" id="modalVerMensaje" tabindex="-1">
            <div class="modal-dialog modal-lg">
                <div class="modal-content">
                    <div class="modal-header bg-info text-white">
                        <h5 class="modal-title" id="verMensajeAsunto">Mensaje</h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body" id="verMensajeCuerpo">
                        <div class="text-center py-4"><div class="spinner-border text-info"></div></div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cerrar</button>
                        <button type="button" class="btn btn-primary d-none" id="btnResponder" onclick="responderMensaje()"><i class="fas fa-reply"></i> Responder</button>
                    </div>
                </div>
            </div>
        </div>

        <?php require __DIR__ . '/partials/scripts.php'; ?>
        <script>
            let ID_PROFESOR_USER = <?= (int) $user_id ?>;
            let mensajeActual = null;
            let preseleccionarEstudiante = null;

            function prepararNuevoMensaje() {
                $('#idMensajePadre').val('');
                $('#tipoIndividual').prop('checked', true);
                cambiarTipoDestino();
                $('#inputAsunto').val('');
                $('#inputCuerpo').val('');
                $('#errorEnvio').addClass('d-none');
                cargarDatosDestinatarios();
            }

            function cambiarTipoDestino() {
                const esSeccion = $('#tipoSeccion').is(':checked');
                $('#grupoSeccion').toggleClass('d-none', !esSeccion);
                $('#grupoEstudiante').toggleClass('d-none', esSeccion);
            }

            let datosDestinatariosCache = null;
            function cargarDatosDestinatarios() {
                if (datosDestinatariosCache) { pintarDestinatarios(datosDestinatariosCache); return; }
                $.getJSON('api/mensajes_datos.php', function(resp) {
                    if (!resp.success) return;
                    datosDestinatariosCache = resp;
                    pintarDestinatarios(resp);
                });
            }

            function pintarDestinatarios(resp) {
                const $sel = $('#selectEstudiante').empty();
                if (resp.estudiantes.length === 0) {
                    $sel.append('<option value="">No tienes estudiantes asignados</option>');
                } else {
                    let grupoActual = null, $grupo = null;
                    resp.estudiantes.forEach(function(e) {
                        const grupo = e.grado_nombre + ' ' + e.seccion_nombre;
                        if (grupo !== grupoActual) {
                            $grupo = $('<optgroup>').attr('label', grupo);
                            $sel.append($grupo);
                            grupoActual = grupo;
                        }
                        $grupo.append($('<option>').val(e.id_estudiante).text(e.primer_nombre + ' ' + e.primer_apellido + ' (' + e.nie + ')'));
                    });
                }
                if (preseleccionarEstudiante) {
                    $sel.val(preseleccionarEstudiante);
                    preseleccionarEstudiante = null;
                }
                const $selS = $('#selectSeccion').empty();
                if (resp.secciones.length === 0) {
                    $selS.append('<option value="">No tienes secciones asignadas</option>');
                } else {
                    resp.secciones.forEach(function(s) {
                        $selS.append($('<option>').val(s.id_seccion).text(s.grado_nombre + ' ' + s.seccion_nombre));
                    });
                }
            }

            function enviarMensaje() {
                const tipo = $('input[name=tipoDestino]:checked').val();
                const asunto = $('#inputAsunto').val().trim();
                const cuerpo = $('#inputCuerpo').val().trim();
                $('#errorEnvio').addClass('d-none');

                if (!asunto || !cuerpo) {
                    $('#errorEnvio').removeClass('d-none').text('Completa el asunto y el mensaje.');
                    return;
                }

                const datos = { tipo: tipo, asunto: asunto, cuerpo: cuerpo, id_mensaje_padre: $('#idMensajePadre').val() };
                if (tipo === 'individual') {
                    datos.id_estudiante = $('#selectEstudiante').val();
                    if (!datos.id_estudiante) { $('#errorEnvio').removeClass('d-none').text('Selecciona un estudiante.'); return; }
                } else {
                    datos.id_seccion = $('#selectSeccion').val();
                    if (!datos.id_seccion) { $('#errorEnvio').removeClass('d-none').text('Selecciona una sección.'); return; }
                }

                $.post('api/enviar_mensaje.php', datos, function(resp) {
                    if (resp.success) {
                        window.location.reload();
                    } else {
                        $('#errorEnvio').removeClass('d-none').text(resp.error || 'No se pudo enviar el mensaje.');
                    }
                }, 'json').fail(function(xhr) {
                    const msg = (xhr.responseJSON && xhr.responseJSON.error) ? xhr.responseJSON.error : 'No se pudo conectar con el servidor.';
                    $('#errorEnvio').removeClass('d-none').text(msg);
                });
            }

            function verMensaje(id) {
                const modal = new bootstrap.Modal(document.getElementById('modalVerMensaje'));
                $('#verMensajeAsunto').text('Cargando...');
                $('#verMensajeCuerpo').html('<div class="text-center py-4"><div class="spinner-border text-info"></div></div>');
                $('#btnResponder').addClass('d-none');
                modal.show();

                $.getJSON('api/leer_mensaje.php', { id: id }, function(resp) {
                    if (!resp.success) {
                        $('#verMensajeAsunto').text('Error');
                        $('#verMensajeCuerpo').html('<div class="alert alert-danger">' + (resp.error || 'No se pudo cargar el mensaje.') + '</div>');
                        return;
                    }
                    mensajeActual = resp.data;
                    $('#verMensajeAsunto').text(resp.data.asunto);
                    let destinoTxt = resp.data.tipo === 'seccion'
                        ? ('Enviado a la sección ' + resp.data.grado_nombre + ' ' + resp.data.seccion_nombre)
                        : '';
                    $('#verMensajeCuerpo').html(
                        '<p class="text-muted mb-1">De: <strong>' + $('<div>').text(resp.data.remitente_nombre + ' ' + resp.data.remitente_apellido).html() + '</strong>' +
                        ' &middot; ' + new Date(resp.data.fecha_envio.replace(' ', 'T')).toLocaleString('es-ES') + '</p>' +
                        (destinoTxt ? '<p class="text-muted small">' + destinoTxt + '</p>' : '') +
                        '<hr><div style="white-space: pre-wrap;">' + $('<div>').text(resp.data.cuerpo).html() + '</div>'
                    );
                    // Solo se puede responder a mensajes de otro remitente (no a los propios enviados).
                    if (resp.data.id_remitente != ID_PROFESOR_USER) {
                        $('#btnResponder').removeClass('d-none');
                    }
                    // Refrescar el badge/tabla de recibidos si este mensaje se acaba de marcar leído.
                    const $fila = $('tr.fila-mensaje').filter(function() { return $(this).attr('onclick') === 'verMensaje(' + id + ')'; });
                    $fila.removeClass('no-leido');
                });
            }

            function responderMensaje() {
                if (!mensajeActual) return;
                bootstrap.Modal.getInstance(document.getElementById('modalVerMensaje')).hide();
                // No se usa prepararNuevoMensaje() aquí a propósito: esa función ya
                // dispara su propia carga de destinatarios, y llamarla dos veces
                // seguidas (una desde aquí y otra explícita después) puede correr
                // dos peticiones AJAX en paralelo la primera vez que se abre el
                // modal en la sesión, y la que responda primero pisa la
                // preselección de la que responda después. Se hace todo en un
                // solo lugar con una sola carga.
                $('#idMensajePadre').val(mensajeActual.id);
                $('#inputAsunto').val(mensajeActual.asunto.startsWith('Re: ') ? mensajeActual.asunto : ('Re: ' + mensajeActual.asunto));
                $('#inputCuerpo').val('');
                $('#errorEnvio').addClass('d-none');
                // La respuesta va siempre uno-a-uno al remitente original, aunque el
                // mensaje original haya sido un aviso grupal de sección.
                $('#tipoIndividual').prop('checked', true);
                cambiarTipoDestino();
                preseleccionarEstudiante = mensajeActual.id_estudiante_remitente || null;
                cargarDatosDestinatarios();
                new bootstrap.Modal(document.getElementById('modalNuevoMensaje')).show();
            }
        </script>
    </div>
</body>
</html>
