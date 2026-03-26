<?php

namespace App\Services;

use App\Core\Database;
use App\Core\ImageUrlHelper;
use PDO;

class TeamProfileService {
    private PDO $db;

    public function __construct() {
        $this->db = Database::getInstance()->getConnection();
    }

    /**
     * Obtener todos los perfiles con filtros
     */
    public function getAll(array $filters = []): array {
        $sql = "SELECT 
            tp.*,
            t.name as linked_therapist_name
            FROM team_profiles tp
            LEFT JOIN therapists t ON tp.linked_therapist_id = t.id
            WHERE 1=1";
        
        $params = [];
        
        if (isset($filters['member_type'])) {
            $sql .= " AND tp.member_type = :member_type";
            $params[':member_type'] = $filters['member_type'];
        }
        
        if (isset($filters['is_visible_public'])) {
            $sql .= " AND tp.is_visible_public = :is_visible_public";
            $params[':is_visible_public'] = $filters['is_visible_public'];
        }
        
        $sql .= " ORDER BY tp.order_index ASC, tp.created_at DESC";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        
        $profiles = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Normalizar URLs de fotos
        foreach ($profiles as &$profile) {
            if (!empty($profile['friendly_photo_url'])) {
                $profile['friendly_photo_url'] = ImageUrlHelper::buildUrl($profile['friendly_photo_url']);
            }
        }
        
        return $profiles;
    }

    /**
     * Obtener perfil por ID
     */
    public function getById(string $id): ?array {
        $sql = "SELECT 
            tp.*,
            t.name as linked_therapist_name
            FROM team_profiles tp
            LEFT JOIN therapists t ON tp.linked_therapist_id = t.id
            WHERE tp.id = :id";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute([':id' => $id]);
        
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // Normalizar URL de foto si existe
        if ($result && !empty($result['friendly_photo_url'])) {
            $result['friendly_photo_url'] = ImageUrlHelper::buildUrl($result['friendly_photo_url']);
        }
        
        return $result ?: null;
    }

    /**
     * Crear nuevo perfil
     */
    public function create(array $data): string {
        $profileId = $this->generateUUID();
        
        $sql = "INSERT INTO team_profiles (
            id, member_type, linked_therapist_id, full_name, 
            public_role_title, professional_level, public_bio,
            friendly_photo_url, is_visible_public, order_index
        ) VALUES (
            :id, :member_type, :linked_therapist_id, :full_name,
            :public_role_title, :professional_level, :public_bio,
            :friendly_photo_url, :is_visible_public, :order_index
        )";
        
        $stmt = $this->db->prepare($sql);
        
        // Convertir is_visible_public a entero si es booleano
        $isVisiblePublic = $data['is_visible_public'] ?? true;
        if (is_bool($isVisiblePublic)) {
            $isVisiblePublic = $isVisiblePublic ? 'true' : 'false';
        }
        
        $stmt->execute([
            ':id' => $profileId,
            ':member_type' => $data['member_type'],
            ':linked_therapist_id' => $data['linked_therapist_id'] ?? null,
            ':full_name' => $data['full_name'],
            ':public_role_title' => $data['public_role_title'],
            ':professional_level' => $data['professional_level'] ?? null,
            ':public_bio' => $data['public_bio'] ?? null,
            ':friendly_photo_url' => $data['friendly_photo_url'] ?? null,
            ':is_visible_public' => $isVisiblePublic,
            ':order_index' => $data['order_index'] ?? 0
        ]);
        
        return $profileId;
    }

    /**
     * Actualizar perfil
     */
    public function update(string $id, array $data): bool {
        try {
            $fields = [];
            $params = [':id' => $id];
            
            $allowedFields = [
                'member_type', 'linked_therapist_id', 'full_name', 'public_role_title',
                'professional_level', 'public_bio', 'friendly_photo_url', 
                'is_visible_public', 'order_index'
            ];
            
            foreach ($allowedFields as $field) {
                if (array_key_exists($field, $data)) {
                    $fields[] = "$field = :$field";
                    $value = $data[$field];
                    
                    // Convertir booleanos a enteros para MySQL
                    if ($field === 'is_visible_public' && is_bool($value)) {
                        $value = $value ? 'true' : 'false';
                    }
                    
                    $params[":$field"] = $value;
                }
            }
            
            if (empty($fields)) {
                return false;
            }
            
            $sql = "UPDATE team_profiles SET " . implode(', ', $fields) . " WHERE id = :id";
            $stmt = $this->db->prepare($sql);
            
            $success = $stmt->execute($params);
            
            if (!$success) {
                $errorInfo = $stmt->errorInfo();
                throw new \Exception('Error ejecutando consulta SQL: ' . ($errorInfo[2] ?? 'Error desconocido'));
            }
            
            return true;
        } catch (\PDOException $e) {
            error_log('Error PDO actualizando perfil de equipo: ' . $e->getMessage());
            throw new \Exception('Error actualizando perfil: ' . $e->getMessage());
        } catch (\Exception $e) {
            error_log('Error actualizando perfil de equipo: ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Eliminar perfil
     */
    public function delete(string $id): bool {
        $sql = "DELETE FROM team_profiles WHERE id = :id";
        $stmt = $this->db->prepare($sql);
        return $stmt->execute([':id' => $id]);
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
