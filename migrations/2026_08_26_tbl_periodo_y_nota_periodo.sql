-- migrations/2026_08_26_tbl_periodo_y_nota_periodo.sql
--
-- Migración faltante: tbl_periodo y tbl_nota_periodo sostienen TODO el
-- "puente" entre Actividades y el Cuadro de Notas (ver
-- config/PeriodoHelper.php, config/CuadroNotasHelper.php,
-- modules/admin/cuadro_notas.php, y las columnas
-- tbl_actividad.id_periodo/bloque_notas/numero_nota agregadas por
-- migrations/2026_08_16_puente_actividades_cuadro_notas.sql), pero se
-- crearon directamente contra la base de datos del sandbox en la sesión
-- que las construyó ("Fase 5"/"Fase 6") y NUNCA se guardaron como
-- migración -- por eso un despliegue nuevo que solo corre los archivos de
-- migrations/ nunca tiene estas dos tablas, y el ALTER TABLE de
-- 2026_08_16_puente_actividades_cuadro_notas.sql (que hace
-- FOREIGN KEY (id_periodo) REFERENCES tbl_periodo(id)) fallaría si se
-- corriera antes de que tbl_periodo exista.
--
-- IMPORTANTE PARA UN DESPLIEGUE NUEVO DESDE CERO: correr este archivo
-- ANTES que 2026_08_16_puente_actividades_cuadro_notas.sql. Si se
-- importan todos los archivos de migrations/ juntos en una sola corrida
-- (como ya hace el usuario), el orden no importa porque ambas migraciones
-- usan formas idempotentes (IF NOT EXISTS / columnas ya existentes).
--
-- Forma EXACTA capturada con SHOW CREATE TABLE contra el sandbox donde
-- ya llevan semanas funcionando -- IF NOT EXISTS hace que en ESTE
-- sandbox la migración sea un no-op seguro.

CREATE TABLE IF NOT EXISTS tbl_periodo (
    id INT AUTO_INCREMENT PRIMARY KEY,
    anno INT NOT NULL,
    nivel ENUM('basica','bachillerato') NOT NULL,
    numero TINYINT(2) NOT NULL COMMENT '1..3 para básica, 1..4 para bachillerato',
    nombre VARCHAR(100) NOT NULL,
    fecha_inicio DATE NULL,
    fecha_fin DATE NULL,
    id_institucion INT NULL,
    UNIQUE KEY uniq_periodo (anno, nivel, numero, id_institucion)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tbl_nota_periodo (
    id INT AUTO_INCREMENT PRIMARY KEY,
    id_asignacion_docente INT NOT NULL,
    id_matricula INT NOT NULL,
    id_periodo INT NOT NULL,
    bloque ENUM('unico','bloque1','bloque2','examen') NOT NULL DEFAULT 'unico',
    numero_nota TINYINT(2) NOT NULL DEFAULT 1,
    valor DECIMAL(4,2) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_nota (id_asignacion_docente, id_matricula, id_periodo, bloque, numero_nota),
    KEY idx_matricula (id_matricula),
    KEY idx_periodo (id_periodo),
    CONSTRAINT fk_notaperiodo_asignacion FOREIGN KEY (id_asignacion_docente) REFERENCES tbl_asignacion_docente(id) ON DELETE CASCADE,
    CONSTRAINT fk_notaperiodo_matricula FOREIGN KEY (id_matricula) REFERENCES tbl_matricula(id) ON DELETE CASCADE,
    CONSTRAINT fk_notaperiodo_periodo FOREIGN KEY (id_periodo) REFERENCES tbl_periodo(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
