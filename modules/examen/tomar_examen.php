<?php
// Verificar sesión y permisos antes de cargar
require_once __DIR__ . '/../../config/database.php';
// Obtener datos del examen desde $id_examen
$tiempo_limite = 30; // Minutos, venir de BD
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Examen en Línea</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
</head>
<body oncontextmenu="return false;"> <!-- Deshabilitar click derecho -->

<div class="container mt-5">
    <div class="alert alert-warning" id="warningAlert" style="display:none;">
        ⚠️ ¡No cambies de pestaña! El examen se cerrará automáticamente.
    </div>
    
    <div class="d-flex justify-content-between">
        <h3>Examen de Matemáticas</h3>
        <h4 id="timer">30:00</h4>
    </div>

    <form id="examForm" action="guardar_examen.php" method="POST">
        <!-- Preguntas cargadas desde BD -->
        <div class="mb-3">
            <label>1. ¿Cuánto es 2 + 2?</label>
            <input type="radio" name="p1" value="4"> 4
            <input type="radio" name="p1" value="5"> 5
        </div>
        <button type="submit" class="btn btn-success">Finalizar Examen</button>
    </form>
</div>

<script>
    // 1. Temporizador
    let time = <?php echo $tiempo_limite * 60; ?>;
    const timerElement = document.getElementById('timer');
    
    const countdown = setInterval(() => {
        let minutes = Math.floor(time / 60);
        let seconds = time % 60;
        seconds = seconds < 10 ? '0' + seconds : seconds;
        timerElement.innerHTML = `${minutes}:${seconds}`;
        time--;

        if (time < 0) {
            clearInterval(countdown);
            document.getElementById('examForm').submit();
        }
    }, 1000);

    // 2. Seguridad de Ventanas (Anti-Trampa)
    let violationCount = 0;
    const maxViolations = 2;

    document.addEventListener("visibilitychange", () => {
        if (document.hidden) {
            handleViolation();
        }
    });

    window.addEventListener("blur", () => {
        handleViolation();
    });

    function handleViolation() {
        violationCount++;
        $("#warningAlert").fadeIn();
        if (violationCount >= maxViolations) {
            alert("Se ha detectado intento de fraude. El examen se cerrará.");
            document.getElementById('examForm').submit();
            window.close();
        }
    }

    // 3. Pantalla Completa al iniciar
    document.documentElement.requestFullscreen().catch((e) => console.log(e));
</script>
</body>
</html>