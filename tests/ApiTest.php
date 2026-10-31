<?php

declare(strict_types=1);

const NOW = 1793400000;
const KONYHA = '70:F7:00:00:14:63';

function api_settings(): array
{
    return Settings::load(__DIR__ . '/../config/settings.example.json')
        + []; // example has api_key CHANGE_ME
}

function api_store(): ReadingStore
{
    return new ReadingStore(Db::open(':memory:'));
}

function call(ReadingStore $store, string $method, string $path, array $query = [], string $body = '',
              array $headers = []): ?Response
{
    return Api::handle(new Request($method, $path, $query, $headers, $body), api_settings(), $store, NOW);
}

function post_readings(ReadingStore $store, array $readings, string $key = 'CHANGE_ME'): Response
{
    return call($store, 'POST', '/api/readings', [], json_encode(['readings' => $readings]), ['x-api-key' => $key]);
}

function reading(string $address = KONYHA, int $ts = NOW - 60, array $override = []): array
{
    return array_merge([
        'address' => $address, 'ts' => $ts, 'temperature_c' => 22.56,
        'humidity_pct' => 63.94, 'voltage_mv' => 2930, 'battery_pct' => 93,
    ], $override);
}

function body(Response $r): array
{
    return json_decode($r->body, true);
}

function test_post_requires_api_key(): void
{
    $r = post_readings(api_store(), [reading()], 'wrong');
    assert_eq(401, $r->status);
    assert_eq('bad api key', body($r)['error']);
}

function test_post_stores_and_counts(): void
{
    $s = api_store();
    $r = post_readings($s, [reading(ts: NOW - 120), reading(ts: NOW - 60)]);
    assert_eq(200, $r->status);
    assert_eq(['stored' => 2, 'duplicates' => 0, 'ignored' => 0, 'rejected' => 0], body($r));
    assert_eq(NOW - 60, $s->latest(KONYHA)['ts']);
}

function test_post_lowercase_address_is_matched(): void
{
    $s = api_store();
    post_readings($s, [reading(strtolower(KONYHA))]);
    assert_eq(NOW - 60, $s->latest(KONYHA)['ts']);
}

function test_post_mixed_batch(): void
{
    $s = api_store();
    $missing = reading(ts: NOW - 10);
    unset($missing['humidity_pct']);
    $r = post_readings($s, [
        reading(),
        reading(),
        reading('11:22:33:44:55:66'),
        $missing,
        reading('zz'),
        reading(ts: NOW - 5, override: ['temperature_c' => 'hot']),
    ]);
    assert_eq(200, $r->status);
    assert_eq(['stored' => 1, 'duplicates' => 1, 'ignored' => 1, 'rejected' => 3], body($r));
}

function test_post_too_many(): void
{
    $readings = [];
    for ($i = 0; $i < 501; $i++) {
        $readings[] = reading(ts: NOW - $i);
    }
    assert_eq(413, post_readings(api_store(), $readings)->status);
}

function test_post_bad_json(): void
{
    $r = call(api_store(), 'POST', '/api/readings', [], '{bad', ['x-api-key' => 'CHANGE_ME']);
    assert_eq(400, $r->status);
    $r = call(api_store(), 'POST', '/api/readings', [], '{"readings":5}', ['x-api-key' => 'CHANGE_ME']);
    assert_eq(400, $r->status);
}

function test_post_updates_collector_last_seen(): void
{
    $s = api_store();
    assert_eq(200, post_readings($s, [])->status);
    assert_eq((string)NOW, $s->getMeta('collector_last_seen'));
}

function test_get_on_readings_is_405(): void
{
    assert_eq(405, call(api_store(), 'GET', '/api/readings')->status);
}

function test_config_hides_secrets(): void
{
    $r = call(api_store(), 'GET', '/api/config');
    assert_eq(200, $r->status);
    assert_true(!str_contains($r->body, 'CHANGE_ME'), 'secret leaked');
    assert_true(!str_contains($r->body, '70:F7'), 'addresses leaked');
    $b = body($r);
    assert_eq(60, $b['refresh_seconds']);
    assert_eq(600, $b['stale_after_seconds']);
    assert_eq(4, $b['layout']['columns']);
    assert_eq(['id' => 'utazo', 'name' => 'utazó', 'icon' => 'room'], $b['sensors'][0]);
}

function test_sensors_latest_null_when_empty(): void
{
    $b = body(call(api_store(), 'GET', '/api/sensors'));
    assert_eq(NOW, $b['now']);
    assert_eq(null, $b['collector_last_seen']);
    assert_eq(3, count($b['sensors']));
    assert_eq(null, $b['sensors'][0]['latest']);
}

function test_sensors_filter_by_id(): void
{
    $s = api_store();
    post_readings($s, [reading()]);
    $b = body(call($s, 'GET', '/api/sensors', ['id' => 'konyha']));
    assert_eq(1, count($b['sensors']));
    assert_eq('konyha', $b['sensors'][0]['id']);
    assert_eq(22.56, $b['sensors'][0]['latest']['temperature_c']);
    assert_eq(NOW, $b['collector_last_seen']);
}

function test_sensors_unknown_id(): void
{
    assert_eq(400, call(api_store(), 'GET', '/api/sensors', ['id' => 'nope'])->status);
}

function test_history_bucket_sizes(): void
{
    foreach ([24 => 300, 48 => 600, 168 => 1800] as $hours => $bucket) {
        $b = body(call(api_store(), 'GET', '/api/history', ['sensors' => 'konyha,kinti', 'hours' => (string)$hours]));
        assert_eq($bucket, $b['bucket_seconds']);
        assert_eq(NOW - $hours * 3600, $b['from']);
        assert_eq(NOW, $b['to']);
        assert_eq(['konyha', 'kinti'], array_column($b['series'], 'id'));
        assert_eq([], $b['series'][0]['points']);
    }
}

function test_history_returns_points(): void
{
    $s = api_store();
    post_readings($s, [reading(ts: NOW - 60)]);
    $b = body(call($s, 'GET', '/api/history', ['sensors' => 'konyha', 'hours' => '24']));
    assert_eq([[intdiv(NOW - 60, 300) * 300, 22.56, 63.94]], $b['series'][0]['points']);
}

function test_history_bad_hours(): void
{
    assert_eq(400, call(api_store(), 'GET', '/api/history', ['sensors' => 'konyha', 'hours' => '12'])->status);
}

function test_history_unknown_sensor(): void
{
    assert_eq(400, call(api_store(), 'GET', '/api/history', ['sensors' => 'konyha,nope', 'hours' => '24'])->status);
}

function test_unknown_api_path(): void
{
    assert_eq(404, call(api_store(), 'GET', '/api/nothing')->status);
}

function test_static_path_returns_null(): void
{
    assert_eq(null, call(api_store(), 'GET', '/index.html'));
}

function test_api_needs_no_password_only_the_api_key(): void
{
    // The password for access from outside is nginx's job on krpi.
    $r = call(api_store(), 'POST', '/api/readings', [], '{"readings":[]}', ['x-api-key' => 'CHANGE_ME']);
    assert_eq(200, $r->status);
    assert_eq(200, call(api_store(), 'GET', '/api/config')->status);
}

function test_post_future_reading_returns_503_and_stores_nothing(): void
{
    // krpi has no hardware clock: after a power cut its clock can be behind
    // the tablet's. The tablet must keep such readings and retry later.
    $s = api_store();
    $r = post_readings($s, [reading(ts: NOW - 60), reading(ts: NOW + 301)]);
    assert_eq(503, $r->status);
    assert_true(str_contains(body($r)['error'], 'clock'), $r->body);
    assert_eq(null, $s->latest(KONYHA));
}

function test_post_slightly_ahead_is_accepted(): void
{
    $s = api_store();
    assert_eq(200, post_readings($s, [reading(ts: NOW + 300)])->status);
}
