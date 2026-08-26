<?php
/**
 * Helper del Manual de Convivencia Escolar (Fase 1). Igual que
 * config/PeriodoHelper.php para tbl_periodo, siembra bajo demanda -- se
 * llama en cada carga de modules/admin/manual_convivencia.php, así que un
 * tenant nuevo obtiene su manual + secciones + marco legal por defecto la
 * primera vez que el director abre la página, sin necesitar un paso
 * manual de "inicializar".
 */
require_once __DIR__ . '/CatalogoConvivencia.php';
require_once __DIR__ . '/HtmlSanitizer.php';

class ManualConvivenciaHelper
{
    /**
     * Obtiene el id del manual de la institución, creándolo si no existe
     * todavía. Al crearlo, prellena Generalidades (I.1-I.4) desde
     * tbl_institucion -- el director puede editarlas después.
     */
    public static function asegurarManual(PDO $db, int $idInstitucion, int $anno): int
    {
        $stmt = $db->prepare("SELECT id FROM tbl_manual_convivencia WHERE id_institucion = :inst");
        $stmt->execute([':inst' => $idInstitucion]);
        $id = $stmt->fetchColumn();
        if ($id !== false) {
            return (int) $id;
        }

        $inst = $db->prepare("SELECT nombre_ce, departamento, municipio, codigo_infra FROM tbl_institucion WHERE id = :id");
        $inst->execute([':id' => $idInstitucion]);
        $datosInst = $inst->fetch(PDO::FETCH_ASSOC) ?: [];

        $insert = $db->prepare(
            "INSERT INTO tbl_manual_convivencia
                (id_institucion, codigo_ce, nombre_ce, departamento, municipio, anno_lectivo)
             VALUES (:inst, :codigo, :nombre, :depto, :muni, :anno)"
        );
        $insert->execute([
            ':inst' => $idInstitucion,
            ':codigo' => $datosInst['codigo_infra'] ?? null,
            ':nombre' => $datosInst['nombre_ce'] ?? null,
            ':depto' => $datosInst['departamento'] ?? null,
            ':muni' => $datosInst['municipio'] ?? null,
            ':anno' => $anno,
        ]);

        return (int) $db->lastInsertId();
    }

    /**
     * Garantiza que existan las filas de secciones II..X para $idManual.
     * Idempotente vía INSERT ... ON DUPLICATE KEY UPDATE id=id (no-op si
     * ya existe), apoyado en la UNIQUE KEY uniq_manual_seccion.
     */
    public static function asegurarSecciones(PDO $db, int $idManual): void
    {
        $stmt = $db->prepare(
            "INSERT INTO tbl_manual_convivencia_seccion (id_manual, codigo)
             VALUES (:manual, :codigo)
             ON DUPLICATE KEY UPDATE id = id"
        );
        foreach (array_keys(CatalogoConvivencia::SECCIONES) as $codigo) {
            $stmt->execute([':manual' => $idManual, ':codigo' => $codigo]);
        }
    }

    /**
     * Siembra el catálogo de marco legal por defecto para la institución
     * SOLO si todavía no tiene ninguna fila (a diferencia de las
     * secciones, aquí no se usa ON DUPLICATE KEY porque no hay una llave
     * natural por norma -- el director puede editar/desactivar/borrar
     * libremente después, así que la siembra debe ser de una sola vez).
     */
    public static function asegurarMarcoLegal(PDO $db, int $idInstitucion): void
    {
        $count = $db->prepare("SELECT COUNT(*) FROM tbl_manual_convivencia_marco_legal WHERE id_institucion = :inst");
        $count->execute([':inst' => $idInstitucion]);
        if ((int) $count->fetchColumn() > 0) {
            return;
        }

        $insert = $db->prepare(
            "INSERT INTO tbl_manual_convivencia_marco_legal
                (id_institucion, nombre_norma, articulo_referencia, descripcion, orden)
             VALUES (:inst, :nombre, :articulo, :descripcion, :orden)"
        );
        foreach (CatalogoConvivencia::MARCO_LEGAL_SEED as $norma) {
            $insert->execute([
                ':inst' => $idInstitucion,
                ':nombre' => $norma['nombre_norma'],
                ':articulo' => $norma['articulo_referencia'],
                ':descripcion' => $norma['descripcion'],
                ':orden' => $norma['orden'],
            ]);
        }
    }

    /**
     * Compara el roster actual del comité contra los mínimos sugeridos
     * por la guía (CatalogoConvivencia::COMITE_MINIMOS/TOTAL_MINIMO).
     * Función pura (sin acceso a BD) -- solo cuenta y resta. Se usa para
     * mostrar un aviso NO bloqueante en el formulario; nunca impide
     * guardar un integrante ni cerrar el manual.
     *
     * @param array $miembros filas de tbl_manual_convivencia_comite (con 'rol_comite' y 'activo')
     * @return array ['total' => int, 'cumple_total' => bool, 'por_rol' => [rol => ['actual'=>int,'minimo'=>int,'cumple'=>bool]]]
     */
    public static function checklistComite(array $miembros): array
    {
        $conteos = array_fill_keys(array_keys(CatalogoConvivencia::COMITE_MINIMOS), 0);
        $total = 0;
        foreach ($miembros as $m) {
            if (isset($m['activo']) && !$m['activo']) {
                continue;
            }
            $rol = $m['rol_comite'] ?? null;
            if ($rol !== null && array_key_exists($rol, $conteos)) {
                $conteos[$rol]++;
            }
            $total++;
        }

        $porRol = [];
        foreach (CatalogoConvivencia::COMITE_MINIMOS as $rol => $minimo) {
            $actual = $conteos[$rol];
            $porRol[$rol] = [
                'actual' => $actual,
                'minimo' => $minimo,
                'cumple' => $actual >= $minimo,
            ];
        }

        return [
            'total' => $total,
            'cumple_total' => $total >= CatalogoConvivencia::COMITE_TOTAL_MINIMO,
            'por_rol' => $porRol,
        ];
    }

    /**
     * Alias delgado hacia HtmlSanitizer::limpiar() (ver config/HtmlSanitizer.php
     * -- misma implementación, extraída de aquí para poder reutilizarse en
     * otros formularios con editor de texto enriquecido, como
     * modules/profesor/impartir_clase.php). Se conserva este método para
     * no romper las llamadas existentes en modules/admin/manual_convivencia.php.
     */
    public static function sanitizarHtml(?string $html): ?string
    {
        return HtmlSanitizer::limpiar($html);
    }
}
