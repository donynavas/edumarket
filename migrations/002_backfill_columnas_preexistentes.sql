-- =====================================================================
-- Migración 002: backfill de id_institucion en tablas que YA tenían
-- la columna en el esquema original, pero nunca fueron pobladas.
--
-- Contexto: al auditar modules/admin/gestionar_examenes.php se detectó
-- que tbl_estudiante, tbl_profesor y tbl_asignatura ya incluían la
-- columna id_institucion en el esquema base (educacion_plus.sql), por
-- lo que 001_tenant_isolation.sql no las tocó (solo agrega la columna
-- donde no existía). Sin embargo los datos de prueba tienen filas con
-- id_institucion = NULL, lo que rompe -funcionalmente, no solo por
-- seguridad- los filtros por tenant ya aplicados en:
--   - modules/admin/gestionar_estudiantes.php
--   - modules/admin/gestionar_profesores.php
--   - modules/admin/gestionar_asignaturas.php
--   - modules/admin/gestionar_examenes.php
--
-- tbl_grado se deja fuera a propósito: gestionar_grados.php inserta
-- grados SIN id_institucion (catálogo global de niveles), así que esa
-- tabla no se trata como tenant-scoped en el resto del código.
-- =====================================================================

-- tbl_estudiante: heredar id_institucion desde tbl_persona (id_persona -> persona.id)
UPDATE tbl_estudiante e
JOIN tbl_persona p ON e.id_persona = p.id
SET e.id_institucion = p.id_institucion
WHERE e.id_institucion IS NULL AND p.id_institucion IS NOT NULL;

-- tbl_profesor: heredar id_institucion desde tbl_persona (id_persona -> persona.id)
UPDATE tbl_profesor pr
JOIN tbl_persona p ON pr.id_persona = p.id
SET pr.id_institucion = p.id_institucion
WHERE pr.id_institucion IS NULL AND p.id_institucion IS NOT NULL;

-- tbl_asignatura: heredar id_institucion desde tbl_asignacion_docente
-- (una asignatura ya asignada a algún profesor hereda la institución
-- de esa asignación; asignaturas sin ninguna asignación docente NO se
-- tocan aquí, ver verificación abajo).
UPDATE tbl_asignatura a
JOIN (
    SELECT id_asignatura, MIN(id_institucion) AS id_institucion
    FROM tbl_asignacion_docente
    WHERE id_institucion IS NOT NULL
    GROUP BY id_asignatura
) ad ON ad.id_asignatura = a.id
SET a.id_institucion = ad.id_institucion
WHERE a.id_institucion IS NULL;

-- Verificación: filas que siguen huérfanas después del backfill
SELECT 'tbl_estudiante' AS tabla, COUNT(*) AS huerfanas FROM tbl_estudiante WHERE id_institucion IS NULL
UNION ALL
SELECT 'tbl_profesor', COUNT(*) FROM tbl_profesor WHERE id_institucion IS NULL
UNION ALL
SELECT 'tbl_asignatura', COUNT(*) FROM tbl_asignatura WHERE id_institucion IS NULL;
