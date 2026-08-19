<?php
/**
 * API: Obtener lecciones filtradas
 * Método: GET
 * Parámetros: tipo, nivel, id_curso, limite
 */

session_start();
header('Content-Type: application/json');
require_once __DIR__ . '/../../../config/database.php';

// Nota: el catálogo de lecciones de inglés es contenido compartido entre
// instituciones (no tiene id_institucion en el esquema), por diseño. Sólo
// se exige sesión activa, no se filtra por tenant.
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'No autorizado']);
    exit;
}

$tipo = $_GET['tipo'] ?? '';
$nivel = $_GET['nivel'] ?? '';
$id_curso = $_GET['id_curso'] ?? 0;
$limite = $_GET['limite'] ?? 20;

try {
    $database = new Database();
    $db = $database->getConnection();
    
    $query = "SELECT l.*, c.nombre as curso_nombre, c.nivel as curso_nivel,
              (SELECT COUNT(*) FROM tbl_ingles_ejercicio WHERE id_leccion = l.id) as total_ejercicios,
              (SELECT AVG(puntaje) FROM tbl_ingles_progreso WHERE id_leccion = l.id) as promedio_puntaje
              FROM tbl_ingles_leccion l
              JOIN tbl_ingles_curso c ON l.id_curso = c.id
              WHERE l.estado = 'publicado'";
    
    $params = [];
    
    if ($tipo) {
        $query .= " AND l.tipo = :tipo";
        $params[':tipo'] = $tipo;
    }
    
    if ($nivel) {
        $query .= " AND c.nivel = :nivel";
        $params[':nivel'] = $nivel;
    }
    
    if ($id_curso) {
        $query .= " AND l.id_curso = :id_curso";
        $params[':id_curso'] = $id_curso;
    }
    
    $query .= " ORDER BY l.orden ASC LIMIT :limite";
    
    $stmt = $db->prepare($query);
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value, PDO::PARAM_STR);
    }
    $stmt->bindValue(':limite', intval($limite), PDO::PARAM_INT);
    $stmt->execute();
    
    $lecciones = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode([
        'success' => true,
        'data' => [
            'lecciones' => $lecciones,
            'total' => count($lecciones),
            'filtros' => [
                'tipo' => $tipo,
                'nivel' => $nivel,
                'id_curso' => $id_curso
            ]
        ]
    ]);
    
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => 'Error: ' . $e->getMessage()
    ]);
}
?>