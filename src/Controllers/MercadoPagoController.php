<?php

namespace App\Controllers;

use App\Core\BaseController;
use App\Core\Response;
use App\Services\MercadoPagoService;

/**
 * Controlador para pagos con MercadoPago.
 * 
 * Endpoints:
 * - POST /payments/mercadopago — Procesar pago con token de Card Payment Brick
 * - POST /webhooks/mercadopago — Recibir notificaciones de MercadoPago (webhook)
 * - GET /payments/mercadopago/public-key — Obtener public key para el frontend
 */
class MercadoPagoController extends BaseController
{
    private MercadoPagoService $mercadoPagoService;

    public function __construct()
    {
        $this->mercadoPagoService = new MercadoPagoService();
    }

    /**
     * Procesar pago con token de Card Payment Brick.
     * 
     * Recibe el token generado por el Brick en el frontend y crea el cobro
     * a través de la API de MercadoPago.
     * 
     * POST /payments/mercadopago
     * Body: {
     *   token: string,
     *   transaction_amount: number,
     *   installments: number,
     *   payment_method_id: string,
     *   issuer_id?: number,
     *   payer: { email: string },
     *   appointment_id: string,
     *   description?: string
     * }
     */
    public function processPayment()
    {
        try {
            $data = $this->getJsonInput();

            // Validar campos requeridos
            $this->validateRequired($data, [
                'transaction_amount',
                'payment_method_id',
                'appointment_id',
            ]);

            // Validar que payer.email exista
            if (empty($data['payer']['email'])) {
                Response::error('El campo payer.email es requerido', 400);
                return;
            }

            $appointmentId = $data['appointment_id'];

            // Procesar pago con MercadoPago
            $paymentResult = $this->mercadoPagoService->processPayment($data);

            // Guardar resultado del pago en BD
            $paymentResult['transaction_amount'] = $data['transaction_amount'];
            $this->mercadoPagoService->savePaymentResult($appointmentId, $paymentResult);

            // Actualizar estado de la cita según resultado del pago
            $this->mercadoPagoService->updateAppointmentStatus($appointmentId, $paymentResult['status']);

            $message = $this->getStatusMessage($paymentResult['status'], $paymentResult['status_detail']);

            Response::success([
                'payment_id' => $paymentResult['payment_id'],
                'status' => $paymentResult['status'],
                'status_detail' => $paymentResult['status_detail'],
            ], $message);

        } catch (\Exception $e) {
            error_log('[MercadoPagoController] Error procesando pago: ' . $e->getMessage());
            Response::error('Error procesando pago: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Obtener un mensaje amigable para el usuario basado en el estado y detalle.
     */
    private function getStatusMessage($status, $statusDetail)
    {
        if ($status === 'approved') {
            return 'payment:status.approved';
        }

        if (in_array($status, ['pending', 'in_process'])) {
            return 'payment:status.pending';
        }

        // Map status_detail to translation keys
        // These keys must exist in public/locales/{lang}/payment.json under "errors"
        $key = 'payment:errors.' . $statusDetail;
        
        // Return key. The frontend will fallback to a default if key is missing.
        return $key;
    }

    /**
     * Webhook de MercadoPago — Recibe notificaciones de cambios de estado.
     * 
     * POST /webhooks/mercadopago
     * MercadoPago envía notificaciones de tipo "payment" cuando cambia el estado.
     * 
     * Body (de MercadoPago): {
     *   action: string,
     *   api_version: string,
     *   data: { id: string },
     *   date_created: string,
     *   id: number,
     *   live_mode: bool,
     *   type: string
     * }
     */
    public function webhook()
    {
        try {
            $data = $this->getJsonInput();

            error_log('[MercadoPago Webhook] Notificación recibida: ' . json_encode($data));

            // Verificar que sea una notificación de pago
            $type = $data['type'] ?? '';
            if ($type !== 'payment') {
                // Responder 200 OK para que MP no reintente (no es un tipo que nos interese)
                Response::json(['received' => true]);
                return;
            }

            $paymentId = $data['data']['id'] ?? null;
            if (!$paymentId) {
                Response::json(['received' => true, 'error' => 'No payment ID']);
                return;
            }

            // Consultar estado actual del pago en MercadoPago
            $paymentData = $this->mercadoPagoService->getPayment((string) $paymentId);
            $status = $paymentData['status'] ?? 'unknown';

            error_log("[MercadoPago Webhook] Payment {$paymentId} status: {$status}");

            // Buscar la cita asociada a este payment_id en nuestra BD
            $db = \App\Core\Database::getInstance()->getConnection();
            $stmt = $db->prepare("SELECT appointment_id FROM appointment_payments WHERE external_payment_id = :payment_id");
            $stmt->execute([':payment_id' => (string) $paymentId]);
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);

            if ($row) {
                $appointmentId = $row['appointment_id'];

                // Actualizar estado en BD
                $this->mercadoPagoService->savePaymentResult($appointmentId, [
                    'payment_id' => $paymentId,
                    'status' => $status,
                    'status_detail' => $paymentData['status_detail'] ?? '',
                ]);
                $this->mercadoPagoService->updateAppointmentStatus($appointmentId, $status);

                error_log("[MercadoPago Webhook] Appointment {$appointmentId} actualizado a status basado en: {$status}");
            } else {
                error_log("[MercadoPago Webhook] No se encontró cita para payment_id: {$paymentId}");
            }

            // Siempre responder 200 para que MP no reintente
            Response::json(['received' => true]);

        } catch (\Exception $e) {
            error_log('[MercadoPago Webhook] Error: ' . $e->getMessage());
            // Aún así responder 200 para evitar reintentos innecesarios
            Response::json(['received' => true, 'error' => $e->getMessage()]);
        }
    }

    /**
     * Obtener la Public Key de MercadoPago para el frontend.
     * Evita hardcodear la key en el frontend.
     * 
     * GET /payments/mercadopago/public-key
     */
    public function getPublicKey()
    {
        $publicKey = $_ENV['MP_PUBLIC_KEY'] ?? '';

        if (empty($publicKey)) {
            Response::error('MercadoPago no está configurado', 500);
            return;
        }

        Response::success([
            'public_key' => $publicKey,
        ]);
    }

    /**
     * Crear una preferencia de pago para Checkout Pro.
     * 
     * POST /payments/mercadopago/preference
     */
    public function createPreference()
    {
        try {
            $data = $this->getJsonInput();

            $this->validateRequired($data, [
                'transaction_amount',
                'appointment_id',
                'payer_email',
            ]);

            $result = $this->mercadoPagoService->createPreference([
                'transaction_amount' => $data['transaction_amount'],
                'appointment_id' => $data['appointment_id'],
                'payer_email' => $data['payer_email'],
                'description' => $data['description'] ?? 'Sesión de consejería - Mente Livre',
            ]);

            Response::success($result);

        } catch (\Exception $e) {
            error_log('[MercadoPagoController] Error creando preferencia: ' . $e->getMessage());
            Response::error('Error al crear preferencia de pago: ' . $e->getMessage(), 500);
        }
    }
}
