<?php

namespace App;

use PDO;

class Connection
{
    private static ?PDO $connection = null;

    public static function get(): PDO
    {
        if (self::$connection === null) {
            $databaseUrl = getenv('DATABASE_URL');

            if ($databaseUrl && in_array('pgsql', PDO::getAvailableDrivers())) {
                try {
                    self::$connection = new PDO($databaseUrl);
                    self::$connection->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
                    self::$connection->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
                } catch (\PDOException $e) {
                    error_log("PostgreSQL error: " . $e->getMessage());
                    $dbPath = __DIR__ . '/../database.sqlite';
                    self::$connection = new PDO("sqlite:{$dbPath}");
                    self::$connection->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
                    self::$connection->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
                }
            } else {
                $dbPath = __DIR__ . '/../database.sqlite';
                self::$connection = new PDO("sqlite:{$dbPath}");
                self::$connection->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
                self::$connection->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

                self::$connection->exec('PRAGMA foreign_keys = ON;');
                self::$connection->exec('PRAGMA journal_mode = WAL;');
                self::$connection->exec('PRAGMA busy_timeout = 5000;');
            }
        }

        return self::$connection;
    }
}
