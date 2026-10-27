-- ============================================================
-- 線上題庫與測驗系統  schema.sql
-- 相容：XAMPP 內建 MariaDB 10.4+、MySQL 8.0.16+
-- 說明：docs/02-database.md
-- 執行：phpMyAdmin → 匯入，或  mysql -u root < database/schema.sql
-- 注意：會先刪除同名資料表再重建（開發用，請勿在正式資料上執行）
-- ============================================================

CREATE DATABASE IF NOT EXISTS quiz_system
  DEFAULT CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE quiz_system;

SET NAMES utf8mb4;
-- DROP 順序已是「子表先、父表後」，即使外鍵檢查是開啟的也能執行
SET FOREIGN_KEY_CHECKS = 0;

DROP TABLE IF EXISTS bookmarks;
DROP TABLE IF EXISTS attempt_answer_options;
DROP TABLE IF EXISTS attempt_answers;
DROP TABLE IF EXISTS exam_attempts;
DROP TABLE IF EXISTS exam_questions;
DROP TABLE IF EXISTS exams;
DROP TABLE IF EXISTS question_tags;
DROP TABLE IF EXISTS tags;
DROP TABLE IF EXISTS question_options;
DROP TABLE IF EXISTS questions;
DROP TABLE IF EXISTS chapters;
DROP TABLE IF EXISTS course_enrollments;
DROP TABLE IF EXISTS courses;
DROP TABLE IF EXISTS users;

SET FOREIGN_KEY_CHECKS = 1;

-- ------------------------------------------------------------
-- 1. users 使用者
-- ------------------------------------------------------------
CREATE TABLE users (
  user_id       INT UNSIGNED NOT NULL AUTO_INCREMENT,
  username      VARCHAR(50)  NOT NULL,
  email         VARCHAR(100) NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  role          ENUM('student','teacher','admin') NOT NULL DEFAULT 'student',
  is_active     TINYINT(1)   NOT NULL DEFAULT 1,
  must_change_password TINYINT(1) NOT NULL DEFAULT 0,   -- 1 = 登入後必須先改密碼（F-22）
  created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (user_id),
  UNIQUE KEY uq_users_email (email),
  KEY ix_users_role (role)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- 2. courses 課程
-- ------------------------------------------------------------
CREATE TABLE courses (
  course_id   INT UNSIGNED NOT NULL AUTO_INCREMENT,
  teacher_id  INT UNSIGNED NOT NULL,
  name        VARCHAR(100) NOT NULL,
  description TEXT NULL,
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (course_id),
  KEY ix_courses_teacher (teacher_id),
  CONSTRAINT fk_courses_teacher
    FOREIGN KEY (teacher_id) REFERENCES users (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- 3. course_enrollments 修課（users × courses 多對多）
-- ------------------------------------------------------------
CREATE TABLE course_enrollments (
  course_id   INT UNSIGNED NOT NULL,
  user_id     INT UNSIGNED NOT NULL,
  enrolled_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (course_id, user_id),
  KEY ix_enroll_user (user_id),
  CONSTRAINT fk_enroll_course
    FOREIGN KEY (course_id) REFERENCES courses (course_id) ON DELETE CASCADE,
  CONSTRAINT fk_enroll_user
    FOREIGN KEY (user_id)   REFERENCES users (user_id)     ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- 4. chapters 章節
-- ------------------------------------------------------------
CREATE TABLE chapters (
  chapter_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  course_id  INT UNSIGNED NOT NULL,
  name       VARCHAR(100) NOT NULL,
  sort_order INT NOT NULL DEFAULT 0,
  PRIMARY KEY (chapter_id),
  KEY ix_chapters_course (course_id, sort_order),
  CONSTRAINT fk_chapters_course
    FOREIGN KEY (course_id) REFERENCES courses (course_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- 5. questions 題庫
--    difficulty：1 易、2 中、3 難
--    is_active ：0 = 軟刪除（停用），保留歷史作答
-- ------------------------------------------------------------
CREATE TABLE questions (
  question_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  chapter_id  INT UNSIGNED NOT NULL,
  creator_id  INT UNSIGNED NOT NULL,
  type        ENUM('single','multiple','truefalse','short') NOT NULL,
  content     TEXT NOT NULL,
  difficulty  TINYINT UNSIGNED NOT NULL DEFAULT 1,
  explanation TEXT NULL,
  is_active   TINYINT(1) NOT NULL DEFAULT 1,
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (question_id),
  KEY ix_questions_pick (chapter_id, is_active, difficulty),
  KEY ix_questions_creator (creator_id),
  CONSTRAINT fk_questions_chapter
    FOREIGN KEY (chapter_id) REFERENCES chapters (chapter_id),
  CONSTRAINT fk_questions_creator
    FOREIGN KEY (creator_id) REFERENCES users (user_id),
  CONSTRAINT chk_question_difficulty CHECK (difficulty BETWEEN 1 AND 3)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- 6. question_options 題目選項（簡答題沒有選項）
-- ------------------------------------------------------------
CREATE TABLE question_options (
  option_id   INT UNSIGNED NOT NULL AUTO_INCREMENT,
  question_id INT UNSIGNED NOT NULL,
  content     VARCHAR(500) NOT NULL,
  is_correct  TINYINT(1) NOT NULL DEFAULT 0,
  sort_order  INT NOT NULL DEFAULT 0,
  PRIMARY KEY (option_id),
  KEY ix_options_question (question_id, sort_order),
  CONSTRAINT fk_options_question
    FOREIGN KEY (question_id) REFERENCES questions (question_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- 7. tags 標籤
-- ------------------------------------------------------------
CREATE TABLE tags (
  tag_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  name   VARCHAR(50) NOT NULL,
  PRIMARY KEY (tag_id),
  UNIQUE KEY uq_tags_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- 8. question_tags 題目 × 標籤（多對多）
-- ------------------------------------------------------------
CREATE TABLE question_tags (
  question_id INT UNSIGNED NOT NULL,
  tag_id      INT UNSIGNED NOT NULL,
  PRIMARY KEY (question_id, tag_id),
  KEY ix_qtags_tag (tag_id),
  CONSTRAINT fk_qtags_question
    FOREIGN KEY (question_id) REFERENCES questions (question_id) ON DELETE CASCADE,
  CONSTRAINT fk_qtags_tag
    FOREIGN KEY (tag_id)      REFERENCES tags (tag_id)           ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- 9. exams 考卷
--    status：draft 草稿、published 已發佈、closed 已關閉
--    start_time / end_time 為 NULL 表示不限制
-- ------------------------------------------------------------
CREATE TABLE exams (
  exam_id      INT UNSIGNED NOT NULL AUTO_INCREMENT,
  course_id    INT UNSIGNED NOT NULL,
  creator_id   INT UNSIGNED NOT NULL,
  title        VARCHAR(150) NOT NULL,
  duration_min INT UNSIGNED NOT NULL,
  start_time   DATETIME NULL,
  end_time     DATETIME NULL,
  pass_score   DECIMAL(6,2) NOT NULL DEFAULT 60.00,
  max_attempts INT UNSIGNED NOT NULL DEFAULT 1,
  status       ENUM('draft','published','closed') NOT NULL DEFAULT 'draft',
  created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (exam_id),
  KEY ix_exams_course_status (course_id, status),
  KEY ix_exams_creator (creator_id),
  CONSTRAINT fk_exams_course
    FOREIGN KEY (course_id)  REFERENCES courses (course_id),
  CONSTRAINT fk_exams_creator
    FOREIGN KEY (creator_id) REFERENCES users (user_id),
  CONSTRAINT chk_exam_duration CHECK (duration_min > 0),
  CONSTRAINT chk_exam_attempts CHECK (max_attempts >= 1),
  CONSTRAINT chk_exam_pass     CHECK (pass_score >= 0),
  CONSTRAINT chk_exam_window   CHECK (end_time IS NULL OR start_time IS NULL OR end_time > start_time)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- 10. exam_questions 考卷 × 題目（多對多，帶配分與順序）
-- ------------------------------------------------------------
CREATE TABLE exam_questions (
  exam_id     INT UNSIGNED NOT NULL,
  question_id INT UNSIGNED NOT NULL,
  score       DECIMAL(5,2) NOT NULL DEFAULT 1.00,
  sort_order  INT NOT NULL DEFAULT 0,
  PRIMARY KEY (exam_id, question_id),
  KEY ix_eq_question (question_id),
  CONSTRAINT fk_eq_exam
    FOREIGN KEY (exam_id)     REFERENCES exams (exam_id) ON DELETE CASCADE,
  CONSTRAINT fk_eq_question
    FOREIGN KEY (question_id) REFERENCES questions (question_id),
  CONSTRAINT chk_eq_score CHECK (score > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- 11. exam_attempts 作答（一次作答 = 一張單據）
--     status：in_progress 作答中、submitted 已交卷、graded 已批改完成
-- ------------------------------------------------------------
CREATE TABLE exam_attempts (
  attempt_id   INT UNSIGNED NOT NULL AUTO_INCREMENT,
  exam_id      INT UNSIGNED NOT NULL,
  user_id      INT UNSIGNED NOT NULL,
  attempt_no   INT UNSIGNED NOT NULL DEFAULT 1,
  started_at   DATETIME NOT NULL,
  submitted_at DATETIME NULL,
  total_score  DECIMAL(6,2) NULL,
  status       ENUM('in_progress','submitted','graded') NOT NULL DEFAULT 'in_progress',
  PRIMARY KEY (attempt_id),
  UNIQUE KEY uq_attempt (exam_id, user_id, attempt_no),
  KEY ix_attempt_user (user_id, exam_id),
  KEY ix_attempt_status (status, started_at),
  CONSTRAINT fk_attempt_exam
    FOREIGN KEY (exam_id) REFERENCES exams (exam_id),
  CONSTRAINT fk_attempt_user
    FOREIGN KEY (user_id) REFERENCES users (user_id),
  CONSTRAINT chk_attempt_no CHECK (attempt_no >= 1)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- 12. attempt_answers 作答明細（開始作答時每題預先建立一列）
--     is_correct：NULL 尚未批改 / 0 答錯 / 1 答對
-- ------------------------------------------------------------
CREATE TABLE attempt_answers (
  answer_id     INT UNSIGNED NOT NULL AUTO_INCREMENT,
  attempt_id    INT UNSIGNED NOT NULL,
  question_id   INT UNSIGNED NOT NULL,
  answer_text   TEXT NULL,
  is_correct    TINYINT(1) NULL,
  score_awarded DECIMAL(5,2) NOT NULL DEFAULT 0.00,
  PRIMARY KEY (answer_id),
  UNIQUE KEY uq_answer (attempt_id, question_id),
  KEY ix_answer_question (question_id),
  CONSTRAINT fk_answer_attempt
    FOREIGN KEY (attempt_id)  REFERENCES exam_attempts (attempt_id) ON DELETE CASCADE,
  CONSTRAINT fk_answer_question
    FOREIGN KEY (question_id) REFERENCES questions (question_id),
  CONSTRAINT chk_answer_score CHECK (score_awarded >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- 13. attempt_answer_options 客觀題勾選的選項（多對多）
--     option_id 為 RESTRICT：被勾選過的選項不可刪除或重建
-- ------------------------------------------------------------
CREATE TABLE attempt_answer_options (
  answer_id INT UNSIGNED NOT NULL,
  option_id INT UNSIGNED NOT NULL,
  PRIMARY KEY (answer_id, option_id),
  KEY ix_aao_option (option_id),
  CONSTRAINT fk_aao_answer
    FOREIGN KEY (answer_id) REFERENCES attempt_answers (answer_id) ON DELETE CASCADE,
  CONSTRAINT fk_aao_option
    FOREIGN KEY (option_id) REFERENCES question_options (option_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- 14. bookmarks 收藏題目 / 錯題本筆記
-- ------------------------------------------------------------
CREATE TABLE bookmarks (
  user_id     INT UNSIGNED NOT NULL,
  question_id INT UNSIGNED NOT NULL,
  note        VARCHAR(255) NULL,
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (user_id, question_id),
  KEY ix_bookmarks_question (question_id),
  CONSTRAINT fk_bookmarks_user
    FOREIGN KEY (user_id)     REFERENCES users (user_id)         ON DELETE CASCADE,
  CONSTRAINT fk_bookmarks_question
    FOREIGN KEY (question_id) REFERENCES questions (question_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
