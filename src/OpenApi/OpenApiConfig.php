<?php

namespace App\OpenApi;

use OpenApi\Attributes as OA;

#[OA\Info(
    title: "Mente Livre API",
    version: "1.0.0",
    description: "API REST para el sistema de gestión de terapeutas y citas de Mente Livre",
    contact: new OA\Contact(
        email: "mentelivre.pe@gmail.com",
        name: "Mente Livre"
    )
)]
#[OA\Server(
    url: "https://backend.mentelivre.org/",
    description: "Servidor de Desarrollo Local"
)]
#[OA\Components(
    securitySchemes: [
        new OA\SecurityScheme(
            securityScheme: 'bearerAuth',
            type: 'http',
            scheme: 'bearer',
            bearerFormat: 'JWT',
            description: 'Token JWT de autenticación. Incluir en el header: Authorization: Bearer {token}'
        )
    ]
)]
#[OA\Tag(name: "Auth", description: "Endpoints de autenticación")]
#[OA\Tag(name: "Therapists", description: "Endpoints para gestión de terapeutas")]
#[OA\Tag(name: "Appointments", description: "Endpoints para gestión de citas")]
#[OA\Tag(name: "Users", description: "Endpoints para gestión de usuarios")]
#[OA\Tag(name: "Swagger", description: "Endpoints de documentación")]
class OpenApiConfig {}
