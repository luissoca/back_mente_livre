<?php

namespace App\Services;

use App\Core\Database;
use PDO;
use App\Services\EmailDomainRuleService;

class UserService {
    private PDO $db;
    private EmailDomainRuleService $emailDomainRuleService;

    public function __construct() {
        $this->db = Database::getInstance()->getConnection();
        $this->emailDomainRuleService = new EmailDomainRuleService();
    }

    /**
     * Obtener todos los usuarios con filtros
     */
    public function getAll(array $filters = []): array {
        $sql = "SELECT 
            u.id, u.email, u.email_verified, u.is_active,
            u.created_at, u.updated_at,
            p.first_name, p.last_name, p.full_name, p.phone
            FROM users u
            LEFT JOIN profiles p ON u.id = p.user_id
            WHERE 1=1";
        
        $params = [];
        
        if (isset($filters['email_classification'])) {
            // Filtrar por clasificación de email usando la tabla email_classifications
            $sql .= " AND EXISTS (
                SELECT 1 FROM email_classifications ec 
                WHERE ec.user_id = u.id 
                AND ec.account_type = :email_classification
            )";
            $params[':email_classification'] = $filters['email_classification'];
        }
        
        if (isset($filters['verified_email'])) {
            $sql .= " AND u.email_verified = :verified_email";
            $params[':verified_email'] = $filters['verified_email'] ? 'true' : 'false';
        }
        
        $sql .= " ORDER BY u.created_at DESC";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        
        $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Obtener roles de cada usuario
        foreach ($users as &$user) {
            $user['roles'] = $this->getUserRoles($user['id']);
            // Agregar email_classification e is_university_verified (desde email_classifications)
            $classification = $this->ensureEmailClassification($user['id'], $user['email'] ?? null);
            $user['email_classification'] = $classification['account_type'] ?? null;
            $user['is_university_verified'] = isset($classification['is_university_verified'])
                ? (bool)$classification['is_university_verified']
                : null;
            // Mapear email_verified a verified_email para compatibilidad con el frontend
            $user['verified_email'] = (bool)($user['email_verified'] ?? false);
        }
        
        return $users;
    }

    /**
     * Obtener usuario por ID
     */
    public function getById(string $id): ?array {
        $sql = "SELECT 
            u.id, u.email, u.email_verified, u.is_active,
            u.created_at, u.updated_at,
            p.first_name, p.last_name, p.full_name, p.phone
            FROM users u
            LEFT JOIN profiles p ON u.id = p.user_id
            WHERE u.id = :id";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute([':id' => $id]);
        
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($user) {
            $user['roles'] = $this->getUserRoles($id);
            // Agregar email_classification e is_university_verified
            $classification = $this->ensureEmailClassification($id, $user['email'] ?? null);
            $user['email_classification'] = $classification['account_type'] ?? null;
            $user['is_university_verified'] = isset($classification['is_university_verified'])
                ? (bool)$classification['is_university_verified']
                : null;
            // Mapear email_verified a verified_email para compatibilidad
            $user['verified_email'] = (bool)($user['email_verified'] ?? false);
        }
        
        return $user ?: null;
    }

    /**
     * Obtener usuario por email
     */
    public function getByEmail(string $email): ?array {
        $sql = "SELECT 
            u.id, u.email, u.email_verified, u.is_active, u.password_hash,
            u.created_at, u.updated_at
            FROM users u
            WHERE u.email = :email";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute([':email' => $email]);
        
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($user) {
            $user['roles'] = $this->getUserRoles($user['id']);
            // Agregar email_classification e is_university_verified
            $classification = $this->ensureEmailClassification($user['id'], $user['email'] ?? null);
            $user['email_classification'] = $classification['account_type'] ?? null;
            $user['is_university_verified'] = isset($classification['is_university_verified'])
                ? (bool)$classification['is_university_verified']
                : null;
            // Mapear email_verified a verified_email para compatibilidad
            $user['verified_email'] = (bool)($user['email_verified'] ?? false);
        }
        
        return $user ?: null;
    }

    /**
     * Actualizar usuario
     */
    public function update(string $id, array $data): bool {
        $this->db->beginTransaction();
        
        try {
            // Actualizar datos básicos del usuario (solo email, no full_name que está en profiles)
            if (!empty($data['email'])) {
                $sql = "UPDATE users SET email = :email WHERE id = :id";
                $stmt = $this->db->prepare($sql);
                $stmt->execute([':email' => $data['email'], ':id' => $id]);
            }
            
            // Actualizar o crear perfil
            $profileFields = ['first_name', 'last_name', 'full_name', 'phone'];
            $hasProfileData = false;
            
            foreach ($profileFields as $field) {
                if (isset($data[$field])) {
                    $hasProfileData = true;
                    break;
                }
            }
            
            if ($hasProfileData) {
                // Verificar si existe perfil
                $stmt = $this->db->prepare("SELECT id FROM profiles WHERE user_id = :user_id");
                $stmt->execute([':user_id' => $id]);
                $existingProfile = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if ($existingProfile) {
                    // Actualizar perfil existente
                    $updateFields = [];
                    $updateParams = [':user_id' => $id];
                    
                    foreach ($profileFields as $field) {
                        if (isset($data[$field])) {
                            $updateFields[] = "$field = :$field";
                            $updateParams[":$field"] = $data[$field];
                        }
                    }
                    
                    if (!empty($updateFields)) {
                        $sql = "UPDATE profiles SET " . implode(', ', $updateFields) . " WHERE user_id = :user_id";
                        $stmt = $this->db->prepare($sql);
                        $stmt->execute($updateParams);
                    }
                } else {
                    // Crear nuevo perfil
                    $insertFields = ['id', 'user_id'];
                    $insertValues = [':id', ':user_id'];
                    $insertParams = [
                        ':id' => $this->generateUUID(),
                        ':user_id' => $id
                    ];
                    
                    foreach ($profileFields as $field) {
                        if (isset($data[$field])) {
                            $insertFields[] = $field;
                            $insertValues[] = ":$field";
                            $insertParams[":$field"] = $data[$field];
                        }
                    }
                    
                    $sql = "INSERT INTO profiles (" . implode(', ', $insertFields) . ") 
                            VALUES (" . implode(', ', $insertValues) . ")";
                    $stmt = $this->db->prepare($sql);
                    $stmt->execute($insertParams);
                }
            }
            
            $this->db->commit();
            return true;
            
        } catch (\Exception $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    /**
     * Obtener roles de un usuario
     */
    private function getUserRoles(string $userId): array {
        try {
            $sql = "SELECT r.id, r.name 
                    FROM user_roles ur
                    INNER JOIN roles r ON ur.role_id = r.id
                    WHERE ur.user_id = :user_id";
            
            $stmt = $this->db->prepare($sql);
            $stmt->execute([':user_id' => $userId]);
            
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (\Exception $e) {
            error_log('Error getting user roles for user ' . $userId . ': ' . $e->getMessage());
            // Retornar array vacío en caso de error para no romper el flujo
            return [];
        }
    }

    /**
     * Obtener clasificación de email de un usuario
     */
    private function getEmailClassificationData(string $userId): ?array {
        try {
            $sql = "SELECT account_type, is_university_verified 
                    FROM email_classifications 
                    WHERE user_id = :user_id 
                    ORDER BY created_at DESC 
                    LIMIT 1";
            
            $stmt = $this->db->prepare($sql);
            $stmt->execute([':user_id' => $userId]);
            
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            return $result ?: null;
        } catch (\Exception $e) {
            error_log('Error getting email classification for user ' . $userId . ': ' . $e->getMessage());
            return null;
        }
    }

    private function ensureEmailClassification(string $userId, ?string $email): ?array {
        $classification = $this->getEmailClassificationData($userId);
        if ($classification) {
            return $classification;
        }

        if (empty($email)) {
            return null;
        }

        // Crear o actualizar clasificación si no existe
        $this->emailDomainRuleService->saveEmailClassification($userId, $email);
        return $this->getEmailClassificationData($userId);
    }

    /**
     * Generar UUID v4
     */
    private function generateUUID(): string {
        $data = random_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}
