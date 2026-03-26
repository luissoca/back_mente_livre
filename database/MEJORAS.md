# 📊 Resumen de Mejoras del Esquema de Base de Datos

## 🎯 Objetivo
Migrar el esquema de PostgreSQL (Supabase) a MySQL/MariaDB con un diseño normalizado, optimizado y siguiendo buenas prácticas.

---

## ✅ Mejoras Principales Implementadas

### 1. **Normalización de la Tabla `therapists`**

#### ❌ **ANTES** (Problema):
```sql
therapists (
  id, user_id, name, photo_url, friendly_photo_url, photo_position,
  price_public, price_university, price_corporate, price_international,
  experience_topics[], population_served[], ...
)
```
**Problemas:**
- Muchos campos mezclados
- Arrays de PostgreSQL no compatibles con MySQL
- Difícil agregar nuevos tipos de precios

#### ✅ **DESPUÉS** (Solución):
```sql
therapists (id, user_id, name, ...) -- Solo información principal
therapist_pricing (therapist_id, pricing_tier, price) -- Precios normalizados
therapist_photos (therapist_id, photo_type, photo_url) -- Fotos normalizadas
therapist_experience_topics (therapist_id, topic) -- Temas normalizados
therapist_population_served (therapist_id, population) -- Población normalizada
```

**Beneficios:**
- ✅ Separación de responsabilidades
- ✅ Fácil agregar nuevos tipos de precios
- ✅ Múltiples fotos por terapeuta
- ✅ Búsquedas eficientes en temas

---

### 2. **Normalización de la Tabla `appointments`**

#### ❌ **ANTES** (Problema):
```sql
appointments (
  id, therapist_id, user_id,
  patient_name, patient_email, patient_phone,
  contact_first_name, contact_last_name, contact_phone, -- DUPLICADO
  payment_method, amount_paid, payment_confirmed_at, ... -- MEZCLADO
)
```
**Problemas:**
- Información de contacto duplicada
- Información de pago mezclada con información de cita

#### ✅ **DESPUÉS** (Solución):
```sql
appointments (id, therapist_id, user_id, patient_contact_id, ...) -- Solo cita
patient_contacts (id, email, first_name, last_name, phone) -- Contactos normalizados
appointment_payments (appointment_id, original_price, discount_applied, ...) -- Pagos separados
```

**Beneficios:**
- ✅ Sin duplicación de datos
- ✅ Reutilización de contactos
- ✅ Mejor auditoría de pagos
- ✅ Separación de responsabilidades

---

### 3. **Eliminación de Arrays de PostgreSQL**

#### ❌ **ANTES**:
```sql
experience_topics TEXT[]  -- Array de PostgreSQL
population_served TEXT[]  -- Array de PostgreSQL
values TEXT[]             -- Array de PostgreSQL
```

#### ✅ **DESPUÉS**:
```sql
therapist_experience_topics (therapist_id, topic) -- Tabla relacionada
therapist_population_served (therapist_id, population) -- Tabla relacionada
site_content.values JSON -- JSON para valores simples
```

**Beneficios:**
- ✅ Compatible con MySQL
- ✅ Búsquedas eficientes
- ✅ Sin límite de elementos
- ✅ Mejor normalización

---

### 4. **Unificación de Tablas de Equipo**

#### ❌ **ANTES**:
```sql
team_members (...) -- Tabla para miembros institucionales
team_profiles (...) -- Tabla unificada (creada después)
```
**Problema:** Duplicación de funcionalidad

#### ✅ **DESPUÉS**:
```sql
team_profiles (
  member_type ENUM('clinical', 'institutional'),
  linked_therapist_id, -- NULL si es institutional
  ...
)
```
**Beneficio:** Una sola tabla para todo el equipo

---

### 5. **Sistema de Roles Mejorado**

#### ❌ **ANTES**:
```sql
user_roles (id, user_id, role) -- role como ENUM directo
```

#### ✅ **DESPUÉS**:
```sql
roles (id, name, description) -- Tabla de roles
user_roles (user_id, role_id) -- Relación normalizada
```
**Beneficios:**
- ✅ Fácil agregar nuevos roles
- ✅ Descripciones de roles
- ✅ Mejor escalabilidad

---

### 6. **Optimización de Índices**

#### Mejoras:
- ✅ Índices únicos para prevenir duplicados
- ✅ Índices compuestos para consultas frecuentes
- ✅ Índices en todas las foreign keys
- ✅ Índices en campos de búsqueda frecuente (email, status, etc.)

**Ejemplo:**
```sql
-- Búsqueda por email de paciente
INDEX idx_appointments_patient_email (patient_email)

-- Búsqueda por terapeuta y fecha
INDEX idx_appointments_therapist (therapist_id)
INDEX idx_appointments_date (appointment_date)
```

---

### 7. **Mejora de Integridad Referencial**

#### Cambios:
- ✅ Foreign keys con acciones apropiadas (CASCADE, SET NULL)
- ✅ Constraints para validación
- ✅ ENUMs para valores controlados
- ✅ Comentarios descriptivos en todas las tablas

**Ejemplo:**
```sql
FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE SET NULL
-- Si se elimina un usuario, las citas se mantienen pero sin user_id
```

---

## 📈 Comparativa de Estructura

### **Tablas Originales:** 12 tablas
### **Tablas Mejoradas:** 18 tablas (normalizadas)

**Incremento justificado por:**
- ✅ Mejor normalización
- ✅ Separación de responsabilidades
- ✅ Facilidad de mantenimiento
- ✅ Mejor rendimiento en consultas

---

## 🔄 Migración de Tipos de Datos

| PostgreSQL | MySQL | Nota |
|-----------|-------|------|
| `UUID` | `CHAR(36)` | Mantiene formato UUID |
| `TEXT[]` | Tabla relacionada | Mejor normalización |
| `JSONB` | `JSON` | Similar funcionalidad |
| `TIMESTAMP WITH TIME ZONE` | `DATETIME` | Zona horaria en aplicación |
| `ENUM` | `ENUM` | Compatible |

---

## 🚨 Consideraciones Importantes

### 1. **Índices Únicos Parciales**
- ❌ MySQL no soporta índices únicos parciales como PostgreSQL
- ✅ Solución: Validación en PHP para citas activas duplicadas

### 2. **Row Level Security (RLS)**
- ❌ PostgreSQL RLS no existe en MySQL
- ✅ Solución: Middleware de autenticación y autorización en PHP

### 3. **Funciones de Base de Datos**
- ❌ Funciones como `has_role()` en PostgreSQL
- ✅ Solución: Métodos en servicios PHP

### 4. **Triggers**
- ✅ Compatibles, pero sintaxis diferente
- ✅ Implementados en el esquema

---

## 📝 Próximos Pasos

1. ✅ **Esquema creado** - `database/schema.sql`
2. ⏳ **Ejecutar script** en MySQL
3. ⏳ **Crear servicios PHP** para reemplazar funciones de PostgreSQL
4. ⏳ **Implementar middleware** para RLS
5. ⏳ **Migrar datos** (si aplica)

---

## 🎓 Buenas Prácticas Aplicadas

- ✅ **Normalización 3NF** - Eliminación de redundancias
- ✅ **Nomenclatura consistente** - snake_case para tablas y columnas
- ✅ **Comentarios descriptivos** - Documentación en el esquema
- ✅ **Índices optimizados** - Para consultas frecuentes
- ✅ **Foreign keys** - Integridad referencial
- ✅ **Tipos de datos apropiados** - Optimización de espacio
- ✅ **Valores por defecto** - Datos consistentes
- ✅ **Timestamps automáticos** - Auditoría de cambios

---

## 📚 Referencias

- [Database Normalization](https://en.wikipedia.org/wiki/Database_normalization)
- [MySQL Best Practices](https://dev.mysql.com/doc/refman/8.0/en/optimization-indexes.html)
- [Foreign Key Constraints](https://dev.mysql.com/doc/refman/8.0/en/create-table-foreign-keys.html)
