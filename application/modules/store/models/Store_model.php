<?php

require_once dirname(__DIR__, 3) . '/libraries/PageBounds.php';

class Store_model extends CI_Model
{
    /** Legacy callers receive a bounded catalogue page, including ungrouped items. */
    public function getItems($realm, $offset = 0, $limit = 25): array
    {
        return $this->getCatalogueItems($realm, [], $offset, $limit);
    }

    private function catalogueQuery($realm, array $filters)
    {
        $realm = filter_var($realm, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $group = $filters['group'] ?? 'all'; $quality = $filters['quality'] ?? 'ALL';
        $currency = $filters['currency'] ?? 'all'; $name = $filters['name'] ?? ''; $sort = $filters['sort'] ?? 'standard';
        if (!$realm || (!is_int($group) && !is_string($group)) || ($group !== 'all' && filter_var($group, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]) === false)
            || (!is_int($quality) && !is_string($quality)) || ($quality !== 'ALL' && !in_array((string) $quality, ['0','1','2','3','4','5','6','7'], true))
            || !in_array($currency, ['all', 'vp', 'dp'], true) || !is_string($name) || mb_strlen($name) > 100
            || !in_array($sort, ['standard','name','priceVp','priceDp','quality'], true)) return null;
        $builder = $this->db->table('store_items s')->join('store_groups g', 's.group = g.id', 'left')->where('s.realm', $realm);
        if ($group !== 'all') {
            if ((int) $group === 0) $builder->groupStart()->where('s.group', 0)->orWhere('g.id', null)->groupEnd();
            else $builder->where('g.id', (int) $group);
        }
        if ($quality !== 'ALL') $builder->where('s.quality', (int) $quality);
        if ($currency !== 'all') $builder->where('s.' . $currency . '_price >', 0);
        $name = trim($name);
        if ($name !== '') {
            $escape = $this->db->likeEscapeChar;
            $name = strtr($name, [$escape => $escape . $escape, '%' => $escape . '%', '_' => $escape . '_']);
            $builder->like('s.name', $name, 'both', true);
        }
        return $builder;
    }

    public function getCatalogueItems($realm, array $filters = [], $offset = 0, $limit = 25): array
    {
        $bounds = PageBounds::bounds($offset, $limit);
        if (!$bounds || !($builder = $this->catalogueQuery($realm, $filters))) return [];
        $sort = $filters['sort'] ?? 'standard';
        if ($sort === 'name') $builder->orderBy('s.name', 'ASC');
        elseif ($sort === 'priceVp') $builder->orderBy('s.vp_price', 'ASC');
        elseif ($sort === 'priceDp') $builder->orderBy('s.dp_price', 'ASC');
        elseif ($sort === 'quality') $builder->orderBy('s.quality', 'DESC');
        else $builder->orderBy('g.id IS NULL', 'ASC', false)->orderBy('g.orderNumber', 'ASC')->orderBy('g.id', 'ASC');
        // Catalogue responses never carry delivery queries or console commands into the page.
        return $builder->select('s.id, s.itemid, s.itemcount, s.name, s.quality, s.vp_price, s.dp_price, s.realm, s.description, s.icon, s.tooltip')
            ->orderBy('s.id', 'ASC')->limit($bounds[1], $bounds[0])->get()->getResultArray();
    }

    public function countCatalogueItems($realm, array $filters = []): int
    {
        $builder = $this->catalogueQuery($realm, $filters);
        return $builder ? $builder->countAllResults() : 0;
    }

    public function getCatalogueGroups($realm): array
    {
        $realm = filter_var($realm, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if (!$realm) return [];
        return $this->db->table('store_items s')->select('g.id, g.title, g.orderNumber')
            ->join('store_groups g', 's.group = g.id', 'left')->where('s.realm', $realm)
            ->groupBy('g.id, g.title, g.orderNumber')->orderBy('g.id IS NULL', 'ASC', false)
            ->orderBy('g.orderNumber', 'ASC')->orderBy('g.id', 'ASC')->get()->getResultArray();
    }

    public function getItem($id)
    {
        $query = $this->db->table('store_items')->select()->where(['id' => $id])->orderBy('group', 'ASC')->get();

        if ($query->getNumRows() > 0) {
            $result = $query->getResultArray();

            return $result[0];
        } else {
            return false;
        }
    }

    public function getStoreGroups(): false|array
    {
        $query = $this->db->table('store_groups')->select()->get();

        if ($query->getNumRows() > 0) {
            return $query->getResultArray();
        } else {
            return false;
        }
    }

    public function logOrder($vp, $dp, $cart): int
    {
        if (!$this->db->table('order_log')->insert(['vp_cost' => $vp, 'dp_cost' => $dp, 'cart' => json_encode($cart),
            'completed' => 0, 'user_id' => $this->user->getId(), 'timestamp' => time()])) throw new RuntimeException('Unable to record the order');
        return (int) $this->db->insertID();
    }

    public function completeOrder(int $id): bool
    {
        return $this->db->table('order_log')->where('id', $id)->where('user_id', $this->user->getId())->where('completed', 0)
            ->update(['completed' => 1]) && $this->db->affectedRows() === 1;
    }

    private function orders(int $completed, int $userId)
    {
        $builder = $this->db->table('order_log')->where('completed', $completed);
        if ($completed === 0) $builder->where('timestamp >', time() - 7 * 86400);
        if ($userId > 0) $builder->where('user_id', $userId);
        return $builder;
    }

    public function getOrders(int $completed, $offset = 0, $limit = 25, int $userId = 0): array
    {
        $bounds = PageBounds::bounds($offset, $limit);
        if (!$bounds || !in_array($completed, [0, 1], true) || $userId < 0) return [];
        return $this->orders($completed, $userId)->orderBy('timestamp', 'DESC')->orderBy('id', 'DESC')->limit($bounds[1], $bounds[0])->get()->getResultArray();
    }

    public function countOrders(int $completed, int $userId = 0): int
    {
        return in_array($completed, [0, 1], true) && $userId >= 0 ? $this->orders($completed, $userId)->countAllResults() : 0;
    }

    public function findByUserId($type, $userId, $offset = 0, $limit = 25): array
    {
        $userId = filter_var($userId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        return $userId ? $this->getOrders((int) $type, $offset, $limit, $userId) : [];
    }

    public function getOrder($id)
    {
        return $this->db->table('order_log')->where('id', $id)->get()->getRowArray() ?: false;
    }

    public function getItemsByIds(array $ids): array
    {
        $items = [];
        foreach (array_chunk(array_values(array_unique(array_map('intval', $ids))), 500) as $batch) {
            $items += array_column($this->db->table('store_items')->select('id, name, realm')->whereIn('id', $batch)->get()->getResultArray(), null, 'id');
        }
        return $items;
    }

    /** Claim the failed order once. Its retained audit record and balance credit commit together. */
    public function refundOrder(int $id): ?array
    {
        if ($id < 1) return null;
        if (!$this->db->transBegin()) throw new RuntimeException('Unable to start refund');
        try {
            $order = $this->getOrder($id);
            if (!$order || (int) $order['completed'] !== 0 || (int) $order['vp_cost'] < 0 || (int) $order['dp_cost'] < 0) {
                $this->db->transRollback(); return null;
            }
            $claimed = $this->db->table('order_log')->where('id', $id)->where('completed', 0)->update(['completed' => -1]);
            if (!$claimed) throw new RuntimeException('Unable to claim refund');
            if ($this->db->affectedRows() !== 1) { $this->db->transRollback(); return null; }
            $credited = $this->db->query('UPDATE account_data SET vp = COALESCE(vp, 0) + ?, dp = COALESCE(dp, 0) + ? WHERE id = ?',
                [(int) $order['vp_cost'], (int) $order['dp_cost'], (int) $order['user_id']]);
            if (!$credited || $this->db->affectedRows() !== 1 || !$this->db->transStatus()) throw new RuntimeException('Unable to credit refund');
            if (!$this->db->transCommit()) throw new RuntimeException('Unable to commit refund');
            return $order;
        } catch (Throwable $error) { $this->db->transRollback(); throw $error; }
    }
}
