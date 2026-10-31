<?php
// Router for PHP's built-in web server:
//   php -q -S 0.0.0.0:8080 -t public router.php
// API requests are answered here. For other paths `return false` lets the
// built-in server send the file from public/. The password for access from
// outside is checked by nginx on krpi, not here.

declare(strict_types=1);

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
if (!str_starts_with($path, '/api/')) {
    return false;
}

foreach (glob(__DIR__ . '/src/*.php') as $file) {
    require_once $file;
}

try {
    $settings = Settings::load(getenv('SETTINGS_PATH') ?: __DIR__ . '/config/settings.json');
    $req = new Request(
        $_SERVER['REQUEST_METHOD'] ?? 'GET',
        $path,
        $_GET,
        array_change_key_case(getallheaders(), CASE_LOWER),
        (string)file_get_contents('php://input'),
    );
    $resp = Api::handle($req, $settings, new ReadingStore(Db::open($settings['db_path'])), time());
} catch (Throwable $e) {
    error_log('thermobeacon: ' . get_class($e) . ': ' . $e->getMessage());
    $resp = Response::json(500, ['error' => 'server error']);
}

http_response_code($resp->status);
foreach ($resp->headers as $name => $value) {
    header("$name: $value");
}
echo $resp->body;
