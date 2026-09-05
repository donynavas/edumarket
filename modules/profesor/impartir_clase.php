<?php
/**
 * "Impartir Clase" -- planeación de clase basada en el layout de
 * EstructuraClase.xlsx (Docente/Grado/Sección/Fecha/Asignatura/No. Clase,
 * Objetivo, Desarrollo, Recursos, Cierre, y 3 botones de cierre: Asignar
 * tarea/examen/Actividad). Los 3 botones de cierre son "vinculantes" de
 * verdad: llaman a ActividadHelper::crearActividadEnAsignacion() -- el
 * mismo método que usa gestionar_actividades.php -- así que si el
 * profesor elige Período+Casilla ahí, la actividad resultante queda
 * enlazada al Cuadro de Notas exactamente igual que si se hubiera creado
 * desde Actividades.
 *
 * Flujo: primero se guarda la clase (Objetivo/Desarrollo/Cierre + datos
 * de encabezado) para obtener un id_clase real; Recursos y los 3 botones
 * de Cierre solo se habilitan una vez que existe ese id (cuelgan de él
 * por FK). Ver migrations/2026_08_26c_impartir_clase.sql.
 */
session_start();
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/TenantGuard.php';
require_once __DIR__ . '/../../config/PeriodoHelper.php';
require_once __DIR__ . '/../../config/CuadroNotasHelper.php';
require_once __DIR__ . '/../../config/ActividadHelper.php';
require_once __DIR__ . '/../../config/HtmlSanitizer.php';
require_once __DIR__ . '/../../config/HorarioHelper.php';
require_once __DIR__ . '/../../config/ForoHelper.php';

if (!isset($_SESSION['user_id']) || $_SESSION['rol'] != 'profesor') {
    header("Location: " . BASE_URL . "/login.php");
    exit;
}

$database = new Database();
$db = $database->getConnection();
$user_id = $_SESSION['user_id'];
$tid = TenantGuard::id();

$query = "SELECT p.id as id_profesor, per.primer_nombre, per.primer_apellido
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

// ===== ASIGNACIONES DEL PROFESOR (mismo query que gestionar_actividades.php) =====
$query = "SELECT ad.id, ad.anno, asig.nombre as asignatura_nombre,
          g.nombre as grado_nombre, s.nombre as seccion_nombre, g.nivel as nivel_grado
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

$id_asignacion_filtro = (int) ($_GET['asignacion'] ?? ($asignaciones[0]['id'] ?? 0));
$id_clase = (int) ($_GET['clase'] ?? 0);

$asignacion_filtro_info = null;
foreach ($asignaciones as $a) {
    if ((int) $a['id'] === $id_asignacion_filtro) { $asignacion_filtro_info = $a; break; }
}

/** Verifica que $idAsignacion pertenezca a este profesor y devuelve su fila (con anno/nivel/id_grado/id_asignatura), o null. */
function resolverAsignacionPropia(PDO $db, int $idAsignacion, int $idProfesor): ?array
{
    $check = $db->prepare("SELECT ad.id, ad.anno, g.nivel, g.id AS id_grado, asig.id AS id_asignatura,
                                   asig.nombre AS asignatura_nombre, g.nombre AS grado_nombre, s.nombre AS seccion_nombre
                           FROM tbl_asignacion_docente ad
                           JOIN tbl_asignatura asig ON ad.id_asignatura = asig.id
                           JOIN tbl_seccion s ON ad.id_seccion = s.id
                           JOIN tbl_grado g ON s.id_grado = g.id
                           WHERE ad.id = :id AND ad.id_profesor = :prof");
    $check->execute([':id' => $idAsignacion, ':prof' => $idProfesor]);
    $row = $check->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

/** Verifica que $idClase pertenezca a esta institución Y a una asignación de este profesor. Devuelve la fila o null. */
function resolverClasePropia(PDO $db, int $idClase, int $tid, int $idProfesor): ?array
{
    $stmt = $db->prepare("SELECT c.* FROM tbl_clase_impartida c
                          JOIN tbl_asignacion_docente ad ON c.id_asignacion_docente = ad.id
                          WHERE c.id = :id AND c.id_institucion = :tid AND ad.id_profesor = :prof");
    $stmt->execute([':id' => $idClase, ':tid' => $tid, ':prof' => $idProfesor]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

// ===== PROCESAR ACCIONES POST =====
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $accion = $_POST['accion'] ?? '';
    try {
        if ($accion === 'guardar_clase') {
            $idAsignacionPost = (int) ($_POST['id_asignacion'] ?? 0);
            $asigInfo = resolverAsignacionPropia($db, $idAsignacionPost, $id_profesor);
            if (!$asigInfo) {
                throw new Exception('No tiene permiso para esta asignación.');
            }

            $numero_clase = trim($_POST['numero_clase'] ?? '') ?: null;
            $fecha_clase = $_POST['fecha_clase'] ?: date('Y-m-d');
            // Objetivo/Desarrollo/Cierre vienen del editor de texto enriquecido
            // (TinyMCE, ver el <script> más abajo) -- el HTML que llega por POST
            // nunca es de confianza, se limpia con la misma lista blanca que ya
            // usa el Manual de Convivencia antes de guardarlo.
            $objetivo = HtmlSanitizer::limpiar(trim($_POST['objetivo'] ?? ''));
            $desarrollo = HtmlSanitizer::limpiar($_POST['desarrollo'] ?? '');
            $cierre = HtmlSanitizer::limpiar(trim($_POST['cierre'] ?? ''));

            $idClasePost = (int) ($_POST['id_clase'] ?? 0);
            if ($idClasePost > 0) {
                $claseActual = resolverClasePropia($db, $idClasePost, $tid, $id_profesor);
                if (!$claseActual) {
                    throw new Exception('No tiene permiso para editar esta clase.');
                }
                $stmt = $db->prepare("UPDATE tbl_clase_impartida SET
                        id_asignacion_docente = :asig, numero_clase = :num, fecha_clase = :fecha,
                        objetivo = :obj, desarrollo = :des, cierre = :cie
                    WHERE id = :id");
                $stmt->execute([
                    ':asig' => $idAsignacionPost, ':num' => $numero_clase, ':fecha' => $fecha_clase,
                    ':obj' => $objetivo, ':des' => $desarrollo, ':cie' => $cierre, ':id' => $idClasePost,
                ]);
                $id_clase = $idClasePost;
            } else {
                $stmt = $db->prepare("INSERT INTO tbl_clase_impartida
                        (id_institucion, id_asignacion_docente, numero_clase, fecha_clase, objetivo, desarrollo, cierre, created_by)
                    VALUES (:tid, :asig, :num, :fecha, :obj, :des, :cie, :creator)");
                $stmt->execute([
                    ':tid' => $tid, ':asig' => $idAsignacionPost, ':num' => $numero_clase, ':fecha' => $fecha_clase,
                    ':obj' => $objetivo, ':des' => $desarrollo, ':cie' => $cierre, ':creator' => $user_id,
                ]);
                $id_clase = (int) $db->lastInsertId();
            }
            $id_asignacion_filtro = $idAsignacionPost;
            $mensaje = 'Clase guardada. Ya puedes agregar recursos y usar los botones de cierre.';
            $tipo_mensaje = 'success';

        } elseif (in_array($accion, ['asignar_tarea', 'asignar_examen', 'asignar_actividad'], true)) {
            $idClasePost = (int) ($_POST['id_clase'] ?? 0);
            $claseActual = resolverClasePropia($db, $idClasePost, $tid, $id_profesor);
            if (!$claseActual) {
                throw new Exception('No tiene permiso para modificar esta clase.');
            }
            $asigInfo = resolverAsignacionPropia($db, (int) $claseActual['id_asignacion_docente'], $id_profesor);
            if (!$asigInfo) {
                throw new Exception('La asignación de esta clase ya no es válida.');
            }

            $titulo = trim($_POST['titulo'] ?? '');
            if ($titulo === '') {
                throw new Exception('El título es obligatorio.');
            }
            $descripcion = $_POST['descripcion'] ?? '';
            $fecha_programada = $_POST['fecha_programada'] ?? date('Y-m-d H:i:s');
            $fecha_limite = $_POST['fecha_limite'] ?: null;
            $nota_maxima = filter_input(INPUT_POST, 'nota_maxima', FILTER_VALIDATE_FLOAT) ?: 10;
            $id_periodo_post = filter_input(INPUT_POST, 'id_periodo', FILTER_VALIDATE_INT) ?: null;
            $casilla_post = trim($_POST['casilla'] ?? '') ?: null;

            if ($accion === 'asignar_tarea') {
                $tipo = 'tarea';
                $duracion_minutos = null;
                $contenido = '';
                $url_recurso = null;
                $recursos_url = '';
                $estado = 'publicado';
                $preguntas = null;
                $id_rubrica_plantilla = filter_input(INPUT_POST, 'id_rubrica_plantilla', FILTER_VALIDATE_INT) ?: null;
                $columnaClase = 'id_actividad_tarea';
            } elseif ($accion === 'asignar_examen') {
                $tipo = 'examen';
                $duracion_minutos = filter_input(INPUT_POST, 'duracion_minutos', FILTER_VALIDATE_INT) ?: null;
                $contenido = '';
                $url_recurso = null;
                $recursos_url = '';
                $estado = 'programado';
                // Examen "cascarón": se crea sin preguntas -- el profesor las
                // agrega después desde Gestionar Actividades, que ya tiene el
                // generador completo. Evita duplicar ese generador aquí.
                $preguntas = [];
                $id_rubrica_plantilla = null;
                $columnaClase = 'id_actividad_examen';
            } else { // asignar_actividad
                $tipo = $_POST['tipo'] ?? 'enlace';
                if (!array_key_exists($tipo, ActividadHelper::tiposReferencia())) {
                    throw new Exception('Tipo de actividad no válido.');
                }
                $duracion_minutos = null;
                $contenido = $_POST['contenido'] ?? '';
                $url_recurso = filter_var($_POST['url_recurso'] ?? '', FILTER_VALIDATE_URL) ?: null;
                $recursos_url = '';
                $estado = 'publicado';
                $preguntas = null;
                $id_rubrica_plantilla = null;
                $columnaClase = 'id_actividad_extra';
            }

            $idActividadNueva = ActividadHelper::crearActividadEnAsignacion(
                $db, $tid, (int) $asigInfo['id'], (int) $asigInfo['anno'], $asigInfo['nivel'],
                $titulo, $descripcion, $tipo, $fecha_programada, $fecha_limite, $duracion_minutos,
                $nota_maxima, $contenido, $url_recurso, $recursos_url, $estado,
                $id_periodo_post, $casilla_post, $preguntas,
                $id_profesor, $id_rubrica_plantilla
            );

            $db->prepare("UPDATE tbl_clase_impartida SET `$columnaClase` = :act WHERE id = :id")
               ->execute([':act' => $idActividadNueva, ':id' => $idClasePost]);

            $id_clase = $idClasePost;
            $id_asignacion_filtro = (int) $asigInfo['id'];
            $etiquetas = ['asignar_tarea' => 'Tarea', 'asignar_examen' => 'Examen', 'asignar_actividad' => 'Actividad'];
            $mensaje = $etiquetas[$accion] . ' creada y vinculada a esta clase.';
            $tipo_mensaje = 'success';

        } elseif ($accion === 'generar_sesiones') {
            $idAsignacionPost = (int) ($_POST['id_asignacion'] ?? 0);
            $asigInfo = resolverAsignacionPropia($db, $idAsignacionPost, $id_profesor);
            if (!$asigInfo) {
                throw new Exception('No tiene permiso para esta asignación.');
            }
            $fechaInicioPost = trim($_POST['fecha_inicio'] ?? '');
            $fechaFinPost = trim($_POST['fecha_fin'] ?? '');
            $resultado = HorarioHelper::generarSesiones($db, $tid, $idAsignacionPost, $user_id, $fechaInicioPost, $fechaFinPost);

            $id_asignacion_filtro = $idAsignacionPost;
            $id_clase = 0;
            if ($resultado['creadas'] > 0) {
                $mensaje = 'Se generaron ' . $resultado['creadas'] . ' sesión(es) según el horario de esta asignación.'
                    . ($resultado['omitidas'] > 0 ? ' Se omitieron ' . $resultado['omitidas'] . ' fecha(s) que ya tenían una clase registrada.' : '');
                $tipo_mensaje = 'success';
            } else {
                $mensaje = 'No se generó ninguna sesión nueva'
                    . ($resultado['omitidas'] > 0 ? ' -- las ' . $resultado['omitidas'] . ' fecha(s) del rango ya tenían una clase registrada.' : ' en ese rango de fechas (revisa que coincida con los días del horario).');
                $tipo_mensaje = 'warning';
            }

        } elseif ($accion === 'publicar_foro_mensaje') {
            $idClasePost = (int) ($_POST['id_clase'] ?? 0);
            $claseActual = resolverClasePropia($db, $idClasePost, $tid, $id_profesor);
            if (!$claseActual) {
                throw new Exception('No tiene permiso para publicar en esta clase.');
            }
            ForoHelper::publicar($db, $tid, $idClasePost, $user_id, 'profesor', $_POST['mensaje'] ?? '');
            $id_clase = $idClasePost;
            $id_asignacion_filtro = (int) $claseActual['id_asignacion_docente'];
            $mensaje = 'Mensaje publicado en el foro de la clase.';
            $tipo_mensaje = 'success';

        } elseif ($accion === 'eliminar_foro_mensaje') {
            $idClasePost = (int) ($_POST['id_clase'] ?? 0);
            $claseActual = resolverClasePropia($db, $idClasePost, $tid, $id_profesor);
            if (!$claseActual) {
                throw new Exception('No tiene permiso para moderar esta clase.');
            }
            $idMensajePost = (int) ($_POST['id_mensaje'] ?? 0);
            // El mensaje debe pertenecer a ESTA clase -- evita que, con un id
            // adivinado, se borre un mensaje de otra clase (propia o ajena).
            $db->prepare("DELETE FROM tbl_foro_mensaje WHERE id = :id AND id_clase = :clase")
               ->execute([':id' => $idMensajePost, ':clase' => $idClasePost]);
            $id_clase = $idClasePost;
            $id_asignacion_filtro = (int) $claseActual['id_asignacion_docente'];
            $mensaje = 'Mensaje del foro eliminado.';
            $tipo_mensaje = 'warning';

        } elseif ($accion === 'eliminar_clase') {
            $idClasePost = (int) ($_POST['id_clase'] ?? 0);
            $claseActual = resolverClasePropia($db, $idClasePost, $tid, $id_profesor);
            if (!$claseActual) {
                throw new Exception('No tiene permiso para eliminar esta clase.');
            }
            $db->prepare("DELETE FROM tbl_clase_impartida WHERE id = :id")->execute([':id' => $idClasePost]);
            $id_asignacion_filtro = (int) $claseActual['id_asignacion_docente'];
            $id_clase = 0;
            $mensaje = 'Clase eliminada.';
            $tipo_mensaje = 'warning';
        }
    } catch (Exception $e) {
        error_log("Error en impartir_clase.php: " . $e->getMessage());
        $mensaje = 'Error: ' . $e->getMessage();
        $tipo_mensaje = 'danger';
    }
    // Recalcular la info de la asignación filtrada tras un POST (puede haber cambiado).
    $asignacion_filtro_info = null;
    foreach ($asignaciones as $a) {
        if ((int) $a['id'] === $id_asignacion_filtro) { $asignacion_filtro_info = $a; break; }
    }
}

// ===== VINCULACIÓN AL CUADRO DE NOTAS (para los 3 modales de Cierre) =====
$periodos_cuadro = [];
$casillas_cuadro = [];
if ($asignacion_filtro_info) {
    PeriodoHelper::asegurar($db, $tid, (int) $asignacion_filtro_info['anno']);
    $stmtPerCN = $db->prepare("SELECT id, numero, nombre, fecha_inicio, fecha_fin FROM tbl_periodo WHERE id_institucion = :tid AND anno = :anno AND nivel = :nivel ORDER BY numero");
    $stmtPerCN->execute([':tid' => $tid, ':anno' => $asignacion_filtro_info['anno'], ':nivel' => $asignacion_filtro_info['nivel_grado']]);
    $periodos_cuadro = $stmtPerCN->fetchAll(PDO::FETCH_ASSOC);
    $casillas_cuadro = CuadroNotasHelper::casillasDisponibles($asignacion_filtro_info['nivel_grado']);
}

// ===== DÍAS CON HORARIO (para "Generar sesiones del período") =====
$dias_horario_asignacion = $id_asignacion_filtro ? HorarioHelper::diasConHorario($db, $id_asignacion_filtro) : [];
$dias_horario_nombres = array_map(fn($d) => CatalogoHorario::DIAS_SEMANA[$d] ?? $d, $dias_horario_asignacion);

// ===== CLASE ACTUAL (si hay una seleccionada/recién guardada) =====
$clase = $id_clase > 0 ? resolverClasePropia($db, $id_clase, $tid, $id_profesor) : null;
if (!$clase) {
    $id_clase = 0;
}

$recursos = [];
$mensajesForo = [];
if ($clase) {
    $stmtRec = $db->prepare("SELECT * FROM tbl_clase_recurso WHERE id_clase = :id ORDER BY orden, id");
    $stmtRec->execute([':id' => $clase['id']]);
    $recursos = $stmtRec->fetchAll(PDO::FETCH_ASSOC);

    $mensajesForo = ForoHelper::mensajesDeClase($db, $clase['id']);
}

// Actividades ya vinculadas (para mostrar título/estado junto a cada botón de Cierre).
$actividadesVinculadas = ['tarea' => null, 'examen' => null, 'extra' => null];
if ($clase) {
    $idsAct = array_filter([
        'tarea' => $clase['id_actividad_tarea'],
        'examen' => $clase['id_actividad_examen'],
        'extra' => $clase['id_actividad_extra'],
    ]);
    if ($idsAct) {
        $in = implode(',', array_fill(0, count($idsAct), '?'));
        $stmtAct = $db->prepare("SELECT id, titulo, tipo FROM tbl_actividad WHERE id IN ($in)");
        $stmtAct->execute(array_values($idsAct));
        $porId = [];
        foreach ($stmtAct->fetchAll(PDO::FETCH_ASSOC) as $a) { $porId[$a['id']] = $a; }
        foreach ($idsAct as $clave => $idAct) {
            $actividadesVinculadas[$clave] = $porId[$idAct] ?? null;
        }
    }
}

// ===== BITÁCORA: otras clases de esta asignación =====
$bitacora = [];
if ($id_asignacion_filtro) {
    $stmtBit = $db->prepare("SELECT id, numero_clase, fecha_clase, objetivo, estado,
                                     id_actividad_tarea, id_actividad_examen, id_actividad_extra
                              FROM tbl_clase_impartida
                              WHERE id_asignacion_docente = :asig AND id_institucion = :tid AND id != :actual
                              ORDER BY fecha_clase DESC, id DESC LIMIT 30");
    $stmtBit->execute([':asig' => $id_asignacion_filtro, ':tid' => $tid, ':actual' => $id_clase]);
    $bitacora = $stmtBit->fetchAll(PDO::FETCH_ASSOC);
}

// ===== RÚBRICAS (para el select de "Asignar tarea") =====
$stmtRub = $db->prepare("SELECT id, nombre FROM tbl_rubrica WHERE id_profesor = :prof AND id_institucion = :tid AND id_actividad IS NULL AND estado = 'activo' ORDER BY nombre");
$stmtRub->execute([':prof' => $id_profesor, ':tid' => $tid]);
$rubricas_plantilla = $stmtRub->fetchAll(PDO::FETCH_ASSOC);

$tipos_referencia = ActividadHelper::tiposReferencia();

$activePage = 'impartir_clase';
$pageTitle = 'Impartir Clase - Educación Plus';
ob_start();
?>
<style>
    .card-custom { background: white; border-radius: 12px; box-shadow: 0 2px 12px rgba(0,0,0,0.08); border: none; margin-bottom: 20px; }
    .campo-solo-lectura { background: #f5f7fa; border-radius: 8px; padding: 10px 14px; }
    .recurso-chip { display: inline-flex; align-items: center; gap: 6px; background: #eef3fb; border-radius: 20px; padding: 6px 14px; margin: 3px; font-size: 0.85rem; }
    .btn-cierre { min-height: 90px; }
    .bitacora-item { border-left: 3px solid var(--secondary); padding: 8px 12px; }
    .bitacora-item.tiene-vinculo { border-left-color: var(--success); }
    /* Los 3 modales de Cierre reutilizan partials/bloque_cuadro_notas.php,
       que por defecto viene oculto (class="d-none") porque en el modal de
       gestionar_actividades.php un JS lo muestra/oculta según el tipo de
       actividad elegido. Aquí cada modal ya es de un solo tipo fijo
       (tarea/examen/actividad), así que el bloque debe verse siempre. */
    #modalTarea #tarea_bloque_cuadro_notas,
    #modalExamen #examen_bloque_cuadro_notas,
    #modalActividadExtra #act_bloque_cuadro_notas { display: block !important; }
    @media print {
        .sidebar, .no-print, .btn { display: none !important; }
        .main-content { margin-left: 0; }
        .hoja { box-shadow: none; border-radius: 0; max-width: 100%; padding: 10px; }
    }
</style>
<?php
$extraHead = ob_get_clean();
require __DIR__ . '/partials/header.php';
?>
        <div class="d-flex justify-content-between align-items-center mb-4 no-print">
            <div>
                <h2><i class="fas fa-chalkboard-teacher"></i> Impartir Clase</h2>
                <p class="text-muted mb-0">Planea, vincula al Cuadro de Notas, e imparte tu clase</p>
            </div>
            <?php if ($clase): ?>
            <div>
                <a class="btn btn-outline-primary" href="impartir_clase_vivo.php?clase=<?= $clase['id'] ?>" target="_blank">
                    <i class="fas fa-play"></i> Modo Vista en Vivo
                </a>
                <button class="btn btn-outline-secondary" onclick="window.print()"><i class="fas fa-print"></i> Imprimir / PDF</button>
            </div>
            <?php endif; ?>
        </div>

        <?php if ($mensaje): ?>
        <div class="alert alert-<?= $tipo_mensaje ?> alert-dismissible fade show no-print">
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

        <!-- Selector de asignación -->
        <div class="card-custom p-3 mb-4 no-print">
            <form method="GET" class="row g-3 align-items-end">
                <div class="col-md-8">
                    <label class="form-label small text-muted">Asignación</label>
                    <select name="asignacion" class="form-select" onchange="this.form.submit()">
                        <?php foreach ($asignaciones as $asig): ?>
                        <option value="<?= $asig['id'] ?>" <?= $id_asignacion_filtro == $asig['id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($asig['asignatura_nombre']) ?> - <?= htmlspecialchars($asig['grado_nombre']) ?> <?= htmlspecialchars($asig['seccion_nombre']) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <a href="impartir_clase.php?asignacion=<?= $id_asignacion_filtro ?>" class="btn btn-outline-primary w-100">
                        <i class="fas fa-plus"></i> Nueva Clase
                    </a>
                </div>
                <div class="col-md-2">
                    <button type="button" class="btn btn-outline-success w-100" data-bs-toggle="modal" data-bs-target="#modalGenerarSesiones">
                        <i class="fas fa-calendar-plus"></i> Generar sesiones
                    </button>
                </div>
            </form>
        </div>

        <div class="row">
            <div class="col-lg-8">
                <!-- ESTRUCTURA DE CLASE -->
                <div class="card-custom p-4 hoja">
                    <h4 class="text-center mb-4">ESTRUCTURA DE CLASE</h4>
                    <form method="POST" id="formClase">
                        <input type="hidden" name="accion" value="guardar_clase">
                        <input type="hidden" name="id_clase" value="<?= $clase['id'] ?? 0 ?>">
                        <input type="hidden" name="id_asignacion" value="<?= $id_asignacion_filtro ?>">
                        <div class="row g-3 mb-3">
                            <div class="col-md-6">
                                <label class="form-label small text-muted">Nombre del Docente</label>
                                <div class="campo-solo-lectura"><?= htmlspecialchars(trim(($profesor['primer_nombre'] ?? '') . ' ' . ($profesor['primer_apellido'] ?? ''))) ?></div>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label small text-muted">Asignatura</label>
                                <div class="campo-solo-lectura"><?= htmlspecialchars($asignacion_filtro_info['asignatura_nombre'] ?? '') ?></div>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label small text-muted">Grado</label>
                                <div class="campo-solo-lectura"><?= htmlspecialchars($asignacion_filtro_info['grado_nombre'] ?? '') ?></div>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label small text-muted">Sección</label>
                                <div class="campo-solo-lectura"><?= htmlspecialchars($asignacion_filtro_info['seccion_nombre'] ?? '') ?></div>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label small text-muted">No. Clase</label>
                                <input type="text" name="numero_clase" class="form-control" maxlength="20" value="<?= htmlspecialchars($clase['numero_clase'] ?? '') ?>" placeholder="Ej. 3">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label small text-muted">Fecha</label>
                                <input type="date" name="fecha_clase" class="form-control" value="<?= htmlspecialchars($clase['fecha_clase'] ?? date('Y-m-d')) ?>">
                            </div>
                        </div>

                        <div class="mb-3">
                            <label class="form-label fw-bold">Objetivo de la Clase</label>
                            <textarea name="objetivo" id="rte-objetivo" class="form-control rte-editor" rows="2" placeholder="¿Qué aprenderán los estudiantes hoy?"><?= htmlspecialchars($clase['objetivo'] ?? '') ?></textarea>
                        </div>

                        <div class="mb-3">
                            <label class="form-label fw-bold">Desarrollo de la Clase</label>
                            <textarea name="desarrollo" id="rte-desarrollo" class="form-control rte-editor" rows="6" placeholder="Actividades, explicación, ejercicios..."><?= htmlspecialchars($clase['desarrollo'] ?? '') ?></textarea>
                        </div>

                        <div class="mb-3">
                            <label class="form-label fw-bold">Cierre</label>
                            <textarea name="cierre" id="rte-cierre" class="form-control rte-editor" rows="2" placeholder="Conclusión, resumen, próxima clase..."><?= htmlspecialchars($clase['cierre'] ?? '') ?></textarea>
                        </div>

                        <button type="submit" class="btn btn-primary no-print"><i class="fas fa-save"></i> <?= $clase ? 'Actualizar Clase' : 'Guardar Clase' ?></button>
                        <?php if ($clase): ?>
                        <button type="button" class="btn btn-outline-danger no-print" onclick="eliminarClase(<?= $clase['id'] ?>)"><i class="fas fa-trash"></i> Eliminar</button>
                        <?php endif; ?>
                    </form>

                    <?php if (!$clase): ?>
                    <p class="text-muted small mt-3 no-print"><i class="fas fa-info-circle"></i> Guarda la clase primero para poder agregar Recursos y usar los botones de Cierre.</p>
                    <?php endif; ?>

                    <?php if ($clase): ?>
                    <!-- RECURSOS -->
                    <hr>
                    <h5 class="mb-3">Recursos</h5>
                    <div class="row g-2 mb-3 no-print">
                        <div class="col-6 col-md-3"><button class="btn btn-sm btn-outline-primary w-100" data-bs-toggle="modal" data-bs-target="#modalRecurso" onclick="prepararRecurso('imagen')"><i class="fas fa-image"></i> Imagen</button></div>
                        <div class="col-6 col-md-3"><button class="btn btn-sm btn-outline-primary w-100" data-bs-toggle="modal" data-bs-target="#modalRecurso" onclick="prepararRecurso('sitio_web')"><i class="fas fa-globe"></i> Sitio Web</button></div>
                        <div class="col-6 col-md-3"><button class="btn btn-sm btn-outline-primary w-100" data-bs-toggle="modal" data-bs-target="#modalRecurso" onclick="prepararRecurso('articulo')"><i class="fas fa-file-alt"></i> Artículo</button></div>
                        <div class="col-6 col-md-3"><button class="btn btn-sm btn-outline-primary w-100" data-bs-toggle="modal" data-bs-target="#modalRecurso" onclick="prepararRecurso('video_yt')"><i class="fab fa-youtube"></i> Video YT</button></div>
                    </div>
                    <div id="listaRecursos">
                        <?php if (empty($recursos)): ?>
                        <p class="text-muted small">Todavía no hay recursos agregados.</p>
                        <?php else: ?>
                        <?php foreach ($recursos as $r): ?>
                        <span class="recurso-chip" data-id="<?= $r['id'] ?>">
                            <i class="fas <?= ['imagen'=>'fa-image','sitio_web'=>'fa-globe','articulo'=>'fa-file-alt','video_yt'=>'fa-video'][$r['tipo']] ?? 'fa-link' ?>"></i>
                            <?php if ($r['url']): ?><a href="<?= htmlspecialchars($r['url']) ?>" target="_blank"><?= htmlspecialchars($r['titulo'] ?: $r['url']) ?></a>
                            <?php else: ?><?= htmlspecialchars($r['titulo'] ?: 'Artículo') ?><?php endif; ?>
                            <button type="button" class="btn btn-sm text-danger p-0 ms-1 no-print" onclick="eliminarRecurso(<?= $r['id'] ?>)" title="Quitar">&times;</button>
                        </span>
                        <?php endforeach; ?>
                        <?php endif; ?>
                    </div>

                    <!-- CIERRE: 3 acciones vinculantes -->
                    <hr>
                    <h5 class="mb-3">Acciones de Cierre</h5>
                    <div class="row g-3 no-print">
                        <div class="col-md-4">
                            <button class="btn btn-warning w-100 btn-cierre" data-bs-toggle="modal" data-bs-target="#modalTarea"><i class="fas fa-clipboard-list fa-lg d-block mb-1"></i> Asignar tarea</button>
                            <?php if ($actividadesVinculadas['tarea']): ?><small class="d-block text-center text-success mt-1"><i class="fas fa-check-circle"></i> <?= htmlspecialchars($actividadesVinculadas['tarea']['titulo']) ?></small><?php endif; ?>
                        </div>
                        <div class="col-md-4">
                            <button class="btn btn-danger w-100 btn-cierre" data-bs-toggle="modal" data-bs-target="#modalExamen"><i class="fas fa-file-alt fa-lg d-block mb-1"></i> Asignar examen</button>
                            <?php if ($actividadesVinculadas['examen']): ?><small class="d-block text-center text-success mt-1"><i class="fas fa-check-circle"></i> <?= htmlspecialchars($actividadesVinculadas['examen']['titulo']) ?> — <a href="gestionar_actividades.php?asignacion=<?= $id_asignacion_filtro ?>">editar preguntas</a></small><?php endif; ?>
                        </div>
                        <div class="col-md-4">
                            <button class="btn btn-primary w-100 btn-cierre" data-bs-toggle="modal" data-bs-target="#modalActividadExtra"><i class="fas fa-plus-circle fa-lg d-block mb-1"></i> Asignar Actividad</button>
                            <?php if ($actividadesVinculadas['extra']): ?><small class="d-block text-center text-success mt-1"><i class="fas fa-check-circle"></i> <?= htmlspecialchars($actividadesVinculadas['extra']['titulo']) ?></small><?php endif; ?>
                        </div>
                    </div>

                    <!-- FORO DE LA CLASE -->
                    <hr>
                    <h5 class="mb-3" id="foro"><i class="fas fa-comments"></i> Foro de la Clase</h5>
                    <p class="text-muted small no-print">Visible para los estudiantes matriculados en <?= htmlspecialchars($asignacion_filtro_info['grado_nombre'] ?? '') ?> <?= htmlspecialchars($asignacion_filtro_info['seccion_nombre'] ?? '') ?>. Comparte algo o responde lo que publiquen.</p>
                    <div class="mb-3" style="max-height: 320px; overflow-y: auto;">
                        <?php if (empty($mensajesForo)): ?>
                        <p class="text-muted small">Todavía no hay mensajes en el foro de esta clase.</p>
                        <?php else: ?>
                        <?php foreach ($mensajesForo as $fm): ?>
                        <div class="d-flex justify-content-between align-items-start mb-2 pb-2 border-bottom">
                            <div>
                                <strong class="small"><?= htmlspecialchars(trim($fm['primer_nombre'] . ' ' . $fm['primer_apellido'])) ?></strong>
                                <span class="badge <?= $fm['autor_rol'] === 'profesor' ? 'bg-primary' : 'bg-secondary' ?> ms-1"><?= $fm['autor_rol'] === 'profesor' ? 'Docente' : 'Estudiante' ?></span>
                                <span class="text-muted small ms-1"><?= date('d/m/Y h:i A', strtotime($fm['created_at'])) ?></span>
                                <div class="small" style="white-space: pre-wrap;"><?= htmlspecialchars($fm['mensaje']) ?></div>
                            </div>
                            <button type="button" class="btn btn-sm text-danger p-0 ms-2 no-print" title="Eliminar mensaje" onclick="eliminarMensajeForo(<?= $fm['id'] ?>)">&times;</button>
                        </div>
                        <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                    <form method="POST" class="no-print">
                        <input type="hidden" name="accion" value="publicar_foro_mensaje">
                        <input type="hidden" name="id_clase" value="<?= $clase['id'] ?>">
                        <div class="input-group">
                            <textarea name="mensaje" class="form-control" rows="2" maxlength="3000" placeholder="Escribe un mensaje para tus estudiantes..." required></textarea>
                            <button type="submit" class="btn btn-success"><i class="fas fa-paper-plane"></i> Publicar</button>
                        </div>
                    </form>
                    <form method="POST" id="formEliminarMensajeForo" class="d-none">
                        <input type="hidden" name="accion" value="eliminar_foro_mensaje">
                        <input type="hidden" name="id_clase" value="<?= $clase['id'] ?>">
                        <input type="hidden" name="id_mensaje" id="eliminarMensajeForoId">
                    </form>
                    <?php endif; ?>
                </div>
            </div>

            <div class="col-lg-4 no-print">
                <!-- BITÁCORA -->
                <div class="card-custom p-3">
                    <h6><i class="fas fa-history"></i> Bitácora de clases</h6>
                    <?php if (empty($bitacora)): ?>
                    <p class="text-muted small mb-0">No hay clases previas en esta asignación.</p>
                    <?php else: ?>
                    <?php foreach ($bitacora as $b):
                        $tieneVinculo = $b['id_actividad_tarea'] || $b['id_actividad_examen'] || $b['id_actividad_extra'];
                    ?>
                    <a href="impartir_clase.php?asignacion=<?= $id_asignacion_filtro ?>&clase=<?= $b['id'] ?>" class="d-block text-decoration-none text-dark bitacora-item mb-2 <?= $tieneVinculo ? 'tiene-vinculo' : '' ?>">
                        <div class="d-flex justify-content-between">
                            <strong class="small"><?= $b['numero_clase'] ? 'Clase ' . htmlspecialchars($b['numero_clase']) : 'Clase' ?></strong>
                            <span class="small text-muted"><?= date('d/m/Y', strtotime($b['fecha_clase'])) ?></span>
                        </div>
                        <?php if ($b['objetivo']): ?><div class="small text-muted text-truncate"><?= htmlspecialchars($b['objetivo']) ?></div><?php endif; ?>
                        <div class="mt-1">
                            <?php if ($b['id_actividad_tarea']): ?><span class="badge bg-warning text-dark">✓ Tarea</span><?php endif; ?>
                            <?php if ($b['id_actividad_examen']): ?><span class="badge bg-danger">✓ Examen</span><?php endif; ?>
                            <?php if ($b['id_actividad_extra']): ?><span class="badge bg-primary">✓ Actividad</span><?php endif; ?>
                        </div>
                    </a>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Modal Generar sesiones del período -->
        <div class="modal fade" id="modalGenerarSesiones" tabindex="-1">
            <div class="modal-dialog">
                <div class="modal-content">
                    <div class="modal-header bg-success text-white">
                        <h5 class="modal-title"><i class="fas fa-calendar-plus"></i> Generar sesiones del período</h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                    </div>
                    <form method="POST">
                        <div class="modal-body">
                            <input type="hidden" name="accion" value="generar_sesiones">
                            <input type="hidden" name="id_asignacion" value="<?= $id_asignacion_filtro ?>">
                            <?php if (empty($dias_horario_asignacion)): ?>
                            <div class="alert alert-warning small mb-0">
                                <i class="fas fa-exclamation-triangle"></i>
                                Esta asignación todavía no tiene ningún día configurado en
                                <strong>Horario de Clases</strong>. Agrégale su horario primero
                                y vuelve aquí para generar las sesiones automáticamente.
                            </div>
                            <?php else: ?>
                            <p class="text-muted small">
                                Crea una fila de bitácora (en borrador) por cada
                                <strong><?= htmlspecialchars(implode(', ', $dias_horario_nombres)) ?></strong>
                                dentro del rango de fechas, según el horario ya configurado para
                                <?= htmlspecialchars($asignacion_filtro_info['asignatura_nombre'] ?? '') ?> -
                                <?= htmlspecialchars($asignacion_filtro_info['grado_nombre'] ?? '') ?> <?= htmlspecialchars($asignacion_filtro_info['seccion_nombre'] ?? '') ?>.
                                Las fechas que ya tengan una clase registrada se omiten (no duplica).
                            </p>
                            <?php if (!empty($periodos_cuadro)): ?>
                            <div class="mb-3">
                                <label class="form-label small text-muted">Rellenar con un período (opcional)</label>
                                <select class="form-select" id="selectPeriodoSesiones">
                                    <option value="">-- Elegir fechas manualmente --</option>
                                    <?php foreach ($periodos_cuadro as $p): ?>
                                    <option value="<?= htmlspecialchars($p['fecha_inicio'] ?? '') ?>|<?= htmlspecialchars($p['fecha_fin'] ?? '') ?>"><?= htmlspecialchars($p['nombre']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <?php endif; ?>
                            <div class="row g-3">
                                <div class="col-md-6">
                                    <label class="form-label">Fecha inicio *</label>
                                    <input type="date" name="fecha_inicio" id="sesionesFechaInicio" class="form-control" required>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">Fecha fin *</label>
                                    <input type="date" name="fecha_fin" id="sesionesFechaFin" class="form-control" required>
                                </div>
                            </div>
                            <?php endif; ?>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                            <?php if (!empty($dias_horario_asignacion)): ?>
                            <button type="submit" class="btn btn-success"><i class="fas fa-calendar-plus"></i> Generar</button>
                            <?php endif; ?>
                        </div>
                    </form>
                </div>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <?php if ($clase): ?>
    <!-- Modal Recurso -->
    <div class="modal fade" id="modalRecurso" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header"><h5 class="modal-title" id="tituloModalRecurso">Agregar Recurso</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
                <form id="formRecurso" enctype="multipart/form-data">
                    <div class="modal-body">
                        <input type="hidden" name="id_clase" value="<?= $clase['id'] ?>">
                        <input type="hidden" name="tipo" id="recurso_tipo">
                        <div class="mb-3" id="campoRecursoTitulo">
                            <label class="form-label">Título</label>
                            <input type="text" name="titulo" class="form-control">
                        </div>
                        <div class="mb-3 d-none" id="campoRecursoArchivo">
                            <label class="form-label">Archivo de imagen</label>
                            <input type="file" name="archivo" class="form-control" accept="image/png,image/jpeg,image/gif,image/webp">
                        </div>
                        <div class="mb-3 d-none" id="campoRecursoUrl">
                            <label class="form-label">URL</label>
                            <input type="url" name="url" class="form-control" placeholder="https://...">
                        </div>
                        <div class="mb-3 d-none" id="campoRecursoContenido">
                            <label class="form-label">Contenido</label>
                            <textarea name="contenido" class="form-control" rows="4"></textarea>
                        </div>
                        <div id="recursoError" class="text-danger small"></div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                        <button type="submit" class="btn btn-primary">Agregar</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Modal Asignar tarea -->
    <div class="modal fade" id="modalTarea" tabindex="-1">
        <div class="modal-dialog modal-lg modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header bg-warning"><h5 class="modal-title"><i class="fas fa-clipboard-list"></i> Asignar tarea</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
                <form method="POST">
                    <div class="modal-body">
                        <input type="hidden" name="accion" value="asignar_tarea">
                        <input type="hidden" name="id_clase" value="<?= $clase['id'] ?>">
                        <div class="mb-3"><label class="form-label">Título *</label><input type="text" name="titulo" class="form-control" required></div>
                        <div class="mb-3"><label class="form-label">Descripción</label><textarea name="descripcion" class="form-control" rows="3"></textarea></div>
                        <div class="row g-3 mb-3">
                            <div class="col-md-4"><label class="form-label">Fecha límite</label><input type="datetime-local" name="fecha_limite" class="form-control"></div>
                            <div class="col-md-4"><label class="form-label">Nota máxima</label><input type="number" name="nota_maxima" class="form-control" value="10" min="0" step="0.1"></div>
                            <div class="col-md-4">
                                <label class="form-label">Rúbrica</label>
                                <select name="id_rubrica_plantilla" class="form-select">
                                    <option value="">Sin rúbrica</option>
                                    <?php foreach ($rubricas_plantilla as $r): ?>
                                    <option value="<?= $r['id'] ?>"><?= htmlspecialchars($r['nombre']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        <input type="hidden" name="fecha_programada" value="<?= date('Y-m-d\TH:i') ?>">
                        <?php $idPrefix = 'tarea_'; require __DIR__ . '/partials/bloque_cuadro_notas.php'; ?>
                    </div>
                    <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button><button type="submit" class="btn btn-warning">Crear tarea</button></div>
                </form>
            </div>
        </div>
    </div>

    <!-- Modal Asignar examen -->
    <div class="modal fade" id="modalExamen" tabindex="-1">
        <div class="modal-dialog modal-lg modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header bg-danger text-white"><h5 class="modal-title"><i class="fas fa-file-alt"></i> Asignar examen</h5><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div>
                <form method="POST">
                    <div class="modal-body">
                        <input type="hidden" name="accion" value="asignar_examen">
                        <input type="hidden" name="id_clase" value="<?= $clase['id'] ?>">
                        <p class="text-muted small"><i class="fas fa-info-circle"></i> Se crea el examen sin preguntas; podrás agregarlas después desde Gestionar Actividades.</p>
                        <div class="mb-3"><label class="form-label">Título *</label><input type="text" name="titulo" class="form-control" required></div>
                        <div class="row g-3 mb-3">
                            <div class="col-md-4"><label class="form-label">Duración (min)</label><input type="number" name="duracion_minutos" class="form-control" min="1"></div>
                            <div class="col-md-4"><label class="form-label">Nota máxima</label><input type="number" name="nota_maxima" class="form-control" value="10" min="0" step="0.1"></div>
                            <div class="col-md-4"><label class="form-label">Fecha límite</label><input type="datetime-local" name="fecha_limite" class="form-control"></div>
                        </div>
                        <input type="hidden" name="fecha_programada" value="<?= date('Y-m-d\TH:i') ?>">
                        <?php $idPrefix = 'examen_'; require __DIR__ . '/partials/bloque_cuadro_notas.php'; ?>
                    </div>
                    <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button><button type="submit" class="btn btn-danger">Crear examen</button></div>
                </form>
            </div>
        </div>
    </div>

    <!-- Modal Asignar Actividad -->
    <div class="modal fade" id="modalActividadExtra" tabindex="-1">
        <div class="modal-dialog modal-lg modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header bg-primary text-white"><h5 class="modal-title"><i class="fas fa-plus-circle"></i> Asignar Actividad</h5><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div>
                <form method="POST">
                    <div class="modal-body">
                        <input type="hidden" name="accion" value="asignar_actividad">
                        <input type="hidden" name="id_clase" value="<?= $clase['id'] ?>">
                        <div class="row g-3 mb-3">
                            <div class="col-md-8"><label class="form-label">Título *</label><input type="text" name="titulo" class="form-control" required></div>
                            <div class="col-md-4">
                                <label class="form-label">Tipo</label>
                                <select name="tipo" class="form-select">
                                    <?php foreach ($tipos_referencia as $key => $label): ?>
                                    <option value="<?= $key ?>"><?= $label ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        <div class="mb-3"><label class="form-label">Descripción</label><textarea name="descripcion" class="form-control" rows="2"></textarea></div>
                        <div class="mb-3"><label class="form-label">URL del recurso</label><input type="url" name="url_recurso" class="form-control" placeholder="https://..."></div>
                        <div class="mb-3"><label class="form-label">Contenido</label><textarea name="contenido" class="form-control" rows="3" placeholder="Texto del artículo/referencia (opcional)"></textarea></div>
                        <input type="hidden" name="fecha_programada" value="<?= date('Y-m-d\TH:i') ?>">
                        <?php $idPrefix = 'act_'; require __DIR__ . '/partials/bloque_cuadro_notas.php'; ?>
                    </div>
                    <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button><button type="submit" class="btn btn-primary">Crear actividad</button></div>
                </form>
            </div>
        </div>
    </div>

    <form method="POST" id="formEliminarClase" class="d-none">
        <input type="hidden" name="accion" value="eliminar_clase">
        <input type="hidden" name="id_clase" id="eliminarClaseId">
    </form>
    <?php endif; ?>

    <?php require __DIR__ . '/partials/scripts.php'; ?>
    <!-- Editor de texto enriquecido para Objetivo/Desarrollo/Cierre --
         mismo patrón (autohospedado vía jsdelivr, sin API key) y misma
         barra de herramientas que modules/admin/manual_convivencia.php.
         A diferencia de esa pantalla, aquí los 3 campos siempre están
         visibles (no viven dentro de pestañas ocultas de Bootstrap), así
         que se inicializan directo al cargar la página -- no hace falta
         el truco de init-por-pestaña. -->
    <script src="https://cdn.jsdelivr.net/npm/tinymce@6/tinymce.min.js" referrerpolicy="origin"></script>
    <script>
        if (typeof tinymce !== 'undefined' && document.querySelector('textarea.rte-editor')) {
            tinymce.init({
                selector: 'textarea.rte-editor',
                height: 220,
                menubar: false,
                statusbar: false,
                branding: false,
                promotion: false,
                plugins: 'table',
                toolbar: 'undo redo | bold italic underline | forecolor backcolor | fontfamily fontsize | alignleft aligncenter alignright alignjustify | table | removeformat',
                font_family_formats: 'Arial=arial,helvetica,sans-serif; Georgia=georgia,palatino,serif; Times New Roman=times new roman,times,serif; Verdana=verdana,geneva,sans-serif; Courier New=courier new,courier,monospace',
                font_size_formats: '8pt 10pt 12pt 14pt 16pt 18pt 24pt 36pt',
                content_style: "body { font-family: 'Segoe UI', sans-serif; font-size: 14px; }",
                table_default_attributes: { border: '1' },
                table_default_styles: { width: '100%' },
            });
        }
        // TinyMCE reemplaza visualmente el <textarea> pero solo escribe de
        // vuelta su contenido en él al detectar el submit del <form> que lo
        // contiene -- sin esto, el POST enviaría el textarea vacío.
        document.querySelectorAll('form').forEach(function (form) {
            form.addEventListener('submit', function () {
                if (typeof tinymce !== 'undefined') {
                    tinymce.triggerSave();
                }
            });
        });
    </script>
    <script>
        function eliminarClase(id) {
            if (!confirm('¿Eliminar esta clase? Esto no borra las actividades ya creadas (tarea/examen/actividad), solo el registro de la clase.')) return;
            document.getElementById('eliminarClaseId').value = id;
            document.getElementById('formEliminarClase').submit();
        }

        function eliminarMensajeForo(id) {
            if (!confirm('¿Eliminar este mensaje del foro?')) return;
            document.getElementById('eliminarMensajeForoId').value = id;
            document.getElementById('formEliminarMensajeForo').submit();
        }

        function eliminarRecurso(id) {
            if (!confirm('¿Quitar este recurso?')) return;
            fetch('api/clase_recurso.php', {
                method: 'POST',
                body: new URLSearchParams({ accion: 'eliminar', id_recurso: id })
            }).then(r => r.json()).then(data => {
                if (data.success) { location.reload(); } else { alert(data.message || 'No se pudo eliminar'); }
            });
        }

        function prepararRecurso(tipo) {
            document.getElementById('recurso_tipo').value = tipo;
            document.getElementById('recursoError').textContent = '';
            document.getElementById('formRecurso').reset();
            document.getElementById('recurso_tipo').value = tipo;
            const titulos = { imagen: 'Agregar Imagen', sitio_web: 'Agregar Sitio Web', articulo: 'Agregar Artículo', video_yt: 'Agregar Video de YouTube' };
            document.getElementById('tituloModalRecurso').textContent = titulos[tipo] || 'Agregar Recurso';
            document.getElementById('campoRecursoArchivo').classList.toggle('d-none', tipo !== 'imagen');
            document.getElementById('campoRecursoUrl').classList.toggle('d-none', !(tipo === 'sitio_web' || tipo === 'video_yt'));
            document.getElementById('campoRecursoContenido').classList.toggle('d-none', tipo !== 'articulo');
        }

        document.getElementById('formRecurso')?.addEventListener('submit', function (e) {
            e.preventDefault();
            const fd = new FormData(this);
            fetch('api/clase_recurso.php', { method: 'POST', body: fd })
                .then(r => r.json())
                .then(data => {
                    if (data.success) { location.reload(); }
                    else { document.getElementById('recursoError').textContent = data.message || 'No se pudo agregar el recurso'; }
                })
                .catch(() => { document.getElementById('recursoError').textContent = 'Error de conexión'; });
        });

        // Al elegir un período en el modal "Generar sesiones", rellena las
        // fechas de inicio/fin con las de ese período -- siguen siendo
        // editables a mano después, por si el profesor quiere un rango
        // distinto (ej. solo la primera mitad del período).
        document.getElementById('selectPeriodoSesiones')?.addEventListener('change', function () {
            if (!this.value) return;
            const [inicio, fin] = this.value.split('|');
            if (inicio) document.getElementById('sesionesFechaInicio').value = inicio;
            if (fin) document.getElementById('sesionesFechaFin').value = fin;
        });
    </script>
</body>
</html>
