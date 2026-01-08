# API de Asistencias

Este documento describe los endpoints disponibles.

## Autenticación

- `GET api/auth.php?action=status`
  - Respuesta: `{ user: {id,nombre,usuario,email,role}, isAdmin: boolean }`
- `POST api/auth.php?action=login`
  - Body: `{ email: string | usuario: string, password: string }`
  - Respuesta: `{ ok: true, user: {...} }`
- `GET|POST api/auth.php?action=logout`
  - Respuesta: `{ ok: true }`

## Administración

Todos requieren sesión con rol `admin`.

- `GET api/admin.php?action=users.list&q=texto`
  - Lista usuarios (filtro opcional por nombre/usuario/email).
- `POST api/admin.php?action=users.create`
  - Body: `{ nombre, usuario?, email, password, role }`
- `POST api/admin.php?action=users.setRole`
  - Body: `{ id, role }`
- `POST api/admin.php?action=users.delete`
  - Body: `{ id }`
- `POST api/admin.php?action=users.resetPassword`
  - Body: `{ id, password }`

### Bootstrap (inicialización)

- `POST api/admin.php?action=bootstrap.admin`
  - Crea o actualiza el usuario `admin` con contraseña `admin`.
  - Comportamiento:
    - Si existe cualquier usuario con `role=admin`, responde `409 { error: "already_initialized" }`.
    - Si existe usuario `admin` (o email `admin@localhost`), se actualiza su rol a `admin` y se resetea la contraseña.
    - Si no existe, se crea: nombre `Admin`, usuario `admin`, email `admin@localhost`, rol `admin`.

### Asistencias

- `GET api/admin.php?action=asistencias.list&usuario_id&start_date&end_date`
  - Lista registros con filtros.
- `POST api/admin.php?action=asistencias.add`
  - Body: `{ usuario_id, fecha?, accion, hora?, observacion? }`
- `POST api/admin.php?action=asistencias.delete`
  - Body: `{ id }`
- `GET api/admin.php?action=asistencias.aggregate&group=day|week|month&type&usuario_id&start_date&end_date`
  - Devuelve agregados por grupo y tipo: `[{ day|week|month, accion, count }]`.

## Público

- `GET api/users.php?action=list`
  - Lista básica de usuarios (`id, nombre, usuario`). Usada por el formulario simple.
- `POST/GET api/asistencias.php?action=add|list`
  - Operaciones básicas de registro/listado sin sesión (para flujo mínimo).

### Registro simple (temporal para pruebas)

- `POST api/register.php`
  - Body JSON o form: `{ usuario: string, password: string, role?: 'user'|'admin', nombre?: string, email?: string }`
  - Respuesta: `{ ok: true, id, usuario, role }` o `409 duplicate_user` si ya existe `usuario`/`email`.
  - Notas:
    - Usa `password_hash()` por seguridad.
    - Puedes desactivar este endpoint editando las banderas en [api/register.php](api/register.php) (`$ALLOW_PUBLIC_REGISTER` y `$ALLOW_PUBLIC_REGISTER_ADMIN`).

## Notas de seguridad

- Las operaciones de administración requieren sesión y rol admin.
- Las contraseñas se guardan con `password_hash()` y se validan con `password_verify()`.
- Las cookies de sesión son `HttpOnly` y `SameSite=Lax`.
