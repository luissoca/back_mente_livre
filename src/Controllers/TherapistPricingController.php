<?php

namespace App\Controllers;

use App\Core\BaseController;
use App\Core\Response;
use App\Services\TherapistPricingService;
use OpenApi\Attributes as OA;

#[OA\Tag(name: "Therapist Pricing", description: "Gestión de precios de terapeutas")]
class TherapistPricingController extends BaseController {
    private TherapistPricingService $pricingService;

    public function __construct() {
        $this->pricingService = new TherapistPricingService();
    }

    #[OA\Get(
        path: '/therapists/{therapistId}/pricing',
        summary: 'Obtener precios de un terapeuta',
        operationId: 'getTherapistPricing',
        tags: ['Therapist Pricing'],
        parameters: [
            new OA\Parameter(name: 'therapistId', in: 'path', required: true, schema: new OA\Schema(type: 'string'))
        ],
        responses: [
            new OA\Response(response: 200, description: 'Lista de precios del terapeuta')
        ]
    )]
    public function index(string $therapistId): void {
        $pricing = $this->pricingService->getByTherapist($therapistId);
        Response::json(['data' => $pricing]);
    }

    #[OA\Get(
        path: '/therapist-pricing/{id}',
        summary: 'Obtener precio por ID',
        operationId: 'getTherapistPricingById',
        tags: ['Therapist Pricing'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string'))
        ],
        responses: [
            new OA\Response(response: 200, description: 'Precio encontrado'),
            new OA\Response(response: 404, description: 'Precio no encontrado')
        ]
    )]
    public function show(string $id): void {
        $pricing = $this->pricingService->getById($id);
        
        if (!$pricing) {
            Response::error('Precio no encontrado', 404);
        }
        
        Response::json(['data' => $pricing]);
    }

    #[OA\Post(
        path: '/therapists/{therapistId}/pricing',
        summary: 'Crear precio para un terapeuta',
        operationId: 'createTherapistPricing',
        security: [['bearerAuth' => []]],
        tags: ['Therapist Pricing'],
        parameters: [
            new OA\Parameter(name: 'therapistId', in: 'path', required: true, schema: new OA\Schema(type: 'string'))
        ],
        responses: [
            new OA\Response(response: 201, description: 'Precio creado exitosamente'),
            new OA\Response(response: 400, description: 'Datos inválidos')
        ]
    )]
    public function store(string $therapistId): void {
        $data = $this->getJsonInput();
        
        $this->validateRequired($data, ['pricing_tier', 'price']);
        
        if (!in_array($data['pricing_tier'], ['university_pe', 'university_international', 'corporate', 'public'])) {
            Response::error('pricing_tier inválido', 400);
        }
        
        $data['therapist_id'] = $therapistId;
        
        $id = $this->pricingService->upsert($data);
        Response::json(['data' => ['id' => $id]], 201);
    }

    #[OA\Put(
        path: '/therapists/{therapistId}/pricing/batch',
        summary: 'Actualizar múltiples precios de un terapeuta',
        operationId: 'updateTherapistPricingBatch',
        security: [['bearerAuth' => []]],
        tags: ['Therapist Pricing'],
        parameters: [
            new OA\Parameter(name: 'therapistId', in: 'path', required: true, schema: new OA\Schema(type: 'string'))
        ],
        responses: [
            new OA\Response(response: 200, description: 'Precios actualizados exitosamente')
        ]
    )]
    public function updateBatch(string $therapistId): void {
        $data = $this->getJsonInput();
        
        if (!is_array($data)) {
            Response::error('Se requiere un objeto con los precios', 400);
        }
        
        $results = $this->pricingService->updateBatch($therapistId, $data);
        Response::json(['data' => $results]);
    }

    #[OA\Put(
        path: '/therapist-pricing/{id}',
        summary: 'Actualizar precio',
        operationId: 'updateTherapistPricing',
        security: [['bearerAuth' => []]],
        tags: ['Therapist Pricing'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string'))
        ],
        responses: [
            new OA\Response(response: 200, description: 'Precio actualizado exitosamente'),
            new OA\Response(response: 404, description: 'Precio no encontrado')
        ]
    )]
    public function update(string $id): void {
        try {
            $pricing = $this->pricingService->getById($id);
            
            if (!$pricing) {
                Response::error('Precio no encontrado', 404);
                return;
            }
            
            $data = $this->getJsonInput();
            
            $success = $this->pricingService->update($id, $data);
            if (!$success) {
                Response::error('No se pudo actualizar el precio', 400);
                return;
            }
            
            // Obtener el precio actualizado para devolverlo en la respuesta
            $updatedPricing = $this->pricingService->getById($id);
            Response::json(['data' => $updatedPricing]);
        } catch (\Exception $e) {
            error_log('Error actualizando precio: ' . $e->getMessage());
            Response::error('Error interno del servidor: ' . $e->getMessage(), 500);
        }
    }

    #[OA\Delete(
        path: '/therapist-pricing/{id}',
        summary: 'Eliminar precio',
        operationId: 'deleteTherapistPricing',
        security: [['bearerAuth' => []]],
        tags: ['Therapist Pricing'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string'))
        ],
        responses: [
            new OA\Response(response: 200, description: 'Precio eliminado exitosamente'),
            new OA\Response(response: 404, description: 'Precio no encontrado')
        ]
    )]
    public function destroy(string $id): void {
        $pricing = $this->pricingService->getById($id);
        
        if (!$pricing) {
            Response::error('Precio no encontrado', 404);
        }
        
        $success = $this->pricingService->delete($id);
        if (!$success) {
            Response::error('No se pudo eliminar el precio', 400);
        }
        
        Response::json(['message' => 'Precio eliminado exitosamente']);
    }
}
