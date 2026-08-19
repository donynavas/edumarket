<?php
/**
 * Cabecera/sidebar compartida del módulo de profesor.
 *
 * Antes cada una de las 9 páginas de modules/profesor/ tenía su propio
 * <style> y su propio <nav> de sidebar, con 5 combinaciones distintas de
 * colores/estructura y navegación fragmentada en dos "islas" que no se
 * enlazaban entre sí (ver auditoría previa a este cambio). Este parcial
 * centraliza ambas cosas: un único look visual y un único set de enlaces,
 * para que "Actividades" y cualquier otra pantalla se vean/comporten igual
 * sin importar desde dónde se entró.
 *
 * La página que incluye este archivo debe, ANTES de requerirlo, dejar
 * definidas estas variables:
 *
 *   $activePage   string  Clave del enlace activo (ver $NAV_LINKS abajo).
 *   $pageTitle    string  Texto para <title>.
 *   $profesor     array   Debe traer al menos 'primer_nombre'.
 *
 * Y opcionalmente:
 *
 *   $extraHead                 string  HTML crudo (normalmente un <style>
 *                                       con reglas propias de la página)
 *                                       que se inserta justo antes de
 *                                       </head>. Así cada página conserva
 *                                       su CSS específico (tarjetas,
 *                                       badges, etc.) sin duplicar aquí el
 *                                       CSS genérico del sidebar.
 *   $mostrarAsignacionesSidebar bool    Si true, pinta debajo del menú la
 *                                       mini-lista "Mis Asignaciones" que
 *                                       ya usaban calificaciones.php,
 *                                       gestionar_estudiantes.php y
 *                                       reportes.php.
 *   $asignaciones               array   Requerido si lo anterior es true.
 *                                       Cada item: id, asignatura_nombre,
 *                                       grado_nombre, seccion_nombre,
 *                                       total_estudiantes (opcional).
 *   $idAsignacionFiltro         mixed   id de la asignación resaltada en
 *                                       esa mini-lista (viene de $_GET).
 *
 * Deja abiertos <body> y <div class="main-content">: la página continúa
 * escribiendo su contenido justo después del require.
 */

$activePage = $activePage ?? '';
$pageTitle = $pageTitle ?? 'Educación Plus';
$extraHead = $extraHead ?? '';
$mostrarAsignacionesSidebar = $mostrarAsignacionesSidebar ?? false;
$asignaciones = $asignaciones ?? [];
$idAsignacionFiltro = $idAsignacionFiltro ?? null;

// Set único de enlaces del sidebar. Antes 9 páginas tenían 9 subconjuntos
// distintos de estos mismos enlaces (algunos con "Estudiantes" apuntando
// al archivo equivocado) -- ahora todas comparten exactamente esta lista.
$NAV_LINKS = [
    'dashboard'    => ['href' => 'profesor_dashboard.php',    'icon' => 'fa-home',          'label' => 'Dashboard'],
    'actividades'  => ['href' => 'gestionar_actividades.php', 'icon' => 'fa-tasks',          'label' => 'Actividades'],
    'calificaciones' => ['href' => 'calificaciones.php',      'icon' => 'fa-star',           'label' => 'Calificaciones'],
    'estudiantes'  => ['href' => 'gestionar_estudiantes.php', 'icon' => 'fa-user-graduate',  'label' => 'Estudiantes'],
    'examen'       => ['href' => 'asignar_examen.php',        'icon' => 'fa-file-alt',       'label' => 'Asignar Examen'],
    'banco'        => ['href' => 'banco_preguntas.php',       'icon' => 'fa-layer-group',    'label' => 'Banco de Preguntas'],
    'tablon'       => ['href' => 'tablon.php',                'icon' => 'fa-th-large',       'label' => 'Tablón'],
    'asistencia'   => ['href' => 'asistencia.php',            'icon' => 'fa-clipboard-check','label' => 'Asistencia'],
    'reportes'     => ['href' => 'reportes.php',               'icon' => 'fa-chart-bar',      'label' => 'Reportes'],
];
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($pageTitle) ?></title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet">

    <style>
        :root {
            --primary: #2c3e50; --secondary: #3498db; --success: #2ecc71;
            --warning: #f39c12; --danger: #e74c3c; --info: #17a2b8; --purple: #9b59b6;
            --sidebar-width: 260px;
        }
        body { font-family: 'Segoe UI', system-ui, -apple-system, sans-serif; background: #f5f7fa; }
        .sidebar { position: fixed; top: 0; left: 0; height: 100vh; width: var(--sidebar-width); background: var(--primary); color: white; padding-top: 20px; z-index: 1000; overflow-y: auto; }
        .sidebar .nav-link { color: rgba(255,255,255,0.85); padding: 12px 20px; margin: 2px 10px; border-radius: 8px; transition: all 0.2s; }
        .sidebar .nav-link:hover, .sidebar .nav-link.active { color: white; background: rgba(255,255,255,0.15); }
        .sidebar .nav-link i { width: 24px; text-align: center; margin-right: 8px; }
        .main-content { margin-left: var(--sidebar-width); padding: 20px; }
        @media (max-width: 992px) {
            .sidebar { transform: translateX(-100%); }
            .sidebar.active { transform: translateX(0); }
            .main-content { margin-left: 0; }
        }
    </style>
    <?= $extraHead ?>
</head>
<body>
    <!-- Sidebar -->
    <div class="sidebar" id="sidebar">
        <div class="text-center mb-4 px-3">
            <div class="d-flex align-items-center justify-content-center gap-2 mb-2">
                <div class="bg-white text-primary rounded-circle d-flex align-items-center justify-content-center" style="width: 45px; height: 45px; font-weight: 700;">
                    <?= htmlspecialchars(strtoupper(substr($profesor['primer_nombre'] ?? '?', 0, 1))) ?>
                </div>
                <div class="text-start">
                    <div class="fw-bold"><?= htmlspecialchars($profesor['primer_nombre'] ?? '') ?></div>
                    <small class="text-white-50">Profesor</small>
                </div>
            </div>
        </div>
        <nav class="nav flex-column px-2">
            <?php foreach ($NAV_LINKS as $key => $link): ?>
            <a class="nav-link<?= $activePage === $key ? ' active' : '' ?>" href="<?= htmlspecialchars($link['href']) ?>">
                <i class="fas <?= htmlspecialchars($link['icon']) ?>"></i> <?= htmlspecialchars($link['label']) ?>
            </a>
            <?php endforeach; ?>
            <a class="nav-link" href="../../logout.php"><i class="fas fa-sign-out-alt"></i> Cerrar Sesión</a>
        </nav>

        <?php if ($mostrarAsignacionesSidebar && !empty($asignaciones)): ?>
        <div class="mt-4 px-3">
            <small class="text-white-50">Mis Asignaciones</small>
            <div class="mt-2" style="max-height: 300px; overflow-y: auto;">
                <?php foreach ($asignaciones as $asig): ?>
                <a href="?asignacion=<?= (int) $asig['id'] ?>" class="d-block text-white-50 text-decoration-none py-1 px-2 rounded small <?= $idAsignacionFiltro == $asig['id'] ? 'bg-white bg-opacity-10 text-white' : '' ?>">
                    <i class="fas fa-chevron-right me-1 small"></i>
                    <?= htmlspecialchars($asig['asignatura_nombre']) ?> - <?= htmlspecialchars($asig['grado_nombre']) ?> <?= htmlspecialchars($asig['seccion_nombre']) ?>
                    <?php if (isset($asig['total_estudiantes'])): ?>
                    <span class="badge bg-primary float-end"><?= (int) $asig['total_estudiantes'] ?></span>
                    <?php endif; ?>
                </a>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <!-- Main Content -->
    <div class="main-content">
