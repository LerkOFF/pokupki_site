<?php
// Local run, from the project root:
//   POKUPKI_DATA=/tmp/pokupki-data php -S 127.0.0.1:8080 scripts/dev-router.php
// then open http://127.0.0.1:8080/pokupki/ . Put {"hash": password_hash(...), "secret": "<32+ random chars>"} into $POKUPKI_DATA/auth.json.
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$root = dirname(__DIR__) . '/artifacts';
switch ($path) {
    case '/pokupki':
        header('Location: /pokupki/', true, 301);
        return true;
    case '/pokupki/':
        header('Content-Type: text/html; charset=utf-8');
        header('Cache-Control: no-store');
        readfile($root . '/shopping-list.html');
        return true;
    case '/pokupki/api':
        // POKUPKI_DELAY_MS=3000 slows list requests down to imitate a poor connection.
        if (($_SERVER['REQUEST_METHOD'] ?? '') === 'GET' && getenv('POKUPKI_DELAY_MS')) usleep((int)getenv('POKUPKI_DELAY_MS') * 1000);
        require $root . '/shopping-api.php';
        return true;
    case '/pokupki/photo':
        require $root . '/shopping-photo.php';
        return true;
}
http_response_code(404);
return true;
