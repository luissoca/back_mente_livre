<?php

namespace App\Controllers;

use App\Services\IzipayService;
use App\Services\AppointmentService;
use App\Core\Database;
use Exception;

class IzipayController
    {
            private $izipayService;
            private $appointmentService;

    public function __construct()
        {
                    $this->izipayService      = new IzipayService();
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

                            $appointment = $this->appointmentService->getById($appointmentId);
                            if (!$appointment) {
                                                throw new Exception('Appointment not found');
                            }

                            $amount = $appointment['final_price'] > 0
                                                ? $appointment['final_price']
                                                : ($appointment['original_price'] > 0 ? $appointment['original_price'] : 0);

                            if ($amount <= 0) {
                                                $amount = 25.00;
                            }

                            $response = $this->izipayService->createPayment(
                                                $amount,
                                                'PEN',
                                                $appointmentId,
                                                $appointment['patient_email'] ?? 'customer@mentelivre.org',
                                                ['appointment_id' => $appointmentId]
                                            );

                            echo json_encode(['status' => 'success', 'data' => $response]);

            } catch (Exception $e) {
                            http_response_code(500);
                            echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
            }
}

    public function webhook()
        {
                    $rawPost = file_get_contents('php://input');

                try {
                                // Soportar body form-encoded, JSON y URL-encoded
                                if (empty($_POST) || !isset($_POST['kr-hash'])) {
                                                    $data = json_decode($rawPost, true);
                                                    if (is_array($data) && isset($data['kr-hash'])) {
                                                                            $_POST = $data;
                                                    } else {
                                                                            parse_str($rawPost, $parsed);
                                                                            if (isset($parsed['kr-hash'])) {
                                                                                                        $_POST = $parsed;
                                                                                }
                                                    }
                                }

                                if (!isset($_POST['kr-hash']) || !isset($_POST['kr-answer'])) {
                                                    throw new Exception('Missing required IPN fields (kr-hash or kr-answer)');
                                }

                                $krAnswer     = $_POST['kr-answer'];
                                $receivedHash = $_POST['kr-hash'];
                                $hashKey      = $_POST['kr-hash-key'] ?? 'sha256_hmac';

                                // Seleccionar clave segun kr-hash-key
                                if ($hashKey === 'sha256_hmac') {
                                                    $secretKey = $_ENV['IZIPAY_HMAC_KEY'] ?? '';
                                } else {
                                                    $secretKey = $_ENV['IZIPAY_PASSWORD'] ?? '';
                                }

                                if (empty($secretKey)) {
                                                    throw new Exception('IPN secret key not configured (IZIPAY_HMAC_KEY)');
                                }

                                // Validar firma HMAC-SHA256
                                $calculatedHash = hash_hmac('sha256', $krAnswer, $secretKey);
                                if (!hash_equals($calculatedHash, $receivedHash)) {
                                                    error_log('[IzipayWebhook] Firma invalida. Expected: ' . $calculatedHash . ' Got: ' . $receivedHash);
                                                    http_response_code(403);
                                                    echo "Invalid signature";
                                                    return;
                                }

                                // Firma valida — procesar resultado del pago
                                $answer      = json_decode($krAnswer, true);
                                $orderStatus = $answer['orderStatus'] ?? '';
                                $orderId     = $answer['orderDetails']['orderId'] ?? null;

                                // Extraer datos del pago
                                $transactionId = $answer['transactions'][0]['uuid']   ?? null;
                                $amount        = $answer['orderDetails']['orderTotalAmount'] ?? null;
                                $currency      = $answer['orderDetails']['orderCurrency']   ?? 'PEN';

                                // Convertir centavos a soles (Izipay envia el monto en centavos)
                                if ($amount !== null) {
                                                    $amount = $amount / 100;
                                }

                                error_log('[IzipayWebhook] orderStatus=' . $orderStatus . ' orderId=' . $orderId . ' transactionId=' . $transactionId);

                                if ($orderStatus === 'PAID' && $orderId) {
                                                    // 1. Confirmar la cita
                                    $this->appointmentService->update($orderId, ['status' => 'confirmed']);

                                    // 2. Guardar transaction_id, monto y moneda en appointment_payments
                                    try {
                                                            $db  = Database::getInstance()->getConnection();
                                                            $now = date('Y-m-d H:i:s');

                                                            // Verificar si ya existe el registro de pago
                                                            $checkStmt = $db->prepare(
                                                                                        "SELECT id FROM appointment_payments WHERE appointment_id = :appointment_id"
                                                                                    );
                                                            $checkStmt->execute([':appointment_id' => $orderId]);
                                                            $existing = $checkStmt->fetch(\PDO::FETCH_ASSOC);

                                                            if ($existing) {
                                                                                        // Actualizar registro existente con datos de Izipay
                                                                $updateStmt = $db->prepare(
                                                                                                "UPDATE appointment_payments
                                                                                                                             SET transaction_id        = :transaction_id,
                                                                                                                                                              amount_paid           = :amount,
                                                                                                                                                                                               currency              = :currency,
                                                                                                                                                                                                                                payment_method        = 'izipay',
                                                                                                                                                                                                                                                                 payment_confirmed_at  = :confirmed_at
                                                                                                                                                                                                                                                                                              WHERE appointment_id = :appointment_id"
                                                                                            );
                                                                                        $updateStmt->execute([
                                                                                                                                         ':transaction_id' => $transactionId,
                                                                                                                                         ':amount'         => $amount,
                                                                                                                                         ':currency'       => $currency,
                                                                                                                                         ':confirmed_at'   => $now,
                                                                                                                                         ':appointment_id' => $orderId,
                                                                                                                                     ]);
                                                                                        error_log('[IzipayWebhook] Pago actualizado para appointment ' . $orderId);
                                                            } else {
                                                                                        // Insertar nuevo registro de pago
                                                                $insertId   = sprintf(
                                                                                                '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
                                                                                                mt_rand(0, 0xffff), mt_rand(0, 0xffff),
                                                                                                mt_rand(0, 0xffff),
                                                                                                mt_rand(0, 0x0fff) | 0x4000,
                                                                                                mt_rand(0, 0x3fff) | 0x8000,
                                                                                                mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
                                                                                            );
                                                                                        $insertStmt = $db->prepare(
                                                                                                                        "INSERT INTO appointment_payments
                                                                                                                                                        (id, appointment_id, transaction_id, amount_paid, currency, payment_method, payment_confirmed_at)
                                                                                                                                                                                     VALUES
                                                                                                                                                                                                                     (:id, :appointment_id, :transaction_id, :amount, :currency, 'izipay', :confirmed_at)"
                                                                                                                    );
                                                                                        $insertStmt->execute([
                                                                                                                                         ':id'             => $insertId,
                                                                                                                                         ':appointment_id' => $orderId,
                                                                                                                                         ':transaction_id' => $transactionId,
                                                                                                                                         ':amount'         => $amount,
                                                                                                                                         ':currency'       => $currency,
                                                                                                                                         ':confirmed_at'   => $now,
                                                                                                                                     ]);
                                                                                        error_log('[IzipayWebhook] Pago insertado para appointment ' . $orderId);
                                                            }
                                    } catch (Exception $dbEx) {
                                                            // No interrumpir el webhook si falla el guardado del pago
                                                        error_log('[IzipayWebhook] Error guardando datos de pago: ' . $dbEx->getMessage());
                                    }
                                }

                                echo "OK";

                } catch (Exception $e) {
                                error_log('[IzipayWebhook] Error: ' . $e->getMessage());
                                http_response_code(400);
                                echo "Error: " . $e->getMessage();
                }
        }
    }
