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
            $appEnv = getenv('APP_ENV') ?: 'local';

            error_log("=== Connection::get() ===");
            error_log("APP_ENV: " . $appEnv);
            error_log("DATABASE_URL: " . ($databaseUrl ? 'set' : 'not set'));
            error_log("Available drivers: " . implode(', ', PDO::getAvailableDrivers()));

            // ВСЕГДА используем SQLite для тестов
            if ($appEnv === 'test' || !$databaseUrl || !in_array('pgsql', PDO::getAvailableDrivers())) {
                error_log("Using SQLite");
                self::$connection = self::createSQLiteConnection();
            } else {
                error_log("Using PostgreSQL");
                try {
                    self::$connection = new PDO($databaseUrl);
                    self::$connection->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
                    self::$connection->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
                    self::createTablesPostgres(self::$connection);
                } catch (PDOException $e) {
                    error_log("PostgreSQL error: " . $e->getMessage());
                    self::$connection = self::createSQLiteConnection();
                }
            }
        }

        return self::$connection;
    }

    private static function createSQLiteConnection(): PDO
    {
        $dbPath = __DIR__ . '/../database.sqlite';
        error_log("Creating SQLite connection to: " . $dbPath);

        $pdo = new PDO("sqlite:{$dbPath}");
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $pdo->exec('PRAGMA foreign_keys = ON;');

        // Создаём таблицы, если их нет
        error_log("Creating tables if not exist...");
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

        // Проверяем, что таблицы создались
        $tables = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name IN ('urls', 'url_checks')")->fetchAll();
        error_log("Tables created: " . print_r($tables, true));

        return $pdo;
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
}
