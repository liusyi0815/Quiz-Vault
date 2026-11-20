-- ============================================================
-- 正式環境初始資料：只建立「一個」管理員帳號
-- 先匯入 schema.sql，再匯入本檔（不要匯入 seed.sql，那是含假帳號的展示資料）
-- ============================================================
USE quiz_system;
SET NAMES utf8mb4;

-- ▼ 匯入前請改成你自己的資料
SET @admin_name  = '系統管理員';
SET @admin_email = 'admin@example.edu.tw';   -- 改成你的真實 email（登入帳號）

-- ▼ 這是我產生的初始密碼的雜湊，初始密碼另存於 credentials-private.csv。
--   更安全的做法：自己產生一組再貼上
--   php -r "echo password_hash('你自己的密碼', PASSWORD_DEFAULT);"
SET @admin_hash  = '$2y$10$mkl4UUdnki5.MpIGLMv.x.YuNLceyExx.CiwbFkmlhU2EPLx1FeSW';

-- must_change_password = 1：管理員第一次登入就必須先改掉初始密碼
INSERT INTO users (username, email, password_hash, role, is_active, must_change_password)
VALUES (@admin_name, LOWER(@admin_email), @admin_hash, 'admin', 1, 1);

-- 其他老師帳號請登入系統後，由管理員在「使用者管理」建立（F-17）
