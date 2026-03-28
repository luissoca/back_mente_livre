<?php

namespace App\Controllers;

use App\Core\BaseController;
use App\Core\Response;
use App\Services\AppointmentService;
use App\Services\EmailService;
use OpenApi\Attributes as OA;

#[OA\Tag(name: "Appointments", description: "Gestion de citas")]
class AppointmentController extends BaseController {
    private AppointmentService $appointmentService;

    public function __construct() {
        $this->appointmentService = new AppointmentService();
    }

    #[OA\Get(
        path: '/appointments',
        summary: 'Listar citas con filtros y paginacion',
        operationId: 'getAppointments',
        tags: ['Appointments'],
        parameters: [
            new OA\Parameter(name: 'therapist_id',   in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'status',         in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'date_from',      in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'date_to',        in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'patient_email',  in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'email')),
            new OA\Parameter(name: 'page',           in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 1)),
            new OA\Parameter(name: 'per_page',       in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 50)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Lista paginada de citas', content: new OA\JsonContent(type: 'object'))
        ]
    )]
    public function index(): void {
        $filters = array_filter([
            'therapist_id'  => $_GET['therapist_id']  ?? null,
            'status'        => $_GET['status']         ?? null,
            'date_from'     => $_GET['date_from']      ?? null,
            'date_to'       => $_GET['date_to']        ?? null,
            'patient_email' => $_GET['patient_email']  ?? null,
        ], fn($v) => $v !== null && $v !== '');

        // Soporte para status como array (?status[]=confirmed&status[]=pending)
        if (isset($_GET['status']) && is_array($_GET['status'])) {
            $filters['status'] = $_GET['status'];
        }

        $page    = max(1, (int)($_GET['page']     ?? 1));
        $perPage = max(1, min(200, (int)($_GET['per_page'] ?? 50)));

        $result = $this->appointmentService->getAll($filters, $page, $perPage);
        Response::json($result);
    }

    #[OA\Get(
        path: '/appointments/{id}',
        summary: 'Obtener cita por ID',
        operationId: 'getAppointment',
        tags: ['Appointments'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string'))],
        responses: [
            new OA\Response(response: 200, description: 'Detalle de la cita', content: new OA\JsonContent(ref: '#/components/schemas/Appointment')),
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
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/AppointmentCreate')),
        tags: ['Appointments'],
        responses: [
            new OA\Response(response: 201, description: 'Cita creada exitosamente'),
            new OA\Response(response: 400, description: 'Datos invalidos')
        ]
    )]
    public function store(): void {
        $data = $this->getJsonInput();

        $required = ['therapist_id', 'patient_email', 'patient_name', 'appointment_date', 'start_time', 'end_time'];
        foreach ($required as $field) {
            if (empty($data[$field])) {
                Response::json(['error' => "El campo $field es requerido"], 400);
                return;
            }
        }

        $available = $this->appointmentService->checkAvailability(
            $data['therapist_id'],
            $data['appointment_date'],
            $data['start_time'],
            $data['end_time']
        );

        if (!$available) {
            Response::json(['error' => 'El horario seleccionado no esta disponible'], 400);
            return;
        }

        try {
            $id = $this->appointmentService->create($data);
            Response::json(['data' => ['id' => $id, 'message' => 'Cita creada exitosamente']], 201);
        } catch (\Exception $e) {
            error_log('Error creating appointment: ' . $e->getMessage());
            Response::json(['error' => 'Error al crear la cita: ' . $e->getMessage()], 500);
        }
    }

    #[OA\Put(
        path: '/appointments/{id}',
        summary: 'Actualizar cita',
        operationId: 'updateAppointment',
        tags: ['Appointments'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string'))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/AppointmentUpdate')),
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

        if (isset($data['status'])) {
            $validStatuses = ['pending', 'confirmed', 'completed', 'cancelled', 'pending_payment', 'payment_review'];
            if (!in_array($data['status'], $validStatuses)) {
                Response::json(['error' => 'Estado invalido'], 400);
                return;
            }
        }

        $currentUser = $GLOBALS['current_user'] ?? null;
        if ($currentUser) {
            $roles = $currentUser['roles'] ?? [];
            $isPrivileged = in_array('admin', $roles) || in_array('therapist', $roles);
            if (!$isPrivileged) {
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
            Response::json(['error' => 'Error al actualizar la cita: ' . $e->getMessage()], 500);
        }
    }

    #[OA\Patch(
        path: '/appointments/{id}/confirm-payment',
        summary: 'Confirmar pago manual (admin) y notificar al paciente',
        operationId: 'confirmAppointmentPayment',
        security: [['bearerAuth' => []]],
        tags: ['Appointments'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string'))],
        responses: [
            new OA\Response(response: 200, description: 'Pago confirmado y email enviado'),
            new OA\Response(response: 404, description: 'Cita no encontrada'),
            new OA\Response(response: 409, description: 'La cita no esta en estado payment_review')
        ]
    )]
    public function confirmPayment(string $id): void {
        $appointment = $this->appointmentService->getById($id);
        if (!$appointment) {
            Response::json(['error' => 'Cita no encontrada'], 404);
            return;
        }

        if ($appointment['status'] !== 'payment_review') {
            Response::json([
                'error'  => 'La cita no esta pendiente de revision de pago',
                'status' => $appointment['status'],
            ], 409);
            return;
        }

        try {
            // 1. Confirmar cita + registrar timestamp del pago
            $this->appointmentService->update($id, ['status' => 'confirmed']);

            // 2. Marcar payment_confirmed_at en appointment_payments
            $this->appointmentService->confirmPaymentRecord($id);

            // 3. Enviar email de confirmacion al paciente
            $emailService = new EmailService();
            $emailSent = $emailService->sendAppointmentConfirmation($appointment);

            Response::json([
                'message'    => 'Pago confirmado exitosamente',
                'email_sent' => $emailSent,
            ]);
        } catch (\Exception $e) {
            error_log('Error confirming payment: ' . $e->getMessage());
            Response::json(['error' => 'Error al confirmar el pago: ' . $e->getMessage()], 500);
        }
    }

    #[OA\Delete(
        path: '/appointments/{id}',
        summary: 'Eliminar cita',
        operationId: 'deleteAppointment',
        tags: ['Appointments'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string'))],
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
        new OA\Property(property: 'id',                  type: 'string'),
        new OA\Property(property: 'therapist_id',        type: 'string'),
        new OA\Property(property: 'therapist_name',      type: 'string'),
        new OA\Property(property: 'patient_email',       type: 'string'),
        new OA\Property(property: 'patient_name',        type: 'string'),
        new OA\Property(property: 'patient_phone',       type: 'string',   nullable: true),
        new OA\Property(property: 'consultation_reason', type: 'string',   nullable: true),
        new OA\Property(property: 'appointment_date',    type: 'string',   format: 'date'),
        new OA\Property(property: 'start_time',          type: 'string',   format: 'time'),
        new OA\Property(property: 'end_time',            type: 'string',   format: 'time'),
        new OA\Property(property: 'status',              type: 'string',   enum: ['pending', 'confirmed', 'completed', 'cancelled', 'pending_payment', 'payment_review']),
        new OA\Property(property: 'pricing_tier',        type: 'string',   nullable: true),
        new OA\Property(property: 'notes',               type: 'string',   nullable: true),
        new OA\Property(property: 'created_at',          type: 'string',   format: 'date-time'),
        new OA\Property(property: 'updated_at',          type: 'string',   format: 'date-time'),
    ]
)]
class AppointmentSchema {}

#[OA\Schema(
    schema: 'AppointmentCreate',
    required: ['therapist_id', 'patient_email', 'patient_name', 'appointment_date', 'start_time', 'end_time'],
    properties: [
        new OA\Property(property: 'therapist_id',        type: 'string'),
        new OA\Property(property: 'patient_email',       type: 'string',   format: 'email'),
        new OA\Property(property: 'patient_name',        type: 'string'),
        new OA\Property(property: 'patient_phone',       type: 'string',   nullable: true),
        new OA\Property(property: 'consultation_reason', type: 'string',   nullable: true),
        new OA\Property(property: 'appointment_date',    type: 'string',   format: 'date'),
        new OA\Property(property: 'start_time',          type: 'string',   format: 'time'),
        new OA\Property(property: 'end_time',            type: 'string',   format: 'time'),
        new OA\Property(property: 'status',              type: 'string',   enum: ['pending', 'confirmed'], default: 'pending'),
        new OA\Property(property: 'original_price',      type: 'number',   nullable: true),
        new OA\Property(property: 'discount_applied',    type: 'number',   nullable: true),
        new OA\Property(property: 'final_price',         type: 'number',   nullable: true),
    ]
)]
class AppointmentCreateSchema {}

#[OA\Schema(
    schema: 'AppointmentUpdate',
    properties: [
        new OA\Property(property: 'status',              type: 'string', enum: ['pending', 'confirmed', 'completed', 'cancelled', 'pending_payment', 'payment_review']),
        new OA\Property(property: 'appointment_date',    type: 'string', format: 'date'),
        new OA\Property(property: 'start_time',          type: 'string', format: 'time'),
        new OA\Property(property: 'end_time',            type: 'string', format: 'time'),
        new OA\Property(property: 'consultation_reason', type: 'string'),
        new OA\Property(property: 'notes',               type: 'string'),
        new OA\Property(property: 'patient_name',        type: 'string'),
        new OA\Property(property: 'patient_phone',       type: 'string'),
    ]
)]
class AppointmentUpdateSchema {}
