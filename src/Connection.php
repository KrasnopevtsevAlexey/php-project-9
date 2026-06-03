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
                // Включаем поддержку FOREIGN KEY для SQLite
                self::$connection->exec('PRAGMA foreign_keys = ON;');
            } else {
                // На Render используем PostgreSQL
                self::$connection = new PDO($databaseUrl);
                self::$connection->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
                self::$connection->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
            }
        }
        
        return self::$connection;
    }
}
