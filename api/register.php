<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

require __DIR__ . '/config.php';

// Banderas temporales: cambia a false cuando migres al panel admin
$ALLOW_PUBLIC_REGISTER = true;       // Permitir registro público simple
$ALLOW_PUBLIC_REGISTER_ADMIN = true; // Permitir crear rol admin desde aquí (solo para pruebas)

session_set_cookie_params([
    'httponly' => true,
    'samesite' => 'Lax',
]);
session_start();

function ensureUsersTable(): void {
    $pdo = pdo();
    $sql = "CREATE TABLE IF NOT EXISTS usuarios (
        id INT AUTO_INCREMENT PRIMARY KEY,
        nombre VARCHAR(200) NOT NULL,
        usuario VARCHAR(60) NULL,
        email VARCHAR(200) NOT NULL UNIQUE,
        password_hash VARCHAR(255) NOT NULL,
        role ENUM('user','admin') NOT NULL DEFAULT 'user',
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uk_usuario (usuario)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
    $pdo->exec($sql);
    // Intentar asegurar columna role si la tabla existía sin ella
    try { $pdo->exec("ALTER TABLE usuarios ADD COLUMN role ENUM('user','admin') NOT NULL DEFAULT 'user'"); } catch (Throwable $e) {}
}

function json_input(): array {
    $raw = file_get_contents('php://input');
    $data = json_decode($raw, true);
    if (!is_array($data)) $data = $_POST;
    return $data ?? [];
}

try {
    ensureUsersTable();

    $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
    if ($method !== 'POST') {
        http_response_code(405);
        echo json_encode(['error' => 'method_not_allowed']);
        exit;
    }

    $data = json_input();

    // Bloqueo configurable: deshabilita este endpoint cuando tengas el panel administrador operativo
    if (!$ALLOW_PUBLIC_REGISTER) {
        http_response_code(403);
        echo json_encode(['error' => 'disabled']);
        exit;
    }

    $usuario  = trim((string)($data['usuario'] ?? ''));
    $password = (string)($data['password'] ?? '');
    $role     = (string)($data['role'] ?? 'user');
    $nombre   = trim((string)($data['nombre'] ?? $usuario));
    $email    = trim((string)($data['email'] ?? ''));

    if ($usuario === '' || $password === '') {
        http_response_code(400);
        echo json_encode(['error' => 'invalid_input', 'detail' => 'usuario y password son requeridos']);
        exit;
    }

    // Validación de rol (solo pruebas permiten admin)
    if (!in_array($role, ['user','admin'], true)) {
        $role = 'user';
    }
    if ($role === 'admin' && !$ALLOW_PUBLIC_REGISTER_ADMIN) {
        $role = 'user';
    }

    // Si no proveen email, usa un placeholder único basado en usuario
    if ($email === '') {
        $email = $usuario . '@local';
    }

    $hash = password_hash($password, PASSWORD_DEFAULT);

    $sql = 'INSERT INTO usuarios (`nombre`, `usuario`, `email`, `password_hash`, `role`) VALUES (:n, :u, :e, :p, :r)';
    $stmt = pdo()->prepare($sql);
    try {
        $params = [
            'n' => ($nombre !== '' ? $nombre : $usuario),
            'u' => ($usuario !== '' ? $usuario : null),
            'e' => $email,
            'p' => $hash,
            'r' => $role,
        ];
        $stmt->execute($params);
        echo json_encode(['ok' => true, 'id' => (int)pdo()->lastInsertId(), 'usuario' => $usuario, 'role' => $role]);
        exit;
    } catch (PDOException $ex) {
        http_response_code(409);
        echo json_encode(['error' => 'duplicate_user', 'detail' => $ex->getMessage()]);
        exit;
    }
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => 'server_error', 'detail' => $e->getMessage()]);
}
