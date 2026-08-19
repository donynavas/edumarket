-- Repara la cuenta huérfana de 'henry1' (profesor de Colegio Edumarket).
-- Confirmado con el dump que subiste el 15/8: el usuario 32 ("henry1")
-- sigue sin fila en tbl_persona (por eso el dashboard mostraba "No se
-- encontró el perfil del profesor"). Este script crea SOLO lo que falta
-- -- tbl_persona y tbl_profesor -- sin tocar el usuario ni la contraseña
-- existentes. Es seguro correrlo más de una vez: si ya existe la fila,
-- no la duplica.
--
-- Edita primer_nombre / primer_apellido abajo con el nombre real antes de
-- correrlo (o corrígelo después con un UPDATE, o desde el panel de admin
-- si tienes una pantalla de "editar perfil").

SET @usuario_objetivo = 'henry1';

START TRANSACTION;

INSERT INTO tbl_persona (id_usuario, primer_nombre, tercer_nombre, primer_apellido, email, estado)
SELECT u.id, 'Henry', '', '(pendiente)', u.email, 'activo'
FROM tbl_usuario u
WHERE u.usuario = @usuario_objetivo
  AND NOT EXISTS (SELECT 1 FROM tbl_persona p WHERE p.id_usuario = u.id);

INSERT INTO tbl_profesor (id_persona, estado, especialidad, titulo_academico, id_institucion)
SELECT per.id, 'activo', 'General', 'Licenciatura', u.id_institucion
FROM tbl_usuario u
JOIN tbl_persona per ON per.id_usuario = u.id
WHERE u.usuario = @usuario_objetivo
  AND NOT EXISTS (SELECT 1 FROM tbl_profesor pr WHERE pr.id_persona = per.id);

COMMIT;

-- Verificación: debe mostrar id_persona e id_profesor con valores (no NULL)
SELECT u.id AS id_usuario, u.usuario, per.id AS id_persona, pr.id AS id_profesor, pr.id_institucion
FROM tbl_usuario u
LEFT JOIN tbl_persona per ON per.id_usuario = u.id
LEFT JOIN tbl_profesor pr ON pr.id_persona = per.id
WHERE u.usuario = @usuario_objetivo;
