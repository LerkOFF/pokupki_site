<?php
declare(strict_types=1);
require __DIR__ . '/shopping-lib.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
try {
    $db = pokupki_db();
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        if (!pokupki_require_auth()) { http_response_code(401); echo '{"error":"auth"}'; exit; }
        $items = [];
        foreach ($db->query('SELECT id, data FROM items ORDER BY id') as $row) {
            $items[] = ['id' => $row['id']] + json_decode($row['data'], true, 512, JSON_THROW_ON_ERROR);
        }
        echo json_encode($items, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        exit;
    }
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit; }
    if (!pokupki_same_origin()) { http_response_code(403); exit; }
    if ((int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 8192) { http_response_code(413); exit; }
    $input = json_decode(file_get_contents('php://input'), true, 32, JSON_THROW_ON_ERROR);
    $action = $input['action'] ?? '';

    if ($action === 'login') {
        $cfg = pokupki_auth_config();
        if ($cfg === null) { http_response_code(503); echo '{"error":"Пароль на сервере не настроен"}'; exit; }
        $password = $input['password'] ?? '';
        if (!is_string($password) || strlen($password) > 200) throw new InvalidArgumentException('password');
        $ip = (string)($_SERVER['REMOTE_ADDR'] ?? '');
        $db->exec('CREATE TABLE IF NOT EXISTS login_fail (ip TEXT NOT NULL, ts INTEGER NOT NULL)');
        $db->prepare('DELETE FROM login_fail WHERE ts < ?')->execute([time() - 900]);
        $stmt = $db->prepare('SELECT COUNT(*) FROM login_fail WHERE ip=?'); $stmt->execute([$ip]);
        if ((int)$stmt->fetchColumn() >= 10) { http_response_code(429); echo '{"error":"Слишком много попыток. Подождите 15 минут."}'; exit; }
        if (password_verify($password, $cfg['hash'])) {
            $db->prepare('DELETE FROM login_fail WHERE ip=?')->execute([$ip]);
            pokupki_start_session($cfg);
            echo '{"ok":true}';
        } else {
            $db->prepare('INSERT INTO login_fail(ip,ts) VALUES(?,?)')->execute([$ip, time()]);
            usleep(700000);
            http_response_code(401); echo '{"error":"Неверный пароль"}';
        }
        exit;
    }
    if ($action === 'logout') { pokupki_end_session(); echo '{"ok":true}'; exit; }
    if (!pokupki_require_auth()) { http_response_code(401); echo '{"error":"auth"}'; exit; }

    $id = $input['id'] ?? '';
    if (!in_array($action, ['add', 'update', 'delete'], true)) throw new InvalidArgumentException('action');
    if ($action !== 'add' && (!is_string($id) || !preg_match('/^[a-f0-9]{32}$/D', $id))) throw new InvalidArgumentException('id');
    $db->exec('BEGIN IMMEDIATE');
    $gone = [];
    if ($action === 'delete') {
        $stmt = $db->prepare('SELECT data FROM items WHERE id=?'); $stmt->execute([$id]);
        $raw = $stmt->fetchColumn();
        if ($raw !== false) $gone = json_decode($raw, true, 32, JSON_THROW_ON_ERROR)['photos'] ?? [];
        $stmt = $db->prepare('DELETE FROM items WHERE id=?'); $stmt->execute([$id]);
    } else {
        $patch = $input['data'] ?? null;
        if (!is_array($patch)) throw new InvalidArgumentException('data');
        $data = [];
        if ($action === 'update') {
            $stmt = $db->prepare('SELECT data FROM items WHERE id=?'); $stmt->execute([$id]);
            $raw = $stmt->fetchColumn();
            if ($raw === false) { $db->exec('ROLLBACK'); http_response_code(404); exit; }
            $data = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
        } else {
            if ((int)$db->query('SELECT COUNT(*) FROM items')->fetchColumn() >= 2000) throw new InvalidArgumentException('limit');
            $id = bin2hex(random_bytes(16));
        }
        foreach (['name','need','unit','price','cat','have','order'] as $key) if (array_key_exists($key, $patch)) $data[$key] = $patch[$key];
        // Photos are changed only through shopping-photo.php; archived moves a product out of the list and back.
        if ($action === 'update' && array_key_exists('archived', $patch)) {
            if (!is_bool($patch['archived'])) throw new InvalidArgumentException('archived');
            if ($patch['archived']) { $data['archived'] = true; $data['archivedAt'] = time(); }
            else unset($data['archived'], $data['archivedAt']);
        }
        if (!is_string($data['name'] ?? null) || trim($data['name']) === '' || strlen($data['name']) > 400) throw new InvalidArgumentException('name');
        if (!is_string($data['unit'] ?? '') || strlen($data['unit'] ?? '') > 100) throw new InvalidArgumentException('unit');
        foreach (['need','have','price','order'] as $key) {
            if (!is_numeric($data[$key] ?? null) || !is_finite((float)$data[$key]) || (float)$data[$key] < 0) throw new InvalidArgumentException($key);
        }
        if ($data['need'] < 1 || $data['need'] > 999 || $data['have'] > $data['need'] || $data['price'] > 1000000000) throw new InvalidArgumentException('range');
        // price is in rubles with kopecks: two decimals, whole amounts stay integers
        $price = round((float)$data['price'], 2);
        $data['price'] = $price == floor($price) ? (int)$price : $price;
        if (!in_array($data['cat'] ?? '', ['basic','meat','dairy','bakery','produce','extra'], true)) throw new InvalidArgumentException('cat');
        $stmt = $db->prepare('INSERT INTO items(id,data) VALUES(?,?) ON CONFLICT(id) DO UPDATE SET data=excluded.data');
        $stmt->execute([$id, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)]);
    }
    $db->exec('COMMIT');
    foreach ($gone as $photo) {
        if (is_string($photo) && preg_match('/^[a-f0-9]{32}$/D', $photo)) { @unlink(pokupki_photo_path($photo)); @unlink(pokupki_photo_path($photo, true)); }
    }
    echo json_encode(['id' => $id]);
} catch (InvalidArgumentException | JsonException $e) {
    http_response_code(400); echo '{"error":"Некорректные данные"}';
} catch (Throwable $e) {
    error_log('pokupki: ' . $e->getMessage());
    http_response_code(500); echo '{"error":"Не удалось сохранить список"}';
}
