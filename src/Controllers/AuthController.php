<?php

namespace App\Controllers;

use App\Core\BaseController;
use App\Core\Response;
use App\Services\AuthService;
use OpenApi\Attributes as OA;

#[OA\Tag(
    name: 'Auth',
    description: 'Endpoints de autenticación'
)]
class AuthController extends BaseController {
    
    #[OA\Post(
        path: '/auth/check-student',
        summary: 'Verificar correo estudiante',
        description: 'Verifica si el email pertenece a un dominio universitario y si ya está registrado.',
        tags: ['Auth'],
        operationId: 'checkStudent',
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['email'],
                properties: [
                    new OA\Property(property: 'email', type: 'string', example: 'usuario@universidad.edu.pe')
                ]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: 'Resultado de la verificación')
        ]
    )]
    public function checkStudent() {
        $data = $this->getJsonInput();
        $this->validateRequired($data, ['email']);

        $email = strtolower($data['email']);
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            Response::error('Email inválido', 400);
        }

        $isStudent = false;
        $db = \App\Core\Database::getInstance()->getConnection();
        
        $domain = substr(strrchr($email, "@"), 1);
        if ($domain) {
            $domain = strtolower($domain);
            $sql = "SELECT domain FROM email_domain_rules WHERE is_active = TRUE AND rule_type = 'whitelist'";
            $stmt = $db->prepare($sql);
            $stmt->execute();
            
            $endsWith = function($haystack, $needle) {
                $length = strlen($needle);
                if (!$length) return true;
                return substr($haystack, -$length) === $needle;
            };

            while ($row = $stmt->fetch()) {
                $allowedDomain = strtolower($row['domain']);
                if ($domain === $allowedDomain || $endsWith($domain, '.' . $allowedDomain)) {
                    $isStudent = true;
                    break;
                }
            }

            // Fallback for general .edu domains if not found in db
            if (!$isStudent) {
                if ($endsWith($domain, '.edu') || $endsWith($domain, '.edu.pe') || $endsWith($domain, 'universidad.pe')) {
                    $isStudent = true;
                }
            }
        }

        $userExists = false;
        $sqlUser = "SELECT id FROM users WHERE email = :email LIMIT 1";
        $stmtUser = $db->prepare($sqlUser);
        $stmtUser->execute([':email' => $email]);
        if ($stmtUser->fetch()) {
            $userExists = true;
        }

        Response::json([
            'is_student' => $isStudent,
            'user_exists' => $userExists
        ]);
    }

    private $authService;

    public function __construct() {
        $this->authService = new AuthService();
    }

    #[OA\Post(
        path: '/auth/login',
        summary: 'Iniciar sesión',
        description: 'Autenticar usuario y obtener token JWT',
        tags: ['Auth'],
        operationId: 'login',
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['username', 'password'],
                properties: [
                    new OA\Property(property: 'username', type: 'string', example: 'admin@mentelivre.com'),
                    new OA\Property(property: 'password', type: 'string', example: 'password123')
                ]
            )
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'Login exitoso',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'success', type: 'boolean', example: true),
                        new OA\Property(
                            property: 'data',
                            properties: [
                                new OA\Property(property: 'token', type: 'string'),
                                new OA\Property(property: 'user', type: 'object')
                            ],
                            type: 'object'
                        )
                    ]
                )
            ),
            new OA\Response(response: 401, description: 'Credenciales inválidas')
        ]
    )]
    public function login() {
        $data = $this->getJsonInput();
        $this->validateRequired($data, ['username', 'password']);

        try {
            $result = $this->authService->login($data['username'], $data['password']);
            Response::json($result, 200);
        } catch (\Exception $e) {
            Response::error($e->getMessage(), 401);
        }
    }

    #[OA\Post(
        path: '/auth/refresh',
        summary: 'Refrescar token',
        description: 'Obtener un nuevo token usando un refresh token',
        tags: ['Auth'],
        operationId: 'refreshToken',
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['refresh_token'],
                properties: [
                    new OA\Property(property: 'refresh_token', type: 'string')
                ]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: 'Token refrescado exitosamente'),
            new OA\Response(response: 401, description: 'Token inválido')
        ]
    )]
    public function refresh() {
        $data = $this->getJsonInput();
        $this->validateRequired($data, ['refresh_token']);

        try {
            $result = $this->authService->refreshToken($data['refresh_token']);
            Response::json($result, 200);
        } catch (\Exception $e) {
            Response::error($e->getMessage(), 401);
        }
    }

    #[OA\Post(
        path: '/auth/register',
        summary: 'Registrar nuevo usuario',
        description: 'Crear una nueva cuenta de usuario',
        tags: ['Auth'],
        operationId: 'register',
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['email', 'password', 'first_name', 'last_name'],
                properties: [
                    new OA\Property(property: 'email', type: 'string', example: 'usuario@example.com'),
                    new OA\Property(property: 'password', type: 'string', example: 'password123'),
                    new OA\Property(property: 'first_name', type: 'string', example: 'Juan'),
                    new OA\Property(property: 'last_name', type: 'string', example: 'Pérez')
                ]
            )
        ),
        responses: [
            new OA\Response(
                response: 201,
                description: 'Registro exitoso',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'success', type: 'boolean', example: true),
                        new OA\Property(
                            property: 'data',
                            properties: [
                                new OA\Property(property: 'token', type: 'string'),
                                new OA\Property(property: 'refresh_token', type: 'string'),
                                new OA\Property(property: 'user', type: 'object')
                            ],
                            type: 'object'
                        )
                    ]
                )
            ),
            new OA\Response(response: 400, description: 'Datos inválidos'),
            new OA\Response(response: 409, description: 'El email ya está registrado')
        ]
    )]
    public function register() {
        $data = $this->getJsonInput();
        $this->validateRequired($data, ['email', 'password', 'first_name', 'last_name']);

        // Validar formato de email
        if (!filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
            Response::error('Email inválido', 400);
        }

        // Validar longitud de contraseña
        if (strlen($data['password']) < 6) {
            Response::error('La contraseña debe tener al menos 6 caracteres', 400);
        }

        try {
            $result = $this->authService->register(
                $data['email'],
                $data['password'],
                $data['first_name'],
                $data['last_name']
            );
            Response::json($result, 201);
        } catch (\Exception $e) {
            $statusCode = strpos($e->getMessage(), 'ya está registrado') !== false ? 409 : 400;
            Response::error($e->getMessage(), $statusCode);
        }
    }

    #[OA\Post(
        path: '/auth/logout',
        summary: 'Cerrar sesión',
        description: 'Invalidar el token actual',
        tags: ['Auth'],
        operationId: 'logout',
        security: [['bearerAuth' => []]],
        responses: [
            new OA\Response(response: 200, description: 'Logout exitoso'),
            new OA\Response(response: 401, description: 'No autenticado')
        ]
    )]
    public function logout() {
        $data = $this->getJsonInput();
        
        if (isset($data['refresh_token'])) {
            $this->authService->logout($data['refresh_token']);
        }

        Response::json(['message' => 'Sesión cerrada exitosamente'], 200);
    }

    #[OA\Post(
        path: '/auth/forgot-password',
        summary: 'Solicitar reset de contraseña',
        description: 'Envía un email con un enlace para restablecer la contraseña',
        tags: ['Auth'],
        operationId: 'forgotPassword',
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['email'],
                properties: [
                    new OA\Property(property: 'email', type: 'string', example: 'usuario@example.com')
                ]
            )
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'Solicitud procesada (siempre retorna éxito por seguridad)',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'success', type: 'boolean', example: true),
                        new OA\Property(property: 'message', type: 'string')
                    ]
                )
            )
        ]
    )]
    public function forgotPassword() {
        $data = $this->getJsonInput();
        $this->validateRequired($data, ['email']);

        // Validar formato de email
        if (!filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
            Response::error('Email inválido', 400);
        }

        try {
            $result = $this->authService->forgotPassword($data['email']);
            Response::json($result, 200);
        } catch (\Exception $e) {
            // Por seguridad, siempre retornamos éxito
            Response::json([
                'success' => true,
                'message' => 'Si el email existe, se ha enviado un enlace de recuperación.'
            ], 200);
        }
    }

    #[OA\Post(
        path: '/auth/reset-password',
        summary: 'Resetear contraseña',
        description: 'Restablece la contraseña usando un token de recuperación',
        tags: ['Auth'],
        operationId: 'resetPassword',
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['token', 'new_password'],
                properties: [
                    new OA\Property(property: 'token', type: 'string', description: 'Token de recuperación recibido por email'),
                    new OA\Property(property: 'new_password', type: 'string', example: 'nuevaPassword123', description: 'Nueva contraseña (mínimo 6 caracteres)')
                ]
            )
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'Contraseña actualizada exitosamente',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'success', type: 'boolean', example: true),
                        new OA\Property(property: 'message', type: 'string')
                    ]
                )
            ),
            new OA\Response(response: 400, description: 'Datos inválidos'),
            new OA\Response(response: 401, description: 'Token inválido o expirado')
        ]
    )]
    public function resetPassword() {
        $data = $this->getJsonInput();
        $this->validateRequired($data, ['token', 'new_password']);

        // Validar longitud de contraseña
        if (strlen($data['new_password']) < 6) {
            Response::error('La contraseña debe tener al menos 6 caracteres', 400);
        }

        try {
            $result = $this->authService->resetPassword($data['token'], $data['new_password']);
            Response::json($result, 200);
        } catch (\Exception $e) {
            $statusCode = strpos($e->getMessage(), 'Token inválido') !== false ? 401 : 400;
            Response::error($e->getMessage(), $statusCode);
        }
    }
}

