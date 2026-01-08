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
    // Asegurar columna role si la tabla ya existía sin ella
    try { $pdo->exec("ALTER TABLE usuarios ADD COLUMN role ENUM('user','admin') NOT NULL DEFAULT 'user'"); } catch (Throwable $e) {}
}

function ensureAsistenciasTable(): void {
    $pdo = pdo();
    $sql = "CREATE TABLE IF NOT EXISTS asistencias (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        usuario_id INT NOT NULL,
        fecha DATE NOT NULL,
        accion ENUM('entrada','salida','almuerzo_inicio','almuerzo_fin') NOT NULL,
        hora TIME NOT NULL,
        observacion VARCHAR(500) NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        CONSTRAINT fk_asistencias_usuario
          FOREIGN KEY (usuario_id) REFERENCES usuarios(id)
          ON DELETE CASCADE ON UPDATE CASCADE,
        INDEX ix_usuario_fecha (usuario_id, fecha),
        UNIQUE KEY uk_evento_unico (usuario_id, fecha, accion, hora)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
    $pdo->exec($sql);
}

function currentUser(): ?array {
    if (!isset($_SESSION['uid'])) return null;
    $stmt = pdo()->prepare('SELECT id, nombre, usuario, email, role, created_at FROM usuarios WHERE id = :id');
    $stmt->execute([':id' => $_SESSION['uid']]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function requireAdmin(): void {
    $u = currentUser();
    if (!$u || ($u['role'] ?? 'user') !== 'admin') {
        http_response_code(403);
        echo json_encode(['error' => 'forbidden', 'detail' => 'Requiere rol admin']);
        exit;
    }
}

function json_input(): array {
    $raw = file_get_contents('php://input');
    $data = json_decode($raw, true);
    if (!is_array($data)) $data = $_POST;
    return $data;
}

try {
    ensureUsersTable();
    ensureAsistenciasTable();

    $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
    $data = json_input();
    $action = $_GET['action'] ?? $_POST['action'] ?? ($data['action'] ?? 'status');

    if ($action === 'status') {
        $u = currentUser();
        echo json_encode(['user' => $u, 'isAdmin' => !!($u && ($u['role'] ?? 'user') === 'admin')]);
        exit;
    }

    // Inicialización: crear/asegurar usuario admin con password "admin"
    // No requiere sesión, pero sólo funciona si aún no hay admins o si el usuario "admin" ya existe.
    if ($action === 'bootstrap.admin' && $method === 'POST') {
        // ¿Ya hay algún admin en el sistema?
        $hasAdmin = (int)pdo()->query("SELECT COUNT(*) FROM usuarios WHERE role = 'admin'")->fetchColumn();

        // Intentar localizar usuario por alias típico
        $stmtFind = pdo()->prepare('SELECT id, usuario, email, role FROM usuarios WHERE usuario = :u OR email = :e LIMIT 1');
        $stmtFind->execute([':u' => 'admin', ':e' => 'admin@localhost']);
        $existing = $stmtFind->fetch(PDO::FETCH_ASSOC);

        $hash = password_hash('admin', PASSWORD_DEFAULT);

        if ($existing) {
            // Actualizar a rol admin y resetear contraseña
            $stmt = pdo()->prepare('UPDATE usuarios SET role = "admin", password_hash = :p WHERE id = :id');
            $stmt->execute([':p' => $hash, ':id' => (int)$existing['id']]);
            echo json_encode(['ok' => true, 'id' => (int)$existing['id'], 'updated' => true]);
            exit;
        }

        if ($hasAdmin > 0) {
            http_response_code(409);
            echo json_encode(['error' => 'already_initialized']);
            exit;
        }

        // Crear usuario admin base
        $stmtIns = pdo()->prepare('INSERT INTO usuarios (nombre, usuario, email, password_hash, role) VALUES (:n, :u, :e, :p, :r)');
        $stmtIns->execute([
            ':n' => 'Admin',
            ':u' => 'admin',
            ':e' => 'admin@localhost',
            ':p' => $hash,
            ':r' => 'admin',
        ]);
        echo json_encode(['ok' => true, 'id' => (int)pdo()->lastInsertId(), 'created' => true]);
        exit;
    }

    // Usuarios
    if ($action === 'users.list' && $method === 'GET') {
        requireAdmin();
        $q = trim((string)($_GET['q'] ?? ''));
        if ($q !== '') {
            $like = '%' . $q . '%';
            $stmt = pdo()->prepare('SELECT id, nombre, usuario, email, role, created_at FROM usuarios WHERE nombre LIKE :q OR usuario LIKE :q OR email LIKE :q ORDER BY nombre');
            $stmt->execute([':q' => $like]);
        } else {
            $stmt = pdo()->query('SELECT id, nombre, usuario, email, role, created_at FROM usuarios ORDER BY nombre');
        }
        echo json_encode($stmt->fetchAll());
        exit;
    }

    if ($action === 'users.create' && $method === 'POST') {
        requireAdmin();
        $nombre = trim((string)($data['nombre'] ?? ''));
        $usuario = trim((string)($data['usuario'] ?? ''));
        $email = trim((string)($data['email'] ?? ''));
        $password = (string)($data['password'] ?? '');
        $role = (string)($data['role'] ?? 'user');
        if ($nombre === '' || $email === '' || $password === '' || !in_array($role, ['user','admin'], true)) {
            http_response_code(400);
            echo json_encode(['error' => 'invalid_input']);
            exit;
        }
        $hash = password_hash($password, PASSWORD_DEFAULT);
        $stmt = pdo()->prepare('INSERT INTO usuarios (nombre, usuario, email, password_hash, role) VALUES (:n, :u, :e, :p, :r)');
        try {
            $stmt->execute([':n'=>$nombre, ':u'=>($usuario !== '' ? $usuario : null), ':e'=>$email, ':p'=>$hash, ':r'=>$role]);
            echo json_encode(['ok' => true, 'id' => (int)pdo()->lastInsertId()]);
        } catch (PDOException $ex) {
            http_response_code(409);
            echo json_encode(['error' => 'duplicate_user', 'detail' => $ex->getMessage()]);
        }
        exit;
    }

    if ($action === 'users.setRole' && $method === 'POST') {
        requireAdmin();
        $id = (int)($data['id'] ?? 0);
        $role = (string)($data['role'] ?? 'user');
        if (!$id || !in_array($role, ['user','admin'], true)) {
            http_response_code(400);
            echo json_encode(['error' => 'invalid_input']);
            exit;
        }
        $stmt = pdo()->prepare('UPDATE usuarios SET role = :r WHERE id = :id');
        $stmt->execute([':r' => $role, ':id' => $id]);
        echo json_encode(['ok' => true]);
        exit;
    }

    if ($action === 'users.delete' && $method === 'POST') {
        requireAdmin();
        $id = (int)($data['id'] ?? 0);
        if (!$id) { http_response_code(400); echo json_encode(['error'=>'invalid_id']); exit; }
        $stmt = pdo()->prepare('DELETE FROM usuarios WHERE id = :id');
        $stmt->execute([':id' => $id]);
        echo json_encode(['ok' => true]);
        exit;
    }

    if ($action === 'users.resetPassword' && $method === 'POST') {
        requireAdmin();
        $id = (int)($data['id'] ?? 0);
        $new = (string)($data['password'] ?? '');
        if (!$id || $new === '') { http_response_code(400); echo json_encode(['error'=>'invalid_input']); exit; }
        $hash = password_hash($new, PASSWORD_DEFAULT);
        $stmt = pdo()->prepare('UPDATE usuarios SET password_hash = :p WHERE id = :id');
        $stmt->execute([':p' => $hash, ':id' => $id]);
        echo json_encode(['ok' => true]);
        exit;
    }

    // Asistencias
    if ($action === 'asistencias.list' && $method === 'GET') {
        requireAdmin();
        $uid = isset($_GET['usuario_id']) ? (int)$_GET['usuario_id'] : 0;
        $start = (string)($_GET['start_date'] ?? '');
        $end = (string)($_GET['end_date'] ?? '');

        $sql = 'SELECT a.id, a.usuario_id, u.nombre, u.usuario, a.fecha, a.accion, a.hora, a.observacion, a.created_at
                FROM asistencias a JOIN usuarios u ON u.id = a.usuario_id WHERE 1=1';
        $params = [];
        if ($uid) { $sql .= ' AND a.usuario_id = :uid'; $params[':uid'] = $uid; }
        if ($start !== '') { $sql .= ' AND a.fecha >= :s'; $params[':s'] = $start; }
        if ($end !== '') { $sql .= ' AND a.fecha <= :e'; $params[':e'] = $end; }
        $sql .= ' ORDER BY a.fecha DESC, a.hora DESC';
        $stmt = pdo()->prepare($sql);
        $stmt->execute($params);
        echo json_encode($stmt->fetchAll());
        exit;
    }

    if ($action === 'asistencias.aggregate' && $method === 'GET') {
        requireAdmin();
        $group = (string)($_GET['group'] ?? 'day'); // day|week|month
        $type = (string)($_GET['type'] ?? ''); // accion filter opcional
        $uid = isset($_GET['usuario_id']) ? (int)$_GET['usuario_id'] : 0;
        $start = (string)($_GET['start_date'] ?? '');
        $end = (string)($_GET['end_date'] ?? '');

        // Definir expresión de agrupación
        if ($group === 'week') {
            $grpExpr = 'YEARWEEK(a.fecha, 1)';
            $grpAlias = 'week';
        } elseif ($group === 'month') {
            $grpExpr = "DATE_FORMAT(a.fecha, '%Y-%m')";
            $grpAlias = 'month';
        } else {
            $grpExpr = 'a.fecha';
            $grpAlias = 'day';
        }

        $sql = "SELECT $grpExpr AS grp, a.accion, COUNT(*) AS cnt
                FROM asistencias a WHERE 1=1";
        $params = [];
        if ($uid) { $sql .= ' AND a.usuario_id = :uid'; $params[':uid'] = $uid; }
        if ($type !== '') { $sql .= ' AND a.accion = :t'; $params[':t'] = $type; }
        if ($start !== '') { $sql .= ' AND a.fecha >= :s'; $params[':s'] = $start; }
        if ($end !== '') { $sql .= ' AND a.fecha <= :e'; $params[':e'] = $end; }
        $sql .= ' GROUP BY grp, a.accion ORDER BY grp DESC, a.accion';
        $stmt = pdo()->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll();
        // Normalizar alias
        $out = array_map(function($r) use ($grpAlias) {
            return [ $grpAlias => $r['grp'], 'accion' => $r['accion'], 'count' => (int)$r['cnt'] ];
        }, $rows);
        echo json_encode($out);
        exit;
    }

    if ($action === 'asistencias.add' && $method === 'POST') {
        requireAdmin();
        $usuario_id = (int)($data['usuario_id'] ?? 0);
        $fecha = (string)($data['fecha'] ?? date('Y-m-d'));
        $accion = (string)($data['accion'] ?? 'entrada');
        $hora = (string)($data['hora'] ?? date('H:i:s'));
        $obs = isset($data['observacion']) ? (string)$data['observacion'] : null;
        if (!$usuario_id || !in_array($accion, ['entrada','salida','almuerzo_inicio','almuerzo_fin'], true)) {
            http_response_code(400);
            echo json_encode(['error'=>'invalid_input']);
            exit;
        }
        $stmt = pdo()->prepare('INSERT INTO asistencias (usuario_id, fecha, accion, hora, observacion) VALUES (:uid, :f, :a, :h, :o)');
        try {
            $stmt->execute([':uid'=>$usuario_id, ':f'=>$fecha, ':a'=>$accion, ':h'=>$hora, ':o'=>$obs]);
            echo json_encode(['ok'=>true, 'id'=>pdo()->lastInsertId()]);
        } catch (PDOException $ex) {
            if ((int)$ex->getCode() === 23000) {
                http_response_code(409);
                echo json_encode(['error'=>'duplicate_event']);
            } else {
                throw $ex;
            }
        }
        exit;
    }

    if ($action === 'asistencias.delete' && $method === 'POST') {
        requireAdmin();
        $id = (int)($data['id'] ?? 0);
        if (!$id) { http_response_code(400); echo json_encode(['error'=>'invalid_id']); exit; }
        $stmt = pdo()->prepare('DELETE FROM asistencias WHERE id = :id');
        $stmt->execute([':id' => $id]);
        echo json_encode(['ok' => true]);
        exit;
    }

    http_response_code(400);
    echo json_encode(['error' => 'unsupported_action', 'action' => $action]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => 'server_error', 'detail' => $e->getMessage()]);
}
