<?php

namespace App\Controllers;

use App\Core\BaseController;
use App\Core\Response;
use App\Services\GoogleAuthService;
use OpenApi\Attributes as OA;

#[OA\Tag(
    name: 'GoogleAuth',
    description: 'Autenticación con Google'
)]
class GoogleAuthController extends BaseController {
    private $googleAuthService;

    public function __construct() {
        $this->googleAuthService = new GoogleAuthService();
    }

    #[OA\Post(
        path: '/auth/google',
        summary: 'Login con Google',
        description: 'Autentica un usuario usando un ID Token de Google',
        tags: ['GoogleAuth'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['idToken'],
                properties: [
                    new OA\Property(property: 'idToken', type: 'string', description: 'Google ID Token')
                ]
            )
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'Login exitoso',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'success', type: 'boolean', example: true),
                        new OA\Property(
                            property: 'data',
                            properties: [
                                new OA\Property(property: 'token', type: 'string'),
                                new OA\Property(property: 'refresh_token', type: 'string'),
                                new OA\Property(property: 'user', type: 'object')
                            ],
                            type: 'object'
                        )
                    ]
                )
            ),
            new OA\Response(response: 401, description: 'Token inválido')
        ]
    )]
    public function googleLogin() {
        $data = $this->getJsonInput();
        $this->validateRequired($data, ['idToken']);

        try {
            $result = $this->googleAuthService->authenticate($data['idToken']);
            Response::json($result, 200);
        } catch (\Exception $e) {
            Response::error($e->getMessage(), 401);
        }
    }
}
