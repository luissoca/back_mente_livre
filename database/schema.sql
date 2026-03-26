-- ============================================================================
-- ESQUEMA DE BASE DE DATOS - MENTE LIVRE
-- MySQL/MariaDB - Diseño Normalizado y Optimizado
-- 
-- IMPORTANTE: Este script es IDEMPOTENTE
-- Puede ejecutarse múltiples veces de forma segura:
-- - Las tablas usan CREATE TABLE IF NOT EXISTS
-- - Los INSERT usan ON DUPLICATE KEY UPDATE (actualiza si ya existe)
-- 
-- Ejecutar: mysql -u usuario -p base_de_datos < schema.sql
-- ============================================================================

-- Configuración inicial
SET FOREIGN_KEY_CHECKS = 0;
SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
SET time_zone = "-05:00"; -- Zona horaria de Perú

-- ============================================================================
-- TABLAS DE USUARIOS Y AUTENTICACIÓN
-- ============================================================================

-- Tabla de usuarios (reemplaza auth.users de Supabase)
CREATE TABLE IF NOT EXISTS `users` (
  `id` CHAR(36) NOT NULL PRIMARY KEY COMMENT 'UUID del usuario',
  `email` VARCHAR(255) NOT NULL UNIQUE COMMENT 'Email único del usuario',
  `password_hash` VARCHAR(255) NOT NULL COMMENT 'Hash de la contraseña (bcrypt)',
  `email_verified` BOOLEAN NOT NULL DEFAULT FALSE COMMENT 'Email verificado',
  `email_verified_at` DATETIME NULL COMMENT 'Fecha de verificación de email',
  `is_active` BOOLEAN NOT NULL DEFAULT TRUE COMMENT 'Usuario activo',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX `idx_users_email` (`email`),
  INDEX `idx_users_active` (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Usuarios del sistema';

-- Tabla de perfiles (información extendida del usuario)
CREATE TABLE IF NOT EXISTS `profiles` (
  `id` CHAR(36) NOT NULL PRIMARY KEY COMMENT 'UUID - FK a users.id',
  `user_id` CHAR(36) NOT NULL UNIQUE COMMENT 'FK a users.id',
  `first_name` VARCHAR(100) NULL COMMENT 'Nombre',
  `last_name` VARCHAR(100) NULL COMMENT 'Apellido',
  `full_name` VARCHAR(200) NULL COMMENT 'Nombre completo (calculado)',
  `phone` VARCHAR(20) NULL COMMENT 'Teléfono de contacto',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
  INDEX `idx_profiles_user_id` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Perfiles extendidos de usuarios';

-- Tabla de roles
CREATE TABLE IF NOT EXISTS `roles` (
  `id` TINYINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `name` VARCHAR(50) NOT NULL UNIQUE COMMENT 'Nombre del rol (admin, therapist)',
  `description` VARCHAR(255) NULL COMMENT 'Descripción del rol',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_roles_name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Roles del sistema';

-- Tabla de asignación de roles a usuarios
CREATE TABLE IF NOT EXISTS `user_roles` (
  `id` CHAR(36) NOT NULL PRIMARY KEY COMMENT 'UUID',
  `user_id` CHAR(36) NOT NULL COMMENT 'FK a users.id',
  `role_id` TINYINT UNSIGNED NOT NULL COMMENT 'FK a roles.id',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`role_id`) REFERENCES `roles`(`id`) ON DELETE CASCADE,
  UNIQUE KEY `unique_user_role` (`user_id`, `role_id`),
  INDEX `idx_user_roles_user` (`user_id`),
  INDEX `idx_user_roles_role` (`role_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Asignación de roles a usuarios';

-- Tabla de clasificación de emails (para determinar tipo de cuenta)
CREATE TABLE IF NOT EXISTS `email_classifications` (
  `id` CHAR(36) NOT NULL PRIMARY KEY COMMENT 'UUID',
  `user_id` CHAR(36) NULL COMMENT 'FK a users.id (NULL si es regla general)',
  `email_domain` VARCHAR(255) NOT NULL COMMENT 'Dominio de email',
  `account_type` ENUM('university_pe', 'university_international', 'corporate', 'public') NOT NULL DEFAULT 'public',
  `is_university_verified` BOOLEAN NOT NULL DEFAULT FALSE,
  `graduate_recent` BOOLEAN NOT NULL DEFAULT FALSE,
  `graduate_until` DATE NULL COMMENT 'Fecha hasta cuando es estudiante reciente',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE SET NULL,
  INDEX `idx_email_classifications_domain` (`email_domain`),
  INDEX `idx_email_classifications_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Clasificación de emails por dominio';

-- Tabla de reglas de dominios de email (whitelist/blacklist)
CREATE TABLE IF NOT EXISTS `email_domain_rules` (
  `id` CHAR(36) NOT NULL PRIMARY KEY COMMENT 'UUID',
  `domain` VARCHAR(255) NOT NULL UNIQUE COMMENT 'Dominio de email',
  `rule_type` ENUM('whitelist', 'blacklist') NOT NULL COMMENT 'Tipo de regla',
  `note` TEXT NULL COMMENT 'Nota sobre la regla',
  `is_active` BOOLEAN NOT NULL DEFAULT TRUE,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX `idx_email_domain_rules_domain` (`domain`),
  INDEX `idx_email_domain_rules_active` (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Reglas de dominios de email para clasificación';

-- ============================================================================
-- TABLAS DE TERAPEUTAS
-- ============================================================================

-- Tabla principal de terapeutas
CREATE TABLE IF NOT EXISTS `therapists` (
  `id` CHAR(36) NOT NULL PRIMARY KEY COMMENT 'UUID',
  `user_id` CHAR(36) NULL COMMENT 'FK a users.id (NULL si no tiene cuenta)',
  `name` VARCHAR(200) NOT NULL COMMENT 'Nombre completo del terapeuta',
  `university` VARCHAR(255) NOT NULL COMMENT 'Universidad',
  `academic_credentials` TEXT NULL COMMENT 'Credenciales académicas',
  `age` INT UNSIGNED NULL COMMENT 'Edad',
  `years_experience` INT UNSIGNED NULL COMMENT 'Años de experiencia',
  `role_title` VARCHAR(100) NOT NULL DEFAULT 'Psicólogo/a' COMMENT 'Título del rol',
  `specialty` VARCHAR(255) NULL COMMENT 'Especialidad',
  `therapeutic_approach` TEXT NULL COMMENT 'Enfoque terapéutico',
  `short_description` VARCHAR(300) NULL COMMENT 'Descripción corta (max 300 caracteres)',
  `public_bio` TEXT NULL COMMENT 'Biografía pública para página Conócenos',
  `modality` VARCHAR(50) NOT NULL DEFAULT 'Online' COMMENT 'Modalidad de atención',
  `availability_schedule` TEXT NULL COMMENT 'Horario de disponibilidad (texto libre)',
  `is_active` BOOLEAN NOT NULL DEFAULT TRUE COMMENT 'Terapeuta activo',
  `is_visible_in_about` BOOLEAN NOT NULL DEFAULT FALSE COMMENT 'Visible en página Conócenos',
  `hourly_rate` DECIMAL(10,2) NOT NULL COMMENT 'Tarifa por hora (precio base)',
  `field_visibility` JSON NULL COMMENT 'Control de visibilidad de campos (JSON)',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE SET NULL,
  INDEX `idx_therapists_user` (`user_id`),
  INDEX `idx_therapists_active` (`is_active`),
  INDEX `idx_therapists_visible_about` (`is_visible_in_about`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Terapeutas del sistema';

-- Tabla de precios por tipo de cuenta (normalizada)
CREATE TABLE IF NOT EXISTS `therapist_pricing` (
  `id` CHAR(36) NOT NULL PRIMARY KEY COMMENT 'UUID',
  `therapist_id` CHAR(36) NOT NULL COMMENT 'FK a therapists.id',
  `pricing_tier` ENUM('university_pe', 'university_international', 'corporate', 'public') NOT NULL,
  `price` DECIMAL(10,2) NOT NULL COMMENT 'Precio para este tier',
  `is_enabled` BOOLEAN NOT NULL DEFAULT TRUE COMMENT 'Precio habilitado',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (`therapist_id`) REFERENCES `therapists`(`id`) ON DELETE CASCADE,
  UNIQUE KEY `unique_therapist_tier` (`therapist_id`, `pricing_tier`),
  INDEX `idx_therapist_pricing_therapist` (`therapist_id`),
  INDEX `idx_therapist_pricing_tier` (`pricing_tier`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Precios de terapeutas por tipo de cuenta';

-- Tabla de temas de experiencia (normalizada - reemplaza array)
CREATE TABLE IF NOT EXISTS `therapist_experience_topics` (
  `id` CHAR(36) NOT NULL PRIMARY KEY COMMENT 'UUID',
  `therapist_id` CHAR(36) NOT NULL COMMENT 'FK a therapists.id',
  `topic` VARCHAR(255) NOT NULL COMMENT 'Tema de experiencia',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`therapist_id`) REFERENCES `therapists`(`id`) ON DELETE CASCADE,
  INDEX `idx_therapist_topics_therapist` (`therapist_id`),
  INDEX `idx_therapist_topics_topic` (`topic`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Temas de experiencia de terapeutas';

-- Tabla de población atendida (normalizada - reemplaza array)
CREATE TABLE IF NOT EXISTS `therapist_population_served` (
  `id` CHAR(36) NOT NULL PRIMARY KEY COMMENT 'UUID',
  `therapist_id` CHAR(36) NOT NULL COMMENT 'FK a therapists.id',
  `population` VARCHAR(255) NOT NULL COMMENT 'Población atendida',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`therapist_id`) REFERENCES `therapists`(`id`) ON DELETE CASCADE,
  INDEX `idx_therapist_population_therapist` (`therapist_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Población atendida por terapeutas';

-- Tabla de imágenes/fotos de terapeutas (normalizada)
CREATE TABLE IF NOT EXISTS `therapist_photos` (
  `id` CHAR(36) NOT NULL PRIMARY KEY COMMENT 'UUID',
  `therapist_id` CHAR(36) NOT NULL COMMENT 'FK a therapists.id',
  `photo_type` ENUM('profile', 'friendly') NOT NULL DEFAULT 'profile' COMMENT 'Tipo de foto: profile=agenda/tarjetas, friendly=Conócenos',
  `photo_url` VARCHAR(500) NOT NULL COMMENT 'URL de la foto',
  `photo_position` VARCHAR(50) NOT NULL DEFAULT '50% 20%' COMMENT 'Posición focal de la imagen (CSS)',
  `is_active` BOOLEAN NOT NULL DEFAULT TRUE,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (`therapist_id`) REFERENCES `therapists`(`id`) ON DELETE CASCADE,
  INDEX `idx_therapist_photos_therapist` (`therapist_id`),
  INDEX `idx_therapist_photos_type` (`photo_type`),
  INDEX `idx_therapist_photos_active` (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Fotos de terapeutas';

-- ============================================================================
-- TABLAS DE HORARIOS
-- ============================================================================

-- Tabla de horarios semanales recurrentes
CREATE TABLE IF NOT EXISTS `weekly_schedules` (
  `id` CHAR(36) NOT NULL PRIMARY KEY COMMENT 'UUID',
  `therapist_id` CHAR(36) NOT NULL COMMENT 'FK a therapists.id',
  `day_of_week` TINYINT UNSIGNED NOT NULL COMMENT 'Día de la semana (1=Lunes, 7=Domingo)',
  `start_time` TIME NOT NULL COMMENT 'Hora de inicio',
  `end_time` TIME NOT NULL COMMENT 'Hora de fin',
  `is_active` BOOLEAN NOT NULL DEFAULT TRUE,
  `updated_by_role` VARCHAR(50) NULL COMMENT 'Rol que hizo la última actualización',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (`therapist_id`) REFERENCES `therapists`(`id`) ON DELETE CASCADE,
  UNIQUE KEY `unique_therapist_day_time` (`therapist_id`, `day_of_week`, `start_time`),
  INDEX `idx_weekly_schedules_therapist` (`therapist_id`),
  INDEX `idx_weekly_schedules_day` (`day_of_week`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Horarios semanales recurrentes de terapeutas';

-- Tabla de excepciones de horarios (horarios específicos por semana)
CREATE TABLE IF NOT EXISTS `weekly_schedule_overrides` (
  `id` CHAR(36) NOT NULL PRIMARY KEY COMMENT 'UUID',
  `therapist_id` CHAR(36) NOT NULL COMMENT 'FK a therapists.id',
  `week_start_date` DATE NOT NULL COMMENT 'Lunes de la semana',
  `day_of_week` TINYINT UNSIGNED NOT NULL COMMENT 'Día de la semana (1=Lunes, 7=Domingo)',
  `start_time` TIME NOT NULL,
  `end_time` TIME NOT NULL,
  `is_active` BOOLEAN NOT NULL DEFAULT TRUE,
  `updated_by_role` VARCHAR(50) NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (`therapist_id`) REFERENCES `therapists`(`id`) ON DELETE CASCADE,
  UNIQUE KEY `unique_therapist_week_day_time` (`therapist_id`, `week_start_date`, `day_of_week`, `start_time`),
  INDEX `idx_schedule_overrides_therapist_week` (`therapist_id`, `week_start_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Excepciones de horarios por semana específica';

-- ============================================================================
-- TABLAS DE CITAS Y PAGOS
-- ============================================================================

-- Tabla de información de contacto de pacientes (normalizada)
CREATE TABLE IF NOT EXISTS `patient_contacts` (
  `id` CHAR(36) NOT NULL PRIMARY KEY COMMENT 'UUID',
  `email` VARCHAR(255) NOT NULL COMMENT 'Email del paciente',
  `first_name` VARCHAR(100) NULL,
  `last_name` VARCHAR(100) NULL,
  `full_name` VARCHAR(200) NOT NULL COMMENT 'Nombre completo',
  `phone` VARCHAR(20) NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX `idx_patient_contacts_email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Información de contacto de pacientes';

-- Tabla de códigos promocionales (creada antes de appointments por FK en appointments.promo_code_id)
CREATE TABLE IF NOT EXISTS `promo_codes` (
  `id` CHAR(36) NOT NULL PRIMARY KEY COMMENT 'UUID',
  `code` VARCHAR(50) NOT NULL UNIQUE COMMENT 'Código promocional',
  `is_active` BOOLEAN NOT NULL DEFAULT TRUE,
  `discount_percent` TINYINT UNSIGNED NOT NULL COMMENT 'Porcentaje de descuento (1-100)',
  `base_price` DECIMAL(10,2) NOT NULL DEFAULT 25.00 COMMENT 'Precio base para aplicar descuento',
  `max_uses_total` INT UNSIGNED NULL COMMENT 'Máximo de usos totales (NULL = ilimitado)',
  `max_uses_per_user` INT UNSIGNED NOT NULL DEFAULT 1 COMMENT 'Máximo de usos por usuario',
  `max_sessions` TINYINT UNSIGNED NOT NULL DEFAULT 1 COMMENT 'Máximo de sesiones (1-4)',
  `valid_from` DATETIME NULL COMMENT 'Válido desde',
  `valid_until` DATETIME NULL COMMENT 'Válido hasta',
  `uses_count` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Contador de usos',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX `idx_promo_codes_code` (`code`),
  INDEX `idx_promo_codes_active` (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Códigos promocionales';

-- Tabla de paquetes de sesiones configurables por admin
CREATE TABLE IF NOT EXISTS `session_packages` (
  `id` CHAR(36) NOT NULL PRIMARY KEY COMMENT 'UUID',
  `name` VARCHAR(100) NOT NULL COMMENT 'Nombre del paquete',
  `session_count` TINYINT UNSIGNED NOT NULL COMMENT 'Número de sesiones en el paquete',
  `discount_percent` TINYINT UNSIGNED NOT NULL COMMENT 'Porcentaje de descuento base',
  `is_active` BOOLEAN NOT NULL DEFAULT TRUE COMMENT 'Paquete activo para compra',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX `idx_session_packages_active` (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Configuración de paquetes de sesiones';

-- Tabla de paquetes comprados por pacientes
CREATE TABLE IF NOT EXISTS `patient_packages` (
  `id` CHAR(36) NOT NULL PRIMARY KEY COMMENT 'UUID',
  `package_id` CHAR(36) NOT NULL COMMENT 'FK a session_packages.id',
  `therapist_id` CHAR(36) NOT NULL COMMENT 'FK a therapists.id',
  `user_id` CHAR(36) NULL COMMENT 'FK a users.id (NULL si no está autenticado)',
  `patient_email` VARCHAR(255) NOT NULL COMMENT 'Email del paciente',
  `total_sessions` TINYINT UNSIGNED NOT NULL COMMENT 'Total de sesiones compradas',
  `used_sessions` TINYINT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Sesiones agendadas/usadas',
  `status` ENUM('active', 'completed', 'cancelled') NOT NULL DEFAULT 'active',
  `total_price_paid` DECIMAL(10,2) NOT NULL COMMENT 'Precio total pagado por el paquete',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (`package_id`) REFERENCES `session_packages`(`id`) ON DELETE RESTRICT,
  FOREIGN KEY (`therapist_id`) REFERENCES `therapists`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE SET NULL,
  INDEX `idx_patient_packages_user` (`user_id`),
  INDEX `idx_patient_packages_email` (`patient_email`),
  INDEX `idx_patient_packages_therapist` (`therapist_id`),
  INDEX `idx_patient_packages_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Paquetes de sesiones comprados por pacientes';

-- Tabla principal de citas
CREATE TABLE IF NOT EXISTS `appointments` (
  `id` CHAR(36) NOT NULL PRIMARY KEY COMMENT 'UUID',
  `therapist_id` CHAR(36) NOT NULL COMMENT 'FK a therapists.id',
  `user_id` CHAR(36) NULL COMMENT 'FK a users.id (NULL si no está autenticado)',
  `patient_contact_id` CHAR(36) NULL COMMENT 'FK a patient_contacts.id',
  `patient_email` VARCHAR(255) NOT NULL COMMENT 'Email usado para la cita (puede diferir del user_id)',
  `patient_name` VARCHAR(200) NOT NULL COMMENT 'Nombre del paciente',
  `patient_phone` VARCHAR(20) NULL COMMENT 'Teléfono del paciente',
  `consultation_reason` TEXT NULL COMMENT 'Motivo de consulta',
  `appointment_date` DATE NOT NULL COMMENT 'Fecha de la cita',
  `start_time` TIME NOT NULL COMMENT 'Hora de inicio',
  `end_time` TIME NOT NULL COMMENT 'Hora de fin',
  `status` ENUM('pending', 'confirmed', 'completed', 'cancelled', 'pending_payment', 'payment_review') NOT NULL DEFAULT 'pending',
  `pricing_tier` ENUM('university_pe', 'university_international', 'corporate', 'public') NULL COMMENT 'Tier de precio usado',
  `promo_code_id` CHAR(36) NULL COMMENT 'FK a promo_codes.id',
  `email_used` VARCHAR(255) NULL COMMENT 'Email usado para determinar el pricing tier',
  `patient_package_id` CHAR(36) NULL COMMENT 'FK a patient_packages.id (si pertenece a un paquete)',
  `notes` TEXT NULL COMMENT 'Notas adicionales',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (`therapist_id`) REFERENCES `therapists`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE SET NULL,
  FOREIGN KEY (`patient_contact_id`) REFERENCES `patient_contacts`(`id`) ON DELETE SET NULL,
  FOREIGN KEY (`promo_code_id`) REFERENCES `promo_codes`(`id`) ON DELETE SET NULL,
  FOREIGN KEY (`patient_package_id`) REFERENCES `patient_packages`(`id`) ON DELETE SET NULL,
  -- Nota: La validación de citas duplicadas activas se maneja en PHP
  -- ya que MySQL no soporta índices únicos parciales como PostgreSQL
  UNIQUE KEY `unique_appointment_slot` (`therapist_id`, `appointment_date`, `start_time`) 
    COMMENT 'Previene múltiples citas en el mismo slot (validación adicional en PHP para status)',
  INDEX `idx_appointments_therapist` (`therapist_id`),
  INDEX `idx_appointments_user` (`user_id`),
  INDEX `idx_appointments_date` (`appointment_date`),
  INDEX `idx_appointments_status` (`status`),
  INDEX `idx_appointments_patient_email` (`patient_email`),
  INDEX `idx_appointments_promo_code` (`promo_code_id`),
  INDEX `idx_appointments_patient_package` (`patient_package_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Citas/agendamientos';

-- Tabla de información de pago (normalizada - separada de appointments)
CREATE TABLE IF NOT EXISTS `appointment_payments` (
  `id` CHAR(36) NOT NULL PRIMARY KEY COMMENT 'UUID',
  `appointment_id` CHAR(36) NOT NULL UNIQUE COMMENT 'FK a appointments.id',
  `original_price` DECIMAL(10,2) NOT NULL COMMENT 'Precio original',
  `discount_applied` DECIMAL(10,2) NOT NULL DEFAULT 0.00 COMMENT 'Descuento aplicado',
  `final_price` DECIMAL(10,2) NOT NULL COMMENT 'Precio final',
  `amount_paid` DECIMAL(10,2) NULL COMMENT 'Monto pagado',
  `payment_method` VARCHAR(50) NOT NULL DEFAULT 'Yape/Plin' COMMENT 'Método de pago',
  `payment_confirmed_at` DATETIME NULL COMMENT 'Fecha de confirmación de pago',
  `payment_confirmed_by` CHAR(36) NULL COMMENT 'FK a users.id (quien confirmó)',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (`appointment_id`) REFERENCES `appointments`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`payment_confirmed_by`) REFERENCES `users`(`id`) ON DELETE SET NULL,
  INDEX `idx_appointment_payments_appointment` (`appointment_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Información de pago de citas';

-- Tabla de uso de códigos promocionales
CREATE TABLE IF NOT EXISTS `promo_code_uses` (
  `id` CHAR(36) NOT NULL PRIMARY KEY COMMENT 'UUID',
  `promo_code_id` CHAR(36) NOT NULL COMMENT 'FK a promo_codes.id',
  `appointment_id` CHAR(36) NULL COMMENT 'FK a appointments.id',
  `user_email` VARCHAR(255) NOT NULL COMMENT 'Email del usuario que usó el código',
  `discount_applied` DECIMAL(10,2) NOT NULL COMMENT 'Descuento aplicado',
  `final_amount` DECIMAL(10,2) NOT NULL COMMENT 'Monto final',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`promo_code_id`) REFERENCES `promo_codes`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`appointment_id`) REFERENCES `appointments`(`id`) ON DELETE SET NULL,
  INDEX `idx_promo_code_uses_code` (`promo_code_id`),
  INDEX `idx_promo_code_uses_appointment` (`appointment_id`),
  INDEX `idx_promo_code_uses_email` (`user_email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Registro de uso de códigos promocionales';

-- ============================================================================
-- TABLAS DE CONTENIDO INSTITUCIONAL
-- ============================================================================

-- Tabla de contenido del sitio (CMS)
CREATE TABLE IF NOT EXISTS `site_content` (
  `id` CHAR(36) NOT NULL PRIMARY KEY COMMENT 'UUID',
  `about_title` VARCHAR(255) NOT NULL DEFAULT '¿Quiénes Somos?' COMMENT 'Título de la sección',
  `about_intro` TEXT NOT NULL COMMENT 'Introducción de la sección',
  `mission` TEXT NOT NULL COMMENT 'Misión',
  `vision` TEXT NOT NULL COMMENT 'Visión',
  `approach` TEXT NOT NULL COMMENT 'Enfoque',
  `purpose` TEXT NULL COMMENT 'Propósito',
  `values` JSON NULL COMMENT 'Valores (array JSON)',
  `updated_by` CHAR(36) NULL COMMENT 'FK a users.id (quien actualizó)',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (`updated_by`) REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Contenido institucional del sitio';

-- Tabla unificada de perfiles del equipo (Conócenos)
CREATE TABLE IF NOT EXISTS `team_profiles` (
  `id` CHAR(36) NOT NULL PRIMARY KEY COMMENT 'UUID',
  `member_type` ENUM('clinical', 'institutional') NOT NULL COMMENT 'Tipo: clinical=terapeuta, institutional=otro',
  `linked_therapist_id` CHAR(36) NULL COMMENT 'FK a therapists.id (si es clinical)',
  `full_name` VARCHAR(200) NOT NULL COMMENT 'Nombre completo',
  `public_role_title` VARCHAR(100) NOT NULL COMMENT 'Título público del rol',
  `public_bio` TEXT NULL COMMENT 'Biografía pública',
  `friendly_photo_url` VARCHAR(500) NULL COMMENT 'URL de foto amigable',
  `is_visible_public` BOOLEAN NOT NULL DEFAULT TRUE COMMENT 'Visible en página pública',
  `order_index` INT NOT NULL DEFAULT 0 COMMENT 'Orden de visualización',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (`linked_therapist_id`) REFERENCES `therapists`(`id`) ON DELETE SET NULL,
  INDEX `idx_team_profiles_type` (`member_type`),
  INDEX `idx_team_profiles_visible` (`is_visible_public`),
  INDEX `idx_team_profiles_order` (`order_index`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Perfiles del equipo para página Conócenos';

-- ============================================================================
-- DATOS INICIALES
-- ============================================================================

-- Insertar roles básicos (idempotente - actualiza si ya existe)
INSERT INTO `roles` (`id`, `name`, `description`) VALUES
(1, 'admin', 'Administrador del sistema'),
(2, 'therapist', 'Terapeuta/Psicólogo')
ON DUPLICATE KEY UPDATE 
  `name` = VALUES(`name`),
  `description` = VALUES(`description`);

-- Insertar contenido inicial del sitio (idempotente - actualiza si ya existe)
INSERT INTO `site_content` (`id`, `about_title`, `about_intro`, `mission`, `vision`, `approach`, `values`) VALUES
('00000000-0000-0000-0000-000000000001', 
 '¿Quiénes Somos?',
 'En Mente Livre nos dedicamos a acompañar a jóvenes y universitarios en su bienestar emocional y mental. Nuestro equipo combina profesionales titulados y practicantes avanzados con formación especializada en psicología, enfocados en brindar atención online de calidad, cercana y confiable.',
 'Brindar acompañamiento emocional accesible y de calidad a jóvenes universitarios, promoviendo su bienestar mental durante una etapa crucial de sus vidas.',
 'Ser referentes en consejería psicológica online para jóvenes, construyendo una comunidad donde la salud mental sea prioridad y esté al alcance de todos.',
 'Ofrecemos consejería psicológica (no psicoterapia clínica), enfocada en orientación breve y objetivos concretos. No realizamos diagnósticos ni tratamientos clínicos.',
 JSON_ARRAY('Accesibilidad', 'Confidencialidad', 'Compromiso social', 'Profesionalismo', 'Empatía'))
ON DUPLICATE KEY UPDATE 
  `about_title` = VALUES(`about_title`),
  `about_intro` = VALUES(`about_intro`),
  `mission` = VALUES(`mission`),
  `vision` = VALUES(`vision`),
  `approach` = VALUES(`approach`),
  `values` = VALUES(`values`);

-- Insertar dominios de universidades peruanas (whitelist) (idempotente - actualiza si ya existe)
-- Nota: Usamos el dominio como clave única para detectar duplicados, ya que UUID() genera un nuevo ID cada vez
INSERT INTO `email_domain_rules` (`id`, `domain`, `rule_type`, `note`, `is_active`) VALUES
(UUID(), 'pucp.edu.pe', 'whitelist', 'Pontificia Universidad Católica del Perú', TRUE),
(UUID(), 'ulima.edu.pe', 'whitelist', 'Universidad de Lima', TRUE),
(UUID(), 'upc.edu.pe', 'whitelist', 'Universidad Peruana de Ciencias Aplicadas', TRUE),
(UUID(), 'up.edu.pe', 'whitelist', 'Universidad del Pacífico', TRUE),
(UUID(), 'usmp.pe', 'whitelist', 'Universidad San Martín de Porres', TRUE),
(UUID(), 'unmsm.edu.pe', 'whitelist', 'Universidad Nacional Mayor de San Marcos', TRUE),
(UUID(), 'unfv.edu.pe', 'whitelist', 'Universidad Nacional Federico Villarreal', TRUE),
(UUID(), 'uni.edu.pe', 'whitelist', 'Universidad Nacional de Ingeniería', TRUE),
(UUID(), 'unsa.edu.pe', 'whitelist', 'Universidad Nacional de San Agustín', TRUE),
(UUID(), 'ucsm.edu.pe', 'whitelist', 'Universidad Católica de Santa María', TRUE),
(UUID(), 'ucsp.edu.pe', 'whitelist', 'Universidad Católica San Pablo', TRUE),
(UUID(), 'uandina.edu.pe', 'whitelist', 'Universidad Andina del Cusco', TRUE),
(UUID(), 'ucsur.edu.pe', 'whitelist', 'Universidad Científica del Sur', TRUE),
(UUID(), 'upn.edu.pe', 'whitelist', 'Universidad Privada del Norte', TRUE),
(UUID(), 'utp.edu.pe', 'whitelist', 'Universidad Tecnológica del Perú', TRUE),
(UUID(), 'upao.edu.pe', 'whitelist', 'Universidad Privada Antenor Orrego', TRUE),
(UUID(), 'usat.edu.pe', 'whitelist', 'Universidad Católica Santo Toribio de Mogrovejo', TRUE),
(UUID(), 'udep.edu.pe', 'whitelist', 'Universidad de Piura', TRUE),
(UUID(), 'continental.edu.pe', 'whitelist', 'Universidad Continental', TRUE),
(UUID(), 'usil.edu.pe', 'whitelist', 'Universidad San Ignacio de Loyola', TRUE),
(UUID(), 'esan.edu.pe', 'whitelist', 'Universidad ESAN', TRUE),
(UUID(), 'cayetano.edu.pe', 'whitelist', 'Universidad Peruana Cayetano Heredia', TRUE),
(UUID(), 'urp.edu.pe', 'whitelist', 'Universidad Ricardo Palma', TRUE),
(UUID(), 'uigv.edu.pe', 'whitelist', 'Universidad Inca Garcilaso de la Vega', TRUE),
(UUID(), 'uap.edu.pe', 'whitelist', 'Universidad Alas Peruanas', TRUE),
(UUID(), 'uncp.edu.pe', 'whitelist', 'Universidad Nacional del Centro del Perú', TRUE),
(UUID(), 'unsaac.edu.pe', 'whitelist', 'Universidad Nacional de San Antonio Abad del Cusco', TRUE),
(UUID(), 'unt.edu.pe', 'whitelist', 'Universidad Nacional de Trujillo', TRUE),
(UUID(), 'unp.edu.pe', 'whitelist', 'Universidad Nacional de Piura', TRUE),
(UUID(), 'unprg.edu.pe', 'whitelist', 'Universidad Nacional Pedro Ruiz Gallo', TRUE)
ON DUPLICATE KEY UPDATE 
  `rule_type` = VALUES(`rule_type`),
  `note` = VALUES(`note`),
  `is_active` = VALUES(`is_active`);

-- Restaurar foreign key checks
SET FOREIGN_KEY_CHECKS = 1;
