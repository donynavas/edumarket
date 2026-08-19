-- Agrega a tbl_actividad las columnas necesarias para el "puente" entre
-- Actividades (tareas/exámenes que crea el profesor) y el Cuadro de Notas
-- (modules/admin/cuadro_notas.php, con sus casillas fijas n1..n8 en básica
-- o Bloque1/Bloque2/Examen en bachillerato).
--
-- id_periodo:     a qué período real (tbl_periodo) corresponde la nota de
--                 esta actividad. NULL = la actividad no está vinculada al
--                 Cuadro de Notas (se sigue calificando solo en
--                 Actividades/Calificaciones, como hasta ahora).
-- bloque_notas:   'unico' (básica, n1..n8), 'bloque1'/'bloque2'/'examen'
--                 (bachillerato) -- mismos valores que tbl_nota_periodo.bloque.
-- numero_nota:    1-8 en básica, 1-4 en bloque1/bloque2, 1 en examen.
--
-- Es SQL puro y seguro de pegar directo en la pestaña "SQL" de phpMyAdmin.
-- Es idempotente por sí solo la primera vez; si ya corriste esta migración
-- antes, ALTER TABLE fallará con "Duplicate column name" -- eso es
-- normal y no indica ningún problema (ya estaba aplicada).

ALTER TABLE tbl_actividad
  ADD COLUMN id_periodo INT NULL AFTER id_examen,
  ADD COLUMN bloque_notas ENUM('unico','bloque1','bloque2','examen') NULL AFTER id_periodo,
  ADD COLUMN numero_nota TINYINT(2) NULL AFTER bloque_notas,
  ADD CONSTRAINT fk_actividad_periodo FOREIGN KEY (id_periodo) REFERENCES tbl_periodo(id) ON DELETE SET NULL;
