<?php
session_start();
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/TenantGuard.php';
require_once __DIR__ . '/../../config/PeriodoHelper.php';
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

// ===== HELPERS: actividad tipo "examen" <-> motor real de exámenes =====
// Las actividades tipo "examen" se enlazan a un registro real en
// tbl_examen (mismas tablas que usan asignar_examen.php/crear_examen.php/
// tomar_examen.php), para que el examen creado aquí sea genuinamente
// calificable y el estudiante pueda tomarlo — no es sólo un anuncio.
// Los tipos de pregunta soportados son los 5 autocalificables; "ensayo"
// (pregunta abierta) queda excluido a propósito.
const TIPOS_PREGUNTA_EXAMEN_PERMITIDOS = ['opcion_multiple', 'verdadero_falso', 'completar', 'relacionar', 'respuesta_corta'];

function mapEstadoActividadAExamen(string $estadoActividad): string {
    // tbl_examen no tiene estado 'publicado'; 'publicado' y 'activo' en la
    // actividad significan lo mismo para el examen: disponible para tomar
    // (tomar_examen.php exige estado = 'activo').
    return match ($estadoActividad) {
        'publicado', 'activo' => 'activo',
        'cerrado' => 'cerrado',
        'eliminado' => 'eliminado',
        default => 'programado',
    };
}

/**
 * Inserta las preguntas (y sus opciones) de un examen a partir del arreglo
 * ya decodificado del campo oculto preguntas_json del formulario. Asume
 * que el examen (fila tbl_examen) ya existe y que, si es una edición, las
 * preguntas viejas de ese examen ya fueron borradas por el llamador.
 */
function guardarPreguntasExamen(PDO $db, int $id_examen, array $preguntas): void {
    $orden = 1;
    foreach ($preguntas as $preg) {
        $tipo = $preg['tipo'] ?? '';
        $enunciado = trim($preg['enunciado'] ?? '');
        if ($enunciado === '' || !in_array($tipo, TIPOS_PREGUNTA_EXAMEN_PERMITIDOS, true)) {
            continue;
        }
        $puntaje = is_numeric($preg['puntaje'] ?? null) ? (float) $preg['puntaje'] : 1.0;

        $stmt = $db->prepare("INSERT INTO tbl_pregunta_examen (id_examen, numero_orden, tipo, enunciado, puntaje)
                              VALUES (:examen, :orden, :tipo, :enunciado, :puntaje)");
        $stmt->execute([
            ':examen' => $id_examen, ':orden' => $orden, ':tipo' => $tipo,
            ':enunciado' => $enunciado, ':puntaje' => $puntaje
        ]);
        $id_pregunta = (int) $db->lastInsertId();

        if ($tipo === 'opcion_multiple') {
            $opciones = array_filter($preg['opciones'] ?? [], fn($o) => trim($o['texto'] ?? '') !== '');
            $i = 0;
            foreach ($opciones as $op) {
                $stmt = $db->prepare("INSERT INTO tbl_opcion_respuesta (id_pregunta, texto, es_correcta, orden)
                                      VALUES (:preg, :texto, :correcta, :orden)");
                $stmt->execute([
                    ':preg' => $id_pregunta, ':texto' => trim($op['texto']),
                    ':correcta' => !empty($op['es_correcta']) ? 1 : 0, ':orden' => $i
                ]);
                $i++;
            }
        } elseif ($tipo === 'verdadero_falso') {
            $correcta = $preg['correcta_vf'] ?? 'V';
            $stmt = $db->prepare("INSERT INTO tbl_opcion_respuesta (id_pregunta, texto, es_correcta, orden) VALUES (:preg, 'Verdadero', :v, 0)");
            $stmt->execute([':preg' => $id_pregunta, ':v' => ($correcta === 'V' ? 1 : 0)]);
            $stmt = $db->prepare("INSERT INTO tbl_opcion_respuesta (id_pregunta, texto, es_correcta, orden) VALUES (:preg, 'Falso', :f, 1)");
            $stmt->execute([':preg' => $id_pregunta, ':f' => ($correcta === 'F' ? 1 : 0)]);
        } elseif ($tipo === 'completar') {
            // Las respuestas correctas van entre corchetes dentro del enunciado.
            preg_match_all('/\[(.*?)\]/', $enunciado, $matches);
            foreach ($matches[1] as $i => $respuesta) {
                $stmt = $db->prepare("INSERT INTO tbl_opcion_respuesta (id_pregunta, texto, es_correcta, orden) VALUES (:preg, :texto, 1, :orden)");
                $stmt->execute([':preg' => $id_pregunta, ':texto' => $respuesta, ':orden' => $i]);
            }
        } elseif ($tipo === 'respuesta_corta') {
            $correcta = trim($preg['respuesta_correcta'] ?? '');
            $stmt = $db->prepare("INSERT INTO tbl_opcion_respuesta (id_pregunta, texto, es_correcta, orden) VALUES (:preg, :texto, 1, 0)");
            $stmt->execute([':preg' => $id_pregunta, ':texto' => $correcta]);
        } elseif ($tipo === 'relacionar') {
            $izquierda = array_values(array_filter($preg['izquierda'] ?? [], fn($v) => trim($v) !== ''));
            $derecha = array_values(array_filter($preg['derecha'] ?? [], fn($v) => trim($v) !== ''));
            foreach ($izquierda as $i => $elem) {
                $stmt = $db->prepare("INSERT INTO tbl_opcion_respuesta (id_pregunta, texto, es_correcta, orden) VALUES (:preg, :texto, 0, :orden)");
                $stmt->execute([':preg' => $id_pregunta, ':texto' => $elem, ':orden' => $i]);
            }
            foreach ($derecha as $i => $elem) {
                $stmt = $db->prepare("INSERT INTO tbl_opcion_respuesta (id_pregunta, texto, es_correcta, orden) VALUES (:preg, :texto, 1, :orden)");
                $stmt->execute([':preg' => $id_pregunta, ':texto' => $elem, ':orden' => count($izquierda) + $i]);
            }
        }

        $orden++;
    }
}

/**
 * Resuelve y valida la vinculación opcional de una actividad al Cuadro de
 * Notas (tbl_actividad.id_periodo/bloque_notas/numero_nota). $idAsignacion
 * es la asignación REAL de la actividad (nunca la del filtro de la URL).
 * Devuelve ['id_periodo'=>, 'bloque_notas'=>, 'numero_nota'=>] con todo NULL
 * si el profesor eligió "No vincular". Lanza Exception si el período no
 * pertenece a esta institución/año/nivel, si la casilla no es válida para
 * el nivel del grado, o si otra actividad de la misma asignación ya usa
 * esa misma casilla (para no pisarle la nota sin que el profesor lo sepa).
 */
function resolverVinculoCuadroNotas(PDO $db, int $tid, int $idAsignacion, int $anno, string $nivel, ?int $idPeriodoPost, ?string $casillaPost, ?int $idActividadActual): array
{
    $vacio = ['id_periodo' => null, 'bloque_notas' => null, 'numero_nota' => null];

    if (!$idPeriodoPost || !$casillaPost) {
        return $vacio;
    }

    $stmtPer = $db->prepare("SELECT id FROM tbl_periodo WHERE id = :id AND id_institucion = :tid AND anno = :anno AND nivel = :nivel");
    $stmtPer->execute([':id' => $idPeriodoPost, ':tid' => $tid, ':anno' => $anno, ':nivel' => $nivel]);
    if (!$stmtPer->fetch()) {
        throw new Exception('El período elegido no es válido para esta asignación.');
    }

    $casilla = CuadroNotasHelper::resolverCasilla($nivel, $casillaPost);
    if (!$casilla) {
        throw new Exception('La casilla del Cuadro de Notas elegida no es válida para este nivel.');
    }

    $stmtColision = $db->prepare("SELECT titulo FROM tbl_actividad
        WHERE id_asignacion_docente = :asig AND id_periodo = :per AND bloque_notas = :bloque AND numero_nota = :num
        AND id != :actual");
    $stmtColision->execute([
        ':asig' => $idAsignacion,
        ':per' => $idPeriodoPost,
        ':bloque' => $casilla['bloque'],
        ':num' => $casilla['numero_nota'],
        ':actual' => $idActividadActual ?? 0,
    ]);
    $conflicto = $stmtColision->fetchColumn();
    if ($conflicto) {
        throw new Exception("Esa casilla ya la usa la actividad \"$conflicto\". Elige otra casilla o quita el vínculo de esa actividad primero.");
    }

    return ['id_periodo' => $idPeriodoPost, 'bloque_notas' => $casilla['bloque'], 'numero_nota' => $casilla['numero_nota']];
}

// ===== PROCESAR ACCIONES POST =====
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $accion = $_POST['accion'] ?? '';
    
    try {
        $db->beginTransaction();
        
        // === CREAR ACTIVIDAD ===
        if ($accion == 'crear') {
            $id_asignacion = filter_input(INPUT_POST, 'id_asignacion', FILTER_VALIDATE_INT);
            $titulo = trim($_POST['titulo'] ?? '');
            $descripcion = $_POST['descripcion'] ?? '';
            $tipo = $_POST['tipo'] ?? 'tarea';
            $fecha_programada = $_POST['fecha_programada'] ?? date('Y-m-d H:i:s');
            $fecha_limite = $_POST['fecha_limite'] ?: null;
            $duracion_minutos = filter_input(INPUT_POST, 'duracion_minutos', FILTER_VALIDATE_INT) ?: null;
            $nota_maxima = filter_input(INPUT_POST, 'nota_maxima', FILTER_VALIDATE_FLOAT) ?: 10;
            $contenido = $_POST['contenido'] ?? '';
            $url_recurso = filter_var($_POST['url_recurso'] ?? '', FILTER_VALIDATE_URL) ?: null;
            $recursos_url = $_POST['recursos_url'] ?? '';
            $estado = $_POST['estado'] ?? 'programado';

            $id_periodo_post = filter_input(INPUT_POST, 'id_periodo', FILTER_VALIDATE_INT) ?: null;
            $casilla_post = trim($_POST['casilla'] ?? '') ?: null;

            // Verificar que la asignación pertenece al profesor. Se trae
            // también anno + nivel del grado (vía sección) para poder
            // validar la vinculación al Cuadro de Notas más abajo.
            // tbl_asignacion_docente no tiene columna id_institucion;
            // id_profesor ya está tenant-verificado.
            $check = $db->prepare("SELECT ad.id, ad.anno, g.nivel FROM tbl_asignacion_docente ad
                                   JOIN tbl_seccion s ON ad.id_seccion = s.id
                                   JOIN tbl_grado g ON s.id_grado = g.id
                                   WHERE ad.id = :id AND ad.id_profesor = :prof");
            $check->execute([':id' => $id_asignacion, ':prof' => $id_profesor]);
            $asignacionInfo = $check->fetch(PDO::FETCH_ASSOC);

            if ($asignacionInfo && !empty($titulo)) {
                $id_examen = null;

                $vinculo = ['id_periodo' => null, 'bloque_notas' => null, 'numero_nota' => null];
                if (in_array($tipo, ['tarea', 'examen'], true)) {
                    $vinculo = resolverVinculoCuadroNotas($db, $tid, $id_asignacion, (int) $asignacionInfo['anno'], $asignacionInfo['nivel'], $id_periodo_post, $casilla_post, null);
                }

                // Si el tipo es "examen", se crea un examen REAL (mismas
                // tablas que usa el motor autocalificable) y se enlaza a
                // esta actividad — ver helpers arriba.
                if ($tipo === 'examen') {
                    $preguntas = json_decode($_POST['preguntas_json'] ?? '[]', true);
                    if (!is_array($preguntas) || empty($preguntas)) {
                        throw new Exception('Un examen necesita al menos una pregunta');
                    }
                    $stmtExamen = $db->prepare("INSERT INTO tbl_examen
                        (id_asignacion_docente, titulo, descripcion, duracion_minutos, nota_maxima,
                         fecha_programada, fecha_limite, estado)
                        VALUES (:asig, :titulo, :descripcion, :duracion, :nota_maxima, :fecha_prog, :fecha_limite, :estado)");
                    $stmtExamen->execute([
                        ':asig' => $id_asignacion, ':titulo' => $titulo, ':descripcion' => $descripcion,
                        ':duracion' => $duracion_minutos, ':nota_maxima' => $nota_maxima,
                        ':fecha_prog' => $fecha_programada, ':fecha_limite' => $fecha_limite,
                        ':estado' => mapEstadoActividadAExamen($estado)
                    ]);
                    $id_examen = (int) $db->lastInsertId();
                    guardarPreguntasExamen($db, $id_examen, $preguntas);
                }

                // tbl_actividad tampoco tiene columna id_institucion.
                // duracion_minutos es TIME en el esquema real (no INT); se
                // convierte con SEC_TO_TIME desde los minutos del formulario.
                $query = "INSERT INTO tbl_actividad (id_asignacion_docente, id_examen, id_periodo, bloque_notas, numero_nota,
                          titulo, descripcion, tipo,
                          fecha_programada, fecha_limite, duracion_minutos, nota_maxima,
                          contenido, url_recurso, recursos_url, estado)
                          VALUES (:id_asignacion, :id_examen, :id_periodo, :bloque_notas, :numero_nota,
                                  :titulo, :descripcion, :tipo,
                                  :fecha_programada, :fecha_limite, SEC_TO_TIME(:duracion * 60), :nota_maxima,
                                  :contenido, :url_recurso, :recursos, :estado)";
                $stmt = $db->prepare($query);
                $stmt->execute([
                    ':id_asignacion' => $id_asignacion,
                    ':id_examen' => $id_examen,
                    ':id_periodo' => $vinculo['id_periodo'],
                    ':bloque_notas' => $vinculo['bloque_notas'],
                    ':numero_nota' => $vinculo['numero_nota'],
                    ':titulo' => $titulo,
                    ':descripcion' => $descripcion,
                    ':tipo' => $tipo,
                    ':fecha_programada' => $fecha_programada,
                    ':fecha_limite' => $fecha_limite,
                    ':duracion' => $duracion_minutos,
                    ':nota_maxima' => $nota_maxima,
                    ':contenido' => $contenido,
                    ':url_recurso' => $url_recurso,
                    ':recursos' => $recursos_url,
                    ':estado' => $estado
                ]);

                $db->commit();
                $mensaje = 'Actividad creada exitosamente';
                $tipo_mensaje = 'success';
            } else {
                throw new Exception("Datos inválidos o no tiene permiso para esta asignación");
            }
            
        } elseif ($accion == 'actualizar') {
            $id_actividad = filter_input(INPUT_POST, 'id_actividad', FILTER_VALIDATE_INT);
            $id_asignacion = filter_input(INPUT_POST, 'id_asignacion', FILTER_VALIDATE_INT);
            $titulo = trim($_POST['titulo'] ?? '');
            $descripcion = $_POST['descripcion'] ?? '';
            $tipo = $_POST['tipo'] ?? 'tarea';
            $fecha_programada = $_POST['fecha_programada'] ?? date('Y-m-d H:i:s');
            $fecha_limite = $_POST['fecha_limite'] ?: null;
            $duracion_minutos = filter_input(INPUT_POST, 'duracion_minutos', FILTER_VALIDATE_INT) ?: null;
            $nota_maxima = filter_input(INPUT_POST, 'nota_maxima', FILTER_VALIDATE_FLOAT) ?: 10;
            $contenido = $_POST['contenido'] ?? '';
            $url_recurso = filter_var($_POST['url_recurso'] ?? '', FILTER_VALIDATE_URL) ?: null;
            $recursos_url = $_POST['recursos_url'] ?? '';
            $estado = $_POST['estado'] ?? 'programado';

            $id_periodo_post = filter_input(INPUT_POST, 'id_periodo', FILTER_VALIDATE_INT) ?: null;
            $casilla_post = trim($_POST['casilla'] ?? '') ?: null;

            // Verificar propiedad. tbl_actividad no tiene columna
            // id_institucion; ad.id_profesor ya está tenant-verificado.
            // Se trae también id_examen (si ya había uno enlazado) para
            // decidir si hay que crearlo, actualizarlo, o desvincularlo, y
            // anno + nivel (vía sección/grado) para validar la vinculación
            // al Cuadro de Notas.
            $check = $db->prepare("SELECT a.id, a.id_examen, a.id_asignacion_docente, ad.anno, g.nivel FROM tbl_actividad a
                                  JOIN tbl_asignacion_docente ad ON a.id_asignacion_docente = ad.id
                                  JOIN tbl_seccion s ON ad.id_seccion = s.id
                                  JOIN tbl_grado g ON s.id_grado = g.id
                                  WHERE a.id = :id AND ad.id_profesor = :prof");
            $check->execute([':id' => $id_actividad, ':prof' => $id_profesor]);
            $actividadActual = $check->fetch(PDO::FETCH_ASSOC);
            // La asignación real de la actividad (no la del filtro de la URL,
            // que podría no coincidir si el profesor cambió de filtro entre
            // que abrió la lista y editó una actividad de otra asignación).
            // El nivel real de ESA asignación es el que se usa para validar
            // la casilla del Cuadro de Notas, nunca el de la URL.
            if ($actividadActual) {
                $id_asignacion = (int) $actividadActual['id_asignacion_docente'];
            }

            if ($actividadActual && !empty($titulo)) {
                $id_examen = $actividadActual['id_examen'] ?: null;

                $vinculo = ['id_periodo' => null, 'bloque_notas' => null, 'numero_nota' => null];
                if (in_array($tipo, ['tarea', 'examen'], true)) {
                    $vinculo = resolverVinculoCuadroNotas($db, $tid, $id_asignacion, (int) $actividadActual['anno'], $actividadActual['nivel'], $id_periodo_post, $casilla_post, $id_actividad);
                }

                if ($tipo === 'examen') {
                    $preguntas = json_decode($_POST['preguntas_json'] ?? '[]', true);
                    if (!is_array($preguntas) || empty($preguntas)) {
                        throw new Exception('Un examen necesita al menos una pregunta');
                    }
                    if ($id_examen) {
                        // Ya había un examen enlazado: actualizar y reemplazar sus preguntas.
                        $stmtExamen = $db->prepare("UPDATE tbl_examen SET
                            titulo = :titulo, descripcion = :descripcion, duracion_minutos = :duracion,
                            nota_maxima = :nota_maxima, fecha_programada = :fecha_prog,
                            fecha_limite = :fecha_limite, estado = :estado
                            WHERE id = :id");
                        $stmtExamen->execute([
                            ':titulo' => $titulo, ':descripcion' => $descripcion, ':duracion' => $duracion_minutos,
                            ':nota_maxima' => $nota_maxima, ':fecha_prog' => $fecha_programada,
                            ':fecha_limite' => $fecha_limite, ':estado' => mapEstadoActividadAExamen($estado),
                            ':id' => $id_examen
                        ]);
                        $db->prepare("DELETE FROM tbl_pregunta_examen WHERE id_examen = :id")->execute([':id' => $id_examen]);
                    } else {
                        // La actividad no tenía examen enlazado todavía (p.ej. cambió de
                        // tipo "tarea" a "examen" al editar): crear uno nuevo.
                        $stmtExamen = $db->prepare("INSERT INTO tbl_examen
                            (id_asignacion_docente, titulo, descripcion, duracion_minutos, nota_maxima,
                             fecha_programada, fecha_limite, estado)
                            VALUES (:asig, :titulo, :descripcion, :duracion, :nota_maxima, :fecha_prog, :fecha_limite, :estado)");
                        $stmtExamen->execute([
                            ':asig' => $id_asignacion, ':titulo' => $titulo, ':descripcion' => $descripcion,
                            ':duracion' => $duracion_minutos, ':nota_maxima' => $nota_maxima,
                            ':fecha_prog' => $fecha_programada, ':fecha_limite' => $fecha_limite,
                            ':estado' => mapEstadoActividadAExamen($estado)
                        ]);
                        $id_examen = (int) $db->lastInsertId();
                    }
                    guardarPreguntasExamen($db, $id_examen, $preguntas);
                } else {
                    // Ya no es tipo "examen": se desvincula (el examen real, si
                    // existía y ya tiene intentos de estudiantes, no se borra).
                    $id_examen = null;
                }

                $query = "UPDATE tbl_actividad SET
                          id_examen = :id_examen, id_periodo = :id_periodo, bloque_notas = :bloque_notas, numero_nota = :numero_nota,
                          titulo = :titulo, descripcion = :descripcion, tipo = :tipo,
                          fecha_programada = :fecha_programada, fecha_limite = :fecha_limite,
                          duracion_minutos = SEC_TO_TIME(:duracion * 60), nota_maxima = :nota_maxima,
                          contenido = :contenido, url_recurso = :url_recurso,
                          recursos_url = :recursos, estado = :estado
                          WHERE id = :id";
                $stmt = $db->prepare($query);
                $stmt->execute([
                    ':id' => $id_actividad,
                    ':id_examen' => $id_examen,
                    ':id_periodo' => $vinculo['id_periodo'],
                    ':bloque_notas' => $vinculo['bloque_notas'],
                    ':numero_nota' => $vinculo['numero_nota'],
                    ':titulo' => $titulo,
                    ':descripcion' => $descripcion,
                    ':tipo' => $tipo,
                    ':fecha_programada' => $fecha_programada,
                    ':fecha_limite' => $fecha_limite,
                    ':duracion' => $duracion_minutos,
                    ':nota_maxima' => $nota_maxima,
                    ':contenido' => $contenido,
                    ':url_recurso' => $url_recurso,
                    ':recursos' => $recursos_url,
                    ':estado' => $estado
                ]);

                $db->commit();
                $mensaje = 'Actividad actualizada exitosamente';
                $tipo_mensaje = 'success';
            } else {
                throw new Exception("No tiene permiso para editar esta actividad");
            }
            
        } elseif ($accion == 'eliminar') {
            $id_actividad = filter_input(INPUT_POST, 'id_actividad', FILTER_VALIDATE_INT);
            
            // Verificar propiedad y eliminar en cascada. Ni tbl_actividad ni
            // tbl_entrega_actividad tienen columna id_institucion.
            $check = $db->prepare("SELECT a.id FROM tbl_actividad a
                                  JOIN tbl_asignacion_docente ad ON a.id_asignacion_docente = ad.id
                                  WHERE a.id = :id AND ad.id_profesor = :prof");
            $check->execute([':id' => $id_actividad, ':prof' => $id_profesor]);

            if ($check->rowCount() > 0) {
                // 1. Eliminar entregas relacionadas primero
                $db->prepare("DELETE FROM tbl_entrega_actividad WHERE id_actividad = :id")
                   ->execute([':id' => $id_actividad]);

                // 2. Eliminar la actividad
                $db->prepare("DELETE FROM tbl_actividad WHERE id = :id")
                   ->execute([':id' => $id_actividad]);
                
                $db->commit();
                $mensaje = 'Actividad eliminada exitosamente';
                $tipo_mensaje = 'warning';
            } else {
                throw new Exception("No tiene permiso para eliminar esta actividad");
            }
        }
        
    } catch (Exception $e) {
        $db->rollBack();
        error_log("Error en gestionar_actividades.php: " . $e->getMessage());
        $mensaje = 'Error: ' . $e->getMessage();
        $tipo_mensaje = 'danger';
    }
}

// ===== OBTENER ASIGNACIONES DEL PROFESOR =====
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

// ===== FILTROS =====
$id_asignacion_filtro = $_GET['asignacion'] ?? ($asignaciones[0]['id'] ?? 0);
$filtro_tipo = $_GET['tipo'] ?? 'todos';
$filtro_estado = $_GET['estado'] ?? 'todos';
$busqueda = $_GET['busqueda'] ?? '';

// ===== VINCULACIÓN AL CUADRO DE NOTAS (para el modal Nueva/Editar Actividad) =====
// El modal siempre crea/edita actividades de $id_asignacion_filtro (la
// asignación actualmente seleccionada arriba), así que basta con calcular
// UNA vez el nivel/período de esa asignación -- no hace falta JS con datos
// de todas las asignaciones. Ver resolverVinculoCuadroNotas() arriba, que sí
// revalida contra la asignación REAL de la actividad al guardar.
$asignacion_filtro_info = null;
foreach ($asignaciones as $a) {
    if ((int) $a['id'] === (int) $id_asignacion_filtro) { $asignacion_filtro_info = $a; break; }
}
$periodos_cuadro = [];
$casillas_cuadro = [];
if ($asignacion_filtro_info) {
    PeriodoHelper::asegurar($db, $tid, (int) $asignacion_filtro_info['anno']);
    $stmtPerCN = $db->prepare("SELECT id, numero, nombre FROM tbl_periodo WHERE id_institucion = :tid AND anno = :anno AND nivel = :nivel ORDER BY numero");
    $stmtPerCN->execute([':tid' => $tid, ':anno' => $asignacion_filtro_info['anno'], ':nivel' => $asignacion_filtro_info['nivel_grado']]);
    $periodos_cuadro = $stmtPerCN->fetchAll(PDO::FETCH_ASSOC);
    $casillas_cuadro = CuadroNotasHelper::casillasDisponibles($asignacion_filtro_info['nivel_grado']);
}

// ===== OBTENER ACTIVIDADES =====
$actividades = [];

if ($id_asignacion_filtro) {
    // ✅ CORREGIDO: Usar ea.id en lugar de ea.id_estudiante
    // tbl_actividad no tiene columna id_institucion; se agrega el join a
    // tbl_asignacion_docente/tbl_asignatura para el aislamiento por tenant,
    // y ad.id_profesor para que un profesor no pueda ver actividades de una
    // asignación que no es suya con solo cambiar el id en la URL.
    // duracion_minutos es TIME en el esquema real; se convierte a minutos.
    // Para actividades tipo "examen" los intentos/notas viven en
    // tbl_intento_examen (motor real de exámenes), no en
    // tbl_entrega_actividad — se traen con subconsultas correlacionadas
    // (evita multiplicar filas si se combinara con un JOIN directo) y se
    // suman en PHP más abajo, ya que una actividad nunca tiene ambas cosas
    // a la vez.
    $query_act = "SELECT a.id, a.id_examen, a.id_periodo, a.bloque_notas, a.numero_nota, a.titulo, a.descripcion, a.tipo, a.contenido, a.url_recurso,
                 a.fecha_programada, a.fecha_limite, TIME_TO_SEC(a.duracion_minutos)/60 as duracion_minutos, a.nota_maxima, a.estado,
                 COUNT(ea.id) as total_entregas,
                 AVG(ea.nota_obtenida) as promedio_notas,
                 (SELECT COUNT(*) FROM tbl_intento_examen ie WHERE ie.id_examen = a.id_examen) as total_intentos_examen,
                 (SELECT AVG(ie.puntaje_obtenido) FROM tbl_intento_examen ie WHERE ie.id_examen = a.id_examen AND ie.estado = 'calificado') as promedio_examen
                 FROM tbl_actividad a
                 JOIN tbl_asignacion_docente ad ON a.id_asignacion_docente = ad.id
                 JOIN tbl_asignatura asig ON ad.id_asignatura = asig.id
                 LEFT JOIN tbl_entrega_actividad ea ON a.id = ea.id_actividad
                 WHERE a.id_asignacion_docente = :id_asignacion AND ad.id_profesor = :id_profesor AND asig.id_institucion = :tid";

    $params = [':id_asignacion' => $id_asignacion_filtro, ':id_profesor' => $id_profesor, ':tid' => $tid];
    
    if ($filtro_tipo != 'todos') {
        $query_act .= " AND a.tipo = :tipo";
        $params[':tipo'] = $filtro_tipo;
    }
    
    if ($filtro_estado != 'todos') {
        $query_act .= " AND a.estado = :estado";
        $params[':estado'] = $filtro_estado;
    }
    
    if (!empty($busqueda)) {
        $query_act .= " AND (a.titulo LIKE :busqueda OR a.descripcion LIKE :busqueda)";
        $params[':busqueda'] = "%$busqueda%";
    }
    
    $query_act .= " GROUP BY a.id ORDER BY a.fecha_programada DESC";
    
    $stmt_act = $db->prepare($query_act);
    foreach ($params as $key => $value) {
        $stmt_act->bindValue($key, $value);
    }
    $stmt_act->execute();
    $actividades = $stmt_act->fetchAll(PDO::FETCH_ASSOC);
}

// ===== PREGUNTAS DE LOS EXÁMENES ENLAZADOS (para poblar el modal al editar) =====
// Se cargan en lote (no una consulta por actividad) y se embeben como JSON
// en la página; el mismo patrón usado en banco_preguntas.php.
$preguntas_por_examen = [];
$ids_examen = array_filter(array_column($actividades, 'id_examen'));
if ($ids_examen) {
    $in = implode(',', array_fill(0, count($ids_examen), '?'));
    $stmtPreg = $db->prepare("SELECT * FROM tbl_pregunta_examen WHERE id_examen IN ($in) ORDER BY numero_orden");
    $stmtPreg->execute(array_values($ids_examen));
    $preguntas_raw = $stmtPreg->fetchAll(PDO::FETCH_ASSOC);

    $ids_pregunta = array_column($preguntas_raw, 'id');
    $opciones_por_pregunta = [];
    if ($ids_pregunta) {
        $in2 = implode(',', array_fill(0, count($ids_pregunta), '?'));
        $stmtOp = $db->prepare("SELECT * FROM tbl_opcion_respuesta WHERE id_pregunta IN ($in2) ORDER BY orden");
        $stmtOp->execute($ids_pregunta);
        foreach ($stmtOp->fetchAll(PDO::FETCH_ASSOC) as $op) {
            $opciones_por_pregunta[$op['id_pregunta']][] = $op;
        }
    }

    foreach ($preguntas_raw as $preg) {
        $opciones = $opciones_por_pregunta[$preg['id']] ?? [];
        $item = [
            'tipo' => $preg['tipo'],
            'enunciado' => $preg['enunciado'],
            'puntaje' => $preg['puntaje'],
        ];
        if ($preg['tipo'] === 'opcion_multiple') {
            $item['opciones'] = array_map(fn($o) => ['texto' => $o['texto'], 'es_correcta' => (int) $o['es_correcta']], $opciones);
        } elseif ($preg['tipo'] === 'verdadero_falso') {
            $correcta = 'V';
            foreach ($opciones as $o) {
                if ($o['texto'] === 'Falso' && $o['es_correcta']) $correcta = 'F';
            }
            $item['correcta_vf'] = $correcta;
        } elseif ($preg['tipo'] === 'respuesta_corta') {
            $item['respuesta_correcta'] = $opciones[0]['texto'] ?? '';
        } elseif ($preg['tipo'] === 'relacionar') {
            $item['izquierda'] = array_column(array_filter($opciones, fn($o) => !$o['es_correcta']), 'texto');
            $item['derecha'] = array_column(array_filter($opciones, fn($o) => $o['es_correcta']), 'texto');
        }
        $preguntas_por_examen[$preg['id_examen']][] = $item;
    }
}
// Mapa actividad.id -> arreglo de preguntas (vacío si no es examen o no tiene id_examen)
$preguntas_por_actividad = [];
foreach ($actividades as $act) {
    if (!empty($act['id_examen'])) {
        $preguntas_por_actividad[$act['id']] = $preguntas_por_examen[$act['id_examen']] ?? [];
    }
}

// Tipos de actividad
$tipos_actividad = [
    'tarea' => ['label' => '📝 Tarea', 'icon' => 'fa-clipboard-list', 'color' => 'warning'],
    'examen' => ['label' => '📋 Examen', 'icon' => 'fa-file-alt', 'color' => 'danger'],
    'video' => ['label' => '🎬 Video', 'icon' => 'fa-video', 'color' => 'info'],
    'youtube' => ['label' => '📺 YouTube', 'icon' => 'fa-youtube', 'color' => 'danger'],
    'articulo' => ['label' => '📄 Artículo', 'icon' => 'fa-book-open', 'color' => 'primary'],
    'referencia' => ['label' => '📚 Referencia', 'icon' => 'fa-book', 'color' => 'purple'],
    'podcast' => ['label' => '🎧 Podcast', 'icon' => 'fa-podcast', 'color' => 'success'],
    'revista' => ['label' => '📰 Revista', 'icon' => 'fa-newspaper', 'color' => 'teal'],
    'enlace' => ['label' => '🔗 Enlace', 'icon' => 'fa-link', 'color' => 'secondary']
];

$estados_actividad = [
    'programado' => ['label' => 'Programado', 'class' => 'bg-secondary'],
    'publicado' => ['label' => 'Publicado', 'class' => 'bg-success'],
    'activo' => ['label' => 'Activo', 'class' => 'bg-primary'],
    'cerrado' => ['label' => 'Cerrado', 'class' => 'bg-dark'],
    'eliminado' => ['label' => 'Eliminado', 'class' => 'bg-light text-muted']
];
$activePage = 'actividades';
$pageTitle = 'Gestionar Actividades - Educación Plus';
ob_start();
?>
<style>
    .card-custom { background: white; border-radius: 12px; box-shadow: 0 2px 12px rgba(0,0,0,0.08); border: none; margin-bottom: 20px; }
    .activity-card { border-left: 4px solid var(--secondary); transition: all 0.2s; }
    .activity-card:hover { transform: translateY(-3px); box-shadow: 0 6px 20px rgba(0,0,0,0.12); }
    .activity-card.tipo-tarea { border-left-color: var(--warning); }
    .activity-card.tipo-examen { border-left-color: var(--danger); }
    .activity-card.tipo-video { border-left-color: var(--info); }
    .badge-actividad { padding: 6px 14px; border-radius: 20px; font-size: 0.85rem; font-weight: 600; }
    /* #modalActividad envuelve modal-body y modal-footer dentro de un <form>
       para poder enviarlos juntos en un solo submit. Un <form> normal es un
       bloque comun (display:block), asi que el "flex: 1 1 auto" que Bootstrap
       le pone a .modal-body no tenia ningun efecto (su padre real no era flex),
       y por eso el cuerpo del modal crecia a su alto natural, empujando el
       footer (Cancelar/Guardar) fuera de la pantalla y sin scroll interno.
       Convertimos el <form> en el contenedor flex-column real. */
    #modalActividad .modal-content { display: flex; flex-direction: column; }
    #modalActividad form#formActividad { display: flex; flex-direction: column; flex: 1 1 auto; min-height: 0; }
    #modalActividad .modal-body { flex: 1 1 auto; min-height: 0; overflow-y: auto; }
    #modalActividad .modal-footer { flex-shrink: 0; }
</style>
<?php
$extraHead = ob_get_clean();
require __DIR__ . '/partials/header.php';
?>
        <!-- Header -->
        <div class="d-flex justify-content-between align-items-center mb-4">
            <div>
                <h2><i class="fas fa-tasks"></i> Gestionar Actividades</h2>
                <p class="text-muted mb-0">Crear, editar y organizar actividades para tus clases</p>
            </div>
            <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#modalActividad" onclick="prepararModalCrear()">
                <i class="fas fa-plus"></i> Nueva Actividad
            </button>
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
        
        <!-- Filtros -->
        <div class="card-custom p-3 mb-4">
            <form method="GET" class="row g-3">
                <input type="hidden" name="asignacion" value="<?= $id_asignacion_filtro ?>">
                <div class="col-md-3">
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
                    <label class="form-label small text-muted">Tipo</label>
                    <select name="tipo" class="form-select" onchange="this.form.submit()">
                        <option value="todos" <?= $filtro_tipo == 'todos' ? 'selected' : '' ?>>Todos</option>
                        <?php foreach ($tipos_actividad as $key => $tipo): ?>
                        <option value="<?= $key ?>" <?= $filtro_tipo == $key ? 'selected' : '' ?>><?= $tipo['label'] ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label small text-muted">Estado</label>
                    <select name="estado" class="form-select" onchange="this.form.submit()">
                        <option value="todos" <?= $filtro_estado == 'todos' ? 'selected' : '' ?>>Todos</option>
                        <?php foreach ($estados_actividad as $key => $estado): ?>
                        <option value="<?= $key ?>" <?= $filtro_estado == $key ? 'selected' : '' ?>><?= $estado['label'] ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label small text-muted">Buscar</label>
                    <div class="input-group">
                        <input type="text" name="busqueda" class="form-control" placeholder="Título..." value="<?= htmlspecialchars($busqueda) ?>">
                        <button type="submit" class="btn btn-primary"><i class="fas fa-search"></i></button>
                    </div>
                </div>
            </form>
        </div>

        <!-- Lista de Actividades -->
        <?php if (empty($actividades)): ?>
        <div class="card-custom p-5 text-center">
            <i class="fas fa-clipboard-list fa-4x text-muted mb-3"></i>
            <h5>No hay actividades en esta asignación</h5>
            <p class="text-muted">Comienza creando tu primera actividad</p>
            <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#modalActividad" onclick="prepararModalCrear()">
                <i class="fas fa-plus"></i> Crear Primera Actividad
            </button>
        </div>
        <?php else: ?>
        <div class="row g-3">
            <?php foreach ($actividades as $act):
                $tipo = $tipos_actividad[$act['tipo']] ?? ['label' => $act['tipo'], 'icon' => 'fa-file', 'color' => 'secondary'];
                $estado = $estados_actividad[$act['estado']] ?? ['label' => $act['estado'], 'class' => 'bg-secondary'];
                // Una actividad tipo "examen" registra sus intentos en
                // tbl_intento_examen, no en tbl_entrega_actividad — nunca
                // tiene ambas cosas a la vez, así que sumarlas es seguro.
                $total_entregas = (int) $act['total_entregas'] + (int) ($act['total_intentos_examen'] ?? 0);
                $promedio = $act['promedio_notas'] ?? $act['promedio_examen'] ?? null;
            ?>
            <div class="col-lg-6">
                <div class="card-custom activity-card tipo-<?= htmlspecialchars($act['tipo']) ?> p-3">
                    <div class="d-flex justify-content-between align-items-start mb-2">
                        <span class="badge-actividad bg-<?= $tipo['color'] ?> text-white">
                            <i class="fas <?= $tipo['icon'] ?>"></i> <?= $tipo['label'] ?>
                        </span>
                        <div class="dropdown">
                            <button class="btn btn-sm btn-light" data-bs-toggle="dropdown"><i class="fas fa-ellipsis-v"></i></button>
                            <ul class="dropdown-menu dropdown-menu-end">
                                <li><a class="dropdown-item" href="#" onclick="editarActividad(<?= htmlspecialchars(json_encode($act)) ?>, <?= htmlspecialchars(json_encode($preguntas_por_actividad[$act['id']] ?? [])) ?>)"><i class="fas fa-edit"></i> Editar</a></li>
                                <li><a class="dropdown-item" href="calificaciones.php?actividad=<?= $act['id'] ?>"><i class="fas fa-star"></i> Calificar</a></li>
                                <li><hr class="dropdown-divider"></li>
                                <li><a class="dropdown-item text-danger" href="#" onclick="eliminarActividad(<?= $act['id'] ?>)"><i class="fas fa-trash"></i> Eliminar</a></li>
                            </ul>
                        </div>
                    </div>
                    <h6 class="mb-2"><?= htmlspecialchars($act['titulo']) ?></h6>
                    <?php if ($act['descripcion']): ?>
                    <p class="text-muted small mb-2"><?= nl2br(htmlspecialchars(substr($act['descripcion'], 0, 100))) ?><?= strlen($act['descripcion']) > 100 ? '...' : '' ?></p>
                    <?php endif; ?>
                    <div class="d-flex justify-content-between align-items-center small text-muted">
                        <span><i class="fas fa-calendar"></i> <?= date('d/m/Y', strtotime($act['fecha_programada'])) ?></span>
                        <?php if ($act['fecha_limite']): ?>
                        <span><i class="fas fa-clock"></i> <?= date('d/m', strtotime($act['fecha_limite'])) ?></span>
                        <?php endif; ?>
                        <span class="badge <?= $estado['class'] ?>"><?= $estado['label'] ?></span>
                    </div>
                    <?php if ($total_entregas > 0): ?>
                    <div class="mt-2 pt-2 border-top small">
                        <i class="fas fa-inbox text-primary"></i> <?= $total_entregas ?> <?= $act['tipo'] === 'examen' ? 'intentos' : 'entregas' ?>
                        <?php if ($promedio !== null): ?>
                        • 📊 <?= number_format($promedio, 2) ?>/<?= $act['nota_maxima'] ?>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
        <?php endif; ?>
    </div>

    <!-- Modal Crear/Editar Actividad -->
    <div class="modal fade" id="modalActividad" tabindex="-1">
        <div class="modal-dialog modal-xl modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title" id="modalTitle"><i class="fas fa-plus"></i> Nueva Actividad</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" id="formActividad">
                    <div class="modal-body">
                        <input type="hidden" name="accion" id="accion" value="crear">
                        <input type="hidden" name="id_actividad" id="id_actividad">
                        <input type="hidden" name="id_asignacion" value="<?= $id_asignacion_filtro ?>">
                        <input type="hidden" name="preguntas_json" id="preguntas_json">

                        <div class="row g-3">
                            <div class="col-md-8">
                                <label class="form-label">Título *</label>
                                <input type="text" name="titulo" id="titulo" class="form-control" required placeholder="Título de la actividad">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Tipo *</label>
                                <select name="tipo" id="tipo" class="form-select" required onchange="mostrarCamposTipo()">
                                    <?php foreach ($tipos_actividad as $key => $tipo): ?>
                                    <option value="<?= $key ?>"><?= $tipo['label'] ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-12">
                                <label class="form-label">Descripción</label>
                                <textarea name="descripcion" id="descripcion" class="form-control" rows="3" placeholder="Instrucciones o descripción..."></textarea>
                            </div>

                            <!-- Campos dinámicos según tipo -->
                            <div id="campo_url" class="col-12 d-none">
                                <label class="form-label" id="label_url">URL del Recurso</label>
                                <input type="url" name="url_recurso" id="url_recurso" class="form-control" placeholder="https://...">
                                <small class="text-muted" id="help_url">Enlace al video, artículo, etc.</small>
                            </div>
                            <div id="campo_contenido" class="col-12 d-none">
                                <label class="form-label">Contenido</label>
                                <textarea name="contenido" id="contenido" class="form-control" rows="4" placeholder="Texto del artículo, referencia, etc."></textarea>
                            </div>

                            <!-- Generador de preguntas (sólo tipo = examen) -->
                            <div id="bloque_examen" class="col-12 d-none">
                                <hr>
                                <div class="d-flex justify-content-between align-items-center mb-2">
                                    <label class="form-label mb-0"><i class="fas fa-list-ol"></i> Preguntas del examen *</label>
                                    <button type="button" class="btn btn-sm btn-outline-primary" onclick="agregarPreguntaExamen()">
                                        <i class="fas fa-plus"></i> Agregar pregunta
                                    </button>
                                </div>
                                <small class="text-muted d-block mb-2">
                                    Modelos autocalificables disponibles: opción múltiple, verdadero/falso, completar espacios,
                                    relacionar columnas y respuesta corta. No incluye preguntas abiertas (ensayo).
                                </small>
                                <div id="preguntas_examen_container"></div>
                                <div id="preguntas_examen_vacio" class="text-center text-muted py-4 border rounded bg-light">
                                    <i class="fas fa-inbox fa-2x mb-2 d-block"></i> Todavía no hay preguntas. Agrega al menos una con el botón de arriba.
                                </div>
                                <hr>
                            </div>

                            <div class="col-md-4">
                                <label class="form-label">Fecha Programada</label>
                                <input type="datetime-local" name="fecha_programada" id="fecha_programada" class="form-control" value="<?= date('Y-m-d\TH:i') ?>">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Fecha Límite</label>
                                <input type="datetime-local" name="fecha_limite" id="fecha_limite" class="form-control">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Duración (min)</label>
                                <input type="number" name="duracion_minutos" id="duracion_minutos" class="form-control" min="1" placeholder="Opcional">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Nota Máxima</label>
                                <input type="number" name="nota_maxima" id="nota_maxima" class="form-control" value="10" min="0" max="100" step="0.1">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Estado</label>
                                <select name="estado" id="estado" class="form-select">
                                    <option value="programado">Programado</option>
                                    <option value="publicado" selected>Publicado</option>
                                    <option value="activo">Activo</option>
                                    <option value="cerrado">Cerrado</option>
                                </select>
                            </div>

                            <!-- Vinculación al Cuadro de Notas (solo tarea/examen) -->
                            <div id="bloque_cuadro_notas" class="col-12 d-none">
                                <hr>
                                <label class="form-label mb-1"><i class="fas fa-clipboard-list"></i> Vincular al Cuadro de Notas</label>
                                <small class="text-muted d-block mb-2">
                                    Opcional. Si eliges un período y una casilla, la nota final de esta actividad se
                                    copiará automáticamente a esa casilla del Cuadro de Notas (podrás seguir
                                    ajustándola a mano ahí si hace falta).
                                </small>
                                <?php if (empty($periodos_cuadro)): ?>
                                <p class="text-muted small mb-0">No hay períodos configurados todavía para esta asignación.</p>
                                <?php else: ?>
                                <div class="row g-2">
                                    <div class="col-md-6">
                                        <label class="form-label small">Período</label>
                                        <select name="id_periodo" id="cn_id_periodo" class="form-select form-select-sm">
                                            <option value="">No vincular</option>
                                            <?php foreach ($periodos_cuadro as $p): ?>
                                            <option value="<?= $p['id'] ?>"><?= htmlspecialchars($p['nombre']) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label small">Casilla</label>
                                        <select name="casilla" id="cn_casilla" class="form-select form-select-sm">
                                            <option value="">No vincular</option>
                                            <?php foreach ($casillas_cuadro as $c): ?>
                                            <option value="<?= htmlspecialchars($c['valor']) ?>"><?= htmlspecialchars($c['label']) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                        <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Guardar</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Scripts -->
    <?php require __DIR__ . '/partials/scripts.php'; ?>

    <script>
        function prepararModalCrear() {
            document.getElementById('accion').value = 'crear';
            document.getElementById('id_actividad').value = '';
            document.getElementById('modalTitle').innerHTML = '<i class="fas fa-plus"></i> Nueva Actividad';
            document.getElementById('formActividad').reset();
            document.getElementById('fecha_programada').value = new Date().toISOString().slice(0,16);
            vaciarPreguntasExamen();
            mostrarCamposTipo();
        }

        // Reconstruye el string de <option> de #cn_casilla ('n3', 'b1n2',
        // 'b2n4', 'examen') a partir de bloque_notas/numero_nota, que es
        // como vienen guardados en la base de datos.
        function casillaValorDesdeActividad(act) {
            if (!act.bloque_notas || !act.numero_nota) return '';
            if (act.bloque_notas === 'unico') return 'n' + act.numero_nota;
            if (act.bloque_notas === 'bloque1') return 'b1n' + act.numero_nota;
            if (act.bloque_notas === 'bloque2') return 'b2n' + act.numero_nota;
            if (act.bloque_notas === 'examen') return 'examen';
            return '';
        }

        function editarActividad(act, preguntas) {
            document.getElementById('accion').value = 'actualizar';
            document.getElementById('id_actividad').value = act.id;
            document.getElementById('modalTitle').innerHTML = '<i class="fas fa-edit"></i> Editar Actividad';
            document.getElementById('titulo').value = act.titulo;
            document.getElementById('tipo').value = act.tipo;
            document.getElementById('descripcion').value = act.descripcion || '';
            document.getElementById('fecha_programada').value = act.fecha_programada ? act.fecha_programada.slice(0,16) : '';
            document.getElementById('fecha_limite').value = act.fecha_limite ? act.fecha_limite.slice(0,16) : '';
            document.getElementById('duracion_minutos').value = act.duracion_minutos || '';
            document.getElementById('nota_maxima').value = act.nota_maxima || 10;
            document.getElementById('contenido').value = act.contenido || '';
            document.getElementById('url_recurso').value = act.url_recurso || '';
            document.getElementById('estado').value = act.estado || 'publicado';

            // Vínculo al Cuadro de Notas -- solo tiene sentido preseleccionarlo
            // si la actividad pertenece a LA MISMA asignación cuyo período/
            // casillas se renderizaron en este modal (la del filtro actual).
            // Si no coincide, los <select> simplemente no tendrán esa opción
            // y quedan en "No vincular"; el servidor revalida de todos modos.
            const elPeriodo = document.getElementById('cn_id_periodo');
            const elCasilla = document.getElementById('cn_casilla');
            if (elPeriodo) elPeriodo.value = act.id_periodo || '';
            if (elCasilla) elCasilla.value = casillaValorDesdeActividad(act);

            vaciarPreguntasExamen();
            if (act.tipo === 'examen' && Array.isArray(preguntas)) {
                preguntas.forEach(p => agregarPreguntaExamen(p));
            }

            mostrarCamposTipo();
            new bootstrap.Modal(document.getElementById('modalActividad')).show();
        }

        function mostrarCamposTipo() {
            const tipo = document.getElementById('tipo').value;
            const campoUrl = document.getElementById('campo_url');
            const campoContenido = document.getElementById('campo_contenido');
            const bloqueExamen = document.getElementById('bloque_examen');
            const bloqueCuadroNotas = document.getElementById('bloque_cuadro_notas');
            const labelUrl = document.getElementById('label_url');
            const helpUrl = document.getElementById('help_url');

            campoUrl.classList.add('d-none');
            campoContenido.classList.add('d-none');
            bloqueExamen.classList.add('d-none');
            // Solo tarea y examen producen una nota que tenga sentido llevar
            // al Cuadro de Notas (los demás tipos son material de consulta).
            bloqueCuadroNotas.classList.toggle('d-none', !['tarea', 'examen'].includes(tipo));

            if (['youtube', 'video', 'enlace', 'podcast'].includes(tipo)) {
                campoUrl.classList.remove('d-none');
                if (tipo === 'youtube') {
                    labelUrl.textContent = 'Enlace de YouTube';
                    helpUrl.textContent = 'https://www.youtube.com/watch?v=...';
                } else if (tipo === 'video') {
                    labelUrl.textContent = 'URL del Video';
                    helpUrl.textContent = 'Enlace directo al archivo MP4';
                } else {
                    labelUrl.textContent = 'URL del Recurso';
                    helpUrl.textContent = 'Enlace al recurso externo';
                }
            }
            if (['articulo', 'referencia', 'revista'].includes(tipo)) {
                campoContenido.classList.remove('d-none');
            }
            if (tipo === 'examen') {
                bloqueExamen.classList.remove('d-none');
                if (document.querySelectorAll('#preguntas_examen_container .pregunta-examen-item').length === 0) {
                    agregarPreguntaExamen();
                }
            }
        }

        function eliminarActividad(id) {
            if (confirm('¿Está seguro de eliminar esta actividad? Se eliminarán también las entregas de los estudiantes. (Si es un examen ya tomado por estudiantes, el examen y sus notas NO se borran, sólo se quita del tablón.)')) {
                const form = document.createElement('form');
                form.method = 'POST';
                form.innerHTML = `<input type="hidden" name="accion" value="eliminar"><input type="hidden" name="id_actividad" value="${id}">`;
                document.body.appendChild(form);
                form.submit();
            }
        }

        // ===== Generador de preguntas del examen (dentro del modal) =====
        // Cada bloque de pregunta es independiente (sin IDs únicos por
        // índice); las funciones ubican su propio bloque con closest(), así
        // que agregar/quitar preguntas no requiere renumerar nada salvo la
        // etiqueta visual "Pregunta N".
        let contadorPreguntaExamen = 0;

        function vaciarPreguntasExamen() {
            document.getElementById('preguntas_examen_container').innerHTML = '';
            actualizarVisibilidadVacioExamen();
        }

        function actualizarVisibilidadVacioExamen() {
            const hay = document.querySelectorAll('#preguntas_examen_container .pregunta-examen-item').length > 0;
            document.getElementById('preguntas_examen_vacio').classList.toggle('d-none', hay);
            renumerarPreguntasExamen();
        }

        function renumerarPreguntasExamen() {
            document.querySelectorAll('#preguntas_examen_container .pregunta-examen-item').forEach((el, i) => {
                el.querySelector('.pregunta-numero').textContent = 'Pregunta ' + (i + 1);
            });
        }

        function agregarPreguntaExamen(data) {
            contadorPreguntaExamen++;
            const div = document.createElement('div');
            div.className = 'card mb-2 pregunta-examen-item';
            div.innerHTML = `
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center mb-2">
                        <strong class="pregunta-numero">Pregunta</strong>
                        <button type="button" class="btn btn-sm btn-outline-danger" onclick="quitarPreguntaExamen(this)"><i class="fas fa-trash"></i></button>
                    </div>
                    <div class="row g-2">
                        <div class="col-md-4">
                            <label class="form-label small">Tipo de pregunta</label>
                            <select class="form-select form-select-sm campo-tipo-pregunta" onchange="actualizarTipoPreguntaExamen(this)">
                                <option value="opcion_multiple">Opción múltiple</option>
                                <option value="verdadero_falso">Verdadero / Falso</option>
                                <option value="completar">Completar espacios</option>
                                <option value="relacionar">Relacionar columnas</option>
                                <option value="respuesta_corta">Respuesta corta</option>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label small">Puntaje</label>
                            <input type="number" class="form-control form-control-sm campo-puntaje-pregunta" value="1" min="0.5" step="0.5">
                        </div>
                        <div class="col-12">
                            <label class="form-label small">Enunciado</label>
                            <textarea class="form-control form-control-sm campo-enunciado-pregunta" rows="2"></textarea>
                            <small class="text-muted campo-ayuda-completar" style="display:none">Encierra las respuestas correctas entre corchetes. Ej: La capital de Francia es [París].</small>
                        </div>
                        <div class="col-12 campo-opciones-pregunta">
                            <label class="form-label small">Opciones de respuesta</label>
                            <div class="lista-opciones-pregunta"></div>
                            <button type="button" class="btn btn-sm btn-outline-secondary mt-1" onclick="agregarOpcionPregunta(this)"><i class="fas fa-plus"></i> Agregar opción</button>
                        </div>
                        <div class="col-12 campo-vf-pregunta" style="display:none">
                            <label class="form-label small">Respuesta correcta</label>
                            <select class="form-select form-select-sm campo-vf-correcta">
                                <option value="V">Verdadero</option>
                                <option value="F">Falso</option>
                            </select>
                        </div>
                        <div class="col-12 campo-corta-pregunta" style="display:none">
                            <label class="form-label small">Respuesta correcta esperada</label>
                            <input type="text" class="form-control form-control-sm campo-respuesta-corta" placeholder="Texto que se comparará con la respuesta del estudiante">
                        </div>
                        <div class="col-12 campo-relacionar-pregunta" style="display:none">
                            <div class="row g-2">
                                <div class="col-md-6">
                                    <label class="form-label small">Columna izquierda</label>
                                    <div class="lista-relacionar-izquierda"></div>
                                    <button type="button" class="btn btn-sm btn-outline-secondary mt-1" onclick="agregarElementoRelacionar(this, 'izquierda')"><i class="fas fa-plus"></i> Elemento</button>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label small">Columna derecha (respuestas)</label>
                                    <div class="lista-relacionar-derecha"></div>
                                    <button type="button" class="btn btn-sm btn-outline-secondary mt-1" onclick="agregarElementoRelacionar(this, 'derecha')"><i class="fas fa-plus"></i> Elemento</button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>`;
            document.getElementById('preguntas_examen_container').appendChild(div);

            const selectTipo = div.querySelector('.campo-tipo-pregunta');
            if (data && data.tipo) selectTipo.value = data.tipo;
            actualizarTipoPreguntaExamen(selectTipo, data);

            if (data) {
                div.querySelector('.campo-enunciado-pregunta').value = data.enunciado || '';
                div.querySelector('.campo-puntaje-pregunta').value = data.puntaje || 1;
            }

            actualizarVisibilidadVacioExamen();
        }

        function quitarPreguntaExamen(btn) {
            btn.closest('.pregunta-examen-item').remove();
            actualizarVisibilidadVacioExamen();
        }

        function actualizarTipoPreguntaExamen(select, data) {
            const bloque = select.closest('.pregunta-examen-item');
            const tipo = select.value;
            bloque.querySelector('.campo-opciones-pregunta').style.display = 'none';
            bloque.querySelector('.campo-vf-pregunta').style.display = 'none';
            bloque.querySelector('.campo-corta-pregunta').style.display = 'none';
            bloque.querySelector('.campo-relacionar-pregunta').style.display = 'none';
            bloque.querySelector('.campo-ayuda-completar').style.display = 'none';

            const listaOpciones = bloque.querySelector('.lista-opciones-pregunta');
            const listaIzq = bloque.querySelector('.lista-relacionar-izquierda');
            const listaDer = bloque.querySelector('.lista-relacionar-derecha');

            if (tipo === 'opcion_multiple') {
                bloque.querySelector('.campo-opciones-pregunta').style.display = '';
                listaOpciones.innerHTML = '';
                if (data && data.tipo === 'opcion_multiple' && Array.isArray(data.opciones) && data.opciones.length) {
                    data.opciones.forEach(o => agregarOpcionPregunta(bloque.querySelector('.campo-opciones-pregunta button'), o.texto, !!parseInt(o.es_correcta)));
                } else if (!data) {
                    agregarOpcionPregunta(bloque.querySelector('.campo-opciones-pregunta button'));
                    agregarOpcionPregunta(bloque.querySelector('.campo-opciones-pregunta button'));
                }
            } else if (tipo === 'verdadero_falso') {
                bloque.querySelector('.campo-vf-pregunta').style.display = '';
                if (data && data.tipo === 'verdadero_falso') {
                    bloque.querySelector('.campo-vf-correcta').value = data.correcta_vf || 'V';
                }
            } else if (tipo === 'completar') {
                bloque.querySelector('.campo-ayuda-completar').style.display = '';
            } else if (tipo === 'respuesta_corta') {
                bloque.querySelector('.campo-corta-pregunta').style.display = '';
                if (data && data.tipo === 'respuesta_corta') {
                    bloque.querySelector('.campo-respuesta-corta').value = data.respuesta_correcta || '';
                }
            } else if (tipo === 'relacionar') {
                bloque.querySelector('.campo-relacionar-pregunta').style.display = '';
                listaIzq.innerHTML = '';
                listaDer.innerHTML = '';
                if (data && data.tipo === 'relacionar' && Array.isArray(data.izquierda) && data.izquierda.length) {
                    data.izquierda.forEach(v => agregarElementoRelacionar(bloque.querySelector('.lista-relacionar-izquierda').nextElementSibling, 'izquierda', v));
                    (data.derecha || []).forEach(v => agregarElementoRelacionar(bloque.querySelector('.lista-relacionar-derecha').nextElementSibling, 'derecha', v));
                } else if (!data) {
                    agregarElementoRelacionar(bloque.querySelector('.lista-relacionar-izquierda').nextElementSibling, 'izquierda');
                    agregarElementoRelacionar(bloque.querySelector('.lista-relacionar-derecha').nextElementSibling, 'derecha');
                }
            }
        }

        function agregarOpcionPregunta(btn, texto, correcta) {
            const contenedor = btn.closest('.campo-opciones-pregunta').querySelector('.lista-opciones-pregunta');
            const div = document.createElement('div');
            div.className = 'input-group input-group-sm mb-1';
            div.innerHTML = `
                <div class="input-group-text">
                    <input type="checkbox" class="form-check-input mt-0 opcion-pregunta-correcta" ${correcta ? 'checked' : ''} title="Marcar como correcta">
                </div>
                <input type="text" class="form-control opcion-pregunta-texto" placeholder="Texto de la opción" value="${texto ? String(texto).replace(/"/g, '&quot;') : ''}">
                <button type="button" class="btn btn-outline-danger" onclick="this.closest('.input-group').remove()"><i class="fas fa-times"></i></button>`;
            contenedor.appendChild(div);
        }

        function agregarElementoRelacionar(btn, columna, texto) {
            const clase = columna === 'izquierda' ? '.lista-relacionar-izquierda' : '.lista-relacionar-derecha';
            const contenedor = btn.closest('.col-md-6').querySelector(clase);
            const div = document.createElement('div');
            div.className = 'input-group input-group-sm mb-1';
            div.innerHTML = `
                <input type="text" class="form-control elemento-relacionar-texto" placeholder="Elemento" value="${texto ? String(texto).replace(/"/g, '&quot;') : ''}">
                <button type="button" class="btn btn-outline-danger" onclick="this.closest('.input-group').remove()"><i class="fas fa-times"></i></button>`;
            contenedor.appendChild(div);
        }

        function serializarPreguntasExamen() {
            const preguntas = [];
            document.querySelectorAll('#preguntas_examen_container .pregunta-examen-item').forEach(bloque => {
                const tipo = bloque.querySelector('.campo-tipo-pregunta').value;
                const enunciado = bloque.querySelector('.campo-enunciado-pregunta').value.trim();
                const puntaje = parseFloat(bloque.querySelector('.campo-puntaje-pregunta').value) || 1;
                if (!enunciado) return;

                const pregunta = { tipo, enunciado, puntaje };
                if (tipo === 'opcion_multiple') {
                    pregunta.opciones = Array.from(bloque.querySelectorAll('.lista-opciones-pregunta .input-group')).map(row => ({
                        texto: row.querySelector('.opcion-pregunta-texto').value.trim(),
                        es_correcta: row.querySelector('.opcion-pregunta-correcta').checked ? 1 : 0
                    })).filter(o => o.texto !== '');
                } else if (tipo === 'verdadero_falso') {
                    pregunta.correcta_vf = bloque.querySelector('.campo-vf-correcta').value;
                } else if (tipo === 'respuesta_corta') {
                    pregunta.respuesta_correcta = bloque.querySelector('.campo-respuesta-corta').value.trim();
                } else if (tipo === 'relacionar') {
                    pregunta.izquierda = Array.from(bloque.querySelectorAll('.lista-relacionar-izquierda .elemento-relacionar-texto')).map(i => i.value.trim()).filter(v => v !== '');
                    pregunta.derecha = Array.from(bloque.querySelectorAll('.lista-relacionar-derecha .elemento-relacionar-texto')).map(i => i.value.trim()).filter(v => v !== '');
                }
                preguntas.push(pregunta);
            });
            return preguntas;
        }

        // Antes de enviar el formulario: si el tipo es "examen", serializar
        // las preguntas al campo oculto y validar que haya al menos una
        // (con datos mínimos según su tipo) antes de dejar continuar el POST.
        document.getElementById('formActividad').addEventListener('submit', function(e) {
            const tipo = document.getElementById('tipo').value;
            if (tipo !== 'examen') {
                document.getElementById('preguntas_json').value = '';
                return;
            }
            const preguntas = serializarPreguntasExamen();
            if (preguntas.length === 0) {
                e.preventDefault();
                alert('Agrega al menos una pregunta con enunciado antes de guardar el examen.');
                return;
            }
            for (const p of preguntas) {
                if (p.tipo === 'opcion_multiple' && (!p.opciones || p.opciones.filter(o => o.texto).length < 2)) {
                    e.preventDefault();
                    alert('Cada pregunta de opción múltiple necesita al menos dos opciones.');
                    return;
                }
                if (p.tipo === 'opcion_multiple' && !p.opciones.some(o => o.es_correcta)) {
                    e.preventDefault();
                    alert('Marca cuál opción es la correcta en cada pregunta de opción múltiple.');
                    return;
                }
                if (p.tipo === 'completar' && !/\[.+?\]/.test(p.enunciado)) {
                    e.preventDefault();
                    alert('En preguntas de "completar", encierra la(s) respuesta(s) correcta(s) entre corchetes dentro del enunciado.');
                    return;
                }
                if (p.tipo === 'respuesta_corta' && !p.respuesta_correcta) {
                    e.preventDefault();
                    alert('Escribe la respuesta correcta esperada en cada pregunta de respuesta corta.');
                    return;
                }
                if (p.tipo === 'relacionar' && (p.izquierda.length < 2 || p.izquierda.length !== p.derecha.length)) {
                    e.preventDefault();
                    alert('En preguntas de "relacionar", agrega al menos dos pares con la misma cantidad de elementos en ambas columnas.');
                    return;
                }
            }
            document.getElementById('preguntas_json').value = JSON.stringify(preguntas);
        });

        // Inicializar
        document.addEventListener('DOMContentLoaded', function() {
            if (window.innerWidth < 992) $('#sidebar').addClass('active');
            mostrarCamposTipo();
        });
    </script>
</body>
</html>