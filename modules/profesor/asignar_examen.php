<?php
session_start();
require_once __DIR__ . '/../../config/database.php';

// Verificar que sea profesor
if (!isset($_SESSION['user_id']) || $_SESSION['rol'] != 'profesor') {
    header("Location: " . BASE_URL . "/login.php");
    exit;
}

$database = new Database();
$db = $database->getConnection();
$user_id = $_SESSION['user_id'];
$id_profesor = null;

// Obtener ID del profesor
$query = "SELECT id FROM tbl_profesor WHERE id_persona = (
    SELECT id FROM tbl_persona WHERE id_usuario = :user_id
)";
$stmt = $db->prepare($query);
$stmt->execute([':user_id' => $user_id]);
$profesor_data = $stmt->fetch(PDO::FETCH_ASSOC);
$id_profesor = $profesor_data['id'] ?? 0;

$mensaje = '';
$tipo_mensaje = '';

// ===== PROCESAR CREACIÓN DE EXAMEN =====
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['accion'])) {
    try {
        $db->beginTransaction();
        
        if ($_POST['accion'] == 'crear_examen') {
            $id_asignacion = $_POST['id_asignacion'];
            $titulo = trim($_POST['titulo']);
            $descripcion = trim($_POST['descripcion']);
            $fecha_programada = $_POST['fecha_programada'];
            $fecha_limite = $_POST['fecha_limite'];
            $duracion_minutos = $_POST['duracion_minutos'];
            $nota_maxima = $_POST['nota_maxima'];
            $tipo = 'examen';
            $estado = $_POST['estado'] ?? 'programado';
            
            // Validaciones
            if (empty($titulo) || empty($fecha_programada) || empty($fecha_limite)) {
                throw new Exception('Los campos obligatorios deben estar completos');
            }
            
            // Obtener período y año de la asignación
            $query = "SELECT id_periodo, anno FROM tbl_asignacion_docente WHERE id = :id";
            $stmt = $db->prepare($query);
            $stmt->execute([':id' => $id_asignacion]);
            $asignacion = $stmt->fetch(PDO::FETCH_ASSOC);
            
            // Insertar actividad (examen)
            $query = "INSERT INTO tbl_actividad (
                id_asignacion_docente, tipo, titulo, descripcion, 
                fecha_programada, fecha_limite, duracion_minutos, 
                nota_maxima, estado
            ) VALUES (
                :id_asignacion, :tipo, :titulo, :descripcion,
                :fecha_programada, :fecha_limite, :duracion,
                :nota_maxima, :estado
            )";
            
            $stmt = $db->prepare($query);
            $stmt->execute([
                ':id_asignacion' => $id_asignacion,
                ':tipo' => $tipo,
                ':titulo' => $titulo,
                ':descripcion' => $descripcion,
                ':fecha_programada' => $fecha_programada,
                ':fecha_limite' => $fecha_limite,
                ':duracion' => $duracion_minutos,
                ':nota_maxima' => $nota_maxima,
                ':estado' => $estado
            ]);
            
            $id_examen = $db->lastInsertId();
            
            $db->commit();
            $mensaje = 'Examen asignado exitosamente';
            $tipo_mensaje = 'success';
        }
        
    } catch (Exception $e) {
        $db->rollBack();
        $mensaje = 'Error: ' . $e->getMessage();
        $tipo_mensaje = 'danger';
    }
}

// ===== OBTENER DATOS PARA EL FORMULARIO =====

// Obtener asignaciones del profesor
$query = "SELECT 
    ad.id, ad.anno, ad.id_periodo,
    a.nombre as asignatura, a.codigo,
    s.nombre as seccion, g.nombre as grado
    FROM tbl_asignacion_docente ad
    JOIN tbl_asignatura a ON ad.id_asignatura = a.id
    JOIN tbl_seccion s ON ad.id_seccion = s.id
    JOIN tbl_grado g ON s.id_grado = g.id
    WHERE ad.id_profesor = :id_profesor
    ORDER BY g.nombre, s.nombre, a.nombre";

$stmt = $db->prepare($query);
$stmt->execute([':id_profesor' => $id_profesor]);
$asignaciones = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Obtener exámenes existentes
$query = "SELECT 
    act.id, act.titulo, act.fecha_programada, act.fecha_limite,
    act.nota_maxima, act.estado,
    a.nombre as asignatura,
    s.nombre as seccion,
    g.nombre as grado
    FROM tbl_actividad act
    JOIN tbl_asignacion_docente ad ON act.id_asignacion_docente = ad.id
    JOIN tbl_asignatura a ON ad.id_asignatura = a.id
    JOIN tbl_seccion s ON ad.id_seccion = s.id
    JOIN tbl_grado g ON s.id_grado = g.id
    WHERE ad.id_profesor = :id_profesor
    AND act.tipo = 'examen'
    ORDER BY act.fecha_programada DESC";

$stmt = $db->prepare($query);
$stmt->execute([':id_profesor' => $id_profesor]);
$examenes = $stmt->fetchAll(PDO::FETCH_ASSOC);

$periodos = [1 => '1er Trimestre', 2 => '2do Trimestre', 3 => '3er Trimestre', 4 => '4to Trimestre'];
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Asignar Examen - Educación Plus</title>
    
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    
    <style>
        :root { --primary: #2c3e50; --sidebar-width: 250px; }
        body { font-family: 'Segoe UI', sans-serif; background: #f8f9fa; }
        .sidebar { position: fixed; top: 0; left: 0; height: 100vh; width: var(--sidebar-width); background: var(--primary); color: white; padding-top: 60px; z-index: 1000; }
        .sidebar .nav-link { color: rgba(255,255,255,0.8); padding: 12px 20px; }
        .sidebar .nav-link:hover, .sidebar .nav-link.active { color: white; background: rgba(255,255,255,0.1); }
        .main-content { margin-left: var(--sidebar-width); padding: 20px; }
        .card-custom { background: white; border-radius: 10px; box-shadow: 0 2px 10px rgba(0,0,0,0.08); margin-bottom: 20px; }
        @media (max-width: 768px) { .sidebar { transform: translateX(-100%); } .sidebar.active { transform: translateX(0); } .main-content { margin-left: 0; } }
    </style>
</head>
<body>
    <!-- Sidebar -->
    <div class="sidebar" id="sidebar">
        <div class="text-center mb-4">
            <h4><i class="fas fa-graduation-cap"></i> Educación Plus</h4>
            <small>Panel de Profesor</small>
        </div>
        <nav class="nav flex-column">
            <a class="nav-link" href="dashboard_profesor.php"><i class="fas fa-home"></i> Dashboard</a>
            <a class="nav-link" href="mis_asignaciones.php"><i class="fas fa-book"></i> Mis Asignaciones</a>
            <a class="nav-link active" href="asignar_examen.php"><i class="fas fa-file-alt"></i> Asignar Examen</a>
            <a class="nav-link" href="calificaciones.php"><i class="fas fa-star"></i> Calificaciones</a>
            <a class="nav-link" href="../../logout.php"><i class="fas fa-sign-out-alt"></i> Cerrar Sesión</a>
        </nav>
    </div>

    <!-- Main Content -->
    <div class="main-content">
        <!-- Header -->
        <div class="d-flex justify-content-between align-items-center mb-4">
            <div>
                <h2><i class="fas fa-file-alt"></i> Asignar Examen</h2>
                <p class="text-muted mb-0">Crear y programar exámenes para tus estudiantes</p>
            </div>
            <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#modalNuevoExamen">
                <i class="fas fa-plus"></i> Nuevo Examen
            </button>
        </div>

        <!-- Mensajes -->
        <?php if ($mensaje): ?>
        <div class="alert alert-<?= $tipo_mensaje ?> alert-dismissible fade show">
            <?= htmlspecialchars($mensaje) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php endif; ?>

        <!-- Stats -->
        <div class="row g-3 mb-4">
            <div class="col-md-3">
                <div class="card-custom p-3 text-center">
                    <h3 class="mb-0 text-primary"><?= count($asignaciones) ?></h3>
                    <small class="text-muted">Asignaciones</small>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card-custom p-3 text-center">
                    <h3 class="mb-0 text-success"><?= count($examenes) ?></h3>
                    <small class="text-muted">Exámenes Creados</small>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card-custom p-3 text-center">
                    <h3 class="mb-0 text-warning"><?= count(array_filter($examenes, fn($e) => $e['estado'] == 'programado')) ?></h3>
                    <small class="text-muted">Programados</small>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card-custom p-3 text-center">
                    <h3 class="mb-0 text-info"><?= count(array_filter($examenes, fn($e) => $e['estado'] == 'activo')) ?></h3>
                    <small class="text-muted">Activos</small>
                </div>
            </div>
        </div>

        <!-- Lista de Exámenes -->
        <div class="card-custom">
            <div class="card-header bg-white py-3">
                <h5 class="mb-0"><i class="fas fa-list"></i> Exámenes Asignados</h5>
            </div>
            <div class="card-body p-0">
                <?php if (empty($examenes)): ?>
                <div class="text-center py-5 text-muted">
                    <i class="fas fa-inbox fa-3x mb-3"></i>
                    <p>No hay exámenes asignados todavía.</p>
                    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#modalNuevoExamen">
                        <i class="fas fa-plus"></i> Crear Primer Examen
                    </button>
                </div>
                <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Examen</th>
                                <th>Asignatura</th>
                                <th>Grado/Sección</th>
                                <th>Fecha Programada</th>
                                <th>Fecha Límite</th>
                                <th>Nota Máx.</th>
                                <th>Estado</th>
                                <th>Acciones</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($examenes as $examen): ?>
                            <tr>
                                <td>
                                    <strong><?= htmlspecialchars($examen['titulo']) ?></strong>
                                </td>
                                <td><?= htmlspecialchars($examen['asignatura']) ?></td>
                                <td>
                                    <?= htmlspecialchars($examen['grado']) ?> - 
                                    <?= htmlspecialchars($examen['seccion']) ?>
                                </td>
                                <td><?= date('d/m/Y H:i', strtotime($examen['fecha_programada'])) ?></td>
                                <td><?= date('d/m/Y H:i', strtotime($examen['fecha_limite'])) ?></td>
                                <td><span class="badge bg-info"><?= $examen['nota_maxima'] ?></span></td>
                                <td>
                                    <span class="badge bg-<?= $examen['estado'] == 'activo' ? 'success' : ($examen['estado'] == 'programado' ? 'warning' : 'secondary') ?>">
                                        <?= ucfirst($examen['estado']) ?>
                                    </span>
                                </td>
                                <td>
                                    <div class="btn-group btn-group-sm">
                                        <button class="btn btn-info" title="Ver">
                                            <i class="fas fa-eye"></i>
                                        </button>
                                        <button class="btn btn-warning" title="Editar">
                                            <i class="fas fa-edit"></i>
                                        </button>
                                        <button class="btn btn-danger" title="Eliminar">
                                            <i class="fas fa-trash"></i>
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
    </div>

    <!-- Modal Nuevo Examen -->
    <div class="modal fade" id="modalNuevoExamen" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title"><i class="fas fa-file-alt"></i> Asignar Nuevo Examen</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <div class="modal-body">
                        <input type="hidden" name="accion" value="crear_examen">
                        
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label">Asignatura *</label>
                                <select name="id_asignacion" class="form-select" required>
                                    <option value="">Seleccionar asignatura</option>
                                    <?php foreach ($asignaciones as $asig): ?>
                                    <option value="<?= $asig['id'] ?>">
                                        <?= htmlspecialchars($asig['asignatura']) ?> - 
                                        <?= htmlspecialchars($asig['grado']) ?> <?= htmlspecialchars($asig['seccion']) ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Título del Examen *</label>
                                <input type="text" name="titulo" class="form-control" required 
                                       placeholder="Ej: Examen Parcial Unidad 1">
                            </div>
                            <div class="col-12">
                                <label class="form-label">Descripción</label>
                                <textarea name="descripcion" class="form-control" rows="3" 
                                          placeholder="Instrucciones, temas a evaluar, etc."></textarea>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Fecha de Programación *</label>
                                <input type="datetime-local" name="fecha_programada" class="form-control" required>
                                <small class="text-muted">Cuándo estará disponible el examen</small>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Fecha Límite de Entrega *</label>
                                <input type="datetime-local" name="fecha_limite" class="form-control" required>
                                <small class="text-muted">Fecha y hora límite para entregar</small>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Duración (minutos)</label>
                                <input type="number" name="duracion_minutos" class="form-control" 
                                       placeholder="60" min="1">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Nota Máxima *</label>
                                <input type="number" name="nota_maxima" class="form-control" 
                                       value="10" step="0.1" min="0" max="100" required>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Estado</label>
                                <select name="estado" class="form-select">
                                    <option value="programado">Programado</option>
                                    <option value="activo">Activo</option>
                                    <option value="cerrado">Cerrado</option>
                                </select>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-save"></i> Asignar Examen
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Toggle sidebar en móvil
        document.getElementById('sidebar')?.addEventListener('click', (e) => {
            if (e.target.closest('.nav-link')) {
                document.getElementById('sidebar').classList.remove('active');
            }
        });
    </script>
</body>
</html>