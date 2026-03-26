# Guía de Migraciones de Base de Datos

Esta carpeta contiene todas las migraciones de la base de datos del proyecto.

## Archivos de Migración

### 1. `schema.sql`
**Propósito**: Migración estructural inicial (idempotente)

Este archivo contiene:
- Definición de 18 tablas normalizadas
- Índices optimizados para rendimiento
- Foreign keys para integridad referencial
- Datos iniciales (roles, site_content, email_domain_rules)
- **Idempotencia**: Usa `CREATE TABLE IF NOT EXISTS` y `INSERT ... ON DUPLICATE KEY UPDATE`

**Cuándo ejecutar**: Primera vez al configurar el proyecto o al actualizar la estructura.

```bash
docker exec -i mentelivre_db mysql -umentelivre_user -pmentelivre_pass mentelivre_db < backend_mente_livre/database/schema.sql
```

### 2. `001_seed_production_data.sql`
**Propósito**: Migración de datos de producción desde Supabase/CSV

Este archivo contiene:
- Datos migrados de los CSVs exportados desde Supabase
- Usuarios, perfiles, terapeutas, citas, horarios, códigos promo, etc.
- **Idempotencia**: Usa `INSERT ... ON DUPLICATE KEY UPDATE`

**IMPORTANTE**: 
- Las contraseñas son temporales y deben ser reseteadas
- Revise los emails y datos de contacto antes de usar en producción

**Cuándo ejecutar**: Después de `schema.sql` para cargar datos iniciales.

```bash
docker exec -i mentelivre_db mysql -umentelivre_user -pmentelivre_pass mentelivre_db < backend_mente_livre/database/001_seed_production_data.sql
```

### 3. `002_normalize_image_urls.sql`
**Propósito**: Normalizar URLs de imágenes

Este archivo actualiza:
- `therapist_photos.photo_url`: Convierte URLs de Supabase a rutas relativas
- `team_profiles.friendly_photo_url`: Similar normalización

**Cuándo ejecutar**: Después de `001_seed_production_data.sql` si las URLs necesitan ser normalizadas.

```bash
Get-Content backend_mente_livre/database/002_normalize_image_urls.sql | docker exec -i mentelivre_db mysql -umentelivre_user -pmentelivre_pass mentelivre_db
```

### 4. `003_remove_duplicates.sql`
**Propósito**: Eliminar registros duplicados

Este archivo limpia:
- `therapist_experience_topics`: Elimina duplicados por (therapist_id, topic)
- `therapist_photos`: Elimina duplicados por (therapist_id, photo_url)

**Cuándo ejecutar**: Si se detectan duplicados en los datos (ej: después de importaciones múltiples).

```bash
Get-Content backend_mente_livre/database/003_remove_duplicates.sql | docker exec -i mentelivre_db mysql -umentelivre_user -pmentelivre_pass mentelivre_db
```

### 5. `004_add_password_reset_tokens.sql`
**Propósito**: Tabla para recuperación de contraseña

Crea la tabla `password_reset_tokens` con:
- `user_id`, `token`, `expires_at`, `used_at`
- Índices y FK a `users`

**Cuándo ejecutar**: Después del schema (o después de 003) para habilitar “olvidé mi contraseña”.

```bash
docker exec -i mentelivre_db mysql -umentelivre_user -pmentelivre_pass mentelivre_db < backend_mente_livre/database/004_add_password_reset_tokens.sql
```

### 6. `005_add_promo_code_id_to_appointments.sql`
**Propósito**: Añadir relación de citas con códigos promocionales (bases existentes)

Añade a la tabla `appointments`:
- Columna `promo_code_id` (FK a `promo_codes`)
- Índice e integridad referencial

**Idempotente**: Solo añade la columna si no existe (bases creadas con un schema antiguo sin esta columna).

**Cuándo ejecutar**: En bases de datos que ya tenían `appointments` sin `promo_code_id`. No es necesario si la base se creó desde cero con el `schema.sql` actual.

```bash
docker exec -i mentelivre_db mysql -umentelivre_user -pmentelivre_pass mentelivre_db < backend_mente_livre/database/005_add_promo_code_id_to_appointments.sql
```

### 7. `006_update_site_content_purpose.sql`
**Propósito**: Rellenar el campo Propósito del contenido institucional

La columna `purpose` ya existe en `site_content` (TEXT NULL). Los INSERT iniciales no la rellenaban, por eso la API devuelve `"purpose": null`. Esta migración actualiza el registro existente con un texto por defecto solo cuando `purpose` es NULL (idempotente).

**Cuándo ejecutar**: Cuando quieras que el endpoint `/site-content` devuelva un valor en `purpose` para la página Conócenos. Después puedes cambiarlo desde el panel de administración.

```bash
docker exec -i mentelivre_db mysql -umentelivre_user -pmentelivre_pass mentelivre_db < backend_mente_livre/database/006_update_site_content_purpose.sql
```

## Orden de Ejecución Recomendado

Para una configuración completa desde cero:

```bash
# 1. Estructura de tablas e índices
docker exec -i mentelivre_db mysql -umentelivre_user -pmentelivre_pass mentelivre_db < backend_mente_livre/database/schema.sql

# 2. Datos de producción
docker exec -i mentelivre_db mysql -umentelivre_user -pmentelivre_pass mentelivre_db < backend_mente_livre/database/001_seed_production_data.sql

# 3. Normalizar URLs de imágenes (solo si es necesario)
Get-Content backend_mente_livre/database/002_normalize_image_urls.sql | docker exec -i mentelivre_db mysql -umentelivre_user -pmentelivre_pass mentelivre_db

# 4. Limpiar duplicados (solo si es necesario)
Get-Content backend_mente_livre/database/003_remove_duplicates.sql | docker exec -i mentelivre_db mysql -umentelivre_user -pmentelivre_pass mentelivre_db

# 5. Tokens de recuperación de contraseña
docker exec -i mentelivre_db mysql -umentelivre_user -pmentelivre_pass mentelivre_db < backend_mente_livre/database/004_add_password_reset_tokens.sql

# 6. Añadir promo_code_id a appointments (solo para bases existentes creadas con schema antiguo)
docker exec -i mentelivre_db mysql -umentelivre_user -pmentelivre_pass mentelivre_db < backend_mente_livre/database/005_add_promo_code_id_to_appointments.sql

# 7. Rellenar purpose en site_content (opcional; para que Conócenos muestre Propósito)
docker exec -i mentelivre_db mysql -umentelivre_user -pmentelivre_pass mentelivre_db < backend_mente_livre/database/006_update_site_content_purpose.sql
```

## Checklist Pre-Migración

Antes de ejecutar cualquier migración en producción:

- [ ] Hacer backup completo de la base de datos
- [ ] Probar las migraciones en un ambiente de desarrollo
- [ ] Revisar los logs de cada migración
- [ ] Verificar la integridad de los datos después de cada paso
- [ ] Documentar cualquier error o advertencia

## Verificación Post-Migración

Después de ejecutar las migraciones, verificar:

```sql
-- Verificar conteo de tablas
SELECT COUNT(*) as table_count FROM information_schema.tables 
WHERE table_schema = 'mentelivre_db';

-- Verificar conteo de registros principales
SELECT 
  (SELECT COUNT(*) FROM users) as users,
  (SELECT COUNT(*) FROM therapists) as therapists,
  (SELECT COUNT(*) FROM appointments) as appointments,
  (SELECT COUNT(*) FROM weekly_schedules) as schedules;

-- Verificar que no hay duplicados
SELECT therapist_id, topic, COUNT(*) 
FROM therapist_experience_topics 
GROUP BY therapist_id, topic 
HAVING COUNT(*) > 1;

SELECT therapist_id, photo_url, COUNT(*) 
FROM therapist_photos 
GROUP BY therapist_id, photo_url 
HAVING COUNT(*) > 1;
```

## Troubleshooting

### Error: "Duplicate entry"
Si encuentras errores de duplicados al ejecutar `001_seed_production_data.sql`:
1. Verifica que `schema.sql` se haya ejecutado correctamente
2. Ejecuta `003_remove_duplicates.sql` para limpiar duplicados existentes
3. Vuelve a ejecutar la migración de datos

### Error: "Unknown column"
Si encuentras errores de columnas desconocidas:
1. Asegúrate de que `schema.sql` se ejecutó completamente
2. Verifica que la versión del script de migración coincida con el schema

### Imágenes no se cargan
Si las imágenes de terapeutas o equipo no se muestran:
1. Ejecuta `002_normalize_image_urls.sql`
2. Verifica que las imágenes existan en `backend_mente_livre/public/uploads/`
3. Revisa los logs del backend para errores de `ImageUrlHelper`

## Notas Adicionales

- Todas las migraciones son idempotentes y pueden re-ejecutarse sin problemas
- Los IDs de Supabase (UUIDs) se mantienen para preservar relaciones
- Las contraseñas en `001_seed_production_data.sql` son temporales
- Se recomienda cambiar todas las contraseñas después de la migración
