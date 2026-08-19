-- ============================================================================
-- 001_tenant_isolation.sql
-- Prioridad 0: aislamiento estricto de datos entre instituciones (tenants)
--
-- Estrategia: denormalizar id_institucion en todas las tablas hijas que se
-- consultan directamente en la aplicación, para que cada query pueda filtrar
-- con "AND id_institucion = ?" sin depender de joins multi-nivel frágiles.
--
-- Seguro de ejecutar más de una vez (usa IF NOT EXISTS / comprobaciones).
-- Ejecutar sobre una base de datos ya cargada con educacion_plus.sql.
-- ============================================================================

-- ---------- tbl_persona (vía tbl_usuario.id_usuario) ----------
ALTER TABLE tbl_persona ADD COLUMN IF NOT EXISTS id_institucion INT(11) DEFAULT NULL AFTER id_usuario;
UPDATE tbl_persona p
  JOIN tbl_usuario u ON p.id_usuario = u.id
  SET p.id_institucion = u.id_institucion
  WHERE p.id_institucion IS NULL;
ALTER TABLE tbl_persona ADD INDEX IF NOT EXISTS idx_persona_institucion (id_institucion);

-- ---------- tbl_director (vía tbl_persona) ----------
ALTER TABLE tbl_director ADD COLUMN IF NOT EXISTS id_institucion INT(11) DEFAULT NULL AFTER id_persona;
UPDATE tbl_director d
  JOIN tbl_persona p ON d.id_persona = p.id
  SET d.id_institucion = p.id_institucion
  WHERE d.id_institucion IS NULL;
ALTER TABLE tbl_director ADD INDEX IF NOT EXISTS idx_director_institucion (id_institucion);

-- ---------- tbl_responsable (vía tbl_persona) ----------
ALTER TABLE tbl_responsable ADD COLUMN IF NOT EXISTS id_institucion INT(11) DEFAULT NULL AFTER id_persona;
UPDATE tbl_responsable r
  JOIN tbl_persona p ON r.id_persona = p.id
  SET r.id_institucion = p.id_institucion
  WHERE r.id_institucion IS NULL;
ALTER TABLE tbl_responsable ADD INDEX IF NOT EXISTS idx_responsable_institucion (id_institucion);

-- ---------- tbl_matricula (vía tbl_seccion) ----------
ALTER TABLE tbl_matricula ADD COLUMN IF NOT EXISTS id_institucion INT(11) DEFAULT NULL AFTER id_seccion;
UPDATE tbl_matricula m
  JOIN tbl_seccion s ON m.id_seccion = s.id
  SET m.id_institucion = s.id_institucion
  WHERE m.id_institucion IS NULL;
ALTER TABLE tbl_matricula ADD INDEX IF NOT EXISTS idx_matricula_institucion (id_institucion);

-- ---------- tbl_asignacion_docente (vía tbl_seccion) ----------
ALTER TABLE tbl_asignacion_docente ADD COLUMN IF NOT EXISTS id_institucion INT(11) DEFAULT NULL AFTER id_seccion;
UPDATE tbl_asignacion_docente ad
  JOIN tbl_seccion s ON ad.id_seccion = s.id
  SET ad.id_institucion = s.id_institucion
  WHERE ad.id_institucion IS NULL;
ALTER TABLE tbl_asignacion_docente ADD INDEX IF NOT EXISTS idx_asigdoc_institucion (id_institucion);

-- ---------- tbl_actividad (vía tbl_asignacion_docente) ----------
ALTER TABLE tbl_actividad ADD COLUMN IF NOT EXISTS id_institucion INT(11) DEFAULT NULL AFTER id_asignacion_docente;
UPDATE tbl_actividad a
  JOIN tbl_asignacion_docente ad ON a.id_asignacion_docente = ad.id
  SET a.id_institucion = ad.id_institucion
  WHERE a.id_institucion IS NULL;
ALTER TABLE tbl_actividad ADD INDEX IF NOT EXISTS idx_actividad_institucion (id_institucion);

-- ---------- tbl_examen (vía tbl_asignacion_docente) ----------
ALTER TABLE tbl_examen ADD COLUMN IF NOT EXISTS id_institucion INT(11) DEFAULT NULL AFTER id_asignacion_docente;
UPDATE tbl_examen e
  JOIN tbl_asignacion_docente ad ON e.id_asignacion_docente = ad.id
  SET e.id_institucion = ad.id_institucion
  WHERE e.id_institucion IS NULL;
ALTER TABLE tbl_examen ADD INDEX IF NOT EXISTS idx_examen_institucion (id_institucion);

-- ---------- tbl_config_examen (vía tbl_actividad) ----------
ALTER TABLE tbl_config_examen ADD COLUMN IF NOT EXISTS id_institucion INT(11) DEFAULT NULL AFTER id_actividad;
UPDATE tbl_config_examen c
  JOIN tbl_actividad a ON c.id_actividad = a.id
  SET c.id_institucion = a.id_institucion
  WHERE c.id_institucion IS NULL;
ALTER TABLE tbl_config_examen ADD INDEX IF NOT EXISTS idx_cfgexamen_institucion (id_institucion);

-- ---------- tbl_asistencia (vía tbl_matricula) ----------
ALTER TABLE tbl_asistencia ADD COLUMN IF NOT EXISTS id_institucion INT(11) DEFAULT NULL AFTER id_matricula;
UPDATE tbl_asistencia asi
  JOIN tbl_matricula m ON asi.id_matricula = m.id
  SET asi.id_institucion = m.id_institucion
  WHERE asi.id_institucion IS NULL;
ALTER TABLE tbl_asistencia ADD INDEX IF NOT EXISTS idx_asistencia_institucion (id_institucion);

-- ---------- tbl_entrega_actividad (vía tbl_matricula) ----------
ALTER TABLE tbl_entrega_actividad ADD COLUMN IF NOT EXISTS id_institucion INT(11) DEFAULT NULL AFTER id_matricula;
UPDATE tbl_entrega_actividad en
  JOIN tbl_matricula m ON en.id_matricula = m.id
  SET en.id_institucion = m.id_institucion
  WHERE en.id_institucion IS NULL;
ALTER TABLE tbl_entrega_actividad ADD INDEX IF NOT EXISTS idx_entrega_institucion (id_institucion);

-- ---------- tbl_notificacion (vía tbl_usuario destinatario) ----------
ALTER TABLE tbl_notificacion ADD COLUMN IF NOT EXISTS id_institucion INT(11) DEFAULT NULL AFTER id_destinatario;
UPDATE tbl_notificacion n
  JOIN tbl_usuario u ON n.id_destinatario = u.id
  SET n.id_institucion = u.id_institucion
  WHERE n.id_institucion IS NULL;
ALTER TABLE tbl_notificacion ADD INDEX IF NOT EXISTS idx_notificacion_institucion (id_institucion);

-- ---------- tbl_intento_examen (vía tbl_estudiante) ----------
ALTER TABLE tbl_intento_examen ADD COLUMN IF NOT EXISTS id_institucion INT(11) DEFAULT NULL AFTER id_estudiante;
UPDATE tbl_intento_examen ie
  JOIN tbl_estudiante es ON ie.id_estudiante = es.id
  SET ie.id_institucion = es.id_institucion
  WHERE ie.id_institucion IS NULL;
ALTER TABLE tbl_intento_examen ADD INDEX IF NOT EXISTS idx_intento_institucion (id_institucion);

-- ---------- tbl_calendario_evaluacion (vía tbl_asignacion_docente) ----------
ALTER TABLE tbl_calendario_evaluacion ADD COLUMN IF NOT EXISTS id_institucion INT(11) DEFAULT NULL AFTER id_asignacion_docente;
UPDATE tbl_calendario_evaluacion ce
  JOIN tbl_asignacion_docente ad ON ce.id_asignacion_docente = ad.id
  SET ce.id_institucion = ad.id_institucion
  WHERE ce.id_institucion IS NULL;
ALTER TABLE tbl_calendario_evaluacion ADD INDEX IF NOT EXISTS idx_calendario_institucion (id_institucion);

-- ---------- tbl_chat_clase (vía tbl_asignacion_docente) ----------
ALTER TABLE tbl_chat_clase ADD COLUMN IF NOT EXISTS id_institucion INT(11) DEFAULT NULL AFTER id_asignacion;
UPDATE tbl_chat_clase cc
  JOIN tbl_asignacion_docente ad ON cc.id_asignacion = ad.id
  SET cc.id_institucion = ad.id_institucion
  WHERE cc.id_institucion IS NULL;
ALTER TABLE tbl_chat_clase ADD INDEX IF NOT EXISTS idx_chat_institucion (id_institucion);

-- ---------- tbl_foro (vía tbl_actividad) ----------
ALTER TABLE tbl_foro ADD COLUMN IF NOT EXISTS id_institucion INT(11) DEFAULT NULL AFTER id_actividad;
UPDATE tbl_foro f
  JOIN tbl_actividad a ON f.id_actividad = a.id
  SET f.id_institucion = a.id_institucion
  WHERE f.id_institucion IS NULL;
ALTER TABLE tbl_foro ADD INDEX IF NOT EXISTS idx_foro_institucion (id_institucion);

-- ---------- tbl_logs_actividad (vía tbl_usuario) ----------
ALTER TABLE tbl_logs_actividad ADD COLUMN IF NOT EXISTS id_institucion INT(11) DEFAULT NULL AFTER id_usuario;
UPDATE tbl_logs_actividad l
  JOIN tbl_usuario u ON l.id_usuario = u.id
  SET l.id_institucion = u.id_institucion
  WHERE l.id_institucion IS NULL;
ALTER TABLE tbl_logs_actividad ADD INDEX IF NOT EXISTS idx_logs_institucion (id_institucion);

-- ---------- tbl_bienestar_alerta (vía tbl_estudiante) ----------
ALTER TABLE tbl_bienestar_alerta ADD COLUMN IF NOT EXISTS id_institucion INT(11) DEFAULT NULL AFTER id_estudiante;
UPDATE tbl_bienestar_alerta ba
  JOIN tbl_estudiante es ON ba.id_estudiante = es.id
  SET ba.id_institucion = es.id_institucion
  WHERE ba.id_institucion IS NULL;
ALTER TABLE tbl_bienestar_alerta ADD INDEX IF NOT EXISTS idx_bienestar_institucion (id_institucion);

-- ---------- tbl_ingles_asignacion (vía tbl_profesor, siempre NOT NULL) ----------
ALTER TABLE tbl_ingles_asignacion ADD COLUMN IF NOT EXISTS id_institucion INT(11) DEFAULT NULL AFTER id_profesor;
UPDATE tbl_ingles_asignacion ia
  JOIN tbl_profesor pr ON ia.id_profesor = pr.id
  SET ia.id_institucion = pr.id_institucion
  WHERE ia.id_institucion IS NULL;
ALTER TABLE tbl_ingles_asignacion ADD INDEX IF NOT EXISTS idx_inglesasig_institucion (id_institucion);

-- ---------- tbl_ingles_progreso (vía tbl_estudiante) ----------
ALTER TABLE tbl_ingles_progreso ADD COLUMN IF NOT EXISTS id_institucion INT(11) DEFAULT NULL AFTER id_estudiante;
UPDATE tbl_ingles_progreso ip
  JOIN tbl_estudiante es ON ip.id_estudiante = es.id
  SET ip.id_institucion = es.id_institucion
  WHERE ip.id_institucion IS NULL;
ALTER TABLE tbl_ingles_progreso ADD INDEX IF NOT EXISTS idx_inglesprog_institucion (id_institucion);

-- ---------- tbl_ingles_logros_estudiante (vía tbl_estudiante) ----------
ALTER TABLE tbl_ingles_logros_estudiante ADD COLUMN IF NOT EXISTS id_institucion INT(11) DEFAULT NULL AFTER id_estudiante;
UPDATE tbl_ingles_logros_estudiante il
  JOIN tbl_estudiante es ON il.id_estudiante = es.id
  SET il.id_institucion = es.id_institucion
  WHERE il.id_institucion IS NULL;
ALTER TABLE tbl_ingles_logros_estudiante ADD INDEX IF NOT EXISTS idx_ingleslogro_institucion (id_institucion);

-- ============================================================================
-- Verificación: filas huérfanas (sin institución resuelta) por tabla.
-- Si algo aparece aquí con count > 0, son datos con relaciones rotas que
-- deben revisarse manualmente antes de confiar en el filtro de tenant.
-- ============================================================================
SELECT 'tbl_persona' t, COUNT(*) huerfanos FROM tbl_persona WHERE id_institucion IS NULL
UNION ALL SELECT 'tbl_director', COUNT(*) FROM tbl_director WHERE id_institucion IS NULL
UNION ALL SELECT 'tbl_responsable', COUNT(*) FROM tbl_responsable WHERE id_institucion IS NULL
UNION ALL SELECT 'tbl_matricula', COUNT(*) FROM tbl_matricula WHERE id_institucion IS NULL
UNION ALL SELECT 'tbl_asignacion_docente', COUNT(*) FROM tbl_asignacion_docente WHERE id_institucion IS NULL
UNION ALL SELECT 'tbl_actividad', COUNT(*) FROM tbl_actividad WHERE id_institucion IS NULL
UNION ALL SELECT 'tbl_examen', COUNT(*) FROM tbl_examen WHERE id_institucion IS NULL
UNION ALL SELECT 'tbl_config_examen', COUNT(*) FROM tbl_config_examen WHERE id_institucion IS NULL
UNION ALL SELECT 'tbl_asistencia', COUNT(*) FROM tbl_asistencia WHERE id_institucion IS NULL
UNION ALL SELECT 'tbl_entrega_actividad', COUNT(*) FROM tbl_entrega_actividad WHERE id_institucion IS NULL
UNION ALL SELECT 'tbl_notificacion', COUNT(*) FROM tbl_notificacion WHERE id_institucion IS NULL
UNION ALL SELECT 'tbl_intento_examen', COUNT(*) FROM tbl_intento_examen WHERE id_institucion IS NULL
UNION ALL SELECT 'tbl_calendario_evaluacion', COUNT(*) FROM tbl_calendario_evaluacion WHERE id_institucion IS NULL
UNION ALL SELECT 'tbl_chat_clase', COUNT(*) FROM tbl_chat_clase WHERE id_institucion IS NULL
UNION ALL SELECT 'tbl_foro', COUNT(*) FROM tbl_foro WHERE id_institucion IS NULL
UNION ALL SELECT 'tbl_logs_actividad', COUNT(*) FROM tbl_logs_actividad WHERE id_institucion IS NULL
UNION ALL SELECT 'tbl_bienestar_alerta', COUNT(*) FROM tbl_bienestar_alerta WHERE id_institucion IS NULL
UNION ALL SELECT 'tbl_ingles_asignacion', COUNT(*) FROM tbl_ingles_asignacion WHERE id_institucion IS NULL
UNION ALL SELECT 'tbl_ingles_progreso', COUNT(*) FROM tbl_ingles_progreso WHERE id_institucion IS NULL
UNION ALL SELECT 'tbl_ingles_logros_estudiante', COUNT(*) FROM tbl_ingles_logros_estudiante WHERE id_institucion IS NULL;
