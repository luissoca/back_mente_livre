<?php
/**
 * Manejo de CORS
 * Funciones de compatibilidad hacia atrás
 */

use App\Core\Response;

function handleCors() {
    // Esta función ahora es manejada por CorsMiddleware
    // Se mantiene para compatibilidad
}

function getAllowedOrigin() {
    $allowedOrigins = [
        'http://localhost:5173',
        'http://localhost:3000',
        'https://tudominio.com'
    ];

    $origin = $_SERVER['HTTP_ORIGIN'] ?? null;

    // Si no hay origen (como Postman), permitir
    if (!$origin) {
        return '*';
    }

    // Si el origen está en la lista permitida, devolverlo
    if (in_array($origin, $allowedOrigins)) {
        return $origin;
    }

    // Por defecto, no permitir
    return 'null';
}

/**
 * Función helper para enviar respuestas JSON
 * Ahora usa App\Core\Response
 */
function sendJsonResponse($data, $statusCode = 200) {
    Response::json($data, $statusCode);
}
