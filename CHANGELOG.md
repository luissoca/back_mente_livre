# Changelog - Backend Mente Livre

## [1.0.0] - 2026-01-15

### ✅ Implementado

#### Base de Datos
- ✅ Migración completa de PostgreSQL a MySQL/MariaDB
- ✅ Esquema normalizado con 18 tablas
- ✅ Índices optimizados para consultas frecuentes
- ✅ Migraciones idempotentes con `ON DUPLICATE KEY UPDATE`
- ✅ Datos de producción migrados desde CSVs

#### Servicios Creados
- `AuthService` - Autenticación con JWT
- `TherapistService` - Lógica de terapeutas
- `AppointmentService` - Gestión de citas
- `UserService` - Gestión de usuarios
- `SiteContentService` - Contenido institucional
- `TeamProfileService` - Perfiles del equipo
- `PromoCodeService` - Códigos promocionales
- `WeeklyScheduleService` - Horarios semanales
- `RoleService` - Roles y permisos

#### Controladores Implementados
- `AuthController` - Login, refresh, logout
- `TherapistController` - CRUD de terapeutas
- `AppointmentController` - CRUD de citas con validación de disponibilidad
- `UserController` - Gestión de usuarios y perfiles
- `SiteContentController` - GET/PUT contenido institucional
- `TeamProfileController` - CRUD perfiles del equipo
- `PromoCodeController` - CRUD + validación de códigos
- `WeeklyScheduleController` - CRUD horarios con detección de conflictos
- `SwaggerController` - Generación automática de documentación

#### Middleware
- `CorsMiddleware` - Manejo de CORS
- `AuthMiddleware` - Validación de tokens JWT
- `RoleMiddleware` - Control de acceso por roles

#### Documentación
- ✅ Swagger/OpenAPI automático para todos los endpoints
- ✅ Swagger UI disponible en `/docs`
- ✅ Tipos y esquemas definidos para todas las entidades
- ✅ Ejemplos de uso en cada endpoint

#### Características Especiales
- ✅ Validación de disponibilidad de horarios (evita solapamientos)
- ✅ Validación de códigos promocionales con límites de uso
- ✅ Gestión automática de contactos de pacientes
- ✅ Soporte para múltiples precios por terapeuta (university, public, corporate)
- ✅ Sistema de roles (admin, therapist)
- ✅ Clasificación automática de emails (university_pe, international, etc.)

### 📋 Endpoints Disponibles (38+)

#### Autenticación (3)
- `POST /auth/login`
- `POST /auth/refresh`
- `POST /auth/logout`

#### Terapeutas (4)
- `GET /therapists`
- `GET /therapists/{id}`
- `POST /therapists` 🔒
- `PUT /therapists/{id}` 🔒

#### Horarios (4)
- `GET /therapists/{therapistId}/schedules`
- `POST /therapists/{therapistId}/schedules` 🔒
- `PUT /schedules/{id}` 🔒
- `DELETE /schedules/{id}` 🔒

#### Citas (5)
- `GET /appointments`
- `GET /appointments/{id}`
- `POST /appointments`
- `PUT /appointments/{id}` 🔒
- `DELETE /appointments/{id}` 🔒

#### Usuarios (3)
- `GET /users` 🔒
- `GET /users/{id}` 🔒
- `PUT /users/{id}` 🔒

#### Contenido del Sitio (2)
- `GET /site-content`
- `PUT /site-content` 🔒

#### Perfiles del Equipo (5)
- `GET /team-profiles`
- `GET /team-profiles/{id}`
- `POST /team-profiles` 🔒
- `PUT /team-profiles/{id}` 🔒
- `DELETE /team-profiles/{id}` 🔒

#### Códigos Promocionales (5)
- `GET /promo-codes` 🔒
- `GET /promo-codes/{id}` 🔒
- `POST /promo-codes` 🔒
- `PUT /promo-codes/{id}` 🔒
- `POST /promo-codes/validate`

#### Documentación (3)
- `GET /docs` - Swagger UI
- `GET /swagger.json` - Especificación OpenAPI
- `POST /swagger/generate` - Regenerar documentación

🔒 = Requiere autenticación

### 🔄 Cambios de Arquitectura

#### De Supabase a PHP
- **RLS (Row Level Security)** → Middleware de roles en PHP
- **Edge Functions (Deno)** → Endpoints PHP
- **Supabase Auth** → JWT en PHP
- **PostgreSQL** → MySQL/MariaDB
- **Supabase Storage** → Almacenamiento local planificado
- **Resend (emails)** → PHPMailer planificado

#### Normalizaciones de Base de Datos
- `therapists` → Dividido en: `therapists`, `therapist_pricing`, `therapist_photos`, `therapist_experience_topics`, `therapist_population_served`
- `appointments` → Dividido en: `appointments`, `patient_contacts`, `appointment_payments`
- `team_members` + `team_profiles` → Unificado en `team_profiles`
- Arrays PostgreSQL → Tablas relacionadas
- `user_roles` → Normalizado con tabla `roles`

### 📦 Dependencias
- PHP 8.2+
- MariaDB 10.5+
- Composer packages:
  - `firebase/php-jwt` - Autenticación JWT
  - `zircote/swagger-php` - Generación de OpenAPI
  - `vlucas/phpdotenv` - Variables de entorno

### 🔜 Próximas Mejoras Sugeridas
- [ ] Sistema de envío de emails con PHPMailer
- [ ] Almacenamiento de imágenes (local o Cloudinary)
- [ ] Rate limiting para APIs públicas
- [ ] Cache con Redis
- [ ] Tests unitarios y de integración
- [ ] CI/CD pipeline
- [ ] Logs estructurados
- [ ] Webhooks para notificaciones
