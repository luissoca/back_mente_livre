<?php

namespace App\Services;

use App\Core\Database;
use App\Exceptions\UnauthorizedException;
use App\Services\RoleService;
use App\Services\EmailDomainRuleService;
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
        // Buscar usuario por email (username es el email)
        $sql = "
            SELECT 
                u.id,
                u.email,
                u.password_hash,
                u.is_active,
                u.email_verified,
                p.full_name,
                p.first_name,
                p.last_name,
                p.phone
            FROM users u
            LEFT JOIN profiles p ON u.id = p.user_id
            WHERE u.email = ? AND u.is_active = TRUE
            LIMIT 1
        ";
        $user = $this->db->fetchOne($sql, [$username]);

        if (!$user) {
            throw new UnauthorizedException('Credenciales inválidas');
        }

        // Verificar contraseña
        if (!password_verify($password, $user['password_hash'])) {
            throw new UnauthorizedException('Credenciales inválidas');
        }

        // Obtener roles del usuario
        $roles = $this->getUserRoles($user['id']);

        // Obtener therapist_id si el usuario es terapeuta
        $therapistId = null;
        $roleNames = array_column($roles, 'name');
        if (in_array('therapist', $roleNames)) {
            $roleService = new RoleService();
            $therapistId = $roleService->getTherapistIdForUser($user['id']);
        }

        // Generar tokens
        $accessToken = $this->generateAccessToken($user, $roles);
        $refreshToken = $this->generateRefreshToken($user);

        // Guardar refresh token
        $this->saveRefreshToken($user['id'], $refreshToken);

        // Preparar datos del usuario para la respuesta
        $userData = [
            'id' => $user['id'],
            'email' => $user['email'],
            'full_name' => $user['full_name'] ?? ($user['first_name'] . ' ' . $user['last_name']) ?? null,
            'first_name' => $user['first_name'] ?? null,
            'last_name' => $user['last_name'] ?? null,
            'phone' => $user['phone'] ?? null,
            'email_verified' => (bool)$user['email_verified'],
            'roles' => $roles,
            'therapist_id' => $therapistId
        ];

        return [
            'success' => true,
            'data' => [
                'token' => $accessToken,
                'refresh_token' => $refreshToken,
                'user' => $userData
            ]
        ];
    }

    /**
     * Login con usuario externo (Google, etc.)
     */
    public function loginWithExternalUser($user) {
        if (!$user['is_active']) {
            throw new UnauthorizedException('Usuario inactivo');
        }

        // Obtener roles del usuario
        $roles = $this->getUserRoles($user['id']);

        // Obtener therapist_id si el usuario es terapeuta
        $therapistId = null;
        $roleNames = array_column($roles, 'name');
        if (in_array('therapist', $roleNames)) {
            $roleService = new RoleService();
            $therapistId = $roleService->getTherapistIdForUser($user['id']);
        }

        // Generar tokens
        $accessToken = $this->generateAccessToken($user, $roles);
        $refreshToken = $this->generateRefreshToken($user);

        // Guardar refresh token
        $this->saveRefreshToken($user['id'], $refreshToken);

        // Preparar datos del usuario para la respuesta
        $userData = [
            'id' => $user['id'],
            'email' => $user['email'],
            'full_name' => $user['full_name'] ?? ($user['first_name'] . ' ' . $user['last_name']) ?? null,
            'first_name' => $user['first_name'] ?? null,
            'last_name' => $user['last_name'] ?? null,
            'phone' => $user['phone'] ?? null,
            'avatar_url' => $user['avatar_url'] ?? null,
            'email_verified' => (bool)$user['email_verified'],
            'roles' => $roles,
            'therapist_id' => $therapistId
        ];

        return [
            'success' => true,
            'data' => [
                'token' => $accessToken,
                'refresh_token' => $refreshToken,
                'user' => $userData
            ]
        ];
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
        $roles = $this->db->fetchAll($sql, [$userId]);
        return $roles ?: [];
    }

    /**
     * Generar access token
     */
    private function generateAccessToken($user, $roles) {
        $roleNames = array_column($roles, 'name');
        
        $payload = [
            'iss' => 'mentelivre_api',
            'aud' => 'mentelivre_users',
            'iat' => time(),
            'exp' => time() + (60 * 60), // 1 hora
            'userId' => $user['id'],
            'email' => $user['email'],
            'roles' => $roleNames
        ];

        return JWT::encode($payload, $this->jwtSecret, 'HS256');
    }

    /**
     * Generar refresh token
     */
    private function generateRefreshToken($user) {
        $payload = [
            'iss' => 'mentelivre_api',
            'aud' => 'mentelivre_users',
            'iat' => time(),
            'exp' => time() + (60 * 60 * 24 * 30), // 30 días
            'userId' => $user['id'],
            'type' => 'refresh'
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
            throw new UnauthorizedException('Token inválido: firma incorrecta');
        } catch (\Exception $e) {
            throw new UnauthorizedException('Token inválido o expirado');
        }
    }

    /**
     * Refresh token
     */
    public function refreshToken($refreshToken) {
        try {
            $decoded = JWT::decode($refreshToken, new Key($this->jwtSecret, 'HS256'));
            
            if (!isset($decoded->type) || $decoded->type !== 'refresh') {
                throw new UnauthorizedException('Token inválido');
            }

            // Verificar que el refresh token esté en el storage
            if (!$this->isRefreshTokenValid($decoded->userId, $refreshToken)) {
                throw new UnauthorizedException('Token revocado');
            }

            // Obtener usuario actualizado
            $sql = "
                SELECT 
                    u.id,
                    u.email,
                    u.is_active,
                    u.email_verified,
                    p.full_name,
                    p.first_name,
                    p.last_name,
                    p.phone
                FROM users u
                LEFT JOIN profiles p ON u.id = p.user_id
                WHERE u.id = ? AND u.is_active = TRUE
                LIMIT 1
            ";
            $user = $this->db->fetchOne($sql, [$decoded->userId]);

            if (!$user) {
                throw new UnauthorizedException('Usuario no encontrado');
            }

            // Obtener roles actualizados
            $roles = $this->getUserRoles($user['id']);

            // Generar nuevo access token
            $accessToken = $this->generateAccessToken($user, $roles);

            return [
                'success' => true,
                'data' => [
                    'access_token' => $accessToken,
                    'refresh_token' => $refreshToken
                ]
            ];
        } catch (\Exception $e) {
            throw new UnauthorizedException('Token inválido o expirado: ' . $e->getMessage());
        }
    }

    /**
     * Logout
     */
    public function logout($refreshToken) {
        if ($refreshToken) {
            $this->removeRefreshToken($refreshToken);
        }
        return ['success' => true, 'message' => 'Sesión cerrada exitosamente'];
    }

    /**
     * Guardar refresh token
     */
    /**
     * Guardar refresh token
     */
    private function saveRefreshToken($userId, $token) {
        $tokenId = $this->generateUuid();
        $expiresAt = date('Y-m-d H:i:s', time() + (60 * 60 * 24 * 30)); // 30 días

        $sql = "INSERT INTO refresh_tokens (id, user_id, token, expires_at, created_at) VALUES (?, ?, ?, ?, NOW())";
        $this->db->executeQuery($sql, [$tokenId, $userId, $token, $expiresAt]);

        // Limpieza: Mantener solo los últimos 5 tokens por usuario
        // Nota: Esta consulta es genérica, debería funcionar en MySQL y PostgreSQL
        // En caso de problemas de compatibilidad, se puede simplificar eliminando solo los expirados
        /* 
        $sql = "DELETE FROM refresh_tokens 
                WHERE user_id = ? 
                AND id NOT IN (
                    SELECT id FROM (
                        SELECT id FROM refresh_tokens 
                        WHERE user_id = ? 
                        ORDER BY created_at DESC 
                        LIMIT 5
                    ) as sub
                )";
        $this->db->executeQuery($sql, [$userId, $userId]); 
        */
        
        // Versión simplificada para evitar conflictos SQL: eliminar expirados
        $sql = "DELETE FROM refresh_tokens WHERE user_id = ? AND expires_at < NOW()";
        $this->db->executeQuery($sql, [$userId]);
    }

    /**
     * Verificar si un refresh token es válido
     */
    /**
     * Verificar si un refresh token es válido
     */
    private function isRefreshTokenValid($userId, $token) {
        $sql = "SELECT id FROM refresh_tokens 
                WHERE user_id = ? AND token = ? AND expires_at > NOW() 
                LIMIT 1";
        $result = $this->db->fetchOne($sql, [$userId, $token]);
        return (bool)$result;
    }

    /**
     * Remover refresh token
     */
    /**
     * Remover refresh token
     */
    private function removeRefreshToken($token) {
        $sql = "DELETE FROM refresh_tokens WHERE token = ?";
        $this->db->executeQuery($sql, [$token]);
    }



    /**
     * Registrar nuevo usuario
     */
    public function register($email, $password, $firstName, $lastName) {
        // Validar que el email no exista
        $sql = "SELECT id FROM users WHERE email = ? LIMIT 1";
        $existingUser = $this->db->fetchOne($sql, [$email]);

        if ($existingUser) {
            throw new UnauthorizedException('El email ya está registrado');
        }

        // Generar UUID para el usuario
        $userId = $this->generateUuid();
        $profileId = $this->generateUuid();

        // Hashear contraseña
        $passwordHash = password_hash($password, PASSWORD_DEFAULT);

        // Crear usuario
        $sql = "INSERT INTO users (id, email, password_hash, email_verified, is_active, created_at, updated_at) 
                VALUES (?, ?, ?, FALSE, TRUE, NOW(), NOW())";
        $this->db->executeQuery($sql, [$userId, $email, $passwordHash]);

        // Crear perfil
        $fullName = trim($firstName . ' ' . $lastName);
        $sql = "INSERT INTO profiles (id, user_id, first_name, last_name, full_name, created_at, updated_at) 
                VALUES (?, ?, ?, ?, ?, NOW(), NOW())";
        $this->db->executeQuery($sql, [$profileId, $userId, $firstName, $lastName, $fullName]);

        // Clasificar y guardar el tipo de cuenta del usuario basándose en su email
        $emailDomainService = new EmailDomainRuleService();
        $emailDomainService->saveEmailClassification($userId, $email);

        // Obtener usuario creado con perfil
        $sql = "
            SELECT 
                u.id,
                u.email,
                u.password_hash,
                u.is_active,
                u.email_verified,
                p.full_name,
                p.first_name,
                p.last_name,
                p.phone
            FROM users u
            LEFT JOIN profiles p ON u.id = p.user_id
            WHERE u.id = ?
            LIMIT 1
        ";
        $user = $this->db->fetchOne($sql, [$userId]);

        if (!$user) {
            throw new \Exception('Error al crear el usuario');
        }

        // Obtener roles del usuario (debería estar vacío para nuevos usuarios)
        $roles = $this->getUserRoles($userId);

        // Generar tokens automáticamente después del registro
        $accessToken = $this->generateAccessToken($user, $roles);
        $refreshToken = $this->generateRefreshToken($user);

        // Guardar refresh token
        $this->saveRefreshToken($userId, $refreshToken);

        // Preparar datos del usuario para la respuesta
        $userData = [
            'id' => $user['id'],
            'email' => $user['email'],
            'full_name' => $user['full_name'] ?? ($user['first_name'] . ' ' . $user['last_name']) ?? null,
            'first_name' => $user['first_name'] ?? null,
            'last_name' => $user['last_name'] ?? null,
            'phone' => $user['phone'] ?? null,
            'email_verified' => (bool)$user['email_verified'],
            'roles' => $roles
        ];

        return [
            'success' => true,
            'data' => [
                'token' => $accessToken,
                'refresh_token' => $refreshToken,
                'user' => $userData
            ]
        ];
    }

    /**
     * Generar UUID v4
     */
    private function generateUuid() {
        $data = random_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40); // versión 4
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80); // variante
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }

    /**
     * Solicitar reset de contraseña (forgot password)
     * Genera un token de recuperación y lo guarda en la base de datos
     */
    public function forgotPassword($email) {
        // Buscar usuario por email
        $sql = "SELECT id, email FROM users WHERE email = ? AND is_active = TRUE LIMIT 1";
        $user = $this->db->fetchOne($sql, [$email]);

        if (!$user) {
            // Por seguridad, no revelamos si el email existe o no
            return [
                'success' => true,
                'message' => 'Si el email existe, se ha enviado un enlace de recuperación.'
            ];
        }

        // Generar token único
        $token = bin2hex(random_bytes(32)); // Token de 64 caracteres
        $tokenHash = hash('sha256', $token); // Hash para almacenar en BD
        $tokenId = $this->generateUuid();
        
        // Token expira en 1 hora
        $expiresAt = date('Y-m-d H:i:s', strtotime('+1 hour'));

        // Invalidar tokens anteriores del usuario
        $sql = "UPDATE password_reset_tokens SET used_at = NOW() WHERE user_id = ? AND used_at IS NULL";
        $this->db->executeQuery($sql, [$user['id']]);

        // Guardar nuevo token
        $sql = "
            INSERT INTO password_reset_tokens (id, user_id, token, expires_at)
            VALUES (?, ?, ?, ?)
        ";
        $this->db->executeQuery($sql, [$tokenId, $user['id'], $tokenHash, $expiresAt]);

        // TODO: Enviar email con el link de recuperación
        // Por ahora, retornamos el token en la respuesta (solo para desarrollo)
        // En producción, esto debe enviarse por email
        $resetUrl = $_ENV['FRONTEND_URL'] ?? 'http://localhost:5173';
        $resetLink = $resetUrl . '/reset-password?token=' . $token . '&type=recovery';

        return [
            'success' => true,
            'message' => 'Si el email existe, se ha enviado un enlace de recuperación.',
            // TODO: Remover esto en producción - solo para desarrollo
            'reset_link' => $resetLink
        ];
    }

    /**
     * Resetear contraseña usando token
     */
    public function resetPassword($token, $newPassword) {
        // Validar longitud de contraseña
        if (strlen($newPassword) < 6) {
            throw new \Exception('La contraseña debe tener al menos 6 caracteres');
        }

        // Hash del token para buscar en BD
        $tokenHash = hash('sha256', $token);

        // Buscar token válido
        $sql = "
            SELECT prt.id, prt.user_id, prt.expires_at, prt.used_at
            FROM password_reset_tokens prt
            WHERE prt.token = ? 
            AND prt.expires_at > NOW()
            AND prt.used_at IS NULL
            LIMIT 1
        ";
        $tokenRecord = $this->db->fetchOne($sql, [$tokenHash]);

        if (!$tokenRecord) {
            throw new UnauthorizedException('Token inválido o expirado');
        }

        // Actualizar contraseña del usuario
        $passwordHash = password_hash($newPassword, PASSWORD_DEFAULT);
        $sql = "UPDATE users SET password_hash = ?, updated_at = NOW() WHERE id = ?";
        $this->db->executeQuery($sql, [$passwordHash, $tokenRecord['user_id']]);

        // Marcar token como usado
        $sql = "UPDATE password_reset_tokens SET used_at = NOW() WHERE id = ?";
        $this->db->executeQuery($sql, [$tokenRecord['id']]);

        // Invalidar todos los refresh tokens del usuario por seguridad
        $this->invalidateUserRefreshTokens($tokenRecord['user_id']);

        return [
            'success' => true,
            'message' => 'Contraseña actualizada exitosamente'
        ];
    }

    /**
     * Invalidar todos los refresh tokens de un usuario
     */
    private function invalidateUserRefreshTokens($userId) {
        $sql = "DELETE FROM refresh_tokens WHERE user_id = ?";
        $this->db->executeQuery($sql, [$userId]);
    }
}
