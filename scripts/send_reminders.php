<?php
/**
 * scripts/send_reminders.php
 *
 * Script de recordatorios — envía emails 24 h antes de cada cita confirmada.
 *
 * CONFIGURAR EN CRON (Hostinger → Cron Jobs):
 *   Frecuencia: cada día a las 08:00 AM
 *   Comando:    php /ruta/al/proyecto/scripts/send_reminders.php
 *
 *   Ejemplo crontab:
 *   0 8 * * * php /home/user/domains/blueviolet-goshawk-261654.hostingersite.com/public_html/backend_mente_livre/scripts/send_reminders.php >> /tmp/reminders.log 2>&1
 *
 * LÓGICA:
 *   - Busca citas con status='confirmed' cuya fecha sea exactamente mañana
 *   - Envía un email de recordatorio a cada paciente
 *   - Marca la cita con reminder_sent_at para no enviar duplicados
 *
 * REQUISITOS:
 *   - Variables MAIL_* configuradas en .env.production
 *   - Columna reminder_sent_at en tabla appointments (ver migration abajo)
 *
 * MIGRATION REQUERIDA (ejecutar una sola vez):
 *   ALTER TABLE appointments ADD COLUMN IF NOT EXISTS reminder_sent_at DATETIME NULL DEFAULT NULL;
 */

// -------------------------------------------------------------------------
// Bootstrap — cargar autoload y variables de entorno
// -------------------------------------------------------------------------
$projectRoot = dirname(__DIR__);

require_once $projectRoot . '/vendor/autoload.php';

use Dotenv\Dotenv;
use App\Core\Database;
use App\Services\EmailService;

// Cargar variables de entorno
if (file_exists($projectRoot . '/.env')) {
    Dotenv::createImmutable($projectRoot)->load();
} elseif (file_exists($projectRoot . '/.env.production')) {
    Dotenv::createImmutable($projectRoot, '.env.production')->load();
}

// -------------------------------------------------------------------------
// Configuración
// -------------------------------------------------------------------------
$timezone = $_ENV['APP_TIMEZONE'] ?? 'America/Lima';
date_default_timezone_set($timezone);

$tomorrow     = date('Y-m-d', strtotime('+1 day'));
$scriptStart  = microtime(true);
$sent         = 0;
$skipped      = 0;
$errors       = 0;

log_msg("=== Inicio send_reminders.php — buscando citas para {$tomorrow} ===");

// -------------------------------------------------------------------------
// Consulta — citas confirmadas de mañana sin recordatorio enviado
// -------------------------------------------------------------------------
try {
    $db  = Database::getInstance()->getConnection();
    $sql = "
        SELECT
            a.id,
            a.appointment_date,
            a.start_time,
            a.end_time,
            a.patient_email,
            a.patient_name,
            t.name  AS therapist_name
        FROM appointments a
        LEFT JOIN therapists t ON a.therapist_id = t.id
        WHERE a.status            = 'confirmed'
          AND a.appointment_date  = :tomorrow
          AND (a.reminder_sent_at IS NULL)
        ORDER BY a.start_time ASC
    ";

    $stmt = $db->prepare($sql);
    $stmt->execute([':tomorrow' => $tomorrow]);
    $appointments = $stmt->fetchAll(PDO::FETCH_ASSOC);

    log_msg('Citas encontradas: ' . count($appointments));
} catch (Throwable $e) {
    log_msg('ERROR al consultar la BD: ' . $e->getMessage(), 'ERROR');
    exit(1);
}

if (empty($appointments)) {
    log_msg('No hay citas para recordar mañana. Fin.');
    exit(0);
}

// -------------------------------------------------------------------------
// Envío de emails
// -------------------------------------------------------------------------
$emailService = new EmailService();

foreach ($appointments as $apt) {
    $id    = $apt['id'];
    $email = $apt['patient_email'] ?? '';
    $name  = $apt['patient_name']  ?? 'Paciente';

    if (empty($email)) {
        log_msg("Cita {$id}: sin email — omitida.", 'WARN');
        $skipped++;
        continue;
    }

    try {
        $ok = $emailService->sendAppointmentReminder($apt);

        if ($ok) {
            // Marcar como enviado para no duplicar
            $upd = $db->prepare(
                "UPDATE appointments SET reminder_sent_at = NOW() WHERE id = ?"
            );
            $upd->execute([$id]);
            log_msg("Cita {$id}: recordatorio enviado a {$email}");
            $sent++;
        } else {
            log_msg("Cita {$id}: EmailService devolvió false (SMTP no configurado?).", 'WARN');
            $skipped++;
        }
    } catch (Throwable $e) {
        log_msg("Cita {$id}: ERROR al enviar — " . $e->getMessage(), 'ERROR');
        $errors++;
    }
}

// -------------------------------------------------------------------------
// Resumen
// -------------------------------------------------------------------------
$elapsed = round(microtime(true) - $scriptStart, 2);
log_msg("=== Fin — enviados: {$sent} | omitidos: {$skipped} | errores: {$errors} | tiempo: {$elapsed}s ===");

exit($errors > 0 ? 1 : 0);

// -------------------------------------------------------------------------
// Helper
// -------------------------------------------------------------------------
function log_msg(string $message, string $level = 'INFO'): void {
    $ts = date('Y-m-d H:i:s');
    echo "[{$ts}] [{$level}] {$message}" . PHP_EOL;
}
