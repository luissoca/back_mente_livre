<?php

namespace App\Services;

use App\Core\Database;
use PDO;

class AppointmentService {
    private PDO $db;

    public function __construct() {
        $this->db = Database::getInstance()->getConnection();
    }

    /**
     * Obtener todas las citas con filtros opcionales
     */
    public function getAll(array $filters = []): array {
        $sql = "SELECT 
            a.*,
            t.name as therapist_name,
            pc.full_name as patient_name,
            pc.email as patient_email,
            pc.phone as patient_phone,
            ap.original_price,
            ap.discount_applied,
            ap.final_price,
            ap.amount_paid,
            ap.payment_method,
            ap.payment_confirmed_at
            FROM appointments a
            LEFT JOIN therapists t ON a.therapist_id = t.id
            LEFT JOIN patient_contacts pc ON a.patient_contact_id = pc.id
            LEFT JOIN appointment_payments ap ON a.id = ap.appointment_id
            WHERE 1=1";
        
        $params = [];
        
        if (isset($filters['therapist_id'])) {
            $sql .= " AND a.therapist_id = :therapist_id";
            $params[':therapist_id'] = $filters['therapist_id'];
        }
        
        if (isset($filters['status'])) {
            // Si status es un array, usar IN, si no, usar igualdad
            if (is_array($filters['status'])) {
                $placeholders = [];
                foreach ($filters['status'] as $index => $status) {
                    $key = ':status_' . $index;
                    $placeholders[] = $key;
                    $params[$key] = $status;
                }
                $sql .= " AND a.status IN (" . implode(', ', $placeholders) . ")";
            } else {
                $sql .= " AND a.status = :status";
                $params[':status'] = $filters['status'];
            }
        }
        
        if (isset($filters['date_from'])) {
            $sql .= " AND a.appointment_date >= :date_from";
            $params[':date_from'] = $filters['date_from'];
        }
        
        if (isset($filters['date_to'])) {
            $sql .= " AND a.appointment_date <= :date_to";
            $params[':date_to'] = $filters['date_to'];
        }
        
        if (isset($filters['patient_email'])) {
            $sql .= " AND a.patient_email = :patient_email";
            $params[':patient_email'] = $filters['patient_email'];
        }
        
        $sql .= " ORDER BY a.appointment_date DESC, a.start_time DESC";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Obtener cita por ID
     */
    public function getById(string $id): ?array {
        $sql = "SELECT 
            a.*,
            t.name as therapist_name,
            t.hourly_rate as therapist_rate,
            pc.full_name as patient_name,
            pc.email as patient_email,
            pc.phone as patient_phone,
            ap.original_price,
            ap.discount_applied,
            ap.final_price,
            ap.amount_paid,
            ap.payment_method,
            ap.payment_confirmed_at
            FROM appointments a
            LEFT JOIN therapists t ON a.therapist_id = t.id
            LEFT JOIN patient_contacts pc ON a.patient_contact_id = pc.id
            LEFT JOIN appointment_payments ap ON a.id = ap.appointment_id
            WHERE a.id = :id";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute([':id' => $id]);
        
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$result) {
            error_log("❌ AppointmentService::getById($id) NOT FOUND");
        } else {
            error_log("✅ AppointmentService::getById($id) FOUND. Status: " . $result['status']);
        }
        
        return $result ?: null;
    }

    /**
     * Crear nueva cita
     */
    public function create(array $data): string {
        error_log('🚀 AppointmentService::create called with data: ' . json_encode($data));
        
        // Debug current transaction state
        if ($this->db->inTransaction()) {
             error_log('⚠️ WARNING: Already in transaction before create!');
        }
        
        // Ensure error mode is exception GLOBALLY for this transaction
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        // $this->db->beginTransaction(); // DISABLED FOR DEBUGGING PERSISTENCE
        error_log("⚠️ TRANSACTION DISABLED: Relying on AutoCommit");

        
        
        try {
            // Check if user exists immediately to isolate transaction abort source
            $userId = $data['user_id'] ?? null;
            if ($userId) {
                 error_log("🔍 Verifying User ID (Early Check): $userId");
                 try {
                     $checkUser = $this->db->prepare("SELECT id FROM users WHERE id = :id");
                     $checkUser->execute([':id' => $userId]);
                     if (!$checkUser->fetch()) {
                         error_log("⚠️ WARNING: User ID $userId NOT found. Setting to NULL.");
                         $userId = null;
                     } else {
                         error_log("✅ User ID $userId verified (Early Check).");
                     }
                 } catch (\PDOException $e) {
                     error_log("❌ User Verification FAILED (Early Check): " . $e->getMessage());
                     $userId = null;
                 }
                 // Ensure cursor is closed
                 if (isset($checkUser)) {
                     $checkUser->closeCursor();
                 }
                 
                 // DEBUG: Check transaction state immediately after user check
                 if (!$this->db->inTransaction()) {
                    error_log('❌ CRITICAL: Transaction lost after User Verification!');
                 }
                 $errInfo = $this->db->errorInfo();
                 if ($errInfo[0] != '00000') {
                    error_log('❌ CRITICAL: DB Error State after User Verification: ' . json_encode($errInfo));
                 } else {
                    error_log('✅ DB State clean after User Verification.');
                 }
            }
            
            // Antes de crear la nueva cita, eliminar cualquier cita cancelada en el mismo slot
            // Esto permite que los usuarios puedan re-agendar en un horario que previamente cancelaron
            $deleteCancelledSql = "DELETE FROM appointments 
                                   WHERE therapist_id = :therapist_id 
                                   AND appointment_date = :date
                                   AND start_time = :start_time
                                   AND status = 'cancelled'";
            // Disable DELETE to debug transaction abort
            // DELETE DISABLED FOR DEBUGGING
            // try {
            //     // Ensure error mode is exception
            //     $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            //     
            //     $deleteStmt = $this->db->prepare($deleteCancelledSql);
            //     $deleteStmt->execute([
            //         ':therapist_id' => $data['therapist_id'],
            //         ':date' => $data['appointment_date'],
            //         ':start_time' => $data['start_time']
            //     ]);
            //     error_log('✅ DELETE Cancelled Appointments executed successfully');
            // } catch (\PDOException $e) {
            //     error_log('❌ Error cleaning up cancelled appointments: ' . $e->getMessage());
            //     // Throwing here will be caught by the outer catch, but at least we log the specific error
            //     throw $e;
            // }
            
            // Generar UUID para la cita
            $appointmentId = $this->generateUUID();
            
            
            // INLINED getOrCreatePatientContact for debugging
            error_log('🔍 Searching for patient contact (INLINED): ' . $data['patient_email']);
            
            // Check transaction before query
            if (!$this->db->inTransaction()) error_log('❌ CRITICAL: No transaction before contact SELECT!');

            $contactSql = "SELECT id FROM patient_contacts WHERE email = :email";
            $contactStmt = $this->db->prepare($contactSql);
            $contactStmt->execute([':email' => $data['patient_email']]);
            
            // Check transaction after execute
             if (!$this->db->inTransaction()) error_log('❌ CRITICAL: Transaction lost after contact SELECT execute!');

            $existingContact = $contactStmt->fetch(PDO::FETCH_ASSOC);
            $contactStmt->closeCursor(); // CRITICAL
            
             // Check transaction after fetch/close
             if (!$this->db->inTransaction()) error_log('❌ CRITICAL: Transaction lost after contact SELECT fetch/close!');

            if ($existingContact) {
                $patientContactId = $existingContact['id'];
                error_log('✅ Patient contact found (INLINED): ' . $patientContactId);
            } else {
                 // Create new
                 error_log('🆕 Creating new patient contact (INLINED) for: ' . $data['patient_email']);
                 $patientContactId = $this->generateUUID();
                 $nameParts = explode(' ', trim($data['patient_name'] ?? ''), 2);
                 
                 $insertContactSql = "INSERT INTO patient_contacts (id, first_name, last_name, full_name, email, phone)
                        VALUES (:id, :first_name, :last_name, :full_name, :email, :phone)";
                
                $insertContactStmt = $this->db->prepare($insertContactSql);
                $insertContactStmt->execute([
                    ':id' => $patientContactId,
                    ':first_name' => $nameParts[0] ?? '',
                    ':last_name' => $nameParts[1] ?? '',
                    ':full_name' => $data['patient_name'] ?? '',
                    ':email' => $data['patient_email'],
                    ':phone' => $data['patient_phone'] ?? null
                ]);
                error_log('✅ New patient contact created (INLINED): ' . $patientContactId);
            }

            // Final check after contact logic
            if (!$this->db->inTransaction()) {
                error_log('❌ CRITICAL: Transaction lost after INLINED contact logic!');
            } else {
                error_log('✅ Transaction ALIVE after INLINED contact logic.');
            }
            

            $sql = "INSERT INTO appointments (
                id, therapist_id, user_id, patient_contact_id, patient_email, 
                patient_name, patient_phone, consultation_reason, appointment_date,
                start_time, end_time, status, pricing_tier, email_used, notes, patient_package_id
            ) VALUES (
                :id, :therapist_id, :user_id, :patient_contact_id, :patient_email,
                :patient_name, :patient_phone, :consultation_reason, :appointment_date,
                :start_time, :end_time, :status, :pricing_tier, :email_used, :notes, :patient_package_id
            )";
            
            error_log('🕒 Attempting to INSERT appointment...');
            // User verification moved to top

            
            error_log('🕒 Attempting to INSERT appointment...');
            
            // Check if user is booking from a package
            if (isset($data['patient_package_id']) && !empty($data['patient_package_id'])) {
                error_log("📦 Validating Patient Package ID: " . $data['patient_package_id']);
                
                // Get the package details
                $pkgStmt = $this->db->prepare("SELECT * FROM patient_packages WHERE id = ?");
                $pkgStmt->execute([$data['patient_package_id']]);
                $pkg = $pkgStmt->fetch(PDO::FETCH_ASSOC);

                if (!$pkg) {
                    throw new \Exception("El paquete de sesiones seleccionado no existe.");
                }

                if ($pkg['therapist_id'] !== $data['therapist_id']) {
                    throw new \Exception("Este paquete de sesiones sólo es válido con el psicólogo original.");
                }

                if ($pkg['status'] !== 'active' || $pkg['used_sessions'] >= $pkg['total_sessions']) {
                    throw new \Exception("El paquete de sesiones ya no está activo o no tiene sesiones disponibles.");
                }

                // If this is the 2nd or later session, check if the previous one ended at least 1 hr ago
                if ($pkg['used_sessions'] > 0) {
                    $lastApptStmt = $this->db->prepare("
                        SELECT end_time, appointment_date, CONCAT(appointment_date, ' ', end_time) as end_datetime 
                        FROM appointments 
                        WHERE patient_package_id = ? 
                          AND status IN ('pending', 'confirmed', 'completed', 'pending_payment', 'payment_review') 
                        ORDER BY appointment_date DESC, end_time DESC LIMIT 1
                    ");
                    $lastApptStmt->execute([$data['patient_package_id']]);
                    $lastAppt = $lastApptStmt->fetch(PDO::FETCH_ASSOC);
                    
                    if ($lastAppt) {
                        $lastEnd = strtotime($lastAppt['end_datetime']);
                        $now = time();
                        
                        // Check if 1 hour has passed
                        if ($now < $lastEnd) {
                            throw new \Exception("Aún no puedes programar la siguiente sesión de tu paquete. La sesión anterior aún no ha terminado.");
                        } 
                        // Note: The user requested ONLY `appointment end_time sin el +1 hora.`
                        // This means the unlock condition is simply: NOW > lastAppt.end_time
                        // So the above condition handles it correctly.
                    }
                }
            }
            
            try {
                $stmt = $this->db->prepare($sql);
                $stmt->execute([
                    ':id' => $appointmentId,
                    ':therapist_id' => $data['therapist_id'],
                    ':user_id' => $userId,
                    ':patient_contact_id' => $patientContactId,
                    ':patient_email' => $data['patient_email'],
                    ':patient_name' => $data['patient_name'] ?? '',
                    ':patient_phone' => $data['patient_phone'] ?? null,
                    ':consultation_reason' => $data['consultation_reason'] ?? null,
                    ':appointment_date' => $data['appointment_date'],
                    ':start_time' => $data['start_time'],
                    ':end_time' => $data['end_time'],
                    ':status' => $data['status'] ?? 'pending',
                    ':pricing_tier' => $data['pricing_tier'] ?? null,
                    ':email_used' => $data['email_used'] ?? $data['patient_email'],
                    ':notes' => $data['notes'] ?? null,
                    ':patient_package_id' => $data['patient_package_id'] ?? null
                ]);
                $rowCount = $stmt->rowCount();
                if ($rowCount > 0) {
                    error_log("✅ Appointment INSERT successful (Rows affected: $rowCount, ID: $appointmentId)");
                } else {
                    error_log("❌ CRITICAL: Appointment INSERT executed but rowCount is 0!");
                    throw new \Exception("Appointment insert failed (0 rows affected)");
                }
            } catch (\PDOException $e) {
                error_log('❌ INSERT FAILED: ' . $e->getMessage());
                error_log('❌ SQL State: ' . $e->getCode());
                throw $e;
            }
            
            // Registrar uso del código promocional ANTES de crear el registro de pago
            // para asegurar que esté en la misma transacción
            if (isset($data['promo_code_id']) && !empty($data['promo_code_id'])) {
                $promoCodeId = $data['promo_code_id'];
                $userEmail = $data['patient_email'];
                $discountApplied = isset($data['discount_applied']) ? (float)$data['discount_applied'] : 0.00;
                $finalAmount = isset($data['final_price']) ? (float)$data['final_price'] : 0.00;
                $originalPrice = isset($data['original_price']) ? (float)$data['original_price'] : 0.00;
                
                // Insertar registro de uso
                $useId = $this->generateUUID();
                $insertUseSql = "INSERT INTO promo_code_uses (id, promo_code_id, user_email, appointment_id, discount_applied, final_amount)
                                VALUES (:id, :promo_code_id, :user_email, :appointment_id, :discount_applied, :final_amount)";
                $useStmt = $this->db->prepare($insertUseSql);
                $useStmt->execute([
                    ':id' => $useId,
                    ':promo_code_id' => $promoCodeId,
                    ':user_email' => $userEmail,
                    ':appointment_id' => $appointmentId,
                    ':discount_applied' => $discountApplied,
                    ':final_amount' => $finalAmount
                ]);
                
                // Incrementar contador de usos
                $updateCountSql = "UPDATE promo_codes SET uses_count = uses_count + 1 WHERE id = :id";
                $countStmt = $this->db->prepare($updateCountSql);
                $countStmt->execute([':id' => $promoCodeId]);
            }
            
            // Crear registro de pago si se proporciona información
            if (isset($data['original_price']) && $data['original_price'] !== null) {
                error_log("🕒 Creating Payment Record for Appointment $appointmentId");
                $this->createPaymentRecord($appointmentId, $data);
                error_log("✅ Payment Record created successfully");
            }
            
            // PRE-COMMIT VERIFICATION (Inside Transaction)
            error_log("🔍 PRE-COMMIT CHECK: Verifying data inside transaction...");
             try {
                $preCheckStmt = $this->db->prepare("SELECT id, status FROM appointments WHERE id = ?");
                $preCheckStmt->execute([$appointmentId]);
                $preCheckResult = $preCheckStmt->fetch(PDO::FETCH_ASSOC);
                if ($preCheckResult) {
                    error_log("✅ PRE-COMMIT: Appointment $appointmentId exists inside transaction.");
                } else {
                    error_log("❌ CRITICAL PRE-COMMIT: Appointment $appointmentId MISSING inside transaction!");
                }
             } catch (\Exception $e) {
                 error_log("❌ PRE-COMMIT ERROR: " . $e->getMessage());
             }

             /*
            if (!$this->db->commit()) {
                error_log("❌ CRITICAL: COMMIT failed (returned false)");
                throw new \Exception("Database commit failed");
            }
            error_log("✅ TRANSACTION COMMITTED for Appointment $appointmentId");
            */
            error_log("✅ ACTION COMPLETED (AutoCommit) for Appointment $appointmentId");
            
            // IMMEDIATE VERIFICATION
            try {
                $checkStmt = $this->db->prepare("SELECT id, status FROM appointments WHERE id = ?");
                $checkStmt->execute([$appointmentId]);
                $checkResult = $checkStmt->fetch(PDO::FETCH_ASSOC);
                if ($checkResult) {
                    error_log("✅ VERIFICATION: Appointment $appointmentId exists immediately after commit. Status: " . $checkResult['status']);
                } else {
                    error_log("❌ VERIFICATION FAILED: Appointment $appointmentId MISSING immediately after commit!");
                }
            } catch (\Exception $e) {
                error_log("❌ VERIFICATION ERROR: " . $e->getMessage());
            }

            // Si la cita proviene de un paquete, incrementar sesiones usadas
            if (isset($data['patient_package_id']) && !empty($data['patient_package_id'])) {
                $updPkgStmt = $this->db->prepare("
                    UPDATE patient_packages 
                    SET used_sessions = used_sessions + 1,
                        status = CASE WHEN used_sessions + 1 >= total_sessions THEN 'completed' ELSE status END
                    WHERE id = ? AND used_sessions < total_sessions
                ");
                $updPkgStmt->execute([$data['patient_package_id']]);
                
                // Also update status to Confirmed directly if paid ahead by package
                $updApptStmt = $this->db->prepare("UPDATE appointments SET status = 'confirmed' WHERE id = ?");
                $updApptStmt->execute([$appointmentId]);
            }

            return $appointmentId;
            
            
        } catch (\Exception $e) {
            // $this->db->rollBack(); // DISABLED
            error_log("❌ CREATE FAILED: " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Actualizar cita
     */
    public function update(string $id, array $data): bool {
        $fields = [];
        $params = [':id' => $id];
        
        $allowedFields = [
            'status', 'appointment_date', 'start_time', 'end_time',
            'consultation_reason', 'notes', 'patient_name', 'patient_phone'
        ];
        
        foreach ($allowedFields as $field) {
            if (isset($data[$field])) {
                $fields[] = "$field = :$field";
                $params[":$field"] = $data[$field];
            }
        }
        
        if (empty($fields)) {
            return false;
        }
        
        $sql = "UPDATE appointments SET " . implode(', ', $fields) . " WHERE id = :id";
        $stmt = $this->db->prepare($sql);
        
        return $stmt->execute($params);
    }

    /**
     * Eliminar cita
     */
    public function delete(string $id): bool {
        $sql = "DELETE FROM appointments WHERE id = :id";
        $stmt = $this->db->prepare($sql);
        return $stmt->execute([':id' => $id]);
    }

    /**
     * Verificar disponibilidad de horario
     */
    public function checkAvailability(string $therapistId, string $date, string $startTime, string $endTime): bool {
        error_log("🔍 Default checkAvailability called for T:$therapistId D:$date $startTime-$endTime");
        // Usar placeholders únicos para evitar problemas con PDO cuando se repiten nombres
        $sql = "SELECT COUNT(*) as count FROM appointments 
                WHERE therapist_id = :therapist_id 
                AND appointment_date = :date
                AND status NOT IN ('cancelled')
                AND (
                    (start_time <= :start_time1 AND end_time > :start_time2)
                    OR (start_time < :end_time1 AND end_time >= :end_time2)
                    OR (start_time >= :start_time3 AND end_time <= :end_time3)
                )";
        
        
        try {
            $stmt = $this->db->prepare($sql);
            $stmt->execute([
                ':therapist_id' => $therapistId,
                ':date' => $date,
                ':start_time1' => $startTime,
                ':start_time2' => $startTime,
                ':start_time3' => $startTime,
                ':end_time1' => $endTime,
                ':end_time2' => $endTime,
                ':end_time3' => $endTime
            ]);
            
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            $stmt->closeCursor(); // Ensure cursor is closed
            $count = $result['count'];
            error_log("✅ checkAvailability result: $count");
            return $count == 0;
        } catch (\PDOException $e) {
            error_log("❌ checkAvailability FAILED: " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Verificar si hay citas activas en un rango de tiempo específico (para validar antes de eliminar schedules)
     * @param string $therapistId ID del terapeuta
     * @param string $date Fecha específica (YYYY-MM-DD) o null para verificar día de semana recurrente
     * @param int|null $dayOfWeek Día de la semana (1-7) si date es null
     * @param string $startTime Hora de inicio (HH:MM:SS)
     * @param string $endTime Hora de fin (HH:MM:SS)
     * @return bool true si hay citas activas, false si no hay
     */
    public function hasActiveAppointments(string $therapistId, ?string $date, ?int $dayOfWeek, string $startTime, string $endTime): bool {
        // Estados que bloquean (citas activas)
        $blockingStatuses = ['pending', 'pending_payment', 'payment_review', 'confirmed', 'completed'];
        $statusPlaceholders = implode(',', array_fill(0, count($blockingStatuses), '?'));
        
        if ($date) {
            // Verificar para una fecha específica
            $sql = "SELECT COUNT(*) as count FROM appointments 
                    WHERE therapist_id = ? 
                    AND appointment_date = ?
                    AND status IN ($statusPlaceholders)
                    AND (
                        (start_time <= ? AND end_time > ?)
                        OR (start_time < ? AND end_time >= ?)
                        OR (start_time >= ? AND end_time <= ?)
                    )";
            
            $params = array_merge(
                [$therapistId, $date],
                $blockingStatuses,
                [$startTime, $startTime, $endTime, $endTime, $startTime, $endTime]
            );
        } else {
            // Verificar para un día de la semana recurrente (buscar en todas las fechas futuras)
            // Convertir día de la semana MySQL (1=Lunes) a formato SQL
            $sql = "SELECT COUNT(*) as count FROM appointments 
                    WHERE therapist_id = ? 
                    AND DAYOFWEEK(appointment_date) = ?
                    AND appointment_date >= CURDATE()
                    AND status IN ($statusPlaceholders)
                    AND (
                        (start_time <= ? AND end_time > ?)
                        OR (start_time < ? AND end_time >= ?)
                        OR (start_time >= ? AND end_time <= ?)
                    )";
            
            // MySQL DAYOFWEEK: 1=Domingo, 2=Lunes, ..., 7=Sábado
            // Nuestro sistema: 1=Lunes, 2=Martes, ..., 7=Domingo
            // Convertir: nuestro 1 (Lunes) = MySQL 2, nuestro 7 (Domingo) = MySQL 1
            $mysqlDayOfWeek = $dayOfWeek == 7 ? 1 : $dayOfWeek + 1;
            
            $params = array_merge(
                [$therapistId, $mysqlDayOfWeek],
                $blockingStatuses,
                [$startTime, $startTime, $endTime, $endTime, $startTime, $endTime]
            );
        }
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result['count'] > 0;
    }

    /**
     * Obtener o crear contacto de paciente
     */
    private function getOrCreatePatientContact(array $data): string {
        try {
            // Buscar contacto existente
            error_log('🔍 Searching for patient contact: ' . $data['email']);
            $sql = "SELECT id FROM patient_contacts WHERE email = :email";
            $stmt = $this->db->prepare($sql);
            $stmt->execute([':email' => $data['email']]);
            
            $existing = $stmt->fetch(PDO::FETCH_ASSOC);
            $stmt->closeCursor(); // Ensure cursor is closed
            if ($existing) {
                error_log('✅ Patient contact found: ' . $existing['id']);
                return $existing['id'];
            }
            
            // Crear nuevo contacto
            error_log('🆕 Creating new patient contact for: ' . $data['email']);
            $contactId = $this->generateUUID();
            $nameParts = explode(' ', trim($data['name']), 2);
            
            $sql = "INSERT INTO patient_contacts (id, first_name, last_name, full_name, email, phone)
                    VALUES (:id, :first_name, :last_name, :full_name, :email, :phone)";
            
            $stmt = $this->db->prepare($sql);
            $stmt->execute([
                ':id' => $contactId,
                ':first_name' => $nameParts[0] ?? '',
                ':last_name' => $nameParts[1] ?? '',
                ':full_name' => $data['name'],
                ':email' => $data['email'],
                ':phone' => $data['phone'] ?? null
            ]);
            
            error_log('✅ New patient contact created: ' . $contactId);
            return $contactId;
        } catch (\PDOException $e) {
             error_log('❌ Error in getOrCreatePatientContact: ' . $e->getMessage());
             throw $e;
        }
    }

    /**
     * Crear registro de pago
     */
    private function createPaymentRecord(string $appointmentId, array $data): void {
        // Asegurar que los valores numéricos sean correctos
        $originalPrice = isset($data['original_price']) ? (float)$data['original_price'] : 0;
        $discountApplied = isset($data['discount_applied']) ? (float)$data['discount_applied'] : 0;
        $finalPrice = isset($data['final_price']) ? (float)$data['final_price'] : $originalPrice;
        
        $sql = "INSERT INTO appointment_payments (
            id, appointment_id, original_price, discount_applied, 
            final_price, amount_paid, payment_method, payment_confirmed_at
        ) VALUES (
            :id, :appointment_id, :original_price, :discount_applied,
            :final_price, :amount_paid, :payment_method, :payment_confirmed_at
        )";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            ':id' => $this->generateUUID(),
            ':appointment_id' => $appointmentId,
            ':original_price' => $originalPrice,
            ':discount_applied' => $discountApplied,
            ':final_price' => $finalPrice,
            ':amount_paid' => $data['amount_paid'] ?? null,
            ':payment_method' => $data['payment_method'] ?? 'Yape/Plin',
            ':payment_confirmed_at' => $data['payment_confirmed_at'] ?? null
        ]);
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
