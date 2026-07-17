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

            // ВСЕГДА используем SQLite для тестов и если APP_ENV не 'production'
            if ($appEnv === 'test' || $appEnv === 'local') {
                self::$connection = self::createSQLiteConnection();
            } else {
                // В production используем PostgreSQL
                $databaseUrl = getenv('DATABASE_URL');
                if ($databaseUrl && in_array('pgsql', PDO::getAvailableDrivers())) {
                    try {
                        self::$connection = new PDO($databaseUrl);
                        self::$connection->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
                        self::$connection->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

                        // Инициализируем таблицы PostgreSQL из файла database.sql
                        self::initDatabaseSchema(self::$connection, 'pgsql');
                    } catch (\PDOException $e) {
                        error_log("PostgreSQL error: " . $e->getMessage());
                        self::$connection = self::createSQLiteConnection();
                    }
                } else {
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

        // Проверяем, создана ли уже таблица urls в базе данных
        try {
            $pdo->query("SELECT 1 FROM urls LIMIT 1");
        } catch (\PDOException $e) {
            // Если таблицы не существует, SQLite выбросит исключение — значит, нужно накатить схему
            self::initDatabaseSchema($pdo, 'sqlite');
        }

        return $pdo;
    }


    private static function initDatabaseSchema(PDO $pdo, string $driver): void
    {
        $sqlPath = __DIR__ . '/../database.sql';

        if (!file_exists($sqlPath)) {
            return;
        }

        $sql = file_get_contents($sqlPath);
        if ($sql === false) {
            return;
        }

        if ($driver === 'sqlite') {
            // Адаптируем синтаксис PostgreSQL под особенности SQLite на лету:
            // 1. SERIAL PRIMARY KEY -> INTEGER PRIMARY KEY AUTOINCREMENT
            $sql = preg_replace('/SERIAL\s+PRIMARY\s+KEY/i', 'INTEGER PRIMARY KEY AUTOINCREMENT', $sql);

            // 2. Убираем неподдерживаемый в SQLite синтаксис каскадного удаления таблиц "CASCADE"
            $sql = preg_replace('/DROP\s+TABLE\s+IF\s+EXISTS\s+([a-zA-Z_]+)\s+CASCADE/i', 'DROP TABLE IF EXISTS $1', $sql);
        }

        $pdo->exec($sql);
    }
}
