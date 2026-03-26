-- ============================================================================
-- Script para normalizar las URLs de imágenes en la base de datos
-- Este script extrae el nombre del archivo de las URLs de Supabase y 
-- actualiza las rutas para usar solo el nombre del archivo relativo
-- ============================================================================

-- Actualizar therapist_photos.photo_url
-- Extraer nombre del archivo de URLs de Supabase o mantener rutas relativas existentes

UPDATE `therapist_photos`
SET `photo_url` = CASE
    -- Si es una URL de Supabase, extraer solo el nombre del archivo
    WHEN `photo_url` LIKE 'https://orkbgvnmnuqfkebrxpko.supabase.co/storage/v1/object/public/therapist-photos/%' THEN
        CONCAT('therapists/photos/', SUBSTRING_INDEX(`photo_url`, '/', -1))
    -- Si es una URL de Supabase friendly, extraer solo el nombre del archivo
    WHEN `photo_url` LIKE 'https://orkbgvnmnuqfkebrxpko.supabase.co/storage/v1/object/public/therapist-photos/friendly-%' THEN
        CONCAT('therapists/photos/', SUBSTRING_INDEX(`photo_url`, '/', -1))
    -- Si ya es una ruta relativa como /therapists/..., convertir a formato relativo sin /
    WHEN `photo_url` LIKE '/therapists/%' THEN
        CONCAT('therapists/photos/', SUBSTRING_INDEX(`photo_url`, '/', -1))
    -- Mantener el resto como está si ya está en el formato correcto
    ELSE `photo_url`
END
WHERE `photo_url` IS NOT NULL;

-- Actualizar team_profiles.friendly_photo_url
-- Extraer nombre del archivo de URLs de Supabase

UPDATE `team_profiles`
SET `friendly_photo_url` = CASE
    -- Si es una URL de Supabase team-profiles, extraer solo el nombre del archivo
    WHEN `friendly_photo_url` LIKE 'https://orkbgvnmnuqfkebrxpko.supabase.co/storage/v1/object/public/therapist-photos/team-profiles/%' THEN
        CONCAT('team/photos/', SUBSTRING_INDEX(`friendly_photo_url`, '/', -1))
    -- Mantener el resto como está si ya está en el formato correcto
    ELSE `friendly_photo_url`
END
WHERE `friendly_photo_url` IS NOT NULL;

-- Verificar los resultados
SELECT 
    'therapist_photos' AS table_name,
    COUNT(*) AS total_records,
    COUNT(CASE WHEN photo_url LIKE 'therapists/photos/%' THEN 1 END) AS normalized_urls,
    COUNT(CASE WHEN photo_url LIKE 'https://%' THEN 1 END) AS remaining_urls
FROM `therapist_photos`
WHERE photo_url IS NOT NULL

UNION ALL

SELECT 
    'team_profiles' AS table_name,
    COUNT(*) AS total_records,
    COUNT(CASE WHEN friendly_photo_url LIKE 'team/photos/%' THEN 1 END) AS normalized_urls,
    COUNT(CASE WHEN friendly_photo_url LIKE 'https://%' THEN 1 END) AS remaining_urls
FROM `team_profiles`
WHERE friendly_photo_url IS NOT NULL;
