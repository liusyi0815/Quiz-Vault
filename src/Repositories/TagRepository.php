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

    // ---------------------------------------------------------------- F-07 標籤管理

    /** @return array{0:list<array>, 1:int} 每個標籤附上「用到它的題目數」 */
    public function list(?string $q, int $limit, int $offset): array
    {
        $pdo = Database::connection();
        $where = '';
        $params = [];
        if ($q !== null) {
            $where = " WHERE t.name LIKE :q ESCAPE '!'";
            $params['q'] = \App\Support\Sql::likeContains($q);
        }
        $count = $pdo->prepare('SELECT COUNT(*) FROM tags t' . $where);
        $count->execute($params);

        $stmt = $pdo->prepare(
            'SELECT t.tag_id, t.name, (SELECT COUNT(*) FROM question_tags qt WHERE qt.tag_id = t.tag_id) AS question_count
             FROM tags t' . $where . ' ORDER BY t.name LIMIT ' . $limit . ' OFFSET ' . $offset
        );
        $stmt->execute($params);
        return [$stmt->fetchAll(), (int) $count->fetchColumn()];
    }

    public function findById(int $id): ?array
    {
        $stmt = Database::connection()->prepare('SELECT tag_id, name FROM tags WHERE tag_id = :id');
        $stmt->execute(['id' => $id]);
        return $stmt->fetch() ?: null;
    }

    /** 以名稱找（忽略大小寫與前後空白，由 utf8mb4_unicode_ci 負責） */
    public function findByName(string $name): ?array
    {
        $stmt = Database::connection()->prepare('SELECT tag_id, name FROM tags WHERE name = :n');
        $stmt->execute(['n' => $name]);
        return $stmt->fetch() ?: null;
    }

    public function create(string $name): int
    {
        $pdo = Database::connection();
        $pdo->prepare('INSERT INTO tags (name) VALUES (:n)')->execute(['n' => $name]);
        return (int) $pdo->lastInsertId();
    }

    /** question_tags 由外鍵 ON DELETE CASCADE 連動刪除 */
    public function delete(int $id): int
    {
        $stmt = Database::connection()->prepare('DELETE FROM tags WHERE tag_id = :id');
        $stmt->execute(['id' => $id]);
        return $stmt->rowCount();
    }

    public function questionCount(int $id): int
    {
        $stmt = Database::connection()->prepare('SELECT COUNT(*) FROM question_tags WHERE tag_id = :id');
        $stmt->execute(['id' => $id]);
        return (int) $stmt->fetchColumn();
    }
}
