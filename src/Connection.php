<?php

namespace App;

use PDO;

class Connection
{
    private static ?PDO $connection = null;

    public static function get(): PDO
    {
        if (self::$connection === null) {
            $appEnv = getenv('APP_ENV') ?: 'local';

            if ($appEnv === 'test' || $appEnv === 'local') {
                // Переносим базу во временную папку ОС для обхода ограничений прав доступа в Docker
                $dbPath = $appEnv === 'test' ? '/tmp/database.sqlite' : __DIR__ . '/../database.sqlite';

                self::$connection = new PDO("sqlite:{$dbPath}");
                self::$connection->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
                self::$connection->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

                self::$connection->exec('PRAGMA foreign_keys = ON;');
                self::$connection->exec('PRAGMA journal_mode = WAL;');
                self::$connection->exec('PRAGMA busy_timeout = 5000;');

                self::$connection->exec("
                    CREATE TABLE IF NOT EXISTS urls (
                        id INTEGER PRIMARY KEY AUTOINCREMENT,
                        name VARCHAR(255) NOT NULL UNIQUE,
                        created_at DATETIME NOT NULL
                    );
                    CREATE TABLE IF NOT EXISTS url_checks (
                        id INTEGER PRIMARY KEY AUTOINCREMENT,
                        url_id INTEGER NOT NULL,
                        status_code INTEGER,
                        h1 VARCHAR(1000),
                        title VARCHAR(1000),
                        description VARCHAR(1000),
                        created_at DATETIME NOT NULL,
                        FOREIGN KEY (url_id) REFERENCES urls(id) ON DELETE CASCADE
                    );
                ");
            } else {
                $databaseUrl = getenv('DATABASE_URL');
                if ($databaseUrl && in_array('pgsql', PDO::getAvailableDrivers())) {
                    try {
                        self::$connection = new PDO($databaseUrl);
                        self::$connection->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
                        self::$connection->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

                        self::$connection->exec("
                            CREATE TABLE IF NOT EXISTS urls (
                                id SERIAL PRIMARY KEY,
                                name VARCHAR(255) NOT NULL UNIQUE,
                                created_at TIMESTAMP NOT NULL
                            );
                            CREATE TABLE IF NOT EXISTS url_checks (
                                id SERIAL PRIMARY KEY,
                                url_id INTEGER NOT NULL REFERENCES urls(id) ON DELETE CASCADE,
                                status_code INTEGER,
                                h1 VARCHAR(1000),
                                title VARCHAR(1000),
                                description VARCHAR(1000),
                                created_at TIMESTAMP NOT NULL
                            );
                        ");
                    } catch (\PDOException $e) {
                        error_log("PostgreSQL error: " . $e->getMessage());
                        self::$connection = new PDO("sqlite:/tmp/database.sqlite");
                        self::$connection->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
                        self::$connection->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
                    }
                }
            }
        }

        return self::$connection;
    }
}
