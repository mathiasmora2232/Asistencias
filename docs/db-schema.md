# Esquema de Base de Datos: Asistencias

Este documento define la base de datos MySQL para la aplicación de registro de asistencias, con tablas de `usuarios` y `asistencias`.

## Base de datos

- Nombre: `asistencias_app`
- Motor: MySQL 8+ (InnoDB)
- Charset/Collation: `utf8mb4` / `utf8mb4_unicode_ci`

```sql
-- Crear base de datos
CREATE DATABASE IF NOT EXISTS asistencias_app
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;
USE asistencias_app;
```

## Tabla: usuarios

Contiene los usuarios del sistema.

```sql
CREATE TABLE IF NOT EXISTS usuarios (
  id INT AUTO_INCREMENT PRIMARY KEY,
  nombre VARCHAR(200) NOT NULL,
  usuario VARCHAR(60) NULL,
  email VARCHAR(200) NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  role ENUM('user','admin') NOT NULL DEFAULT 'user',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uk_usuario (usuario)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

### Índices recomendados

- `UNIQUE(email)` ya incluido.
- `UNIQUE(usuario)` ya incluido.

### Datos de ejemplo

Los usuarios base solicitados: Klever, Luis y Raquel. Para seguridad, guarda contraseñas con `password_hash()` de PHP.

```sql
-- Inserta usuarios con placeholders del hash
INSERT INTO usuarios (nombre, usuario, email, password_hash, role)
VALUES
  ('Klever', 'klever', 'klever@example.com', 'REEMPLAZAR_HASH', 'user'),
  ('Luis',   'luis',   'luis@example.com',   'REEMPLAZAR_HASH', 'user'),
  ('Raquel', 'raquel', 'raquel@example.com', 'REEMPLAZAR_HASH', 'user');
```

Genera los hashes en PHP:

```php
<?php
$pass = '123456';
$hash = password_hash($pass, PASSWORD_DEFAULT);
echo $hash, "\n";
```

## Tabla: asistencias

Registra eventos de asistencia por usuario y fecha.

```sql
CREATE TABLE IF NOT EXISTS asistencias (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  usuario_id INT NOT NULL,
  fecha DATE NOT NULL,
  accion ENUM('entrada','salida','almuerzo_inicio','almuerzo_fin') NOT NULL,
  hora TIME NOT NULL,
  observacion VARCHAR(500) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_asistencias_usuario
    FOREIGN KEY (usuario_id)
    REFERENCES usuarios(id)
    ON DELETE CASCADE
    ON UPDATE CASCADE,
  -- Asegura una sola acción por usuario/fecha/hora
  INDEX ix_usuario_fecha (usuario_id, fecha),
  UNIQUE KEY uk_evento_unico (usuario_id, fecha, accion, hora)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

## Vistas y consultas útiles

Obtener la última asistencia por usuario:

```sql
SELECT a.*
FROM asistencias a
JOIN (
  SELECT usuario_id, MAX(CONCAT(fecha, ' ', hora)) AS max_ts
  FROM asistencias
  GROUP BY usuario_id
) t ON t.usuario_id = a.usuario_id AND CONCAT(a.fecha, ' ', a.hora) = t.max_ts;
```

Listado diario:

```sql
SELECT u.nombre, a.accion, a.hora
FROM asistencias a
JOIN usuarios u ON u.id = a.usuario_id
WHERE a.fecha = CURDATE()
ORDER BY u.nombre, a.hora;
```

## API PHP sugerida

Reutiliza tu endpoint `api/auth.php` para sesión. Para registrar asistencias, crea `api/asistencias.php` con operaciones `POST` (crear) y `GET` (listar):

```php
// Ejemplo mínimo de inserción
$stmt = pdo()->prepare('INSERT INTO asistencias (usuario_id, fecha, accion, hora, observacion) VALUES (:uid, :f, :a, :h, :o)');
$stmt->execute([
  ':uid' => $usuarioId,
  ':f'   => date('Y-m-d'),
  ':a'   => $_POST['accion'] ?? 'entrada',
  ':h'   => $_POST['hora'] ?? date('H:i:s'),
  ':o'   => $_POST['observacion'] ?? null,
]);
```

## Notas

- Usa `password_hash()` y `password_verify()` para autenticación segura.
- Mantén `FOREIGN KEY` con `ON DELETE CASCADE` para limpieza automática.
- Ajusta la `UNIQUE` de `asistencias` según reglas de negocio si permiten múltiples eventos de la misma acción.
