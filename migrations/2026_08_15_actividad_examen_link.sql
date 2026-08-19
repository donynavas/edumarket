-- Vincular Actividades (Tablón) con el motor de exámenes real, y corregir
-- dos enums de tbl_actividad que no coincidían con los valores que el
-- formulario de gestionar_actividades.php ya venía enviando.
--
-- Bug 1 (silencioso, bloqueaba CASI TODA creación de actividad): el <select>
-- "Estado" del modal trae 'publicado' seleccionado por defecto, pero
-- tbl_actividad.estado sólo aceptaba ('programado','activo','cerrado').
-- Con sql_mode=STRICT_TRANS_TABLES (activo en este servidor), insertar
-- 'publicado' lanza "Data truncated for column 'estado'" y la transacción
-- se revierte — es decir, CUALQUIER actividad nueva fallaba a menos que el
-- profesor cambiara manualmente el Estado antes de guardar.
--
-- Bug 2 (mismo síntoma): el <select> "Tipo" ofrece video/youtube/articulo/
-- referencia/podcast/revista/enlace, pero tbl_actividad.tipo sólo aceptaba
-- ('tarea','examen','foro','recurso','laboratorio'). Cualquier tipo fuera
-- de esa lista fallaba igual.
--
-- Además: se agrega id_examen para que una actividad tipo="examen" quede
-- enlazada a un examen real (tbl_examen/tbl_pregunta_examen/
-- tbl_opcion_respuesta) — el mismo motor autocalificable que ya usan
-- asignar_examen.php/crear_examen.php/tomar_examen.php — en vez de ser
-- sólo un anuncio sin preguntas de verdad.

ALTER TABLE tbl_actividad
    MODIFY COLUMN tipo ENUM(
        'tarea','examen','foro','recurso','laboratorio',
        'video','youtube','articulo','referencia','podcast','revista','enlace'
    ) DEFAULT NULL;

ALTER TABLE tbl_actividad
    MODIFY COLUMN estado ENUM('programado','publicado','activo','cerrado','eliminado')
    DEFAULT 'programado';

ALTER TABLE tbl_actividad
    ADD COLUMN id_examen INT NULL AFTER id_asignacion_docente,
    ADD CONSTRAINT fk_actividad_examen FOREIGN KEY (id_examen) REFERENCES tbl_examen(id) ON DELETE SET NULL,
    ADD INDEX idx_actividad_examen (id_examen);
