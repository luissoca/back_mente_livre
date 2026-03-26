<?php

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Services\PromoCodeService;
use OpenApi\Attributes as OA;

#[OA\Tag(name: "Promo Codes", description: "Gestión de códigos promocionales")]
class PromoCodeController {
    private PromoCodeService $promoCodeService;

    public function __construct() {
        $this->promoCodeService = new PromoCodeService();
    }

    #[OA\Get(
        path: '/promo-codes',
        summary: 'Listar códigos promocionales',
        operationId: 'getPromoCodes',
        security: [['bearerAuth' => []]],
        tags: ['Promo Codes'],
        parameters: [
            new OA\Parameter(name: 'is_active', in: 'query', required: false, schema: new OA\Schema(type: 'boolean')),
            new OA\Parameter(name: 'valid_now', in: 'query', required: false, schema: new OA\Schema(type: 'boolean'))
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Lista de códigos promocionales',
                content: new OA\JsonContent(
                    type: 'array',
                    items: new OA\Items(ref: '#/components/schemas/PromoCode')
                )
            )
        ]
    )]
    public function index(): void {
        // Obtener query parameters directamente desde $_GET
        $filters = [];
        
        if (isset($_GET['is_active']) && $_GET['is_active'] !== '') {
            $filters['is_active'] = filter_var($_GET['is_active'], FILTER_VALIDATE_BOOLEAN) ? 1 : 0;
        }
        
        if (isset($_GET['valid_now']) && $_GET['valid_now'] !== '') {
            $filters['valid_now'] = filter_var($_GET['valid_now'], FILTER_VALIDATE_BOOLEAN) ? 1 : 0;
        }
        
        $promoCodes = $this->promoCodeService->getAll($filters);
        Response::json(['data' => $promoCodes]);
    }

    #[OA\Get(
        path: '/promo-codes/{id}',
        summary: 'Obtener código por ID',
        operationId: 'getPromoCode',
        security: [['bearerAuth' => []]],
        tags: ['Promo Codes'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string'))
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Detalle del código promocional',
                content: new OA\JsonContent(ref: '#/components/schemas/PromoCode')
            ),
            new OA\Response(response: 404, description: 'Código no encontrado')
        ]
    )]
    public function show(string $id): void {
        $promoCode = $this->promoCodeService->getById($id);
        
        if (!$promoCode) {
            Response::json(['error' => 'Código promocional no encontrado'], 404);
            return;
        }
        
        Response::json(['data' => $promoCode]);
    }

    #[OA\Post(
        path: '/promo-codes',
        summary: 'Crear código promocional',
        operationId: 'createPromoCode',
        security: [['bearerAuth' => []]],
        tags: ['Promo Codes'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(ref: '#/components/schemas/PromoCodeCreate')
        ),
        responses: [
            new OA\Response(
                response: 201,
                description: 'Código creado exitosamente',
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
        // Obtener datos del body JSON directamente
        $data = json_decode(file_get_contents('php://input'), true);
        
        if (!$data) {
            Response::json(['error' => 'Datos inválidos'], 400);
            return;
        }
        
        // Validaciones
        $required = ['code', 'discount_percent'];
        foreach ($required as $field) {
            if (empty($data[$field])) {
                Response::json(['error' => "El campo $field es requerido"], 400);
                return;
            }
        }
        
        // Validar que el código no exista
        $existing = $this->promoCodeService->getByCode($data['code']);
        if ($existing) {
            Response::json(['error' => 'El código promocional ya existe'], 400);
            return;
        }
        
        try {
            $id = $this->promoCodeService->create($data);
            Response::json([
                'id' => $id,
                'message' => 'Código promocional creado exitosamente'
            ], 201);
        } catch (\Exception $e) {
            Response::json(['error' => 'Error al crear el código: ' . $e->getMessage()], 500);
        }
    }

    #[OA\Put(
        path: '/promo-codes/{id}',
        summary: 'Actualizar código promocional',
        operationId: 'updatePromoCode',
        security: [['bearerAuth' => []]],
        tags: ['Promo Codes'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string'))
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(ref: '#/components/schemas/PromoCodeUpdate')
        ),
        responses: [
            new OA\Response(response: 200, description: 'Código actualizado exitosamente'),
            new OA\Response(response: 404, description: 'Código no encontrado')
        ]
    )]
    public function update(string $id): void {
        $promoCode = $this->promoCodeService->getById($id);
        
        if (!$promoCode) {
            Response::json(['error' => 'Código promocional no encontrado'], 404);
            return;
        }
        
        // Obtener datos del body JSON directamente
        $data = json_decode(file_get_contents('php://input'), true);
        
        if (!$data) {
            Response::json(['error' => 'Datos inválidos'], 400);
            return;
        }
        
        try {
            $this->promoCodeService->update($id, $data);
            Response::json(['message' => 'Código promocional actualizado exitosamente']);
        } catch (\Exception $e) {
            Response::json(['error' => 'Error al actualizar el código: ' . $e->getMessage()], 500);
        }
    }

    #[OA\Post(
        path: '/promo-codes/validate',
        summary: 'Validar código promocional',
        operationId: 'validatePromoCode',
        tags: ['Promo Codes'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['code', 'user_email'],
                properties: [
                    new OA\Property(property: 'code', type: 'string'),
                    new OA\Property(property: 'user_email', type: 'string', format: 'email'),
                    new OA\Property(property: 'base_price', type: 'number', description: 'Precio base del terapeuta sobre el cual aplicar el descuento (opcional)')
                ]
            )
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'Resultado de la validación',
                content: new OA\JsonContent(ref: '#/components/schemas/PromoCodeValidation')
            )
        ]
    )]
    public function validate(): void {
        // Obtener datos del body JSON directamente
        $data = json_decode(file_get_contents('php://input'), true);
        
        if (!$data || empty($data['code']) || empty($data['user_email'])) {
            Response::json(['error' => 'Se requiere código y email'], 400);
            return;
        }
        
        // Obtener precio base del terapeuta si se proporciona (opcional)
        $basePrice = isset($data['base_price']) && is_numeric($data['base_price']) 
            ? (float)$data['base_price'] 
            : null;
        
        $result = $this->promoCodeService->validate($data['code'], $data['user_email'], $basePrice);
        
        Response::json(['data' => $result]);
    }

    #[OA\Delete(
        path: '/promo-codes/{id}',
        summary: 'Eliminar código promocional',
        operationId: 'deletePromoCode',
        security: [['bearerAuth' => []]],
        tags: ['Promo Codes'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string'))
        ],
        responses: [
            new OA\Response(response: 200, description: 'Código eliminado exitosamente'),
            new OA\Response(response: 404, description: 'Código no encontrado')
        ]
    )]
    public function destroy(string $id): void {
        $promoCode = $this->promoCodeService->getById($id);
        
        if (!$promoCode) {
            Response::json(['error' => 'Código promocional no encontrado'], 404);
            return;
        }
        
        try {
            $this->promoCodeService->delete($id);
            Response::json(['message' => 'Código promocional eliminado exitosamente']);
        } catch (\Exception $e) {
            Response::json(['error' => 'Error al eliminar el código: ' . $e->getMessage()], 500);
        }
    }
}

#[OA\Schema(
    schema: 'PromoCode',
    properties: [
        new OA\Property(property: 'id', type: 'string'),
        new OA\Property(property: 'code', type: 'string'),
        new OA\Property(property: 'is_active', type: 'boolean'),
        new OA\Property(property: 'discount_percent', type: 'integer'),
        new OA\Property(property: 'base_price', type: 'number'),
        new OA\Property(property: 'max_uses_total', type: 'integer', nullable: true),
        new OA\Property(property: 'max_uses_per_user', type: 'integer'),
        new OA\Property(property: 'max_sessions', type: 'integer'),
        new OA\Property(property: 'valid_from', type: 'string', format: 'date-time', nullable: true),
        new OA\Property(property: 'valid_until', type: 'string', format: 'date-time', nullable: true),
        new OA\Property(property: 'uses_count', type: 'integer'),
        new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
        new OA\Property(property: 'updated_at', type: 'string', format: 'date-time')
    ]
)]
class PromoCodeSchema {}

#[OA\Schema(
    schema: 'PromoCodeCreate',
    required: ['code', 'discount_percent'],
    properties: [
        new OA\Property(property: 'code', type: 'string'),
        new OA\Property(property: 'is_active', type: 'boolean', default: true),
        new OA\Property(property: 'discount_percent', type: 'integer'),
        new OA\Property(property: 'base_price', type: 'number', default: 25.00),
        new OA\Property(property: 'max_uses_total', type: 'integer', nullable: true),
        new OA\Property(property: 'max_uses_per_user', type: 'integer', default: 1),
        new OA\Property(property: 'max_sessions', type: 'integer', default: 1),
        new OA\Property(property: 'valid_from', type: 'string', format: 'date-time', nullable: true),
        new OA\Property(property: 'valid_until', type: 'string', format: 'date-time', nullable: true)
    ]
)]
class PromoCodeCreateSchema {}

#[OA\Schema(
    schema: 'PromoCodeUpdate',
    properties: [
        new OA\Property(property: 'code', type: 'string'),
        new OA\Property(property: 'is_active', type: 'boolean'),
        new OA\Property(property: 'discount_percent', type: 'integer'),
        new OA\Property(property: 'base_price', type: 'number'),
        new OA\Property(property: 'max_uses_total', type: 'integer'),
        new OA\Property(property: 'max_uses_per_user', type: 'integer'),
        new OA\Property(property: 'max_sessions', type: 'integer'),
        new OA\Property(property: 'valid_from', type: 'string', format: 'date-time'),
        new OA\Property(property: 'valid_until', type: 'string', format: 'date-time')
    ]
)]
class PromoCodeUpdateSchema {}

#[OA\Schema(
    schema: 'PromoCodeValidation',
    properties: [
        new OA\Property(property: 'valid', type: 'boolean'),
        new OA\Property(property: 'message', type: 'string'),
        new OA\Property(property: 'promo_code_id', type: 'string', nullable: true),
        new OA\Property(property: 'code', type: 'string', nullable: true),
        new OA\Property(property: 'discount_percent', type: 'integer', nullable: true),
        new OA\Property(property: 'base_price', type: 'number', nullable: true),
        new OA\Property(property: 'discount_amount', type: 'number', nullable: true),
        new OA\Property(property: 'final_price', type: 'number', nullable: true),
        new OA\Property(property: 'max_sessions', type: 'integer', nullable: true)
    ]
)]
class PromoCodeValidationSchema {}
