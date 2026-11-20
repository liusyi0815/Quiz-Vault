<?php
declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;

final class TagRepository
{
    /**
     * 依名稱取得 tag_id，不存在就建立。
     * 名稱的大小寫以資料庫排序規則（utf8mb4_unicode_ci）為準：SQL 與 sql 視為同一個標籤。
     */
    public function idByName(string $name): int
    {
        $pdo = Database::connection();
        $pdo->prepare('INSERT INTO tags (name) VALUES (:n) ON DUPLICATE KEY UPDATE tag_id = LAST_INSERT_ID(tag_id)')
            ->execute(['n' => $name]);
        return (int) $pdo->query('SELECT LAST_INSERT_ID()')->fetchColumn();
    }
}
