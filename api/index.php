<?php
ob_start();
/**
 * Punto de entrada principal del API
 * Router que maneja todas las peticiones
 */

// Autoload - Ajustado para subir un nivel desde api/
require_once __DIR__ . '/../vendor/autoload.php';

// Configurar zona horaria para Perú (America/Lima)
date_default_timezone_set('America/Lima');

// Config - Ajustado para subir un nivel desde api/
require_once __DIR__ . '/../config/env.php';

// Cargar rutas y router - Ajustado para subir un nivel desde api/
use App\Core\Router;
require_once __DIR__ . '/../routes/api.php';

// Detectar base path automáticamente
// En producción: https://example.com/backend_mente_livre/
// Se puede configurar manualmente con variable de entorno APP_BASE_PATH
$basePath = $_ENV['APP_BASE_PATH'] ?? getenv('APP_BASE_PATH') ?? '';

if (empty($basePath)) {
    // Detectar desde SCRIPT_NAME (más confiable)
    $scriptName = $_SERVER['SCRIPT_NAME'] ?? '/index.php';
    
    // FIX: En Vercel/Serverless, SCRIPT_NAME a veces refleja el REQUEST_URI
    // Solo confiamos en SCRIPT_NAME si termina en .php (es decir, es el script real)
    if (substr($scriptName, -4) === '.php') {
        $scriptDir = dirname($scriptName);
        
        // Si el script está en una subcarpeta (no en root)
        if ($scriptDir !== '/' && $scriptDir !== '.' && $scriptDir !== '\\') {
            $basePath = $scriptDir;
        }
    }
}

error_log('[index.php] basePath detectado: ' . ($basePath ?: 'vacío'));
error_log('[index.php] REQUEST_URI: ' . ($_SERVER['REQUEST_URI'] ?? 'no definido'));
error_log('[index.php] SCRIPT_NAME: ' . ($_SERVER['SCRIPT_NAME'] ?? 'no definido'));

$router = new Router($basePath);

// Configurar rutas
error_log('[index.php] Configurando rutas...');
configureRoutes($router);
error_log('[index.php] Rutas configuradas');

// Despachar la petición
try {
    error_log('[index.php] Despachando petición...');
    $router->dispatch();
    error_log('[index.php] Petición despachada exitosamente');
} catch (\Exception $e) {
    error_log('[index.php] ERROR en router: ' . $e->getMessage());
    error_log('[index.php] Stack trace: ' . $e->getTraceAsString());
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['message' => 'Error interno del servidor'], JSON_UNESCAPED_UNICODE);
}

ob_end_flush();
