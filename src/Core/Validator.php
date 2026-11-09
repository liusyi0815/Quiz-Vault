<?php
declare(strict_types=1);

namespace App\Core;

use App\Support\AppException;

/**
 * 規則字串：required | email | trim | min:N | max:N | maxbytes:N
 * 例：['email' => 'required|email|trim|max:100']
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
            $value = $input[$field] ?? null;

            if ($value === null || $value === '') {
                if ($required) {
                    $errors[] = ['field' => $field, 'reason' => '必填'];
                }
                continue;
            }
            if (!is_string($value)) {
                $errors[] = ['field' => $field, 'reason' => '必須是文字'];
                continue;
            }
            if (in_array('trim', $ruleList, true)) {
                $value = trim($value);
            }

            foreach ($ruleList as $rule) {
                [$name, $arg] = array_pad(explode(':', $rule, 2), 2, null);
                $len = mb_strlen($value);
                if ($name === 'email' && filter_var($value, FILTER_VALIDATE_EMAIL) === false) {
                    $errors[] = ['field' => $field, 'reason' => '格式不正確'];
                    break;
                }
                if ($name === 'min' && $len < (int) $arg) {
                    $errors[] = ['field' => $field, 'reason' => "長度至少 {$arg} 個字元"];
                    break;
                }
                if ($name === 'max' && $len > (int) $arg) {
                    $errors[] = ['field' => $field, 'reason' => "長度不可超過 {$arg} 個字元"];
                    break;
                }
                if ($name === 'maxbytes' && strlen($value) > (int) $arg) {
                    $errors[] = ['field' => $field, 'reason' => "長度不可超過 {$arg} 位元組"];
                    break;
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
