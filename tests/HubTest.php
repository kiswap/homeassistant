<?php

declare(strict_types=1);

final class FakePlugin implements Plugin
{
    public ?int $seenNow = null;

    /** @param string[] $paths */
    public function __construct(private array $paths = ['/api/fake/ping'], private array $public = ['x' => 1]) {}

    public function routes(): array
    {
        $routes = [];
        foreach ($this->paths as $path) {
            $routes[$path] = function (Request $req, int $now): Response {
                $this->seenNow = $now;
                return Response::json(200, ['pong' => $req->path]);
            };
        }
        return $routes;
    }

    public function publicConfig(): array
    {
        return $this->public;
    }
}

function hub_settings(): array
{
    return ['refresh_seconds' => 30, 'plugins' => ['fake' => []], 'layout' => ['columns' => 1, 'widgets' => []]];
}

function hub_get(Hub $hub, string $path): ?Response
{
    return $hub->handle(new Request('GET', $path, [], [], ''), 1793400000);
}

function test_hub_config_lists_plugins(): void
{
    $r = hub_get(new Hub(hub_settings(), ['fake' => new FakePlugin()]), '/api/config');
    assert_eq(200, $r->status);
    assert_eq([
        'refresh_seconds' => 30,
        'layout' => ['columns' => 1, 'widgets' => []],
        'plugins' => ['fake' => ['x' => 1]],
    ], json_decode($r->body, true));
}

function test_hub_routes_to_plugin(): void
{
    $plugin = new FakePlugin();
    $r = hub_get(new Hub(hub_settings(), ['fake' => $plugin]), '/api/fake/ping');
    assert_eq(200, $r->status);
    assert_eq(['pong' => '/api/fake/ping'], json_decode($r->body, true));
    assert_eq(1793400000, $plugin->seenNow);
}

function test_hub_unknown_api_path_404(): void
{
    $r = hub_get(new Hub(hub_settings(), ['fake' => new FakePlugin()]), '/api/nothing');
    assert_eq(404, $r->status);
    assert_eq(['error' => 'not found'], json_decode($r->body, true));
}

function test_hub_non_api_path_returns_null(): void
{
    assert_eq(null, hub_get(new Hub(hub_settings(), ['fake' => new FakePlugin()]), '/index.html'));
}

function test_hub_duplicate_route_throws(): void
{
    $hub = new Hub(hub_settings(), ['one' => new FakePlugin(['/api/x']), 'two' => new FakePlugin(['/api/x'])]);
    try {
        hub_get($hub, '/api/config');
    } catch (RuntimeException $e) {
        assert_true(str_contains($e->getMessage(), 'one') && str_contains($e->getMessage(), 'two'), $e->getMessage());
        return;
    }
    throw new AssertionFailed('no exception');
}

function test_load_plugins_missing_folder_throws(): void
{
    try {
        Hub::loadPlugins(['plugins' => ['nope' => []]], sys_get_temp_dir() . '/no-plugins-here');
    } catch (RuntimeException $e) {
        assert_true(str_contains($e->getMessage(), 'plugin nope'), $e->getMessage());
        return;
    }
    throw new AssertionFailed('no exception');
}

function test_load_plugins_missing_class_throws(): void
{
    $dir = sys_get_temp_dir() . '/ha-plugins-' . bin2hex(random_bytes(4));
    mkdir("$dir/empty/src", 0777, true);
    file_put_contents("$dir/empty/src/Nothing.php", "<?php\n");
    try {
        Hub::loadPlugins(['plugins' => ['empty' => []]], $dir);
    } catch (RuntimeException $e) {
        assert_true(str_contains($e->getMessage(), 'plugin empty'), $e->getMessage());
        return;
    }
    throw new AssertionFailed('no exception');
}

function plugin_dir(): string
{
    $dir = sys_get_temp_dir() . '/ha-web-' . bin2hex(random_bytes(4));
    mkdir("$dir/demo/web/widgets", 0777, true);
    file_put_contents("$dir/demo/web/widgets/tile.js", 'export {};');
    mkdir("$dir/demo/src");
    file_put_contents("$dir/demo/src/Secret.php", '<?php');
    return $dir;
}

function test_plugin_file_found(): void
{
    $dir = plugin_dir();
    assert_eq(realpath("$dir/demo/web/widgets/tile.js"), Hub::pluginFile('/plugins/demo/widgets/tile.js', ['demo'], $dir));
}

function test_plugin_file_disabled_plugin_null(): void
{
    assert_eq(null, Hub::pluginFile('/plugins/demo/widgets/tile.js', ['other'], plugin_dir()));
}

function test_plugin_file_dotdot_null(): void
{
    $dir = plugin_dir();
    assert_eq(null, Hub::pluginFile('/plugins/demo/../demo/src/Secret.php', ['demo'], $dir));
    assert_eq(null, Hub::pluginFile('/plugins/demo/widgets/../../src/Secret.php', ['demo'], $dir));
}

function test_plugin_file_missing_null(): void
{
    $dir = plugin_dir();
    assert_eq(null, Hub::pluginFile('/plugins/demo/widgets/none.js', ['demo'], $dir));
    assert_eq(null, Hub::pluginFile('/plugins/demo/', ['demo'], $dir));
    assert_eq(null, Hub::pluginFile('/plugins/demo/widgets', ['demo'], $dir));
}

function test_content_type_js(): void
{
    assert_eq('application/javascript; charset=utf-8', Hub::contentType('/x/widgets/tile.js'));
    assert_eq('text/css; charset=utf-8', Hub::contentType('/x/a.css'));
    assert_eq('image/svg+xml', Hub::contentType('/x/a.svg'));
    assert_eq('application/octet-stream', Hub::contentType('/x/a.bin'));
}
