<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: strict-origin-when-cross-origin');

require __DIR__ . '/config.php';

session_set_cookie_params([
    'httponly' => true,
    'samesite' => 'Lax',
    'secure'   => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (isset($_SERVER['SERVER_PORT']) && $_SERVER['SERVER_PORT'] == 443),
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
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uk_usuario (usuario)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
    $pdo->exec($sql);
    // Asegurar columna role si la tabla ya existía sin ella
    try { $pdo->exec("ALTER TABLE usuarios ADD COLUMN role ENUM('user','admin') NOT NULL DEFAULT 'user'"); } catch (Throwable $e) { /* ya existe */ }
}

function currentUser() {
    if (!isset($_SESSION['uid'])) return null;
    $stmt = pdo()->prepare('SELECT id, nombre, usuario, email, role, created_at FROM usuarios WHERE id = :id');
    $stmt->execute([':id' => $_SESSION['uid']]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

try {
    ensureUsersTable();

    $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
    // Leer payload JSON/POST UNA sola vez y usarlo para resolver la acción
    $raw = file_get_contents('php://input');
    $data = json_decode($raw, true);
    if (!is_array($data)) { $data = $_POST; }
    $action = $_GET['action'] ?? $_POST['action'] ?? ($data['action'] ?? 'status');

    if ($action === 'status') {
        $u = currentUser();
        echo json_encode(['user' => $u, 'isAdmin' => !!($u && ($u['role'] ?? 'user') === 'admin')]);
        exit;
    }

    if ($action === 'logout' && $method === 'POST') {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
        }
        session_destroy();
        echo json_encode(['ok' => true]);
        exit;
    }

    // $data ya contiene el cuerpo/POST si corresponde

    if ($action === 'register' && $method === 'POST') {
        $nombre = trim((string)($data['nombre'] ?? ''));
        $usuario= trim((string)($data['usuario'] ?? ''));
        $email  = trim((string)($data['email'] ?? ''));
        $pass   = (string)($data['password'] ?? '');
        if ($nombre === '' || $usuario === '' || $email === '' || $pass === '') {
            http_response_code(400);
            echo json_encode(['error' => 'Campos incompletos']);
            exit;
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            http_response_code(400);
            echo json_encode(['error' => 'Email inválido']);
            exit;
        }
        // Validación básica de usuario
        if (!preg_match('/^[a-zA-Z0-9_\.\-]{3,30}$/', $usuario)) {
            http_response_code(400);
            echo json_encode(['error' => 'Usuario inválido (3-30, letras/números/_.-)']);
            exit;
        }
        // Comprobar duplicados para mensajes amigables
        $dupEmail = pdo()->prepare('SELECT 1 FROM usuarios WHERE email = :e LIMIT 1');
        $dupEmail->execute([':e'=>$email]);
        if ($dupEmail->fetchColumn()) {
            http_response_code(409);
            echo json_encode(['error'=>'Email ya registrado']);
            exit;
        }
        $dupUser = pdo()->prepare('SELECT 1 FROM usuarios WHERE usuario = :u LIMIT 1');
        $dupUser->execute([':u'=>$usuario]);
        if ($dupUser->fetchColumn()) {
            http_response_code(409);
            echo json_encode(['error'=>'Usuario ya en uso']);
            exit;
        }

        try {
            $hash = password_hash($pass, PASSWORD_DEFAULT);
            $stmt = pdo()->prepare('INSERT INTO usuarios (nombre, usuario, email, password_hash, role) VALUES (:n,:u,:e,:p,\'user\')');
            $stmt->execute([':n'=>$nombre, ':u'=>$usuario, ':e'=>$email, ':p'=>$hash]);
            echo json_encode(['ok'=>true]);
        } catch (PDOException $ex) {
            // Un último guardado para errores de integridad
            if ((int)$ex->getCode() === 23000) {
                http_response_code(409);
                echo json_encode(['error'=>'Datos duplicados','detail'=>'Verifica email y usuario']);
            } else {
                throw $ex;
            }
        }
        exit;
    }

    if ($action === 'login' && $method === 'POST') {
        // Aceptar tanto 'email' como 'usuario' en el payload
        $login = trim((string)($data['email'] ?? ($data['usuario'] ?? '')));
        $pass  = (string)($data['password'] ?? '');
        // Permitir login por email o usuario
        if (strpos($login, '@') !== false) {
            $stmt = pdo()->prepare('SELECT id, password_hash, nombre, usuario, email, role, created_at FROM usuarios WHERE email = :v');
        } else {
            $stmt = pdo()->prepare('SELECT id, password_hash, nombre, usuario, email, role, created_at FROM usuarios WHERE usuario = :v');
        }
        $stmt->execute([':v' => $login]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            http_response_code(404);
            echo json_encode(['error' => 'Usuario/Email no encontrado']);
            exit;
        }
        if (!password_verify($pass, $row['password_hash'])) {
            http_response_code(401);
            echo json_encode(['error' => 'Contraseña incorrecta']);
            exit;
        }
        // Protección contra fijación de sesión
        session_regenerate_id(true);
        $_SESSION['uid'] = (int)$row['id'];
        echo json_encode(['ok'=>true, 'user' => ['id'=>$row['id'], 'nombre'=>$row['nombre'], 'usuario'=>$row['usuario'], 'email'=>$row['email'], 'role'=>$row['role'], 'created_at'=>$row['created_at']]]);
        exit;
    }

    http_response_code(400);
    echo json_encode(['error' => 'Acción no soportada']);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Error del servidor', 'detail' => $e->getMessage()]);
}
