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

            if (!$databaseUrl) {
                // Локально используем SQLite
                $dbPath = __DIR__ . '/../database.sqlite';
                self::$connection = new PDO("sqlite:{$dbPath}");
                self::$connection->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
                self::$connection->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
                self::$connection->exec('PRAGMA foreign_keys = ON;');
                self::createTablesSQLite(self::$connection);
            } else {
                // На Render/тесты используем PostgreSQL
                try {
                    // Проверяем, доступен ли драйвер pdo_pgsql
                    if (!in_array('pgsql', PDO::getAvailableDrivers())) {
                        throw new \PDOException('PDO PostgreSQL driver not available');
                    }

                    self::$connection = new PDO($databaseUrl);
                    self::$connection->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
                    self::$connection->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
                    self::createTablesPostgres(self::$connection);
                } catch (PDOException $e) {
                    error_log("Connection error: " . $e->getMessage());
                    throw $e;
                }
            }
        }

        return self::$connection;
    }

    private static function createTablesPostgres(PDO $pdo): void
    {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS urls (
                id SERIAL PRIMARY KEY,
                name VARCHAR(255) NOT NULL UNIQUE,
                created_at TIMESTAMP NOT NULL
            );

            CREATE TABLE IF NOT EXISTS url_checks (
                id SERIAL PRIMARY KEY,
                url_id INTEGER NOT NULL REFERENCES urls(id) ON DELETE CASCADE,
                status_code INTEGER,
                h1 TEXT,
                title TEXT,
                description TEXT,
                created_at TIMESTAMP NOT NULL
            );
        ");
    }

    private static function createTablesSQLite(PDO $pdo): void
    {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS urls (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name VARCHAR(255) NOT NULL UNIQUE,
                created_at DATETIME NOT NULL
            );

            CREATE TABLE IF NOT EXISTS url_checks (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                url_id INTEGER NOT NULL,
                status_code INTEGER,
                h1 TEXT,
                title TEXT,
                description TEXT,
                created_at DATETIME NOT NULL,
                FOREIGN KEY (url_id) REFERENCES urls(id) ON DELETE CASCADE
            );
        ");
    }
}
