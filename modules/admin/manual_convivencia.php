<?php
/**
 * Manual de Convivencia Escolar -- Fase 1.
 *
 * Formulario del director para construir el Plan de Convivencia Escolar
 * siguiendo el esquema oficial de 10 secciones de la "Guía para Elaborar
 * el Plan de Convivencia Escolar" (MINED, 2ª ed., pág. 28), gestión del
 * Comité de Convivencia Escolar (págs. 23-24) y un catálogo de marco
 * legal editable (para que sea "actualizable según normas" -- incluye
 * Ley Crecer Juntos, Decreto 431). Termina con una pestaña de Vista
 * Previa que también sirve como plantilla de impresión/exportación a PDF
 * (mismo patrón de CSS de impresión que el resto del proyecto, ver
 * modules/admin/resumen_centro_demeritos.php -- no hay librería de PDF
 * instalada en este proyecto).
 *
 * Fase 2 (pendiente, fuera de este archivo): buzón de aportes de
 * docentes/estudiantes/padres de familia con aprobación del director. El
 * modelo de datos aquí (una fila por sección en
 * tbl_manual_convivencia_seccion) ya deja espacio para eso sin rediseño.
 */
session_start();
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/TenantGuard.php';
require_once __DIR__ . '/../../config/CatalogoConvivencia.php';
require_once __DIR__ . '/../../config/ManualConvivenciaHelper.php';

if (!isset($_SESSION['user_id']) || ($_SESSION['rol'] != 'admin' && $_SESSION['rol'] != 'director')) {
    header("Location: " . BASE_URL . "/login.php");
    exit;
}

$database = new Database();
$db = $database->getConnection();
$user_id = $_SESSION['user_id'];
$tid = TenantGuard::id();

// Siembra bajo demanda -- mismo patrón que PeriodoHelper::asegurar() en
// cuadro_notas.php: un tenant nuevo obtiene su manual + secciones +
// marco legal la primera vez que el director abre esta página. $idManual
// ya queda tenant-scoped desde aquí (asegurarManual busca/crea por
// id_institucion = $tid), así que las operaciones que filtran por
// id_manual = $idManual más abajo no necesitan un assertOwner aparte.
$idManual = ManualConvivenciaHelper::asegurarManual($db, $tid, (int) date('Y'));
ManualConvivenciaHelper::asegurarSecciones($db, $idManual);
ManualConvivenciaHelper::asegurarMarcoLegal($db, $tid);

$mensaje = '';
$tipo_mensaje = '';

// ===== PROCESAR ACCIONES POST =====
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $accion = $_POST['accion'] ?? '';

    try {
        $db->beginTransaction();

        // GUARDAR GENERALIDADES (sección I -- vive en la fila del manual)
        if ($accion == 'guardar_generalidades') {
            $stmt = $db->prepare(
                "UPDATE tbl_manual_convivencia SET
                    codigo_ce = :codigo, nombre_ce = :nombre, departamento = :depto, municipio = :muni,
                    poblacion_descripcion = :poblacion, ejes_pncecp = :ejes, anno_lectivo = :anno,
                    estado = :estado, updated_by = :uid
                 WHERE id = :id AND id_institucion = :tid"
            );
            $stmt->execute([
                ':codigo' => trim($_POST['codigo_ce'] ?? ''),
                ':nombre' => trim($_POST['nombre_ce'] ?? ''),
                ':depto' => trim($_POST['departamento'] ?? ''),
                ':muni' => trim($_POST['municipio'] ?? ''),
                ':poblacion' => trim($_POST['poblacion_descripcion'] ?? ''),
                ':ejes' => trim($_POST['ejes_pncecp'] ?? ''),
                ':anno' => (int) ($_POST['anno_lectivo'] ?? date('Y')),
                ':estado' => in_array($_POST['estado'] ?? '', ['borrador', 'vigente'], true) ? $_POST['estado'] : 'borrador',
                ':uid' => $user_id,
                ':id' => $idManual,
                ':tid' => $tid,
            ]);
            $db->commit();
            $mensaje = 'Generalidades guardadas exitosamente';
            $tipo_mensaje = 'success';
        }

        // GUARDAR UNA SECCIÓN (II a X)
        elseif ($accion == 'guardar_seccion') {
            $codigo = $_POST['codigo'] ?? '';
            if (!array_key_exists($codigo, CatalogoConvivencia::SECCIONES)) {
                throw new Exception('Sección inválida.');
            }
            $tipo = CatalogoConvivencia::SECCIONES[$codigo]['tipo'];

            if ($tipo === 'estructurado') {
                // Solo la sección III (Objetivos) es estructurada hoy. El
                // objetivo general viene del mismo editor de texto
                // enriquecido que las secciones narrativas -- se sanitiza
                // igual (ver sanitizarHtml()). Los objetivos específicos
                // son ítems de una lista de texto plano, no pasan por el
                // editor.
                $objetivoGeneral = ManualConvivenciaHelper::sanitizarHtml(trim($_POST['objetivo_general'] ?? ''));
                $especificos = array_values(array_filter(
                    array_map('trim', $_POST['objetivos_especificos'] ?? []),
                    fn($v) => $v !== ''
                ));
                $datosJson = json_encode([
                    'objetivo_general' => $objetivoGeneral,
                    'objetivos_especificos' => $especificos,
                ], JSON_UNESCAPED_UNICODE);
                $contenido = null;
            } else {
                $datosJson = null;
                // El contenido llega como HTML del editor de texto
                // enriquecido (negrita, cursiva, alineación, color,
                // tablas, etc.) -- nunca se confía tal cual, se limpia
                // con una lista blanca de etiquetas/atributos antes de
                // guardar (ver ManualConvivenciaHelper::sanitizarHtml()).
                $contenido = ManualConvivenciaHelper::sanitizarHtml(trim($_POST['contenido'] ?? ''));
            }

            $stmt = $db->prepare(
                "UPDATE tbl_manual_convivencia_seccion SET contenido = :contenido, datos_json = :datos
                 WHERE id_manual = :manual AND codigo = :codigo"
            );
            $stmt->execute([
                ':contenido' => $contenido,
                ':datos' => $datosJson,
                ':manual' => $idManual,
                ':codigo' => $codigo,
            ]);
            $db->commit();
            $mensaje = 'Sección "' . CatalogoConvivencia::SECCIONES[$codigo]['titulo'] . '" guardada exitosamente';
            $tipo_mensaje = 'success';
        }

        // CREAR INTEGRANTE DEL COMITÉ
        elseif ($accion == 'crear_comite_miembro') {
            $nombre = trim($_POST['nombre_completo'] ?? '');
            $rol = $_POST['rol_comite'] ?? '';
            if ($nombre === '') {
                throw new Exception('El nombre del integrante es obligatorio.');
            }
            if (!array_key_exists($rol, CatalogoConvivencia::COMITE_ROLES)) {
                throw new Exception('Rol de comité inválido.');
            }
            $idEstudiante = !empty($_POST['id_estudiante']) ? (int) $_POST['id_estudiante'] : null;
            $idProfesor = !empty($_POST['id_profesor']) ? (int) $_POST['id_profesor'] : null;

            $stmt = $db->prepare(
                "INSERT INTO tbl_manual_convivencia_comite
                    (id_manual, nombre_completo, rol_comite, es_coordinador, genero, id_estudiante, id_profesor, fecha_eleccion, periodo_vigencia)
                 VALUES (:manual, :nombre, :rol, :coord, :genero, :estudiante, :profesor, :fecha, :periodo)"
            );
            $stmt->execute([
                ':manual' => $idManual,
                ':nombre' => $nombre,
                ':rol' => $rol,
                ':coord' => !empty($_POST['es_coordinador']) ? 1 : 0,
                ':genero' => in_array($_POST['genero'] ?? '', ['M', 'F', 'Otro'], true) ? $_POST['genero'] : null,
                ':estudiante' => $idEstudiante,
                ':profesor' => $idProfesor,
                ':fecha' => $_POST['fecha_eleccion'] ?: null,
                ':periodo' => trim($_POST['periodo_vigencia'] ?? '') ?: null,
            ]);
            $db->commit();
            $mensaje = 'Integrante agregado al Comité de Convivencia Escolar';
            $tipo_mensaje = 'success';
        }

        // ACTUALIZAR INTEGRANTE DEL COMITÉ
        elseif ($accion == 'actualizar_comite_miembro') {
            $id = (int) ($_POST['id_miembro'] ?? 0);
            TenantGuard::assertOwner($db, 'tbl_manual_convivencia_comite', $id);

            $nombre = trim($_POST['nombre_completo'] ?? '');
            $rol = $_POST['rol_comite'] ?? '';
            if ($nombre === '') {
                throw new Exception('El nombre del integrante es obligatorio.');
            }
            if (!array_key_exists($rol, CatalogoConvivencia::COMITE_ROLES)) {
                throw new Exception('Rol de comité inválido.');
            }
            $idEstudiante = !empty($_POST['id_estudiante']) ? (int) $_POST['id_estudiante'] : null;
            $idProfesor = !empty($_POST['id_profesor']) ? (int) $_POST['id_profesor'] : null;

            $stmt = $db->prepare(
                "UPDATE tbl_manual_convivencia_comite SET
                    nombre_completo = :nombre, rol_comite = :rol, es_coordinador = :coord, genero = :genero,
                    id_estudiante = :estudiante, id_profesor = :profesor, fecha_eleccion = :fecha, periodo_vigencia = :periodo
                 WHERE id = :id"
            );
            $stmt->execute([
                ':nombre' => $nombre,
                ':rol' => $rol,
                ':coord' => !empty($_POST['es_coordinador']) ? 1 : 0,
                ':genero' => in_array($_POST['genero'] ?? '', ['M', 'F', 'Otro'], true) ? $_POST['genero'] : null,
                ':estudiante' => $idEstudiante,
                ':profesor' => $idProfesor,
                ':fecha' => $_POST['fecha_eleccion'] ?: null,
                ':periodo' => trim($_POST['periodo_vigencia'] ?? '') ?: null,
                ':id' => $id,
            ]);
            $db->commit();
            $mensaje = 'Integrante actualizado';
            $tipo_mensaje = 'success';
        }

        // ELIMINAR INTEGRANTE DEL COMITÉ
        elseif ($accion == 'eliminar_comite_miembro') {
            $id = (int) ($_POST['id_miembro'] ?? 0);
            TenantGuard::assertOwner($db, 'tbl_manual_convivencia_comite', $id);
            $db->prepare("DELETE FROM tbl_manual_convivencia_comite WHERE id = :id")->execute([':id' => $id]);
            $db->commit();
            $mensaje = 'Integrante eliminado del comité';
            $tipo_mensaje = 'warning';
        }

        // CREAR REFERENCIA DE MARCO LEGAL
        elseif ($accion == 'crear_marco_legal') {
            $nombreNorma = trim($_POST['nombre_norma'] ?? '');
            if ($nombreNorma === '') {
                throw new Exception('El nombre de la norma es obligatorio.');
            }
            $stmt = $db->prepare(
                "INSERT INTO tbl_manual_convivencia_marco_legal (id_institucion, nombre_norma, articulo_referencia, descripcion, orden)
                 VALUES (:tid, :nombre, :articulo, :descripcion, :orden)"
            );
            $stmt->execute([
                ':tid' => $tid,
                ':nombre' => $nombreNorma,
                ':articulo' => trim($_POST['articulo_referencia'] ?? '') ?: null,
                ':descripcion' => trim($_POST['descripcion'] ?? '') ?: null,
                ':orden' => (int) ($_POST['orden'] ?? 0),
            ]);
            $db->commit();
            $mensaje = 'Referencia de marco legal agregada';
            $tipo_mensaje = 'success';
        }

        // ACTUALIZAR REFERENCIA DE MARCO LEGAL
        elseif ($accion == 'actualizar_marco_legal') {
            $id = (int) ($_POST['id_marco'] ?? 0);
            $check = $db->prepare("SELECT 1 FROM tbl_manual_convivencia_marco_legal WHERE id = :id AND id_institucion = :tid");
            $check->execute([':id' => $id, ':tid' => $tid]);
            if (!$check->fetchColumn()) {
                http_response_code(403);
                throw new Exception('No tiene permiso para editar esta referencia.');
            }
            $nombreNorma = trim($_POST['nombre_norma'] ?? '');
            if ($nombreNorma === '') {
                throw new Exception('El nombre de la norma es obligatorio.');
            }
            $stmt = $db->prepare(
                "UPDATE tbl_manual_convivencia_marco_legal SET
                    nombre_norma = :nombre, articulo_referencia = :articulo, descripcion = :descripcion, orden = :orden
                 WHERE id = :id AND id_institucion = :tid"
            );
            $stmt->execute([
                ':nombre' => $nombreNorma,
                ':articulo' => trim($_POST['articulo_referencia'] ?? '') ?: null,
                ':descripcion' => trim($_POST['descripcion'] ?? '') ?: null,
                ':orden' => (int) ($_POST['orden'] ?? 0),
                ':id' => $id,
                ':tid' => $tid,
            ]);
            $db->commit();
            $mensaje = 'Referencia de marco legal actualizada';
            $tipo_mensaje = 'success';
        }

        // ACTIVAR/DESACTIVAR REFERENCIA DE MARCO LEGAL
        // Se desactiva en vez de borrar (no se ofrece "eliminar" para esta
        // tabla) para no romper referencias de un PDF ya impreso.
        elseif ($accion == 'toggle_marco_legal') {
            $id = (int) ($_POST['id_marco'] ?? 0);
            $check = $db->prepare("SELECT activo FROM tbl_manual_convivencia_marco_legal WHERE id = :id AND id_institucion = :tid");
            $check->execute([':id' => $id, ':tid' => $tid]);
            $activoActual = $check->fetchColumn();
            if ($activoActual === false) {
                http_response_code(403);
                throw new Exception('No tiene permiso para modificar esta referencia.');
            }
            $db->prepare("UPDATE tbl_manual_convivencia_marco_legal SET activo = :activo WHERE id = :id AND id_institucion = :tid")
               ->execute([':activo' => $activoActual ? 0 : 1, ':id' => $id, ':tid' => $tid]);
            $db->commit();
            $mensaje = $activoActual ? 'Referencia desactivada' : 'Referencia reactivada';
            $tipo_mensaje = 'success';
        }

    } catch (Exception $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        $mensaje = 'Error: ' . $e->getMessage();
        $tipo_mensaje = 'danger';
    }
}

// ===== OBTENER DATOS PARA RENDER =====
$stmtManual = $db->prepare("SELECT * FROM tbl_manual_convivencia WHERE id = :id AND id_institucion = :tid");
$stmtManual->execute([':id' => $idManual, ':tid' => $tid]);
$manual = $stmtManual->fetch(PDO::FETCH_ASSOC);

$stmtSecciones = $db->prepare("SELECT * FROM tbl_manual_convivencia_seccion WHERE id_manual = :manual");
$stmtSecciones->execute([':manual' => $idManual]);
$secciones = [];
foreach ($stmtSecciones->fetchAll(PDO::FETCH_ASSOC) as $s) {
    $secciones[$s['codigo']] = $s;
}
// Objetivos (sección III) ya decodificados para no repetir json_decode en el HTML.
$objetivos = ['objetivo_general' => '', 'objetivos_especificos' => []];
if (!empty($secciones['III']['datos_json'])) {
    $decoded = json_decode($secciones['III']['datos_json'], true);
    if (is_array($decoded)) {
        $objetivos['objetivo_general'] = $decoded['objetivo_general'] ?? '';
        $objetivos['objetivos_especificos'] = $decoded['objetivos_especificos'] ?? [];
    }
}

$stmtComite = $db->prepare(
    "SELECT * FROM tbl_manual_convivencia_comite WHERE id_manual = :manual
     ORDER BY FIELD(rol_comite, 'estudiante','docente','administrativo','familia'), nombre_completo"
);
$stmtComite->execute([':manual' => $idManual]);
$comite = $stmtComite->fetchAll(PDO::FETCH_ASSOC);
$checklist = ManualConvivenciaHelper::checklistComite($comite);

$stmtMarco = $db->prepare("SELECT * FROM tbl_manual_convivencia_marco_legal WHERE id_institucion = :tid ORDER BY orden, id");
$stmtMarco->execute([':tid' => $tid]);
$marcoLegal = $stmtMarco->fetchAll(PDO::FETCH_ASSOC);
$marcoLegalActivo = array_values(array_filter($marcoLegal, fn($m) => (int) $m['activo'] === 1));

// Estudiantes y profesores de la institución, para los <select> opcionales
// de "vincular a usuario existente" en el modal del Comité.
$stmtEst = $db->prepare(
    "SELECT e.id, CONCAT(per.primer_nombre, ' ', per.primer_apellido) AS nombre
     FROM tbl_estudiante e JOIN tbl_persona per ON e.id_persona = per.id
     WHERE e.id_institucion = :tid ORDER BY nombre"
);
$stmtEst->execute([':tid' => $tid]);
$estudiantesDisponibles = $stmtEst->fetchAll(PDO::FETCH_ASSOC);

$stmtProf = $db->prepare(
    "SELECT p.id, CONCAT(per.primer_nombre, ' ', per.primer_apellido) AS nombre
     FROM tbl_profesor p JOIN tbl_persona per ON p.id_persona = per.id
     WHERE p.id_institucion = :tid ORDER BY nombre"
);
$stmtProf->execute([':tid' => $tid]);
$profesoresDisponibles = $stmtProf->fetchAll(PDO::FETCH_ASSOC);

$anios = range(date('Y') - 1, date('Y') + 2);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manual de Convivencia Escolar - Educación Plus</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <style>
        :root { --primary: #2c3e50; --secondary: #3498db; --success: #2ecc71; --warning: #f39c12; --danger: #e74c3c; --sidebar-width: 250px; }
        body { font-family: 'Segoe UI', sans-serif; background: #f8f9fa; }
        .sidebar { position: fixed; top: 0; left: 0; height: 100vh; width: var(--sidebar-width); background: var(--primary); color: white; padding-top: 60px; z-index: 1000; }
        .sidebar .nav-link { color: rgba(255,255,255,0.8); padding: 12px 20px; }
        .sidebar .nav-link:hover, .sidebar .nav-link.active { color: white; background: rgba(255,255,255,0.15); }
        .main-content { margin-left: var(--sidebar-width); padding: 20px; }
        .card-custom { background: white; border-radius: 10px; box-shadow: 0 2px 10px rgba(0,0,0,0.08); border: none; margin-bottom: 24px; }
        .nav-tabs .nav-link { color: var(--primary); }
        .nav-tabs .nav-link.active { font-weight: 700; }
        .hoja { background: white; max-width: 900px; margin: 0 auto; padding: 30px; border-radius: 10px; box-shadow: 0 2px 10px rgba(0,0,0,0.08); }
        .hoja h3 { color: var(--primary); border-bottom: 2px solid var(--secondary); padding-bottom: 6px; margin-top: 30px; }
        .hoja h3:first-child { margin-top: 0; }
        .hoja table { border-collapse: collapse; width: 100%; margin: 10px 0; }
        .hoja table td, .hoja table th, .hoja table tr { border: 1px solid #ccc; padding: 6px; }
        .checklist-item.ok { color: var(--success); }
        .checklist-item.falta { color: var(--danger); }
        @media (max-width: 768px) { .sidebar { transform: translateX(-100%); } .sidebar.active { transform: translateX(0); } .main-content { margin-left: 0; } }
        @media print { .sidebar, .no-print, .btn { display: none !important; } .main-content { margin-left: 0; padding: 0; } .tab-pane { display: none !important; } #tab-previa.tab-pane { display: block !important; } .hoja { box-shadow: none; max-width: 100%; } }
    </style>
</head>
<body>
    <!-- Sidebar -->
    <div class="sidebar" id="sidebar">
        <div class="text-center mb-4">
            <h4><i class="fas fa-graduation-cap"></i> Educación Plus</h4>
            <small>Panel de Administración</small>
        </div>
        <nav class="nav flex-column">
            <a class="nav-link" href="../../index.php"><i class="fas fa-tachometer-alt"></i> Dashboard</a>
            <a class="nav-link" href="gestionar_estudiantes.php"><i class="fas fa-user-graduate"></i> Estudiantes</a>
            <a class="nav-link" href="gestionar_profesores.php"><i class="fas fa-chalkboard-teacher"></i> Profesores</a>
            <a class="nav-link" href="gestionar_grados.php"><i class="fas fa-layer-group"></i> Grados/Secciones</a>
            <a class="nav-link" href="gestionar_asignaturas.php"><i class="fas fa-book"></i> Asignaturas</a>
            <a class="nav-link" href="gestionar_matriculas.php"><i class="fas fa-file-signature"></i> Matrículas</a>
            <a class="nav-link" href="cuadro_notas.php"><i class="fas fa-clipboard-list"></i> Cuadro de Notas</a>
            <a class="nav-link active" href="manual_convivencia.php"><i class="fas fa-handshake"></i> Convivencia Escolar</a>
            <a class="nav-link" href="../../logout.php"><i class="fas fa-sign-out-alt"></i> Cerrar Sesión</a>
        </nav>
    </div>

    <!-- Main Content -->
    <div class="main-content">
        <div class="d-flex justify-content-between align-items-center mb-4 no-print">
            <div>
                <h2><i class="fas fa-handshake"></i> Manual de Convivencia Escolar</h2>
                <p class="text-muted mb-0">Plan de Convivencia Escolar -- alineado a la Guía MINED y a la Ley Crecer Juntos (Decreto 431)</p>
            </div>
            <button class="btn btn-primary" onclick="irAVistaPreviaEImprimir()"><i class="fas fa-print"></i> Imprimir / Exportar a PDF</button>
        </div>

        <?php if ($mensaje): ?>
        <div class="alert alert-<?= $tipo_mensaje ?> alert-dismissible fade show no-print">
            <?= htmlspecialchars($mensaje) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php endif; ?>

        <ul class="nav nav-tabs no-print flex-wrap mb-3" id="tabsManual" role="tablist">
            <li class="nav-item"><button class="nav-link active" data-bs-toggle="tab" data-bs-target="#tab-generalidades" type="button">I. Generalidades</button></li>
            <?php foreach (CatalogoConvivencia::SECCIONES as $codigo => $def): ?>
            <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-<?= $codigo ?>" type="button"><?= $codigo ?>.</button></li>
            <?php endforeach; ?>
            <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-comite" type="button"><i class="fas fa-users"></i> Comité</button></li>
            <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-marco-legal" type="button"><i class="fas fa-balance-scale"></i> Marco Legal</button></li>
            <li class="nav-item"><button class="nav-link" id="tab-btn-previa" data-bs-toggle="tab" data-bs-target="#tab-previa" type="button"><i class="fas fa-eye"></i> Vista Previa</button></li>
        </ul>

        <div class="tab-content">

            <!-- ===== I. GENERALIDADES ===== -->
            <div class="tab-pane fade show active" id="tab-generalidades">
                <div class="card-custom p-4">
                    <form method="POST">
                        <input type="hidden" name="accion" value="guardar_generalidades">
                        <div class="row g-3">
                            <div class="col-md-4">
                                <label class="form-label">1. Código del CE</label>
                                <input type="text" name="codigo_ce" class="form-control" value="<?= htmlspecialchars($manual['codigo_ce'] ?? '') ?>">
                            </div>
                            <div class="col-md-8">
                                <label class="form-label">2. Nombre del CE</label>
                                <input type="text" name="nombre_ce" class="form-control" value="<?= htmlspecialchars($manual['nombre_ce'] ?? '') ?>">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">3. Departamento</label>
                                <input type="text" name="departamento" class="form-control" value="<?= htmlspecialchars($manual['departamento'] ?? '') ?>">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">4. Municipio</label>
                                <input type="text" name="municipio" class="form-control" value="<?= htmlspecialchars($manual['municipio'] ?? '') ?>">
                            </div>
                            <div class="col-12">
                                <label class="form-label">5. Población que atiende el CE</label>
                                <textarea name="poblacion_descripcion" class="form-control" rows="3"><?= htmlspecialchars($manual['poblacion_descripcion'] ?? '') ?></textarea>
                            </div>
                            <div class="col-12">
                                <label class="form-label">6. Ejes de la Política Nacional para la Convivencia Escolar y Cultura de Paz</label>
                                <textarea name="ejes_pncecp" class="form-control" rows="3"><?= htmlspecialchars($manual['ejes_pncecp'] ?? '') ?></textarea>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Año lectivo</label>
                                <select name="anno_lectivo" class="form-select">
                                    <?php foreach ($anios as $a): ?>
                                    <option value="<?= $a ?>" <?= $a == ($manual['anno_lectivo'] ?? date('Y')) ? 'selected' : '' ?>><?= $a ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Estado del manual</label>
                                <select name="estado" class="form-select">
                                    <option value="borrador" <?= ($manual['estado'] ?? '') === 'borrador' ? 'selected' : '' ?>>Borrador</option>
                                    <option value="vigente" <?= ($manual['estado'] ?? '') === 'vigente' ? 'selected' : '' ?>>Vigente</option>
                                </select>
                            </div>
                        </div>
                        <button type="submit" class="btn btn-primary mt-3"><i class="fas fa-save"></i> Guardar Generalidades</button>
                    </form>
                </div>
            </div>

            <!-- ===== SECCIONES II a X ===== -->
            <?php foreach (CatalogoConvivencia::SECCIONES as $codigo => $def): ?>
            <div class="tab-pane fade" id="tab-<?= $codigo ?>">
                <div class="card-custom p-4">
                    <h5><?= $codigo ?>. <?= htmlspecialchars($def['titulo']) ?></h5>
                    <form method="POST">
                        <input type="hidden" name="accion" value="guardar_seccion">
                        <input type="hidden" name="codigo" value="<?= $codigo ?>">
                        <?php if ($def['tipo'] === 'estructurado'): ?>
                            <div class="mb-3">
                                <label class="form-label">Objetivo general</label>
                                <textarea name="objetivo_general" id="rte-objetivo-general" class="rte-editor"><?= htmlspecialchars($objetivos['objetivo_general']) ?></textarea>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Objetivos específicos</label>
                                <div id="listaObjetivosEspecificos">
                                    <?php if (empty($objetivos['objetivos_especificos'])): ?>
                                        <div class="input-group mb-2">
                                            <input type="text" name="objetivos_especificos[]" class="form-control" placeholder="Objetivo específico">
                                            <button type="button" class="btn btn-outline-danger" onclick="this.closest('.input-group').remove()"><i class="fas fa-times"></i></button>
                                        </div>
                                    <?php else: foreach ($objetivos['objetivos_especificos'] as $obj): ?>
                                        <div class="input-group mb-2">
                                            <input type="text" name="objetivos_especificos[]" class="form-control" value="<?= htmlspecialchars($obj) ?>" placeholder="Objetivo específico">
                                            <button type="button" class="btn btn-outline-danger" onclick="this.closest('.input-group').remove()"><i class="fas fa-times"></i></button>
                                        </div>
                                    <?php endforeach; endif; ?>
                                </div>
                                <button type="button" class="btn btn-sm btn-outline-secondary" onclick="agregarObjetivoEspecifico()"><i class="fas fa-plus"></i> Agregar objetivo específico</button>
                            </div>
                        <?php else: ?>
                            <div class="mb-3">
                                <textarea name="contenido" id="rte-<?= $codigo ?>" class="rte-editor"><?= htmlspecialchars($secciones[$codigo]['contenido'] ?? '') ?></textarea>
                            </div>
                        <?php endif; ?>
                        <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Guardar sección</button>
                    </form>
                </div>
            </div>
            <?php endforeach; ?>

            <!-- ===== COMITÉ DE CONVIVENCIA ESCOLAR ===== -->
            <div class="tab-pane fade" id="tab-comite">
                <div class="card-custom p-4">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <h5 class="mb-0"><i class="fas fa-users"></i> Comité de Convivencia Escolar</h5>
                        <button class="btn btn-success" data-bs-toggle="modal" data-bs-target="#modalComite" onclick="prepararModalComite('crear')"><i class="fas fa-plus"></i> Agregar integrante</button>
                    </div>

                    <div class="alert <?= $checklist['cumple_total'] ? 'alert-success' : 'alert-warning' ?>">
                        <strong><i class="fas fa-clipboard-check"></i> Composición mínima sugerida por la guía (<?= CatalogoConvivencia::COMITE_TOTAL_MINIMO ?> integrantes):</strong>
                        <ul class="mb-0 mt-2">
                            <?php foreach ($checklist['por_rol'] as $rol => $c): ?>
                            <li class="checklist-item <?= $c['cumple'] ? 'ok' : 'falta' ?>">
                                <i class="fas <?= $c['cumple'] ? 'fa-check-circle' : 'fa-exclamation-circle' ?>"></i>
                                <?= CatalogoConvivencia::COMITE_ROLES[$rol] ?>: <?= $c['actual'] ?> / <?= $c['minimo'] ?>
                                <?= !$c['cumple'] ? ' (faltan ' . ($c['minimo'] - $c['actual']) . ')' : '' ?>
                            </li>
                            <?php endforeach; ?>
                            <li class="checklist-item <?= $checklist['cumple_total'] ? 'ok' : 'falta' ?>">
                                <strong>Total: <?= $checklist['total'] ?> / <?= CatalogoConvivencia::COMITE_TOTAL_MINIMO ?></strong>
                            </li>
                        </ul>
                    </div>

                    <div class="table-responsive">
                        <table class="table table-hover">
                            <thead class="table-light">
                                <tr><th>Nombre</th><th>Rol</th><th>Género</th><th>Coordinador/a</th><th>Período</th><th class="no-print">Acciones</th></tr>
                            </thead>
                            <tbody>
                                <?php foreach ($comite as $m): ?>
                                <tr>
                                    <td><?= htmlspecialchars($m['nombre_completo']) ?></td>
                                    <td><?= CatalogoConvivencia::COMITE_ROLES[$m['rol_comite']] ?? $m['rol_comite'] ?></td>
                                    <td><?= htmlspecialchars($m['genero'] ?? '-') ?></td>
                                    <td><?= $m['es_coordinador'] ? '<i class="fas fa-star text-warning"></i>' : '' ?></td>
                                    <td><?= htmlspecialchars($m['periodo_vigencia'] ?? '-') ?></td>
                                    <td class="no-print">
                                        <button type="button" class="btn btn-sm btn-warning"
                                            onclick='prepararModalComite("editar", <?= json_encode($m, JSON_HEX_APOS | JSON_HEX_QUOT) ?>)'>
                                            <i class="fas fa-edit"></i>
                                        </button>
                                        <form method="POST" style="display:inline" onsubmit="return confirm('¿Eliminar este integrante del comité?');">
                                            <input type="hidden" name="accion" value="eliminar_comite_miembro">
                                            <input type="hidden" name="id_miembro" value="<?= $m['id'] ?>">
                                            <button type="submit" class="btn btn-sm btn-danger"><i class="fas fa-trash"></i></button>
                                        </form>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                                <?php if (empty($comite)): ?>
                                <tr><td colspan="6" class="text-center text-muted py-3">Todavía no hay integrantes registrados.</td></tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- ===== MARCO LEGAL ===== -->
            <div class="tab-pane fade" id="tab-marco-legal">
                <div class="card-custom p-4">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <h5 class="mb-0"><i class="fas fa-balance-scale"></i> Marco Legal</h5>
                        <button class="btn btn-success" data-bs-toggle="modal" data-bs-target="#modalMarcoLegal" onclick="prepararModalMarcoLegal('crear')"><i class="fas fa-plus"></i> Agregar norma</button>
                    </div>
                    <p class="text-muted small">Este catálogo es editable: cuando una norma cambie, actualízala aquí y el manual (vista web e impresión) reflejará el cambio de inmediato, sin tener que volver a redactar el documento.</p>
                    <div class="table-responsive">
                        <table class="table table-hover">
                            <thead class="table-light">
                                <tr><th>#</th><th>Norma</th><th>Artículo/Referencia</th><th>Estado</th><th class="no-print">Acciones</th></tr>
                            </thead>
                            <tbody>
                                <?php foreach ($marcoLegal as $ml): ?>
                                <tr class="<?= $ml['activo'] ? '' : 'text-muted' ?>">
                                    <td><?= $ml['orden'] ?></td>
                                    <td><?= htmlspecialchars($ml['nombre_norma']) ?><?= !$ml['activo'] ? ' <span class="badge bg-secondary">Inactiva</span>' : '' ?></td>
                                    <td><?= htmlspecialchars($ml['articulo_referencia'] ?? '-') ?></td>
                                    <td><?= $ml['activo'] ? '<span class="badge bg-success">Activa</span>' : '<span class="badge bg-secondary">Inactiva</span>' ?></td>
                                    <td class="no-print">
                                        <button type="button" class="btn btn-sm btn-warning"
                                            onclick='prepararModalMarcoLegal("editar", <?= json_encode($ml, JSON_HEX_APOS | JSON_HEX_QUOT) ?>)'>
                                            <i class="fas fa-edit"></i>
                                        </button>
                                        <form method="POST" style="display:inline">
                                            <input type="hidden" name="accion" value="toggle_marco_legal">
                                            <input type="hidden" name="id_marco" value="<?= $ml['id'] ?>">
                                            <button type="submit" class="btn btn-sm <?= $ml['activo'] ? 'btn-secondary' : 'btn-success' ?>">
                                                <i class="fas <?= $ml['activo'] ? 'fa-eye-slash' : 'fa-eye' ?>"></i>
                                            </button>
                                        </form>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- ===== VISTA PREVIA / IMPRESIÓN ===== -->
            <div class="tab-pane fade" id="tab-previa">
                <div class="hoja">
                    <div class="text-center mb-4">
                        <h2>Manual de Convivencia Escolar</h2>
                        <h4><?= htmlspecialchars($manual['nombre_ce'] ?? '') ?></h4>
                        <p class="text-muted">Año lectivo <?= htmlspecialchars($manual['anno_lectivo'] ?? '') ?> -- Estado: <?= htmlspecialchars(ucfirst($manual['estado'] ?? 'borrador')) ?></p>
                    </div>

                    <h3>I. Generalidades</h3>
                    <p>
                        <strong>1. Código del CE:</strong> <?= htmlspecialchars($manual['codigo_ce'] ?? '-') ?><br>
                        <strong>2. Nombre del CE:</strong> <?= htmlspecialchars($manual['nombre_ce'] ?? '-') ?><br>
                        <strong>3. Departamento:</strong> <?= htmlspecialchars($manual['departamento'] ?? '-') ?><br>
                        <strong>4. Municipio:</strong> <?= htmlspecialchars($manual['municipio'] ?? '-') ?><br>
                        <strong>5. Población que atiende el CE:</strong> <?= nl2br(htmlspecialchars($manual['poblacion_descripcion'] ?? '-')) ?><br>
                        <strong>6. Ejes de la Política Nacional para la Convivencia Escolar y Cultura de Paz:</strong> <?= nl2br(htmlspecialchars($manual['ejes_pncecp'] ?? '-')) ?>
                    </p>

                    <?php foreach (CatalogoConvivencia::SECCIONES as $codigo => $def): ?>
                    <h3><?= $codigo ?>. <?= htmlspecialchars($def['titulo']) ?></h3>
                    <?php if ($codigo === 'III'): ?>
                        <p><strong>Objetivo general:</strong></p>
                        <?php // El contenido ya viene sanitizado (lista blanca de etiquetas/atributos)
                        // desde ManualConvivenciaHelper::sanitizarHtml() al guardar -- se imprime
                        // como HTML, no como texto escapado, porque viene del editor de texto
                        // enriquecido (negrita, color, alineación, etc.). ?>
                        <div><?= $objetivos['objetivo_general'] !== '' ? $objetivos['objetivo_general'] : '<span class="text-muted">Sin definir.</span>' ?></div>
                        <?php if (!empty($objetivos['objetivos_especificos'])): ?>
                        <p class="mt-2"><strong>Objetivos específicos:</strong></p>
                        <ul>
                            <?php foreach ($objetivos['objetivos_especificos'] as $obj): ?>
                            <li><?= htmlspecialchars($obj) ?></li>
                            <?php endforeach; ?>
                        </ul>
                        <?php endif; ?>
                    <?php else: ?>
                        <?php // Mismo criterio: contenido ya sanitizado, se imprime como HTML. ?>
                        <div><?= !empty($secciones[$codigo]['contenido']) ? $secciones[$codigo]['contenido'] : '<span class="text-muted">Sin contenido todavía.</span>' ?></div>
                    <?php endif; ?>
                    <?php endforeach; ?>

                    <h3><i class="fas fa-users"></i> Comité de Convivencia Escolar</h3>
                    <table class="table table-sm table-bordered">
                        <thead><tr><th>Nombre</th><th>Rol</th><th>Período</th></tr></thead>
                        <tbody>
                            <?php foreach ($comite as $m): ?>
                            <tr>
                                <td><?= htmlspecialchars($m['nombre_completo']) ?><?= $m['es_coordinador'] ? ' (Coordinador/a)' : '' ?></td>
                                <td><?= CatalogoConvivencia::COMITE_ROLES[$m['rol_comite']] ?? $m['rol_comite'] ?></td>
                                <td><?= htmlspecialchars($m['periodo_vigencia'] ?? '-') ?></td>
                            </tr>
                            <?php endforeach; ?>
                            <?php if (empty($comite)): ?>
                            <tr><td colspan="3" class="text-muted">Sin integrantes registrados todavía.</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>

                    <h3><i class="fas fa-balance-scale"></i> Marco Legal</h3>
                    <ol>
                        <?php foreach ($marcoLegalActivo as $ml): ?>
                        <li>
                            <strong><?= htmlspecialchars($ml['nombre_norma']) ?></strong>
                            <?= $ml['articulo_referencia'] ? ' (' . htmlspecialchars($ml['articulo_referencia']) . ')' : '' ?>
                            <?= $ml['descripcion'] ? '<br><small>' . nl2br(htmlspecialchars($ml['descripcion'])) . '</small>' : '' ?>
                        </li>
                        <?php endforeach; ?>
                    </ol>
                </div>
            </div>

        </div>
    </div>

    <!-- Modal Comité -->
    <div class="modal fade" id="modalComite" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="modalTitleComite"><i class="fas fa-plus"></i> Nuevo integrante</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" id="formComite">
                    <div class="modal-body">
                        <input type="hidden" name="accion" id="accion_comite" value="crear_comite_miembro">
                        <input type="hidden" name="id_miembro" id="id_miembro">
                        <div class="mb-3">
                            <label class="form-label">Nombre completo *</label>
                            <input type="text" name="nombre_completo" id="mc_nombre" class="form-control" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Rol en el comité *</label>
                            <select name="rol_comite" id="mc_rol" class="form-select" required>
                                <?php foreach (CatalogoConvivencia::COMITE_ROLES as $k => $v): ?>
                                <option value="<?= $k ?>"><?= $v ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label">Género</label>
                                <select name="genero" id="mc_genero" class="form-select">
                                    <option value="">Sin especificar</option>
                                    <option value="M">Masculino</option>
                                    <option value="F">Femenino</option>
                                    <option value="Otro">Otro</option>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Período de vigencia</label>
                                <input type="text" name="periodo_vigencia" id="mc_periodo" class="form-control" placeholder="ej. 2026-2028">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Fecha de elección</label>
                                <input type="date" name="fecha_eleccion" id="mc_fecha" class="form-control">
                            </div>
                            <div class="col-md-6 d-flex align-items-end">
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" name="es_coordinador" id="mc_coordinador" value="1">
                                    <label class="form-check-label" for="mc_coordinador">Es coordinador/a (director/a)</label>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Vincular a estudiante existente (opcional)</label>
                                <select name="id_estudiante" id="mc_estudiante" class="form-select">
                                    <option value="">-- Ninguno --</option>
                                    <?php foreach ($estudiantesDisponibles as $e): ?>
                                    <option value="<?= $e['id'] ?>"><?= htmlspecialchars($e['nombre']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Vincular a docente existente (opcional)</label>
                                <select name="id_profesor" id="mc_profesor" class="form-select">
                                    <option value="">-- Ninguno --</option>
                                    <?php foreach ($profesoresDisponibles as $p): ?>
                                    <option value="<?= $p['id'] ?>"><?= htmlspecialchars($p['nombre']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                        <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Guardar</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Modal Marco Legal -->
    <div class="modal fade" id="modalMarcoLegal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="modalTitleMarcoLegal"><i class="fas fa-plus"></i> Nueva norma</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" id="formMarcoLegal">
                    <div class="modal-body">
                        <input type="hidden" name="accion" id="accion_marco" value="crear_marco_legal">
                        <input type="hidden" name="id_marco" id="id_marco">
                        <div class="mb-3">
                            <label class="form-label">Nombre de la norma *</label>
                            <input type="text" name="nombre_norma" id="ml_nombre" class="form-control" required>
                        </div>
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label">Artículo/Referencia</label>
                                <input type="text" name="articulo_referencia" id="ml_articulo" class="form-control">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Orden</label>
                                <input type="number" name="orden" id="ml_orden" class="form-control" value="0">
                            </div>
                            <div class="col-12">
                                <label class="form-label">Descripción</label>
                                <textarea name="descripcion" id="ml_descripcion" class="form-control" rows="3"></textarea>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                        <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Guardar</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Scripts -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <!-- Editor de texto enriquecido para las secciones narrativas y el
         objetivo general -- autohospedado vía jsdelivr (no el CDN
         cdn.tiny.cloud), así que no pide clave de API; solo se usan
         funciones core (negrita/cursiva/subrayado, alineación, color,
         fuente/tamaño y tablas), sin plugins de pago. -->
    <script src="https://cdn.jsdelivr.net/npm/tinymce@6/tinymce.min.js" referrerpolicy="origin"></script>
    <script>
        // Configuración base del editor de texto enriquecido -- se
        // reutiliza para cada instancia (una por pestaña de sección).
        const RTE_CONFIG_BASE = {
            height: 320,
            menubar: false,
            statusbar: false,
            branding: false,
            promotion: false,
            plugins: 'table',
            toolbar: 'undo redo | bold italic underline | forecolor backcolor | fontfamily fontsize | alignleft aligncenter alignright alignjustify | table | removeformat',
            font_family_formats: 'Arial=arial,helvetica,sans-serif; Georgia=georgia,palatino,serif; Times New Roman=times new roman,times,serif; Verdana=verdana,geneva,sans-serif; Courier New=courier new,courier,monospace',
            font_size_formats: '8pt 10pt 12pt 14pt 16pt 18pt 24pt 36pt',
            content_style: "body { font-family: 'Segoe UI', sans-serif; font-size: 14px; }",
            table_default_attributes: { border: '1' },
            table_default_styles: { width: '100%' },
        };

        // TinyMCE mide mal un contenedor que todavía está oculto
        // (Bootstrap oculta cada pestaña con display:none hasta que se
        // activa), así que inicializar TODOS los editores de una vez al
        // cargar la página los deja mal dimensionados. En su lugar, cada
        // pestaña inicializa su propio editor la primera vez que se
        // muestra.
        function initRteEnPane(pane) {
            pane.querySelectorAll('textarea.rte-editor').forEach(function (ta) {
                if (!ta.id || tinymce.get(ta.id)) {
                    return;
                }
                tinymce.init(Object.assign({}, RTE_CONFIG_BASE, { selector: '#' + ta.id }));
            });
        }
        document.querySelectorAll('#tabsManual button[data-bs-toggle="tab"]').forEach(function (btn) {
            btn.addEventListener('shown.bs.tab', function (e) {
                const pane = document.querySelector(e.target.getAttribute('data-bs-target'));
                if (pane) {
                    initRteEnPane(pane);
                }
            });
        });

        // TinyMCE reemplaza visualmente el <textarea> pero solo escribe de
        // vuelta su contenido en él al detectar el submit del <form> que lo
        // contiene -- sin esto, el POST enviaría el textarea vacío.
        document.querySelectorAll('form').forEach(function (form) {
            form.addEventListener('submit', function () {
                if (typeof tinymce !== 'undefined') {
                    tinymce.triggerSave();
                }
            });
        });

        function agregarObjetivoEspecifico() {
            const div = document.createElement('div');
            div.className = 'input-group mb-2';
            div.innerHTML = '<input type="text" name="objetivos_especificos[]" class="form-control" placeholder="Objetivo específico">' +
                '<button type="button" class="btn btn-outline-danger" onclick="this.closest(\'.input-group\').remove()"><i class="fas fa-times"></i></button>';
            document.getElementById('listaObjetivosEspecificos').appendChild(div);
        }

        function prepararModalComite(modo, data) {
            document.getElementById('formComite').reset();
            if (modo === 'crear') {
                document.getElementById('accion_comite').value = 'crear_comite_miembro';
                document.getElementById('id_miembro').value = '';
                document.getElementById('modalTitleComite').innerHTML = '<i class="fas fa-plus"></i> Nuevo integrante';
            } else {
                document.getElementById('accion_comite').value = 'actualizar_comite_miembro';
                document.getElementById('id_miembro').value = data.id;
                document.getElementById('mc_nombre').value = data.nombre_completo || '';
                document.getElementById('mc_rol').value = data.rol_comite || 'estudiante';
                document.getElementById('mc_genero').value = data.genero || '';
                document.getElementById('mc_periodo').value = data.periodo_vigencia || '';
                document.getElementById('mc_fecha').value = data.fecha_eleccion || '';
                document.getElementById('mc_coordinador').checked = !!Number(data.es_coordinador);
                document.getElementById('mc_estudiante').value = data.id_estudiante || '';
                document.getElementById('mc_profesor').value = data.id_profesor || '';
                document.getElementById('modalTitleComite').innerHTML = '<i class="fas fa-edit"></i> Editar integrante';
            }
        }

        function prepararModalMarcoLegal(modo, data) {
            document.getElementById('formMarcoLegal').reset();
            if (modo === 'crear') {
                document.getElementById('accion_marco').value = 'crear_marco_legal';
                document.getElementById('id_marco').value = '';
                document.getElementById('modalTitleMarcoLegal').innerHTML = '<i class="fas fa-plus"></i> Nueva norma';
            } else {
                document.getElementById('accion_marco').value = 'actualizar_marco_legal';
                document.getElementById('id_marco').value = data.id;
                document.getElementById('ml_nombre').value = data.nombre_norma || '';
                document.getElementById('ml_articulo').value = data.articulo_referencia || '';
                document.getElementById('ml_orden').value = data.orden || 0;
                document.getElementById('ml_descripcion').value = data.descripcion || '';
                document.getElementById('modalTitleMarcoLegal').innerHTML = '<i class="fas fa-edit"></i> Editar norma';
            }
        }

        // El botón de imprimir siempre exporta el documento completo desde
        // la pestaña Vista Previa, sin importar en qué pestaña esté el
        // usuario -- cambia de pestaña primero y luego llama a print().
        function irAVistaPreviaEImprimir() {
            const tabPrevia = new bootstrap.Tab(document.getElementById('tab-btn-previa'));
            tabPrevia.show();
            setTimeout(() => window.print(), 150);
        }
    </script>
</body>
</html>
