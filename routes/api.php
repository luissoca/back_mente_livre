<?php

use App\Core\Router;
use App\Middleware\CorsMiddleware;
use App\Middleware\AuthMiddleware;
use App\Middleware\RoleMiddleware;

/**
 * Configurar rutas de la API
 */
function configureRoutes(Router $router) {
    // Middleware global (CORS siempre se aplica)
    $router->addMiddleware(new CorsMiddleware());

    // Definicion de middlewares reutilizables
    $authMiddleware  = [new AuthMiddleware()];
    $adminMiddleware = [new AuthMiddleware(), new RoleMiddleware('admin')];
    $staffMiddleware = [new AuthMiddleware(), new RoleMiddleware('admin', 'therapist')];

    // -------------------------------------------------------------------------
    // RUTAS PUBLICAS — sin autenticacion
    // -------------------------------------------------------------------------

    // Autenticacion
    $router->post('/auth/login',           'App\Controllers\AuthController@login');
    $router->post('/auth/register',        'App\Controllers\AuthController@register');
    $router->post('/auth/check-student',   'App\Controllers\AuthController@checkStudent');
    $router->post('/auth/refresh',         'App\Controllers\AuthController@refresh');
    $router->post('/auth/logout',          'App\Controllers\AuthController@logout');
    $router->post('/auth/forgot-password', 'App\Controllers\AuthController@forgotPassword');
    $router->post('/auth/reset-password',  'App\Controllers\AuthController@resetPassword');
    $router->post('/auth/google',          'App\Controllers\GoogleAuthController@googleLogin');

    // Terapeutas (solo lectura publica)
    $router->get('/therapists',      'App\Controllers\TherapistController@index');
    $router->get('/therapists/{id}', 'App\Controllers\TherapistController@show');

    // Contenido del sitio (solo lectura publica)
    $router->get('/site-content', 'App\Controllers\SiteContentController@show');

    // Perfiles del equipo (solo lectura publica)
    $router->get('/team-profiles',      'App\Controllers\TeamProfileController@index');
    $router->get('/team-profiles/{id}', 'App\Controllers\TeamProfileController@show');

    // Horarios publicos (para que pacientes vean disponibilidad)
    $router->get('/therapists/{therapistId}/schedules',          'App\Controllers\WeeklyScheduleController@index');
    $router->get('/therapists/{therapistId}/schedule-overrides', 'App\Controllers\WeeklyScheduleOverrideController@index');

    // Fotos de terapeutas (publicas)
    $router->get('/therapists/{therapistId}/photos', 'App\Controllers\TherapistPhotoController@index');
    $router->get('/therapist-photos/{id}',            'App\Controllers\TherapistPhotoController@show');

    // Precios de terapeutas (publicos)
    $router->get('/therapists/{therapistId}/pricing', 'App\Controllers\TherapistPricingController@index');
    $router->get('/therapist-pricing/{id}',           'App\Controllers\TherapistPricingController@show');

    // Paquetes de sesiones (publico para mostrar en el sitio)
    $router->get('/session-packages', 'App\Controllers\SessionPackageController@index');

    // Validacion de codigos promocionales (publica — usada durante el checkout)
    $router->post('/promo-codes/validate', 'App\Controllers\PromoCodeController@validate');

    // Reglas de dominio de email (publicas — usadas en el registro)
    $router->get('/email-domain-rules',      'App\Controllers\EmailDomainRuleController@index');
    $router->get('/email-domain-rules/{id}', 'App\Controllers\EmailDomainRuleController@show');

    // Webhooks de pasarelas de pago (sin auth — son llamados por servicios externos)
    // $router->post('/webhooks/mercadopago', 'App\Controllers\MercadoPagoController@webhook'); // DESACTIVADO — solo Izipay
    $router->post('/izipay/webhook',       'App\Controllers\IzipayController@webhook');

    // Proxy de imagenes privadas (B2)
    $router->get('/uploads/{path:.+}', 'App\Controllers\ImageController@show');

    // Documentacion API
    $router->get('/docs',              'App\Controllers\SwaggerController@ui');
    $router->get('/swagger.json',      'App\Controllers\SwaggerController@get');
    $router->post('/swagger/generate', 'App\Controllers\SwaggerController@generate');

    // -------------------------------------------------------------------------
    // RUTAS AUTENTICADAS — cualquier usuario logueado
    // -------------------------------------------------------------------------

    // Citas (requieren auth para proteger datos de pacientes)
    $router->get('/appointments',        'App\Controllers\AppointmentController@index',   $authMiddleware);
    $router->get('/appointments/{id}',   'App\Controllers\AppointmentController@show',    $authMiddleware);
    $router->post('/appointments',       'App\Controllers\AppointmentController@store',   $authMiddleware);
    $router->put('/appointments/{id}',   'App\Controllers\AppointmentController@update',  $authMiddleware);
    $router->delete('/appointments/{id}','App\Controllers\AppointmentController@destroy', $authMiddleware);
    $router->patch('/appointments/{id}/confirm-payment','App\Controllers\AppointmentController@confirmPayment', $adminMiddleware);

    // Paquetes del paciente (el propio usuario ve sus paquetes)
    $router->get('/patient-packages/my-packages', 'App\Controllers\PatientPackageController@myPackages', $authMiddleware);

    // Perfil del propio usuario
    $router->get('/users/{id}', 'App\Controllers\UserController@show',  $authMiddleware);
    $router->put('/users/{id}', 'App\Controllers\UserController@update', $authMiddleware);

    // Pagos — solo Izipay activo (MercadoPago y Culqi desactivados)
    $router->post('/izipay/create-payment',             'App\Controllers\IzipayController@createPayment',      $authMiddleware);
    // $router->post('/payments/mercadopago',              'App\Controllers\MercadoPagoController@processPayment',$authMiddleware);
    // $router->post('/payments/mercadopago/preference',   'App\Controllers\MercadoPagoController@createPreference',$authMiddleware);
    // $router->get('/payments/mercadopago/public-key',    'App\Controllers\MercadoPagoController@getPublicKey');
    // $router->post('/payments/culqi',                    'App\Controllers\CulqiController@processPayment',      $authMiddleware);
    // $router->get('/payments/culqi/public-key',          'App\Controllers\CulqiController@getPublicKey');

    // -------------------------------------------------------------------------
    // RUTAS DE STAFF — admin o terapeuta
    // -------------------------------------------------------------------------

    // Horarios (solo staff puede crear/editar)
    $router->post('/therapists/{therapistId}/schedules', 'App\Controllers\WeeklyScheduleController@store',   $staffMiddleware);
    $router->put('/schedules/{id}',                      'App\Controllers\WeeklyScheduleController@update',  $staffMiddleware);
    $router->delete('/schedules/{id}',                   'App\Controllers\WeeklyScheduleController@destroy', $staffMiddleware);

    // Excepciones de horarios
    $router->post('/therapists/{therapistId}/schedule-overrides',       'App\Controllers\WeeklyScheduleOverrideController@store',       $staffMiddleware);
    $router->post('/therapists/{therapistId}/schedule-overrides/batch', 'App\Controllers\WeeklyScheduleOverrideController@storeBatch',  $staffMiddleware);
    $router->put('/schedule-overrides/{id}',                            'App\Controllers\WeeklyScheduleOverrideController@update',      $staffMiddleware);
    $router->delete('/schedule-overrides/{id}',                         'App\Controllers\WeeklyScheduleOverrideController@destroy',     $staffMiddleware);
    $router->delete('/therapists/{therapistId}/schedule-overrides/week','App\Controllers\WeeklyScheduleOverrideController@destroyByWeek',$staffMiddleware);

    // Fotos de terapeutas (solo staff puede subir/editar)
    $router->post('/therapists/{therapistId}/photos', 'App\Controllers\TherapistPhotoController@store',   $staffMiddleware);
    $router->put('/therapist-photos/{id}',            'App\Controllers\TherapistPhotoController@update',  $staffMiddleware);
    $router->delete('/therapist-photos/{id}',         'App\Controllers\TherapistPhotoController@destroy', $staffMiddleware);

    // Subida de archivos (solo staff)
    $router->post('/upload/therapist-photo', 'App\Controllers\FileUploadController@uploadTherapistPhoto', $staffMiddleware);
    $router->post('/upload/team-photo',      'App\Controllers\FileUploadController@uploadTeamPhoto',      $staffMiddleware);

    // -------------------------------------------------------------------------
    // RUTAS DE ADMIN — solo administradores
    // -------------------------------------------------------------------------

    // Gestion de terapeutas
    $router->post('/therapists',       'App\Controllers\TherapistController@store',   $adminMiddleware);
    $router->put('/therapists/{id}',   'App\Controllers\TherapistController@update',  $adminMiddleware);
    $router->delete('/therapists/{id}','App\Controllers\TherapistController@destroy', $adminMiddleware);

    // Precios de terapeutas
    $router->post('/therapists/{therapistId}/pricing',    'App\Controllers\TherapistPricingController@store',       $adminMiddleware);
    $router->put('/therapist-pricing/{id}',               'App\Controllers\TherapistPricingController@update',      $adminMiddleware);
    $router->delete('/therapist-pricing/{id}',            'App\Controllers\TherapistPricingController@destroy',     $adminMiddleware);
    $router->put('/therapists/{therapistId}/pricing/batch','App\Controllers\TherapistPricingController@updateBatch', $adminMiddleware);

    // Gestion de usuarios (solo admin puede listar todos y desactivar/activar)
    $router->get('/users',                    'App\Controllers\UserController@index',      $adminMiddleware);
    $router->patch('/users/{id}/deactivate',  'App\Controllers\UserController@deactivate', $adminMiddleware);
    $router->patch('/users/{id}/activate',    'App\Controllers\UserController@activate',   $adminMiddleware);

    // Roles de usuarios
    $router->get('/users/{userId}/roles',              'App\Controllers\UserRoleController@index',   $adminMiddleware);
    $router->post('/users/{userId}/roles',             'App\Controllers\UserRoleController@store',   $adminMiddleware);
    $router->delete('/users/{userId}/roles/{roleName}','App\Controllers\UserRoleController@destroy', $adminMiddleware);

    // Contenido del sitio (solo admin puede editar)
    $router->put('/site-content', 'App\Controllers\SiteContentController@update', $adminMiddleware);

    // Perfiles del equipo (solo admin puede crear/editar/borrar)
    $router->post('/team-profiles',       'App\Controllers\TeamProfileController@store',   $adminMiddleware);
    $router->put('/team-profiles/{id}',   'App\Controllers\TeamProfileController@update',  $adminMiddleware);
    $router->delete('/team-profiles/{id}','App\Controllers\TeamProfileController@destroy', $adminMiddleware);

    // Codigos promocionales (gestion — solo admin)
    $router->get('/promo-codes',        'App\Controllers\PromoCodeController@index',   $adminMiddleware);
    $router->get('/promo-codes/{id}',   'App\Controllers\PromoCodeController@show',    $adminMiddleware);
    $router->post('/promo-codes',       'App\Controllers\PromoCodeController@store',   $adminMiddleware);
    $router->put('/promo-codes/{id}',   'App\Controllers\PromoCodeController@update',  $adminMiddleware);
    $router->delete('/promo-codes/{id}','App\Controllers\PromoCodeController@destroy', $adminMiddleware);

    // Reglas de dominio de email (solo admin puede crear/editar/borrar)
    $router->post('/email-domain-rules',       'App\Controllers\EmailDomainRuleController@store',   $adminMiddleware);
    $router->put('/email-domain-rules/{id}',   'App\Controllers\EmailDomainRuleController@update',  $adminMiddleware);
    $router->delete('/email-domain-rules/{id}','App\Controllers\EmailDomainRuleController@destroy', $adminMiddleware);

    // Paquetes de sesiones (solo admin puede crear/editar/borrar)
    $router->post('/session-packages',       'App\Controllers\SessionPackageController@create', $adminMiddleware);
    $router->put('/session-packages/{id}',   'App\Controllers\SessionPackageController@update', $adminMiddleware);
    $router->delete('/session-packages/{id}','App\Controllers\SessionPackageController@delete', $adminMiddleware);

    // Paquetes de pacientes (gestion admin)
    $router->get('/patient-packages',        'App\Controllers\PatientPackageController@index',   $adminMiddleware);
    $router->get('/patient-packages/{id}',   'App\Controllers\PatientPackageController@show',    $adminMiddleware);
    $router->post('/patient-packages',       'App\Controllers\PatientPackageController@store',   $adminMiddleware);
    $router->put('/patient-packages/{id}',   'App\Controllers\PatientPackageController@update',  $adminMiddleware);
    $router->delete('/patient-packages/{id}','App\Controllers\PatientPackageController@destroy', $adminMiddleware);

    // Ruta de prueba basica (sin datos sensibles)
    $router->get('/test', function() {
        \App\Core\Response::json([
            'message'   => 'API Mente Livre funcionando correctamente',
            'timestamp' => date('Y-m-d H:i:s'),
            'timezone'  => date_default_timezone_get(),
        ]);
    });

    // Ruta de prueba con autenticacion
    $router->get('/test-auth', function() {
        $user = $GLOBALS['current_user'] ?? null;
        \App\Core\Response::json([
            'message' => 'Autenticacion exitosa',
            'user'    => $user,
        ]);
    }, $authMiddleware);
}
