<?php
session_start();
require_once __DIR__ . '/config/app.php';

// Si ya está logueado, redirigir al index (que redirige por rol)
if (isset($_SESSION['user_id'])) {
    redirect('/index.php');
}

// Si es superadmin
if (isset($_SESSION['rol']) && $_SESSION['rol'] === 'superadmin') {
    redirect('/superadmin/dashboard.php');
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Educación Plus — Plataforma Educativa</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">
    <style>
        :root { --primary: #2c3e50; --brand: #3498db; }
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Segoe UI', system-ui, sans-serif; }

        .hero {
            background: linear-gradient(135deg, var(--primary) 0%, var(--brand) 100%);
            min-height: 100vh; display: flex; align-items: center;
            padding: 2rem 1rem;
        }
        .hero-content { color: white; }
        .hero h1 { font-size: 3rem; font-weight: 800; line-height: 1.1; }
        .hero h1 span { color: #f39c12; }
        .hero .lead { opacity: .85; font-size: 1.1rem; }

        .card-feature {
            background: rgba(255,255,255,.1); backdrop-filter: blur(8px);
            border: 1px solid rgba(255,255,255,.2); border-radius: 1rem;
            padding: 1.5rem; color: white; text-align: center;
            transition: transform .2s;
        }
        .card-feature:hover { transform: translateY(-4px); }
        .card-feature i { font-size: 2rem; margin-bottom: .75rem; color: #f39c12; }

        .btn-hero {
            background: #f39c12; color: white; border: none;
            padding: 1rem 2.5rem; border-radius: 3rem;
            font-size: 1.1rem; font-weight: 700;
            text-decoration: none; display: inline-block;
            transition: all .2s;
        }
        .btn-hero:hover { background: #e67e22; color: white; transform: translateY(-2px); }

        .btn-hero-outline {
            background: transparent; color: white;
            border: 2px solid rgba(255,255,255,.5);
            padding: 1rem 2.5rem; border-radius: 3rem;
            font-size: 1.1rem; font-weight: 600;
            text-decoration: none; display: inline-block;
            transition: all .2s;
        }
        .btn-hero-outline:hover { background: rgba(255,255,255,.15); color: white; }
    </style>
</head>
<body>
<section class="hero">
    <div class="container">
        <div class="row align-items-center g-5">
            <div class="col-lg-7">
                <div class="hero-content">
                    <div class="mb-3">
                        <span class="badge" style="background:rgba(255,255,255,.15);font-size:.9rem;padding:.5rem 1rem;border-radius:2rem;">
                            <i class="fas fa-star text-warning me-1"></i> Plataforma Educativa Multi-Institución
                        </span>
                    </div>
                    <h1 class="mb-3">Gestión Educativa <span>Moderna</span></h1>
                    <p class="lead mb-4">
                        Sistema integral para instituciones educativas. Administre estudiantes,
                        profesores, calificaciones, evaluaciones y bienestar estudiantil desde un solo lugar.
                    </p>
                    <div class="d-flex gap-3 flex-wrap">
                        <a href="<?= url('/login.php') ?>" class="btn-hero">
                            <i class="fas fa-right-to-bracket me-2"></i> Ingresar al Sistema
                        </a>
                        <a href="<?= url('/superadmin/login.php') ?>" class="btn-hero-outline">
                            <i class="fas fa-shield-halved me-2"></i> Panel Admin
                        </a>
                    </div>
                </div>
            </div>
            <div class="col-lg-5">
                <div class="row g-3">
                    <?php
                    $features = [
                        ['icon'=>'fa-users','title'=>'Gestión Estudiantil','desc'=>'Control total de matrículas y expedientes'],
                        ['icon'=>'fa-chalkboard-user','title'=>'Portal Docente','desc'=>'Actividades, evaluaciones y calificaciones'],
                        ['icon'=>'fa-chart-line','title'=>'Reportes','desc'=>'Estadísticas y reportes académicos en PDF'],
                        ['icon'=>'fa-heart-pulse','title'=>'Bienestar','desc'=>'Seguimiento del bienestar estudiantil'],
                    ];
                    foreach ($features as $f): ?>
                    <div class="col-6">
                        <div class="card-feature">
                            <i class="fas <?= $f['icon'] ?>"></i>
                            <h6 class="fw-bold mb-1"><?= $f['title'] ?></h6>
                            <small style="opacity:.75"><?= $f['desc'] ?></small>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
        <div class="text-center mt-5">
            <small style="color:rgba(255,255,255,.4)">
                © <?= date('Y') ?> Educación Plus &bull; Sistema Multi-Tenant &bull; v2.0
            </small>
        </div>
    </div>
</section>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
