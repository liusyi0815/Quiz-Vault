<?php
declare(strict_types=1);

namespace App\Core;

/** 分頁參數：?page=1&per_page=20（預設 20、上限 100，可在 .env 調整） */
final class Page
{
    private function __construct(public readonly int $page, public readonly int $perPage)
    {
    }

    public static function fromRequest(Request $request): self
    {
        $default = Config::int('DEFAULT_PAGE_SIZE', 20);
        $max = Config::int('MAX_PAGE_SIZE', 100);

        $page = self::positiveInt($request->query['page'] ?? null) ?? 1;
        $per = self::positiveInt($request->query['per_page'] ?? null) ?? $default;
        return new self($page, min($per, $max));
    }

    public function offset(): int
    {
        return ($this->page - 1) * $this->perPage;
    }

    /** 回應用的 meta */
    public function meta(int $total): array
    {
        return ['page' => $this->page, 'per_page' => $this->perPage, 'total' => $total];
    }

    private static function positiveInt(mixed $v): ?int
    {
        return is_string($v) && ctype_digit($v) && (int) $v >= 1 ? (int) $v : null;
    }
}
