<?php
session_start();
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/TenantGuard.php';

// Verificar que sea profesor
if (!isset($_SESSION['user_id']) || $_SESSION['rol'] != 'profesor') {
    header("Location: " . BASE_URL . "/login.php");
    exit;
}

$database = new Database();
$db = $database->getConnection();
$user_id = $_SESSION['user_id'];
$tid = TenantGuard::id();

// Obtener datos del profesor
$query = "SELECT p.id as id_profesor, per.primer_nombre, per.primer_apellido, per.email
          FROM tbl_profesor p
          JOIN tbl_persona per ON p.id_persona = per.id
          WHERE per.id_usuario = :user_id AND p.id_institucion = :tid";
$stmt = $db->prepare($query);
$stmt->bindValue(':user_id', $user_id, PDO::PARAM_INT);
$stmt->bindValue(':tid', $tid, PDO::PARAM_INT);
$stmt->execute();
$profesor = $stmt->fetch(PDO::FETCH_ASSOC);
$id_profesor = $profesor['id_profesor'] ?? 0;

$mensaje = '';
$tipo_mensaje = '';

// ===== PROCESAR ACCIONES POST =====
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $accion = $_POST['accion'] ?? '';
    
    try {
        $db->beginTransaction();
        
        // === ACTUALIZAR ESTADO DE MATRÍCULA ===
        if ($accion == 'actualizar_estado_matricula') {
            $id_matricula = filter_input(INPUT_POST, 'id_matricula', FILTER_VALIDATE_INT);
            $nuevo_estado = $_POST['estado'] ?? 'activo';
            
            // tbl_matricula no tiene columna id_institucion; el aislamiento
            // por tenant ya queda garantizado por ad.id_profesor = :id_profesor
            // ($id_profesor viene de una consulta anterior filtrada por tenant).
            $check = $db->prepare("
                SELECT m.id FROM tbl_matricula m
                JOIN tbl_asignacion_docente ad ON m.id_seccion = ad.id_seccion AND m.anno = ad.anno
                WHERE m.id = :id_matricula AND ad.id_profesor = :id_profesor
            ");
            $check->execute([':id_matricula' => $id_matricula, ':id_profesor' => $id_profesor]);

            if ($check->rowCount() > 0) {
                $query = "UPDATE tbl_matricula SET estado = :estado, fecha_actualizacion = NOW() WHERE id = :id";
                $stmt = $db->prepare($query);
                $stmt->execute([':estado' => $nuevo_estado, ':id' => $id_matricula]);
                
                $db->commit();
                $mensaje = 'Estado de matrícula actualizado correctamente';
                $tipo_mensaje = 'success';
            } else {
                throw new Exception("No tiene permiso para modificar esta matrícula");
            }
            
        } elseif ($accion == 'enviar_mensaje_estudiante') {
            $id_estudiante = filter_input(INPUT_POST, 'id_estudiante', FILTER_VALIDATE_INT);
            $asunto = trim($_POST['asunto'] ?? '');
            $mensaje_texto = trim($_POST['mensaje'] ?? '');
            
            // Verificar que el estudiante esté en sus clases
            $check = $db->prepare("
                SELECT e.id FROM tbl_estudiante e
                JOIN tbl_matricula m ON e.id = m.id_estudiante
                JOIN tbl_asignacion_docente ad ON m.id_seccion = ad.id_seccion AND m.anno = ad.anno
                WHERE e.id = :id_estudiante AND ad.id_profesor = :id_profesor AND e.id_institucion = :tid
            ");
            $check->execute([':id_estudiante' => $id_estudiante, ':id_profesor' => $id_profesor, ':tid' => $tid]);
            
            if ($check->rowCount() > 0 && !empty($asunto) && !empty($mensaje_texto)) {
                // Aquí iría la lógica para enviar mensaje (email o sistema interno)
                $db->commit();
                $mensaje = 'Mensaje enviado correctamente';
                $tipo_mensaje = 'success';
            } else {
                throw new Exception("Datos inválidos o no tiene permiso");
            }
        }
        
    } catch (Exception $e) {
        $db->rollBack();
        error_log("Error en gestionar_estudiantes.php: " . $e->getMessage());
        $mensaje = 'Error: ' . $e->getMessage();
        $tipo_mensaje = 'danger';
    }
}

// ===== OBTENER ASIGNACIONES DEL PROFESOR =====
$query = "SELECT ad.id, ad.anno, asig.nombre as asignatura_nombre,
          g.nombre as grado_nombre, s.nombre as seccion_nombre
          FROM tbl_asignacion_docente ad
          JOIN tbl_asignatura asig ON ad.id_asignatura = asig.id
          JOIN tbl_seccion s ON ad.id_seccion = s.id
          JOIN tbl_grado g ON s.id_grado = g.id
          WHERE ad.id_profesor = :id_profesor AND asig.id_institucion = :tid
          ORDER BY g.nombre, s.nombre, asig.nombre";
$stmt = $db->prepare($query);
$stmt->bindValue(':id_profesor', $id_profesor, PDO::PARAM_INT);
$stmt->bindValue(':tid', $tid, PDO::PARAM_INT);
$stmt->execute();
$asignaciones = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ===== OBTENER ESTUDIANTES =====
// ===== OBTENER ESTUDIANTES =====
$id_asignacion_filtro = $_GET['asignacion'] ?? ($asignaciones[0]['id'] ?? 0);
$busqueda = $_GET['busqueda'] ?? '';
$estado_filtro = $_GET['estado'] ?? 'todos';

$estudiantes = [];
$total_estudiantes = 0;

if ($id_asignacion_filtro) {
    try {
        // tbl_entrega_actividad no tiene columna id_estudiante (sólo
        // id_matricula); el join anterior comparaba e.id = ea.id (la PK de
        // la propia entrega), lo que emparejaba estudiantes con entregas al
        // azar por coincidencia de ids en vez de por relación real.
        $query_est = "SELECT
                     e.id as id_estudiante,
                     p.primer_nombre,
                     p.primer_apellido,
                     p.email,
                     p.celular,
                     e.nie,
                     m.id as id_matricula,
                     m.estado as estado_matricula,
                     COUNT(ea.id) as total_entregas,
                     AVG(ea.nota_obtenida) as promedio_general,
                     MAX(ea.fecha_entrega) as ultima_entrega
                     FROM tbl_estudiante e
                     JOIN tbl_persona p ON e.id_persona = p.id
                     JOIN tbl_matricula m ON e.id = m.id_estudiante
                     LEFT JOIN tbl_entrega_actividad ea ON m.id = ea.id_matricula
                     WHERE m.id_seccion = (SELECT id_seccion FROM tbl_asignacion_docente WHERE id = :id_asig AND id_profesor = :id_prof)
                     AND m.anno = (SELECT anno FROM tbl_asignacion_docente WHERE id = :id_asig2 AND id_profesor = :id_prof2)
                     AND e.id_institucion = :tid3";

        $params = [
            ':id_asig' => $id_asignacion_filtro, ':id_prof' => $id_profesor,
            ':id_asig2' => $id_asignacion_filtro, ':id_prof2' => $id_profesor,
            ':tid3' => $tid
        ];
        
        // Filtro de búsqueda
        if (!empty($busqueda)) {
            $query_est .= " AND (p.primer_nombre LIKE :busqueda OR p.primer_apellido LIKE :busqueda OR e.nie LIKE :busqueda OR p.numero_documento LIKE :busqueda)";
            $params[':busqueda'] = "%$busqueda%";
        }
        
        // Filtro de estado
        if ($estado_filtro != 'todos') {
            $query_est .= " AND m.estado = :estado";
            $params[':estado'] = $estado_filtro;
        }
        
        $query_est .= " GROUP BY e.id ORDER BY p.primer_apellido, p.primer_nombre";
        
        $stmt_est = $db->prepare($query_est);
        foreach ($params as $key => $value) {
            $stmt_est->bindValue($key, $value);
        }
        $stmt_est->execute();
        $estudiantes = $stmt_est->fetchAll(PDO::FETCH_ASSOC);
        $total_estudiantes = count($estudiantes);
        
    } catch (PDOException $e) {
        error_log("Error al obtener estudiantes: " . $e->getMessage());
        $mensaje = 'Error al cargar los estudiantes: ' . $e->getMessage();
        $tipo_mensaje = 'danger';
    }
}
// ===== ESTADÍSTICAS GENERALES =====
$estadisticas = [
    'total' => $total_estudiantes,
    'activos' => 0,
    'inactivos' => 0,
    'promedio_general' => 0
];

foreach ($estudiantes as $est) {
    if ($est['estado_matricula'] == 'activo') {
        $estadisticas['activos']++;
    } else {
        $estadisticas['inactivos']++;
    }
    
    if ($est['promedio_general'] > 0) {
        $estadisticas['promedio_general'] += $est['promedio_general'];
    }
}

if ($estadisticas['activos'] > 0) {
    $estadisticas['promedio_general'] = round($estadisticas['promedio_general'] / $estadisticas['activos'], 2);
}
?>
<?php
$activePage = 'estudiantes';
$pageTitle = 'Gestión de Estudiantes - Educación Plus';
$mostrarAsignacionesSidebar = true;
$idAsignacionFiltro = $id_asignacion_filtro;
ob_start();
?>
<style>
    .card-custom { background: white; border-radius: 12px; box-shadow: 0 2px 12px rgba(0,0,0,0.08); border: none; margin-bottom: 20px; }
    .student-avatar { width: 45px; height: 45px; border-radius: 50%; background: linear-gradient(135deg, var(--secondary), var(--primary)); color: white; display: flex; align-items: center; justify-content: center; font-weight: 700; font-size: 1.1rem; }
    .stat-card { background: white; border-radius: 12px; padding: 20px; box-shadow: 0 2px 8px rgba(0,0,0,0.08); text-align: center; }
    .stat-card i { font-size: 2.5rem; margin-bottom: 10px; }
    .stat-card.active i { color: var(--success); }
    .stat-card.inactive i { color: var(--danger); }
    .stat-card.average i { color: var(--warning); }
    .badge-estado { padding: 6px 12px; border-radius: 20px; font-size: 0.8rem; font-weight: 600; }
    .estado-activo { background: #d4edda; color: #155724; }
    .estado-inactivo { background: #f8d7da; color: #721c24; }
    .estado-pendiente { background: #fff3cd; color: #856404; }
    .table-hover tbody tr:hover { background: #f8f9fa; cursor: pointer; }
</style>
<?php
$extraHead = ob_get_clean();
require __DIR__ . '/partials/header.php';
?>
        <!-- Header -->
        <div class="d-flex justify-content-between align-items-center mb-4">
            <div>
                <h2 class="mb-1"><i class="fas fa-user-graduate"></i> Gestión de Estudiantes</h2>
                <nav aria-label="breadcrumb">
                    <ol class="breadcrumb mb-0">
                        <li class="breadcrumb-item"><a href="profesor_dashboard.php">Dashboard</a></li>
                        <li class="breadcrumb-item active">Estudiantes</li>
                    </ol>
                </nav>
            </div>
        </div>

        <!-- Messages -->
        <?php if ($mensaje): ?>
        <div class="alert alert-<?= $tipo_mensaje ?> alert-dismissible fade show">
            <i class="fas fa-<?= $tipo_mensaje == 'success' ? 'check-circle' : 'exclamation-circle' ?>"></i>
            <?= htmlspecialchars($mensaje) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php endif; ?>

        <?php if (empty($asignaciones)): ?>
        <div class="card-custom p-5 text-center">
            <i class="fas fa-exclamation-triangle fa-3x text-warning mb-3"></i>
            <h4>No tienes asignaciones registradas</h4>
            <p class="text-muted">Contacta al administrador para que te asigne clases</p>
        </div>
        <?php else: ?>
        
        <!-- Estadísticas -->
        <div class="row g-3 mb-4">
            <div class="col-md-4">
                <div class="stat-card active">
                    <i class="fas fa-users"></i>
                    <h3><?= $estadisticas['activos'] ?></h3>
                    <p class="text-muted mb-0">Estudiantes Activos</p>
                </div>
            </div>
            <div class="col-md-4">
                <div class="stat-card inactive">
                    <i class="fas fa-user-times"></i>
                    <h3><?= $estadisticas['inactivos'] ?></h3>
                    <p class="text-muted mb-0">Estudiantes Inactivos</p>
                </div>
            </div>
            <div class="col-md-4">
                <div class="stat-card average">
                    <i class="fas fa-chart-line"></i>
                    <h3><?= $estadisticas['promedio_general'] > 0 ? $estadisticas['promedio_general'] : '-' ?></h3>
                    <p class="text-muted mb-0">Promedio General</p>
                </div>
            </div>
        </div>

        <!-- Filtros -->
        <div class="card-custom p-3 mb-4">
            <form method="GET" class="row g-3">
                <input type="hidden" name="asignacion" value="<?= $id_asignacion_filtro ?>">
                
                <div class="col-md-4">
                    <label class="form-label small text-muted">Asignación</label>
                    <select name="asignacion" class="form-select" onchange="this.form.submit()">
                        <?php foreach ($asignaciones as $asig): ?>
                        <option value="<?= $asig['id'] ?>" <?= $id_asignacion_filtro == $asig['id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($asig['asignatura_nombre']) ?> - <?= htmlspecialchars($asig['grado_nombre']) ?> <?= htmlspecialchars($asig['seccion_nombre']) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="col-md-3">
                    <label class="form-label small text-muted">Estado</label>
                    <select name="estado" class="form-select" onchange="this.form.submit()">
                        <option value="todos" <?= $estado_filtro == 'todos' ? 'selected' : '' ?>>Todos</option>
                        <option value="activo" <?= $estado_filtro == 'activo' ? 'selected' : '' ?>>Activos</option>
                        <option value="inactivo" <?= $estado_filtro == 'inactivo' ? 'selected' : '' ?>>Inactivos</option>
                        <option value="pendiente" <?= $estado_filtro == 'pendiente' ? 'selected' : '' ?>>Pendientes</option>
                    </select>
                </div>
                
                <div class="col-md-3">
                    <label class="form-label small text-muted">Buscar</label>
                    <div class="input-group">
                        <input type="text" name="busqueda" class="form-control" placeholder="Nombre, NIE o DNI..." value="<?= htmlspecialchars($busqueda) ?>">
                        <button type="submit" class="btn btn-primary"><i class="fas fa-search"></i></button>
                    </div>
                </div>
                
                <div class="col-md-2 d-flex align-items-end">
                    <a href="?asignacion=<?= $id_asignacion_filtro ?>" class="btn btn-outline-secondary w-100">
                        <i class="fas fa-redo"></i> Limpiar
                    </a>
                </div>
            </form>
        </div>

        <!-- Tabla de Estudiantes -->
        <div class="card-custom">
            <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
                <h5 class="mb-0">
                    <i class="fas fa-list"></i> Lista de Estudiantes
                    <span class="badge bg-primary ms-2"><?= $total_estudiantes ?></span>
                </h5>
                <button class="btn btn-sm btn-outline-primary" onclick="exportarLista()">
                    <i class="fas fa-download"></i> Exportar
                </button>
            </div>
            <div class="card-body p-0">
                <?php if (empty($estudiantes)): ?>
                <div class="text-center py-5">
                    <i class="fas fa-inbox fa-4x text-muted mb-3"></i>
                    <h5>No hay estudiantes matriculados</h5>
                    <p class="text-muted">Esta asignación no tiene estudiantes registrados</p>
                </div>
                <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Estudiante</th>
                                <th>NIE/DNI</th>
                                <th>Contacto</th>
                                <th>Matrícula</th>
                                <th>Entregas</th>
                                <th>Promedio</th>
                                <th>Acciones</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($estudiantes as $est): 
                                $iniciales = strtoupper(substr($est['primer_nombre'], 0, 1) . substr($est['primer_apellido'], 0, 1));
                                $nombre_completo = trim($est['primer_nombre'] . ' ' . $est['primer_apellido']);
                            ?>
                            <tr>
                                <td>
                                    <div class="d-flex align-items-center gap-3">
                                        <div class="student-avatar"><?= $iniciales ?></div>
                                        <div>
                                            <strong><?= htmlspecialchars($nombre_completo) ?></strong>
                                        </div>
                                    </div>
                                </td>
                                <td>
                                    <small>
                                        <div><i class="fas fa-id-card"></i> <?= htmlspecialchars($est['nie']) ?></div>
                                    </small>
                                </td>
                                <td>
                                    <small>
                                        <?php if ($est['email']): ?>
                                        <div><i class="fas fa-envelope"></i> <?= htmlspecialchars($est['email']) ?></div>
                                        <?php endif; ?>
                                        <?php if ($est['celular']): ?>
                                        <div class="text-muted"><i class="fas fa-phone"></i> <?= htmlspecialchars($est['celular']) ?></div>
                                        <?php endif; ?>
                                    </small>
                                </td>
                            <td>
                                <span class="badge-estado estado-<?= $est['estado_matricula'] ?>">
                                <?= ucfirst($est['estado_matricula']) ?>
                                </span>
                            </td>
                                <td>
                                    <span class="badge bg-info"><?= $est['total_entregas'] ?? 0 ?></span>
                                    <?php if ($est['ultima_entrega']): ?>
                                    <br><small class="text-muted"><?= date('d/m', strtotime($est['ultima_entrega'])) ?></small>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($est['promedio_general']): ?>
                                    <strong class="<?= $est['promedio_general'] >= 7 ? 'text-success' : ($est['promedio_general'] >= 5 ? 'text-warning' : 'text-danger') ?>">
                                        <?= number_format($est['promedio_general'], 2) ?>
                                    </strong>
                                    <?php else: ?>
                                    <span class="text-muted">-</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div class="btn-group btn-group-sm">
                                        <button type="button" class="btn btn-outline-primary" onclick="verDetalleEstudiante(<?= $est['id_estudiante'] ?>)" title="Ver detalle">
                                            <i class="fas fa-eye"></i>
                                        </button>
                                        <button type="button" class="btn btn-outline-success" onclick="enviarMensaje(<?= $est['id_estudiante'] ?>, '<?= htmlspecialchars($nombre_completo) ?>')" title="Enviar mensaje">
                                            <i class="fas fa-envelope"></i>
                                        </button>
                                        <button type="button" class="btn btn-outline-<?= $est['estado_matricula'] == 'activo' ? 'warning' : 'success' ?>" onclick="cambiarEstado(<?= $est['id_matricula'] ?>, '<?= $est['estado_matricula'] == 'activo' ? 'inactivo' : 'activo' ?>)" title="<?= $est['estado_matricula'] == 'activo' ? 'Desactivar' : 'Activar' ?>">
                                            <i class="fas fa-<?= $est['estado_matricula'] == 'activo' ? 'pause' : 'play' ?>"></i>
                                        </button>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <!-- Modal Ver Detalle Estudiante -->
    <div class="modal fade" id="modalDetalle" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title"><i class="fas fa-user-graduate"></i> Detalle del Estudiante</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body" id="detalleContent">
                    <!-- Contenido dinámico -->
                </div>
            </div>
        </div>
    </div>

    <!-- Modal Enviar Mensaje -->
    <div class="modal fade" id="modalMensaje" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header bg-success text-white">
                    <h5 class="modal-title"><i class="fas fa-envelope"></i> Enviar Mensaje</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <div class="modal-body">
                        <input type="hidden" name="accion" value="enviar_mensaje_estudiante">
                        <input type="hidden" name="id_estudiante" id="msg_id_estudiante">
                        
                        <div class="mb-3">
                            <label class="form-label">Para:</label>
                            <input type="text" class="form-control" id="msg_destinatario" readonly>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Asunto *</label>
                            <input type="text" name="asunto" class="form-control" required placeholder="Asunto del mensaje">
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Mensaje *</label>
                            <textarea name="mensaje" class="form-control" rows="5" required placeholder="Escribe tu mensaje aquí..."></textarea>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                        <button type="submit" class="btn btn-success"><i class="fas fa-paper-plane"></i> Enviar</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Scripts -->
    <?php require __DIR__ . '/partials/scripts.php'; ?>

    <script>
    $(document).ready(function() {
        // Sidebar responsive
        if (window.innerWidth < 992) {
            $('#sidebar').addClass('active');
        }
    });
    
    // Ver detalle del estudiante
    function verDetalleEstudiante(id) {
        $('#detalleContent').html('<div class="text-center py-4"><i class="fas fa-spinner fa-spin fa-2x"></i><p class="mt-2">Cargando...</p></div>');
        $('#modalDetalle').modal('show');
        
        // Aquí iría una llamada AJAX para cargar los datos
        setTimeout(() => {
            $('#detalleContent').html(`
                <div class="alert alert-info">
                    <i class="fas fa-info-circle"></i> Funcionalidad en desarrollo. 
                    ID Estudiante: ${id}
                </div>
            `);
        }, 500);
    }
    
    // Enviar mensaje
    function enviarMensaje(id, nombre) {
        $('#msg_id_estudiante').val(id);
        $('#msg_destinatario').val(nombre);
        $('#modalMensaje').modal('show');
    }
    
    // Cambiar estado de matrícula
    function cambiarEstado(idMatricula, nuevoEstado) {
        const accion = nuevoEstado === 'activo' ? 'activar' : 'desactivar';
        if (confirm(`¿Está seguro de ${accion} esta matrícula?`)) {
            const form = document.createElement('form');
            form.method = 'POST';
            form.innerHTML = `
                <input type="hidden" name="accion" value="actualizar_estado_matricula">
                <input type="hidden" name="id_matricula" value="${idMatricula}">
                <input type="hidden" name="estado" value="${nuevoEstado}">
            `;
            document.body.appendChild(form);
            form.submit();
        }
    }
    
    // Exportar lista
    function exportarLista() {
        alert('Función de exportación en desarrollo');
        // Aquí se implementaría la exportación a Excel/PDF
    }
    </script>
</body>
</html>