<?php

namespace App\Services;

use App\Core\Database;
use App\Services\AuthService;
use App\Services\EmailDomainRuleService;
use Firebase\JWT\JWT;
use Firebase\JWT\JWK;
use Exception;

class GoogleAuthService {
    private $db;
    private $authService;
    private $clientId;

    public function __construct() {
        $this->db = Database::getInstance();
        $this->authService = new AuthService();
        $this->clientId = $_ENV['GOOGLE_CLIENT_ID'] ?? getenv('GOOGLE_CLIENT_ID');
    }

    public function authenticate($idToken) {
        if (empty($this->clientId)) {
            throw new Exception('GOOGLE_CLIENT_ID not configured in backend');
        }

        // 1. Verify Token
        $payload = $this->verifyIdToken($idToken);

        // 2. Find or Create User
        $user = $this->findOrCreateUser($payload);

        // 3. Login
        return $this->authService->loginWithExternalUser($user);
    }

    private function verifyIdToken($idToken) {
        try {
            // Fetch Google's public keys
            // Recommended: Cache these keys
            $jwksUrl = 'https://www.googleapis.com/oauth2/v3/certs';
            $jwksContent = @file_get_contents($jwksUrl);
            
            if ($jwksContent === false) {
                // Fallback for environments where file_get_contents might be restricted or network issues
                // Try cURL
                $ch = curl_init();
                curl_setopt($ch, CURLOPT_URL, $jwksUrl);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                $jwksContent = curl_exec($ch);
                
                if (curl_errno($ch)) {
                    throw new Exception('Error en cURL: ' . curl_error($ch));
                }
                
                if ($jwksContent === false) {
                    throw new Exception('Failed to fetch Google certs');
                }
            }
            
            $jwks = json_decode($jwksContent, true);
            
            // Verify signature
            $decoded = JWT::decode($idToken, JWK::parseKeySet($jwks));
            $payload = (array) $decoded;

            // Verify Audience
            if ($payload['aud'] !== $this->clientId) {
                throw new Exception('Client ID mismatch');
            }

            // Verify Issuer
            if (!in_array($payload['iss'], ['accounts.google.com', 'https://accounts.google.com'])) {
                throw new Exception('Invalid issuer');
            }

            return $payload;
        } catch (Exception $e) {
            throw new Exception('Invalid Google Token: ' . $e->getMessage());
        }
    }

    private function findOrCreateUser($payload) {
        $googleSub = $payload['sub'];
        $email = $payload['email'];
        $firstName = $payload['given_name'] ?? '';
        $lastName = $payload['family_name'] ?? '';
        $avatarUrl = $payload['picture'] ?? null;

        // 1. Check by google_sub
        $sql = "SELECT u.id, u.email, u.password_hash, u.is_active, u.email_verified, u.google_sub,
                       p.first_name, p.last_name, p.full_name, p.phone, p.avatar_url 
                FROM users u 
                LEFT JOIN profiles p ON u.id = p.user_id 
                WHERE u.google_sub = ? LIMIT 1";
        $user = $this->db->fetchOne($sql, [$googleSub]);

        if ($user) {
            // Update avatar if changed/missing
            if ($avatarUrl && $user['avatar_url'] !== $avatarUrl) {
                $this->updateAvatar($user['id'], $avatarUrl);
                $user['avatar_url'] = $avatarUrl;
            }
            return $user;
        }

        // 2. Check by email (Link account)
        $sql = "SELECT u.id, u.email, u.password_hash, u.is_active, u.email_verified, u.google_sub,
                       p.first_name, p.last_name, p.full_name, p.phone, p.avatar_url 
                FROM users u 
                LEFT JOIN profiles p ON u.id = p.user_id 
                WHERE u.email = ? LIMIT 1";
        $user = $this->db->fetchOne($sql, [$email]);

        if ($user) {
            // Link account
            $this->linkGoogleAccount($user['id'], $googleSub, $avatarUrl);
            $user['google_sub'] = $googleSub;
            $user['avatar_url'] = $avatarUrl;
            
            // If email was not verified, verify it now since Google trusted it
            if (!$user['email_verified'] && $payload['email_verified']) {
                $this->verifyUserEmail($user['id']);
                $user['email_verified'] = 1;
            }
            
            return $user;
        }

        // 3. Create new user
        return $this->createNewUser($email, $firstName, $lastName, $googleSub, $avatarUrl, $payload['email_verified'] ?? false);
    }

    private function updateAvatar($userId, $avatarUrl) {
        $sql = "UPDATE profiles SET avatar_url = ?, updated_at = NOW() WHERE user_id = ?";
        $this->db->executeQuery($sql, [$avatarUrl, $userId]);
    }

    private function linkGoogleAccount($userId, $googleSub, $avatarUrl) {
        $sql = "UPDATE users SET google_sub = ?, updated_at = NOW() WHERE id = ?";
        $this->db->executeQuery($sql, [$googleSub, $userId]);

        if ($avatarUrl) {
            $this->updateAvatar($userId, $avatarUrl);
        }
    }

    private function verifyUserEmail($userId) {
        $sql = "UPDATE users SET email_verified = TRUE, email_verified_at = NOW(), updated_at = NOW() WHERE id = ?";
        $this->db->executeQuery($sql, [$userId]);
    }

    private function createNewUser($email, $firstName, $lastName, $googleSub, $avatarUrl, $emailVerified) {
        // Generate UUIDs
        $userId = $this->generateUuid();
        $profileId = $this->generateUuid();

        // Generate random secure password (user won't know it, they use Google)
        $randomPassword = bin2hex(random_bytes(16));
        $passwordHash = password_hash($randomPassword, PASSWORD_DEFAULT);

        // Insert User
        $sql = "INSERT INTO users (id, email, password_hash, email_verified, email_verified_at, is_active, google_sub, created_at, updated_at) 
                VALUES (?, ?, ?, ?, ?, TRUE, ?, NOW(), NOW())";
        $verifiedAt = $emailVerified ? date('Y-m-d H:i:s') : null;
        $this->db->executeQuery($sql, [$userId, $email, $passwordHash, $emailVerified ? 'true' : 'false', $verifiedAt, $googleSub]);

        // Insert Profile
        $fullName = trim($firstName . ' ' . $lastName);
        $sql = "INSERT INTO profiles (id, user_id, first_name, last_name, full_name, avatar_url, created_at, updated_at) 
                VALUES (?, ?, ?, ?, ?, ?, NOW(), NOW())";
        $this->db->executeQuery($sql, [$profileId, $userId, $firstName, $lastName, $fullName, $avatarUrl]);

        // Classify Email
        $emailDomainService = new EmailDomainRuleService();
        $emailDomainService->saveEmailClassification($userId, $email);

        // Return user structure
        return [
            'id' => $userId,
            'email' => $email,
            'email_verified' => $emailVerified,
            'is_active' => 1,
            'google_sub' => $googleSub,
            'first_name' => $firstName,
            'last_name' => $lastName,
            'full_name' => $fullName,
            'phone' => null,
            'avatar_url' => $avatarUrl
        ];
    }

    // Helper to generate UUID (duplicated from AuthService, could be in a Helper class)
    private function generateUuid() {
        $data = random_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}
