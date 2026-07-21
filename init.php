<?php

declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

$pdo = \App\Connection::get();
$driverName = $pdo->getAttribute(\PDO::ATTR_DRIVER_NAME);

$sqlPath = __DIR__ . '/database.sql';
if (!file_exists($sqlPath)) {
    echo "Файл database.sql не найден.\n";
    exit(1);
}

$sql = file_get_contents($sqlPath);

if ($driverName === 'sqlite') {
    // Безопасно адаптируем схему под SQLite в консоли до запуска тестов
    $sql = preg_replace('/SERIAL\s+PRIMARY\s+KEY/i', 'INTEGER PRIMARY KEY AUTOINCREMENT', $sql);
    $sql = preg_replace('/DROP\s+TABLE\s+IF\s+EXISTS\s+([a-zA-Z_]+)\s+CASCADE/i', 'DROP TABLE IF EXISTS $1', $sql);
    $sql = str_ireplace('TIMESTAMP', 'DATETIME', $sql);
}

try {
    $pdo->exec($sql);
    // Дополнительно настраиваем базу
    if ($driverName === 'sqlite') {
        $pdo->exec('PRAGMA foreign_keys = ON;');
        $pdo->exec('PRAGMA journal_mode = WAL;');
    }
    echo "База данных успешно инициализирована из файла database.sql!\n";
} catch (\PDOException $e) {
    echo "Ошибка инициализации базы данных: " . $e->getMessage() . "\n";
    exit(1);
}
