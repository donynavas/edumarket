<?php
/**
 * API: Obtener vocabulario por nivel/categoría
 * Método: GET
 * Parámetros: nivel, categoria, limite, buscar
 */

session_start();
header('Content-Type: application/json');
require_once __DIR__ . '/../../../config/database.php';

$nivel = $_GET['nivel'] ?? '';
$categoria = $_GET['categoria'] ?? '';
$limite = $_GET['limite'] ?? 50;
$buscar = $_GET['buscar'] ?? '';

try {
    $database = new Database();
    $db = $database->getConnection();
    
    $query = "SELECT * FROM tbl_ingles_vocabulario WHERE 1=1";
    
    $params = [];
    
    if ($nivel) {
        $query .= " AND nivel = :nivel";
        $params[':nivel'] = $nivel;
    }
    
    if ($categoria) {
        $query .= " AND categoria = :categoria";
        $params[':categoria'] = $categoria;
    }
    
    if ($buscar) {
        $query .= " AND (palabra_ingles LIKE :buscar OR palabra_espanol LIKE :buscar2)";
        $params[':buscar'] = "%{$buscar}%";
        $params[':buscar2'] = "%{$buscar}%";
    }
    
    $query .= " ORDER BY palabra_ingles ASC LIMIT :limite";
    
    $stmt = $db->prepare($query);
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value, PDO::PARAM_STR);
    }
    $stmt->bindValue(':limite', intval($limite), PDO::PARAM_INT);
    $stmt->execute();
    
    $vocabulario = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode([
        'success' => true,
        'data' => [
            'vocabulario' => $vocabulario,
            'total' => count($vocabulario),
            'filtros' => [
                'nivel' => $nivel,
                'categoria' => $categoria,
                'buscar' => $buscar
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