<?php

namespace App\Controllers;

use App\Core\BaseController;
use App\Core\Response;
use App\Services\TeamProfileService;
use OpenApi\Attributes as OA;

#[OA\Tag(name: "Team Profiles", description: "Gestión de perfiles del equipo")]
class TeamProfileController extends BaseController {
    private TeamProfileService $teamProfileService;

    public function __construct() {
        $this->teamProfileService = new TeamProfileService();
    }

    #[OA\Get(
        path: '/team-profiles',
        summary: 'Listar perfiles del equipo',
        operationId: 'getTeamProfiles',
        tags: ['Team Profiles'],
        parameters: [
            new OA\Parameter(name: 'member_type', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['clinical', 'institutional'])),
            new OA\Parameter(name: 'is_visible_public', in: 'query', required: false, schema: new OA\Schema(type: 'boolean'))
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Lista de perfiles del equipo',
                content: new OA\JsonContent(
                    type: 'array',
                    items: new OA\Items(ref: '#/components/schemas/TeamProfile')
                )
            )
        ]
    )]
    public function index(): void {
        // Obtener query parameters directamente desde $_GET
        $memberType = $_GET['member_type'] ?? null;
        $isVisiblePublic = $_GET['is_visible_public'] ?? null;
        
        // Convertir is_visible_public de string a boolean si viene
        if ($isVisiblePublic !== null && $isVisiblePublic !== '') {
            // Convertir 'true', '1', 'yes' a 1, y 'false', '0', 'no' a 0
            $isVisiblePublic = filter_var($isVisiblePublic, FILTER_VALIDATE_BOOLEAN) ? 1 : 0;
        }
        
        $filters = [];
        if ($memberType !== null && $memberType !== '') {
            $filters['member_type'] = $memberType;
        }
        if ($isVisiblePublic !== null && $isVisiblePublic !== '') {
            $filters['is_visible_public'] = $isVisiblePublic;
        }
        
        $profiles = $this->teamProfileService->getAll($filters);
        Response::json(['data' => $profiles]);
    }

    #[OA\Get(
        path: '/team-profiles/{id}',
        summary: 'Obtener perfil por ID',
        operationId: 'getTeamProfile',
        tags: ['Team Profiles'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string'))
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Detalle del perfil',
                content: new OA\JsonContent(ref: '#/components/schemas/TeamProfile')
            ),
            new OA\Response(response: 404, description: 'Perfil no encontrado')
        ]
    )]
    public function show(string $id): void {
        $profile = $this->teamProfileService->getById($id);
        
        if (!$profile) {
            Response::json(['error' => 'Perfil no encontrado'], 404);
            return;
        }
        
        Response::json(['data' => $profile]);
    }

    #[OA\Post(
        path: '/team-profiles',
        summary: 'Crear nuevo perfil',
        operationId: 'createTeamProfile',
        security: [['bearerAuth' => []]],
        tags: ['Team Profiles'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(ref: '#/components/schemas/TeamProfileCreate')
        ),
        responses: [
            new OA\Response(
                response: 201,
                description: 'Perfil creado exitosamente',
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
        
        $this->validateRequired($data, ['member_type', 'full_name', 'public_role_title']);
        
        try {
            $id = $this->teamProfileService->create($data);
            Response::json([
                'id' => $id,
                'message' => 'Perfil creado exitosamente'
            ], 201);
        } catch (\Exception $e) {
            Response::json(['error' => 'Error al crear el perfil: ' . $e->getMessage()], 500);
        }
    }

    #[OA\Put(
        path: '/team-profiles/{id}',
        summary: 'Actualizar perfil',
        operationId: 'updateTeamProfile',
        security: [['bearerAuth' => []]],
        tags: ['Team Profiles'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string'))
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(ref: '#/components/schemas/TeamProfileUpdate')
        ),
        responses: [
            new OA\Response(response: 200, description: 'Perfil actualizado exitosamente'),
            new OA\Response(response: 404, description: 'Perfil no encontrado')
        ]
    )]
    public function update(string $id): void {
        $profile = $this->teamProfileService->getById($id);
        
        if (!$profile) {
            Response::json(['error' => 'Perfil no encontrado'], 404);
            return;
        }
        
        $data = $this->getJsonInput();
        
        try {
            $this->teamProfileService->update($id, $data);
            
            // Obtener el perfil actualizado para devolverlo en la respuesta
            $updatedProfile = $this->teamProfileService->getById($id);
            Response::json(['data' => $updatedProfile]);
        } catch (\Exception $e) {
            Response::json(['error' => 'Error al actualizar el perfil: ' . $e->getMessage()], 500);
        }
    }

    #[OA\Delete(
        path: '/team-profiles/{id}',
        summary: 'Eliminar perfil',
        operationId: 'deleteTeamProfile',
        security: [['bearerAuth' => []]],
        tags: ['Team Profiles'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string'))
        ],
        responses: [
            new OA\Response(response: 200, description: 'Perfil eliminado exitosamente'),
            new OA\Response(response: 404, description: 'Perfil no encontrado')
        ]
    )]
    public function destroy(string $id): void {
        $profile = $this->teamProfileService->getById($id);
        
        if (!$profile) {
            Response::json(['error' => 'Perfil no encontrado'], 404);
            return;
        }
        
        try {
            $this->teamProfileService->delete($id);
            Response::json(['message' => 'Perfil eliminado exitosamente']);
        } catch (\Exception $e) {
            Response::json(['error' => 'Error al eliminar el perfil: ' . $e->getMessage()], 500);
        }
    }
}

#[OA\Schema(
    schema: 'TeamProfile',
    properties: [
        new OA\Property(property: 'id', type: 'string'),
        new OA\Property(property: 'member_type', type: 'string', enum: ['clinical', 'institutional']),
        new OA\Property(property: 'linked_therapist_id', type: 'string', nullable: true),
        new OA\Property(property: 'linked_therapist_name', type: 'string', nullable: true),
        new OA\Property(property: 'full_name', type: 'string'),
        new OA\Property(property: 'public_role_title', type: 'string'),
        new OA\Property(property: 'professional_level', type: 'string', nullable: true),
        new OA\Property(property: 'public_bio', type: 'string', nullable: true),
        new OA\Property(property: 'friendly_photo_url', type: 'string', nullable: true),
        new OA\Property(property: 'is_visible_public', type: 'boolean'),
        new OA\Property(property: 'order_index', type: 'integer'),
        new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
        new OA\Property(property: 'updated_at', type: 'string', format: 'date-time')
    ]
)]
class TeamProfileSchema {}

#[OA\Schema(
    schema: 'TeamProfileCreate',
    required: ['member_type', 'full_name', 'public_role_title'],
    properties: [
        new OA\Property(property: 'member_type', type: 'string', enum: ['clinical', 'institutional']),
        new OA\Property(property: 'linked_therapist_id', type: 'string', nullable: true),
        new OA\Property(property: 'full_name', type: 'string'),
        new OA\Property(property: 'public_role_title', type: 'string'),
        new OA\Property(property: 'public_bio', type: 'string', nullable: true),
        new OA\Property(property: 'friendly_photo_url', type: 'string', nullable: true),
        new OA\Property(property: 'is_visible_public', type: 'boolean', default: true),
        new OA\Property(property: 'order_index', type: 'integer', default: 0)
    ]
)]
class TeamProfileCreateSchema {}

#[OA\Schema(
    schema: 'TeamProfileUpdate',
    properties: [
        new OA\Property(property: 'member_type', type: 'string', enum: ['clinical', 'institutional']),
        new OA\Property(property: 'linked_therapist_id', type: 'string', nullable: true),
        new OA\Property(property: 'full_name', type: 'string'),
        new OA\Property(property: 'public_role_title', type: 'string'),
        new OA\Property(property: 'public_bio', type: 'string'),
        new OA\Property(property: 'friendly_photo_url', type: 'string'),
        new OA\Property(property: 'is_visible_public', type: 'boolean'),
        new OA\Property(property: 'order_index', type: 'integer')
    ]
)]
class TeamProfileUpdateSchema {}
