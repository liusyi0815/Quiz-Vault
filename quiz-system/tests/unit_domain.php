<?php
declare(strict_types=1);

/**
 * 單元測試：不需要伺服器、不需要資料庫。
 * 驗證純邏輯：題型規則、考卷開放時段與有效狀態、發佈檢查、日期時間與驗證器。
 *   php tests/unit_domain.php
 */

require __DIR__ . '/support.php';
require dirname(__DIR__) . '/src/autoload.php';

use App\Core\Config;
use App\Core\Validator;
use App\Domain\ExamRules;
use App\Domain\QuestionRules;
use App\Support\AppException;
use App\Support\Dates;

Config::load(dirname(__DIR__));
date_default_timezone_set('Asia/Taipei');
putenv('APP_TIMEZONE=Asia/Taipei');

$tz = new DateTimeZone('Asia/Taipei');
$now = new DateTimeImmutable('2026-11-10 10:00:00', $tz);
$o = static fn (string $c, bool $ok): array => ['content' => $c, 'is_correct' => $ok];

echo "\n[題型規則] Domain\\QuestionRules\n";
check('單選：4 選項 1 正確 → OK', QuestionRules::problem('single', [$o('a', true), $o('b', false), $o('c', false), $o('d', false)]) === null);
check('單選：2 個正確 → 不合格', QuestionRules::problem('single', [$o('a', true), $o('b', true)]) !== null);
check('單選：1 個選項 → 不合格', QuestionRules::problem('single', [$o('a', true)]) !== null);
check('單選：7 個選項 → 不合格', QuestionRules::problem('single', array_map(fn ($i) => $o("x{$i}", $i === 0), range(0, 6))) !== null);
check('多選：3 選項 2 正確 → OK', QuestionRules::problem('multiple', [$o('a', true), $o('b', true), $o('c', false)]) === null);
check('多選：3 選項全錯 → 不合格', QuestionRules::problem('multiple', [$o('a', false), $o('b', false), $o('c', false)]) !== null);
check('多選：2 選項 → 不合格', QuestionRules::problem('multiple', [$o('a', true), $o('b', false)]) !== null);
check('是非：「對」「錯」各 1 且 1 正確 → OK', QuestionRules::problem('truefalse', [$o('對', true), $o('錯', false)]) === null);
check('是非：選項不是對／錯 → 不合格', QuestionRules::problem('truefalse', [$o('Yes', true), $o('No', false)]) !== null);
check('是非：兩個都正確 → 不合格', QuestionRules::problem('truefalse', [$o('對', true), $o('錯', true)]) !== null);
check('簡答：沒有選項 → OK', QuestionRules::problem('short', []) === null);
check('簡答：有選項 → 不合格', QuestionRules::problem('short', [$o('a', true)]) !== null);
check('選項內容重複（不分大小寫）→ 不合格', QuestionRules::problem('single', [$o('A', true), $o('a', false)]) !== null);
check('未知題型 → 不合格', QuestionRules::problem('essay', []) !== null);

echo "\n[開放時段] ExamRules::isOpen / isEnded / effectiveStatus\n";
$exam = static fn (string $status, ?string $start, ?string $end): array => ['status' => $status, 'start_time' => $start, 'end_time' => $end];
check('草稿：不開放', !ExamRules::isOpen($exam('draft', null, null), $now));
check('已發佈、沒有時間限制：開放', ExamRules::isOpen($exam('published', null, null), $now));
check('已關閉：不開放', !ExamRules::isOpen($exam('closed', null, null), $now));
check('開始時間剛好等於現在：開放（start ≤ now）', ExamRules::isOpen($exam('published', '2026-11-10 10:00:00', null), $now));
check('開始時間還沒到：不開放', !ExamRules::isOpen($exam('published', '2026-11-10 10:00:01', null), $now));
check('結束時間剛好等於現在：不開放（end 必須 > now）', !ExamRules::isOpen($exam('published', null, '2026-11-10 10:00:00'), $now));
check('結束時間還差一秒：開放', ExamRules::isOpen($exam('published', null, '2026-11-10 10:00:01'), $now));
check('已發佈且超過結束時間 → isEnded', ExamRules::isEnded($exam('published', null, '2026-11-10 09:59:59'), $now));
check('已關閉不算 isEnded（它的有效狀態就是 closed）', !ExamRules::isEnded($exam('closed', null, '2020-01-01 00:00:00'), $now));
check('有效狀態：已發佈超過結束時間 → ended', ExamRules::effectiveStatus($exam('published', null, '2026-11-01 00:00:00'), $now) === 'ended');
check('有效狀態：已發佈未結束 → published', ExamRules::effectiveStatus($exam('published', null, '2026-12-01 00:00:00'), $now) === 'published');
check('有效狀態：草稿 → draft', ExamRules::effectiveStatus($exam('draft', null, '2020-01-01 00:00:00'), $now) === 'draft');

echo "\n[發佈檢查] ExamRules::publishFailures\n";
$settings = ['pass_score' => 60, 'duration_min' => 30, 'start_time' => null, 'end_time' => null];
$q = static fn (int $id, string $type, float $score, bool $active = true, ?array $options = null): array => [
    'question_id' => $id, 'type' => $type, 'score' => $score, 'is_active' => $active,
    'options' => $options ?? ($type === 'short' ? [] : [['content' => 'a', 'is_correct' => true], ['content' => 'b', 'is_correct' => false]]),
];
$rules = static fn (array $failures): array => array_column($failures, 'rule');

check('合格的考卷 → 沒有任何失敗', ExamRules::publishFailures($settings, [$q(1, 'single', 50), $q(2, 'short', 50)], $now) === []);
check('沒有題目 → no_questions（且不再報總分相關規則）', $rules(ExamRules::publishFailures($settings, [], $now)) === ['no_questions']);
$f = ExamRules::publishFailures($settings, [$q(1, 'single', 10), $q(2, 'single', 10)], $now);
check('及格分 60 > 總分 20 → pass_score_exceeds_total，並附上數字', $rules($f) === ['pass_score_exceeds_total'] && $f[0]['total_score'] === 20.0 && $f[0]['pass_score'] === 60.0);
check('及格分剛好等於總分 → 合格', ExamRules::publishFailures(['pass_score' => 20] + $settings, [$q(1, 'single', 10), $q(2, 'single', 10)], $now) === []);
check('及格分 0 → 合格', ExamRules::publishFailures(['pass_score' => 0] + $settings, [$q(1, 'single', 10)], $now) === []);
$f = ExamRules::publishFailures($settings, [$q(1, 'single', 100), $q(7, 'single', 0, false)], $now);
check('停用的題目 → question_inactive，帶題目編號', in_array(['rule' => 'question_inactive', 'question_id' => 7], $f, true));
$bad = [['content' => 'a', 'is_correct' => true], ['content' => 'b', 'is_correct' => true]];
$f = ExamRules::publishFailures($settings, [$q(9, 'single', 100, true, $bad)], $now);
check('單選有 2 個正確答案 → question_shape_invalid，帶原因', $rules($f) === ['question_shape_invalid'] && $f[0]['question_id'] === 9 && $f[0]['reason'] !== '');
check('總分 0 → total_score_zero', in_array('total_score_zero', $rules(ExamRules::publishFailures($settings, [$q(1, 'single', 0)], $now)), true));
check('開始時間不早於結束時間 → invalid_window',
    in_array('invalid_window', $rules(ExamRules::publishFailures(['start_time' => '2027-01-02 00:00:00', 'end_time' => '2027-01-01 00:00:00'] + $settings, [$q(1, 'single', 100)], $now)), true));
check('結束時間已過 → end_in_past',
    in_array('end_in_past', $rules(ExamRules::publishFailures(['end_time' => '2026-11-10 09:00:00'] + $settings, [$q(1, 'single', 100)], $now)), true));
check('結束時間剛好等於現在 → end_in_past（必須晚於現在）',
    in_array('end_in_past', $rules(ExamRules::publishFailures(['end_time' => '2026-11-10 10:00:00'] + $settings, [$q(1, 'single', 100)], $now)), true));
check('限時 0 分鐘 → duration_invalid', in_array('duration_invalid', $rules(ExamRules::publishFailures(['duration_min' => 0] + $settings, [$q(1, 'single', 100)], $now)), true));
$f = ExamRules::publishFailures(['pass_score' => 90, 'end_time' => '2020-01-01 00:00:00', 'start_time' => null, 'duration_min' => 30], [$q(1, 'single', 10), $q(2, 'single', 10, false)], $now);
check('同時有多個問題 → 一次全部列出', $rules($f) === ['end_in_past', 'question_inactive', 'pass_score_exceeds_total'], implode(',', $rules($f)));

echo "\n[日期時間] Dates::parse\n";
check('ISO 8601 含 +08:00', Dates::parse('2026-11-03T10:00:00+08:00') === '2026-11-03 10:00:00');
check('UTC（Z）會轉成台北時間', Dates::parse('2026-11-03T02:00:00Z') === '2026-11-03 10:00:00');
check('其他時區（+09:00）會轉成台北時間', Dates::parse('2026-11-03T11:00:00+09:00') === '2026-11-03 10:00:00');
check('沒有時區 → 當成本地時間', Dates::parse('2026-11-03 10:00') === '2026-11-03 10:00:00');
check('不存在的日期（2 月 31 日）→ null', Dates::parse('2026-02-31 10:00:00') === null);
check('亂寫的文字 → null', Dates::parse('明天下午') === null);
check('只有日期沒有時間 → null', Dates::parse('2026-11-03') === null);
check('Dates::iso 與 parse 互為反向', Dates::iso('2026-11-03 10:00:00') === '2026-11-03T10:00:00+08:00');

echo "\n[驗證器] Validator number / datetime / int / bool\n";
$err = static function (array $in, array $rules): array {
    try {
        Validator::validate($in, $rules);
    } catch (AppException $e) {
        return array_column($e->details, 'field');
    }
    return [];
};
check('number：12.5 與字串 "12.5" 都可以', Validator::validate(['a' => 12.5, 'b' => '12.5'], ['a' => 'number', 'b' => 'number']) === ['a' => 12.5, 'b' => 12.5]);
check('number：小於 min:0.01 → 錯誤', $err(['a' => 0.001], ['a' => 'number|min:0.01']) === ['a']);
check('number：大於 max:999.99 → 錯誤', $err(['a' => 1000], ['a' => 'number|max:999.99']) === ['a']);
check('number：999.99 剛好可以', $err(['a' => 999.99], ['a' => 'number|max:999.99']) === []);
check('number：文字 → 錯誤', $err(['a' => 'abc'], ['a' => 'number']) === ['a']);
check('int：3.5 不是整數 → 錯誤', $err(['a' => 3.5], ['a' => 'int']) === ['a']);
check('int：字串 "7" 可以轉成 7', Validator::validate(['a' => '7'], ['a' => 'int']) === ['a' => 7]);
check('bool：true / "0" / "false" 都可以', Validator::validate(['a' => true, 'b' => '0', 'c' => 'false'], ['a' => 'bool', 'b' => 'bool', 'c' => 'bool']) === ['a' => true, 'b' => false, 'c' => false]);
check('datetime：轉成資料庫格式', Validator::validate(['t' => '2026-12-01T09:00:00+08:00'], ['t' => 'datetime']) === ['t' => '2026-12-01 09:00:00']);
check('datetime：格式錯誤 → 錯誤', $err(['t' => 'tomorrow'], ['t' => 'datetime']) === ['t']);
check('nullable：有給 null 會輸出 null；沒給就不出現',
    Validator::validate(['t' => null], ['t' => 'nullable|datetime']) === ['t' => null] && Validator::validate([], ['t' => 'nullable|datetime']) === []);
check('in：不在清單內 → 錯誤', $err(['t' => 'x'], ['t' => 'in:a,b']) === ['t']);
check('required：空白字串（trim 後）→ 錯誤', $err(['t' => '   '], ['t' => 'required|trim']) === ['t']);

exit(summary());
