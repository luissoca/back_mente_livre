<?php

namespace App\Controllers;

use App\Core\BaseController;
use App\Core\Response;
use App\Services\EmailDomainRuleService;
use OpenApi\Attributes as OA;

#[OA\Tag(name: "Email Domain Rules", description: "Gestión de reglas de dominios de email")]
class EmailDomainRuleController extends BaseController {
    private EmailDomainRuleService $ruleService;

    public function __construct() {
        $this->ruleService = new EmailDomainRuleService();
    }

    #[OA\Get(
        path: '/email-domain-rules',
        summary: 'Obtener todas las reglas de dominios',
        operationId: 'getEmailDomainRules',
        tags: ['Email Domain Rules'],
        parameters: [
            new OA\Parameter(name: 'active_only', in: 'query', required: false, schema: new OA\Schema(type: 'boolean'))
        ],
        responses: [
            new OA\Response(response: 200, description: 'Lista de reglas de dominios')
        ]
    )]
    public function index(): void {
        $activeOnly = isset($_GET['active_only']) ? filter_var($_GET['active_only'], FILTER_VALIDATE_BOOLEAN) : null;
        
        $rules = $this->ruleService->getAll($activeOnly);
        Response::json(['data' => $rules]);
    }

    #[OA\Get(
        path: '/email-domain-rules/{id}',
        summary: 'Obtener regla por ID',
        operationId: 'getEmailDomainRule',
        tags: ['Email Domain Rules'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string'))
        ],
        responses: [
            new OA\Response(response: 200, description: 'Regla encontrada'),
            new OA\Response(response: 404, description: 'Regla no encontrada')
        ]
    )]
    public function show(string $id): void {
        $rule = $this->ruleService->getById($id);
        
        if (!$rule) {
            Response::error('Regla no encontrada', 404);
        }
        
        Response::json(['data' => $rule]);
    }

    #[OA\Post(
        path: '/email-domain-rules',
        summary: 'Crear regla de dominio',
        operationId: 'createEmailDomainRule',
        security: [['bearerAuth' => []]],
        tags: ['Email Domain Rules'],
        responses: [
            new OA\Response(response: 201, description: 'Regla creada exitosamente'),
            new OA\Response(response: 400, description: 'Datos inválidos')
        ]
    )]
    public function store(): void {
        $data = $this->getJsonInput();
        
        $this->validateRequired($data, ['domain', 'rule_type']);
        
        if (!in_array($data['rule_type'], ['whitelist', 'blacklist'])) {
            Response::error('rule_type debe ser "whitelist" o "blacklist"', 400);
        }
        
        try {
            $id = $this->ruleService->create($data);
            Response::json(['data' => ['id' => $id]], 201);
        } catch (\Exception $e) {
            $statusCode = strpos($e->getMessage(), 'Ya existe') !== false ? 409 : 400;
            Response::error($e->getMessage(), $statusCode);
        }
    }

    #[OA\Put(
        path: '/email-domain-rules/{id}',
        summary: 'Actualizar regla de dominio',
        operationId: 'updateEmailDomainRule',
        security: [['bearerAuth' => []]],
        tags: ['Email Domain Rules'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string'))
        ],
        responses: [
            new OA\Response(response: 200, description: 'Regla actualizada exitosamente'),
            new OA\Response(response: 404, description: 'Regla no encontrada')
        ]
    )]
    public function update(string $id): void {
        try {
            $rule = $this->ruleService->getById($id);
            
            if (!$rule) {
                Response::error('Regla no encontrada', 404);
                return;
            }
            
            $data = $this->getJsonInput();
            
            if (isset($data['rule_type']) && !in_array($data['rule_type'], ['whitelist', 'blacklist'])) {
                Response::error('rule_type debe ser "whitelist" o "blacklist"', 400);
                return;
            }
            
            $success = $this->ruleService->update($id, $data);
            if (!$success) {
                Response::error('No se pudo actualizar la regla', 400);
                return;
            }
            
            // Obtener la regla actualizada para devolverla en la respuesta
            $updatedRule = $this->ruleService->getById($id);
            Response::json(['data' => $updatedRule]);
        } catch (\Exception $e) {
            $statusCode = strpos($e->getMessage(), 'Ya existe') !== false ? 409 : 500;
            Response::error($e->getMessage(), $statusCode);
        }
    }

    #[OA\Delete(
        path: '/email-domain-rules/{id}',
        summary: 'Eliminar regla de dominio',
        operationId: 'deleteEmailDomainRule',
        security: [['bearerAuth' => []]],
        tags: ['Email Domain Rules'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string'))
        ],
        responses: [
            new OA\Response(response: 200, description: 'Regla eliminada exitosamente'),
            new OA\Response(response: 404, description: 'Regla no encontrada')
        ]
    )]
    public function destroy(string $id): void {
        $rule = $this->ruleService->getById($id);
        
        if (!$rule) {
            Response::error('Regla no encontrada', 404);
        }
        
        $success = $this->ruleService->delete($id);
        if (!$success) {
            Response::error('No se pudo eliminar la regla', 400);
        }
        
        Response::json(['message' => 'Regla eliminada exitosamente']);
    }
}
