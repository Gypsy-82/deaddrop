<?php

declare(strict_types=1);

/**
 * SQLite access. The database file lives on the persistent /data volume — the one
 * writable surface in an otherwise read-only container. WAL mode lets a client write
 * a comment while you read the log.
 */
final class Database
{
    public const PATH = '/data/app.sqlite';

    private static ?PDO $pdo = null;

    public static function pdo(): PDO
    {
        if (self::$pdo === null) {
            $pdo = new PDO('sqlite:' . self::PATH);
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
            $pdo->exec('PRAGMA journal_mode = WAL');
            $pdo->exec('PRAGMA foreign_keys = ON');
            $pdo->exec('PRAGMA busy_timeout = 5000');
            self::$pdo = $pdo;
        }

        return self::$pdo;
    }

    /** Create tables if they don't exist. Idempotent — safe to call on every boot. */
    public static function migrate(): void
    {
        $sql = (string) file_get_contents(__DIR__ . '/../db/schema.sql');
        self::pdo()->exec($sql);
    }
}
