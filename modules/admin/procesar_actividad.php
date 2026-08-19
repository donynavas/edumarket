<?php
session_start();
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/TenantGuard.php';

// ========================================
// CONFIGURACIÓN Y VALIDACIÓN INICIAL
// ========================================

// Verificar sesión y rol
if (!isset($_SESSION['user_id']) || $_SESSION['rol'] != 'profesor') {
    header("Location: " . BASE_URL . "/login.php");
    exit;
}

// Verificar método POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: tablon.php");
    exit;
}

$database = new Database();
$db = $database->getConnection();
$user_id = $_SESSION['user_id'];
$tid = TenantGuard::id();

// ========================================
// OBTENER ID DEL PROFESOR
// ========================================
$query = "SELECT p.id as id_profesor FROM tbl_profesor p
          JOIN tbl_persona per ON p.id_persona = per.id
          WHERE per.id_usuario = :user_id AND p.id_institucion = :tid";
$stmt = $db->prepare($query);
$stmt->bindValue(':user_id', $user_id, PDO::PARAM_INT);
$stmt->bindValue(':tid', $tid, PDO::PARAM_INT);
$stmt->execute();
$profesor = $stmt->fetch(PDO::FETCH_ASSOC);
$id_profesor = $profesor['id_profesor'] ?? 0;

if (!$id_profesor) {
    $_SESSION['error'] = "Perfil de profesor no encontrado";
    header("Location: " . BASE_URL . "/logout.php");
    exit;
}

// ========================================
// RECIBIR Y SANITIZAR DATOS
// ========================================
$modo = $_POST['modo'] ?? 'crear';
$id_asignacion = (int)($_POST['id_asignacion'] ?? 0);
$id_actividad = isset($_POST['id_actividad']) ? (int)$_POST['id_actividad'] : 0;

$titulo = trim($_POST['titulo'] ?? '');
$descripcion = trim($_POST['descripcion'] ?? '');
$tipo = trim($_POST['tipo'] ?? '');
$contenido = trim($_POST['contenido'] ?? '');
$url_recurso = trim($_POST['url_recurso'] ?? '');
$estado = trim($_POST['estado'] ?? 'programado');
$fecha_programada = $_POST['fecha_programada'] ?? '';
$fecha_limite = !empty($_POST['fecha_limite']) ? $_POST['fecha_limite'] : null;
$duracion_minutos = !empty($_POST['duracion_minutos']) ? (int)$_POST['duracion_minutos'] : null;
$nota_maxima = !empty($_POST['nota_maxima']) ? (float)$_POST['nota_maxima'] : null;

// ========================================
// VALIDACIONES CRÍTICAS
// ========================================
$errors = [];

// 1. Validar campos obligatorios
if (empty($titulo)) $errors[] = "El título es obligatorio";
if (empty($descripcion)) $errors[] = "La descripción es obligatoria";
if (empty($tipo)) $errors[] = "El tipo de actividad es obligatorio";
if (empty($fecha_programada)) $errors[] = "La fecha de publicación es obligatoria";

// 2. Validar tipo de actividad
$tipos_validos = ['tarea', 'examen', 'video', 'youtube', 'articulo', 'referencia', 'podcast', 'revista', 'enlace'];
if (!in_array($tipo, $tipos_validos)) {
    $errors[] = "Tipo de actividad no válido";
}

// 3. Validar estado
$estados_validos = ['programado', 'publicado', 'activo', 'cerrado'];
if (!in_array($estado, $estados_validos)) {
    $errors[] = "Estado no válido";
}

// 4. 🔒 VALIDACIÓN DE CLAVE FORÁNEA - CRÍTICO
if ($id_asignacion <= 0) {
    $errors[] = "Asignación no válida";
} else {
    // Verificar que la asignación existe y pertenece al profesor.
    // tbl_asignacion_docente no tiene columna id_institucion; id_profesor
    // ya está tenant-verificado (resuelto arriba vía p.id_institucion).
    $query = "SELECT id FROM tbl_asignacion_docente
              WHERE id = :id_asignacion
              AND id_profesor = :id_profesor
              LIMIT 1";
    $stmt = $db->prepare($query);
    $stmt->execute([
        ':id_asignacion' => $id_asignacion,
        ':id_profesor' => $id_profesor
    ]);
    
    if ($stmt->rowCount() === 0) {
        $errors[] = "La asignación seleccionada no existe o no tiene permiso para usarla";
        error_log("ERROR FK: Intento de usar id_asignacion=$id_asignacion con id_profesor=$id_profesor");
    }
}

// 5. Si es edición, verificar que la actividad existe
if ($modo === 'editar' && empty($errors)) {
    if ($id_actividad <= 0) {
        $errors[] = "ID de actividad no válido";
    } else {
        // tbl_actividad no tiene columna id_institucion; id_asignacion ya
        // se verificó como propio del profesor arriba.
        $query = "SELECT id FROM tbl_actividad
                  WHERE id = :id_actividad
                  AND id_asignacion_docente = :id_asignacion
                  LIMIT 1";
        $stmt = $db->prepare($query);
        $stmt->execute([
            ':id_actividad' => $id_actividad,
            ':id_asignacion' => $id_asignacion
        ]);
        
        if ($stmt->rowCount() === 0) {
            $errors[] = "La actividad no existe";
        }
    }
}

// ========================================
// PROCESAR SEGÚN MODO
// ========================================
if (empty($errors)) {
    try {
        $db->beginTransaction();
        
        if ($modo === 'crear') {
            // ========================================
            // INSERT - CREAR NUEVA ACTIVIDAD
            // ========================================
            // tbl_actividad no tiene columna id_institucion (se confirmó
            // contra el esquema real) — insertarla aquí bloqueaba TODA
            // creación de actividad/examen desde el tablón del profesor.
            // tbl_actividad.duracion_minutos es TIME en el esquema real (no
            // INT); se convierte con SEC_TO_TIME desde los minutos del form.
            $query = "INSERT INTO tbl_actividad (
                        titulo, descripcion, tipo, contenido, url_recurso,
                        fecha_programada, fecha_limite, duracion_minutos,
                        nota_maxima, estado, id_asignacion_docente
                    ) VALUES (
                        :titulo, :descripcion, :tipo, :contenido, :url_recurso,
                        :fecha_programada, :fecha_limite, SEC_TO_TIME(:duracion_minutos * 60),
                        :nota_maxima, :estado, :id_asignacion_docente
                    )";

            $stmt = $db->prepare($query);
            $stmt->execute([
                ':titulo' => $titulo,
                ':descripcion' => $descripcion,
                ':tipo' => $tipo,
                // tbl_actividad.contenido es NOT NULL sin default; el
                // formulario no siempre lo captura, así que se completa con
                // la descripción para que el INSERT no falle (mismo patrón
                // usado en gestionar_examenes.php).
                ':contenido' => !empty($contenido) ? $contenido : $descripcion,
                ':url_recurso' => !empty($url_recurso) ? $url_recurso : null,
                ':fecha_programada' => $fecha_programada,
                ':fecha_limite' => $fecha_limite,
                ':duracion_minutos' => $duracion_minutos,
                ':nota_maxima' => $nota_maxima,
                ':estado' => $estado,
                ':id_asignacion_docente' => $id_asignacion
            ]);
            
            $nuevo_id = $db->lastInsertId();
            
            // Crear registros de entrega para todos los estudiantes
            $query_estudiantes = "SELECT m.id_estudiante 
                                  FROM tbl_matricula m
                                  WHERE m.id_seccion = (
                                      SELECT id_seccion FROM tbl_asignacion_docente WHERE id = :id_asignacion
                                  )
                                  AND m.anno = (
                                      SELECT anno FROM tbl_asignacion_docente WHERE id = :id_asignacion2
                                  )
                                  AND m.estado = 'activo'";
            
            $stmt_est = $db->prepare($query_estudiantes);
            $stmt_est->execute([
                ':id_asignacion' => $id_asignacion,
                ':id_asignacion2' => $id_asignacion
            ]);
            $estudiantes = $stmt_est->fetchAll(PDO::FETCH_ASSOC);
            
            if (!empty($estudiantes)) {
                // tbl_entrega_actividad tampoco tiene columna id_institucion.
                $query_entrega = "INSERT INTO tbl_entrega_actividad
                                  (id_actividad, id_estudiante, estado_entrega)
                                  VALUES (:id_actividad, :id_estudiante, 'pendiente')";
                $stmt_entrega = $db->prepare($query_entrega);

                foreach ($estudiantes as $estudiante) {
                    $stmt_entrega->execute([
                        ':id_actividad' => $nuevo_id,
                        ':id_estudiante' => $estudiante['id_estudiante']
                    ]);
                }
            }
            
            $db->commit();
            $_SESSION['success'] = "Actividad creada exitosamente";
            header("Location: tablon.php?asignacion=$id_asignacion");
            exit;
            
        } elseif ($modo === 'editar') {
            // ========================================
            // UPDATE - ACTUALIZAR ACTIVIDAD
            // ========================================
            $query = "UPDATE tbl_actividad SET
                        titulo = :titulo,
                        descripcion = :descripcion,
                        tipo = :tipo,
                        contenido = :contenido,
                        url_recurso = :url_recurso,
                        fecha_programada = :fecha_programada,
                        fecha_limite = :fecha_limite,
                        duracion_minutos = SEC_TO_TIME(:duracion_minutos * 60),
                        nota_maxima = :nota_maxima,
                        estado = :estado
                    WHERE id = :id_actividad
                    AND id_asignacion_docente = :id_asignacion";

            $stmt = $db->prepare($query);
            $stmt->execute([
                ':titulo' => $titulo,
                ':descripcion' => $descripcion,
                ':tipo' => $tipo,
                // contenido es NOT NULL sin default; ver nota en el INSERT arriba.
                ':contenido' => !empty($contenido) ? $contenido : $descripcion,
                ':url_recurso' => !empty($url_recurso) ? $url_recurso : null,
                ':fecha_programada' => $fecha_programada,
                ':fecha_limite' => $fecha_limite,
                ':duracion_minutos' => $duracion_minutos,
                ':nota_maxima' => $nota_maxima,
                ':estado' => $estado,
                ':id_actividad' => $id_actividad,
                ':id_asignacion' => $id_asignacion
            ]);
            
            $db->commit();
            $_SESSION['success'] = "Actividad actualizada exitosamente";
            header("Location: tablon.php?asignacion=$id_asignacion");
            exit;
        }
        
    } catch (PDOException $e) {
        $db->rollBack();
        error_log("Error SQL en procesar_actividad.php: " . $e->getMessage());
        error_log("Query data: " . print_r($_POST, true));
        
        // Mensaje específico para error de FK
        if ($e->getCode() == 23000) {
            $_SESSION['error'] = "Error de integridad: Verifique que la asignación exista";
        } else {
            $_SESSION['error'] = "Error al guardar la actividad: " . $e->getMessage();
        }
        
        $redirect_id = $modo === 'editar' ? "&editar=$id_actividad" : "";
        header("Location: gestionar_actividades.php?asignacion=$id_asignacion$redirect_id");
        exit;
    }
} else {
    // ========================================
    // ERRORES DE VALIDACIÓN
    // ========================================
    $_SESSION['error'] = implode("<br>", $errors);
    $redirect_id = $modo === 'editar' ? "&editar=$id_actividad" : "";
    header("Location: gestionar_actividades.php?asignacion=$id_asignacion$redirect_id");
    exit;
}
?>