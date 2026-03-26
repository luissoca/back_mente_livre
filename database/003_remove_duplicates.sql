-- Script para eliminar duplicados en therapist_experience_topics y therapist_photos
-- Mantiene solo una copia de cada registro duplicado

-- Eliminar duplicados de therapist_experience_topics
-- Crear tabla temporal con registros únicos
CREATE TEMPORARY TABLE temp_topics AS
SELECT MIN(id) as id, therapist_id, topic
FROM therapist_experience_topics
GROUP BY therapist_id, topic;

-- Eliminar todos los registros de la tabla original
DELETE FROM therapist_experience_topics;

-- Reinsertar solo los registros únicos
INSERT INTO therapist_experience_topics (id, therapist_id, topic)
SELECT id, therapist_id, topic FROM temp_topics;

DROP TEMPORARY TABLE temp_topics;

-- Eliminar duplicados de therapist_photos
-- Crear tabla temporal con registros únicos
CREATE TEMPORARY TABLE temp_photos AS
SELECT MIN(id) as id, therapist_id, photo_type, photo_url, photo_position, is_active
FROM therapist_photos
GROUP BY therapist_id, photo_type, photo_url, photo_position, is_active;

-- Eliminar todos los registros de la tabla original
DELETE FROM therapist_photos;

-- Reinsertar solo los registros únicos
INSERT INTO therapist_photos (id, therapist_id, photo_type, photo_url, photo_position, is_active)
SELECT id, therapist_id, photo_type, photo_url, photo_position, is_active FROM temp_photos;

DROP TEMPORARY TABLE temp_photos;

-- Verificar resultados
SELECT 'Experience Topics After Cleanup:' as message;
SELECT therapist_id, COUNT(*) as topics_count 
FROM therapist_experience_topics 
GROUP BY therapist_id 
ORDER BY therapist_id 
LIMIT 5;

SELECT 'Photos After Cleanup:' as message;
SELECT therapist_id, COUNT(*) as photos_count 
FROM therapist_photos 
GROUP BY therapist_id 
ORDER BY therapist_id 
LIMIT 5;
