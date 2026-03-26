<?php

namespace App\Controllers;

use App\Core\BaseController;
use App\Core\Response;
use App\Services\WeeklyScheduleOverrideService;
use OpenApi\Attributes as OA;

#[OA\Tag(name: "Schedule Overrides", description: "Gestión de excepciones de horarios semanales")]
class WeeklyScheduleOverrideController extends BaseController {
    private WeeklyScheduleOverrideService $overrideService;

    public function __construct() {
        $this->overrideService = new WeeklyScheduleOverrideService();
    }

    #[OA\Get(
        path: '/therapists/{therapistId}/schedule-overrides',
        summary: 'Obtener excepciones de horario de un terapeuta',
        operationId: 'getScheduleOverrides',
        tags: ['Schedule Overrides'],
        parameters: [
            new OA\Parameter(name: 'therapistId', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'week_start_date', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date'))
        ],
        responses: [
            new OA\Response(response: 200, description: 'Lista de excepciones de horario')
        ]
    )]
    public function index(string $therapistId): void {
        $weekStartDate = $_GET['week_start_date'] ?? null;
        
        if ($weekStartDate) {
            $overrides = $this->overrideService->getByTherapistAndWeek($therapistId, $weekStartDate);
        } else {
            $overrides = $this->overrideService->getByTherapist($therapistId);
        }
        
        Response::json(['data' => $overrides]);
    }

    #[OA\Post(
        path: '/therapists/{therapistId}/schedule-overrides',
        summary: 'Crear excepción de horario',
        operationId: 'createScheduleOverride',
        security: [['bearerAuth' => []]],
        tags: ['Schedule Overrides'],
        parameters: [
            new OA\Parameter(name: 'therapistId', in: 'path', required: true, schema: new OA\Schema(type: 'string'))
        ],
        responses: [
            new OA\Response(response: 201, description: 'Excepción creada exitosamente'),
            new OA\Response(response: 400, description: 'Datos inválidos')
        ]
    )]
    public function store(string $therapistId): void {
        try {
            $data = $this->getJsonInput();
            
            $this->validateRequired($data, ['week_start_date', 'day_of_week', 'start_time', 'end_time']);
            
            $data['therapist_id'] = $therapistId;
            
            // Obtener rol del usuario actual
            // El token JWT tiene 'roles' como array de strings
            $currentUser = $GLOBALS['current_user'] ?? null;
            $userRole = 'admin';
            if ($currentUser) {
                if (isset($currentUser['roles']) && is_array($currentUser['roles']) && !empty($currentUser['roles'])) {
                    // roles es un array de strings en el JWT
                    $userRole = $currentUser['roles'][0] ?? 'admin';
                } elseif (isset($currentUser['role'])) {
                    $userRole = $currentUser['role'];
                }
            }
            $data['updated_by_role'] = $userRole;
            
            // Asegurar que day_of_week sea un entero
            $dayOfWeek = $data['day_of_week'];
            if (!is_numeric($dayOfWeek)) {
                Response::error('day_of_week debe ser un número (1-7)', 400);
            }
            $dayOfWeek = (int)$dayOfWeek;
            
            // Validar rango de day_of_week
            if ($dayOfWeek < 1 || $dayOfWeek > 7) {
                Response::error('day_of_week debe estar entre 1 y 7', 400);
            }
            
            // Verificar conflictos
            try {
                $hasConflict = $this->overrideService->checkConflict(
                    $therapistId,
                    $data['week_start_date'],
                    $dayOfWeek,
                    $data['start_time'],
                    $data['end_time']
                );
                
                if ($hasConflict) {
                    Response::error('Ya existe un horario en conflicto para esta semana y día', 409);
                }
            } catch (\Exception $e) {
                // Continuar con la creación si el check falla (no debería bloquear)
            }
            
            $data['day_of_week'] = $dayOfWeek;
            $id = $this->overrideService->create($data);
            Response::json(['data' => ['id' => $id]], 201);
        } catch (\Exception $e) {
            error_log('Error en store schedule override: ' . $e->getMessage());
            error_log('Stack trace: ' . $e->getTraceAsString());
            Response::error('Error interno del servidor: ' . $e->getMessage(), 500);
        }
    }

    #[OA\Post(
        path: '/therapists/{therapistId}/schedule-overrides/batch',
        summary: 'Crear múltiples excepciones de horario',
        operationId: 'createScheduleOverridesBatch',
        security: [['bearerAuth' => []]],
        tags: ['Schedule Overrides'],
        parameters: [
            new OA\Parameter(name: 'therapistId', in: 'path', required: true, schema: new OA\Schema(type: 'string'))
        ],
        responses: [
            new OA\Response(response: 201, description: 'Excepciones creadas exitosamente')
        ]
    )]
    public function storeBatch(string $therapistId): void {
        try {
            $data = $this->getJsonInput();
            
            if (!isset($data['overrides']) || !is_array($data['overrides'])) {
                Response::error('Se requiere un array de overrides', 400);
            }
            
            // Obtener rol del usuario actual
            // El token JWT tiene 'roles' como array de strings
            $currentUser = $GLOBALS['current_user'] ?? null;
            $userRole = 'admin';
            if ($currentUser) {
                if (isset($currentUser['roles']) && is_array($currentUser['roles']) && !empty($currentUser['roles'])) {
                    // roles es un array de strings en el JWT
                    $userRole = $currentUser['roles'][0] ?? 'admin';
                } elseif (isset($currentUser['role'])) {
                    $userRole = $currentUser['role'];
                }
            }
            
            foreach ($data['overrides'] as &$override) {
                $override['therapist_id'] = $therapistId;
                $override['updated_by_role'] = $userRole;
                
                // Asegurar que day_of_week sea un entero
                if (isset($override['day_of_week'])) {
                    $dayOfWeek = $override['day_of_week'];
                    if (!is_numeric($dayOfWeek)) {
                        Response::error('day_of_week debe ser un número (1-7)', 400);
                    }
                    $dayOfWeek = (int)$dayOfWeek;
                    if ($dayOfWeek < 1 || $dayOfWeek > 7) {
                        Response::error('day_of_week debe estar entre 1 y 7', 400);
                    }
                    $override['day_of_week'] = $dayOfWeek;
                }
            }
            
            $ids = $this->overrideService->createBatch($data['overrides']);
            Response::json(['data' => ['ids' => $ids]], 201);
        } catch (\Exception $e) {
            error_log('Error en storeBatch schedule override: ' . $e->getMessage());
            error_log('Stack trace: ' . $e->getTraceAsString());
            Response::error('Error interno del servidor: ' . $e->getMessage(), 500);
        }
    }

    #[OA\Put(
        path: '/schedule-overrides/{id}',
        summary: 'Actualizar excepción de horario',
        operationId: 'updateScheduleOverride',
        security: [['bearerAuth' => []]],
        tags: ['Schedule Overrides'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string'))
        ],
        responses: [
            new OA\Response(response: 200, description: 'Excepción actualizada exitosamente')
        ]
    )]
    public function update(string $id): void {
        $data = $this->getJsonInput();
        
        $override = $this->overrideService->getById($id);
        if (!$override) {
            Response::error('Excepción de horario no encontrada', 404);
        }
        
        if (isset($data['updated_by_role'])) {
            $currentUser = $GLOBALS['current_user'] ?? null;
            $userRole = 'admin';
            if ($currentUser) {
                if (isset($currentUser['roles']) && is_array($currentUser['roles']) && !empty($currentUser['roles'])) {
                    $userRole = $currentUser['roles'][0] ?? 'admin';
                } elseif (isset($currentUser['role'])) {
                    $userRole = $currentUser['role'];
                }
            }
            $data['updated_by_role'] = $userRole;
        }
        
        $success = $this->overrideService->update($id, $data);
        if (!$success) {
            Response::error('No se pudo actualizar la excepción', 400);
        }
        
        Response::json(['data' => ['id' => $id]]);
    }

    #[OA\Delete(
        path: '/schedule-overrides/{id}',
        summary: 'Eliminar excepción de horario',
        operationId: 'deleteScheduleOverride',
        security: [['bearerAuth' => []]],
        tags: ['Schedule Overrides'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string'))
        ],
        responses: [
            new OA\Response(response: 200, description: 'Excepción eliminada exitosamente')
        ]
    )]
    public function destroy(string $id): void {
        try {
            $override = $this->overrideService->getById($id);
            if (!$override) {
                Response::error('Excepción de horario no encontrada', 404);
                return;
            }
            
            $success = $this->overrideService->delete($id);
            if (!$success) {
                Response::error('No se pudo eliminar la excepción', 400);
                return;
            }
            
            Response::json(['message' => 'Excepción eliminada exitosamente']);
        } catch (\Exception $e) {
            Response::error($e->getMessage(), 400);
        }
    }

    #[OA\Delete(
        path: '/therapists/{therapistId}/schedule-overrides/week',
        summary: 'Eliminar todas las excepciones de una semana',
        operationId: 'deleteScheduleOverridesByWeek',
        security: [['bearerAuth' => []]],
        tags: ['Schedule Overrides'],
        parameters: [
            new OA\Parameter(name: 'therapistId', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'week_start_date', in: 'query', required: true, schema: new OA\Schema(type: 'string', format: 'date'))
        ],
        responses: [
            new OA\Response(response: 200, description: 'Excepciones eliminadas exitosamente')
        ]
    )]
    public function destroyByWeek(string $therapistId): void {
        $weekStartDate = $_GET['week_start_date'] ?? null;
        
        if (!$weekStartDate) {
            Response::error('Se requiere week_start_date', 400);
        }
        
        $success = $this->overrideService->deleteByWeek($therapistId, $weekStartDate);
        if (!$success) {
            Response::error('No se pudieron eliminar las excepciones', 400);
        }
        
        Response::json(['message' => 'Excepciones eliminadas exitosamente']);
    }
}
