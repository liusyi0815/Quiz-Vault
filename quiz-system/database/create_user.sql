-- 建立網站執行時使用的低權限帳號（建議，不要讓 PHP 直接用 root）
-- 在 phpMyAdmin 的「SQL」頁籤執行，或：mysql -u root < database/create_user.sql
-- 請先把下面的密碼 change_me 改掉，並與 .env 的 DB_PASS 一致。

CREATE USER IF NOT EXISTS 'quiz_app'@'localhost' IDENTIFIED BY 'change_me';

-- 只給資料的新增、查詢、修改、刪除；沒有 DROP / ALTER，被入侵時傷害較小
GRANT SELECT, INSERT, UPDATE, DELETE ON quiz_system.* TO 'quiz_app'@'localhost';
FLUSH PRIVILEGES;
