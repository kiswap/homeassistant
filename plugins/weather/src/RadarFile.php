<?php

declare(strict_types=1);

/**
 * Reads HungaroMet's radar composite: a zip file that holds one netCDF
 * file (classic format) with a grid of bytes on a regular lat/lon grid.
 * Row 0 is the northern edge. A byte is dBZ = raw / 2 - 32; 0 means no
 * echo and 255 means outside the radars' range.
 * Files: https://odp.met.hu/weather/radar/composite/nc/
 */
final class RadarFile
{
    public const OUTSIDE = 255;
    private const TYPE_SIZE = [1 => 1, 2 => 1, 3 => 2, 4 => 4, 5 => 4, 6 => 8];

    /** Returns the first file inside a zip archive. */
    public static function unzip(string $zip): string
    {
        // The end-of-central-directory record is the last 22 bytes when the
        // archive has no comment; search backwards in case it has one.
        $eocd = strrpos($zip, "PK\x05\x06");
        if ($eocd === false) {
            throw new RuntimeException('radar: not a zip file');
        }
        $cdOffset = unpack('V', $zip, $eocd + 16)[1];
        if (substr($zip, $cdOffset, 4) !== "PK\x01\x02") {
            throw new RuntimeException('radar: bad zip directory');
        }
        $cd = unpack('vmethod/x8/Vcsize/Vsize', $zip, $cdOffset + 10);
        $localOffset = unpack('V', $zip, $cdOffset + 42)[1];
        if (substr($zip, $localOffset, 4) !== "PK\x03\x04") {
            throw new RuntimeException('radar: bad zip entry');
        }
        $local = unpack('vname/vextra', $zip, $localOffset + 26);
        $data = substr($zip, $localOffset + 30 + $local['name'] + $local['extra'], $cd['csize']);
        $file = match ($cd['method']) {
            0 => $data,
            8 => gzinflate($data),
            default => throw new RuntimeException("radar: zip method {$cd['method']} not supported"),
        };
        if ($file === false || strlen($file) !== $cd['size']) {
            throw new RuntimeException('radar: cannot unpack zip entry');
        }
        return $file;
    }

    /**
     * Reads the byte grid $var and its position from a netCDF classic file.
     *
     * @return array{north: float, west: float, dlat: float, dlon: float, rows: int, cols: int, data: string}
     */
    public static function grid(string $nc, string $var): array
    {
        $vars = self::variables($nc);
        foreach (['La1', 'Lo1', 'Dx', 'Dy', $var] as $name) {
            if (!isset($vars[$name])) {
                throw new RuntimeException("radar: variable $name missing");
            }
        }
        $float = fn(string $name) => unpack('G', $nc, $vars[$name]['begin'])[1];
        $v = $vars[$var];
        if ($v['type'] !== 1 || count($v['dims']) !== 2) {
            throw new RuntimeException("radar: $var is not a 2D byte grid");
        }
        [$rows, $cols] = $v['dims'];
        $data = substr($nc, $v['begin'], $rows * $cols);
        if (strlen($data) !== $rows * $cols) {
            throw new RuntimeException('radar: file is cut short');
        }
        return [
            'north' => $float('La1'),
            'west' => $float('Lo1'),
            'dlat' => $float('Dy'),
            'dlon' => $float('Dx'),
            'rows' => $rows,
            'cols' => $cols,
            'data' => $data,
        ];
    }

    /**
     * Cuts out the cells within $km of a point, in both directions. The
     * result has the same keys as grid().
     */
    public static function crop(array $grid, float $lat, float $lon, float $km): array
    {
        $kmPerDeg = 111.32;
        $centerRow = (int)round(($grid['north'] - $lat) / $grid['dlat']);
        $centerCol = (int)round(($lon - $grid['west']) / $grid['dlon']);
        $halfRows = (int)ceil($km / ($grid['dlat'] * $kmPerDeg));
        $halfCols = (int)ceil($km / ($grid['dlon'] * $kmPerDeg * cos(deg2rad($lat))));
        $r0 = max(0, $centerRow - $halfRows);
        $r1 = min($grid['rows'], $centerRow + $halfRows + 1);
        $c0 = max(0, $centerCol - $halfCols);
        $c1 = min($grid['cols'], $centerCol + $halfCols + 1);
        if ($r1 <= $r0 || $c1 <= $c0) {
            throw new RuntimeException('radar: the place is outside the radar grid');
        }
        $data = '';
        for ($r = $r0; $r < $r1; $r++) {
            $data .= substr($grid['data'], $r * $grid['cols'] + $c0, $c1 - $c0);
        }
        return [
            'north' => $grid['north'] - $r0 * $grid['dlat'],
            'west' => $grid['west'] + $c0 * $grid['dlon'],
            'dlat' => $grid['dlat'],
            'dlon' => $grid['dlon'],
            'rows' => $r1 - $r0,
            'cols' => $c1 - $c0,
            'data' => $data,
        ];
    }

    /**
     * Run-length encoding: pairs of (count 1..255, value) bytes. A radar
     * frame is mostly zeros, so this keeps it to a few KB.
     */
    public static function rle(string $data): string
    {
        $out = '';
        $n = strlen($data);
        for ($i = 0; $i < $n;) {
            $value = $data[$i];
            $run = 1;
            while ($run < 255 && $i + $run < $n && $data[$i + $run] === $value) {
                $run++;
            }
            $out .= chr($run) . $value;
            $i += $run;
        }
        return $out;
    }

    /**
     * Header of a netCDF classic file (CDF\x01): name => type, dims, begin.
     * https://docs.unidata.ucar.edu/netcdf-c/current/file_format_specifications.html
     *
     * @return array<string, array{type: int, dims: int[], begin: int}>
     */
    private static function variables(string $nc): array
    {
        if (substr($nc, 0, 4) !== "CDF\x01") {
            throw new RuntimeException('radar: not a netCDF classic file');
        }
        $pos = 8; // magic, numrecs
        $u32 = function () use ($nc, &$pos): int {
            $v = unpack('N', $nc, $pos)[1];
            $pos += 4;
            return $v;
        };
        $name = function () use ($nc, &$pos, $u32): string {
            $len = $u32();
            $s = substr($nc, $pos, $len);
            $pos += ($len + 3) & ~3;
            return $s;
        };
        $skipAttributes = function () use (&$pos, $u32, $name): void {
            $u32(); // tag, or 0 when absent
            for ($n = $u32(); $n > 0; $n--) {
                $name();
                $size = self::TYPE_SIZE[$u32()] ?? throw new RuntimeException('radar: bad attribute type');
                $pos += ($u32() * $size + 3) & ~3;
            }
        };

        $u32();
        $dims = [];
        for ($n = $u32(); $n > 0; $n--) {
            $name();
            $dims[] = $u32();
        }
        $skipAttributes();
        $u32();
        $vars = [];
        for ($n = $u32(); $n > 0; $n--) {
            $varName = $name();
            $ids = [];
            for ($d = $u32(); $d > 0; $d--) {
                $ids[] = $u32();
            }
            $skipAttributes();
            $type = $u32();
            $u32(); // vsize
            $vars[$varName] = [
                'type' => $type,
                'dims' => array_map(fn(int $id) => $dims[$id], $ids),
                'begin' => $u32(),
            ];
        }
        return $vars;
    }
}
