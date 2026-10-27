-- ============================================================
-- 分階段測試資料 ① users + courses（只有這兩張表）
-- 先匯入 schema.sql，再匯入本檔。相容 XAMPP MariaDB 10.4+ / MySQL 8
-- 所有測試帳號密碼皆為：Test1234!
-- 之後要補其他資料表，直接匯入完整的 seed.sql 即可（會清空 14 張表後重建）
-- ============================================================
USE quiz_system;
SET NAMES utf8mb4;

-- 清空舊資料：用 DELETE 且「子表先刪、父表後刪」，不依賴外鍵檢查設定
-- 請在「剛匯入 schema.sql 之後」使用本檔；若已匯入過完整 seed.sql，請先重新匯入 schema.sql
DELETE FROM courses;
DELETE FROM users;

-- 1. users
SET @pw = '$2y$10$ujXcB3X3Lm3dam9/LZlMRunwb2qb6gKaId31qRtiCFawmSQjag5oq';
INSERT INTO users (user_id, username, email, password_hash, role, is_active, created_at) VALUES
  (1, '系統管理員', 'admin@example.edu.tw', @pw, 'admin', 1, '2026-08-30 09:00:00'),
  (2, '陳建宏老師', 'teacher1@example.edu.tw', @pw, 'teacher', 1, '2026-08-30 09:00:00'),
  (3, '林美玲老師', 'teacher2@example.edu.tw', @pw, 'teacher', 1, '2026-08-30 09:00:00'),
  (4, '張志豪老師', 'teacher3@example.edu.tw', @pw, 'teacher', 1, '2026-08-30 09:00:00'),
  (5, '王小明', 's001@example.edu.tw', @pw, 'student', 1, '2026-08-30 09:00:00'),
  (6, '陳怡君', 's002@example.edu.tw', @pw, 'student', 1, '2026-08-30 09:00:00'),
  (7, '林冠宇', 's003@example.edu.tw', @pw, 'student', 1, '2026-08-30 09:00:00'),
  (8, '張雅婷', 's004@example.edu.tw', @pw, 'student', 1, '2026-08-30 09:00:00'),
  (9, '李承恩', 's005@example.edu.tw', @pw, 'student', 1, '2026-08-30 09:00:00'),
  (10, '黃詩涵', 's006@example.edu.tw', @pw, 'student', 1, '2026-08-30 09:00:00'),
  (11, '吳俊廷', 's007@example.edu.tw', @pw, 'student', 1, '2026-08-30 09:00:00'),
  (12, '劉思妤', 's008@example.edu.tw', @pw, 'student', 1, '2026-08-30 09:00:00'),
  (13, '蔡宗翰', 's009@example.edu.tw', @pw, 'student', 1, '2026-08-30 09:00:00'),
  (14, '楊筱涵', 's010@example.edu.tw', @pw, 'student', 1, '2026-08-30 09:00:00'),
  (15, '許哲維', 's011@example.edu.tw', @pw, 'student', 1, '2026-08-30 09:00:00'),
  (16, '鄭佳穎', 's012@example.edu.tw', @pw, 'student', 1, '2026-08-30 09:00:00'),
  (17, '謝宇軒', 's013@example.edu.tw', @pw, 'student', 1, '2026-08-30 09:00:00'),
  (18, '郭怡萱', 's014@example.edu.tw', @pw, 'student', 1, '2026-08-30 09:00:00'),
  (19, '洪柏翰', 's015@example.edu.tw', @pw, 'student', 1, '2026-08-30 09:00:00'),
  (20, '曾郁婷', 's016@example.edu.tw', @pw, 'student', 1, '2026-08-30 09:00:00'),
  (21, '周家豪', 's017@example.edu.tw', @pw, 'student', 1, '2026-08-30 09:00:00'),
  (22, '蕭雅文', 's018@example.edu.tw', @pw, 'student', 1, '2026-08-30 09:00:00'),
  (23, '賴冠廷', 's019@example.edu.tw', @pw, 'student', 1, '2026-08-30 09:00:00'),
  (24, '徐若瑜', 's020@example.edu.tw', @pw, 'student', 1, '2026-08-30 09:00:00');

-- s020（user_id 24）標記為「須先改密碼」，用來測試 F-22 的強制改密碼流程
UPDATE users SET must_change_password = 1 WHERE user_id = 24;

-- 2. courses
INSERT INTO courses (course_id, teacher_id, name, description, created_at) VALUES
  (1, 2, '資料庫與網頁設計', 'MySQL 資料庫、PHP 後端與網頁安全實作', '2026-08-31 09:00:00'),
  (2, 2, 'PHP 程式設計', 'PHP 語法、陣列函式與表單處理', '2026-08-31 09:00:00'),
  (3, 3, '資料結構', '陣列、堆疊、佇列與排序演算法', '2026-08-31 09:00:00'),
  (4, 4, '計算機概論', '電腦硬體與網路基礎', '2026-08-31 09:00:00');
