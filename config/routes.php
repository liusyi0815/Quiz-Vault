<?php
declare(strict_types=1);

use App\Core\Router;
use App\Http\Controllers\AdminUserController;
use App\Http\Controllers\AttemptController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\ChapterController;
use App\Http\Controllers\CourseController;
use App\Http\Controllers\EnrollmentController;
use App\Http\Controllers\ExamController;
use App\Http\Controllers\HealthController;
use App\Http\Controllers\QuestionController;

/**
 * 路由表。middleware 依序執行：auth → must_change → csrf → role
 * 端點清單與權限見 docs/03-api.md；「老師只能管自己的課」由各 Service 的 AccessPolicy 判斷。
 */
return static function (Router $r): void {
    // 一般受保護端點（任何登入的角色）
    $user    = ['auth', 'must_change', 'csrf'];
    // 「須先改密碼」狀態下仍可使用：查自己、改密碼、登出（不掛 must_change）
    $allow   = ['auth', 'csrf'];
    $student = ['auth', 'must_change', 'csrf', 'role:student'];
    $teacher = ['auth', 'must_change', 'csrf', 'role:teacher,admin'];
    $admin   = ['auth', 'must_change', 'csrf', 'role:admin'];

    // ---------- 公開 ----------
    $r->add('GET',  '/health',        [HealthController::class, 'index']);
    $r->add('POST', '/auth/register', [AuthController::class, 'register']);
    $r->add('POST', '/auth/login',    [AuthController::class, 'login']);

    // ---------- 帳號（F-01、F-02）----------
    $r->add('POST',  '/auth/logout', [AuthController::class, 'logout'],         $allow);
    $r->add('GET',   '/me',          [AuthController::class, 'me'],             $allow);
    $r->add('PUT',   '/me/password', [AuthController::class, 'changePassword'], $allow);
    $r->add('PATCH', '/me',          [AuthController::class, 'updateMe'],       $user);

    // ---------- 管理員（F-22）----------
    $r->add('POST', '/admin/users/{user_id}/reset-password', [AdminUserController::class, 'resetPassword'], $admin);

    // ---------- 課程（F-03）----------
    $r->add('GET',    '/courses',               [CourseController::class, 'index'],   $user);
    $r->add('POST',   '/courses',               [CourseController::class, 'store'],   $teacher);
    $r->add('GET',    '/courses/{course_id}',   [CourseController::class, 'show'],    $user);
    $r->add('PATCH',  '/courses/{course_id}',   [CourseController::class, 'update'],  $teacher);
    $r->add('DELETE', '/courses/{course_id}',   [CourseController::class, 'destroy'], $teacher);

    // ---------- 修課與退選（F-04）----------
    $r->add('POST',   '/courses/{course_id}/enrollment',          [EnrollmentController::class, 'enroll'],        $student);
    $r->add('DELETE', '/courses/{course_id}/enrollment',          [EnrollmentController::class, 'unenroll'],      $student);
    $r->add('GET',    '/courses/{course_id}/students',            [EnrollmentController::class, 'students'],      $teacher);
    $r->add('DELETE', '/courses/{course_id}/students/{user_id}',  [EnrollmentController::class, 'removeStudent'], $teacher);

    // ---------- 章節（F-05）----------
    $r->add('GET',    '/courses/{course_id}/chapters',        [ChapterController::class, 'index'],   $user);
    $r->add('POST',   '/courses/{course_id}/chapters',        [ChapterController::class, 'store'],   $teacher);
    $r->add('PUT',    '/courses/{course_id}/chapters/order',  [ChapterController::class, 'reorder'], $teacher);
    $r->add('PATCH',  '/chapters/{chapter_id}',               [ChapterController::class, 'update'],  $teacher);
    $r->add('DELETE', '/chapters/{chapter_id}',               [ChapterController::class, 'destroy'], $teacher);

    // ---------- 題庫（F-06，含選項與標籤）----------
    $r->add('GET',    '/questions',                [QuestionController::class, 'index'],   $teacher);
    $r->add('POST',   '/questions',                [QuestionController::class, 'store'],   $teacher);
    $r->add('GET',    '/questions/{question_id}',  [QuestionController::class, 'show'],    $teacher);
    $r->add('PUT',    '/questions/{question_id}',  [QuestionController::class, 'replace'], $teacher);
    $r->add('PATCH',  '/questions/{question_id}',  [QuestionController::class, 'patch'],   $teacher);
    $r->add('DELETE', '/questions/{question_id}',  [QuestionController::class, 'destroy'], $teacher);

    // ---------- 考卷：建立、組卷、隨機抽題、發佈（F-08、F-09、F-10）----------
    $r->add('GET',    '/exams',                            [ExamController::class, 'index'],          $user);
    $r->add('POST',   '/exams',                            [ExamController::class, 'store'],          $teacher);
    $r->add('GET',    '/exams/{exam_id}',                  [ExamController::class, 'show'],           $user);
    $r->add('PATCH',  '/exams/{exam_id}',                  [ExamController::class, 'update'],         $teacher);
    $r->add('DELETE', '/exams/{exam_id}',                  [ExamController::class, 'destroy'],        $teacher);
    $r->add('PUT',    '/exams/{exam_id}/questions',        [ExamController::class, 'replaceQuestions'], $teacher);
    $r->add('POST',   '/exams/{exam_id}/questions/random', [ExamController::class, 'drawRandom'],     $teacher);
    $r->add('POST',   '/exams/{exam_id}/publish',          [ExamController::class, 'publish'],        $teacher);
    $r->add('POST',   '/exams/{exam_id}/unpublish',        [ExamController::class, 'unpublish'],      $teacher);
    $r->add('POST',   '/exams/{exam_id}/close',            [ExamController::class, 'close'],          $teacher);

    // ---------- 作答、批改、成績（F-11、F-12、F-14、F-18）----------
    $r->add('POST', '/exams/{exam_id}/attempts',                       [AttemptController::class, 'start'],      $student);
    $r->add('GET',  '/exams/{exam_id}/attempts',                       [AttemptController::class, 'forExam'],    $teacher);
    $r->add('GET',  '/attempts/{attempt_id}',                          [AttemptController::class, 'show'],       $student);
    $r->add('PUT',  '/attempts/{attempt_id}/answers/{question_id}',    [AttemptController::class, 'saveAnswer'], $student);
    $r->add('POST', '/attempts/{attempt_id}/submit',                   [AttemptController::class, 'submit'],     $student);
    $r->add('GET',  '/attempts/{attempt_id}/result',                   [AttemptController::class, 'result'],     $user);
    $r->add('GET',  '/me/attempts',                                    [AttemptController::class, 'mine'],       $student);
};
