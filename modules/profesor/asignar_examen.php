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
$id_profesor = null;

// Obtener ID del profesor
$query = "SELECT p.id, per.primer_nombre FROM tbl_profesor p
    JOIN tbl_persona per ON p.id_persona = per.id
    WHERE per.id_usuario = :user_id AND p.id_institucion = :tid";
$stmt = $db->prepare($query);
$stmt->execute([':user_id' => $user_id, ':tid' => $tid]);
$profesor_data = $stmt->fetch(PDO::FETCH_ASSOC);
$id_profesor = $profesor_data['id'] ?? 0;
$profesor = $profesor_data ?: [];

$mensaje = '';
$tipo_mensaje = '';

// Un examen creado aquí (tbl_examen) sólo es visible/tomable para los
// estudiantes cuando existe una fila hermana en tbl_actividad (tipo='examen',
// id_examen = este examen) -- es esa fila la que tbl_actividad-driven
// actividades.php/calendario_evaluaciones.php/ver_materia.php realmente
// consultan para mostrárselo al estudiante de la sección de esta asignación.
// Antes, este módulo cambiaba el estado del examen (Publicar/Activar/Cerrar)
// sin nunca crear esa fila, así que ningún examen creado aquí llegaba a
// ningún estudiante aunque quedara "Activo". Se sincroniza automáticamente
// a TODA la sección de la asignación (mismo criterio que ya usan Actividades,
// Notas y Horario en el resto del sistema -- no hay selección de estudiantes
// individuales en ningún otro módulo, así que agregar una aquí sería
// inconsistente).
function sincronizarActividadExamen(PDO $db, int $id_examen, string $nuevoEstadoExamen): void
{
    $stmt = $db->prepare("SELECT id_asignacion_docente, titulo, descripcion, instrucciones,
                                  duracion_minutos, nota_maxima, fecha_programada, fecha_limite
                           FROM tbl_examen WHERE id = :id");
    $stmt->execute([':id' => $id_examen]);
    $examen = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$examen) return;

    $stmtAct = $db->prepare("SELECT id FROM tbl_actividad WHERE id_examen = :id_examen");
    $stmtAct->execute([':id_examen' => $id_examen]);
    $idActividad = $stmtAct->fetchColumn();

    if ($idActividad) {
        // Ya existe: mantener sincronizados estado y metadatos (por si el
        // profesor editó título/fechas/nota en crear_examen.php después de
        // publicar). tbl_actividad.estado usa las mismas palabras que
        // tbl_examen.estado para este caso (programado/activo/cerrado), así
        // que se copia directo sin tabla de traducción.
        $stmt = $db->prepare("UPDATE tbl_actividad SET
                estado = :estado, titulo = :titulo, descripcion = :descripcion,
                fecha_programada = :fecha_prog, fecha_limite = :fecha_limite,
                duracion_minutos = SEC_TO_TIME(:duracion * 60), nota_maxima = :nota_maxima
            WHERE id = :id");
        $stmt->execute([
            ':estado' => $nuevoEstadoExamen, ':titulo' => $examen['titulo'], ':descripcion' => $examen['descripcion'],
            ':fecha_prog' => $examen['fecha_programada'], ':fecha_limite' => $examen['fecha_limite'],
            ':duracion' => $examen['duracion_minutos'] ?: 60, ':nota_maxima' => $examen['nota_maxima'],
            ':id' => $idActividad,
        ]);
    } elseif ($nuevoEstadoExamen !== 'borrador') {
        // Primera vez que se publica (borrador -> programado/activo/cerrado
        // directo): se crea la actividad recién ahora, nunca mientras el
        // examen sigue en borrador (así un examen a medio construir no le
        // aparece a nadie).
        $stmt = $db->prepare("INSERT INTO tbl_actividad
                (id_asignacion_docente, id_examen, titulo, descripcion, tipo,
                 fecha_programada, fecha_limite, duracion_minutos, nota_maxima, contenido, estado)
            VALUES
                (:asig, :id_examen, :titulo, :descripcion, 'examen',
                 :fecha_prog, :fecha_limite, SEC_TO_TIME(:duracion * 60), :nota_maxima, :contenido, :estado)");
        $stmt->execute([
            ':asig' => $examen['id_asignacion_docente'], ':id_examen' => $id_examen,
            ':titulo' => $examen['titulo'], ':descripcion' => $examen['descripcion'],
            ':fecha_prog' => $examen['fecha_programada'], ':fecha_limite' => $examen['fecha_limite'],
            ':duracion' => $examen['duracion_minutos'] ?: 60, ':nota_maxima' => $examen['nota_maxima'],
            ':contenido' => $examen['instrucciones'] ?: '', ':estado' => $nuevoEstadoExamen,
        ]);
    }
}

// ===== ACCIONES SOBRE EXÁMENES EXISTENTES (tbl_examen) =====
// La creación/edición de contenido del examen (preguntas de los 5 tipos) se hace
// en crear_examen.php; aquí sólo se gestiona el ciclo de vida (publicar/cerrar/eliminar)
// de exámenes que ya pertenecen a este profesor.
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['accion'])) {
    try {
        $db->beginTransaction();

        $accion = $_POST['accion'];

        if (in_array($accion, ['cambiar_estado', 'eliminar_examen', 'resincronizar'], true)) {
            $id_examen = (int) $_POST['id_examen'];

            // El examen DEBE pertenecer a una asignación de ESTE profesor.
            // Ni tbl_examen ni tbl_asignacion_docente tienen columna
            // id_institucion; basta con ad.id_profesor porque $id_profesor
            // ya viene de una consulta anterior filtrada por tenant.
            $query = "SELECT e.id FROM tbl_examen e
                      JOIN tbl_asignacion_docente ad ON e.id_asignacion_docente = ad.id
                      WHERE e.id = :id AND ad.id_profesor = :prof";
            $stmt = $db->prepare($query);
            $stmt->execute([':id' => $id_examen, ':prof' => $id_profesor]);
            if (!$stmt->fetch()) {
                throw new Exception('Este examen no le pertenece.');
            }

            if ($accion == 'resincronizar') {
                // Botón "Asignar" -- fuerza a que la fila hermana en
                // tbl_actividad (la que de verdad consultan
                // actividades.php/ver_materia.php/calendario_evaluaciones.php
                // del lado estudiante) quede al día con el examen, usando el
                // estado ACTUAL del examen (no cambia nada, solo re-sincroniza).
                // Sirve como red de seguridad si por lo que sea la fila
                // hermana nunca se creó o quedó desactualizada.
                $estadoActual = $db->prepare("SELECT estado FROM tbl_examen WHERE id = :id");
                $estadoActual->execute([':id' => $id_examen]);
                $estadoExamen = $estadoActual->fetchColumn();
                if ($estadoExamen === 'borrador') {
                    throw new Exception('Este examen todavía está en Borrador -- publícalo primero.');
                }
                sincronizarActividadExamen($db, $id_examen, $estadoExamen);
                $mensaje = 'Examen re-asignado a la sección. Ya debería verlo cualquier estudiante matriculado en esa sección/año.';
                $tipo_mensaje = 'success';
            } elseif ($accion == 'cambiar_estado') {
                $nuevo_estado = $_POST['nuevo_estado'] ?? '';
                $estados_validos = ['borrador', 'programado', 'activo', 'cerrado'];
                if (!in_array($nuevo_estado, $estados_validos, true)) {
                    throw new Exception('Estado no válido.');
                }
                $stmt = $db->prepare("UPDATE tbl_examen SET estado = :estado WHERE id = :id");
                $stmt->execute([':estado' => $nuevo_estado, ':id' => $id_examen]);
                sincronizarActividadExamen($db, $id_examen, $nuevo_estado);
                $mensaje = $nuevo_estado === 'programado'
                    ? 'Examen publicado: ya es visible para los estudiantes de esa sección como "Programado".'
                    : 'Estado del examen actualizado';
                $tipo_mensaje = 'success';
            } else { // eliminar_examen
                // Borrar primero la actividad hermana (si llegó a publicarse)
                // y sus entregas/intentos, para no dejar filas huérfanas.
                $stmtAct = $db->prepare("SELECT id FROM tbl_actividad WHERE id_examen = :id");
                $stmtAct->execute([':id' => $id_examen]);
                $idActividad = $stmtAct->fetchColumn();
                if ($idActividad) {
                    $db->prepare("DELETE FROM tbl_entrega_actividad WHERE id_actividad = :id")->execute([':id' => $idActividad]);
                    $db->prepare("DELETE FROM tbl_actividad WHERE id = :id")->execute([':id' => $idActividad]);
                }
                $db->prepare("DELETE FROM tbl_intento_examen WHERE id_examen = :id")->execute([':id' => $id_examen]);
                $stmt = $db->prepare("DELETE FROM tbl_opcion_respuesta WHERE id_pregunta IN (SELECT id FROM tbl_pregunta_examen WHERE id_examen = :id)");
                $stmt->execute([':id' => $id_examen]);
                $stmt = $db->prepare("DELETE FROM tbl_pregunta_examen WHERE id_examen = :id");
                $stmt->execute([':id' => $id_examen]);
                $stmt = $db->prepare("DELETE FROM tbl_examen WHERE id = :id");
                $stmt->execute([':id' => $id_examen]);
                $mensaje = 'Examen eliminado';
                $tipo_mensaje = 'success';
            }
        }

        $db->commit();
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
    WHERE ad.id_profesor = :id_profesor AND a.id_institucion = :tid
    ORDER BY g.nombre, s.nombre, a.nombre";

$stmt = $db->prepare($query);
$stmt->execute([':id_profesor' => $id_profesor, ':tid' => $tid]);
$asignaciones = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Obtener exámenes existentes (con su contenido real: tbl_examen + preguntas)
$query = "SELECT
    ex.id, ex.id_asignacion_docente, ex.titulo, ex.fecha_programada, ex.fecha_limite,
    ex.nota_maxima, ex.estado,
    a.nombre as asignatura,
    s.nombre as seccion,
    g.nombre as grado,
    ad.anno,
    (SELECT COUNT(*) FROM tbl_pregunta_examen pe WHERE pe.id_examen = ex.id) as total_preguntas,
    (SELECT COUNT(*) FROM tbl_matricula m WHERE m.id_seccion = ad.id_seccion AND m.anno = ad.anno AND m.estado = 'activo') as total_estudiantes
    FROM tbl_examen ex
    JOIN tbl_asignacion_docente ad ON ex.id_asignacion_docente = ad.id
    JOIN tbl_asignatura a ON ad.id_asignatura = a.id
    JOIN tbl_seccion s ON ad.id_seccion = s.id
    JOIN tbl_grado g ON s.id_grado = g.id
    WHERE ad.id_profesor = :id_profesor
    AND a.id_institucion = :tid
    ORDER BY ex.created_at DESC";

$stmt = $db->prepare($query);
$stmt->execute([':id_profesor' => $id_profesor, ':tid' => $tid]);
$examenes = $stmt->fetchAll(PDO::FETCH_ASSOC);

$periodos = [1 => '1er Trimestre', 2 => '2do Trimestre', 3 => '3er Trimestre', 4 => '4to Trimestre'];
$activePage = 'examen';
$pageTitle = 'Asignar Examen - Educación Plus';
ob_start();
?>
<style>
    .card-custom { background: white; border-radius: 10px; box-shadow: 0 2px 10px rgba(0,0,0,0.08); margin-bottom: 20px; }
</style>
<?php
$extraHead = ob_get_clean();
require __DIR__ . '/partials/header.php';
?>
        <!-- Header -->
        <div class="d-flex justify-content-between align-items-center mb-4">
            <div>
                <h2><i class="fas fa-file-alt"></i> Asignar Examen</h2>
                <p class="text-muted mb-0">Crear preguntas y programar exámenes para tus estudiantes</p>
            </div>
            <?php if (!empty($asignaciones)): ?>
            <a href="crear_examen.php" class="btn btn-primary">
                <i class="fas fa-plus"></i> Nuevo Examen
            </a>
            <?php else: ?>
            <button class="btn btn-primary" disabled title="Necesitas tener al menos una asignación de clase">
                <i class="fas fa-plus"></i> Nuevo Examen
            </button>
            <?php endif; ?>
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
                    <?php if (!empty($asignaciones)): ?>
                    <a href="crear_examen.php" class="btn btn-primary">
                        <i class="fas fa-plus"></i> Crear Primer Examen
                    </a>
                    <?php else: ?>
                    <p class="small">Necesitas tener al menos una asignación de clase para crear un examen.</p>
                    <?php endif; ?>
                </div>
                <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Examen</th>
                                <th>Asignatura</th>
                                <th>Grado/Sección</th>
                                <th>Año</th>
                                <th>Estudiantes</th>
                                <th>Preguntas</th>
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
                                <td><?= htmlspecialchars($examen['anno']) ?></td>
                                <td>
                                    <?php if ((int) $examen['total_estudiantes'] === 0): ?>
                                    <span class="badge bg-danger" title="Ningún estudiante está matriculado (activo) en esa sección para ese año -- revisa Grados/Secciones y Matrículas, o que la asignación no sea de un año viejo.">
                                        <i class="fas fa-exclamation-triangle"></i> 0
                                    </span>
                                    <?php else: ?>
                                    <span class="badge bg-secondary"><?= (int) $examen['total_estudiantes'] ?></span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="badge bg-<?= $examen['total_preguntas'] > 0 ? 'secondary' : 'danger' ?>">
                                        <?= (int) $examen['total_preguntas'] ?>
                                    </span>
                                </td>
                                <td><?= $examen['fecha_programada'] ? date('d/m/Y H:i', strtotime($examen['fecha_programada'])) : '—' ?></td>
                                <td><?= $examen['fecha_limite'] ? date('d/m/Y H:i', strtotime($examen['fecha_limite'])) : '—' ?></td>
                                <td><span class="badge bg-info"><?= $examen['nota_maxima'] ?></span></td>
                                <td>
                                    <span class="badge bg-<?= $examen['estado'] == 'activo' ? 'success' : ($examen['estado'] == 'programado' ? 'warning' : ($examen['estado'] == 'cerrado' ? 'dark' : 'secondary')) ?>">
                                        <?= ucfirst($examen['estado']) ?>
                                    </span>
                                </td>
                                <td>
                                    <div class="btn-group btn-group-sm">
                                        <a class="btn btn-warning" title="Editar preguntas y configuración"
                                           href="crear_examen.php?asignacion=<?= $examen['id_asignacion_docente'] ?>&examen=<?= $examen['id'] ?>">
                                            <i class="fas fa-edit"></i>
                                        </a>
                                        <?php if ($examen['estado'] === 'borrador'): ?>
                                        <form method="POST" class="d-inline" onsubmit="return confirm('¿Publicar este examen para que quede visible como Programado?');">
                                            <input type="hidden" name="accion" value="cambiar_estado">
                                            <input type="hidden" name="id_examen" value="<?= $examen['id'] ?>">
                                            <input type="hidden" name="nuevo_estado" value="programado">
                                            <button type="submit" class="btn btn-success" title="Publicar"<?= $examen['total_preguntas'] == 0 ? ' disabled' : '' ?>>
                                                <i class="fas fa-check"></i>
                                            </button>
                                        </form>
                                        <?php elseif ($examen['estado'] === 'programado'): ?>
                                        <form method="POST" class="d-inline">
                                            <input type="hidden" name="accion" value="cambiar_estado">
                                            <input type="hidden" name="id_examen" value="<?= $examen['id'] ?>">
                                            <input type="hidden" name="nuevo_estado" value="activo">
                                            <button type="submit" class="btn btn-info" title="Activar">
                                                <i class="fas fa-play"></i>
                                            </button>
                                        </form>
                                        <?php elseif ($examen['estado'] === 'activo'): ?>
                                        <form method="POST" class="d-inline">
                                            <input type="hidden" name="accion" value="cambiar_estado">
                                            <input type="hidden" name="id_examen" value="<?= $examen['id'] ?>">
                                            <input type="hidden" name="nuevo_estado" value="cerrado">
                                            <button type="submit" class="btn btn-secondary" title="Cerrar">
                                                <i class="fas fa-lock"></i>
                                            </button>
                                        </form>
                                        <?php endif; ?>
                                        <?php if ($examen['estado'] !== 'borrador'): ?>
                                        <form method="POST" class="d-inline">
                                            <input type="hidden" name="accion" value="resincronizar">
                                            <input type="hidden" name="id_examen" value="<?= $examen['id'] ?>">
                                            <button type="submit" class="btn btn-outline-primary" title="Asignar / re-sincronizar con la sección (usa esto si el examen no le aparece a los estudiantes)">
                                                <i class="fas fa-share-square"></i>
                                            </button>
                                        </form>
                                        <?php endif; ?>
                                        <form method="POST" class="d-inline" onsubmit="return confirm('¿Eliminar este examen y todas sus preguntas? Esta acción no se puede deshacer.');">
                                            <input type="hidden" name="accion" value="eliminar_examen">
                                            <input type="hidden" name="id_examen" value="<?= $examen['id'] ?>">
                                            <button type="submit" class="btn btn-danger" title="Eliminar">
                                                <i class="fas fa-trash"></i>
                                            </button>
                                        </form>
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

    <?php require __DIR__ . '/partials/scripts.php'; ?>
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