<?php

namespace App;

use PDO;
use Carbon\Carbon;

class Check
{
    public static function save(int $urlId, array $data): ?array
    {
        $pdo = Connection::get();
        
        $stmt = $pdo->prepare('
            INSERT INTO url_checks (url_id, status_code, h1, title, description, created_at) 
            VALUES (:url_id, :status_code, :h1, :title, :description, :created_at)
        ');
        
        $createdAt = Carbon::now()->toDateTimeString();
        $stmt->execute([
            ':url_id' => $urlId,
            ':status_code' => $data['status_code'] ?? null,
            ':h1' => $data['h1'] ?? null,
            ':title' => $data['title'] ?? null,
            ':description' => $data['description'] ?? null,
            ':created_at' => $createdAt
        ]);
        
        $id = $pdo->lastInsertId();
        
        return self::findById($id);
    }
    
    public static function findByUrlId(int $urlId): array
    {
        $pdo = Connection::get();
        $stmt = $pdo->prepare('SELECT * FROM url_checks WHERE url_id = :url_id ORDER BY created_at DESC');
        $stmt->execute([':url_id' => $urlId]);
        return $stmt->fetchAll();
    }
    
    public static function findById(int $id): ?array
    {
        $pdo = Connection::get();
        $stmt = $pdo->prepare('SELECT * FROM url_checks WHERE id = :id');
        $stmt->execute([':id' => $id]);
        return $stmt->fetch() ?: null;
    }
}
