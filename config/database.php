<?php
// config/database.php — Conexión PDO segura con soporte multitenant

require_once __DIR__ . '/app.php';
require_once __DIR__ . '/TenantManager.php';

class Database {
    private string $host;
    private string $db_name;
    private string $username;
    private string $password;
    public ?PDO $conn = null;

    public function __construct() {
        $this->host     = defined('DB_HOST') ? DB_HOST : '127.0.0.1';
        $this->db_name  = defined('DB_NAME') ? DB_NAME : 'educacion_plus';
        $this->username = defined('DB_USER') ? DB_USER : 'root';
        $this->password = defined('DB_PASS') ? DB_PASS : '';
    }

    public function getConnection(): ?PDO {
        if ($this->conn !== null) return $this->conn;

        $dsn = "mysql:host={$this->host};dbname={$this->db_name};charset=utf8mb4";
        try {
            $this->conn = new PDO($dsn, $this->username, $this->password, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
                PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci",
            ]);

            // Resolver tenant automáticamente
            TenantManager::resolve($this->conn);

        } catch (PDOException $e) {
            error_log('[Database] Conexión fallida: ' . $e->getMessage());
            // No mostrar detalles al usuario
            die('Error de conexión a la base de datos. Verifique la configuración en config/app.php');
        }
        return $this->conn;
    }

    // Compatibilidad con código que usa tenantQuery()
    public function tenantQuery(string $sql): string {
        $tenantId = TenantManager::getId();
        if (!$tenantId) return $sql;
        if (stripos($sql, 'WHERE') !== false) {
            return $sql . " AND id_institucion = " . (int)$tenantId;
        }
        return $sql . " WHERE id_institucion = " . (int)$tenantId;
    }
}
