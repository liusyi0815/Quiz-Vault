# 資料庫

| 檔案 | 說明 |
| --- | --- |
| `schema.sql` | 建立資料庫 `quiz_system` 與 14 張資料表（會先 DROP 同名資料表，**開發用**） |
| `seed_01_users_courses.sql` | **分階段 ①**：只有 `users`（24 位）與 `courses`（4 門），先從這兩張表開始開發時使用 |
| `seed_production.sql` | **正式環境用**：只建立一個管理員帳號（首次登入須改密碼），不含任何假資料 |
| `seed.sql` | 開發 / 展示用完整測試資料：24 位使用者、4 門課、40 題、6 份考卷、39 次作答（會清空 14 張表後重建） |
| `migrations/001_add_must_change_password.sql` | 給已匯入舊版 schema 的資料庫補上 `users.must_change_password`（F-22）；重新匯入 `schema.sql` 也可 |
| `create_user.sql` | 建立網站用的低權限帳號 `quiz_app`（建議） |

設計說明見 [docs/02-database.md](../docs/02-database.md)。

## 使用 XAMPP 匯入（phpMyAdmin）

1. 開啟 **XAMPP Control Panel**，啟動 **Apache** 與 **MySQL**。
   （XAMPP 的 MySQL 實際上是 **MariaDB**，本專案的 SQL 已確認相容。）
2. 瀏覽器開啟 <http://localhost/phpmyadmin>。
3. 左側**不要選任何資料庫**，點上方「**匯入**」→ 選擇 `database/schema.sql` → 按「**執行**」。
4. 再匯入一次，選擇 `database/seed_01_users_courses.sql`（只有使用者與課程），或 `database/seed.sql`（完整資料）。
5. 左側出現 `quiz_system`，裡面有 14 張資料表即成功。
6. （建議）到「SQL」頁籤貼上 `create_user.sql` 的內容執行，並把密碼改掉。

> 匯入檔案大小限制預設為 2 MB，`seed.sql` 約 100 KB，沒有問題。

## 使用指令匯入

Windows（XAMPP）：

```bat
C:\xampp\mysql\bin\mysql.exe -u root --default-character-set=utf8mb4 < database\schema.sql
C:\xampp\mysql\bin\mysql.exe -u root --default-character-set=utf8mb4 < database\seed.sql
```

macOS / Linux：

```bash
mysql -u root --default-character-set=utf8mb4 < database/schema.sql
mysql -u root --default-character-set=utf8mb4 < database/seed.sql
```

XAMPP 預設 `root` **沒有密碼**；若你設定過，指令加上 `-p`。

## 測試帳號與密碼

測試資料（`seed.sql`、`seed_01_users_courses.sql`）裡的 24 個帳號，**每個人都有各自的隨機密碼與獨立雜湊**。密碼明碼不放在 repo，而是在另外一份 `credentials-private.csv`（已列入 `.gitignore`，請勿上傳 GitHub、勿貼到群組）。

| 角色 | Email |
| --- | --- |
| 管理員 | `admin@example.edu.tw` |
| 老師 | `teacher1@example.edu.tw`（開「資料庫與網頁設計」與「PHP 程式設計」）、`teacher2@…`、`teacher3@…` |
| 學生 | `s001@example.edu.tw` ~ `s020@example.edu.tw`（**s020 被標記為須先改密碼**，用來測試 F-22） |

## 正式上線（真的架到網路上）

**不要**匯入 `seed.sql`：它有 24 個假帳號，雖然密碼不公開，仍不該出現在正式站。改用：

1. 匯入 `schema.sql`。
2. 開啟 `seed_production.sql`，把 `@admin_email` 改成你的真實 email，（建議）把 `@admin_hash` 換成你自己產生的雜湊：
   `php -r "echo password_hash('你自己的密碼', PASSWORD_DEFAULT);"`
3. 匯入 `seed_production.sql`。這會建立一個管理員，`must_change_password = 1`，第一次登入就必須改掉初始密碼。
4. 其他老師帳號由管理員登入後在系統內建立（F-17），學生可自行註冊。
5. 建立低權限資料庫帳號（`create_user.sql`），並完成 [05 §9.4 上線前檢查清單](../docs/05-architecture.md#94-上線前檢查清單)。

## 測試資料裡有哪些情境

| 情境 | 位置 |
| --- | --- |
| 已關閉、可看解析的考卷（含簡答題評分） | 考卷 1「SQL 與資料表設計小考」、考卷 5「資料結構隨堂測」 |
| 進行中的考卷，含全班已交卷、待評分簡答題 | 考卷 2「JOIN 與交易期中測驗」 |
| 作答中（可測續答與倒數） | 考卷 2 的兩筆 `in_progress`（學生 s011、s012），**匯入後約 30 分鐘內有效** |
| 草稿考卷（還沒發佈） | 考卷 3「PHP 與網頁安全綜合測驗」 |
| 無結束時間、可多次作答 | 考卷 4「PHP 基礎測驗」 |
| 已發佈但尚未開放（開始時間在未來） | 考卷 6「SQL 補考」 |
| 重考（同一人兩次作答） | 考卷 1 的 s002、s004、s008 |
| 收藏與錯題本 | 16 筆收藏，皆來自已結束考卷中答錯的題目 |

## 常見問題

| 狀況 | 處理方式 |
| --- | --- |
| 中文變成 `???` | 匯入時沒用 UTF-8。phpMyAdmin 匯入頁面的「檔案字元集」選 `utf-8`；指令加上 `--default-character-set=utf8mb4` |
| `#1701 Cannot truncate a table referenced in a foreign key constraint` | 舊版 seed 用 `TRUNCATE` 清資料所致，已改成 `DELETE`。請重新下載最新版 `seed*.sql`，**先重新匯入 `schema.sql`**，再匯入 seed |
| `#1451 Cannot delete or update a parent row` | 外鍵保護正常運作：有作答紀錄的考卷、使用者、題目選項都不能直接刪除 |
| `#4025 CONSTRAINT ... failed`（MariaDB）／`3819`（MySQL） | 資料違反 CHECK 約束，例如難度不在 1~3 |
| 重新匯入後想還原資料 | 重新匯入 `schema.sql` + `seed.sql` 即可（會清空重建） |
