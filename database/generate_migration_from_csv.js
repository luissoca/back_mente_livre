#!/usr/bin/env node

/**
 * Script para convertir datos CSV de producción a INSERT SQL
 * para la nueva estructura normalizada de base de datos
 */

import fs from 'fs';
import path from 'path';
import { fileURLToPath } from 'url';
import { dirname } from 'path';

const __filename = fileURLToPath(import.meta.url);
const __dirname = dirname(__filename);

// Rutas
// El directorio CSV está en: C:\Users\Marcel\Documents\temp_lovable\database en csv
const CSV_DIR = path.join('C:', 'Users', 'Marcel', 'Documents', 'temp_lovable', 'database en csv');
const OUTPUT_FILE = path.join(__dirname, '001_seed_production_data.sql');

// Mapeo de roles: nombre de rol → ID numérico
const ROLE_MAP = {
  'admin': 1,
  'therapist': 2
};

// Función para parsear CSV con soporte para campos con saltos de línea
function parseCSVLine(line, delimiter = ';') {
  const result = [];
  let current = '';
  let inQuotes = false;
  
  for (let i = 0; i < line.length; i++) {
    const char = line[i];
    const nextChar = line[i + 1];
    
    if (char === '"') {
      if (inQuotes && nextChar === '"') {
        // Comillas escapadas
        current += '"';
        i++; // Saltar la siguiente comilla
      } else {
        // Toggle quotes
        inQuotes = !inQuotes;
      }
    } else if (char === delimiter && !inQuotes) {
      result.push(current.trim());
      current = '';
    } else {
      current += char;
    }
  }
  result.push(current.trim());
  return result;
}

// Función para leer CSV
function readCSV(filename) {
  const filepath = path.join(CSV_DIR, filename);
  if (!fs.existsSync(filepath)) {
    console.warn(`⚠️  Archivo no encontrado: ${filename}`);
    return [];
  }
  
  const content = fs.readFileSync(filepath, 'utf-8');
  const lines = content.split('\n');
  if (lines.length < 2) return [];
  
  const headers = parseCSVLine(lines[0]);
  const rows = [];
  let currentRow = [];
  let currentLine = '';
  let inQuotes = false;
  
  for (let i = 1; i < lines.length; i++) {
    const line = lines[i];
    
    // Verificar si estamos dentro de comillas
    const quoteCount = (line.match(/"/g) || []).length;
    if (quoteCount % 2 !== 0) {
      inQuotes = !inQuotes;
    }
    
    if (currentLine) {
      currentLine += '\n' + line;
    } else {
      currentLine = line;
    }
    
    // Si terminamos de procesar un campo completo (no estamos en comillas)
    if (!inQuotes || i === lines.length - 1) {
      const values = parseCSVLine(currentLine);
      if (values.length >= headers.length || i === lines.length - 1) {
        const row = {};
        headers.forEach((header, index) => {
          let value = values[index] || '';
          // Remover comillas exteriores si existen
          if (value.startsWith('"') && value.endsWith('"')) {
            value = value.slice(1, -1).replace(/""/g, '"');
          }
          value = value.trim();
          // Manejar valores vacíos
          if (value === '' || value === 'NULL' || value === 'null') {
            row[header] = null;
          } else {
            row[header] = value;
          }
        });
        rows.push(row);
        currentLine = '';
        inQuotes = false;
      }
    }
  }
  
  return rows;
}

// Función para escapar strings SQL
function escapeSQL(value) {
  if (value === null || value === undefined || value === '') {
    return 'NULL';
  }
  if (typeof value === 'boolean') {
    return value ? 'TRUE' : 'FALSE';
  }
  // Escapar comillas simples y barras invertidas
  const str = String(value).replace(/\\/g, '\\\\').replace(/'/g, "\\'");
  return `'${str}'`;
}

// Función para convertir a JSON válido para MySQL
function escapeJSON(value) {
  if (value === null || value === undefined || value === '') {
    return 'NULL';
  }
  // Si el valor parece ser un string JSON con comillas escapadas, convertirlo
  let jsonStr = String(value);
  // Remover comillas exteriores si existen
  if (jsonStr.startsWith('"') && jsonStr.endsWith('"')) {
    jsonStr = jsonStr.slice(1, -1);
  }
  // Reemplazar comillas dobles escapadas con comillas simples
  jsonStr = jsonStr.replace(/""/g, '"');
  
  // Intentar parsear como JSON para validar
  try {
    const parsed = JSON.parse(jsonStr);
    // Convertir de vuelta a JSON string válido
    return `'${JSON.stringify(parsed).replace(/'/g, "\\'")}'`;
  } catch (e) {
    // Si no es JSON válido, intentar construir un array desde el formato PostgreSQL
    if (jsonStr.startsWith('[') && jsonStr.endsWith(']')) {
      try {
        const parsed = JSON.parse(jsonStr);
        return `'${JSON.stringify(parsed).replace(/'/g, "\\'")}'`;
      } catch (e2) {
        // Si falla, retornar como string escapado
        return escapeSQL(value);
      }
    }
    return escapeSQL(value);
  }
}

// Función para convertir fecha PostgreSQL a MySQL
function convertDate(pgDate) {
  if (!pgDate || pgDate === 'NULL' || pgDate === '') return 'NULL';
  // PostgreSQL: 2025-12-22 21:31:30.731904+00
  // MySQL: 2025-12-22 21:31:30
  const match = pgDate.match(/^(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})/);
  if (match) {
    return `'${match[1]}'`;
  }
  return escapeSQL(pgDate);
}

// Función para convertir boolean
function convertBoolean(value) {
  if (value === null || value === '') return 'NULL';
  if (value === 'true' || value === true) return 'TRUE';
  if (value === 'false' || value === false) return 'FALSE';
  return 'NULL';
}

// Función para parsear JSON array de PostgreSQL
function parseArray(pgArray) {
  if (!pgArray || pgArray === '' || pgArray === '[]' || pgArray === 'NULL') {
    return [];
  }
  try {
    // PostgreSQL array: ["item1","item2"]
    const match = pgArray.match(/\[(.*?)\]/);
    if (match) {
      const items = match[1].split(',').map(item => {
        // Limpiar comillas dobles al inicio y final
        let clean = item.trim();
        // Remover comillas dobles externas
        if ((clean.startsWith('"') && clean.endsWith('"')) || 
            (clean.startsWith("'") && clean.endsWith("'"))) {
          clean = clean.slice(1, -1);
        }
        // Remover escapes
        clean = clean.replace(/\\"/g, '"').replace(/\\'/g, "'");
        return clean;
      }).filter(item => item.length > 0);
      return items;
    }
    // Intentar parsear como JSON
    return JSON.parse(pgArray);
  } catch (e) {
    return [];
  }
}

// Función principal para generar SQL
function generateMigrationSQL() {
  let sql = `-- ============================================================================
-- MIGRACIÓN DE DATOS DE PRODUCCIÓN
-- Mente Livre - Migración de Datos desde Supabase
-- Generado automáticamente desde archivos CSV
-- ============================================================================
-- 
-- IMPORTANTE: Este archivo contiene datos reales de producción
-- Ejecutar SOLO después de que el esquema estructural esté completo (schema.sql)
-- 
-- INSTRUCCIONES:
-- 1. Asegurar que schema.sql ya se ejecutó
-- 2. Revisar y ajustar los datos si es necesario
-- 3. Ejecutar: mysql -u usuario -p base_de_datos < 001_seed_production_data.sql
-- 
-- ============================================================================

SET FOREIGN_KEY_CHECKS = 0;
SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";

`;

  console.log('📥 Leyendo archivos CSV...\n');

  // 1. MIGRAR PROFILES → USERS + PROFILES
  console.log('👤 Procesando profiles...');
  const profiles = readCSV('profiles-export-2026-01-15_16-30-52.csv');
  
  if (profiles.length > 0) {
    sql += `-- ============================================================================
-- 1. MIGRACIÓN DE USUARIOS Y PERFILES
-- ============================================================================

`;
    
    for (const profile of profiles) {
      const userId = profile.id;
      const email = profile.email;
      
      // NOTA: Los passwords no están en el CSV, necesitarán regenerarse
      // Por ahora usamos un hash temporal que DEBE ser actualizado
      const tempPasswordHash = '$2y$10$TEMPORARY.PASSWORD.MUST.BE.CHANGED'; // ⚠️ CAMBIAR
      
      // Insertar en users (solo campos básicos, password necesita regenerarse)
      sql += `-- Usuario: ${email}\n`;
      sql += `INSERT INTO \`users\` (\`id\`, \`email\`, \`password_hash\`, \`email_verified\`, \`email_verified_at\`, \`is_active\`, \`created_at\`, \`updated_at\`) VALUES\n`;
      sql += `  (${escapeSQL(userId)}, ${escapeSQL(email)}, ${escapeSQL(tempPasswordHash)}, `;
      sql += `FALSE, NULL, TRUE, ${convertDate(profile.created_at)}, ${convertDate(profile.updated_at)})\n`;
      sql += `ON DUPLICATE KEY UPDATE\n`;
      sql += `  \`email\` = VALUES(\`email\`),\n`;
      sql += `  \`updated_at\` = VALUES(\`updated_at\`);\n\n`;
      
      // Insertar en profiles
      const firstName = profile.first_name || '';
      const lastName = profile.last_name || '';
      const fullName = profile.full_name || `${firstName} ${lastName}`.trim() || email;
      
      sql += `INSERT INTO \`profiles\` (\`id\`, \`user_id\`, \`first_name\`, \`last_name\`, \`full_name\`, \`phone\`, \`created_at\`, \`updated_at\`) VALUES\n`;
      sql += `  (${escapeSQL(userId)}, ${escapeSQL(userId)}, `;
      sql += `${escapeSQL(firstName)}, ${escapeSQL(lastName)}, ${escapeSQL(fullName)}, `;
      sql += `${escapeSQL(profile.phone) || 'NULL'}, ${convertDate(profile.created_at)}, ${convertDate(profile.updated_at)})\n`;
      sql += `ON DUPLICATE KEY UPDATE\n`;
      sql += `  \`first_name\` = VALUES(\`first_name\`),\n`;
      sql += `  \`last_name\` = VALUES(\`last_name\`),\n`;
      sql += `  \`full_name\` = VALUES(\`full_name\`),\n`;
      sql += `  \`phone\` = VALUES(\`phone\`),\n`;
      sql += `  \`updated_at\` = VALUES(\`updated_at\`);\n\n`;
    }
  }

  // 2. MIGRAR USER ROLES
  console.log('🔐 Procesando user_roles...');
  const userRoles = readCSV('user_roles-export-2026-01-15_16-28-59.csv');
  
  if (userRoles.length > 0) {
    sql += `-- ============================================================================
-- 2. ASIGNACIÓN DE ROLES A USUARIOS
-- ============================================================================

`;
    
    for (const userRole of userRoles) {
      const roleId = ROLE_MAP[userRole.role] || null;
      if (!roleId) {
        console.warn(`⚠️  Rol desconocido: ${userRole.role}`);
        continue;
      }
      
      sql += `INSERT INTO \`user_roles\` (\`id\`, \`user_id\`, \`role_id\`, \`created_at\`) VALUES\n`;
      sql += `  (${escapeSQL(userRole.id)}, ${escapeSQL(userRole.user_id)}, ${roleId}, ${convertDate(userRole.created_at)})\n`;
      sql += `ON DUPLICATE KEY UPDATE\n`;
      sql += `  \`role_id\` = VALUES(\`role_id\`);\n\n`;
    }
  }

  // 3. MIGRAR THERAPISTS
  console.log('👨‍⚕️ Procesando therapists...');
  const therapists = readCSV('therapists-export-2026-01-15_16-29-51.csv');
  
  if (therapists.length > 0) {
    sql += `-- ============================================================================
-- 3. MIGRACIÓN DE TERAPEUTAS
-- ============================================================================

`;
    
    for (const therapist of therapists) {
      // Insertar terapeuta principal
      sql += `-- Terapeuta: ${therapist.name}\n`;
      sql += `INSERT INTO \`therapists\` (\n`;
      sql += `  \`id\`, \`user_id\`, \`name\`, \`university\`, \`academic_cycle\`, \`academic_credentials\`,\n`;
      sql += `  \`age\`, \`years_experience\`, \`professional_level\`, \`role_title\`, \`specialty\`,\n`;
      sql += `  \`therapeutic_approach\`, \`short_description\`, \`public_bio\`, \`modality\`,\n`;
      sql += `  \`availability_schedule\`, \`is_active\`, \`is_visible_in_about\`, \`hourly_rate\`,\n`;
      sql += `  \`field_visibility\`, \`created_at\`, \`updated_at\`\n`;
      sql += `) VALUES\n`;
      sql += `  (\n`;
      sql += `    ${escapeSQL(therapist.id)},\n`;
      sql += `    ${therapist.user_id ? escapeSQL(therapist.user_id) : 'NULL'},\n`;
      sql += `    ${escapeSQL(therapist.name)},\n`;
      sql += `    ${escapeSQL(therapist.university)},\n`;
      sql += `    ${escapeSQL(therapist.academic_cycle)},\n`;
      sql += `    ${escapeSQL(therapist.academic_credentials) || 'NULL'},\n`;
      sql += `    ${therapist.age ? parseInt(therapist.age) : 'NULL'},\n`;
      sql += `    ${therapist.years_experience ? parseInt(therapist.years_experience) : 'NULL'},\n`;
      sql += `    ${escapeSQL(therapist.professional_level) || 'NULL'},\n`;
      sql += `    ${escapeSQL(therapist.role_title) || "'Psicólogo/a'"},\n`;
      sql += `    ${escapeSQL(therapist.specialty) || 'NULL'},\n`;
      sql += `    ${escapeSQL(therapist.therapeutic_approach) || 'NULL'},\n`;
      sql += `    ${escapeSQL(therapist.short_description) || 'NULL'},\n`;
      sql += `    ${escapeSQL(therapist.public_bio) || 'NULL'},\n`;
      sql += `    ${escapeSQL(therapist.modality) || "'Online'"},\n`;
      sql += `    ${escapeSQL(therapist.availability_schedule) || 'NULL'},\n`;
      sql += `    ${convertBoolean(therapist.is_active)},\n`;
      sql += `    ${convertBoolean(therapist.is_visible_in_about)},\n`;
      sql += `    ${therapist.hourly_rate ? parseFloat(therapist.hourly_rate) : '0.00'},\n`;
      sql += `    ${therapist.field_visibility ? escapeSQL(therapist.field_visibility) : 'NULL'},\n`;
      sql += `    ${convertDate(therapist.created_at)},\n`;
      sql += `    ${convertDate(therapist.updated_at)}\n`;
      sql += `  )\n`;
      sql += `ON DUPLICATE KEY UPDATE\n`;
      sql += `  \`name\` = VALUES(\`name\`),\n`;
      sql += `  \`university\` = VALUES(\`university\`),\n`;
      sql += `  \`academic_cycle\` = VALUES(\`academic_cycle\`),\n`;
      sql += `  \`is_active\` = VALUES(\`is_active\`),\n`;
      sql += `  \`hourly_rate\` = VALUES(\`hourly_rate\`),\n`;
      sql += `  \`updated_at\` = VALUES(\`updated_at\`);\n\n`;
      
      // Insertar precios
      const pricePublic = therapist.price_public ? parseFloat(therapist.price_public) : null;
      const priceUniversity = therapist.price_university && therapist.price_university_enabled === 'true' 
        ? parseFloat(therapist.price_university) : null;
      const priceCorporate = therapist.price_corporate ? parseFloat(therapist.price_corporate) : null;
      const priceInternational = therapist.price_international ? parseFloat(therapist.price_international) : null;
      
      if (pricePublic) {
        sql += `INSERT INTO \`therapist_pricing\` (\`id\`, \`therapist_id\`, \`pricing_tier\`, \`price\`, \`is_enabled\`, \`created_at\`, \`updated_at\`) VALUES\n`;
        sql += `  (UUID(), ${escapeSQL(therapist.id)}, 'public', ${pricePublic}, TRUE, NOW(), NOW())\n`;
        sql += `ON DUPLICATE KEY UPDATE \`price\` = VALUES(\`price\`);\n\n`;
      }
      if (priceUniversity) {
        sql += `INSERT INTO \`therapist_pricing\` (\`id\`, \`therapist_id\`, \`pricing_tier\`, \`price\`, \`is_enabled\`, \`created_at\`, \`updated_at\`) VALUES\n`;
        sql += `  (UUID(), ${escapeSQL(therapist.id)}, 'university_pe', ${priceUniversity}, TRUE, NOW(), NOW())\n`;
        sql += `ON DUPLICATE KEY UPDATE \`price\` = VALUES(\`price\`);\n\n`;
      }
      if (priceCorporate) {
        sql += `INSERT INTO \`therapist_pricing\` (\`id\`, \`therapist_id\`, \`pricing_tier\`, \`price\`, \`is_enabled\`, \`created_at\`, \`updated_at\`) VALUES\n`;
        sql += `  (UUID(), ${escapeSQL(therapist.id)}, 'corporate', ${priceCorporate}, TRUE, NOW(), NOW())\n`;
        sql += `ON DUPLICATE KEY UPDATE \`price\` = VALUES(\`price\`);\n\n`;
      }
      if (priceInternational) {
        sql += `INSERT INTO \`therapist_pricing\` (\`id\`, \`therapist_id\`, \`pricing_tier\`, \`price\`, \`is_enabled\`, \`created_at\`, \`updated_at\`) VALUES\n`;
        sql += `  (UUID(), ${escapeSQL(therapist.id)}, 'university_international', ${priceInternational}, TRUE, NOW(), NOW())\n`;
        sql += `ON DUPLICATE KEY UPDATE \`price\` = VALUES(\`price\`);\n\n`;
      }
      
      // Insertar fotos
      if (therapist.photo_url) {
        sql += `INSERT INTO \`therapist_photos\` (\`id\`, \`therapist_id\`, \`photo_type\`, \`photo_url\`, \`photo_position\`, \`created_at\`) VALUES\n`;
        sql += `  (UUID(), ${escapeSQL(therapist.id)}, 'profile', ${escapeSQL(therapist.photo_url)}, `;
        sql += `${escapeSQL(therapist.photo_position) || "'1'"}, NOW())\n`;
        sql += `ON DUPLICATE KEY UPDATE \`photo_url\` = VALUES(\`photo_url\`);\n\n`;
      }
      if (therapist.friendly_photo_url) {
        sql += `INSERT INTO \`therapist_photos\` (\`id\`, \`therapist_id\`, \`photo_type\`, \`photo_url\`, \`photo_position\`, \`created_at\`) VALUES\n`;
        sql += `  (UUID(), ${escapeSQL(therapist.id)}, 'friendly', ${escapeSQL(therapist.friendly_photo_url)}, `;
        sql += `'2', NOW())\n`;
        sql += `ON DUPLICATE KEY UPDATE \`photo_url\` = VALUES(\`photo_url\`);\n\n`;
      }
      
      // Insertar temas de experiencia
      const experienceTopics = parseArray(therapist.experience_topics);
      if (experienceTopics.length > 0) {
        // Agrupar todos los temas en un solo INSERT para mayor eficiencia
        const topicValues = experienceTopics.map(topic => {
          // Limpiar el tema de comillas extra
          let cleanTopic = topic.replace(/^"|"$/g, '').replace(/\\"/g, '"');
          return `    (UUID(), ${escapeSQL(therapist.id)}, ${escapeSQL(cleanTopic)}, NOW())`;
        }).join(',\n');
        
        sql += `INSERT INTO \`therapist_experience_topics\` (\`id\`, \`therapist_id\`, \`topic\`, \`created_at\`) VALUES\n`;
        sql += `${topicValues}\n`;
        sql += `ON DUPLICATE KEY UPDATE \`topic\` = VALUES(\`topic\`);\n\n`;
      }
      
      // Insertar población atendida
      const populationServed = parseArray(therapist.population_served);
      if (populationServed.length > 0) {
        // Agrupar todas las poblaciones en un solo INSERT
        const populationValues = populationServed.map(population => {
          // Limpiar la población de comillas extra
          let cleanPopulation = population.replace(/^"|"$/g, '').replace(/\\"/g, '"');
          return `    (UUID(), ${escapeSQL(therapist.id)}, ${escapeSQL(cleanPopulation)}, NOW())`;
        }).join(',\n');
        
        sql += `INSERT INTO \`therapist_population_served\` (\`id\`, \`therapist_id\`, \`population\`, \`created_at\`) VALUES\n`;
        sql += `${populationValues}\n`;
        sql += `ON DUPLICATE KEY UPDATE \`population\` = VALUES(\`population\`);\n\n`;
      }
    }
  }

  // 4. MIGRAR APPOINTMENTS
  console.log('📅 Procesando appointments...');
  const appointments = readCSV('appointments-export-2026-01-15_16-31-10.csv');
  
  if (appointments.length > 0) {
    sql += `-- ============================================================================
-- 4. MIGRACIÓN DE CITAS (APPOINTMENTS)
-- ============================================================================

`;
    
    // Primero crear contactos de pacientes
    const patientContactsMap = new Map();
    
    for (const appointment of appointments) {
      const contactKey = `${appointment.patient_email || appointment.email_used || ''}`;
      if (contactKey && !patientContactsMap.has(contactKey)) {
        const firstName = appointment.contact_first_name || appointment.patient_name?.split(' ')[0] || '';
        const lastName = appointment.contact_last_name || appointment.patient_name?.split(' ').slice(1).join(' ') || '';
        const fullName = appointment.patient_name || `${firstName} ${lastName}`.trim() || contactKey;
        const contactId = appointment.id.substring(0, 8) + '-' + appointment.id.substring(8, 12) + '-' + appointment.id.substring(12, 16) + '-' + appointment.id.substring(16, 20) + '-' + appointment.id.substring(20);
        
        patientContactsMap.set(contactKey, {
          id: contactId,
          firstName,
          lastName,
          fullName,
          email: contactKey,
          phone: appointment.patient_phone || appointment.contact_phone || null
        });
      }
    }
    
    // Insertar contactos
    for (const [key, contact] of patientContactsMap) {
      sql += `INSERT INTO \`patient_contacts\` (\`id\`, \`first_name\`, \`last_name\`, \`full_name\`, \`email\`, \`phone\`, \`created_at\`, \`updated_at\`) VALUES\n`;
      sql += `  (${escapeSQL(contact.id)}, ${escapeSQL(contact.firstName)}, ${escapeSQL(contact.lastName)}, `;
      sql += `${escapeSQL(contact.fullName)}, ${escapeSQL(contact.email)}, ${escapeSQL(contact.phone) || 'NULL'}, NOW(), NOW())\n`;
      sql += `ON DUPLICATE KEY UPDATE\n`;
      sql += `  \`first_name\` = VALUES(\`first_name\`),\n`;
      sql += `  \`last_name\` = VALUES(\`last_name\`),\n`;
      sql += `  \`full_name\` = VALUES(\`full_name\`),\n`;
      sql += `  \`phone\` = VALUES(\`phone\`);\n\n`;
    }
    
    // Insertar citas
    for (const appointment of appointments) {
      const contactKey = `${appointment.patient_email || appointment.email_used || ''}`;
      const contact = patientContactsMap.get(contactKey);
      const contactId = contact ? contact.id : null;
      
      if (!contactId) {
        console.warn(`⚠️  No se pudo encontrar contacto para cita ${appointment.id}`);
        continue;
      }
      
      sql += `INSERT INTO \`appointments\` (\n`;
      sql += `  \`id\`, \`therapist_id\`, \`patient_contact_id\`, \`patient_email\`, \`patient_name\`,\n`;
      sql += `  \`patient_phone\`, \`consultation_reason\`, \`appointment_date\`,\n`;
      sql += `  \`start_time\`, \`end_time\`, \`status\`, \`notes\`, \`created_at\`, \`updated_at\`\n`;
      sql += `) VALUES\n`;
      sql += `  (\n`;
      sql += `    ${escapeSQL(appointment.id)},\n`;
      sql += `    ${escapeSQL(appointment.therapist_id)},\n`;
      sql += `    ${escapeSQL(contactId)},\n`;
      sql += `    ${escapeSQL(contact.email || appointment.patient_email || appointment.email_used) || 'NULL'},\n`;
      sql += `    ${escapeSQL(contact.fullName || appointment.patient_name || '') || 'NULL'},\n`;
      sql += `    ${escapeSQL(contact.phone || appointment.patient_phone) || 'NULL'},\n`;
      sql += `    ${escapeSQL(appointment.consultation_reason || appointment.notes) || 'NULL'},\n`;
      sql += `    ${escapeSQL(appointment.appointment_date) || 'NULL'},\n`;
      sql += `    ${escapeSQL(appointment.start_time) || 'NULL'},\n`;
      sql += `    ${escapeSQL(appointment.end_time) || 'NULL'},\n`;
      sql += `    ${escapeSQL(appointment.status) || "'scheduled'"},\n`;
      sql += `    ${escapeSQL(appointment.notes) || 'NULL'},\n`;
      sql += `    ${convertDate(appointment.created_at)},\n`;
      sql += `    ${convertDate(appointment.updated_at)}\n`;
      sql += `  )\n`;
      sql += `ON DUPLICATE KEY UPDATE\n`;
      sql += `  \`status\` = VALUES(\`status\`),\n`;
      sql += `  \`notes\` = VALUES(\`notes\`),\n`;
      sql += `  \`updated_at\` = VALUES(\`updated_at\`);\n\n`;
      
      // Insertar información de pago si existe
      if (appointment.amount_paid || appointment.payment_method) {
        const originalPrice = appointment.original_price ? parseFloat(appointment.original_price) : (appointment.final_price || appointment.amount_paid ? parseFloat(appointment.final_price || appointment.amount_paid) : 0);
        const discountApplied = appointment.discount_applied ? parseFloat(appointment.discount_applied) : 0;
        const finalPrice = appointment.final_price ? parseFloat(appointment.final_price) : (originalPrice - discountApplied);
        const amountPaid = appointment.amount_paid ? parseFloat(appointment.amount_paid) : (appointment.payment_confirmed_at ? finalPrice : null);
        
        sql += `INSERT INTO \`appointment_payments\` (\n`;
        sql += `  \`id\`, \`appointment_id\`, \`original_price\`, \`discount_applied\`,\n`;
        sql += `  \`final_price\`, \`amount_paid\`, \`payment_method\`, \`payment_confirmed_at\`, \`created_at\`, \`updated_at\`\n`;
        sql += `) VALUES\n`;
        sql += `  (\n`;
        sql += `    UUID(),\n`;
        sql += `    ${escapeSQL(appointment.id)},\n`;
        sql += `    ${originalPrice},\n`;
        sql += `    ${discountApplied},\n`;
        sql += `    ${finalPrice},\n`;
        sql += `    ${amountPaid !== null ? amountPaid : 'NULL'},\n`;
        sql += `    ${escapeSQL(appointment.payment_method) || "'Yape/Plin'"},\n`;
        sql += `    ${appointment.payment_confirmed_at ? convertDate(appointment.payment_confirmed_at) : 'NULL'},\n`;
        sql += `    ${convertDate(appointment.created_at)},\n`;
        sql += `    ${convertDate(appointment.updated_at)}\n`;
        sql += `  )\n`;
        sql += `ON DUPLICATE KEY UPDATE\n`;
        sql += `  \`original_price\` = VALUES(\`original_price\`),\n`;
        sql += `  \`discount_applied\` = VALUES(\`discount_applied\`),\n`;
        sql += `  \`final_price\` = VALUES(\`final_price\`),\n`;
        sql += `  \`amount_paid\` = VALUES(\`amount_paid\`),\n`;
        sql += `  \`payment_method\` = VALUES(\`payment_method\`),\n`;
        sql += `  \`payment_confirmed_at\` = VALUES(\`payment_confirmed_at\`);\n\n`;
      }
    }
  }

  // 5. MIGRAR PROMO CODES
  console.log('🎟️  Procesando promo_codes...');
  const promoCodes = readCSV('promo_codes-export-2026-01-15_16-30-26.csv');
  
  if (promoCodes.length > 0) {
    sql += `-- ============================================================================
-- 5. MIGRACIÓN DE CÓDIGOS PROMOCIONALES
-- ============================================================================

`;
    
    for (const promo of promoCodes) {
      sql += `INSERT INTO \`promo_codes\` (\n`;
      sql += `  \`id\`, \`code\`, \`is_active\`, \`discount_percent\`, \`base_price\`,\n`;
      sql += `  \`max_uses_total\`, \`max_uses_per_user\`, \`max_sessions\`,\n`;
      sql += `  \`valid_from\`, \`valid_until\`, \`uses_count\`, \`created_at\`, \`updated_at\`\n`;
      sql += `) VALUES\n`;
      sql += `  (\n`;
      sql += `    ${escapeSQL(promo.id)},\n`;
      sql += `    ${escapeSQL(promo.code)},\n`;
      sql += `    ${convertBoolean(promo.is_active)},\n`;
      sql += `    ${promo.discount_percent ? parseInt(promo.discount_percent) : 0},\n`;
      sql += `    ${promo.base_price ? parseFloat(promo.base_price) : 25.00},\n`;
      sql += `    ${promo.max_uses_total ? parseInt(promo.max_uses_total) : 'NULL'},\n`;
      sql += `    ${promo.max_uses_per_user ? parseInt(promo.max_uses_per_user) : 1},\n`;
      sql += `    ${promo.max_sessions ? parseInt(promo.max_sessions) : 1},\n`;
      sql += `    ${convertDate(promo.valid_from) || 'NULL'},\n`;
      sql += `    ${convertDate(promo.valid_until) || 'NULL'},\n`;
      sql += `    ${promo.uses_count ? parseInt(promo.uses_count) : 0},\n`;
      sql += `    ${convertDate(promo.created_at)},\n`;
      sql += `    ${convertDate(promo.updated_at)}\n`;
      sql += `  )\n`;
      sql += `ON DUPLICATE KEY UPDATE\n`;
      sql += `  \`is_active\` = VALUES(\`is_active\`),\n`;
      sql += `  \`discount_percent\` = VALUES(\`discount_percent\`),\n`;
      sql += `  \`base_price\` = VALUES(\`base_price\`),\n`;
      sql += `  \`max_uses_total\` = VALUES(\`max_uses_total\`),\n`;
      sql += `  \`max_uses_per_user\` = VALUES(\`max_uses_per_user\`),\n`;
      sql += `  \`max_sessions\` = VALUES(\`max_sessions\`),\n`;
      sql += `  \`uses_count\` = VALUES(\`uses_count\`);\n\n`;
    }
  }

  // 6. MIGRAR PROMO CODE USES
  console.log('🎫 Procesando promo_code_uses...');
  const promoCodeUses = readCSV('promo_code_uses-export-2026-01-15_16-30-38.csv');
  
  if (promoCodeUses.length > 0) {
    sql += `-- ============================================================================
-- 6. USOS DE CÓDIGOS PROMOCIONALES
-- ============================================================================

`;
    
    for (const use of promoCodeUses) {
      sql += `INSERT INTO \`promo_code_uses\` (\n`;
      sql += `  \`id\`, \`promo_code_id\`, \`user_email\`, \`appointment_id\`,\n`;
      sql += `  \`discount_applied\`, \`final_amount\`, \`created_at\`\n`;
      sql += `) VALUES\n`;
      sql += `  (\n`;
      sql += `    ${escapeSQL(use.id)},\n`;
      sql += `    ${escapeSQL(use.promo_code_id)},\n`;
      sql += `    ${escapeSQL(use.user_email)},\n`;
      sql += `    ${use.appointment_id ? escapeSQL(use.appointment_id) : 'NULL'},\n`;
      sql += `    ${use.discount_applied ? parseFloat(use.discount_applied) : 'NULL'},\n`;
      sql += `    ${use.final_amount ? parseFloat(use.final_amount) : 'NULL'},\n`;
      sql += `    ${convertDate(use.created_at)}\n`;
      sql += `  )\n`;
      sql += `ON DUPLICATE KEY UPDATE\n`;
      sql += `  \`discount_applied\` = VALUES(\`discount_applied\`),\n`;
      sql += `  \`final_amount\` = VALUES(\`final_amount\`);\n\n`;
    }
  }

  // 7. MIGRAR WEEKLY SCHEDULES
  console.log('📆 Procesando weekly_schedules...');
  const weeklySchedules = readCSV('weekly_schedules-export-2026-01-15_16-29-35.csv');
  
  if (weeklySchedules.length > 0) {
    sql += `-- ============================================================================
-- 7. MIGRACIÓN DE HORARIOS SEMANALES
-- ============================================================================

`;
    
    for (const schedule of weeklySchedules) {
      // Convertir day_of_week de string a número (1=Lunes, 7=Domingo)
      const dayMap = {
        'monday': 1, 'tuesday': 2, 'wednesday': 3, 'thursday': 4,
        'friday': 5, 'saturday': 6, 'sunday': 7
      };
      const dayOfWeek = dayMap[schedule.day_of_week?.toLowerCase()] || null;
      
      if (!dayOfWeek) {
        console.warn(`⚠️  Día de semana desconocido: ${schedule.day_of_week}`);
        continue;
      }
      
      sql += `INSERT INTO \`weekly_schedules\` (\n`;
      sql += `  \`id\`, \`therapist_id\`, \`day_of_week\`,\n`;
      sql += `  \`start_time\`, \`end_time\`, \`is_active\`, \`created_at\`, \`updated_at\`\n`;
      sql += `) VALUES\n`;
      sql += `  (\n`;
      sql += `    ${escapeSQL(schedule.id)},\n`;
      sql += `    ${escapeSQL(schedule.therapist_id)},\n`;
      sql += `    ${dayOfWeek},\n`;
      sql += `    ${escapeSQL(schedule.start_time) || 'NULL'},\n`;
      sql += `    ${escapeSQL(schedule.end_time) || 'NULL'},\n`;
      sql += `    ${convertBoolean(schedule.is_active)},\n`;
      sql += `    ${convertDate(schedule.created_at)},\n`;
      sql += `    ${convertDate(schedule.updated_at)}\n`;
      sql += `  )\n`;
      sql += `ON DUPLICATE KEY UPDATE\n`;
      sql += `  \`start_time\` = VALUES(\`start_time\`),\n`;
      sql += `  \`end_time\` = VALUES(\`end_time\`),\n`;
      sql += `  \`is_active\` = VALUES(\`is_active\`);\n\n`;
    }
  }

  // 8. MIGRAR TEAM PROFILES
  console.log('👥 Procesando team_profiles...');
  const teamProfiles = readCSV('team_profiles-export-2026-01-15_16-29-59.csv');
  
  if (teamProfiles.length > 0) {
    sql += `-- ============================================================================
-- 8. MIGRACIÓN DE PERFILES DEL EQUIPO
-- ============================================================================

`;
    
    for (const team of teamProfiles) {
      sql += `INSERT INTO \`team_profiles\` (\n`;
      sql += `  \`id\`, \`member_type\`, \`linked_therapist_id\`, \`full_name\`,\n`;
      sql += `  \`public_role_title\`, \`professional_level\`, \`public_bio\`,\n`;
      sql += `  \`friendly_photo_url\`, \`is_visible_public\`, \`order_index\`, \`created_at\`, \`updated_at\`\n`;
      sql += `) VALUES\n`;
      sql += `  (\n`;
      sql += `    ${escapeSQL(team.id)},\n`;
      sql += `    ${team.member_type && team.member_type !== 'NULL' && team.member_type !== '' ? escapeSQL(team.member_type) : "'institutional'"},\n`;
      sql += `    ${team.linked_therapist_id ? escapeSQL(team.linked_therapist_id) : 'NULL'},\n`;
      sql += `    ${escapeSQL(team.full_name)},\n`;
      sql += `    ${escapeSQL(team.public_role_title) || 'NULL'},\n`;
      sql += `    ${escapeSQL(team.professional_level) || 'NULL'},\n`;
      sql += `    ${escapeSQL(team.public_bio) || 'NULL'},\n`;
      sql += `    ${escapeSQL(team.friendly_photo_url) || 'NULL'},\n`;
      sql += `    ${convertBoolean(team.is_visible_public)},\n`;
      sql += `    ${team.order_index ? parseInt(team.order_index) : '0'},\n`;
      sql += `    ${convertDate(team.created_at)},\n`;
      sql += `    ${convertDate(team.updated_at)}\n`;
      sql += `  )\n`;
      sql += `ON DUPLICATE KEY UPDATE\n`;
      sql += `  \`full_name\` = VALUES(\`full_name\`),\n`;
      sql += `  \`public_role_title\` = VALUES(\`public_role_title\`),\n`;
      sql += `  \`public_bio\` = VALUES(\`public_bio\`),\n`;
      sql += `  \`order_index\` = VALUES(\`order_index\`);\n\n`;
    }
  }

  // 9. MIGRAR SITE CONTENT
  console.log('📝 Procesando site_content...');
  const siteContent = readCSV('site_content-export-2026-01-15_16-30-19.csv');
  
  if (siteContent.length > 0) {
    sql += `-- ============================================================================
-- 9. ACTUALIZACIÓN DE CONTENIDO DEL SITIO
-- ============================================================================

`;
    
    for (const content of siteContent) {
      sql += `INSERT INTO \`site_content\` (\n`;
      sql += `  \`id\`, \`about_title\`, \`about_intro\`, \`mission\`, \`vision\`,\n`;
      sql += `  \`approach\`, \`values\`, \`created_at\`, \`updated_at\`\n`;
      sql += `) VALUES\n`;
      sql += `  (\n`;
      sql += `    ${escapeSQL(content.id)},\n`;
      sql += `    ${escapeSQL(content.about_title)},\n`;
      sql += `    ${escapeSQL(content.about_intro)},\n`;
      sql += `    ${escapeSQL(content.mission)},\n`;
      sql += `    ${escapeSQL(content.vision)},\n`;
      sql += `    ${escapeSQL(content.approach)},\n`;
      sql += `    ${escapeJSON(content.values)},\n`;
      sql += `    ${convertDate(content.created_at)},\n`;
      sql += `    ${convertDate(content.updated_at)}\n`;
      sql += `  )\n`;
      sql += `ON DUPLICATE KEY UPDATE\n`;
      sql += `  \`about_title\` = VALUES(\`about_title\`),\n`;
      sql += `  \`about_intro\` = VALUES(\`about_intro\`),\n`;
      sql += `  \`mission\` = VALUES(\`mission\`),\n`;
      sql += `  \`vision\` = VALUES(\`vision\`),\n`;
      sql += `  \`approach\` = VALUES(\`approach\`),\n`;
      sql += `  \`values\` = VALUES(\`values\`);\n\n`;
    }
  }

  // 10. MIGRAR EMAIL DOMAIN RULES (ya están en schema.sql, pero actualizamos con los reales)
  console.log('📧 Procesando email_domain_rules...');
  const emailDomainRules = readCSV('email_domain_rules-export-2026-01-15_16-31-02.csv');
  
  if (emailDomainRules.length > 0) {
    sql += `-- ============================================================================
-- 10. ACTUALIZACIÓN DE REGLAS DE DOMINIOS DE EMAIL
-- ============================================================================

`;
    
    for (const rule of emailDomainRules) {
      sql += `INSERT INTO \`email_domain_rules\` (\n`;
      sql += `  \`id\`, \`domain\`, \`rule_type\`, \`note\`, \`is_active\`, \`created_at\`, \`updated_at\`\n`;
      sql += `) VALUES\n`;
      sql += `  (\n`;
      sql += `    ${escapeSQL(rule.id)},\n`;
      sql += `    ${escapeSQL(rule.domain)},\n`;
      sql += `    ${escapeSQL(rule.rule_type)},\n`;
      sql += `    ${escapeSQL(rule.note) || 'NULL'},\n`;
      sql += `    ${convertBoolean(rule.is_active)},\n`;
      sql += `    ${convertDate(rule.created_at)},\n`;
      sql += `    ${convertDate(rule.updated_at)}\n`;
      sql += `  )\n`;
      sql += `ON DUPLICATE KEY UPDATE\n`;
      sql += `  \`rule_type\` = VALUES(\`rule_type\`),\n`;
      sql += `  \`note\` = VALUES(\`note\`),\n`;
      sql += `  \`is_active\` = VALUES(\`is_active\`);\n\n`;
    }
  }

  sql += `-- ============================================================================
-- FIN DE MIGRACIÓN
-- ============================================================================

SET FOREIGN_KEY_CHECKS = 1;

-- ============================================================================
-- NOTAS IMPORTANTES
-- ============================================================================
-- 
-- ⚠️  IMPORTANTE: Los passwords de usuarios están temporalmente como:
--     '$2y$10$TEMPORARY.PASSWORD.MUST.BE.CHANGED'
--     DEBES regenerar los passwords reales después de la migración
-- 
-- ✅ Verificar la integridad de los datos después de ejecutar:
--     SELECT COUNT(*) FROM users;
--     SELECT COUNT(*) FROM therapists;
--     SELECT COUNT(*) FROM appointments;
-- 
-- ============================================================================
`;

  // Guardar archivo
  fs.writeFileSync(OUTPUT_FILE, sql, 'utf-8');
  
  console.log(`\n✅ Migración SQL generada exitosamente!`);
  console.log(`📄 Archivo: ${OUTPUT_FILE}\n`);
  console.log(`⚠️  IMPORTANTE: Revisa el archivo y actualiza los passwords antes de ejecutar!`);
}

// Ejecutar
try {
  generateMigrationSQL();
} catch (error) {
  console.error('❌ Error generando migración:', error.message);
  console.error(error.stack);
  process.exit(1);
}
