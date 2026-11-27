# 線上題庫與測驗系統（Quiz System）

「資料庫與網頁設計」課程專題。老師建立題庫與考卷，學生線上限時作答，交卷後自動批改，並提供成績查詢、統計報表與錯題本。

**技術**：PHP 8.2 + MySQL 8.0 + HTML / CSS / JavaScript（REST API，前後端分離）

> 目前階段：**規劃書 + 資料庫 + 後端 M0～M5**。資料庫（`database/`）、後端骨架、帳號（F-01、F-02、F-22）、課程／修課／章節／題庫（F-03～F-06）、考卷與組卷與發佈（F-08～F-10）已完成並實測；作答與自動批改與成績與逾時處理（F-11、F-12、F-14、F-18）也已完成；簡答題評分、標籤、統計、收藏與錯題本、使用者管理（F-07、F-13、F-15、F-16、F-17）也已完成。**後端功能已全部完成，只剩前端頁面。**規劃文件放在 [`docs/`](docs/)。

## 規劃書

每份文件可獨立閱讀，但功能 ID（`F-xx`）、資料表名稱、端點路徑與錯誤碼在所有文件間一致，可交叉引用。

| # | 文件 | 內容 | 先讀對象 |
| --- | --- | --- | --- |
| 00 | [系統概觀](docs/00-overview.md) | 系統是什麼、系統邊界、名詞定義、使用者類型 | 所有人，先讀這份 |
| 01 | [功能描述與優先級](docs/01-features.md) | 功能清單 F-01 ~ F-22、規則摘要、P0 ~ P3 | 全員 |
| 02 | [資料庫設計](docs/02-database.md) | 14 張資料表、ER 圖、約束、索引、查詢範例 | 後端、資料庫 |
| 03 | [端點規劃](docs/03-api.md) | REST API 全表、request / response、錯誤碼 | 後端、前端 |
| 04 | [行為邏輯](docs/04-behavior.md) | 流程、狀態機、權限矩陣、業務規則 | 後端 |
| 05 | [技術架構](docs/05-architecture.md) | 專案結構、分層、設定、部署、測試策略 | 後端 |

## 怎麼使用這份規劃書

1. 先讀 00 了解系統與名詞，再看 01 的優先級，從 **P0** 開始實作。
2. 資料庫以 02 為準，建表語法將放在 `database/schema.sql`。
3. 03 裡的 request / response 範例就是前後端的契約，前端可以直接照這個 mock。
4. 實作時發現規劃與現實不合，**直接修改文件**，並在 commit message 說明原因。

## 預計的專案結構

完整說明見 [05 §2](docs/05-architecture.md#2-專案結構)。

```text
quiz-system/
├─ public/        # 網站根目錄（前端頁面 + api/index.php）
├─ src/           # 後端：Core / Http / Services / Repositories / Serializers / Support
├─ config/
├─ database/      # schema.sql、seed.sql、create_user.sql（已完成）
├─ bin/           # 排程腳本
├─ tests/
└─ docs/          # 規劃書
```

## 開發環境（VS Code）

開啟專案後，VS Code 會提示安裝建議擴充套件（定義在 `.vscode/extensions.json`），按「全部安裝」即可。

| 擴充套件 | 用途 |
| --- | --- |
| PHP Intelephense | PHP 補全、跳轉、型別檢查、格式化 |
| PHP Debug | 搭配 Xdebug 下中斷點 |
| Database Client | 直接在 VS Code 連 MySQL、看資料表、跑 SQL |
| REST Client | 用 `tests/api/*.http` 手動測試 API |
| EditorConfig / Prettier | 統一縮排與換行、格式化 JS / JSON / HTML / CSS |
| Markdown Preview Mermaid | 預覽規劃書裡的 ER 圖與狀態機 |
| Docker、GitLens | 容器管理、Git 歷史 |

### 快速開始（XAMPP）

1. 啟動 XAMPP 的 **Apache** 與 **MySQL**（其實是 MariaDB）。
2. 開啟 <http://localhost/phpmyadmin>，依序匯入 `database/schema.sql`、`database/seed.sql`。**匯入 seed 前先點左邊的 `quiz_system`**。步驟見 [`database/README.md`](database/README.md)。
3. 複製 `.env.example` 為 `.env`。XAMPP 剛裝好時 `root` 沒有密碼，預設值可以直接用；之後建議改用 `database/create_user.sql` 建立的 `quiz_app`。
4. 確認 PHP 在終端機可用：`php -v` 要顯示 8.2 以上。若找不到指令，用 `C:\xampp\php\php.exe`，或把 `C:\xampp\php` 加進 Windows 的 PATH。
5. 在專案根目錄啟動開發伺服器（VS Code：`Ctrl+Shift+B` 執行 **Dev Server**）：

   ```bat
   php -S localhost:8000 -t public public/router.php
   ```

6. 瀏覽器開啟 <http://localhost:8000/api/v1/health>，看到 `"database":"ok"` 就代表 PHP 與資料庫都接上了。
7. 執行冒煙測試（另開一個終端機）：

   ```bat
   php tests/smoke_m1.php
   ```

   測試登入、改密碼要用到管理員帳號，可再加上 `ADMIN_EMAIL` 與 `ADMIN_PASSWORD` 環境變數（密碼在 `credentials-private.csv`）。

   M2（課程、修課、章節、題庫）的測試：

   ```bat
   php tests\smoke_m2.php
   ```

   它會用 `teacher1`、`teacher2`（以及選用的管理員）的帳號跑 130 多項檢查，密碼從 `TEACHER1_PASSWORD`、`TEACHER2_PASSWORD`、`ADMIN_PASSWORD` 環境變數，或專案根目錄（或上一層）的 `credentials-private.csv` 讀取。測試資料都以 `[smoke]` 開頭，結束時會自動清除。

   M3（考卷、組卷、隨機抽題、發佈檢查、狀態轉換）的測試，帳號需求與 M2 相同：

   ```bat
   php tests\smoke_m3.php
   ```

   M4（作答、自動批改、成績與解析、逾時）的測試，帳號需求相同：

   ```bat
   php tests\smoke_m4.php
   ```

   逾時作答的排程處理（建議每分鐘一次，Windows 用工作排程器；沒排程也沒關係，讀取時會順便結算）：

   ```bat
   C:\xampp\php\php.exe bin\finalize_overdue.php
   ```

   M5（標籤、簡答題評分、統計、收藏與錯題本、使用者管理）的測試。使用者管理與刪除標籤需要管理員密碼（`ADMIN_PASSWORD`），沒有的話會自動略過那幾項：

   ```bat
   php tests\smoke_m5.php
   ```

   另有**不需要伺服器與資料庫**的單元測試，驗證題型規則、開放時段（邊界到秒）、發佈檢查與日期解析：

   ```bat
   php tests\unit_domain.php
   ```
8. 也可以用 `tests/api/01-auth-and-exam.http`（VS Code 的 REST Client）手動送請求。

**改用 XAMPP 的 Apache（不用 `php -S`）：** 把專案放在 `C:\xampp\htdocs\quiz-system`，網址改成 <http://localhost/quiz-system/public/api/v1/health>。`public/api/.htaccess` 需要 Apache 的 `mod_rewrite`（XAMPP 預設已開）。

### 目前已完成的端點

| 方法 | 路徑 | 功能 |
| --- | --- | --- |
| GET | `/health` | 健康檢查（含資料庫連線） |
| POST | `/auth/register` | 學生註冊 |
| POST | `/auth/login`、`/auth/logout` | 登入、登出 |
| GET / PATCH | `/me` | 目前使用者、修改姓名／email |
| PUT | `/me/password` | 修改密碼 |
| POST | `/admin/users/{user_id}/reset-password` | 管理員重設密碼（F-22） |
| GET / POST | `/courses` | 課程列表、開課（F-03） |
| GET / PATCH / DELETE | `/courses/{course_id}` | 課程詳情、修改、刪除空課程 |
| POST / DELETE | `/courses/{course_id}/enrollment` | 加入課程、退選（F-04） |
| GET | `/courses/{course_id}/students` | 修課名單（課程擁有者、管理員） |
| DELETE | `/courses/{course_id}/students/{user_id}` | 移除學生 |
| GET / POST | `/courses/{course_id}/chapters` | 章節列表、新增章節（F-05） |
| PUT | `/courses/{course_id}/chapters/order` | 調整章節順序 |
| PATCH / DELETE | `/chapters/{chapter_id}` | 修改、刪除章節 |
| GET / POST | `/questions` | 題目列表（可篩選）、新增題目含選項與標籤（F-06） |
| GET / PUT / PATCH / DELETE | `/questions/{question_id}` | 詳情、整題取代、部分更新、軟刪除 |
| GET / POST | `/exams` | 考卷列表（學生只看得到自己已修課程中已發佈／已關閉的）、建立草稿（F-08） |
| GET / PATCH / DELETE | `/exams/{exam_id}` | 考卷詳情（學生版不含題目）、修改設定、刪除草稿 |
| PUT | `/exams/{exam_id}/questions` | 整份取代題目與配分（F-08） |
| POST | `/exams/{exam_id}/questions/random` | 隨機抽題加入（F-09） |
| POST | `/exams/{exam_id}/publish`、`/unpublish`、`/close` | 發佈（含發佈檢查）、撤回、關閉（F-10） |
| POST / GET | `/exams/{exam_id}/attempts` | 學生開始作答（F-11）／全班作答列表（老師、管理員） |
| GET | `/attempts/{attempt_id}` | 續答：題目與已暫存的答案（不含正確答案） |
| PUT | `/attempts/{attempt_id}/answers/{question_id}` | 暫存單題答案 |
| POST | `/attempts/{attempt_id}/submit` | 交卷並自動批改（F-12） |
| GET | `/attempts/{attempt_id}/result` | 成績；考卷結束後才有逐題結果與解析（F-14） |
| GET | `/me/attempts` | 我的作答紀錄 |
| PATCH | `/attempts/{attempt_id}/answers/{question_id}/grade` | 簡答題人工評分（F-13） |
| GET / POST / DELETE | `/tags`、`/tags/{tag_id}` | 標籤列表、新增、刪除（刪除僅管理員）（F-07） |
| GET | `/exams/{exam_id}/stats` | 考卷統計：平均、最高、最低、及格率、分數分佈（F-15） |
| GET | `/courses/{course_id}/stats/questions`、`/stats/chapters` | 各題答對率、班級章節弱點 |
| GET | `/me/stats/chapters` | 個人章節弱點 |
| GET | `/me/bookmarks`、`/me/wrong-questions` | 我的收藏、錯題本（F-16） |
| PUT / DELETE | `/me/bookmarks/{question_id}` | 收藏（含筆記）、取消收藏 |
| GET / POST / PATCH | `/admin/users`、`/admin/users/{user_id}` | 使用者列表、建立老師／管理員、改角色與停用（F-17） |

> `public/router.php` 只供 `php -S` 開發用；Apache / XAMPP 環境走 `public/api/.htaccess`。

### 常用任務（`Ctrl+Shift+P` → Tasks: Run Task）

| 任務 | 說明 |
| --- | --- |
| Dev Server（php -S :8000） | 啟動內建開發伺服器 |
| Composer Install | 安裝相依套件 |
| PHPUnit：全部 | 執行測試（`Ctrl+Shift+B` 旁的 Test 預設任務） |
| Docker：啟動 / 停止 | 啟停 `docker-compose.yml` |
| DB：重建（schema + seed，Docker） | 重新匯入資料庫 |

### 除錯（Xdebug）

1. 在 `php.ini` 啟用 Xdebug 3：`xdebug.mode=debug`、`xdebug.start_with_request=yes`、`xdebug.client_port=9003`。
2. 在 VS Code 選「Run and Debug」→ **PHP: Listen for Xdebug**（Docker 環境選 Docker 那一項）。
3. 在 Controller / Service 下中斷點，從瀏覽器或 REST Client 送請求即可停下。

## 本機環境（預計）

- PHP 8.2+、MySQL 8.0.16+ 或 MariaDB 10.4+、Apache（**XAMPP 即可**）
- 或使用 Docker Compose，見 [05 §9](docs/05-architecture.md#9-部署)

## 組員

| 姓名 | 學號 | 負責 |
| --- | --- | --- |
|  |  |  |
