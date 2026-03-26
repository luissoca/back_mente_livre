<?php

namespace App\Core;

class Router {
    private $routes = [];
    private $basePath = '';
    private $middleware = [];

    public function __construct($basePath = '') {
        $this->basePath = $basePath;
    }

    /**
     * Agregar middleware global
     */
    public function addMiddleware($middleware) {
        $this->middleware[] = $middleware;
    }

    /**
     * Registrar ruta GET
     */
    public function get($path, $handler, $middleware = []) {
        $this->registerRoute('GET', $path, $handler, $middleware);
    }

    /**
     * Registrar ruta POST
     */
    public function post($path, $handler, $middleware = []) {
        $this->registerRoute('POST', $path, $handler, $middleware);
    }

    /**
     * Registrar ruta PUT
     */
    public function put($path, $handler, $middleware = []) {
        $this->registerRoute('PUT', $path, $handler, $middleware);
    }

    /**
     * Registrar ruta PATCH
     */
    public function patch($path, $handler, $middleware = []) {
        $this->registerRoute('PATCH', $path, $handler, $middleware);
    }

    /**
     * Registrar ruta DELETE
     */
    public function delete($path, $handler, $middleware = []) {
        $this->registerRoute('DELETE', $path, $handler, $middleware);
    }

    /**
     * Registrar ruta con cualquier método
     */
    public function any($path, $handler, $middleware = []) {
        $methods = ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'];
        foreach ($methods as $method) {
            $this->registerRoute($method, $path, $handler, $middleware);
        }
    }

    /**
     * Registrar una ruta
     */
    private function registerRoute($method, $path, $handler, $middleware = []) {
        // Normalizar path
        if ($path[0] !== '/') {
            $path = '/' . $path;
        }

        // Convertir parámetros dinámicos {id} o {path:.+} a regex
        // Primero manejar parámetros con regex específica: {param:regex}
        $pattern = preg_replace_callback('/\{([a-zA-Z0-9_]+):([^\}]+)\}/', function($matches) {
            return '(' . $matches[2] . ')';
        }, $path);
        
        // Luego manejar parámetros simples: {param}
        $pattern = preg_replace('/\{([a-zA-Z0-9_]+)\}/', '([^/]+)', $pattern);
        
        // Escapar / para regex pero no los grupos de captura
        $pattern = str_replace('/', '\/', $pattern);
        $pattern = '#^' . $pattern . '$#';

        $this->routes[] = [
            'method' => $method,
            'pattern' => $pattern,
            'originalPath' => $path,
            'handler' => $handler,
            'middleware' => array_merge($this->middleware, $middleware)
        ];
    }

    /**
     * Resolver y ejecutar ruta
     */
    public function dispatch() {
        $method = $_SERVER['REQUEST_METHOD'];
        $requestUri = $_SERVER['REQUEST_URI'];
        
        error_log('[Router] Iniciando dispatch - METHOD: ' . $method . ', URI: ' . $requestUri);
        error_log('[Router] basePath configurado: ' . ($this->basePath ?: 'vacío'));
        
        // Ejecutar middleware global primero (para manejar OPTIONS con CORS)
        foreach ($this->middleware as $middleware) {
            if (is_string($middleware)) {
                $middleware = new $middleware();
            }
            $result = $middleware->handle();
            if ($result === false) {
                error_log('[Router] Middleware detuvo la ejecución');
                return; // Middleware detuvo la ejecución
            }
        }

        // Si es OPTIONS, ya fue manejado por CorsMiddleware, salir
        if ($method === 'OPTIONS') {
            error_log('[Router] Es OPTIONS, saliendo');
            return;
        }

        $path = parse_url($requestUri, PHP_URL_PATH);
        error_log('[Router] Path parseado: ' . $path);

        // Quitar base path
        if ($this->basePath && strpos($path, $this->basePath) === 0) {
            $path = substr($path, strlen($this->basePath));
            error_log('[Router] Quitado basePath: ' . $this->basePath . ', path ahora: ' . $path);
        } elseif (preg_match('#(/backend_mente_livre)(/api)?#', $path, $matches)) {
            // Si detecta el path, quitarlo
            $path = preg_replace('#^/backend_mente_livre#', '', $path);
            error_log('[Router] Quitado /backend_mente_livre, path ahora: ' . $path);
        }

        // Quitar /api si existe
        if (strpos($path, '/api') === 0) {
            $path = substr($path, 4);
            error_log('[Router] Quitado /api, path ahora: ' . $path);
        }

        if (empty($path) || $path[0] !== '/') {
            $path = '/' . $path;
        }
        
        error_log('[Router] Path final procesado: ' . $path);

        // Ordenar rutas: estáticas primero, luego dinámicas
        $routes = $this->routes;
        usort($routes, function($a, $b) {
            // Rutas sin parámetros ({}) primero
            $aHasParams = strpos($a['originalPath'], '{') !== false;
            $bHasParams = strpos($b['originalPath'], '{') !== false;
            if ($aHasParams === $bHasParams) return 0;
            return $aHasParams ? 1 : -1;
        });

        // Buscar ruta coincidente
        error_log('[Router] Total de rutas registradas: ' . count($routes));
        foreach ($routes as $route) {
            if ($route['method'] !== $method && $route['method'] !== 'ANY') {
                continue;
            }

            error_log('[Router] Probando ruta: ' . $route['method'] . ' ' . $route['originalPath'] . ' con pattern: ' . $route['pattern']);
            if (preg_match($route['pattern'], $path, $matches)) {
                $handlerInfo = is_string($route['handler']) ? $route['handler'] : (is_callable($route['handler']) ? 'Closure' : 'Unknown');
                error_log('[Router] ¡RUTA COINCIDENTE! Handler: ' . $handlerInfo);
                // Ejecutar middleware
                foreach ($route['middleware'] as $middleware) {
                    if (is_string($middleware)) {
                        $middleware = new $middleware();
                    }
                    $result = $middleware->handle();
                    if ($result === false) {
                        return; // Middleware detuvo la ejecución
                    }
                }

                // Extraer parámetros de la URL
                array_shift($matches); // Remover el match completo
                
                // Obtener nombres de parámetros del path original (id o path:.+)
                preg_match_all('/\{([a-zA-Z0-9_]+)(?::[^\}]+)?\}/', $route['originalPath'], $paramNames);
                $params = [];
                if (!empty($paramNames[1])) {
                    foreach ($paramNames[1] as $index => $name) {
                        if (isset($matches[$index])) {
                            $params[$name] = $matches[$index];
                        }
                    }
                }
                
                // Si hay parámetros, pasarlos en el orden correcto
                $paramValues = array_values($params);

                // Ejecutar handler
                $handler = $route['handler'];
                error_log('[Router] Ejecutando handler: ' . (is_string($handler) ? $handler : 'callable'));
                
                if (is_string($handler) && strpos($handler, '@') !== false) {
                    // Formato: "Controller@method"
                    list($controllerName, $method) = explode('@', $handler);
                    error_log('[Router] Instanciando controller: ' . $controllerName . ', método: ' . $method);
                    $controller = new $controllerName();
                    error_log('[Router] Llamando método del controller');
                    call_user_func_array([$controller, $method], $paramValues);
                } elseif (is_callable($handler)) {
                    // Closure
                    error_log('[Router] Ejecutando closure');
                    call_user_func_array($handler, $paramValues);
                } else {
                    error_log('[Router] ERROR: Handler inválido');
                    Response::error('Handler inválido', 500);
                }

                error_log('[Router] Handler ejecutado exitosamente');
                return;
            }
        }

        // Ruta no encontrada
        error_log('[Router] ERROR: Ruta no encontrada para: ' . $method . ' ' . $path);
        error_log('[Router] Rutas disponibles:');
        foreach ($routes as $r) {
            if ($r['method'] === $method || $r['method'] === 'ANY') {
                error_log('[Router]   - ' . $r['method'] . ' ' . $r['originalPath']);
            }
        }
        Response::error('Ruta no encontrada', 404);
    }
}
