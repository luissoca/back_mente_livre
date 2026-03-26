# Diseño de Base de Datos - Mente Livre

## 📋 Resumen de Mejoras y Normalización

Este documento explica las mejoras realizadas al esquema de base de datos, migrándolo de PostgreSQL (Supabase) a MySQL/MariaDB con un diseño normalizado y optimizado.

---

## 🔍 Problemas Identificados en el Esquema Original

### 1. **Tabla `therapists` sobrecargada**
   - **Problema**: Muchos campos mezclados (precios, fotos, información académica, etc.)
   - **Solución**: Separación en tablas relacionadas:
     - `therapist_pricing` - Precios por tipo de cuenta
     - `therapist_photos` - Fotos (profile y friendly)
     - `therapist_experience_topics` - Temas de experiencia (normalizado)
     - `therapist_population_served` - Población atendida (normalizado)

### 2. **Tabla `appointments` con información duplicada**
   - **Problema**: Información de contacto duplicada (patient_name, patient_email, contact_first_name, contact_last_name)
   - **Solución**: 
     - Tabla `patient_contacts` para normalizar información de contacto
     - Tabla `appointment_payments` separada para información de pago

### 3. **Arrays de PostgreSQL**
   - **Problema**: `experience_topics[]`, `population_served[]`, `values[]` no existen en MySQL
   - **Solución**: Tablas relacionadas normalizadas o JSON según el caso

### 4. **Tablas duplicadas**
   - **Problema**: `team_members` y `team_profiles` con funcionalidad similar
   - **Solución**: Solo `team_profiles` unificada con campo `member_type`

### 5. **UUIDs vs IDs**
   - **Problema**: PostgreSQL usa UUIDs nativos, MySQL necesita CHAR(36)
   - **Solución**: Usar CHAR(36) para UUIDs, mantener consistencia

---

## ✨ Mejoras Implementadas

### 1. **Normalización de Datos**

#### **Precios de Terapeutas**
```sql
-- ANTES: Campos en therapists
price_public, price_university, price_corporate, price_international

-- DESPUÉS: Tabla normalizada
therapist_pricing (therapist_id, pricing_tier, price, is_enabled)
```
**Beneficio**: Fácil agregar nuevos tipos de precios sin modificar la tabla principal.

#### **Fotos de Terapeutas**
```sql
-- ANTES: Campos en therapists
photo_url, friendly_photo_url, photo_position

-- DESPUÉS: Tabla normalizada
therapist_photos (therapist_id, photo_type, photo_url, photo_position)
```
**Beneficio**: Múltiples fotos por terapeuta, mejor organización.

#### **Temas de Experiencia**
```sql
-- ANTES: Array en PostgreSQL
experience_topics TEXT[]

-- DESPUÉS: Tabla relacionada
therapist_experience_topics (therapist_id, topic)
```
**Beneficio**: Búsquedas eficientes, sin límite de temas.

#### **Información de Pago**
```sql
-- ANTES: Campos en appointments
payment_method, amount_paid, payment_confirmed_at, etc.

-- DESPUÉS: Tabla separada
appointment_payments (appointment_id, original_price, discount_applied, final_price, ...)
```
**Beneficio**: Separación de responsabilidades, mejor auditoría.

### 2. **Optimizaciones de Índices**

- **Índices únicos** para prevenir duplicados
- **Índices compuestos** para consultas frecuentes
- **Índices en foreign keys** para mejor rendimiento

### 3. **Mejoras de Integridad**

- **Foreign keys** con acciones CASCADE/SET NULL apropiadas
- **Constraints** para validación de datos
- **ENUMs** para valores controlados
- **Comentarios** en todas las tablas y columnas

### 4. **Estructura de Roles**

```sql
-- Tabla de roles (normalizada)
roles (id, name, description)
user_roles (user_id, role_id) -- Muchos a muchos
```
**Beneficio**: Fácil agregar nuevos roles sin modificar código.

---

## 📊 Estructura de Tablas

### **Grupo 1: Autenticación y Usuarios**
- `users` - Usuarios del sistema
- `profiles` - Perfiles extendidos
- `roles` - Roles disponibles
- `user_roles` - Asignación de roles
- `email_classifications` - Clasificación de emails
- `email_domain_rules` - Reglas de dominios

### **Grupo 2: Terapeutas**
- `therapists` - Información principal
- `therapist_pricing` - Precios por tipo
- `therapist_photos` - Fotos
- `therapist_experience_topics` - Temas de experiencia
- `therapist_population_served` - Población atendida

### **Grupo 3: Horarios**
- `weekly_schedules` - Horarios recurrentes
- `weekly_schedule_overrides` - Excepciones por semana

### **Grupo 4: Citas y Pagos**
- `patient_contacts` - Contactos de pacientes
- `appointments` - Citas
- `appointment_payments` - Información de pago

### **Grupo 5: Promociones**
- `promo_codes` - Códigos promocionales
- `promo_code_uses` - Uso de códigos

### **Grupo 6: Contenido**
- `site_content` - Contenido institucional
- `team_profiles` - Perfiles del equipo

---

## 🔄 Cambios de PostgreSQL a MySQL

### **Tipos de Datos**
| PostgreSQL | MySQL |
|-----------|-------|
| `UUID` | `CHAR(36)` |
| `TEXT[]` | Tabla relacionada o `JSON` |
| `JSONB` | `JSON` |
| `TIMESTAMP WITH TIME ZONE` | `DATETIME` |
| `ENUM` | `ENUM` (similar) |

### **Funciones**
- **Triggers**: Convertidos a sintaxis MySQL
- **Funciones de base de datos**: Se implementarán en PHP (mejor práctica)
- **RLS (Row Level Security)**: Se maneja en la lógica de PHP con middleware

---

## 📝 Notas Importantes

### **UUIDs**
- Se mantienen como `CHAR(36)` para compatibilidad
- Se generan en PHP usando `ramsey/uuid` o similar
- Alternativa: Usar `BINARY(16)` para mejor rendimiento (requiere conversión)

### **Zona Horaria**
- Configurada a `-05:00` (Perú)
- Todas las fechas se almacenan en esta zona

### **Valores por Defecto**
- Se mantienen los valores por defecto del sistema original
- Se agregaron valores iniciales en `site_content` y `email_domain_rules`

### **Índices Únicos**
- `unique_active_appointment`: Previene citas duplicadas activas
- `unique_therapist_tier`: Un precio por tier por terapeuta
- `unique_user_role`: Un rol por usuario (evita duplicados)

---

## 🚀 Próximos Pasos

1. **Ejecutar el script** en la base de datos MySQL
2. **Crear funciones PHP** para reemplazar funciones de PostgreSQL:
   - `has_role()` - Verificar roles
   - `get_therapist_id_for_user()` - Obtener ID de terapeuta
3. **Implementar middleware** para RLS (Row Level Security)
4. **Migrar datos** existentes (si aplica)

---

## 📚 Referencias

- [MySQL Documentation](https://dev.mysql.com/doc/)
- [Database Normalization](https://en.wikipedia.org/wiki/Database_normalization)
- [Best Practices for MySQL](https://dev.mysql.com/doc/refman/8.0/en/optimization-indexes.html)
