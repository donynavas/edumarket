-- Evita secciones duplicadas (misma letra, mismo grado, mismo año lectivo,
-- misma institución) -- hoy no existía ninguna restricción de unicidad y el
-- formulario de nombre libre de modules/admin/gestionar_grados.php podía
-- crear "A", "A", "A" sin límite para el mismo grado.
--
-- Verificación previa (debe devolver 0 filas antes de aplicar el UNIQUE KEY):
-- SELECT id_grado, anno_lectivo, id_institucion, nombre, COUNT(*) c
-- FROM tbl_seccion GROUP BY id_grado, anno_lectivo, id_institucion, nombre HAVING c > 1;

ALTER TABLE tbl_seccion
  ADD UNIQUE KEY uniq_seccion_grado_anno_inst_letra (id_grado, anno_lectivo, id_institucion, nombre);
