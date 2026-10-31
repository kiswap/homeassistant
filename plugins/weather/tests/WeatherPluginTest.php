<?php

declare(strict_types=1);

// 2026-10-31 17:40 in Budapest (16:40 UTC).
const W_NOW = 1793464800;

function w_cache_dir(): string
{
    $dir = sys_get_temp_dir() . '/weather-test-' . bin2hex(random_bytes(4));
    mkdir($dir);
    return $dir;
}

function w_section(string $dir): array
{
    return ['latitude' => 47.4979, 'longitude' => 19.0402, 'place' => 'Budapest', 'cache_dir' => $dir];
}

function w_call(WeatherPlugin $plugin, string $path, array $query = [], int $now = W_NOW): Response
{
    $hub = new Hub(['refresh_seconds' => 60, 'layout' => []], ['weather' => $plugin]);
    return $hub->handle(new Request('GET', $path, $query, [], ''), $now);
}

function w_body(Response $r): array
{
    return json_decode($r->body, true);
}

/**
 * Open-Meteo's answer: 27 hours from 15:00 local time, as unix times. The
 * sun sets at 16:25 and rises at 06:30, unless $sun is false.
 */
function w_open_meteo(bool $sun = true): string
{
    $start = W_NOW - 2 * 3600 - 40 * 60; // 15:00 local
    $hourly = ['time' => [], 'precipitation' => [], 'precipitation_probability' => [], 'weather_code' => [], 'is_day' => []];
    for ($i = 0; $i < 27; $i++) {
        $hourly['time'][] = $start + $i * 3600;
        $hourly['precipitation'][] = $i < 3 ? 0.4 : 0.0;
        $hourly['precipitation_probability'][] = $i === 1 ? 70 : 10;
        $hourly['weather_code'][] = $i === 2 ? 61 : 3;
        $hourly['is_day'][] = $i < 2 ? 1 : 0;
        $hourly['temperature_2m'][] = 10.0 + $i / 2;
    }
    $daily = [
        'sunrise' => [$start - 8.5 * 3600, $start + 15.5 * 3600],
        'sunset' => [$start + 85 * 60, $start + 24 * 3600 + 85 * 60],
    ];
    return json_encode([
        'current' => ['temperature_2m' => 12.5, 'weather_code' => 3, 'is_day' => 0],
        'hourly' => $hourly,
    ] + ($sun ? ['daily' => $daily] : []));
}

function test_settings_need_coordinates(): void
{
    try {
        new WeatherPlugin(['latitude' => 47.5]);
        assert_true(false, 'expected an exception');
    } catch (RuntimeException $e) {
        assert_eq('settings: plugins.weather: longitude must be a number', $e->getMessage());
    }
}

function test_public_config(): void
{
    $plugin = new WeatherPlugin(w_section(w_cache_dir()));
    assert_eq(
        ['place' => 'Budapest', 'latitude' => 47.4979, 'longitude' => 19.0402, 'radius_km' => 50],
        $plugin->publicConfig(),
    );
}

function test_forecast_groups_hours_into_3_hour_blocks(): void
{
    $plugin = new WeatherPlugin(w_section(w_cache_dir()), fn(string $url) => w_open_meteo());
    $r = w_call($plugin, '/api/weather/forecast');
    assert_eq(200, $r->status);
    $data = w_body($r);
    assert_eq(12.5, $data['current']['temperature_c']);
    assert_eq(8, count($data['blocks']));
    $first = $data['blocks'][0];
    assert_eq(15, $first['hour'], 'the block holding 17:40 starts at 15:00');
    assert_eq(1.2, $first['mm'], 'sum of the 3 hours');
    assert_eq(70, $first['probability'], 'highest of the 3 hours');
    assert_eq(61, $first['code'], 'worst weather code');
    assert_eq(false, $first['is_day'], 'from 17:40 to 18:00 the sun is down');
    assert_eq(18, $data['blocks'][1]['hour']);
    assert_eq(0.0, (float)$data['blocks'][1]['mm']);
    assert_eq(false, $data['blocks'][1]['is_day']);
    assert_eq(12, $data['blocks'][7]['hour']);
    assert_eq(true, $data['blocks'][5]['is_day'], '06-09: sun up from 06:30');
    assert_eq(true, $data['blocks'][7]['is_day'], '12-15');
    assert_eq(24, count($data['temperatures']), 'one per hour of the 8 blocks');
    assert_eq(['ts' => W_NOW - 2 * 3600 - 40 * 60, 'c' => 10], $data['temperatures'][0]);
}

function test_forecast_without_sun_times_uses_the_middle_hour(): void
{
    $plugin = new WeatherPlugin(w_section(w_cache_dir()), fn(string $url) => w_open_meteo(false));
    $data = w_body(w_call($plugin, '/api/weather/forecast'));
    assert_eq(true, $data['blocks'][0]['is_day']);
    assert_eq(false, $data['blocks'][1]['is_day']);
}

function test_forecast_is_cached(): void
{
    $calls = 0;
    $plugin = new WeatherPlugin(w_section(w_cache_dir()), function (string $url) use (&$calls) {
        $calls++;
        return w_open_meteo();
    });
    w_call($plugin, '/api/weather/forecast');
    w_call($plugin, '/api/weather/forecast', [], W_NOW + WeatherPlugin::FORECAST_TTL - 1);
    assert_eq(1, $calls);
    w_call($plugin, '/api/weather/forecast', [], W_NOW + WeatherPlugin::FORECAST_TTL);
    assert_eq(2, $calls);
}

function test_forecast_falls_back_to_old_answer(): void
{
    $dir = w_cache_dir();
    w_call(new WeatherPlugin(w_section($dir), fn(string $url) => w_open_meteo()), '/api/weather/forecast');
    $down = new WeatherPlugin(w_section($dir), fn(string $url) => throw new RuntimeException('offline'));
    $r = w_call($down, '/api/weather/forecast', [], W_NOW + 3600);
    assert_eq(200, $r->status);
    assert_eq(true, w_body($r)['stale']);
    assert_eq(W_NOW, w_body($r)['fetched']);
    $r = w_call($down, '/api/weather/forecast', [], W_NOW + WeatherPlugin::MAX_STALE);
    assert_eq(502, $r->status, 'too old to show');
}

function test_forecast_without_any_answer_is_502(): void
{
    $plugin = new WeatherPlugin(w_section(w_cache_dir()), fn(string $url) => null);
    assert_eq(502, w_call($plugin, '/api/weather/forecast')->status);
}

/** A HungaroMet frame where the cell at Budapest has the value $value. */
function w_radar_zip(int $value): string
{
    // A 200 x 200 grid starting 0.5 degrees north-west of Budapest.
    $rows = $cols = 200;
    $data = str_repeat("\0", $rows * $cols);
    // A 3 x 3 patch, so rounding cannot miss it.
    for ($row = 61; $row <= 63; $row++) {
        for ($col = 39; $col <= 41; $col++) {
            $data[$row * $cols + $col] = chr($value);
        }
    }
    return make_zip(make_nc($rows, $cols, $data, 47.9979, 18.5402));
}

function w_radar_url_time(string $url): int
{
    preg_match('/-(\d{8}_\d{4})\.nc\.zip$/', $url, $m);
    return DateTimeImmutable::createFromFormat('Ymd_Hi', $m[1], new DateTimeZone('UTC'))->getTimestamp();
}

function test_radar_lists_frames_of_the_last_hour(): void
{
    $urls = [];
    $plugin = new WeatherPlugin(w_section(w_cache_dir()), function (string $url) use (&$urls) {
        $urls[] = $url;
        // The newest frame (16:35 UTC) is not published yet.
        return w_radar_url_time($url) >= W_NOW - 300 ? null : w_radar_zip(100);
    });
    $data = w_body(w_call($plugin, '/api/weather/radar'));
    assert_eq(WeatherPlugin::RADAR_ATTEMPTS, count($urls), 'a few downloads per request');
    assert_eq(
        'https://odp.met.hu/weather/radar/composite/nc/refl2D_pscappi/radar_composite-refl2D_pscappi-20261031_1635.nc.zip',
        $urls[0],
    );
    assert_eq(3, count($data['frames']));
    assert_eq(W_NOW - 600, end($data['frames']), 'newest published frame is 16:30 UTC');

    // Later requests fetch the rest; the missing frame waits RADAR_RETRY seconds.
    for ($i = 1; $i <= 2; $i++) {
        $data = w_body(w_call($plugin, '/api/weather/radar', [], W_NOW + $i));
    }
    assert_eq(WeatherPlugin::RADAR_FRAMES - 1, count($data['frames']));
    $before = count($urls);
    w_call($plugin, '/api/weather/radar', [], W_NOW + 3);
    assert_eq($before, count($urls), 'everything is cached');
    w_call($plugin, '/api/weather/radar', [], W_NOW + WeatherPlugin::RADAR_RETRY);
    assert_eq(W_NOW - 300, w_radar_url_time(end($urls)), 'the missing frame is tried again');
}

function test_radar_frame_holds_the_cut_out(): void
{
    $plugin = new WeatherPlugin(w_section(w_cache_dir()), fn(string $url) => w_radar_zip(100));
    $list = w_body(w_call($plugin, '/api/weather/radar'));
    $grid = $list['grid'];
    // radius 50 km * 1.5 is about 85 rows each way, but the grid ends 63 rows north.
    assert_true($grid['rows'] > 140 && $grid['rows'] < 160, "rows {$grid['rows']}");
    $r = w_call($plugin, '/api/weather/radar-frame', ['time' => (string)end($list['frames'])]);
    assert_eq(200, $r->status);
    $rle = base64_decode(w_body($r)['rle']);
    $cells = '';
    for ($i = 0; $i < strlen($rle); $i += 2) {
        $cells .= str_repeat($rle[$i + 1], ord($rle[$i]));
    }
    assert_eq($grid['rows'] * $grid['cols'], strlen($cells));
    $row = (int)round(($grid['north'] - 47.4979) / $grid['dlat']);
    $col = (int)round((19.0402 - $grid['west']) / $grid['dlon']);
    assert_eq(100, ord($cells[$row * $grid['cols'] + $col]), 'Budapest cell');
}

function test_radar_frame_unknown_time_is_404(): void
{
    $plugin = new WeatherPlugin(w_section(w_cache_dir()), fn(string $url) => null);
    assert_eq(404, w_call($plugin, '/api/weather/radar-frame', ['time' => '123'])->status);
}

function test_radar_stops_after_a_failure(): void
{
    $calls = 0;
    $plugin = new WeatherPlugin(w_section(w_cache_dir()), function (string $url) use (&$calls) {
        $calls++;
        throw new RuntimeException('timeout');
    });
    $data = w_body(w_call($plugin, '/api/weather/radar'));
    assert_eq(1, $calls, 'one timeout per request, not four');
    assert_eq([], $data['frames']);
    assert_eq(null, $data['grid']);
}
