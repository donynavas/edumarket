-- Corrige tbl_entrega_actividad para que el cuadro de notas del profesor
-- (modules/profesor/calificaciones.php) funcione de verdad.
--
-- Hallazgo: varias consultas en modules/profesor/{calificaciones,reportes,
-- gestionar_estudiantes}.php referenciaban columnas que NUNCA existieron en
-- esta tabla (ea.id_estudiante, ea.retroalimentacion, ea.fecha_calificacion)
-- o hacían joins sin sentido (ea.id = e.id, comparando la PK de la entrega
-- con la PK del estudiante). Las columnas reales son id_matricula y
-- observacion_docente. El código se corrige aparte; aquí sólo se agrega lo
-- que realmente falta en el esquema:
--
--   fecha_calificacion: la UI ya mostraba/guardaba una fecha de calificación
--   (icono de check con la fecha) pero la columna no existía -> cada intento
--   de calificar una entrega lanzaba una excepción SQL real.
--
--   UNIQUE KEY (id_actividad, id_matricula): el cuadro de notas ahora lista
--   a TODOS los estudiantes matriculados en la sección de la actividad (no
--   solo a quienes ya tienen una fila de entrega), para que el profesor
--   pueda calificar aunque el estudiante no haya "entregado" nada (ej.
--   participación). Guardar una nota hace INSERT ... ON DUPLICATE KEY
--   UPDATE sobre (id_actividad, id_matricula); sin esta llave única se
--   crearían filas duplicadas cada vez que se guarda.

ALTER TABLE tbl_entrega_actividad
    ADD COLUMN fecha_calificacion DATETIME NULL AFTER fecha_entrega;

ALTER TABLE tbl_entrega_actividad
    ADD UNIQUE KEY uk_entrega_actividad_matricula (id_actividad, id_matricula);
