<?php
session_start();
require_once __DIR__ . '/../../config/database.php';

$id_leccion = $_GET['id'] ?? 0;

// Obtener lección
$query = "SELECT l.*, c.nombre as curso_nombre, c.nivel
          FROM tbl_ingles_leccion l
          JOIN tbl_ingles_curso c ON l.id_curso = c.id
          WHERE l.id = :id";
$stmt = $db->prepare($query);
$stmt->bindValue(':id', $id_leccion, PDO::PARAM_INT);
$stmt->execute();
$leccion = $stmt->fetch(PDO::FETCH_ASSOC);

// Obtener ejercicios
$query = "SELECT * FROM tbl_ingles_ejercicio WHERE id_leccion = :id ORDER BY orden";
$stmt = $db->prepare($query);
$stmt->bindValue(':id', $id_leccion, PDO::PARAM_INT);
$stmt->execute();
$ejercicios = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title><?= htmlspecialchars($leccion['titulo']) ?> - English Plus</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <style>
        body { background: #f7f7f7; }
        .exercise-card {
            background: white;
            border-radius: 16px;
            padding: 30px;
            margin-bottom: 20px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.08);
        }
        .option-btn {
            width: 100%;
            padding: 15px;
            margin: 10px 0;
            border: 2px solid #e5e5e5;
            border-radius: 12px;
            background: white;
            text-align: left;
            transition: all 0.2s;
        }
        .option-btn:hover {
            border-color: #58cc02;
            background: #f0fff0;
        }
        .option-btn.selected {
            border-color: #58cc02;
            background: #58cc02;
            color: white;
        }
        .option-btn.correct {
            border-color: #58cc02;
            background: #58cc02;
            color: white;
        }
        .option-btn.incorrect {
            border-color: #ff4b4b;
            background: #ff4b4b;
            color: white;
        }
        .progress-bar-duo {
            height: 10px;
            background: #e5e5e5;
            border-radius: 10px;
            overflow: hidden;
        }
        .progress-fill {
            height: 100%;
            background: #58cc02;
            transition: width 0.3s;
        }
    </style>
</head>
<body>
    <div class="container py-5">
        <!-- Progress Bar -->
        <div class="mb-4">
            <div class="d-flex justify-content-between mb-2">
                <span>Progreso</span>
                <span id="progressText">0/<?= count($ejercicios) ?></span>
            </div>
            <div class="progress-bar-duo">
                <div class="progress-fill" id="progressFill" style="width: 0%"></div>
            </div>
        </div>
        
        <!-- Video (si existe) -->
        <?php if ($leccion['video_url']): ?>
        <div class="video-container mb-4">
            <iframe src="https://www.youtube.com/embed/<?= htmlspecialchars($leccion['video_url']) ?>" 
                    frameborder="0" allowfullscreen></iframe>
        </div>
        <?php endif; ?>
        
        <!-- Ejercicios -->
        <div id="exerciseContainer">
            <?php foreach ($ejercicios as $index => $ejercicio): ?>
            <div class="exercise-card" data-index="<?= $index ?>" style="display: <?= $index === 0 ? 'block' : 'none' ?>">
                <h4 class="mb-4"><?= htmlspecialchars($ejercicio['pregunta']) ?></h4>
                
                <?php
                $opciones = json_decode($ejercicio['opciones_json'], true) ?? [];
                foreach ($opciones as $opcion):
                ?>
                <button class="option-btn" onclick="seleccionarOpcion(this, '<?= $opcion ?>')">
                    <?= htmlspecialchars($opcion) ?>
                </button>
                <?php endforeach; ?>
                
                <div class="mt-4 d-flex justify-content-between">
                    <button class="btn btn-secondary" onclick="anteriorEjercicio()">
                        <i class="fas fa-arrow-left"></i> Anterior
                    </button>
                    <button class="btn btn-success" onclick="verificarRespuesta()">
                        <i class="fas fa-check"></i> Verificar
                    </button>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        
        <!-- Resultado Final -->
        <div id="resultadoFinal" style="display: none;" class="text-center py-5">
            <i class="fas fa-trophy fa-5x text-warning mb-4"></i>
            <h2>¡Lección Completada!</h2>
            <p class="lead">Puntaje: <span id="puntajeFinal">0</span>/<?= count($ejercicios) * 10 ?></p>
            <button class="btn btn-success btn-lg" onclick="guardarProgreso()">
                <i class="fas fa-save"></i> Guardar Progreso
            </button>
        </div>
    </div>
    
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script>
        let ejercicioActual = 0;
        let respuestas = [];
        let puntaje = 0;
        const ejercicios = <?= json_encode($ejercicios) ?>;
        
        function seleccionarOpcion(btn, opcion) {
            $('.option-btn').removeClass('selected');
            $(btn).addClass('selected');
            respuestas[ejercicioActual] = opcion;
        }
        
        function verificarRespuesta() {
            const respuestaSeleccionada = respuestas[ejercicioActual];
            const respuestaCorrecta = ejercicios[ejercicioActual].respuesta_correcta;
            
            if (respuestaSeleccionada === respuestaCorrecta) {
                $('.option-btn.selected').removeClass('selected').addClass('correct');
                puntaje += parseInt(ejercicios[ejercicioActual].puntos);
            } else {
                $('.option-btn.selected').removeClass('selected').addClass('incorrect');
                $('.option-btn').each(function() {
                    if ($(this).text().trim() === respuestaCorrecta) {
                        $(this).addClass('correct');
                    }
                });
            }
            
            setTimeout(() => {
                siguienteEjercicio();
            }, 1500);
        }
        
        function siguienteEjercicio() {
            $('.exercise-card').hide();
            ejercicioActual++;
            
            if (ejercicioActual >= ejercicios.length) {
                mostrarResultado();
            } else {
                $(`.exercise-card[data-index="${ejercicioActual}"]`).show();
                actualizarProgreso();
            }
        }
        
        function anteriorEjercicio() {
            if (ejercicioActual > 0) {
                $('.exercise-card').hide();
                ejercicioActual--;
                $(`.exercise-card[data-index="${ejercicioActual}"]`).show();
                actualizarProgreso();
            }
        }
        
        function actualizarProgreso() {
            const porcentaje = ((ejercicioActual + 1) / ejercicios.length) * 100;
            $('#progressFill').css('width', porcentaje + '%');
            $('#progressText').text(`${ejercicioActual + 1}/${ejercicios.length}`);
        }
        
        function mostrarResultado() {
            $('#exerciseContainer').hide();
            $('#resultadoFinal').show();
            $('#puntajeFinal').text(puntaje);
        }
        
        function guardarProgreso() {
            $.post('../api/ingles/guardar_progreso.php', {
                id_leccion: <?= $id_leccion ?>,
                puntaje: puntaje,
                respuestas: JSON.stringify(respuestas)
            }, function(response) {
                if (response.success) {
                    window.location.href = 'ingles_dashboard.php';
                }
            }, 'json');
        }
    </script>
</body>
</html>