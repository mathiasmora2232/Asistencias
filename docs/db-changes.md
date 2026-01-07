# Cambios recomendados en la base de datos

Este documento propone ajustes para la tabla `asistencias` y su relación con `usuarios`.

## Estructura actual observada

- Tabla: `asistencias`
  - `id` BIGINT UNSIGNED AUTO_INCREMENT
  - `usuario_id` INT(11) NOT NULL
  - `fecha` DATE NOT NULL
  - `accion` ENUM('entrada','salida','almuerzo_inicio','almuerzo_fin') NOT NULL
  - `hora` TIME NOT NULL
  - `observacion` VARCHAR(500) NULL
  - `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP

## Ajustes recomendados

1. Agregar `FOREIGN KEY` hacia `usuarios` para integridad referencial:

```sql
ALTER TABLE asistencias
  ADD CONSTRAINT fk_asistencias_usuario
  FOREIGN KEY (usuario_id)
  REFERENCES usuarios(id)
  ON DELETE CASCADE
  ON UPDATE CASCADE;
```

2. Evitar duplicados del mismo evento (mismo usuario, fecha, acción y hora):

```sql
ALTER TABLE asistencias
  ADD UNIQUE KEY uk_evento_unico (usuario_id, fecha, accion, hora);
```

3. Índice para acelerar listados por usuario y fecha:

```sql
CREATE INDEX ix_usuario_fecha ON asistencias (usuario_id, fecha);
```

> Nota: Si ya existen filas que conflictúan con la `UNIQUE`, primero limpia datos duplicados o usa un índice único parcial si aplicara a tu motor (MariaDB no soporta parcial clásico; podrías relajar la regla removiendo `hora` si tu negocio lo permite).

## Compatibilidad con la API

- La API `api/asistencias.php` ya contempla el código `409 duplicate_event` cuando la `UNIQUE` detecta un duplicado.
- La hora se normaliza a formato `HH:MM:SS` para cumplir con el tipo `TIME`.
- Se buscan usuarios por `nombre` o `usuario` para obtener `usuario_id`.

## Semilla opcional de usuarios

```sql
INSERT INTO usuarios (nombre, usuario, email, password_hash, role)
VALUES
  ('Klever', 'klever', 'klever@example.com', 'REEMPLAZAR_HASH', 'user'),
  ('Luis',   'luis',   'luis@example.com',   'REEMPLAZAR_HASH', 'user'),
  ('Raquel', 'raquel', 'raquel@example.com', 'REEMPLAZAR_HASH', 'user');
```

Genera los hashes con `password_hash()` en PHP.

---

## Solución al error #1005 (errno: 121 "Duplicate key on write or update")

Este error al agregar el `FOREIGN KEY` suele deberse a uno de estos motivos:

- Ya existe una restricción con el mismo nombre en el esquema (los nombres de FK son únicos por base).
- Hay filas en `asistencias` cuyo `usuario_id` no existe en `usuarios`.
- Falta/choque de índice en `usuario_id`.

Sigue estos pasos en el SQL de phpMyAdmin (ejecútalos en tu base):

1) Ver FKs existentes y revisar si el nombre ya existe:

```sql
SELECT CONSTRAINT_NAME, TABLE_NAME
FROM information_schema.REFERENTIAL_CONSTRAINTS
WHERE CONSTRAINT_SCHEMA = DATABASE();
```

2) Si ya existe `fk_asistencias_usuario`, elimínalo o usa un nombre diferente:

```sql
-- Eliminar si existiera
ALTER TABLE asistencias DROP FOREIGN KEY fk_asistencias_usuario;
```

3) Asegurar que no hay filas huérfanas (sin usuario asociado):

```sql
SELECT a.id, a.usuario_id
FROM asistencias a
LEFT JOIN usuarios u ON u.id = a.usuario_id
WHERE u.id IS NULL
LIMIT 100;
```

Si devuelve filas, corrige `usuario_id` o elimina esas filas antes de crear el FK.

4) Crear/asegurar índice en `usuario_id` y luego el FK con un nombre único nuevo:

```sql
ALTER TABLE asistencias
  ADD INDEX idx_asistencias_usuario_id (usuario_id);

ALTER TABLE asistencias
  ADD CONSTRAINT fk_asistencias_usuario_id
  FOREIGN KEY (usuario_id)
  REFERENCES usuarios(id)
  ON DELETE CASCADE
  ON UPDATE CASCADE;
```

5) (Opcional) Agregar la `UNIQUE` y el índice sugeridos si aún no existen:

```sql
ALTER TABLE asistencias
  ADD UNIQUE INDEX uk_evento_unico (usuario_id, fecha, accion, hora);

CREATE INDEX ix_usuario_fecha ON asistencias (usuario_id, fecha);
```

Con esto, el FK debería crear sin errores y la API funcionará con integridad referencial.
