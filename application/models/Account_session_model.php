<?php

class Account_session_model extends CI_Model
{
    /**
     * Accounts without a revocation record use the original, empty version.
     * Read from the database on every request, including for legacy sessions.
     */
    public function getVersion(int $accountId): string
    {
        $row = $this->db->table('account_session_versions')->select('version')
            ->where('account_id', $accountId)->get()->getRowArray();
        return $row['version'] ?? '';
    }

    /**
     * Revoke active sessions and remembered logins in one CMS transaction.
     * A random version also revokes sessions when the password is unchanged.
     */
    public function invalidate(int $accountId): void
    {
        if ($accountId <= 0) throw new InvalidArgumentException('Invalid session account.');
        if (!$this->db->transBegin()) throw new RuntimeException('Unable to begin session revocation.');
        try {
            $updated = $this->db->table('account_session_versions')->upsert([
                'account_id' => $accountId, 'version' => bin2hex(random_bytes(32)),
            ]);
            $deleted = $this->db->table('remember_me_tokens')->where('account_id', $accountId)->delete();
            if (!$updated || !$deleted || !$this->db->transStatus() || !$this->db->transCommit()) {
                throw new RuntimeException('Unable to revoke account sessions.');
            }
        } catch (Throwable $error) {
            $this->db->transRollback();
            throw $error;
        }
    }
}
