<?php

namespace App\Middleware;

class CorsMiddleware {
    public function handle() {
        $origin = $this->getAllowedOrigin();
        
        // Manejar preflight OPTIONS
        if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
            // Eliminar cualquier output previo
            if (ob_get_length()) ob_clean();
            
            header('Access-Control-Allow-Origin: ' . $origin);
            header('Access-Control-Allow-Methods: GET, HEAD, PUT, PATCH, POST, DELETE, OPTIONS');
            header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');
            header('Access-Control-Allow-Credentials: true');
            header('Access-Control-Max-Age: 86400'); // 24 horas
            http_response_code(200);
            exit;
        }

        // Headers CORS para todas las respuestas
        header('Access-Control-Allow-Origin: ' . $origin);
        header('Access-Control-Allow-Methods: GET, HEAD, PUT, PATCH, POST, DELETE, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');
        header('Access-Control-Allow-Credentials: true');
        
        // Solo establecer Content-Type si no es una respuesta de descarga
        if (!headers_sent() && !isset($GLOBALS['skip_content_type'])) {
            header('Content-Type: application/json; charset=utf-8');
        }

        return true; // Continuar con la ejecución
    }

    private function getAllowedOrigin() {
        $allowedOrigins = [
            // Desarrollo local - varios puertos comunes
            'http://localhost:3000',
            'http://localhost:5173',
            'http://localhost:5174',
            'http://localhost:8080',
            'http://localhost:8081',
            'http://127.0.0.1:3000',
            'http://127.0.0.1:5173',
            'http://127.0.0.1:5174',
            'http://127.0.0.1:8080',
            // Producción - Mente Livre
            'https://mentelivre.org',
            'https://www.mentelivre.org',
            'https://backend.mentelivre.org'
        ];

        $origin = $_SERVER['HTTP_ORIGIN'] ?? null;

        // Si no hay origen (como Postman o peticiones directas), permitir en desarrollo
        if (!$origin) {
            // En desarrollo, permitir todo. En producción puedes restringir
            return '*';
        }

        // Si el origen está en la lista permitida, devolverlo
        if (in_array($origin, $allowedOrigins)) {
            return $origin;
        }

        // En desarrollo: si es localhost con cualquier puerto, permitirlo
        if (preg_match('/^http:\/\/(localhost|127\.0\.0\.1):\d+$/', $origin)) {
            return $origin;
        }

        // Por defecto, no permitir (producción)
        return 'null';
    }
}
