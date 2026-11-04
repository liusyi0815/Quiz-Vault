-- ============================================================
-- 分階段測試資料 ① users + courses（只有這兩張表）
-- 先匯入 schema.sql，再匯入本檔。相容 XAMPP MariaDB 10.4+ / MySQL 8
-- 每位使用者各自有一組隨機密碼與雜湊，密碼另存於 credentials-private.csv（請勿上傳 GitHub）
-- 之後要補其他資料表，直接匯入完整的 seed.sql 即可（會清空 14 張表後重建）
-- ============================================================
USE quiz_system;
SET NAMES utf8mb4;

-- 清空舊資料：用 DELETE 且「子表先刪、父表後刪」，不依賴外鍵檢查設定
-- 請在「剛匯入 schema.sql 之後」使用本檔；若已匯入過完整 seed.sql，請先重新匯入 schema.sql
DELETE FROM courses;
DELETE FROM users;

-- 1. users
INSERT INTO users (user_id, username, email, password_hash, role, is_active, created_at) VALUES
  (1, '系統管理員', 'admin@example.edu.tw', '$2y$10$Wl3JuQf3Vm3ek1.x451C6.POBPtdDoT0/PQ8BP5kySANoYTpbT6qq', 'admin', 1, '2026-08-30 09:00:00'),
  (2, '陳建宏老師', 'teacher1@example.edu.tw', '$2y$10$G6Hd9hJjDK.oqufWrTwXQOOg8hfXrg9vM2JsDLrtQzx3ojcYkDqcC', 'teacher', 1, '2026-08-30 09:00:00'),
  (3, '林美玲老師', 'teacher2@example.edu.tw', '$2y$10$BD4jAeqDo90eVwoN2VM/SuEYTpi4xiYMmB.EFHkrUmtlp/PnGWsTq', 'teacher', 1, '2026-08-30 09:00:00'),
  (4, '張志豪老師', 'teacher3@example.edu.tw', '$2y$10$8oQzMTbrnTlPIb1ZQ3oy7.899fC6DjZYhocKyFsauJFEUGnt68yr6', 'teacher', 1, '2026-08-30 09:00:00'),
  (5, '王小明', 's001@example.edu.tw', '$2y$10$lAXHWXWIBcTVlLVY/ooL5e7m.zt71ghb/Wdr01jAizM.hf5V0k7iC', 'student', 1, '2026-08-30 09:00:00'),
  (6, '陳怡君', 's002@example.edu.tw', '$2y$10$dJRIP17T7ms6yo6aE88qwudJJBTf0yS8GiyGtHYF.WPyubjiwH1HW', 'student', 1, '2026-08-30 09:00:00'),
  (7, '林冠宇', 's003@example.edu.tw', '$2y$10$nvz2xj9d6kb2Z7JRhfhw1OWfQI5JL29DFt52UWyry5PmCSKhKUQG6', 'student', 1, '2026-08-30 09:00:00'),
  (8, '張雅婷', 's004@example.edu.tw', '$2y$10$SJW1Z3r0I3bqAr8eavx8bOGu6Pyseed4wGHTdpD0EGv1o7CB7EBTa', 'student', 1, '2026-08-30 09:00:00'),
  (9, '李承恩', 's005@example.edu.tw', '$2y$10$OuQz83fuX61PZrcjWika9.MCtBgDkCjTsDgyBv7TGzOtlZLYaOK8W', 'student', 1, '2026-08-30 09:00:00'),
  (10, '黃詩涵', 's006@example.edu.tw', '$2y$10$unDmtYzaDB3KgLWHOesDue8V0XCVMsVJLjaPo/bW6/IpAyPmJZcPW', 'student', 1, '2026-08-30 09:00:00'),
  (11, '吳俊廷', 's007@example.edu.tw', '$2y$10$9O..2kQGkEjxSTEmV7OPsOBNBqDSFKRpc9Tt95bmUTyd50vw0Us5W', 'student', 1, '2026-08-30 09:00:00'),
  (12, '劉思妤', 's008@example.edu.tw', '$2y$10$S/3DehHfClw7lTzK0FO9yOqDEn4kcmsdon70.6TtAwh2hO2GOQShG', 'student', 1, '2026-08-30 09:00:00'),
  (13, '蔡宗翰', 's009@example.edu.tw', '$2y$10$M4bDnIHLY4aMzluosvNOreVqT3HfROuQq94fcBFfdYvuHs4ntvPo2', 'student', 1, '2026-08-30 09:00:00'),
  (14, '楊筱涵', 's010@example.edu.tw', '$2y$10$0xgvxb2SkUtJXbRtXVoZPuhJyBnjPKcVWNwlsZe/n4ll7SMYDfvIi', 'student', 1, '2026-08-30 09:00:00'),
  (15, '許哲維', 's011@example.edu.tw', '$2y$10$rl2zqCRsQUwUS2IgCsA9ee/zLp.1ZVZQaUF42HvV5H75CRw0s0bTK', 'student', 1, '2026-08-30 09:00:00'),
  (16, '鄭佳穎', 's012@example.edu.tw', '$2y$10$9u4JH2SL/Z1sGo01TZ1WQul15TcQVwaHlRdRzPCSmYWVlnbhgCSAK', 'student', 1, '2026-08-30 09:00:00'),
  (17, '謝宇軒', 's013@example.edu.tw', '$2y$10$TYRfHmf3h6tzcY14eHz5jOoH39R0G9hb8OvCGboMsFFxc6w/XRx2i', 'student', 1, '2026-08-30 09:00:00'),
  (18, '郭怡萱', 's014@example.edu.tw', '$2y$10$Z9R3W96EM5LTKX/hOgklnOg4wpCQjhksU0Dp/dXKBfs/NSDiPrkbS', 'student', 1, '2026-08-30 09:00:00'),
  (19, '洪柏翰', 's015@example.edu.tw', '$2y$10$wU2zLNZ5.8n/IIdEDY6cRugrJc4hAYjKU/wbC/9ar6c538Onl1JhS', 'student', 1, '2026-08-30 09:00:00'),
  (20, '曾郁婷', 's016@example.edu.tw', '$2y$10$2wCRCBs.U/adxRU1DDKiXuZNj0XM6Cn9I2NyoRRmCVboxERAwQfnu', 'student', 1, '2026-08-30 09:00:00'),
  (21, '周家豪', 's017@example.edu.tw', '$2y$10$p4VCZk7.o.mBOT8wIa9pg.eH7Z9iVFkaD1HJZr8mpOOIFs5/oacty', 'student', 1, '2026-08-30 09:00:00'),
  (22, '蕭雅文', 's018@example.edu.tw', '$2y$10$EvfLRh9iAQ5ofirMpdjMX.BJ9hRRyF5Yi8QTQ8Nm9pUXqtImQ//dO', 'student', 1, '2026-08-30 09:00:00'),
  (23, '賴冠廷', 's019@example.edu.tw', '$2y$10$xQs0jFHBBfIuWhT.UavC5OVb3oMFQR3gdvyudJqcLruJxbb6AT.Vi', 'student', 1, '2026-08-30 09:00:00'),
  (24, '徐若瑜', 's020@example.edu.tw', '$2y$10$zXCxxhw1WAqxaW/ANm4xcO5rWUmpey3eHnP6JzMnSc.1KPXCX6Gqa', 'student', 1, '2026-08-30 09:00:00');

-- s020（user_id 24）標記為「須先改密碼」，用來測試 F-22 的強制改密碼流程
UPDATE users SET must_change_password = 1 WHERE user_id = 24;

-- 2. courses
INSERT INTO courses (course_id, teacher_id, name, description, created_at) VALUES
  (1, 2, '資料庫與網頁設計', 'MySQL 資料庫、PHP 後端與網頁安全實作', '2026-08-31 09:00:00'),
  (2, 2, 'PHP 程式設計', 'PHP 語法、陣列函式與表單處理', '2026-08-31 09:00:00'),
  (3, 3, '資料結構', '陣列、堆疊、佇列與排序演算法', '2026-08-31 09:00:00'),
  (4, 4, '計算機概論', '電腦硬體與網路基礎', '2026-08-31 09:00:00');
