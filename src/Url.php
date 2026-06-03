<?php

namespace App;

use PDO;
use Carbon\Carbon;

class Url
{
    public static function save(string $name): ?array
    {
        $pdo = Connection::get();
        
        // Нормализация URL
        $parsed = parse_url($name);
        
        if (!isset($parsed['scheme']) || !isset($parsed['host'])) {
            return null;
        }
        
        $normalizedName = strtolower($parsed['scheme'] . '://' . $parsed['host']);
        
        // Проверка на существование
        $stmt = $pdo->prepare('SELECT * FROM urls WHERE name = :name');
        $stmt->execute([':name' => $normalizedName]);
        $existing = $stmt->fetch();
        
        if ($existing) {
            return $existing;
        }
        
        // Сохранение нового URL
        $stmt = $pdo->prepare('INSERT INTO urls (name, created_at) VALUES (:name, :created_at)');
        $createdAt = Carbon::now()->toDateTimeString();
        $stmt->execute([
            ':name' => $normalizedName,
            ':created_at' => $createdAt
        ]);
        
        $id = $pdo->lastInsertId();
        
        return self::findById($id);
    }
    
    public static function findById(int $id): ?array
    {
        $pdo = Connection::get();
        $stmt = $pdo->prepare('SELECT * FROM urls WHERE id = :id');
        $stmt->execute([':id' => $id]);
        return $stmt->fetch() ?: null;
    }
    
    public static function findAll(): array
    {
        $pdo = Connection::get();
        $stmt = $pdo->query('
            SELECT urls.*, MAX(url_checks.status_code) as last_status_code
            FROM urls
            LEFT JOIN url_checks ON urls.id = url_checks.url_id
            GROUP BY urls.id
            ORDER BY urls.created_at DESC
        ');
        return $stmt->fetchAll();
    }
}
