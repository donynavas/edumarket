-- Recordatorio automático de fecha_limite próxima (ver config/RecordatorioHelper.php).
-- Un solo flag booleano basta: el recordatorio es un evento único por
-- actividad (no una serie de avisos escalonados 7d/2d/1d) -- una tabla de
-- seguimiento aparte sería sobre-ingeniería para lo que pide hoy la
-- funcionalidad. El índice compuesto mantiene barata la consulta de barrido
-- que corre en cada carga de página (ver puntos de disparo en
-- modules/profesor/partials/header.php y
-- modules/estudiante/estudiante_dashboard.php).
ALTER TABLE tbl_actividad
    ADD COLUMN recordatorio_enviado TINYINT(1) NOT NULL DEFAULT 0 AFTER estado,
    ADD INDEX idx_actividad_recordatorio (recordatorio_enviado, fecha_limite);
