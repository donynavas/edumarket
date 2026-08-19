<?php
/**
 * api/generar_alertas.php
 * Detección automática de alertas de bienestar.
 *
 * Llamar vía cron o desde el dashboard admin.
 * Ejemplo cron (diario a las 6 AM):
 *   0 6 * * * php /ruta/al/proyecto/modules/admin/api/generar_alertas.php
 *
 * Ubicación final: modules/admin/api/generar_alertas.php
 */

// Permitir ejecución desde CLI (cron, procesa todas las instituciones) o
// desde web (solo admin/director de UNA institución, debe quedar acotado a
// la suya — de lo contrario generaría/leería alertas de otras instituciones).
require_once __DIR__ . '/../../../config/TenantGuard.php';
$tid = null; // null = sin acotar (uso legítimo solo desde CLI/cron)
if (php_sapi_name() !== 'cli') {
    session_start();
    require_once __DIR__ . '/../../../config/database.php';
    if (!isset($_SESSION['user_id']) || !in_array($_SESSION['rol'], ['admin', 'director'])) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'No autorizado']);
        exit;
    }
    $tid = TenantGuard::id();
} else {
    require_once __DIR__ . '/../../../config/database.php';
}

$db = (new Database())->getConnection();

$alertas_creadas = 0;
$errores         = [];

try {
    // ── 1. Alerta por ausencias consecutivas (≥4 días) ──────────────────
    // Requiere tabla tbl_bienestar_asistencia; si no existe, omitir este bloque.
    /*
    $stmt = $db->query(
        "SELECT id_estudiante, COUNT(*) AS dias_ausente
         FROM tbl_asistencia
         WHERE estado='ausente'
           AND fecha >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
         GROUP BY id_estudiante
         HAVING dias_ausente >= 4"
    );
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        insertar_alerta($db, $row['id_estudiante'], 'ausencias',
            "Ausencias consecutivas: {$row['dias_ausente']} días en los últimos 7 días.", 'alta');
        $alertas_creadas++;
    }
    */

    // ── 2. Alerta por caída de notas significativa (>20 puntos) ─────────
    // Compara promedio del periodo actual vs anterior en tbl_nota
    $stmt = $db->query(
        "SELECT n.id_estudiante,
                AVG(n.nota_final) AS promedio_actual,
                (
                    SELECT AVG(n2.nota_final)
                    FROM tbl_nota n2
                    WHERE n2.id_estudiante = n.id_estudiante
                      AND n2.periodo = n.periodo - 1
                ) AS promedio_anterior
         FROM tbl_nota n
         WHERE n.periodo = (SELECT MAX(periodo) FROM tbl_nota)
         GROUP BY n.id_estudiante
         HAVING promedio_anterior IS NOT NULL
            AND (promedio_anterior - promedio_actual) > 20"
    );
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $diff = round($row['promedio_anterior'] - $row['promedio_actual'], 1);
        insertar_alerta(
            $db,
            (int)$row['id_estudiante'],
            'notas',
            "Caída de promedio de {$diff} puntos respecto al periodo anterior.",
            $diff > 30 ? 'alta' : 'media'
        );
        $alertas_creadas++;
    }

    // ── 3. Alertas por reportes de docentes que solicitan derivación ─────
    $sqlReportes = "SELECT r.id_estudiante, COUNT(*) AS total_reportes
         FROM tbl_bienestar_reporte_docente r
         JOIN tbl_estudiante e ON r.id_estudiante = e.id
         WHERE r.derivar = 1 AND r.atendido = 0
           AND NOT EXISTS (
               SELECT 1 FROM tbl_bienestar_alerta a
               WHERE a.id_estudiante = r.id_estudiante
                 AND a.tipo = 'reporte_docente' AND a.atendida = 0
           )"
        . ($tid !== null ? " AND e.id_institucion = :tid" : "") .
        " GROUP BY r.id_estudiante
         HAVING total_reportes >= 1";
    $stmt = $db->prepare($sqlReportes);
    $stmt->execute($tid !== null ? [':tid' => $tid] : []);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        insertar_alerta(
            $db,
            (int)$row['id_estudiante'],
            'reporte_docente',
            "{$row['total_reportes']} reporte(s) de docente solicitan atención del orientador.",
            'media'
        );
        $alertas_creadas++;
    }

} catch (PDOException $e) {
    $errores[] = $e->getMessage();
    error_log("generar_alertas PDO: " . $e->getMessage());
}

$resultado = [
    'success'          => empty($errores),
    'alertas_creadas'  => $alertas_creadas,
    'errores'          => $errores,
    'timestamp'        => date('Y-m-d H:i:s'),
];

if (php_sapi_name() === 'cli') {
    echo json_encode($resultado, JSON_PRETTY_PRINT) . "\n";
} else {
    header('Content-Type: application/json');
    echo json_encode($resultado);
}

// ── Helper ────────────────────────────────────────────────────────────────
function insertar_alerta(PDO $db, int $id_est, string $tipo, string $desc, string $nivel): void {
    // No duplicar alertas del mismo tipo/estudiante si ya existe una pendiente
    $check = $db->prepare(
        "SELECT id FROM tbl_bienestar_alerta
         WHERE id_estudiante=:e AND tipo=:t AND atendida=0 LIMIT 1"
    );
    $check->execute([':e' => $id_est, ':t' => $tipo]);
    if ($check->fetch()) {
        return; // ya existe, no duplicar
    }

    // Verificar que el estudiante exista y pertenezca a una institución
    // antes de crear la alerta (evita filas huérfanas). tbl_bienestar_alerta
    // en sí no tiene columna id_institucion (se confirmó contra el esquema
    // real) — insertarla ahí bloqueaba TODA generación de alertas.
    $inst = $db->prepare("SELECT id_institucion FROM tbl_estudiante WHERE id = :e");
    $inst->execute([':e' => $id_est]);
    $idInstitucion = $inst->fetchColumn();
    if (!$idInstitucion) return; // estudiante inexistente o sin institución: no crear alerta huérfana

    $db->prepare(
        "INSERT INTO tbl_bienestar_alerta (id_estudiante, tipo, descripcion, nivel)
         VALUES (:e, :t, :d, :n)"
    )->execute([':e' => $id_est, ':t' => $tipo, ':d' => $desc, ':n' => $nivel]);
}
