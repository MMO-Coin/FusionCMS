<?php

/** A bounded count cache; result pages still come from the realm database. */
final class ArmoryCountCache
{
    public const TTL = 30;
    public const MAX_ENTRIES = 128;
    private const MAX_BYTES = 65536;
    private string $directory;
    private string $lockPath;
    private Closure $clock;

    public function __construct(private bool $enabled, ?string $directory = null, ?callable $clock = null)
    {
        $root = defined('FCPATH') ? FCPATH : dirname(__DIR__, 2) . DIRECTORY_SEPARATOR;
        $this->directory = $directory ?? $root . 'writable/cache/data/search';
        // Admin data-cache clearing must not replace a lock held by another worker.
        $this->lockPath = $directory === null ? $root . 'writable/cache/armory_counts_v1.lock' : $directory . '/armory_counts_v1.lock';
        $this->clock = $clock === null ? static fn() => microtime(true) : Closure::fromCallable($clock);
    }

    public function remember(int $realmId, string $type, string $search, callable $load, bool $refresh = false): int
    {
        $key = hash('sha256', $realmId . "\0" . $type . "\0" . trim($search));
        $started = ($this->clock)();
        if ($this->enabled && !$refresh) {
            $cached = $this->locked(fn() => $this->read(($this->clock)())[$key]['count'] ?? null);
            if (is_int($cached)) return $cached;
        }

        // A failed database read must reach the caller and must never be cached.
        $count = $load();
        if (!is_int($count) || $count < 0) throw new UnexpectedValueException('Invalid Armory count');
        if (!$this->enabled) return $count;

        $this->locked(function() use ($key, $started, $count): void {
            $now = ($this->clock)();
            if (!is_finite((float) $started) || $started > $now || $now - $started >= self::TTL) return;
            $entries = $this->read($now);
            // An overlapping, newer count must not be replaced by this older read.
            if (isset($entries[$key]) && $entries[$key]['created'] > $started) return;
            unset($entries[$key]);
            $entries[$key] = ['count' => $count, 'created' => $started];
            uasort($entries, static fn($left, $right) => $left['created'] <=> $right['created']);
            $entries = array_slice($entries, -self::MAX_ENTRIES, null, true);
            $payload = json_encode(['version' => 1, 'entries' => $entries], JSON_THROW_ON_ERROR);
            if (strlen($payload) > self::MAX_BYTES) return;
            $temporary = $this->directory . '/armory_counts_v1.tmp';
            try {
                if (@file_put_contents($temporary, $payload) !== strlen($payload)) return;
                @rename($temporary, $this->directory . '/armory_counts_v1.cache');
            } finally {
                if (is_file($temporary)) @unlink($temporary);
            }
        });
        return $count;
    }

    private function read(float $now): array
    {
        if (!is_finite($now)) return [];
        $path = $this->directory . '/armory_counts_v1.cache';
        clearstatcache(true, $path);
        $size = @filesize($path);
        if ($size === false || $size > self::MAX_BYTES) return [];
        $contents = @file_get_contents($path);
        $bucket = is_string($contents) ? json_decode($contents, true, 8) : null;
        if (!is_array($bucket) || ($bucket['version'] ?? null) !== 1 || !is_array($bucket['entries'] ?? null)) return [];
        $valid = [];
        foreach (array_slice($bucket['entries'], -self::MAX_ENTRIES, null, true) as $key => $entry) {
            if (!is_string($key) || !preg_match('/^[a-f0-9]{64}$/D', $key) || !is_array($entry)) continue;
            $created = $entry['created'] ?? null;
            if (!is_int($entry['count'] ?? null) || $entry['count'] < 0 || (!is_int($created) && !is_float($created))
                || !is_finite((float) $created) || $created > $now || $now - $created >= self::TTL) continue;
            $valid[$key] = ['count' => $entry['count'], 'created' => $created];
        }
        return $valid;
    }

    private function locked(callable $operation): mixed
    {
        if (!$this->enabled) return null;
        if (!is_dir($this->directory) && !@mkdir($this->directory, 0775, true) && !is_dir($this->directory)) return null;
        $handle = @fopen($this->lockPath, 'c');
        if ($handle === false) return null;
        try {
            if (!@flock($handle, LOCK_EX)) return null;
            return $operation();
        } catch (Throwable $error) {
            // Cache availability must not prevent a fresh database count.
            return null;
        } finally {
            @flock($handle, LOCK_UN);
            fclose($handle);
        }
    }
}
