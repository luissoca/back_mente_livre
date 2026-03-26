<?php

namespace App\Services;

use App\Core\Database;
use Exception;
use PDO;

class PatientPackageService {
    private $db;

    public function __construct() {
        $this->db = Database::getInstance();
    }

    public function getUserPackages($email) {
        $sql = "
            SELECT pp.*, sp.name as package_name, t.name as therapist_name, t.friendly_photo_url as therapist_photo 
            FROM patient_packages pp
            JOIN session_packages sp ON pp.package_id = sp.id
            JOIN therapists t ON pp.therapist_id = t.id
            LEFT JOIN team_profiles tp ON t.id = tp.linked_therapist_id
            WHERE pp.patient_email = ? 
            ORDER BY pp.created_at DESC
        ";
        // To get photo we try team_profiles first, if not we could use therapist_photos.
        // As a fallback, we fetch directly from therapist tables:
        $sql = "
            SELECT 
                pp.*, 
                sp.name as package_name, 
                t.name as therapist_name,
                (SELECT photo_url FROM therapist_photos tp WHERE tp.therapist_id = t.id AND tp.photo_type = 'profile' AND tp.is_active = TRUE LIMIT 1) as therapist_photo
            FROM patient_packages pp
            JOIN session_packages sp ON pp.package_id = sp.id
            JOIN therapists t ON pp.therapist_id = t.id
            WHERE pp.patient_email = ?
            ORDER BY pp.created_at DESC
        ";
        
        return $this->db->fetchAll($sql, [$email]);
    }

    public function getActivePackageForTherapist($email, $therapistId) {
        $sql = "
            SELECT * FROM patient_packages
            WHERE patient_email = ? AND therapist_id = ? AND status = 'active' AND used_sessions < total_sessions
            ORDER BY created_at ASC LIMIT 1
        ";
        return $this->db->fetchOne($sql, [$email, $therapistId]);
    }

    public function createPatientPackage($data) {
        // Enforce UUID generation for PostgreSQL if needed, or let gen_random_uuid() handle it. 
        // Here we rely on the DB default.
        $sql = "INSERT INTO patient_packages 
                (package_id, therapist_id, user_id, patient_email, total_sessions, total_price_paid, status) 
                VALUES (?, ?, ?, ?, ?, ?, 'active') RETURNING id";
        
        $stmt = $this->db->executeQuery($sql, [
            $data['package_id'],
            $data['therapist_id'],
            $data['user_id'] ?? null,
            $data['patient_email'],
            $data['total_sessions'],
            $data['total_price_paid']
        ]);
        
        $result = $stmt->fetch();
        return $result ? $result['id'] : null;
    }

    public function incrementUsedSessions($id) {
        // Increment and update status to completed if used = total
        $sql = "
            UPDATE patient_packages 
            SET used_sessions = used_sessions + 1,
                status = CASE WHEN used_sessions + 1 >= total_sessions THEN 'completed' ELSE status END
            WHERE id = ? AND used_sessions < total_sessions
            RETURNING used_sessions, total_sessions, status
        ";
        $stmt = $this->db->executeQuery($sql, [$id]);
        return $stmt->fetch();
    }
}
