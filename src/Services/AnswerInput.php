<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Validator;
use App\Support\AppException;

/** 學生送來的「某一題的答案」的驗證與整理（暫存與交卷共用，docs/04 §7.4） */
final class AnswerInput
{
    /**
     * @param list<int> $validOptionIds 這一題實際擁有的選項
     * @return array{option_ids:list<int>, answer_text:?string}
     */
    public static function normalize(string $type, array $input, array $validOptionIds): array
    {
        if ($type === 'short') {
            $given = $input['option_ids'] ?? null;
            if ($given !== null && $given !== []) {
                throw new AppException(400, 'INVALID_OPTION', '簡答題不接受選項，請使用 answer_text');
            }
            $v = Validator::validate($input, ['answer_text' => 'nullable|max:5000']);
            return ['option_ids' => [], 'answer_text' => $v['answer_text'] ?? null];
        }

        if (isset($input['answer_text']) && $input['answer_text'] !== '') {
            throw AppException::validation([['field' => 'answer_text', 'reason' => '這一題是選擇題，不接受文字答案']]);
        }
        $raw = $input['option_ids'] ?? [];
        if (!is_array($raw)) {
            throw AppException::validation([['field' => 'option_ids', 'reason' => '必須是陣列']]);
        }
        $ids = [];
        foreach (array_values($raw) as $i => $id) {
            if (!is_int($id) || $id < 1) {
                throw AppException::validation([['field' => "option_ids[{$i}]", 'reason' => '必須是正整數']]);
            }
            $ids[] = $id;
        }
        if (count(array_unique($ids)) !== count($ids)) {
            throw new AppException(400, 'INVALID_OPTION', '選項不可重複');
        }
        $foreign = array_values(array_diff($ids, $validOptionIds));
        if ($foreign !== []) {
            throw new AppException(400, 'INVALID_OPTION', '勾選的選項不屬於這一題', array_map(static fn (int $id): array => ['option_id' => $id], $foreign));
        }
        if ($type !== 'multiple' && count($ids) > 1) {
            throw new AppException(400, 'INVALID_OPTION', '這一題只能勾選一個選項');
        }
        sort($ids);
        return ['option_ids' => $ids, 'answer_text' => null];
    }
}
