<?php

declare(strict_types=1);

function write_settings(array $data): string
{
    $path = sys_get_temp_dir() . '/tb-settings-' . bin2hex(random_bytes(4)) . '.json';
    file_put_contents($path, json_encode($data));
    return $path;
}

function minimal_settings(): array
{
    return [
        'api_key' => 'k',
        'db_path' => ':memory:',
        'sensors' => [['id' => 'a', 'name' => 'A', 'icon' => 'tree', 'address' => '70:f7:00:00:12:3a']],
        'layout' => ['columns' => 1, 'rows' => ['1fr'], 'widgets' => []],
    ];
}

function test_settings_missing_key_throws(): void
{
    $data = minimal_settings();
    unset($data['api_key']);
    try {
        Settings::load(write_settings($data));
    } catch (RuntimeException $e) {
        assert_true(str_contains($e->getMessage(), 'api_key'), $e->getMessage());
        return;
    }
    throw new AssertionFailed('no exception');
}

function test_settings_old_file_with_password_keys_still_loads(): void
{
    // settings.json files from the first deploy still hold these keys.
    $data = minimal_settings() + [
        'home_networks' => ['192.168.0.0/16'],
        'basic_auth' => ['user' => 'u', 'password' => 'p'],
    ];
    assert_eq('k', Settings::load(write_settings($data))['api_key']);
}

function test_settings_missing_file_throws(): void
{
    try {
        Settings::load('/nonexistent/settings.json');
    } catch (RuntimeException $e) {
        return;
    }
    throw new AssertionFailed('no exception');
}

function test_settings_defaults_and_uppercase(): void
{
    $s = Settings::load(write_settings(minimal_settings()));
    assert_eq(600, $s['stale_after_seconds']);
    assert_eq(60, $s['refresh_seconds']);
    assert_eq('70:F7:00:00:12:3A', $s['sensors'][0]['address']);
    assert_eq('a', Settings::sensorByAddress($s)['70:F7:00:00:12:3A']['id']);
}

function test_settings_example_loads(): void
{
    $s = Settings::load(__DIR__ . '/../config/settings.example.json');
    assert_eq(3, count($s['sensors']));
    assert_eq(['utazo', 'konyha', 'kinti'], array_column($s['sensors'], 'id'));
    assert_eq('70:F7:00:00:14:63', $s['sensors'][1]['address']);
}
