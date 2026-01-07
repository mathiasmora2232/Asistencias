<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

require __DIR__ . '/config.php';

session_set_cookie_params(['httponly'=>true,'samesite'=>'Lax']);
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

try {
    ensureUsersTable();
    $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
    $action = $_GET['action'] ?? $_POST['action'] ?? 'list';

    if ($action === 'list' && $method === 'GET') {
        $stmt = pdo()->query('SELECT id, nombre, usuario FROM usuarios ORDER BY nombre');
        echo json_encode($stmt->fetchAll());
        exit;
    }

    http_response_code(400);
    echo json_encode(['error'=>'unsupported_action']);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error'=>'server_error','detail'=>$e->getMessage()]);
}
