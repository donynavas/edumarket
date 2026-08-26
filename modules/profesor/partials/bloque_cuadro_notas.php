<?php
/**
 * Bloque reusable "¿Es nota evaluada? Vincular al Cuadro de Notas",
 * extraído de gestionar_actividades.php para poder incluirse en más de un
 * formulario en la misma página (p.ej. los 3 modales de Cierre de
 * modules/profesor/impartir_clase.php: Asignar tarea/examen/Actividad).
 *
 * Variables que el llamador debe definir ANTES de hacer el require:
 *   $periodos_cuadro  array  filas de tbl_periodo (id, numero, nombre)
 *   $casillas_cuadro  array  filas de CuadroNotasHelper::casillasDisponibles() (valor, label)
 *   $idPrefix         string opcional (default '') -- se antepone a los
 *                     id="" de los <select> para poder incluir este bloque
 *                     varias veces en una misma página sin que los ids
 *                     choquen. Los name="" NO llevan prefijo a propósito:
 *                     cada bloque vive dentro de un <form> independiente,
 *                     así que solo se envía un id_periodo/casilla por POST.
 *
 * El servidor siempre revalida el vínculo con
 * ActividadHelper::resolverVinculoCuadroNotas() al guardar -- este bloque
 * es solo la UI; nunca es la única fuente de verdad.
 */
$idPrefix = $idPrefix ?? '';
?>
<!-- Vinculación al Cuadro de Notas (cualquier tipo de actividad) -->
<div id="<?= $idPrefix ?>bloque_cuadro_notas" class="col-12 d-none">
    <hr>
    <label class="form-label mb-1"><i class="fas fa-clipboard-list"></i> ¿Es nota evaluada? Vincular al Cuadro de Notas</label>
    <small class="text-muted d-block mb-2">
        Opcional para cualquier tipo de actividad. Si eliges un período y una casilla, la
        nota final de esta actividad se copiará automáticamente a esa casilla del Cuadro
        de Notas (podrás seguir ajustándola a mano ahí si hace falta). Si dejas ambos en
        "No vincular", la actividad no cuenta como nota evaluada.
    </small>
    <?php if (empty($periodos_cuadro)): ?>
    <p class="text-muted small mb-0">No hay períodos configurados todavía para esta asignación.</p>
    <?php else: ?>
    <div class="row g-2">
        <div class="col-md-6">
            <label class="form-label small">Período</label>
            <select name="id_periodo" id="<?= $idPrefix ?>cn_id_periodo" class="form-select form-select-sm">
                <option value="">No vincular</option>
                <?php foreach ($periodos_cuadro as $p): ?>
                <option value="<?= $p['id'] ?>"><?= htmlspecialchars($p['nombre']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-6">
            <label class="form-label small">Casilla</label>
            <select name="casilla" id="<?= $idPrefix ?>cn_casilla" class="form-select form-select-sm">
                <option value="">No vincular</option>
                <?php foreach ($casillas_cuadro as $c): ?>
                <option value="<?= htmlspecialchars($c['valor']) ?>"><?= htmlspecialchars($c['label']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
    </div>
    <?php endif; ?>
</div>
