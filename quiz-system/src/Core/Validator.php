<?php
declare(strict_types=1);

namespace App\Core;

use App\Support\AppException;
use App\Support\Dates;

/**
 * 規則以 | 分隔：
 *   型別   string（預設）｜int｜number（可有小數）｜bool｜array｜datetime（ISO 8601，轉成資料庫用的 'Y-m-d H:i:s'）
 *   修飾   required｜nullable（欄位有給 null 或空字串時，輸出 null）｜trim｜email
 *   限制   min:N｜max:N（字串=字元數、整數=數值、陣列=元素數）｜maxbytes:N｜in:a,b,c
 * 例：['email' => 'required|email|trim|max:100', 'difficulty' => 'int|min:1|max:3']
 * 沒有給（或為空）且非 required 的欄位，不會出現在回傳結果裡。
 */
final class Validator
{
    public static function validate(array $input, array $rules): array
    {
        $errors = [];
        $out = [];

        foreach ($rules as $field => $ruleString) {
            $ruleList = explode('|', $ruleString);
            $required = in_array('required', $ruleList, true);
            $nullable = in_array('nullable', $ruleList, true);
            $trim     = in_array('trim', $ruleList, true);
            $type = 'string';
            foreach (['int', 'number', 'bool', 'array', 'datetime'] as $candidate) {
                if (in_array($candidate, $ruleList, true)) {
                    $type = $candidate;
                }
            }

            $present = array_key_exists($field, $input);
            $value = $input[$field] ?? null;
            if ($trim && is_string($value)) {
                $value = trim($value);
            }

            if ($value === null || $value === '') {
                if ($required) {
                    $errors[] = ['field' => $field, 'reason' => '必填'];
                } elseif ($nullable && $present) {
                    $out[$field] = null;
                }
                continue;
            }

            // ---- 型別檢查與轉換 ----
            if ($type === 'int') {
                if (is_string($value) && preg_match('/^-?\d+$/', $value) === 1) {
                    $value = (int) $value;
                }
                if (!is_int($value)) {
                    $errors[] = ['field' => $field, 'reason' => '必須是整數'];
                    continue;
                }
            } elseif ($type === 'number') {
                if (is_string($value) && is_numeric($value)) {
                    $value = $value + 0;
                }
                if ((!is_int($value) && !is_float($value)) || !is_finite((float) $value)) {
                    $errors[] = ['field' => $field, 'reason' => '必須是數字'];
                    continue;
                }
                $value = (float) $value;
            } elseif ($type === 'datetime') {
                $parsed = is_string($value) ? Dates::parse($value) : null;
                if ($parsed === null) {
                    $errors[] = ['field' => $field, 'reason' => '格式必須是 ISO 8601，例如 2026-11-03T10:00:00+08:00'];
                    continue;
                }
                $value = $parsed;
            } elseif ($type === 'bool') {
                if (!is_bool($value)) {
                    if (in_array($value, [1, '1', 'true'], true)) {
                        $value = true;
                    } elseif (in_array($value, [0, '0', 'false'], true)) {
                        $value = false;
                    } else {
                        $errors[] = ['field' => $field, 'reason' => '必須是 true 或 false'];
                        continue;
                    }
                }
            } elseif ($type === 'array') {
                if (!is_array($value)) {
                    $errors[] = ['field' => $field, 'reason' => '必須是陣列'];
                    continue;
                }
            } elseif (!is_string($value)) {
                $errors[] = ['field' => $field, 'reason' => '必須是文字'];
                continue;
            }

            // ---- 限制條件（遇到第一個不符就停止，一個欄位只回報一個原因）----
            foreach ($ruleList as $rule) {
                [$name, $arg] = array_pad(explode(':', $rule, 2), 2, null);
                $isNumber = is_int($value) || is_float($value);
                $size = $isNumber ? $value : (is_array($value) ? count($value) : mb_strlen((string) $value));
                $limit = $isNumber ? (float) $arg : (int) $arg;
                $reason = null;

                if ($name === 'email' && filter_var($value, FILTER_VALIDATE_EMAIL) === false) {
                    $reason = '格式不正確';
                } elseif ($name === 'min' && $size < $limit) {
                    $reason = match (true) {
                        $isNumber        => "不可小於 {$arg}",
                        is_array($value) => "至少要有 {$arg} 個項目",
                        default          => "長度至少 {$arg} 個字元",
                    };
                } elseif ($name === 'max' && $size > $limit) {
                    $reason = match (true) {
                        $isNumber        => "不可大於 {$arg}",
                        is_array($value) => "最多只能有 {$arg} 個項目",
                        default          => "長度不可超過 {$arg} 個字元",
                    };
                } elseif ($name === 'maxbytes' && strlen((string) $value) > (int) $arg) {
                    $reason = "長度不可超過 {$arg} 位元組";
                } elseif ($name === 'in' && !in_array($value, explode(',', (string) $arg), true)) {
                    $reason = '必須是其中之一：' . str_replace(',', '、', (string) $arg);
                }

                if ($reason !== null) {
                    $errors[] = ['field' => $field, 'reason' => $reason];
                    continue 2;
                }
            }
            $out[$field] = $value;
        }

        if ($errors !== []) {
            throw AppException::validation($errors);
        }
        return $out;
    }

    /** 路由中的 ID：必須是正整數，否則視為找不到 */
    public static function id(mixed $value): int
    {
        if (!is_string($value) || !ctype_digit($value) || (int) $value < 1) {
            throw AppException::notFound();
        }
        return (int) $value;
    }
}
