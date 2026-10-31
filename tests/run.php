<?php
// Minimal test runner: php tests/run.php
// Loads every tests/*Test.php and runs every function named test_*.

declare(strict_types=1);

foreach (glob(__DIR__ . '/../src/*.php') as $file) {
    require_once $file;
}

final class AssertionFailed extends Exception {}

function assert_eq(mixed $expected, mixed $actual, string $msg = ''): void
{
    if ($expected !== $actual) {
        throw new AssertionFailed(sprintf(
            "%sexpected %s, got %s",
            $msg === '' ? '' : "$msg: ",
            var_export($expected, true),
            var_export($actual, true),
        ));
    }
}

function assert_true(bool $value, string $msg = ''): void
{
    if (!$value) {
        throw new AssertionFailed($msg === '' ? 'expected true' : $msg);
    }
}

$before = get_defined_functions()['user'];
foreach (glob(__DIR__ . '/*Test.php') as $file) {
    require_once $file;
}
$tests = array_filter(
    array_diff(get_defined_functions()['user'], $before),
    fn(string $name) => str_starts_with($name, 'test_'),
);

$failed = 0;
foreach ($tests as $test) {
    try {
        $test();
        echo "PASS $test\n";
    } catch (Throwable $e) {
        $failed++;
        echo "FAIL $test: " . get_class($e) . ': ' . $e->getMessage() . "\n";
    }
}
echo sprintf("%d tests, %d failed\n", count($tests), $failed);
exit($failed === 0 ? 0 : 1);
