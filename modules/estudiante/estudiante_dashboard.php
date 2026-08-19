<?php

session_start();
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../config/TenantGuard.php';

// Verificar que sea estudiante
if (!isset($_SESSION['user_id']) || $_SESSION['rol'] != 'estudiante') {
    header("Location: " . BASE_URL . "/login.php");
    exit;
}

$database = new Database();
$db = $database->getConnection();
$user_id = $_SESSION['user_id'];
$tid = TenantGuard::id();

require_once __DIR__ . '/../../config/MensajeHelper.php';

// Barrido de recordatorios de fecha_limite próxima (no hay cron real en
// este entorno -- se revisa cuando el estudiante entra al sistema, en su
// página de aterrizaje tras el login). Idempotente vía
// tbl_actividad.recordatorio_enviado -- corre ANTES de contar los no
// leídos para que un recordatorio recién generado en esta misma carga ya
// se refleje en el badge.
require_once __DIR__ . '/../../config/RecordatorioHelper.php';
RecordatorioHelper::procesarRecordatoriosPendientes($db, $tid);

$totalNoLeidos = MensajeHelper::contarNoLeidos($db, (int) $user_id);

// ===== OBTENER DATOS DEL ESTUDIANTE =====
$query = "SELECT
          e.id as id_estudiante, e.nie, e.estado_familiar, e.discapacidad, e.trabaja,
          p.primer_nombre, p.segundo_nombre, p.primer_apellido, p.segundo_apellido,
          p.dui, p.fecha_nacimiento, p.sexo, p.nacionalidad, p.direccion,
          p.telefono_fijo, p.celular, p.email,
          g.id as id_grado, g.nombre as grado_nombre, g.nivel, g.nota_minima_aprobacion,
          s.id as id_seccion, s.nombre as seccion_nombre,
          m.id as id_matricula, m.anno, m.estado,
          u.usuario
          FROM tbl_estudiante e
          JOIN tbl_persona p ON e.id_persona = p.id
          JOIN tbl_usuario u ON p.id_usuario = u.id
          LEFT JOIN tbl_matricula m ON e.id = m.id_estudiante
          LEFT JOIN tbl_seccion s ON m.id_seccion = s.id
          LEFT JOIN tbl_grado g ON s.id_grado = g.id
          WHERE p.id_usuario = :user_id
          AND (m.estado = 'activo' OR m.estado IS NULL)
          AND e.id_institucion = :tid
          ORDER BY m.anno DESC, m.id DESC
          LIMIT 1";

$stmt = $db->prepare($query);
$stmt->bindValue(':user_id', $user_id, PDO::PARAM_INT);
$stmt->bindValue(':tid', $tid, PDO::PARAM_INT);
$stmt->execute();
$estudiante = $stmt->fetch(PDO::FETCH_ASSOC);

// Si no tiene matrícula, mostrar mensaje
if (!$estudiante) {
    ?>
    <!DOCTYPE html>
    <html lang="es">
    <head>
        <meta charset="UTF-8">
        <title>Error - Educación Plus</title>
        <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    </head>
    <body class="bg-light">
        <div class="container mt-5">
            <div class="row justify-content-center">
                <div class="col-md-8">
                    <div class="card shadow">
                        <div class="card-body p-4 text-center">
                            <i class="fas fa-exclamation-triangle fa-4x text-warning mb-3"></i>
                            <h4>Sin Matrícula Activa</h4>
                            <p class="text-muted">Contacta a administración para regularizar tu situación.</p>
                            <a href="<?= BASE_URL ?>/logout.php" class="btn btn-secondary">Cerrar Sesión</a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </body>
    </html>
    <?php
    exit;
}

// ✅ EXTRAER VARIABLES CON VALORES POR DEFECTO
$id_estudiante = $estudiante['id_estudiante'] ?? 0;
$id_matricula = $estudiante['id_matricula'] ?? 0;
$id_seccion = $estudiante['id_seccion'] ?? 0;
$anno = $estudiante['anno'] ?? date('Y');
// NOTA: ya no se filtra por "período" (id_periodo, un entero legado 1-4 sin
// relación con tbl_periodo real) -- una asignación docente y una matrícula
// duran el año lectivo completo, así que el estudiante debe ver TODAS sus
// clases/actividades del año sin importar en qué "período" se haya creado
// la asignación. Ese filtro era la causa de que exámenes/actividades ya
// asignados no aparecieran (ver commit "Corregir regresión..." de hoy). El
// concepto de período sigue existiendo, pero solo para el Cuadro de Notas
// (modules/admin/cuadro_notas.php), que ya usa tbl_periodo real por fechas.

// ===== ESTADÍSTICAS DEL ESTUDIANTE =====

// 1. Promedio general
//
// Igual que en mis_notas.php: las notas de examen viven en
// tbl_intento_examen, no en tbl_entrega_actividad. vw_logro_estudiante
// (migrations/2026_08_16_vista_logro_estudiante.sql) unifica ambas
// fuentes -- sin ella, un estudiante que solo hubiera presentado
// exámenes (sin tareas entregadas aún) seguía viendo "0.00" de Promedio
// General aunque ya tuviera exámenes calificados.
$query_prom = "SELECT AVG(nota_obtenida) as promedio, COUNT(*) as total_calificadas
               FROM vw_logro_estudiante
               WHERE id_matricula = :id_matricula
               AND estado_entrega = 'calificado'
               AND nota_obtenida IS NOT NULL";
$stmt_prom = $db->prepare($query_prom);
$stmt_prom->execute([
    ':id_matricula' => $id_matricula
]);
$stats_promedio = $stmt_prom->fetch(PDO::FETCH_ASSOC);
$promedio_general = $stats_promedio['promedio'] ?? 0;

// 2. Tareas pendientes
$query_pendientes = "SELECT COUNT(*) as pendientes
                     FROM tbl_actividad act
                     JOIN tbl_asignacion_docente ad ON act.id_asignacion_docente = ad.id
                     LEFT JOIN tbl_entrega_actividad ea ON act.id = ea.id_actividad 
                         AND ea.id_matricula = :id_matricula
                     WHERE ad.id_seccion = :id_seccion
                     AND ad.anno = :anno
                     AND act.tipo IN ('tarea', 'laboratorio', 'proyecto')
                     AND act.fecha_limite >= NOW()
                     AND (ea.id IS NULL OR ea.estado_entrega != 'calificado')";
$stmt_pendientes = $db->prepare($query_pendientes);
$stmt_pendientes->execute([
    ':id_matricula' => $id_matricula,
    ':id_seccion' => $id_seccion,
    ':anno' => $anno
]);
$tareas_pendientes = $stmt_pendientes->fetchColumn();

// 3. Próximos exámenes (CONSULTA ÚNICA Y CORREGIDA)
$query_examenes = "SELECT 
    act.id, act.titulo, act.fecha_programada, 
    asig.nombre as asignatura,
    DATEDIFF(act.fecha_programada, NOW()) as dias_restantes
    FROM tbl_actividad act
    JOIN tbl_asignacion_docente ad ON act.id_asignacion_docente = ad.id
    JOIN tbl_asignatura asig ON ad.id_asignatura = asig.id
    WHERE ad.id_seccion = :id_seccion
    AND ad.anno = :anno
    AND act.tipo = 'examen'
    AND act.estado = 'activo'
    AND act.fecha_programada >= NOW()
    ORDER BY act.fecha_programada ASC
    LIMIT 3";

$stmt_examenes = $db->prepare($query_examenes);
$stmt_examenes->execute([
    ':id_seccion' => $id_seccion,
    ':anno' => $anno
]);
$proximos_examenes = $stmt_examenes->fetchAll(PDO::FETCH_ASSOC);

// 4. Asistencia
$query_asistencia = "SELECT 
    COUNT(*) as total_dias,
    SUM(CASE WHEN estado = 'presente' THEN 1 ELSE 0 END) as presentes
    FROM tbl_asistencia
    WHERE id_matricula = :id_matricula";
$stmt_asistencia = $db->prepare($query_asistencia);
$stmt_asistencia->execute([':id_matricula' => $id_matricula]);
$asistencia = $stmt_asistencia->fetch(PDO::FETCH_ASSOC);
$porcentaje_asistencia = $asistencia['total_dias'] > 0 
    ? round(($asistencia['presentes'] / $asistencia['total_dias']) * 100) 
    : 100;

// 5. Notificaciones
$query_notif = "SELECT COUNT(*) as no_leidas FROM tbl_notificacion 
                WHERE id_destinatario = :user_id AND leido = 0";
$stmt_notif = $db->prepare($query_notif);
$stmt_notif->execute([':user_id' => $user_id]);
$notificaciones = $stmt_notif->fetchColumn() ?? 0;

// ===== CLASES/MATERIAS DEL ESTUDIANTE =====
$query_clases = "SELECT 
    ad.id as id_asignacion,
    asig.id as id_asignatura, asig.nombre as asignatura, asig.codigo,
    per.primer_nombre as profesor_nombre, per.primer_apellido as profesor_apellido,
    COALESCE(prof.especialidad, 'General') as profesor_especialidad,
    COUNT(DISTINCT act.id) as total_actividades,
    COUNT(DISTINCT CASE WHEN ea.id IS NOT NULL THEN ea.id END) as actividades_completadas,
    AVG(ea.nota_obtenida) as promedio_materia
    FROM tbl_asignacion_docente ad
    JOIN tbl_asignatura asig ON ad.id_asignatura = asig.id
    JOIN tbl_profesor prof ON ad.id_profesor = prof.id
    JOIN tbl_persona per ON prof.id_persona = per.id
    LEFT JOIN tbl_actividad act ON ad.id = act.id_asignacion_docente
    LEFT JOIN tbl_entrega_actividad ea ON act.id = ea.id_actividad 
        AND ea.id_matricula = :id_matricula
    WHERE ad.id_seccion = :id_seccion
    AND ad.anno = :anno
    GROUP BY ad.id
    ORDER BY asig.nombre";

$stmt_clases = $db->prepare($query_clases);
$stmt_clases->execute([
    ':id_seccion' => $id_seccion,
    ':anno' => $anno,
    ':id_matricula' => $id_matricula
]);
$clases = $stmt_clases->fetchAll(PDO::FETCH_ASSOC);

// ===== ACTIVIDADES PENDIENTES =====
$query_actividades = "SELECT 
    act.id, act.titulo, act.tipo, act.descripcion, act.fecha_limite, act.nota_maxima,
    asig.nombre as asignatura, asig.codigo as codigo_asignatura,
    CASE WHEN ea.id IS NOT NULL THEN ea.estado_entrega ELSE 'pendiente' END as estado_entrega,
    ea.nota_obtenida
    FROM tbl_actividad act
    JOIN tbl_asignacion_docente ad ON act.id_asignacion_docente = ad.id
    JOIN tbl_asignatura asig ON ad.id_asignatura = asig.id
    LEFT JOIN tbl_entrega_actividad ea ON act.id = ea.id_actividad 
        AND ea.id_matricula = :id_matricula
    WHERE ad.id_seccion = :id_seccion
    AND ad.anno = :anno
    AND act.fecha_limite >= CURDATE()
    AND (ea.id IS NULL OR ea.estado_entrega != 'calificado')
    ORDER BY act.fecha_limite ASC
    LIMIT 5";

$stmt_actividades = $db->prepare($query_actividades);
$stmt_actividades->execute([
    ':id_seccion' => $id_seccion,
    ':anno' => $anno,
    ':id_matricula' => $id_matricula
]);
$actividades_pendientes = $stmt_actividades->fetchAll(PDO::FETCH_ASSOC);

// ===== ÚLTIMAS CALIFICACIONES =====
$query_notas = "SELECT 
    act.titulo, act.tipo, act.fecha_programada,
    asig.nombre as asignatura,
    ea.nota_obtenida, ea.observacion_docente, ea.estado_entrega,
    g.nota_minima_aprobacion
    FROM tbl_entrega_actividad ea
    JOIN tbl_actividad act ON ea.id_actividad = act.id
    JOIN tbl_asignacion_docente ad ON act.id_asignacion_docente = ad.id
    JOIN tbl_asignatura asig ON ad.id_asignatura = asig.id
    JOIN tbl_seccion s ON ad.id_seccion = s.id
    JOIN tbl_grado g ON s.id_grado = g.id
    WHERE ea.id_matricula = :id_matricula
    AND ea.nota_obtenida IS NOT NULL
    ORDER BY ea.fecha_entrega DESC
    LIMIT 5";

$stmt_notas = $db->prepare($query_notas);
$stmt_notas->execute([':id_matricula' => $id_matricula]);
$ultimas_notas = $stmt_notas->fetchAll(PDO::FETCH_ASSOC);

// ===== CONFIGURACIÓN =====
$tipos_actividad = [
    'tarea' => ['label' => 'Tarea', 'icon' => 'fa-clipboard', 'color' => 'info'],
    'examen' => ['label' => 'Examen', 'icon' => 'fa-file-alt', 'color' => 'danger'],
    'laboratorio' => ['label' => 'Laboratorio', 'icon' => 'fa-flask', 'color' => 'warning'],
    'foro' => ['label' => 'Foro', 'icon' => 'fa-comments', 'color' => 'success'],
    'proyecto' => ['label' => 'Proyecto', 'icon' => 'fa-folder-open', 'color' => 'primary'],
    'recurso' => ['label' => 'Recurso', 'icon' => 'fa-book', 'color' => 'secondary']
];

// Calcular edad
$fecha_nac = new DateTime($estudiante['fecha_nacimiento'] ?? date('Y-m-d'));
$edad = (new DateTime())->diff($fecha_nac)->y;
$nombre_completo = trim("{$estudiante['primer_nombre']} {$estudiante['segundo_nombre']} {$estudiante['primer_apellido']} {$estudiante['segundo_apellido']}");
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Mi Dashboard - Educación Plus</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/fullcalendar@5.11.3/main.min.css" rel="stylesheet">
    <style>
        :root { --primary: #4361ee; --secondary: #3f37c9; --success: #4cc9f0; --warning: #f72585; --danger: #e63946; --sidebar-width: 260px; }
        body { font-family: 'Segoe UI', sans-serif; background: #f5f7fa; }
        .sidebar { position: fixed; top: 0; left: 0; height: 100vh; width: var(--sidebar-width); background: linear-gradient(180deg, #1d3557, #2a4365); color: white; z-index: 1000; }
        .sidebar .nav-link { color: rgba(255,255,255,0.85); padding: 12px 20px; border-radius: 8px; margin: 2px 0; }
        .sidebar .nav-link:hover, .sidebar .nav-link.active { background: rgba(255,255,255,0.15); color: white; }
        .main-content { margin-left: var(--sidebar-width); padding: 20px 30px; }
        .card-custom { background: white; border-radius: 12px; box-shadow: 0 2px 12px rgba(0,0,0,0.08); margin-bottom: 20px; }
        .stat-card { background: white; border-radius: 12px; padding: 20px; box-shadow: 0 2px 8px rgba(0,0,0,0.08); }
        .profile-avatar { width: 70px; height: 70px; border-radius: 50%; background: linear-gradient(135deg, var(--primary), var(--secondary)); color: white; display: flex; align-items: center; justify-content: center; font-size: 1.5rem; font-weight: 600; margin: 0 auto 10px; }
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
            <div class="profile-avatar"><?= strtoupper(substr($estudiante['primer_nombre'] ?? 'E', 0, 1)) ?></div>
            <div class="fw-bold"><?= htmlspecialchars($estudiante['primer_nombre'] . ' ' . $estudiante['primer_apellido']) ?></div>
            <small class="text-white-50"><?= htmlspecialchars($estudiante['grado_nombre']) ?> - <?= htmlspecialchars($estudiante['seccion_nombre']) ?></small>
        </div>
        <nav class="nav flex-column p-2">
            <a class="nav-link active" href="<?= BASE_URL ?>/modules/estudiante/estudiante_dashboard.php"><i class="fas fa-home"></i> Dashboard</a>
            <a class="nav-link" href="<?= BASE_URL ?>/modules/estudiante/mis_clases.php"><i class="fas fa-book"></i> Mis Clases</a>
            <a class="nav-link" href="<?= BASE_URL ?>/modules/estudiante/actividades.php"><i class="fas fa-tasks"></i> Actividades</a>
            <a class="nav-link" href="<?= BASE_URL ?>/modules/estudiante/mis_notas.php"><i class="fas fa-star"></i> Calificaciones</a>
            <a class="nav-link" href="<?= BASE_URL ?>/modules/estudiante/mensajes.php">
                <i class="fas fa-envelope"></i> Mensajes
                <?php if ($totalNoLeidos > 0): ?><span class="badge bg-danger rounded-pill float-end"><?= $totalNoLeidos ?></span><?php endif; ?>
            </a>
            <a class="nav-link" href="<?= BASE_URL ?>/logout.php"><i class="fas fa-sign-out-alt"></i> Cerrar Sesión</a>
        </nav>
    </aside>

    <!-- Main Content -->
    <main class="main-content">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h2>👋 Hola, <?= htmlspecialchars(explode(' ', $estudiante['primer_nombre'])[0]) ?>!</h2>
            <button class="btn btn-outline-primary btn-sm" id="sidebarToggle"><i class="fas fa-bars"></i></button>
        </div>

        <!-- Stats -->
        <div class="row g-3 mb-4">
            <div class="col-md-3">
                <div class="stat-card text-center">
                    <h3 class="text-primary"><?= number_format($promedio_general, 2) ?></h3>
                    <small class="text-muted">Promedio General</small>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stat-card text-center">
                    <h3 class="text-success"><?= $stats_promedio['total_calificadas'] ?? 0 ?></h3>
                    <small class="text-muted">Actividades Calificadas</small>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stat-card text-center">
                    <h3 class="text-warning"><?= $tareas_pendientes ?></h3>
                    <small class="text-muted">Tareas Pendientes</small>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stat-card text-center">
                    <h3 class="text-danger"><?= $porcentaje_asistencia ?>%</h3>
                    <small class="text-muted">Asistencia</small>
                </div>
            </div>
        </div>

        <!-- Clases -->
        <div class="card-custom">
            <div class="card-header bg-white py-3"><h5 class="mb-0"><i class="fas fa-book-open"></i> Mis Clases</h5></div>
            <div class="card-body">
                <?php if (empty($clases)): ?>
                <p class="text-muted text-center">No tienes clases asignadas.</p>
                <?php else: ?>
                <div class="row g-3">
                    <?php foreach ($clases as $clase): ?>
                    <div class="col-md-6">
                        <div class="card border">
                            <div class="card-body">
                                <h6 class="card-title"><?= htmlspecialchars($clase['asignatura']) ?></h6>
                                <p class="card-text small text-muted">
                                    <i class="fas fa-chalkboard-teacher"></i> Prof. <?= htmlspecialchars($clase['profesor_apellido']) ?>
                                </p>
                                <div class="progress" style="height: 6px;">
                                    <div class="progress-bar" style="width: <?= min(($clase['actividades_completadas'] / max($clase['total_actividades'], 1)) * 100, 100) ?>%"></div>
                                </div>
                                <small class="text-muted"><?= $clase['actividades_completadas'] ?>/<?= $clase['total_actividades'] ?> actividades</small>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Pendientes -->
        <div class="card-custom">
            <div class="card-header bg-white py-3 d-flex justify-content-between">
                <h5 class="mb-0"><i class="fas fa-bell"></i> Pendientes</h5>
                <span class="badge bg-warning text-dark"><?= $tareas_pendientes ?></span>
            </div>
            <div class="card-body">
                <?php if (empty($actividades_pendientes)): ?>
                <p class="text-muted text-center">¡Felicidades! No tienes actividades pendientes.</p>
                <?php else: ?>
                <?php foreach ($actividades_pendientes as $act): 
                    $dias = ceil((strtotime($act['fecha_limite']) - time()) / 86400);
                ?>
                <div class="d-flex justify-content-between align-items-center py-2 border-bottom">
                    <div>
                        <strong><?= htmlspecialchars($act['titulo']) ?></strong><br>
                        <small class="text-muted"><?= htmlspecialchars($act['asignatura']) ?></small>
                    </div>
                    <div class="text-end">
                        <small class="text-<?= $dias <= 2 ? 'danger' : 'muted' ?>">
                            <?= $dias <= 2 ? '⚠️ ' : '' ?><?= date('d/m', strtotime($act['fecha_limite'])) ?>
                        </small>
                    </div>
                </div>
                <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>

        <!-- Próximos Exámenes -->
        <div class="card-custom">
            <div class="card-header bg-white py-3"><h5 class="mb-0"><i class="fas fa-file-alt"></i> Próximos Exámenes</h5></div>
            <div class="card-body">
                <?php if (empty($proximos_examenes)): ?>
                <p class="text-muted text-center">No hay exámenes programados próximamente.</p>
                <?php else: ?>
                <?php foreach ($proximos_examenes as $examen): ?>
                <div class="d-flex justify-content-between align-items-center py-2 border-bottom">
                    <div>
                        <strong><?= htmlspecialchars($examen['titulo']) ?></strong><br>
                        <small class="text-muted"><?= htmlspecialchars($examen['asignatura']) ?></small>
                    </div>
                    <div class="text-end">
                        <small class="text-<?= $examen['dias_restantes'] <= 2 ? 'danger' : 'muted' ?>">
                            <?= date('d/m', strtotime($examen['fecha_programada'])) ?>
                        </small>
                    </div>
                </div>
                <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </main>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script>
        document.getElementById('sidebarToggle')?.addEventListener('click', () => {
            document.getElementById('sidebar').classList.toggle('active');
        });
    </script>
</body>
</html>