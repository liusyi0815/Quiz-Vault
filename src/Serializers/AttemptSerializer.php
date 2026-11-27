<?php
declare(strict_types=1);

namespace App\Serializers;

use App\Support\Dates;

/**
 * 作答的回應結構。
 * inProgress() 是學生作答期間看到的版本：整個結構裡「沒有」is_correct 與解析；
 * result() 只有在符合可見性規則（或呼叫者是老師、管理員）時才會帶出逐題結果。
 */
final class AttemptSerializer
{
    /**
     * @param list<array> $answers  AttemptRepository::answers()
     * @param array<int,list<array>> $options  optionsFor($ids, false)：沒有 is_correct
     * @param array<int,list<int>> $chosen  answer_id => option_ids
     */
    public static function inProgress(array $attempt, \DateTimeImmutable $deadline, \DateTimeImmutable $now, array $answers, array $options, array $chosen): array
    {
        return [
            'attempt_id'        => (int) $attempt['attempt_id'],
            'exam'              => ['exam_id' => (int) $attempt['exam_id'], 'title' => $attempt['title']],
            'attempt_no'        => (int) $attempt['attempt_no'],
            'status'            => $attempt['status'],
            'started_at'        => Dates::iso($attempt['started_at']),
            'deadline_at'       => $deadline->format('c'),
            'server_time'       => $now->format('c'),
            'remaining_seconds' => max(0, $deadline->getTimestamp() - $now->getTimestamp()),
            'questions'         => array_map(static fn (array $a): array => [
                'question_id' => (int) $a['question_id'],
                'type'        => $a['type'],
                'content'     => $a['content'],
                'score'       => (float) $a['score'],
                'options'     => array_map(static fn (array $o): array => [
                    'option_id' => (int) $o['option_id'], 'content' => $o['content'],
                ], $options[(int) $a['question_id']] ?? []),
                'answer'      => ['option_ids' => $chosen[(int) $a['answer_id']] ?? [], 'answer_text' => $a['answer_text']],
            ], $answers),
        ];
    }

    /**
     * @param list<array>|null $detail 逐題結果；null 代表尚未開放
     */
    public static function result(array $attempt, ?bool $passed, int $pending, bool $late, ?array $detail): array
    {
        $out = [
            'attempt_id'             => (int) $attempt['attempt_id'],
            'exam'                   => [
                'exam_id' => (int) $attempt['exam_id'], 'title' => $attempt['title'],
                'course'  => ['course_id' => (int) $attempt['course_id'], 'name' => $attempt['course_name']],
            ],
            'attempt_no'             => (int) $attempt['attempt_no'],
            'status'                 => $attempt['status'],
            'started_at'             => Dates::iso($attempt['started_at']),
            'submitted_at'           => Dates::iso($attempt['submitted_at']),
            'total_score'            => (float) $attempt['total_score'],
            'pass_score'             => (float) $attempt['pass_score'],
            'passed'                 => $passed,
            'late'                   => $late,
            'pending_manual_grading' => $pending,
            'answers_revealed'       => $detail !== null,
        ];
        if ($detail !== null) {
            $out['answers'] = $detail;
        }
        return $out;
    }

    /**
     * @param list<array> $answers
     * @param array<int,list<array>> $options  optionsFor($ids, true)
     * @param array<int,list<int>> $chosen
     */
    public static function detail(array $answers, array $options, array $chosen): array
    {
        return array_map(static function (array $a) use ($options, $chosen): array {
            $mine = $chosen[(int) $a['answer_id']] ?? [];
            return [
                'question_id'   => (int) $a['question_id'],
                'type'          => $a['type'],
                'content'       => $a['content'],
                'score'         => (float) $a['score'],
                'score_awarded' => (float) $a['score_awarded'],
                'is_correct'    => $a['is_correct'] === null ? null : (bool) $a['is_correct'],
                'my_option_ids' => $mine,
                'answer_text'   => $a['answer_text'],
                'options'       => array_map(static fn (array $o): array => [
                    'option_id' => (int) $o['option_id'], 'content' => $o['content'], 'is_correct' => (bool) $o['is_correct'],
                ], $options[(int) $a['question_id']] ?? []),
                'explanation'   => $a['explanation'],
            ];
        }, $answers);
    }

    /** 「我的作答紀錄」列表的一筆 */
    public static function listItem(array $row, ?bool $passed, ?string $deadlineIso): array
    {
        $inProgress = $row['status'] === 'in_progress';
        $out = [
            'attempt_id'             => (int) $row['attempt_id'],
            'exam'                   => [
                'exam_id' => (int) $row['exam_id'], 'title' => $row['title'],
                'course'  => ['course_id' => (int) $row['course_id'], 'name' => $row['course_name']],
            ],
            'attempt_no'             => (int) $row['attempt_no'],
            'status'                 => $row['status'],
            'started_at'             => Dates::iso($row['started_at']),
            'submitted_at'           => Dates::iso($row['submitted_at']),
            'total_score'            => $inProgress ? null : (float) $row['total_score'],
            'pass_score'             => (float) $row['pass_score'],
            'passed'                 => $passed,
            'pending_manual_grading' => (int) $row['pending'],
        ];
        if ($deadlineIso !== null) {
            $out['deadline_at'] = $deadlineIso;
        }
        return $out;
    }
}
