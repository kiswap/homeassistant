<?php

declare(strict_types=1);

/** A netCDF classic file like HungaroMet's: scalars La1, Lo1, Dx, Dy and a byte grid. */
function make_nc(int $rows, int $cols, string $data, float $north = 50.5, float $west = 13.5,
                 float $dlat = 0.008, float $dlon = 0.0125, string $var = 'refl2D_pscappi'): string
{
    $name = fn(string $s) => pack('N', strlen($s)) . str_pad($s, (strlen($s) + 3) & ~3, "\0");
    $absent = pack('NN', 0, 0);
    $scalars = ['La1' => $north, 'Lo1' => $west, 'Dx' => $dlon, 'Dy' => $dlat];
    $dims = pack('NN', 10, 2) . $name('lat') . pack('N', $rows) . $name('lon') . pack('N', $cols);
    $header = function (int $begin) use ($name, $absent, $scalars, $dims, $var, $rows, $cols): string {
        $vars = '';
        foreach ($scalars as $n => $_) {
            $vars .= $name($n) . pack('N', 0) . $absent . pack('NNN', 5, 4, $begin);
            $begin += 4;
        }
        $vars .= $name($var) . pack('NNN', 2, 0, 1) . $absent . pack('NNN', 1, ($rows * $cols + 3) & ~3, $begin);
        return "CDF\x01" . pack('N', 0) . $dims . $absent . pack('NN', 11, count($scalars) + 1) . $vars;
    };
    $head = $header(0);
    $head = $header(strlen($head));
    $body = '';
    foreach ($scalars as $v) {
        $body .= pack('G', $v);
    }
    return $head . $body . $data;
}

function make_zip(string $file, int $method = 8): string
{
    $packed = $method === 8 ? gzdeflate($file) : $file;
    $name = 'radar.nc';
    $sizes = pack('VVV', crc32($file), strlen($packed), strlen($file));
    $local = "PK\x03\x04" . pack('vvvvv', 20, 0, $method, 0, 0) . $sizes . pack('vv', strlen($name), 0) . $name;
    $central = "PK\x01\x02" . pack('vvvvvv', 20, 20, 0, $method, 0, 0) . $sizes
        . pack('vvvvvVV', strlen($name), 0, 0, 0, 0, 0, 0) . $name;
    $eocd = "PK\x05\x06" . pack('vvvvVVv', 0, 0, 1, 1, strlen($central), strlen($local) + strlen($packed), 0);
    return $local . $packed . $central . $eocd;
}

function test_unzip_deflated_and_stored(): void
{
    $file = str_repeat("radar\0", 1000);
    assert_eq($file, RadarFile::unzip(make_zip($file)));
    assert_eq($file, RadarFile::unzip(make_zip($file, 0)));
}

function test_unzip_rejects_other_data(): void
{
    try {
        RadarFile::unzip('not a zip');
        assert_true(false, 'expected an exception');
    } catch (RuntimeException $e) {
        assert_eq('radar: not a zip file', $e->getMessage());
    }
}

function test_grid_reads_position_and_bytes(): void
{
    $data = implode('', array_map('chr', range(0, 11)));
    $grid = RadarFile::grid(make_nc(3, 4, $data), 'refl2D_pscappi');
    assert_eq(3, $grid['rows']);
    assert_eq(4, $grid['cols']);
    assert_eq($data, $grid['data']);
    assert_eq(50.5, $grid['north']);
    assert_eq(13.5, $grid['west']);
    assert_true(abs($grid['dlat'] - 0.008) < 1e-7, 'dlat');
    assert_true(abs($grid['dlon'] - 0.0125) < 1e-7, 'dlon');
}

function test_grid_needs_the_variable(): void
{
    try {
        RadarFile::grid(make_nc(1, 1, "\0", var: 'other'), 'refl2D_pscappi');
        assert_true(false, 'expected an exception');
    } catch (RuntimeException $e) {
        assert_eq('radar: variable refl2D_pscappi missing', $e->getMessage());
    }
}

function test_crop_cuts_around_the_point(): void
{
    // 10 x 10 cells of 1 degree; the value of a cell is its row * 10 + col.
    $data = implode('', array_map('chr', range(0, 99)));
    $grid = ['north' => 10.0, 'west' => 0.0, 'dlat' => 1.0, 'dlon' => 1.0, 'rows' => 10, 'cols' => 10, 'data' => $data];
    // Row 4, col 3; 120 km is a bit more than one degree, so 2 cells each way.
    $crop = RadarFile::crop($grid, 6.0, 3.0, 120);
    assert_eq(5, $crop['rows']);
    assert_true($crop['cols'] >= 5, 'cols');
    assert_eq(8.0, $crop['north']);
    assert_eq(chr(21), $crop['data'][0], 'top left is row 2, col 1');
}

function test_crop_stops_at_the_edge(): void
{
    $grid = ['north' => 10.0, 'west' => 0.0, 'dlat' => 1.0, 'dlon' => 1.0, 'rows' => 10, 'cols' => 10,
        'data' => str_repeat("\0", 100)];
    $crop = RadarFile::crop($grid, 10.0, 0.0, 120);
    assert_eq(10.0, $crop['north']);
    assert_eq(0.0, $crop['west']);
    assert_eq(3, $crop['rows']);
}

function test_rle_pairs_count_and_value(): void
{
    assert_eq("\x03\x00\x01\x07\x02\x00", RadarFile::rle("\0\0\0\x07\0\0"));
    assert_eq("\xff\x00\x01\x00", RadarFile::rle(str_repeat("\0", 256)), 'runs stop at 255');
    assert_eq('', RadarFile::rle(''));
}
