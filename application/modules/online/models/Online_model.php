<?php

class Online_model extends CI_Model
{
    /** One auth lookup supplies the same staff exclusion for the page and both counts. */
    public function excludedAccounts(int $realmId): array
    {
        $rank = column('account_access', 'gmlevel');
        $source = $rank ? 'account_access' : 'account';
        $rank = $rank ?: column('account', 'gmlevel');
        $id = column($source, 'id');
        $table = table($source);
        if (!$rank || !$id || !$table) throw new RuntimeException('Online player GM filtering requires mapped account rank columns');
        $builder = $this->external_account_model->getConnection()->table($table)->select($id . ' AS id')->where($rank . ' >', 0);
        // Existing emulator rank queries identify schemas with per-realm grants.
        if ($source === 'account_access' && preg_match('/\bRealmID\b/i', (string) query('get_rank'))) {
            $builder->whereIn('RealmID', [-1, $realmId]);
        }
        $rows = $builder->get()->getResultArray();
        return array_values(array_unique(array_map('intval', array_column($rows, 'id'))));
    }
}
