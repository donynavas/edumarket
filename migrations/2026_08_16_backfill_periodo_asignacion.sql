-- Backfill de tbl_asignacion_docente.id_periodo para las asignaciones
-- creadas mientras id_periodo se dejaba en NULL (Fase 4 de esta sesión,
-- revertida en modules/admin/gestionar_profesores.php: ver el comentario
-- junto al INSERT en asignarMaterias()).
--
-- Motivo: modules/estudiante/estudiante_dashboard.php, mis_clases.php,
-- actividades.php, calendario_evaluaciones.php, modules/profesor/estudiantes.php
-- y modules/admin/calificaciones.php|reporte_notas.php filtran por
-- "ad.id_periodo = :periodo", con :periodo cayendo a 1 por defecto en casi
-- todos lados. Con id_periodo NULL esa comparación nunca es verdadera en
-- SQL, así que cualquier asignación creada entre el deploy de Fase 4 y este
-- fix quedaba invisible para el estudiante -- un examen/actividad asignado
-- que nunca aparecía en su Dashboard/Mis Clases/Actividades.
--
-- Este script pone esas filas en el período 1 (Primer Trimestre), el mismo
-- valor por defecto que ahora vuelve a usar el formulario "Asignar Materias".
-- Es SQL puro y seguro de pegar directo en la pestaña "SQL" de phpMyAdmin
-- (no hace falta el importador de archivos). Es idempotente: una segunda
-- corrida no encuentra ninguna fila con id_periodo NULL y no hace nada.
--
-- Si alguna de estas asignaciones en realidad era para un período distinto
-- al primero, corrígela manualmente después con un UPDATE puntual por id,
-- o edítala desde la propia aplicación si ya cuenta con esa opción.

UPDATE tbl_asignacion_docente
SET id_periodo = 1
WHERE id_periodo IS NULL;
