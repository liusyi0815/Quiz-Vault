# 03 端點規劃

## 1. 通則

| 項目 | 規定 |
| --- | --- |
| Base URL | `/api/v1` |
| 格式 | 請求與回應皆為 `application/json; charset=utf-8` |
| 認證 | PHP Session（Cookie：`QUIZSESSID`，`HttpOnly`、`SameSite=Lax`） |
| CSRF | `POST` / `PUT` / `PATCH` / `DELETE` 必須帶 `X-CSRF-Token`，值取自 `GET /me` 或登入回應的 `csrf_token` |
| 欄位命名 | `snake_case`，與資料表欄位一致 |
| 時間 | ISO 8601，含時區，例：`2026-11-03T10:30:00+08:00` |
| 分頁 | `?page=1&per_page=20`（預設 20，上限 100），回應 `meta` 帶 `page`、`per_page`、`total` |
| 角色縮寫 | **S** 學生、**T** 老師、**A** 管理員；`T*` 代表僅限**自己開的課程** |
| 須先改密碼 | 帳號 `must_change_password = true` 時，除 `GET /me`、`PUT /me/password`、`POST /auth/logout`、`GET /health` 外，所有端點回 `403 PASSWORD_CHANGE_REQUIRED` |

### 成功回應

```json
{ "data": { }, "meta": { } }
```

建立資源回 `201`，無內容回 `204`。

### 錯誤回應

```json
{
  "error": {
    "code": "VALIDATION_FAILED",
    "message": "欄位驗證失敗",
    "details": [ { "field": "email", "reason": "格式不正確" } ]
  }
}
```

`code` 供程式判斷，`message` 供人閱讀；錯誤碼清單見 [§4](#4-錯誤碼)。

## 2. 端點總表

權限欄的 `—` 表示不需登入；`登入` 表示任一角色。

### 2.1 認證與個人資料

| 方法 | 路徑 | 說明 | 權限 | 功能 |
| --- | --- | --- | --- | --- |
| POST | `/auth/register` | 學生註冊 | — | F-01 |
| POST | `/auth/login` | 登入 | — | F-01 |
| POST | `/auth/logout` | 登出 | 登入 | F-01 |
| GET | `/me` | 目前使用者與 `csrf_token` | 登入 | F-02 |
| PATCH | `/me` | 修改姓名、email | 登入 | F-02 |
| PUT | `/me/password` | 修改密碼（也用於「須先改密碼」時） | 登入 | F-02、F-22 |

### 2.2 課程、修課、章節

| 方法 | 路徑 | 說明 | 權限 | 功能 |
| --- | --- | --- | --- | --- |
| GET | `/courses` | 課程列表（`?q=`、`?mine=1`） | 登入 | F-03 |
| POST | `/courses` | 開課 | T、A | F-03 |
| GET | `/courses/{course_id}` | 課程詳情 | 登入 | F-03 |
| PATCH | `/courses/{course_id}` | 修改課程 | T*、A | F-03 |
| DELETE | `/courses/{course_id}` | 刪除空課程 | T*、A | F-03 |
| POST | `/courses/{course_id}/enrollment` | 加入課程 | S | F-04 |
| DELETE | `/courses/{course_id}/enrollment` | 退選 | S | F-04 |
| GET | `/courses/{course_id}/students` | 修課名單 | T*、A | F-04 |
| DELETE | `/courses/{course_id}/students/{user_id}` | 移除學生 | T*、A | F-04 |
| GET | `/courses/{course_id}/chapters` | 章節列表 | 登入 | F-05 |
| POST | `/courses/{course_id}/chapters` | 新增章節 | T*、A | F-05 |
| PUT | `/courses/{course_id}/chapters/order` | 批次排序 | T*、A | F-05 |
| PATCH | `/chapters/{chapter_id}` | 修改章節 | T*、A | F-05 |
| DELETE | `/chapters/{chapter_id}` | 刪除空章節 | T*、A | F-05 |

### 2.3 題庫與標籤

| 方法 | 路徑 | 說明 | 權限 | 功能 |
| --- | --- | --- | --- | --- |
| GET | `/questions` | 題目列表（`course_id`、`chapter_id`、`type`、`difficulty`、`tag`、`q`、`is_active`） | T*、A | F-06 |
| POST | `/questions` | 新增題目（含選項、標籤） | T*、A | F-06 |
| GET | `/questions/{question_id}` | 題目詳情（含正確答案） | T*、A | F-06 |
| PUT | `/questions/{question_id}` | 整題取代 | T*、A | F-06 |
| PATCH | `/questions/{question_id}` | 部分更新（解析、難度、標籤、章節、`is_active`） | T*、A | F-06 |
| DELETE | `/questions/{question_id}` | 軟刪除（`is_active = 0`） | T*、A | F-06 |
| POST | `/questions/import` | CSV 匯入（`?dry_run=1` 為預覽） | T*、A | F-19 |
| GET | `/tags` | 標籤列表 | T、A | F-07 |
| POST | `/tags` | 新增標籤 | T、A | F-07 |
| DELETE | `/tags/{tag_id}` | 刪除標籤 | A | F-07 |

### 2.4 考卷

| 方法 | 路徑 | 說明 | 權限 | 功能 |
| --- | --- | --- | --- | --- |
| GET | `/exams` | 考卷列表（`course_id`、`status`） | 登入 | F-08 |
| POST | `/exams` | 建立考卷（草稿） | T*、A | F-08 |
| GET | `/exams/{exam_id}` | 考卷詳情 | 登入 | F-08 |
| PATCH | `/exams/{exam_id}` | 修改設定 | T*、A | F-08 |
| DELETE | `/exams/{exam_id}` | 刪除草稿 | T*、A | F-08 |
| PUT | `/exams/{exam_id}/questions` | 整份取代題目與配分 | T*、A | F-08 |
| POST | `/exams/{exam_id}/questions/random` | 隨機抽題加入 | T*、A | F-09 |
| POST | `/exams/{exam_id}/publish` | 發佈 | T*、A | F-10 |
| POST | `/exams/{exam_id}/unpublish` | 撤回為草稿 | T*、A | F-10 |
| POST | `/exams/{exam_id}/close` | 關閉 | T*、A | F-10 |

學生看到的 `GET /exams` 只包含**自己已修課程**且狀態為 `published` / `closed` 的考卷。`GET /exams/{exam_id}` 對學生**不回傳題目內容**，只回傳設定與 `my_attempts` 摘要（已作答次數、最高分、`can_start`）。

### 2.5 作答與批改

| 方法 | 路徑 | 說明 | 權限 | 功能 |
| --- | --- | --- | --- | --- |
| POST | `/exams/{exam_id}/attempts` | 開始作答 | S | F-11 |
| GET | `/attempts/{attempt_id}` | 取得作答中的題目與已暫存答案（續答用） | S（本人） | F-11 |
| PUT | `/attempts/{attempt_id}/answers/{question_id}` | 暫存單題答案 | S（本人） | F-11 |
| POST | `/attempts/{attempt_id}/submit` | 交卷並批改 | S（本人） | F-11、F-12 |
| GET | `/attempts/{attempt_id}/result` | 成績與（符合條件時）解析 | S（本人）、T*、A | F-14 |
| GET | `/me/attempts` | 我的作答紀錄（`exam_id`、`course_id`） | S | F-14 |
| GET | `/exams/{exam_id}/attempts` | 全班作答列表（含待評分篩選 `?pending=1`） | T*、A | F-13、F-14 |
| PATCH | `/attempts/{attempt_id}/answers/{question_id}/grade` | 簡答題給分 | T*、A | F-13 |

### 2.6 統計、收藏、錯題本

| 方法 | 路徑 | 說明 | 權限 | 功能 |
| --- | --- | --- | --- | --- |
| GET | `/exams/{exam_id}/stats` | 平均、最高、最低、及格率、分數分佈 | T*、A | F-15 |
| GET | `/courses/{course_id}/stats/questions` | 各題答對率 | T*、A | F-15 |
| GET | `/courses/{course_id}/stats/chapters` | 班級章節弱點 | T*、A | F-15 |
| GET | `/me/stats/chapters` | 個人章節弱點（`?course_id=`） | S | F-15 |
| GET | `/me/bookmarks` | 我的收藏 | S | F-16 |
| PUT | `/me/bookmarks/{question_id}` | 收藏／更新筆記 | S | F-16 |
| DELETE | `/me/bookmarks/{question_id}` | 取消收藏 | S | F-16 |
| GET | `/me/wrong-questions` | 錯題本 | S | F-16 |

### 2.7 管理與系統

| 方法 | 路徑 | 說明 | 權限 | 功能 |
| --- | --- | --- | --- | --- |
| GET | `/admin/users` | 使用者列表（`role`、`q`、`is_active`） | A | F-17 |
| POST | `/admin/users` | 建立老師／管理員帳號 | A | F-17 |
| PATCH | `/admin/users/{user_id}` | 改角色、停用／啟用 | A | F-17 |
| POST | `/admin/users/{user_id}/reset-password` | 重設密碼，回傳臨時密碼（只顯示一次） | A | F-22 |
| GET | `/health` | 服務與資料庫狀態 | — | F-21 |

權限細節與「`T*` 的判定方式」見 [04 §2](04-behavior.md#2-角色與權限矩陣)。

## 3. Request / Response 範例

### 3.1 登入

`POST /auth/login`

```json
{ "email": "s001@example.edu.tw", "password": "P@ssw0rd!" }
```

`200`

```json
{
  "data": {
    "user": { "user_id": 21, "username": "王小明", "email": "s001@example.edu.tw", "role": "student" },
    "csrf_token": "b7c1…e9",
    "must_change_password": false
  }
}
```

`must_change_password` 為 `true` 時，前端應導向「修改密碼」頁面（見 [§3.13](#313-強制修改密碼使用者端)）。`GET /me` 的回應也包含此欄位。

`POST /auth/logout` 回 `200`：`{ "data": { "logged_out": true } }`；註冊 `POST /auth/register` 回 `201`：`{ "data": { "user": { … } } }`（不會自動登入）。

### 3.2 新增題目（多選題）

`POST /questions`

```json
{
  "chapter_id": 3,
  "type": "multiple",
  "content": "下列哪些屬於 DML 指令？",
  "difficulty": 2,
  "explanation": "DML 用於操作資料：SELECT、INSERT、UPDATE、DELETE。CREATE 屬於 DDL。",
  "options": [
    { "content": "SELECT", "is_correct": true,  "sort_order": 1 },
    { "content": "INSERT", "is_correct": true,  "sort_order": 2 },
    { "content": "CREATE", "is_correct": false, "sort_order": 3 },
    { "content": "GRANT",  "is_correct": false, "sort_order": 4 }
  ],
  "tags": ["SQL", "DML"]
}
```

`201`

```json
{
  "data": {
    "question_id": 101,
    "chapter_id": 3,
    "type": "multiple",
    "difficulty": 2,
    "is_active": true,
    "options": [ { "option_id": 401, "content": "SELECT", "is_correct": true, "sort_order": 1 } ],
    "tags": [ { "tag_id": 1, "name": "SQL" }, { "tag_id": 9, "name": "DML" } ]
  }
}
```

### 3.3 整份取代考卷題目

`PUT /exams/12/questions`

```json
{
  "questions": [
    { "question_id": 101, "score": 10, "sort_order": 1 },
    { "question_id": 102, "score": 10, "sort_order": 2 }
  ]
}
```

`200` 回傳更新後的清單與 `total_score`。

### 3.4 隨機抽題

`POST /exams/12/questions/random`

```json
{
  "chapter_ids": [3, 4],
  "tags": ["SQL"],
  "by_difficulty": { "1": 5, "2": 3, "3": 2 },
  "score_each": 10
}
```

`200`（成功）回傳新加入的題目清單；`409`（題數不足）：

```json
{
  "error": {
    "code": "INSUFFICIENT_QUESTIONS",
    "message": "符合條件的題目不足",
    "details": [ { "difficulty": 3, "requested": 2, "available": 1 } ]
  }
}
```

### 3.5 發佈考卷

`POST /exams/12/publish`

`200`

```json
{ "data": { "exam_id": 12, "status": "published", "total_score": 100 } }
```

檢查失敗 `409 EXAM_PUBLISH_CHECK_FAILED`：

```json
{
  "error": {
    "code": "EXAM_PUBLISH_CHECK_FAILED",
    "message": "考卷未通過發佈檢查",
    "details": [
      { "rule": "pass_score_exceeds_total", "pass_score": 120, "total_score": 100 },
      { "rule": "question_inactive", "question_id": 105 }
    ]
  }
}
```

### 3.6 開始作答

`POST /exams/12/attempts`（無 body）

`201` — **不含 `is_correct` 與解析**

```json
{
  "data": {
    "attempt_id": 57,
    "exam_id": 12,
    "attempt_no": 1,
    "status": "in_progress",
    "started_at": "2026-11-03T10:00:02+08:00",
    "deadline_at": "2026-11-03T10:30:02+08:00",
    "server_time": "2026-11-03T10:00:02+08:00",
    "questions": [
      {
        "question_id": 101,
        "type": "multiple",
        "content": "下列哪些屬於 DML 指令？",
        "score": 10,
        "options": [
          { "option_id": 401, "content": "SELECT" },
          { "option_id": 402, "content": "INSERT" },
          { "option_id": 403, "content": "CREATE" }
        ],
        "answer": { "option_ids": [], "answer_text": null }
      }
    ]
  }
}
```

前端以 `deadline_at - server_time` 計算倒數起點，避免被使用者電腦時間誤導。

### 3.7 暫存答案

`PUT /attempts/57/answers/101`

客觀題：

```json
{ "option_ids": [401, 402] }
```

簡答題：

```json
{ "answer_text": "INNER JOIN 只回傳兩表都有符合的資料列。" }
```

`200`

```json
{ "data": { "question_id": 101, "saved_at": "2026-11-03T10:07:41+08:00" } }
```

### 3.8 交卷

`POST /attempts/57/submit`

body 可選，帶入最後尚未暫存的答案（逾時後送來的答案會被忽略）：

```json
{ "answers": [ { "question_id": 102, "option_ids": [405] } ] }
```

`200`

```json
{
  "data": {
    "attempt_id": 57,
    "status": "graded",
    "total_score": 80,
    "pass_score": 60,
    "passed": true,
    "late": false,
    "pending_manual_grading": 0
  }
}
```

含簡答題待評分時 `status` 為 `submitted`，`pending_manual_grading > 0`，`total_score` 為客觀題暫計分數。

### 3.9 查詢結果（尚未開放解析 / 已開放解析）

`GET /attempts/57/result`

尚未開放：

```json
{
  "data": {
    "attempt_id": 57, "status": "graded", "total_score": 80, "passed": true,
    "answers_revealed": false
  }
}
```

已開放：

```json
{
  "data": {
    "attempt_id": 57, "status": "graded", "total_score": 80, "passed": true,
    "answers_revealed": true,
    "answers": [
      {
        "question_id": 101, "type": "multiple", "content": "下列哪些屬於 DML 指令？",
        "score": 10, "score_awarded": 10, "is_correct": true,
        "my_option_ids": [401, 402],
        "options": [
          { "option_id": 401, "content": "SELECT", "is_correct": true },
          { "option_id": 403, "content": "CREATE", "is_correct": false }
        ],
        "explanation": "DML 用於操作資料……"
      }
    ]
  }
}
```

### 3.10 簡答題給分

`PATCH /attempts/57/answers/104/grade`

```json
{ "score_awarded": 7.5 }
```

`200`

```json
{ "data": { "question_id": 104, "score_awarded": 7.5, "attempt_status": "graded", "total_score": 87.5 } }
```

### 3.11 考卷統計

`GET /exams/12/stats`

```json
{
  "data": {
    "attempts": 18, "avg_score": 72.4, "max_score": 100, "min_score": 35,
    "pass_rate": 77.8,
    "distribution": [ { "range": "0-59", "count": 4 }, { "range": "60-79", "count": 8 }, { "range": "80-100", "count": 6 } ]
  }
}
```

### 3.12 管理員重設密碼

`POST /admin/users/24/reset-password`（無 body；需 `X-CSRF-Token`）

`200`

```json
{
  "data": {
    "user_id": 24,
    "username": "徐若瑜",
    "temp_password": "Kp7mXw4Rtn2Q",
    "must_change_password": true
  }
}
```

- `temp_password` **只在這次回應出現**，資料庫只存雜湊，之後無法再查詢；請管理員私下轉交，若遺失就再重設一次。
- 對自己操作回 `409 CANNOT_MODIFY_SELF`；目標不存在回 `404 NOT_FOUND`；非管理員回 `403 FORBIDDEN`。

### 3.13 強制修改密碼（使用者端）

使用者以臨時密碼登入：`POST /auth/login` 回 `200`，且 `must_change_password` 為 `true`。

此時呼叫其他端點（例如 `GET /courses`）：`403`

```json
{ "error": { "code": "PASSWORD_CHANGE_REQUIRED", "message": "請先修改密碼" } }
```

使用者設定新密碼：`PUT /me/password`

```json
{ "old_password": "Kp7mXw4Rtn2Q", "new_password": "MyNewPass#2026" }
```

`200`

```json
{ "data": { "must_change_password": false } }
```

- 「舊密碼」就是剛才的臨時密碼；舊密碼不對回 `400 WRONG_PASSWORD`。
- 新密碼長度須 ≥ 8，且不得與舊密碼相同，否則回 `400 VALIDATION_FAILED`。

## 4. 錯誤碼

### 4.1 通用

| HTTP | code | 說明 |
| --- | --- | --- |
| 400 | `VALIDATION_FAILED` | 欄位缺漏、型別或格式錯誤，`details` 列出欄位 |
| 400 | `INVALID_JSON` | 請求內容不是合法 JSON |
| 401 | `UNAUTHENTICATED` | 尚未登入或 session 過期 |
| 403 | `FORBIDDEN` | 角色或擁有權不足 |
| 403 | `CSRF_FAILED` | 缺少或錯誤的 `X-CSRF-Token` |
| 404 | `NOT_FOUND` | 資源不存在，或對該使用者視為不存在 |
| 405 | `METHOD_NOT_ALLOWED` | 路徑存在但方法不支援 |
| 429 | `RATE_LIMITED` | 請求過於頻繁（P2，登入防爆破） |
| 500 | `INTERNAL_ERROR` | 未預期錯誤，回傳 `request_id`，不外洩 SQL |
| 503 | `DB_UNAVAILABLE` | 資料庫無法連線 |

### 4.2 帳號

| HTTP | code | 說明 |
| --- | --- | --- |
| 401 | `INVALID_CREDENTIALS` | email 或密碼錯誤（不區分原因） |
| 403 | `ACCOUNT_DISABLED` | 帳號已停用 |
| 409 | `EMAIL_TAKEN` | email 已被使用 |
| 400 | `WRONG_PASSWORD` | 修改密碼時舊密碼不正確 |
| 409 | `LAST_ADMIN` | 不能停用或降級最後一位管理員 |
| 409 | `CANNOT_MODIFY_SELF` | 管理員不能停用、降級或重設自己的密碼 |
| 403 | `PASSWORD_CHANGE_REQUIRED` | 帳號被標記須先改密碼；改完前只能使用 `GET /me`、`PUT /me/password`、`POST /auth/logout` |

### 4.3 課程與章節

| HTTP | code | 說明 |
| --- | --- | --- |
| 409 | `ALREADY_ENROLLED` | 已加入該課程 |
| 403 | `NOT_ENROLLED` | 尚未修該課程 |
| 409 | `COURSE_NOT_EMPTY` | 課程下仍有章節或考卷，不可刪除 |
| 409 | `CHAPTER_NOT_EMPTY` | 章節下仍有題目，不可刪除 |

### 4.4 題庫

| HTTP | code | 說明 |
| --- | --- | --- |
| 400 | `INVALID_QUESTION_SHAPE` | 題型與選項不符（例：單選有兩個正確答案） |
| 409 | `QUESTION_IN_USE` | 題目已被作答或已發佈考卷使用，不可改題型、題幹與選項 |
| 409 | `QUESTION_INACTIVE` | 題目已停用，不可加入考卷 |
| 409 | `INSUFFICIENT_QUESTIONS` | 隨機抽題的符合題數不足 |

### 4.5 考卷

| HTTP | code | 說明 |
| --- | --- | --- |
| 409 | `EXAM_NOT_EDITABLE` | 考卷不是草稿，不可改題目與配分 |
| 409 | `EXAM_INVALID_STATE` | 狀態轉換不合法（例：關閉狀態再發佈） |
| 409 | `EXAM_PUBLISH_CHECK_FAILED` | 發佈檢查未通過，`details` 列出規則 |
| 409 | `EXAM_NOT_OPEN` | 考卷未發佈，或不在開放時段內 |
| 409 | `HAS_ATTEMPTS` | 已有作答，不可撤回或刪除 |

### 4.6 作答與評分

| HTTP | code | 說明 |
| --- | --- | --- |
| 409 | `ATTEMPT_LIMIT_REACHED` | 已達作答次數上限 |
| 409 | `ATTEMPT_IN_PROGRESS` | 已有進行中的作答（`details` 帶 `attempt_id`） |
| 409 | `ATTEMPT_EXPIRED` | 已逾時，不接受暫存 |
| 409 | `ATTEMPT_NOT_EDITABLE` | 作答已交卷，不可再改答案 |
| 409 | `ATTEMPT_NOT_SUBMITTED` | 作答尚未交卷，無法查看結果 |
| 400 | `INVALID_OPTION` | 勾選的選項不屬於該題，或勾選數量不符題型 |
| 400 | `SCORE_OUT_OF_RANGE` | 給分不在 `0 ~ 配分` |
| 409 | `NOT_GRADABLE` | 只有簡答題可人工評分 |

### 4.7 狀態碼選擇原則

- **400**：請求本身格式不對，改正後即可重送。
- **401 / 403**：身份或權限問題；對「別人的資源」優先回 **404**，避免洩漏資源是否存在。
- **409**：請求合法，但與資源目前狀態衝突（狀態機、唯一鍵、外鍵限制）。
