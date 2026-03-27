<?php

namespace App\Controllers;

use App\Core\Response;
use App\Services\SessionPackageService;
use Exception;

class SessionPackageController
    {
            private $service;

    public function __construct()
        {
                    $this->service = new SessionPackageService();
        }

    /**
     * Listar paquetes de sesiones.
             * - Admin: ve todos (activos e inactivos)
             * - Publico/usuario: solo los activos
         */
    public function index()
        {
                    try {
                                    // Detectar si el usuario es admin usando JWT (GLOBALS), no $_SESSION
                                    $currentUser = $GLOBALS['current_user'] ?? null;
                                    $isAdmin     = false;

                                    if ($currentUser) {
                                                        $roles = $currentUser['roles'] ?? [];
                                                        if (is_array($roles)) {
                                                                                foreach ($roles as $role) {
                                                                                                            $roleName = is_array($role) ? ($role['name'] ?? '') : $role;
                                                                                                            if ($roleName === 'admin') {
                                                                                                                                            $isAdmin = true;
                                                                                                                                            break;
                                                                                                                }
                                                                                    }
                                                        } elseif (($currentUser['role'] ?? '') === 'admin') {
                                                                                $isAdmin = true;
                                                        }
                                    }

                                    // onlyActive = true si NO es admin
                                    $packages = $this->service->getAllPackages(!$isAdmin);

                                    Response::json([
                                                                   'status' => 'success',
                                                                   'data'   => $packages
                                                               ]);

                    } catch (Exception $e) {
                                    Response::json([
                                                                   'status'  => 'error',
                                                                   'message' => 'Error al obtener paquetes de sesiones: ' . $e->getMessage()
                                                               ], 500);
                    }
        }

    /**
     * Crear un nuevo paquete de sesiones (solo admin).
             */
    public function create()
        {
                    try {
                                    $data = json_decode(file_get_contents('php://input'), true);

                                    if (!isset($data['name']) || !isset($data['session_count']) || !isset($data['discount_percent'])) {
                                                        Response::json([
                                                                                           'status'  => 'error',
                                                                                           'message' => 'Faltan campos requeridos: name, session_count, discount_percent'
                                                                                       ], 400);
                                                        return;
                                    }

                                    $id = $this->service->createPackage($data);

                                    Response::json([
                                                                   'status'  => 'success',
                                                                   'message' => 'Paquete creado exitosamente',
                                                                   'data'    => ['id' => $id]
                                                               ], 201);

                    } catch (Exception $e) {
                                    Response::json([
                                                                   'status'  => 'error',
                                                                   'message' => 'Error al crear paquete: ' . $e->getMessage()
                                                               ], 500);
                    }
        }

    /**
     * Actualizar un paquete de sesiones (solo admin).
         */
    public function update($id)
        {
                    try {
                                    $data    = json_decode(file_get_contents('php://input'), true);
                                    $updated = $this->service->updatePackage($id, $data);

                                    if ($updated) {
                                                        Response::json([
                                                                                           'status'  => 'success',
                                                                                           'message' => 'Paquete actualizado exitosamente'
                                                                                       ]);
                                    } else {
                                                        Response::json([
                                                                                           'status'  => 'error',
                                                                                           'message' => 'No se pudo actualizar el paquete o no hubo cambios'
                                                                                       ], 400);
                                    }

                    } catch (Exception $e) {
                                    Response::json([
                                                                   'status'  => 'error',
                                                                   'message' => 'Error al actualizar paquete: ' . $e->getMessage()
                                                               ], 500);
                    }
        }

    /**
     * Eliminar un paquete de sesiones (solo admin).
         */
    public function delete($id)
        {
                    try {
                                    $deleted = $this->service->deletePackage($id);

                                    if ($deleted) {
                                                        Response::json([
                                                                                           'status'  => 'success',
                                                                                           'message' => 'Paquete eliminado exitosamente'
                                                                                       ]);
                                    } else {
                                                        Response::json([
                                                                                           'status'  => 'error',
                                                                                           'message' => 'Paquete no encontrado'
                                                                                       ], 404);
                                    }

                    } catch (Exception $e) {
                                    Response::json([
                                                                   'status'  => 'error',
                                                                   'message' => $e->getMessage()
                                                               ], 400);
                    }
        }
    }
