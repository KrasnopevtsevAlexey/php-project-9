<?php

namespace App;

class Check
{
    public static function save(int $urlId, array $data): void
    {
        $pdo = Connection::get();

        $stmt = $pdo->prepare('
            INSERT INTO url_checks (url_id, status_code, h1, title, description, created_at) 
            VALUES (:url_id, :status_code, :h1, :title, :description, :created_at)
        ');

        $stmt->execute([
            ':url_id' => $urlId,
            ':status_code' => $data['status_code'],
            ':h1' => $data['h1'],
            ':title' => $data['title'],
            ':description' => $data['description'],
            ':created_at' => $data['created_at']
        ]);
    }

    public static function findByUrlId(int $urlId): array
    {
        $pdo = Connection::get();
        $stmt = $pdo->prepare('SELECT * FROM url_checks WHERE url_id = :url_id ORDER BY created_at DESC');
        $stmt->execute([':url_id' => $urlId]);
        return $stmt->fetchAll() ?: [];
    }
}
