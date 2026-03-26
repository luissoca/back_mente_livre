<?php

namespace App\Core;

use PDO;
use PDOException;

class Database {
    private static $instance = null;
    private $connection;

    private function __construct() {
        try {
            // CRITICAL: Clean up PostgreSQL environment variables that might cause conflicts.
            // Specifically PGCHANNELBINDING which seems to have an invalid value ("require ") in the Vercel environment.
            // This line ensures that even if Vercel has a bad value, we ignore it.
            putenv('PGCHANNELBINDING');
            putenv('PGSSLMODE');

            $host = trim($_ENV['DB_HOST'] ?? getenv('DB_HOST'));
            $dbname = trim($_ENV['DB_DATABASE'] ?? getenv('DB_DATABASE'));
            $username = trim($_ENV['DB_USER'] ?? getenv('DB_USER'));
            $password = trim($_ENV['DB_PASSWORD'] ?? getenv('DB_PASSWORD'));
            $port = trim($_ENV['DB_PORT'] ?? getenv('DB_PORT') ?? 5432);
            $sslmode = trim($_ENV['DB_SSLMODE'] ?? getenv('DB_SSLMODE') ?? 'require');

            // Validar que todas las variables requeridas estén configuradas
            if (empty($host)) {
                throw new \Exception('DB_HOST no está configurado. Verifica tu archivo .env');
            }
            if (empty($dbname)) {
                throw new \Exception('DB_DATABASE no está configurado. Verifica tu archivo .env');
            }
            if (empty($username)) {
                throw new \Exception('DB_USER no está configurado. Verifica tu archivo .env');
            }
            if ($password === null || $password === '') {
                throw new \Exception('DB_PASSWORD no está configurado. Verifica tu archivo .env');
            }

            // Extract endpoint ID for Neon (SNI workaround)
            // Host format: ep-lively-pine-ai8bftr4-pooler.c-4.us-east-1.aws.neon.tech
            // Endpoint ID: ep-lively-pine-ai8bftr4
            $endpointId = explode('.', $host)[0];

            // PostgreSQL uses pgsql:host=...;port=...;dbname=...;sslmode=...;options=endpoint=...
            $dsn = "pgsql:host={$host};port={$port};dbname={$dbname};sslmode={$sslmode};options=endpoint={$endpointId}";
            
            $options = [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ];

            $this->connection = new PDO($dsn, $username, $password, $options);
            
            // Establecer zona horaria a -05:00 (Perú) para PostgreSQL
            $this->connection->exec("SET TIME ZONE 'America/Lima'");
            
            error_log("✅ Database connected to HOST: " . $host . " DB: " . $dbname);
            
        } catch (PDOException $e) {
            error_log('Error de conexión a la base de datos (PostgreSQL): ' . $e->getMessage());
            throw new \Exception('Error de conexión a la base de datos: ' . $e->getMessage());
        }
    }

    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function getConnection() {
        return $this->connection;
    }

    // Prevenir clonación
    private function __clone() {}
    
    // Prevenir deserialización
    public function __wakeup() {
        throw new \Exception("Cannot unserialize singleton");
    }

    /**
     * Ejecutar query
     */
    public function executeQuery($sql, $params = []) {
        try {
            $stmt = $this->connection->prepare($sql);
            $stmt->execute($params);
            return $stmt;
        } catch (PDOException $e) {
            error_log('Error ejecutando query: ' . $e->getMessage());
            error_log('SQL: ' . $sql);
            throw $e;
        }
    }

    /**
     * Obtener todos los registros
     */
    public function fetchAll($sql, $params = []) {
        $stmt = $this->executeQuery($sql, $params);
        return $stmt->fetchAll();
    }

    /**
     * Obtener un solo registro
     */
    public function fetchOne($sql, $params = []) {
        $stmt = $this->executeQuery($sql, $params);
        return $stmt->fetch();
    }
}
