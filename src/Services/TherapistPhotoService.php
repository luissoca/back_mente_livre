<?php

namespace App\Services;

use App\Core\Database;
use App\Core\ImageUrlHelper;
use PDO;

class TherapistPhotoService {
    private PDO $db;

    public function __construct() {
        $this->db = Database::getInstance()->getConnection();
    }

    /**
     * Obtener todas las fotos de un terapeuta
     */
    public function getByTherapist(string $therapistId, ?string $photoType = null): array {
        $sql = "SELECT * FROM therapist_photos WHERE therapist_id = :therapist_id";
        $params = [':therapist_id' => $therapistId];
        
        if ($photoType) {
            $sql .= " AND photo_type = :photo_type";
            $params[':photo_type'] = $photoType;
        }
        
        $sql .= " ORDER BY photo_type ASC, created_at DESC";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        
        $photos = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Normalizar URLs
        foreach ($photos as &$photo) {
            if (!empty($photo['photo_url'])) {
                $photo['photo_url'] = ImageUrlHelper::buildUrl($photo['photo_url']);
            }
        }
        
        return $photos;
    }

    /**
     * Obtener foto por ID
     */
    public function getById(string $id): ?array {
        $sql = "SELECT * FROM therapist_photos WHERE id = :id";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([':id' => $id]);
        
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($result && !empty($result['photo_url'])) {
            $result['photo_url'] = ImageUrlHelper::buildUrl($result['photo_url']);
        }
        
        return $result ?: null;
    }

    /**
     * Crear foto
     */
    public function create(array $data): string {
        $photoId = $this->generateUUID();
        
        $sql = "INSERT INTO therapist_photos (
            id, therapist_id, photo_type, photo_url, photo_position, is_active
        ) VALUES (
            :id, :therapist_id, :photo_type, :photo_url, :photo_position, :is_active
        )";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            ':id' => $photoId,
            ':therapist_id' => $data['therapist_id'],
            ':photo_type' => $data['photo_type'] ?? 'profile',
            ':photo_url' => $data['photo_url'],
            ':photo_position' => $data['photo_position'] ?? '50% 20%',
            ':is_active' => $data['is_active'] ?? true
        ]);
        
        return $photoId;
    }

    /**
     * Actualizar foto
     */
    public function update(string $id, array $data): bool {
        $fields = [];
        $params = [':id' => $id];
        
        $allowedFields = ['photo_url', 'photo_position', 'is_active', 'photo_type'];
        
        foreach ($allowedFields as $field) {
            if (array_key_exists($field, $data)) {
                $fields[] = "$field = :$field";
                $params[":$field"] = $data[$field];
            }
        }
        
        if (empty($fields)) {
            return false;
        }
        
        $sql = "UPDATE therapist_photos SET " . implode(', ', $fields) . " WHERE id = :id";
        $stmt = $this->db->prepare($sql);
        
        return $stmt->execute($params);
    }

    /**
     * Eliminar foto
     */
    public function delete(string $id): bool {
        $sql = "DELETE FROM therapist_photos WHERE id = :id";
        $stmt = $this->db->prepare($sql);
        return $stmt->execute([':id' => $id]);
    }

    /**
     * Desactivar otras fotos del mismo tipo para un terapeuta
     */
    public function deactivateOthers(string $therapistId, string $photoType, string $excludeId): bool {
        $sql = "UPDATE therapist_photos 
                SET is_active = FALSE 
                WHERE therapist_id = :therapist_id 
                AND photo_type = :photo_type 
                AND id != :exclude_id";
        $stmt = $this->db->prepare($sql);
        return $stmt->execute([
            ':therapist_id' => $therapistId,
            ':photo_type' => $photoType,
            ':exclude_id' => $excludeId
        ]);
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
