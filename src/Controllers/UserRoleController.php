<?php

namespace App\Controllers;

use App\Core\BaseController;
use App\Core\Response;
use App\Services\RoleService;
use App\Services\UserService;
use OpenApi\Attributes as OA;

#[OA\Tag(name: "User Roles", description: "Gestión de roles de usuarios")]
class UserRoleController extends BaseController {
    private RoleService $roleService;
    private UserService $userService;

    public function __construct() {
        $this->roleService = new RoleService();
        $this->userService = new UserService();
    }

    #[OA\Get(
        path: '/users/{userId}/roles',
        summary: 'Obtener roles de un usuario',
        operationId: 'getUserRoles',
        security: [['bearerAuth' => []]],
        tags: ['User Roles'],
        parameters: [
            new OA\Parameter(name: 'userId', in: 'path', required: true, schema: new OA\Schema(type: 'string'))
        ],
        responses: [
            new OA\Response(response: 200, description: 'Lista de roles del usuario')
        ]
    )]
    public function index(string $userId): void {
        $roles = $this->roleService->getUserRoles($userId);
        Response::json(['data' => $roles]);
    }

    #[OA\Post(
        path: '/users/{userId}/roles',
        summary: 'Asignar rol a un usuario',
        operationId: 'assignUserRole',
        security: [['bearerAuth' => []]],
        tags: ['User Roles'],
        parameters: [
            new OA\Parameter(name: 'userId', in: 'path', required: true, schema: new OA\Schema(type: 'string'))
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['role_name'],
                properties: [
                    new OA\Property(property: 'role_name', type: 'string', example: 'therapist')
                ]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: 'Rol asignado exitosamente'),
            new OA\Response(response: 400, description: 'Error al asignar rol')
        ]
    )]
    public function store(string $userId): void {
        try {
            $data = $this->getJsonInput();
            
            $this->validateRequired($data, ['role_name']);
            
            // Verificar que el usuario existe
            $user = $this->userService->getById($userId);
            if (!$user) {
                Response::error('Usuario no encontrado', 404);
                return;
            }
            
            $this->roleService->assignRole($userId, $data['role_name']);
            
            Response::json(['message' => 'Rol asignado exitosamente']);
        } catch (\Exception $e) {
            error_log('Error en UserRoleController::store: ' . $e->getMessage());
            Response::error($e->getMessage(), 400);
        }
    }

    #[OA\Delete(
        path: '/users/{userId}/roles/{roleName}',
        summary: 'Remover rol de un usuario',
        operationId: 'removeUserRole',
        security: [['bearerAuth' => []]],
        tags: ['User Roles'],
        parameters: [
            new OA\Parameter(name: 'userId', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'roleName', in: 'path', required: true, schema: new OA\Schema(type: 'string'))
        ],
        responses: [
            new OA\Response(response: 200, description: 'Rol removido exitosamente'),
            new OA\Response(response: 400, description: 'Error al remover rol')
        ]
    )]
    public function destroy(string $userId, string $roleName): void {
        $success = $this->roleService->removeRole($userId, $roleName);
        
        if (!$success) {
            Response::error('No se pudo remover el rol', 400);
        }
        
        Response::json(['message' => 'Rol removido exitosamente']);
    }
}
