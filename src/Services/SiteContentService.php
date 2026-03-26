<?php

namespace App\Services;

use App\Core\Database;
use PDO;

class SiteContentService {
    private PDO $db;
    private const CONTENT_ID = '00000000-0000-0000-0000-000000000001';

    public function __construct() {
        $this->db = Database::getInstance()->getConnection();
    }

    /**
     * Obtener contenido del sitio
     */
    public function get(): ?array {
        $sql = "SELECT * FROM site_content WHERE id = :id";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([':id' => self::CONTENT_ID]);
        
        $content = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($content && !empty($content['values'])) {
            // Decodificar JSON si es un string
            if (is_string($content['values'])) {
                $content['values'] = json_decode($content['values'], true);
            }
        }
        
        return $content ?: null;
    }

    /**
     * Actualizar contenido del sitio
     */
    public function update(array $data): bool {
        $fields = [];
        $params = [':id' => self::CONTENT_ID];
        
        $allowedFields = [
            'about_title', 'about_intro', 'mission', 'vision', 
            'approach', 'purpose', 'values'
        ];
        
        foreach ($allowedFields as $field) {
            if (isset($data[$field])) {
                // Escapar nombres de columnas con backticks para evitar conflictos con palabras reservadas
                $escapedField = "`$field`";
                $fields[] = "$escapedField = :$field";
                
                // Convertir array a JSON para el campo values
                if ($field === 'values' && is_array($data[$field])) {
                    $params[":$field"] = json_encode($data[$field]);
                } else {
                    $params[":$field"] = $data[$field];
                }
            }
        }
        
        if (empty($fields)) {
            return false;
        }
        
        // Verificar si existe el registro
        $existingContent = $this->get();
        
        if ($existingContent) {
            // Actualizar
            $sql = "UPDATE site_content SET " . implode(', ', $fields) . " WHERE id = :id";
        } else {
            // Crear (por si no existe)
            $insertFields = ['`id`'];
            $insertValues = [':id'];
            
            foreach ($allowedFields as $field) {
                if (isset($data[$field])) {
                    $insertFields[] = "`$field`";
                    $insertValues[] = ":$field";
                }
            }
            
            $sql = "INSERT INTO site_content (" . implode(', ', $insertFields) . ") 
                    VALUES (" . implode(', ', $insertValues) . ")";
        }
        
        $stmt = $this->db->prepare($sql);
        return $stmt->execute($params);
    }
}
