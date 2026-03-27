<?php

namespace App\Controllers;

use App\Services\IzipayService;
use App\Services\AppointmentService;
use Exception;

class IzipayController
{
    private $izipayService;
    private $appointmentService;

    public function __construct()
    {
        $this->izipayService = new IzipayService();
        $this->appointmentService = new AppointmentService();
    }

    public function createPayment()
    {
        header('Content-Type: application/json');
        
        try {
            $data = json_decode(file_get_contents('php://input'), true);
            
            if (!isset($data['appointment_id'])) {
                throw new Exception('appointment_id is required');
            }

            $appointmentId = $data['appointment_id'];
            
            // Fetch appointment details
            $appointment = $this->appointmentService->getById($appointmentId);
            
            if (!$appointment) {
                throw new Exception('Appointment not found');
            }
            
            // Check if user is payer (email match) - Optional security check
            
            // Calculate amount from appointment
            $amount = $appointment['final_price'] > 0 
                ? $appointment['final_price'] 
                : ($appointment['original_price'] > 0 ? $appointment['original_price'] : 0);

            if ($amount <= 0) {
                 // Fallback or error if price is 0 (should use other flow for free appts)
                 // Or fetch therapist rate if not set
                 $amount = 25.00; // Fallback
            }

            // Generate Token
            $response = $this->izipayService->createPayment(
                $amount,
                'PEN', // Currency
                $appointmentId, // Order ID
                $appointment['patient_email'] ?? 'customer@mentelivre.org',
                [
                    'appointment_id' => $appointmentId
                ]
            );

            echo json_encode(['status' => 'success', 'data' => $response]);

        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
        }
    }

    public function webhook()
    {
        // Izipay / Lyra IPN: data comes as POST form-encoded fields
        // Key fields: kr-hash, kr-hash-algorithm, kr-hash-key, kr-answer, kr-answer-type
        $rawPost = file_get_contents('php://input');

        try {
            // Support both form-encoded and JSON bodies
            if (empty($_POST) || !isset($_POST['kr-hash'])) {
                $data = json_decode($rawPost, true);
                if (is_array($data) && isset($data['kr-hash'])) {
                    $_POST = $data;
                } else {
                    // Try URL-encoded body
                    parse_str($rawPost, $parsed);
                    if (isset($parsed['kr-hash'])) {
                        $_POST = $parsed;
                    }
                }
            }

            if (!isset($_POST['kr-hash']) || !isset($_POST['kr-answer'])) {
                throw new Exception('Missing required IPN fields (kr-hash or kr-answer)');
            }

            $krAnswer    = $_POST['kr-answer'];      // Raw JSON string — do NOT decode before hashing
            $receivedHash = $_POST['kr-hash'];
            $hashKey     = $_POST['kr-hash-key'] ?? 'sha256_hmac'; // 'sha256_hmac' = HMAC key, 'password' = shop password

            // -------------------------------------------------------
            // Select the correct key depending on kr-hash-key value
            // Izipay sends kr-hash-key = 'sha256_hmac' when using HMAC,
            // or 'password' when using the shop password.
            // -------------------------------------------------------
            if ($hashKey === 'sha256_hmac') {
                $secretKey = $_ENV['IZIPAY_HMAC_KEY'] ?? '';
            } else {
                // Fallback: use the shop password (IZIPAY_PASSWORD)
                $secretKey = $_ENV['IZIPAY_PASSWORD'] ?? '';
            }

            if (empty($secretKey)) {
                throw new Exception('IPN secret key not configured (IZIPAY_HMAC_KEY)');
            }

            // Lyra/Izipay HMAC-SHA256: hash the raw kr-answer string
            $calculatedHash = hash_hmac('sha256', $krAnswer, $secretKey);

            if (!hash_equals($calculatedHash, $receivedHash)) {
                error_log('[IzipayWebhook] Invalid signature. Expected: ' . $calculatedHash . ' Got: ' . $receivedHash);
                http_response_code(403);
                echo "Invalid signature";
                return;
            }

            // Signature valid — process the payment result
            $answer      = json_decode($krAnswer, true);
            $orderStatus = $answer['orderStatus'] ?? '';
            $orderId     = $answer['orderDetails']['orderId'] ?? null;
            $transactionId = $answer['transactions'][0]['uuid'] ?? null;

            error_log('[IzipayWebhook] orderStatus=' . $orderStatus . ' orderId=' . $orderId);

            if ($orderStatus === 'PAID' && $orderId) {
                $this->appointmentService->update($orderId, ['status' => 'confirmed']);
                // Guardar transaction_id y datos de pago para auditoría
                                    $amount = $answer['orderDetails']['orderTotalAmount'] ?? 0;
                                    $currency = $answer['orderDetails']['orderCurrency'] ?? 'PEN';
                                    $db = \App\Core\Database::getInstance();
                                    $checkStmt = $db->prepare(
                                                                "SELECT id FROM appointment_payments WHERE appointment_id = ?"
                                                            );
                                    $checkStmt->execute([$orderId]);
                                    $existing = $checkStmt->fetch(\PDO::FETCH_ASSOC);
                                    if ($existing) {
                                                                $payStmt = $db->prepare(
                                                                                                "UPDATE appointment_payments
                                                                                                                             SET transaction_id = ?, payment_method = 'izipay',
                                                                                                                                                              amount_paid = ?, currency = ?,
                                                                                                                                                                                               payment_confirmed_at = NOW(), status = 'confirmed'
                                                                                                                                                                                                                            WHERE appointment_id = ?"
                                                                                            );
                                                                $payStmt->execute([$transactionId, $amount / 100, $currency, $orderId]);
                                    } else {
                                                                $payStmt = $db->prepare(
                                                                                                "INSERT INTO appointment_payments
                                                                                                                                (appointment_id, transaction_id, payment_method, amount_paid, currency, payment_confirmed_at, status)
                                                                                                                                                             VALUES (?, ?, 'izipay', ?, ?, NOW(), 'confirmed')"
                                                                                            );
                                                                $payStmt->execute([$orderId, $transactionId, $amount / 100, $currency]);
                                    }
                                    error_log('[IzipayWebhook] transaction_id=' . $transactionId . ' guardado para appointment=' . $orderId);
            }

            echo "OK";

        } catch (Exception $e) {
            error_log('[IzipayWebhook] Error: ' . $e->getMessage());
            http_response_code(400);
            echo "Error: " . $e->getMessage();
        }
    }
}
