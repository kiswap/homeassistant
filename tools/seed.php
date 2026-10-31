<?php
// Fills a database with fake history for trying out the dashboard.
// Usage: php tools/seed.php <settings.json> <days>
// One reading per minute per configured sensor, ending now.

declare(strict_types=1);

foreach ([...glob(__DIR__ . '/../core/*.php'), ...glob(__DIR__ . '/../plugins/thermobeacon/src/*.php')] as $file) {
    require_once $file;
}

if ($argc !== 3) {
    fwrite(STDERR, "usage: php tools/seed.php <settings.json> <days>\n");
    exit(2);
}
// Only the thermobeacon section is used. Its checks run in ThermobeaconPlugin.
$settings = Settings::load($argv[1])['plugins']['thermobeacon'];
new ThermobeaconPlugin($settings);
$days = (int)$argv[2];
$store = new ReadingStore(Db::open($settings['db_path']));
$now = intdiv(time(), 60) * 60;
$start = $now - $days * 86400;

$store->transaction(function () use ($settings, $store, $start, $now) {
    foreach ($settings['sensors'] as $i => $sensor) {
        // kinti swings between about -5 and 12 °C, so negatives appear.
        [$base, $swing] = $sensor['icon'] === 'tree' ? [3.5, 8.5] : [21.5 + $i, 1.5];
        for ($ts = $start; $ts <= $now; $ts += 60) {
            $day = sin(($ts % 86400) / 86400 * 2 * M_PI - M_PI / 2);
            $store->insert([
                'address' => $sensor['address'],
                'ts' => $ts,
                'temperature_c' => round($base + $swing * $day, 2),
                'humidity_pct' => round(60 - 15 * $day + $i * 5, 2),
                'voltage_mv' => 2900,
                'battery_pct' => 90,
            ]);
        }
    }
});
echo "seeded $days days for " . count($settings['sensors']) . " sensors\n";
