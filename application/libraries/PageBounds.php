<?php

/** Bounds shared by public lists and account search. */
final class PageBounds
{
    public static function size($value, int $fallback = 10): int
    {
        $value = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        return min($value === false ? $fallback : $value, 100);
    }

    public static function offset($value): int
    {
        return filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]) ?: 0;
    }

    public static function bounds($offset, $size): ?array
    {
        $offset = filter_var($offset, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
        $size = filter_var($size, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        return $offset === false || $size === false ? null : [$offset, min($size, 100)];
    }

    public static function clamp($offset, int $total, int $size): int
    {
        return min(self::offset($offset), max(0, (int) floor(($total - 1) / $size) * $size));
    }
}
