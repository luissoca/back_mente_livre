<?php

namespace App\Controllers;

use App\Core\BaseController;
use App\Core\Response;
use App\Services\B2Service;
use OpenApi\Attributes as OA;

#[OA\Tag(name: "File Upload", description: "Subida de archivos (fotos)")]
class FileUploadController extends BaseController {
    
    private const MAX_FILE_SIZE = 5 * 1024 * 1024; // 5MB
    private const ALLOWED_TYPES = ['image/jpeg', 'image/jpg', 'image/png', 'image/webp'];
    private $b2Service;

    public function __construct() {
        $this->b2Service = new B2Service();
    }
    
    #[OA\Post(
        path: '/upload/therapist-photo',
        summary: 'Subir foto de terapeuta',
        operationId: 'uploadTherapistPhoto',
        security: [['bearerAuth' => []]],
        tags: ['File Upload'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\MediaType(
                mediaType: 'multipart/form-data',
                schema: new OA\Schema(
                    properties: [
                        new OA\Property(property: 'file', type: 'string', format: 'binary'),
                        new OA\Property(property: 'therapist_id', type: 'string'),
                        new OA\Property(property: 'photo_type', type: 'string', enum: ['profile', 'friendly'])
                    ]
                )
            )
        ),
        responses: [
            new OA\Response(response: 200, description: 'Archivo subido exitosamente'),
            new OA\Response(response: 400, description: 'Error en la subida')
        ]
    )]
    public function uploadTherapistPhoto(): void {
        if (!isset($_FILES['file'])) {
            Response::error('No se recibió ningún archivo', 400);
        }
        
        $file = $_FILES['file'];
        $therapistId = $_POST['therapist_id'] ?? null;
        $photoType = $_POST['photo_type'] ?? 'profile';
        
        if (!$therapistId) {
            Response::error('therapist_id es requerido', 400);
        }
        
        // Validar tipo de archivo
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mimeType = finfo_file($finfo, $file['tmp_name']);
        
        if (!in_array($mimeType, self::ALLOWED_TYPES)) {
            Response::error('Tipo de archivo no permitido. Solo se permiten imágenes JPEG, PNG o WebP', 400);
        }
        
        // Validar tamaño
        if ($file['size'] > self::MAX_FILE_SIZE) {
            Response::error('El archivo es demasiado grande. Máximo 5MB', 400);
        }
        
        // Generar nombre y path
        $extension = pathinfo($file['name'], PATHINFO_EXTENSION);
        $filename = $therapistId . '_' . time() . '_' . uniqid() . '.' . $extension;
        $b2Path = 'therapists/' . ($photoType === 'friendly' ? 'friendly/' : '') . $filename;
        
        try {
            // Subir a B2
            $this->b2Service->uploadFile($file, $b2Path);
            
            // Retornar path relativo para que el frontend lo solicite a nuestro proxy
            // El proxy route es /uploads/{path}
            // Pero en la base de datos guardamos 'therapists/file.jpg'.
            // Al servir, el frontend llamará a /uploads/therapists/file.jpg
            
            Response::json([
                'data' => [
                    'url' => $b2Path, // Guardamos el path relativo en la BD
                    'filename' => $filename,
                    'size' => $file['size'],
                    'type' => $mimeType
                ]
            ]);
        } catch (\Exception $e) {
            Response::error('Error al subir archivo: ' . $e->getMessage(), 500);
        }
    }
    
    #[OA\Post(
        path: '/upload/team-photo',
        summary: 'Subir foto de equipo',
        operationId: 'uploadTeamPhoto',
        security: [['bearerAuth' => []]],
        tags: ['File Upload'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\MediaType(
                mediaType: 'multipart/form-data',
                schema: new OA\Schema(
                    properties: [
                        new OA\Property(property: 'file', type: 'string', format: 'binary'),
                        new OA\Property(property: 'team_profile_id', type: 'string')
                    ]
                )
            )
        ),
        responses: [
            new OA\Response(response: 200, description: 'Archivo subido exitosamente'),
            new OA\Response(response: 400, description: 'Error en la subida')
        ]
    )]
    public function uploadTeamPhoto(): void {
        if (!isset($_FILES['file'])) {
            Response::error('No se recibió ningún archivo', 400);
        }
        
        $file = $_FILES['file'];
        $teamProfileId = $_POST['team_profile_id'] ?? null;
        
        if (!$teamProfileId) {
            Response::error('team_profile_id es requerido', 400);
        }
        
        // Validar tipo de archivo
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mimeType = finfo_file($finfo, $file['tmp_name']);
        
        if (!in_array($mimeType, self::ALLOWED_TYPES)) {
            Response::error('Tipo de archivo no permitido. Solo se permiten imágenes JPEG, PNG o WebP', 400);
        }
        
        // Validar tamaño
        if ($file['size'] > self::MAX_FILE_SIZE) {
            Response::error('El archivo es demasiado grande. Máximo 5MB', 400);
        }
        
        // Generar nombre y path
        $extension = pathinfo($file['name'], PATHINFO_EXTENSION);
        $filename = $teamProfileId . '_' . time() . '_' . uniqid() . '.' . $extension;
        $b2Path = 'team/' . $filename;
        
        try {
            // Subir a B2
            $this->b2Service->uploadFile($file, $b2Path);
            
            Response::json([
                'data' => [
                    'url' => $b2Path,
                    'filename' => $filename,
                    'size' => $file['size'],
                    'type' => $mimeType
                ]
            ]);
        } catch (\Exception $e) {
            Response::error('Error al subir archivo: ' . $e->getMessage(), 500);
        }
    }
}
