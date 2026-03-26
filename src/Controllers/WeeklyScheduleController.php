<?php

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Services\WeeklyScheduleService;
use OpenApi\Attributes as OA;

#[OA\Tag(name: "Schedules", description: "Gestión de horarios semanales")]
class WeeklyScheduleController {
    private WeeklyScheduleService $scheduleService;

    public function __construct() {
        $this->scheduleService = new WeeklyScheduleService();
    }

    #[OA\Get(
        path: '/therapists/{therapistId}/schedules',
        summary: 'Obtener horarios de un terapeuta',
        operationId: 'getTherapistSchedules',
        tags: ['Schedules'],
        parameters: [
            new OA\Parameter(name: 'therapistId', in: 'path', required: true, schema: new OA\Schema(type: 'string'))
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Lista de horarios semanales',
                content: new OA\JsonContent(
                    type: 'array',
                    items: new OA\Items(ref: '#/components/schemas/WeeklySchedule')
                )
            )
        ]
    )]
    public function index(string $therapistId): void {
        $schedules = $this->scheduleService->getByTherapist($therapistId);
        Response::json(['data' => $schedules]);
    }

    #[OA\Post(
        path: '/therapists/{therapistId}/schedules',
        summary: 'Crear horario para un terapeuta',
        operationId: 'createSchedule',
        security: [['bearerAuth' => []]],
        tags: ['Schedules'],
        parameters: [
            new OA\Parameter(name: 'therapistId', in: 'path', required: true, schema: new OA\Schema(type: 'string'))
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(ref: '#/components/schemas/WeeklyScheduleCreate')
        ),
        responses: [
            new OA\Response(
                response: 201,
                description: 'Horario creado exitosamente',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'id', type: 'string'),
                        new OA\Property(property: 'message', type: 'string')
                    ]
                )
            ),
            new OA\Response(response: 400, description: 'Datos inválidos o conflicto de horario')
        ]
    )]
    public function store(string $therapistId): void {
        $request = new Request();
        $data = $request->body();
        $data['therapist_id'] = $therapistId;
        
        // Validaciones
        $required = ['day_of_week', 'start_time', 'end_time'];
        foreach ($required as $field) {
            if (!isset($data[$field])) {
                Response::json(['error' => "El campo $field es requerido"], 400);
                return;
            }
        }
        
        // Verificar conflictos
        $hasConflict = $this->scheduleService->checkConflict(
            $data['therapist_id'],
            $data['day_of_week'],
            $data['start_time'],
            $data['end_time']
        );
        
        if ($hasConflict) {
            Response::json(['error' => 'Ya existe un horario en ese intervalo'], 400);
            return;
        }
        
        try {
            $id = $this->scheduleService->create($data);
            
            // Invalidar caché de horarios del terapeuta
            $this->scheduleService->invalidateCache($therapistId);
            
            Response::json([
                'id' => $id,
                'message' => 'Horario creado exitosamente'
            ], 201);
        } catch (\Exception $e) {
            Response::json(['error' => 'Error al crear el horario: ' . $e->getMessage()], 500);
        }
    }

    #[OA\Put(
        path: '/schedules/{id}',
        summary: 'Actualizar horario',
        operationId: 'updateSchedule',
        security: [['bearerAuth' => []]],
        tags: ['Schedules'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string'))
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(ref: '#/components/schemas/WeeklyScheduleUpdate')
        ),
        responses: [
            new OA\Response(response: 200, description: 'Horario actualizado exitosamente'),
            new OA\Response(response: 404, description: 'Horario no encontrado'),
            new OA\Response(response: 400, description: 'Conflicto de horario')
        ]
    )]
    public function update(string $id): void {
        $schedule = $this->scheduleService->getById($id);
        
        if (!$schedule) {
            Response::json(['error' => 'Horario no encontrado'], 404);
            return;
        }
        
        $data = $this->getJsonInput();
        
        // Si se están actualizando horarios, verificar conflictos
        if (isset($data['day_of_week']) || isset($data['start_time']) || isset($data['end_time'])) {
            $dayOfWeek = $data['day_of_week'] ?? $schedule['day_of_week'];
            $startTime = $data['start_time'] ?? $schedule['start_time'];
            $endTime = $data['end_time'] ?? $schedule['end_time'];
            
            $hasConflict = $this->scheduleService->checkConflict(
                $schedule['therapist_id'],
                $dayOfWeek,
                $startTime,
                $endTime,
                $id
            );
            
            if ($hasConflict) {
                Response::json(['error' => 'Ya existe un horario en ese intervalo'], 400);
                return;
            }
        }
        
        try {
            $success = $this->scheduleService->update($id, $data);
            if (!$success) {
                Response::json(['error' => 'No se pudo actualizar el horario'], 400);
                return;
            }
            
            // Invalidar caché de horarios del terapeuta
            $this->scheduleService->invalidateCache($schedule['therapist_id']);
            
            Response::json(['message' => 'Horario actualizado exitosamente']);
        } catch (\Exception $e) {
            Response::json(['error' => $e->getMessage()], 400);
        }
    }

    #[OA\Delete(
        path: '/schedules/{id}',
        summary: 'Eliminar horario',
        operationId: 'deleteSchedule',
        security: [['bearerAuth' => []]],
        tags: ['Schedules'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string'))
        ],
        responses: [
            new OA\Response(response: 200, description: 'Horario eliminado exitosamente'),
            new OA\Response(response: 404, description: 'Horario no encontrado')
        ]
    )]
    public function destroy(string $id): void {
        $schedule = $this->scheduleService->getById($id);
        
        if (!$schedule) {
            Response::json(['error' => 'Horario no encontrado'], 404);
            return;
        }
        
        try {
            $success = $this->scheduleService->delete($id);
            if (!$success) {
                Response::json(['error' => 'No se pudo eliminar el horario'], 400);
                return;
            }
            
            // Invalidar caché de horarios del terapeuta
            $this->scheduleService->invalidateCache($schedule['therapist_id']);
            
            Response::json(['message' => 'Horario eliminado exitosamente']);
        } catch (\Exception $e) {
            Response::json(['error' => $e->getMessage()], 400);
        }
    }
}

#[OA\Schema(
    schema: 'WeeklySchedule',
    properties: [
        new OA\Property(property: 'id', type: 'string'),
        new OA\Property(property: 'therapist_id', type: 'string'),
        new OA\Property(property: 'day_of_week', type: 'integer', description: 'Día de la semana (1=Lunes, 7=Domingo)'),
        new OA\Property(property: 'start_time', type: 'string', format: 'time'),
        new OA\Property(property: 'end_time', type: 'string', format: 'time'),
        new OA\Property(property: 'is_active', type: 'boolean'),
        new OA\Property(property: 'updated_by_role', type: 'string', nullable: true),
        new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
        new OA\Property(property: 'updated_at', type: 'string', format: 'date-time')
    ]
)]
class WeeklyScheduleSchema {}

#[OA\Schema(
    schema: 'WeeklyScheduleCreate',
    required: ['day_of_week', 'start_time', 'end_time'],
    properties: [
        new OA\Property(property: 'day_of_week', type: 'integer', description: 'Día de la semana (1=Lunes, 7=Domingo)'),
        new OA\Property(property: 'start_time', type: 'string', format: 'time'),
        new OA\Property(property: 'end_time', type: 'string', format: 'time'),
        new OA\Property(property: 'is_active', type: 'boolean', default: true)
    ]
)]
class WeeklyScheduleCreateSchema {}

#[OA\Schema(
    schema: 'WeeklyScheduleUpdate',
    properties: [
        new OA\Property(property: 'day_of_week', type: 'integer', description: 'Día de la semana (1=Lunes, 7=Domingo)'),
        new OA\Property(property: 'start_time', type: 'string', format: 'time'),
        new OA\Property(property: 'end_time', type: 'string', format: 'time'),
        new OA\Property(property: 'is_active', type: 'boolean')
    ]
)]
class WeeklyScheduleUpdateSchema {}
