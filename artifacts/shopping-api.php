<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
try {
    $db = new PDO('sqlite:/var/lib/pokupki/shopping.sqlite');
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->exec('PRAGMA busy_timeout=5000');
    $db->exec('CREATE TABLE IF NOT EXISTS items (id TEXT PRIMARY KEY, data TEXT NOT NULL)');
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $items = [];
        foreach ($db->query('SELECT id, data FROM items ORDER BY id') as $row) {
            $items[] = ['id' => $row['id']] + json_decode($row['data'], true, 512, JSON_THROW_ON_ERROR);
        }
        echo json_encode($items, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        exit;
    }
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit; }
    if (isset($_SERVER['HTTP_ORIGIN']) && $_SERVER['HTTP_ORIGIN'] !== 'https://lerk.tech') {
        http_response_code(403); exit;
    }
    if ((int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 8192) { http_response_code(413); exit; }
    $input = json_decode(file_get_contents('php://input'), true, 32, JSON_THROW_ON_ERROR);
    $action = $input['action'] ?? '';
    $id = $input['id'] ?? '';
    if (!in_array($action, ['add', 'update', 'delete'], true)) throw new InvalidArgumentException('action');
    if ($action !== 'add' && (!is_string($id) || !preg_match('/^[a-f0-9]{32}$/D', $id))) throw new InvalidArgumentException('id');
    $db->exec('BEGIN IMMEDIATE');
    if ($action === 'delete') {
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
        if (!is_string($data['name'] ?? null) || trim($data['name']) === '' || strlen($data['name']) > 400) throw new InvalidArgumentException('name');
        if (!is_string($data['unit'] ?? '') || strlen($data['unit'] ?? '') > 100) throw new InvalidArgumentException('unit');
        foreach (['need','have','price','order'] as $key) {
            if (!is_numeric($data[$key] ?? null) || !is_finite((float)$data[$key]) || (float)$data[$key] < 0) throw new InvalidArgumentException($key);
        }
        if ($data['need'] < 1 || $data['need'] > 999 || $data['have'] > $data['need'] || $data['price'] > 1000000000) throw new InvalidArgumentException('range');
        if (!in_array($data['cat'] ?? '', ['basic','meat','dairy','bakery','produce','extra'], true)) throw new InvalidArgumentException('cat');
        $stmt = $db->prepare('INSERT INTO items(id,data) VALUES(?,?) ON CONFLICT(id) DO UPDATE SET data=excluded.data');
        $stmt->execute([$id, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)]);
    }
    $db->exec('COMMIT');
    echo json_encode(['id' => $id]);
} catch (InvalidArgumentException | JsonException $e) {
    http_response_code(400); echo '{"error":"Некорректные данные"}';
} catch (Throwable $e) {
    error_log('pokupki: ' . $e->getMessage());
    http_response_code(500); echo '{"error":"Не удалось сохранить список"}';
}
