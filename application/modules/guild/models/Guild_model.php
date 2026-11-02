<?php

use CodeIgniter\Database\BaseConnection;

require_once dirname(__DIR__, 3) . '/libraries/PageBounds.php';

class Guild_model extends CI_Model
{
    private BaseConnection $connection;

    public function getGuild($realm, $guildId)
    {
        $realm = $this->realms->getRealm($realm);
        $realm->getCharacters()->connect();
        $this->connection = $realm->getCharacters()->getConnection();

        $query = $this->connection->query(query('get_guild', $realm->getId()), [$guildId]);

        if ($query->getNumRows() > 0) {
            $result = $query->getResultArray();
            return $result[0];
        } else {
            return false;
        }
    }

    private function rosterQuery(int $realm): string
    {
        // Keep each emulator's joins and aliases, but apply one stable order and bounded page outside it.
        $sql = rtrim(query('get_guild_members', $realm), "; \t\r\n");
        return preg_replace('/\s+ORDER\s+BY\s+.+$/is', '', $sql);
    }

    public function getGuildMembers(int $realm, int $guildId, $offset = 0, $limit = 25): array
    {
        $bounds = PageBounds::bounds($offset, $limit);
        if (!$bounds || $guildId < 1 || !$this->realms->realmExists($realm)) return [];
        $db = $this->realms->getRealm($realm)->getCharacters()->getConnection();
        return $db->query('SELECT * FROM (' . $this->rosterQuery($realm) . ') roster ORDER BY rights DESC, member_rank ASC, guid ASC LIMIT ? OFFSET ?',
            [$guildId, $bounds[1], $bounds[0]])->getResultArray();
    }

    public function countGuildMembers(int $realm, int $guildId): int
    {
        if ($guildId < 1 || !$this->realms->realmExists($realm)) return 0;
        $db = $this->realms->getRealm($realm)->getCharacters()->getConnection();
        return (int) $db->query('SELECT COUNT(*) AS total FROM (' . $this->rosterQuery($realm) . ') roster', [$guildId])->getRowArray()['total'];
    }

    public function loadMember($realmId, $memberId)
    {
        $realm = $this->realms->getRealm($realmId);

        $data = $realm->getCharacters()->getCharacters(columns("characters", ["guid", "name", "race", "class", "gender", "level"], $realmId), [column("characters", "guid", false, $realmId) => $memberId]);

        return $data[0] ?? false;
    }
}
