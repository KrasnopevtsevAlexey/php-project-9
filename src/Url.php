<?php

namespace App;

use PDO;

class Url
{
    public static function save(string $normalizedName, string $createdAt): string
    {
        $pdo = Connection::get();
        $stmt = $pdo->prepare('INSERT INTO urls (name, created_at) VALUES (:name, :created_at)');
        $stmt->execute([
            ':name' => $normalizedName,
            ':created_at' => $createdAt
        ]);

        return $pdo->lastInsertId();
    }

    public static function findByName(string $name): ?array
    {
        $pdo = Connection::get();
        $stmt = $pdo->prepare('SELECT * FROM urls WHERE name = :name');
        $stmt->execute([':name' => $name]);
        return $stmt->fetch() ?: null;
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

        $stmt = $pdo->query('SELECT id, name, created_at FROM urls ORDER BY created_at DESC');
        $urls = $stmt->fetchAll() ?: [];

        if (empty($urls)) {
            return [];
        }

        $urlIds = array_column($urls, 'id');
        $placeholders = implode(',', array_fill(0, count($urlIds), '?'));

        $checksStmt = $pdo->prepare("
            SELECT url_id, status_code 
            FROM url_checks 
            WHERE id IN (
                SELECT MAX(id) 
                FROM url_checks 
                WHERE url_id IN ($placeholders) 
                GROUP BY url_id
            )
        ");

        $checksStmt->execute($urlIds);
        $latestChecks = $checksStmt->fetchAll() ?: [];
        $checksMap = array_column($latestChecks, 'status_code', 'url_id');

        return array_map(function ($url) use ($checksMap) {
            $url['last_status_code'] = $checksMap[$url['id']] ?? null;
            return $url;
        }, $urls);
    }
}
