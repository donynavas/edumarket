<?php
/**
 * API: Crear asignación para estudiantes
 * Método: POST
 * Parámetros: id_profesor, tipo, id_curso, id_leccion, id_seccion, fecha_limite, puntaje_minimo, instrucciones
 */

session_start();
header('Content-Type: application/json');
require_once __DIR__ . '/../../../config/database.php';
require_once __DIR__ . '/../../../config/TenantGuard.php';

if (!isset($_SESSION['user_id']) || $_SESSION['rol'] != 'profesor') {
    echo json_encode([
        'success' => false,
        'message' => 'No autorizado. Solo profesores pueden crear asignaciones.'
    ]);
    exit;
}

$tid = TenantGuard::id();
$tipo = $_POST['tipo'] ?? 'curso'; // curso o leccion
$id_curso = $_POST['curso'] ?? null;
$id_leccion = $_POST['leccion'] ?? null;
$id_seccion = $_POST['id_seccion'] ?? null;
$fecha_limite = $_POST['fecha_limite'] ?? null;
$puntaje_minimo = $_POST['puntaje_minimo'] ?? 7.0;
$instrucciones = $_POST['instrucciones'] ?? '';

try {
    $database = new Database();
    $db = $database->getConnection();
    $db->beginTransaction();

    // El profesor SIEMPRE se deriva de la sesión — nunca se confía en un
    // id_profesor enviado por el cliente (evita crear asignaciones a nombre
    // de otro profesor, incluso de otra institución).
    $stmtProf = $db->prepare(
        "SELECT p.id FROM tbl_profesor p
         JOIN tbl_persona per ON p.id_persona = per.id
         WHERE per.id_usuario = :uid AND p.id_institucion = :tid"
    );
    $stmtProf->execute([':uid' => $_SESSION['user_id'], ':tid' => $tid]);
    $id_profesor = $stmtProf->fetchColumn();
    if (!$id_profesor) {
        throw new Exception('No se pudo resolver el profesor de la sesión actual');
    }

    // Validar curso o lección
    if ($tipo == 'curso' && !$id_curso) {
        throw new Exception('Curso requerido para asignación de tipo curso');
    }

    if ($tipo == 'leccion' && !$id_leccion) {
        throw new Exception('Lección requerida para asignación de tipo lección');
    }

    // Obtener estudiantes de la sección — sólo si la sección pertenece a la
    // institución del profesor (evita asignar tarea a una sección ajena).
    $estudiantes = [];
    if ($id_seccion) {
        $query = "SELECT e.id FROM tbl_estudiante e
                  JOIN tbl_matricula m ON e.id = m.id_estudiante
                  JOIN tbl_seccion s ON m.id_seccion = s.id
                  WHERE m.id_seccion = :id_seccion AND m.estado = 'activo'
                    AND s.id_institucion = :tid";
        $stmt = $db->prepare($query);
        $stmt->bindValue(':id_seccion', $id_seccion, PDO::PARAM_INT);
        $stmt->bindValue(':tid', $tid, PDO::PARAM_INT);
        $stmt->execute();
        $estudiantes = $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    // Crear asignación para cada estudiante
    $asignaciones_creadas = 0;
    foreach ($estudiantes as $id_estudiante) {
        // tbl_ingles_asignacion no tiene columna id_institucion (se confirmó
        // contra el esquema real) — insertarla aquí bloqueaba TODA asignación
        // de inglés. id_profesor y los estudiantes ya se verificaron arriba.
        $query = "INSERT INTO tbl_ingles_asignacion
                  (id_profesor, id_curso, id_leccion, id_seccion, id_estudiante,
                   fecha_asignacion, fecha_limite, estado, instrucciones, puntaje_minimo)
                  VALUES (:id_profesor, :id_curso, :id_leccion, :id_seccion, :id_estudiante,
                          NOW(), :fecha_limite, 'pendiente', :instrucciones, :puntaje_minimo)";

        $stmt = $db->prepare($query);
        $stmt->bindValue(':id_profesor', $id_profesor, PDO::PARAM_INT);
        $stmt->bindValue(':id_curso', $id_curso, $id_curso ? PDO::PARAM_INT : PDO::PARAM_NULL);
        $stmt->bindValue(':id_leccion', $id_leccion, $id_leccion ? PDO::PARAM_INT : PDO::PARAM_NULL);
        $stmt->bindValue(':id_seccion', $id_seccion, $id_seccion ? PDO::PARAM_INT : PDO::PARAM_NULL);
        $stmt->bindValue(':id_estudiante', $id_estudiante, PDO::PARAM_INT);
        $stmt->bindValue(':fecha_limite', $fecha_limite, $fecha_limite ? PDO::PARAM_STR : PDO::PARAM_NULL);
        $stmt->bindValue(':instrucciones', $instrucciones, PDO::PARAM_STR);
        $stmt->bindValue(':puntaje_minimo', $puntaje_minimo, PDO::PARAM_STR);
        $stmt->execute();

        $asignaciones_creadas++;
    }
    
    $db->commit();
    
    echo json_encode([
        'success' => true,
        'message' => "Asignación creada para {$asignaciones_creadas} estudiantes",
        'data' => [
            'tipo' => $tipo,
            'id_curso' => $id_curso,
            'id_leccion' => $id_leccion,
            'estudiantes_asignados' => $asignaciones_creadas,
            'fecha_limite' => $fecha_limite,
            'puntaje_minimo' => $puntaje_minimo
        ]
    ]);
    
} catch (Exception $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    
    echo json_encode([
        'success' => false,
        'message' => 'Error: ' . $e->getMessage()
    ]);
}
?>