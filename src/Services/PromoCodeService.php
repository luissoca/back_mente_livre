<?php

namespace App\Services;

use App\Core\Database;
use PDO;

class PromoCodeService {
    private PDO $db;

    public function __construct() {
        $this->db = Database::getInstance()->getConnection();
    }

    /**
     * Obtener todos los códigos promocionales
     */
    public function getAll(array $filters = []): array {
        $sql = "SELECT * FROM promo_codes WHERE 1=1";
        $params = [];
        
        if (isset($filters['is_active'])) {
            $sql .= " AND is_active = :is_active";
            $params[':is_active'] = $filters['is_active'] ? 'true' : 'false';
        }
        
        if (isset($filters['valid_now'])) {
            $sql .= " AND (valid_from IS NULL OR valid_from <= NOW())";
            $sql .= " AND (valid_until IS NULL OR valid_until >= NOW())";
        }
        
        $sql .= " ORDER BY created_at DESC";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Obtener código por ID
     */
    public function getById(string $id): ?array {
        $sql = "SELECT * FROM promo_codes WHERE id = :id";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([':id' => $id]);
        
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result ?: null;
    }

    /**
     * Obtener código por código string
     */
    public function getByCode(string $code): ?array {
        $sql = "SELECT * FROM promo_codes WHERE code = :code";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([':code' => strtoupper($code)]);
        
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result ?: null;
    }

    /**
     * Crear código promocional
     */
    public function create(array $data): string {
        $promoId = $this->generateUUID();
        
        $sql = "INSERT INTO promo_codes (
            id, code, is_active, discount_percent, base_price,
            max_uses_total, max_uses_per_user, max_sessions,
            valid_from, valid_until
        ) VALUES (
            :id, :code, :is_active, :discount_percent, :base_price,
            :max_uses_total, :max_uses_per_user, :max_sessions,
            :valid_from, :valid_until
        )";
        
        $stmt = $this->db->prepare($sql);
        
        // Convertir is_active a entero si es booleano
        $isActive = $data['is_active'] ?? true;
        if (is_bool($isActive)) {
            $isActive = $isActive ? 'true' : 'false';
        }
        
        $stmt->execute([
            ':id' => $promoId,
            ':code' => strtoupper($data['code']),
            ':is_active' => $isActive,
            ':discount_percent' => $data['discount_percent'],
            ':base_price' => $data['base_price'] ?? 25.00,
            ':max_uses_total' => $data['max_uses_total'] ?? null,
            ':max_uses_per_user' => $data['max_uses_per_user'] ?? 1,
            ':max_sessions' => $data['max_sessions'] ?? 1,
            ':valid_from' => $data['valid_from'] ?? null,
            ':valid_until' => $data['valid_until'] ?? null
        ]);
        
        return $promoId;
    }

    /**
     * Actualizar código promocional
     */
    public function update(string $id, array $data): bool {
        try {
            $fields = [];
            $params = [':id' => $id];
            
            $allowedFields = [
                'code', 'is_active', 'discount_percent', 'base_price',
                'max_uses_total', 'max_uses_per_user', 'max_sessions',
                'valid_from', 'valid_until'
            ];
            
            foreach ($allowedFields as $field) {
                if (array_key_exists($field, $data)) {
                    $fields[] = "$field = :$field";
                    $value = $data[$field];
                    
                    // Convertir código a mayúsculas
                    if ($field === 'code') {
                        $value = strtoupper($value);
                    }
                    
                    // Convertir booleanos a enteros para MySQL
                    if ($field === 'is_active' && is_bool($value)) {
                        $value = $value ? 'true' : 'false';
                    }
                    
                    $params[":$field"] = $value;
                }
            }
            
            if (empty($fields)) {
                return false;
            }
            
            $sql = "UPDATE promo_codes SET " . implode(', ', $fields) . " WHERE id = :id";
            $stmt = $this->db->prepare($sql);
            
            $success = $stmt->execute($params);
            
            if (!$success) {
                $errorInfo = $stmt->errorInfo();
                throw new \Exception('Error ejecutando consulta SQL: ' . ($errorInfo[2] ?? 'Error desconocido'));
            }
            
            return true;
        } catch (\PDOException $e) {
            error_log('Error PDO actualizando código promocional: ' . $e->getMessage());
            throw new \Exception('Error actualizando código: ' . $e->getMessage());
        } catch (\Exception $e) {
            error_log('Error actualizando código promocional: ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Eliminar código promocional
     */
    public function delete(string $id): bool {
        $this->db->beginTransaction();
        
        try {
            // Primero eliminar los usos asociados
            $sql = "DELETE FROM promo_code_uses WHERE promo_code_id = :id";
            $stmt = $this->db->prepare($sql);
            $stmt->execute([':id' => $id]);
            
            // Luego eliminar el código
            $sql = "DELETE FROM promo_codes WHERE id = :id";
            $stmt = $this->db->prepare($sql);
            $stmt->execute([':id' => $id]);
            
            $this->db->commit();
            return true;
        } catch (\Exception $e) {
            $this->db->rollBack();
            error_log('Error eliminando código promocional: ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Validar código promocional
     * @param string $code Código promocional
     * @param string $userEmail Email del usuario
     * @param float|null $basePrice Precio base del terapeuta sobre el cual aplicar el descuento (opcional)
     */
    public function validate(string $code, string $userEmail, ?float $basePrice = null): array {
        $promo = $this->getByCode($code);
        
        if (!$promo) {
            return [
                'valid' => false,
                'message' => 'Código promocional no encontrado'
            ];
        }
        
        // Verificar si está activo
        if (!$promo['is_active']) {
            return [
                'valid' => false,
                'message' => 'Código promocional inactivo'
            ];
        }
        
        // Verificar fechas de validez
        $now = new \DateTime();
        
        if ($promo['valid_from']) {
            $validFrom = new \DateTime($promo['valid_from']);
            if ($now < $validFrom) {
                return [
                    'valid' => false,
                    'message' => 'Código promocional aún no válido'
                ];
            }
        }
        
        if ($promo['valid_until']) {
            $validUntil = new \DateTime($promo['valid_until']);
            if ($now > $validUntil) {
                return [
                    'valid' => false,
                    'message' => 'Código promocional expirado'
                ];
            }
        }
        
        // Verificar límite de usos totales
        if ($promo['max_uses_total'] !== null && $promo['uses_count'] >= $promo['max_uses_total']) {
            return [
                'valid' => false,
                'message' => 'Código promocional agotado'
            ];
        }
        
        // Verificar límite de usos por usuario
        $userUses = $this->getUserUsesCount($promo['id'], $userEmail);
        if ($userUses >= $promo['max_uses_per_user']) {
            return [
                'valid' => false,
                'message' => 'Ya has utilizado este código el máximo de veces permitidas'
            ];
        }
        
        // Calcular precio con descuento
        // Si se proporciona basePrice (precio del terapeuta), aplicar descuento sobre ese precio
        // Si no, usar el base_price del código promocional (comportamiento legacy)
        $priceToUse = $basePrice !== null && $basePrice > 0 ? $basePrice : $promo['base_price'];
        $discountAmount = ($priceToUse * $promo['discount_percent']) / 100;
        $finalPrice = $priceToUse - $discountAmount;
        
        return [
            'valid' => true,
            'promo_code_id' => $promo['id'],
            'code' => $promo['code'],
            'discount_percent' => $promo['discount_percent'],
            'base_price' => $priceToUse, // Precio sobre el cual se aplicó el descuento
            'original_base_price' => $promo['base_price'], // Precio base original del código
            'discount_amount' => round($discountAmount, 2),
            'final_price' => round($finalPrice, 2),
            'max_sessions' => $promo['max_sessions'],
            'message' => 'Código promocional válido'
        ];
    }

    /**
     * Registrar uso de código promocional
     */
    public function registerUse(string $promoCodeId, string $userEmail, ?string $appointmentId = null): bool {
        $this->db->beginTransaction();
        
        try {
            // Registrar uso
            $sql = "INSERT INTO promo_code_uses (id, promo_code_id, user_email, appointment_id)
                    VALUES (:id, :promo_code_id, :user_email, :appointment_id)";
            
            $stmt = $this->db->prepare($sql);
            $stmt->execute([
                ':id' => $this->generateUUID(),
                ':promo_code_id' => $promoCodeId,
                ':user_email' => $userEmail,
                ':appointment_id' => $appointmentId
            ]);
            
            // Incrementar contador de usos
            $sql = "UPDATE promo_codes SET uses_count = uses_count + 1 WHERE id = :id";
            $stmt = $this->db->prepare($sql);
            $stmt->execute([':id' => $promoCodeId]);
            
            $this->db->commit();
            return true;
            
        } catch (\Exception $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    /**
     * Obtener contador de usos por usuario
     */
    private function getUserUsesCount(string $promoCodeId, string $userEmail): int {
        $sql = "SELECT COUNT(*) as count FROM promo_code_uses 
                WHERE promo_code_id = :promo_code_id AND user_email = :user_email";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            ':promo_code_id' => $promoCodeId,
            ':user_email' => $userEmail
        ]);
        
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result['count'] ?? 0;
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
