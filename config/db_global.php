<?php
// config/db_global.php — Conexión global (sin tenant) para superadmin
require_once __DIR__ . '/app.php';
require_once __DIR__ . '/database.php';

// Alias para compatibilidad con superadmin
class DatabaseGlobal {
    private static ?PDO $conn = null;

    public function getConnection(): PDO {
        if (self::$conn !== null) return self::$conn;
        
        $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4';
        try {
            self::$conn = new PDO($dsn, DB_USER, DB_PASS, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
                PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4",
            ]);
        } catch (PDOException $e) {
            error_log('[DatabaseGlobal] ' . $e->getMessage());
            die('Error de conexión. Verifique la configuración en config/app.php');
        }
        return self::$conn;
    }
}
