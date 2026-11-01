<?php

class Totp_model extends CI_Model
{
    /**
     * Claim a verified time step once across all website sessions and workers.
     * Keep the high-water mark even after 2FA is disabled or sessions expire.
     */
    public function claim(int $accountId, string $key, int $timeStep): bool
    {
        if ($accountId <= 0 || $key === '' || $timeStep < 0) return false;
        $identity = ['account_id' => $accountId, 'secret_hash' => hash('sha256', $key)];
        $inserted = $this->db->table('account_totp_uses')->ignore(true)->insert($identity + ['time_step' => -1]);
        if (!$inserted) throw new RuntimeException('Unable to initialize authenticator replay protection.');
        $updated = $this->db->table('account_totp_uses')->where($identity)
            ->where('time_step <', $timeStep)->update(['time_step' => $timeStep]);
        if (!$updated) throw new RuntimeException('Unable to claim the authenticator code.');
        return $this->db->affectedRows() === 1;
    }
}
