óá<?php

namespace App\Controllers;

use App\Core\BaseController;
use App\Core\Response;
use App\Services\TherapistService;
use OpenApi\Attributes as OA;

#[OA\Tag(name: "Therapists", description: "Gestión de terapeutas")]
class TherapistController extends BaseController {
    private TherapistService $therapistService;

    public function __construct() {
        $this->therapistService = new TherapistService();
    }

    #[OA\Get(
        path: '/therapists',
        summary: 'Listar terapeutas',
        operationId: 'getTherapists',
        tags: ['Therapists'],
        parameters: [
            new OA\Parameter(name: 'include_inactive', in: 'query', required: false, schema: new OA\Schema(type: 'boolean'))
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Lista de terapeutas',
                content: new OA\JsonContent(
                    type: 'array',
                    items: new OA\Items(ref: '#/components/schemas/Therapist')
                )
            )
        ]
    )]
    public function index(): void {
        $includeInactive = isset($_GET['include_inactive']) && filter_var($_GET['include_inactive'], FILTER_VALIDATE_BOOLEAN);
        
        if ($includeInactive) {
            $therapists = $this->therapistService->getAll();
        } else {
            $therapists = $this->therapistService->getAllActive();
        }
        
        Response::json(['data' => $therapists]);
    }

    #[OA\Get(
        path: '/therapists/{id}',
        summary: 'Obtener terapeuta por ID',
        operationId: 'getTherapist',
        tags: ['Therapists'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string'))
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Detalle del terapeuta',
                content: new OA\JsonContent(ref: '#/components/schemas/Therapist')
            ),
            new OA\Response(response: 404, description: 'Terapeuta no encontrado')
        ]
    )]
    public function show(string $id): void {
        $therapist = $this->therapistService->getById($id);
        
        if (!$therapist) {
            Response::json(['error' => 'Terapeuta no encontrado'], 404);
            return;
        }
        
        Response::json(['data' => $therapist]);
    }

    #[OA\Post(
        path: '/therapists',
        summary: 'Crear terapeuta',
        operationId: 'createTherapist',
        security: [['bearerAuth' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(ref: '#/components/schemas/TherapistCreate')
        ),
        tags: ['Therapists'],
        responses: [
            new OA\Response(
                response: 201,
                description: 'Terapeuta creado exitosamente',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'id', type: 'string'),
                        new OA\Property(property: 'message', type: 'string')
                    ]
                )
            ),
            new OA\Response(response: 400, description: 'Datos inválidos')
        ]
    )]
    public function store(): void {
        $data = $this->getJsonInput();
        
        // Validaciones básicas
        if (empty($data['name']) || empty($data['university']) || empty($data['hourly_rate'])) {
            Response::json(['error' => 'Faltan datos requeridos (name, university, hourly_rate)'], 400);
            return;
        }
        
        try {
            $id = $this->therapistService->create($data);
            $this->therapistService->invalidateCache();
            Response::json([
                'data' => [
                    'id' => $id,
                    'message' => 'Terapeuta creado exitosamente'
                ]
            ], 201);
        } catch (\Exception $e) {
            Response::json(['error' => 'Error al crear terapeuta: ' . $e->getMessage()], 500);
        }
    }

    #[OA\Put(
        path: '/therapists/{id}',
        summary: 'Actualizar terapeuta',
        operationId: 'updateTherapist',
        security: [['bearerAuth' => []]],
        tags: ['Therapists'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string'))
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(ref: '#/components/schemas/TherapistUpdate')
        ),
        responses: [
            new OA\Response(response: 200, description: 'Terapeuta actualizado exitosamente'),
            new OA\Response(response: 404, description: 'Terapeuta no encontrado')
        ]
    )]
    public function update(string $id): void {
        $therapist = $this->therapistService->getById($id);
        
        if (!$therapist) {
            Response::json(['error' => 'Terapeuta no encontrado'], 404);
            return;
        }
        
        $data = $this->getJsonInput();
        
        try {
            $this->therapistService->update($id, $data);
            $this->therapistService->invalidateCache();
            Response::json(['message' => 'Terapeuta actualizado exitosamente']);
        } catch (\Exception $e) {
            Response::json(['error' => 'Error al actualizar terapeuta: ' . $e->getMessage()], 500);
        }
    }
}

#[OA\Schema(
    schema: 'Therapist',
    properties: [
        new OA\Property(property: 'id', type: 'string'),
        new OA\Property(property: 'user_id', type: 'string', nullable: true),
        new OA\Property(property: 'name', type: 'string'),
        new OA\Property(property: 'university', type: 'string'),
        new OA\Property(property: 'age', type: 'integer', nullable: true),
        new OA\Property(property: 'years_experience', type: 'integer', nullable: true),
        new OA\Property(property: 'role_title', type: 'string', nullable: true),
        new OA\Property(property: 'specialty', type: 'string', nullable: true),
        new OA\Property(property: 'therapeutic_approach', type: 'string', nullable: true),
        new OA\Property(property: 'short_description', type: 'string', nullable: true),
        new OA\Property(property: 'modality', type: 'string', nullable: true),
        new OA\Property(property: 'hourly_rate', type: 'number'),
        new OA\Property(property: 'is_active', type: 'boolean'),
        new OA\Property(property: 'is_visible_in_about', type: 'boolean'),
        new OA\Property(property: 'field_visibility', type: 'string', nullable: true),
        new OA\Property(
            property: 'experience_topics', 
            type: 'array', 
            items: new OA\Items(type: 'string')
        ),
        new OA\Property(
            property: 'population_served', 
            type: 'array', 
            items: new OA\Items(type: 'string')
        ),
        new OA\Property(
            property: 'photos', 
            type: 'array', 
            items: new OA\Items(ref: '#/components/schemas/TherapistPhoto')
        ),
        new OA\Property(
            property: 'pricing', 
            type: 'object', 
            additionalProperties: new OA\AdditionalProperties(ref: '#/components/schemas/TherapistPricingTier')
        ),
        new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
        new OA\Property(property: 'updated_at', type: 'string', format: 'date-time')
    ]
)]
class TherapistSchema {}

#[OA\Schema(
    schema: 'TherapistCreate',
    required: ['name', 'university', 'hourly_rate'],
    properties: [
        new OA\Property(property: 'user_id', type: 'string', nullable: true),
        new OA\Property(property: 'name', type: 'string'),
        new OA\Property(property: 'university', type: 'string'),
        new OA\Property(property: 'age', type: 'integer', nullable: true),
        new OA\Property(property: 'years_experience', type: 'integer', nullable: true),
        new OA\Property(property: 'role_title', type: 'string', nullable: true),
        new OA\Property(property: 'specialty', type: 'string', nullable: true),
        new OA\Property(property: 'therapeutic_approach', type: 'string', nullable: true),
        new OA\Property(property: 'short_description', type: 'string', nullable: true),
        new OA\Property(property: 'modality', type: 'string', nullable: true),
        new OA\Property(property: 'hourly_rate', type: 'number'),
        new OA\Property(property: 'is_active', type: 'boolean', default: true),
        new OA\Property(property: 'is_visible_in_about', type: 'boolean', default: false),
        new OA\Property(property: 'field_visibility', type: 'object', nullable: true),
        new OA\Property(
            property: 'experience_topics', 
            type: 'array', 
            items: new OA\Items(type: 'string'),
            nullable: true
        ),
        new OA\Property(
            property: 'pricing', 
            type: 'object', 
            additionalProperties: new OA\AdditionalProperties(
                properties: [
                    new OA\Property(property: 'price', type: 'number'),
                    new OA\Property(property: 'enabled', type: 'boolean', default: true)
                ]
            ),
            nullable: true
        )
    ]
)]
class TherapistCreateSchema {}

#[OA\Schema(
    schema: 'TherapistUpdate',
    properties: [
        new OA\Property(property: 'user_id', type: 'string', nullable: true),
        new OA\Property(property: 'name', type: 'string'),
        new OA\Property(property: 'university', type: 'string'),
        new OA\Property(property: 'age', type: 'integer'),
        new OA\Property(property: 'years_experience', type: 'integer'),
        new OA\Property(property: 'role_title', type: 'string'),
        new OA\Property(property: 'specialty', type: 'string'),
        new OA\Property(property: 'therapeutic_approach', type: 'string'),
        new OA\Property(property: 'short_description', type: 'string'),
        new OA\Property(property: 'modality', type: 'string'),
        new OA\Property(property: 'hourly_rate', type: 'number'),
        new OA\Property(property: 'is_active', type: 'boolean'),
        new OA\Property(property: 'is_visible_in_about', type: 'boolean'),
        new OA\Property(property: 'field_visibility', type: 'object', nullable: true)
    ]
)]
class TherapistUpdateSchema {}

#[OA\Schema(
    schema: 'TherapistPhoto',
    properties: [
        new OA\Property(property: 'photo_type', type: 'string'),
        new OA\Property(property: 'photo_url', type: 'string'),
        new OA\Property(property: 'photo_position', type: 'string', nullable: true),
        new OA\Property(property: 'is_active', type: 'boolean', default: true),
        new OA\Property(property: 'created_at', type: 'string', format: 'date-time')
    ]
)]
class TherapistPhotoSchema {}

#[OA\Schema(
    schema: 'TherapistPricingTier',
    properties: [
        new OA\Property(property: 'price', type: 'number'),
        new OA\Property(property: 'enabled', type: 'boolean')
    ]
)]
class TherapistPricingTierSchema {}
