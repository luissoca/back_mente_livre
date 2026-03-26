<?php

namespace App\Controllers;

use App\Core\BaseController;
use App\Core\Response;
use App\Services\TherapistPhotoService;
use OpenApi\Attributes as OA;

#[OA\Tag(name: "Therapist Photos", description: "Gestión de fotos de terapeutas")]
class TherapistPhotoController extends BaseController {
    private TherapistPhotoService $photoService;

    public function __construct() {
        $this->photoService = new TherapistPhotoService();
    }

    #[OA\Get(
        path: '/therapists/{therapistId}/photos',
        summary: 'Obtener fotos de un terapeuta',
        operationId: 'getTherapistPhotos',
        tags: ['Therapist Photos'],
        parameters: [
            new OA\Parameter(name: 'therapistId', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'photo_type', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['profile', 'friendly']))
        ],
        responses: [
            new OA\Response(response: 200, description: 'Lista de fotos del terapeuta')
        ]
    )]
    public function index(string $therapistId): void {
        $photoType = $_GET['photo_type'] ?? null;
        
        $photos = $this->photoService->getByTherapist($therapistId, $photoType);
        Response::json(['data' => $photos]);
    }

    #[OA\Get(
        path: '/therapist-photos/{id}',
        summary: 'Obtener foto por ID',
        operationId: 'getTherapistPhoto',
        tags: ['Therapist Photos'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string'))
        ],
        responses: [
            new OA\Response(response: 200, description: 'Foto encontrada'),
            new OA\Response(response: 404, description: 'Foto no encontrada')
        ]
    )]
    public function show(string $id): void {
        $photo = $this->photoService->getById($id);
        
        if (!$photo) {
            Response::error('Foto no encontrada', 404);
        }
        
        Response::json(['data' => $photo]);
    }

    #[OA\Post(
        path: '/therapists/{therapistId}/photos',
        summary: 'Crear foto para un terapeuta',
        operationId: 'createTherapistPhoto',
        security: [['bearerAuth' => []]],
        tags: ['Therapist Photos'],
        parameters: [
            new OA\Parameter(name: 'therapistId', in: 'path', required: true, schema: new OA\Schema(type: 'string'))
        ],
        responses: [
            new OA\Response(response: 201, description: 'Foto creada exitosamente'),
            new OA\Response(response: 400, description: 'Datos inválidos')
        ]
    )]
    public function store(string $therapistId): void {
        $data = $this->getJsonInput();
        
        $this->validateRequired($data, ['photo_url']);
        
        $data['therapist_id'] = $therapistId;
        
        // Si es la foto activa, desactivar otras del mismo tipo
        if (($data['is_active'] ?? true) && isset($data['photo_type'])) {
            $id = $this->photoService->create($data);
            $this->photoService->deactivateOthers($therapistId, $data['photo_type'], $id);
        } else {
            $id = $this->photoService->create($data);
        }
        
        Response::json(['data' => ['id' => $id]], 201);
    }

    #[OA\Put(
        path: '/therapist-photos/{id}',
        summary: 'Actualizar foto',
        operationId: 'updateTherapistPhoto',
        security: [['bearerAuth' => []]],
        tags: ['Therapist Photos'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string'))
        ],
        responses: [
            new OA\Response(response: 200, description: 'Foto actualizada exitosamente'),
            new OA\Response(response: 404, description: 'Foto no encontrada')
        ]
    )]
    public function update(string $id): void {
        $photo = $this->photoService->getById($id);
        
        if (!$photo) {
            Response::error('Foto no encontrada', 404);
        }
        
        $data = $this->getJsonInput();
        
        // Si se activa esta foto, desactivar otras del mismo tipo
        if (isset($data['is_active']) && $data['is_active']) {
            $this->photoService->deactivateOthers($photo['therapist_id'], $photo['photo_type'], $id);
        }
        
        $success = $this->photoService->update($id, $data);
        if (!$success) {
            Response::error('No se pudo actualizar la foto', 400);
        }
        
        Response::json(['data' => ['id' => $id]]);
    }

    #[OA\Delete(
        path: '/therapist-photos/{id}',
        summary: 'Eliminar foto',
        operationId: 'deleteTherapistPhoto',
        security: [['bearerAuth' => []]],
        tags: ['Therapist Photos'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string'))
        ],
        responses: [
            new OA\Response(response: 200, description: 'Foto eliminada exitosamente'),
            new OA\Response(response: 404, description: 'Foto no encontrada')
        ]
    )]
    public function destroy(string $id): void {
        $photo = $this->photoService->getById($id);
        
        if (!$photo) {
            Response::error('Foto no encontrada', 404);
        }
        
        $success = $this->photoService->delete($id);
        if (!$success) {
            Response::error('No se pudo eliminar la foto', 400);
        }
        
        Response::json(['message' => 'Foto eliminada exitosamente']);
    }
}
