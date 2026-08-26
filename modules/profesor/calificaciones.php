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

        } elseif ($accion == 'calificar_rubrica') {
            // Calificación con la matriz de rúbrica de la actividad (ver
            // modules/profesor/rubricas.php y
            // gestionar_actividades.php::copiarRubricaATactividad()). Sigue
            // escribiendo su suma en la MISMA columna nota_obtenida que ya
            // usa calificar_entrega -- es una captura más rica del mismo
            // número, no un sistema paralelo, así que CuadroNotasHelper,
            // vw_logro_estudiante, reportes, etc. siguen funcionando igual.
            $id_matricula = filter_input(INPUT_POST, 'id_matricula', FILTER_VALIDATE_INT);
            $id_actividad = filter_input(INPUT_POST, 'id_actividad', FILTER_VALIDATE_INT);
            $rubrica_nivel_post = is_array($_POST['rubrica_nivel'] ?? null) ? $_POST['rubrica_nivel'] : [];
            $rubrica_comentario_post = is_array($_POST['rubrica_comentario'] ?? null) ? $_POST['rubrica_comentario'] : [];

            // Mismo chequeo de propiedad que calificar_entrega.
            $check = $db->prepare("
                SELECT a.id, a.nota_maxima
                FROM tbl_actividad a
                JOIN tbl_asignacion_docente ad ON a.id_asignacion_docente = ad.id
                JOIN tbl_matricula m ON m.id_seccion = ad.id_seccion AND m.anno = ad.anno
                WHERE a.id = :id_actividad AND ad.id_profesor = :id_profesor AND m.id = :id_matricula
            ");
            $check->execute([':id_actividad' => $id_actividad, ':id_profesor' => $id_profesor, ':id_matricula' => $id_matricula]);
            $actividad = $check->fetch(PDO::FETCH_ASSOC);
            if (!$actividad) {
                throw new Exception("No tiene permiso para calificar a este estudiante en esta actividad");
            }

            $stmtRub = $db->prepare("SELECT id FROM tbl_rubrica WHERE id_actividad = :id");
            $stmtRub->execute([':id' => $id_actividad]);
            $id_rubrica = $stmtRub->fetchColumn();
            if (!$id_rubrica) {
                throw new Exception("Esta actividad no tiene una rúbrica asociada");
            }
            if (empty($rubrica_nivel_post)) {
                throw new Exception("Debe calificar al menos un criterio de la rúbrica");
            }

            // Por cada (id_criterio, id_nivel) enviado, releer el puntaje
            // REAL desde la celda -- nunca se confía en un puntaje que el
            // cliente pudiera enviar -- acotado a ESTA rúbrica (cr.id_rubrica)
            // para que no se cuele un id_criterio/id_nivel de una rúbrica ajena.
            $stmtCelda = $db->prepare("SELECT ce.puntaje FROM tbl_rubrica_celda ce
                JOIN tbl_rubrica_criterio cr ON ce.id_criterio = cr.id
                WHERE ce.id_criterio = :crit AND ce.id_nivel = :niv AND cr.id_rubrica = :rub");

            $detalles = [];
            $totalPuntaje = 0.0;
            foreach ($rubrica_nivel_post as $idCriterioPost => $idNivelPost) {
                $idCriterioPost = (int) $idCriterioPost;
                $idNivelPost = (int) $idNivelPost;
                if (!$idCriterioPost || !$idNivelPost) {
                    continue;
                }
                $stmtCelda->execute([':crit' => $idCriterioPost, ':niv' => $idNivelPost, ':rub' => $id_rubrica]);
                $puntaje = $stmtCelda->fetchColumn();
                if ($puntaje === false) {
                    continue; // celda no pertenece a esta rúbrica: se ignora
                }
                $totalPuntaje += (float) $puntaje;
                $detalles[] = [
                    'id_criterio' => $idCriterioPost,
                    'id_nivel' => $idNivelPost,
                    'puntaje' => (float) $puntaje,
                    'comentario' => trim($rubrica_comentario_post[$idCriterioPost] ?? ''),
                ];
            }
            if (empty($detalles)) {
                throw new Exception("Ninguno de los criterios enviados es válido para esta rúbrica");
            }

            $nota_maxima = $actividad['nota_maxima'] ?? 10;
            $nota_obtenida = min($totalPuntaje, $nota_maxima);

            // No se toca observacion_docente aquí -- es independiente de los
            // comentarios por criterio (comentario_criterio, abajo); se deja
            // intacto si ya existía, y en NULL si la fila se crea recién ahora.
            $query = "INSERT INTO tbl_entrega_actividad
                          (id_actividad, id_matricula, nota_obtenida, observacion_docente, estado_entrega, fecha_calificacion)
                      VALUES (:id_actividad, :id_matricula, :nota, NULL, 'calificado', NOW())
                      ON DUPLICATE KEY UPDATE
                          nota_obtenida = VALUES(nota_obtenida),
                          estado_entrega = VALUES(estado_entrega),
                          fecha_calificacion = VALUES(fecha_calificacion)";
            $stmt = $db->prepare($query);
            $stmt->execute([':id_actividad' => $id_actividad, ':id_matricula' => $id_matricula, ':nota' => $nota_obtenida]);

            // LAST_INSERT_ID() no es confiable tras ON DUPLICATE KEY UPDATE
            // cuando la fila ya existía (puede devolver 0) -- se relee el id
            // real explícitamente.
            $stmtEntrega = $db->prepare("SELECT id FROM tbl_entrega_actividad WHERE id_actividad = :a AND id_matricula = :m");
            $stmtEntrega->execute([':a' => $id_actividad, ':m' => $id_matricula]);
            $id_entrega = (int) $stmtEntrega->fetchColumn();

            // Reemplazo completo del detalle de rúbrica de esta entrega.
            $db->prepare("DELETE FROM tbl_entrega_rubrica_detalle WHERE id_entrega_actividad = :id")->execute([':id' => $id_entrega]);
            $stmtInsDet = $db->prepare("INSERT INTO tbl_entrega_rubrica_detalle (id_entrega_actividad, id_criterio, id_nivel, puntaje_otorgado, comentario_criterio)
                                        VALUES (:entrega, :crit, :niv, :pts, :com)");
            foreach ($detalles as $d) {
                $stmtInsDet->execute([
                    ':entrega' => $id_entrega, ':crit' => $d['id_criterio'], ':niv' => $d['id_nivel'],
                    ':pts' => $d['puntaje'], ':com' => $d['comentario'] !== '' ? $d['comentario'] : null,
                ]);
            }

            $valorSobreDiez = $nota_maxima > 0 ? ($nota_obtenida / $nota_maxima) * 10 : null;
            CuadroNotasHelper::sincronizar($db, $id_actividad, $id_matricula, $valorSobreDiez);

            $db->commit();
            $mensaje = 'Calificación por rúbrica guardada exitosamente';
            $tipo_mensaje = 'success';

        } elseif ($accion == 'comentario_multiple') {
            // Guarda SOLO comentarios para varios estudiantes a la vez, sin
            // tocar nota_obtenida/estado_entrega -- cierra el vacío de
            // calificar_multiple (arriba), que hoy descarta por completo
            // cualquier fila con la celda de nota vacía (línea "continue" si
            // $nota === false), así que hoy es imposible guardar solo un
            // comentario sin nota desde la vía masiva.
            $id_actividad = filter_input(INPUT_POST, 'id_actividad', FILTER_VALIDATE_INT);
            $comentarios = is_array($_POST['comentarios'] ?? null) ? $_POST['comentarios'] : [];

            $check = $db->prepare("
                SELECT a.id, ad.id_seccion, ad.anno
                FROM tbl_actividad a
                JOIN tbl_asignacion_docente ad ON a.id_asignacion_docente = ad.id
                WHERE a.id = :id_actividad AND ad.id_profesor = :id_profesor
            ");
            $check->execute([':id_actividad' => $id_actividad, ':id_profesor' => $id_profesor]);
            $actividad = $check->fetch(PDO::FETCH_ASSOC);

            if ($actividad) {
                // Sólo matrículas que de verdad pertenezcan a la sección/año
                // de esta actividad -- mismo chequeo que calificar_multiple.
                $checkMatricula = $db->prepare("
                    SELECT id FROM tbl_matricula WHERE id = :id_matricula AND id_seccion = :id_seccion AND anno = :anno
                ");

                $query = "INSERT INTO tbl_entrega_actividad (id_actividad, id_matricula, nota_obtenida, observacion_docente, estado_entrega, fecha_calificacion)
                          VALUES (:id_actividad, :id_matricula, NULL, :retro, NULL, NULL)
                          ON DUPLICATE KEY UPDATE observacion_docente = VALUES(observacion_docente)";
                $stmt = $db->prepare($query);

                $actualizadas = 0;
                foreach ($comentarios as $id_matricula => $texto) {
                    $id_matricula = (int) $id_matricula;
                    $texto = trim($texto);
                    if ($texto === '') {
                        continue;
                    }
                    $checkMatricula->execute([
                        ':id_matricula' => $id_matricula,
                        ':id_seccion' => $actividad['id_seccion'],
                        ':anno' => $actividad['anno'],
                    ]);
                    if (!$checkMatricula->fetch()) {
                        continue; // matrícula ajena a esta sección/año: se ignora
                    }
                    $stmt->execute([':id_actividad' => $id_actividad, ':id_matricula' => $id_matricula, ':retro' => $texto]);
                    $actualizadas++;
                }

                $db->commit();
                $mensaje = "$actualizadas comentario(s) guardado(s)";
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

// ===== RÚBRICA DE LA ACTIVIDAD SELECCIONADA (solo tareas) =====
// Ver modules/profesor/rubricas.php (biblioteca de plantillas) y
// gestionar_actividades.php::copiarRubricaATactividad() (copia a la
// actividad). $rubrica_estructura queda null si la actividad no tiene
// rúbrica asociada -- el HTML/JS de abajo usa eso para decidir si muestra
// el input numérico de siempre o el botón "Calificar con rúbrica".
$id_rubrica_actividad = null;
$rubrica_estructura = null;
$rubrica_detalle_por_matricula = []; // [id_matricula][id_criterio] = ['id_nivel'=>, 'comentario'=>]
if ($actividad_seleccionada && $actividad_seleccionada['tipo'] === 'tarea') {
    $stmtRubAct = $db->prepare("SELECT id FROM tbl_rubrica WHERE id_actividad = :id");
    $stmtRubAct->execute([':id' => $id_actividad_filtro]);
    $id_rubrica_actividad = $stmtRubAct->fetchColumn() ?: null;

    if ($id_rubrica_actividad) {
        $stmtN = $db->prepare("SELECT id, nombre, orden FROM tbl_rubrica_nivel WHERE id_rubrica = :id ORDER BY orden");
        $stmtN->execute([':id' => $id_rubrica_actividad]);
        $niveles = $stmtN->fetchAll(PDO::FETCH_ASSOC);

        $stmtC = $db->prepare("SELECT id, nombre, descripcion, orden FROM tbl_rubrica_criterio WHERE id_rubrica = :id ORDER BY orden");
        $stmtC->execute([':id' => $id_rubrica_actividad]);
        $criterios = $stmtC->fetchAll(PDO::FETCH_ASSOC);

        $stmtCe = $db->prepare("SELECT ce.id_criterio, ce.id_nivel, ce.descripcion, ce.puntaje FROM tbl_rubrica_celda ce
                                JOIN tbl_rubrica_criterio cr ON ce.id_criterio = cr.id
                                WHERE cr.id_rubrica = :id");
        $stmtCe->execute([':id' => $id_rubrica_actividad]);
        $celdas = [];
        foreach ($stmtCe->fetchAll(PDO::FETCH_ASSOC) as $ce) {
            $celdas[$ce['id_criterio']][$ce['id_nivel']] = ['descripcion' => $ce['descripcion'], 'puntaje' => (float) $ce['puntaje']];
        }

        $rubrica_estructura = [
            'niveles' => array_map(fn($n) => ['id' => (int) $n['id'], 'nombre' => $n['nombre']], $niveles),
            'criterios' => array_map(function ($c) use ($niveles, $celdas) {
                return [
                    'id' => (int) $c['id'], 'nombre' => $c['nombre'], 'descripcion' => $c['descripcion'],
                    'niveles' => array_map(function ($n) use ($c, $celdas) {
                        $celda = $celdas[$c['id']][$n['id']] ?? ['descripcion' => null, 'puntaje' => 0.0];
                        return ['id_nivel' => (int) $n['id'], 'descripcion' => $celda['descripcion'], 'puntaje' => (float) $celda['puntaje']];
                    }, $niveles),
                ];
            }, $criterios),
        ];

        // Detalle ya calificado (para prellenar la matriz al reabrir el modal).
        $stmtDet = $db->prepare("SELECT ea.id_matricula, erd.id_criterio, erd.id_nivel, erd.comentario_criterio
                                 FROM tbl_entrega_rubrica_detalle erd
                                 JOIN tbl_entrega_actividad ea ON erd.id_entrega_actividad = ea.id
                                 WHERE ea.id_actividad = :id_actividad");
        $stmtDet->execute([':id_actividad' => $id_actividad_filtro]);
        foreach ($stmtDet->fetchAll(PDO::FETCH_ASSOC) as $d) {
            $rubrica_detalle_por_matricula[$d['id_matricula']][$d['id_criterio']] = [
                'id_nivel' => (int) $d['id_nivel'], 'comentario' => $d['comentario_criterio'],
            ];
        }
    }
}

// ===== BANCO DE COMENTARIOS REUTILIZABLES (biblioteca personal) =====
$stmtBancoCom = $db->prepare("SELECT id, texto, categoria FROM tbl_banco_comentario WHERE id_profesor = :prof AND id_institucion = :tid AND estado = 'activo' ORDER BY updated_at DESC");
$stmtBancoCom->execute([':prof' => $id_profesor, ':tid' => $tid]);
$comentarios_banco = $stmtBancoCom->fetchAll(PDO::FETCH_ASSOC);

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
                <div class="card-header bg-white py-3">
                    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
                        <h5 class="mb-0">
                            <i class="fas fa-list"></i> Entregas de Estudiantes
                            <span class="badge bg-primary ms-2"><?= count($entregas) ?></span>
                        </h5>
                        <div class="d-flex gap-2 flex-wrap">
                            <?php if (!$id_rubrica_actividad): ?>
                            <button type="button" class="btn btn-outline-secondary btn-sm" onclick="llenarNotasAutomaticas()">
                                <i class="fas fa-magic"></i> Autollenar
                            </button>
                            <?php endif; ?>
                            <a href="api/exportar_calificaciones.php?id_actividad=<?= (int) $id_actividad_filtro ?>" class="btn btn-outline-success btn-sm">
                                <i class="fas fa-file-excel"></i> Exportar a Excel
                            </a>
                            <button type="submit" class="btn btn-primary btn-sm">
                                <i class="fas fa-save"></i> Guardar Todas
                            </button>
                        </div>
                    </div>
                    <div class="d-flex gap-2 align-items-center flex-wrap mt-2 pt-2 border-top">
                        <small class="text-muted"><i class="fas fa-comment-dots"></i> Banco de comentarios:</small>
                        <?php if ($comentarios_banco): ?>
                        <select id="selectorBancoMasivo" class="form-select form-select-sm" style="max-width: 320px;">
                            <option value="">-- Elegir comentario --</option>
                            <?php foreach ($comentarios_banco as $c): ?>
                            <option value="<?= htmlspecialchars($c['texto']) ?>"><?= htmlspecialchars(mb_strimwidth($c['texto'], 0, 60, '…')) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <button type="button" class="btn btn-outline-secondary btn-sm" onclick="aplicarComentarioASeleccionados()">
                            <i class="fas fa-check-double"></i> Aplicar a seleccionados
                        </button>
                        <button type="button" class="btn btn-outline-primary btn-sm" onclick="guardarComentariosSeleccionados()">
                            <i class="fas fa-save"></i> Guardar solo comentarios (seleccionados)
                        </button>
                        <?php endif; ?>
                        <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-toggle="modal" data-bs-target="#modalBancoComentarios">
                            <i class="fas fa-cog"></i> Administrar banco
                        </button>
                    </div>
                </div>

                <div class="table-responsive">
                    <table class="table table-hover mb-0 align-middle">
                        <thead class="table-light">
                            <tr>
                                <th><input type="checkbox" class="form-check-input" id="chkTodos" onchange="document.querySelectorAll('.chk-estudiante').forEach(c => c.checked = this.checked)"></th>
                                <th>Estudiante</th>
                                <th>Entrega</th>
                                <th>Estado</th>
                                <th><?= $id_rubrica_actividad ? 'Nota (rúbrica)' : 'Nota (' . $entregas[0]['nota_maxima'] . ')' ?></th>
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
                                $notaActual = $entrega['nota_obtenida'];
                            ?>
                            <tr>
                                <td><input type="checkbox" class="form-check-input chk-estudiante" value="<?= $entrega['id_matricula'] ?>"></td>
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
                                <?php if ($id_rubrica_actividad): ?>
                                <td>
                                    <div class="mb-1 fw-bold <?= $nota_clase ?>"><?= $notaActual !== null ? number_format($notaActual, 2) . '/' . $entrega['nota_maxima'] : 'Sin calificar' ?></div>
                                    <button type="button" class="btn btn-outline-primary btn-sm" onclick='abrirModalRubrica(<?= (int) $entrega['id_matricula'] ?>, <?= json_encode($nombre_completo, JSON_HEX_APOS | JSON_HEX_QUOT) ?>)'>
                                        <i class="fas fa-th"></i> Calificar con rúbrica
                                    </button>
                                </td>
                                <?php else: ?>
                                <td>
                                    <div class="input-group input-group-sm">
                                        <input type="number"
                                               name="calificaciones[<?= $entrega['id_matricula'] ?>][nota]"
                                               class="form-control nota-input <?= $nota_clase ?>"
                                               value="<?= $notaActual ?? '' ?>"
                                               min="0"
                                               max="<?= $entrega['nota_maxima'] ?>"
                                               step="0.1"
                                               placeholder="-"
                                               onchange="actualizarColorNota(this)">
                                        <span class="input-group-text">/<?= $entrega['nota_maxima'] ?></span>
                                    </div>
                                </td>
                                <?php endif; ?>
                                <td>
                                    <textarea name="calificaciones[<?= $entrega['id_matricula'] ?>][retroalimentacion]"
                                              class="form-control form-control-sm retro-textarea"
                                              placeholder="Comentarios..."><?= htmlspecialchars($entrega['retroalimentacion'] ?? '') ?></textarea>
                                </td>
                                <td>
                                    <div class="btn-group btn-group-sm">
                                        <?php if (!$id_rubrica_actividad): ?>
                                        <button type="button" class="btn btn-outline-primary" onclick="calificarIndividual(<?= $entrega['id_matricula'] ?>)" title="Guardar solo esta">
                                            <i class="fas fa-save"></i>
                                        </button>
                                        <?php endif; ?>
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

    <!-- Modal Calificar con Rúbrica -->
    <div class="modal fade" id="modalCalificarRubrica" tabindex="-1">
        <div class="modal-dialog modal-xl modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title"><i class="fas fa-th"></i> Calificar con rúbrica: <span id="rub_estudiante"></span></h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" id="formCalificarRubrica">
                    <div class="modal-body">
                        <input type="hidden" name="accion" value="calificar_rubrica">
                        <input type="hidden" name="id_matricula" id="rub_id_matricula">
                        <input type="hidden" name="id_actividad" value="<?= (int) $id_actividad_filtro ?>">

                        <div id="rubrica_matriz_container"></div>

                        <div class="alert alert-light border mt-3 d-flex justify-content-between align-items-center mb-0">
                            <strong>Total</strong>
                            <span><strong id="rub_total_puntos">0</strong> / <span id="rub_nota_maxima_actividad">10</span> pts</span>
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

    <!-- Modal Administrar Banco de Comentarios -->
    <div class="modal fade" id="modalBancoComentarios" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-comment-dots"></i> Banco de comentarios</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div id="listaBancoComentarios" class="mb-3">
                        <?php if (empty($comentarios_banco)): ?>
                        <p class="text-muted small" id="bancoComentariosVacio">Todavía no tienes comentarios guardados.</p>
                        <?php else: ?>
                        <?php foreach ($comentarios_banco as $c): ?>
                        <div class="d-flex justify-content-between align-items-start border rounded p-2 mb-2" data-id-comentario="<?= $c['id'] ?>">
                            <div class="small"><?= htmlspecialchars($c['texto']) ?><?php if ($c['categoria']): ?><br><span class="badge bg-light text-dark border"><?= htmlspecialchars($c['categoria']) ?></span><?php endif; ?></div>
                            <button type="button" class="btn btn-sm btn-outline-danger ms-2" onclick="eliminarComentarioBanco(<?= $c['id'] ?>, this)"><i class="fas fa-trash"></i></button>
                        </div>
                        <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                    <hr>
                    <label class="form-label small">Nuevo comentario</label>
                    <textarea id="nuevoComentarioTexto" class="form-control form-control-sm mb-2" rows="2" placeholder="Ej: Buen trabajo, pero revisa la ortografía."></textarea>
                    <input type="text" id="nuevoComentarioCategoria" class="form-control form-control-sm mb-2" placeholder="Categoría (opcional)">
                    <button type="button" class="btn btn-primary btn-sm w-100" onclick="agregarComentarioBanco()"><i class="fas fa-plus"></i> Agregar al banco</button>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cerrar</button>
                </div>
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

    // Estructura de la rúbrica de la actividad seleccionada (null si no
    // tiene) y el detalle ya calificado por estudiante, para prellenar el
    // modal de calificación al reabrirlo. Ver PHP arriba
    // ($rubrica_estructura / $rubrica_detalle_por_matricula).
    const RUBRICA_ESTRUCTURA = <?= json_encode($rubrica_estructura, JSON_UNESCAPED_UNICODE) ?>;
    const RUBRICA_DETALLE = <?= json_encode($rubrica_detalle_por_matricula, JSON_UNESCAPED_UNICODE) ?>;
    const NOTA_MAXIMA_ACTIVIDAD = <?= json_encode((float) ($actividad_seleccionada['nota_maxima'] ?? 10)) ?>;

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
    
    // ===== Calificación con rúbrica =====
    // La matriz se construye en JS a partir de RUBRICA_ESTRUCTURA (misma
    // rúbrica para todos los estudiantes de esta actividad) y se prellena
    // por estudiante con RUBRICA_DETALLE[idMatricula]. Radios con
    // name="rubrica_nivel[id_criterio]" y textareas con
    // name="rubrica_comentario[id_criterio]" postean directamente como
    // arreglos PHP -- no hace falta serializar nada aparte al enviar.
    function escapeHtml(s) {
        return (s ?? '').toString().replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
    }

    function abrirModalRubrica(idMatricula, nombreEstudiante) {
        if (!RUBRICA_ESTRUCTURA) return;
        document.getElementById('rub_id_matricula').value = idMatricula;
        document.getElementById('rub_estudiante').textContent = nombreEstudiante;
        document.getElementById('rub_nota_maxima_actividad').textContent = NOTA_MAXIMA_ACTIVIDAD;

        const detalle = RUBRICA_DETALLE[idMatricula] || {};
        const cont = document.getElementById('rubrica_matriz_container');
        cont.innerHTML = '';

        const table = document.createElement('table');
        table.className = 'table table-bordered table-sm align-middle';

        let headHtml = '<thead class="table-light"><tr><th style="min-width:220px">Criterio</th>';
        RUBRICA_ESTRUCTURA.niveles.forEach(n => { headHtml += `<th class="text-center">${escapeHtml(n.nombre)}</th>`; });
        headHtml += '</tr></thead>';
        table.innerHTML = headHtml;

        const tbody = document.createElement('tbody');
        RUBRICA_ESTRUCTURA.criterios.forEach(crit => {
            const detCrit = detalle[crit.id] || {};
            const tr = document.createElement('tr');
            let rowHtml = `<td><strong>${escapeHtml(crit.nombre)}</strong>`;
            if (crit.descripcion) rowHtml += `<br><small class="text-muted">${escapeHtml(crit.descripcion)}</small>`;
            rowHtml += `<textarea class="form-control form-control-sm mt-2" name="rubrica_comentario[${crit.id}]" rows="2" placeholder="Comentario (opcional)">${escapeHtml(detCrit.comentario)}</textarea></td>`;

            crit.niveles.forEach(niv => {
                const checked = (detCrit.id_nivel === niv.id_nivel) ? 'checked' : '';
                rowHtml += `<td class="text-center celda-rub-radio" data-puntaje="${niv.puntaje}">
                    <div class="small mb-1">${niv.descripcion ? escapeHtml(niv.descripcion) : '<span class="text-muted">—</span>'}</div>
                    <input type="radio" class="form-check-input campo-rub-radio" name="rubrica_nivel[${crit.id}]" value="${niv.id_nivel}" ${checked} onchange="recalcularTotalRubrica()">
                    <div class="small text-muted">${niv.puntaje} pts</div>
                </td>`;
            });
            tr.innerHTML = rowHtml;
            tbody.appendChild(tr);
        });
        table.appendChild(tbody);
        cont.appendChild(table);

        recalcularTotalRubrica();
        new bootstrap.Modal(document.getElementById('modalCalificarRubrica')).show();
    }

    function recalcularTotalRubrica() {
        let total = 0;
        document.querySelectorAll('#rubrica_matriz_container .campo-rub-radio:checked').forEach(r => {
            total += parseFloat(r.closest('.celda-rub-radio')?.dataset.puntaje || 0);
        });
        document.getElementById('rub_total_puntos').textContent = total.toFixed(2);
    }

    // ===== Banco de comentarios: aplicar a varios estudiantes a la vez =====
    function aplicarComentarioASeleccionados() {
        const texto = document.getElementById('selectorBancoMasivo').value;
        if (!texto) { alert('Elige un comentario del banco primero'); return; }
        const seleccionados = document.querySelectorAll('.chk-estudiante:checked');
        if (seleccionados.length === 0) { alert('Marca al menos un estudiante'); return; }
        seleccionados.forEach(chk => {
            const fila = chk.closest('tr');
            fila.querySelector('textarea[name*="[retroalimentacion]"]').value = texto;
        });
    }

    // Guarda SOLO los comentarios de las filas marcadas, sin tocar la nota
    // -- vía accion=comentario_multiple (independiente del formulario
    // principal de "Guardar Todas", que hoy descarta cualquier fila sin
    // nota). Se arma un formulario nuevo dinámicamente y se envía (mismo
    // patrón que eliminarActividad() en gestionar_actividades.php).
    function guardarComentariosSeleccionados() {
        const seleccionados = document.querySelectorAll('.chk-estudiante:checked');
        if (seleccionados.length === 0) { alert('Marca al menos un estudiante'); return; }

        const form = document.createElement('form');
        form.method = 'POST';
        form.style.display = 'none';
        form.innerHTML = `<input type="hidden" name="accion" value="comentario_multiple">
                           <input type="hidden" name="id_actividad" value="<?= (int) $id_actividad_filtro ?>">`;
        seleccionados.forEach(chk => {
            const idMatricula = chk.value;
            const texto = chk.closest('tr').querySelector('textarea[name*="[retroalimentacion]"]').value;
            const input = document.createElement('input');
            input.type = 'hidden';
            input.name = `comentarios[${idMatricula}]`;
            input.value = texto;
            form.appendChild(input);
        });
        document.body.appendChild(form);
        form.submit();
    }

    // ===== Administrar banco de comentarios (modal) =====
    // location.reload() tras guardar/eliminar (en vez de manipular el DOM a
    // mano) -- mismo patrón que banco_preguntas.php -- así el <select> del
    // toolbar y esta lista siempre quedan sincronizados sin duplicar lógica
    // de renderizado en JS.
    function agregarComentarioBanco() {
        const texto = document.getElementById('nuevoComentarioTexto').value.trim();
        if (!texto) { alert('Escribe el texto del comentario'); return; }
        const formData = new FormData();
        formData.append('accion', 'guardar');
        formData.append('texto', texto);
        formData.append('categoria', document.getElementById('nuevoComentarioCategoria').value.trim());

        fetch('api/guardar_comentario_banco.php', { method: 'POST', body: formData })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    location.reload();
                } else {
                    alert('Error: ' + data.message);
                }
            })
            .catch(() => alert('Error de conexión al guardar el comentario'));
    }

    function eliminarComentarioBanco(idComentario, btn) {
        if (!confirm('¿Eliminar este comentario del banco?')) return;
        const formData = new FormData();
        formData.append('accion', 'eliminar');
        formData.append('id_comentario', idComentario);

        fetch('api/guardar_comentario_banco.php', { method: 'POST', body: formData })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    location.reload();
                } else {
                    alert('Error: ' + data.message);
                }
            })
            .catch(() => alert('Error de conexión al eliminar el comentario'));
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