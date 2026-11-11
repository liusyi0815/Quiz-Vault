# 01 功能描述與優先級

## 優先級定義

| 等級 | 意義 |
| --- | --- |
| **P0** | 必做。沒有它系統無法完成「出題 → 考試 → 批改 → 看成績」主流程，也是期末展示的最小範圍。 |
| **P1** | 應做。讓系統完整可用，時間允許就完成。 |
| **P2** | 加分。提升體驗或展示價值。 |
| **P3** | 擴充。目前不做，只在資料庫與架構上預留空間。 |

端點細節見 [03-api](03-api.md)；流程與規則見 [04-behavior](04-behavior.md)。

## 功能總表

| ID | 功能 | 使用者 | 優先級 |
| --- | --- | --- | --- |
| F-01 | 註冊 / 登入 / 登出 | 全部 | P0 |
| F-02 | 個人資料與修改密碼 | 全部 | 資料 P0 / 改密碼 P1 |
| F-03 | 課程管理 | 老師、管理員 | P0 |
| F-04 | 修課與退選 | 學生 | P0 |
| F-05 | 章節管理 | 老師、管理員 | P0 |
| F-06 | 題庫管理（題目與選項） | 老師、管理員 | P0 |
| F-07 | 標籤管理 | 老師、管理員 | P1 |
| F-08 | 考卷建立與手動組卷 | 老師、管理員 | P0 |
| F-09 | 隨機抽題組卷 | 老師、管理員 | P1 |
| F-10 | 考卷發佈 / 撤回 / 關閉 | 老師、管理員 | P0 |
| F-11 | 線上作答（開始、暫存、交卷） | 學生 | P0 |
| F-12 | 自動批改與計分 | 系統 | P0 |
| F-13 | 簡答題人工評分 | 老師、管理員 | P1 |
| F-14 | 成績查詢與解析檢討 | 學生、老師 | P0 |
| F-15 | 統計報表 | 學生、老師 | P1 |
| F-16 | 題目收藏與錯題本 | 學生 | P1 |
| F-17 | 使用者管理 | 管理員 | P1 |
| F-18 | 逾時作答自動處理 | 系統 | 讀取時處理 P0 / 排程 P1 |
| F-19 | 題目匯入（CSV） | 老師 | P2 |
| F-20 | 擴充功能：公告、題目錯誤回報、登入紀錄 | 全部 | P3 |
| F-21 | 健康檢查 | 維運 | P1 |
| F-22 | 管理員重設密碼 | 管理員 | P1 |

---

## F-01 註冊 / 登入 / 登出 — P0

**描述**：使用者以 email 與密碼登入，後端建立 PHP Session。學生可自行註冊；老師與管理員帳號由管理員建立（或由 seed 提供）。

**使用者故事**
- 身為學生，我用學校 email 註冊並登入，登入後看到我的課程。
- 身為老師，我使用管理員給我的帳號登入，直接進入教師後台。

**規則摘要**
- 自行註冊只能得到 `student` 角色，前端傳來的 `role` 一律忽略。
- 密碼以 `password_hash()` 儲存，登入用 `password_verify()`；不得出現在任何 response 或 log。
- 登入成功後 `session_regenerate_id(true)`，並產生 CSRF token（見 [05 §5](05-architecture.md#5-認證與安全)）。
- 登入失敗一律回同一種訊息，不透露「帳號不存在」或「密碼錯誤」。
- 帳號若標記為「須先改密碼」（`must_change_password = 1`），登入仍會成功，response 帶 `must_change_password: true`；在改密碼之前只能使用 `GET /me`、`PUT /me/password`、`POST /auth/logout`（見 F-22）。

**端點**：`POST /auth/register`、`POST /auth/login`、`POST /auth/logout`

---

## F-02 個人資料與修改密碼 — 資料 P0 / 改密碼 P1

**描述**：回傳目前登入者的基本資料與角色；可修改姓名、email 與密碼。

**規則摘要**
- `GET /me` 同時回傳 `csrf_token`，前端於所有寫入請求帶入。
- 修改密碼需提供舊密碼；新密碼長度 ≥ 8 且**不得與舊密碼相同**；成功後重新產生 session id，並清除「須先改密碼」旗標（見 F-22）。
- email 修改後仍須維持唯一（`EMAIL_TAKEN`）。

**端點**：`GET /me`、`PATCH /me`、`PUT /me/password`

---

## F-03 課程管理 — P0

**描述**：老師開設、修改課程；課程列表依角色顯示不同範圍。

**使用者故事**
- 身為老師，我建立「資料庫與網頁設計」課程，之後在其下建立章節與題目。
- 身為學生，我可以瀏覽所有課程，並看到自己是否已修。

**規則摘要**
- 老師只能修改、刪除**自己的**課程；管理員不受限。
- 只有**完全空白**的課程（沒有章節、考卷）才能刪除，否則回 `COURSE_NOT_EMPTY`。
- 建立課程時 `teacher_id` 一律取自 session，不接受前端指定（管理員可代指定，且必須是啟用中的老師帳號）。
- 課程列表 `?mine=1`：學生＝我修的課、老師＝我開的課、管理員＝全部；`can_manage` 告訴前端要不要顯示編輯按鈕。

**端點**：`GET /courses`、`POST /courses`、`GET /courses/{course_id}`、`PATCH /courses/{course_id}`、`DELETE /courses/{course_id}`

---

## F-04 修課與退選 — P0

**描述**：學生自行加入或退出課程；老師可查看修課名單並移除學生。

**規則摘要**
- 加入已修課程回 `ALREADY_ENROLLED`。
- 退選只刪除 `course_enrollments` 的紀錄，**不刪除**過往作答與成績。
- 未修課的學生對該課程的考卷一律視為不存在（回 `NOT_ENROLLED`）。

**端點**：`POST /courses/{course_id}/enrollment`、`DELETE /courses/{course_id}/enrollment`、`GET /courses/{course_id}/students`、`DELETE /courses/{course_id}/students/{user_id}`

---

## F-05 章節管理 — P0

**描述**：在課程下新增、改名、排序、刪除章節。

**規則摘要**
- 章節下已有題目時不能刪除（`CHAPTER_NOT_EMPTY`），避免孤兒題目。
- 排序以 `sort_order` 儲存，批次調整一次寫入（P1）。

**端點**：`GET /courses/{course_id}/chapters`、`POST /courses/{course_id}/chapters`、`PATCH /chapters/{chapter_id}`、`DELETE /chapters/{chapter_id}`、`PUT /courses/{course_id}/chapters/order`

---

## F-06 題庫管理 — P0

**描述**：老師在章節下新增題目，一次寫入題目、選項與標籤；支援查詢篩選、編輯與停用。

**使用者故事**
- 身為老師，我新增一題多選題、勾出兩個正確選項，並貼上標籤 `SQL`、`JOIN`。
- 身為老師，我發現某題有誤，把它停用，之後組卷就不會再挑到它。

**規則摘要**（詳見 [04 §4](04-behavior.md#4-題目規則與出題流程)）
- 題型與選項需相符：單選恰 1 個正確、是非恰 2 個選項、多選至少 2 個選項且至少 1 個正確、簡答不得有選項。
- 題目、選項、標籤在**同一個 transaction** 寫入。
- 題目已被作答或已被發佈的考卷使用後，**不得修改題型、題幹與選項**（`QUESTION_IN_USE`）；仍可改解析、難度、標籤與停用。
- 題目不能搬到別門課程的章節；`PATCH` 只能改解析、難度、章節、標籤與啟用狀態，要改題幹或選項請用 `PUT` 整題取代。
- 刪除一律是軟刪除（`is_active = 0`）。

**端點**：`GET /questions`、`POST /questions`、`GET /questions/{question_id}`、`PUT /questions/{question_id}`、`PATCH /questions/{question_id}`、`DELETE /questions/{question_id}`

---

## F-07 標籤管理 — P1

**描述**：標籤清單、新增與刪除。出題時輸入未存在的標籤名稱會自動建立。

**規則摘要**
- 標籤名稱唯一，比對時忽略大小寫與前後空白。
- 刪除標籤只移除與題目的關聯（`question_tags` 連動刪除），不影響題目；僅管理員可刪。

**端點**：`GET /tags`、`POST /tags`、`DELETE /tags/{tag_id}`

---

## F-08 考卷建立與手動組卷 — P0

**描述**：老師建立考卷（標題、限時、開放時段、及格分數、作答次數），並從題庫挑題、設定每題配分與順序。

**使用者故事**
- 身為老師，我建立「第一次小考」限時 30 分鐘、可作答 2 次，挑 10 題並給每題 10 分。

**規則摘要**（詳見 [04 §5](04-behavior.md#5-組卷與發佈流程)）
- 只有 `draft` 狀態的考卷能改題目與配分。
- 題目必須屬於該考卷所在課程，且為啟用中（`is_active = 1`）。
- 題目清單以「整份取代」的方式寫入（單一 transaction）。

**端點**：`GET /exams`、`POST /exams`、`GET /exams/{exam_id}`、`PATCH /exams/{exam_id}`、`DELETE /exams/{exam_id}`、`PUT /exams/{exam_id}/questions`

---

## F-09 隨機抽題組卷 — P1

**描述**：依章節、難度、標籤與題數，從題庫隨機抽題加入考卷。

**規則摘要**
- 可指定各難度的題數，如易 5、中 3、難 2；不足時回 `INSUFFICIENT_QUESTIONS` 並列出缺少的條件。
- 已在考卷中的題目不會重複抽到。
- 抽出後仍可手動調整，直到發佈。

**端點**：`POST /exams/{exam_id}/questions/random`

---

## F-10 考卷發佈 / 撤回 / 關閉 — P0

**描述**：考卷狀態由草稿 → 發佈 → 關閉。發佈前系統檢查考卷是否合法。

**規則摘要**（狀態機見 [04 §6](04-behavior.md#6-考卷狀態機)）
- 發佈檢查：至少一題、題目皆有效、總分 > 0、及格分不超過總分、開始時間早於結束時間。
- 已有人作答的考卷不能撤回為草稿（`HAS_ATTEMPTS`）。
- 已發佈考卷只能延長結束時間與修改標題，題目與配分鎖定。

**端點**：`POST /exams/{exam_id}/publish`、`POST /exams/{exam_id}/unpublish`、`POST /exams/{exam_id}/close`

---

## F-11 線上作答 — P0

**描述**：學生開始測驗、作答中暫存答案、交卷。伺服器全程控管時間與次數。

**使用者故事**
- 身為學生，我按「開始測驗」後看到倒數計時與題目；中途重新整理頁面，答案與倒數都還在。
- 身為學生，我時間到時系統自動幫我交卷，已作答的內容都有計分。

**規則摘要**（流程見 [04 §7](04-behavior.md#7-作答流程)）
- 開始條件：已修課、考卷為 `published` 且在開放時段內、次數未達上限、沒有進行中的作答。
- 開始時一次建立該次作答，並為每一題預先建立一筆 `attempt_answers`。
- `deadline_at = min(started_at + duration_min, end_time)`；逾時後不接受暫存。
- 交卷具冪等性：重複交卷回傳同一份結果。
- 作答期間 response **不含** `is_correct` 與解析。

**端點**：`POST /exams/{exam_id}/attempts`、`GET /attempts/{attempt_id}`、`PUT /attempts/{attempt_id}/answers/{question_id}`、`POST /attempts/{attempt_id}/submit`、`GET /me/attempts`

---

## F-12 自動批改與計分 — P0

**描述**：交卷時，系統在同一個 transaction 內批改單選、多選、是非題並加總。

**規則摘要**（演算法見 [04 §9](04-behavior.md#9-自動批改與計分)）
- 客觀題採「全對才給分」：勾選集合必須與正確選項集合完全相同。
- 未作答視為答錯。
- 簡答題先不計分（`is_correct = NULL`）；若考卷含簡答題，作答停在 `submitted`，待老師評完才變 `graded`。

**端點**：無獨立端點，由 `POST /attempts/{attempt_id}/submit` 觸發。

---

## F-13 簡答題人工評分 — P1

**描述**：老師看到待評分的簡答題與學生答案，輸入得分；全部評完後作答轉為 `graded` 並重算總分。

**規則摘要**
- 給分範圍必須在 `0 ~ 該題配分`，否則 `SCORE_OUT_OF_RANGE`。
- 只能對簡答題評分；對客觀題回 `NOT_GRADABLE`。
- 已 `graded` 的作答可重新評分，總分隨之重算。

**端點**：`PATCH /attempts/{attempt_id}/answers/{question_id}/grade`、`GET /exams/{exam_id}/attempts`

---

## F-14 成績查詢與解析檢討 — P0

**描述**：學生查看歷次作答的總分、是否通過；在符合條件時逐題檢視自己的答案、正確答案與解析。老師查看全班成績。

**規則摘要**（詳見 [04 §11](04-behavior.md#11-成績與解析可見性)）
- 交卷後立即可見：總分、是否通過。
- 逐題對錯、正確答案與解析：在考卷關閉（或超過結束時間）後才開放，避免答案在考試期間流傳。
- 老師在任何時候都可看到完整答案。

**端點**：`GET /attempts/{attempt_id}/result`、`GET /me/attempts`、`GET /exams/{exam_id}/attempts`

---

## F-15 統計報表 — P1

**描述**：老師看單份考卷統計（平均、最高、最低、及格率、分數分佈）、各題答對率、各章節弱點；學生看自己的章節弱點。

**規則摘要**
- 統計只納入 `graded` 的作答；有簡答題待評分的作答不納入。
- 各題答對率由低到高排序，方便老師找出難題或有誤的題目。

**端點**：`GET /exams/{exam_id}/stats`、`GET /courses/{course_id}/stats/questions`、`GET /courses/{course_id}/stats/chapters`、`GET /me/stats/chapters`

---

## F-16 題目收藏與錯題本 — P1

**描述**：學生可收藏題目並寫筆記；系統依歷次作答彙整答錯的題目。

**規則摘要**
- 只有「該學生已可檢視解析」的題目才能收藏，避免以收藏功能繞過答案揭露規則。
- 錯題本由 `attempt_answers.is_correct = 0` 計算，同一題多次答錯合併顯示次數與最近一次時間。

**端點**：`GET /me/bookmarks`、`PUT /me/bookmarks/{question_id}`、`DELETE /me/bookmarks/{question_id}`、`GET /me/wrong-questions`

---

## F-17 使用者管理 — P1

**描述**：管理員建立老師／管理員帳號、調整角色、停用帳號。

**規則摘要**
- 不能停用或降級自己；系統至少保留一位啟用中的管理員（`LAST_ADMIN`）。
- 使用者不實體刪除（有外鍵歷史），改以 `is_active = 0` 停用，停用後無法登入（`ACCOUNT_DISABLED`）。
- 管理員建立的新帳號預設標記「須先改密碼」，使用者首次登入必須改掉管理員設定的初始密碼。
- 使用者忘記密碼時，由管理員重設，見 [F-22](#f-22-管理員重設密碼--p1)。

**端點**：`GET /admin/users`、`POST /admin/users`、`PATCH /admin/users/{user_id}`

---

## F-18 逾時作答自動處理 — 讀取時處理 P0 / 排程 P1

**描述**：學生關閉瀏覽器或網路中斷而未交卷時，逾時的作答要被自動交卷並批改，成績才不會懸空。

**規則摘要**（詳見 [04 §12](04-behavior.md#12-逾時處理)）
- P0：任何讀取該作答、或老師查看考卷作答列表時，若發現逾時就即時補交卷（lazy finalize）。
- P1：以排程 `bin/finalize_overdue.php` 每分鐘批次處理。
- 交卷時只採計逾時前已暫存的答案。

---

## F-19 題目匯入（CSV）— P2

**描述**：老師上傳 CSV 一次匯入多題（章節、題型、題幹、選項、正確答案、難度、標籤）。

**規則摘要**
- 先回傳預覽（可匯入幾題、哪幾列有錯），確認後才寫入。
- 整批在單一 transaction，任何一列失敗就整批回滾，並回報失敗列號。

**端點**：`POST /questions/import`

---

## F-20 擴充功能 — P3

預留、目前不實作：

| 擴充 | 說明 |
| --- | --- |
| `announcements` | 課程公告 |
| `question_reports` | 學生回報題目錯誤，老師處理 |
| `login_logs` | 登入紀錄，供稽核 |
| 忘記密碼（Email 重設連結） | 新增 `password_resets` 資料表；需要寄信（PHPMailer + SMTP） |
| 多老師共同授課 | 新增 `course_teachers` 關聯表取代 `courses.teacher_id` |
| 部分給分 | 多選題依答對比例給分 |
| 題目／選項隨機排序 | 每次作答獨立亂序 |

---

## F-21 健康檢查 — P1

**描述**：`GET /health` 回傳服務存活與資料庫連線狀態，供部署後 smoke test 使用。

**端點**：`GET /health`

---

## F-22 管理員重設密碼 — P1

**描述**：使用者忘記密碼時，由管理員為其重設。系統產生一組隨機的臨時密碼，使用者以臨時密碼登入後，必須先自行改成新密碼，才能使用其他功能。本系統**不提供**「忘記密碼」的自助 Email 流程（列為擴充，見 F-20）。

**使用者故事**
- 身為學生，我忘記密碼，聯絡管理員；管理員把臨時密碼告訴我，我登入後被要求立刻設定新密碼。
- 身為管理員，我在使用者列表按「重設密碼」，看到一組**只顯示一次**的臨時密碼，私下轉交給使用者。

**規則摘要**（流程見 [04 §3.5](04-behavior.md#35-管理員重設密碼)）
- 僅管理員可用；**不能重設自己的密碼**（`CANNOT_MODIFY_SELF`），自己改密碼請用 F-02。
- 臨時密碼 12 碼，由伺服器以密碼學安全的亂數產生，並排除 `0/O`、`1/l/I` 等易混淆字元；**只在這次 response 回傳一次**，資料庫只存雜湊，也不寫入 log。
- 重設後 `must_change_password = 1`：使用者登入後，在改密碼之前只能使用 `GET /me`、`PUT /me/password`、`POST /auth/logout`，其他端點回 `PASSWORD_CHANGE_REQUIRED`（狀態機見 [04 §3.6](04-behavior.md#36-須先改密碼的狀態)）。
- 使用者改密碼成功後旗標清除，才恢復正常使用。
- 已停用（`is_active = 0`）的帳號也可重設，但仍無法登入，直到重新啟用。
- **限制**：目標使用者既有的 session 不會被立即登出，最長在閒置逾時（`SESSION_LIFETIME`，預設 2 小時）後失效。若要立即失效，需新增 `password_changed_at` 欄位（P2，見 [02 §3.7](02-database.md#37-擴充預留)）。

**端點**：`POST /admin/users/{user_id}/reset-password`
