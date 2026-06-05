# 🚀 Guía de Instalación — localhost/educacionplus/

## Problema que se solucionó
El HTTP 500 era causado por **4 problemas simultáneos**:
1. `header("Location: welcome.php")` → con subcarpeta va a `localhost/welcome.php` (¡incorrecto!)
2. `estudiante_dashboard.php` tenía `include 'config/database.php'` (ruta rota)
3. `profesor_dashboard.php` igual — ruta rota
4. `TenantManager` en localhost entraba en bucle infinito de redirecciones

## Pasos para instalar

### 1. Descomprimir
Extraer `educacionplus_fixed.zip` en su carpeta de XAMPP:
```
C:\xampp\htdocs\educacionplus\   (Windows)
/var/www/html/educacionplus/     (Linux)
```

### 2. Importar la base de datos
En phpMyAdmin:
1. Crear BD llamada `educacion_plus`
2. Importar el archivo `educacion_plus.sql` que viene dentro del ZIP

### 3. Configurar credenciales de BD
Abrir `config/app.php` y verificar:
```php
define('DB_HOST', '127.0.0.1');
define('DB_NAME', 'educacion_plus');
define('DB_USER', 'root');
define('DB_PASS', '');   // su contraseña de MySQL
```

### 4. Acceder
- **Sitio principal:** http://localhost/educacionplus/
- **Login:** http://localhost/educacionplus/login.php
- **Super Admin:** http://localhost/educacionplus/superadmin/login.php

### 5. Credenciales de prueba (del SQL original)
| Usuario | Contraseña | Rol |
|---|---|---|
| `admin` | (ver BD - hash) | Admin |
| `estudiante` | (ver BD) | Estudiante |
| `teacher` | (ver BD) | Profesor |
| `super` | (ver BD) | Super Admin |

> Si las contraseñas no funcionan, regenerarlas con:
> ```php
> <?php echo password_hash('nueva_clave', PASSWORD_DEFAULT); ?>
> ```
> Y actualizarlas en la BD: `UPDATE tbl_usuario SET password='HASH' WHERE usuario='admin';`

## Lo que se corrigió en esta versión
- ✅ Auto-detección de subcarpeta (funciona en localhost/educacionplus/ Y en dominio raíz)
- ✅ Todas las redirecciones usan rutas absolutas con `BASE_URL`
- ✅ TenantManager carga la primera institución activa en localhost (sin subdominio)
- ✅ 52 archivos PHP con rutas de include corregidas
- ✅ `session_start()` comentado → activado en todos los módulos
- ✅ `db_global.php` recreado para superadmin
