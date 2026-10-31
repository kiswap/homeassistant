<?php

declare(strict_types=1);

function tb_section(): array
{
    return [
        'api_key' => 'k',
        'db_path' => ':memory:',
        'sensors' => [['id' => 'a', 'name' => 'A', 'icon' => 'tree', 'address' => '70:f7:00:00:12:3a']],
    ];
}

function expect_tb_section_error(array $section, string $needle): void
{
    try {
        new ThermobeaconPlugin($section);
    } catch (RuntimeException $e) {
        assert_true(str_contains($e->getMessage(), $needle), $e->getMessage());
        return;
    }
    throw new AssertionFailed('no exception');
}

function test_tb_section_missing_key_throws(): void
{
    foreach (['api_key', 'db_path', 'sensors'] as $key) {
        $section = tb_section();
        unset($section[$key]);
        expect_tb_section_error($section, $key);
    }
}

function test_tb_sensor_needs_fields(): void
{
    $section = tb_section();
    unset($section['sensors'][0]['address']);
    expect_tb_section_error($section, 'sensors[0] needs address');
}

function test_tb_section_with_old_password_keys_still_loads(): void
{
    // A hand-written section may still hold the keys of the first deploy.
    $section = tb_section() + ['home_networks' => ['192.168.0.0/16'], 'basic_auth' => ['user' => 'u']];
    assert_eq(['a'], array_column((new ThermobeaconPlugin($section))->publicConfig()['sensors'], 'id'));
}

function test_tb_defaults_and_uppercase(): void
{
    $plugin = new ThermobeaconPlugin(tb_section());
    assert_eq(600, $plugin->publicConfig()['stale_after_seconds']);
    assert_eq('70:F7:00:00:12:3A', $plugin->sensorByAddress()['70:F7:00:00:12:3A']['address']);
    assert_eq('a', $plugin->sensorById()['a']['id']);
}

function test_tb_icon_defaults_to_empty(): void
{
    $section = tb_section();
    unset($section['sensors'][0]['icon']);
    assert_eq('', (new ThermobeaconPlugin($section))->publicConfig()['sensors'][0]['icon']);
}

function test_settings_example_loads(): void
{
    $settings = Settings::load(__DIR__ . '/../../../config/settings.example.json');
    $plugin = new ThermobeaconPlugin($settings['plugins']['thermobeacon']);
    assert_eq(['utazo', 'konyha', 'kinti'], array_column($plugin->publicConfig()['sensors'], 'id'));
    assert_eq('70:F7:00:00:14:63', $plugin->sensorById()['konyha']['address']);
}
