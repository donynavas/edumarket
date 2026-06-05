<?php
session_start();
require_once __DIR__ . '/config/database.php';

$mensaje = '';
$tipo_mensaje = '';

// Si ya está logueado, redirigir
if (isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit;
}

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $database = new Database();
    $db = $database->getConnection();
    
    try {
        $db->beginTransaction(); // ✅ Iniciar transacción para asegurar integridad
        
        // Recibir y sanitizar datos
        $usuario = trim($_POST['usuario'] ?? '');
        $password = $_POST['password'] ?? '';
        $confirm_password = $_POST['confirm_password'] ?? '';
        $email = filter_var(trim($_POST['email'] ?? ''), FILTER_VALIDATE_EMAIL);
        $rol = $_POST['rol'] ?? 'estudiante';
        $primer_nombre = trim($_POST['primer_nombre'] ?? '');
        $primer_apellido = trim($_POST['primer_apellido'] ?? '');
        $segundo_nombre = trim($_POST['segundo_nombre'] ?? '');
        $segundo_apellido = trim($_POST['segundo_apellido'] ?? '');
        $dui = trim($_POST['dui'] ?? '');
        $fecha_nacimiento = $_POST['fecha_nacimiento'] ?? null;
        $sexo = $_POST['sexo'] ?? '';
        $nacionalidad = trim($_POST['nacionalidad'] ?? 'Salvadoreña');
        $celular = trim($_POST['celular'] ?? '');
        $telefono_fijo = trim($_POST['telefono_fijo'] ?? '');
        $direccion = trim($_POST['direccion'] ?? '');
        
        // Datos específicos por rol
        $nie = '';
        $especialidad = '';
        $area_responsabilidad = '';
        $nivel_acceso = '';
        
        if ($rol == 'estudiante') {
           $nie = strtoupper(trim($_POST['nie'] ?? ''));
        } elseif ($rol == 'profesor') {
            $especialidad = trim($_POST['especialidad'] ?? 'General');
        } elseif ($rol == 'director') {
            $area_responsabilidad = $_POST['area_responsabilidad'] ?? 'General';
        } elseif ($rol == 'admin') {
            $nivel_acceso = $_POST['nivel_acceso'] ?? 'standard';
        }
        
        // Validaciones básicas
        $errores = [];
        
        if (strlen($usuario) < 3) {
            $errores[] = "El usuario debe tener al menos 3 caracteres.";
        }
        
        if (strlen($password) < 6) {
            $errores[] = "La contraseña debe tener al menos 6 caracteres.";
        }
        
        if ($password !== $confirm_password) {
            $errores[] = "Las contraseñas no coinciden.";
        }
        
        if (!$email) {
            $errores[] = "El email no es válido.";
        }
        
        if (empty($primer_nombre) || empty($primer_apellido)) {
            $errores[] = "El nombre y apellido son obligatorios.";
        }
        
        // Validar que el usuario no exista
        if (empty($errores)) {
            $check = $db->prepare("SELECT id FROM tbl_usuario WHERE usuario = :usuario");
            $check->execute([':usuario' => $usuario]);
            if ($check->rowCount() > 0) {
                $errores[] = "El usuario ya está registrado.";
            }
        }
        
        // Validar que el email no exista
        if (empty($errores)) {
            $check = $db->prepare("SELECT id FROM tbl_usuario WHERE email = :email");
            $check->execute([':email' => $email]);
            if ($check->rowCount() > 0) {
                $errores[] = "El email ya está registrado.";
            }
        }
        
        // Si hay errores, mostrarlos
        if (!empty($errores)) {
            $mensaje = implode("<br>", $errores);
            $tipo_mensaje = 'danger';
        } else {
            // ✅ PASO 1: Insertar en tbl_usuario
            $password_hash = password_hash($password, PASSWORD_DEFAULT);
            $query = "INSERT INTO tbl_usuario (usuario, password, email, rol, estado, fecha_registro) 
                      VALUES (:usuario, :password, :email, :rol, 1, NOW())";
            $stmt = $db->prepare($query);
            $stmt->execute([
                ':usuario' => $usuario,
                ':password' => $password_hash,
                ':email' => $email,
                ':rol' => $rol
            ]);
            $id_usuario = $db->lastInsertId();
            
            // ✅ PASO 2: Insertar en tbl_persona (con id_usuario como FK)
            $query_persona = "INSERT INTO tbl_persona (
                id_usuario, 
                primer_nombre, 
                segundo_nombre, 
                primer_apellido, 
                segundo_apellido,
                dui, 
                fecha_nacimiento, 
                sexo, 
                nacionalidad, 
                direccion, 
                telefono_fijo, 
                celular, 
                email,
                estado
            ) VALUES (
                :id_usuario, :p_nombre, :s_nombre, :p_apellido, :s_apellido,
                :dui, :fecha_nac, :sexo, :nacionalidad, :direccion, 
                :tel_fijo, :celular, :email, 'activo'
            )";
            
            $stmt_persona = $db->prepare($query_persona);
            $stmt_persona->execute([
                ':id_usuario' => $id_usuario,
                ':p_nombre' => $primer_nombre,
                ':s_nombre' => $segundo_nombre,
                ':p_apellido' => $primer_apellido,
                ':s_apellido' => $segundo_apellido,
                ':dui' => $dui,
                ':fecha_nac' => $fecha_nacimiento,
                ':sexo' => $sexo,
                ':nacionalidad' => $nacionalidad,
                ':direccion' => $direccion,
                ':tel_fijo' => $telefono_fijo,
                ':celular' => $celular,
                ':email' => $email
            ]);
            $id_persona = $db->lastInsertId();
            
            // ✅ PASO 3: Insertar en tabla específica según rol
            switch ($rol) {
                case 'estudiante':
                    $query_rol = "INSERT INTO tbl_estudiante (
                        id_persona, 
                        nie, 
                        estado_familiar, 
                        discapacidad, 
                        trabaja,
                        estado
                    ) VALUES (
                        :id_persona, :nie, :estado_familiar, :discapacidad, :trabaja, 'activo'
                    )";
                    $stmt_rol = $db->prepare($query_rol);
                    $stmt_rol->execute([
                        ':id_persona' => $id_persona,
                        ':nie' => $nie,
                        ':estado_familiar' => $_POST['estado_familiar'] ?? 'Convive con ambos padres',
                        ':discapacidad' => $_POST['discapacidad'] ?? 'Ninguna',
                        ':trabaja' => $_POST['trabaja'] ?? 0
                    ]);
                    break;
                    
                case 'profesor':
                    $query_rol = "INSERT INTO tbl_profesor (
                        id_persona, 
                        especialidad, 
                        estado
                    ) VALUES (
                        :id_persona, :especialidad, 'activo'
                    )";
                    $stmt_rol = $db->prepare($query_rol);
                    $stmt_rol->execute([
                        ':id_persona' => $id_persona,
                        ':especialidad' => $especialidad
                    ]);
                    break;
                    
                case 'director':
                    $query_rol = "INSERT INTO tbl_director (
                        id_persona, 
                        area_responsabilidad, 
                        estado
                    ) VALUES (
                        :id_persona, :area, 'activo'
                    )";
                    $stmt_rol = $db->prepare($query_rol);
                    $stmt_rol->execute([
                        ':id_persona' => $id_persona,
                        ':area' => $area_responsabilidad
                    ]);
                    break;
                    
                case 'admin':
                    // Los admins pueden no requerir tabla adicional
                    // Si tienes tbl_admin, descomenta esto:
                    /*
                    $query_rol = "INSERT INTO tbl_admin (
                        id_persona, 
                        nivel_acceso
                    ) VALUES (
                        :id_persona, :nivel
                    )";
                    $stmt_rol = $db->prepare($query_rol);
                    $stmt_rol->execute([
                        ':id_persona' => $id_persona,
                        ':nivel' => $nivel_acceso
                    ]);
                    */
                    break;
            }
            
            // ✅ Registrar log de registro
            try {
                $logQuery = "INSERT INTO tbl_logs_actividad (
                    id_usuario, 
                    accion, 
                    descripcion, 
                    ip_address,
                    fecha
                ) VALUES (
                    :id, 'Registro Exitoso', 'Nuevo usuario registrado como $rol', :ip, NOW()
                )";
                $logStmt = $db->prepare($logQuery);
                $logStmt->execute([
                    ':id' => $id_usuario,
                    ':ip' => $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'
                ]);
            } catch (Exception $logError) {
                // Si falla el log, no interrumpimos el registro
                error_log("Error al registrar log: " . $logError->getMessage());
            }
            
            $db->commit(); // ✅ Confirmar transacción
            
            $mensaje = "✅ Registro exitoso. Usuario creado como <strong>$rol</strong>.<br>
                       <small>Redirigiendo al login en 3 segundos...</small>";
            $tipo_mensaje = 'success';
            
            // Redirigir al login después de 3 segundos
            header("refresh:3;url=login.php");
        }
        
    } catch (PDOException $e) {
        $db->rollBack(); // ✅ Revertir cambios si hay error
        error_log("Error en registro: " . $e->getMessage());
        $mensaje = "Error al registrar: " . $e->getMessage();
        $tipo_mensaje = 'danger';
    } catch (Exception $e) {
        $db->rollBack();
        $mensaje = "Error: " . $e->getMessage();
        $tipo_mensaje = 'danger';
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Registro - Educación Plus</title>
    
    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Font Awesome -->
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <!-- Google Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    
    <style>
        :root {
            --primary-gradient: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            --sidebar-width: 250px;
        }
        
        * { margin: 0; padding: 0; box-sizing: border-box; }
        
        body {
            font-family: 'Poppins', sans-serif;
            background: #f8f9fa;
            min-height: 100vh;
        }
        
        .bg-animation {
            position: fixed;
            top: 0; left: 0;
            width: 100%; height: 100%;
            background: var(--primary-gradient);
            z-index: -1;
            animation: gradientShift 15s ease infinite;
            background-size: 400% 400%;
        }
        
        @keyframes gradientShift {
            0% { background-position: 0% 50%; }
            50% { background-position: 100% 50%; }
            100% { background-position: 0% 50%; }
        }
        
        .registro-container {
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
            position: relative;
            z-index: 1;
        }
        
        .registro-card {
            max-width: 700px;
            width: 100%;
            background: white;
            border-radius: 24px;
            box-shadow: 0 25px 80px rgba(0, 0, 0, 0.15);
            overflow: hidden;
            animation: cardSlideIn 0.6s ease-out;
        }
        
        @keyframes cardSlideIn {
            from { opacity: 0; transform: translateY(30px) scale(0.98); }
            to { opacity: 1; transform: translateY(0) scale(1); }
        }
        
        .registro-header {
            background: var(--primary-gradient);
            padding: 30px;
            text-align: center;
            color: white;
            position: relative;
            overflow: hidden;
        }
        
        .registro-header::before {
            content: '';
            position: absolute;
            top: -50%;
            left: -50%;
            width: 200%;
            height: 200%;
            background: radial-gradient(circle, rgba(255,255,255,0.1) 0%, transparent 70%);
            animation: pulse 4s ease-in-out infinite;
        }
        
        @keyframes pulse {
            0%, 100% { transform: scale(1); opacity: 0.5; }
            50% { transform: scale(1.1); opacity: 0.8; }
        }
        
        .registro-header i {
            font-size: 3rem;
            margin-bottom: 15px;
            animation: bounce 2s ease infinite;
            position: relative;
            z-index: 1;
        }
        
        @keyframes bounce {
            0%, 100% { transform: translateY(0); }
            50% { transform: translateY(-10px); }
        }
        
        .registro-header h3 {
            font-size: 1.8rem;
            font-weight: 600;
            margin-bottom: 5px;
            position: relative;
            z-index: 1;
        }
        
        .registro-header p {
            opacity: 0.9;
            font-size: 0.95rem;
            position: relative;
            z-index: 1;
        }
        
        .registro-body {
            padding: 35px;
        }
        
        .form-group {
            margin-bottom: 20px;
            position: relative;
        }
        
        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: 500;
            color: #333;
            font-size: 0.9rem;
        }
        
        .form-control {
            border: 2px solid #e0e0e0;
            border-radius: 12px;
            padding: 12px 15px;
            font-size: 1rem;
            transition: all 0.3s ease;
        }
        
        .form-control:focus {
            border-color: #667eea;
            box-shadow: 0 0 0 4px rgba(102, 126, 234, 0.1);
            outline: none;
        }
        
        .form-control.is-invalid {
            border-color: #e74c3c;
            animation: shake 0.5s ease;
        }
        
        @keyframes shake {
            0%, 100% { transform: translateX(0); }
            25% { transform: translateX(-5px); }
            75% { transform: translateX(5px); }
        }
        
        .input-icon {
            position: relative;
        }
        
        .input-icon i {
            position: absolute;
            left: 15px;
            top: 50%;
            transform: translateY(-50%);
            color: #999;
            z-index: 2;
        }
        
        .input-icon .form-control {
            padding-left: 45px;
        }
        
        .password-toggle {
            position: absolute;
            right: 15px;
            top: 50%;
            transform: translateY(-50%);
            background: none;
            border: none;
            color: #999;
            cursor: pointer;
            padding: 5px;
            transition: color 0.3s ease;
        }
        
        .password-toggle:hover {
            color: #667eea;
        }
        
        .btn-registro {
            background: var(--primary-gradient);
            border: none;
            border-radius: 12px;
            padding: 14px 20px;
            font-size: 1rem;
            font-weight: 600;
            color: white;
            cursor: pointer;
            transition: all 0.3s ease;
            width: 100%;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            position: relative;
            overflow: hidden;
        }
        
        .btn-registro::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255,255,255,0.2), transparent);
            transition: left 0.5s ease;
        }
        
        .btn-registro:hover::before {
            left: 100%;
        }
        
        .btn-registro:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 30px rgba(102, 126, 234, 0.4);
        }
        
        .alert-custom {
            border-radius: 12px;
            border: none;
            padding: 15px 20px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 12px;
            animation: alertSlideIn 0.3s ease;
        }
        
        @keyframes alertSlideIn {
            from { opacity: 0; transform: translateY(-10px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        .alert-danger {
            background: #fef2f2;
            color: #dc2626;
        }
        
        .alert-success {
            background: #f0fdf4;
            color: #16a34a;
        }
        
        .rol-badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 6px 14px;
            border-radius: 20px;
            font-size: 0.85rem;
            font-weight: 600;
            margin-bottom: 15px;
        }
        
        .rol-estudiante { background: #dbeafe; color: #1e40af; }
        .rol-profesor { background: #dcfce7; color: #166534; }
        .rol-admin { background: #fef3c7; color: #92400e; }
        .rol-director { background: #ede9fe; color: #5b21b6; }
        
        .login-link {
            text-align: center;
            margin-top: 25px;
            padding-top: 20px;
            border-top: 1px solid #eee;
        }
        
        .login-link a {
            color: #667eea;
            text-decoration: none;
            font-weight: 500;
        }
        
        .login-link a:hover {
            text-decoration: underline;
        }
        
        .rol-info {
            background: #f8fafc;
            border-left: 4px solid #667eea;
            padding: 12px 15px;
            border-radius: 0 8px 8px 0;
            margin-bottom: 20px;
            font-size: 0.9rem;
            color: #475569;
        }
        
        .form-section-title {
            font-size: 1rem;
            font-weight: 600;
            color: var(--primary-gradient);
            margin: 25px 0 15px 0;
            padding-bottom: 8px;
            border-bottom: 2px solid #e0e0e0;
        }
        
        @media (max-width: 768px) {
            .registro-card {
                border-radius: 20px;
            }
            .registro-body {
                padding: 25px;
            }
        }
    </style>
</head>
<body>
    <div class="bg-animation"></div>
    
    <div class="registro-container">
        <div class="registro-card">
            <div class="registro-header">
                <i class="fas fa-user-plus"></i>
                <h3>Crear Cuenta</h3>
                <p>Regístrate para acceder a Educación Plus</p>
            </div>
            
            <div class="registro-body">
                <?php if ($mensaje): ?>
                <div class="alert-custom alert-<?= $tipo_mensaje ?>">
                    <i class="fas fa-<?= $tipo_mensaje == 'success' ? 'check-circle' : 'exclamation-circle' ?>"></i>
                    <span><?= $mensaje ?></span>
                </div>
                <?php endif; ?>
                
                <form method="POST" id="registroForm" novalidate>
                    <!-- Badge de Rol Seleccionado -->
                    <div class="text-center mb-3">
                        <span class="rol-badge rol-estudiante" id="rolBadge">
                            <i class="fas fa-graduation-cap"></i>
                            <span id="rolBadgeText">Estudiante</span>
                        </span>
                    </div>
                    
                    <!-- Info del Rol -->
                    <div class="rol-info" id="rolInfo">
                        <i class="fas fa-info-circle"></i> 
                        <strong>Estudiante:</strong> Acceso a clases, tareas y calificaciones.
                    </div>
                    
                    <!-- Sección: Datos Personales -->
                    <div class="form-section-title">
                        <i class="fas fa-user"></i> Datos Personales
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6">
                            <div class="form-group">
                                <label>Primer Nombre *</label>
                                <div class="input-icon">
                                    <input type="text" name="primer_nombre" class="form-control" 
                                           placeholder="Ej: Juan" required 
                                           value="<?= htmlspecialchars($_POST['primer_nombre'] ?? '') ?>">
                                    <i class="fas fa-user"></i>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-group">
                                <label>Segundo Nombre</label>
                                <div class="input-icon">
                                    <input type="text" name="segundo_nombre" class="form-control" 
                                           placeholder="Ej: Carlos" 
                                           value="<?= htmlspecialchars($_POST['segundo_nombre'] ?? '') ?>">
                                    <i class="fas fa-user"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6">
                            <div class="form-group">
                                <label>Primer Apellido *</label>
                                <div class="input-icon">
                                    <input type="text" name="primer_apellido" class="form-control" 
                                           placeholder="Ej: Pérez" required 
                                           value="<?= htmlspecialchars($_POST['primer_apellido'] ?? '') ?>">
                                    <i class="fas fa-user-tag"></i>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-group">
                                <label>Segundo Apellido</label>
                                <div class="input-icon">
                                    <input type="text" name="segundo_apellido" class="form-control" 
                                           placeholder="Ej: García" 
                                           value="<?= htmlspecialchars($_POST['segundo_apellido'] ?? '') ?>">
                                    <i class="fas fa-user-tag"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6">
                            <div class="form-group">
                                <label>Email *</label>
                                <div class="input-icon">
                                    <input type="email" name="email" class="form-control" 
                                           placeholder="tu@email.com" required 
                                           value="<?= htmlspecialchars($_POST['email'] ?? '') ?>">
                                    <i class="fas fa-envelope"></i>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-group">
                                <label>Celular *</label>
                                <div class="input-icon">
                                    <input type="text" name="celular" class="form-control" 
                                           placeholder="7777-7777" required 
                                           value="<?= htmlspecialchars($_POST['celular'] ?? '') ?>">
                                    <i class="fas fa-phone"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-4">
                            <div class="form-group">
                                <label>DUI</label>
                                <div class="input-icon">
                                    <input type="text" name="dui" class="form-control" 
                                           placeholder="00000000-0" 
                                           value="<?= htmlspecialchars($_POST['dui'] ?? '') ?>">
                                    <i class="fas fa-id-card"></i>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="form-group">
                                <label>Fecha de Nacimiento</label>
                                <input type="date" name="fecha_nacimiento" class="form-control" 
                                       value="<?= htmlspecialchars($_POST['fecha_nacimiento'] ?? '') ?>">
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="form-group">
                                <label>Sexo</label>
                                <select name="sexo" class="form-select">
                                    <option value="">Seleccionar</option>
                                    <option value="M" <?= ($_POST['sexo'] ?? '') == 'M' ? 'selected' : '' ?>>Masculino</option>
                                    <option value="F" <?= ($_POST['sexo'] ?? '') == 'F' ? 'selected' : '' ?>>Femenino</option>
                                </select>
                            </div>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6">
                            <div class="form-group">
                                <label>Nacionalidad</label>
                                <input type="text" name="nacionalidad" class="form-control" 
                                       value="<?= htmlspecialchars($_POST['nacionalidad'] ?? 'Salvadoreña') ?>">
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-group">
                                <label>Teléfono Fijo</label>
                                <div class="input-icon">
                                    <input type="text" name="telefono_fijo" class="form-control" 
                                           placeholder="2222-2222" 
                                           value="<?= htmlspecialchars($_POST['telefono_fijo'] ?? '') ?>">
                                    <i class="fas fa-phone-alt"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label>Dirección</label>
                        <textarea name="direccion" class="form-control" rows="2" 
                                  placeholder="Dirección completa"><?= htmlspecialchars($_POST['direccion'] ?? '') ?></textarea>
                    </div>
                    
                    <!-- Sección: Datos de Acceso -->
                    <div class="form-section-title">
                        <i class="fas fa-lock"></i> Datos de Acceso
                    </div>
                    
                    <div class="form-group">
                        <label>Nombre de Usuario *</label>
                        <div class="input-icon">
                            <input type="text" name="usuario" class="form-control" 
                                   placeholder="Usuario para login" required 
                                   pattern="[a-zA-Z0-9_]{3,20}" 
                                   title="3-20 caracteres, solo letras, números y guión bajo"
                                   value="<?= htmlspecialchars($_POST['usuario'] ?? '') ?>">
                            <i class="fas fa-at"></i>
                        </div>
                        <small class="text-muted">3-20 caracteres, solo letras, números y _</small>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6">
                            <div class="form-group">
                                <label>Contraseña *</label>
                                <div class="input-icon">
                                    <input type="password" name="password" id="password" class="form-control" 
                                           placeholder="Mínimo 6 caracteres" required minlength="6">
                                    <i class="fas fa-lock"></i>
                                    <button type="button" class="password-toggle" onclick="togglePassword('password', this)">
                                        <i class="fas fa-eye"></i>
                                    </button>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-group">
                                <label>Confirmar Contraseña *</label>
                                <div class="input-icon">
                                    <input type="password" name="confirm_password" id="confirm_password" class="form-control" 
                                           placeholder="Repite la contraseña" required>
                                    <i class="fas fa-lock"></i>
                                    <button type="button" class="password-toggle" onclick="togglePassword('confirm_password', this)">
                                        <i class="fas fa-eye"></i>
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Rol -->
                    <div class="form-group">
                        <label>Tipo de Usuario *</label>
                        <select name="rol" class="form-select" id="rolSelector" onchange="actualizarRol()">
                            <option value="estudiante" <?= ($_POST['rol'] ?? '') == 'estudiante' ? 'selected' : '' ?>>
                                🎓 Estudiante
                            </option>
                            <option value="profesor" <?= ($_POST['rol'] ?? '') == 'profesor' ? 'selected' : '' ?>>
                                👨‍🏫 Profesor
                            </option>
                            <option value="admin" <?= ($_POST['rol'] ?? '') == 'admin' ? 'selected' : '' ?>>
                                ⚙️ Administrador
                            </option>
                            <option value="director" <?= ($_POST['rol'] ?? '') == 'director' ? 'selected' : '' ?>>
                                👔 Director
                            </option>
                        </select>
                    </div>
                    
                    <!-- Campos específicos por rol -->
                    
                    <!-- Datos de Estudiante -->
                    <div class="rol-field" data-rol="estudiante" style="display: none;">
                        <div class="form-group">
                            <label>Estado Familiar</label>
                            <select name="estado_familiar" class="form-select">
                                <option value="Convive con ambos padres">Convive con ambos padres</option>
                                <option value="Convive con madre">Convive con madre</option>
                                <option value="Convive con padre">Convive con padre</option>
                                <option value="Otros">Otros</option>
                            </select>
                        </div>
                        <div class="row">
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label>Discapacidad</label>
                                    <input type="text" name="discapacidad" class="form-control" 
                                           value="<?= htmlspecialchars($_POST['discapacidad'] ?? 'Ninguna') ?>">
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label>¿Trabaja?</label>
                                    <select name="trabaja" class="form-select">
                                        <option value="0" <?= ($_POST['trabaja'] ?? '0') == '0' ? 'selected' : '' ?>>No</option>
                                        <option value="1" <?= ($_POST['trabaja'] ?? '0') == '1' ? 'selected' : '' ?>>Sí</option>
                                    </select>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Especialidad (solo para profesores) -->
                    <div class="form-group rol-field" data-rol="profesor" style="display: none;">
                        <label>Especialidad *</label>
                        <input type="text" name="especialidad" class="form-control" 
                               placeholder="Ej: Matemáticas, Ciencias, Lenguaje"
                               value="<?= htmlspecialchars($_POST['especialidad'] ?? '') ?>">
                        <small class="text-muted">Área de enseñanza principal</small>
                    </div>
                    
                    <!-- Área de Responsabilidad (solo para directores) -->
                    <div class="form-group rol-field" data-rol="director" style="display: none;">
                        <label>Área de Responsabilidad</label>
                        <select name="area_responsabilidad" class="form-select">
                            <option value="General">General</option>
                            <option value="Académica">Académica</option>
                            <option value="Administrativa">Administrativa</option>
                            <option value="Convivencia">Convivencia</option>
                        </select>
                    </div>
                    
                    <!-- Nivel de Acceso (solo para admins) -->
                    <div class="form-group rol-field" data-rol="admin" style="display: none;">
                        <label>Nivel de Acceso</label>
                        <select name="nivel_acceso" class="form-select">
                            <option value="standard">Estándar</option>
                            <option value="avanzado">Avanzado</option>
                            <option value="super">Super Admin</option>
                        </select>
                        <small class="text-muted">Define permisos del administrador</small>
                    </div>
                    
                    <!-- Términos -->
                    <div class="form-check mb-4">
                        <input type="checkbox" class="form-check-input" id="terminos" required>
                        <label class="form-check-label" for="terminos">
                            Acepto los <a href="#" class="text-decoration-none">términos y condiciones</a> y la <a href="#" class="text-decoration-none">política de privacidad</a>
                        </label>
                    </div>
                    
                    <!-- Submit -->
                    <button type="submit" class="btn-registro">
                        <i class="fas fa-user-plus"></i> <span id="btnText">Crear Cuenta</span>
                    </button>
                </form>
                
                <!-- Login Link -->
                <div class="login-link">
                    <p class="mb-2">¿Ya tienes cuenta?</p>
                    <a href="login.php"><i class="fas fa-sign-in-alt"></i> Iniciar Sesión</a>
                </div>
            </div>
        </div>
    </div>

    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    
    <script>
        // Configuración de roles
        const rolesConfig = {
            estudiante: {
                icon: 'fa-graduation-cap',
                label: 'Estudiante',
                class: 'rol-estudiante',
                info: '<i class="fas fa-info-circle"></i> <strong>Estudiante:</strong> Acceso a clases, tareas y calificaciones.'
            },
            profesor: {
                icon: 'fa-chalkboard-teacher',
                label: 'Profesor',
                class: 'rol-profesor',
                info: '<i class="fas fa-info-circle"></i> <strong>Profesor:</strong> Gestión de clases, calificaciones y actividades.'
            },
            admin: {
                icon: 'fa-cog',
                label: 'Administrador',
                class: 'rol-admin',
                info: '<i class="fas fa-info-circle"></i> <strong>Admin:</strong> Configuración del sistema y gestión de usuarios.'
            },
            director: {
                icon: 'fa-user-tie',
                label: 'Director',
                class: 'rol-director',
                info: '<i class="fas fa-info-circle"></i> <strong>Director:</strong> Supervisión general y reportes institucionales.'
            }
        };
        
        // Toggle password visibility
        function togglePassword(inputId, btn) {
            const input = document.getElementById(inputId);
            const icon = btn.querySelector('i');
            
            if (input.type === 'password') {
                input.type = 'text';
                icon.classList.remove('fa-eye');
                icon.classList.add('fa-eye-slash');
            } else {
                input.type = 'password';
                icon.classList.remove('fa-eye-slash');
                icon.classList.add('fa-eye');
            }
        }
        
        // Actualizar UI según rol seleccionado
        function actualizarRol() {
            const rol = document.getElementById('rolSelector').value;
            const config = rolesConfig[rol];
            
            // Actualizar badge
            const badge = document.getElementById('rolBadge');
            badge.className = `rol-badge ${config.class}`;
            badge.innerHTML = `<i class="fas ${config.icon}"></i> <span id="rolBadgeText">${config.label}</span>`;
            
            // Actualizar info
            document.getElementById('rolInfo').innerHTML = config.info;
            
            // Actualizar texto del botón
            document.getElementById('btnText').textContent = `Crear Cuenta de ${config.label}`;
            
            // Mostrar/ocultar campos específicos
            document.querySelectorAll('.rol-field').forEach(field => {
                const fieldRol = field.dataset.rol;
                field.style.display = (fieldRol === rol) ? 'block' : 'none';
                
                // Hacer requeridos los campos del rol activo
                const inputs = field.querySelectorAll('input, select');
                inputs.forEach(input => {
                    if (fieldRol === rol) {
                        if (input.name === 'especialidad' || input.name === 'area_responsabilidad') {
                            input.required = true;
                        }
                    } else {
                        input.required = false;
                    }
                });
            });
        }
        
        // Form validation
        document.getElementById('registroForm').addEventListener('submit', function(e) {
            const password = document.getElementById('password').value;
            const confirm = document.getElementById('confirm_password').value;
            const terminos = document.getElementById('terminos').checked;
            const rol = document.getElementById('rolSelector').value;
            
            // Validar contraseñas
            if (password !== confirm) {
                e.preventDefault();
                alert('⚠️ Las contraseñas no coinciden');
                document.getElementById('confirm_password').classList.add('is-invalid');
                return false;
            }
            
            // Validar términos
            if (!terminos) {
                e.preventDefault();
                alert('⚠️ Debes aceptar los términos y condiciones');
                return false;
            }
            
            // Validar campos específicos del rol
            if (rol === 'profesor' && !document.querySelector('[name="especialidad"]').value.trim()) {
                e.preventDefault();
                alert('⚠️ La especialidad es requerida para profesores');
                return false;
            }
            
            // Show loading
            const btn = this.querySelector('button[type="submit"]');
            btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span> Registrando...';
            btn.disabled = true;
        });
        
        // Remove invalid state on input
        document.querySelectorAll('.form-control').forEach(input => {
            input.addEventListener('input', function() {
                this.classList.remove('is-invalid');
            });
        });
        
        // Initialize
        document.addEventListener('DOMContentLoaded', function() {
            actualizarRol();
            document.querySelector('input[name="primer_nombre"]').focus();
        });
    </script>
</body>
</html>