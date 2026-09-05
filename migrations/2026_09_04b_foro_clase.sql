-- Foro por clase: el profesor pidió poder compartir con los estudiantes en
-- cada clase que crea (tbl_clase_impartida). Es un muro simple (sin hilos
-- anidados) donde el profesor y los estudiantes matriculados en la sección
-- de esa asignación pueden publicar mensajes de texto plano.
--
-- id_institucion va directo en la tabla (igual que tbl_clase_impartida,
-- tbl_bloque_horario, tbl_rubrica) -- no hace falta entrada en el mapa
-- viaTenantColumn de TenantGuard.php.
CREATE TABLE IF NOT EXISTS tbl_foro_mensaje (
    id INT AUTO_INCREMENT PRIMARY KEY,
    id_clase INT NOT NULL,
    id_institucion INT NOT NULL,
    id_usuario INT NOT NULL COMMENT 'tbl_usuario.id del autor (profesor o estudiante)',
    autor_rol ENUM('profesor','estudiante') NOT NULL,
    mensaje TEXT NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_foro_clase (id_clase, created_at),
    KEY idx_foro_tenant (id_institucion),
    CONSTRAINT fk_foro_clase FOREIGN KEY (id_clase) REFERENCES tbl_clase_impartida(id) ON DELETE CASCADE,
    CONSTRAINT fk_foro_usuario FOREIGN KEY (id_usuario) REFERENCES tbl_usuario(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
