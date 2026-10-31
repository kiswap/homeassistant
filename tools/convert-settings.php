<?php
// Converts a settings.json from before the hub split to the hub's shape.
// Usage: php tools/convert-settings.php <old settings.json> > config/settings.json

declare(strict_types=1);

require_once __DIR__ . '/../plugins/thermobeacon/src/LegacySettings.php';

if ($argc !== 2) {
    fwrite(STDERR, "usage: php tools/convert-settings.php <old settings.json>\n");
    exit(2);
}
$old = json_decode((string)@file_get_contents($argv[1]), true);
if (!is_array($old)) {
    fwrite(STDERR, "cannot read JSON from {$argv[1]}\n");
    exit(1);
}
try {
    $new = LegacySettings::convert($old);
} catch (RuntimeException $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}
echo json_encode($new, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), "\n";
