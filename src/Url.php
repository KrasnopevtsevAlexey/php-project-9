<?php

namespace App;

use PDO;
use Carbon\Carbon;

class Url
{
    public static function save(string $name): ?array
    {
        $pdo = Connection::get();
        
        $parsed = parse_url($name);
        
        if (!isset($parsed['scheme']) || !isset($parsed['host'])) {
            return null;
        }
        
        $normalizedName = strtolower($parsed['scheme'] . '://' . $parsed['host']);
        
        $stmt = $pdo->prepare('SELECT * FROM urls WHERE name = :name');
        $stmt->execute([':name' => $normalizedName]);
        $existing = $stmt->fetch();
        
        if ($existing) {
            return array_merge($existing, ['is_new' => false]);
        }
        
        $stmt = $pdo->prepare('INSERT INTO urls (name, created_at) VALUES (:name, :created_at)');
        $createdAt = Carbon::now()->toDateTimeString();
        $stmt->execute([
            ':name' => $normalizedName,
            ':created_at' => $createdAt
        ]);
        
        $id = $pdo->lastInsertId();
        $result = self::findById($id);
        return $result ? array_merge($result, ['is_new' => true]) : null;
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
            SELECT 
                urls.*, 
                MAX(url_checks.created_at) as last_check_date,
                (
                    SELECT status_code 
                    FROM url_checks 
                    WHERE url_id = urls.id 
                    ORDER BY created_at DESC 
                    LIMIT 1
                ) as last_status_code
            FROM urls
            LEFT JOIN url_checks ON urls.id = url_checks.url_id
            GROUP BY urls.id
            ORDER BY urls.created_at DESC
        ');
        return $stmt->fetchAll();
    }
}
