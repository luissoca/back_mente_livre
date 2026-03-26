<?php

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Services\UserService;
use OpenApi\Attributes as OA;

#[OA\Tag(name: "Users", description: "Gestión de usuarios y perfiles")]
class UserController {
    private UserService $userService;

    public function __construct() {
        $this->userService = new UserService();
    }

    #[OA\Get(
        path: '/users',
        summary: 'Listar usuarios',
        operationId: 'getUsers',
        security: [['bearerAuth' => []]],
        tags: ['Users'],
        parameters: [
            new OA\Parameter(name: 'email_classification', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'verified_email', in: 'query', required: false, schema: new OA\Schema(type: 'boolean'))
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Lista de usuarios',
                content: new OA\JsonContent(
                    type: 'array',
                    items: new OA\Items(ref: '#/components/schemas/User')
                )
            )
        ]
    )]
    public function index(): void {
        try {
            // Obtener query parameters directamente desde $_GET
            $filters = [];
            
            if (isset($_GET['email_classification']) && $_GET['email_classification'] !== '') {
                $filters['email_classification'] = $_GET['email_classification'];
            }
            
        if (isset($_GET['verified_email']) && $_GET['verified_email'] !== '') {
            // Convertir a boolean (1 o 0) para la columna email_verified
            $filters['verified_email'] = filter_var($_GET['verified_email'], FILTER_VALIDATE_BOOLEAN) ? 1 : 0;
        }
            
            $users = $this->userService->getAll($filters);
            Response::json(['data' => $users]);
        } catch (\Exception $e) {
            error_log('Error in UserController::index(): ' . $e->getMessage());
            error_log('Stack trace: ' . $e->getTraceAsString());
            Response::json(['error' => 'Error interno del servidor: ' . $e->getMessage()], 500);
        }
    }

    #[OA\Get(
        path: '/users/{id}',
        summary: 'Obtener usuario por ID',
        operationId: 'getUser',
        security: [['bearerAuth' => []]],
        tags: ['Users'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string'))
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Detalle del usuario',
                content: new OA\JsonContent(ref: '#/components/schemas/User')
            ),
            new OA\Response(response: 404, description: 'Usuario no encontrado')
        ]
    )]
    public function show(string $id): void {
        $user = $this->userService->getById($id);
        
        if (!$user) {
            Response::json(['error' => 'Usuario no encontrado'], 404);
            return;
        }
        
        // Remover información sensible
        unset($user['password_hash']);
        
        Response::json(['data' => $user]);
    }

    #[OA\Put(
        path: '/users/{id}',
        summary: 'Actualizar usuario',
        operationId: 'updateUser',
        security: [['bearerAuth' => []]],
        tags: ['Users'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string'))
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(ref: '#/components/schemas/UserUpdate')
        ),
        responses: [
            new OA\Response(response: 200, description: 'Usuario actualizado exitosamente'),
            new OA\Response(response: 404, description: 'Usuario no encontrado')
        ]
    )]
    public function update(string $id): void {
        $user = $this->userService->getById($id);
        
        if (!$user) {
            Response::json(['error' => 'Usuario no encontrado'], 404);
            return;
        }
        
        // Obtener datos del body JSON directamente
        $data = json_decode(file_get_contents('php://input'), true);
        
        if (!$data) {
            Response::json(['error' => 'Datos inválidos'], 400);
            return;
        }
        
        try {
            $this->userService->update($id, $data);
            Response::json(['message' => 'Usuario actualizado exitosamente']);
        } catch (\Exception $e) {
            Response::json(['error' => 'Error al actualizar el usuario: ' . $e->getMessage()], 500);
        }
    }
}

#[OA\Schema(
    schema: 'User',
    properties: [
        new OA\Property(property: 'id', type: 'string'),
        new OA\Property(property: 'email', type: 'string', format: 'email'),
        new OA\Property(property: 'full_name', type: 'string'),
        new OA\Property(property: 'email_classification', type: 'string', nullable: true),
        new OA\Property(property: 'verified_email', type: 'boolean'),
        new OA\Property(property: 'first_name', type: 'string', nullable: true),
        new OA\Property(property: 'last_name', type: 'string', nullable: true),
        new OA\Property(property: 'phone', type: 'string', nullable: true),
        new OA\Property(property: 'date_of_birth', type: 'string', format: 'date', nullable: true),
        new OA\Property(property: 'university', type: 'string', nullable: true),
        new OA\Property(property: 'profile_photo_url', type: 'string', nullable: true),
        new OA\Property(property: 'about_me', type: 'string', nullable: true),
        new OA\Property(
            property: 'roles',
            type: 'array',
            items: new OA\Items(
                properties: [
                    new OA\Property(property: 'id', type: 'integer'),
                    new OA\Property(property: 'name', type: 'string')
                ],
                type: 'object'
            )
        ),
        new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
        new OA\Property(property: 'updated_at', type: 'string', format: 'date-time')
    ]
)]
class UserSchema {}

#[OA\Schema(
    schema: 'UserUpdate',
    properties: [
        new OA\Property(property: 'full_name', type: 'string'),
        new OA\Property(property: 'first_name', type: 'string'),
        new OA\Property(property: 'last_name', type: 'string'),
        new OA\Property(property: 'phone', type: 'string'),
        new OA\Property(property: 'date_of_birth', type: 'string', format: 'date'),
        new OA\Property(property: 'university', type: 'string'),
        new OA\Property(property: 'profile_photo_url', type: 'string'),
        new OA\Property(property: 'about_me', type: 'string')
    ]
)]
class UserUpdateSchema {}
