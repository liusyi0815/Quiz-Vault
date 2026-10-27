# 05 技術架構

## 1. 技術選型

| 項目 | 選用 | 理由 |
| --- | --- | --- |
| 後端語言 | PHP 8.2 以上（`declare(strict_types=1)`） | 課程指定；8.x 有型別、`match`、具名參數 |
| 資料庫 | **XAMPP 內建 MariaDB 10.4+**，或 MySQL 8.0.16+ | `CHECK` 需實際生效（MariaDB 10.2.1+ / MySQL 8.0.16+）；相容性見 [02 §3.8](02-database.md#38-xamppmariadb與-mysql-8-的相容性) |
| DB 存取 | PDO（`pdo_mysql`） + Prepared Statement | 防 SQL Injection；不使用 ORM，SQL 清楚可展示 |
| Web Server | Apache 2.4（`mod_rewrite`） | XAMPP 內建；以 `.htaccess` 轉送 API |
| 前端 | HTML5、CSS3、JavaScript（ES Modules）、Bootstrap 5 | 靜態頁面以 `fetch` 呼叫 API；不需前端建置工具 |
| 圖表 | Chart.js（P1） | 統計報表 |
| 套件管理 | Composer（PSR-4 autoload） | 少量依賴：`vlucas/phpdotenv`、`phpunit/phpunit` |
| 測試 | PHPUnit + 真實 MySQL 測試資料庫 | 外鍵、鎖、CHECK 無法用 SQLite 忠實模擬 |
| 開發工具 | VS Code（共用設定在 `.vscode/`，見 README）、phpMyAdmin、MySQL Workbench、REST Client / Postman、Git | 統一編輯器設定與除錯流程 |
| 容器 | Docker Compose（選用） | 讓組員環境一致 |

**不使用框架的取捨**：整個後端只有約 50 個端點，自寫一個精簡路由器即可，且能在報告中完整說明請求如何被處理。若改用 Slim 或 Laravel，分層與資料庫設計不需變動。

## 2. 專案結構

```text
quiz-system/
├─ public/                      # Apache DocumentRoot，只有這裡對外
│  ├─ index.html                # 首頁
│  ├─ login.html  register.html
│  ├─ student/                  # 學生頁面（my-courses、exam、result、wrong-questions …）
│  ├─ teacher/                  # 老師頁面（courses、questions、exam-builder、grading、stats …）
│  ├─ admin/                    # 管理員頁面（users）
│  ├─ assets/
│  │  ├─ css/
│  │  ├─ js/
│  │  │  ├─ api.js              # fetch 包裝：帶 CSRF、統一處理錯誤信封
│  │  │  └─ pages/              # 每個頁面各自的腳本
│  │  └─ img/
│  └─ api/
│     ├─ index.php              # 前端控制器：所有 /api/v1/* 都進這裡
│     └─ .htaccess              # 重寫規則
├─ src/                         # 後端程式（namespace App\）
│  ├─ Core/                     # Router、Request、Response、Database、Session、Config、Validator
│  ├─ Http/
│  │  ├─ Controllers/           # 每個 domain 一個 Controller
│  │  └─ Middleware/            # Auth、MustChangePassword、Csrf、Role
│  ├─ Services/                 # 業務規則與 transaction 邊界
│  ├─ Repositories/             # 所有 SQL
│  ├─ Domain/                   # 純邏輯：AttemptDeadline、Exam 狀態判斷、題型規則
│  ├─ Serializers/              # 回應結構（學生版 / 老師版分開）
│  └─ Support/                  # 錯誤類別、日期工具、字串工具
├─ config/
│  ├─ app.php                   # 讀取 .env 的設定
│  └─ routes.php                # 路由表
├─ database/
│  ├─ schema.sql                # 完整建表（含 CHECK、索引）
│  ├─ seed.sql                  # 測試資料
│  └─ migrations/               # 之後的結構變更：NNN_description.sql
├─ bin/
│  └─ finalize_overdue.php      # 逾時作答處理（排程）
├─ tests/
│  ├─ Unit/
│  ├─ Integration/
│  ├─ Api/
│  └─ Concurrency/
├─ docs/                        # 本規劃文件、ER 圖
├─ .env.example
├─ composer.json
├─ docker-compose.yml  Dockerfile
└─ phpunit.xml
```

安全重點：只有 `public/` 是網站根目錄，`src/`、`config/`、`.env`、`database/` 都在網頁無法直接存取的位置。

## 3. 分層與 transaction

```text
HTTP 請求
  → public/api/index.php
  → Router（比對方法與路徑，建立 middleware 鏈）
  → Middleware：Auth → MustChangePassword → Csrf → Role
  → Controller   （解析輸入、驗證格式、呼叫 Service、輸出 Response）
  → Service      （業務規則、擁有權檢查、transaction 邊界）
  → Repository   （PDO 與 SQL）
  → MySQL
```

| 層 | 職責 | 禁止 |
| --- | --- | --- |
| Controller | 取參數、驗證格式、呼叫 Service、決定 HTTP 狀態碼 | 寫 SQL、做業務判斷 |
| Service | 業務規則、狀態機、權限（擁有權）、transaction | 直接拼 HTTP 回應 |
| Repository | SQL 與資料映射 | `commit` / `rollBack`、業務判斷 |
| Domain | 純函式與值物件（可不連 DB 測試） | 存取 DB、讀取 session |
| Serializer | 決定回應欄位，**學生版與老師版是不同類別** | 在學生版出現 `is_correct` |

**Transaction 慣例**

```php
public function submit(int $attemptId, int $userId, array $finalAnswers): array
{
    $this->db->beginTransaction();
    try {
        $attempt = $this->attempts->lockForUpdate($attemptId, $userId);   // SELECT ... FOR UPDATE
        if ($attempt['status'] !== 'in_progress') {
            $this->db->commit();
            return $this->resultFor($attempt);                            // 冪等
        }
        $this->grading->gradeAttempt($attemptId);                         // §04-9 的三段 SQL
        $this->db->commit();
        return $this->resultFor($this->attempts->find($attemptId));
    } catch (\Throwable $e) {
        $this->db->rollBack();
        throw $e;
    }
}
```

- Service 擁有 transaction；Repository 只用傳入的同一個 `PDO` 連線。
- 一個請求使用一條連線（`Database::connection()` 為請求內單例）。
- 例外一律 rollback 後重新拋出，由統一的錯誤處理轉成回應，Repository 不吞例外。
- 跨 Service 呼叫走 Service，不直接使用別的 domain 的 Repository。

## 4. 設定

設定放在專案根目錄的 `.env`（**不進版控**），並提供 `.env.example`。

| 變數 | 預設 | 說明 |
| --- | --- | --- |
| `APP_ENV` | `development` | `development` / `production` / `test` |
| `APP_DEBUG` | `true` | 正式環境必須 `false`，錯誤不得顯示堆疊 |
| `APP_TIMEZONE` | `Asia/Taipei` | PHP 與 MySQL session 時區 |
| `DB_HOST` | `127.0.0.1` | |
| `DB_PORT` | `3306` | |
| `DB_NAME` | `quiz_system` | |
| `DB_USER` | `quiz_app` | 應用程式帳號，最小權限 |
| `DB_PASS` | 必填 | |
| `DB_CHARSET` | `utf8mb4` | |
| `SESSION_NAME` | `QUIZSESSID` | |
| `SESSION_LIFETIME` | `7200` | 閒置 2 小時後失效 |
| `ATTEMPT_GRACE_SECONDS` | `5` | 逾時寬限，見 [04 §7.3](04-behavior.md#73-截止時間) |
| `DEFAULT_PAGE_SIZE` | `20` | 分頁預設 |
| `MAX_PAGE_SIZE` | `100` | 分頁上限 |

**PDO 連線設定**

```php
$pdo = new PDO($dsn, $user, $pass, [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,     // 真正的 prepared statement
]);
$pdo->exec("SET time_zone = '+08:00'");
```

資料庫帳號分兩種：

| 帳號 | 權限 | 用途 |
| --- | --- | --- |
| `quiz_admin` | 建表、改結構 | 只在部署時執行 `schema.sql` 與 migrations |
| `quiz_app` | 對 `quiz_system.*` 的 `SELECT, INSERT, UPDATE, DELETE` | 網站執行時使用；**沒有 DROP / ALTER** |

## 5. 認證與安全

| 面向 | 做法 |
| --- | --- |
| 密碼 | `password_hash(PASSWORD_DEFAULT)`、`password_verify()`、`password_needs_rehash()`；不記錄、不回傳 |
| Session | `session_start()` 前設定 Cookie：`httponly`、`samesite=Lax`、正式環境 `secure`；登入與登出時 `session_regenerate_id(true)`；`session.use_strict_mode=1` |
| CSRF | 登入時產生 `bin2hex(random_bytes(32))` 存於 session；所有 `POST / PUT / PATCH / DELETE` 要求 `X-CSRF-Token`，以 `hash_equals()` 比對 |
| SQL Injection | 全部使用 Prepared Statement；`LIMIT`、排序欄位等無法綁定的動態部分只能來自程式內白名單 |
| XSS | API 只回 JSON；前端以 `textContent` 顯示使用者內容，**不使用 `innerHTML` 拼接題目與答案**；PHP 若輸出 HTML 一律 `htmlspecialchars()` |
| 答案外洩 | 學生端 Serializer 與老師端分離；整合測試明確驗證學生的作答回應不含 `is_correct` |
| 臨時密碼 | 管理員重設時以 `random_int()` 產生 12 碼，**只回傳一次**；資料庫只存雜湊；不寫 log；回應加 `Cache-Control: no-store` |
| 強制改密碼 | `MustChangePassword` middleware：旗標為 1 時只放行改密碼、查自己、登出 |
| 權限 | Middleware 檢查角色，Service 檢查擁有權；兩層都要通過 |
| 輸入驗證 | Controller 以 `Validator` 檢查型別、長度、列舉值；失敗統一 `VALIDATION_FAILED` |
| 登入防爆破 | P2：同一 email／IP 連續失敗後短暫鎖定 |
| 錯誤訊息 | 正式環境不顯示 SQL 與堆疊，只回 `request_id` |

## 6. 背景工作

目前只有一種背景工作：**逾時作答處理**（[04 §12](04-behavior.md#12-逾時處理)）。

- P0：讀取時處理（`AttemptService::finalizeIfOverdue()`），不需要任何排程。
- P1：`php bin/finalize_overdue.php`，每分鐘執行一次。
  - Linux：`* * * * * php /var/www/quiz-system/bin/finalize_overdue.php`
  - Windows / XAMPP：工作排程器每分鐘執行 `php.exe`。
- 腳本必須**冪等**；每筆作答獨立 transaction，先 `SELECT ... FOR UPDATE` 鎖住該筆並重新檢查狀態，避免多個執行個體重複處理（不使用 MariaDB 10.4 不支援的 `SKIP LOCKED`）。
- 腳本結束時輸出處理筆數，方便觀察。

## 7. 錯誤處理

`index.php` 最外層以 `try / catch (Throwable)` 包住，統一轉成錯誤信封（格式見 [03 §1](03-api.md#1-通則)）。

| 來源 | 轉換 |
| --- | --- |
| `AppException`（含 `code`、HTTP 狀態、`details`） | 直接輸出 |
| `Validator` 失敗 | `400 VALIDATION_FAILED` |
| 路由找不到 / 方法不符 | `404 NOT_FOUND` / `405 METHOD_NOT_ALLOWED` |
| `PDOException` 連線失敗 | `503 DB_UNAVAILABLE` |
| `PDOException` 其他 | 依下表轉 domain 錯誤；轉不出來就 `500 INTERNAL_ERROR`，並寫入 log |
| 其他 `Throwable` | `500 INTERNAL_ERROR`，不外洩訊息 |

**MySQL 錯誤碼對應**（以 `errorInfo[1]` 與 constraint 名稱判斷，不比對錯誤文字）

| MySQL 錯誤 | 情境 | 轉成 |
| --- | --- | --- |
| 1062 Duplicate entry — `users.email` | 註冊／改 email | `EMAIL_TAKEN` |
| 1062 — `uq_attempt` | 併發開始作答 | `ATTEMPT_IN_PROGRESS` 或 `ATTEMPT_LIMIT_REACHED`（重查後決定） |
| 1062 — `course_enrollments` 主鍵 | 重複修課 | `ALREADY_ENROLLED` |
| 1451 Cannot delete parent row | 刪除被引用的資料 | 依資料表轉 `COURSE_NOT_EMPTY`、`CHAPTER_NOT_EMPTY`、`QUESTION_IN_USE` |
| 1452 Cannot add child row | 外鍵指向不存在 | `VALIDATION_FAILED`（或 `NOT_FOUND`） |
| 3819（MySQL）／4025（MariaDB）Check constraint violated | CHECK 失敗 | `VALIDATION_FAILED` |
| 1213 Deadlock | 死結 | Service 重試最多 2 次，仍失敗才 `500` |

Log 寫到 `storage/logs/app-YYYY-MM-DD.log`，每個請求帶 `request_id`；log 內不得出現密碼、session id 與 CSRF token。

## 8. 測試策略

| 層 | 內容 | 工具 |
| --- | --- | --- |
| 單元 | `AttemptDeadline` 計算、題型規則、`Validator`、狀態機轉換判斷 | PHPUnit |
| 整合 | Repository／Service 對真實 MySQL：批改（四種題型、全對／漏選／多選／未作答）、次數上限、transaction 回滾、FK RESTRICT 防護 | PHPUnit + `quiz_system_test` |
| API | 以 `php -S` 或 Apache 起服務，用 HTTP 客戶端走完整流程；每個端點的權限（401 / 403 / 404） | PHPUnit（Guzzle）或 Postman collection |
| 併發 | 同時開始作答、重複交卷、暫存與交卷競爭、撤回與開始競爭 | 多個 PDO 連線／多個 `curl` 同時送出 |
| 資料庫約束 | schema 套到空白 DB 全部成功；CHECK、UNIQUE、FK 的違反案例會被擋下（已有 `schema.sql` + `seed.sql` 的實測結果） | PHPUnit |
| 手動驗收 | 依 [01](01-features.md) 的功能逐項走流程；各角色畫面 | 檢核表 |

**測試資料庫**：獨立的 `quiz_system_test`；每個測試類別前載入 `schema.sql` 與最小 fixture，每個測試用 transaction 包住並於結束 rollback（併發測試例外，改用事後清表）。**不使用 SQLite 取代 MySQL。**

**最少必測的情境清單**

1. 學生註冊 → 登入 → 修課 → 看到考卷 → 作答 → 交卷 → 分數正確。
2. 單選、是非、多選（全對 / 漏選 / 多選 / 空白）、簡答（待評分 → 評分 → `graded`）。
3. 次數上限；已有進行中的作答時再開始。
4. 逾時：暫存被拒、交卷只計已暫存、逾時自動處理。
5. 學生在作答中與結束前，回應**不含**正確答案與解析；結束後才開放。
6. 學生不能看他人的作答（404）；老師不能動別人的課程（404 / 403）；學生未修課不能開考。
7. 已被使用的題目不能改選項（`QUESTION_IN_USE`）；已有作答的考卷不能撤回。
8. 同時兩個請求開始作答，最後只產生一次作答。
9. 重複交卷回傳相同結果，分數不會重複計算。

## 9. 部署

### 9.1 開發環境（XAMPP）

1. 專案放在 `C:\xampp\htdocs\quiz-system`，將 Apache 的 DocumentRoot 或 VirtualHost 指向 `public/`。
2. 啟用 `mod_rewrite`，`public/api/.htaccess`：

   ```apache
   RewriteEngine On
   RewriteCond %{REQUEST_FILENAME} !-f
   RewriteRule ^v1/(.*)$ index.php [QSA,L]
   ```

3. 以 phpMyAdmin 或指令匯入 `database/schema.sql`、`database/seed.sql`，步驟見 [`database/README.md`](../database/README.md)。XAMPP 的 MySQL 實際是 MariaDB，相容性見 [02 §3.8](02-database.md#38-xamppmariadb與-mysql-8-的相容性)。
4. 複製 `.env.example` 為 `.env` 並填入資料庫帳密；`composer install`。

### 9.2 Docker Compose（選用）

```yaml
services:
  db:
    image: mysql:8.0
    environment:
      MYSQL_DATABASE: quiz_system
      MYSQL_ROOT_PASSWORD: ${DB_ROOT_PASSWORD}
      MYSQL_USER: quiz_app
      MYSQL_PASSWORD: ${DB_PASS}
    command: --character-set-server=utf8mb4 --collation-server=utf8mb4_unicode_ci
    volumes:
      - db-data:/var/lib/mysql
      - ./database/schema.sql:/docker-entrypoint-initdb.d/01-schema.sql:ro
      - ./database/seed.sql:/docker-entrypoint-initdb.d/02-seed.sql:ro
    healthcheck:
      test: ["CMD", "mysqladmin", "ping", "-h", "localhost"]
      interval: 5s
      retries: 10

  app:
    build: .                      # php:8.2-apache + pdo_mysql + a2enmod rewrite
    env_file: .env
    environment:
      DB_HOST: db
    depends_on:
      db: { condition: service_healthy }
    ports: ["8080:80"]
    volumes:
      - ./:/var/www/html

  phpmyadmin:
    image: phpmyadmin:5
    environment: { PMA_HOST: db }
    ports: ["8081:80"]
    depends_on: [db]

volumes:
  db-data: {}
```

### 9.3 展示／正式環境

- Apache + PHP-FPM（或 `mod_php`）；啟用 HTTPS，Cookie 加上 `secure`。
- `APP_ENV=production`、`APP_DEBUG=false`；PHP 設定 `display_errors=Off`。
- 以 `quiz_admin` 套 migration，網站使用 `quiz_app`。
- **備份**：`mysqldump --single-transaction --routines quiz_system > backup.sql`，期末前至少演練一次還原。
- **部署順序**：備份 → 套 migration → 更新程式 → smoke test（`GET /health`、登入、查課程列表）。
- `GET /health` 回傳存活與一次 `SELECT 1` 的結果；`storage/logs` 目錄須可寫入，且不在 `public/` 內。

## 10. 程式碼慣例

- 所有 PHP 檔案 `declare(strict_types=1);`，遵循 PSR-12，PSR-4 命名空間 `App\`。
- Controller 保持精簡（約 10 行以內的方法），**SQL 只能出現在 Repository**。
- 所有 SQL 使用具名參數（`:name`）；排序欄位等動態片段只能來自白名單陣列。
- Response 一律經過 Serializer，不直接把資料庫一列回傳。
- JSON 欄位名稱與資料表欄位一致（`snake_case`）；時間統一輸出 ISO 8601。
- 資料庫結構變更一律新增 `database/migrations/NNN_description.sql`，不直接手改資料庫；`schema.sql` 同步更新到最新。
- Commit 依功能切分；每個 PR／合併前需通過測試。
- 前端 `api.js` 集中處理：加上 CSRF 標頭、解析錯誤信封、`401` 導向登入；頁面腳本不直接呼叫 `fetch`。
