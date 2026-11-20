<?php
declare(strict_types=1);

namespace App\Domain;

/**
 * 題型與選項的規則（docs/04 §4.1）。純函式、不碰資料庫，
 * 新增題目（QuestionInput）與考卷發佈檢查（ExamRules）共用同一套。
 */
final class QuestionRules
{
    /**
     * @param list<array{content:string,is_correct:bool}> $options
     * @return string|null 不符合規則時回傳原因，符合回傳 null
     */
    public static function problem(string $type, array $options): ?string
    {
        $count = count($options);

        if ($type === 'short') {
            return $count === 0 ? null : '簡答題不可有選項';
        }

        $correct = count(array_filter($options, static fn (array $o): bool => $o['is_correct']));
        $lower = array_map(static fn (array $o): string => mb_strtolower($o['content']), $options);
        if (count(array_unique($lower)) !== $count) {
            return '選項內容不可重複';
        }

        return match ($type) {
            'single' => match (true) {
                $count < 2 || $count > 6 => '單選題需要 2 到 6 個選項',
                $correct !== 1           => '單選題必須恰有 1 個正確答案',
                default                  => null,
            },
            'multiple' => match (true) {
                $count < 3 || $count > 8 => '多選題需要 3 到 8 個選項',
                $correct < 1             => '多選題至少要有 1 個正確答案',
                default                  => null,
            },
            'truefalse' => match (true) {
                $count !== 2 => '是非題必須恰有 2 個選項',
                !self::hasExactly($options, ['對', '錯']) => '是非題的選項必須是「對」與「錯」',
                $correct !== 1 => '是非題必須恰有 1 個正確答案',
                default => null,
            },
            default => '未知的題型',
        };
    }

    /** @param list<array{content:string}> $options @param list<string> $labels */
    private static function hasExactly(array $options, array $labels): bool
    {
        $contents = array_column($options, 'content');
        sort($contents);
        sort($labels);
        return $contents === $labels;
    }
}
