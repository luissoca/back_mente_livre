<?php
/**
 * Script CLI para generar Swagger manualmente
 * Uso: php src/swagger.php
 * 
 * NOTA: Normalmente no es necesario ejecutar esto manualmente,
 * ya que el endpoint /swagger.json lo genera automáticamente.
 */

require_once __DIR__ . '/../vendor/autoload.php';

use OpenApi\Generator;

try {
    // Generar la documentación OpenAPI - escanear directorio completo
    // openapi.php será detectado automáticamente
    $openapi = Generator::scan([__DIR__]);

    // Guardar en formato JSON
    $jsonPath = __DIR__ . '/../public/swagger.json';
    $yamlPath = __DIR__ . '/../public/swagger.yaml';
    
    file_put_contents($jsonPath, $openapi->toJson());
    file_put_contents($yamlPath, $openapi->toYaml());

    echo "✅ Documentación OpenAPI generada exitosamente.\n";
    echo "📄 JSON: public/swagger.json\n";
    echo "📄 YAML: public/swagger.yaml\n";
    echo "\n💡 Accede a: https://backend.mentelivre.org//docs\n";
} catch (\Exception $e) {
    echo "❌ Error generando Swagger: " . $e->getMessage() . "\n";
    exit(1);
}
