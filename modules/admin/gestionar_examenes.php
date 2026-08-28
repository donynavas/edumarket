<?php
session_start();
require_once __DIR__ . '/../../config/database.php';

// Verificar autenticación
if (!isset($_SESSION['user_id']) || ($_SESSION['rol'] != 'admin' && $_SESSION['rol'] != 'director' && $_SESSION['rol'] != 'profesor')) {
    header("Location: " . BASE_URL . "/login.php");
    exit;
}

require_once __DIR__ . '/../../config/TenantGuard.php';
$tid = TenantGuard::id();
$database = new Database();
$db = $database->getConnection();
$user_id = $_SESSION['user_id'];
$rol = $_SESSION['rol'];

$mensaje = '';
$tipo_mensaje = '';

// Verifica que $id_asignacion pertenezca, en esta institución, al profesor
// de la sesión actual (nunca sólo a "algún profesor de la institución").
// Esto evita que un profesor cree/edite/elimine exámenes de la clase de otro.
function assertAsignacionDelProfesor(PDO $db, int $id_asignacion, int $user_id, int $tid): void {
    // tbl_asignacion_docente no tiene columna id_institucion; el aislamiento
    // por tenant se hace vía tbl_profesor, que sí la tiene.
    $stmt = $db->prepare(
        "SELECT ad.id FROM tbl_asignacion_docente ad
         JOIN tbl_profesor pr ON ad.id_profesor = pr.id
         JOIN tbl_persona per ON pr.id_persona = per.id
         WHERE ad.id = :id_asig AND per.id_usuario = :user_id
           AND pr.id_institucion = :tid"
    );
    $stmt->execute([
        ':id_asig' => $id_asignacion,
        ':user_id' => $user_id,
        ':tid' => $tid,
    ]);
    if (!$stmt->fetch()) {
        http_response_code(403);
        throw new Exception('Esta asignación no pertenece a su cuenta de profesor.');
    }
}

// Igual, pero a partir de un id_actividad (examen) ya existente: resuelve su
// id_asignacion_docente y valida que sea del profesor de la sesión.
function assertExamenDelProfesor(PDO $db, int $id_actividad, int $user_id, int $tid): void {
    // Ni tbl_actividad ni tbl_asignacion_docente tienen columna
    // id_institucion; el aislamiento por tenant se hace vía tbl_profesor.
    $stmt = $db->prepare(
        "SELECT a.id FROM tbl_actividad a
         JOIN tbl_asignacion_docente ad ON a.id_asignacion_docente = ad.id
         JOIN tbl_profesor pr ON ad.id_profesor = pr.id
         JOIN tbl_persona per ON pr.id_persona = per.id
         WHERE a.id = :id_actividad AND a.tipo = 'examen' AND per.id_usuario = :user_id
           AND pr.id_institucion = :tid"
    );
    $stmt->execute([
        ':id_actividad' => $id_actividad,
        ':user_id' => $user_id,
        ':tid' => $tid,
    ]);
    if (!$stmt->fetch()) {
        http_response_code(403);
        throw new Exception('Este examen no pertenece a una clase suya.');
    }
}

// ===== PROCESAR FORMULARIO POST =====
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $accion = $_POST['accion'] ?? '';
    
    try {
        $db->beginTransaction();

        // Sólo el profesor titular de la asignación puede crear, editar,
        // eliminar o cambiar el estado de un examen. Admin/Director usan
        // esta pantalla únicamente como vista de supervisión (sólo lectura)
        // sobre los exámenes de toda la institución.
        if (in_array($accion, ['crear', 'actualizar', 'eliminar', 'cambiar_estado'], true) && $rol !== 'profesor') {
            throw new Exception('Sólo el profesor asignado a la clase puede crear, editar o eliminar exámenes.');
        }

        // === CREAR EXAMEN ===
        if ($accion == 'crear') {
            $titulo = trim($_POST['titulo']);
            $id_asignacion = $_POST['id_asignacion'];
            $fecha_programada = $_POST['fecha_programada'];
            $duracion_minutos = $_POST['duracion_minutos'] ?? 60;
            $nota_maxima = $_POST['nota_maxima'] ?? 10;
            $estado = $_POST['estado'] ?? 'programado';
            
            // Validar que la asignación existe, pertenece a esta institución
            // Y es del profesor de la sesión (no de otro profesor cualquiera).
            assertAsignacionDelProfesor($db, (int)$id_asignacion, (int)$user_id, (int)$tid);

            // INSERT EN tbl_actividad (tipo = examen)
            // Nota: tbl_actividad.contenido es NOT NULL sin default; este
            // formulario no lo captura por separado, así que se completa con
            // la descripción (igual patrón usado en gestionar_actividades.php
            // y procesar_actividad.php) para que el INSERT no falle.
            // tbl_actividad no tiene columna id_institucion (se confirmó
            // contra el esquema real) — insertarla aquí bloqueaba TODA
            // creación de examen desde esta pantalla. id_asignacion ya se
            // verificó como propio del profesor/tenant arriba.
            // tbl_actividad.duracion_minutos es de tipo TIME en el esquema real
            // (no INT); se convierte con SEC_TO_TIME para que el valor en
            // minutos que envía el formulario no rompa el INSERT.
            $query = "INSERT INTO tbl_actividad (id_asignacion_docente, titulo, descripcion, tipo,
                      fecha_programada, fecha_limite, duracion_minutos, nota_maxima, estado, recursos_url, contenido)
                      VALUES (:id_asignacion, :titulo, :descripcion, 'examen',
                              :fecha_programada, :fecha_limite, SEC_TO_TIME(:duracion * 60), :nota_maxima, :estado, :recursos, :contenido)";
            $stmt = $db->prepare($query);
            $stmt->bindValue(':id_asignacion', $id_asignacion, PDO::PARAM_INT);
            $stmt->bindValue(':titulo', $titulo, PDO::PARAM_STR);
            $stmt->bindValue(':descripcion', $_POST['descripcion'] ?? '', PDO::PARAM_STR);
            $stmt->bindValue(':fecha_programada', $fecha_programada, PDO::PARAM_STR);
            $stmt->bindValue(':fecha_limite', $_POST['fecha_limite'] ?? null, PDO::PARAM_STR);
            $stmt->bindValue(':duracion', $duracion_minutos, PDO::PARAM_INT);
            $stmt->bindValue(':nota_maxima', $nota_maxima, PDO::PARAM_STR);
            $stmt->bindValue(':estado', $estado, PDO::PARAM_STR);
            $stmt->bindValue(':recursos', $_POST['recursos_url'] ?? '', PDO::PARAM_STR);
            $stmt->bindValue(':contenido', $_POST['contenido'] ?? ($_POST['descripcion'] ?? ''), PDO::PARAM_STR);
            $stmt->execute();
            $id_actividad = $db->lastInsertId();
            
            // Si hay preguntas, insertar en tbl_ingles_ejercicio o tabla similar
            // (Depende de tu estructura de preguntas de examen)
            
            $db->commit();
            $mensaje = 'Examen creado exitosamente';
            $tipo_mensaje = 'success';
            
        } elseif ($accion == 'actualizar') {
            $id_actividad = $_POST['id_actividad'];
            $titulo = trim($_POST['titulo']);
            $id_asignacion = $_POST['id_asignacion'];
            $fecha_programada = $_POST['fecha_programada'];
            $duracion_minutos = $_POST['duracion_minutos'] ?? 60;
            $nota_maxima = $_POST['nota_maxima'] ?? 10;
            $estado = $_POST['estado'] ?? 'programado';
            
            // Validar que la actividad es un examen de esta institución Y
            // pertenece al profesor de la sesión.
            assertExamenDelProfesor($db, (int)$id_actividad, (int)$user_id, (int)$tid);
            // Validar que la nueva asignación elegida también es suya (evita
            // "traspasar" el examen a la clase de otro profesor).
            assertAsignacionDelProfesor($db, (int)$id_asignacion, (int)$user_id, (int)$tid);

            // UPDATE tbl_actividad
            // Ver nota arriba: duracion_minutos es TIME en el esquema real.
            $query = "UPDATE tbl_actividad SET id_asignacion_docente = :id_asignacion,
                      titulo = :titulo, descripcion = :descripcion,
                      fecha_programada = :fecha_programada, fecha_limite = :fecha_limite,
                      duracion_minutos = SEC_TO_TIME(:duracion * 60), nota_maxima = :nota_maxima, estado = :estado
                      WHERE id = :id";
            $stmt = $db->prepare($query);
            $stmt->bindValue(':id_asignacion', $id_asignacion, PDO::PARAM_INT);
            $stmt->bindValue(':titulo', $titulo, PDO::PARAM_STR);
            $stmt->bindValue(':descripcion', $_POST['descripcion'] ?? '', PDO::PARAM_STR);
            $stmt->bindValue(':fecha_programada', $fecha_programada, PDO::PARAM_STR);
            $stmt->bindValue(':fecha_limite', $_POST['fecha_limite'] ?? null, PDO::PARAM_STR);
            $stmt->bindValue(':duracion', $duracion_minutos, PDO::PARAM_INT);
            $stmt->bindValue(':nota_maxima', $nota_maxima, PDO::PARAM_STR);
            $stmt->bindValue(':estado', $estado, PDO::PARAM_STR);
            $stmt->bindValue(':id', $id_actividad, PDO::PARAM_INT);
            $stmt->execute();
            
            $db->commit();
            $mensaje = 'Examen actualizado exitosamente';
            $tipo_mensaje = 'success';
            
        } elseif ($accion == 'eliminar') {
            $id_actividad = $_POST['id_actividad'];
            assertExamenDelProfesor($db, (int)$id_actividad, (int)$user_id, (int)$tid);

            // Verificar si tiene entregas de estudiantes
            $check = $db->prepare("SELECT COUNT(*) as total FROM tbl_entrega_actividad WHERE id_actividad = :id");
            $check->bindValue(':id', $id_actividad, PDO::PARAM_INT);
            $check->execute();
            $result = $check->fetch(PDO::FETCH_ASSOC);
            
            if ($result['total'] > 0) {
                throw new Exception('No se puede eliminar el examen porque ya tiene entregas de estudiantes.');
            }
            
            // DELETE from tbl_actividad
            $query = "DELETE FROM tbl_actividad WHERE id = :id AND tipo = 'examen'";
            $stmt = $db->prepare($query);
            $stmt->bindValue(':id', $id_actividad, PDO::PARAM_INT);
            $stmt->execute();
            
            $db->commit();
            $mensaje = 'Examen eliminado exitosamente';
            $tipo_mensaje = 'warning';
            
        } elseif ($accion == 'cambiar_estado') {
            $id_actividad = $_POST['id_actividad'];
            $estado = $_POST['estado'];
            assertExamenDelProfesor($db, (int)$id_actividad, (int)$user_id, (int)$tid);

            $query = "UPDATE tbl_actividad SET estado = :estado WHERE id = :id AND tipo = 'examen'";
            $stmt = $db->prepare($query);
            $stmt->bindValue(':estado', $estado, PDO::PARAM_STR);
            $stmt->bindValue(':id', $id_actividad, PDO::PARAM_INT);
            $stmt->execute();
            
            $db->commit();
            $mensaje = 'Estado del examen actualizado';
            $tipo_mensaje = 'success';
        }
        
    } catch (PDOException $e) {
        $db->rollBack();
        if ($e->errorInfo[1] == 1062) {
            $mensaje = "Registro duplicado.";
        } else {
            $mensaje = 'Error de base de datos: ' . $e->getMessage();
        }
        $tipo_mensaje = 'danger';
    } catch (Exception $e) {
        $db->rollBack();
        $mensaje = 'Error: ' . $e->getMessage();
        $tipo_mensaje = 'danger';
    }
}

// ===== OBTENER LISTA DE EXÁMENES =====
$filtro_estado = $_GET['estado'] ?? '';
$filtro_asignatura = $_GET['asignatura'] ?? '';
$filtro_fecha_inicio = $_GET['fecha_inicio'] ?? '';
$filtro_fecha_fin = $_GET['fecha_fin'] ?? '';
$busqueda = $_GET['busqueda'] ?? '';

// Si es profesor, filtrar por sus asignaciones
// ✅ Corregido: la subconsulta usaba tbl_usuario.id_persona, columna que no
// existe (la relación real es tbl_persona.id_usuario -> tbl_usuario.id), por
// lo que esta rama fallaba siempre para el rol profesor.
if ($rol == 'profesor') {
    $query_prof = "SELECT ad.id FROM tbl_asignacion_docente ad
                   JOIN tbl_profesor pr ON ad.id_profesor = pr.id
                   JOIN tbl_persona per ON pr.id_persona = per.id
                   WHERE per.id_usuario = :user_id AND pr.id_institucion = :tid";
    $stmt_prof = $db->prepare($query_prof);
    $stmt_prof->bindValue(':user_id', $user_id, PDO::PARAM_INT);
    $stmt_prof->bindValue(':tid', $tid, PDO::PARAM_INT);
    $stmt_prof->execute();
    $asignaciones_profesor = $stmt_prof->fetchAll(PDO::FETCH_COLUMN);

    if (empty($asignaciones_profesor)) {
        $asignaciones_profesor = [0]; // Para evitar error en IN()
    }
}

$query = "SELECT a.id, a.titulo, a.descripcion, a.tipo, a.fecha_programada, a.fecha_limite,
          TIME_TO_SEC(a.duracion_minutos)/60 as duracion_minutos, a.nota_maxima, a.estado, a.recursos_url,
          asig.nombre as asignatura_nombre,
          g.nombre as grado_nombre,
          s.nombre as seccion_nombre,
          p.primer_nombre as profesor_nombre, p.primer_apellido as profesor_apellido,
          COUNT(DISTINCT ea.id) as total_entregas,
          AVG(ea.nota_obtenida) as promedio_notas
          FROM tbl_actividad a
          JOIN tbl_asignacion_docente ad ON a.id_asignacion_docente = ad.id
          JOIN tbl_asignatura asig ON ad.id_asignatura = asig.id
          JOIN tbl_seccion s ON ad.id_seccion = s.id
          JOIN tbl_grado g ON s.id_grado = g.id
          JOIN tbl_profesor prof ON ad.id_profesor = prof.id
          JOIN tbl_persona p ON prof.id_persona = p.id
          LEFT JOIN tbl_entrega_actividad ea ON a.id = ea.id_actividad
          WHERE a.tipo = 'examen' AND asig.id_institucion = :tid";

$params = [':tid' => $tid];

// Si es profesor, filtrar por sus asignaciones
// ✅ Corregido: mezclaba placeholders posicionales (?) en el SQL con claves
// nombradas en el arreglo de parámetros — PDO no permite mezclar ambos.
if ($rol == 'profesor' && !empty($asignaciones_profesor)) {
    $inKeys = [];
    foreach ($asignaciones_profesor as $key => $id_asig) {
        $paramKey = ':asig_' . $key;
        $inKeys[] = $paramKey;
        $params[$paramKey] = $id_asig;
    }
    $query .= " AND ad.id IN (" . implode(',', $inKeys) . ")";
}

if (!empty($filtro_estado)) {
    $query .= " AND a.estado = :estado";
    $params[':estado'] = $filtro_estado;
}

if (!empty($filtro_asignatura)) {
    $query .= " AND asig.id = :asignatura";
    $params[':asignatura'] = $filtro_asignatura;
}

if (!empty($filtro_fecha_inicio)) {
    $query .= " AND a.fecha_programada >= :fecha_inicio";
    $params[':fecha_inicio'] = $filtro_fecha_inicio . ' 00:00:00';
}

if (!empty($filtro_fecha_fin)) {
    $query .= " AND a.fecha_programada <= :fecha_fin";
    $params[':fecha_fin'] = $filtro_fecha_fin . ' 23:59:59';
}

if (!empty($busqueda)) {
    $query .= " AND (a.titulo LIKE :busqueda OR a.descripcion LIKE :busqueda)";
    $params[':busqueda'] = "%$busqueda%";
}

$query .= " GROUP BY a.id ORDER BY a.fecha_programada DESC";

$stmt = $db->prepare($query);
foreach ($params as $key => $value) {
    $stmt->bindValue($key, $value);
}
$stmt->execute();
$examenes = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Obtener asignaturas para filtro
$query = "SELECT id, nombre FROM tbl_asignatura WHERE id_institucion = :tid ORDER BY nombre";
$stmt = $db->prepare($query);
$stmt->bindValue(':tid', $tid, PDO::PARAM_INT);
$stmt->execute();
$asignaturas = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Obtener asignaciones docentes para el formulario
if ($rol == 'profesor' && !empty($asignaciones_profesor)) {
    $inKeys = [];
    $params = [':tid' => $tid];
    foreach ($asignaciones_profesor as $key => $id_asig) {
        $paramKey = ':asig_' . $key;
        $inKeys[] = $paramKey;
        $params[$paramKey] = $id_asig;
    }
    $query = "SELECT ad.id, asig.nombre as asignatura_nombre, g.nombre as grado_nombre, s.nombre as seccion_nombre
              FROM tbl_asignacion_docente ad
              JOIN tbl_asignatura asig ON ad.id_asignatura = asig.id
              JOIN tbl_seccion s ON ad.id_seccion = s.id
              JOIN tbl_grado g ON s.id_grado = g.id
              WHERE ad.id IN (" . implode(',', $inKeys) . ") AND asig.id_institucion = :tid
              ORDER BY asig.nombre, g.nombre, s.nombre";
    $stmt = $db->prepare($query);
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value);
    }
    $stmt->execute();
    $asignaciones_docentes = $stmt->fetchAll(PDO::FETCH_ASSOC);
} else {
    $query = "SELECT ad.id, asig.nombre as asignatura_nombre, g.nombre as grado_nombre, s.nombre as seccion_nombre
              FROM tbl_asignacion_docente ad
              JOIN tbl_asignatura asig ON ad.id_asignatura = asig.id
              JOIN tbl_seccion s ON ad.id_seccion = s.id
              JOIN tbl_grado g ON s.id_grado = g.id
              WHERE asig.id_institucion = :tid
              ORDER BY asig.nombre, g.nombre, s.nombre";
    $stmt = $db->prepare($query);
    $stmt->bindValue(':tid', $tid, PDO::PARAM_INT);
    $stmt->execute();
    $asignaciones_docentes = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Estados de examen
$estados_examen = [
    'programado' => ['label' => 'Programado', 'class' => 'bg-secondary', 'icon' => 'fa-clock'],
    'activo' => ['label' => 'Activo', 'class' => 'bg-success', 'icon' => 'fa-play'],
    'cerrado' => ['label' => 'Cerrado', 'class' => 'bg-danger', 'icon' => 'fa-lock'],
    'cancelado' => ['label' => 'Cancelado', 'class' => 'bg-dark', 'icon' => 'fa-times']
];
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gestión de Exámenes - Educación Plus</title>
    
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="https://cdn.datatables.net/1.13.4/css/dataTables.bootstrap5.min.css" rel="stylesheet">
    
    <style>
        :root { --primary: #2c3e50; --secondary: #3498db; --sidebar-width: 250px; }
        body { font-family: 'Segoe UI', sans-serif; background: #f8f9fa; }
        .sidebar { position: fixed; top: 0; left: 0; height: 100vh; width: var(--sidebar-width); background: var(--primary); color: white; padding-top: 60px; z-index: 1000; }
        .sidebar .nav-link { color: rgba(255,255,255,0.8); padding: 12px 20px; }
        .sidebar .nav-link:hover, .sidebar .nav-link.active { color: white; background: rgba(255,255,255,0.1); }
        .main-content { margin-left: var(--sidebar-width); padding: 20px; }
        .card-custom { background: white; border-radius: 10px; box-shadow: 0 2px 10px rgba(0,0,0,0.08); border: none; margin-bottom: 24px; }
        .badge-estado { padding: 5px 12px; border-radius: 20px; font-size: 0.75rem; font-weight: 600; }
        @media (max-width: 768px) { .sidebar { transform: translateX(-100%); } .sidebar.active { transform: translateX(0); } .main-content { margin-left: 0; } }
    </style>
</head>
<body>
    <!-- Sidebar -->
    <div class="sidebar" id="sidebar">
        <div class="text-center mb-4">
            <h4><i class="fas fa-graduation-cap"></i> Educación Plus</h4>
            <small>Panel de Administración</small>
        </div>
        <nav class="nav flex-column">
            <a class="nav-link" href="../../index.php"><i class="fas fa-tachometer-alt"></i> Dashboard</a>
            <a class="nav-link" href="gestionar_estudiantes.php"><i class="fas fa-user-graduate"></i> Estudiantes</a>
            <a class="nav-link" href="gestionar_profesores.php"><i class="fas fa-chalkboard-teacher"></i> Profesores</a>
            <a class="nav-link" href="gestionar_grados.php"><i class="fas fa-layer-group"></i> Grados/Secciones</a>
            <a class="nav-link" href="gestionar_asignaturas.php"><i class="fas fa-book"></i> Asignaturas</a>
            <a class="nav-link" href="horario_clases.php"><i class="fas fa-calendar-week"></i> Horario</a>
            <a class="nav-link active" href="gestionar_examenes.php"><i class="fas fa-file-alt"></i> Exámenes</a>
            <a class="nav-link" href="calificaciones.php"><i class="fas fa-star"></i> Calificaciones</a>
            <a class="nav-link" href="manual_convivencia.php"><i class="fas fa-handshake"></i> Convivencia Escolar</a>
            <a class="nav-link" href="../../logout.php"><i class="fas fa-sign-out-alt"></i> Cerrar Sesión</a>
        </nav>
    </div>

    <!-- Main Content -->
    <div class="main-content">
        <!-- Header -->
        <div class="d-flex justify-content-between align-items-center mb-4">
            <div>
                <h2><i class="fas fa-file-alt text-danger"></i> Gestión de Exámenes</h2>
                <?php if ($rol === 'profesor'): ?>
                <p class="text-muted mb-0">Crear y administrar exámenes en línea</p>
                <?php else: ?>
                <p class="text-muted mb-0">Vista de supervisión — sólo el profesor de cada clase puede crear, editar o eliminar sus exámenes</p>
                <?php endif; ?>
            </div>
            <?php if ($rol === 'profesor'): ?>
            <button class="btn btn-danger" data-bs-toggle="modal" data-bs-target="#modalExamen">
                <i class="fas fa-plus"></i> Nuevo Examen
            </button>
            <?php endif; ?>
        </div>

        <!-- Messages -->
        <?php if ($mensaje): ?>
        <div class="alert alert-<?= $tipo_mensaje ?> alert-dismissible fade show">
            <i class="fas fa-<?= $tipo_mensaje == 'success' ? 'check-circle' : 'exclamation-circle' ?>"></i>
            <?= htmlspecialchars($mensaje) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php endif; ?>

        <!-- Stats Cards -->
        <div class="row mb-4">
            <div class="col-md-3 mb-3">
                <div class="card-custom p-3 text-center">
                    <h3 class="mb-0 text-danger"><?= count($examenes) ?></h3>
                    <p class="mb-0 text-muted">Total Exámenes</p>
                </div>
            </div>
            <div class="col-md-3 mb-3">
                <div class="card-custom p-3 text-center">
                    <h3 class="mb-0 text-success"><?= count(array_filter($examenes, fn($e) => $e['estado'] == 'activo')) ?></h3>
                    <p class="mb-0 text-muted">Activos</p>
                </div>
            </div>
            <div class="col-md-3 mb-3">
                <div class="card-custom p-3 text-center">
                    <h3 class="mb-0 text-secondary"><?= count(array_filter($examenes, fn($e) => $e['estado'] == 'programado')) ?></h3>
                    <p class="mb-0 text-muted">Programados</p>
                </div>
            </div>
            <div class="col-md-3 mb-3">
                <div class="card-custom p-3 text-center">
                    <h3 class="mb-0 text-primary"><?= array_sum(array_column($examenes, 'total_entregas')) ?></h3>
                    <p class="mb-0 text-muted">Entregas</p>
                </div>
            </div>
        </div>

        <!-- Filters -->
        <div class="card-custom p-4 mb-4">
            <form method="GET" class="row g-3">
                <div class="col-md-2">
                    <label class="form-label">Estado</label>
                    <select name="estado" class="form-select">
                        <option value="">Todos</option>
                        <?php foreach ($estados_examen as $key => $estado): ?>
                        <option value="<?= $key ?>" <?= $filtro_estado == $key ? 'selected' : '' ?>><?= $estado['label'] ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Asignatura</label>
                    <select name="asignatura" class="form-select">
                        <option value="">Todas</option>
                        <?php foreach ($asignaturas as $asig): ?>
                        <option value="<?= $asig['id'] ?>" <?= $filtro_asignatura == $asig['id'] ? 'selected' : '' ?>><?= htmlspecialchars($asig['nombre']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label">Fecha Inicio</label>
                    <input type="date" name="fecha_inicio" class="form-control" value="<?= htmlspecialchars($filtro_fecha_inicio) ?>">
                </div>
                <div class="col-md-2">
                    <label class="form-label">Fecha Fin</label>
                    <input type="date" name="fecha_fin" class="form-control" value="<?= htmlspecialchars($filtro_fecha_fin) ?>">
                </div>
                <div class="col-md-2">
                    <label class="form-label">Búsqueda</label>
                    <input type="text" name="busqueda" class="form-control" placeholder="Título..." value="<?= htmlspecialchars($busqueda) ?>">
                </div>
                <div class="col-12">
                    <button type="submit" class="btn btn-secondary"><i class="fas fa-filter"></i> Filtrar</button>
                    <a href="gestionar_examenes.php" class="btn btn-outline-secondary"><i class="fas fa-redo"></i> Limpiar</a>
                </div>
            </form>
        </div>

        <!-- Exams Table -->
        <div class="card-custom">
            <div class="card-header bg-white py-3">
                <div class="d-flex justify-content-between align-items-center">
                    <h5 class="mb-0"><i class="fas fa-list"></i> Lista de Exámenes</h5>
                    <span class="badge bg-danger"><?= count($examenes) ?> exámenes</span>
                </div>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover mb-0" id="tablaExamenes">
                        <thead class="table-light">
                            <tr>
                                <th>Examen</th>
                                <th>Asignatura</th>
                                <th>Grado/Sección</th>
                                <th>Fecha</th>
                                <th>Duración</th>
                                <th>Entregas</th>
                                <th>Estado</th>
                                <th>Acciones</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php // Sin fila manual de "sin datos": con DataTables, una tbody con una
                            // sola fila de 1 <td colspan> frente a un thead de más columnas dispara
                            // "Incorrect column count" (tn/18). Con tbody vacío, DataTables muestra
                            // su propio mensaje localizado (idioma es-ES cargado abajo). ?>
                            <?php foreach ($examenes as $examen):
                                $estado = $estados_examen[$examen['estado']] ?? ['label' => $examen['estado'], 'class' => 'bg-secondary', 'icon' => 'fa-circle'];
                            ?>
                            <tr>
                                <td>
                                    <strong><?= htmlspecialchars($examen['titulo']) ?></strong>
                                    <?php if ($examen['descripcion']): ?>
                                    <br><small class="text-muted"><?= htmlspecialchars(substr($examen['descripcion'], 0, 50)) ?>...</small>
                                    <?php endif; ?>
                                </td>
                                <td><?= htmlspecialchars($examen['asignatura_nombre']) ?></td>
                                <td>
                                    <span class="badge bg-info"><?= htmlspecialchars($examen['grado_nombre']) ?></span>
                                    <span class="badge bg-secondary"><?= htmlspecialchars($examen['seccion_nombre']) ?></span>
                                </td>
                                <td>
                                    <small><i class="fas fa-calendar"></i> <?= date('d/m/Y', strtotime($examen['fecha_programada'])) ?></small>
                                    <br>
                                    <small><i class="fas fa-clock"></i> <?= date('H:i', strtotime($examen['fecha_programada'])) ?></small>
                                </td>
                                <td><span class="badge bg-warning"><?= $examen['duracion_minutos'] ?> min</span></td>
                                <td>
                                    <span class="badge bg-primary"><?= $examen['total_entregas'] ?></span>
                                    <?php if ($examen['promedio_notas']): ?>
                                    <br><small class="text-muted">Prom: <?= number_format($examen['promedio_notas'], 2) ?></small>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="badge-estado <?= $estado['class'] ?>">
                                        <i class="fas <?= $estado['icon'] ?>"></i> <?= $estado['label'] ?>
                                    </span>
                                </td>
                                <td>
                                    <div class="btn-group btn-group-sm">
                                        <button class="btn btn-info" onclick="verExamen(<?= $examen['id'] ?>)"><i class="fas fa-eye"></i></button>
                                        <?php if ($rol === 'profesor'): ?>
                                        <button class="btn btn-warning" onclick="editarExamen(<?= $examen['id'] ?>)"><i class="fas fa-edit"></i></button>
                                        <?php endif; ?>
                                        <button class="btn btn-success" onclick="verResultados(<?= $examen['id'] ?>)"><i class="fas fa-chart-bar"></i></button>
                                        <?php if ($rol === 'profesor'): ?>
                                        <button class="btn btn-danger" onclick="eliminarExamen(<?= $examen['id'] ?>)"><i class="fas fa-trash"></i></button>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <!-- Modal Nuevo/Editar Examen -->
    <div class="modal fade" id="modalExamen" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header bg-danger text-white">
                    <h5 class="modal-title" id="modalTitleExamen"><i class="fas fa-plus"></i> Nuevo Examen</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" id="formExamen">
                    <div class="modal-body">
                        <input type="hidden" name="accion" id="accion_examen" value="crear">
                        <input type="hidden" name="id_actividad" id="id_actividad">
                        
                        <div class="row g-3">
                            <div class="col-md-8">
                                <label class="form-label">Título del Examen *</label>
                                <input type="text" name="titulo" id="titulo" class="form-control" required>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Estado *</label>
                                <select name="estado" id="estado" class="form-select" required>
                                    <?php foreach ($estados_examen as $key => $estado): ?>
                                    <option value="<?= $key ?>" <?= $key == 'programado' ? 'selected' : '' ?>><?= $estado['label'] ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-12">
                                <label class="form-label">Descripción</label>
                                <textarea name="descripcion" id="descripcion" class="form-control" rows="2" placeholder="Instrucciones para el estudiante..."></textarea>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Asignación Docente *</label>
                                <select name="id_asignacion" id="id_asignacion" class="form-select" required>
                                    <option value="">Seleccionar</option>
                                    <?php foreach ($asignaciones_docentes as $asig): ?>
                                    <option value="<?= $asig['id'] ?>">
                                        <?= htmlspecialchars($asig['asignatura_nombre']) ?> - <?= htmlspecialchars($asig['grado_nombre']) ?> <?= htmlspecialchars($asig['seccion_nombre']) ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Nota Máxima *</label>
                                <input type="number" name="nota_maxima" id="nota_maxima" class="form-control" value="10" min="0" max="100" step="0.1" required>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Fecha Programada *</label>
                                <input type="datetime-local" name="fecha_programada" id="fecha_programada" class="form-control" required>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Fecha Límite</label>
                                <input type="datetime-local" name="fecha_limite" id="fecha_limite" class="form-control">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Duración (minutos) *</label>
                                <input type="number" name="duracion_minutos" id="duracion_minutos" class="form-control" value="60" min="1" max="180" required>
                            </div>
                            <div class="col-12">
                                <label class="form-label">URL de Recursos</label>
                                <input type="url" name="recursos_url" id="recursos_url" class="form-control" placeholder="https://...">
                                <small class="text-muted">Enlace a materiales de estudio o referencia</small>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><i class="fas fa-times"></i> Cancelar</button>
                        <button type="submit" class="btn btn-danger"><i class="fas fa-save"></i> Guardar Examen</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Scripts -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.4/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.4/js/dataTables.bootstrap5.min.js"></script>
    
    <script>
        $(document).ready(function() {
            $('#tablaExamenes').DataTable({
                language: { url: '//cdn.datatables.net/plug-ins/1.13.4/i18n/es-ES.json' },
                pageLength: 10,
                order: [[3, 'desc']]
            });
            
            $('#sidebarToggle').click(function() {
                $('#sidebar').toggleClass('active');
            });
        });
        
        function verExamen(id) {
            alert('Ver examen ID: ' + id);
            // Implementar modal de detalle
        }
        
        function editarExamen(id) {
            // Cargar datos del examen en el modal
            $.get('api/get_examen.php', { id: id }, function(data) {
                if (data.success) {
                    $('#accion_examen').val('actualizar');
                    $('#id_actividad').val(data.examen.id);
                    $('#modalTitleExamen').html('<i class="fas fa-edit"></i> Editar Examen');
                    $('#titulo').val(data.examen.titulo);
                    $('#descripcion').val(data.examen.descripcion);
                    $('#id_asignacion').val(data.examen.id_asignacion_docente);
                    $('#nota_maxima').val(data.examen.nota_maxima);
                    $('#fecha_programada').val(data.examen.fecha_programada);
                    $('#fecha_limite').val(data.examen.fecha_limite);
                    $('#duracion_minutos').val(data.examen.duracion_minutos);
                    $('#estado').val(data.examen.estado);
                    $('#recursos_url').val(data.examen.recursos_url);
                    $('#modalExamen').modal('show');
                }
            }, 'json');
        }
        
        function eliminarExamen(id) {
            if (confirm('¿Está seguro de eliminar este examen? Esta acción no se puede deshacer.')) {
                const form = $('<form>', { method: 'POST', action: 'gestionar_examenes.php' });
                form.append($('<input>', { type: 'hidden', name: 'accion', value: 'eliminar' }));
                form.append($('<input>', { type: 'hidden', name: 'id_actividad', value: id }));
                $('body').append(form);
                form.submit();
            }
        }
        
        function verResultados(id) {
            window.location.href = 'resultados_examen.php?id_examen=' + id;
        }
    </script>
</body>
</html>