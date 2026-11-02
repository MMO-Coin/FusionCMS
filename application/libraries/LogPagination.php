<?php

/** Shared bounds for log readers, including callers outside the admin UI. */
final class LogPagination
{
    public const DEFAULT_SIZE = 25;
    public const MAX_SIZE = 100;

    public static function bounds($offset, $limit): ?array
    {
        $offset = filter_var($offset, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
        $limit = filter_var($limit, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        return $offset === false || $limit === false ? null : [$offset, min($limit, self::MAX_SIZE)];
    }
}
