<?php
require_once __DIR__ . '/../vendor/autoload.php';

use App\Services\B2Service;
use Dotenv\Dotenv;

// Cargar variables de entorno
if (file_exists(__DIR__ . '/../.env')) {
    $dotenv = Dotenv::createImmutable(__DIR__ . '/../');
    $dotenv->load();
}

$b2 = new B2Service();

$directories = [
    'team/photos' => __DIR__ . '/../public/uploads/team/photos',
    'therapists/photos' => __DIR__ . '/../public/uploads/therapists/photos'
];

$sqlUpdates = [];
echo "Iniciando migración...\n";

foreach ($directories as $b2Prefix => $localPath) {
    if (!is_dir($localPath)) {
        echo "Directorio no encontrado: $localPath\n";
        continue;
    }

    $files = scandir($localPath);
    foreach ($files as $file) {
        if ($file === '.' || $file === '..' || $file === '.gitkeep' || $file === 'README.md') continue;

        $filePath = $localPath . '/' . $file;
        $b2Path = $b2Prefix . '/' . $file;

        echo "Subiendo $file a B2 ($b2Path)... ";

        try {
            $fileData = [
                'tmp_name' => $filePath,
                'type' => mime_content_type($filePath)
            ];
            
            $b2->uploadFile($fileData, $b2Path);
            echo "Hecho.\n";

            // Preparar SQL
            if ($b2Prefix === 'team/photos') {
                // Para perfiles de equipo, buscar por friendly_photo_url que contenga el nombre del archivo
                $sqlUpdates[] = "UPDATE team_profiles SET friendly_photo_url = '$b2Path' WHERE friendly_photo_url LIKE '%$file%';";
            } else {
                // Para fotos de terapeutas, buscar por photo_url que contenga el nombre del archivo
                $sqlUpdates[] = "UPDATE therapist_photos SET photo_url = '$b2Path' WHERE photo_url LIKE '%$file%';";
            }

        } catch (\Exception $e) {
            echo "ERROR: " . $e->getMessage() . "\n";
        }
    }
}

echo "\n--- SCRIPT SQL PARA ACTUALIZAR BASE DE DATOS ---\n";
echo implode("\n", $sqlUpdates);
echo "\n-----------------------------------------------\n";

file_put_contents(__DIR__ . '/update_db_b2.sql', implode("\n", $sqlUpdates));
echo "Script SQL guardado en " . __DIR__ . "/update_db_b2.sql\n";
