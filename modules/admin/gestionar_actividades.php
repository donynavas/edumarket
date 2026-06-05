<?php
session_start();
require_once __DIR__ . '/../../config/database.php';

// Verificar autenticación
if (!isset($_SESSION['user_id']) || $_SESSION['rol'] != 'profesor') {
    header("Location: " . BASE_URL . "/login.php");
    exit;
}

$database = new Database();
$db = $database->getConnection();
$user_id = $_SESSION['user_id'];

// Obtener ID del profesor
$query = "SELECT p.id as id_profesor FROM tbl_profesor p
          JOIN tbl_persona per ON p.id_persona = per.id
          WHERE per.id_usuario = :user_id";
$stmt = $db->prepare($query);
$stmt->bindValue(':user_id', $user_id, PDO::PARAM_INT);
$stmt->execute();
$profesor = $stmt->fetch(PDO::FETCH_ASSOC);
$id_profesor = $profesor['id_profesor'] ?? 0;

// Modo edición o creación
$modo_edicion = isset($_GET['editar']) && !empty($_GET['editar']);
$id_actividad = $modo_edicion ? (int)$_GET['editar'] : 0;
$id_asignacion = $_GET['asignacion'] ?? 0;

// Validar asignación docente
if (!$id_asignacion) {
    $_SESSION['error'] = "No se ha seleccionado una asignación";
    header("Location: tablon.php");
    exit;
}

// Verificar que la asignación pertenezca al profesor
$query = "SELECT ad.id, asig.nombre as asignatura, g.nombre as grado, s.nombre as seccion
          FROM tbl_asignacion_docente ad
          JOIN tbl_asignatura asig ON ad.id_asignatura = asig.id
          JOIN tbl_seccion s ON ad.id_seccion = s.id
          WHERE ad.id = :id_asignacion AND ad.id_profesor = :id_profesor";
$stmt = $db->prepare($query);
$stmt->execute([
    ':id_asignacion' => $id_asignacion,
    ':id_profesor' => $id_profesor
]);
$asignacion = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$asignacion) {
    $_SESSION['error'] = "No tiene permiso para acceder a esta asignación";
    header("Location: tablon.php");
    exit;
}

// Datos de la actividad (si es edición)
$actividad = null;
if ($modo_edicion) {
    $query = "SELECT * FROM tbl_actividad WHERE id = :id AND id_asignacion_docente = :id_asignacion";
    $stmt = $db->prepare($query);
    $stmt->execute([
        ':id' => $id_actividad,
        ':id_asignacion' => $id_asignacion
    ]);
    $actividad = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$actividad) {
        $_SESSION['error'] = "Actividad no encontrada";
        header("Location: tablon.php?asignacion=$id_asignacion");
        exit;
    }
}

// Tipos y estados
$tipos_actividad = [
    'tarea' => 'Tarea',
    'examen' => 'Examen',
    'video' => 'Video',
    'youtube' => 'YouTube',
    'articulo' => 'Artículo',
    'referencia' => 'Referencia',
    'podcast' => 'Podcast',
    'revista' => 'Revista',
    'enlace' => 'Enlace'
];

$estados_actividad = [
    'programado' => 'Programado',
    'publicado' => 'Publicado',
    'activo' => 'Activo',
    'cerrado' => 'Cerrado'
];

// Mensajes de sesión
$mensaje = $_SESSION['success'] ?? '';
$error = $_SESSION['error'] ?? '';
unset($_SESSION['success'], $_SESSION['error']);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $modo_edicion ? 'Editar' : 'Nueva' ?> Actividad - Educación Plus</title>
    
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet">
    
    <style>
        :root {
            --primary: #2c3e50;
            --sidebar-width: 260px;
        }
        
        body {
            font-family: 'Segoe UI', system-ui, sans-serif;
            background: #f0f2f5;
        }
        
        .sidebar {
            position: fixed;
            top: 0;
            left: 0;
            height: 100vh;
            width: var(--sidebar-width);
            background: var(--primary);
            color: white;
            padding-top: 20px;
            z-index: 1000;
        }
        
        .sidebar .brand {
            text-align: center;
            padding: 0 20px 20px;
            border-bottom: 1px solid rgba(255,255,255,0.1);
            margin-bottom: 20px;
        }
        
        .sidebar .nav-link {
            color: rgba(255,255,255,0.85);
            padding: 12px 20px;
            margin: 2px 10px;
            border-radius: 8px;
        }
        
        .sidebar .nav-link:hover, .sidebar .nav-link.active {
            color: white;
            background: rgba(255,255,255,0.15);
        }
        
        .main-content {
            margin-left: var(--sidebar-width);
            padding: 30px;
        }
        
        .form-card {
            background: white;
            border-radius: 12px;
            padding: 30px;
            box-shadow: 0 2px 12px rgba(0,0,0,0.08);
        }
        
        .form-header {
            border-bottom: 2px solid #f0f2f5;
            padding-bottom: 20px;
            margin-bottom: 30px;
        }
        
        .btn-custom {
            padding: 10px 24px;
            border-radius: 8px;
            font-weight: 500;
        }
        
        .form-label {
            font-weight: 600;
            color: #2c3e50;
            margin-bottom: 8px;
        }
        
        .alert-custom {
            border-radius: 8px;
            border: none;
        }
    </style>
</head>
<body>
    <!-- Sidebar -->
    <div class="sidebar">
        <div class="brand">
            <h4><i class="fas fa-graduation-cap"></i> Educación Plus</h4>
            <small>Panel del Profesor</small>
        </div>
        <nav class="nav flex-column">
            <a class="nav-link" href="profesor_dashboard.php"><i class="fas fa-home"></i> Dashboard</a>
            <a class="nav-link" href="tablon.php"><i class="fas fa-chalkboard"></i> Tablón</a>
            <a class="nav-link active" href="gestionar_actividades.php"><i class="fas fa-tasks"></i> Actividades</a>
            <a class="nav-link" href="calificaciones.php"><i class="fas fa-star"></i> Calificaciones</a>
            <a class="nav-link" href="../../logout.php"><i class="fas fa-sign-out-alt"></i> Cerrar Sesión</a>
        </nav>
    </div>

    <!-- Main Content -->
    <div class="main-content">
        <div class="container-fluid">
            <!-- Header -->
            <div class="d-flex justify-content-between align-items-center mb-4">
                <div>
                    <h2 class="mb-1">
                        <i class="fas <?= $modo_edicion ? 'fa-edit' : 'fa-plus-circle' ?>"></i>
                        <?= $modo_edicion ? 'Editar Actividad' : 'Nueva Actividad' ?>
                    </h2>
                    <nav aria-label="breadcrumb">
                        <ol class="breadcrumb mb-0">
                            <li class="breadcrumb-item"><a href="tablon.php">Tablón</a></li>
                            <li class="breadcrumb-item"><a href="tablon.php?asignacion=<?= $id_asignacion ?>"><?= htmlspecialchars($asignacion['asignatura']) ?></a></li>
                            <li class="breadcrumb-item active"><?= $modo_edicion ? 'Editar' : 'Crear' ?></li>
                        </ol>
                    </nav>
                </div>
                <a href="tablon.php?asignacion=<?= $id_asignacion ?>" class="btn btn-outline-secondary">
                    <i class="fas fa-arrow-left"></i> Volver al Tablón
                </a>
            </div>

            <!-- Alertas -->
            <?php if ($mensaje): ?>
            <div class="alert alert-success alert-custom mb-4">
                <i class="fas fa-check-circle"></i> <?= htmlspecialchars($mensaje) ?>
            </div>
            <?php endif; ?>
            
            <?php if ($error): ?>
            <div class="alert alert-danger alert-custom mb-4">
                <i class="fas fa-exclamation-circle"></i> <?= htmlspecialchars($error) ?>
            </div>
            <?php endif; ?>

            <!-- Formulario -->
            <div class="form-card">
                <div class="form-header">
                    <h5 class="mb-0">
                        <i class="fas fa-info-circle text-primary"></i>
                        <?= htmlspecialchars($asignacion['asignatura']) ?> - 
                        <?= htmlspecialchars($asignacion['grado']) ?> <?= htmlspecialchars($asignacion['seccion']) ?>
                    </h5>
                </div>

                <form action="procesar_actividad.php" method="POST" id="formActividad">
                    <input type="hidden" name="id_asignacion" value="<?= $id_asignacion ?>">
                    <input type="hidden" name="modo" value="<?= $modo_edicion ? 'editar' : 'crear' ?>">
                    <?php if ($modo_edicion): ?>
                    <input type="hidden" name="id_actividad" value="<?= $id_actividad ?>">
                    <?php endif; ?>

                    <div class="row">
                        <!-- Columna Izquierda -->
                        <div class="col-lg-8">
                            <!-- Título -->
                            <div class="mb-4">
                                <label for="titulo" class="form-label">Título de la Actividad *</label>
                                <input type="text" 
                                       class="form-control form-control-lg" 
                                       id="titulo" 
                                       name="titulo" 
                                       value="<?= htmlspecialchars($actividad['titulo'] ?? '') ?>"
                                       placeholder="Ej: Tarea de Matemáticas - Fracciones"
                                       required>
                            </div>

                            <!-- Descripción -->
                            <div class="mb-4">
                                <label for="descripcion" class="form-label">Descripción *</label>
                                <textarea class="form-control" 
                                          id="descripcion" 
                                          name="descripcion" 
                                          rows="5"
                                          placeholder="Describa los objetivos, instrucciones y criterios de evaluación..."
                                          required><?= htmlspecialchars($actividad['descripcion'] ?? '') ?></textarea>
                                <div class="form-text">Proporcione instrucciones claras para los estudiantes</div>
                            </div>

                            <!-- Contenido (opcional) -->
                            <div class="mb-4">
                                <label for="contenido" class="form-label">Contenido Adicional</label>
                                <textarea class="form-control" 
                                          id="contenido" 
                                          name="contenido" 
                                          rows="4"
                                          placeholder="Contenido HTML o texto adicional..."><?= htmlspecialchars($actividad['contenido'] ?? '') ?></textarea>
                            </div>

                            <!-- URL Recurso -->
                            <div class="mb-4">
                                <label for="url_recurso" class="form-label">URL del Recurso</label>
                                <input type="url" 
                                       class="form-control" 
                                       id="url_recurso" 
                                       name="url_recurso" 
                                       value="<?= htmlspecialchars($actividad['url_recurso'] ?? '') ?>"
                                       placeholder="https://youtube.com/watch?v=... o enlace externo">
                                <div class="form-text">Para videos de YouTube, artículos, referencias, etc.</div>
                            </div>
                        </div>

                        <!-- Columna Derecha -->
                        <div class="col-lg-4">
                            <!-- Tipo de Actividad -->
                            <div class="mb-4">
                                <label for="tipo" class="form-label">Tipo de Actividad *</label>
                                <select class="form-select" id="tipo" name="tipo" required>
                                    <option value="">Seleccione...</option>
                                    <?php foreach ($tipos_actividad as $valor => $etiqueta): ?>
                                    <option value="<?= $valor ?>" 
                                            <?= ($actividad['tipo'] ?? '') == $valor ? 'selected' : '' ?>>
                                        <?= $etiqueta ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <!-- Estado -->
                            <div class="mb-4">
                                <label for="estado" class="form-label">Estado *</label>
                                <select class="form-select" id="estado" name="estado" required>
                                    <?php foreach ($estados_actividad as $valor => $etiqueta): ?>
                                    <option value="<?= $valor ?>" 
                                            <?= ($actividad['estado'] ?? 'programado') == $valor ? 'selected' : '' ?>>
                                        <?= $etiqueta ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <!-- Fecha Programada -->
                            <div class="mb-4">
                                <label for="fecha_programada" class="form-label">Fecha de Publicación *</label>
                                <input type="datetime-local" 
                                       class="form-control" 
                                       id="fecha_programada" 
                                       name="fecha_programada"
                                       value="<?= $actividad['fecha_programada'] ?? date('Y-m-d\TH:i') ?>"
                                       required>
                            </div>

                            <!-- Fecha Límite -->
                            <div class="mb-4">
                                <label for="fecha_limite" class="form-label">Fecha Límite de Entrega</label>
                                <input type="datetime-local" 
                                       class="form-control" 
                                       id="fecha_limite" 
                                       name="fecha_limite"
                                       value="<?= $actividad['fecha_limite'] ?? '' ?>">
                            </div>

                            <!-- Duración (para exámenes) -->
                            <div class="mb-4">
                                <label for="duracion_minutos" class="form-label">Duración (minutos)</label>
                                <input type="number" 
                                       class="form-control" 
                                       id="duracion_minutos" 
                                       name="duracion_minutos"
                                       value="<?= $actividad['duracion_minutos'] ?? '' ?>"
                                       min="1"
                                       max="300">
                                <div class="form-text">Solo para exámenes o actividades cronometradas</div>
                            </div>

                            <!-- Nota Máxima -->
                            <div class="mb-4">
                                <label for="nota_maxima" class="form-label">Nota Máxima</label>
                                <input type="number" 
                                       class="form-control" 
                                       id="nota_maxima" 
                                       name="nota_maxima"
                                       value="<?= $actividad['nota_maxima'] ?? '100' ?>"
                                       step="0.01"
                                       min="0"
                                       max="1000">
                            </div>
                        </div>
                    </div>

                    <!-- Botones -->
                    <div class="d-flex justify-content-between gap-3 mt-5 pt-4 border-top">
                        <a href="tablon.php?asignacion=<?= $id_asignacion ?>" class="btn btn-outline-secondary btn-custom">
                            <i class="fas fa-times"></i> Cancelar
                        </a>
                        <button type="submit" class="btn btn-primary btn-custom">
                            <i class="fas fa-save"></i> 
                            <?= $modo_edicion ? 'Actualizar Actividad' : 'Crear Actividad' ?>
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Scripts -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
    
    <script>
        $(document).ready(function() {
            // Inicializar Select2
            $('select').select2({
                theme: 'bootstrap-5',
                width: '100%'
            });

            // Validación del formulario
            $('#formActividad').on('submit', function(e) {
                const titulo = $('#titulo').val().trim();
                const descripcion = $('#descripcion').val().trim();
                const tipo = $('#tipo').val();
                const fechaProgramada = $('#fecha_programada').val();

                if (!titulo || !descripcion || !tipo || !fechaProgramada) {
                    e.preventDefault();
                    alert('Por favor complete todos los campos obligatorios (*)');
                    return false;
                }

                // Confirmar si es edición
                <?php if ($modo_edicion): ?>
                if (!confirm('¿Está seguro de actualizar esta actividad?')) {
                    e.preventDefault();
                    return false;
                }
                <?php endif; ?>
            });

            // Mostrar/ocultar campos según tipo
            $('#tipo').on('change', function() {
                const tipo = $(this).val();
                const urlField = $('#url_recurso').closest('.mb-4');
                const duracionField = $('#duracion_minutos').closest('.mb-4');

                if (['youtube', 'video', 'enlace', 'articulo', 'referencia'].includes(tipo)) {
                    urlField.show();
                } else {
                    urlField.hide();
                }

                if (tipo === 'examen') {
                    duracionField.show();
                } else {
                    duracionField.hide();
                }
            }).trigger('change');
        });
    </script>
</body>
</html>