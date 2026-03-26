# Backend API - Mente Livre

Backend API en PHP nativo con arquitectura MVC limpia.

## Características

- **PHP 8.2** con Apache
- **MariaDB 10.5** como base de datos
- **Docker & Docker Compose** para desarrollo
- **JWT Authentication** para seguridad
- **Arquitectura MVC** con separación clara de responsabilidades
- **Router personalizado** con soporte para rutas dinámicas
- **Middleware** para CORS y autenticación
- **PSR-4 Autoloading** con Composer

## Requisitos

- Docker y Docker Compose instalados
- Git

## Estructura del Proyecto

```
backend_mente_livre/
├── config/              # Configuración (env, CORS)
├── routes/              # Definición de rutas de la API
├── src/
│   ├── Controllers/     # Controladores
│   ├── Core/           # Clases core (Router, Database, Response, BaseController)
│   ├── Middleware/     # Middleware (CORS, Auth)
│   ├── Services/       # Lógica de negocio
│   ├── Repositories/   # Acceso a datos
│   ├── Models/         # Modelos de datos
│   └── Exceptions/     # Excepciones personalizadas
├── storage/            # Almacenamiento temporal (refresh tokens, logs)
├── .htaccess          # Configuración Apache (rewrite rules)
├── index.php          # Punto de entrada
├── composer.json      # Dependencias PHP
└── .env.example       # Ejemplo de variables de entorno
```

## Instalación y Configuración

### 1. Clonar el repositorio

```bash
cd back_mente_livre
```

### 2. Configurar variables de entorno

Copia el archivo `.env.example` a `.env` dentro de la carpeta `backend_mente_livre`:

```bash
cd backend_mente_livre
cp .env.example .env
```

Edita el archivo `.env` con tus configuraciones:

```env
DB_HOST=mentelivre_db
DB_PORT=3306
DB_DATABASE=mentelivre_db
DB_USER=mentelivre_user
DB_PASSWORD=mentelivre_pass
JWT_SECRET_KEY=tu_clave_secreta_muy_segura
```

**IMPORTANTE:** En producción, cambia el `JWT_SECRET_KEY` por una clave segura única.

### 3. Iniciar con Docker

Desde la raíz del proyecto (donde está el `docker-compose.yml`):

```bash
docker-compose up -d
```

Esto iniciará:
- **PHP Backend** en `http://localhost:8081`
- **MariaDB** en el puerto `3308`

### 4. Instalar dependencias de PHP

Entra al contenedor y ejecuta Composer:

```bash
docker exec -it mentelivre_backend bash
cd backend_mente_livre
composer install
```

### 5. Crear la base de datos

Conéctate a MySQL y crea las tablas necesarias. Ejemplo básico de tabla de usuarios:

```sql
CREATE TABLE usuarios (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(100) UNIQUE NOT NULL,
    password VARCHAR(255) NOT NULL,
    nombre VARCHAR(255),
    email VARCHAR(255),
    activo TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- Insertar usuario de prueba (password: admin123)
INSERT INTO usuarios (username, password, nombre, email) 
VALUES ('admin', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Administrador', 'admin@mentelivre.com');
```

### 6. Probar la API

Accede a `http://localhost:8081/test` y deberías ver:

```json
{
  "message": "API Mente Livre funcionando correctamente",
  "timestamp": "2024-01-15 10:30:00",
  "timezone": "America/Lima"
}
```

## Endpoints Disponibles

### Autenticación

- `POST /auth/login` - Iniciar sesión
  ```json
  {
    "username": "admin",
    "password": "admin123"
  }
  ```

- `POST /auth/refresh` - Renovar access token
  ```json
  {
    "refresh_token": "tu_refresh_token"
  }
  ```

- `POST /auth/logout` - Cerrar sesión
  ```json
  {
    "refresh_token": "tu_refresh_token"
  }
  ```

### Rutas de Prueba

- `GET /test` - Prueba básica (sin autenticación)
- `GET /test-auth` - Prueba con autenticación requerida

## Desarrollo

### Agregar nuevas rutas

Edita `routes/api.php`:

```php
// Sin autenticación
$router->get('/mi-ruta', 'App\Controllers\MiController@miMetodo');

// Con autenticación
$router->post('/mi-ruta-protegida', 'App\Controllers\MiController@metodoProtegido', $authMiddleware);

// Con parámetros dinámicos
$router->get('/usuarios/{id}', 'App\Controllers\UsuarioController@show', $authMiddleware);
```

### Crear un nuevo controlador

Crea un archivo en `src/Controllers/`:

```php
<?php

namespace App\Controllers;

use App\Core\BaseController;
use App\Core\Response;

class MiController extends BaseController {
    public function index() {
        Response::json(['message' => 'Hola desde mi controlador']);
    }
}
```

### Acceder a la base de datos

```php
use App\Core\Database;

$db = Database::getInstance();

// Obtener todos los registros
$usuarios = $db->fetchAll("SELECT * FROM usuarios WHERE activo = ?", [1]);

// Obtener un solo registro
$usuario = $db->fetchOne("SELECT * FROM usuarios WHERE id = ?", [$id]);

// Ejecutar query
$stmt = $db->executeQuery("UPDATE usuarios SET nombre = ? WHERE id = ?", [$nombre, $id]);
```

## Comandos útiles

```bash
# Ver logs del backend
docker logs -f mentelivre_backend

# Ver logs de la base de datos
docker logs -f mentelivre_db

# Entrar al contenedor del backend
docker exec -it mentelivre_backend bash

# Entrar a MySQL
docker exec -it mentelivre_db mysql -u mentelivre_user -p

# Detener los contenedores
docker-compose down

# Reconstruir los contenedores
docker-compose up -d --build
```

## Autenticación con JWT

Para acceder a rutas protegidas, incluye el token en el header de las peticiones:

```
Authorization: Bearer <tu_access_token>
```

Ejemplo con cURL:

```bash
curl -X GET http://localhost:8081/test-auth \
  -H "Authorization: Bearer eyJ0eXAiOiJKV1QiLCJhbGc..."
```

## Notas de Producción

1. **Cambiar JWT_SECRET_KEY** en el archivo `.env` por una clave segura
2. **Usar HTTPS** en producción
3. **Configurar CORS** adecuadamente en `src/Middleware/CorsMiddleware.php`
4. **Configurar base de datos** externa (no usar Docker en producción si prefieres servicios gestionados)
5. **Considerar usar Redis** para refresh tokens en lugar de archivos JSON

## Solución de Problemas

### Error de conexión a la base de datos

- Verifica que el contenedor de MySQL esté corriendo: `docker ps`
- Verifica las credenciales en `.env`
- Asegúrate de usar `DB_HOST=mentelivre_db` (nombre del contenedor)

### Error 404 en todas las rutas

- Verifica que `mod_rewrite` esté habilitado (ya está en el Dockerfile)
- Verifica el `.htaccess`
- Revisa los logs: `docker logs mentelivre_backend`

### Cambios no se reflejan

- Recuerda que los volúmenes de Docker sincronizan los cambios automáticamente
- Si no ves cambios, reinicia el contenedor: `docker-compose restart app`

## Licencia

Este proyecto es una plantilla base para desarrollo. Ajusta según tus necesidades.
