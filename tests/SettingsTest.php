<?php

declare(strict_types=1);

function write_settings(array $data): string
{
    $path = sys_get_temp_dir() . '/ha-settings-' . bin2hex(random_bytes(4)) . '.json';
    file_put_contents($path, json_encode($data));
    return $path;
}

function minimal_settings(): array
{
    return ['plugins' => [], 'layout' => ['columns' => 1, 'rows' => ['1fr'], 'widgets' => []]];
}

function expect_settings_error(array $data, string $needle): void
{
    try {
        Settings::load(write_settings($data));
    } catch (RuntimeException $e) {
        assert_true(str_contains($e->getMessage(), $needle), $e->getMessage());
        return;
    }
    throw new AssertionFailed('no exception');
}

function test_settings_requires_plugins_and_layout(): void
{
    $data = minimal_settings();
    unset($data['plugins']);
    expect_settings_error($data, 'plugins');
    $data = minimal_settings();
    unset($data['layout']);
    expect_settings_error($data, 'layout');
}

function test_settings_default_refresh_seconds(): void
{
    assert_eq(60, Settings::load(write_settings(minimal_settings()))['refresh_seconds']);
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
