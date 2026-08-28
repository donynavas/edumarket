-- Módulo Horario de Clases (creador manual con validación de choques,
-- lado director). Idempotente.

-- Turno: propiedad de la Sección (una sección completa es matutina o
-- vespertina; su horario hereda ese turno).
ALTER TABLE tbl_seccion
  ADD COLUMN IF NOT EXISTS turno ENUM('matutino','vespertino') NULL AFTER anno_lectivo;

-- Catálogo de bloques horarios (las "horas de clase" del día), editable
-- por el director, por turno. Sembrado con valores por defecto la
-- primera vez que se abre el módulo (ver HorarioHelper::asegurarBloquesPorDefecto).
CREATE TABLE IF NOT EXISTS tbl_bloque_horario (
    id INT AUTO_INCREMENT PRIMARY KEY,
    id_institucion INT NOT NULL,
    turno ENUM('matutino','vespertino') NOT NULL,
    numero SMALLINT NOT NULL COMMENT 'Orden dentro del turno (1, 2, 3...)',
    nombre VARCHAR(50) NOT NULL COMMENT 'ej. "Bloque 1" o "Recreo"',
    hora_inicio TIME NOT NULL,
    hora_fin TIME NOT NULL,
    es_receso TINYINT(1) NOT NULL DEFAULT 0,
    UNIQUE KEY uniq_bloque_orden (id_institucion, turno, numero)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Un bloque de horario = una clase concreta en un día+bloque, colgada
-- de la asignación profesor+materia+sección+año que ya existe (o se
-- crea al vuelo) en tbl_asignacion_docente -- mismo FK que ya usa
-- tbl_examen.
CREATE TABLE IF NOT EXISTS tbl_horario_clase (
    id INT AUTO_INCREMENT PRIMARY KEY,
    id_asignacion_docente INT NOT NULL,
    dia_semana TINYINT NOT NULL COMMENT '1=Lunes .. 5=Viernes',
    id_bloque INT NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_horario_slot (id_asignacion_docente, dia_semana, id_bloque),
    CONSTRAINT fk_hc_asignacion FOREIGN KEY (id_asignacion_docente) REFERENCES tbl_asignacion_docente(id) ON DELETE CASCADE,
    CONSTRAINT fk_hc_bloque FOREIGN KEY (id_bloque) REFERENCES tbl_bloque_horario(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
