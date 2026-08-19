<?php
session_start();
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/TenantGuard.php';
require_once __DIR__ . '/../../config/CuadroNotasHelper.php';

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
        
        // === CALIFICAR ENTREGA (una sola, desde el modal) =====
        // Se identifica por (id_actividad, id_matricula) — NO por id_entrega —
        // porque el cuadro de notas lista a TODOS los matriculados de la
        // sección, incluso a quienes todavía no tienen fila en
        // tbl_entrega_actividad (nunca "entregaron" nada, p.ej. una
        // actividad de participación que el profesor califica directo).
        // tbl_entrega_actividad.id_matricula es la columna real (no existe
        // id_estudiante); observacion_docente es la columna real de
        // comentarios (no existe "retroalimentacion" como columna).
        if ($accion == 'calificar_entrega') {
            $id_matricula = filter_input(INPUT_POST, 'id_matricula', FILTER_VALIDATE_INT);
            $id_actividad = filter_input(INPUT_POST, 'id_actividad', FILTER_VALIDATE_INT);
            $nota_obtenida = filter_input(INPUT_POST, 'nota_obtenida', FILTER_VALIDATE_FLOAT);
            $retroalimentacion = trim($_POST['retroalimentacion'] ?? '');
            $estado_entrega = $_POST['estado_entrega'] ?? 'calificado';

            // El estudiante (via su matrícula) debe estar en la MISMA sección/año
            // que la asignación de ESTA actividad, y la actividad debe ser de
            // este profesor. Ni tbl_entrega_actividad ni tbl_actividad tienen
            // columna id_institucion; basta con ad.id_profesor, ya tenant-verificado.
            $check = $db->prepare("
                SELECT a.id, a.nota_maxima
                FROM tbl_actividad a
                JOIN tbl_asignacion_docente ad ON a.id_asignacion_docente = ad.id
                JOIN tbl_matricula m ON m.id_seccion = ad.id_seccion AND m.anno = ad.anno
                WHERE a.id = :id_actividad AND ad.id_profesor = :id_profesor AND m.id = :id_matricula
            ");
            $check->execute([':id_actividad' => $id_actividad, ':id_profesor' => $id_profesor, ':id_matricula' => $id_matricula]);
            $actividad = $check->fetch(PDO::FETCH_ASSOC);

            if ($actividad) {
                $nota_maxima = $actividad['nota_maxima'] ?? 10;
                if ($nota_obtenida > $nota_maxima) {
                    $nota_obtenida = $nota_maxima;
                }

                $query = "INSERT INTO tbl_entrega_actividad
                              (id_actividad, id_matricula, nota_obtenida, observacion_docente, estado_entrega, fecha_calificacion)
                          VALUES (:id_actividad, :id_matricula, :nota, :retro, :estado, NOW())
                          ON DUPLICATE KEY UPDATE
                              nota_obtenida = VALUES(nota_obtenida),
                              observacion_docente = VALUES(observacion_docente),
                              estado_entrega = VALUES(estado_entrega),
                              fecha_calificacion = VALUES(fecha_calificacion)";
                $stmt = $db->prepare($query);
                $stmt->execute([
                    ':id_actividad' => $id_actividad,
                    ':id_matricula' => $id_matricula,
                    ':nota' => $nota_obtenida,
                    ':retro' => $retroalimentacion,
                    ':estado' => $estado_entrega,
                ]);

                // Si esta actividad está vinculada a una casilla del Cuadro
                // de Notas (ver gestionar_actividades.php), refleja la nota
                // ahí también -- convertida a escala 0-10 (nota_maxima puede
                // ser cualquier valor, no necesariamente 10).
                $valorSobreDiez = $nota_maxima > 0 ? ($nota_obtenida / $nota_maxima) * 10 : null;
                CuadroNotasHelper::sincronizar($db, $id_actividad, $id_matricula, $valorSobreDiez);

                $db->commit();
                $mensaje = 'Calificación guardada exitosamente';
                $tipo_mensaje = 'success';
            } else {
                throw new Exception("No tiene permiso para calificar a este estudiante en esta actividad");
            }

        } elseif ($accion == 'calificar_multiple') {
            // Calificación masiva — mismo upsert por (id_actividad, id_matricula).
            $calificaciones = $_POST['calificaciones'] ?? [];
            $id_actividad = filter_input(INPUT_POST, 'id_actividad', FILTER_VALIDATE_INT);

            // Verificar propiedad de la actividad. tbl_actividad no tiene
            // columna id_institucion; ad.id_profesor ya está tenant-verificado.
            $check = $db->prepare("
                SELECT a.id, a.nota_maxima, ad.id_seccion, ad.anno
                FROM tbl_actividad a
                JOIN tbl_asignacion_docente ad ON a.id_asignacion_docente = ad.id
                WHERE a.id = :id_actividad AND ad.id_profesor = :id_profesor
            ");
            $check->execute([':id_actividad' => $id_actividad, ':id_profesor' => $id_profesor]);

            if ($check->rowCount() > 0) {
                $actividad = $check->fetch(PDO::FETCH_ASSOC);
                $nota_maxima = $actividad['nota_maxima'] ?? 10;

                // Sólo se aceptan matrículas que de verdad pertenezcan a la
                // sección/año de esta actividad — evita que un id_matricula
                // manipulado en el POST califique a un estudiante ajeno.
                $checkMatricula = $db->prepare("
                    SELECT id FROM tbl_matricula WHERE id = :id_matricula AND id_seccion = :id_seccion AND anno = :anno
                ");

                $query = "INSERT INTO tbl_entrega_actividad
                              (id_actividad, id_matricula, nota_obtenida, observacion_docente, estado_entrega, fecha_calificacion)
                          VALUES (:id_actividad, :id_matricula, :nota, :retro, 'calificado', NOW())
                          ON DUPLICATE KEY UPDATE
                              nota_obtenida = VALUES(nota_obtenida),
                              observacion_docente = VALUES(observacion_docente),
                              estado_entrega = VALUES(estado_entrega),
                              fecha_calificacion = VALUES(fecha_calificacion)";
                $stmt = $db->prepare($query);

                $actualizadas = 0;
                foreach ($calificaciones as $id_matricula => $data) {
                    $id_matricula = (int) $id_matricula;
                    $nota = filter_var($data['nota'] ?? '', FILTER_VALIDATE_FLOAT);
                    if ($nota === false || $nota === null) {
                        continue; // celda vacía: no se toca esa entrega
                    }
                    $checkMatricula->execute([
                        ':id_matricula' => $id_matricula,
                        ':id_seccion' => $actividad['id_seccion'],
                        ':anno' => $actividad['anno'],
                    ]);
                    if (!$checkMatricula->fetch()) {
                        continue; // matrícula ajena a esta sección/año: se ignora
                    }

                    $nota = min($nota, $nota_maxima);
                    $stmt->execute([
                        ':id_actividad' => $id_actividad,
                        ':id_matricula' => $id_matricula,
                        ':nota' => $nota,
                        ':retro' => $data['retroalimentacion'] ?? '',
                    ]);

                    // Ver la misma sincronización en 'calificar_entrega' arriba.
                    $valorSobreDiez = $nota_maxima > 0 ? ($nota / $nota_maxima) * 10 : null;
                    CuadroNotasHelper::sincronizar($db, $id_actividad, $id_matricula, $valorSobreDiez);

                    $actualizadas++;
                }

                $db->commit();
                $mensaje = "$actualizadas calificaciones actualizadas";
                $tipo_mensaje = 'success';
            } else {
                throw new Exception("Actividad no válida");
            }
        }
        
    } catch (Exception $e) {
        $db->rollBack();
        error_log("Error en calificaciones.php: " . $e->getMessage());
        $mensaje = 'Error: ' . $e->getMessage();
        $tipo_mensaje = 'danger';
    }
}

// ===== OBTENER ASIGNACIONES DEL PROFESOR =====
// Se incluye nota_minima_aprobacion y nivel de tbl_grado (catálogo global,
// no filtrado por tenant — ver TenantGuard/gestionar_grados.php) para poder
// usar el umbral de aprobación REAL de cada grado en vez de un 60% fijo.
$query = "SELECT ad.id, ad.anno, asig.nombre as asignatura_nombre, asig.codigo as asignatura_codigo,
          g.nombre as grado_nombre, s.nombre as seccion_nombre,
          g.nivel as grado_nivel, g.nota_minima_aprobacion,
          COUNT(DISTINCT m.id) as total_estudiantes
          FROM tbl_asignacion_docente ad
          JOIN tbl_asignatura asig ON ad.id_asignatura = asig.id
          JOIN tbl_seccion s ON ad.id_seccion = s.id
          JOIN tbl_grado g ON s.id_grado = g.id
          LEFT JOIN tbl_matricula m ON s.id = m.id_seccion AND m.anno = ad.anno AND m.estado = 'activo'
          WHERE ad.id_profesor = :id_profesor AND asig.id_institucion = :tid
          GROUP BY ad.id
          ORDER BY g.nombre, s.nombre, asig.nombre";
$stmt = $db->prepare($query);
$stmt->bindValue(':id_profesor', $id_profesor, PDO::PARAM_INT);
$stmt->bindValue(':tid', $tid, PDO::PARAM_INT);
$stmt->execute();
$asignaciones = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ===== FILTROS =====
$id_asignacion_filtro = $_GET['asignacion'] ?? ($asignaciones[0]['id'] ?? 0);
$id_actividad_filtro = $_GET['actividad'] ?? 0;
$busqueda = $_GET['busqueda'] ?? '';
$estado_filtro = $_GET['estado_entrega'] ?? 'todos';

// Grado de la asignación seleccionada, para el umbral de aprobación real.
// nota_minima_aprobacion viene en la escala 0-10 del colegio; se expresa
// como fracción para poder aplicarla sobre el nota_maxima de CUALQUIER
// actividad (que puede no ser sobre 10). 6.0/10 = 60% es el valor por
// defecto de tbl_grado, así que en la práctica coincide con lo anterior
// para los grados que no lo hayan cambiado — pero ahora es el valor real.
$asignacion_actual = null;
foreach ($asignaciones as $asig) {
    if ($asig['id'] == $id_asignacion_filtro) { $asignacion_actual = $asig; break; }
}
$nota_minima_aprobacion = $asignacion_actual['nota_minima_aprobacion'] ?? 6.0;
$umbral_aprobacion_pct = $nota_minima_aprobacion / 10;
$niveles_label = ['basica' => 'Educación Básica', 'bachillerato' => 'Bachillerato'];
$nivel_actual_label = $niveles_label[$asignacion_actual['grado_nivel'] ?? ''] ?? '';

// ===== OBTENER ACTIVIDADES DE LA ASIGNACIÓN =====
// Las actividades tipo "examen" (con id_examen) se autocalifican en
// tbl_intento_examen, NUNCA en tbl_entrega_actividad -- por eso los
// conteos/promedio se calculan de una tabla u otra según el tipo, con
// subconsultas correlacionadas por fila (una lista de actividades es
// pequeña, no hace falta optimizar esto con un JOIN).
$actividades = [];
if ($id_asignacion_filtro) {
    $query_act = "SELECT a.id, a.titulo, a.tipo, a.id_examen, a.nota_maxima, a.fecha_limite, a.estado,
                 CASE WHEN a.tipo = 'examen' AND a.id_examen IS NOT NULL
                      THEN (SELECT COUNT(*) FROM tbl_intento_examen ie WHERE ie.id_examen = a.id_examen)
                      ELSE (SELECT COUNT(*) FROM tbl_entrega_actividad ea2 WHERE ea2.id_actividad = a.id)
                 END as total_entregas,
                 CASE WHEN a.tipo = 'examen' AND a.id_examen IS NOT NULL
                      THEN (SELECT COUNT(*) FROM tbl_intento_examen ie WHERE ie.id_examen = a.id_examen AND ie.estado = 'calificado')
                      ELSE (SELECT COUNT(*) FROM tbl_entrega_actividad ea2 WHERE ea2.id_actividad = a.id AND ea2.estado_entrega = 'calificado')
                 END as calificadas,
                 CASE WHEN a.tipo = 'examen' AND a.id_examen IS NOT NULL
                      THEN (SELECT AVG(porcentaje) FROM tbl_intento_examen ie WHERE ie.id_examen = a.id_examen)
                      ELSE (SELECT AVG(nota_obtenida) FROM tbl_entrega_actividad ea2 WHERE ea2.id_actividad = a.id)
                 END as promedio_notas
                 FROM tbl_actividad a
                 JOIN tbl_asignacion_docente ad ON a.id_asignacion_docente = ad.id
                 WHERE a.id_asignacion_docente = :id_asignacion
                 AND a.estado IN ('publicado', 'activo', 'cerrado')
                 AND ad.id_profesor = :id_profesor
                 ORDER BY a.fecha_programada DESC";

    $stmt_act = $db->prepare($query_act);
    $stmt_act->execute([':id_asignacion' => $id_asignacion_filtro, ':id_profesor' => $id_profesor]);
    $actividades = $stmt_act->fetchAll(PDO::FETCH_ASSOC);
}

// Actividad seleccionada (para saber si es examen autocalificado o tarea manual)
$actividad_seleccionada = null;
foreach ($actividades as $act) {
    if ($act['id'] == $id_actividad_filtro) { $actividad_seleccionada = $act; break; }
}
$es_examen_autocalificado = $actividad_seleccionada && $actividad_seleccionada['tipo'] === 'examen' && !empty($actividad_seleccionada['id_examen']);
$examen_tiene_ensayo = false;

// ===== OBTENER ENTREGAS PARA CALIFICAR =====
$entregas = [];
$estadisticas_actividad = null;

if ($id_actividad_filtro && $es_examen_autocalificado) {
    // ===== ACTIVIDAD TIPO EXAMEN: leer de tbl_intento_examen, solo lectura =====
    // El examen ya se autocalifica en modules/estudiante/api/entregar_examen.php
    // (tbl_intento_examen.puntaje_obtenido/porcentaje) — aquí NO se reescribe
    // esa nota, sólo se muestra. Igual que la vista de tareas, parte de
    // tbl_matricula para listar a todos los inscritos en la sección aunque
    // el estudiante no haya iniciado el examen todavía.
    try {
        $id_examen_actual = (int) $actividad_seleccionada['id_examen'];

        $query_intentos = "SELECT
                          m.id as id_matricula,
                          ie.id as id_intento,
                          ie.fecha_inicio, ie.fecha_fin, ie.tiempo_usado,
                          ie.puntaje_obtenido, ie.porcentaje,
                          COALESCE(ie.estado, 'sin_iniciar') as estado_entrega,
                          e.id as id_estudiante,
                          p.primer_nombre, p.primer_apellido, p.email, e.nie
                          FROM tbl_actividad a
                          JOIN tbl_asignacion_docente ad ON a.id_asignacion_docente = ad.id
                          JOIN tbl_matricula m ON m.id_seccion = ad.id_seccion AND m.anno = ad.anno AND m.estado = 'activo'
                          JOIN tbl_estudiante e ON m.id_estudiante = e.id
                          JOIN tbl_persona p ON e.id_persona = p.id
                          LEFT JOIN tbl_intento_examen ie ON ie.id_examen = :id_examen AND ie.id_matricula = m.id
                          WHERE a.id = :id_actividad AND ad.id_profesor = :id_profesor AND e.id_institucion = :tid";
        $params = [':id_examen' => $id_examen_actual, ':id_actividad' => $id_actividad_filtro, ':id_profesor' => $id_profesor, ':tid' => $tid];

        if (!empty($busqueda)) {
            $query_intentos .= " AND (p.primer_nombre LIKE :busqueda OR p.primer_apellido LIKE :busqueda OR e.nie LIKE :busqueda)";
            $params[':busqueda'] = "%$busqueda%";
        }

        $query_intentos .= " ORDER BY p.primer_apellido, p.primer_nombre";

        $stmt_int = $db->prepare($query_intentos);
        foreach ($params as $key => $value) {
            $stmt_int->bindValue($key, $value);
        }
        $stmt_int->execute();
        $entregas = $stmt_int->fetchAll(PDO::FETCH_ASSOC);

        if ($estado_filtro != 'todos') {
            $filtroEstadoExamen = $estado_filtro === 'pendiente' ? 'sin_iniciar' : $estado_filtro;
            $entregas = array_values(array_filter($entregas, fn($e) => $e['estado_entrega'] === $filtroEstadoExamen));
        }

        // ¿El examen tiene preguntas tipo ensayo (no se autocalifican)?
        $stmtEnsayo = $db->prepare("SELECT COUNT(*) FROM tbl_pregunta_examen WHERE id_examen = :id AND tipo = 'ensayo'");
        $stmtEnsayo->execute([':id' => $id_examen_actual]);
        $examen_tiene_ensayo = $stmtEnsayo->fetchColumn() > 0;

        if (!empty($entregas)) {
            $porcentajes = array_filter(array_column($entregas, 'porcentaje'), fn($n) => $n !== null);
            $estadisticas_actividad = [
                'total' => count($entregas),
                'calificadas' => count(array_filter($entregas, fn($e) => $e['estado_entrega'] == 'calificado')),
                'pendientes' => count(array_filter($entregas, fn($e) => $e['estado_entrega'] != 'calificado')),
                'promedio' => !empty($porcentajes) ? round(array_sum($porcentajes) / count($porcentajes), 2) : 0,
                'maxima' => !empty($porcentajes) ? max($porcentajes) : 0,
                'minima' => !empty($porcentajes) ? min($porcentajes) : 0,
            ];
        }
    } catch (PDOException $e) {
        error_log("Error al obtener intentos de examen: " . $e->getMessage());
    }
} elseif ($id_actividad_filtro) {
    try {
        $query_entregas = "SELECT
                          m.id as id_matricula,
                          ea.id as id_entrega,
                          COALESCE(ea.estado_entrega, 'pendiente') as estado_entrega,
                          ea.nota_obtenida,
                          ea.observacion_docente as retroalimentacion,
                          ea.fecha_entrega,
                          ea.fecha_calificacion,
                          e.id as id_estudiante,
                          p.primer_nombre,
                          p.primer_apellido,
                          p.email,
                          e.nie,
                          a.titulo as actividad_titulo,
                          a.nota_maxima,
                          a.tipo as actividad_tipo
                          FROM tbl_actividad a
                          JOIN tbl_asignacion_docente ad ON a.id_asignacion_docente = ad.id
                          JOIN tbl_matricula m ON m.id_seccion = ad.id_seccion AND m.anno = ad.anno AND m.estado = 'activo'
                          JOIN tbl_estudiante e ON m.id_estudiante = e.id
                          JOIN tbl_persona p ON e.id_persona = p.id
                          LEFT JOIN tbl_entrega_actividad ea ON ea.id_actividad = a.id AND ea.id_matricula = m.id
                          WHERE a.id = :id_actividad
                          AND ad.id_profesor = :id_profesor AND e.id_institucion = :tid";

        $params = [':id_actividad' => $id_actividad_filtro, ':id_profesor' => $id_profesor, ':tid' => $tid];

        // Filtro de búsqueda
        if (!empty($busqueda)) {
            $query_entregas .= " AND (p.primer_nombre LIKE :busqueda OR p.primer_apellido LIKE :busqueda OR e.nie LIKE :busqueda)";
            $params[':busqueda'] = "%$busqueda%";
        }

        // Filtro de estado (las entregas sin fila aún cuentan como 'pendiente')
        if ($estado_filtro != 'todos') {
            if ($estado_filtro === 'pendiente') {
                $query_entregas .= " AND (ea.estado_entrega = :estado OR ea.estado_entrega IS NULL)";
            } else {
                $query_entregas .= " AND ea.estado_entrega = :estado";
            }
            $params[':estado'] = $estado_filtro;
        }

        $query_entregas .= " ORDER BY p.primer_apellido, p.primer_nombre";

        $stmt_ent = $db->prepare($query_entregas);
        foreach ($params as $key => $value) {
            $stmt_ent->bindValue($key, $value);
        }
        $stmt_ent->execute();
        $entregas = $stmt_ent->fetchAll(PDO::FETCH_ASSOC);

        // Calcular estadísticas de la actividad
        if (!empty($entregas)) {
            $notas = array_filter(array_column($entregas, 'nota_obtenida'), fn($n) => $n !== null);
            $estadisticas_actividad = [
                'total' => count($entregas),
                'calificadas' => count(array_filter($entregas, fn($e) => $e['estado_entrega'] == 'calificado')),
                'pendientes' => count(array_filter($entregas, fn($e) => $e['estado_entrega'] != 'calificado')),
                'promedio' => !empty($notas) ? round(array_sum($notas) / count($notas), 2) : 0,
                'maxima' => !empty($notas) ? max($notas) : 0,
                'minima' => !empty($notas) ? min($notas) : 0
            ];
        }

    } catch (PDOException $e) {
        error_log("Error al obtener entregas: " . $e->getMessage());
    }
}

// Tipos de actividad para iconos
$tipos_actividad = [
    'tarea' => ['label' => 'Tarea', 'icon' => 'fa-clipboard-list', 'color' => 'warning'],
    'examen' => ['label' => 'Examen', 'icon' => 'fa-file-alt', 'color' => 'danger'],
    'video' => ['label' => 'Video', 'icon' => 'fa-video', 'color' => 'info'],
    'youtube' => ['label' => 'YouTube', 'icon' => 'fa-youtube', 'color' => 'danger'],
    'articulo' => ['label' => 'Artículo', 'icon' => 'fa-file-alt', 'color' => 'primary'],
    'referencia' => ['label' => 'Referencia', 'icon' => 'fa-book', 'color' => 'purple'],
    'podcast' => ['label' => 'Podcast', 'icon' => 'fa-podcast', 'color' => 'success'],
    'revista' => ['label' => 'Revista', 'icon' => 'fa-newspaper', 'color' => 'teal'],
    'enlace' => ['label' => 'Enlace', 'icon' => 'fa-link', 'color' => 'secondary']
];

// Estados de entrega (tareas)
$estados_entrega = [
    'pendiente' => ['label' => 'Pendiente', 'class' => 'bg-secondary'],
    'entregado' => ['label' => 'Entregado', 'class' => 'bg-info'],
    'revisado' => ['label' => 'Revisado', 'class' => 'bg-warning'],
    'calificado' => ['label' => 'Calificado', 'class' => 'bg-success']
];
// Estados de intento (exámenes) — distinto enum al de tbl_entrega_actividad
$estados_intento = [
    'sin_iniciar' => ['label' => 'Sin iniciar', 'class' => 'bg-secondary'],
    'en_progreso' => ['label' => 'En progreso', 'class' => 'bg-warning'],
    'entregado' => ['label' => 'Entregado', 'class' => 'bg-info'],
    'calificado' => ['label' => 'Autocalificado', 'class' => 'bg-success'],
];
?>
<?php
$activePage = 'calificaciones';
$pageTitle = 'Calificaciones - Educación Plus';
$mostrarAsignacionesSidebar = true;
$idAsignacionFiltro = $id_asignacion_filtro;
ob_start();
?>
<style>
    .card-custom { background: white; border-radius: 12px; box-shadow: 0 2px 12px rgba(0,0,0,0.08); border: none; margin-bottom: 20px; }
    .student-avatar { width: 40px; height: 40px; border-radius: 50%; background: linear-gradient(135deg, var(--secondary), var(--primary)); color: white; display: flex; align-items: center; justify-content: center; font-weight: 700; font-size: 0.9rem; }
    .stat-card { background: white; border-radius: 12px; padding: 20px; box-shadow: 0 2px 8px rgba(0,0,0,0.08); text-align: center; transition: transform 0.2s; }
    .stat-card:hover { transform: translateY(-3px); }
    .stat-card i { font-size: 2rem; margin-bottom: 8px; }
    .badge-estado { padding: 5px 12px; border-radius: 15px; font-size: 0.75rem; font-weight: 600; }
    .nota-input { width: 80px; text-align: center; font-weight: 600; }
    .nota-input.aprobado { color: var(--success); }
    .nota-input.reprobado { color: var(--danger); }
    .retro-textarea { min-height: 80px; resize: vertical; }
    .table-hover tbody tr:hover { background: #f8f9fa; }
    .progress-thin { height: 6px; }
</style>
<?php
$extraHead = ob_get_clean();
require __DIR__ . '/partials/header.php';
?>
        <!-- Header -->
        <div class="d-flex justify-content-between align-items-center mb-4">
            <div>
                <h2 class="mb-1"><i class="fas fa-star"></i> Calificaciones</h2>
                <nav aria-label="breadcrumb">
                    <ol class="breadcrumb mb-0">
                        <li class="breadcrumb-item"><a href="profesor_dashboard.php">Dashboard</a></li>
                        <li class="breadcrumb-item active">Calificaciones</li>
                    </ol>
                </nav>
            </div>
            <?php if ($asignacion_actual): ?>
            <div class="text-end">
                <?php if ($nivel_actual_label): ?>
                <span class="badge bg-light text-dark border me-1"><i class="fas fa-layer-group"></i> <?= htmlspecialchars($nivel_actual_label) ?></span>
                <?php endif; ?>
                <span class="badge bg-light text-dark border">
                    <i class="fas fa-check-circle text-success"></i> Aprueba con <?= number_format($nota_minima_aprobacion, 1) ?>/10
                </span>
            </div>
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

        <?php if (empty($asignaciones)): ?>
        <div class="card-custom p-5 text-center">
            <i class="fas fa-exclamation-triangle fa-3x text-warning mb-3"></i>
            <h4>No tienes asignaciones registradas</h4>
            <p class="text-muted">Contacta al administrador para que te asigne clases</p>
        </div>
        <?php else: ?>
        
        <!-- Selector de Actividad -->
        <div class="card-custom p-3 mb-4">
            <form method="GET" class="row g-3 align-items-end">
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
                
                <div class="col-md-4">
                    <label class="form-label small text-muted">Actividad a Calificar</label>
                    <select name="actividad" class="form-select" onchange="this.form.submit()">
                        <option value="">-- Seleccionar actividad --</option>
                        <?php foreach ($actividades as $act): 
                            $tipo = $tipos_actividad[$act['tipo']] ?? ['label' => $act['tipo'], 'icon' => 'fa-file'];
                        ?>
                        <option value="<?= $act['id'] ?>" <?= $id_actividad_filtro == $act['id'] ? 'selected' : '' ?>>
                            <i class="fas <?= $tipo['icon'] ?>"></i> <?= htmlspecialchars($act['titulo']) ?>
                            (<?= $act['calificadas'] ?>/<?= $act['total_entregas'] ?>)
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="col-md-2">
                    <label class="form-label small text-muted">Estado</label>
                    <select name="estado_entrega" class="form-select" onchange="this.form.submit()">
                        <option value="todos" <?= $estado_filtro == 'todos' ? 'selected' : '' ?>>Todos</option>
                        <option value="pendiente" <?= $estado_filtro == 'pendiente' ? 'selected' : '' ?>>Pendientes</option>
                        <option value="entregado" <?= $estado_filtro == 'entregado' ? 'selected' : '' ?>>Entregados</option>
                        <option value="calificado" <?= $estado_filtro == 'calificado' ? 'selected' : '' ?>>Calificados</option>
                    </select>
                </div>
                
                <div class="col-md-2">
                    <label class="form-label small text-muted">Buscar</label>
                    <div class="input-group">
                        <input type="text" name="busqueda" class="form-control" placeholder="Estudiante..." value="<?= htmlspecialchars($busqueda) ?>">
                        <button type="submit" class="btn btn-primary"><i class="fas fa-search"></i></button>
                    </div>
                </div>
            </form>
        </div>

        <?php if ($es_examen_autocalificado): ?>
        <div class="alert alert-info d-flex align-items-center gap-2">
            <i class="fas fa-robot fa-lg"></i>
            <div>
                Este examen se autocalifica al momento de entregarse — esta vista es de <strong>solo lectura</strong>.
                <?php if ($examen_tiene_ensayo): ?>
                <br><i class="fas fa-exclamation-triangle text-warning"></i> Incluye preguntas de <strong>ensayo</strong>, que todavía no se califican automáticamente (cuentan como 0 hasta que se agregue revisión manual).
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>

        <?php if ($id_actividad_filtro && $estadisticas_actividad): ?>
        <!-- Estadísticas de la Actividad -->
        <div class="row g-3 mb-4">
            <div class="col-md-3">
                <div class="stat-card">
                    <i class="fas fa-users text-primary"></i>
                    <h4><?= $estadisticas_actividad['total'] ?></h4>
                    <small class="text-muted">Total Entregas</small>
                    <div class="progress progress-thin mt-2">
                        <div class="progress-bar" style="width: 100%"></div>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stat-card">
                    <i class="fas fa-check-circle text-success"></i>
                    <h4><?= $estadisticas_actividad['calificadas'] ?></h4>
                    <small class="text-muted">Calificadas</small>
                    <div class="progress progress-thin mt-2">
                        <div class="progress-bar bg-success" style="width: <?= ($estadisticas_actividad['calificadas']/$estadisticas_actividad['total'])*100 ?>%"></div>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stat-card">
                    <i class="fas fa-clock text-warning"></i>
                    <h4><?= $estadisticas_actividad['pendientes'] ?></h4>
                    <small class="text-muted">Pendientes</small>
                    <div class="progress progress-thin mt-2">
                        <div class="progress-bar bg-warning" style="width: <?= ($estadisticas_actividad['pendientes']/$estadisticas_actividad['total'])*100 ?>%"></div>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stat-card">
                    <i class="fas fa-chart-line text-info"></i>
                    <h4><?= $estadisticas_actividad['promedio'] ?><?= $es_examen_autocalificado ? '%' : '' ?></h4>
                    <small class="text-muted">Promedio General</small>
                    <small class="d-block text-muted">
                        Max: <?= $estadisticas_actividad['maxima'] ?><?= $es_examen_autocalificado ? '%' : '' ?> | Min: <?= $estadisticas_actividad['minima'] ?><?= $es_examen_autocalificado ? '%' : '' ?>
                    </small>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Tabla de Calificaciones -->
        <?php if (!$id_actividad_filtro): ?>
        <div class="card-custom p-5 text-center">
            <i class="fas fa-tasks fa-4x text-muted mb-3"></i>
            <h5>Selecciona una actividad para comenzar a calificar</h5>
            <p class="text-muted">Elige una asignación y luego una actividad del menú superior</p>
        </div>
        
        <?php elseif (empty($entregas)): ?>
        <div class="card-custom p-5 text-center">
            <i class="fas fa-inbox fa-4x text-muted mb-3"></i>
            <h5>No hay entregas para mostrar</h5>
            <p class="text-muted">
                <?php if ($estado_filtro != 'todos' || !empty($busqueda)): ?>
                Intenta limpiar los filtros de búsqueda
                <a href="?asignacion=<?= $id_asignacion_filtro ?>&actividad=<?= $id_actividad_filtro ?>" class="btn btn-sm btn-outline-primary ms-2">Limpiar filtros</a>
                <?php else: ?>
                Los estudiantes aún no han entregado esta actividad
                <?php endif; ?>
            </p>
        </div>

        <?php elseif ($es_examen_autocalificado): ?>
        <!-- Vista de solo lectura: el examen ya se autocalificó -->
        <div class="card-custom">
            <div class="card-header bg-white py-3">
                <h5 class="mb-0">
                    <i class="fas fa-robot"></i> Resultados del Examen (autocalificado)
                    <span class="badge bg-primary ms-2"><?= count($entregas) ?></span>
                </h5>
            </div>
            <div class="table-responsive">
                <table class="table table-hover mb-0 align-middle">
                    <thead class="table-light">
                        <tr>
                            <th>Estudiante</th>
                            <th>Estado</th>
                            <th>Inicio</th>
                            <th>Entrega</th>
                            <th>Puntaje</th>
                            <th>Porcentaje</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($entregas as $entrega):
                            $iniciales = strtoupper(substr($entrega['primer_nombre'], 0, 1) . substr($entrega['primer_apellido'], 0, 1));
                            $nombre_completo = trim($entrega['primer_nombre'] . ' ' . $entrega['primer_apellido']);
                            $estado = $estados_intento[$entrega['estado_entrega']] ?? ['label' => $entrega['estado_entrega'], 'class' => 'bg-secondary'];
                            $porcentaje = $entrega['porcentaje'];
                            $umbral_pct_100 = $nota_minima_aprobacion * 10;
                            $clase_pct = $porcentaje === null ? 'text-muted' : ($porcentaje >= $umbral_pct_100 ? 'text-success' : 'text-danger');
                        ?>
                        <tr>
                            <td>
                                <div class="d-flex align-items-center gap-2">
                                    <div class="student-avatar"><?= $iniciales ?></div>
                                    <div>
                                        <strong><?= htmlspecialchars($nombre_completo) ?></strong>
                                        <br><small class="text-muted"><?= htmlspecialchars($entrega['nie']) ?></small>
                                    </div>
                                </div>
                            </td>
                            <td><span class="badge-estado <?= $estado['class'] ?>"><?= $estado['label'] ?></span></td>
                            <td><small><?= $entrega['fecha_inicio'] ? date('d/m/Y H:i', strtotime($entrega['fecha_inicio'])) : '—' ?></small></td>
                            <td><small><?= $entrega['fecha_fin'] ? date('d/m/Y H:i', strtotime($entrega['fecha_fin'])) : '—' ?></small></td>
                            <td><?= $entrega['puntaje_obtenido'] !== null ? number_format($entrega['puntaje_obtenido'], 2) : '—' ?></td>
                            <td>
                                <strong class="<?= $clase_pct ?>"><?= $porcentaje !== null ? number_format($porcentaje, 1) . '%' : '—' ?></strong>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <?php else: ?>
        <form method="POST" id="formCalificaciones">
            <input type="hidden" name="accion" value="calificar_multiple">
            <input type="hidden" name="id_actividad" value="<?= $id_actividad_filtro ?>">
            
            <div class="card-custom">
                <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
                    <h5 class="mb-0">
                        <i class="fas fa-list"></i> Entregas de Estudiantes
                        <span class="badge bg-primary ms-2"><?= count($entregas) ?></span>
                    </h5>
                    <div class="d-flex gap-2">
                        <button type="button" class="btn btn-outline-secondary btn-sm" onclick="llenarNotasAutomaticas()">
                            <i class="fas fa-magic"></i> Autollenar
                        </button>
                        <button type="submit" class="btn btn-primary btn-sm">
                            <i class="fas fa-save"></i> Guardar Todas
                        </button>
                    </div>
                </div>
                
                <div class="table-responsive">
                    <table class="table table-hover mb-0 align-middle">
                        <thead class="table-light">
                            <tr>
                                <th>Estudiante</th>
                                <th>Entrega</th>
                                <th>Estado</th>
                                <th>Nota (<?= $entregas[0]['nota_maxima'] ?>)</th>
                                <th>Retroalimentación</th>
                                <th>Acciones</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($entregas as $entrega): 
                                $iniciales = strtoupper(substr($entrega['primer_nombre'], 0, 1) . substr($entrega['primer_apellido'], 0, 1));
                                $nombre_completo = trim($entrega['primer_nombre'] . ' ' . $entrega['primer_apellido']);
                                $estado = $estados_entrega[$entrega['estado_entrega']] ?? ['label' => $entrega['estado_entrega'], 'class' => 'bg-secondary'];
                                $nota_clase = $entrega['nota_obtenida'] !== null ?
                                    ($entrega['nota_obtenida'] >= ($entrega['nota_maxima']*$umbral_aprobacion_pct) ? 'aprobado' : 'reprobado') : '';
                            ?>
                            <tr>
                                <td>
                                    <div class="d-flex align-items-center gap-2">
                                        <div class="student-avatar"><?= $iniciales ?></div>
                                        <div>
                                            <strong><?= htmlspecialchars($nombre_completo) ?></strong>
                                            <br><small class="text-muted"><?= htmlspecialchars($entrega['nie']) ?></small>
                                        </div>
                                    </div>
                                </td>
                                <td>
                                    <small>
                                        <?php if ($entrega['fecha_entrega']): ?>
                                        <div><i class="fas fa-calendar"></i> <?= date('d/m/Y', strtotime($entrega['fecha_entrega'])) ?></div>
                                        <?php else: ?>
                                        <div class="text-muted fst-italic">Sin entregar</div>
                                        <?php endif; ?>
                                        <?php if ($entrega['fecha_calificacion']): ?>
                                        <div class="text-success"><i class="fas fa-check"></i> <?= date('d/m', strtotime($entrega['fecha_calificacion'])) ?></div>
                                        <?php endif; ?>
                                    </small>
                                </td>
                                <td>
                                    <span class="badge-estado <?= $estado['class'] ?>">
                                        <?= $estado['label'] ?>
                                    </span>
                                </td>
                                <td>
                                    <div class="input-group input-group-sm">
                                        <input type="number"
                                               name="calificaciones[<?= $entrega['id_matricula'] ?>][nota]"
                                               class="form-control nota-input <?= $nota_clase ?>"
                                               value="<?= $entrega['nota_obtenida'] ?? '' ?>"
                                               min="0"
                                               max="<?= $entrega['nota_maxima'] ?>"
                                               step="0.1"
                                               placeholder="-"
                                               onchange="actualizarColorNota(this)">
                                        <span class="input-group-text">/<?= $entrega['nota_maxima'] ?></span>
                                    </div>
                                </td>
                                <td>
                                    <textarea name="calificaciones[<?= $entrega['id_matricula'] ?>][retroalimentacion]"
                                              class="form-control form-control-sm retro-textarea"
                                              placeholder="Comentarios..."><?= htmlspecialchars($entrega['retroalimentacion'] ?? '') ?></textarea>
                                </td>
                                <td>
                                    <div class="btn-group btn-group-sm">
                                        <button type="button" class="btn btn-outline-primary" onclick="calificarIndividual(<?= $entrega['id_matricula'] ?>)" title="Guardar solo esta">
                                            <i class="fas fa-save"></i>
                                        </button>
                                        <button type="button" class="btn btn-outline-info" title="Ver entrega completa">
                                            <i class="fas fa-eye"></i>
                                        </button>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                
                <div class="card-footer bg-white">
                    <div class="d-flex justify-content-end gap-2">
                        <button type="button" class="btn btn-outline-secondary" onclick="window.history.back()">
                            <i class="fas fa-arrow-left"></i> Volver
                        </button>
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-save"></i> Guardar Todas las Calificaciones
                        </button>
                    </div>
                </div>
            </div>
        </form>
        <?php endif; ?>
        <?php endif; ?>
    </div>

    <!-- Modal Calificación Individual -->
    <div class="modal fade" id="modalCalificar" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title"><i class="fas fa-star"></i> Calificar Entrega</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" id="formCalificarIndividual">
                    <div class="modal-body">
                        <input type="hidden" name="accion" value="calificar_entrega">
                        <input type="hidden" name="id_matricula" id="modal_id_matricula">
                        <input type="hidden" name="id_actividad" value="<?= (int) $id_actividad_filtro ?>">

                        <div class="mb-3">
                            <label class="form-label">Estudiante</label>
                            <input type="text" class="form-control" id="modal_estudiante" readonly>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Nota Máxima: <span id="modal_nota_maxima">10</span></label>
                            <div class="input-group">
                                <input type="number" 
                                       name="nota_obtenida" 
                                       id="modal_nota" 
                                       class="form-control form-control-lg text-center"
                                       min="0" 
                                       max="10" 
                                       step="0.1"
                                       required>
                                <span class="input-group-text">pts</span>
                            </div>
                            <div class="form-text">La nota no puede exceder el máximo permitido</div>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Estado de la Entrega</label>
                            <select name="estado_entrega" class="form-select">
                                <option value="calificado" selected>✓ Calificado</option>
                                <option value="revisado">📝 Revisado (pendiente nota)</option>
                                <option value="entregado">📤 Entregado (sin revisar)</option>
                            </select>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Retroalimentación</label>
                            <textarea name="retroalimentacion" class="form-control" rows="4" placeholder="Comentarios para el estudiante..."></textarea>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                        <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Guardar Calificación</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Scripts -->
    <?php require __DIR__ . '/partials/scripts.php'; ?>

    <script>
    // Umbral real de aprobación del grado de la asignación seleccionada
    // (nota_minima_aprobacion/10). Ver PHP arriba: reemplaza el 60% fijo
    // que se usaba antes sin importar el grado.
    const UMBRAL_APROBACION_PCT = <?= json_encode($umbral_aprobacion_pct) ?>;

    $(document).ready(function() {
        // Sidebar responsive
        if (window.innerWidth < 992) {
            $('#sidebar').addClass('active');
        }
        
        // Select2 para filtros
        $('select.form-select').select2({
            theme: 'bootstrap-5',
            width: '100%'
        });
        
        // Validación de notas en tiempo real
        $('input[name*="[nota]"]').on('input', function() {
            const max = $(this).attr('max');
            let val = parseFloat($(this).val());
            
            if (val > max) {
                $(this).val(max);
            }
            if (val < 0) {
                $(this).val(0);
            }
            actualizarColorNota(this);
        });
    });
    
    // Actualizar color de la nota según valor
    function actualizarColorNota(input) {
        const max = parseFloat($(input).attr('max')) || 10;
        const val = parseFloat($(input).val()) || 0;
        const threshold = max * UMBRAL_APROBACION_PCT;
        
        $(input).removeClass('aprobado reprobado');
        if (val >= threshold) {
            $(input).addClass('aprobado');
        } else if (val > 0) {
            $(input).addClass('reprobado');
        }
    }
    
    // Calificar individualmente (abre modal)
    function calificarIndividual(idMatricula) {
        // Antes buscaba la PRIMERA fila con un input[value] en toda la tabla
        // (sin importar qué botón se hubiera presionado); ahora se ubica la
        // fila exacta a partir del name="calificaciones[idMatricula][...]".
        const notaInput = $(`input[name="calificaciones[${idMatricula}][nota]"]`);
        const row = notaInput.closest('tr');
        const estudiante = row.find('strong').text();
        const notaActual = notaInput.val();
        const retroActual = row.find(`textarea[name="calificaciones[${idMatricula}][retroalimentacion]"]`).val();
        const notaMax = notaInput.attr('max');

        $('#modal_id_matricula').val(idMatricula);
        $('#modal_estudiante').val(estudiante);
        $('#modal_nota_maxima').text(notaMax);
        $('#modal_nota').val(notaActual).attr('max', notaMax);
        $('#modalCalificar textarea[name="retroalimentacion"]').val(retroActual);
        
        $('#modalCalificar').modal('show');
    }
    
    // Autollenar notas (para pruebas/demo)
    function llenarNotasAutomaticas() {
        if (!confirm('¿Generar notas aleatorias para todas las entregas?\n\n⚠️ Esto es solo para demostración. En producción, califica manualmente.')) {
            return;
        }
        
        $('input[name*="[nota]"]').each(function() {
            const max = parseFloat($(this).attr('max')) || 10;
            // Generar nota entre 50% y 100% del máximo
            const nota = (Math.random() * 0.5 + 0.5) * max;
            $(this).val(nota.toFixed(1));
            actualizarColorNota(this);
        });
        
        // Mensaje temporal
        const originalText = $('.btn-primary').html();
        $('.btn-primary').html('<i class="fas fa-check"></i> ¡Notas generadas!');
        setTimeout(() => $('.btn-primary').html(originalText), 2000);
    }
    
    // Exportar calificaciones a CSV
    function exportarCalificaciones() {
        let csv = 'Estudiante,NIE,Nota,Estado,Retroalimentación\n';
        
        $('tbody tr').each(function() {
            const nombre = $(this).find('strong').text();
            const nie = $(this).find('.text-muted').first().text();
            const nota = $(this).find('input[name*="[nota]"]').val() || '-';
            const estado = $(this).find('.badge-estado').text();
            const retro = $(this).find('textarea').val().replace(/\n/g, ' ');
            
            csv += `"${nombre}","${nie}","${nota}","${estado}","${retro}"\n`;
        });
        
        const blob = new Blob([csv], {type: 'text/csv;charset=utf-8;'});
        const link = document.createElement('a');
        link.href = URL.createObjectURL(blob);
        link.download = `calificaciones_${new Date().toISOString().slice(0,10)}.csv`;
        link.click();
    }
    
    // Confirmar antes de enviar formulario masivo
    $('#formCalificaciones').on('submit', function(e) {
        const notasLlenas = $('input[name*="[nota]"]').filter(function() {
            return $(this).val() !== '';
        }).length;
        
        if (notasLlenas === 0) {
            e.preventDefault();
            alert('⚠️ No has ingresado ninguna nota. Por favor califica al menos un estudiante.');
            return false;
        }
        
        if (!confirm(`¿Estás seguro de guardar ${notasLlenas} calificación(es)?\n\nEsta acción no se puede deshacer.`)) {
            e.preventDefault();
            return false;
        }
    });
    </script>
</body>
</html>