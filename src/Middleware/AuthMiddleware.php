<?php

namespace App\Middleware;

use App\Services\AuthService;
use App\Core\Response;
use App\Exceptions\UnauthorizedException;

class AuthMiddleware {
    private $authService;

    public function __construct() {
        $this->authService = new AuthService();
    }

    public function handle() {
        $headers = getallheaders();
        $authHeader = $headers['Authorization'] ?? $headers['authorization'] ?? null;

        if (!$authHeader) {
            Response::error('Token no proporcionado', 401);
            return false;
        }

        // Extraer el token (formato: "Bearer <token>")
        if (preg_match('/Bearer\s+(.*)$/i', $authHeader, $matches)) {
            $token = $matches[1];
        } else {
            Response::error('Formato de token inválido', 401);
            return false;
        }

        try {
            $user = $this->authService->verifyToken($token);
            // Almacenar usuario en variable global para uso en controllers
            $GLOBALS['current_user'] = $user;
            // Establecer USER_ID en $_SERVER para uso en RoleMiddleware
            $_SERVER['USER_ID'] = $user['userId'] ?? null;
            return true;
        } catch (UnauthorizedException $e) {
            Response::error($e->getMessage(), $e->getCode());
            return false;
        }
    }
}
