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
| GET | `/courses` | 課程列表（`?q=` 依名稱搜尋、`?mine=1` 只看「我的」：學生＝我修的課、老師＝我開的課、管理員＝全部） | 登入 | F-03 |
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

學生看到的 `GET /exams` 只包含**自己已修課程**且狀態為 `published` / `closed` 的考卷。`GET /exams/{exam_id}` 對學生**不回傳題目內容**，只回傳設定與 `my_attempts` 摘要（已作答次數、最高分、`can_start`）；草稿對學生是 `404`，沒修課的學生回 `403 NOT_ENROLLED`。老師看別人的考卷回 `404`。範例見 [§3.18](#318-考卷)。

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

`by_difficulty`（各難度題數，鍵只能是 `"1"`、`"2"`、`"3"`）與 `count`（不分難度的總題數）擇一；`chapter_ids` 不給代表整門課；`tags` 帶有**任何一個**即可；`score_each` 預設 1。

`200`（成功）回傳**新加入的題目清單**，`meta` 帶 `added`、`question_count`、`total_score`；`409`（題數不足，**整批都不寫入**）：

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

### 3.14 課程

`GET /courses?q=資料庫&per_page=20`

```json
{
  "data": [
    {
      "course_id": 1, "name": "資料庫與網頁設計", "description": "MySQL 資料庫、PHP 後端與網頁安全實作",
      "teacher": { "user_id": 2, "username": "陳建宏老師" },
      "created_at": "2026-08-31T09:00:00+08:00",
      "enrolled": false, "can_manage": false
    }
  ],
  "meta": { "page": 1, "per_page": 20, "total": 1 }
}
```

`enrolled` 只有學生可能是 `true`；`can_manage` 為 `true` 代表目前使用者是課程擁有者或管理員（前端據此顯示編輯按鈕）。

`POST /courses`

```json
{ "name": "資料結構", "description": "陣列、堆疊、佇列與排序演算法" }
```

`201`：課程詳情。開課老師一律是目前登入者，只有管理員可額外帶 `teacher_id` 代指定。

`GET /courses/{course_id}` 多了 `chapter_count`；課程擁有者與管理員另外看到 `student_count`、`question_count`、`exam_count`。

`DELETE /courses/{course_id}` 回 `204`；底下還有章節或考卷回 `409 COURSE_NOT_EMPTY`，`details` 會列出各有幾個。

### 3.15 修課與退選

`POST /courses/1/enrollment`（學生，無 body）→ `201`

```json
{ "data": { "course_id": 1, "enrolled_at": "2026-11-10T15:42:31+08:00" } }
```

重複加入 `409 ALREADY_ENROLLED`；退選 `DELETE /courses/1/enrollment` 回 `204`，沒修的課回 `403 NOT_ENROLLED`。退選只刪除修課紀錄，不影響過往作答。

`GET /courses/1/students?q=s001&per_page=20`（課程擁有者、管理員）

```json
{
  "data": [ { "user_id": 5, "username": "王小明", "email": "s001@example.edu.tw", "enrolled_at": "2026-09-01T10:05:00+08:00" } ],
  "meta": { "page": 1, "per_page": 20, "total": 1 }
}
```

### 3.16 章節

`GET /courses/1/chapters`：依 `sort_order` 排序，不分頁。老師與管理員多一個 `question_count`。

```json
{
  "data": [ { "chapter_id": 1, "course_id": 1, "name": "SQL 基礎", "sort_order": 1, "question_count": 5 } ],
  "meta": { "total": 1 }
}
```

`POST /courses/1/chapters`：`{ "name": "第七章" }`，`sort_order` 不給就排在最後。

`PUT /courses/1/chapters/order`：`{ "chapter_ids": [3, 1, 2] }`，必須剛好是這門課所有的章節，否則 `400`。

`DELETE /chapters/{chapter_id}`：底下有題目（含已停用的）回 `409 CHAPTER_NOT_EMPTY`。

### 3.17 題庫列表、整題取代、部分更新

`GET /questions?course_id=1&type=multiple&tag=SQL&difficulty=2&is_active=1&q=DML&page=1`

```json
{
  "data": [
    {
      "question_id": 3, "chapter": { "chapter_id": 1, "name": "SQL 基礎" }, "course_id": 1,
      "type": "multiple", "content": "下列哪些屬於 DML（資料操作語言）指令？", "difficulty": 2, "is_active": true,
      "tags": [ { "tag_id": 3, "name": "DML" }, { "tag_id": 1, "name": "SQL" } ],
      "created_at": "2026-09-01T10:00:00+08:00"
    }
  ],
  "meta": { "page": 1, "per_page": 20, "total": 1 }
}
```

列表不含選項；老師只看得到自己課程的題目，管理員看全部。

`GET /questions/{question_id}` 多了 `explanation`、`creator_id`、`in_use`，以及含 `is_correct` 的 `options`。

`PUT /questions/{question_id}` 的 body 與新增相同（§3.2）：**整題取代**，沒給的選填欄位會回到預設值（`difficulty` 為 1、`explanation` 為 null、`tags` 為空）。題目不能搬到別門課程的章節（`400`）。`in_use` 為 `true` 時，題型、題幹、選項必須與現有的完全相同，否則 `409 QUESTION_IN_USE`。

`PATCH /questions/{question_id}` 只能改 `chapter_id`、`difficulty`、`explanation`、`is_active`、`tags`；送出 `type`、`content`、`options` 會回 `400`。

`DELETE /questions/{question_id}` 回 `204`，是軟刪除（`is_active = false`）；用 `PATCH` 送 `{ "is_active": true }` 可復原。

### 3.18 考卷

`POST /exams`

```json
{
  "course_id": 1, "title": "第一次小考", "duration_min": 30,
  "start_time": "2026-11-20T09:00:00+08:00", "end_time": "2026-11-27T23:59:00+08:00",
  "pass_score": 60, "max_attempts": 2
}
```

`start_time`、`end_time` 可不給（不限時段）；沒有時區的字串視為本地時間，UTC（`Z`）會轉成 `+08:00`。`pass_score` 預設 60、`max_attempts` 預設 1。

`201`（老師視角：多了 `creator_id`、`attempt_count` 與 `questions`）

```json
{
  "data": {
    "exam_id": 12,
    "course": { "course_id": 1, "name": "資料庫與網頁設計" },
    "title": "第一次小考",
    "status": "draft", "effective_status": "draft", "is_open": false,
    "duration_min": 30,
    "start_time": "2026-11-20T09:00:00+08:00", "end_time": "2026-11-27T23:59:00+08:00",
    "pass_score": 60, "max_attempts": 2,
    "question_count": 0, "total_score": 0,
    "created_at": "2026-11-10T15:00:00+08:00",
    "creator_id": 2, "attempt_count": 0, "questions": []
  }
}
```

| 欄位 | 說明 |
| --- | --- |
| `status` | 資料庫中的狀態：`draft` / `published` / `closed` |
| `effective_status` | 已發佈但超過 `end_time` 顯示為 `ended`，其餘與 `status` 相同（讀取時判斷，不需要排程） |
| `is_open` | 現在可以開始作答嗎（已發佈，且在開放時段內） |

`PATCH /exams/{exam_id}`：欄位同上，只送要改的；`course_id` 不能改。**各狀態可改的欄位**：草稿全部可改；已發佈只能改 `title` 與延長 `end_time`（可設為 `null` 移除結束時間）；已關閉只能改 `title`。被鎖定的欄位回 `409 EXAM_NOT_EDITABLE`，`details` 列出欄位名稱。

`DELETE /exams/{exam_id}` 回 `204`，只有草稿可以刪除；題目清單隨考卷一併刪除，題目本身不受影響。

**老師看考卷詳情的 `questions`**（不含選項與正確答案，要看請用 `GET /questions/{id}`）：

```json
{
  "question_id": 3, "chapter": { "chapter_id": 1, "name": "SQL 基礎" }, "type": "multiple",
  "content": "下列哪些屬於 DML（資料操作語言）指令？", "difficulty": 2, "is_active": true,
  "score": 10, "sort_order": 1
}
```

**學生看考卷詳情**：沒有 `questions`、`creator_id`、`attempt_count`，多了 `my_attempts`：

```json
{
  "data": {
    "exam_id": 12, "title": "第一次小考", "status": "published", "effective_status": "published", "is_open": true,
    "duration_min": 30, "pass_score": 60, "max_attempts": 2, "question_count": 10, "total_score": 100,
    "my_attempts": { "used": 1, "max_attempts": 2, "best_score": 70.5, "in_progress_attempt_id": null, "can_start": true }
  }
}
```

`can_start` 為 `true` 的條件：開放中、`used < max_attempts`、沒有進行中的作答。學生的 `GET /exams` 每一筆也帶有 `my_attempts`。

### 3.19 狀態轉換

| 請求 | 成功 | 失敗 |
| --- | --- | --- |
| `POST /exams/{id}/publish` | `200`：`{ "exam_id", "status": "published", "total_score" }` | 不是草稿 `409 EXAM_INVALID_STATE`；檢查未通過 `409 EXAM_PUBLISH_CHECK_FAILED`（一次列出所有失敗規則，見 §3.5） |
| `POST /exams/{id}/unpublish` | `200`：`status` 回到 `draft` | 不是已發佈 `409 EXAM_INVALID_STATE`；已有任何作答 `409 HAS_ATTEMPTS` |
| `POST /exams/{id}/close` | `200`：`status` 為 `closed` | 不是已發佈 `409 EXAM_INVALID_STATE` |

已關閉是終態。草稿以外的考卷不能改題目、配分與隨機抽題（`409 EXAM_NOT_EDITABLE`）。

`details` 裡的 `rule` 值：`no_questions`、`question_inactive`（附 `question_id`）、`question_shape_invalid`（附 `question_id`、`reason`）、`total_score_zero`、`pass_score_exceeds_total`（附 `pass_score`、`total_score`）、`invalid_window`、`end_in_past`、`duration_invalid`。

### 3.20 作答的實際行為（M4）

- `POST /exams/{id}/attempts`：草稿回 `404`，沒修課回 `403 NOT_ENROLLED`，不在開放時段回 `409 EXAM_NOT_OPEN`，已有作答中的回 `409 ATTEMPT_IN_PROGRESS`（`details` 帶 `attempt_id`），次數用完回 `409 ATTEMPT_LIMIT_REACHED`（`details` 帶 `used`、`max_attempts`）。回應含 `deadline_at` 與 `remaining_seconds`。
- `deadline_at = min(started_at + duration_min, 考卷 end_time)`，伺服器允許 `ATTEMPT_GRACE_SECONDS`（預設 5）秒寬限。
- 暫存是**整題取代**：`option_ids: []` 代表清除；單選、是非題最多 1 個選項；簡答題只接受 `answer_text`（最長 5000 字）。已交卷回 `409 ATTEMPT_NOT_EDITABLE`，已逾時回 `409 ATTEMPT_EXPIRED`。
- `POST .../submit` 的 `answers` 選填，全部合法才寫入，任何一筆不合法就整批不寫入也不批改。已逾時才送來的答案會被忽略（`late=true`）。重複交卷回傳同一份結果。
- `passed`：有簡答題待評分且目前未及格時為 `null`（未定）；人工評分只會加分，所以已及格就一定是 `true`。簡答題空白者直接 0 分。
- `GET /attempts/{id}/result`：交卷後立刻有 `total_score`、`passed`；`answers_revealed` 為 `false` 時沒有逐題 `answers`，考卷關閉或超過結束時間後才為 `true`。老師與管理員隨時可看完整內容。作答中回 `409 ATTEMPT_NOT_SUBMITTED`。
- 逾時處理：任何讀取發現已逾時就先結算；另有排程腳本 `bin/finalize_overdue.php`。

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
- **401 / 403**：身份或權限問題。
  - 大家都看得到的資源（課程本身、章節）：沒有修改權限回 **403**。
  - 不公開的資源（修課名單、題庫、題目）：不是自己的一律回 **404**，避免洩漏資源是否存在；列表則只回自己的，別人的課程回空清單。
- **409**：請求合法，但與資源目前狀態衝突（狀態機、唯一鍵、外鍵限制）。
