<?php
/**
 * Corrige tbl_asignatura.id_institucion = NULL en todas las filas (bug
 * encontrado en una fase anterior de esta sesión y nunca arreglado --
 * migrations/002_backfill_columnas_preexistentes.sql lo intentaba pero
 * referenciaba tbl_asignacion_docente.id_institucion, columna que no
 * existe en esa tabla; ese bloque nunca funcionó).
 *
 * Complicación real confirmada en vivo contra la BD del sandbox: la
 * asignatura "Informática" (id=1) está referenciada por
 * tbl_asignacion_docente de profesores de DOS instituciones distintas
 * (1 y 4) -- un backfill directo de una sola institución no es seguro,
 * porque cualquiera de las dos perdería su propia fila.
 *
 * Es un script PHP (no .sql puro) porque el caso "varias instituciones"
 * necesita lógica procedural por fila (clonar + re-apuntar
 * tbl_asignacion_docente) -- excepción deliberada a la convención de esta
 * sesión de que las migraciones son .sql.
 *
 * Idempotente: solo toca filas con id_institucion IS NULL; en una segunda
 * corrida no queda ninguna, así que no hace nada.
 *
 * Uso por terminal/SSH:  php migrations/2026_08_16_fix_asignatura_institucion.php
 *
 * Uso sin terminal (hosting solo con phpMyAdmin/FTP): subir este archivo
 * dentro de la carpeta migrations/ del proyecto y abrirlo UNA VEZ en el
 * navegador (https://tu-dominio/migrations/2026_08_16_fix_asignatura_institucion.php),
 * habiendo iniciado sesión como admin/director en otra pestaña del MISMO
 * navegador primero (este script exige esa sesión antes de tocar la BD).
 * NO se debe pegar este archivo en el cuadro "Importar" de phpMyAdmin --
 * es PHP, no SQL; phpMyAdmin solo entiende sentencias SQL puras.
 */

require __DIR__ . '/../config/database.php';

// Si se abre por navegador (no por CLI), exigir sesión de admin/director --
// este script escribe en la BD, no debe quedar abierto a cualquiera que
// adivine la URL. Por CLI (terminal/SSH) no hay sesión de navegador que
// verificar, así que se omite el chequeo.
$esNavegador = php_sapi_name() !== 'cli';
if ($esNavegador) {
    session_start();
    header('Content-Type: text/html; charset=utf-8');
    if (!isset($_SESSION['user_id']) || !in_array($_SESSION['rol'] ?? '', ['admin', 'director'], true)) {
        http_response_code(403);
        echo '<p>Acceso denegado. Inicia sesión como admin/director en este mismo navegador y vuelve a abrir esta página.</p>';
        exit;
    }
    echo '<pre>';
}

$db = (new Database())->getConnection();
$db->beginTransaction();

try {
    $rows = $db->query("SELECT id, nombre, codigo FROM tbl_asignatura WHERE id_institucion IS NULL")->fetchAll(PDO::FETCH_ASSOC);

    foreach ($rows as $row) {
        $stmt = $db->prepare("SELECT DISTINCT p.id_institucion
            FROM tbl_asignacion_docente ad
            JOIN tbl_profesor p ON ad.id_profesor = p.id
            WHERE ad.id_asignatura = :id AND p.id_institucion IS NOT NULL
            ORDER BY p.id_institucion");
        $stmt->execute([':id' => $row['id']]);
        $instituciones = $stmt->fetchAll(PDO::FETCH_COLUMN);

        if (count($instituciones) === 0) {
            // Huérfana: ninguna asignación docente la referencia. No hay
            // columna de auditoría/creador en el esquema para inferir el
            // dueño original -- se asigna por defecto a la institución de
            // menor id y se deja registrado en el log para revisión manual.
            $fallback = (int) $db->query("SELECT MIN(id) FROM tbl_institucion")->fetchColumn();
            $db->prepare("UPDATE tbl_asignatura SET id_institucion = :i WHERE id = :id")
               ->execute([':i' => $fallback, ':id' => $row['id']]);
            echo "[huerfana]  asignatura {$row['id']} ({$row['nombre']}) -> institucion $fallback (sin referencias, revisar manualmente)\n";
            continue;
        }

        // Se queda con la primera institución en la fila original...
        $duena = array_shift($instituciones);
        $db->prepare("UPDATE tbl_asignatura SET id_institucion = :i WHERE id = :id")
           ->execute([':i' => $duena, ':id' => $row['id']]);
        echo "[backfill]  asignatura {$row['id']} ({$row['nombre']}) -> institucion $duena (fila original)\n";

        // ...y clona una fila nueva por cada institución adicional,
        // re-apuntando SOLO las tbl_asignacion_docente de esa institución
        // hacia el clon (las de $duena se quedan en el id original).
        foreach ($instituciones as $otraInst) {
            $ins = $db->prepare("INSERT INTO tbl_asignatura (nombre, codigo, id_institucion) VALUES (:n, :c, :i)");
            $ins->execute([':n' => $row['nombre'], ':c' => $row['codigo'], ':i' => $otraInst]);
            $nuevoId = (int) $db->lastInsertId();

            $upd = $db->prepare("UPDATE tbl_asignacion_docente ad
                JOIN tbl_profesor p ON ad.id_profesor = p.id
                SET ad.id_asignatura = :nuevo
                WHERE ad.id_asignatura = :orig AND p.id_institucion = :inst");
            $upd->execute([':nuevo' => $nuevoId, ':orig' => $row['id'], ':inst' => $otraInst]);

            echo "  [clon]    institucion $otraInst -> nueva asignatura $nuevoId, {$upd->rowCount()} asignacion(es) docente repointed\n";
        }
    }

    $db->commit();
    echo "OK -- " . count($rows) . " fila(s) de tbl_asignatura procesadas.\n";
} catch (Throwable $e) {
    $db->rollBack();
    // STDERR solo existe bajo CLI -- usarlo directo bajo un servidor web
    // (Apache/nginx+PHP-FPM) provoca un fatal "Undefined constant STDERR"
    // que taparía el mensaje de error real.
    if ($esNavegador) {
        echo "ERROR: {$e->getMessage()}\n</pre>";
    } else {
        fwrite(STDERR, "ERROR: {$e->getMessage()}\n");
    }
    exit(1);
}

if ($esNavegador) {
    echo '</pre>';
}
