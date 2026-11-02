<?php

/** Batch names used by bounded lists, grouped by the database that owns them. */
final class ListNames
{
    public static function accounts($connection, array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn($id) => $id > 0)));
        $names = [];
        foreach (array_chunk($ids, 500) as $batch) {
            $rows = $connection->table(table('account'))->select(columns('account', ['id', 'username']))
                ->whereIn(column('account', 'id'), $batch)->get()->getResultArray();
            $names += array_column($rows, 'username', 'id');
        }
        return $names;
    }

    public static function characters($realms, array $byRealm): array
    {
        $names = [];
        foreach ($byRealm as $realmId => $ids) {
            if (!$realms->realmExists($realmId)) continue;
            $characters = $realms->getRealm($realmId)->getCharacters();
            foreach (array_chunk(array_values(array_unique(array_map('intval', $ids))), 500) as $batch) {
                $rows = $characters->getCharactersWhereIn(columns('characters', ['guid', 'name'], $realmId), column('characters', 'guid', false, $realmId), $batch);
                $names[$realmId] = ($names[$realmId] ?? []) + array_column((array) $rows, 'name', 'guid');
            }
        }
        return $names;
    }
}
