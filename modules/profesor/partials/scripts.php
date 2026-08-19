<?php
/**
 * Librerías JS compartidas del módulo de profesor. Se incluye justo antes
 * del <script> propio de cada página (que sigue viviendo en el archivo,
 * sin cambios). Cargar el mismo superset en todas las páginas es
 * intencional: es más simple y seguro que decidir por página qué
 * necesita o no, y el costo de cargar una librería de más es mínimo.
 *
 * jQuery/select2 se envuelven en el propio código de cada página con
 * try/catch donde hace falta (ver gestionar_asignaturas.php como
 * referencia) para que un fallo de CDN no deje inutilizable toda la
 * página -- eso no depende de este archivo, es responsabilidad del JS
 * de cada página.
 */
?>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.datatables.net/1.13.4/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.4/js/dataTables.bootstrap5.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
