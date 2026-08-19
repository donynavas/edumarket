<?php
// config/AuthGuard.php — Verificación de sesión y rol reutilizable.
// Complementa a TenantGuard: AuthGuard confirma QUIÉN es el usuario y que su
// rol tenga permiso para esa página; TenantGuard confirma que sólo vea datos
// de SU institución. Ambos son necesarios, uno no sustituye al otro.

class AuthGuard {
    /**
     * Exige sesión activa y, opcionalmente, que el rol esté en la lista
     * permitida. Si no se cumple, corta la ejecución (redirige a login en
     * páginas HTML, o responde 401/403 en endpoints que esperan JSON).
     */
    public static function requireRole(array $rolesPermitidos = [], bool $isJson = false): void {
        if (session_status() === PHP_SESSION_NONE) session_start();

        if (empty($_SESSION['user_id']) || empty($_SESSION['rol'])) {
            if ($isJson) {
                http_response_code(401);
                header('Content-Type: application/json');
                echo json_encode(['error' => 'No autenticado']);
                exit;
            }
            $base = defined('BASE_URL') ? BASE_URL : '';
            header('Location: ' . $base . '/login.php');
            exit;
        }

        if (!empty($rolesPermitidos) && !in_array($_SESSION['rol'], $rolesPermitidos, true)) {
            if ($isJson) {
                http_response_code(403);
                header('Content-Type: application/json');
                echo json_encode(['error' => 'No autorizado para este recurso']);
                exit;
            }
            http_response_code(403);
            die('No tiene permiso para acceder a esta página.');
        }
    }
}
