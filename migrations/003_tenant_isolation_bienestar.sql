-- =====================================================================
-- Migración 003: id_institucion en las tablas del módulo de Bienestar
-- Estudiantil (modules/admin/bienestar_estudiantil.php), que no habían
-- sido cubiertas por 001_tenant_isolation.sql.
-- =====================================================================

ALTER TABLE tbl_bienestar_seguimiento ADD COLUMN IF NOT EXISTS id_institucion INT(11) DEFAULT NULL AFTER id_orientador;
UPDATE tbl_bienestar_seguimiento bs
JOIN tbl_estudiante e ON bs.id_estudiante = e.id
SET bs.id_institucion = e.id_institucion
WHERE bs.id_institucion IS NULL;
ALTER TABLE tbl_bienestar_seguimiento ADD INDEX IF NOT EXISTS idx_bienseg_institucion (id_institucion);

ALTER TABLE tbl_bienestar_sesion ADD COLUMN IF NOT EXISTS id_institucion INT(11) DEFAULT NULL AFTER id_seguimiento;
UPDATE tbl_bienestar_sesion bse
JOIN tbl_bienestar_seguimiento bs ON bse.id_seguimiento = bs.id
SET bse.id_institucion = bs.id_institucion
WHERE bse.id_institucion IS NULL;
ALTER TABLE tbl_bienestar_sesion ADD INDEX IF NOT EXISTS idx_biensesion_institucion (id_institucion);

ALTER TABLE tbl_bienestar_reporte_docente ADD COLUMN IF NOT EXISTS id_institucion INT(11) DEFAULT NULL AFTER id_estudiante;
UPDATE tbl_bienestar_reporte_docente br
JOIN tbl_estudiante e ON br.id_estudiante = e.id
SET br.id_institucion = e.id_institucion
WHERE br.id_institucion IS NULL;
ALTER TABLE tbl_bienestar_reporte_docente ADD INDEX IF NOT EXISTS idx_bienrep_institucion (id_institucion);

-- Verificación
SELECT 'tbl_bienestar_seguimiento' AS tabla, COUNT(*) AS huerfanas FROM tbl_bienestar_seguimiento WHERE id_institucion IS NULL
UNION ALL
SELECT 'tbl_bienestar_sesion', COUNT(*) FROM tbl_bienestar_sesion WHERE id_institucion IS NULL
UNION ALL
SELECT 'tbl_bienestar_reporte_docente', COUNT(*) FROM tbl_bienestar_reporte_docente WHERE id_institucion IS NULL;
