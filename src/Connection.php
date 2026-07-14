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
            $appEnv = getenv('APP_ENV');

            // В тестовом окружении или без DATABASE_URL используем SQLite
            if ($appEnv === 'test' || !$databaseUrl) {
                $dbPath = __DIR__ . '/../database.sqlite';
                self::$connection = new PDO("sqlite:{$dbPath}");
                self::$connection->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
                self::$connection->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
                self::$connection->exec('PRAGMA foreign_keys = ON;');
                self::createTables(self::$connection);
            } else {
                // В продакшене используем PostgreSQL
                try {
                    if (!in_array('pgsql', PDO::getAvailableDrivers())) {
                        throw new \PDOException('PDO PostgreSQL driver not available');
                    }

                    self::$connection = new PDO($databaseUrl);
                    self::$connection->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
                    self::$connection->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
                    self::createTables(self::$connection);
                } catch (PDOException $e) {
                    error_log("Connection error: " . $e->getMessage());
                    throw $e;
                }
            }
        }

        return self::$connection;
    }

    private static function createTables(PDO $pdo): void
    {
        // Проверяем, существует ли таблица urls
        $stmt = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='urls'");
        if ($stmt->fetch()) {
            return; // Таблицы уже существуют
        }

        $pdo->exec("
            CREATE TABLE urls (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name VARCHAR(255) NOT NULL UNIQUE,
                created_at DATETIME NOT NULL
            );

            CREATE TABLE url_checks (
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
