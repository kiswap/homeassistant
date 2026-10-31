<?php

declare(strict_types=1);

final class Settings
{
    private const REQUIRED = ['plugins', 'layout'];

    /**
     * Loads the JSON settings file and checks the hub's own keys. Each
     * plugin checks its own section under "plugins".
     */
    public static function load(string $path): array
    {
        if (!is_file($path)) {
            throw new RuntimeException("settings file not found: $path");
        }
        $data = json_decode((string)file_get_contents($path), true);
        if (!is_array($data)) {
            throw new RuntimeException("settings file is not valid JSON: $path");
        }
        foreach (self::REQUIRED as $key) {
            if (!array_key_exists($key, $data)) {
                throw new RuntimeException("settings: missing key $key");
            }
        }
        if (!is_array($data['plugins'])) {
            throw new RuntimeException('settings: plugins must be an object');
        }
        $data['refresh_seconds'] ??= 60;
        return $data;
    }
}
