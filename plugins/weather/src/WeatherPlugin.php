<?php

declare(strict_types=1);

/**
 * Weather for one place: a 24-hour precipitation forecast in 3-hour blocks
 * from Open-Meteo, and the rain radar of the last hour from HungaroMet's
 * open data (cut out around the place). Both services are free and need no
 * key. The answers are cached as files under cache_dir, so the dashboard's
 * refreshes do not reach the services every minute.
 */
final class WeatherPlugin implements Plugin
{
    public const BLOCKS = 8;
    public const BLOCK_HOURS = 3;
    public const FORECAST_TTL = 900;
    /** How long an old forecast is still shown when the service cannot be reached. */
    public const MAX_STALE = 6 * 3600;
    /** HungaroMet publishes a radar frame every 5 minutes; the last hour is shown. */
    public const RADAR_STEP = 300;
    public const RADAR_FRAMES = 12;
    /** Frame downloads per request, and the wait before asking again for a missing frame. */
    public const RADAR_ATTEMPTS = 4;
    public const RADAR_RETRY = 60;
    public const RADAR_KEEP = 3 * 3600;
    /** The cut-out reaches this much past radius_km, so a wide widget is still filled. */
    public const RADAR_MARGIN = 1.5;
    private const FORECAST_URL = 'https://api.open-meteo.com/v1/forecast';
    private const RADAR_URL = 'https://odp.met.hu/weather/radar/composite/nc/refl2D_pscappi/radar_composite-refl2D_pscappi-%s.nc.zip';
    private const RADAR_VAR = 'refl2D_pscappi';

    private array $section;
    /** @var Closure(string): ?string */
    private Closure $fetch;

    /**
     * $fetch returns the body of a URL, null when the server answers 404,
     * and throws on other failures. Tests pass a fake one.
     */
    public function __construct(array $section, ?Closure $fetch = null)
    {
        foreach (['latitude', 'longitude'] as $key) {
            if (!is_int($section[$key] ?? null) && !is_float($section[$key] ?? null)) {
                throw new RuntimeException("settings: plugins.weather: $key must be a number");
            }
        }
        $section['place'] ??= '';
        $section['radius_km'] ??= 50;
        $section['timezone'] ??= 'Europe/Budapest';
        $section['cache_dir'] ??= '/data/weather';
        $this->section = $section;
        $this->fetch = $fetch ?? self::httpGet(...);
    }

    public function routes(): array
    {
        return [
            '/api/weather/forecast' => $this->forecast(...),
            '/api/weather/radar' => $this->radar(...),
            '/api/weather/radar-frame' => $this->radarFrame(...),
        ];
    }

    public function publicConfig(): array
    {
        return [
            'place' => $this->section['place'],
            'latitude' => $this->section['latitude'],
            'longitude' => $this->section['longitude'],
            'radius_km' => $this->section['radius_km'],
        ];
    }

    private function forecast(Request $req, int $now): Response
    {
        $s = $this->section;
        $url = self::FORECAST_URL . '?' . http_build_query([
            'latitude' => $s['latitude'],
            'longitude' => $s['longitude'],
            'hourly' => 'precipitation,precipitation_probability,weather_code,is_day',
            'current' => 'temperature_2m,weather_code,is_day',
            'timezone' => $s['timezone'],
            'timeformat' => 'unixtime',
            // The current block may have started up to two hours ago.
            'past_hours' => self::BLOCK_HOURS,
            'forecast_hours' => self::BLOCKS * self::BLOCK_HOURS + self::BLOCK_HOURS,
        ]);
        $cached = $this->cached('forecast', $url, self::FORECAST_TTL, $now);
        if ($cached === null) {
            return Response::json(502, ['error' => 'forecast service unreachable']);
        }
        $data = $cached['data'];
        $current = $data['current'] ?? [];
        return Response::json(200, [
            'now' => $now,
            'fetched' => $cached['fetched'],
            'stale' => $cached['stale'],
            'place' => $s['place'],
            'current' => [
                'temperature_c' => $current['temperature_2m'] ?? null,
                'code' => $current['weather_code'] ?? null,
                'is_day' => (bool)($current['is_day'] ?? 1),
            ],
            'blocks' => self::blocks($data['hourly'] ?? [], $now, new DateTimeZone($s['timezone'])),
        ]);
    }

    /**
     * Groups Open-Meteo's hourly values into 3-hour blocks that start at
     * 0, 3, 6, ... o'clock local time, from the block that holds $now.
     * mm is the sum of the hours, probability the highest, and code the
     * highest weather code (higher WMO codes mean worse weather).
     */
    public static function blocks(array $hourly, int $now, DateTimeZone $tz): array
    {
        $blockStart = function (int $ts) use ($tz): int {
            $local = (new DateTimeImmutable("@$ts"))->setTimezone($tz);
            $hour = (int)$local->format('G');
            return $ts - (int)$local->format('i') * 60 - (int)$local->format('s') - ($hour % self::BLOCK_HOURS) * 3600;
        };
        $first = $blockStart($now);
        $groups = [];
        foreach ($hourly['time'] ?? [] as $i => $ts) {
            $start = $blockStart((int)$ts);
            if ($start < $first) {
                continue;
            }
            $groups[$start][] = [
                'mm' => $hourly['precipitation'][$i] ?? null,
                'probability' => $hourly['precipitation_probability'][$i] ?? null,
                'code' => $hourly['weather_code'][$i] ?? null,
                'is_day' => $hourly['is_day'][$i] ?? null,
            ];
        }
        ksort($groups);
        $blocks = [];
        foreach (array_slice($groups, 0, self::BLOCKS, true) as $start => $hours) {
            $values = fn(string $key) => array_values(array_filter(array_column($hours, $key), fn($v) => $v !== null));
            $mm = $values('mm');
            $probability = $values('probability');
            $code = $values('code');
            $isDay = $values('is_day');
            $blocks[] = [
                'start' => $start,
                'hour' => (int)(new DateTimeImmutable("@$start"))->setTimezone($tz)->format('G'),
                'mm' => $mm === [] ? null : round(array_sum($mm), 1),
                'probability' => $probability === [] ? null : (int)max($probability),
                'code' => $code === [] ? null : (int)max($code),
                // The middle hour decides between sun and moon.
                'is_day' => $isDay === [] ? true : (bool)$isDay[intdiv(count($isDay), 2)],
            ];
        }
        return $blocks;
    }

    /**
     * Lists the radar frames of the last hour. Frames that are not cached
     * yet are downloaded, a few per request, so one request never waits
     * long. The frame data itself comes from /api/weather/radar-frame.
     */
    private function radar(Request $req, int $now): Response
    {
        // A frame is published about 6 minutes after its time.
        $newest = intdiv($now - self::RADAR_STEP, self::RADAR_STEP) * self::RADAR_STEP;
        $attempts = 0;
        $times = [];
        $grid = null;
        for ($i = 0; $i < self::RADAR_FRAMES; $i++) {
            $time = $newest - $i * self::RADAR_STEP;
            $frame = $this->readFrame($time);
            if ($frame === null && $attempts < self::RADAR_ATTEMPTS && !$this->recentlyMissed($time, $now)) {
                $attempts++;
                $frame = $this->fetchFrame($time, $now, $attempts);
            }
            if ($frame !== null) {
                $times[] = $time;
                $grid ??= $frame['grid'];
            }
        }
        $this->cleanUp($now);
        sort($times);
        return Response::json(200, ['now' => $now, 'grid' => $grid, 'frames' => $times]);
    }

    private function radarFrame(Request $req, int $now): Response
    {
        $frame = $this->readFrame((int)($req->query['time'] ?? 0));
        if ($frame === null) {
            return Response::json(404, ['error' => 'no such frame']);
        }
        return Response::json(200, ['time' => (int)$req->query['time'], 'rle' => $frame['rle']]);
    }

    private function radarDir(): string
    {
        return $this->section['cache_dir'] . '/radar';
    }

    /** The cut-out depends on these settings, so they are part of the file name. */
    private function frameFile(int $time): string
    {
        $s = $this->section;
        $key = substr(md5(json_encode([$s['latitude'], $s['longitude'], $s['radius_km']])), 0, 8);
        return $this->radarDir() . "/$time-$key.json";
    }

    private function readFrame(int $time): ?array
    {
        $file = $this->frameFile($time);
        $frame = is_file($file) ? json_decode((string)file_get_contents($file), true) : null;
        return is_array($frame) ? $frame : null;
    }

    private function recentlyMissed(int $time, int $now): bool
    {
        $file = $this->radarDir() . "/$time.miss";
        return is_file($file) && $now - (int)file_get_contents($file) < self::RADAR_RETRY;
    }

    /**
     * Downloads one frame, cuts out the area around the place, and caches
     * it. A missing frame (not published yet) is tried again after
     * RADAR_RETRY seconds. Any other failure also sets $attempts to the
     * limit, so a dead service costs one timeout per request, not several.
     */
    private function fetchFrame(int $time, int $now, int &$attempts): ?array
    {
        $s = $this->section;
        $url = sprintf(self::RADAR_URL, gmdate('Ymd_Hi', $time));
        try {
            $zip = ($this->fetch)($url);
            if ($zip === null) {
                $this->writeFile($this->radarDir() . "/$time.miss", (string)$now);
                return null;
            }
            $grid = RadarFile::crop(
                RadarFile::grid(RadarFile::unzip($zip), self::RADAR_VAR),
                (float)$s['latitude'],
                (float)$s['longitude'],
                $s['radius_km'] * self::RADAR_MARGIN,
            );
            $frame = ['rle' => base64_encode(RadarFile::rle($grid['data']))];
            unset($grid['data']);
            $frame['grid'] = $grid;
            $this->writeFile($this->frameFile($time), json_encode($frame, JSON_THROW_ON_ERROR));
            return $frame;
        } catch (Throwable $e) {
            error_log('weather: radar ' . gmdate('Y-m-d H:i', $time) . ': ' . $e->getMessage());
            $attempts = self::RADAR_ATTEMPTS;
            try {
                $this->writeFile($this->radarDir() . "/$time.miss", (string)$now);
            } catch (Throwable) {
            }
            return null;
        }
    }

    /** Deletes frames and miss markers older than RADAR_KEEP seconds. */
    private function cleanUp(int $now): void
    {
        foreach (glob($this->radarDir() . '/*') ?: [] as $file) {
            if ((int)basename($file) < $now - self::RADAR_KEEP) {
                @unlink($file);
            }
        }
    }

    /**
     * Returns the decoded answer of $url, fetched at most $ttl seconds ago.
     * When the service cannot be reached, an older answer (up to MAX_STALE)
     * is returned with stale = true. Returns null when there is none.
     *
     * @return array{data: array, fetched: int, stale: bool}|null
     */
    private function cached(string $name, string $url, int $ttl, int $now): ?array
    {
        $file = $this->section['cache_dir'] . "/$name.json";
        $old = is_file($file) ? json_decode((string)file_get_contents($file), true) : null;
        if (is_array($old) && ($old['url'] ?? null) === $url && $now - $old['fetched'] < $ttl) {
            return ['data' => $old['data'], 'fetched' => $old['fetched'], 'stale' => false];
        }
        try {
            $data = json_decode((string)($this->fetch)($url), true);
            if (!is_array($data)) {
                throw new RuntimeException('answer is not JSON');
            }
        } catch (Throwable $e) {
            error_log("weather: $name: " . $e->getMessage());
            $data = null;
        }
        if ($data !== null) {
            try {
                $this->writeFile($file, json_encode(['url' => $url, 'fetched' => $now, 'data' => $data], JSON_THROW_ON_ERROR));
            } catch (Throwable $e) {
                error_log("weather: $name: " . $e->getMessage());
            }
            return ['data' => $data, 'fetched' => $now, 'stale' => false];
        }
        if (is_array($old) && ($old['url'] ?? null) === $url && $now - $old['fetched'] < self::MAX_STALE) {
            return ['data' => $old['data'], 'fetched' => $old['fetched'], 'stale' => true];
        }
        return null;
    }

    /** Writes through a temp file, so a reader never sees half a file. */
    private function writeFile(string $file, string $content): void
    {
        $dir = dirname($file);
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException("cannot create $dir");
        }
        $tmp = "$file.tmp";
        if (file_put_contents($tmp, $content) === false
            || !rename($tmp, $file)) {
            throw new RuntimeException("cannot write $file");
        }
    }

    /** php -S answers one request at a time, so a slow service must not hold it long. */
    private static function httpGet(string $url): ?string
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 4,
            CURLOPT_TIMEOUT => 8,
            CURLOPT_USERAGENT => 'homeassistant-hub',
        ]);
        $body = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error = curl_error($ch);
        if ($body !== false && $status === 404) {
            return null;
        }
        if ($body === false || $status !== 200) {
            throw new RuntimeException("GET $url failed: " . ($error !== '' ? $error : "HTTP $status"));
        }
        return (string)$body;
    }
}
