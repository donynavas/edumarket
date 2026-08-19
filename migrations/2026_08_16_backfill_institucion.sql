-- Backfill de tbl_estudiante.id_institucion / tbl_profesor.id_institucion.
--
-- Encontrado durante la verificación en vivo de la Fase 5 (entregar_examen.php):
-- TODO el módulo de estudiante (tomar_examen.php, entregar_examen.php,
-- actividades.php, calendario_evaluaciones.php, estudiante_dashboard.php,
-- mis_clases.php, mis_notas.php, ver_materia.php) filtra el aislamiento de
-- tenant con "tbl_estudiante.id_institucion = :tid" (columna propia de la
-- tabla, no un JOIN a tbl_usuario). Los 3 estudiantes de prueba del sandbox
-- (ids 8, 9, 10) tienen esa columna en NULL -- ninguna de esas páginas puede
-- encontrar sus propios datos, aunque tbl_usuario.id_institucion sí está
-- correcto para su cuenta de login. Se confirma el mismo patrón en
-- tbl_profesor: 4 de 7 filas (ids 1-4) tienen id_institucion NULL mientras
-- que las filas más nuevas (5-7) sí tienen el valor y coincide exactamente
-- con tbl_usuario.id_institucion -- es decir, la columna quedó sin
-- "backfillear" para las cuentas creadas antes de agregar esta columna.
--
-- Este UPDATE es un backfill puntual (no borra ni sobreescribe nada que ya
-- tenga valor) que copia el id_institucion real desde tbl_usuario, vía
-- tbl_persona, para dejar consistentes las filas antiguas.

UPDATE tbl_estudiante e
JOIN tbl_persona per ON e.id_persona = per.id
JOIN tbl_usuario u ON per.id_usuario = u.id
SET e.id_institucion = u.id_institucion
WHERE e.id_institucion IS NULL AND u.id_institucion IS NOT NULL;

UPDATE tbl_profesor pr
JOIN tbl_persona per ON pr.id_persona = per.id
JOIN tbl_usuario u ON per.id_usuario = u.id
SET pr.id_institucion = u.id_institucion
WHERE pr.id_institucion IS NULL AND u.id_institucion IS NOT NULL;
