-- migrations/2026_08_26b_manual_convivencia.sql
--
-- Manual de Convivencia Escolar -- Fase 1 (formulario del director +
-- Comité de Convivencia Escolar + catálogo de marco legal editable +
-- vista web + exportación a PDF vía impresión de navegador).
--
-- Ver el plan aprobado para el detalle completo. Estructura de contenidos
-- tomada de la "Guía para Elaborar el Plan de Convivencia Escolar"
-- (MINED, 2ª edición), pág. 28 (esquema de 10 secciones) y págs. 23-24
-- (composición del Comité de Convivencia Escolar).
--
-- IMPORTANTE: esta migración NO trae datos de siembra (INSERT). La
-- siembra de las 9 secciones (II-X) y las 13 referencias de marco legal
-- por defecto se hace en tiempo de ejecución vía
-- config/ManualConvivenciaHelper.php (asegurarSecciones()/
-- asegurarMarcoLegal()), porque necesita id_institucion/id_manual que no
-- existen todavía en tiempo de migración -- mismo patrón ya usado por
-- config/PeriodoHelper.php::asegurar() para tbl_periodo.
--
-- Nombrada con sufijo "b" para no chocar con
-- 2026_08_26_tbl_periodo_y_nota_periodo.sql (mismo día). El orden entre
-- ambas no importa: ninguna depende de la otra.

CREATE TABLE IF NOT EXISTS tbl_manual_convivencia (
    id INT AUTO_INCREMENT PRIMARY KEY,
    id_institucion INT NOT NULL,
    codigo_ce VARCHAR(50) NULL,
    nombre_ce VARCHAR(200) NULL,
    departamento VARCHAR(100) NULL,
    municipio VARCHAR(100) NULL,
    poblacion_descripcion TEXT NULL COMMENT 'I.5 Población que atiende el CE',
    ejes_pncecp TEXT NULL COMMENT 'I.6 Ejes de la Política Nacional para la Convivencia Escolar y Cultura de Paz',
    anno_lectivo INT NOT NULL,
    estado ENUM('borrador','vigente') NOT NULL DEFAULT 'borrador',
    fecha_vigencia_desde DATE NULL,
    created_by INT NULL,
    updated_by INT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_manual_institucion (id_institucion),
    CONSTRAINT fk_mc_institucion FOREIGN KEY (id_institucion) REFERENCES tbl_institucion(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tbl_manual_convivencia_seccion (
    id INT AUTO_INCREMENT PRIMARY KEY,
    id_manual INT NOT NULL,
    codigo VARCHAR(5) NOT NULL COMMENT 'II..X, ver CatalogoConvivencia::SECCIONES',
    contenido MEDIUMTEXT NULL COMMENT 'Texto libre para secciones narrativas (II, IV-X)',
    datos_json JSON NULL COMMENT 'Campos estructurados para la sección III (objetivo_general, objetivos_especificos[])',
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_manual_seccion (id_manual, codigo),
    CONSTRAINT fk_mcs_manual FOREIGN KEY (id_manual) REFERENCES tbl_manual_convivencia(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tbl_manual_convivencia_comite (
    id INT AUTO_INCREMENT PRIMARY KEY,
    id_manual INT NOT NULL,
    nombre_completo VARCHAR(200) NOT NULL,
    rol_comite ENUM('estudiante','docente','administrativo','familia') NOT NULL,
    es_coordinador TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'director/a que coordina el comité',
    genero ENUM('M','F','Otro') NULL,
    id_estudiante INT NULL,
    id_profesor INT NULL,
    fecha_eleccion DATE NULL,
    periodo_vigencia VARCHAR(20) NULL COMMENT 'ej. "2026-2028"',
    activo TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_mcc_manual (id_manual),
    CONSTRAINT fk_mcc_manual FOREIGN KEY (id_manual) REFERENCES tbl_manual_convivencia(id) ON DELETE CASCADE,
    CONSTRAINT fk_mcc_estudiante FOREIGN KEY (id_estudiante) REFERENCES tbl_estudiante(id) ON DELETE SET NULL,
    CONSTRAINT fk_mcc_profesor FOREIGN KEY (id_profesor) REFERENCES tbl_profesor(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tbl_manual_convivencia_marco_legal (
    id INT AUTO_INCREMENT PRIMARY KEY,
    id_institucion INT NOT NULL,
    nombre_norma VARCHAR(255) NOT NULL,
    articulo_referencia VARCHAR(255) NULL,
    descripcion TEXT NULL,
    orden SMALLINT NOT NULL DEFAULT 0,
    activo TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_mclm_institucion (id_institucion),
    CONSTRAINT fk_mclm_institucion FOREIGN KEY (id_institucion) REFERENCES tbl_institucion(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
