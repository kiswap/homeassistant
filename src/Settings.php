<?php

declare(strict_types=1);

final class Settings
{
    private const REQUIRED = ['api_key', 'db_path', 'sensors', 'layout'];

    /** Loads and validates the JSON settings file. */
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
        $data['stale_after_seconds'] ??= 600;
        $data['refresh_seconds'] ??= 60;
        foreach ($data['sensors'] as $i => $sensor) {
            foreach (['id', 'name', 'address'] as $field) {
                if (!isset($sensor[$field])) {
                    throw new RuntimeException("settings: sensors[$i] needs $field");
                }
            }
            $data['sensors'][$i]['address'] = strtoupper($sensor['address']);
            $data['sensors'][$i]['icon'] ??= '';
        }
        return $data;
    }

    /** Map of upper-case BLE address to sensor settings. */
    public static function sensorByAddress(array $settings): array
    {
        return array_column($settings['sensors'], null, 'address');
    }

    /** Map of sensor id to sensor settings. */
    public static function sensorById(array $settings): array
    {
        return array_column($settings['sensors'], null, 'id');
    }
}
