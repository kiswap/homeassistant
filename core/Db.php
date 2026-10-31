<?php

declare(strict_types=1);

final class Db
{
    /** Opens an SQLite database, creating the file if needed. Plugins create their own tables. */
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
        return $pdo;
    }
}
