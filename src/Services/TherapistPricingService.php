<?php

namespace App\Services;

use App\Core\Database;
use PDO;

class TherapistPricingService {
    private PDO $db;

    public function __construct() {
        $this->db = Database::getInstance()->getConnection();
    }

    /**
     * Obtener precios de un terapeuta
     */
    public function getByTherapist(string $therapistId): array {
        $sql = "SELECT * FROM therapist_pricing 
                WHERE therapist_id = :therapist_id 
                ORDER BY pricing_tier ASC";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute([':therapist_id' => $therapistId]);
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Obtener precio por ID
     */
    public function getById(string $id): ?array {
        $sql = "SELECT * FROM therapist_pricing WHERE id = :id";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([':id' => $id]);
        
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result ?: null;
    }

    /**
     * Obtener precio por terapeuta y tier
     */
    public function getByTherapistAndTier(string $therapistId, string $pricingTier): ?array {
        $sql = "SELECT * FROM therapist_pricing 
                WHERE therapist_id = :therapist_id 
                AND pricing_tier = :pricing_tier 
                LIMIT 1";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            ':therapist_id' => $therapistId,
            ':pricing_tier' => $pricingTier
        ]);
        
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result ?: null;
    }

    /**
     * Crear o actualizar precio
     */
    public function upsert(array $data): string {
        $existing = $this->getByTherapistAndTier($data['therapist_id'], $data['pricing_tier']);
        
        if ($existing) {
            // Actualizar
            $this->update($existing['id'], $data);
            return $existing['id'];
        } else {
            // Crear
            return $this->create($data);
        }
    }

    /**
     * Crear precio
     */
    public function create(array $data): string {
        $pricingId = $this->generateUUID();
        
        $isEnabled = $data['is_enabled'] ?? true;
        
        $sql = "INSERT INTO therapist_pricing (
            id, therapist_id, pricing_tier, price, is_enabled
        ) VALUES (
            :id, :therapist_id, :pricing_tier, :price, :is_enabled
        )";
        
        $stmt = $this->db->prepare($sql);
        
        // Convertir is_enabled a entero si es booleano
        if (is_bool($isEnabled)) {
            $isEnabled = $isEnabled ? 'true' : 'false';
        }
        
        $stmt->execute([
            ':id' => $pricingId,
            ':therapist_id' => $data['therapist_id'],
            ':pricing_tier' => $data['pricing_tier'],
            ':price' => $data['price'],
            ':is_enabled' => $isEnabled
        ]);
        
        return $pricingId;
    }

    /**
     * Actualizar precio
     */
    public function update(string $id, array $data): bool {
        try {
            $fields = [];
            $params = [':id' => $id];
            
            $allowedFields = ['price', 'is_enabled'];
            
            foreach ($allowedFields as $field) {
                if (array_key_exists($field, $data)) {
                    $fields[] = "$field = :$field";
                    $value = $data[$field];
                    
                    // Convertir booleanos a enteros para MySQL
                    if ($field === 'is_enabled' && is_bool($value)) {
                        $value = $value ? 'true' : 'false';
                    }
                    
                    $params[":$field"] = $value;
                }
            }
            
            if (empty($fields)) {
                return false;
            }
            
            $sql = "UPDATE therapist_pricing SET " . implode(', ', $fields) . " WHERE id = :id";
            $stmt = $this->db->prepare($sql);
            
            $success = $stmt->execute($params);
            
            if (!$success) {
                $errorInfo = $stmt->errorInfo();
                throw new \Exception('Error ejecutando consulta SQL: ' . ($errorInfo[2] ?? 'Error desconocido'));
            }
            
            return true;
        } catch (\PDOException $e) {
            error_log('Error PDO actualizando precio: ' . $e->getMessage());
            throw new \Exception('Error actualizando precio: ' . $e->getMessage());
        } catch (\Exception $e) {
            error_log('Error actualizando precio: ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Eliminar precio
     */
    public function delete(string $id): bool {
        $sql = "DELETE FROM therapist_pricing WHERE id = :id";
        $stmt = $this->db->prepare($sql);
        return $stmt->execute([':id' => $id]);
    }

    /**
     * Actualizar múltiples precios a la vez
     */
    public function updateBatch(string $therapistId, array $pricingData): array {
        $results = [];
        
        foreach ($pricingData as $tier => $data) {
            $data['therapist_id'] = $therapistId;
            $data['pricing_tier'] = $tier;
            $id = $this->upsert($data);
            $results[$tier] = $id;
        }
        
        return $results;
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
