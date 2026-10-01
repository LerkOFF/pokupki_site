<?php
declare(strict_types=1);
// Shared by shopping-api.php and shopping-photo.php. Not routed by nginx: only those two files are public.

const POKUPKI_COOKIE = 'pokupki_s';
const POKUPKI_SESSION_DAYS = 30;

// Whole numbers stay integers in JSON, others keep at most two decimals (price in rubles, amount in кг or л).
function pokupki_num(float $v): int|float {
    $v = round($v, 2);
    return $v == floor($v) ? (int)$v : $v;
}

function pokupki_dir(): string {
    return rtrim(getenv('POKUPKI_DATA') ?: '/var/lib/pokupki', '/');
}

function pokupki_db(): PDO {
    $db = new PDO('sqlite:' . pokupki_dir() . '/shopping.sqlite');
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->exec('PRAGMA busy_timeout=5000');
    $db->exec('CREATE TABLE IF NOT EXISTS items (id TEXT PRIMARY KEY, data TEXT NOT NULL)');
    $db->exec('CREATE TABLE IF NOT EXISTS meta (k TEXT PRIMARY KEY, v TEXT NOT NULL)');
    pokupki_migrate_unit_price($db);
    return $db;
}

// Before this change "price" was the cost of the whole amount (30 eggs for 300). Now it is the price of one unit
// (шт., кг, л) and the total is price × amount. Runs once per database: backup first, then one transaction.
function pokupki_migrate_unit_price(PDO $db): void {
    $done = fn() => $db->query("SELECT 1 FROM meta WHERE k='price_per_unit'")->fetchColumn() !== false;
    if ($done()) return;
    $backup = pokupki_dir() . '/backup-before-unit-price.sqlite';
    if ((int)$db->query('SELECT COUNT(*) FROM items')->fetchColumn() > 0 && !file_exists($backup)) {
        $db->exec('VACUUM INTO ' . $db->quote($backup));
    }
    $db->exec('BEGIN IMMEDIATE');
    try {
        if ($done()) { $db->exec('ROLLBACK'); return; }
        $update = $db->prepare('UPDATE items SET data=? WHERE id=?');
        foreach ($db->query('SELECT id, data FROM items')->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $d = json_decode($row['data'], true, 32, JSON_THROW_ON_ERROR);
            if (!is_numeric($d['price'] ?? null) || (float)$d['price'] <= 0) continue;
            $need = $d['need'] ?? null;
            if (!is_numeric($need) || $need < 1) {   // older rows kept the amount as text, e.g. "30 шт."
                $need = preg_match('/^(\d+(?:[.,]\d+)?)/', (string)($d['qty'] ?? ''), $m) ? (float)str_replace(',', '.', $m[1]) : 1;
            }
            $need = max(1, min(999, (int)round((float)$need)));
            $p = max(0.01, round((float)$d['price'] / $need, 2));
            $d['price'] = $p == floor($p) ? (int)$p : $p;
            $update->execute([json_encode($d, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), $row['id']]);
        }
        $db->exec("INSERT INTO meta(k,v) VALUES('price_per_unit', datetime('now'))");
        $db->exec('COMMIT');
    } catch (Throwable $e) {
        $db->exec('ROLLBACK');
        throw $e;
    }
}

function pokupki_photo_path(string $id, bool $thumb = false): string {
    return pokupki_dir() . '/photos/' . $id . ($thumb ? '.t' : '') . '.jpg';
}

// Same-origin check for writes: the Origin host must be the host the request came to.
function pokupki_same_origin(): bool {
    $origin = $_SERVER['HTTP_ORIGIN'] ?? null;
    if ($origin === null) return true;
    $p = parse_url($origin);
    if (!is_array($p) || !isset($p['host'])) return false;
    $authority = strtolower($p['host']) . (isset($p['port']) ? ':' . $p['port'] : '');
    return $authority === strtolower($_SERVER['HTTP_HOST'] ?? '');
}

// /var/lib/pokupki/auth.json: {"hash": "<password_hash>", "secret": "<random hex>"}. It lives on the server, not in Git.
// Without a valid file nobody gets in.
function pokupki_auth_config(): ?array {
    $raw = @file_get_contents(pokupki_dir() . '/auth.json');
    $cfg = $raw === false ? null : json_decode($raw, true);
    if (!is_array($cfg) || !is_string($cfg['hash'] ?? null) || !is_string($cfg['secret'] ?? null) || strlen($cfg['secret']) < 32) return null;
    return $cfg;
}

// The signature covers the password hash, so changing the password signs everyone out.
function pokupki_sign(int $exp, array $cfg): string {
    return hash_hmac('sha256', (string)$exp, $cfg['secret'] . $cfg['hash']);
}

function pokupki_set_cookie(string $value, int $expires): void {
    setcookie(POKUPKI_COOKIE, $value, [
        'expires' => $expires,
        'path' => '/pokupki',
        'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

function pokupki_start_session(array $cfg): void {
    $exp = time() + POKUPKI_SESSION_DAYS * 86400;
    pokupki_set_cookie($exp . ':' . pokupki_sign($exp, $cfg), $exp);
}

function pokupki_end_session(): void {
    pokupki_set_cookie('', 1);
}

// True for a valid session. A session with less than half its time left is extended, so people in daily use stay signed in.
function pokupki_require_auth(): bool {
    $cfg = pokupki_auth_config();
    $cookie = $_COOKIE[POKUPKI_COOKIE] ?? '';
    if ($cfg === null || !is_string($cookie) || !preg_match('/^(\d{10}):([a-f0-9]{64})$/D', $cookie, $m)) return false;
    $exp = (int)$m[1];
    if ($exp <= time() || !hash_equals(pokupki_sign($exp, $cfg), $m[2])) return false;
    if ($exp - time() < POKUPKI_SESSION_DAYS * 43200) pokupki_start_session($cfg);
    return true;
}
