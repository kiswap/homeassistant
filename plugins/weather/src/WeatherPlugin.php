<?php

declare(strict_types=1);

/**
 * Weather for one place: a 24-hour precipitation forecast in 3-hour blocks
 * from Open-Meteo, and the rain radar of the last hour plus a 2-hour radar
 * forecast from HungaroMet's open data (cut out around the place). Both services are free and need no
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
    /**
     * MEANDER, HungaroMet's nowcast: one run per hour, published about 10
     * minutes after the hour, with radar frames every 15 minutes for 2 hours.
     */
    public const FORECAST_STEP = 900;
    public const FORECAST_FRAMES = 8;
    public const FORECAST_DELAY = 600;
    private const FORECAST_RADAR_URL = 'https://odp.met.hu/weather/nwp/MEANDER/nc/%s/MEANDER-maxRadSig-%s+0%02d%02d.nc.zip';
    private const FORECAST_VAR = 'maxRadSig';

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
            'hourly' => 'precipitation,precipitation_probability,weather_code,is_day,temperature_2m',
            'current' => 'temperature_2m,weather_code,is_day',
            'daily' => 'sunrise,sunset',
            'forecast_days' => 3,
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
        ] + self::blocks($data['hourly'] ?? [], $data['daily'] ?? [], $now, new DateTimeZone($s['timezone'])));
    }

    /**
     * Groups Open-Meteo's hourly values into 3-hour blocks that start at
     * 0, 3, 6, ... o'clock local time, from the block that holds $now.
     * mm is the sum of the hours, probability the highest, and code the
     * highest weather code (higher WMO codes mean worse weather).
     * A block is day when the sun is up for most of its time from now on.
     * Also returns the hourly temperatures of the blocks, for the curve.
     *
     * @return array{blocks: array, temperatures: array}
     */
    public static function blocks(array $hourly, array $daily, int $now, DateTimeZone $tz): array
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
                'ts' => (int)$ts,
                'temperature' => $hourly['temperature_2m'][$i] ?? null,
            ];
        }
        ksort($groups);
        $blocks = [];
        $temperatures = [];
        foreach (array_slice($groups, 0, self::BLOCKS, true) as $start => $hours) {
            foreach ($hours as $h) {
                if ($h['temperature'] !== null) {
                    $temperatures[] = ['ts' => $h['ts'], 'c' => $h['temperature']];
                }
            }
            $values = fn(string $key) => array_values(array_filter(array_column($hours, $key), fn($v) => $v !== null));
            $mm = $values('mm');
            $probability = $values('probability');
            $code = $values('code');
            $isDay = $values('is_day');
            $from = max($start, $now);
            $to = $start + count($hours) * 3600;
            $daylight = self::daylight($daily, $from, $to);
            $blocks[] = [
                'start' => $start,
                'hour' => (int)(new DateTimeImmutable("@$start"))->setTimezone($tz)->format('G'),
                'mm' => $mm === [] ? null : round(array_sum($mm), 1),
                'probability' => $probability === [] ? null : (int)max($probability),
                'code' => $code === [] ? null : (int)max($code),
                // Without sunrise and sunset times, the middle hour decides.
                'is_day' => $daylight !== null
                    ? 2 * $daylight >= $to - $from
                    : ($isDay === [] ? true : (bool)$isDay[intdiv(count($isDay), 2)]),
            ];
        }
        return ['blocks' => $blocks, 'temperatures' => $temperatures];
    }

    /** Seconds of daylight between $from and $to, or null without sunrise times. */
    private static function daylight(array $daily, int $from, int $to): ?int
    {
        $rises = $daily['sunrise'] ?? [];
        if ($rises === []) {
            return null;
        }
        $seconds = 0;
        foreach ($rises as $i => $rise) {
            $set = $daily['sunset'][$i] ?? null;
            if ($rise !== null && $set !== null) {
                $seconds += max(0, min($to, (int)$set) - max($from, (int)$rise));
            }
        }
        return $seconds;
    }

    /**
     * Lists the radar frames of the last hour, then the forecast frames of
     * the newest MEANDER run that come after them. Frames that are not
     * cached yet are downloaded, a few per request, so one request never
     * waits long. The frame data itself comes from /api/weather/radar-frame.
     * A forecast frame has the same grid and byte values as a measured one,
     * so the dashboard draws both the same way.
     */
    private function radar(Request $req, int $now): Response
    {
        // A frame is published about 6 minutes after its time.
        $newest = intdiv($now - self::RADAR_STEP, self::RADAR_STEP) * self::RADAR_STEP;
        $attempts = 0;
        $times = [];
        $grid = null;
        $s = $this->section;
        $crop = fn(string $nc) => RadarFile::crop(
            RadarFile::grid($nc, self::RADAR_VAR),
            (float)$s['latitude'],
            (float)$s['longitude'],
            $s['radius_km'] * self::RADAR_MARGIN,
        );
        for ($i = 0; $i < self::RADAR_FRAMES; $i++) {
            $time = $newest - $i * self::RADAR_STEP;
            $url = sprintf(self::RADAR_URL, gmdate('Ymd_Hi', $time));
            $frame = $this->frame((string)$time, $url, $crop, $now, $attempts);
            if ($frame !== null) {
                $times[] = $time;
                $grid ??= $frame['grid'];
            }
        }
        sort($times);
        $frames = array_map(fn(int $t) => ['time' => $t], $times);
        if ($grid !== null) {
            $latest = $times === [] ? 0 : end($times);
            array_push($frames, ...$this->forecastFrames($grid, $latest, $now, $attempts));
        }
        $this->cleanUp($now);
        return Response::json(200, ['now' => $now, 'grid' => $grid, 'frames' => $frames]);
    }

    /**
     * The frames of the newest MEANDER run that has any, later than
     * $latest (the newest measured frame). A run is tried in order and
     * stops at the first frame it cannot get, so an unpublished run costs
     * one download per request; then the run before it is used.
     *
     * @return list<array{time: int, run: int}>
     */
    private function forecastFrames(array $grid, int $latest, int $now, int &$attempts): array
    {
        $toGrid = fn(string $nc) => ['data' => RadarFile::resample(RadarFile::forecastGrid($nc, self::FORECAST_VAR), $grid)] + $grid;
        $run = intdiv($now - self::FORECAST_DELAY, 3600) * 3600;
        foreach ([$run, $run - 3600] as $run) {
            $found = [];
            for ($k = 1; $k <= self::FORECAST_FRAMES; $k++) {
                $offset = $k * self::FORECAST_STEP;
                $time = $run + $offset;
                if ($time <= $latest) {
                    continue;
                }
                $url = sprintf(
                    self::FORECAST_RADAR_URL,
                    gmdate('H', $run),
                    gmdate('Ymd_Hi', $run),
                    intdiv($offset, 3600),
                    intdiv($offset % 3600, 60),
                );
                if ($this->frame("$time-f$run", $url, $toGrid, $now, $attempts) === null) {
                    break;
                }
                $found[] = ['time' => $time, 'run' => $run];
            }
            if ($found !== []) {
                return $found;
            }
        }
        return [];
    }

    private function radarFrame(Request $req, int $now): Response
    {
        $time = (int)($req->query['time'] ?? 0);
        $name = isset($req->query['run']) ? "$time-f" . (int)$req->query['run'] : (string)$time;
        $frame = $this->readFrame($name);
        if ($frame === null) {
            return Response::json(404, ['error' => 'no such frame']);
        }
        return Response::json(200, ['time' => $time, 'rle' => $frame['rle']]);
    }

    private function radarDir(): string
    {
        return $this->section['cache_dir'] . '/radar';
    }

    /**
     * A frame's name starts with its time ("<time>" measured,
     * "<time>-f<run>" forecast). The cut-out depends on the settings, so
     * they are part of the file name.
     */
    private function frameFile(string $name): string
    {
        $s = $this->section;
        $key = substr(md5(json_encode([$s['latitude'], $s['longitude'], $s['radius_km']])), 0, 8);
        return $this->radarDir() . "/$name-$key.json";
    }

    private function readFrame(string $name): ?array
    {
        $file = $this->frameFile($name);
        $frame = is_file($file) ? json_decode((string)file_get_contents($file), true) : null;
        return is_array($frame) ? $frame : null;
    }

    private function recentlyMissed(string $name, int $now): bool
    {
        $file = $this->radarDir() . "/$name.miss";
        return is_file($file) && $now - (int)file_get_contents($file) < self::RADAR_RETRY;
    }

    /**
     * Returns a cached frame, or downloads it when the request still has
     * attempts left. $toGrid turns the netCDF file into a cut-out (grid
     * keys plus data). A missing file (not published yet) is tried again
     * after RADAR_RETRY seconds. Any other failure also sets $attempts to
     * the limit, so a dead service costs one timeout per request.
     */
    private function frame(string $name, string $url, Closure $toGrid, int $now, int &$attempts): ?array
    {
        $frame = $this->readFrame($name);
        if ($frame !== null) {
            return $frame;
        }
        if ($attempts >= self::RADAR_ATTEMPTS || $this->recentlyMissed($name, $now)) {
            return null;
        }
        $attempts++;
        $miss = $this->radarDir() . "/$name.miss";
        try {
            $zip = ($this->fetch)($url);
            if ($zip === null) {
                $this->writeFile($miss, (string)$now);
                return null;
            }
            $grid = $toGrid(RadarFile::unzip($zip));
            $frame = ['rle' => base64_encode(RadarFile::rle($grid['data']))];
            unset($grid['data']);
            $frame['grid'] = $grid;
            $this->writeFile($this->frameFile($name), json_encode($frame, JSON_THROW_ON_ERROR));
            return $frame;
        } catch (Throwable $e) {
            error_log("weather: radar $name: " . $e->getMessage());
            $attempts = self::RADAR_ATTEMPTS;
            try {
                $this->writeFile($miss, (string)$now);
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
