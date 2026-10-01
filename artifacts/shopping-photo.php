<?php
declare(strict_types=1);
require __DIR__ . '/shopping-lib.php';

const PHOTO_MAX_BYTES = 4000000;   // request body; the page shrinks photos before sending
const PHOTO_MAX_SIDE_IN = 8000;    // refuse huge pictures before decoding them
const PHOTO_SIDE = 1024;           // what is stored and shown in the gallery: enough for a phone screen, light on a slow network
const PHOTO_QUALITY = 74;
const PHOTO_THUMB = 160;           // list thumbnail, shown at 44 px
const PHOTO_THUMB_QUALITY = 68;
const PHOTOS_PER_ITEM = 12;

function photo_fail(int $code, string $message = ''): never {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode(['error' => $message], JSON_UNESCAPED_UNICODE);
    exit;
}

function photo_is_id(mixed $v): bool {
    return is_string($v) && preg_match('/^[a-f0-9]{32}$/D', $v) === 1;
}

// Scales to fit a box, flattens transparency onto white, writes a JPEG.
function photo_save(GdImage $src, int $box, string $path, int $quality): void {
    $w = imagesx($src); $h = imagesy($src);
    $k = min(1.0, $box / max($w, $h));
    $nw = max(1, (int)round($w * $k)); $nh = max(1, (int)round($h * $k));
    $dst = imagecreatetruecolor($nw, $nh);
    imagefill($dst, 0, 0, imagecolorallocate($dst, 255, 255, 255));
    imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);
    $tmp = $path . '.' . bin2hex(random_bytes(4)) . '.tmp';
    if (!imagejpeg($dst, $tmp, $quality) || !rename($tmp, $path)) { @unlink($tmp); throw new RuntimeException('write'); }
}

if (!pokupki_require_auth()) photo_fail(401, 'auth');
$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    $id = $_GET['id'] ?? '';
    if (!photo_is_id($id)) photo_fail(400);
    $thumb = ($_GET['s'] ?? '') === 't';
    $path = pokupki_photo_path($id, $thumb);
    if ($thumb && !is_file($path) && is_file(pokupki_photo_path($id))) {
        try {
            $full = imagecreatefromjpeg(pokupki_photo_path($id));
            if ($full !== false) photo_save($full, PHOTO_THUMB, $path, PHOTO_THUMB_QUALITY);
        } catch (Throwable $e) { error_log('pokupki thumb: ' . $e->getMessage()); }
    }
    if (!is_file($path)) photo_fail(404);
    header('Content-Type: image/jpeg');
    header('X-Content-Type-Options: nosniff');
    header("Content-Security-Policy: default-src 'none'");
    header('Cache-Control: private, max-age=31536000, immutable');   // an id never changes its picture
    header('Content-Length: ' . filesize($path));
    readfile($path);
    exit;
}

if ($method !== 'POST') photo_fail(405);
if (!pokupki_same_origin()) photo_fail(403);
$op = $_GET['op'] ?? '';
$item = $_GET['item'] ?? '';
$photo = $_GET['photo'] ?? '';
if (!in_array($op, ['add', 'main', 'del'], true) || !photo_is_id($item) || ($op !== 'add' && !photo_is_id($photo))) photo_fail(400);

try {
    $jpegPath = null;
    if ($op === 'add') {
        if ((int)($_SERVER['CONTENT_LENGTH'] ?? 0) > PHOTO_MAX_BYTES) photo_fail(413, 'Фото слишком большое');
        $body = (string)file_get_contents('php://input', false, null, 0, PHOTO_MAX_BYTES + 1);
        if ($body === '' || strlen($body) > PHOTO_MAX_BYTES) photo_fail(413, 'Фото слишком большое');
        $info = @getimagesizefromstring($body);
        if ($info === false || !in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP], true)
            || $info[0] > PHOTO_MAX_SIDE_IN || $info[1] > PHOTO_MAX_SIDE_IN) photo_fail(415, 'Нужна картинка JPEG, PNG или WebP');
        $img = @imagecreatefromstring($body);
        if ($img === false) photo_fail(415, 'Не удалось открыть картинку');
        $photo = bin2hex(random_bytes(16));
        $dir = pokupki_dir() . '/photos';
        if (!is_dir($dir) && !mkdir($dir, 0750, true) && !is_dir($dir)) throw new RuntimeException('photos dir');
        $jpegPath = pokupki_photo_path($photo);
    }

    $db = pokupki_db();
    $db->exec('BEGIN IMMEDIATE');
    $stmt = $db->prepare('SELECT data FROM items WHERE id=?'); $stmt->execute([$item]);
    $raw = $stmt->fetchColumn();
    if ($raw === false) { $db->exec('ROLLBACK'); photo_fail(404, 'Продукт не найден'); }
    $data = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
    $photos = array_values(array_filter($data['photos'] ?? [], 'photo_is_id'));

    if ($op === 'add') {
        if (count($photos) >= PHOTOS_PER_ITEM) { $db->exec('ROLLBACK'); photo_fail(409, 'У продукта уже ' . PHOTOS_PER_ITEM . ' фото'); }
        photo_save($img, PHOTO_SIDE, $jpegPath, PHOTO_QUALITY);
        photo_save($img, PHOTO_THUMB, pokupki_photo_path($photo, true), PHOTO_THUMB_QUALITY);   // so the list never waits for a first-view resize
        $photos[] = $photo;
    } else {
        $at = array_search($photo, $photos, true);
        if ($at === false) { $db->exec('ROLLBACK'); photo_fail(404, 'Фото не найдено'); }
        array_splice($photos, $at, 1);
        if ($op === 'main') array_unshift($photos, $photo);
    }
    $data['photos'] = $photos;
    $stmt = $db->prepare('UPDATE items SET data=? WHERE id=?');
    $stmt->execute([json_encode($data, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), $item]);
    $db->exec('COMMIT');
    if ($op === 'del') { @unlink(pokupki_photo_path($photo)); @unlink(pokupki_photo_path($photo, true)); }
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode(['photo' => $photo, 'photos' => $photos]);
} catch (Throwable $e) {
    if ($jpegPath !== null) { @unlink($jpegPath); @unlink(pokupki_photo_path($photo, true)); }
    error_log('pokupki photo: ' . $e->getMessage());
    photo_fail(500, 'Не удалось сохранить фото');
}
