<?php

namespace App\Controllers;

use App\Services\PatientPackageService;
use Exception;

class PatientPackageController {
    private $service;

    public function __construct() {
        $this->service = new PatientPackageService();
    }

    public function myPackages() {
        try {
            $currentUser = $GLOBALS['current_user'] ?? null;
            $email = $currentUser['email'] ?? null;

            if (!$email) {
                http_response_code(401);
                echo json_encode(['status' => 'error', 'message' => 'No autorizado']);
                return;
            }

            $packages = $this->service->getUserPackages($email);
            
            http_response_code(200);
            echo json_encode([
                'status' => 'success',
                'data' => $packages
            ]);
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode([
                'status' => 'error',
                'message' => 'Error al obtener paquetes del paciente: ' . $e->getMessage()
            ]);
        }
    }
}
