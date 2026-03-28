<?php

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Services\UserService;
use OpenApi\Attributes as OA;

#[OA\Tag(name: "Users", description: "Gestion de usuarios y perfiles")]
class UserController {
    private UserService $userService;

    public function __construct() {
        $this->userService = new UserService();
    }

    #[OA\Get(
        path: '/users',
        summary: 'Listar usuarios con paginacion',
        operationId: 'getUsers',
        security: [['bearerAuth' => []]],
        tags: ['Users'],
        parameters: [
            new OA\Parameter(name: 'email_classification', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'is_active', in: 'query', required: false, schema: new OA\Schema(type: 'boolean')),
            new OA\Parameter(name: 'role', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 1)),
            new OA\Parameter(name: 'per_page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 50)),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Lista paginada de usuarios',
                content: new OA\JsonContent(type: 'object')
            )
        ]
    )]
    public function index(): void {
        try {
            $filters = [];

            if (isset($_GET['email_classification']) && $_GET['email_classification'] !== '') {
                $filters['email_classification'] = $_GET['email_classification'];
            }

            if (isset($_GET['is_active']) && $_GET['is_active'] !== '') {
                $filters['is_active'] = filter_var($_GET['is_active'], FILTER_VALIDATE_BOOLEAN) ? 1 : 0;
            }

            if (isset($_GET['role']) && $_GET['role'] !== '') {
                $filters['role'] = $_GET['role'];
            }

            $page    = max(1, (int)($_GET['page']     ?? 1));
            $perPage = max(1, min(200, (int)($_GET['per_page'] ?? 50)));

            $result = $this->userService->getAll($filters, $page, $perPage);
            Response::json($result);

        } catch (\Exception $e) {
            error_log('Error in UserController::index(): ' . $e->getMessage());
            Response::json(['error' => 'Error interno del servidor'], 500);
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
            new OA\Response(response: 200, description: 'Detalle del usuario', content: new OA\JsonContent(ref: '#/components/schemas/User')),
            new OA\Response(response: 404, description: 'Usuario no encontrado')
        ]
    )]
    public function show(string $id): void {
        $user = $this->userService->getById($id);
        if (!$user) {
            Response::json(['error' => 'Usuario no encontrado'], 404);
            return;
        }
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

        $data = json_decode(file_get_contents('php://input'), true);
        if (!$data) {
            Response::json(['error' => 'Datos invalidos'], 400);
            return;
        }

        try {
            $this->userService->update($id, $data);
            Response::json(['message' => 'Usuario actualizado exitosamente']);
        } catch (\Exception $e) {
            Response::json(['error' => 'Error al actualizar el usuario: ' . $e->getMessage()], 500);
        }
    }

    #[OA\Patch(
        path: '/users/{id}/deactivate',
        summary: 'Desactivar usuario',
        operationId: 'deactivateUser',
        security: [['bearerAuth' => []]],
        tags: ['Users'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string'))
        ],
        responses: [
            new OA\Response(response: 200, description: 'Usuario desactivado exitosamente'),
            new OA\Response(response: 404, description: 'Usuario no encontrado')
        ]
    )]
    public function deactivate(string $id): void {
        $user = $this->userService->getById($id);
        if (!$user) {
            Response::json(['error' => 'Usuario no encontrado'], 404);
            return;
        }

        try {
            $this->userService->deactivate((int)$id);
            Response::json(['message' => 'Usuario desactivado exitosamente']);
        } catch (\Exception $e) {
            Response::json(['error' => 'Error al desactivar el usuario: ' . $e->getMessage()], 500);
        }
    }

    #[OA\Patch(
        path: '/users/{id}/activate',
        summary: 'Activar usuario',
        operationId: 'activateUser',
        security: [['bearerAuth' => []]],
        tags: ['Users'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string'))
        ],
        responses: [
            new OA\Response(response: 200, description: 'Usuario activado exitosamente'),
            new OA\Response(response: 404, description: 'Usuario no encontrado')
        ]
    )]
    public function activate(string $id): void {
        $user = $this->userService->getById($id);
        if (!$user) {
            Response::json(['error' => 'Usuario no encontrado'], 404);
            return;
        }

        try {
            $this->userService->activate((int)$id);
            Response::json(['message' => 'Usuario activado exitosamente']);
        } catch (\Exception $e) {
            Response::json(['error' => 'Error al activar el usuario: ' . $e->getMessage()], 500);
        }
    }
}

#[OA\Schema(
    schema: 'User',
    properties: [
        new OA\Property(property: 'id',          type: 'string'),
        new OA\Property(property: 'email',        type: 'string', format: 'email'),
        new OA\Property(property: 'full_name',    type: 'string'),
        new OA\Property(property: 'is_active',    type: 'boolean'),
        new OA\Property(property: 'email_verified', type: 'boolean'),
        new OA\Property(property: 'first_name',   type: 'string', nullable: true),
        new OA\Property(property: 'last_name',    type: 'string', nullable: true),
        new OA\Property(property: 'phone',        type: 'string', nullable: true),
        new OA\Property(
            property: 'roles',
            type: 'array',
            items: new OA\Items(type: 'string')
        ),
        new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
        new OA\Property(property: 'updated_at', type: 'string', format: 'date-time'),
    ]
)]
class UserSchema {}

#[OA\Schema(
    schema: 'UserUpdate',
    properties: [
        new OA\Property(property: 'full_name',  type: 'string'),
        new OA\Property(property: 'first_name', type: 'string'),
        new OA\Property(property: 'last_name',  type: 'string'),
        new OA\Property(property: 'phone',      type: 'string'),
    ]
)]
class UserUpdateSchema {}
