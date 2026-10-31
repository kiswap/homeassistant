<?php

declare(strict_types=1);

/**
 * Converts a settings.json from before the hub split (one flat file for
 * the thermobeacon server) to the hub's shape. Used once per server by
 * tools/convert-settings.php.
 */
final class LegacySettings
{
    private const REQUIRED = ['api_key', 'db_path', 'sensors', 'layout'];
    private const WIDGET_TYPES = ['sensor' => 'thermobeacon.sensor', 'graph' => 'thermobeacon.graph'];

    public static function convert(array $old): array
    {
        foreach (self::REQUIRED as $key) {
            if (!array_key_exists($key, $old)) {
                throw new RuntimeException("old settings: missing key $key");
            }
        }
        $layout = $old['layout'];
        foreach ($layout['widgets'] ?? [] as $i => $widget) {
            $layout['widgets'][$i]['type'] = self::WIDGET_TYPES[$widget['type'] ?? ''] ?? $widget['type'];
        }
        // home_networks and basic_auth are dropped: nginx checks the password.
        return [
            'refresh_seconds' => $old['refresh_seconds'] ?? 60,
            'plugins' => [
                'thermobeacon' => [
                    'api_key' => $old['api_key'],
                    'db_path' => $old['db_path'],
                    'stale_after_seconds' => $old['stale_after_seconds'] ?? 600,
                    'sensors' => $old['sensors'],
                ],
            ],
            'layout' => $layout,
        ];
    }
}
