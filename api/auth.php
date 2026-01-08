<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

require __DIR__ . '/config.php';

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
}

function json_input(): array {
    $raw = file_get_contents('php://input');
    $data = json_decode($raw, true);
    if (!is_array($data)) $data = $_POST;
    return $data ?? [];
}

function currentUser(): ?array {
    if (!isset($_SESSION['uid'])) return null;
    $stmt = pdo()->prepare('SELECT id, nombre, usuario, email, role, created_at FROM usuarios WHERE id = :id');
    $stmt->execute([':id' => $_SESSION['uid']]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

try {
    ensureUsersTable();

    $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
    $data = json_input();
    $action = $_GET['action'] ?? $_POST['action'] ?? ($data['action'] ?? 'status');

    if ($action === 'status') {
        $u = currentUser();
        echo json_encode(['user' => $u, 'isAdmin' => !!($u && ($u['role'] ?? 'user') === 'admin')]);
        exit;
    }

    if ($action === 'login' && $method === 'POST') {
        $identifier = (string)($data['email'] ?? $data['usuario'] ?? '');
        $password = (string)($data['password'] ?? '');
        if ($identifier === '' || $password === '') {
            http_response_code(400);
            echo json_encode(['error' => 'missing_credentials']);
            exit;
        }
        $stmt = pdo()->prepare('SELECT id, nombre, usuario, email, role, password_hash FROM usuarios WHERE email = :id OR usuario = :id LIMIT 1');
        $stmt->execute([':id' => $identifier]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row || !password_verify($password, (string)$row['password_hash'])) {
            http_response_code(401);
            echo json_encode(['error' => 'invalid_credentials']);
            exit;
        }
        $_SESSION['uid'] = (int)$row['id'];
        echo json_encode(['ok' => true, 'user' => [
            'id' => (int)$row['id'],
            'nombre' => $row['nombre'],
            'usuario' => $row['usuario'],
            'email' => $row['email'],
            'role' => $row['role'],
        ]]);
        exit;
    }

    if ($action === 'logout') {
        // Acepta GET o POST
        $_SESSION = [];
        if (session_id() !== '') {
            session_destroy();
        }
        echo json_encode(['ok' => true]);
        exit;
    }

    http_response_code(400);
    echo json_encode(['error' => 'unsupported_action', 'action' => $action]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => 'server_error', 'detail' => $e->getMessage()]);
}
