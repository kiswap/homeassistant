<?php

declare(strict_types=1);

/** A settings.json as written for the first krpi deploy. */
function legacy_settings(): array
{
    return [
        'api_key' => 'secret',
        'db_path' => '/data/thermobeacon.sqlite',
        'stale_after_seconds' => 900,
        'refresh_seconds' => 30,
        'home_networks' => ['192.168.0.0/16'],
        'basic_auth' => ['user' => 'u', 'password' => 'p'],
        'sensors' => [['id' => 'kinti', 'name' => 'kinti', 'icon' => 'tree', 'address' => '70:F7:00:00:12:3A']],
        'layout' => ['columns' => 2, 'rows' => ['1fr'], 'widgets' => [
            ['type' => 'sensor', 'sensor' => 'kinti', 'col' => 1, 'row' => 1],
            ['type' => 'graph', 'sensors' => ['kinti'], 'col' => 2, 'row' => 1],
            ['type' => 'placeholder', 'col' => 2, 'row' => 2],
        ]],
    ];
}

function test_convert_moves_keys_into_plugin_section(): void
{
    $new = LegacySettings::convert(legacy_settings());
    assert_eq(['refresh_seconds', 'plugins', 'layout'], array_keys($new));
    assert_eq(30, $new['refresh_seconds']);
    assert_eq([
        'api_key' => 'secret',
        'db_path' => '/data/thermobeacon.sqlite',
        'stale_after_seconds' => 900,
        'sensors' => legacy_settings()['sensors'],
    ], $new['plugins']['thermobeacon']);
}

function test_convert_renames_widget_types(): void
{
    $types = array_column(LegacySettings::convert(legacy_settings())['layout']['widgets'], 'type');
    assert_eq(['thermobeacon.sensor', 'thermobeacon.graph', 'placeholder'], $types);
}

function test_convert_drops_password_keys(): void
{
    $json = json_encode(LegacySettings::convert(legacy_settings()));
    assert_true(!str_contains($json, 'home_networks') && !str_contains($json, 'basic_auth'), $json);
}

function test_convert_fills_defaults(): void
{
    $old = legacy_settings();
    unset($old['stale_after_seconds'], $old['refresh_seconds']);
    $new = LegacySettings::convert($old);
    assert_eq(60, $new['refresh_seconds']);
    assert_eq(600, $new['plugins']['thermobeacon']['stale_after_seconds']);
}

function test_convert_missing_api_key_throws(): void
{
    $old = legacy_settings();
    unset($old['api_key']);
    try {
        LegacySettings::convert($old);
    } catch (RuntimeException $e) {
        assert_true(str_contains($e->getMessage(), 'api_key'), $e->getMessage());
        return;
    }
    throw new AssertionFailed('no exception');
}

function test_convert_output_loads(): void
{
    $path = sys_get_temp_dir() . '/ha-converted-' . bin2hex(random_bytes(4)) . '.json';
    file_put_contents($path, json_encode(LegacySettings::convert(legacy_settings())));
    $settings = Settings::load($path);
    $plugin = new ThermobeaconPlugin($settings['plugins']['thermobeacon']);
    assert_eq(['kinti'], array_keys($plugin->sensorById()));
}
