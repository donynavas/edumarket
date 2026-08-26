<?php
// api/guardar_rubrica.php — Crear/actualizar una rúbrica (plantilla) de la
// biblioteca personal de un profesor. Recibe JSON (no FormData, a
// diferencia de guardar_pregunta_banco.php) porque la matriz de niveles x
// criterios x celdas es una estructura anidada que no vale la pena aplanar
// a POST fields.
//
// Edición = reemplazo completo: se borran TODOS los niveles/criterios de
// esa rúbrica (las celdas caen en cascada) y se re-insertan desde cero,
// construyendo tablas de remapeo $mapNivel[clave_cliente]=>id_real /
// $mapCriterio[clave_cliente]=>id_real -- así ningún id de base de datos
// que el cliente pudiera enviar se usa directamente como FK.
//
// tbl_rubrica SÍ tiene columna id_institucion propia (igual que
// tbl_banco_preguntas), confirmado en la migración
// 2026_08_20_rubricas_y_banco_comentarios.sql.

session_start();
require_once __DIR__ . '/../../../config/database.php';
require_once __DIR__ . '/../../../config/TenantGuard.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id']) || $_SESSION['rol'] !== 'profesor') {
    echo json_encode(['success' => false, 'message' => 'No autorizado']);
    exit;
}

$db = (new Database())->getConnection();
$tid = TenantGuard::id();
$user_id = $_SESSION['user_id'];

$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) {
    echo json_encode(['success' => false, 'message' => 'Datos inválidos']);
    exit;
}

try {
    $db->beginTransaction();

    $stmtProf = $db->prepare("SELECT p.id FROM tbl_profesor p
                              JOIN tbl_persona per ON p.id_persona = per.id
                              WHERE per.id_usuario = :uid AND p.id_institucion = :tid");
    $stmtProf->execute([':uid' => $user_id, ':tid' => $tid]);
    $id_profesor = $stmtProf->fetchColumn();
    if (!$id_profesor) {
        throw new Exception('Perfil de profesor no encontrado');
    }

    $nombre = trim($input['nombre'] ?? '');
    if ($nombre === '') {
        throw new Exception('El nombre es obligatorio');
    }
    $descripcion = trim($input['descripcion'] ?? '');
    $descripcion = $descripcion !== '' ? $descripcion : null;

    $niveles = is_array($input['niveles'] ?? null) ? $input['niveles'] : [];
    $criterios = is_array($input['criterios'] ?? null) ? $input['criterios'] : [];
    if (empty($niveles) || empty($criterios)) {
        throw new Exception('La rúbrica necesita al menos un nivel y un criterio');
    }

    $id_rubrica = !empty($input['id_rubrica']) ? (int) $input['id_rubrica'] : 0;

    if ($id_rubrica > 0) {
        // Editar -- solo su propia PLANTILLA (nunca una instancia ya copiada
        // a una actividad, que es de solo lectura una vez creada).
        $stmtCheck = $db->prepare("SELECT id FROM tbl_rubrica WHERE id = :id AND id_profesor = :prof AND id_institucion = :tid AND id_actividad IS NULL");
        $stmtCheck->execute([':id' => $id_rubrica, ':prof' => $id_profesor, ':tid' => $tid]);
        if (!$stmtCheck->fetch()) {
            throw new Exception('No tiene permiso para editar esta rúbrica');
        }

        $db->prepare("UPDATE tbl_rubrica SET nombre = :nombre, descripcion = :descripcion WHERE id = :id")
           ->execute([':nombre' => $nombre, ':descripcion' => $descripcion, ':id' => $id_rubrica]);

        // Reemplazo completo: borrar criterios (arrastra sus celdas) y
        // niveles existentes; se re-insertan desde cero más abajo.
        $db->prepare("DELETE FROM tbl_rubrica_criterio WHERE id_rubrica = :id")->execute([':id' => $id_rubrica]);
        $db->prepare("DELETE FROM tbl_rubrica_nivel WHERE id_rubrica = :id")->execute([':id' => $id_rubrica]);
    } else {
        $stmt = $db->prepare("INSERT INTO tbl_rubrica (id_institucion, id_profesor, id_actividad, nombre, descripcion, estado)
                              VALUES (:tid, :prof, NULL, :nombre, :descripcion, 'activo')");
        $stmt->execute([':tid' => $tid, ':prof' => $id_profesor, ':nombre' => $nombre, ':descripcion' => $descripcion]);
        $id_rubrica = (int) $db->lastInsertId();
    }

    // Niveles: clave del cliente (string arbitraria, ej. "n1") -> id real.
    $mapNivel = [];
    $stmtInsNivel = $db->prepare("INSERT INTO tbl_rubrica_nivel (id_rubrica, nombre, orden) VALUES (:rub, :nombre, :orden)");
    foreach ($niveles as $i => $niv) {
        $nombreNivel = trim($niv['nombre'] ?? '');
        $clave = (string) ($niv['key'] ?? '');
        if ($nombreNivel === '' || $clave === '') {
            continue;
        }
        $stmtInsNivel->execute([':rub' => $id_rubrica, ':nombre' => $nombreNivel, ':orden' => (int) ($niv['orden'] ?? $i)]);
        $mapNivel[$clave] = (int) $db->lastInsertId();
    }
    if (empty($mapNivel)) {
        throw new Exception('La rúbrica necesita al menos un nivel con nombre');
    }

    // Criterios + sus celdas (una celda por nivel presente en $mapNivel).
    $stmtInsCriterio = $db->prepare("INSERT INTO tbl_rubrica_criterio (id_rubrica, nombre, descripcion, orden) VALUES (:rub, :nombre, :descripcion, :orden)");
    $stmtInsCelda = $db->prepare("INSERT INTO tbl_rubrica_celda (id_criterio, id_nivel, descripcion, puntaje) VALUES (:crit, :niv, :descripcion, :puntaje)");
    $totalCriteriosGuardados = 0;
    foreach ($criterios as $i => $crit) {
        $nombreCriterio = trim($crit['nombre'] ?? '');
        if ($nombreCriterio === '') {
            continue;
        }
        $descripcionCriterio = trim($crit['descripcion'] ?? '');
        $descripcionCriterio = $descripcionCriterio !== '' ? $descripcionCriterio : null;

        $stmtInsCriterio->execute([
            ':rub' => $id_rubrica, ':nombre' => $nombreCriterio,
            ':descripcion' => $descripcionCriterio, ':orden' => (int) ($crit['orden'] ?? $i),
        ]);
        $id_criterio = (int) $db->lastInsertId();
        $totalCriteriosGuardados++;

        $celdas = is_array($crit['celdas'] ?? null) ? $crit['celdas'] : [];
        foreach ($mapNivel as $claveNivel => $idNivelReal) {
            $celda = $celdas[$claveNivel] ?? [];
            $descripcionCelda = trim($celda['descripcion'] ?? '');
            $puntaje = is_numeric($celda['puntaje'] ?? null) ? (float) $celda['puntaje'] : 0.0;
            $stmtInsCelda->execute([
                ':crit' => $id_criterio, ':niv' => $idNivelReal,
                ':descripcion' => $descripcionCelda !== '' ? $descripcionCelda : null, ':puntaje' => $puntaje,
            ]);
        }
    }
    if ($totalCriteriosGuardados === 0) {
        throw new Exception('La rúbrica necesita al menos un criterio con nombre');
    }

    $db->commit();
    echo json_encode(['success' => true, 'id_rubrica' => $id_rubrica, 'message' => 'Rúbrica guardada en la biblioteca']);

} catch (Exception $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
