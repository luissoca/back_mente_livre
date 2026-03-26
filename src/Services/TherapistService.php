<?php

namespace App\Services;

use App\Core\Database;
use App\Core\ImageUrlHelper;

class TherapistService {
    private $db;
    private CacheService $cache;

    public function __construct() {
        $this->db = Database::getInstance();
        $this->cache = new CacheService();
    }

    /**
     * Obtener todos los terapeutas activos con su información completa
     * Usa caché en archivo para reducir consultas a MySQL
     */
    public function getAllActive(): array {
        // Intentar obtener del caché
        $cached = $this->cache->get('therapists_active');
        if ($cached !== null) {
            return $cached;
        }

        // No hay caché: consultar MySQL
        $sql = "
            SELECT 
                t.id, t.user_id, t.name, t.university,
                t.age, t.years_experience, t.role_title,
                t.specialty, t.therapeutic_approach, t.short_description,
                t.modality, t.is_active, t.hourly_rate,
                t.field_visibility, t.created_at, t.updated_at
            FROM therapists t
            WHERE t.is_active = TRUE
            ORDER BY t.name ASC
        ";
        
        $therapists = $this->db->fetchAll($sql);
        
        // Agregar información adicional a cada terapeuta
        foreach ($therapists as &$therapist) {
            $therapist['experience_topics'] = $this->getExperienceTopics($therapist['id']);
            $therapist['population_served'] = $this->getPopulationServed($therapist['id']);
            $therapist['photos'] = $this->getPhotos($therapist['id']);
            $therapist['pricing'] = $this->getPricing($therapist['id']);
        }
        
        // Guardar en caché
        $this->cache->set('therapists_active', $therapists);
        
        return $therapists;
    }

    /**
     * Obtener todos los terapeutas (activos e inactivos) con su información completa
     */
    public function getAll(): array {
        $sql = "
            SELECT 
                t.id, t.user_id, t.name, t.university,
                t.age, t.years_experience, t.role_title,
                t.specialty, t.therapeutic_approach, t.short_description,
                t.modality, t.is_active, t.is_visible_in_about, t.hourly_rate,
                t.field_visibility, t.created_at, t.updated_at
            FROM therapists t
            ORDER BY t.name ASC
        ";
        
        $therapists = $this->db->fetchAll($sql);
        
        // Agregar información adicional a cada terapeuta
        foreach ($therapists as &$therapist) {
            $therapist['experience_topics'] = $this->getExperienceTopics($therapist['id']);
            $therapist['population_served'] = $this->getPopulationServed($therapist['id']);
            $therapist['photos'] = $this->getPhotos($therapist['id']);
            $therapist['pricing'] = $this->getPricing($therapist['id']);
        }
        
        return $therapists;
    }

    /**
     * Obtener un terapeuta por ID
     */
    public function getById(string $id): ?array {
        $sql = "
            SELECT 
                t.*
            FROM therapists t
            WHERE t.id = ?
        ";
        
        $therapist = $this->db->fetchOne($sql, [$id]);
        
        if (!$therapist) {
            return null;
        }
        
        // Agregar información relacionada
        $therapist['experience_topics'] = $this->getExperienceTopics($id);
        $therapist['population_served'] = $this->getPopulationServed($id);
        $therapist['photos'] = $this->getPhotos($id);
        $therapist['pricing'] = $this->getPricing($id);
        
        return $therapist;
    }

    /**
     * Obtener temas de experiencia de un terapeuta
     */
    private function getExperienceTopics(string $therapistId): array {
        $sql = "
            SELECT DISTINCT topic
            FROM therapist_experience_topics
            WHERE therapist_id = ?
            ORDER BY topic ASC
        ";
        
        $results = $this->db->fetchAll($sql, [$therapistId]);
        return array_column($results, 'topic');
    }

    /**
     * Obtener población atendida por un terapeuta
     */
    private function getPopulationServed(string $therapistId): array {
        $sql = "
            SELECT population
            FROM therapist_population_served
            WHERE therapist_id = ?
        ";
        
        $results = $this->db->fetchAll($sql, [$therapistId]);
        return array_column($results, 'population');
    }

    /**
     * Obtener fotos de un terapeuta
     */
    private function getPhotos(string $therapistId): array {
        $sql = "
            SELECT photo_type, photo_url, photo_position, is_active, created_at
            FROM therapist_photos
            WHERE therapist_id = ? AND is_active = TRUE
            ORDER BY 
                CASE photo_type 
                    WHEN 'profile' THEN 1 
                    WHEN 'friendly' THEN 2 
                    ELSE 3 
                END,
                created_at DESC
        ";
        
        $photos = $this->db->fetchAll($sql, [$therapistId]);
        
        // Normalizar URLs a rutas completas
        return ImageUrlHelper::normalizePhotoUrls($photos);
    }

    /**
     * Obtener precios de un terapeuta
     */
    private function getPricing(string $therapistId): array {
        $sql = "
            SELECT pricing_tier, price, is_enabled
            FROM therapist_pricing
            WHERE therapist_id = ?
        ";
        
        $results = $this->db->fetchAll($sql, [$therapistId]);
        
        // Convertir a formato más amigable
        $pricing = [];
        foreach ($results as $row) {
            $pricing[$row['pricing_tier']] = [
                'price' => (float)$row['price'],
                'enabled' => (bool)$row['is_enabled']
            ];
        }
        
        return $pricing;
    }

    /**
     * Crear un nuevo terapeuta
     */
    public function create(array $data): string {
        try {
            $id = $this->generateUuid();
            
            $sql = "
                INSERT INTO therapists (
                    id, user_id, name, university,
                    age, years_experience, role_title,
                    specialty, therapeutic_approach, short_description,
                    modality, hourly_rate, is_active, is_visible_in_about, field_visibility
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ";
            
            // Procesar field_visibility: convertir a JSON si es array/objeto
            $fieldVisibility = null;
            if (isset($data['field_visibility'])) {
                if (is_array($data['field_visibility']) || is_object($data['field_visibility'])) {
                    $fieldVisibility = json_encode($data['field_visibility']);
                } else {
                    $fieldVisibility = $data['field_visibility'];
                }
            }
            
            // Convertir booleanos a enteros para MySQL
            $isActive = $data['is_active'] ?? true;
            if (is_bool($isActive)) {
                $isActive = $isActive ? 'true' : 'false';
            }
            
            $isVisibleInAbout = $data['is_visible_in_about'] ?? false;
            if (is_bool($isVisibleInAbout)) {
                $isVisibleInAbout = $isVisibleInAbout ? 'true' : 'false';
            }
            
            $this->db->executeQuery($sql, [
                $id,
                $data['user_id'] ?? null,
                $data['name'],
                $data['university'],
                $data['age'] ?? null,
                $data['years_experience'] ?? null,
                $data['role_title'] ?? 'Psicólogo/a',
                $data['specialty'] ?? null,
                $data['therapeutic_approach'] ?? null,
                $data['short_description'] ?? null,
                $data['modality'] ?? 'Online',
                $data['hourly_rate'],
                $isActive,
                $isVisibleInAbout,
                $fieldVisibility
            ]);
            
            // Agregar temas de experiencia si existen
            if (!empty($data['experience_topics'])) {
                $this->addExperienceTopics($id, $data['experience_topics']);
            }
            
            // Agregar precios si existen
            if (!empty($data['pricing'])) {
                $this->addPricing($id, $data['pricing']);
            }
            
            $this->invalidateCache();
            return $id;
        } catch (\Exception $e) {
            error_log('Error creando terapeuta: ' . $e->getMessage());
            error_log('Stack trace: ' . $e->getTraceAsString());
            if (method_exists($e, 'getPrevious') && $e->getPrevious()) {
                error_log('Previous exception: ' . $e->getPrevious()->getMessage());
            }
            throw $e;
        }
    }

    /**
     * Agregar temas de experiencia
     */
    private function addExperienceTopics(string $therapistId, array $topics): void {
        foreach ($topics as $topic) {
            $sql = "INSERT INTO therapist_experience_topics (id, therapist_id, topic) VALUES (?, ?, ?)";
            $this->db->executeQuery($sql, [$this->generateUuid(), $therapistId, $topic]);
        }
    }

    /**
     * Agregar precios
     */
    private function addPricing(string $therapistId, array $pricing): void {
        foreach ($pricing as $tier => $data) {
            $sql = "
                INSERT INTO therapist_pricing (id, therapist_id, pricing_tier, price, is_enabled)
                VALUES (?, ?, ?, ?, ?)
            ";
            $this->db->executeQuery($sql, [
                $this->generateUuid(),
                $therapistId,
                $tier,
                $data['price'],
                $data['enabled'] ?? true
            ]);
        }
    }

    /**
     * Actualizar terapeuta
     */
    public function update(string $id, array $data): bool {
        try {
            $fields = [];
            $params = [];
            
            $allowedFields = [
                'name', 'university', 'age', 'years_experience',
                'role_title', 'specialty', 'therapeutic_approach',
                'short_description', 'modality', 'hourly_rate', 'is_active', 'user_id',
                'is_visible_in_about', 'field_visibility'
            ];
            
            foreach ($allowedFields as $field) {
                if (array_key_exists($field, $data)) {
                    $fields[] = "$field = ?";
                    $value = $data[$field];
                    
                    if (($field === 'is_active' || $field === 'is_visible_in_about') && is_bool($value)) {
                        $value = $value ? 'true' : 'false';
                    }
                    
                    // Procesar field_visibility: convertir a JSON si es array/objeto
                    if ($field === 'field_visibility') {
                        if (is_array($value) || is_object($value)) {
                            $value = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                        } elseif ($value === null) {
                            $value = null;
                        }
                    }
                    
                    $params[] = $value;
                }
            }
            
            if (empty($fields)) {
                return false;
            }
            
            $params[] = $id;
            $sql = "UPDATE therapists SET " . implode(', ', $fields) . " WHERE id = ?";
            $this->db->executeQuery($sql, $params);
            
            $this->invalidateCache();
            return true;
        } catch (\Exception $e) {
            error_log('Error actualizando terapeuta: ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Eliminar terapeuta
     */
    public function delete(string $id): bool {
        try {
            $sql = "DELETE FROM therapists WHERE id = ?";
            $stmt = $this->db->executeQuery($sql, [$id]);

            if ($stmt->rowCount() > 0) {
                $this->invalidateCache();
                return true;
            }
            return false;
        } catch (\Exception $e) {
            error_log('Error eliminando terapeuta: ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Invalidar caché de terapeutas
     * Llamar después de crear, actualizar o eliminar un terapeuta
     */
    public function invalidateCache(): void {
        $this->cache->invalidate('therapists_active');
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
