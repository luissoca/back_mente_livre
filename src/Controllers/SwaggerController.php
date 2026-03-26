<?php

namespace App\Controllers;

use App\Core\BaseController;
use App\Core\Response;
use OpenApi\Generator;

class SwaggerController extends BaseController {
    
    /**
     * Generar y devolver documentación OpenAPI en formato JSON
     */
    public function generate() {
        try {
            // Escanear directorio src completo (incluye openapi.php automáticamente)
            $openapi = Generator::scan([__DIR__ . '/../']);
            
            // Guardar en archivo si es posible
            $jsonPath = __DIR__ . '/../../public/swagger.json';
            $yamlPath = __DIR__ . '/../../public/swagger.yaml';
            
            if (is_writable(dirname($jsonPath))) {
                @file_put_contents($jsonPath, $openapi->toJson());
            }
            if (is_writable(dirname($yamlPath))) {
                @file_put_contents($yamlPath, $openapi->toYaml());
            }
            
            // Devolver JSON directamente
            header('Content-Type: application/json; charset=utf-8');
            header('Access-Control-Allow-Origin: *');
            echo $openapi->toJson();
            exit;
        } catch (\Exception $e) {
            error_log('Error generando Swagger: ' . $e->getMessage());
            Response::error('Error generando documentación: ' . $e->getMessage(), 500);
        }
    }
    
    /**
     * Devolver documentación OpenAPI existente
     * Regenera automáticamente si no existe o si se solicita
     */
    public function get() {
        $jsonPath = __DIR__ . '/../../public/swagger.json';
        $regenerate = isset($_GET['regenerate']) && $_GET['regenerate'] === 'true';
        
        // Intentar obtener de variables de entorno (Vercel) si el sistema de archivos es de solo lectura
        $isVercel = isset($_ENV['VERCEL']) || getenv('VERCEL');

        // Regenerar si no existe, si se solicita explícitamente, o si tiene más de 1 hora
        if ($isVercel || !file_exists($jsonPath) || $regenerate || (file_exists($jsonPath) && (time() - filemtime($jsonPath)) > 3600)) {
            try {
                // Escanear directorio src completo (incluye openapi.php automáticamente)
                $openapi = Generator::scan([__DIR__ . '/../']);
                $json = $openapi->toJson();
                
                if (!$isVercel && is_writable(dirname($jsonPath))) {
                    @file_put_contents($jsonPath, $json);
                }

                header('Content-Type: application/json; charset=utf-8');
                header('Access-Control-Allow-Origin: *');
                echo $json;
                exit;
            } catch (\Exception $e) {
                error_log('Error regenerando Swagger: ' . $e->getMessage());
                // Si falla, intentar servir el existente
                if (!file_exists($jsonPath)) {
                    Response::error('Error generando documentación: ' . $e->getMessage(), 500);
                }
            }
        }
        
        if (!file_exists($jsonPath)) {
            Response::error('Documentación no disponible', 404);
        }
        
        header('Content-Type: application/json; charset=utf-8');
        header('Access-Control-Allow-Origin: *');
        readfile($jsonPath);
        exit;
    }
    
    /**
     * Servir Swagger UI
     */
    public function ui() {
        $uiPath = __DIR__ . '/../../public/swagger-ui.html';
        
        if (!file_exists($uiPath)) {
            Response::error('Swagger UI no encontrado', 404);
        }
        
        header('Content-Type: text/html; charset=utf-8');
        readfile($uiPath);
        exit;
    }
}
