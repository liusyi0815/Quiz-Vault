<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Validator;
use App\Support\AppException;

/** 考卷相關輸入的驗證與整理 */
final class ExamInput
{
    private const SETTINGS = [
        'title'        => 'trim|max:150',
        'duration_min' => 'int|min:1|max:1440',
        'start_time'   => 'nullable|datetime',
        'end_time'     => 'nullable|datetime',
        'pass_score'   => 'number|min:0|max:9999.99',
        'max_attempts' => 'int|min:1|max:100',
    ];

    /** @return array{course_id:int,title:string,duration_min:int,start_time:?string,end_time:?string,pass_score:float,max_attempts:int} */
    public static function create(array $in): array
    {
        $rules = self::SETTINGS;
        $rules['title'] = 'required|' . $rules['title'];
        $rules['duration_min'] = 'required|' . $rules['duration_min'];
        $v = Validator::validate($in, ['course_id' => 'required|int|min:1'] + $rules);

        $out = [
            'course_id'    => $v['course_id'],
            'title'        => $v['title'],
            'duration_min' => $v['duration_min'],
            'start_time'   => $v['start_time'] ?? null,
            'end_time'     => $v['end_time'] ?? null,
            'pass_score'   => round($v['pass_score'] ?? 60.0, 2),
            'max_attempts' => $v['max_attempts'] ?? 1,
        ];
        self::assertWindow($out['start_time'], $out['end_time']);
        return $out;
    }

    /** 只回傳「有給」的欄位（已正規化） */
    public static function patch(array $in): array
    {
        if (array_key_exists('course_id', $in)) {
            throw AppException::validation([['field' => 'course_id', 'reason' => '考卷不能搬到別的課程']]);
        }
        $v = Validator::validate($in, self::SETTINGS);
        if ($v === []) {
            throw AppException::validation([['field' => 'title', 'reason' => '至少要提供 title、duration_min、start_time、end_time、pass_score、max_attempts 其中一項']]);
        }
        if (isset($v['pass_score'])) {
            $v['pass_score'] = round($v['pass_score'], 2);
        }
        return $v;
    }

    /** 開始時間必須早於結束時間（兩者都有設定時） */
    public static function assertWindow(?string $start, ?string $end): void
    {
        if ($start !== null && $end !== null && $start >= $end) {
            throw AppException::validation([['field' => 'end_time', 'reason' => '必須晚於 start_time']]);
        }
    }

    /**
     * PUT /exams/{id}/questions
     * @return list<array{question_id:int,score:float,sort_order:int,i:int}> 依 sort_order 排序並重新編號為 1..n；i 是原本的位置（錯誤訊息用）
     */
    public static function questions(array $in): array
    {
        $v = Validator::validate($in, ['questions' => 'required|array|max:200']);

        $errors = [];
        $items = [];
        $seen = [];
        foreach (array_values($v['questions']) as $i => $raw) {
            if (!is_array($raw)) {
                $errors[] = ['field' => "questions[{$i}]", 'reason' => '必須是物件'];
                continue;
            }
            try {
                $row = Validator::validate($raw, [
                    'question_id' => 'required|int|min:1',
                    'score'       => 'required|number|min:0.01|max:999.99',
                    'sort_order'  => 'int|min:0|max:100000',
                ]);
            } catch (AppException $e) {
                foreach ($e->details as $d) {
                    $errors[] = ['field' => "questions[{$i}]." . $d['field'], 'reason' => $d['reason']];
                }
                continue;
            }
            if (isset($seen[$row['question_id']])) {
                $errors[] = ['field' => "questions[{$i}].question_id", 'reason' => '同一題不能出現兩次'];
                continue;
            }
            $seen[$row['question_id']] = true;
            $items[] = [
                'question_id' => $row['question_id'], 'score' => round($row['score'], 2),
                'sort_order' => $row['sort_order'] ?? ($i + 1), 'i' => $i,
            ];
        }
        if ($errors !== []) {
            throw AppException::validation($errors);
        }

        usort($items, static fn (array $a, array $b): int => [$a['sort_order'], $a['i']] <=> [$b['sort_order'], $b['i']]);
        foreach ($items as $n => &$it) {
            $it['sort_order'] = $n + 1;
        }
        return $items;
    }

    /**
     * POST /exams/{id}/questions/random
     * @return array{chapter_ids:list<int>, tags:list<string>, by_difficulty:?array<int,int>, count:?int, score_each:float}
     */
    public static function random(array $in): array
    {
        $v = Validator::validate($in, [
            'chapter_ids'   => 'array|max:100',
            'tags'          => 'array|max:10',
            'by_difficulty' => 'array',
            'count'         => 'int|min:1|max:200',
            'score_each'    => 'number|min:0.01|max:999.99',
        ]);

        $hasDifficulty = isset($v['by_difficulty']);
        $hasCount = isset($v['count']);
        if ($hasDifficulty === $hasCount) {
            throw AppException::validation([['field' => 'by_difficulty', 'reason' => 'by_difficulty（各難度題數）與 count（總題數）必須擇一提供']]);
        }

        $chapterIds = [];
        foreach (array_values($v['chapter_ids'] ?? []) as $i => $id) {
            if (!is_int($id) || $id < 1) {
                throw AppException::validation([['field' => "chapter_ids[{$i}]", 'reason' => '必須是正整數']]);
            }
            $chapterIds[] = $id;
        }

        $byDifficulty = null;
        if ($hasDifficulty) {
            $byDifficulty = [];
            foreach ($v['by_difficulty'] as $key => $n) {
                if (!in_array((int) $key, [1, 2, 3], true) || (string) (int) $key !== (string) $key) {
                    throw AppException::validation([['field' => 'by_difficulty', 'reason' => '難度只能是 1、2、3（易、中、難）']]);
                }
                if (!is_int($n) || $n < 0 || $n > 200) {
                    throw AppException::validation([['field' => "by_difficulty.{$key}", 'reason' => '題數必須是 0 到 200 的整數']]);
                }
                if ($n > 0) {
                    $byDifficulty[(int) $key] = $n;
                }
            }
            if ($byDifficulty === []) {
                throw AppException::validation([['field' => 'by_difficulty', 'reason' => '至少要有一個難度的題數大於 0']]);
            }
            ksort($byDifficulty);
        }

        return [
            'chapter_ids'   => array_values(array_unique($chapterIds)),
            'tags'          => QuestionInput::tags($v['tags'] ?? []),
            'by_difficulty' => $byDifficulty,
            'count'         => $v['count'] ?? null,
            'score_each'    => round($v['score_each'] ?? 1.0, 2),
        ];
    }
}
