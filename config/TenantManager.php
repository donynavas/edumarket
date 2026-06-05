<?php
// config/TenantManager.php — Multi-tenant compatible con localhost y subdominios

class TenantManager {
    private static ?array $currentTenant = null;
    private static bool   $resolved      = false;

    public static function resolve(PDO $db): ?array {
        if (self::$resolved) return self::$currentTenant;
        self::$resolved = true;

        // Si ya hay institución en sesión, usarla directamente (evita query extra)
        if (!empty($_SESSION['id_institucion'])) {
            self::$currentTenant = self::loadById($db, (int)$_SESSION['id_institucion']);
            return self::$currentTenant;
        }

        $host  = explode(':', $_SERVER['HTTP_HOST'] ?? 'localhost')[0]; // remover puerto
        $parts = explode('.', $host);

        // ── MODO LOCALHOST / SUBCARPETA ──────────────────────────────
        // localhost, 127.0.0.1, o cualquier IP directa
        if ($host === 'localhost' || $host === '127.0.0.1'
            || filter_var($host, FILTER_VALIDATE_IP)) {
            // Cargar la PRIMERA institución activa como tenant por defecto
            // Esto permite usar el sistema sin subdominios en desarrollo
            self::$currentTenant = self::loadDefault($db);
            return self::$currentTenant;
        }

        // ── MODO SUBDOMINIO (producción) ─────────────────────────────
        // Requiere al menos 3 partes: sub.dominio.tld
        if (count($parts) >= 3) {
            $subdomain = strtolower($parts[0]);
            // Ignorar www
            if ($subdomain !== 'www') {
                self::$currentTenant = self::loadBySubdomain($db, $subdomain);
                return self::$currentTenant;
            }
        }

        // Sin subdominio → cargar default
        self::$currentTenant = self::loadDefault($db);
        return self::$currentTenant;
    }

    private static function loadDefault(PDO $db): ?array {
        try {
            $stmt = $db->query(
                "SELECT * FROM tbl_institucion WHERE estado = 'activo' ORDER BY id ASC LIMIT 1"
            );
            $tenant = $stmt->fetch(PDO::FETCH_ASSOC);
            return $tenant ?: null;
        } catch (PDOException $e) {
            error_log('[TenantManager] loadDefault error: ' . $e->getMessage());
            return null;
        }
    }

    private static function loadById(PDO $db, int $id): ?array {
        try {
            $stmt = $db->prepare(
                "SELECT * FROM tbl_institucion WHERE id = :id AND estado = 'activo' LIMIT 1"
            );
            $stmt->execute([':id' => $id]);
            return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        } catch (PDOException $e) {
            error_log('[TenantManager] loadById error: ' . $e->getMessage());
            return null;
        }
    }

    private static function loadBySubdomain(PDO $db, string $sub): ?array {
        try {
            $stmt = $db->prepare(
                "SELECT * FROM tbl_institucion WHERE subdominio = :sub AND estado = 'activo' LIMIT 1"
            );
            $stmt->execute([':sub' => $sub]);
            $tenant = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$tenant) {
                // Subdominio no encontrado → usar default
                return self::loadDefault($db);
            }
            return $tenant;
        } catch (PDOException $e) {
            error_log('[TenantManager] loadBySubdomain error: ' . $e->getMessage());
            return null;
        }
    }

    public static function getId(): ?int {
        return isset(self::$currentTenant['id']) ? (int)self::$currentTenant['id'] : null;
    }

    public static function getName(): string {
        return self::$currentTenant['nombre_ce'] ?? 'Educación Plus';
    }

    public static function get(): ?array {
        return self::$currentTenant;
    }

    public static function reset(): void {
        self::$currentTenant = null;
        self::$resolved      = false;
    }
}
