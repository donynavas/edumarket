-- migrations/2026_08_20_rubricas_y_banco_comentarios.sql
--
-- Rúbricas personalizadas (matriz criterios × niveles) + banco de
-- comentarios reutilizables, para "Calificaciones" del profesor.
--
-- tbl_rubrica.id_actividad NULL = plantilla reutilizable en la biblioteca
-- personal del profesor; NOT NULL = instancia propia de una actividad,
-- copiada desde una plantilla (id_rubrica_origen conserva la trazabilidad,
-- ON DELETE SET NULL porque borrar la plantilla no debe invalidar
-- instancias ya creadas/calificadas). Mismo espíritu que la copia
-- tbl_banco_preguntas -> tbl_pregunta_examen ya existente en el proyecto,
-- pero reutilizando una sola tabla en vez de duplicar el set completo.
--
-- tbl_entrega_rubrica_detalle.puntaje_otorgado es una FOTO del puntaje de
-- la celda al momento de calificar (no una referencia viva) -- si la
-- instancia de rúbrica se editara más adelante (fuera de alcance de esta
-- fase), las notas ya dadas no cambiarían solas.

CREATE TABLE IF NOT EXISTS tbl_rubrica (
    id INT AUTO_INCREMENT PRIMARY KEY,
    id_institucion INT NOT NULL,
    id_profesor INT NOT NULL,
    id_actividad INT NULL,
    id_rubrica_origen INT NULL,
    nombre VARCHAR(150) NOT NULL,
    descripcion TEXT NULL,
    estado ENUM('activo','archivado') NOT NULL DEFAULT 'activo',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_rubrica_institucion FOREIGN KEY (id_institucion) REFERENCES tbl_institucion(id),
    CONSTRAINT fk_rubrica_profesor FOREIGN KEY (id_profesor) REFERENCES tbl_profesor(id),
    CONSTRAINT fk_rubrica_actividad FOREIGN KEY (id_actividad) REFERENCES tbl_actividad(id) ON DELETE CASCADE,
    CONSTRAINT fk_rubrica_origen FOREIGN KEY (id_rubrica_origen) REFERENCES tbl_rubrica(id) ON DELETE SET NULL,
    UNIQUE KEY uk_rubrica_actividad (id_actividad),
    INDEX idx_rubrica_tenant (id_institucion),
    INDEX idx_rubrica_profesor (id_profesor, estado),
    INDEX idx_rubrica_origen (id_rubrica_origen)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tbl_rubrica_nivel (
    id INT AUTO_INCREMENT PRIMARY KEY,
    id_rubrica INT NOT NULL,
    nombre VARCHAR(100) NOT NULL,
    orden INT NOT NULL DEFAULT 0,
    CONSTRAINT fk_rubnivel_rubrica FOREIGN KEY (id_rubrica) REFERENCES tbl_rubrica(id) ON DELETE CASCADE,
    INDEX idx_rubnivel_rubrica (id_rubrica, orden)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tbl_rubrica_criterio (
    id INT AUTO_INCREMENT PRIMARY KEY,
    id_rubrica INT NOT NULL,
    nombre VARCHAR(150) NOT NULL,
    descripcion TEXT NULL,
    orden INT NOT NULL DEFAULT 0,
    CONSTRAINT fk_rubcrit_rubrica FOREIGN KEY (id_rubrica) REFERENCES tbl_rubrica(id) ON DELETE CASCADE,
    INDEX idx_rubcrit_rubrica (id_rubrica, orden)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tbl_rubrica_celda (
    id INT AUTO_INCREMENT PRIMARY KEY,
    id_criterio INT NOT NULL,
    id_nivel INT NOT NULL,
    descripcion TEXT NULL,
    puntaje DECIMAL(5,2) NOT NULL DEFAULT 0.00,
    CONSTRAINT fk_rubcelda_criterio FOREIGN KEY (id_criterio) REFERENCES tbl_rubrica_criterio(id) ON DELETE CASCADE,
    CONSTRAINT fk_rubcelda_nivel FOREIGN KEY (id_nivel) REFERENCES tbl_rubrica_nivel(id) ON DELETE CASCADE,
    UNIQUE KEY uk_rubcelda_criterio_nivel (id_criterio, id_nivel),
    INDEX idx_rubcelda_nivel (id_nivel)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tbl_entrega_rubrica_detalle (
    id INT AUTO_INCREMENT PRIMARY KEY,
    id_entrega_actividad INT NOT NULL,
    id_criterio INT NOT NULL,
    id_nivel INT NOT NULL,
    puntaje_otorgado DECIMAL(5,2) NOT NULL,
    comentario_criterio TEXT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_rubdetalle_entrega FOREIGN KEY (id_entrega_actividad) REFERENCES tbl_entrega_actividad(id) ON DELETE CASCADE,
    CONSTRAINT fk_rubdetalle_criterio FOREIGN KEY (id_criterio) REFERENCES tbl_rubrica_criterio(id),
    CONSTRAINT fk_rubdetalle_nivel FOREIGN KEY (id_nivel) REFERENCES tbl_rubrica_nivel(id),
    UNIQUE KEY uk_rubdetalle_entrega_criterio (id_entrega_actividad, id_criterio),
    INDEX idx_rubdetalle_nivel (id_nivel)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tbl_banco_comentario (
    id INT AUTO_INCREMENT PRIMARY KEY,
    id_institucion INT NOT NULL,
    id_profesor INT NOT NULL,
    texto TEXT NOT NULL,
    categoria VARCHAR(100) NULL,
    veces_usado INT NOT NULL DEFAULT 0,
    estado ENUM('activo','archivado') NOT NULL DEFAULT 'activo',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_bancocom_institucion FOREIGN KEY (id_institucion) REFERENCES tbl_institucion(id),
    CONSTRAINT fk_bancocom_profesor FOREIGN KEY (id_profesor) REFERENCES tbl_profesor(id),
    INDEX idx_bancocom_tenant (id_institucion),
    INDEX idx_bancocom_profesor (id_profesor, estado)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
