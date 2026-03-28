<?php

namespace App\Services;

use App\Core\Database;
use App\Exceptions\UnauthorizedException;
use App\Services\RoleService;
use App\Services\EmailDomainRuleService;
use App\Services\EmailService;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;

class AuthService {
    private $db;
    private $jwtSecret;

    public function __construct() {
        $this->db = Database::getInstance();
        $secret = $_ENV['JWT_SECRET_KEY'] ?? getenv('JWT_SECRET_KEY');
        if (empty($secret)) {
            $secret = 'default_secret_key_change_in_production_please_set_in_env';
        }
        $this->jwtSecret = (string)$secret;
    }

    /**
     * Login de usuario
     */
    public function login($username, $password) {
        $sql = "
            SELECT u.id, u.email, u.password_hash, u.is_active, u.email_verified,
                p.full_name, p.first_name, p.last_name, p.phone
            FROM users u
            LEFT JOIN profiles p ON u.id = p.user_id
            WHERE u.email = ? AND u.is_active = TRUE
            LIMIT 1
        ";
        $user = $this->db->fetchOne($sql, [$username]);

        if (!$user) {
            throw new UnauthorizedException('Credenciales invalidas');
        }

        if (!password_verify($password, $user['password_hash'])) {
            throw new UnauthorizedException('Credenciales invalidas');
        }

        $roles = $this->getUserRoles($user['id']);

        $therapistId = null;
        $roleNames = array_column($roles, 'name');
        if (in_array('therapist', $roleNames)) {
            $roleService = new RoleService();
            $therapistId = $roleService->getTherapistIdForUser($user['id']);
        }

        $accessToken  = $this->generateAccessToken($user, $roles);
        $refreshToken = $this->generateRefreshToken($user);
        $this->saveRefreshToken($user['id'], $refreshToken);

        $userData = [
            'id'             => $user['id'],
            'email'          => $user['email'],
            'full_name'      => $user['full_name'] ?? (($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? '')),
            'first_name'     => $user['first_name'] ?? null,
            'last_name'      => $user['last_name']  ?? null,
            'phone'          => $user['phone']       ?? null,
            'email_verified' => (bool)$user['email_verified'],
            'roles'          => $roles,
            'therapist_id'   => $therapistId,
        ];

        return ['success' => true, 'data' => ['token' => $accessToken, 'refresh_token' => $refreshToken, 'user' => $userData]];
    }

    /**
     * Login con usuario externo (Google, etc.)
     */
    public function loginWithExternalUser($user) {
        if (!$user['is_active']) {
            throw new UnauthorizedException('Usuario inactivo');
        }

        $roles = $this->getUserRoles($user['id']);

        $therapistId = null;
        $roleNames = array_column($roles, 'name');
        if (in_array('therapist', $roleNames)) {
            $roleService = new RoleService();
            $therapistId = $roleService->getTherapistIdForUser($user['id']);
        }

        $accessToken  = $this->generateAccessToken($user, $roles);
        $refreshToken = $this->generateRefreshToken($user);
        $this->saveRefreshToken($user['id'], $refreshToken);

        $userData = [
            'id'             => $user['id'],
            'email'          => $user['email'],
            'full_name'      => $user['full_name'] ?? (($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? '')),
            'first_name'     => $user['first_name'] ?? null,
            'last_name'      => $user['last_name']  ?? null,
            'phone'          => $user['phone']       ?? null,
            'avatar_url'     => $user['avatar_url']  ?? null,
            'email_verified' => (bool)$user['email_verified'],
            'roles'          => $roles,
            'therapist_id'   => $therapistId,
        ];

        return ['success' => true, 'data' => ['token' => $accessToken, 'refresh_token' => $refreshToken, 'user' => $userData]];
    }

    /**
     * Obtener roles de un usuario
     */
    private function getUserRoles($userId) {
        $sql = "
            SELECT r.id, r.name, r.description
            FROM user_roles ur
            INNER JOIN roles r ON ur.role_id = r.id
            WHERE ur.user_id = ?
        ";
        return $this->db->fetchAll($sql, [$userId]) ?: [];
    }

    /**
     * Generar access token (1 hora)
     */
    private function generateAccessToken($user, $roles) {
        $roleNames = array_column($roles, 'name');
        $payload = [
            'iss'    => 'mentelivre_api',
            'aud'    => 'mentelivre_users',
            'iat'    => time(),
            'exp'    => time() + 3600,
            'userId' => $user['id'],
            'email'  => $user['email'],
            'roles'  => $roleNames,
        ];
        return JWT::encode($payload, $this->jwtSecret, 'HS256');
    }

    /**
     * Generar refresh token (30 dias)
     */
    private function generateRefreshToken($user) {
        $payload = [
            'iss'    => 'mentelivre_api',
            'aud'    => 'mentelivre_users',
            'iat'    => time(),
            'exp'    => time() + (60 * 60 * 24 * 30),
            'userId' => $user['id'],
            'type'   => 'refresh',
        ];
        return JWT::encode($payload, $this->jwtSecret, 'HS256');
    }

    /**
     * Verificar token
     */
    public function verifyToken($token) {
        try {
            $decoded = JWT::decode($token, new Key($this->jwtSecret, 'HS256'));
            return (array) $decoded;
        } catch (\Firebase\JWT\ExpiredException $e) {
            throw new UnauthorizedException('Token expirado');
        } catch (\Firebase\JWT\SignatureInvalidException $e) {
            throw new UnauthorizedException('Token invalido: firma incorrecta');
        } catch (\Exception $e) {
            throw new UnauthorizedException('Token invalido o expirado');
        }
    }

    /**
     * Refresh token
     */
    public function refreshToken($refreshToken) {
        try {
            $decoded = JWT::decode($refreshToken, new Key($this->jwtSecret, 'HS256'));

            if (!isset($decoded->type) || $decoded->type !== 'refresh') {
                throw new UnauthorizedException('Token invalido');
            }

            if (!$this->isRefreshTokenValid($decoded->userId, $refreshToken)) {
                throw new UnauthorizedException('Token revocado');
            }

            $sql = "
                SELECT u.id, u.email, u.is_active, u.email_verified,
                    p.full_name, p.first_name, p.last_name, p.phone
                FROM users u
                LEFT JOIN profiles p ON u.id = p.user_id
                WHERE u.id = ? AND u.is_active = TRUE
                LIMIT 1
            ";
            $user = $this->db->fetchOne($sql, [$decoded->userId]);

            if (!$user) {
                throw new UnauthorizedException('Usuario no encontrado');
            }

            $roles       = $this->getUserRoles($user['id']);
            $accessToken = $this->generateAccessToken($user, $roles);

            return ['success' => true, 'data' => ['access_token' => $accessToken, 'refresh_token' => $refreshToken]];

        } catch (\Exception $e) {
            throw new UnauthorizedException('Token invalido o expirado: ' . $e->getMessage());
        }
    }

    /**
     * Logout
     */
    public function logout($refreshToken) {
        if ($refreshToken) {
            $this->removeRefreshToken($refreshToken);
        }
        return ['success' => true, 'message' => 'Sesion cerrada exitosamente'];
    }

    /**
     * Guardar refresh token
     */
    private function saveRefreshToken($userId, $token) {
        $tokenId   = $this->generateUuid();
        $expiresAt = date('Y-m-d H:i:s', time() + (60 * 60 * 24 * 30));

        $this->db->executeQuery(
            "INSERT INTO refresh_tokens (id, user_id, token, expires_at, created_at) VALUES (?, ?, ?, ?, NOW())",
            [$tokenId, $userId, $token, $expiresAt]
        );

        // Eliminar tokens expirados del mismo usuario
        $this->db->executeQuery(
            "DELETE FROM refresh_tokens WHERE user_id = ? AND expires_at < NOW()",
            [$userId]
        );
    }

    /**
     * Verificar si un refresh token es valido
     */
    private function isRefreshTokenValid($userId, $token) {
        $result = $this->db->fetchOne(
            "SELECT id FROM refresh_tokens WHERE user_id = ? AND token = ? AND expires_at > NOW() LIMIT 1",
            [$userId, $token]
        );
        return (bool)$result;
    }

    /**
     * Remover refresh token
     */
    private function removeRefreshToken($token) {
        $this->db->executeQuery("DELETE FROM refresh_tokens WHERE token = ?", [$token]);
    }

    /**
     * Registrar nuevo usuario
     */
    public function register($email, $password, $firstName, $lastName) {
        $existing = $this->db->fetchOne("SELECT id FROM users WHERE email = ? LIMIT 1", [$email]);
        if ($existing) {
            throw new UnauthorizedException('El email ya esta registrado');
        }

        $userId    = $this->generateUuid();
        $profileId = $this->generateUuid();
        $passwordHash = password_hash($password, PASSWORD_DEFAULT);
        $fullName  = trim($firstName . ' ' . $lastName);

        $this->db->executeQuery(
            "INSERT INTO users (id, email, password_hash, email_verified, is_active, created_at, updated_at) VALUES (?, ?, ?, FALSE, TRUE, NOW(), NOW())",
            [$userId, $email, $passwordHash]
        );

        $this->db->executeQuery(
            "INSERT INTO profiles (id, user_id, first_name, last_name, full_name, created_at, updated_at) VALUES (?, ?, ?, ?, ?, NOW(), NOW())",
            [$profileId, $userId, $firstName, $lastName, $fullName]
        );

        $emailDomainService = new EmailDomainRuleService();
        $emailDomainService->saveEmailClassification($userId, $email);

        $user = $this->db->fetchOne(
            "SELECT u.id, u.email, u.password_hash, u.is_active, u.email_verified, p.full_name, p.first_name, p.last_name, p.phone FROM users u LEFT JOIN profiles p ON u.id = p.user_id WHERE u.id = ? LIMIT 1",
            [$userId]
        );

        if (!$user) {
            throw new \Exception('Error al crear el usuario');
        }

        $roles        = $this->getUserRoles($userId);
        $accessToken  = $this->generateAccessToken($user, $roles);
        $refreshToken = $this->generateRefreshToken($user);
        $this->saveRefreshToken($userId, $refreshToken);

        $userData = [
            'id'             => $user['id'],
            'email'          => $user['email'],
            'full_name'      => $user['full_name'] ?? $fullName,
            'first_name'     => $user['first_name'] ?? $firstName,
            'last_name'      => $user['last_name']  ?? $lastName,
            'phone'          => $user['phone']       ?? null,
            'email_verified' => (bool)$user['email_verified'],
            'roles'          => $roles,
        ];

        return ['success' => true, 'data' => ['token' => $accessToken, 'refresh_token' => $refreshToken, 'user' => $userData]];
    }

    /**
     * Generar UUID v4
     */
    private function generateUuid() {
        $data    = random_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }

    /**
     * Solicitar reset de contrasena — envia email real via EmailService
     */
    public function forgotPassword($email) {
        $user = $this->db->fetchOne(
            "SELECT id, email, p.full_name, p.first_name FROM users u LEFT JOIN profiles p ON u.id = p.user_id WHERE u.email = ? AND u.is_active = TRUE LIMIT 1",
            [$email]
        );

        // Por seguridad: siempre retornar el mismo mensaje
        $response = ['success' => true, 'message' => 'Si el email existe, se ha enviado un enlace de recuperacion.'];

        if (!$user) {
            return $response;
        }

        $token     = bin2hex(random_bytes(32));
        $tokenHash = hash('sha256', $token);
        $tokenId   = $this->generateUuid();
        $expiresAt = date('Y-m-d H:i:s', strtotime('+1 hour'));

        // Invalidar tokens anteriores
        $this->db->executeQuery(
            "UPDATE password_reset_tokens SET used_at = NOW() WHERE user_id = ? AND used_at IS NULL",
            [$user['id']]
        );

        // Guardar nuevo token (hasheado)
        $this->db->executeQuery(
            "INSERT INTO password_reset_tokens (id, user_id, token, expires_at) VALUES (?, ?, ?, ?)",
            [$tokenId, $user['id'], $tokenHash, $expiresAt]
        );

        // Enviar email real
        $displayName = $user['full_name'] ?? $user['first_name'] ?? 'Usuario';
        $emailService = new EmailService();
        $emailService->sendPasswordReset($user['email'], $displayName, $token);

        return $response;
    }

    /**
     * Resetear contrasena usando token
     */
    public function resetPassword($token, $newPassword) {
        if (strlen($newPassword) < 6) {
            throw new \Exception('La contrasena debe tener al menos 6 caracteres');
        }

        $tokenHash   = hash('sha256', $token);
        $tokenRecord = $this->db->fetchOne(
            "SELECT prt.id, prt.user_id FROM password_reset_tokens prt WHERE prt.token = ? AND prt.expires_at > NOW() AND prt.used_at IS NULL LIMIT 1",
            [$tokenHash]
        );

        if (!$tokenRecord) {
            throw new UnauthorizedException('Token invalido o expirado');
        }

        $passwordHash = password_hash($newPassword, PASSWORD_DEFAULT);
        $this->db->executeQuery(
            "UPDATE users SET password_hash = ?, updated_at = NOW() WHERE id = ?",
            [$passwordHash, $tokenRecord['user_id']]
        );

        $this->db->executeQuery(
            "UPDATE password_reset_tokens SET used_at = NOW() WHERE id = ?",
            [$tokenRecord['id']]
        );

        $this->invalidateUserRefreshTokens($tokenRecord['user_id']);

        return ['success' => true, 'message' => 'Contrasena actualizada exitosamente'];
    }

    /**
     * Invalidar todos los refresh tokens de un usuario
     */
    private function invalidateUserRefreshTokens($userId) {
        $this->db->executeQuery("DELETE FROM refresh_tokens WHERE user_id = ?", [$userId]);
    }
}
