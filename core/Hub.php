<?php

declare(strict_types=1);

final class Hub
{
    /** @param array<string, Plugin> $plugins keyed by plugin name */
    public function __construct(private array $settings, private array $plugins) {}

    /**
     * Loads the plugins named in the settings file from
     * <pluginsDir>/<name>/src/*.php. The class for "thermobeacon" is
     * ThermobeaconPlugin.
     *
     * @return array<string, Plugin>
     */
    public static function loadPlugins(array $settings, string $pluginsDir): array
    {
        $plugins = [];
        foreach ($settings['plugins'] as $name => $section) {
            $files = glob("$pluginsDir/$name/src/*.php") ?: [];
            if ($files === []) {
                throw new RuntimeException("plugin $name: no files in $pluginsDir/$name/src");
            }
            foreach ($files as $file) {
                require_once $file;
            }
            $class = ucfirst((string)$name) . 'Plugin';
            if (!class_exists($class) || !is_subclass_of($class, Plugin::class)) {
                throw new RuntimeException("plugin $name: class $class not found");
            }
            $plugins[$name] = new $class($section);
        }
        return $plugins;
    }

    /**
     * Handles one request. Returns null for non-API paths, so the caller
     * serves the static file. The password for access from outside is
     * checked by nginx on krpi, not here.
     */
    public function handle(Request $req, int $now): ?Response
    {
        if (!str_starts_with($req->path, '/api/')) {
            return null;
        }
        // php -S has no startup step, so the routes are collected (and
        // checked for duplicates) on every API request.
        $routes = $this->routes();
        if ($req->path === '/api/config') {
            return $this->config();
        }
        if (isset($routes[$req->path])) {
            return ($routes[$req->path])($req, $now);
        }
        return Response::json(404, ['error' => 'not found']);
    }

    /** @return array<string, callable> */
    private function routes(): array
    {
        $routes = [];
        $owner = ['/api/config' => 'the hub'];
        foreach ($this->plugins as $name => $plugin) {
            foreach ($plugin->routes() as $path => $handler) {
                if (isset($owner[$path])) {
                    throw new RuntimeException("route $path is registered by both {$owner[$path]} and $name");
                }
                $owner[$path] = $name;
                $routes[$path] = $handler;
            }
        }
        return $routes;
    }

    private function config(): Response
    {
        $plugins = [];
        foreach ($this->plugins as $name => $plugin) {
            $plugins[$name] = $plugin->publicConfig();
        }
        return Response::json(200, [
            'refresh_seconds' => $this->settings['refresh_seconds'],
            'layout' => $this->settings['layout'],
            'plugins' => (object)$plugins,
        ]);
    }
}
