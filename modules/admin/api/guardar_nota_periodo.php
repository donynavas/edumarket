<?php
/**
 * Guarda las notas de un periodo (básica: n1-n8 "unico"; bachillerato:
 * n1-n4 "bloque1"/"bloque2" + "examen") para una asignación docente, un
 * periodo, y varios estudiantes matriculados a la vez -- llamado por AJAX
 * desde modules/admin/cuadro_notas.php (Fase 6 del plan).
 *
 * Nivel/sección/año se resuelven SIEMPRE desde la asignación en la BD,
 * nunca desde lo que mande el cliente -- evita que alguien manipule el
 * POST para escribir bloques de bachillerato en una asignación de básica,
 * o notas de una sección ajena a la asignación.
 */
session_start();
require_once __DIR__ . '/../../../config/database.php';
require_once __DIR__ . '/../../../config/TenantGuard.php';
require_once __DIR__ . '/../../../config/PeriodoHelper.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id']) || ($_SESSION['rol'] != 'admin' && $_SESSION['rol'] != 'director')) {
    echo json_encode(['success' => false, 'message' => 'No autorizado']);
    exit;
}

$db = (new Database())->getConnection();
$tid = TenantGuard::id();

try {
    $db->beginTransaction();

    $id_asignacion = filter_input(INPUT_POST, 'id_asignacion', FILTER_VALIDATE_INT);
    $numero_periodo = filter_input(INPUT_POST, 'periodo', FILTER_VALIDATE_INT);
    if (!$id_asignacion || !$numero_periodo) {
        throw new Exception('Parámetros incompletos');
    }

    // 403 si la asignación no pertenece a esta institución.
    TenantGuard::assertOwner($db, 'tbl_asignacion_docente', $id_asignacion);

    $stmtAsig = $db->prepare("SELECT ad.id_seccion, ad.anno, g.nivel
        FROM tbl_asignacion_docente ad
        JOIN tbl_seccion s ON ad.id_seccion = s.id
        JOIN tbl_grado g ON s.id_grado = g.id
        WHERE ad.id = :id");
    $stmtAsig->execute([':id' => $id_asignacion]);
    $asig = $stmtAsig->fetch(PDO::FETCH_ASSOC);
    if (!$asig) {
        throw new Exception('Asignación no encontrada');
    }

    $nivel = $asig['nivel'];
    $id_seccion = (int) $asig['id_seccion'];
    $anno = (int) $asig['anno'];

    $maxPeriodo = $nivel === 'basica' ? 3 : 4;
    if ($numero_periodo < 1 || $numero_periodo > $maxPeriodo) {
        throw new Exception('Periodo inválido para el nivel de esta asignación');
    }

    $id_periodo = PeriodoHelper::obtenerId($db, $tid, $anno, $nivel, $numero_periodo);
    if (!$id_periodo) {
        throw new Exception('No se pudo resolver el periodo');
    }

    // Solo se aceptan matrículas que de verdad pertenezcan a la sección/año
    // de esta asignación -- evita que un id_matricula manipulado en el POST
    // reciba notas de un estudiante ajeno (mismo patrón "checkMatricula" ya
    // usado en modules/profesor/calificaciones.php y asistencia.php).
    $checkMatricula = $db->prepare("SELECT id FROM tbl_matricula WHERE id = :id AND id_seccion = :sec AND anno = :anno AND estado = 'activo'");

    $upsert = $db->prepare("INSERT INTO tbl_nota_periodo (id_asignacion_docente, id_matricula, id_periodo, bloque, numero_nota, valor)
        VALUES (:asig, :mat, :per, :bloque, :num, :valor)
        ON DUPLICATE KEY UPDATE valor = VALUES(valor)");

    // Mapa de nombre de campo del formulario -> [bloque, numero_nota].
    // Básica solo usa 'unico' 1-8; bachillerato usa bloque1/bloque2 (1-4
    // cada uno) + examen (numero_nota fijo 1).
    if ($nivel === 'basica') {
        $campos = [];
        for ($i = 1; $i <= 8; $i++) { $campos["n$i"] = ['unico', $i]; }
    } else {
        $campos = [];
        for ($i = 1; $i <= 4; $i++) {
            $campos["b1n$i"] = ['bloque1', $i];
            $campos["b2n$i"] = ['bloque2', $i];
        }
        $campos['ex'] = ['examen', 1];
    }

    $notas = $_POST['notas'] ?? [];
    $actualizadas = 0;

    foreach ($notas as $id_matricula => $valores) {
        $id_matricula = (int) $id_matricula;
        $checkMatricula->execute([':id' => $id_matricula, ':sec' => $id_seccion, ':anno' => $anno]);
        if (!$checkMatricula->fetch()) {
            continue; // matrícula ajena a esta sección/año: se ignora
        }
        if (!is_array($valores)) {
            continue;
        }
        foreach ($campos as $campo => $destino) {
            if (!array_key_exists($campo, $valores) || $valores[$campo] === '') {
                continue; // celda vacía: no se upsertea (permite dejar notas incompletas)
            }
            $valor = filter_var($valores[$campo], FILTER_VALIDATE_FLOAT);
            if ($valor === false) {
                continue;
            }
            $valor = max(0, min(10, $valor));
            [$bloque, $numero] = $destino;
            $upsert->execute([
                ':asig' => $id_asignacion,
                ':mat' => $id_matricula,
                ':per' => $id_periodo,
                ':bloque' => $bloque,
                ':num' => $numero,
                ':valor' => $valor,
            ]);
            $actualizadas++;
        }
    }

    $db->commit();
    echo json_encode(['success' => true, 'actualizadas' => $actualizadas]);
} catch (Exception $e) {
    $db->rollBack();
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
