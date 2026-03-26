<?php

namespace App\Controllers;

use App\Core\BaseController;
use App\Services\B2Service;
use OpenApi\Attributes as OA;

/**
 * Controlador para servir imágenes privadas desde B2
 */
class ImageController extends BaseController {
    private $b2Service;

    public function __construct() {
        $this->b2Service = new B2Service();
    }

    /**
     * Servir imagen desde B2
     * GET /uploads/{path}
     */
    public function show($path) {
        // Validar path para evitar LFI (aunque B2 key es string)
        if (strpos($path, '..') !== false) {
            http_response_code(403);
            echo "Access Denied";
            return;
        }

        $file = $this->b2Service->getFile($path);

        if (!$file) {
            http_response_code(404);
            echo "Image not found";
            return;
        }

        // Configurar headers para servir la imagen
        header("Content-Type: " . $file['ContentType']);
        header("Content-Length: " . $file['ContentLength']);
        header("Cache-Control: public, max-age=31536000"); // Cache por 1 año

        // Stream contenido
        echo $file['Body'];
    }
}
