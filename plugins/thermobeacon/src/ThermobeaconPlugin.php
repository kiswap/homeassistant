<?php

declare(strict_types=1);

/**
 * Receives the readings that the ThermoBeacon collector app on the tablet
 * uploads, stores them in SQLite, and serves them to the dashboard. The
 * app's request format is in ../thermobeacon/docs/upload-format.md.
 */
final class ThermobeaconPlugin implements Plugin
{
    public const MAX_BATCH = 500;
    public const MAX_FUTURE_SECONDS = 300;
    /** Graph range in hours => bucket size in seconds (about 300 points). */
    public const RANGES = [24 => 300, 48 => 600, 168 => 1800];
    private const REQUIRED = ['api_key', 'db_path', 'sensors'];

    private array $section;

    /** The store is opened on first use when none is given (tests pass one). */
    public function __construct(array $section, private ?ReadingStore $store = null)
    {
        foreach (self::REQUIRED as $key) {
            if (!array_key_exists($key, $section)) {
                throw new RuntimeException("settings: plugins.thermobeacon: missing key $key");
            }
        }
        $section['stale_after_seconds'] ??= 600;
        foreach ($section['sensors'] as $i => $sensor) {
            foreach (['id', 'name', 'address'] as $field) {
                if (!isset($sensor[$field])) {
                    throw new RuntimeException("settings: plugins.thermobeacon: sensors[$i] needs $field");
                }
            }
            $section['sensors'][$i]['address'] = strtoupper($sensor['address']);
            $section['sensors'][$i]['icon'] ??= '';
        }
        $this->section = $section;
    }

    public function routes(): array
    {
        // The app posts to /api/readings, so that path stays as it was.
        $readings = fn(Request $req, int $now) => $req->method === 'POST'
            ? $this->postReadings($req, $now)
            : Response::json(405, ['error' => 'use POST']);
        return [
            '/api/readings' => $readings,
            '/api/thermobeacon/readings' => $readings,
            '/api/thermobeacon/sensors' => $this->sensors(...),
            '/api/thermobeacon/history' => $this->history(...),
        ];
    }

    public function publicConfig(): array
    {
        return [
            'stale_after_seconds' => $this->section['stale_after_seconds'],
            'sensors' => array_map(self::publicSensor(...), $this->section['sensors']),
        ];
    }

    /** Map of upper-case BLE address to sensor settings. */
    public function sensorByAddress(): array
    {
        return array_column($this->section['sensors'], null, 'address');
    }

    /** Map of sensor id to sensor settings. */
    public function sensorById(): array
    {
        return array_column($this->section['sensors'], null, 'id');
    }

    private function store(): ReadingStore
    {
        return $this->store ??= new ReadingStore(Db::open($this->section['db_path']));
    }

    private function postReadings(Request $req, int $now): Response
    {
        $key = $req->headers['x-api-key'] ?? '';
        if (!hash_equals((string)$this->section['api_key'], $key)) {
            return Response::json(401, ['error' => 'bad api key']);
        }
        $data = json_decode($req->body, true);
        if (!is_array($data) || !isset($data['readings']) || !is_array($data['readings'])
            || !array_is_list($data['readings'])) {
            return Response::json(400, ['error' => 'body must be {"readings":[...]}']);
        }
        if (count($data['readings']) > self::MAX_BATCH) {
            return Response::json(413, ['error' => 'at most ' . self::MAX_BATCH . ' readings per request']);
        }

        // A reading from the future usually means krpi's clock is behind
        // (it has no hardware clock). Store nothing and ask the tablet to
        // retry, so the readings stay in its queue instead of being lost.
        foreach ($data['readings'] as $r) {
            if (is_array($r) && is_int($r['ts'] ?? null) && $r['ts'] > $now + self::MAX_FUTURE_SECONDS) {
                error_log('thermobeacon: reading from the future, server clock may be behind');
                return Response::json(503, ['error' => 'server clock is behind the tablet; retry later']);
            }
        }

        $known = $this->sensorByAddress();
        $store = $this->store();
        $counts = ['stored' => 0, 'duplicates' => 0, 'ignored' => 0, 'rejected' => 0];
        $store->transaction(function () use ($data, $known, $store, $now, &$counts) {
            foreach ($data['readings'] as $r) {
                $reading = self::validReading($r, $now);
                if ($reading === null) {
                    $counts['rejected']++;
                } elseif (!isset($known[$reading['address']])) {
                    $counts['ignored']++;
                } elseif ($store->insert($reading)) {
                    $counts['stored']++;
                } else {
                    $counts['duplicates']++;
                }
            }
            $store->setMeta('collector_last_seen', (string)$now);
        });
        if ($counts['rejected'] > 0 || $counts['ignored'] > 0) {
            error_log("thermobeacon: batch had {$counts['rejected']} rejected, {$counts['ignored']} ignored readings");
        }
        return Response::json(200, $counts);
    }

    /** Returns the normalized reading, or null when it must be rejected. */
    private static function validReading(mixed $r, int $now): ?array
    {
        if (!is_array($r) || !is_string($r['address'] ?? null) || !is_int($r['ts'] ?? null)) {
            return null;
        }
        $address = strtoupper($r['address']);
        if (preg_match('/^[0-9A-F]{2}(:[0-9A-F]{2}){5}$/', $address) !== 1) {
            return null;
        }
        foreach (['temperature_c', 'humidity_pct', 'voltage_mv', 'battery_pct'] as $field) {
            if (!is_int($r[$field] ?? null) && !is_float($r[$field] ?? null)) {
                return null;
            }
        }
        return [
            'address' => $address,
            'ts' => $r['ts'],
            'temperature_c' => (float)$r['temperature_c'],
            'humidity_pct' => (float)$r['humidity_pct'],
            'voltage_mv' => (int)$r['voltage_mv'],
            'battery_pct' => (int)$r['battery_pct'],
        ];
    }

    private static function publicSensor(array $sensor): array
    {
        return ['id' => $sensor['id'], 'name' => $sensor['name'], 'icon' => $sensor['icon']];
    }

    private function sensors(Request $req, int $now): Response
    {
        $sensors = $this->section['sensors'];
        $store = $this->store();
        if (isset($req->query['id'])) {
            $byId = $this->sensorById();
            if (!isset($byId[$req->query['id']])) {
                return Response::json(400, ['error' => 'unknown sensor id']);
            }
            $sensors = [$byId[$req->query['id']]];
        }
        $lastSeen = $store->getMeta('collector_last_seen');
        return Response::json(200, [
            'now' => $now,
            'collector_last_seen' => $lastSeen === null ? null : (int)$lastSeen,
            'sensors' => array_map(
                fn(array $s) => self::publicSensor($s) + ['latest' => $store->latest($s['address'])],
                $sensors,
            ),
        ]);
    }

    private function history(Request $req, int $now): Response
    {
        $hours = (int)($req->query['hours'] ?? 0);
        if (!isset(self::RANGES[$hours])) {
            return Response::json(400, ['error' => 'hours must be 24, 48 or 168']);
        }
        $byId = $this->sensorById();
        $ids = array_values(array_filter(explode(',', (string)($req->query['sensors'] ?? ''))));
        foreach ($ids as $id) {
            if (!isset($byId[$id])) {
                return Response::json(400, ['error' => "unknown sensor id: $id"]);
            }
        }
        $bucket = self::RANGES[$hours];
        $from = $now - $hours * 3600;
        return Response::json(200, [
            'from' => $from,
            'to' => $now,
            'bucket_seconds' => $bucket,
            'series' => array_map(
                fn(string $id) => ['id' => $id, 'points' => $this->store()->history($byId[$id]['address'], $from, $bucket)],
                $ids,
            ),
        ]);
    }
}
