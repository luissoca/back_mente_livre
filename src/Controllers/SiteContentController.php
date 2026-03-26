<?php

namespace App\Controllers;

use App\Core\BaseController;
use App\Core\Response;
use App\Services\SiteContentService;
use OpenApi\Attributes as OA;

#[OA\Tag(name: "Site Content", description: "Gestión de contenido institucional del sitio")]
class SiteContentController extends BaseController {
    private SiteContentService $siteContentService;

    public function __construct() {
        $this->siteContentService = new SiteContentService();
    }

    #[OA\Get(
        path: '/site-content',
        summary: 'Obtener contenido institucional',
        operationId: 'getSiteContent',
        tags: ['Site Content'],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Contenido institucional del sitio',
                content: new OA\JsonContent(ref: '#/components/schemas/SiteContent')
            ),
            new OA\Response(response: 404, description: 'Contenido no encontrado')
        ]
    )]
    public function show(): void {
        $content = $this->siteContentService->get();
        
        if (!$content) {
            Response::json(['error' => 'Contenido no encontrado'], 404);
            return;
        }
        
        Response::json(['data' => $content]);
    }

    #[OA\Put(
        path: '/site-content',
        summary: 'Actualizar contenido institucional',
        operationId: 'updateSiteContent',
        security: [['bearerAuth' => []]],
        tags: ['Site Content'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(ref: '#/components/schemas/SiteContentUpdate')
        ),
        responses: [
            new OA\Response(response: 200, description: 'Contenido actualizado exitosamente'),
            new OA\Response(response: 400, description: 'Datos inválidos')
        ]
    )]
    public function update(): void {
        $data = $this->getJsonInput();
        
        try {
            $this->siteContentService->update($data);
            
            // Obtener el contenido actualizado para devolverlo en la respuesta
            $updatedContent = $this->siteContentService->get();
            Response::json(['data' => $updatedContent]);
        } catch (\Exception $e) {
            Response::json(['error' => 'Error al actualizar el contenido: ' . $e->getMessage()], 500);
        }
    }
}

#[OA\Schema(
    schema: 'SiteContent',
    properties: [
        new OA\Property(property: 'id', type: 'string'),
        new OA\Property(property: 'about_title', type: 'string'),
        new OA\Property(property: 'about_intro', type: 'string'),
        new OA\Property(property: 'mission', type: 'string'),
        new OA\Property(property: 'vision', type: 'string'),
        new OA\Property(property: 'approach', type: 'string'),
        new OA\Property(property: 'purpose', type: 'string', nullable: true),
        new OA\Property(
            property: 'values',
            type: 'array',
            items: new OA\Items(type: 'string'),
            nullable: true
        ),
        new OA\Property(property: 'updated_by', type: 'string', nullable: true),
        new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
        new OA\Property(property: 'updated_at', type: 'string', format: 'date-time')
    ]
)]
class SiteContentSchema {}

#[OA\Schema(
    schema: 'SiteContentUpdate',
    properties: [
        new OA\Property(property: 'about_title', type: 'string'),
        new OA\Property(property: 'about_intro', type: 'string'),
        new OA\Property(property: 'mission', type: 'string'),
        new OA\Property(property: 'vision', type: 'string'),
        new OA\Property(property: 'approach', type: 'string'),
        new OA\Property(property: 'purpose', type: 'string'),
        new OA\Property(
            property: 'values',
            type: 'array',
            items: new OA\Items(type: 'string')
        )
    ]
)]
class SiteContentUpdateSchema {}
