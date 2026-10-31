<?php

declare(strict_types=1);

final class ReadingStore
{
    public function __construct(private PDO $pdo) {}

    /**
     * Stores one reading. Returns false when a reading with the same
     * address and time already exists (a resend after a lost response).
     */
    public function insert(array $r): bool
    {
        $stmt = $this->pdo->prepare(
            'INSERT OR IGNORE INTO readings
                (address, ts, temperature_c, humidity_pct, voltage_mv, battery_pct)
             VALUES (:address, :ts, :t, :h, :mv, :pct)'
        );
        $stmt->execute([
            ':address' => $r['address'],
            ':ts' => $r['ts'],
            ':t' => $r['temperature_c'],
            ':h' => $r['humidity_pct'],
            ':mv' => $r['voltage_mv'],
            ':pct' => $r['battery_pct'],
        ]);
        return $stmt->rowCount() === 1;
    }

    public function transaction(callable $fn): mixed
    {
        $this->pdo->beginTransaction();
        try {
            $result = $fn();
            $this->pdo->commit();
            return $result;
        } catch (Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    public function latest(string $address): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT ts, temperature_c, humidity_pct, voltage_mv, battery_pct
             FROM readings WHERE address = ? ORDER BY ts DESC LIMIT 1'
        );
        $stmt->execute([$address]);
        $row = $stmt->fetch();
        if ($row === false) {
            return null;
        }
        return [
            'ts' => (int)$row['ts'],
            'temperature_c' => (float)$row['temperature_c'],
            'humidity_pct' => (float)$row['humidity_pct'],
            'voltage_mv' => $row['voltage_mv'] === null ? null : (int)$row['voltage_mv'],
            'battery_pct' => $row['battery_pct'] === null ? null : (int)$row['battery_pct'],
        ];
    }

    /** Averages per time bucket: list of [bucketStartTs, avgTemp, avgHum]. */
    public function history(string $address, int $fromTs, int $bucketSeconds): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT (ts / :b) * :b AS bucket,
                    ROUND(AVG(temperature_c), 2) AS t,
                    ROUND(AVG(humidity_pct), 2) AS h
             FROM readings WHERE address = :a AND ts >= :from
             GROUP BY bucket ORDER BY bucket'
        );
        $stmt->execute([':b' => $bucketSeconds, ':a' => $address, ':from' => $fromTs]);
        return array_map(
            fn(array $row) => [(int)$row['bucket'], (float)$row['t'], (float)$row['h']],
            $stmt->fetchAll(),
        );
    }

    public function setMeta(string $key, string $value): void
    {
        $this->pdo->prepare('INSERT OR REPLACE INTO meta (key, value) VALUES (?, ?)')
            ->execute([$key, $value]);
    }

    public function getMeta(string $key): ?string
    {
        $stmt = $this->pdo->prepare('SELECT value FROM meta WHERE key = ?');
        $stmt->execute([$key]);
        $value = $stmt->fetchColumn();
        return $value === false ? null : (string)$value;
    }
}
