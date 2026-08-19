-- Correo interno profesor <-> estudiante.
--
-- No existía ningún sistema de mensajería real: tbl_notificacion (id ya
-- presente en el esquema) nunca se llenaba desde ningún flujo (sin asunto,
-- sin soporte para varios destinatarios por mensaje), y el modal "Enviar
-- Mensaje" de modules/profesor/gestionar_estudiantes.php era un stub que
-- mostraba éxito sin guardar nada. Se crea un esquema propio y dedicado.
--
-- tbl_mensaje: el contenido (una fila por mensaje enviado, sea individual o
-- grupal a una sección completa).
-- tbl_mensaje_destinatario: una fila por cada destinatario real de ese
-- mensaje, con su propio estado de lectura -- así un aviso a toda una
-- sección queda leído/no leído de forma independiente por cada estudiante.

CREATE TABLE IF NOT EXISTS tbl_mensaje (
    id INT AUTO_INCREMENT PRIMARY KEY,
    id_institucion INT NOT NULL,
    id_remitente INT NOT NULL,
    asunto VARCHAR(200) NOT NULL,
    cuerpo TEXT NOT NULL,
    tipo ENUM('individual','seccion') NOT NULL DEFAULT 'individual',
    id_seccion_destino INT NULL,
    id_mensaje_padre INT NULL,
    fecha_envio DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_mensaje_remitente FOREIGN KEY (id_remitente) REFERENCES tbl_usuario(id),
    CONSTRAINT fk_mensaje_seccion FOREIGN KEY (id_seccion_destino) REFERENCES tbl_seccion(id),
    CONSTRAINT fk_mensaje_padre FOREIGN KEY (id_mensaje_padre) REFERENCES tbl_mensaje(id),
    INDEX idx_mensaje_institucion (id_institucion),
    INDEX idx_mensaje_remitente (id_remitente)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tbl_mensaje_destinatario (
    id INT AUTO_INCREMENT PRIMARY KEY,
    id_mensaje INT NOT NULL,
    id_usuario_destinatario INT NOT NULL,
    leido TINYINT(1) NOT NULL DEFAULT 0,
    fecha_lectura DATETIME NULL,
    CONSTRAINT fk_destinatario_mensaje FOREIGN KEY (id_mensaje) REFERENCES tbl_mensaje(id) ON DELETE CASCADE,
    CONSTRAINT fk_destinatario_usuario FOREIGN KEY (id_usuario_destinatario) REFERENCES tbl_usuario(id),
    UNIQUE KEY uk_mensaje_usuario (id_mensaje, id_usuario_destinatario),
    INDEX idx_destinatario_leido (id_usuario_destinatario, leido)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
