<?php

class Account_device_model extends CI_Model
{
    public static function agent(string $value): string
    {
        return mb_substr(mb_convert_encoding($value, 'UTF-8', 'UTF-8'), 0, 255);
    }

    public static function label(string $agent): string
    {
        $browser = 'Unknown browser';
        foreach (['Edge' => '/Edg(?:e|A|iOS)?\//i', 'Opera' => '/OPR\//i', 'Firefox' => '/Firefox\/|FxiOS\//i',
            'Chrome' => '/Chrome\/|CriOS\//i', 'Safari' => '/Safari\//i'] as $name => $pattern) {
            if (preg_match($pattern, $agent)) { $browser = $name; break; }
        }
        foreach (['Android', 'iPhone', 'iPad', 'Windows', 'Macintosh', 'Linux'] as $platform) {
            if (stripos($agent, $platform) !== false) return $browser . ' on ' . ($platform === 'Macintosh' ? 'macOS' : $platform);
        }
        return $browser;
    }

    public function track(int $accountId, string $token, string $version, string $ip, string $agent, int $lifetime): void
    {
        if ($accountId <= 0 || !preg_match('/^[a-f0-9]{64}$/D', $token) || $lifetime <= 0) {
            throw new InvalidArgumentException('Invalid session inventory identity.');
        }
        $identity = ['session_hash' => hash('sha256', $token), 'account_id' => $accountId, 'version' => $version];
        $metadata = ['last_seen_at' => date('Y-m-d H:i:s'), 'expires_at' => date('Y-m-d H:i:s', time() + $lifetime),
            'ip_address' => substr($ip, 0, 45), 'user_agent' => self::agent($agent)];
        if (!$this->db->table('account_sessions')->ignore(true)->insert($identity + $metadata + ['created_at' => date('Y-m-d H:i:s')])
            || !$this->db->table('account_sessions')->where($identity)->update($metadata)) {
            throw new RuntimeException('Unable to update the session inventory.');
        }
        if (random_int(1, 100) === 1) $this->purgeExpired();
    }

    public function forget(int $accountId, string $token): void
    {
        if (!$this->db->table('account_sessions')->where('account_id', $accountId)
            ->where('session_hash', hash('sha256', $token))->delete()) {
            throw new RuntimeException('Unable to remove the session inventory entry.');
        }
    }

    public function sessions(int $accountId, string $version, string $currentToken): array
    {
        $now = date('Y-m-d H:i:s');
        // A stale in-flight request must never delete a newer version's rows.
        $this->db->table('account_sessions')->where('account_id', $accountId)
            ->where('expires_at <=', $now)->delete();
        $rows = $this->db->table('account_sessions')->where('account_id', $accountId)->where('version', $version)
            ->where('expires_at >', $now)->orderBy('last_seen_at', 'DESC')->limit(100)->get()->getResultArray();
        return array_map(function (array $row) use ($currentToken) {
            $row['current'] = hash_equals($row['session_hash'], hash('sha256', $currentToken));
            $row['label'] = self::label($row['user_agent']);
            unset($row['session_hash'], $row['account_id'], $row['version']);
            return $row;
        }, $rows);
    }

    public function remembered(int $accountId, string $currentToken): array
    {
        $rows = $this->db->table('remember_me_tokens')->select('token_hash, created_at, expires_at, last_used_at, ip_address, user_agent')
            ->where('account_id', $accountId)->where('expires_at >', date('Y-m-d H:i:s'))
            ->orderBy('created_at', 'DESC')->limit(100)->get()->getResultArray();
        return array_map(function (array $row) use ($currentToken) {
            $row['current'] = $currentToken !== '' && hash_equals($row['token_hash'], hash('sha256', $currentToken));
            $row['label'] = self::label($row['user_agent']);
            unset($row['token_hash']);
            return $row;
        }, $rows);
    }

    public function purgeExpired(): void
    {
        $now = date('Y-m-d H:i:s');
        $rows = $this->db->table('account_sessions')->select('session_hash')->where('expires_at <=', $now)
            ->orderBy('expires_at')->limit(100)->get()->getResultArray();
        if ($rows) {
            // Recheck expiry in case a session was refreshed after the lookup.
            $this->db->table('account_sessions')->whereIn('session_hash', array_column($rows, 'session_hash'))
                ->where('expires_at <=', $now)->delete();
        }
    }
}
