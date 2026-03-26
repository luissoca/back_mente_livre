<?php

namespace App\Services;

use App\Core\Database;
use PDO;

/**
 * Servicio para procesar pagos con Culqi API v2.
 * 
 * Flow Pasarela Embebida:
 * 1. Frontend tokeniza tarjeta con CulqiJS v4 -> obtiene token_id
 * 2. Backend recibe token_id y crea el cargo usando Secret Key
 * 
 * Docs: https://docs.culqi.com/es/documentacion/pagos-online/cargo-unico/cargos/
 */
class CulqiService
{
    private PDO $db;
    private string $secretKey;
    private string $apiBaseUrl = 'https://api.culqi.com/v2';

    public function __construct()
    {
        $this->db = Database::getInstance()->getConnection();
        $this->secretKey = $_ENV['CULQI_PRIVATE_KEY'] ?? '';
        
        if (empty($this->secretKey)) {
            error_log('[Culqi] WARNING: CULQI_PRIVATE_KEY no configurado en .env');
        }
    }

    /**
     * Crear un cargo en Culqi
     *
     * @param array $data Datos del cargo:
     *   - token: string (source_id)
     *   - amount: float (monto en SOLES)
     *   - email: string
     *   - description: string
     *   - installments: int (opcional)
     * @return array Respuesta de Culqi (id, state, outcome)
     * @throws \Exception
     */
    public function createCharge(array $data): array
    {
        $url = $this->apiBaseUrl . '/charges';
        
        // Culqi espera el monto en céntimos (integer)
        // Ejemplo: S/ 100.00 -> 10000
        $amountInCents = (int) round(((float) $data['amount']) * 100);

        $payload = [
            'amount' => $amountInCents,
            'currency_code' => 'PEN',
            'email' => $data['email'],
            'source_id' => $data['token'],
            'description' => $data['description'] ?? 'Sesión de consejería - Mente Livre',
            'installments' => isset($data['installments']) ? (int)$data['installments'] : 0
        ];

        // Metadata adicional para tracking
        if (!empty($data['metadata'])) {
            $payload['metadata'] = $data['metadata'];
        }
        
        // Antifraude (opcional pero recomendado)
        // 'antifraud_details' podría requerir datos del cliente (nombre, apellido, teléfono)
        // Por ahora lo dejamos simple.

        $headers = [
            'Authorization: Bearer ' . $this->secretKey,
            'Content-Type: application/json',
        ];

        error_log('[Culqi] Creando cargo - Amount: ' . $amountInCents . ' cents, Email: ' . $payload['email']);

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
            error_log('[Culqi] cURL error: ' . $curlError);
            throw new \Exception('Error de conexión con Culqi: ' . $curlError);
        }

        $responseData = json_decode($response, true);

        if ($httpCode >= 400) {
            // Culqi devuelve errores con 'object': 'error', 'type', 'merchant_message', 'user_message'
            $userMessage = $responseData['user_message'] ?? 'Error desconocido';
            $merchantMessage = $responseData['merchant_message'] ?? '';
            
            error_log('[Culqi] API error (HTTP ' . $httpCode . '): ' . $merchantMessage . ' | User msg: ' . $userMessage);
            throw new \Exception('Error de pago: ' . $userMessage, $httpCode);
        }

        // Éxito
        $result = [
            'payment_id' => $responseData['id'] ?? null,
            'status' => 'pending', // Mapearemos el estado de Culqi a nuestro formato interno después
            'culqi_state' => $responseData['outcome']['type'] ?? 'venta_exitosa', // venta_exitosa, venta_fallida, etc. (Wait, structure is different)
            // Revisando docs: 
            // V2 response: { object: 'charge', id: 'chr_...', amount: 100, current_amount: 100, ... outcome: { type: 'venta_exitosa', code: 'AUT0000', merchant_message: '...' } }
            // Pero ojo, 'outcome' puede ser null en versiones antiguas o diferentes estados.
            // Para cargos exitosos HTTP 201.
        ];

        // Determinar estado basado en outcome
        /*
         outcome.type:
         - venta_exitosa
         - venta_fallida (usualmente HTTP 4xx o 402, pero a veces 201 con estado denegado? No, Culqi suele tirar 400/402 si falla)
        */
        
        $outcomeType = $responseData['outcome']['type'] ?? '';
        
        if ($outcomeType === 'venta_exitosa') {
            $result['status'] = 'approved';
            $result['status_detail'] = 'Accredited';
        } else {
            // Si llega aquí con 201 pero no es venta_exitosa (caso raro en Culqi API v2 actual, suele lanzar error)
            $result['status'] = 'rejected';
            $result['status_detail'] = $responseData['outcome']['merchant_message'] ?? 'Error en el proceso';
        }

        return $result;
    }

    /**
     * Guardar resultado en BD
     */
    public function savePaymentResult(string $appointmentId, array $paymentResult, float $originalAmount): void
    {
        // Reutilizamos la lógica de MercadoPagoService o similar, 
        // pero adaptada. Idealmente esto debería ser abstracto, 
        // pero por ahora repetimos el patrón para ser explícitos.
        
        $checkSql = "SELECT id FROM appointment_payments WHERE appointment_id = :appointment_id";
        $checkStmt = $this->db->prepare($checkSql);
        $checkStmt->execute([':appointment_id' => $appointmentId]);
        $existing = $checkStmt->fetch(PDO::FETCH_ASSOC);

        $params = [
            ':external_payment_id' => (string) $paymentResult['payment_id'],
            ':external_status' => $paymentResult['status'],
            ':external_status_detail' => $paymentResult['status_detail'],
            ':status' => $paymentResult['status'],
            ':appointment_id' => $appointmentId,
        ];

        if ($existing) {
            $sql = "UPDATE appointment_payments SET 
                payment_method = 'culqi',
                external_payment_id = :external_payment_id,
                external_status = :external_status,
                external_status_detail = :external_status_detail,
                payment_confirmed_at = CASE WHEN :status = 'approved' THEN NOW() ELSE payment_confirmed_at END,
                updated_at = NOW()
            WHERE appointment_id = :appointment_id";
        } else {
            $sql = "INSERT INTO appointment_payments (
                id, appointment_id, original_price, discount_applied, final_price,
                payment_method, external_payment_id, external_status, external_status_detail,
                payment_confirmed_at
            ) VALUES (
                UUID(), :appointment_id, :original_price, 0, :final_price,
                'culqi', :external_payment_id, :external_status, :external_status_detail,
                CASE WHEN :status = 'approved' THEN NOW() ELSE NULL END
            )";
            $params[':original_price'] = $originalAmount;
            $params[':final_price'] = $originalAmount;
        }

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
    }

    /**
     * Actualizar estado cita
     */
    public function updateAppointmentStatus(string $appointmentId, string $paymentStatus): void
    {
         $statusMap = [
            'approved' => 'confirmed',
            'pending' => 'payment_review',
            'rejected' => 'pending_payment',
        ];

        $newStatus = $statusMap[$paymentStatus] ?? 'pending_payment';

        $sql = "UPDATE appointments SET status = :status, updated_at = NOW() WHERE id = :id";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([':status' => $newStatus, ':id' => $appointmentId]);
    }
}
