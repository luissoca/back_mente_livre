<?php

namespace App\Services;

use App\Core\Database;
use PDO;

class WeeklyScheduleOverrideService {
    private PDO $db;
    private AppointmentService $appointmentService;

    public function __construct() {
        $this->db = Database::getInstance()->getConnection();
        $this->appointmentService = new AppointmentService();
    }

    /**
     * Obtener excepciones de horario por terapeuta y semana
     */
    public function getByTherapistAndWeek(string $therapistId, string $weekStartDate): array {
        $sql = "SELECT * FROM weekly_schedule_overrides 
                WHERE therapist_id = :therapist_id 
                AND week_start_date = :week_start_date
                AND is_active = TRUE
                ORDER BY day_of_week ASC, start_time ASC";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            ':therapist_id' => $therapistId,
            ':week_start_date' => $weekStartDate
        ]);
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Obtener todas las excepciones de un terapeuta
     */
    public function getByTherapist(string $therapistId, ?string $weekStartDate = null): array {
        $sql = "SELECT * FROM weekly_schedule_overrides 
                WHERE therapist_id = :therapist_id";
        
        $params = [':therapist_id' => $therapistId];
        
        if ($weekStartDate) {
            $sql .= " AND week_start_date = :week_start_date";
            $params[':week_start_date'] = $weekStartDate;
        }
        
        $sql .= " ORDER BY week_start_date DESC, day_of_week ASC, start_time ASC";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Obtener excepción por ID
     */
    public function getById(string $id): ?array {
        $sql = "SELECT * FROM weekly_schedule_overrides WHERE id = :id";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([':id' => $id]);
        
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result ?: null;
    }

    /**
     * Crear excepción de horario
     */
    public function create(array $data): string {
        $overrideId = $this->generateUUID();
        
        // Validar campos requeridos
        $required = ['therapist_id', 'week_start_date', 'day_of_week', 'start_time', 'end_time'];
        foreach ($required as $field) {
            if (!isset($data[$field])) {
                throw new \Exception("Campo requerido faltante: {$field}");
            }
        }
        
        $sql = "INSERT INTO weekly_schedule_overrides (
            id, therapist_id, week_start_date, day_of_week, start_time, end_time, is_active, updated_by_role
        ) VALUES (
            :id, :therapist_id, :week_start_date, :day_of_week, :start_time, :end_time, :is_active, :updated_by_role
        )";
        
        $stmt = $this->db->prepare($sql);
        
        // Asegurar que is_active sea boolean
        $isActive = isset($data['is_active']) ? (bool)$data['is_active'] : true;
        
        $params = [
            ':id' => $overrideId,
            ':therapist_id' => $data['therapist_id'],
            ':week_start_date' => $data['week_start_date'],
            ':day_of_week' => (int)$data['day_of_week'], // Asegurar que sea entero
            ':start_time' => $data['start_time'],
            ':end_time' => $data['end_time'],
            ':is_active' => $isActive ? 1 : 0, // Convertir boolean a int para MySQL
            ':updated_by_role' => $data['updated_by_role'] ?? null
        ];
        
        $stmt->execute($params);
        
        return $overrideId;
    }

    /**
     * Crear múltiples excepciones
     */
    public function createBatch(array $overrides): array {
        $createdIds = [];
        
        foreach ($overrides as $override) {
            $createdIds[] = $this->create($override);
        }
        
        return $createdIds;
    }

    /**
     * Actualizar excepción
     * @throws \Exception Si se intenta desactivar y hay citas activas
     */
    public function update(string $id, array $data): bool {
        // Si se está desactivando (is_active = false), verificar citas
        if (isset($data['is_active']) && !$data['is_active']) {
            $override = $this->getById($id);
            if ($override) {
                // Calcular la fecha específica del override
                $weekStart = new \DateTime($override['week_start_date']);
                $dayOfWeek = (int)$override['day_of_week'];
                $daysToAdd = $dayOfWeek - 1;
                $weekStart->modify("+{$daysToAdd} days");
                $specificDate = $weekStart->format('Y-m-d');
                
                // Verificar si hay citas activas
                $hasAppointments = $this->appointmentService->hasActiveAppointments(
                    $override['therapist_id'],
                    $specificDate,
                    null,
                    $override['start_time'],
                    $override['end_time']
                );
                
                if ($hasAppointments) {
                    throw new \Exception('No se puede desactivar este horario porque tiene citas programadas. Por favor, cancela o reprograma las citas primero.');
                }
            }
        }
        
        $fields = [];
        $params = [':id' => $id];
        
        $allowedFields = ['day_of_week', 'start_time', 'end_time', 'is_active', 'updated_by_role'];
        
        foreach ($allowedFields as $field) {
            if (array_key_exists($field, $data)) {
                $fields[] = "$field = :$field";
                $params[":$field"] = $data[$field];
            }
        }
        
        if (empty($fields)) {
            return false;
        }
        
        $sql = "UPDATE weekly_schedule_overrides SET " . implode(', ', $fields) . " WHERE id = :id";
        $stmt = $this->db->prepare($sql);
        
        return $stmt->execute($params);
    }

    /**
     * Eliminar excepción
     * @throws \Exception Si hay citas activas en este horario
     */
    public function delete(string $id): bool {
        // Primero obtener el override para verificar citas
        $override = $this->getById($id);
        if (!$override) {
            return false;
        }
        
        // Calcular la fecha específica del override
        $weekStart = new \DateTime($override['week_start_date']);
        $dayOfWeek = (int)$override['day_of_week'];
        // day_of_week: 1=Lunes, 7=Domingo
        // Agregar días desde el inicio de la semana (Lunes = 0)
        $daysToAdd = $dayOfWeek - 1;
        $weekStart->modify("+{$daysToAdd} days");
        $specificDate = $weekStart->format('Y-m-d');
        
        // Verificar si hay citas activas en este horario
        $hasAppointments = $this->appointmentService->hasActiveAppointments(
            $override['therapist_id'],
            $specificDate,
            null,
            $override['start_time'],
            $override['end_time']
        );
        
        if ($hasAppointments) {
            throw new \Exception('No se puede eliminar este horario porque tiene citas programadas. Por favor, cancela o reprograma las citas primero.');
        }
        
        $sql = "DELETE FROM weekly_schedule_overrides WHERE id = :id";
        $stmt = $this->db->prepare($sql);
        return $stmt->execute([':id' => $id]);
    }

    /**
     * Eliminar todas las excepciones de una semana específica
     */
    public function deleteByWeek(string $therapistId, string $weekStartDate): bool {
        $sql = "DELETE FROM weekly_schedule_overrides 
                WHERE therapist_id = :therapist_id 
                AND week_start_date = :week_start_date";
        $stmt = $this->db->prepare($sql);
        return $stmt->execute([
            ':therapist_id' => $therapistId,
            ':week_start_date' => $weekStartDate
        ]);
    }

    /**
     * Verificar conflictos de horario
     */
    public function checkConflict(string $therapistId, string $weekStartDate, int $dayOfWeek, string $startTime, string $endTime, ?string $excludeId = null): bool {
        // Usar placeholders únicos para evitar problemas con PDO
        $sql = "SELECT COUNT(*) as count FROM weekly_schedule_overrides 
                WHERE therapist_id = :therapist_id 
                AND week_start_date = :week_start_date
                AND day_of_week = :day_of_week
                AND is_active = TRUE
                AND (
                    (start_time <= :start_time1 AND end_time > :start_time2)
                    OR (start_time < :end_time1 AND end_time >= :end_time2)
                    OR (start_time >= :start_time3 AND end_time <= :end_time3)
                )";
        
        $params = [
            ':therapist_id' => $therapistId,
            ':week_start_date' => $weekStartDate,
            ':day_of_week' => $dayOfWeek,
            ':start_time1' => $startTime,
            ':start_time2' => $startTime,
            ':start_time3' => $startTime,
            ':end_time1' => $endTime,
            ':end_time2' => $endTime,
            ':end_time3' => $endTime
        ];
        
        if ($excludeId) {
            $sql .= " AND id != :exclude_id";
            $params[':exclude_id'] = $excludeId;
        }
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result['count'] > 0;
    }

    /**
     * Generar UUID v4
     */
    private function generateUUID(): string {
        $data = random_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}
