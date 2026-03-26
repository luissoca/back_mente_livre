<?php

namespace App\Services;

use App\Core\Database;
use PDO;

class WeeklyScheduleService {
    private PDO $db;
    private AppointmentService $appointmentService;
    private CacheService $cache;

    public function __construct() {
        $this->db = Database::getInstance()->getConnection();
        $this->appointmentService = new AppointmentService();
        $this->cache = new CacheService();
    }

    /**
     * Obtener horarios de un terapeuta
     * Usa caché en archivo para reducir consultas a MySQL
     */
    public function getByTherapist(string $therapistId): array {
        // Intentar obtener del caché
        $cacheKey = 'schedules_' . $therapistId;
        $cached = $this->cache->get($cacheKey);
        if ($cached !== null) {
            error_log("[Cache] WeeklyScheduleService::getByTherapist({$therapistId}) - datos desde CACHÉ (" . count($cached) . " horarios)");
            return $cached;
        }

        error_log("[Cache] WeeklyScheduleService::getByTherapist({$therapistId}) - datos desde MYSQL (regenerando caché)");
        // No hay caché: consultar MySQL
        $sql = "SELECT * FROM weekly_schedules 
                WHERE therapist_id = :therapist_id 
                ORDER BY day_of_week ASC, start_time ASC";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute([':therapist_id' => $therapistId]);
        
        $schedules = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Guardar en caché
        $this->cache->set($cacheKey, $schedules);
        
        return $schedules;
    }

    /**
     * Obtener horario por ID
     */
    public function getById(string $id): ?array {
        $sql = "SELECT * FROM weekly_schedules WHERE id = :id";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([':id' => $id]);
        
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result ?: null;
    }

    /**
     * Crear horario
     */
    public function create(array $data): string {
        $scheduleId = $this->generateUUID();
        
        $sql = "INSERT INTO weekly_schedules (
            id, therapist_id, day_of_week, start_time, end_time, is_active
        ) VALUES (
            :id, :therapist_id, :day_of_week, :start_time, :end_time, :is_active
        )";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            ':id' => $scheduleId,
            ':therapist_id' => $data['therapist_id'],
            ':day_of_week' => $data['day_of_week'],
            ':start_time' => $data['start_time'],
            ':end_time' => $data['end_time'],
            ':is_active' => ($data['is_active'] ?? true) ? 'true' : 'false'
        ]);
        
        return $scheduleId;
    }

    /**
     * Actualizar horario
     * @throws \Exception Si se intenta desactivar y hay citas activas
     */
    public function update(string $id, array $data): bool {
        // Si se está desactivando (is_active = false), verificar citas
        if (isset($data['is_active']) && !$data['is_active']) {
            $schedule = $this->getById($id);
            if ($schedule) {
                // Verificar si hay citas activas para este día de la semana recurrente
                $hasAppointments = $this->appointmentService->hasActiveAppointments(
                    $schedule['therapist_id'],
                    null, // null = verificar día de semana recurrente
                    (int)$schedule['day_of_week'],
                    $schedule['start_time'],
                    $schedule['end_time']
                );
                
                if ($hasAppointments) {
                    throw new \Exception('No se puede desactivar este horario porque tiene citas programadas. Por favor, cancela o reprograma las citas primero.');
                }
            }
        }
        
        $fields = [];
        $params = [':id' => $id];
        
        $allowedFields = ['day_of_week', 'start_time', 'end_time', 'is_active'];
        
        foreach ($allowedFields as $field) {
            if (array_key_exists($field, $data)) {
                $fields[] = "$field = :$field";
                $params[":$field"] = $data[$field];
            }
        }
        
        if (empty($fields)) {
            return false;
        }
        
        $sql = "UPDATE weekly_schedules SET " . implode(', ', $fields) . " WHERE id = :id";
        $stmt = $this->db->prepare($sql);
        
        return $stmt->execute($params);
    }

    /**
     * Eliminar horario
     * @throws \Exception Si hay citas activas en este horario
     */
    public function delete(string $id): bool {
        // Primero obtener el schedule para verificar citas
        $schedule = $this->getById($id);
        if (!$schedule) {
            return false;
        }
        
        // Verificar si hay citas activas para este día de la semana recurrente
        $hasAppointments = $this->appointmentService->hasActiveAppointments(
            $schedule['therapist_id'],
            null, // null = verificar día de semana recurrente
            (int)$schedule['day_of_week'],
            $schedule['start_time'],
            $schedule['end_time']
        );
        
        if ($hasAppointments) {
            throw new \Exception('No se puede eliminar este horario porque tiene citas programadas. Por favor, cancela o reprograma las citas primero.');
        }
        
        $sql = "DELETE FROM weekly_schedules WHERE id = :id";
        $stmt = $this->db->prepare($sql);
        return $stmt->execute([':id' => $id]);
    }

    /**
     * Verificar conflictos de horario
     */
    public function checkConflict(string $therapistId, int $dayOfWeek, string $startTime, string $endTime, ?string $excludeId = null): bool {
        $sql = "SELECT COUNT(*) as count FROM weekly_schedules 
                WHERE therapist_id = :therapist_id 
                AND day_of_week = :day_of_week
                AND is_active = TRUE
                AND (
                    (start_time <= :start_time AND end_time > :start_time)
                    OR (start_time < :end_time AND end_time >= :end_time)
                    OR (start_time >= :start_time AND end_time <= :end_time)
                )";
        
        $params = [
            ':therapist_id' => $therapistId,
            ':day_of_week' => $dayOfWeek,
            ':start_time' => $startTime,
            ':end_time' => $endTime
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
     * Invalidar caché de horarios de un terapeuta específico
     * Llamar después de crear, actualizar o eliminar un horario
     */
    public function invalidateCache(string $therapistId): void {
        $cacheKey = 'schedules_' . $therapistId;
        $this->cache->invalidate($cacheKey);
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
