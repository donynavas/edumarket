<?php
session_start();
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/TenantGuard.php';
require_once __DIR__ . '/../../config/MensajeHelper.php';

// Verificar que sea estudiante
if (!isset($_SESSION['user_id']) || $_SESSION['rol'] != 'estudiante') {
    header("Location: " . BASE_URL . "/login.php");
    exit;
}

$database = new Database();
$db = $database->getConnection();
$user_id = $_SESSION['user_id'];
$tid = TenantGuard::id();

$stmt = $db->prepare("SELECT e.id as id_estudiante, p.primer_nombre, p.primer_apellido
                       FROM tbl_estudiante e
                       JOIN tbl_persona p ON e.id_persona = p.id
                       JOIN tbl_matricula m ON e.id = m.id_estudiante
                       WHERE p.id_usuario = :user_id AND m.estado = 'activo' AND e.id_institucion = :tid
                       LIMIT 1");
$stmt->bindValue(':user_id', $user_id, PDO::PARAM_INT);
$stmt->bindValue(':tid', $tid, PDO::PARAM_INT);
$stmt->execute();
$datos = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$datos) {
    header("Location: " . BASE_URL . "/index.php");
    exit;
}

// ===== RECIBIDOS =====
$stmt = $db->prepare("SELECT m.id, m.asunto, m.tipo, m.fecha_envio, d.leido,
                       per.primer_nombre AS remitente_nombre, per.primer_apellido AS remitente_apellido,
                       s.nombre AS seccion_nombre, g.nombre AS grado_nombre
                       FROM tbl_mensaje_destinatario d
                       JOIN tbl_mensaje m ON d.id_mensaje = m.id
                       JOIN tbl_usuario ru ON m.id_remitente = ru.id
                       JOIN tbl_persona per ON per.id_usuario = ru.id
                       LEFT JOIN tbl_seccion s ON m.id_seccion_destino = s.id
                       LEFT JOIN tbl_grado g ON s.id_grado = g.id
                       WHERE d.id_usuario_destinatario = :uid
                       ORDER BY m.fecha_envio DESC");
$stmt->execute([':uid' => $user_id]);
$recibidos = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ===== ENVIADOS =====
$stmt = $db->prepare("SELECT m.id, m.asunto, m.fecha_envio,
                       (SELECT COUNT(*) FROM tbl_mensaje_destinatario WHERE id_mensaje = m.id AND leido = 1) AS total_leidos,
                       (SELECT CONCAT(p2.primer_nombre, ' ', p2.primer_apellido)
                            FROM tbl_mensaje_destinatario d2
                            JOIN tbl_usuario u2 ON d2.id_usuario_destinatario = u2.id
                            JOIN tbl_persona p2 ON p2.id_usuario = u2.id
                            WHERE d2.id_mensaje = m.id LIMIT 1) AS destinatario_nombre
                       FROM tbl_mensaje m
                       WHERE m.id_remitente = :uid
                       ORDER BY m.fecha_envio DESC");
$stmt->execute([':uid' => $user_id]);
$enviados = $stmt->fetchAll(PDO::FETCH_ASSOC);

$totalNoLeidos = count(array_filter($recibidos, fn($m) => !$m['leido']));
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Mensajes - Educación Plus</title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">

    <style>
        :root { --primary: #4361ee; --success: #2ecc71; --warning: #f39c12; --danger: #e74c3c; --sidebar-width: 260px; }
        body { font-family: 'Segoe UI', sans-serif; background: #f5f7fa; }
        .sidebar { position: fixed; top: 0; left: 0; height: 100vh; width: var(--sidebar-width); background: linear-gradient(180deg, #1d3557, #2a4365); color: white; z-index: 1000; }
        .sidebar .nav-link { color: rgba(255,255,255,0.85); padding: 12px 20px; border-radius: 8px; margin: 2px 0; }
        .sidebar .nav-link:hover, .sidebar .nav-link.active { background: rgba(255,255,255,0.15); color: white; }
        .main-content { margin-left: var(--sidebar-width); padding: 20px 30px; }
        .card-custom { background: white; border-radius: 12px; box-shadow: 0 2px 12px rgba(0,0,0,0.08); border: none; margin-bottom: 20px; overflow: hidden; }
        .fila-mensaje { cursor: pointer; }
        .fila-mensaje.no-leido { font-weight: 600; background: #f0f7ff; }
        .fila-mensaje:hover { background: #f8f9fa; }
        @media (max-width: 992px) { .sidebar { transform: translateX(-100%); } .sidebar.active { transform: translateX(0); } .main-content { margin-left: 0; } }
    </style>
</head>
<body>
    <!-- Sidebar -->
    <aside class="sidebar" id="sidebar">
        <div class="text-center p-3 border-bottom">
            <h5><i class="fas fa-graduation-cap"></i> Educación Plus</h5>
        </div>
        <div class="p-3 text-center border-bottom">
            <div class="fw-bold small"><?= htmlspecialchars($datos['primer_nombre']) ?></div>
            <small class="text-white-50">Estudiante</small>
        </div>
        <nav class="nav flex-column p-2">
            <a class="nav-link" href="../../index.php"><i class="fas fa-home"></i> Dashboard</a>
            <a class="nav-link" href="mis_clases.php"><i class="fas fa-book"></i> Mis Clases</a>
            <a class="nav-link" href="actividades.php"><i class="fas fa-tasks"></i> Actividades</a>
            <a class="nav-link" href="mis_notas.php"><i class="fas fa-star"></i> Calificaciones</a>
            <a class="nav-link active" href="mensajes.php">
                <i class="fas fa-envelope"></i> Mensajes
                <?php if ($totalNoLeidos > 0): ?><span class="badge bg-danger rounded-pill float-end"><?= $totalNoLeidos ?></span><?php endif; ?>
            </a>
            <a class="nav-link" href="../../logout.php"><i class="fas fa-sign-out-alt"></i> Cerrar Sesión</a>
        </nav>
    </aside>

    <!-- Main Content -->
    <main class="main-content">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <div>
                <h2><i class="fas fa-envelope"></i> Mensajes</h2>
                <p class="text-muted mb-0">Correo interno con tus profesores</p>
            </div>
            <div>
                <button class="btn btn-outline-primary btn-sm d-lg-none me-2" id="sidebarToggle"><i class="fas fa-bars"></i></button>
                <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#modalNuevoMensaje" onclick="prepararNuevoMensaje()">
                    <i class="fas fa-plus"></i> Nuevo Mensaje
                </button>
            </div>
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
                                    <td>
                                        <?= htmlspecialchars($m['remitente_nombre'] . ' ' . $m['remitente_apellido']) ?>
                                        <?php if ($m['tipo'] === 'seccion'): ?><span class="badge bg-info">Aviso de sección</span><?php endif; ?>
                                    </td>
                                    <td><?= htmlspecialchars($m['asunto']) ?></td>
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
                                    <td><?= htmlspecialchars($m['destinatario_nombre'] ?? '(profesor eliminado)') ?></td>
                                    <td><?= htmlspecialchars($m['asunto']) ?></td>
                                    <td><small><?= date('d/m/Y H:i', strtotime($m['fecha_envio'])) ?></small></td>
                                    <td><small><?= (int) $m['total_leidos'] ?>/1</small></td>
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
                            <label class="form-label">Profesor</label>
                            <select class="form-select" id="selectProfesor"><option value="">Cargando...</option></select>
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
    </main>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jquery/3.7.0/jquery.min.js"></script>
    <script>
        document.getElementById('sidebarToggle')?.addEventListener('click', () => {
            document.getElementById('sidebar').classList.toggle('active');
        });

        let ID_ESTUDIANTE_USER = <?= (int) $user_id ?>;
        let mensajeActual = null;
        let preseleccionarProfesor = null;

        function prepararNuevoMensaje() {
            $('#idMensajePadre').val('');
            $('#inputAsunto').val('');
            $('#inputCuerpo').val('');
            $('#errorEnvio').addClass('d-none');
            cargarDatosDestinatarios();
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
            const $sel = $('#selectProfesor').empty();
            if (resp.profesores.length === 0) {
                $sel.append('<option value="">No tienes profesores asignados</option>');
            } else {
                resp.profesores.forEach(function(p) {
                    $sel.append($('<option>').val(p.id_profesor).text(p.primer_nombre + ' ' + p.primer_apellido + ' (' + p.asignatura + ')'));
                });
            }
            if (preseleccionarProfesor) {
                $sel.val(preseleccionarProfesor);
                preseleccionarProfesor = null;
            }
        }

        function enviarMensaje() {
            const asunto = $('#inputAsunto').val().trim();
            const cuerpo = $('#inputCuerpo').val().trim();
            const idProfesor = $('#selectProfesor').val();
            $('#errorEnvio').addClass('d-none');

            if (!asunto || !cuerpo) {
                $('#errorEnvio').removeClass('d-none').text('Completa el asunto y el mensaje.');
                return;
            }
            if (!idProfesor) {
                $('#errorEnvio').removeClass('d-none').text('Selecciona un profesor.');
                return;
            }

            const datos = { asunto: asunto, cuerpo: cuerpo, id_profesor: idProfesor, id_mensaje_padre: $('#idMensajePadre').val() };

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
                if (resp.data.id_remitente != ID_ESTUDIANTE_USER) {
                    $('#btnResponder').removeClass('d-none');
                }
                const $fila = $('tr.fila-mensaje').filter(function() { return $(this).attr('onclick') === 'verMensaje(' + id + ')'; });
                $fila.removeClass('no-leido');
            });
        }

        function responderMensaje() {
            if (!mensajeActual) return;
            bootstrap.Modal.getInstance(document.getElementById('modalVerMensaje')).hide();
            // No se usa prepararNuevoMensaje() aquí a propósito, por la misma
            // razón que en el lado profesor: evitar dos cargas AJAX paralelas
            // pisándose la preselección la primera vez que se abre el modal.
            $('#idMensajePadre').val(mensajeActual.id);
            $('#inputAsunto').val(mensajeActual.asunto.startsWith('Re: ') ? mensajeActual.asunto : ('Re: ' + mensajeActual.asunto));
            $('#inputCuerpo').val('');
            $('#errorEnvio').addClass('d-none');
            preseleccionarProfesor = mensajeActual.id_profesor_remitente || null;
            cargarDatosDestinatarios();
            new bootstrap.Modal(document.getElementById('modalNuevoMensaje')).show();
        }
    </script>
</body>
</html>
