# 線上題庫與測驗系統（Quiz System）

「資料庫與網頁設計」課程專題。老師建立題庫與考卷，學生線上限時作答，交卷後自動批改，並提供成績查詢、統計報表與錯題本。

**技術**：PHP 8.2 + MySQL 8.0 + HTML / CSS / JavaScript（REST API，前後端分離）

> 目前階段：**規劃書 + 資料庫 + 後端 M0～M1**。資料庫（`database/`）與後端的基礎骨架、註冊／登入／登出／改密碼、管理員重設密碼（F-22）已完成並實測；課程、題庫、考卷、作答（M2 之後）與前端頁面尚未開始。規劃文件放在 [`docs/`](docs/)。

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
