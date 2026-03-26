<?php

namespace App\Services;

use App\Core\Database;

class RoleService {
    private $db;

    public function __construct() {
        $this->db = Database::getInstance();
    }

    /**
     * Verificar si un usuario tiene un rol específico
     * Reemplaza la función has_role() de PostgreSQL
     */
    public function hasRole(string $userId, string $roleName): bool {
        $sql = "
            SELECT COUNT(*) as count
            FROM user_roles ur
            INNER JOIN roles r ON ur.role_id = r.id
            WHERE ur.user_id = ? AND r.name = ?
        ";
        
        $result = $this->db->fetchOne($sql, [$userId, $roleName]);
        return $result && $result['count'] > 0;
    }

    /**
     * Verificar si un usuario es admin
     */
    public function isAdmin(string $userId): bool {
        return $this->hasRole($userId, 'admin');
    }

    /**
     * Verificar si un usuario es terapeuta
     */
    public function isTherapist(string $userId): bool {
        return $this->hasRole($userId, 'therapist');
    }

    /**
     * Obtener todos los roles de un usuario
     */
    public function getUserRoles(string $userId): array {
        $sql = "
            SELECT r.id, r.name, r.description
            FROM user_roles ur
            INNER JOIN roles r ON ur.role_id = r.id
            WHERE ur.user_id = ?
        ";
        
        return $this->db->fetchAll($sql, [$userId]);
    }

    /**
     * Asignar un rol a un usuario
     */
    public function assignRole(string $userId, string $roleName): bool {
        // Obtener ID del rol
        $role = $this->db->fetchOne("SELECT id FROM roles WHERE name = ?", [$roleName]);
        
        if (!$role) {
            throw new \Exception("Rol no encontrado: {$roleName}");
        }

        // Verificar si el usuario ya tiene este rol
        if ($this->hasRole($userId, $roleName)) {
            throw new \Exception("El usuario ya tiene el rol '{$roleName}'");
        }

        // Generar UUID para user_role
        $userRoleId = $this->generateUuid();

        try {
            $sql = "INSERT INTO user_roles (id, user_id, role_id) VALUES (?, ?, ?)";
            $this->db->executeQuery($sql, [$userRoleId, $userId, $role['id']]);
            
            return true;
        } catch (\Exception $e) {
            error_log('Error asignando rol: ' . $e->getMessage());
            // Si es un error de duplicado, lanzar excepción más descriptiva
            if (strpos($e->getMessage(), 'Duplicate entry') !== false || 
                strpos($e->getMessage(), 'unique_user_role') !== false) {
                throw new \Exception("El usuario ya tiene el rol '{$roleName}'");
            }
            throw $e;
        }
    }

    /**
     * Remover un rol de un usuario
     */
    public function removeRole(string $userId, string $roleName): bool {
        try {
            $sql = "
                DELETE FROM user_roles
                USING roles
                WHERE user_roles.role_id = roles.id
                  AND user_roles.user_id = ?
                  AND roles.name = ?
            ";
            
            $this->db->executeQuery($sql, [$userId, $roleName]);
            return true;
        } catch (\Exception $e) {
            error_log('Error removiendo rol: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Obtener ID de terapeuta para un usuario
     * Reemplaza la función get_therapist_id_for_user() de PostgreSQL
     */
    public function getTherapistIdForUser(string $userId): ?string {
        $sql = "SELECT id FROM therapists WHERE user_id = ? LIMIT 1";
        $result = $this->db->fetchOne($sql, [$userId]);
        
        return $result ? $result['id'] : null;
    }

    /**
     * Generar UUID v4
     */
    private function generateUuid(): string {
        return sprintf(
            '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0, 0xffff), mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0x0fff) | 0x4000,
            mt_rand(0, 0x3fff) | 0x8000,
            mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
        );
    }
}
