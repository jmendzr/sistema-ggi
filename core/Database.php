<?php
// ============================================================
//  GGI SISTEMA — Conexión a base de datos (PDO Singleton)
// ============================================================

require_once __DIR__ . '/../config/config.php';

class Database {
    private static ?PDO $instance = null;

    private function __construct() {}
    private function __clone() {}

    public static function getConnection(): PDO {
        if (self::$instance === null) {
            $dsn = sprintf(
                'mysql:host=%s;dbname=%s;charset=%s',
                DB_HOST, DB_NAME, DB_CHARSET
            );
            try {
                self::$instance = new PDO($dsn, DB_USER, DB_PASS, [
                    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES   => false,
                    PDO::ATTR_PERSISTENT         => true,   // reutiliza conexiones → menos latencia
                    PDO::MYSQL_ATTR_INIT_COMMAND => "SET time_zone = '-05:00', NAMES 'utf8mb4'",
                ]);
            } catch (PDOException $e) {
                if (APP_ENV === 'development') {
                    die('Error de conexión: ' . $e->getMessage());
                }
                die('Error interno del servidor. Contacte al administrador.');
            }
        }
        return self::$instance;
    }

    // Shorthand
    public static function get(): PDO {
        return self::getConnection();
    }
}
