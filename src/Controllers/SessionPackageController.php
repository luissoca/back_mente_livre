<?php

namespace App\Controllers;

use App\Services\SessionPackageService;
use Exception;

class SessionPackageController {
    private $service;

    public function __construct() {
        $this->service = new SessionPackageService();
    }

    public function index() {
        try {
            // Si es admin, puede ver todos. Si no, solo los activos.
            $isAdmin = isset($_SESSION['user']['role']) && $_SESSION['user']['role'] === 'admin';
            $packages = $this->service->getAllPackages(!$isAdmin);
            
            http_response_code(200);
            echo json_encode([
                'status' => 'success',
                'data' => $packages
            ]);
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode([
                'status' => 'error',
                'message' => 'Error al obtener paquetes de sesiones: ' . $e->getMessage()
            ]);
        }
    }

    public function create() {
        try {
            $data = json_decode(file_get_contents('php://input'), true);
            
            if (!isset($data['name']) || !isset($data['session_count']) || !isset($data['discount_percent'])) {
                http_response_code(400);
                echo json_encode(['status' => 'error', 'message' => 'Faltan campos requeridos.']);
                return;
            }
            
            $id = $this->service->createPackage($data);
            
            http_response_code(201);
            echo json_encode([
                'status' => 'success',
                'message' => 'Paquete creado exitosamente',
                'data' => ['id' => $id]
            ]);
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(['status' => 'error', 'message' => 'Error al crear paquete: ' . $e->getMessage()]);
        }
    }

    public function update($id) {
        try {
            $data = json_decode(file_get_contents('php://input'), true);
            
            $updated = $this->service->updatePackage($id, $data);
            
            if ($updated) {
                http_response_code(200);
                echo json_encode(['status' => 'success', 'message' => 'Paquete actualizado']);
            } else {
                http_response_code(400);
                echo json_encode(['status' => 'error', 'message' => 'No se pudo actualizar el paquete o no hubo cambios']);
            }
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(['status' => 'error', 'message' => 'Error al actualizar paquete: ' . $e->getMessage()]);
        }
    }

    public function delete($id) {
        try {
            $deleted = $this->service->deletePackage($id);
            if ($deleted) {
                http_response_code(200);
                echo json_encode(['status' => 'success', 'message' => 'Paquete eliminado']);
            } else {
                http_response_code(404);
                echo json_encode(['status' => 'error', 'message' => 'Paquete no encontrado']);
            }
        } catch (Exception $e) {
            http_response_code(400);
            echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
        }
    }
}
