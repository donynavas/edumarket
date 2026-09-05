<?php
session_start();
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/app.php';

// Verificar que sea admin o director (login.php e index.php envían a
// ambos roles aquí; el resto del módulo admin/ ya acepta los dos, pero a
// este dashboard le faltaba — causaba un bucle de redirección infinito
// para cualquier usuario con rol 'director').
if (!isset($_SESSION['user_id']) || ($_SESSION['rol'] != 'admin' && $_SESSION['rol'] != 'director')) {
    header("Location: " . BASE_URL . "/login.php");
    exit;
}

require_once __DIR__ . '/../../config/TenantGuard.php';
$tid = TenantGuard::id();
$database = new Database();
$db = $database->getConnection();
$user_id = $_SESSION['user_id'];

// Obtener estadísticas generales — SIEMPRE acotadas a la institución de la
// sesión actual. Sin este filtro el dashboard mezclaba conteos de TODAS las
// instituciones de la plataforma.
$stats = [];

// Total estudiantes
$query = "SELECT COUNT(*) as total FROM tbl_estudiante e
          JOIN tbl_persona p ON e.id_persona = p.id
          JOIN tbl_usuario u ON p.id_usuario = u.id
          WHERE u.estado = 1 AND e.id_institucion = :tid";
$stmt = $db->prepare($query);
$stmt->execute([':tid' => $tid]);
$stats['estudiantes'] = $stmt->fetch(PDO::FETCH_ASSOC)['total'];

// Total profesores
$query = "SELECT COUNT(*) as total FROM tbl_profesor p
          JOIN tbl_persona per ON p.id_persona = per.id
          JOIN tbl_usuario u ON per.id_usuario = u.id
          WHERE u.estado = 1 AND p.id_institucion = :tid";
$stmt = $db->prepare($query);
$stmt->execute([':tid' => $tid]);
$stats['profesores'] = $stmt->fetch(PDO::FETCH_ASSOC)['total'];

// Total asignaturas
$query = "SELECT COUNT(*) as total FROM tbl_asignatura WHERE id_institucion = :tid";
$stmt = $db->prepare($query);
$stmt->execute([':tid' => $tid]);
$stats['asignaturas'] = $stmt->fetch(PDO::FETCH_ASSOC)['total'];

// Total grados
$query = "SELECT COUNT(*) as total FROM tbl_grado WHERE id_institucion = :tid";
$stmt = $db->prepare($query);
$stmt->execute([':tid' => $tid]);
$stats['grados'] = $stmt->fetch(PDO::FETCH_ASSOC)['total'];

// Usuarios activos
$query = "SELECT COUNT(*) as total FROM tbl_usuario WHERE estado = 1 AND id_institucion = :tid";
$stmt = $db->prepare($query);
$stmt->execute([':tid' => $tid]);
$stats['usuarios_activos'] = $stmt->fetch(PDO::FETCH_ASSOC)['total'];

// Notificaciones pendientes (ya son propias del usuario, sin cambio)
$query = "SELECT COUNT(*) as total FROM tbl_notificacion
          WHERE id_destinatario = :user_id AND leido = 0";
$stmt = $db->prepare($query);
$stmt->bindParam(':user_id', $user_id);
$stmt->execute();
$stats['notificaciones'] = $stmt->fetch(PDO::FETCH_ASSOC)['total'];

// Actividad reciente — sólo de usuarios de la propia institución
// tbl_logs_actividad no tiene columna id_institucion; se acota por
// institución vía tbl_usuario (u.id_institucion), que ya está joined.
$query = "SELECT l.*, u.usuario, p.primer_nombre, p.primer_apellido
          FROM tbl_logs_actividad l
          JOIN tbl_usuario u ON l.id_usuario = u.id
          JOIN tbl_persona p ON u.id = p.id_usuario
          WHERE u.id_institucion = :tid
          ORDER BY l.fecha_hora DESC LIMIT 10";
$stmt = $db->prepare($query);
$stmt->execute([':tid' => $tid]);
$actividad_reciente = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard Admin - Educación Plus</title>
    
    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Font Awesome -->
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <!-- Custom CSS -->
    <style>
        :root {
            --primary-color: #2c3e50;
            --secondary-color: #3498db;
            --accent-color: #e74c3c;
            --sidebar-width: 250px;
        }
        
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background-color: #f8f9fa;
        }
        
        /* Sidebar */
        .sidebar {
            position: fixed;
            top: 0;
            left: 0;
            height: 100vh;
            width: var(--sidebar-width);
            background: var(--primary-color);
            color: white;
            padding-top: 60px;
            z-index: 1000;
            transition: all 0.3s;
        }
        
        .sidebar .nav-link {
            color: rgba(255,255,255,0.8);
            padding: 12px 20px;
            border-left: 3px solid transparent;
        }
        
        .sidebar .nav-link:hover,
        .sidebar .nav-link.active {
            color: white;
            background: rgba(255,255,255,0.1);
            border-left-color: var(--secondary-color);
        }
        
        .sidebar .nav-link i {
            margin-right: 10px;
            width: 20px;
            text-align: center;
        }
        
        /* Main Content */
        .main-content {
            margin-left: var(--sidebar-width);
            padding: 20px;
            transition: all 0.3s;
        }
        
        /* Top Navbar */
        .top-navbar {
            background: white;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            padding: 15px 30px;
            margin-bottom: 30px;
        }
        
        /* Stats Cards */
        .stats-card {
            background: white;
            border-radius: 10px;
            padding: 25px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.08);
            transition: transform 0.3s;
            border-left: 4px solid var(--secondary-color);
        }
        
        .stats-card:hover {
            transform: translateY(-5px);
        }
        
        .stats-card .icon {
            font-size: 2.5rem;
            opacity: 0.3;
            position: absolute;
            right: 20px;
            top: 20px;
        }
        
        .stats-card h3 {
            font-size: 2rem;
            font-weight: bold;
            margin: 0;
        }
        
        .stats-card p {
            color: #666;
            margin: 0;
        }
        
        /* Quick Actions */
        .quick-action {
            background: white;
            border-radius: 10px;
            padding: 20px;
            text-align: center;
            box-shadow: 0 2px 10px rgba(0,0,0,0.08);
            transition: all 0.3s;
            cursor: pointer;
            text-decoration: none;
            color: inherit;
            display: block;
        }
        
        .quick-action:hover {
            transform: translateY(-3px);
            box-shadow: 0 5px 15px rgba(0,0,0,0.15);
        }

        .quick-action.disabled {
            opacity: 0.5;
            pointer-events: none;
            cursor: default;
        }
        
        .quick-action i {
            font-size: 2rem;
            margin-bottom: 10px;
            color: var(--secondary-color);
        }
        
        /* Tables */
        .table-custom {
            background: white;
            border-radius: 10px;
            overflow: hidden;
            box-shadow: 0 2px 10px rgba(0,0,0,0.08);
        }
        
        .table-custom thead {
            background: var(--primary-color);
            color: white;
        }
        
        /* Notification Badge */
        .notification-badge {
            position: relative;
        }
        
        .notification-badge .badge {
            position: absolute;
            top: -5px;
            right: -5px;
            font-size: 0.7rem;
        }
        
        /* Responsive */
        @media (max-width: 768px) {
            .sidebar {
                transform: translateX(-100%);
            }
            
            .sidebar.active {
                transform: translateX(0);
            }
            
            .main-content {
                margin-left: 0;
            }
        }
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
            <a class="nav-link active" href="<?= BASE_URL ?>/modules/dashboard/admin_dashboard.php">
                <i class="fas fa-tachometer-alt"></i> Dashboard
            </a>

            <div class="sidebar-section">
                <small class="text-uppercase text-muted px-3">Gestión Académica</small>
                <a class="nav-link" href="<?= BASE_URL ?>/modules/admin/gestionar_estudiantes.php">
                    <i class="fas fa-user-graduate"></i> Estudiantes
                </a>
                <a class="nav-link" href="<?= BASE_URL ?>/modules/admin/gestionar_profesores.php">
                    <i class="fas fa-chalkboard-teacher"></i> Profesores
                </a>
                <a class="nav-link" href="<?= BASE_URL ?>/modules/admin/gestionar_grados.php">
                    <i class="fas fa-layer-group"></i> Grados/Secciones
                </a>
                <a class="nav-link" href="<?= BASE_URL ?>/modules/admin/gestionar_asignaturas.php">
                    <i class="fas fa-book"></i> Asignaturas
                </a>
                <a class="nav-link" href="<?= BASE_URL ?>/modules/admin/horario_clases.php">
                    <i class="fas fa-calendar-week"></i> Horario
                </a>
                <a class="nav-link" href="<?= BASE_URL ?>/modules/admin/carnet_estudiantil.php">
                    <i class="fas fa-id-card"></i> Carnet Estudiantil
                </a>
                <a class="nav-link" href="<?= BASE_URL ?>/modules/admin/gestionar_matriculas.php">
                    <i class="fas fa-file-signature"></i> Matrículas
                </a>
            </div>

            <div class="sidebar-section">
                <small class="text-uppercase text-muted px-3">Evaluaciones</small>
                <a class="nav-link disabled" href="#" tabindex="-1" aria-disabled="true" title="La gestión de actividades es una función del panel de profesor; no hay vista de administrador todavía">
                    <i class="fas fa-tasks"></i> Actividades <span class="badge bg-secondary ms-1">Próx.</span>
                </a>
                <a class="nav-link" href="<?= BASE_URL ?>/modules/admin/gestionar_examenes.php">
                    <i class="fas fa-file-alt"></i> Exámenes
                </a>
                <a class="nav-link" href="<?= BASE_URL ?>/modules/admin/calificaciones.php">
                    <i class="fas fa-star"></i> Calificaciones
                </a>
                <a class="nav-link" href="<?= BASE_URL ?>/modules/admin/cuadro_notas.php">
                    <i class="fas fa-clipboard-list"></i> Cuadro de Notas
                </a>
                <a class="nav-link" href="<?= BASE_URL ?>/modules/admin/calendario_evaluaciones.php">
                    <i class="fas fa-calendar-alt"></i> Calendario
                </a>
            </div>

            <div class="sidebar-section">
                <small class="text-uppercase text-muted px-3">Comunicación</small>
                <a class="nav-link disabled" href="#" tabindex="-1" aria-disabled="true" title="Próximamente">
                    <i class="fas fa-bell notification-badge"></i> Notificaciones
                    <?php if($stats['notificaciones'] > 0): ?>
                        <span class="badge bg-danger"><?= $stats['notificaciones'] ?></span>
                    <?php endif; ?>
                    <span class="badge bg-secondary ms-1">Próx.</span>
                </a>
                <a class="nav-link disabled" href="#" tabindex="-1" aria-disabled="true" title="Próximamente">
                    <i class="fas fa-comments"></i> Foros <span class="badge bg-secondary ms-1">Próx.</span>
                </a>
                <a class="nav-link disabled" href="#" tabindex="-1" aria-disabled="true" title="Próximamente">
                    <i class="fas fa-envelope"></i> Mensajería <span class="badge bg-secondary ms-1">Próx.</span>
                </a>
            </div>

            <div class="sidebar-section">
                <small class="text-uppercase text-muted px-3">Reportes</small>
                <a class="nav-link" href="<?= BASE_URL ?>/modules/admin/reporte_notas.php">
                    <i class="fas fa-chart-bar"></i> Reporte de Notas
                </a>
                <a class="nav-link disabled" href="#" tabindex="-1" aria-disabled="true" title="Próximamente">
                    <i class="fas fa-clipboard-check"></i> Reporte de Asistencia <span class="badge bg-secondary ms-1">Próx.</span>
                </a>
                <a class="nav-link disabled" href="#" tabindex="-1" aria-disabled="true" title="Próximamente">
                    <i class="fas fa-chart-line"></i> Uso de Plataforma <span class="badge bg-secondary ms-1">Próx.</span>
                </a>
                <a class="nav-link" href="<?= BASE_URL ?>/modules/admin/reporte_general.php">
                    <i class="fas fa-file-pdf"></i> Reporte General
                </a>
            </div>

            <div class="sidebar-section">
                <small class="text-uppercase text-muted px-3">Sistema</small>
                <a class="nav-link disabled" href="#" tabindex="-1" aria-disabled="true" title="Próximamente">
                    <i class="fas fa-users-cog"></i> Usuarios <span class="badge bg-secondary ms-1">Próx.</span>
                </a>
                <a class="nav-link disabled" href="#" tabindex="-1" aria-disabled="true" title="Próximamente">
                    <i class="fas fa-cog"></i> Configuración <span class="badge bg-secondary ms-1">Próx.</span>
                </a>
                <a class="nav-link disabled" href="#" tabindex="-1" aria-disabled="true" title="Próximamente">
                    <i class="fas fa-history"></i> Logs de Actividad <span class="badge bg-secondary ms-1">Próx.</span>
                </a>
            </div>

            <a class="nav-link mt-4" id="logoutLink" href="<?= BASE_URL ?>/logout.php">
                <i class="fas fa-sign-out-alt"></i> Cerrar Sesión
            </a>
        </nav>
    </div>

    <!-- Main Content -->
    <div class="main-content">
        <!-- Top Navbar -->
        <div class="top-navbar d-flex justify-content-between align-items-center">
            <button class="btn btn-outline-secondary d-md-none" id="sidebarToggle">
                <i class="fas fa-bars"></i>
            </button>
            
            <div>
                <h4 class="mb-0">Panel de Administración</h4>
                <small class="text-muted">Bienvenido, Administrador</small>
            </div>
            
            <div class="d-flex align-items-center">
                <div class="dropdown me-3">
                    <a class="nav-link notification-badge" href="#" id="notifDropdown" 
                       data-bs-toggle="dropdown">
                        <i class="fas fa-bell fa-lg"></i>
                        <?php if($stats['notificaciones'] > 0): ?>
                            <span class="badge bg-danger"><?= $stats['notificaciones'] ?></span>
                        <?php endif; ?>
                    </a>
                    <ul class="dropdown-menu dropdown-menu-end" aria-labelledby="notifDropdown">
                        <li><h6 class="dropdown-header">Notificaciones</h6></li>
                        <li><span class="dropdown-item disabled">Ver todas (próximamente)</span></li>
                    </ul>
                </div>

                <div class="dropdown">
                    <a class="d-flex align-items-center text-decoration-none dropdown-toggle"
                       href="#" id="userDropdown" data-bs-toggle="dropdown">
                        <div class="rounded-circle bg-primary text-white d-flex align-items-center
                                    justify-content-center me-2" style="width: 40px; height: 40px;">
                            <i class="fas fa-user"></i>
                        </div>
                        <span>Admin</span>
                    </a>
                    <ul class="dropdown-menu dropdown-menu-end" aria-labelledby="userDropdown">
                        <li><span class="dropdown-item disabled"><i class="fas fa-user"></i> Perfil (próximamente)</span></li>
                        <li><span class="dropdown-item disabled"><i class="fas fa-cog"></i> Configuración (próximamente)</span></li>
                        <li><hr class="dropdown-divider"></li>
                        <li><a class="dropdown-item" href="<?= BASE_URL ?>/logout.php"><i class="fas fa-sign-out-alt"></i> Salir</a></li>
                    </ul>
                </div>
            </div>
        </div>

        <!-- Stats Cards -->
        <div class="row mb-4">
            <div class="col-md-3 mb-3">
                <div class="stats-card position-relative" style="border-left-color: #3498db;">
                    <div class="icon"><i class="fas fa-user-graduate"></i></div>
                    <h3><?= $stats['estudiantes'] ?></h3>
                    <p>Estudiantes</p>
                </div>
            </div>
            
            <div class="col-md-3 mb-3">
                <div class="stats-card position-relative" style="border-left-color: #2ecc71;">
                    <div class="icon"><i class="fas fa-chalkboard-teacher"></i></div>
                    <h3><?= $stats['profesores'] ?></h3>
                    <p>Profesores</p>
                </div>
            </div>
            
            <div class="col-md-3 mb-3">
                <div class="stats-card position-relative" style="border-left-color: #f39c12;">
                    <div class="icon"><i class="fas fa-book"></i></div>
                    <h3><?= $stats['asignaturas'] ?></h3>
                    <p>Asignaturas</p>
                </div>
            </div>
            
            <div class="col-md-3 mb-3">
                <div class="stats-card position-relative" style="border-left-color: #e74c3c;">
                    <div class="icon"><i class="fas fa-users"></i></div>
                    <h3><?= $stats['usuarios_activos'] ?></h3>
                    <p>Usuarios Activos</p>
                </div>
            </div>
        </div>

        <!-- Quick Actions -->
        <div class="row mb-4">
            <div class="col-12">
                <h5><i class="fas fa-bolt"></i> Acciones Rápidas</h5>
            </div>
            
            <div class="col-md-2 col-4 mb-3">
                <a href="<?= BASE_URL ?>/modules/admin/gestionar_estudiantes.php" class="quick-action">
                    <i class="fas fa-user-plus"></i>
                    <small>Nuevo Estudiante</small>
                </a>
            </div>

            <div class="col-md-2 col-4 mb-3">
                <a href="<?= BASE_URL ?>/modules/admin/gestionar_profesores.php" class="quick-action">
                    <i class="fas fa-user-plus"></i>
                    <small>Nuevo Profesor</small>
                </a>
            </div>

            <div class="col-md-2 col-4 mb-3">
                <a href="<?= BASE_URL ?>/modules/admin/gestionar_matriculas.php" class="quick-action">
                    <i class="fas fa-file-signature"></i>
                    <small>Matricular</small>
                </a>
            </div>

            <div class="col-md-2 col-4 mb-3">
                <a href="#" class="quick-action disabled" tabindex="-1" aria-disabled="true" title="La gestión de actividades es una función del panel de profesor; no hay vista de administrador todavía">
                    <i class="fas fa-plus-circle"></i>
                    <small>Nueva Actividad</small>
                </a>
            </div>

            <div class="col-md-2 col-4 mb-3">
                <a href="<?= BASE_URL ?>/modules/admin/calendario_evaluaciones.php" class="quick-action">
                    <i class="fas fa-calendar-plus"></i>
                    <small>Calendario</small>
                </a>
            </div>

            <div class="col-md-2 col-4 mb-3">
                <a href="<?= BASE_URL ?>/modules/admin/reporte_general.php" class="quick-action">
                    <i class="fas fa-file-pdf"></i>
                    <small>Reportes</small>
                </a>
            </div>

            <div class="col-md-2 col-4 mb-3">
                <a href="<?= BASE_URL ?>/modules/admin/horario_clases.php" class="quick-action">
                    <i class="fas fa-calendar-week"></i>
                    <small>Horario</small>
                </a>
            </div>

            <div class="col-md-2 col-4 mb-3">
                <a href="<?= BASE_URL ?>/modules/admin/carnet_estudiantil.php" class="quick-action">
                    <i class="fas fa-id-card"></i>
                    <small>Carnet Estudiantil</small>
                </a>
            </div>
        </div>

        <!-- Activity Log & Notifications -->
        <div class="row">
            <div class="col-md-8 mb-4">
                <div class="table-custom">
                    <div class="p-3 border-bottom">
                        <h5 class="mb-0"><i class="fas fa-history"></i> Actividad Reciente</h5>
                    </div>
                    <div class="table-responsive">
                        <table class="table table-hover mb-0">
                            <thead>
                                <tr>
                                    <th>Usuario</th>
                                    <th>Acción</th>
                                    <th>Fecha/Hora</th>
                                    <th>IP</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach($actividad_reciente as $log): ?>
                                <tr>
                                    <td><?= htmlspecialchars($log['primer_nombre'] . ' ' . $log['primer_apellido']) ?></td>
                                    <td><span class="badge bg-info"><?= htmlspecialchars($log['accion']) ?></span></td>
                                    <td><?= date('d/m/Y H:i', strtotime($log['fecha_hora'])) ?></td>
                                    <td><small><?= htmlspecialchars($log['ip_address']) ?></small></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <div class="p-3 text-center border-top">
                        <a href="#" class="btn btn-sm btn-outline-primary disabled" tabindex="-1" aria-disabled="true" title="Próximamente">Ver Todos los Logs</a>
                    </div>
                </div>
            </div>
            
            <div class="col-md-4 mb-4">
                <div class="table-custom h-100">
                    <div class="p-3 border-bottom">
                        <h5 class="mb-0"><i class="fas fa-chart-pie"></i> Estadísticas</h5>
                    </div>
                    <div class="p-3">
                        <div class="mb-3">
                            <div class="d-flex justify-content-between mb-1">
                                <small>Estudiantes Activos</small>
                                <small><?= $stats['estudiantes'] ?></small>
                            </div>
                            <div class="progress">
                                <div class="progress-bar bg-primary" style="width: 85%"></div>
                            </div>
                        </div>
                        
                        <div class="mb-3">
                            <div class="d-flex justify-content-between mb-1">
                                <small>Profesores Activos</small>
                                <small><?= $stats['profesores'] ?></small>
                            </div>
                            <div class="progress">
                                <div class="progress-bar bg-success" style="width: 75%"></div>
                            </div>
                        </div>
                        
                        <div class="mb-3">
                            <div class="d-flex justify-content-between mb-1">
                                <small>Asignaturas</small>
                                <small><?= $stats['asignaturas'] ?></small>
                            </div>
                            <div class="progress">
                                <div class="progress-bar bg-warning" style="width: 60%"></div>
                            </div>
                        </div>
                        
                        <hr>
                        
                        <div class="text-center">
                            <a href="#" class="btn btn-outline-primary btn-sm disabled" tabindex="-1" aria-disabled="true" title="Próximamente">
                                <i class="fas fa-chart-line"></i> Ver Uso de Plataforma
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- System Alerts -->
        <div class="row">
            <div class="col-12">
                <div class="alert alert-info alert-dismissible fade show" role="alert">
                    <i class="fas fa-info-circle"></i> 
                    <strong>Información:</strong> El sistema está funcionando correctamente. 
                    Última actualización: <?= date('d/m/Y H:i') ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            </div>
        </div>
    </div>

    <!-- Bootstrap 5 JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <!-- jQuery -->
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    
    <script>
        $(document).ready(function() {
            // Toggle sidebar en móvil
            $('#sidebarToggle').click(function() {
                $('#sidebar').toggleClass('active');
            });
            
            // Marcar nav link activo según URL (compara pathname contra pathname,
            // no un substring de un href absoluto — con BASE_URL los hrefs ya son
            // URLs completas, así que "includes()" sobre el pathname nunca calzaba).
            const currentPath = window.location.pathname;
            $('.sidebar .nav-link').each(function() {
                const href = $(this).attr('href');
                if (!href || href === '#') return;
                try {
                    const linkPath = new URL(href, window.location.origin).pathname;
                    if (linkPath === currentPath) {
                        $(this).addClass('active');
                    }
                } catch (e) { /* href inválido, ignorar */ }
            });

            // Nota: el conteo de notificaciones en vivo (api/get_notificaciones_count.php)
            // todavía no está implementado — no hay endpoint que llamar, así que el
            // polling se deja desactivado para no generar errores 404 cada 30s.

            // Tooltip initialization
            var tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'))
            var tooltipList = tooltipTriggerList.map(function (tooltipTriggerEl) {
                return new bootstrap.Tooltip(tooltipTriggerEl)
            });

            // Confirmar cierre de sesión
            $('#logoutLink, .dropdown-item[href$="logout.php"]').click(function(e) {
                if (!confirm('¿Está seguro de cerrar sesión?')) {
                    e.preventDefault();
                }
            });
        });
    </script>
</body>
</html>