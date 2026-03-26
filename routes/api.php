<?php

use App\Core\Router;
use App\Middleware\CorsMiddleware;
use App\Middleware\AuthMiddleware;

/**
 * Configurar rutas de la API
 */
function configureRoutes(Router $router) {
    // Middleware global (CORS siempre se aplica)
    $router->addMiddleware(new CorsMiddleware());

    // Rutas de autenticación (sin auth middleware)
    $router->post('/auth/login', 'App\Controllers\AuthController@login');
    $router->post('/auth/register', 'App\Controllers\AuthController@register');
    $router->post('/auth/check-student', 'App\Controllers\AuthController@checkStudent');
    $router->post('/auth/refresh', 'App\Controllers\AuthController@refresh');
    $router->post('/auth/logout', 'App\Controllers\AuthController@logout');
    $router->post('/auth/forgot-password', 'App\Controllers\AuthController@forgotPassword');
    $router->post('/auth/reset-password', 'App\Controllers\AuthController@resetPassword');
    $router->post('/auth/google', 'App\Controllers\GoogleAuthController@googleLogin');

    // Rutas protegidas (requieren autenticación)
    $authMiddleware = [new AuthMiddleware()];

    // Rutas de terapeutas (públicas - sin autenticación, pero index puede requerir auth si tiene ?all=true)
    $router->get('/therapists', 'App\Controllers\TherapistController@index');
    $router->get('/therapists/{id}', 'App\Controllers\TherapistController@show');
    
    // Rutas de terapeutas (protegidas - solo admin)
    $router->post('/therapists', 'App\Controllers\TherapistController@store', $authMiddleware);
    $router->put('/therapists/{id}', 'App\Controllers\TherapistController@update', $authMiddleware);
    $router->delete('/therapists/{id}', 'App\Controllers\TherapistController@destroy', $authMiddleware);
    
    // Rutas de citas (appointments)
    $router->get('/appointments', 'App\Controllers\AppointmentController@index');
    $router->get('/appointments/{id}', 'App\Controllers\AppointmentController@show');
    $router->post('/appointments', 'App\Controllers\AppointmentController@store');
    $router->put('/appointments/{id}', 'App\Controllers\AppointmentController@update', $authMiddleware);
    $router->delete('/appointments/{id}', 'App\Controllers\AppointmentController@destroy', $authMiddleware);
    
    // Rutas de paquetes de sesiones (session packages)
    $router->get('/session-packages', 'App\Controllers\SessionPackageController@index');
    $router->post('/session-packages', 'App\Controllers\SessionPackageController@create', $authMiddleware);
    $router->put('/session-packages/{id}', 'App\Controllers\SessionPackageController@update', $authMiddleware);
    $router->delete('/session-packages/{id}', 'App\Controllers\SessionPackageController@delete', $authMiddleware);

    // Rutas de paquetes de pacientes (patient packages)
    $router->get('/patient-packages/my-packages', 'App\Controllers\PatientPackageController@myPackages', $authMiddleware);

    // Rutas de usuarios (protegidas)
    $router->get('/users', 'App\Controllers\UserController@index', $authMiddleware);
    $router->get('/users/{id}', 'App\Controllers\UserController@show', $authMiddleware);
    $router->put('/users/{id}', 'App\Controllers\UserController@update', $authMiddleware);
    
    // Rutas de contenido del sitio
    $router->get('/site-content', 'App\Controllers\SiteContentController@show');
    $router->put('/site-content', 'App\Controllers\SiteContentController@update', $authMiddleware);
    
    // Rutas de perfiles del equipo
    $router->get('/team-profiles', 'App\Controllers\TeamProfileController@index');
    $router->get('/team-profiles/{id}', 'App\Controllers\TeamProfileController@show');
    $router->post('/team-profiles', 'App\Controllers\TeamProfileController@store', $authMiddleware);
    $router->put('/team-profiles/{id}', 'App\Controllers\TeamProfileController@update', $authMiddleware);
    $router->delete('/team-profiles/{id}', 'App\Controllers\TeamProfileController@destroy', $authMiddleware);
    
    // Rutas de códigos promocionales
    $router->get('/promo-codes', 'App\Controllers\PromoCodeController@index', $authMiddleware);
    $router->get('/promo-codes/{id}', 'App\Controllers\PromoCodeController@show', $authMiddleware);
    $router->post('/promo-codes', 'App\Controllers\PromoCodeController@store', $authMiddleware);
    $router->put('/promo-codes/{id}', 'App\Controllers\PromoCodeController@update', $authMiddleware);
    $router->delete('/promo-codes/{id}', 'App\Controllers\PromoCodeController@destroy', $authMiddleware);
    $router->post('/promo-codes/validate', 'App\Controllers\PromoCodeController@validate');
    
    // Rutas de horarios semanales
    $router->get('/therapists/{therapistId}/schedules', 'App\Controllers\WeeklyScheduleController@index');
    $router->post('/therapists/{therapistId}/schedules', 'App\Controllers\WeeklyScheduleController@store', $authMiddleware);
    $router->put('/schedules/{id}', 'App\Controllers\WeeklyScheduleController@update', $authMiddleware);
    $router->delete('/schedules/{id}', 'App\Controllers\WeeklyScheduleController@destroy', $authMiddleware);
    
    // Rutas de excepciones de horarios (schedule overrides)
    $router->get('/therapists/{therapistId}/schedule-overrides', 'App\Controllers\WeeklyScheduleOverrideController@index');
    $router->post('/therapists/{therapistId}/schedule-overrides', 'App\Controllers\WeeklyScheduleOverrideController@store', $authMiddleware);
    $router->post('/therapists/{therapistId}/schedule-overrides/batch', 'App\Controllers\WeeklyScheduleOverrideController@storeBatch', $authMiddleware);
    $router->put('/schedule-overrides/{id}', 'App\Controllers\WeeklyScheduleOverrideController@update', $authMiddleware);
    $router->delete('/schedule-overrides/{id}', 'App\Controllers\WeeklyScheduleOverrideController@destroy', $authMiddleware);
    $router->delete('/therapists/{therapistId}/schedule-overrides/week', 'App\Controllers\WeeklyScheduleOverrideController@destroyByWeek', $authMiddleware);
    
    // Rutas de reglas de dominios de email
    $router->get('/email-domain-rules', 'App\Controllers\EmailDomainRuleController@index');
    $router->get('/email-domain-rules/{id}', 'App\Controllers\EmailDomainRuleController@show');
    $router->post('/email-domain-rules', 'App\Controllers\EmailDomainRuleController@store', $authMiddleware);
    $router->put('/email-domain-rules/{id}', 'App\Controllers\EmailDomainRuleController@update', $authMiddleware);
    $router->delete('/email-domain-rules/{id}', 'App\Controllers\EmailDomainRuleController@destroy', $authMiddleware);
    
    // Rutas de fotos de terapeutas
    $router->get('/therapists/{therapistId}/photos', 'App\Controllers\TherapistPhotoController@index');
    $router->get('/therapist-photos/{id}', 'App\Controllers\TherapistPhotoController@show');
    $router->post('/therapists/{therapistId}/photos', 'App\Controllers\TherapistPhotoController@store', $authMiddleware);
    $router->put('/therapist-photos/{id}', 'App\Controllers\TherapistPhotoController@update', $authMiddleware);
    $router->delete('/therapist-photos/{id}', 'App\Controllers\TherapistPhotoController@destroy', $authMiddleware);
    
    // Rutas de precios de terapeutas
    $router->get('/therapists/{therapistId}/pricing', 'App\Controllers\TherapistPricingController@index');
    $router->put('/therapists/{therapistId}/pricing/batch', 'App\Controllers\TherapistPricingController@updateBatch', $authMiddleware);
    $router->get('/therapist-pricing/{id}', 'App\Controllers\TherapistPricingController@show');
    $router->post('/therapists/{therapistId}/pricing', 'App\Controllers\TherapistPricingController@store', $authMiddleware);
    $router->put('/therapist-pricing/{id}', 'App\Controllers\TherapistPricingController@update', $authMiddleware);
    $router->delete('/therapist-pricing/{id}', 'App\Controllers\TherapistPricingController@destroy', $authMiddleware);
    
    // Rutas de roles de usuarios
    $router->get('/users/{userId}/roles', 'App\Controllers\UserRoleController@index', $authMiddleware);
    $router->post('/users/{userId}/roles', 'App\Controllers\UserRoleController@store', $authMiddleware);
    $router->delete('/users/{userId}/roles/{roleName}', 'App\Controllers\UserRoleController@destroy', $authMiddleware);
    
    // Rutas de subida de archivos
    $router->post('/upload/therapist-photo', 'App\Controllers\FileUploadController@uploadTherapistPhoto', $authMiddleware);
    $router->post('/upload/team-photo', 'App\Controllers\FileUploadController@uploadTeamPhoto', $authMiddleware);
    
    // Rutas de MercadoPago
    $router->post('/payments/mercadopago', 'App\Controllers\MercadoPagoController@processPayment');
    $router->post('/payments/mercadopago/preference', 'App\Controllers\MercadoPagoController@createPreference');
    $router->post('/webhooks/mercadopago', 'App\Controllers\MercadoPagoController@webhook');
    $router->get('/payments/mercadopago/public-key', 'App\Controllers\MercadoPagoController@getPublicKey');

    // Rutas de Izipay
    $router->post('/izipay/create-payment', 'App\Controllers\IzipayController@createPayment');
    $router->post('/izipay/webhook', 'App\Controllers\IzipayController@webhook');

    // Serve private images from B2 via proxy
    $router->get('/uploads/{path:.+}', 'App\Controllers\ImageController@show');

    // Rutas de Culqi (pasarela embebida)
    $router->post('/payments/culqi', 'App\Controllers\CulqiController@processPayment');
    $router->get('/payments/culqi/public-key', 'App\Controllers\CulqiController@getPublicKey');

    // Documentación API - Swagger UI
    $router->get('/docs', 'App\Controllers\SwaggerController@ui');
    
    // Generar documentación OpenAPI (automático)
    $router->get('/swagger.json', 'App\Controllers\SwaggerController@get');
    $router->post('/swagger/generate', 'App\Controllers\SwaggerController@generate');
    
    // Ruta de prueba (sin autenticación)
    $router->get('/test', function() {
        \App\Core\Response::json([
            'message' => 'API Mente Livre funcionando correctamente',
            'timestamp' => date('Y-m-d H:i:s'),
            'timezone' => date_default_timezone_get()
        ]);
    });
    
    // Ruta de prueba con autenticación
    $router->get('/test-auth', function() {
        $user = $GLOBALS['current_user'] ?? null;
        \App\Core\Response::json([
            'message' => 'Autenticación exitosa',
            'user' => $user
        ]);
    }, $authMiddleware);

    // Debug route for patient contact creation
    $router->post('/debug-contact', function() {
        $data = json_decode(file_get_contents('php://input'), true);
        $email = $data['email'] ?? 'test@example.com';
        $db = \App\Core\Database::getInstance()->getConnection();
        
        try {
            $db->beginTransaction();
            
            // 1. SELECT
            $sql = "SELECT id FROM patient_contacts WHERE email = :email";
            $stmt = $db->prepare($sql);
            $stmt->execute([':email' => $email]);
            $existing = $stmt->fetch(\PDO::FETCH_ASSOC);
            
            $result = ['step_1_select' => 'success', 'found' => (bool)$existing];
            
            if (!$existing) {
                // 2. INSERT
                $id = sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
                    mt_rand(0, 0xffff), mt_rand(0, 0xffff),
                    mt_rand(0, 0xffff),
                    mt_rand(0, 0x0fff) | 0x4000,
                    mt_rand(0, 0x3fff) | 0x8000,
                    mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
                );
                
                $sqlInput = "INSERT INTO patient_contacts (id, first_name, last_name, full_name, email, phone)
                             VALUES (:id, :first, :last, :full, :email, :phone)";
                $stmtInsert = $db->prepare($sqlInput);
                $stmtInsert->execute([
                    ':id' => $id,
                    ':first' => 'Test',
                    ':last' => 'User',
                    ':full' => 'Test User',
                    ':email' => $email,
                    ':phone' => '123456789'
                ]);
                $result['step_2_insert'] = 'success';
                $result['new_id'] = $id;
                $contactId = $id;
            } else {
                $contactId = $existing['id'];
            }
            
            // 3. INSERT APPOINTMENT (Simulation)
            $apptId = sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
                mt_rand(0, 0xffff), mt_rand(0, 0xffff),
                mt_rand(0, 0xffff),
                mt_rand(0, 0x0fff) | 0x4000,
                mt_rand(0, 0x3fff) | 0x8000,
                mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
            );
            
            // Get a valid therapist ID (just pick one)
            $stmtT = $db->query("SELECT id FROM therapists LIMIT 1");
            $therapistId = $stmtT->fetchColumn();
            
            if (!$therapistId) throw new \Exception("No therapists found");
            
            $sqlAppt = "INSERT INTO appointments (
                id, therapist_id, user_id, patient_contact_id, patient_email, 
                patient_name, patient_phone, consultation_reason, appointment_date,
                start_time, end_time, status, pricing_tier, email_used, notes
            ) VALUES (
                :id, :therapist_id, :user_id, :patient_contact_id, :patient_email,
                :patient_name, :patient_phone, :consultation_reason, :appointment_date,
                :start_time, :end_time, :status, :pricing_tier, :email_used, :notes
            )";
            
            $stmtA = $db->prepare($sqlAppt);
            $stmtA->execute([
                ':id' => $apptId,
                ':therapist_id' => $therapistId,
                ':user_id' => null, // Try NULL first to rule out User FK issues
                ':patient_contact_id' => $contactId,
                ':patient_email' => $email,
                ':patient_name' => 'Test User',
                ':patient_phone' => '123456789',
                ':consultation_reason' => 'Test Reason',
                ':appointment_date' => date('Y-m-d', strtotime('+3 days')),
                ':start_time' => '10:00:00',
                ':end_time' => '11:00:00',
                ':status' => 'pending',
                ':pricing_tier' => 'public',
                ':email_used' => $email,
                ':notes' => 'Debug test'
            ]);
            
            $result['step_3_insert_appt'] = 'success';
            $result['appt_id'] = $apptId;
            
            $db->commit();
            \App\Core\Response::json(['status' => 'success', 'trace' => $result]);
            
        } catch (\Exception $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            \App\Core\Response::json([
                'status' => 'error',
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ], 500);
        }
    });
    
    // Ruta de prueba de conexión a base de datos
    $router->get('/test-db', function() {
        try {
            $db = \App\Core\Database::getInstance();
            $conn = $db->getConnection();
            
            // Probar query simple
            $stmt = $db->executeQuery("SELECT 1 as val, current_database() as db_name, version() as version");
            $result = $stmt->fetch();

            // Listar tablas
            $tablesStmt = $db->executeQuery("
                SELECT table_name 
                FROM information_schema.tables 
                WHERE table_schema = 'public' 
                ORDER BY table_name
            ");
            $tables = $tablesStmt->fetchAll(PDO::FETCH_COLUMN);

            // Get columns for patient_contacts to verify schema
            $columnsStmt = $db->executeQuery("
                SELECT column_name, data_type, is_nullable
                FROM information_schema.columns
                WHERE table_name = 'patient_contacts'
                ORDER BY ordinal_position
            ");
            $columns = $columnsStmt->fetchAll(PDO::FETCH_ASSOC);
            
            \App\Core\Response::json([
                'status' => 'success',
                'message' => 'Conexión a base de datos exitosa',
                'data' => $result,
                'tables' => $tables,
                'patient_contacts_columns' => $columns,
                'env_vars' => [
                    'host' => getenv('DB_HOST') ? 'set' : 'not set',
                    'database' => getenv('DB_DATABASE') ? 'set' : 'not set'
                ]
            ]);
        } catch (\Exception $e) {
            \App\Core\Response::json([
                'status' => 'error',
                'message' => 'Error de conexión: ' . $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ], 500);
        }
    });
}
