<?php

namespace App\Services;

use App\Core\Database;
use PDO;

class EmailDomainRuleService {
    private PDO $db;

    public function __construct() {
        $this->db = Database::getInstance()->getConnection();
    }

    /**
     * Obtener todas las reglas
     */
    public function getAll(?bool $activeOnly = null): array {
        $sql = "SELECT * FROM email_domain_rules";
        
        $params = [];
        if ($activeOnly !== null) {
            $sql .= " WHERE is_active = :is_active";
            $params[':is_active'] = $activeOnly ? 'true' : 'false';
        }
        
        $sql .= " ORDER BY domain ASC";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Obtener regla por ID
     */
    public function getById(string $id): ?array {
        $sql = "SELECT * FROM email_domain_rules WHERE id = :id";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([':id' => $id]);
        
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result ?: null;
    }

    /**
     * Obtener regla por dominio
     */
    public function getByDomain(string $domain): ?array {
        $sql = "SELECT * FROM email_domain_rules WHERE domain = :domain LIMIT 1";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([':domain' => $domain]);
        
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result ?: null;
    }

    /**
     * Crear regla
     */
    public function create(array $data): string {
        $ruleId = $this->generateUUID();
        
        // Verificar si ya existe el dominio
        $existing = $this->getByDomain($data['domain']);
        if ($existing) {
            throw new \Exception('Ya existe una regla para este dominio');
        }
        
        $sql = "INSERT INTO email_domain_rules (
            id, domain, rule_type, note, is_active
        ) VALUES (
            :id, :domain, :rule_type, :note, :is_active
        )";
        
        $stmt = $this->db->prepare($sql);
        
        // Convertir is_active a entero si es booleano
        $isActive = $data['is_active'] ?? true;
        if (is_bool($isActive)) {
            $isActive = $isActive ? 'true' : 'false';
        }
        
        $stmt->execute([
            ':id' => $ruleId,
            ':domain' => $data['domain'],
            ':rule_type' => $data['rule_type'],
            ':note' => $data['note'] ?? null,
            ':is_active' => $isActive
        ]);
        
        return $ruleId;
    }

    /**
     * Actualizar regla
     */
    public function update(string $id, array $data): bool {
        try {
            $fields = [];
            $params = [':id' => $id];
            
            $allowedFields = ['domain', 'rule_type', 'note', 'is_active'];
            
            foreach ($allowedFields as $field) {
                if (array_key_exists($field, $data)) {
                    $fields[] = "$field = :$field";
                    $value = $data[$field];
                    
                    if ($field === 'is_active' && is_bool($value)) {
                        $value = $value ? 'true' : 'false';
                    }
                    
                    $params[":$field"] = $value;
                }
            }
            
            if (empty($fields)) {
                return false;
            }
            
            // Si se actualiza el dominio, verificar que no exista otro
            if (isset($data['domain'])) {
                $existing = $this->getByDomain($data['domain']);
                if ($existing && $existing['id'] !== $id) {
                    throw new \Exception('Ya existe una regla para este dominio');
                }
            }
            
            $sql = "UPDATE email_domain_rules SET " . implode(', ', $fields) . " WHERE id = :id";
            $stmt = $this->db->prepare($sql);
            
            $success = $stmt->execute($params);
            
            if (!$success) {
                $errorInfo = $stmt->errorInfo();
                throw new \Exception('Error ejecutando consulta SQL: ' . ($errorInfo[2] ?? 'Error desconocido'));
            }
            
            return true;
        } catch (\PDOException $e) {
            error_log('Error PDO actualizando regla de dominio: ' . $e->getMessage());
            throw new \Exception('Error actualizando regla: ' . $e->getMessage());
        } catch (\Exception $e) {
            error_log('Error actualizando regla de dominio: ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Eliminar regla
     */
    public function delete(string $id): bool {
        $sql = "DELETE FROM email_domain_rules WHERE id = :id";
        $stmt = $this->db->prepare($sql);
        return $stmt->execute([':id' => $id]);
    }

    /**
     * Verificar si un email cumple con las reglas
     */
    public function checkEmailDomain(string $email): ?array {
        $domain = substr(strrchr($email, "@"), 1);
        if (!$domain) {
            return null;
        }
        
        $rule = $this->getByDomain($domain);
        if ($rule && $rule['is_active']) {
            return $rule;
        }
        
        return null;
    }

    /**
     * Clasificar un email basándose en las reglas de dominio
     * Retorna el account_type correspondiente o 'public' si no hay coincidencia
     */
    public function classifyEmail(string $email): string {
        $emailParts = explode('@', $email);
        $domain = strtolower($emailParts[1] ?? '');
        
        if (empty($domain)) {
            return 'public';
        }

        // Buscar si el dominio está en la whitelist (dominios universitarios verificados)
        $isInWhitelist = $this->isDomainInWhitelist($domain);
        
        // Si está en la whitelist, determinar si es universidad peruana o internacional
        if ($isInWhitelist) {
            // Universidades peruanas (dominios .edu.pe, .ac.pe)
            if (str_ends_with($domain, '.edu.pe') || str_ends_with($domain, '.ac.pe')) {
                return 'university_pe';
            }
            
            // Universidades internacionales
            if (str_ends_with($domain, '.edu') || str_ends_with($domain, '.ac.uk') || 
                str_ends_with($domain, '.edu.ar') || str_ends_with($domain, '.edu.co')) {
                return 'university_international';
            }
            
            // Si está en whitelist pero no coincide con patrones conocidos, asumir university_pe
            return 'university_pe';
        }

        // Fallback: clasificación por patrones comunes
        if (str_ends_with($domain, '.edu.pe') || str_ends_with($domain, '.ac.pe')) {
            return 'university_pe';
        }
        
        if (str_ends_with($domain, '.edu') || str_ends_with($domain, '.ac.uk') || str_ends_with($domain, '.edu.ar')) {
            return 'university_international';
        }

        // Dominios públicos comunes
        $publicDomains = ['gmail.com', 'hotmail.com', 'outlook.com', 'yahoo.com', 'icloud.com', 'live.com', 'msn.com'];
        if (in_array($domain, $publicDomains)) {
            return 'public';
        }

        // Si no es público, asumir corporativo
        return 'corporate';
    }

    /**
     * Verificar si un dominio está en la whitelist
     * También verifica subdominios (ej: cs.ulima.edu.pe busca ulima.edu.pe)
     */
    private function isDomainInWhitelist(string $domain): bool {
        // Buscar coincidencia exacta
        $sql = "SELECT id FROM email_domain_rules 
                WHERE LOWER(domain) = :domain AND is_active = TRUE AND rule_type = 'whitelist' 
                LIMIT 1";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([':domain' => $domain]);
        
        if ($stmt->fetch(PDO::FETCH_ASSOC)) {
            return true;
        }

        // Buscar por dominios padres (para subdominios)
        // Por ejemplo: si el dominio es "cs.ulima.edu.pe", buscar "ulima.edu.pe", "edu.pe"
        $domainParts = explode('.', $domain);
        for ($i = 1; $i < count($domainParts) - 1; $i++) {
            $parentDomain = implode('.', array_slice($domainParts, $i));
            $stmt = $this->db->prepare($sql);
            $stmt->execute([':domain' => $parentDomain]);
            
            if ($stmt->fetch(PDO::FETCH_ASSOC)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Guardar o actualizar la clasificación de email de un usuario
     */
    public function saveEmailClassification(string $userId, string $email): void {
        $classification = $this->classifyEmail($email);
        $emailParts = explode('@', $email);
        $domain = strtolower($emailParts[1] ?? '');
        
        if (empty($domain)) {
            return; // No guardar si no hay dominio válido
        }
        
        // Verificar si ya existe una clasificación para este usuario
        $sql = "SELECT id FROM email_classifications WHERE user_id = :user_id LIMIT 1";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([':user_id' => $userId]);
        $existing = $stmt->fetch(PDO::FETCH_ASSOC);

        $isUniversityVerified = $this->isDomainInWhitelist($domain) && 
                                ($classification === 'university_pe' || $classification === 'university_international');

        if ($existing) {
            // Actualizar clasificación existente
            $sql = "UPDATE email_classifications 
                    SET email_domain = :email_domain, 
                        account_type = :account_type, 
                        is_university_verified = :is_university_verified,
                        updated_at = NOW() 
                    WHERE user_id = :user_id";
            $stmt = $this->db->prepare($sql);
            $stmt->execute([
                ':email_domain' => $domain,
                ':account_type' => $classification,
                ':is_university_verified' => $isUniversityVerified ? 'true' : 'false',
                ':user_id' => $userId
            ]);
        } else {
            // Crear nueva clasificación
            $id = $this->generateUUID();
            $sql = "INSERT INTO email_classifications 
                    (id, user_id, email_domain, account_type, is_university_verified, created_at, updated_at) 
                    VALUES (:id, :user_id, :email_domain, :account_type, :is_university_verified, NOW(), NOW())";
            $stmt = $this->db->prepare($sql);
            $stmt->execute([
                ':id' => $id,
                ':user_id' => $userId,
                ':email_domain' => $domain,
                ':account_type' => $classification,
                ':is_university_verified' => $isUniversityVerified ? 'true' : 'false'
            ]);
        }
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
