<?php

namespace App\Services;

use App\Core\Database;
use PDO;

class AppointmentService
    {
            private PDO $db;

    public function __construct()
        {
                    $this->db = Database::getInstance()->getConnection();
        }

    /**
     * Obtener todas las citas con filtros opcionales y paginacion.
             *
             * @param array $filters  Filtros: therapist_id, status, date_from, date_to, patient_email
             * @param int   $page     Pagina actual (1-based)
         * @param int   $perPage  Registros por pagina (max 200)
         * @return array { data: [], total: int, page: int, per_page: int, total_pages: int }
         */
    public function getAll(array $filters = [], int $page = 1, int $perPage = 50): array
        {
                    $perPage = min(max(1, $perPage), 200); // entre 1 y 200
                $page    = max(1, $page);
                    $offset  = ($page - 1) * $perPage;

                $baseWhere  = "FROM appointments a
                                LEFT JOIN therapists        t  ON a.therapist_id       = t.id
                                                LEFT JOIN patient_contacts  pc ON a.patient_contact_id = pc.id
                                                                LEFT JOIN appointment_payments ap ON a.id              = ap.appointment_id
                                                                                WHERE 1=1";

                $params = [];

                if (isset($filters['therapist_id'])) {
                                $baseWhere .= " AND a.therapist_id = :therapist_id";
                                $params[':therapist_id'] = $filters['therapist_id'];
                }

                if (isset($filters['status'])) {
                                if (is_array($filters['status'])) {
                                                    $placeholders = [];
                                                    foreach ($filters['status'] as $i => $status) {
                                                                            $key            = ':status_' . $i;
                                                                            $placeholders[] = $key;
                                                                            $params[$key]   = $status;
                                                    }
                                                    $baseWhere .= " AND a.status IN (" . implode(', ', $placeholders) . ")";
                                } else {
                                                    $baseWhere            .= " AND a.status = :status";
                                                    $params[':status'] = $filters['status'];
                                }
                }

                if (isset($filters['date_from'])) {
                                $baseWhere .= " AND a.appointment_date >= :date_from";
                                $params[':date_from'] = $filters['date_from'];
                }

                if (isset($filters['date_to'])) {
                                $baseWhere .= " AND a.appointment_date <= :date_to";
                                $params[':date_to'] = $filters['date_to'];
                }

                if (isset($filters['patient_email'])) {
                                $baseWhere .= " AND a.patient_email = :patient_email";
                                $params[':patient_email'] = $filters['patient_email'];
                }

                // Contar total de registros
                $countStmt = $this->db->prepare("SELECT COUNT(*) " . $baseWhere);
                    $countStmt->execute($params);
                    $total = (int)$countStmt->fetchColumn();

                // Obtener registros de la pagina actual
                $dataParams            = $params;
                    $dataParams[':limit']  = $perPage;
                    $dataParams[':offset'] = $offset;

                $sql = "SELECT
                                    a.*,
                                                        t.name                  AS therapist_name,
                                                                            pc.full_name            AS patient_name,
                                                                                                pc.email                AS patient_email,
                                                                                                                    pc.phone                AS patient_phone,
                                                                                                                                        ap.original_price,
                                                                                                                                                            ap.discount_applied,
                                                                                                                                                                                ap.final_price,
                                                                                                                                                                                                    ap.amount_paid,
                                                                                                                                                                                                                        ap.payment_method,
                                                                                                                                                                                                                                            ap.payment_confirmed_at
                                                                                                                                                                                                                                                            " . $baseWhere . "
                                                                                                                                                                                                                                                                            ORDER BY a.appointment_date DESC, a.start_time DESC
                                                                                                                                                                                                                                                                                            LIMIT :limit OFFSET :offset";

                $dataStmt = $this->db->prepare($sql);

                // Bind params nombrados + LIMIT/OFFSET como enteros
                foreach ($params as $key => $value) {
                                $dataStmt->bindValue($key, $value);
                }
                    $dataStmt->bindValue(':limit',  $perPage, PDO::PARAM_INT);
                    $dataStmt->bindValue(':offset', $offset,  PDO::PARAM_INT);
                    $dataStmt->execute();

                return [
                                'data'        => $dataStmt->fetchAll(PDO::FETCH_ASSOC),
                                'total'       => $total,
                                'page'        => $page,
                                'per_page'    => $perPage,
                                'total_pages' => (int)ceil($total / $perPage),
                            ];
        }

    /**
     * Obtener cita por ID.
             */
    public function getById(string $id): ?array
        {
                    $sql = "SELECT
                                        a.*,
                                                            t.name          AS therapist_name,
                                                                                t.hourly_rate   AS therapist_rate,
                                                                                                    pc.full_name    AS patient_name,
                                                                                                                        pc.email        AS patient_email,
                                                                                                                                            pc.phone        AS patient_phone,
                                                                                                                                                                ap.original_price,
                                                                                                                                                                                    ap.discount_applied,
                                                                                                                                                                                                        ap.final_price,
                                                                                                                                                                                                                            ap.amount_paid,
                                                                                                                                                                                                                                                ap.payment_method,
                                                                                                                                                                                                                                                                    ap.payment_confirmed_at
                                                                                                                                                                                                                                                                                    FROM appointments a
                                                                                                                                                                                                                                                                                                    LEFT JOIN therapists        t  ON a.therapist_id       = t.id
                                                                                                                                                                                                                                                                                                                    LEFT JOIN patient_contacts  pc ON a.patient_contact_id = pc.id
                                                                                                                                                                                                                                                                                                                                    LEFT JOIN appointment_payments ap ON a.id              = ap.appointment_id
                                                                                                                                                                                                                                                                                                                                                    WHERE a.id = :id";

                $stmt = $this->db->prepare($sql);
                    $stmt->execute([':id' => $id]);
                    $result = $stmt->fetch(PDO::FETCH_ASSOC);
                    return $result ?: null;
        }

    /**
     * Crear nueva cita.
             */
    public function create(array $data): string
        {
                    // Verificar usuario si viene en el request
                $userId = $data['user_id'] ?? null;
                    if ($userId) {
                                    $checkUser = $this->db->prepare("SELECT id FROM users WHERE id = :id");
                                    $checkUser->execute([':id' => $userId]);
                                    if (!$checkUser->fetch()) {
                                                        $userId = null;
                                    }
                    }

                // Obtener o crear contacto de paciente
                $patientContactId = $this->getOrCreatePatientContact([
                                                                                 'email' => $data['patient_email'],
                                                                                 'name'  => $data['patient_name']  ?? '',
                                                                                 'phone' => $data['patient_phone'] ?? null,
                                                                             ]);

                // Validar paquete de sesiones si aplica
                if (!empty($data['patient_package_id'])) {
                                $this->validatePatientPackage($data['patient_package_id'], $data['therapist_id']);
                }

                $appointmentId = $this->generateUUID();

                $sql = "INSERT INTO appointments (
                                    id, therapist_id, user_id, patient_contact_id,
                                                        patient_email, patient_name, patient_phone,
                                                                            consultation_reason, appointment_date,
                                                                                                start_time, end_time, status, pricing_tier,
                                                                                                                    email_used, notes, patient_package_id
                                                                                                                                    ) VALUES (
                                                                                                                                                        :id, :therapist_id, :user_id, :patient_contact_id,
                                                                                                                                                                            :patient_email, :patient_name, :patient_phone,
                                                                                                                                                                                                :consultation_reason, :appointment_date,
                                                                                                                                                                                                                    :start_time, :end_time, :status, :pricing_tier,
                                                                                                                                                                                                                                        :email_used, :notes, :patient_package_id
                                                                                                                                                                                                                                                        )";

                $stmt = $this->db->prepare($sql);
                    $stmt->execute([
                                               ':id'                  => $appointmentId,
                                               ':therapist_id'        => $data['therapist_id'],
                                               ':user_id'             => $userId,
                                               ':patient_contact_id'  => $patientContactId,
                                               ':patient_email'       => $data['patient_email'],
                                               ':patient_name'        => $data['patient_name']        ?? '',
                                               ':patient_phone'       => $data['patient_phone']       ?? null,
                                               ':consultation_reason' => $data['consultation_reason'] ?? null,
                                               ':appointment_date'    => $data['appointment_date'],
                                               ':start_time'          => $data['start_time'],
                                               ':end_time'            => $data['end_time'],
                                               ':status'              => $data['status']              ?? 'pending',
                                               ':pricing_tier'        => $data['pricing_tier']        ?? null,
                                               ':email_used'          => $data['email_used']          ?? $data['patient_email'],
                                               ':notes'               => $data['notes']               ?? null,
                                               ':patient_package_id'  => $data['patient_package_id']  ?? null,
                                           ]);

                // Registrar uso de codigo promocional si aplica
                if (!empty($data['promo_code_id'])) {
                                $this->registerPromoCodeUsage($appointmentId, $data);
                }

                // Crear registro de pago inicial si se proporcionan precios
                if (isset($data['original_price']) && $data['original_price'] !== null) {
                                $this->createPaymentRecord($appointmentId, $data);
                }

                // Si viene de un paquete: incrementar sesiones usadas y confirmar cita
                if (!empty($data['patient_package_id'])) {
                                $this->incrementPackageSession($data['patient_package_id'], $appointmentId);
                }

                return $appointmentId;
        }

    /**
     * Actualizar cita.
             */
    public function update(string $id, array $data): bool
        {
                    $fields = [];
                    $params = [':id' => $id];

                $allowedFields = [
                                'status', 'appointment_date', 'start_time', 'end_time',
                                'consultation_reason', 'notes', 'patient_name', 'patient_phone'
                            ];

                foreach ($allowedFields as $field) {
                                if (isset($data[$field])) {
                                                    $fields[]          = "$field = :$field";
                                                    $params[":$field"] = $data[$field];
                                }
                }

                if (empty($fields)) {
                                return false;
                }

                $sql  = "UPDATE appointments SET " . implode(', ', $fields) . " WHERE id = :id";
                    $stmt = $this->db->prepare($sql);
                    return $stmt->execute($params);
        }

    /**
     * Eliminar cita.
             */
    public function delete(string $id): bool
        {
                    $stmt = $this->db->prepare("DELETE FROM appointments WHERE id = :id");
                    return $stmt->execute([':id' => $id]);
        }

    /**
     * Verificar disponibilidad de horario.
             */
    public function checkAvailability(string $therapistId, string $date, string $startTime, string $endTime): bool
        {
                    $sql = "SELECT COUNT(*) AS count
                                    FROM appointments
                                                    WHERE therapist_id     = :therapist_id
                                                                      AND appointment_date = :date
                                                                                        AND status NOT IN ('cancelled')
                                                                                                          AND (
                                                                                                                                (start_time <= :start1 AND end_time >  :start2)
                                                                                                                                                   OR (start_time <  :end1   AND end_time >= :end2)
                                                                                                                                                                      OR (start_time >= :start3  AND end_time <= :end3)
                                                                                                                                                                                        )";

                $stmt = $this->db->prepare($sql);
                    $stmt->execute([
                                               ':therapist_id' => $therapistId,
                                               ':date'         => $date,
                                               ':start1'       => $startTime,
                                               ':start2'       => $startTime,
                                               ':start3'       => $startTime,
                                               ':end1'         => $endTime,
                                               ':end2'         => $endTime,
                                               ':end3'         => $endTime,
                                           ]);

                $result = $stmt->fetch(PDO::FETCH_ASSOC);
                    $stmt->closeCursor();
                    return (int)$result['count'] === 0;
        }

    /**
     * Verificar si hay citas activas en un rango de tiempo.
             */
    public function hasActiveAppointments(
                string $therapistId,
                ?string $date,
                ?int $dayOfWeek,
                string $startTime,
                string $endTime
            ): bool {
                $blockingStatuses   = ['pending', 'pending_payment', 'payment_review', 'confirmed', 'completed'];
                $statusPlaceholders = implode(',', array_fill(0, count($blockingStatuses), '?'));

                if ($date) {
                                $sql    = "SELECT COUNT(*) AS count
                                                       FROM appointments
                                                                              WHERE therapist_id      = ?
                                                                                                       AND appointment_date  = ?
                                                                                                                                AND status IN ($statusPlaceholders)
                                                                                                                                                         AND (
                                                                                                                                                                                      (start_time <= ? AND end_time >  ?)
                                                                                                                                                                                                                OR (start_time <  ? AND end_time >= ?)
                                                                                                                                                                                                                                          OR (start_time >= ? AND end_time <= ?)
                                                                                                                                                                                                                                                                   )";
                                $params = array_merge([$therapistId, $date], $blockingStatuses, [
                                                                      $startTime, $startTime, $endTime, $endTime, $startTime, $endTime
                                                                  ]);
                } else {
                                $mysqlDay = $dayOfWeek == 7 ? 1 : $dayOfWeek + 1;
                                $sql      = "SELECT COUNT(*) AS count
                                                         FROM appointments
                                                                                  WHERE therapist_id                = ?
                                                                                                             AND DAYOFWEEK(appointment_date) = ?
                                                                                                                                        AND appointment_date            >= CURDATE()
                                                                                                                                                                   AND status IN ($statusPlaceholders)
                                                                                                                                                                                              AND (
                                                                                                                                                                                                                             (start_time <= ? AND end_time >  ?)
                                                                                                                                                                                                                                                         OR (start_time <  ? AND end_time >= ?)
                                                                                                                                                                                                                                                                                     OR (start_time >= ? AND end_time <= ?)
                                                                                                                                                                                                                                                                                                                )";
                                $params   = array_merge([$therapistId, $mysqlDay], $blockingStatuses, [
                                                                        $startTime, $startTime, $endTime, $endTime, $startTime, $endTime
                                                                    ]);
                }

                $stmt = $this->db->prepare($sql);
                $stmt->execute($params);
                $result = $stmt->fetch(PDO::FETCH_ASSOC);
                return (int)$result['count'] > 0;
    }

    // -------------------------------------------------------------------------
    // Metodos privados
    // -------------------------------------------------------------------------

    private function getOrCreatePatientContact(array $data): string
        {
                    $stmt = $this->db->prepare("SELECT id FROM patient_contacts WHERE email = :email");
                    $stmt->execute([':email' => $data['email']]);
                    $existing = $stmt->fetch(PDO::FETCH_ASSOC);
                    $stmt->closeCursor();

                if ($existing) {
                                return $existing['id'];
                }

                $contactId = $this->generateUUID();
                    $nameParts = explode(' ', trim($data['name'] ?? ''), 2);

                $insert = $this->db->prepare(
                                "INSERT INTO patient_contacts (id, first_name, last_name, full_name, email, phone)
                                             VALUES (:id, :first_name, :last_name, :full_name, :email, :phone)"
                            );
                    $insert->execute([
                                                 ':id'         => $contactId,
                                                 ':first_name' => $nameParts[0] ?? '',
                                                 ':last_name'  => $nameParts[1] ?? '',
                                                 ':full_name'  => $data['name'] ?? '',
                                                 ':email'      => $data['email'],
                                                 ':phone'      => $data['phone'] ?? null,
                                             ]);

                return $contactId;
        }

    private function validatePatientPackage(string $packageId, string $therapistId): void
        {
                    $stmt = $this->db->prepare("SELECT * FROM patient_packages WHERE id = ?");
                    $stmt->execute([$packageId]);
                    $pkg = $stmt->fetch(PDO::FETCH_ASSOC);

                if (!$pkg) {
                                throw new \Exception("El paquete de sesiones seleccionado no existe.");
                }
                    if ($pkg['therapist_id'] !== $therapistId) {
                                    throw new \Exception("Este paquete de sesiones solo es valido con el psicologo original.");
                    }
                    if ($pkg['status'] !== 'active' || $pkg['used_sessions'] >= $pkg['total_sessions']) {
                                    throw new \Exception("El paquete de sesiones ya no esta activo o no tiene sesiones disponibles.");
                    }

                if ($pkg['used_sessions'] > 0) {
                                $lastStmt = $this->db->prepare(
                                                    "SELECT CONCAT(appointment_date, ' ', end_time) AS end_datetime
                                                                     FROM appointments
                                                                                      WHERE patient_package_id = ?
                                                                                                         AND status IN ('pending','confirmed','completed','pending_payment','payment_review')
                                                                                                                          ORDER BY appointment_date DESC, end_time DESC
                                                                                                                                           LIMIT 1"
                                                );
                                $lastStmt->execute([$packageId]);
                                $last = $lastStmt->fetch(PDO::FETCH_ASSOC);

                        if ($last && time() < strtotime($last['end_datetime'])) {
                                            throw new \Exception("Aun no puedes programar la siguiente sesion. La sesion anterior aun no ha terminado.");
                        }
                }
        }

    private function registerPromoCodeUsage(string $appointmentId, array $data): void
        {
                    $useId = $this->generateUUID();
                    $stmt  = $this->db->prepare(
                                    "INSERT INTO promo_code_uses (id, promo_code_id, user_email, appointment_id, discount_applied, final_amount)
                                                 VALUES (:id, :promo_code_id, :user_email, :appointment_id, :discount_applied, :final_amount)"
                                );
                    $stmt->execute([
                                               ':id'               => $useId,
                                               ':promo_code_id'    => $data['promo_code_id'],
                                               ':user_email'       => $data['patient_email'],
                                               ':appointment_id'   => $appointmentId,
                                               ':discount_applied' => (float)($data['discount_applied'] ?? 0),
                                               ':final_amount'     => (float)($data['final_price']      ?? 0),
                                           ]);

                $countStmt = $this->db->prepare("UPDATE promo_codes SET uses_count = uses_count + 1 WHERE id = :id");
                    $countStmt->execute([':id' => $data['promo_code_id']]);
        }

    private function createPaymentRecord(string $appointmentId, array $data): void
        {
                    $originalPrice   = (float)($data['original_price']  ?? 0);
                    $discountApplied = (float)($data['discount_applied'] ?? 0);
                    $finalPrice      = (float)($data['final_price']      ?? $originalPrice);

                $stmt = $this->db->prepare(
                                "INSERT INTO appointment_payments (
                                                id, appointment_id, original_price, discount_applied,
                                                                final_price, amount_paid, payment_method, payment_confirmed_at
                                                                             ) VALUES (
                                                                                             :id, :appointment_id, :original_price, :discount_applied,
                                                                                                             :final_price, :amount_paid, :payment_method, :payment_confirmed_at
                                                                                                                          )"
                            );
                    $stmt->execute([
                                               ':id'                   => $this->generateUUID(),
                                               ':appointment_id'       => $appointmentId,
                                               ':original_price'       => $originalPrice,
                                               ':discount_applied'     => $discountApplied,
                                               ':final_price'          => $finalPrice,
                                               ':amount_paid'          => $data['amount_paid']          ?? null,
                                               ':payment_method'       => $data['payment_method']       ?? null,
                                               ':payment_confirmed_at' => $data['payment_confirmed_at'] ?? null,
                                           ]);
        }

    private function incrementPackageSession(string $packageId, string $appointmentId): void
        {
                    $updPkg = $this->db->prepare(
                                    "UPDATE patient_packages
                                                 SET used_sessions = used_sessions + 1,
                                                                  status = CASE
                                                                                       WHEN used_sessions + 1 >= total_sessions THEN 'completed'
                                                                                                            ELSE status
                                                                                                                             END
                                                                                                                                          WHERE id = ? AND used_sessions < total_sessions"
                                );
                    $updPkg->execute([$packageId]);

                $updAppt = $this->db->prepare("UPDATE appointments SET status = 'confirmed' WHERE id = ?");
                    $updAppt->execute([$appointmentId]);
        }

    private function generateUUID(): string
        {
                    $data    = random_bytes(16);
                    $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
                    $data[8] = chr(ord($data[8]) & 0x3f | 0x80);
                    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
        }
    
    /**
     * Registrar confirmacion de pago manual (admin) en appointment_payments.
     * Actualiza payment_confirmed_at y amount_paid si no estaba seteado.
     */
    public function confirmPaymentRecord(string $appointmentId): void {
        $stmt = $this->db->prepare("
            UPDATE appointment_payments
            SET payment_confirmed_at = NOW(),
                amount_paid = COALESCE(amount_paid, final_price)
            WHERE appointment_id = :appointment_id
        ");
        $stmt->execute([':appointment_id' => $appointmentId]);
    }

}
