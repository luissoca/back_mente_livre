<?php

namespace App\Services;

use App\Core\Database;
use App\Services\EmailDomainRuleService;

class UserService {
    private PDO $db;
    private EmailDomainRuleService $emailDomainRuleService;

    public function __construct() {
        $this->db = Database::getInstance()->getConnection();
        $this->emailDomainRuleService = new EmailDomainRuleService();
    }

    /**
     * Obtener todos los usuarios con filtros y paginacion
     */
    public function getAll(array $filters = [], int $page = 1, int $perPage = 50): array {
        $perPage = max(1, min(200, $perPage));
        $page    = max(1, $page);
        $offset  = ($page - 1) * $perPage;

        $baseSql = "FROM users u
            LEFT JOIN profiles p ON u.id = p.user_id
            WHERE 1=1";

        $params = [];

        if (isset($filters['email_classification'])) {
            $baseSql .= " AND EXISTS (
                SELECT 1 FROM email_classifications ec
                WHERE ec.user_id = u.id
                AND ec.account_type = :email_classification
            )";
            $params[':email_classification'] = $filters['email_classification'];
        }

        if (isset($filters['is_active'])) {
            $baseSql .= " AND u.is_active = :is_active";
            $params[':is_active'] = $filters['is_active'];
        }

        if (isset($filters['role'])) {
            $baseSql .= " AND EXISTS (
                SELECT 1 FROM user_roles ur
                JOIN roles r ON ur.role_id = r.id
                WHERE ur.user_id = u.id AND r.name = :role
            )";
            $params[':role'] = $filters['role'];
        }

        // COUNT query
        $countStmt = $this->db->prepare("SELECT COUNT(DISTINCT u.id) " . $baseSql);
        foreach ($params as $key => $value) {
            $countStmt->bindValue($key, $value);
        }
        $countStmt->execute();
        $total = (int) $countStmt->fetchColumn();

        // Data query
        $dataSql = "SELECT u.id, u.email, u.email_verified, u.is_active,
                u.created_at, u.updated_at,
                p.first_name, p.last_name, p.full_name, p.phone
            " . $baseSql . "
            ORDER BY u.created_at DESC
            LIMIT :limit OFFSET :offset";

        $stmt = $this->db->prepare($dataSql);
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        $users = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($users as &$user) {
            $user['roles'] = $this->getUserRoles($user['id']);
            $this->ensureEmailClassification($user['id'], $user['email']);
        }

        return [
            'data'        => $users,
            'total'       => $total,
            'page'        => $page,
            'per_page'    => $perPage,
            'total_pages' => $total > 0 ? (int) ceil($total / $perPage) : 0,
        ];
    }

    private function getUserRoles(int $userId): array {
        $stmt = $this->db->prepare("
            SELECT r.name
            FROM user_roles ur
            JOIN roles r ON ur.role_id = r.id
            WHERE ur.user_id = :user_id
        ");
        $stmt->execute([':user_id' => $userId]);
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    private function ensureEmailClassification(int $userId, string $email): void {
        $stmt = $this->db->prepare("
            SELECT COUNT(*) FROM email_classifications WHERE user_id = :user_id
        ");
        $stmt->execute([':user_id' => $userId]);
        if ((int) $stmt->fetchColumn() === 0) {
            $classification = $this->emailDomainRuleService->classifyEmail($email);
            $ins = $this->db->prepare("
                INSERT INTO email_classifications (user_id, account_type, classified_at)
                VALUES (:user_id, :account_type, NOW())
            ");
            $ins->execute([
                ':user_id'      => $userId,
                ':account_type' => $classification,
            ]);
        }
    }

    public function getById(int $id): ?array {
        $stmt = $this->db->prepare("
            SELECT u.id, u.email, u.email_verified, u.is_active,
                u.created_at, u.updated_at,
                p.first_name, p.last_name, p.full_name, p.phone
            FROM users u
            LEFT JOIN profiles p ON u.id = p.user_id
            WHERE u.id = :id
        ");
        $stmt->execute([':id' => $id]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$user) return null;
        $user['roles'] = $this->getUserRoles($user['id']);
        return $user;
    }

    public function getByEmail(string $email): ?array {
        $stmt = $this->db->prepare("
            SELECT u.id, u.email, u.email_verified, u.is_active,
                u.created_at, u.updated_at,
                p.first_name, p.last_name, p.full_name, p.phone
            FROM users u
            LEFT JOIN profiles p ON u.id = p.user_id
            WHERE u.email = :email
        ");
        $stmt->execute([':email' => $email]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$user) return null;
        $user['roles'] = $this->getUserRoles($user['id']);
        return $user;
    }

    public function update(int $id, array $data): bool {
        $allowedFields = ['email_verified', 'is_active'];
        $setClauses = [];
        $params = [':id' => $id];

        foreach ($allowedFields as $field) {
            if (array_key_exists($field, $data)) {
                $setClauses[] = "$field = :$field";
                $params[":$field"] = $data[$field];
            }
        }

        if (empty($setClauses)) return false;

        $sql = "UPDATE users SET " . implode(', ', $setClauses) . ", updated_at = NOW() WHERE id = :id";
        $stmt = $this->db->prepare($sql);
        return $stmt->execute($params);
    }

    public function updateProfile(int $userId, array $data): bool {
        $allowedFields = ['first_name', 'last_name', 'full_name', 'phone'];
        $setClauses = [];
        $params = [':user_id' => $userId];

        foreach ($allowedFields as $field) {
            if (array_key_exists($field, $data)) {
                $setClauses[] = "$field = :$field";
                $params[":$field"] = $data[$field];
            }
        }

        if (empty($setClauses)) return false;

        $stmt = $this->db->prepare("SELECT id FROM profiles WHERE user_id = :user_id");
        $stmt->execute([':user_id' => $userId]);
        $exists = $stmt->fetch();

        if ($exists) {
            $sql = "UPDATE profiles SET " . implode(', ', $setClauses) . " WHERE user_id = :user_id";
        } else {
            $fields = array_map(fn($c) => trim(explode('=', $c)[0]), $setClauses);
            $fields[] = 'user_id';
            $placeholders = array_map(fn($f) => ":$f", $fields);
            $sql = "INSERT INTO profiles (" . implode(', ', $fields) . ") VALUES (" . implode(', ', $placeholders) . ")";
        }

        $stmt = $this->db->prepare($sql);
        return $stmt->execute($params);
    }

    public function deactivate(int $id): bool {
        return $this->update($id, ['is_active' => 0]);
    }

    public function activate(int $id): bool {
        return $this->update($id, ['is_active' => 1]);
    }
}
