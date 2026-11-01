<?php

declare(strict_types=1);

namespace CodeIgniter\Throttle;

/**
 * A fixed-window limiter shared by PHP workers on the same filesystem.
 */
final class Throttler
{
    public function __construct(private string $directory)
    {
    }

    public function check(string $key, int $capacity, int $seconds): bool
    {
        if ($capacity < 1 || $seconds < 1) {
            throw new \InvalidArgumentException('Rate limits must be positive.');
        }
        if (!is_dir($this->directory) && !@mkdir($this->directory, 0700, true) && !is_dir($this->directory)) {
            return false;
        }
        $handle = @fopen($this->directory . '/' . hash('sha256', $key) . '.json', 'c+');
        if ($handle === false) {
            return false;
        }
        try {
            if (!flock($handle, LOCK_EX)) {
                return false;
            }
            $now = time();
            $state = json_decode(stream_get_contents($handle), true);
            if (!is_array($state) || !isset($state['expires'], $state['count']) || $state['expires'] <= $now) {
                $state = ['expires' => $now + $seconds, 'count' => 0];
            }
            if ($state['count'] >= $capacity) {
                return false;
            }
            $state['count']++;
            $data = json_encode($state, JSON_THROW_ON_ERROR);
            rewind($handle);
            return ftruncate($handle, 0) && fwrite($handle, $data) === strlen($data) && fflush($handle);
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }
}
