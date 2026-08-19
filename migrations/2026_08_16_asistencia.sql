-- Habilita el registro de asistencia diaria (modules/profesor/asistencia.php).
--
-- tbl_asistencia existía en el esquema pero NADIE escribía en ella en todo
-- el repo (0 INSERT/UPDATE) -- se construye la página desde cero en esta
-- fase. Dos cambios de esquema:
--
--   1. Se agrega 'permiso' al enum de estado (decisión confirmada con el
--      usuario: además de presente/ausente, se necesita un tercer estado
--      para "con permiso"). Se conservan 'tarde'/'justificada' aunque hoy
--      la UI sólo ofrece los 3 botones Presente/Ausente/Permiso, por si ya
--      hay filas o reportes que los usen en otro módulo.
--
--   2. No existía ninguna restricción de unicidad sobre (id_matricula,
--      fecha): guardar la asistencia del mismo grupo dos veces el mismo
--      día crearía filas duplicadas en vez de actualizar. Se agrega la
--      UNIQUE KEY para poder hacer INSERT ... ON DUPLICATE KEY UPDATE.

ALTER TABLE tbl_asistencia
    MODIFY COLUMN estado ENUM('presente','ausente','tarde','justificada','permiso') DEFAULT NULL;

ALTER TABLE tbl_asistencia
    ADD UNIQUE KEY uk_asistencia_matricula_fecha (id_matricula, fecha);
