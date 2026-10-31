<?php

declare(strict_types=1);

function r(string $address, int $ts, float $temp, float $hum): array
{
    return [
        'address' => $address,
        'ts' => $ts,
        'temperature_c' => $temp,
        'humidity_pct' => $hum,
        'voltage_mv' => 2900,
        'battery_pct' => 90,
    ];
}

function test_insert_and_latest(): void
{
    $s = new ReadingStore(Db::open(':memory:'));
    assert_true($s->insert(r('70:F7:00:00:14:63', 1000, 22.5, 60.0)));
    assert_true($s->insert(r('70:F7:00:00:14:63', 1060, 22.7, 61.0)));
    $latest = $s->latest('70:F7:00:00:14:63');
    assert_eq(1060, $latest['ts']);
    assert_eq(22.7, $latest['temperature_c']);
    assert_eq(61.0, $latest['humidity_pct']);
    assert_eq(2900, $latest['voltage_mv']);
    assert_eq(90, $latest['battery_pct']);
}

function test_duplicate_is_ignored(): void
{
    $s = new ReadingStore(Db::open(':memory:'));
    $s->insert(r('A', 1000, 1.0, 2.0));
    assert_eq(false, $s->insert(r('A', 1000, 9.0, 9.0)));
    assert_eq(1.0, $s->latest('A')['temperature_c']);
}

function test_latest_empty(): void
{
    assert_eq(null, (new ReadingStore(Db::open(':memory:')))->latest('A'));
}

function test_history_buckets_average(): void
{
    $s = new ReadingStore(Db::open(':memory:'));
    for ($i = 0; $i < 10; $i++) {
        $s->insert(r('A', $i * 60, (float)$i, 50.0 + $i));
    }
    assert_eq([[0, 2.0, 52.0], [300, 7.0, 57.0]], $s->history('A', 0, 300));
}

function test_history_from_excludes_older(): void
{
    $s = new ReadingStore(Db::open(':memory:'));
    $s->insert(r('A', 100, 5.0, 50.0));
    $s->insert(r('A', 700, 6.0, 60.0));
    $s->insert(r('B', 700, 9.0, 90.0));
    assert_eq([[600, 6.0, 60.0]], $s->history('A', 600, 300));
}

function test_history_negative_temps(): void
{
    $s = new ReadingStore(Db::open(':memory:'));
    $s->insert(r('A', 0, -3.5, 80.0));
    $s->insert(r('A', 60, -4.5, 90.0));
    assert_eq([[0, -4.0, 85.0]], $s->history('A', 0, 300));
}

function test_history_empty(): void
{
    assert_eq([], (new ReadingStore(Db::open(':memory:')))->history('A', 0, 300));
}

function test_meta_roundtrip(): void
{
    $s = new ReadingStore(Db::open(':memory:'));
    assert_eq(null, $s->getMeta('x'));
    $s->setMeta('x', '1');
    $s->setMeta('x', '2');
    assert_eq('2', $s->getMeta('x'));
}

function test_db_file_persists(): void
{
    $path = sys_get_temp_dir() . '/tb-test-' . bin2hex(random_bytes(4)) . '.sqlite';
    (new ReadingStore(Db::open($path)))->insert(r('A', 1, 1.0, 1.0));
    assert_eq(1, (new ReadingStore(Db::open($path)))->latest('A')['ts']);
    assert_eq('wal', Db::open($path)->query('PRAGMA journal_mode')->fetchColumn());
}
