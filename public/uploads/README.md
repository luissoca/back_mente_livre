# Almacenamiento de Imágenes

Esta carpeta contiene todas las imágenes subidas al sistema.

## Estructura

```
uploads/
├── therapists/
│   └── photos/          # Fotos de perfil de terapeutas
├── team/
│   └── photos/          # Fotos del equipo institucional
└── users/
    └── photos/          # Fotos de perfil de usuarios
```

## Acceso desde el Frontend

Las imágenes son accesibles mediante URLs completas construidas automáticamente por el backend:

- **Fotos de terapeutas**: `https://backend.mentelivre.org//uploads/therapists/photos/[nombre-archivo]`
- **Fotos del equipo**: `https://backend.mentelivre.org//uploads/team/photos/[nombre-archivo]`
- **Fotos de usuarios**: `https://backend.mentelivre.org//uploads/users/photos/[nombre-archivo]`

## Formato de URLs en la Base de Datos

**IMPORTANTE**: Las URLs en la base de datos se almacenan como **rutas relativas simples** (solo carpeta y nombre de archivo). El backend construye automáticamente las URLs completas cuando se sirven los datos a través de la API.

### Formato recomendado (rutas relativas):

**`therapist_photos.photo_url`**:
- `therapists/photos/[nombre-archivo]`
- Ejemplo: `therapists/photos/maria-belen.png`
- Ejemplo: `therapists/photos/6fdc471e-04e9-4f87-90a6-ebbbe6131522-1767657710227.png`

**`team_profiles.friendly_photo_url`**:
- `team/photos/[nombre-archivo]` (para fotos del equipo)
- `therapists/photos/[nombre-archivo]` (para fotos friendly de terapeutas)
- Ejemplo: `team/photos/team-1767338388299.png`
- Ejemplo: `therapists/photos/friendly-d817b655-477a-4dea-9e8f-bb944160c54f-1767229701597.jpeg`

**`profiles.profile_photo_url`**:
- `users/photos/[nombre-archivo]`
- Ejemplo: `users/photos/user-123.jpg`

### Conversión Automática

El backend usa `ImageUrlHelper` para convertir automáticamente estas rutas relativas en URLs completas cuando se consultan los datos. No es necesario incluir:
- El prefijo `/uploads/` (el backend lo agrega automáticamente)
- La URL base (ej: `https://backend.mentelivre.org/`)
- Rutas absolutas completas (aunque estas también funcionan)

## Convenciones de Nomenclatura

- Usar nombres descriptivos o UUIDs con timestamps
- Tipos de foto: `profile` (perfil), `friendly` (amigable)
- Ejemplos:
  - `maria-belen.png` (nombre descriptivo)
  - `6fdc471e-04e9-4f87-90a6-ebbbe6131522-1767657710227.png` (UUID + timestamp)
  - `friendly-d817b655-477a-4dea-9e8f-bb944160c54f-1767229701597.jpeg` (friendly photo)

## Migración desde Supabase

Las URLs de Supabase se han normalizado automáticamente usando el script `002_normalize_image_urls.sql`. Las URLs ahora almacenan solo el nombre del archivo relativo a la carpeta correspondiente.
