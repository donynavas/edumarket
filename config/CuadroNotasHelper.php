<?php
/**
 * Puente entre Actividades (tareas/exámenes calificados por el profesor en
 * modules/profesor/calificaciones.php y modules/estudiante/api/entregar_examen.php)
 * y el Cuadro de Notas (modules/admin/cuadro_notas.php, tbl_nota_periodo).
 *
 * Una actividad puede vincularse opcionalmente a una casilla específica del
 * Cuadro de Notas (tbl_actividad.id_periodo/bloque_notas/numero_nota, todos
 * NULL si no está vinculada). Cuando un estudiante recibe nota final en una
 * actividad vinculada, sincronizar() copia esa nota (convertida a escala
 * 0-10) a tbl_nota_periodo -- el profesor sigue pudiendo editarla a mano
 * después desde el propio Cuadro de Notas si hace falta un ajuste.
 */
class CuadroNotasHelper
{
    /**
     * Sincroniza la nota de un estudiante en una actividad hacia su casilla
     * vinculada del Cuadro de Notas (si la actividad tiene una). No hace
     * nada si la actividad no está vinculada o si $valorSobreDiez es NULL
     * (nota aún no calificada).
     */
    public static function sincronizar(PDO $db, int $idActividad, int $idMatricula, ?float $valorSobreDiez): void
    {
        if ($valorSobreDiez === null) {
            return;
        }

        $stmt = $db->prepare("SELECT id_asignacion_docente, id_periodo, bloque_notas, numero_nota
                              FROM tbl_actividad WHERE id = :id");
        $stmt->execute([':id' => $idActividad]);
        $act = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$act || !$act['id_periodo'] || !$act['bloque_notas'] || !$act['numero_nota']) {
            return; // actividad no vinculada al Cuadro de Notas
        }

        $valor = max(0, min(10, round($valorSobreDiez, 2)));

        $stmt = $db->prepare("INSERT INTO tbl_nota_periodo
                (id_asignacion_docente, id_matricula, id_periodo, bloque, numero_nota, valor)
            VALUES (:asig, :mat, :per, :bloque, :num, :valor)
            ON DUPLICATE KEY UPDATE valor = VALUES(valor)");
        $stmt->execute([
            ':asig' => $act['id_asignacion_docente'],
            ':mat' => $idMatricula,
            ':per' => $act['id_periodo'],
            ':bloque' => $act['bloque_notas'],
            ':num' => $act['numero_nota'],
            ':valor' => $valor,
        ]);
    }

    /**
     * Casillas disponibles según el nivel del grado, en el mismo orden y
     * con las mismas etiquetas que usa cuadro_notas.php. Cada casilla es
     * ['valor' => 'n3' | 'b1n2' | 'b2n4' | 'examen', 'bloque' => ..., 'numero_nota' => ..., 'label' => ...].
     * 'valor' es el string que viaja en el <select> del formulario de
     * Actividades; bloque/numero_nota son los que realmente se guardan.
     */
    public static function casillasDisponibles(string $nivel): array
    {
        if ($nivel === 'bachillerato') {
            $casillas = [];
            for ($i = 1; $i <= 4; $i++) {
                $casillas[] = ['valor' => "b1n$i", 'bloque' => 'bloque1', 'numero_nota' => $i, 'label' => "Bloque 1 - n$i"];
            }
            for ($i = 1; $i <= 4; $i++) {
                $casillas[] = ['valor' => "b2n$i", 'bloque' => 'bloque2', 'numero_nota' => $i, 'label' => "Bloque 2 - n$i"];
            }
            $casillas[] = ['valor' => 'examen', 'bloque' => 'examen', 'numero_nota' => 1, 'label' => 'Examen'];
            return $casillas;
        }

        // básica (y cualquier otro valor no reconocido, por seguridad)
        $casillas = [];
        for ($i = 1; $i <= 8; $i++) {
            $casillas[] = ['valor' => "n$i", 'bloque' => 'unico', 'numero_nota' => $i, 'label' => "n$i"];
        }
        return $casillas;
    }

    /** Encuentra la definición de casilla (bloque/numero_nota) a partir del string 'valor' del <select>. */
    public static function resolverCasilla(string $nivel, string $valor): ?array
    {
        foreach (self::casillasDisponibles($nivel) as $casilla) {
            if ($casilla['valor'] === $valor) {
                return $casilla;
            }
        }
        return null;
    }
}
