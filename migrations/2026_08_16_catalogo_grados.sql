-- IMPORTANTE: este archivo tiene nombres con tildes/ñ (Séptimo, Primer año,
-- etc). Correrlo con `mysql -u root educacion_plus < este_archivo.sql` SIN
-- especificar el charset del cliente lo deja en la BD MAL codificado (doble
-- UTF-8 -- "SÃ©ptimo" en vez de "Séptimo"), aunque la conexión de la app
-- (config/database.php) sí use utf8mb4 correctamente. Ejecutar siempre así:
--   mysql --default-character-set=utf8mb4 -u root educacion_plus < este_archivo.sql
-- (Esto se detectó y se corrigió en vivo durante la verificación de esta
-- fase -- ver UPDATE de recuperación al final de este archivo si hace falta
-- repetirlo en otro ambiente.)

-- Siembra el catálogo fijo de 15 grados (Parvularia 4/5/6, Primero..Noveno,
-- Primer/Segundo/Tercer año) pedido por el usuario para
-- modules/admin/gestionar_grados.php. tbl_grado ya tenía 4 filas de prueba
-- (Septimo/Octavo/Quinto/Segundo, todas básica) -- se conservan sus ids
-- (referenciados por tbl_seccion) y solo se normaliza su ortografía antes
-- de sembrar las 11 filas que faltan.
--
-- Idempotente: correr dos veces no duplica nada (el INSERT usa
-- WHERE NOT EXISTS, y al final se agrega un UNIQUE KEY como candado
-- adicional contra duplicados futuros, incluso si algún día se vuelve a
-- permitir nombre libre en el formulario).

-- Verificación previa (debe devolver 0 filas antes de aplicar el UNIQUE KEY):
-- SELECT nombre, nivel, COUNT(*) c FROM tbl_grado GROUP BY nombre, nivel HAVING c > 1;

UPDATE tbl_grado SET nombre = 'Séptimo' WHERE nombre = 'Septimo' AND nivel = 'basica';

INSERT INTO tbl_grado (nombre, nivel, nota_minima_aprobacion, id_institucion)
SELECT c.nombre, c.nivel, c.nota_minima, NULL
FROM (
    SELECT 'Parvularia 4' AS nombre, 'basica' AS nivel, 6.0 AS nota_minima UNION ALL
    SELECT 'Parvularia 5', 'basica', 6.0 UNION ALL
    SELECT 'Parvularia 6', 'basica', 6.0 UNION ALL
    SELECT 'Primero',      'basica', 6.0 UNION ALL
    SELECT 'Segundo',      'basica', 6.0 UNION ALL
    SELECT 'Tercero',      'basica', 6.0 UNION ALL
    SELECT 'Cuarto',       'basica', 6.0 UNION ALL
    SELECT 'Quinto',       'basica', 6.0 UNION ALL
    SELECT 'Sexto',        'basica', 6.0 UNION ALL
    SELECT 'Séptimo',      'basica', 6.0 UNION ALL
    SELECT 'Octavo',       'basica', 6.0 UNION ALL
    SELECT 'Noveno',       'basica', 6.0 UNION ALL
    SELECT 'Primer año',   'bachillerato', 7.0 UNION ALL
    SELECT 'Segundo año',  'bachillerato', 7.0 UNION ALL
    SELECT 'Tercer año',   'bachillerato', 7.0
) c
WHERE NOT EXISTS (
    SELECT 1 FROM tbl_grado g WHERE g.nombre = c.nombre AND g.nivel = c.nivel
);

ALTER TABLE tbl_grado ADD UNIQUE KEY uniq_grado_nombre_nivel (nombre, nivel);

-- Recuperación (solo si este archivo se corrió sin --default-character-set=utf8mb4
-- y quedaron nombres doblemente codificados como "SÃ©ptimo"/"Primer aÃ±o"):
-- UPDATE tbl_grado
-- SET nombre = CONVERT(CAST(CONVERT(nombre USING latin1) AS BINARY) USING utf8mb4)
-- WHERE HEX(nombre) LIKE '%C383%';
