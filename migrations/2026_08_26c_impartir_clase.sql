-- Módulo "Impartir Clase": una fila por clase impartida
-- (tbl_clase_impartida) con sus recursos de apoyo (tbl_clase_recurso) y su
-- vínculo persistente hacia las actividades reales creadas desde los
-- botones de Cierre (Asignar tarea/examen/Actividad), que a su vez pueden
-- quedar vinculadas al Cuadro de Notas vía ActividadHelper (mismo camino
-- que ya usa modules/profesor/gestionar_actividades.php). Idempotente
-- (IF NOT EXISTS), se puede correr junto con el resto de migraciones sin
-- orden especial.

CREATE TABLE IF NOT EXISTS tbl_clase_impartida (
    id INT AUTO_INCREMENT PRIMARY KEY,
    id_institucion INT NOT NULL,
    id_asignacion_docente INT NOT NULL,
    numero_clase VARCHAR(20) NULL,
    fecha_clase DATE NOT NULL,
    objetivo TEXT NULL,
    desarrollo MEDIUMTEXT NULL,
    cierre TEXT NULL,
    estado ENUM('borrador','impartida') NOT NULL DEFAULT 'borrador',
    iniciada_en DATETIME NULL,
    finalizada_en DATETIME NULL,
    id_actividad_tarea INT NULL,
    id_actividad_examen INT NULL,
    id_actividad_extra INT NULL,
    created_by INT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_clase_institucion FOREIGN KEY (id_institucion) REFERENCES tbl_institucion(id) ON DELETE CASCADE,
    CONSTRAINT fk_clase_asignacion FOREIGN KEY (id_asignacion_docente) REFERENCES tbl_asignacion_docente(id) ON DELETE CASCADE,
    CONSTRAINT fk_clase_actividad_tarea FOREIGN KEY (id_actividad_tarea) REFERENCES tbl_actividad(id) ON DELETE SET NULL,
    CONSTRAINT fk_clase_actividad_examen FOREIGN KEY (id_actividad_examen) REFERENCES tbl_actividad(id) ON DELETE SET NULL,
    CONSTRAINT fk_clase_actividad_extra FOREIGN KEY (id_actividad_extra) REFERENCES tbl_actividad(id) ON DELETE SET NULL,
    INDEX idx_clase_asignacion (id_asignacion_docente, fecha_clase),
    INDEX idx_clase_tenant (id_institucion)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tbl_clase_recurso (
    id INT AUTO_INCREMENT PRIMARY KEY,
    id_clase INT NOT NULL,
    tipo ENUM('imagen','sitio_web','articulo','video_yt') NOT NULL,
    titulo VARCHAR(200) NULL,
    url VARCHAR(500) NULL,
    contenido TEXT NULL,
    orden TINYINT NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_recurso_clase FOREIGN KEY (id_clase) REFERENCES tbl_clase_impartida(id) ON DELETE CASCADE,
    INDEX idx_recurso_clase (id_clase, orden)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
