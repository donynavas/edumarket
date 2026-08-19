-- Unifica en UNA sola fuente de lectura las dos tablas que hoy guardan la
-- "entrega/nota" de un estudiante en una actividad:
--   - tbl_entrega_actividad: tareas/laboratorios/proyectos calificados a
--     mano por el profesor.
--   - tbl_intento_examen: exámenes, calificados automáticamente (o
--     manualmente pendiente de mostrar) -- nunca crea fila en
--     tbl_entrega_actividad.
--
-- Antes de esta vista, cada pantalla que necesitaba saber "¿este
-- estudiante ya entregó/calificó esta actividad?" tenía que repetir un
-- LEFT JOIN a tbl_entrega_actividad Y otro a tbl_intento_examen (con una
-- subconsulta para quedarse con el intento más reciente si hubo varios,
-- y un COLLATE explícito porque tbl_intento_examen.estado quedó creada
-- con utf8mb4_general_ci mientras el resto de la BD usa
-- utf8mb4_unicode_ci). Repetir ese patrón en cada archivo nuevo es
-- exactamente lo que causó que 6 pantallas distintas del estudiante (y 2
-- del admin) mostraran "Pendiente"/promedio en 0 para exámenes ya
-- calificados. A partir de ahora, cualquier pantalla nueva que necesite
-- esta información debe hacer UN SOLO LEFT JOIN a vw_logro_estudiante en
-- vez de reimplementar la unión.
--
-- Nota importante: para exámenes solo se incluye el intento MÁS
-- RECIENTE por (examen, matrícula) que ya fue entregado o calificado --
-- un intento 'en_progreso' (el estudiante todavía está resolviendo el
-- examen) NO aparece aquí, igual que una tarea sin entregar tampoco
-- tiene fila en tbl_entrega_actividad. La nota del examen se convierte
-- de su escala 0-100 (tbl_intento_examen.porcentaje) a la escala
-- nota_maxima de la actividad, para que sea comparable con las notas de
-- tareas en la misma columna.
--
-- Es SQL puro y seguro de pegar directo en la pestaña "SQL" de
-- phpMyAdmin. CREATE OR REPLACE VIEW es idempotente -- se puede correr
-- tantas veces como haga falta sin error.

CREATE OR REPLACE VIEW vw_logro_estudiante AS
SELECT
    ea.id_actividad,
    ea.id_matricula,
    ea.nota_obtenida,
    ea.estado_entrega COLLATE utf8mb4_unicode_ci AS estado_entrega,
    ea.fecha_entrega,
    ea.fecha_calificacion,
    ea.observacion_docente,
    ea.id AS id_registro_origen,
    'tarea' AS origen
FROM tbl_entrega_actividad ea

UNION ALL

SELECT
    act.id AS id_actividad,
    ie.id_matricula,
    ROUND((ie.porcentaje / 100) * act.nota_maxima, 2) AS nota_obtenida,
    ie.estado COLLATE utf8mb4_unicode_ci AS estado_entrega,
    ie.fecha_fin AS fecha_entrega,
    CASE WHEN ie.estado = 'calificado' THEN ie.fecha_fin ELSE NULL END AS fecha_calificacion,
    NULL AS observacion_docente,
    ie.id AS id_registro_origen,
    'examen' AS origen
FROM tbl_intento_examen ie
JOIN tbl_actividad act ON act.id_examen = ie.id_examen
WHERE ie.estado IN ('entregado', 'calificado')
AND ie.id = (
    SELECT ie2.id FROM tbl_intento_examen ie2
    WHERE ie2.id_examen = ie.id_examen AND ie2.id_matricula = ie.id_matricula
    ORDER BY ie2.fecha_fin DESC, ie2.id DESC LIMIT 1
);
