<?php

namespace App\Controllers;

use App\Core\BaseController;
use App\Core\Response;
use App\Services\CulqiService;

class CulqiController extends BaseController
{
    private CulqiService $culqiService;

    public function __construct()
    {
        $this->culqiService = new CulqiService();
    }

    public function processPayment()
    {
        try {
            $data = $this->getJsonInput();

            $this->validateRequired($data, ['token', 'amount', 'email', 'appointment_id']);

            $result = $this->culqiService->createCharge($data);
            
            // Save to DB
            $this->culqiService->savePaymentResult($data['appointment_id'], $result, (float)$data['amount']);
            
            // Update appointment status if approved
            if ($result['status'] === 'approved') {
                $this->culqiService->updateAppointmentStatus($data['appointment_id'], 'approved');
            }

            Response::success($result);

        } catch (\Exception $e) {
            error_log('[CulqiController] Error: ' . $e->getMessage());
            Response::error($e->getMessage(), $e->getCode() ?: 500);
        }
    }

    public function getPublicKey()
    {
        $key = $_ENV['CULQI_PUBLIC_KEY'] ?? '';
        if (empty($key)) {
            Response::error('Culqi not configured', 500);
            return;
        }
        Response::success(['public_key' => $key]);
    }
}
