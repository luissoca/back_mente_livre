<?php

namespace App\Core;

class Response {
    /**
     * Enviar respuesta JSON
     */
    public static function json($data, $statusCode = 200) {
        http_response_code($statusCode);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($data, JSON_UNESCAPED_UNICODE);
        exit;
    }

    /**
     * Enviar respuesta de error
     */
    public static function error($message, $statusCode = 500, $errors = null) {
        $response = ['message' => $message];
        if ($errors !== null) {
            $response['errors'] = $errors;
        }
        self::json($response, $statusCode);
    }

    /**
     * Enviar respuesta de éxito
     */
    public static function success($data = null, $message = null, $statusCode = 200) {
        $response = [];
        if ($message !== null) {
            $response['message'] = $message;
        }
        if ($data !== null) {
            if (is_array($data) && isset($data['message'])) {
                $response = array_merge($response, $data);
            } else {
                $response['data'] = $data;
            }
        }
        self::json($response, $statusCode);
    }

    /**
     * Enviar respuesta de no autorizado (401)
     */
    public static function unauthorized($message = 'No autorizado') {
        self::error($message, 401);
    }

    /**
     * Enviar respuesta de prohibido (403)
     */
    public static function forbidden($message = 'Acceso prohibido') {
        self::error($message, 403);
    }
}
