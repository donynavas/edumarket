-- migrations/2026_08_20_demeritos.sql
-- Control de Deméritos (Reglamento de Cortesía Escolar, MINEDUCYT).
--
-- tbl_demerito, tbl_demerito_redencion y tbl_demerito_consecuencia son
-- tablas de LOG insert-only: un estudiante puede recibir la misma
-- categoría de demérito varias veces el mismo día, así que NO es un
-- upsert-por-clave-única como tbl_asistencia. tbl_demerito_observacion es
-- la excepción: 1 nota libre por matrícula+mes, sí se sobreescribe.
--
-- id_matricula (no id_estudiante) como FK en las 4, igual que
-- tbl_asistencia, para heredar el mismo patrón de verificación de
-- propiedad (sección/año/estado='activo') ya probado en asistencia.php.
-- id_institucion directo en las 4 (igual que tbl_rubrica/tbl_banco_comentario)
-- para que TenantGuard::assertOwner() funcione sin tocar su mapa
-- $viaTenantColumn.

CREATE TABLE IF NOT EXISTS tbl_demerito (
    id INT AUTO_INCREMENT PRIMARY KEY,
    id_institucion INT NOT NULL,
    id_matricula INT NOT NULL,
    categoria ENUM('no_saludar','omitir_favor','omitir_gracias','tono_grosero') NOT NULL,
    fecha DATE NOT NULL,
    hora TIME NOT NULL,
    id_profesor_registro INT NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_demerito_institucion FOREIGN KEY (id_institucion) REFERENCES tbl_institucion(id),
    CONSTRAINT fk_demerito_matricula FOREIGN KEY (id_matricula) REFERENCES tbl_matricula(id),
    CONSTRAINT fk_demerito_profesor FOREIGN KEY (id_profesor_registro) REFERENCES tbl_profesor(id),
    INDEX idx_demerito_tenant (id_institucion),
    INDEX idx_demerito_matricula_fecha (id_matricula, fecha)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tbl_demerito_redencion (
    id INT AUTO_INCREMENT PRIMARY KEY,
    id_institucion INT NOT NULL,
    id_matricula INT NOT NULL,
    actividad ENUM('semana_cortesia','apoyo_orden_limpieza','campana_valores') NOT NULL,
    fecha DATE NOT NULL,
    hora TIME NOT NULL,
    cantidad_redimida INT NOT NULL,
    id_profesor_registro INT NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_redencion_institucion FOREIGN KEY (id_institucion) REFERENCES tbl_institucion(id),
    CONSTRAINT fk_redencion_matricula FOREIGN KEY (id_matricula) REFERENCES tbl_matricula(id),
    CONSTRAINT fk_redencion_profesor FOREIGN KEY (id_profesor_registro) REFERENCES tbl_profesor(id),
    INDEX idx_redencion_tenant (id_institucion),
    INDEX idx_redencion_matricula_fecha (id_matricula, fecha)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- cantidad_redimida > 0 se valida en PHP (server-side), no se confía en un
-- CHECK de MySQL/MariaDB (versiones viejas lo parsean pero lo ignoran).

CREATE TABLE IF NOT EXISTS tbl_demerito_consecuencia (
    id INT AUTO_INCREMENT PRIMARY KEY,
    id_institucion INT NOT NULL,
    id_matricula INT NOT NULL,
    fecha DATE NOT NULL,
    descripcion VARCHAR(500) NOT NULL,
    id_profesor_registro INT NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_consecuencia_institucion FOREIGN KEY (id_institucion) REFERENCES tbl_institucion(id),
    CONSTRAINT fk_consecuencia_matricula FOREIGN KEY (id_matricula) REFERENCES tbl_matricula(id),
    CONSTRAINT fk_consecuencia_profesor FOREIGN KEY (id_profesor_registro) REFERENCES tbl_profesor(id),
    INDEX idx_consecuencia_tenant (id_institucion),
    INDEX idx_consecuencia_matricula_fecha (id_matricula, fecha)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Nota mensual libre ("OBSERVACIONES:" de la Tarjeta) -- 1 fila por
-- matrícula+mes, se sobreescribe (UPSERT), no es un log de eventos.
CREATE TABLE IF NOT EXISTS tbl_demerito_observacion (
    id INT AUTO_INCREMENT PRIMARY KEY,
    id_institucion INT NOT NULL,
    id_matricula INT NOT NULL,
    anno YEAR NOT NULL,
    mes TINYINT NOT NULL,
    texto VARCHAR(1000) NOT NULL DEFAULT '',
    id_profesor_registro INT NULL,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_obsdemerito_institucion FOREIGN KEY (id_institucion) REFERENCES tbl_institucion(id),
    CONSTRAINT fk_obsdemerito_matricula FOREIGN KEY (id_matricula) REFERENCES tbl_matricula(id),
    CONSTRAINT fk_obsdemerito_profesor FOREIGN KEY (id_profesor_registro) REFERENCES tbl_profesor(id),
    UNIQUE KEY uk_obsdemerito_matricula_mes (id_matricula, anno, mes),
    INDEX idx_obsdemerito_tenant (id_institucion)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
