<?php
// Router for PHP's built-in web server:
//   php -q -S 0.0.0.0:8080 -t public router.php
// API requests are answered here, and /plugins/<name>/... is served from
// plugins/<name>/web/. For other paths `return false` lets the built-in
// server send the file from public/. The password for access from
// outside is checked by nginx on krpi, not here.

declare(strict_types=1);

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$isApi = str_starts_with($path, '/api/');
$isPluginFile = str_starts_with($path, '/plugins/');
if (!$isApi && !$isPluginFile) {
    return false;
}

foreach (glob(__DIR__ . '/core/*.php') as $file) {
    require_once $file;
}
$settingsPath = getenv('SETTINGS_PATH') ?: __DIR__ . '/config/settings.json';

if ($isPluginFile) {
    try {
        $enabled = array_keys(Settings::load($settingsPath)['plugins']);
        $file = Hub::pluginFile($path, $enabled, __DIR__ . '/plugins');
    } catch (Throwable $e) {
        error_log('homeassistant: ' . get_class($e) . ': ' . $e->getMessage());
        $file = null;
    }
    if ($file === null) {
        http_response_code(404);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'not found';
        return true;
    }
    header('Content-Type: ' . Hub::contentType($file));
    header('Cache-Control: no-cache');
    readfile($file);
    return true;
}

try {
    $settings = Settings::load($settingsPath);
    $req = new Request(
        $_SERVER['REQUEST_METHOD'] ?? 'GET',
        $path,
        $_GET,
        array_change_key_case(getallheaders(), CASE_LOWER),
        (string)file_get_contents('php://input'),
    );
    $hub = new Hub($settings, Hub::loadPlugins($settings, __DIR__ . '/plugins'));
    $resp = $hub->handle($req, time()) ?? Response::json(404, ['error' => 'not found']);
} catch (Throwable $e) {
    error_log('homeassistant: ' . get_class($e) . ': ' . $e->getMessage());
    $resp = Response::json(500, ['error' => 'server error']);
}

http_response_code($resp->status);
foreach ($resp->headers as $name => $value) {
    header("$name: $value");
}
echo $resp->body;
