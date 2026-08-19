<?php
// config/app.php — Configuración central de la aplicación
// AUTO-DETECTA si corre en subcarpeta (localhost/educacionplus/) o en dominio raíz

if (defined('APP_CONFIGURED')) return;
define('APP_CONFIGURED', true);

// =====================================================
// DETECTAR BASE URL AUTOMÁTICAMENTE
// =====================================================

$protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host     = $_SERVER['HTTP_HOST'] ?? 'localhost';

// Detectar si el script está en una subcarpeta
// Ej: /educacionplus/login.php  →  base = /educacionplus
// Ej: /login.php                →  base = (vacío)
$scriptDir = dirname($_SERVER['SCRIPT_NAME'] ?? '');

// Normalizar: quitar trailing slash, manejar raíz
if ($scriptDir === '/' || $scriptDir === '\\') {
    $scriptDir = '';
}

// Si el script está en un subdirectorio del proyecto (modules/, superadmin/, etc.)
// necesitamos subir hasta la raíz del proyecto
$phpSelf = $_SERVER['PHP_SELF'] ?? '';

// Detectar la raíz del proyecto buscando el nombre de la carpeta raíz
// Funciona para localhost/educacionplus/ y para dominios raíz
$projectRoot = '';
if (preg_match('#^(/[^/]+)(/|$)#', $phpSelf, $m)) {
    // Si el primer segmento NO es un archivo PHP ni "modules", es la carpeta raíz
    $first = $m[1];
    if (!str_ends_with($first, '.php') && $first !== '/modules' && $first !== '/superadmin') {
        // Solo si no es la raíz del servidor
        if ($first !== '/index.php') {
            $projectRoot = $first; // e.g., /educacionplus
        }
    }
}

// Fallback: si DOCUMENT_ROOT + path sugiere subcarpeta
define('BASE_URL',  $protocol . '://' . $host . $projectRoot);
define('BASE_PATH', $projectRoot);  // e.g., "/educacionplus" o ""

// =====================================================
// CARGAR VARIABLES DE ENTORNO (.env)
// =====================================================
// Loader minimalista (sin dependencias) para no exponer credenciales en el
// código fuente. Copiar .env.example a .env y completar los valores reales;
// .env NUNCA debe subirse al control de versiones (ver .gitignore).
function load_env(string $path): void {
    if (!is_file($path)) return;
    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) continue;
        if (!str_contains($line, '=')) continue;
        [$key, $value] = explode('=', $line, 2);
        $key   = trim($key);
        $value = trim($value);
        // Quitar comillas envolventes si existen
        if (strlen($value) >= 2 && (
            ($value[0] === '"' && str_ends_with($value, '"')) ||
            ($value[0] === "'" && str_ends_with($value, "'"))
        )) {
            $value = substr($value, 1, -1);
        }
        if (getenv($key) === false) {
            putenv("$key=$value");
            $_ENV[$key] = $value;
        }
    }
}
load_env(__DIR__ . '/../.env');

function env(string $key, ?string $default = null): ?string {
    $value = getenv($key);
    return $value !== false ? $value : $default;
}

// =====================================================
// CONFIGURACIÓN DE BASE DE DATOS
// =====================================================
// En producción estos valores DEBEN venir de .env, nunca hardcodeados aquí.
define('DB_HOST', env('DB_HOST', '127.0.0.1'));
define('DB_NAME', env('DB_NAME', 'educacion_plus'));
define('DB_USER', env('DB_USER', 'root'));
define('DB_PASS', env('DB_PASS', ''));

// =====================================================
// CONFIGURACIÓN DE APLICACIÓN
// =====================================================
define('APP_NAME',     'Educación Plus');
define('APP_VERSION',  '2.0');
define('APP_TIMEZONE', 'America/El_Salvador');

date_default_timezone_set(APP_TIMEZONE);

// =====================================================
// CONFIGURACIÓN DE SESIÓN
// =====================================================
define('SESSION_LIFETIME', 7200); // 2 horas

// =====================================================
// HELPER: Redirección con path correcto
// =====================================================
function redirect(string $path, bool $exit = true): void {
    // Asegurar que el path empiece con /
    if (!str_starts_with($path, '/')) {
        $path = '/' . $path;
    }
    header('Location: ' . BASE_URL . $path);
    if ($exit) exit;
}

// =====================================================
// HELPER: URL absoluta del proyecto
// =====================================================
function url(string $path = ''): string {
    if (!str_starts_with($path, '/')) $path = '/' . $path;
    return BASE_URL . $path;
}

// =====================================================
// HELPER: Sanitizar output HTML
// =====================================================
function e(mixed $val): string {
    return htmlspecialchars((string)($val ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8');
}
