-- Expediente Digital del Docente (lado Director) — hoja de vida esencial.
-- Ninguna tabla lleva id_institucion propia: todas cuelgan de tbl_profesor
-- (que sí la tiene) vía id_profesor, con ON DELETE CASCADE. Idempotente.

CREATE TABLE IF NOT EXISTS tbl_expediente_docente (
    id INT AUTO_INCREMENT PRIMARY KEY,
    id_profesor INT NOT NULL,
    foto_ruta VARCHAR(255) NULL,
    contacto_emergencia_nombre VARCHAR(150) NULL,
    contacto_emergencia_telefono VARCHAR(20) NULL,
    contacto_emergencia_parentesco VARCHAR(50) NULL,
    notas TEXT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_expediente_profesor (id_profesor),
    CONSTRAINT fk_ed_profesor FOREIGN KEY (id_profesor) REFERENCES tbl_profesor(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tbl_expediente_estudio (
    id INT AUTO_INCREMENT PRIMARY KEY,
    id_profesor INT NOT NULL,
    grado_academico VARCHAR(100) NOT NULL COMMENT 'Bachillerato/Técnico/Licenciatura/Ingeniería/Maestría/Doctorado/Otro',
    titulo VARCHAR(200) NOT NULL COMMENT 'Especialidad o título obtenido',
    institucion_educativa VARCHAR(200) NOT NULL,
    anio_graduacion SMALLINT NULL,
    ruta_documento VARCHAR(255) NULL,
    orden SMALLINT NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_ee_profesor (id_profesor),
    CONSTRAINT fk_ee_profesor FOREIGN KEY (id_profesor) REFERENCES tbl_profesor(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tbl_expediente_capacitacion (
    id INT AUTO_INCREMENT PRIMARY KEY,
    id_profesor INT NOT NULL,
    institucion VARCHAR(200) NOT NULL,
    nombre_capacitacion VARCHAR(255) NOT NULL,
    anio SMALLINT NOT NULL,
    duracion_horas SMALLINT NULL,
    ruta_documento VARCHAR(255) NULL,
    orden SMALLINT NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_ec_profesor (id_profesor),
    CONSTRAINT fk_ec_profesor FOREIGN KEY (id_profesor) REFERENCES tbl_profesor(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tbl_expediente_experiencia (
    id INT AUTO_INCREMENT PRIMARY KEY,
    id_profesor INT NOT NULL,
    institucion VARCHAR(200) NOT NULL,
    cargo VARCHAR(150) NOT NULL,
    fecha_desde DATE NOT NULL,
    fecha_hasta DATE NULL COMMENT 'NULL = actualmente vigente',
    ruta_documento VARCHAR(255) NULL,
    orden SMALLINT NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_eexp_profesor (id_profesor),
    CONSTRAINT fk_eexp_profesor FOREIGN KEY (id_profesor) REFERENCES tbl_profesor(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tbl_expediente_documento (
    id INT AUTO_INCREMENT PRIMARY KEY,
    id_profesor INT NOT NULL,
    etiqueta VARCHAR(150) NOT NULL,
    ruta_archivo VARCHAR(255) NOT NULL,
    mime VARCHAR(100) NULL,
    tamano_bytes INT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_edoc_profesor (id_profesor),
    CONSTRAINT fk_edoc_profesor FOREIGN KEY (id_profesor) REFERENCES tbl_profesor(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
