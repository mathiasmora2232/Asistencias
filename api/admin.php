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

function ensureJornadasTable(): void {
    $pdo = pdo();
    $sql = "CREATE TABLE IF NOT EXISTS jornadas (
        id INT AUTO_INCREMENT PRIMARY KEY,
        usuario_id INT NOT NULL,
        hora_entrada TIME NOT NULL,
        hora_salida TIME NOT NULL,
        almuerzo_inicio TIME NULL,
        almuerzo_fin TIME NULL,
        tolerancia_min INT NOT NULL DEFAULT 5,
        horas_extra_inicio TIME NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        CONSTRAINT fk_jornadas_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE CASCADE ON UPDATE CASCADE,
        UNIQUE KEY uk_jornada_usuario (usuario_id)
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
    ensureJornadasTable();

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

    // Jornadas (configuración por empleado)
    if ($action === 'jornadas.get' && $method === 'GET') {
        requireAdmin();
        $uid = isset($_GET['usuario_id']) ? (int)$_GET['usuario_id'] : 0;
        if (!$uid) { http_response_code(400); echo json_encode(['error'=>'invalid_id']); exit; }
        $stmt = pdo()->prepare('SELECT usuario_id, hora_entrada, hora_salida, almuerzo_inicio, almuerzo_fin, tolerancia_min, horas_extra_inicio FROM jornadas WHERE usuario_id = :id');
        $stmt->execute([':id' => $uid]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        echo json_encode($row ?: []);
        exit;
    }

    if ($action === 'jornadas.set' && $method === 'POST') {
        requireAdmin();
        $uid = (int)($data['usuario_id'] ?? 0);
        $he = (string)($data['hora_entrada'] ?? '09:00:00');
        $hs = (string)($data['hora_salida'] ?? '18:00:00');
        $ai = isset($data['almuerzo_inicio']) && $data['almuerzo_inicio'] !== '' ? (string)$data['almuerzo_inicio'] : null;
        $af = isset($data['almuerzo_fin']) && $data['almuerzo_fin'] !== '' ? (string)$data['almuerzo_fin'] : null;
        $tol = (int)($data['tolerancia_min'] ?? 5);
        $hex = isset($data['horas_extra_inicio']) && $data['horas_extra_inicio'] !== '' ? (string)$data['horas_extra_inicio'] : null;
        if (!$uid) { http_response_code(400); echo json_encode(['error'=>'invalid_input']); exit; }
        // Upsert
        $sql = 'INSERT INTO jornadas (usuario_id, hora_entrada, hora_salida, almuerzo_inicio, almuerzo_fin, tolerancia_min, horas_extra_inicio)
                VALUES (:id, :he, :hs, :ai, :af, :tol, :hex)
                ON DUPLICATE KEY UPDATE hora_entrada=VALUES(hora_entrada), hora_salida=VALUES(hora_salida), almuerzo_inicio=VALUES(almuerzo_inicio), almuerzo_fin=VALUES(almuerzo_fin), tolerancia_min=VALUES(tolerancia_min), horas_extra_inicio=VALUES(horas_extra_inicio)';
        $stmt = pdo()->prepare($sql);
        $stmt->execute([':id'=>$uid, ':he'=>$he, ':hs'=>$hs, ':ai'=>$ai, ':af'=>$af, ':tol'=>$tol, ':hex'=>$hex]);
        echo json_encode(['ok'=>true]);
        exit;
    }

    // Estadísticas de puntualidad/retraso
    if ($action === 'stats.punctuality' && $method === 'GET') {
        requireAdmin();
        $group = (string)($_GET['group'] ?? 'day'); // day|week|month
        $uid = isset($_GET['usuario_id']) ? (int)$_GET['usuario_id'] : 0;
        $start = (string)($_GET['start_date'] ?? '');
        $end = (string)($_GET['end_date'] ?? '');
        if (!$uid || $start === '' || $end === '') { http_response_code(400); echo json_encode(['error'=>'invalid_input']); exit; }

        // Jornada del usuario (obligatoria para comparar)
        $j = pdo()->prepare('SELECT hora_entrada, hora_salida, tolerancia_min FROM jornadas WHERE usuario_id = :id');
        $j->execute([':id'=>$uid]);
        $jornada = $j->fetch(PDO::FETCH_ASSOC);
        if (!$jornada) { http_response_code(409); echo json_encode(['error'=>'no_schedule']); exit; }

        $stmt = pdo()->prepare("SELECT a.fecha,
                 MIN(CASE WHEN a.accion='entrada' THEN a.hora END) AS primera_entrada,
                 MAX(CASE WHEN a.accion='salida' THEN a.hora END) AS ultima_salida
               FROM asistencias a
               WHERE a.usuario_id = :uid AND a.fecha >= :s AND a.fecha <= :e
               GROUP BY a.fecha
               ORDER BY a.fecha");
        $stmt->execute([':uid'=>$uid, ':s'=>$start, ':e'=>$end]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $tol = (int)($jornada['tolerancia_min'] ?? 5);
        $data = [];
        foreach ($rows as $r) {
            $f = $r['fecha'];
            $e1 = $r['primera_entrada'];
            $s1 = $r['ultima_salida'];
            $entryDiff = null; $exitDiff = null;
            if ($e1) {
                $q = pdo()->prepare("SELECT TIMESTAMPDIFF(MINUTE, CONCAT(:f,' ', :he), CONCAT(:f,' ', :e1)) AS diff");
                $q->execute([':f'=>$f, ':he'=>$jornada['hora_entrada'], ':e1'=>$e1]);
                $entryDiff = (int)$q->fetchColumn();
            }
            if ($s1) {
                $q = pdo()->prepare("SELECT TIMESTAMPDIFF(MINUTE, CONCAT(:f,' ', :s1), CONCAT(:f,' ', :hs)) AS diff");
                // Nota: diff positivo => salida antes de hora_salida? Ajustamos para sentido correcto abajo
                $q->execute([':f'=>$f, ':s1'=>$s1, ':hs'=>$jornada['hora_salida']]);
                $exitDiff = (int)$q->fetchColumn();
                // Queremos: positivo => después (overtime), negativo => temprano
                $exitDiff = -$exitDiff;
            }
            $data[] = [
                'fecha' => $f,
                'entryDiff' => $entryDiff, // minutos (+ tarde, - temprano)
                'exitDiff' => $exitDiff,   // minutos (+ después, - temprano)
                'entryStatus' => ($entryDiff === null ? 'sin_registro' : (abs($entryDiff) <= $tol ? 'puntual' : ($entryDiff > 0 ? 'tarde' : 'temprano'))),
                'exitStatus' => ($exitDiff === null ? 'sin_registro' : ($exitDiff < 0 ? 'temprano' : ($exitDiff > 0 ? 'tarde' : 'puntual'))),
            ];
        }

        // Agrupar
        $grouped = [];
        foreach ($data as $d) {
            $key = $d['fecha'];
            if ($group === 'week') {
                $stmtG = pdo()->prepare("SELECT YEARWEEK(:f, 1)");
                $stmtG->execute([':f'=>$d['fecha']]);
                $key = (string)$stmtG->fetchColumn();
            } elseif ($group === 'month') {
                $stmtG = pdo()->prepare("SELECT DATE_FORMAT(:f, '%Y-%m')");
                $stmtG->execute([':f'=>$d['fecha']]);
                $key = (string)$stmtG->fetchColumn();
            }
            if (!isset($grouped[$key])) {
                $grouped[$key] = [
                    'group' => $key,
                    'on_time' => 0,
                    'late' => 0,
                    'early' => 0,
                    'early_exit' => 0,
                    'overtime' => 0,
                    'avg_entry_diff_min' => 0,
                    'avg_exit_diff_min' => 0,
                    '_c_entry' => 0,
                    '_c_exit' => 0,
                ];
            }
            $g =& $grouped[$key];
            if ($d['entryStatus'] === 'puntual') $g['on_time']++;
            elseif ($d['entryStatus'] === 'tarde') $g['late']++;
            elseif ($d['entryStatus'] === 'temprano') $g['early']++;
            if ($d['exitStatus'] === 'temprano') $g['early_exit']++;
            elseif ($d['exitStatus'] === 'tarde') $g['overtime']++;
            if ($d['entryDiff'] !== null) { $g['avg_entry_diff_min'] += $d['entryDiff']; $g['_c_entry']++; }
            if ($d['exitDiff'] !== null) { $g['avg_exit_diff_min'] += $d['exitDiff']; $g['_c_exit']++; }
        }
        // Finalizar promedios
        $out = array_values(array_map(function($g){
            if ($g['_c_entry'] > 0) $g['avg_entry_diff_min'] = round($g['avg_entry_diff_min'] / $g['_c_entry']);
            else $g['avg_entry_diff_min'] = null;
            if ($g['_c_exit'] > 0) $g['avg_exit_diff_min'] = round($g['avg_exit_diff_min'] / $g['_c_exit']);
            else $g['avg_exit_diff_min'] = null;
            unset($g['_c_entry'], $g['_c_exit']);
            return $g;
        }, $grouped));
        // Ordenar por group desc
        usort($out, function($a,$b){ return strcmp((string)$b['group'], (string)$a['group']); });
        echo json_encode($out);
        exit;
    }

    http_response_code(400);
    echo json_encode(['error' => 'unsupported_action', 'action' => $action]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => 'server_error', 'detail' => $e->getMessage()]);
}
