<?php
/**
 * Siembra bajo demanda los períodos de calificación de un (institución,
 * año): 3 para educación básica, 4 para bachillerato, con los rangos de
 * fecha exactos que dio el usuario. No es una migración de una sola vez
 * -- se llama desde modules/admin/cuadro_notas.php en cada carga, así se
 * extiende automáticamente a años futuros sin tocar código.
 *
 * Nota sobre los rangos de bachillerato: se solapan un mes en cada borde
 * (Abr aparece en el período 1 y 2, Jun en el 2 y 3) -- así los dio el
 * usuario, se implementan literalmente. Hoy fecha_inicio/fecha_fin son
 * solo informativos (ningún cálculo de nota depende de ellos), así que el
 * solape es cosmético.
 */
class PeriodoHelper
{
    /**
     * Garantiza que existan las filas de tbl_periodo para $idInstitucion +
     * $anno. Idempotente vía INSERT ... ON DUPLICATE KEY UPDATE, apoyado
     * en la UNIQUE KEY uniq_periodo(anno, nivel, numero, id_institucion).
     */
    /**
     * Definiciones crudas de periodo por nivel: [numero, nombre, fecha_inicio, fecha_fin].
     * Única fuente de verdad de nombres/rangos -- asegurar() y cuadro_notas.php
     * (Fase 6) la comparten para no duplicar los rótulos "Período N (...)".
     */
    public static function definiciones(int $anno): array
    {
        return [
            'basica' => [
                [1, "Período 1 (Feb-Mar-Abr)", "$anno-02-01", "$anno-04-30"],
                [2, "Período 2 (May-Jun-Jul)", "$anno-05-01", "$anno-07-31"],
                [3, "Período 3 (Ago-Sep-Oct-Nov)", "$anno-08-01", "$anno-11-30"],
            ],
            'bachillerato' => [
                [1, "Período 1 (Feb-Abr)", "$anno-02-01", "$anno-04-30"],
                [2, "Período 2 (Abr-Jun)", "$anno-04-01", "$anno-06-30"],
                [3, "Período 3 (Jun-Ago)", "$anno-06-01", "$anno-08-31"],
                [4, "Período 4 (Sep-Nov)", "$anno-09-01", "$anno-11-30"],
            ],
        ];
    }

    public static function asegurar(PDO $db, int $idInstitucion, int $anno): void
    {
        $defs = self::definiciones($anno);

        $stmt = $db->prepare(
            "INSERT INTO tbl_periodo (anno, nivel, numero, nombre, fecha_inicio, fecha_fin, id_institucion)
             VALUES (:anno, :nivel, :numero, :nombre, :inicio, :fin, :inst)
             ON DUPLICATE KEY UPDATE nombre = VALUES(nombre), fecha_inicio = VALUES(fecha_inicio), fecha_fin = VALUES(fecha_fin)"
        );

        foreach ($defs as $nivel => $periodos) {
            foreach ($periodos as [$numero, $nombre, $inicio, $fin]) {
                $stmt->execute([
                    ':anno' => $anno,
                    ':nivel' => $nivel,
                    ':numero' => $numero,
                    ':nombre' => $nombre,
                    ':inicio' => $inicio,
                    ':fin' => $fin,
                    ':inst' => $idInstitucion,
                ]);
            }
        }
    }

    /** Devuelve el id de tbl_periodo para (institución, año, nivel, número). Llama a asegurar() primero si hace falta. */
    public static function obtenerId(PDO $db, int $idInstitucion, int $anno, string $nivel, int $numero): ?int
    {
        $stmt = $db->prepare("SELECT id FROM tbl_periodo WHERE id_institucion = :inst AND anno = :anno AND nivel = :nivel AND numero = :numero");
        $stmt->execute([':inst' => $idInstitucion, ':anno' => $anno, ':nivel' => $nivel, ':numero' => $numero]);
        $id = $stmt->fetchColumn();
        if ($id !== false) {
            return (int) $id;
        }

        self::asegurar($db, $idInstitucion, $anno);

        $stmt->execute([':inst' => $idInstitucion, ':anno' => $anno, ':nivel' => $nivel, ':numero' => $numero]);
        $id = $stmt->fetchColumn();
        return $id !== false ? (int) $id : null;
    }
}
