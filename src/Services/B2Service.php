<?php

namespace App\Services;

use Aws\S3\S3Client;
use Aws\Exception\AwsException;

class B2Service {
    private $s3Client;
    private $bucketName;

    public function __construct() {
        $this->bucketName = $_ENV['B2_BUCKET'] ?? '';
        
        $this->s3Client = new S3Client([
            'version' => 'latest',
            'region'  => $_ENV['B2_REGION'] ?? 'us-east-005',
            'endpoint' => $_ENV['B2_ENDPOINT'] ?? '',
            'credentials' => [
                'key'    => $_ENV['B2_ACCESS_KEY'] ?? '',
                'secret' => $_ENV['B2_SECRET_KEY'] ?? '',
            ],
        ]);
    }

    /**
     * Subir archivo a B2
     * 
     * @param array $file Archivo de $_FILES
     * @param string $path Ruta destino en el bucket (ej: therapists/photo.jpg)
     * @return stringURL relativa para acceder (ej: generic_path)
     */
    public function uploadFile($file, $path) {
        try {
            $result = $this->s3Client->putObject([
                'Bucket' => $this->bucketName,
                'Key'    => $path,
                'Body'   => fopen($file['tmp_name'], 'r'),
                'ContentType' => $file['type'],
                // 'ACL'    => 'private', // B2 buckets are private by default usually
            ]);
            
            return $path;
        } catch (AwsException $e) {
            error_log("Error uploading to B2: " . $e->getMessage());
            throw new \Exception("Error al subir archivo a almacenamiento externo");
        }
    }

    /**
     * Obtener archivo de B2 (para proxy)
     */
    public function getFile($path) {
        try {
            $result = $this->s3Client->getObject([
                'Bucket' => $this->bucketName,
                'Key'    => $path
            ]);

            return [
                'Body' => $result['Body'],
                'ContentType' => $result['ContentType'],
                'ContentLength' => $result['ContentLength']
            ];
        } catch (AwsException $e) {
            error_log("Error getting file from B2: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Eliminar archivo de B2
     */
    public function deleteFile($path) {
        try {
            $this->s3Client->deleteObject([
                'Bucket' => $this->bucketName,
                'Key'    => $path
            ]);
            return true;
        } catch (AwsException $e) {
            error_log("Error deleting file from B2: " . $e->getMessage());
            return false;
        }
    }
}
