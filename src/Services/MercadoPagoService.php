<?php

namespace App\Services;

use App\Core\Database;
use PDO;

/**
 * Servicio para procesar pagos con MercadoPago API.
 * 
 * Usa Card Payment Brick: el frontend tokeniza la tarjeta y envía el token
 * al backend, que lo usa para crear el cobro vía POST /v1/payments.
 * 
 * Documentación:
 * - Payments API: https://www.mercadopago.com.pe/developers/en/reference/payments/_payments/post
 * - Card Payment Brick: https://www.mercadopago.com.pe/developers/en/docs/checkout-bricks/card-payment-brick/introduction
 */
class MercadoPagoService
{
    private PDO $db;
    private string $accessToken;
    private string $apiBaseUrl = 'https://api.mercadopago.com';

    public function __construct()
    {
        $this->db = Database::getInstance()->getConnection();
        $this->accessToken = $_ENV['MP_ACCESS_TOKEN'] ?? '';
        
        if (empty($this->accessToken)) {
            error_log('[MercadoPago] WARNING: MP_ACCESS_TOKEN no configurado en .env');
        }
    }

    /**
     * Procesar un pago con el token generado por Card Payment Brick.
     *
     * @param array $data Datos del pago:
     *   - token: string (token de tarjeta generado por Brick)
     *   - transaction_amount: float (monto a cobrar)
     *   - installments: int (cuotas, default 1)
     *   - payment_method_id: string (visa, mastercard, etc.)
     *   - payer: array ['email' => string]  
     *   - description: string (descripción del cobro)
     * @return array Respuesta de MercadoPago con payment_id, status, status_detail
     * @throws \Exception si hay error en la llamada a la API
     */
    public function processPayment(array $data): array
    {
        $url = $this->apiBaseUrl . '/v1/payments';
        $idempotencyKey = $this->generateUUID();

        $payload = [
            'transaction_amount' => (float) $data['transaction_amount'],
            'installments' => (int) ($data['installments'] ?? 1),
            'payment_method_id' => $data['payment_method_id'],
            'payer' => [
                'email' => $data['payer']['email'],
            ],
            'description' => $data['description'] ?? 'Sesión de consejería - Mente Livre',
        ];

        if (!empty($data['token'])) {
            $payload['token'] = $data['token'];
        }

        // Agregar issuer_id si fue proporcionado (requerido para algunos métodos de pago)
        if (!empty($data['issuer_id'])) {
            $payload['issuer_id'] = (int) $data['issuer_id'];
        }

        $headers = [
            'Authorization: Bearer ' . $this->accessToken,
            'Content-Type: application/json',
            'X-Idempotency-Key: ' . $idempotencyKey,
        ];

        error_log('[MercadoPago] Procesando pago - Amount: ' . $payload['transaction_amount'] . ', Method: ' . $payload['payment_method_id']);

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);

        if ($curlError) {
            error_log('[MercadoPago] cURL error: ' . $curlError);
            throw new \Exception('Error de conexión con MercadoPago: ' . $curlError);
        }

        $responseData = json_decode($response, true);

        if ($httpCode >= 400) {
            $errorMessage = $responseData['message'] ?? 'Error desconocido de MercadoPago';
            $errorCause = isset($responseData['cause']) ? json_encode($responseData['cause']) : '';
            error_log('[MercadoPago] API error (HTTP ' . $httpCode . '): ' . $errorMessage . ' | Cause: ' . $errorCause);
            throw new \Exception('Error de MercadoPago: ' . $errorMessage, $httpCode);
        }

        $result = [
            'payment_id' => $responseData['id'] ?? null,
            'status' => $responseData['status'] ?? 'unknown',
            'status_detail' => $responseData['status_detail'] ?? '',
        ];

        error_log('[MercadoPago] Pago procesado - ID: ' . $result['payment_id'] . ', Status: ' . $result['status']);

        return $result;
    }

    /**
     * Crear una preferencia de pago para Checkout Pro (MercadoPago Pro).
     *
     * @param array $data Datos de la preferencia:
     *   - transaction_amount: float (monto a cobrar)
     *   - payer_email: string (email del pagador)
     *   - appointment_id: string (ID de la cita para external_reference)
     *   - description: string (opcional)
     * @return array Respuesta de MercadoPago con preference_id e init_point
     */
    public function createPreference(array $data): array
    {
        $url = $this->apiBaseUrl . '/checkout/preferences';

        // Determinar URLs de retorno (back_urls)
        $frontendUrl = $_ENV['FRONTEND_URL'] ?? 'https://mentelivre.org';
        
        $payload = [
            'items' => [
                [
                    'id' => $data['appointment_id'],
                    'title' => $data['description'] ?? 'Sesión de consejería - Mente Livre',
                    'quantity' => 1,
                    'unit_price' => (float) $data['transaction_amount'],
                    'currency_id' => 'PEN', 
                ]
            ],
            'payer' => [
                'email' => $data['payer_email'],
            ],
            'back_urls' => [
                'success' => $frontendUrl . '/payment/success',
                'failure' => $frontendUrl . '/payment/failure',
                'pending' => $frontendUrl . '/payment/pending',
            ],
            'auto_return' => 'all',
            'external_reference' => $data['appointment_id'],
            'notification_url' => ($_ENV['BACKEND_URL'] ?? 'https://backend.mentelivre.org') . '/webhooks/mercadopago',
            'statement_descriptor' => 'MENTE LIVRE',
        ];

        $headers = [
            'Authorization: Bearer ' . $this->accessToken,
            'Content-Type: application/json',
        ];

        error_log('[MercadoPago] Creando preferencia - Amount: ' . $data['transaction_amount'] . ', Email: ' . $data['payer_email']);

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);

        if ($curlError) {
            error_log('[MercadoPago] cURL error: ' . $curlError);
            throw new \Exception('Error de conexión con MercadoPago: ' . $curlError);
        }

        $responseData = json_decode($response, true);

        if ($httpCode >= 400) {
            $errorMessage = $responseData['message'] ?? 'Error desconocido de MercadoPago';
            error_log('[MercadoPago] API error (HTTP ' . $httpCode . '): ' . $errorMessage);
            throw new \Exception('Error de MercadoPago al crear preferencia: ' . $errorMessage, $httpCode);
        }

        return [
            'id' => $responseData['id'] ?? null,
            'init_point' => $responseData['init_point'] ?? null,
            'sandbox_init_point' => $responseData['sandbox_init_point'] ?? null,
        ];
    }

    /**
     * Consultar estado de un pago en MercadoPago.
     *
     * @param string $paymentId ID del pago en MercadoPago
     * @return array Datos del pago
     */
    public function getPayment(string $paymentId): array
    {
        $url = $this->apiBaseUrl . '/v1/payments/' . $paymentId;

        $headers = [
            'Authorization: Bearer ' . $this->accessToken,
            'Content-Type: application/json',
        ];

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_HTTPGET => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        $responseData = json_decode($response, true);

        if ($httpCode >= 400) {
            throw new \Exception('Error consultando pago: ' . ($responseData['message'] ?? 'Unknown'));
        }

        return $responseData;
    }

    /**
     * Guardar resultado del pago MercadoPago en la tabla appointment_payments.
     * Actualiza el registro existente con los campos de pago externo.
     *
     * @param string $appointmentId ID de la cita
     * @param array $paymentResult Resultado de processPayment()
     */
    public function savePaymentResult(string $appointmentId, array $paymentResult): void
    {
        // Primero verificar si ya existe un registro de pago para esta cita
        $checkSql = "SELECT id FROM appointment_payments WHERE appointment_id = :appointment_id";
        $checkStmt = $this->db->prepare($checkSql);
        $checkStmt->execute([':appointment_id' => $appointmentId]);
        $existing = $checkStmt->fetch(PDO::FETCH_ASSOC);

        if ($existing) {
            // Actualizar registro existente
            $sql = "UPDATE appointment_payments SET 
                payment_method = 'mercadopago',
                external_payment_id = :external_payment_id,
                external_status = :external_status,
                external_status_detail = :external_status_detail,
                payment_confirmed_at = CASE WHEN :status = 'approved' THEN NOW() ELSE payment_confirmed_at END,
                updated_at = NOW()
            WHERE appointment_id = :appointment_id";

            $stmt = $this->db->prepare($sql);
            $stmt->execute([
                ':external_payment_id' => (string) $paymentResult['payment_id'],
                ':external_status' => $paymentResult['status'],
                ':external_status_detail' => $paymentResult['status_detail'],
                ':status' => $paymentResult['status'],
                ':appointment_id' => $appointmentId,
            ]);
        } else {
            // Crear nuevo registro
            $sql = "INSERT INTO appointment_payments (
                id, appointment_id, original_price, discount_applied, final_price,
                payment_method, external_payment_id, external_status, external_status_detail,
                payment_confirmed_at
            ) VALUES (
                :id, :appointment_id, :original_price, 0, :final_price,
                'mercadopago', :external_payment_id, :external_status, :external_status_detail,
                CASE WHEN :status = 'approved' THEN NOW() ELSE NULL END
            )";

            $stmt = $this->db->prepare($sql);
            $stmt->execute([
                ':id' => $this->generateUUID(),
                ':appointment_id' => $appointmentId,
                ':original_price' => $paymentResult['transaction_amount'] ?? 0,
                ':final_price' => $paymentResult['transaction_amount'] ?? 0,
                ':external_payment_id' => (string) $paymentResult['payment_id'],
                ':external_status' => $paymentResult['status'],
                ':external_status_detail' => $paymentResult['status_detail'],
                ':status' => $paymentResult['status'],
            ]);
        }
    }

    /**
     * Actualizar estado de la cita basándose en el resultado del pago.
     *
     * @param string $appointmentId ID de la cita
     * @param string $paymentStatus Estado del pago de MercadoPago
     */
    public function updateAppointmentStatus(string $appointmentId, string $paymentStatus): void
    {
        $statusMap = [
            'approved' => 'confirmed',
            'pending' => 'payment_review',
            'in_process' => 'payment_review',
            'rejected' => 'pending_payment', // Mantener pending_payment para que reintente
        ];

        $newStatus = $statusMap[$paymentStatus] ?? 'pending_payment';

        $sql = "UPDATE appointments SET status = :status, updated_at = NOW() WHERE id = :id";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            ':status' => $newStatus,
            ':id' => $appointmentId,
        ]);

        error_log("[MercadoPago] Appointment {$appointmentId} status updated to: {$newStatus} (MP status: {$paymentStatus})");

        // Si fue aprobado, revisar si había un new_package_id para crear el paciente package
        if ($newStatus === 'confirmed') {
            $this->processPackageCreation($appointmentId);
        }
    }

    private function processPackageCreation(string $appointmentId) {
        try {
            // Get appointment details
            $aptStmt = $this->db->prepare("SELECT * FROM appointments WHERE id = ?");
            $aptStmt->execute([$appointmentId]);
            $apt = $aptStmt->fetch(PDO::FETCH_ASSOC);

            if ($apt && !empty($apt['new_package_id'])) {
                // Determine if package is already created for this appointment to avoid dupes
                if (!empty($apt['patient_package_id'])) {
                    return; // Already processed
                }

                $pkgId = $apt['new_package_id'];
                
                // Get SessionPackage details
                $spStmt = $this->db->prepare("SELECT * FROM session_packages WHERE id = ?");
                $spStmt->execute([$pkgId]);
                $sp = $spStmt->fetch(PDO::FETCH_ASSOC);

                if ($sp) {
                    $patientPackageId = $this->generateUUID();
                    $insertSql = "INSERT INTO patient_packages 
                                  (id, user_id, patient_email, therapist_id, package_id, total_sessions, used_sessions, total_price_paid, status)
                                  VALUES (?, ?, ?, ?, ?, ?, 1, ?, 'active')";
                    $stmt = $this->db->prepare($insertSql);
                    $stmt->execute([
                        $patientPackageId,
                        $apt['user_id'],
                        $apt['patient_email'],
                        $apt['therapist_id'],
                        $pkgId,
                        $sp['session_count'],
                        $apt['final_price']
                    ]);

                    // Link the package to the appointment and clear new_package_id
                    $updAptSql = "UPDATE appointments SET patient_package_id = ?, new_package_id = NULL WHERE id = ?";
                    $updApt = $this->db->prepare($updAptSql);
                    $updApt->execute([$patientPackageId, $appointmentId]);

                    error_log("[MercadoPago] Patient package {$patientPackageId} created for appointment {$appointmentId}");
                }
            }
        } catch (\Exception $e) {
            error_log("[MercadoPago] Error creating package for appointment {$appointmentId}: " . $e->getMessage());
        }
    }


    /**
     * Generar UUID v4
     */
    private function generateUUID(): string
    {
        $data = random_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}
