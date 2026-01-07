<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

require __DIR__ . '/config.php';

function ensureTables(): void {
    $pdo = pdo();
    $pdo->exec("CREATE TABLE IF NOT EXISTS usuarios (
        id INT AUTO_INCREMENT PRIMARY KEY,
        nombre VARCHAR(200) NOT NULL,
        usuario VARCHAR(60) NULL,
        email VARCHAR(200) NULL,
        password_hash VARCHAR(255) NULL,
        role ENUM('user','admin') NOT NULL DEFAULT 'user',
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uk_usuario (usuario)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS asistencias (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        usuario_id INT NOT NULL,
        fecha DATE NOT NULL,
        accion ENUM('entrada','salida','almuerzo_inicio','almuerzo_fin') NOT NULL,
        hora TIME NOT NULL,
        observacion VARCHAR(500) NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        CONSTRAINT fk_asistencias_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE CASCADE ON UPDATE CASCADE,
        INDEX ix_usuario_fecha (usuario_id, fecha),
        UNIQUE KEY uk_evento_unico (usuario_id, fecha, accion, hora)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

function json_input(): array {
    $raw = file_get_contents('php://input');
    $data = json_decode($raw, true);
    if (!is_array($data)) $data = $_POST;
    return $data;
}

function findUsuarioIdByNombre(string $nombre): ?int {
    $stmt = pdo()->prepare('SELECT id FROM usuarios WHERE nombre = ? OR usuario = ? LIMIT 1');
    $stmt->execute([$nombre, $nombre]);
    $id = $stmt->fetchColumn();
    return $id ? (int)$id : null;
}

try {
    ensureTables();

    $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
    $action = $_GET['action'] ?? $_POST['action'] ?? 'list';
    $data = json_input();

    if ($action === 'list' && $method === 'GET') {
        $uid = isset($_GET['usuario_id']) ? (int)$_GET['usuario_id'] : 0;
        $date = isset($_GET['date']) ? (string)$_GET['date'] : '';
        $sql = 'SELECT a.id, a.usuario_id, u.nombre, u.usuario, a.fecha, a.accion, a.hora, a.observacion, a.created_at FROM asistencias a JOIN usuarios u ON u.id = a.usuario_id WHERE 1=1';
        $where = [];
        $params = [];
        if ($uid) { $where[] = 'a.usuario_id = ?'; $params[] = $uid; }
        if ($date !== '') { $where[] = 'a.fecha = ?'; $params[] = $date; }
        if ($where) { $sql .= ' AND ' . implode(' AND ', $where); }
        $sql .= ' ORDER BY a.fecha DESC, a.hora DESC';
        $stmt = pdo()->prepare($sql);
        $stmt->execute($params);
        echo json_encode($stmt->fetchAll());
        exit;
    }

    if ($action === 'add' && $method === 'POST') {
        $nombre = trim((string)($data['nombre'] ?? ''));
        $usuario_id = (int)($data['usuario_id'] ?? 0);
        $accion = (string)($data['accion'] ?? 'entrada');
        $hora = (string)($data['hora'] ?? date('H:i'));
        $fecha = (string)($data['fecha'] ?? date('Y-m-d'));
        $obs = isset($data['observacion']) ? (string)$data['observacion'] : null;

        if (!$usuario_id && $nombre !== '') {
            $usuario_id = findUsuarioIdByNombre($nombre) ?? 0;
        }
        if (!$usuario_id) {
            http_response_code(404);
            echo json_encode(['error' => 'invalid_user', 'detail' => 'Usuario no encontrado']);
            exit;
        }
        if (!in_array($accion, ['entrada','salida','almuerzo_inicio','almuerzo_fin'], true)) {
            http_response_code(400);
            echo json_encode(['error' => 'invalid_action']);
            exit;
        }

        // Normalizar hora a HH:MM:SS
        if (preg_match('/^\d{2}:\d{2}$/', $hora)) { $hora .= ':00'; }
        $stmt = pdo()->prepare('INSERT INTO asistencias (usuario_id, fecha, accion, hora, observacion) VALUES (?, ?, ?, ?, ?)');
        try {
            $stmt->execute([$usuario_id, $fecha, $accion, $hora, $obs]);
            echo json_encode(['ok'=>true, 'id'=>pdo()->lastInsertId()]);
        } catch (PDOException $ex) {
            // Código 23000 para violación de UNIQUE
            if ((int)$ex->getCode() === 23000) {
                http_response_code(409);
                echo json_encode(['error'=>'duplicate_event']);
            } else {
                throw $ex;
            }
        }
        exit;
    }

    http_response_code(400);
    echo json_encode(['error'=>'unsupported_action','action'=>$action]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error'=>'server_error','detail'=>$e->getMessage()]);
}
