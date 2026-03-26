<?php

namespace App\Core;

class ImageUrlHelper {
    /**
     * Construir URL completa para una imagen desde una ruta relativa
     * 
     * @param string|null $relativePath Ruta relativa como 'therapists/photos/file.jpg' o 'team/photos/file.jpg'
     * @return string|null URL completa o null si no hay ruta
     */
    public static function buildUrl(?string $relativePath): ?string {
        if (empty($relativePath)) {
            return null;
        }

        // Si ya es una URL completa (http:// o https://), devolverla con HTTPS
        if (preg_match('/^https?:\/\//', $relativePath)) {
            // Normalizar a HTTPS si es HTTP
            $relativePath = preg_replace('/^http:\/\//i', 'https://', $relativePath);
            // Si contiene '/uploads/', limpiar la ruta y reconstruir
            if (strpos($relativePath, '/uploads/') !== false) {
                $path = substr($relativePath, strpos($relativePath, '/uploads/') + 9);
                return self::buildUrl($path);
            }
            return $relativePath;
        }

        // Determinar la URL base del backend
        $baseUrl = self::getBaseUrl();
        
        // Limpiar la ruta: quitar 'uploads/' si ya existe al inicio para evitar duplicación
        $path = ltrim($relativePath, '/');
        if (strpos($path, 'uploads/') === 0) {
            $path = substr($path, 8);
        }
        
        // Retornar la ruta a través del proxy /uploads/
        return rtrim($baseUrl, '/') . '/uploads/' . ltrim($path, '/');
    }

    /**
     * Obtener la URL base del backend
     * 
     * @return string URL base (ej: https://backend.mentelivre.org/)
     */
    private static function getBaseUrl(): string {
        // Intentar obtener desde variable de entorno
        $baseUrl = $_ENV['BASE_URL'] ?? getenv('BASE_URL');
        
        if (!empty($baseUrl)) {
            return rtrim($baseUrl, '/');
        }

        // Construir desde el request actual de forma limpia
        // Check X-Forwarded-Proto (set by reverse proxies like Vercel, Cloudflare)
        $forwardedProto = $_SERVER['HTTP_X_FORWARDED_PROTO'] ?? null;
        if ($forwardedProto) {
            $protocol = $forwardedProto;
        } else {
            $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        }
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost:8000';
        
        // Force HTTPS for known production hosts
        if (strpos($host, 'mentelivre.org') !== false) {
            $protocol = 'https';
        }
        
        return $protocol . '://' . $host;

    }

    /**
     * Normalizar múltiples URLs en un array de fotos
     * 
     * @param array $photos Array de fotos con 'photo_url'
     * @return array Array con URLs normalizadas
     */
    public static function normalizePhotoUrls(array $photos): array {
        foreach ($photos as &$photo) {
            if (isset($photo['photo_url'])) {
                $photo['photo_url'] = self::buildUrl($photo['photo_url']);
            }
        }
        return $photos;
    }
}
