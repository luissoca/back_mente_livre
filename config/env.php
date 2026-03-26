<?php
/**
 * Cargar variables de entorno desde .env o .env.production
 */

use Dotenv\Dotenv;

$baseDir = __DIR__ . '/..';

// Cargar .env si existe; si no, .env.production (para producción)
if (file_exists($baseDir . '/.env')) {
    Dotenv::createImmutable($baseDir)->load();
} elseif (file_exists($baseDir . '/.env.production')) {
    Dotenv::createImmutable($baseDir, '.env.production')->load();
} else {
    // Si no hay ningún archivo, usar variables de entorno del servidor
}
