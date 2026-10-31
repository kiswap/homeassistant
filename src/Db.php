<?php

declare(strict_types=1);

final class Db
{
    /** Opens the SQLite database, creating the file and schema if needed. */
    public static function open(string $path): PDO
    {
        $pdo = new PDO('sqlite:' . $path);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        // WAL plus synchronous=NORMAL keeps writes few and small, which
        // matters on krpi's flash storage.
        $pdo->exec('PRAGMA journal_mode = WAL');
        $pdo->exec('PRAGMA synchronous = NORMAL');
        $pdo->exec('PRAGMA busy_timeout = 5000');
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS readings (
                address TEXT NOT NULL,
                ts INTEGER NOT NULL,
                temperature_c REAL NOT NULL,
                humidity_pct REAL NOT NULL,
                voltage_mv INTEGER,
                battery_pct INTEGER,
                PRIMARY KEY (address, ts)
            ) WITHOUT ROWID'
        );
        $pdo->exec('CREATE TABLE IF NOT EXISTS meta (key TEXT PRIMARY KEY, value TEXT NOT NULL)');
        return $pdo;
    }
}
