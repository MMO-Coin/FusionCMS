<?php

require_once dirname(__DIR__, 3) . '/libraries/PageBounds.php';

class Armory_model extends CI_Model
{
    private function searchTerm(string $search): ?string
    {
        $search = trim($search);
        return mb_strlen($search) >= 3 && mb_strlen($search) <= 100 ? $search : null;
    }

    private function filtered($db, string $tableName, string $field, string $search)
    {
        $escape = $db->likeEscapeChar;
        $search = strtr($search, [$escape => $escape . $escape, '%' => $escape . '%', '_' => $escape . '_']);
        return $db->table($tableName)->like($field, $search, 'both', true);
    }

    private function itemQuery(string $search, int $realmId)
    {
        $realm = $this->realms->realmExists($realmId) ? $this->realms->getRealm($realmId) : null;
        $term = $this->searchTerm($search);
        if (!$realm || $realm->getExpansionId() > 4 || $term === null) return null;
        return $this->filtered($realm->getWorld()->getConnection(), table('item_template', $realmId), column('item_template', 'name', false, $realmId), $term);
    }

    public function get_items(string $search, $limit, $offset, int $realmId = 1): array
    {
        $bounds = PageBounds::bounds($offset, $limit);
        if (!$bounds || !($builder = $this->itemQuery($search, $realmId))) return [];
        return $builder->select(columns('item_template', ['entry', 'name', 'ItemLevel', 'RequiredLevel', 'InventoryType', 'Quality', 'class', 'subclass'], $realmId))
            ->orderBy(column('item_template', 'ItemLevel', false, $realmId), 'DESC')
            ->orderBy(column('item_template', 'entry', false, $realmId), 'ASC')
            ->limit($bounds[1], $bounds[0])->get()->getResultArray();
    }

    public function get_items_count(string $search, int $realmId): int
    {
        $builder = $this->itemQuery($search, $realmId);
        return $builder ? $builder->countAllResults() : 0;
    }

    private function characterQuery(string $search, int $realmId)
    {
        $realm = $this->realms->realmExists($realmId) ? $this->realms->getRealm($realmId) : null;
        $term = $this->searchTerm($search);
        if (!$realm || $term === null) return null;
        return $this->filtered($realm->getCharacters()->getConnection(), table('characters', $realmId), column('characters', 'name', false, $realmId), $term);
    }

    public function get_characters(string $search, $limit, $offset, int $realmId = 1): array
    {
        $bounds = PageBounds::bounds($offset, $limit);
        if (!$bounds || !($builder = $this->characterQuery($search, $realmId))) return [];
        return $builder->select(columns('characters', ['guid', 'name', 'race', 'gender', 'class', 'level'], $realmId))
            ->orderBy(column('characters', 'name', false, $realmId), 'ASC')
            ->orderBy(column('characters', 'guid', false, $realmId), 'ASC')
            ->limit($bounds[1], $bounds[0])->get()->getResultArray();
    }

    public function get_characters_count(string $search, int $realmId): int
    {
        $builder = $this->characterQuery($search, $realmId);
        return $builder ? $builder->countAllResults() : 0;
    }

    private function guildQuery(string $search, int $realmId)
    {
        $realm = $this->realms->realmExists($realmId) ? $this->realms->getRealm($realmId) : null;
        $term = $this->searchTerm($search);
        if (!$realm || $term === null) return null;
        return $this->filtered($realm->getCharacters()->getConnection(), table('guild', $realmId) . ' g', 'g.' . column('guild', 'name', false, $realmId), $term);
    }

    public function get_guilds(string $search, $limit, $offset, int $realmId = 1): array
    {
        $bounds = PageBounds::bounds($offset, $limit);
        if (!$bounds || !($builder = $this->guildQuery($search, $realmId))) return [];
        $id = column('guild', 'guildid', false, $realmId);
        $name = column('guild', 'name', false, $realmId);
        $leader = column('guild', 'leaderguid', false, $realmId);
        $rows = $builder->select("g.$id AS guildid, g.$name AS name, g.$leader AS leaderguid, c." . column('characters', 'name', false, $realmId) . ' AS leaderName')
            ->join(table('characters', $realmId) . ' c', 'c.' . column('characters', 'guid', false, $realmId) . " = g.$leader", 'left')
            ->orderBy("g.$name", 'ASC')->orderBy("g.$id", 'ASC')->limit($bounds[1], $bounds[0])->get()->getResultArray();
        if (!$rows) return [];
        // Count members only for this page, without grouping or sorting the entire search result.
        $memberGuild = column('guild_member', 'guildid', false, $realmId);
        $counts = $this->realms->getRealm($realmId)->getCharacters()->getConnection()->table(table('guild_member', $realmId))
            ->select($memberGuild . ' AS guildid, COUNT(*) AS total')->whereIn($memberGuild, array_column($rows, 'guildid'))
            ->groupBy($memberGuild)->get()->getResultArray();
        $counts = array_column($counts, 'total', 'guildid');
        foreach ($rows as &$row) {
            $row['GuildMemberCount'] = (int) ($counts[$row['guildid']] ?? 0);
            $row['leaderName'] = $row['leaderName'] ?? '';
        }
        return $rows;
    }

    public function get_guilds_count(string $search, int $realmId): int
    {
        $builder = $this->guildQuery($search, $realmId);
        return $builder ? $builder->countAllResults() : 0;
    }
}
