# API Mente Livre - Documentación

## 🚀 Estado del Proyecto

✅ Base de datos migrada y optimizada
✅ Servicios base creados (RoleService, TherapistService)
✅ Middleware de roles implementado
✅ Controladores con Swagger/OpenAPI
✅ Rutas configuradas

---

## 📋 URLs Importantes

- **API Base**: `https://backend.mentelivre.org/`
- **Documentación Swagger**: `https://backend.mentelivre.org//docs`
- **Test Endpoint**: `https://backend.mentelivre.org//test`

---

## 🔐 Autenticación

La API utiliza JWT (JSON Web Tokens) para autenticación.

### Login
```bash
POST /auth/login
Content-Type: application/json

{
  "email": "admin@example.com",
  "password": "password123"
}
```

**Respuesta:**
```json
{
  "success": true,
  "data": {
    "token": "eyJ0eXAiOiJKV1QiLCJhbGc...",
    "user": {
      "id": "uuid",
      "email": "admin@example.com"
    }
  }
}
```

### Usar el Token
```bash
GET /therapists
Authorization: Bearer eyJ0eXAiOiJKV1QiLCJhbGc...
```

---

## 📚 Endpoints Disponibles

### Terapeutas

#### GET /therapists
Obtener todos los terapeutas activos (público)

```bash
curl https://backend.mentelivre.org//therapists
```

**Respuesta:**
```json
{
  "success": true,
  "data": [
    {
      "id": "uuid",
      "name": "Dr. Juan Pérez",
      "university": "Universidad de Lima",
      "academic_cycle": "10mo ciclo",
      "hourly_rate": 40.00,
      "experience_topics": ["Ansiedad", "Depresión"],
      "photos": [
        {
          "photo_type": "profile",
          "photo_url": "http://...",
          "photo_position": "50% 20%"
        }
      ],
      "pricing": {
        "university_pe": {
          "price": 25.00,
          "enabled": true
        },
        "public": {
          "price": 40.00,
          "enabled": true
        }
      }
    }
  ]
}
```

#### GET /therapists/{id}
Obtener un terapeuta específico (público)

```bash
curl https://backend.mentelivre.org//therapists/{uuid}
```

#### POST /therapists
Crear un nuevo terapeuta (requiere rol: admin)

```bash
curl -X POST https://backend.mentelivre.org//therapists \
  -H "Authorization: Bearer TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "name": "Dra. María López",
    "university": "PUCP",
    "academic_cycle": "Titulada",
    "hourly_rate": 45.00,
    "experience_topics": ["Ansiedad", "Terapia de pareja"],
    "pricing": {
      "university_pe": {
        "price": 25.00,
        "enabled": true
      },
      "public": {
        "price": 45.00,
        "enabled": true
      }
    }
  }'
```

#### PUT /therapists/{id}
Actualizar un terapeuta (requiere rol: admin)

```bash
curl -X PUT https://backend.mentelivre.org//therapists/{uuid} \
  -H "Authorization: Bearer TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "name": "Dra. María López García",
    "hourly_rate": 50.00
  }'
```

---

## 🔧 Estructura Creada

### Servicios
- `RoleService.php` - Gestión de roles y permisos
- `TherapistService.php` - Lógica de negocio de terapeutas
- `AuthService.php` - Autenticación (ya existente)

### Controladores
- `TherapistController.php` - Endpoints de terapeutas con Swagger

### Middleware
- `RoleMiddleware.php` - Control de acceso basado en roles
- `AuthMiddleware.php` - Autenticación JWT (ya existente)
- `CorsMiddleware.php` - CORS (ya existente)

### Base de Datos
- 18 tablas normalizadas
- Índices optimizados
- Relaciones configuradas

---

## 🧪 Probar la API

### 1. Verificar que el servidor está corriendo
```bash
curl https://backend.mentelivre.org//test
```

### 2. Ver la documentación Swagger
Abre en tu navegador:
```
https://backend.mentelivre.org//docs
```

### 3. Crear un usuario admin (manual en DB)
```sql
-- Conectar a la base de datos
docker exec -it mentelivre_db mysql -u mentelivre_user -pmentelivre_pass mentelivre_db

-- Crear usuario
INSERT INTO users (id, email, password_hash, email_verified, is_active)
VALUES (
  UUID(),
  'admin@mentelivre.com',
  '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', -- password: password
  TRUE,
  TRUE
);

-- Asignar rol admin
INSERT INTO user_roles (id, user_id, role_id)
SELECT UUID(), u.id, r.id
FROM users u, roles r
WHERE u.email = 'admin@mentelivre.com'
  AND r.name = 'admin';
```

### 4. Login con el usuario admin
```bash
curl -X POST https://backend.mentelivre.org//auth/login \
  -H "Content-Type: application/json" \
  -d '{
    "email": "admin@mentelivre.com",
    "password": "password"
  }'
```

### 5. Crear un terapeuta de prueba
```bash
curl -X POST https://backend.mentelivre.org//therapists \
  -H "Authorization: Bearer {TOKEN_AQUÍ}" \
  -H "Content-Type: application/json" \
  -d '{
    "name": "Dr. Test",
    "university": "Universidad de Lima",
    "academic_cycle": "Titulado",
    "hourly_rate": 40.00,
    "experience_topics": ["Ansiedad", "Depresión"],
    "pricing": {
      "university_pe": {
        "price": 25.00,
        "enabled": true
      },
      "public": {
        "price": 40.00,
        "enabled": true
      }
    }
  }'
```

### 6. Listar terapeutas
```bash
curl https://backend.mentelivre.org//therapists
```

---

## 📝 Próximos Pasos

### Endpoints Completados ✅
- [x] Appointments (citas) - CRUD completo
- [x] PromoCodes (códigos promocionales) - CRUD + validación
- [x] Users (gestión de usuarios) - Listar y actualizar perfiles
- [x] Schedules (horarios de terapeutas) - CRUD completo
- [x] Team Profiles (perfiles del equipo) - CRUD completo
- [x] Site Content (contenido institucional) - GET/PUT

### Resumen de Endpoints
- **Total**: 38+ endpoints REST
- **Autenticación**: 3 endpoints (login, refresh, logout)
- **Terapeutas**: 4 endpoints + horarios
- **Citas**: 5 endpoints completos
- **Usuarios**: 3 endpoints
- **Contenido**: 2 endpoints
- **Equipo**: 5 endpoints
- **Códigos Promo**: 5 endpoints (incluye validación)

Ver la documentación completa en Swagger UI: `https://backend.mentelivre.org//docs`

### Mejoras Sugeridas
- [ ] Validación de datos mejorada (usar biblioteca de validación)
- [ ] Rate limiting para prevenir abuse
- [ ] Logs estructurados
- [ ] Tests unitarios
- [ ] Tests de integración
- [ ] Caché para consultas frecuentes

---

## 🐛 Troubleshooting

### Error: "DB_HOST no está configurado"
- Verificar variables de entorno en `docker-compose.yml`
- Reiniciar contenedor: `docker-compose restart app`

### Error: "No such file or directory: vendor/autoload.php"
- Ejecutar: `docker exec mentelivre_backend composer install --working-dir=/var/www/html/backend_mente_livre`

### Error: "Table doesn't exist"
- Verificar que las migraciones se ejecutaron correctamente
- Ejecutar: `docker exec mentelivre_db sh -c "mysql -u mentelivre_user -pmentelivre_pass mentelivre_db < /tmp/schema.sql"`

### No se muestra Swagger UI
- Verificar que el archivo existe: `backend_mente_livre/public/swagger-ui.html`
- Verificar permisos de archivos
- Revisar logs de Apache: `docker logs mentelivre_backend`

---

## 📞 Contacto

Para dudas o problemas:
- Email: mentelivre.pe@gmail.com
- WhatsApp Directora: 977 867 086
