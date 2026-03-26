<?php

namespace App\Services;

use App\Core\Database;
use Exception;
use PDO;

class SessionPackageService {
    private $db;

    public function __construct() {
        $this->db = Database::getInstance();
    }

    public function getAllPackages($includeInactive = false) {
        $sql = "SELECT * FROM session_packages";
        if (!$includeInactive) {
            $sql .= " WHERE is_active = TRUE";
        }
        $sql .= " ORDER BY session_count ASC, created_at DESC";
        
        return $this->db->fetchAll($sql);
    }

    public function getPackageById($id) {
        $sql = "SELECT * FROM session_packages WHERE id = ?";
        return $this->db->fetchOne($sql, [$id]);
    }

    public function createPackage($data) {
        $sql = "INSERT INTO session_packages (name, session_count, discount_percent, is_active) 
                VALUES (?, ?, ?, ?) RETURNING id";
        
        $isActive = isset($data['is_active']) ? ($data['is_active'] ? 't' : 'f') : 't';
        
        $stmt = $this->db->executeQuery($sql, [
            $data['name'],
            $data['session_count'],
            $data['discount_percent'],
            $isActive
        ]);
        
        $result = $stmt->fetch();
        return $result ? $result['id'] : null;
    }

    public function updatePackage($id, $data) {
        $fields = [];
        $params = [];
        
        if (isset($data['name'])) {
            $fields[] = "name = ?";
            $params[] = $data['name'];
        }
        
        if (isset($data['session_count'])) {
            $fields[] = "session_count = ?";
            $params[] = $data['session_count'];
        }
        
        if (isset($data['discount_percent'])) {
            $fields[] = "discount_percent = ?";
            $params[] = $data['discount_percent'];
        }
        
        if (isset($data['is_active'])) {
            $fields[] = "is_active = ?";
            $params[] = $data['is_active'] ? 't' : 'f';
        }
        
        if (empty($fields)) {
            return false;
        }
        
        $params[] = $id;
        $sql = "UPDATE session_packages SET " . implode(", ", $fields) . " WHERE id = ?";
        
        $stmt = $this->db->executeQuery($sql, $params);
        return $stmt->rowCount() > 0;
    }

    public function deletePackage($id) {
        // En PostgreSQL necesitamos verificar si hay patient_packages referenciando esto
        $checkSql = "SELECT COUNT(*) as count FROM patient_packages WHERE package_id = ?";
        $check = $this->db->fetchOne($checkSql, [$id]);
        
        if ($check && $check['count'] > 0) {
            throw new Exception("No se puede eliminar este paquete porque ya ha sido comprado por pacientes. Sugerencia: desactivarlo en su lugar.");
        }
        
        $sql = "DELETE FROM session_packages WHERE id = ?";
        $stmt = $this->db->executeQuery($sql, [$id]);
        return $stmt->rowCount() > 0;
    }
}
