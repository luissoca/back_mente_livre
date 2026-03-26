-- 1. Actualizar la tabla de usuarios
ALTER TABLE "users" 
ADD COLUMN "google_sub" VARCHAR(255) UNIQUE;

-- 2. Actualizar la tabla de perfiles
ALTER TABLE "profiles" 
ADD COLUMN "avatar_url" VARCHAR(512);

-- Opcional: Agregar comentarios para documentación
COMMENT ON COLUMN "users"."google_sub" IS 'ID único de Google (Subject) para OAuth';
COMMENT ON COLUMN "profiles"."avatar_url" IS 'URL de la imagen de perfil del usuario';