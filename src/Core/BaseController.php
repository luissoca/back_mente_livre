<?php

namespace App\Core;

use App\Exceptions\UnauthorizedException;
use App\Core\Database;
use App\Core\Response;

abstract class BaseController {
    /**
     * Obtener datos del body JSON
     */
    protected function getJsonInput() {
        $data = json_decode(file_get_contents('php://input'), true);
        return $data ?? [];
    }

    /**
     * Obtener parámetros GET
     */
    protected function getQueryParams() {
        return $_GET;
    }

    /**
     * Obtener archivos subidos
     */
    protected function getUploadedFiles() {
        return $_FILES;
    }

    /**
     * Validar campos requeridos
     */
    protected function validateRequired($data, $fields) {
        $missing = [];
        foreach ($fields as $field) {
            if (!isset($data[$field]) || $data[$field] === '' || $data[$field] === null) {
                $missing[] = $field;
            }
        }
        if (!empty($missing)) {
            Response::error('Campos requeridos faltantes: ' . implode(', ', $missing), 400);
        }
        return true;
    }

    /**
     * Verificar que el usuario tenga un permiso específico
     */
    protected function ensurePermission($permission) {
        $current = $GLOBALS['current_user'] ?? null;
        if (!$current) {
            throw new UnauthorizedException('No autorizado', 403);
        }
        $userId = $current['userId'] ?? null;
        $tipo = $current['tipo_usuario'] ?? null;
        
        // Si es admin o god, permitir todo
        if ($tipo === 'admin' || $tipo === 'god') {
            return;
        }
        
        // Aquí puedes implementar tu lógica de permisos
        // Por ejemplo, verificar en la base de datos
        throw new UnauthorizedException('No autorizado', 403);
    }

    /**
     * Obtener el usuario actual desde el token
     */
    protected function getCurrentUser() {
        $current = $GLOBALS['current_user'] ?? null;
        if (!$current) {
            throw new UnauthorizedException('No autorizado', 403);
        }
        return $current;
    }
}
