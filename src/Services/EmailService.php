<?php

namespace App\Services;

/**
 * EmailService — envia notificaciones transaccionales via Resend API.
 *
 * Variable de entorno requerida:
 *   RESEND_API_KEY=re_xxxxxxxxxxxxxxxx
 *   RESEND_FROM=Mente Livre <no-reply@mentelivre.com>
 *   FRONTEND_URL=https://mentelivre.com
 *   ADMIN_EMAIL=admin@mentelivre.com
 */
class EmailService
{
    private string $apiKey;
    private string $from;
    private string $frontendUrl;
    private string $adminEmail;

    public function __construct()
    {
        $this->apiKey      = $_ENV['RESEND_API_KEY']  ?? '';
        $this->from        = $_ENV['RESEND_FROM']      ?? 'Mente Livre <no-reply@mentelivre.com>';
        $this->frontendUrl = rtrim($_ENV['FRONTEND_URL'] ?? 'http://localhost:5173', '/');
        $this->adminEmail  = $_ENV['ADMIN_EMAIL']      ?? 'admin@mentelivre.com';
    }

    // -------------------------------------------------------------------------
    // API publica
    // -------------------------------------------------------------------------

    /** Confirmacion de cita al paciente. */
    public function sendAppointmentConfirmation(array $appointment): bool
    {
        $to       = $appointment['patient_email'] ?? '';
        $name     = $appointment['patient_name']  ?? 'Paciente';
        $date     = $appointment['appointment_date'] ?? '';
        $start    = $appointment['start_time']    ?? '';
        $end      = $appointment['end_time']      ?? '';
        $therapist = $appointment['therapist_name'] ?? 'tu psicologo/a';
        $id       = $appointment['id'] ?? '';

        $subject = 'Tu cita ha sido confirmada — Mente Livre';
        $body    = $this->wrapHtml($subject,
            "<p>Hola, <strong>{$name}</strong>.</p>
             <p>Tu cita con <strong>{$therapist}</strong> ha sido <strong>confirmada</strong>.</p>
             <table style='border-collapse:collapse;width:100%'>
               <tr><td style='padding:6px 12px;color:#555'>Fecha</td><td style='padding:6px 12px'>{$date}</td></tr>
               <tr><td style='padding:6px 12px;color:#555'>Hora</td><td style='padding:6px 12px'>{$start} &mdash; {$end}</td></tr>
             </table>
             <p style='margin-top:24px'>Si necesitas cancelar o reprogramar, hazlo con al menos 24 horas de anticipacion.</p>
             <p><a href='{$this->frontendUrl}/appointments/{$id}'
                   style='background:#4f46e5;color:#fff;padding:10px 20px;border-radius:6px;text-decoration:none'>
               Ver mi cita
             </a></p>"
        );

        return $this->send($to, $subject, $body);
    }

    /** Cancelacion de cita al paciente. */
    public function sendAppointmentCancellation(array $appointment): bool
    {
        $to        = $appointment['patient_email']  ?? '';
        $name      = $appointment['patient_name']   ?? 'Paciente';
        $date      = $appointment['appointment_date'] ?? '';
        $start     = $appointment['start_time']     ?? '';
        $therapist = $appointment['therapist_name'] ?? 'tu psicologo/a';

        $subject = 'Tu cita ha sido cancelada — Mente Livre';
        $body    = $this->wrapHtml($subject,
            "<p>Hola, <strong>{$name}</strong>.</p>
             <p>Lamentamos informarte que tu cita con <strong>{$therapist}</strong>
                del dia <strong>{$date}</strong> a las <strong>{$start}</strong>
                ha sido <strong>cancelada</strong>.</p>
             <p>Si deseas reagendar, puedes hacerlo en cualquier momento desde nuestra plataforma.</p>
             <p><a href='{$this->frontendUrl}/book'
                   style='background:#4f46e5;color:#fff;padding:10px 20px;border-radius:6px;text-decoration:none'>
               Agendar nueva cita
             </a></p>"
        );

        return $this->send($to, $subject, $body);
    }

    /** Recuperacion de contrasena. */
    public function sendPasswordReset(string $toEmail, string $toName, string $resetToken): bool
    {
        $resetLink = $this->frontendUrl . '/reset-password?token=' . urlencode($resetToken) . '&type=recovery';
        $subject   = 'Recupera tu contrasena — Mente Livre';
        $body      = $this->wrapHtml($subject,
            "<p>Hola, <strong>{$toName}</strong>.</p>
             <p>Recibimos una solicitud para restablecer la contrasena de tu cuenta.</p>
             <p><a href='{$resetLink}'
                   style='background:#4f46e5;color:#fff;padding:10px 20px;border-radius:6px;text-decoration:none'>
               Restablecer contrasena
             </a></p>
             <p style='color:#888;font-size:13px'>Este enlace expira en 1 hora.
                Si no solicitaste esto, ignora este mensaje.</p>"
        );

        return $this->send($toEmail, $subject, $body);
    }

    /** Alerta al admin de pago pendiente de revision. */
    public function sendPaymentReviewAlert(array $appointment): bool
    {
        $patientName  = $appointment['patient_name']    ?? 'N/A';
        $patientEmail = $appointment['patient_email']   ?? 'N/A';
        $therapist    = $appointment['therapist_name']  ?? 'N/A';
        $date         = $appointment['appointment_date'] ?? '';
        $amount       = $appointment['final_price']     ?? 0;
        $method       = $appointment['payment_method']  ?? 'N/A';
        $id           = $appointment['id'] ?? '';

        $subject = "[Mente Livre] Pago pendiente de revision #{$id}";
        $body    = $this->wrapHtml($subject,
            "<p>Un paciente ha enviado un comprobante de pago que requiere tu confirmacion.</p>
             <table style='border-collapse:collapse;width:100%'>
               <tr><td style='padding:6px 12px;color:#555'>Paciente</td>
                   <td style='padding:6px 12px'>{$patientName} ({$patientEmail})</td></tr>
               <tr><td style='padding:6px 12px;color:#555'>Psicologo</td>
                   <td style='padding:6px 12px'>{$therapist}</td></tr>
               <tr><td style='padding:6px 12px;color:#555'>Fecha cita</td>
                   <td style='padding:6px 12px'>{$date}</td></tr>
               <tr><td style='padding:6px 12px;color:#555'>Monto</td>
                   <td style='padding:6px 12px'>S/ {$amount}</td></tr>
               <tr><td style='padding:6px 12px;color:#555'>Metodo</td>
                   <td style='padding:6px 12px'>{$method}</td></tr>
             </table>
             <p><a href='{$this->frontendUrl}/admin/appointments/{$id}'
                   style='background:#dc2626;color:#fff;padding:10px 20px;border-radius:6px;text-decoration:none'>
               Revisar pago
             </a></p>"
        );

        return $this->send($this->adminEmail, $subject, $body);
    }

    // -------------------------------------------------------------------------
    // Metodos privados
    // -------------------------------------------------------------------------

    /**
     * Envia email via Resend HTTP API (curl).
     * Falla silenciosamente si RESEND_API_KEY no esta configurada.
     */
    private function send(string $toEmail, string $subject, string $htmlBody): bool
    {
        if (empty($this->apiKey)) {
            error_log("[EmailService] RESEND_API_KEY no configurada. Email no enviado a: {$toEmail}");
            return false;
        }

        $payload = json_encode([
            'from'    => $this->from,
            'to'      => [$toEmail],
            'subject' => $subject,
            'html'    => $htmlBody,
        ]);

        $ch = curl_init('https://api.resend.com/emails');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . $this->apiKey,
                'Content-Type: application/json',
            ],
            CURLOPT_TIMEOUT        => 10,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr  = curl_error($ch);
        curl_close($ch);

        if ($curlErr) {
            error_log("[EmailService] cURL error enviando a {$toEmail}: {$curlErr}");
            return false;
        }

        if ($httpCode < 200 || $httpCode >= 300) {
            error_log("[EmailService] Resend error {$httpCode} enviando a {$toEmail}: {$response}");
            return false;
        }

        return true;
    }

    /** Envuelve el contenido en template HTML. */
    private function wrapHtml(string $title, string $content): string
    {
        $year = date('Y');
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
      <table width='600' cellpadding='0' cellspacing='0'
             style='background:#fff;border-radius:8px;overflow:hidden;box-shadow:0 2px 8px rgba(0,0,0,.08)'>
        <tr><td style='background:#4f46e5;padding:24px 32px'>
          <h1 style='margin:0;color:#fff;font-size:22px'>Mente Livre</h1>
        </td></tr>
        <tr><td style='padding:32px;color:#333;font-size:15px;line-height:1.6'>
          {$content}
        </td></tr>
        <tr><td style='background:#f4f4f5;padding:16px 32px;text-align:center;color:#999;font-size:12px'>
          &copy; {$year} Mente Livre. Todos los derechos reservados.
        </td></tr>
      </table>
    </td></tr>
  </table>
</body>
</html>";
    }
}
