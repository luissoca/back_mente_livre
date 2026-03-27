óááá<?php

namespace App\Controllers;

use App\Core\BaseController;
use App\Core\Response;
use App\Services\AppointmentService;
use OpenApi\Attributes as OA;

#[OA\Tag(name: "Appointments", description: "Gestión de citas")]
class AppointmentController extends BaseController {
    private AppointmentService $appointmentService;

    public function __construct() {
        $this->appointmentService = new AppointmentService();
    }

    #[OA\Get(
        path: '/appointments',
        summary: 'Listar citas',
        operationId: 'getAppointments',
        tags: ['Appointments'],
        parameters: [
            new OA\Parameter(name: 'therapist_id', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'status', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['pending', 'confirmed', 'completed', 'cancelled', 'pending_payment', 'payment_review'])),
            new OA\Parameter(name: 'date_from', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'date_to', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'patient_email', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'email'))
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Lista de citas',
                content: new OA\JsonContent(
                    type: 'array',
                    items: new OA\Items(ref: '#/components/schemas/Appointment')
                )
            )
        ]
    )]
    public function index(): void {
        $filters = [
            'therapist_id' => $_GET['therapist_id'] ?? null,
            'status' => $_GET['status'] ?? null,
            'date_from' => $_GET['date_from'] ?? null,
            'date_to' => $_GET['date_to'] ?? null,
            'patient_email' => $_GET['patient_email'] ?? null
        ];
        
        // Si hay múltiples parámetros status, convertirlos en array
        if (isset($_GET['status']) && is_array($_GET['status'])) {
            $filters['status'] = $_GET['status'];
        } elseif (isset($_GET['status'])) {
            $filters['status'] = $_GET['status'];
        }
        
        // Remover filtros vacíos
        $filters = array_filter($filters, fn($value) => $value !== null && $value !== '');
        
        $appointments = $this->appointmentService->getAll($filters);
        Response::json(['data' => $appointments]);
    }

    #[OA\Get(
        path: '/appointments/{id}',
        summary: 'Obtener cita por ID',
        operationId: 'getAppointment',
        tags: ['Appointments'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string'))
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Detalle de la cita',
                content: new OA\JsonContent(ref: '#/components/schemas/Appointment')
            ),
            new OA\Response(response: 404, description: 'Cita no encontrada')
        ]
    )]
    public function show(string $id): void {
        $appointment = $this->appointmentService->getById($id);
        
        if (!$appointment) {
            Response::json(['error' => 'Cita no encontrada'], 404);
            return;
        }
        
        Response::json(['data' => $appointment]);
    }

    #[OA\Post(
        path: '/appointments',
        summary: 'Crear nueva cita',
        operationId: 'createAppointment',
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(ref: '#/components/schemas/AppointmentCreate')
        ),
        tags: ['Appointments'],
        responses: [
            new OA\Response(
                response: 201,
                description: 'Cita creada exitosamente',
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
        
        // Validaciones
        $required = ['therapist_id', 'patient_email', 'patient_name', 'appointment_date', 'start_time', 'end_time'];
        foreach ($required as $field) {
            if (empty($data[$field])) {
                Response::json(['error' => "El campo $field es requerido"], 400);
                return;
            }
        }
        
        // Verificar disponibilidad
        $available = $this->appointmentService->checkAvailability(
            $data['therapist_id'],
            $data['appointment_date'],
            $data['start_time'],
            $data['end_time']
        );
        
        if (!$available) {
            Response::json(['error' => 'El horario seleccionado no está disponible'], 400);
            return;
        }
        
        try {
            $id = $this->appointmentService->create($data);
            Response::json([
                'data' => [
                    'id' => $id,
                    'message' => 'Cita creada exitosamente'
                ]
            ], 201);
        } catch (\Exception $e) {
            error_log('Error creating appointment: ' . $e->getMessage());
            error_log('Stack trace: ' . $e->getTraceAsString());
            error_log('Data received: ' . json_encode($data));
            Response::json(['error' => 'Error al crear la cita: ' . $e->getMessage()], 500);
        }
    }

    #[OA\Put(
        path: '/appointments/{id}',
        summary: 'Actualizar cita',
        operationId: 'updateAppointment',
        tags: ['Appointments'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string'))
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(ref: '#/components/schemas/AppointmentUpdate')
        ),
        responses: [
            new OA\Response(response: 200, description: 'Cita actualizada exitosamente'),
            new OA\Response(response: 404, description: 'Cita no encontrada')
        ]
    )]
    public function update(string $id): void {
        $appointment = $this->appointmentService->getById($id);
        
        if (!$appointment) {
            Response::json(['error' => 'Cita no encontrada'], 404);
            return;
        }
        
        $data = $this->getJsonInput();
        
        // Validaciones opcionales
        if (isset($data['status'])) {
            $validStatuses = ['pending', 'confirmed', 'completed', 'cancelled', 'pending_payment', 'payment_review'];
            if (!in_array($data['status'], $validStatuses)) {
                Response::json(['error' => 'Estado inválido'], 400);
                return;
            }
        }
        
        // Si el usuario está autenticado, verificar que solo pueda cancelar/modificar sus propias citas
        // (excepto admin/therapist que pueden modificar cualquier cita)
        $currentUser = $GLOBALS['current_user'] ?? null;
        if ($currentUser) {
            $userRole = null;
            if (isset($currentUser['roles']) && is_array($currentUser['roles']) && !empty($currentUser['roles'])) {
                $userRole = $currentUser['roles'][0] ?? null;
            } elseif (isset($currentUser['role'])) {
                $userRole = $currentUser['role'];
            }
            
            // Si no es admin ni therapist, solo puede modificar sus propias citas
            if ($userRole !== 'admin' && $userRole !== 'therapist') {
                $userEmail = $currentUser['email'] ?? null;
                if ($userEmail && $appointment['patient_email'] !== $userEmail) {
                    Response::json(['error' => 'No tienes permiso para modificar esta cita'], 403);
                    return;
                }
            }
        }
        
        try {
            $this->appointmentService->update($id, $data);
            Response::json(['message' => 'Cita actualizada exitosamente']);
        } catch (\Exception $e) {
            error_log('Error updating appointment: ' . $e->getMessage());
            error_log('Stack trace: ' . $e->getTraceAsString());
            Response::json(['error' => 'Error al actualizar la cita: ' . $e->getMessage()], 500);
        }
    }

    #[OA\Delete(
        path: '/appointments/{id}',
        summary: 'Eliminar cita',
        operationId: 'deleteAppointment',
        tags: ['Appointments'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string'))
        ],
        responses: [
            new OA\Response(response: 200, description: 'Cita eliminada exitosamente'),
            new OA\Response(response: 404, description: 'Cita no encontrada')
        ]
    )]
    public function destroy(string $id): void {
        $appointment = $this->appointmentService->getById($id);
        
        if (!$appointment) {
            Response::json(['error' => 'Cita no encontrada'], 404);
            return;
        }
        
        try {
            $this->appointmentService->delete($id);
            Response::json(['message' => 'Cita eliminada exitosamente']);
        } catch (\Exception $e) {
            Response::json(['error' => 'Error al eliminar la cita: ' . $e->getMessage()], 500);
        }
    }
}

#[OA\Schema(
    schema: 'Appointment',
    properties: [
        new OA\Property(property: 'id', type: 'string'),
        new OA\Property(property: 'therapist_id', type: 'string'),
        new OA\Property(property: 'therapist_name', type: 'string'),
        new OA\Property(property: 'patient_email', type: 'string'),
        new OA\Property(property: 'patient_name', type: 'string'),
        new OA\Property(property: 'patient_phone', type: 'string', nullable: true),
        new OA\Property(property: 'consultation_reason', type: 'string', nullable: true),
        new OA\Property(property: 'appointment_date', type: 'string', format: 'date'),
        new OA\Property(property: 'start_time', type: 'string', format: 'time'),
        new OA\Property(property: 'end_time', type: 'string', format: 'time'),
        new OA\Property(property: 'status', type: 'string', enum: ['pending', 'confirmed', 'completed', 'cancelled', 'pending_payment', 'payment_review']),
        new OA\Property(property: 'pricing_tier', type: 'string', nullable: true),
        new OA\Property(property: 'notes', type: 'string', nullable: true),
        new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
        new OA\Property(property: 'updated_at', type: 'string', format: 'date-time')
    ]
)]
class AppointmentSchema {}

#[OA\Schema(
    schema: 'AppointmentCreate',
    required: ['therapist_id', 'patient_email', 'patient_name', 'appointment_date', 'start_time', 'end_time'],
    properties: [
        new OA\Property(property: 'therapist_id', type: 'string'),
        new OA\Property(property: 'patient_email', type: 'string', format: 'email'),
        new OA\Property(property: 'patient_name', type: 'string'),
        new OA\Property(property: 'patient_phone', type: 'string', nullable: true),
        new OA\Property(property: 'consultation_reason', type: 'string', nullable: true),
        new OA\Property(property: 'appointment_date', type: 'string', format: 'date'),
        new OA\Property(property: 'start_time', type: 'string', format: 'time'),
        new OA\Property(property: 'end_time', type: 'string', format: 'time'),
        new OA\Property(property: 'status', type: 'string', enum: ['pending', 'confirmed'], default: 'pending'),
        new OA\Property(property: 'pricing_tier', type: 'string', nullable: true),
        new OA\Property(property: 'notes', type: 'string', nullable: true),
        new OA\Property(property: 'original_price', type: 'number', nullable: true),
        new OA\Property(property: 'discount_applied', type: 'number', nullable: true),
        new OA\Property(property: 'final_price', type: 'number', nullable: true)
    ]
)]
class AppointmentCreateSchema {}

#[OA\Schema(
    schema: 'AppointmentUpdate',
    properties: [
        new OA\Property(property: 'status', type: 'string', enum: ['pending', 'confirmed', 'completed', 'cancelled', 'pending_payment', 'payment_review']),
        new OA\Property(property: 'appointment_date', type: 'string', format: 'date'),
        new OA\Property(property: 'start_time', type: 'string', format: 'time'),
        new OA\Property(property: 'end_time', type: 'string', format: 'time'),
        new OA\Property(property: 'consultation_reason', type: 'string'),
        new OA\Property(property: 'notes', type: 'string'),
        new OA\Property(property: 'patient_name', type: 'string'),
        new OA\Property(property: 'patient_phone', type: 'string')
    ]
)]
class AppointmentUpdateSchema {}
