-- Motor de Evaluaciones — Fase 1: Banco de preguntas reutilizable
-- Crea las tablas necesarias para que un profesor guarde preguntas
-- independientes de cualquier examen específico, las categorice con
-- metadatos pedagógicos (asignatura, tema, competencia, dificultad) y las
-- reutilice al armar nuevos exámenes (en vez de escribir cada pregunta
-- desde cero cada vez).
--
-- Diseño: las preguntas del banco son una copia-fuente. Al "importar" una
-- pregunta del banco a un examen, se copian sus datos a
-- tbl_pregunta_examen/tbl_opcion_respuesta (igual que si el profesor la
-- hubiera escrito a mano) — así, editar o borrar una pregunta del banco
-- nunca afecta exámenes ya creados que la usaron.

CREATE TABLE IF NOT EXISTS tbl_banco_preguntas (
    id INT AUTO_INCREMENT PRIMARY KEY,
    id_institucion INT NOT NULL,
    id_profesor INT NOT NULL,
    id_asignatura INT NULL,
    tema VARCHAR(150) NULL,
    competencia VARCHAR(150) NULL,
    tipo ENUM('opcion_multiple','verdadero_falso','completar','relacionar','respuesta_corta','ensayo') NOT NULL,
    dificultad ENUM('facil','medio','dificil') NOT NULL DEFAULT 'medio',
    enunciado TEXT NOT NULL,
    puntaje_sugerido DECIMAL(5,2) NOT NULL DEFAULT 1.00,
    imagen_url VARCHAR(500) NULL,
    estado ENUM('activo','archivado') NOT NULL DEFAULT 'activo',
    veces_usada INT NOT NULL DEFAULT 0,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_banco_institucion FOREIGN KEY (id_institucion) REFERENCES tbl_institucion(id),
    CONSTRAINT fk_banco_profesor FOREIGN KEY (id_profesor) REFERENCES tbl_profesor(id),
    CONSTRAINT fk_banco_asignatura FOREIGN KEY (id_asignatura) REFERENCES tbl_asignatura(id) ON DELETE SET NULL,
    INDEX idx_banco_tenant (id_institucion),
    INDEX idx_banco_profesor (id_profesor),
    INDEX idx_banco_asignatura (id_asignatura),
    INDEX idx_banco_tipo (tipo),
    INDEX idx_banco_dificultad (dificultad)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS tbl_banco_opcion (
    id INT AUTO_INCREMENT PRIMARY KEY,
    id_banco_pregunta INT NOT NULL,
    texto TEXT NOT NULL,
    es_correcta TINYINT(1) DEFAULT 0,
    orden INT DEFAULT 0,
    CONSTRAINT fk_banco_opcion_pregunta FOREIGN KEY (id_banco_pregunta) REFERENCES tbl_banco_preguntas(id) ON DELETE CASCADE,
    INDEX idx_banco_opcion_pregunta (id_banco_pregunta)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
