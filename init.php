<?php

declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

$pdo = \App\Connection::get();
$driverName = $pdo->getAttribute(\PDO::ATTR_DRIVER_NAME);

echo "Инициализация базы данных для драйвера: {$driverName}...\n";

if ($driverName === 'sqlite') {
    $pdo->exec("
        DROP TABLE IF EXISTS url_checks;
        DROP TABLE IF EXISTS urls;

        CREATE TABLE urls (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name VARCHAR(255) NOT NULL UNIQUE,
            created_at DATETIME NOT NULL
        );

        CREATE TABLE url_checks (
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
    $pdo->exec("
        DROP TABLE IF EXISTS url_checks CASCADE;
        DROP TABLE IF EXISTS urls CASCADE;

        CREATE TABLE urls (
            id SERIAL PRIMARY KEY,
            name VARCHAR(255) NOT NULL UNIQUE,
            created_at TIMESTAMP NOT NULL
        );

        CREATE TABLE url_checks (
            id SERIAL PRIMARY KEY,
            url_id INTEGER NOT NULL REFERENCES urls(id) ON DELETE CASCADE,
            status_code INTEGER,
            h1 VARCHAR(1000),
            title VARCHAR(1000),
            description VARCHAR(1000),
            created_at TIMESTAMP NOT NULL
        );
    ");
}

echo "База данных успешно инициализирована!\n";
