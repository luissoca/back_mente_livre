<?php

namespace App\Services;

/**
 * Servicio de caché en archivos para reducir consultas a MySQL
 * Sin TTL - solo invalidación manual
 * Usa serialize/unserialize para mejor rendimiento
 */
class CacheService {
    private string $cacheDir;

    public function __construct() {
        // Directorio de caché (usar /tmp en Vercel/Lambda)
        $this->cacheDir = sys_get_temp_dir() . '/mente_livre_cache';
        if (!is_dir($this->cacheDir)) {
            mkdir($this->cacheDir, 0755, true);
        }
    }

    /**
     * Obtener datos del caché
     * @param string $key Nombre del archivo (sin extensión)
     * @return array|null Datos del caché o null si no existe
     */
    public function get(string $key): ?array {
        $filePath = $this->getFilePath($key);
        
        if (!file_exists($filePath)) {
            return null;
        }

        $content = file_get_contents($filePath);
        if ($content === false) {
            return null;
        }

        $data = @unserialize($content);
        if (!is_array($data)) {
            return null;
        }

        return $data;
    }

    /**
     * Guardar datos en el caché
     * @param string $key Nombre del archivo (sin extensión)
     * @param array $data Datos a guardar
     * @return bool True si se guardó correctamente
     */
    public function set(string $key, array $data): bool {
        $filePath = $this->getFilePath($key);
        $serialized = serialize($data);
        
        if ($serialized === false) {
            return false;
        }

        return file_put_contents($filePath, $serialized, LOCK_EX) !== false;
    }

    /**
     * Invalidar (borrar) un archivo de caché
     * @param string $key Nombre del archivo (sin extensión)
     * @return bool True si se borró o no existía
     */
    public function invalidate(string $key): bool {
        $filePath = $this->getFilePath($key);
        
        if (!file_exists($filePath)) {
            return true; // Ya no existe, objetivo cumplido
        }

        return unlink($filePath);
    }

    /**
     * Invalidar múltiples archivos de caché
     * @param array $keys Array de nombres de archivos
     */
    public function invalidateMultiple(array $keys): void {
        foreach ($keys as $key) {
            $this->invalidate($key);
        }
    }

    /**
     * Limpiar todo el caché (borrar todos los archivos .cache)
     */
    public function clear(): void {
        $files = glob($this->cacheDir . '/*.cache');
        if ($files === false) {
            return;
        }

        foreach ($files as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
    }

    /**
     * Obtener la ruta completa del archivo de caché
     */
    private function getFilePath(string $key): string {
        // Sanitizar el nombre del archivo (evitar ../.. etc)
        $safeKey = preg_replace('/[^a-zA-Z0-9_-]/', '_', $key);
        return $this->cacheDir . '/' . $safeKey . '.cache';
    }

    /**
     * Verificar si existe un archivo de caché
     */
    public function has(string $key): bool {
        return file_exists($this->getFilePath($key));
    }
}
