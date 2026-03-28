<?php

namespace App\Services;

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception as MailerException;

/**
 * EmailService — envia notificaciones transaccionales via SMTP.
 *
 * Variables de entorno requeridas (en .env / docker-compose):
 *   MAIL_HOST, MAIL_PORT, MAIL_USERNAME, MAIL_PASSWORD,
 *   MAIL_FROM_ADDRESS, MAIL_FROM_NAME, FRONTEND_URL
 */
class EmailService
{
    private string $host;
    private int    $port;
    private string $username;
    private string $password;
    private string $fromAddress;
    private string $fromName;
    private string $frontendUrl;

    public function __construct()
    {
        $this->host        = $_ENV['MAIL_HOST']         ?? '';
        $this->port        = (int)($_ENV['MAIL_PORT']   ?? 587);
        $this->username    = $_ENV['MAIL_USERNAME']      ?? '';
        $this->password    = $_ENV['MAIL_PASSWORD']      ?? '';
        $this->fromAddress = $_ENV['MAIL_FROM_ADDRESS']  ?? 'no-reply@mentelivre.com';
        $this->fromName    = $_ENV['MAIL_FROM_NAME']     ?? 'Mente Livre';
        $this->frontendUrl = rtrim($_ENV['FRONTEND_URL'] ?? 'http://localhost:5173', '/');
    }

    // -------------------------------------------------------------------------
    // API publica
    // -------------------------------------------------------------------------

    /**
     * Envia email de confirmacion de cita al paciente.
     */
    public function sendAppointmentConfirmation(array $appointment): bool
    {
        $to      = $appointment['patient_email'] ?? '';
        $name    = $appointment['patient_name']  ?? 'Paciente';
        $date    = $appointment['appointment_date'] ?? '';
        $start   = $appointment['start_time']    ?? '';
        $end     = $appointment['end_time']      ?? '';
        $therapist = $appointment['therapist_name'] ?? 'tu psicologo/a';
        $id      = $appointment['id'] ?? '';

        $subject = 'Tu cita ha sido confirmada — Mente Livre';
        $body    = $this->wrapHtml(
            $subject,
            "<p>Hola, <strong>{$name}</strong>.</p>
            <p>Tu cita con <strong>{$therapist}</strong> ha sido <strong>confirmada</strong>.</p>
            <table style='border-collapse:collapse;width:100%'>
              <tr><td style='padding:6px 12px;color:#555'>Fecha</td><td style='padding:6px 12px'>{$date}</td></tr>
              <tr><td style='padding:6px 12px;color:#555'>Hora</td><td style='padding:6px 12px'>{$start} — {$end}</td></tr>
            </table>
            <p style='margin-top:24px'>Si necesitas cancelar o reprogramar, haZlo con al menos 24 horas de anticipacion.</p>
            <p><a href='{$this->frontendUrl}/appointments/{$id}' style='background:#4f46e5;color:#fff;padding:10px 20px;border-radius:6px;text-decoration:none'>Ver mi cita</a></p>"
        );

        return $this->send($to, $name, $subject, $body);
    }

    /**
     * Envia email de cancelacion de cita al paciente.
     */
    public function sendAppointmentCancellation(array $appointment): bool
    {
        $to      = $appointment['patient_email'] ?? '';
        $name    = $appointment['patient_name']  ?? 'Paciente';
        $date    = $appointment['appointment_date'] ?? '';
        $start   = $appointment['start_time']    ?? '';
        $therapist = $appointment['therapist_name'] ?? 'tu psicologo/a';

        $subject = 'Tu cita ha sido cancelada — Mente Livre';
        $body    = $this->wrapHtml(
            $subject,
            "<p>Hola, <strong>{$name}</strong>.</p>
            <p>Lamentamos informarte que tu cita con <strong>{$therapist}</strong>
               del dia <strong>{$date}</strong> a las <strong>{$start}</strong>
               ha sido <strong>cancelada</strong>.</p>
            <p>Si deseas reagendar, puedes hacerlo en cualquier momento desde nuestra plataforma.</p>
            <p><a href='{$this->frontendUrl}/book' style='background:#4f46e5;color:#fff;padding:10px 20px;border-radius:6px;text-decoration:none'>Agendar nueva cita</a></p>"
        );

        return $this->send($to, $name, $subject, $body);
    }

    /**
     * Envia recordatorio de cita (llamar 24 h antes via cron/scheduler).
     */
    public function sendAppointmentReminder(array $appointment): bool
    {
        $to      = $appointment['patient_email'] ?? '';
        $name    = $appointment['patient_name']  ?? 'Paciente';
        $date    = $appointment['appointment_date'] ?? '';
        $start   = $appointment['start_time']    ?? '';
        $end     = $appointment['end_time']      ?? '';
        $therapist = $appointment['therapist_name'] ?? 'tu psicologo/a';
        $id      = $appointment['id'] ?? '';

        $subject = 'Recordatorio: tienes una cita manana — Mente Livre';
        $body    = $this->wrapHtml(
            $subject,
            "<p>Hola, <strong>{$name}</strong>.</p>
            <p>Te recordamos que manana tienes una cita con <strong>{$therapist}</strong>.</p>
            <table style='border-collapse:collapse;width:100%'>
              <tr><td style='padding:6px 12px;color:#555'>Fecha</td><td style='padding:6px 12px'>{$date}</td></tr>
              <tr><td style='padding:6px 12px;color:#555'>Hora</td><td style='padding:6px 12px'>{$start} — {$end}</td></tr>
            </table>
            <p><a href='{$this->frontendUrl}/appointments/{$id}' style='background:#4f46e5;color:#fff;padding:10px 20px;border-radius:6px;text-decoration:none'>Ver detalles</a></p>"
        );

        return $this->send($to, $name, $subject, $body);
    }

    /**
     * Envia link de recuperacion de contrasena.
     */
    public function sendPasswordReset(string $toEmail, string $toName, string $resetToken): bool
    {
        $resetLink = $this->frontendUrl . '/reset-password?token=' . urlencode($resetToken) . '&type=recovery';
        $subject   = 'Recupera tu contrasena — Mente Livre';
        $body      = $this->wrapHtml(
            $subject,
            "<p>Hola, <strong>{$toName}</strong>.</p>
            <p>Recibimos una solicitud para restablecer la contrasena de tu cuenta.</p>
            <p><a href='{$resetLink}' style='background:#4f46e5;color:#fff;padding:10px 20px;border-radius:6px;text-decoration:none'>Restablecer contrasena</a></p>
            <p style='color:#888;font-size:13px'>Este enlace expira en 1 hora. Si no solicitaste esto, ignora este mensaje.</p>"
        );

        return $this->send($toEmail, $toName, $subject, $body);
    }

    /**
     * Notifica al admin que un pago QR/Yape requiere revision.
     */
    public function sendPaymentReviewAlert(array $appointment): bool
    {
        $adminEmail = $_ENV['ADMIN_EMAIL'] ?? $this->fromAddress;
        $patientName  = $appointment['patient_name']  ?? 'N/A';
        $patientEmail = $appointment['patient_email'] ?? 'N/A';
        $therapist    = $appointment['therapist_name'] ?? 'N/A';
        $date         = $appointment['appointment_date'] ?? '';
        $amount       = $appointment['final_price'] ?? 0;
        $method       = $appointment['payment_method'] ?? 'N/A';
        $id           = $appointment['id'] ?? '';

        $subject = "[Mente Livre] Pago pendiente de revision #{$id}";
        $body    = $this->wrapHtml(
            $subject,
            "<p>Un paciente ha enviado un comprobante de pago que requiere tu confirmacion.</p>
            <table style='border-collapse:collapse;width:100%'>
              <tr><td style='padding:6px 12px;color:#555'>Paciente</td><td style='padding:6px 12px'>{$patientName} ({$patientEmail})</td></tr>
              <tr><td style='padding:6px 12px;color:#555'>Psicologo</td><td style='padding:6px 12px'>{$therapist}</td></tr>
              <tr><td style='padding:6px 12px;color:#555'>Fecha cita</td><td style='padding:6px 12px'>{$date}</td></tr>
              <tr><td style='padding:6px 12px;color:#555'>Monto</td><td style='padding:6px 12px'>S/ {$amount}</td></tr>
              <tr><td style='padding:6px 12px;color:#555'>Metodo</td><td style='padding:6px 12px'>{$method}</td></tr>
            </table>
            <p><a href='{$this->frontendUrl}/admin/appointments/{$id}' style='background:#dc2626;color:#fff;padding:10px 20px;border-radius:6px;text-decoration:none'>Revisar pago</a></p>"
        );

        return $this->send($adminEmail, 'Admin', $subject, $body);
    }

    // -------------------------------------------------------------------------
    // Metodos privados
    // -------------------------------------------------------------------------

    /**
     * Envia el email via PHPMailer/SMTP.
     */
    private function send(string $toEmail, string $toName, string $subject, string $htmlBody): bool
    {
        if (empty($this->host) || empty($this->username)) {
            // SMTP no configurado — log silencioso, no rompe el flujo
            error_log("[EmailService] SMTP no configurado. Email no enviado a: {$toEmail} | Asunto: {$subject}");
            return false;
        }

        try {
            $mail = new PHPMailer(true);

            $mail->isSMTP();
            $mail->Host       = $this->host;
            $mail->SMTPAuth   = true;
            $mail->Username   = $this->username;
            $mail->Password   = $this->password;
            $mail->SMTPSecure = $this->port === 465 ? PHPMailer::ENCRYPTION_SMTPS : PHPMailer::ENCRYPTION_STARTTLS;
            $mail->Port       = $this->port;
            $mail->CharSet    = 'UTF-8';

            $mail->setFrom($this->fromAddress, $this->fromName);
            $mail->addAddress($toEmail, $toName);
            $mail->isHTML(true);
            $mail->Subject = $subject;
            $mail->Body    = $htmlBody;
            $mail->AltBody = strip_tags(str_replace(['</p>', '<br>'], "\n", $htmlBody));

            $mail->send();
            return true;

        } catch (MailerException $e) {
            error_log("[EmailService] Error enviando email a {$toEmail}: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Envuelve el contenido en un template HTML basico.
     */
    private function wrapHtml(string $title, string $content): string
    {
        return "<!DOCTYPE html>
<html lang='es'>
<head>
  <meta charset='UTF-8'>
  <meta name='viewport' content='width=device-width,initial-scale=1'>
  <title>{$title}</title>
</head>
<body style='margin:0;padding:0;background:#f4f4f5;font-family:Arial,sans-serif'>
  <table width='100%' cellpadding='0' cellspacing='0' style='background:#f4f4f5;padding:32px 0'>
    <tr><td align='center'>
      <table width='600' cellpadding='0' cellspacing='0' style='background:#fff;border-radius:8px;overflow:hidden;box-shadow:0 2px 8px rgba(0,0,0,.08)'>
        <tr><td style='background:#4f46e5;padding:24px 32px'>
          <h1 style='margin:0;color:#fff;font-size:22px'>Mente Livre</h1>
        </td></tr>
        <tr><td style='padding:32px;color:#333;font-size:15px;line-height:1.6'>
          {$content}
        </td></tr>
        <tr><td style='background:#f4f4f5;padding:16px 32px;text-align:center;color:#999;font-size:12px'>
          &copy; " . date('Y') . " Mente Livre. Todos los derechos reservados.
        </td></tr>
      </table>
    </td></tr>
  </table>
</body>
</html>";
    }
}
