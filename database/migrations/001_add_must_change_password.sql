-- 001：users 新增 must_change_password（F-22 管理員重設密碼）
-- 給「已經匯入舊版 schema.sql」的資料庫使用；新匯入的 schema.sql 已包含此欄位。
-- 只能執行一次，重複執行會出現 Duplicate column name 錯誤。
USE quiz_system;

ALTER TABLE users
  ADD COLUMN must_change_password TINYINT(1) NOT NULL DEFAULT 0 AFTER is_active;
