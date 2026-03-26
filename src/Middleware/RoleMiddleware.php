<?php

namespace App\Middleware;

use App\Services\RoleService;
use App\Core\Response;

class RoleMiddleware {
    private $roleService;

    public function __construct() {
        $this->roleService = new RoleService();
    }

    /**
     * Verificar que el usuario tiene uno de los roles requeridos
     * Reemplaza Row Level Security (RLS) de PostgreSQL
     */
    public function checkRole(array $allowedRoles = []): void {
        // Obtener usuario del token JWT
        $userId = $_SERVER['USER_ID'] ?? null;

        if (!$userId) {
            Response::unauthorized('No autenticado');
        }

        // Si no se especifican roles, solo requiere autenticación
        if (empty($allowedRoles)) {
            return;
        }

        // Verificar si el usuario tiene alguno de los roles permitidos
        $hasRole = false;
        foreach ($allowedRoles as $role) {
            if ($this->roleService->hasRole($userId, $role)) {
                $hasRole = true;
                break;
            }
        }

        if (!$hasRole) {
            Response::forbidden('No tiene permisos para acceder a este recurso');
        }
    }

    /**
     * Solo admins
     */
    public function adminOnly(): void {
        $this->checkRole(['admin']);
    }

    /**
     * Solo terapeutas
     */
    public function therapistOnly(): void {
        $this->checkRole(['therapist']);
    }

    /**
     * Admins o terapeutas
     */
    public function staffOnly(): void {
        $this->checkRole(['admin', 'therapist']);
    }

    /**
     * Verificar que el usuario es el propietario del recurso o es admin
     */
    public function ownerOrAdmin(string $resourceOwnerId): void {
        $userId = $_SERVER['USER_ID'] ?? null;

        if (!$userId) {
            Response::unauthorized('No autenticado');
        }

        // Si es admin, tiene acceso
        if ($this->roleService->isAdmin($userId)) {
            return;
        }

        // Si es el dueño del recurso, tiene acceso
        if ($userId === $resourceOwnerId) {
            return;
        }

        Response::forbidden('No tiene permisos para acceder a este recurso');
    }

    /**
     * Verificar que el usuario es terapeuta y está accediendo a sus propios recursos
     */
    public function therapistOwnResource(string $therapistId): void {
        $userId = $_SERVER['USER_ID'] ?? null;

        if (!$userId) {
            Response::unauthorized('No autenticado');
        }

        // Verificar que es terapeuta
        if (!$this->roleService->isTherapist($userId)) {
            Response::forbidden('Debe ser terapeuta para acceder a este recurso');
        }

        // Verificar que el therapist_id corresponde al usuario
        $userTherapistId = $this->roleService->getTherapistIdForUser($userId);
        
        if ($userTherapistId !== $therapistId) {
            Response::forbidden('No puede acceder a recursos de otros terapeutas');
        }
    }
}
