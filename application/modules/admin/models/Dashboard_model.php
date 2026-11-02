<?php

require_once dirname(__DIR__, 3) . '/libraries/LogPagination.php';

class Dashboard_model extends CI_Model
{
    private array $totals = [];

    public static function periods(?DateTimeImmutable $now = null): array
    {
        $today = ($now ?? new DateTimeImmutable('today'))->setTime(0, 0);
        $month = $today->modify('first day of this month');
        return ['today' => $today->format('Y-m-d'), 'tomorrow' => $today->modify('+1 day')->format('Y-m-d'),
            'month' => $month->format('Y-m-d'), 'previous_month' => $month->modify('-1 month')->format('Y-m-d'),
            'this_key' => $month->format('Y-m'), 'last_key' => $month->modify('-1 month')->format('Y-m')];
    }

    /** One snapshot per request, with bounded calendar ranges and four aggregate reads. */
    public function getTotals(?DateTimeImmutable $now = null): array
    {
        $p = self::periods($now);
        if (isset($this->totals[$p['today']])) return $this->totals[$p['today']];
        $visitors = $this->db->query('SELECT COUNT(DISTINCT CASE WHEN `date` = ? THEN ip END) AS today_unique,
            COUNT(CASE WHEN `date` = ? THEN 1 END) AS today_visits, COUNT(DISTINCT ip) AS month_unique, COUNT(*) AS month_visits
            FROM visitor_log WHERE `date` >= ? AND `date` < ?', [$p['today'], $p['today'], $p['month'], $p['tomorrow']])->getRowArray();
        $signups = $this->db->query('SELECT COALESCE(SUM(CASE WHEN `date` = ? THEN amount ELSE 0 END), 0) AS today_total,
            COALESCE(SUM(CASE WHEN `date` >= ? THEN amount ELSE 0 END), 0) AS month_total,
            COALESCE(SUM(CASE WHEN `date` < ? THEN amount ELSE 0 END), 0) AS last_total
            FROM daily_signups WHERE `date` >= ? AND `date` < ?',
            [$p['today'], $p['month'], $p['month'], $p['previous_month'], $p['tomorrow']])->getRowArray();
        $monthly = [];
        foreach (['income' => 'monthly_income', 'votes' => 'monthly_votes'] as $key => $table) {
            $row = $this->db->query('SELECT COALESCE(SUM(CASE WHEN month = ? THEN amount ELSE 0 END), 0) AS this_total,
                COALESCE(SUM(CASE WHEN month = ? THEN amount ELSE 0 END), 0) AS last_total FROM ' . $table . ' WHERE month IN (?, ?)',
                [$p['this_key'], $p['last_key'], $p['this_key'], $p['last_key']])->getRowArray();
            $monthly[$key] = ['this' => $row['this_total'], 'last' => $row['last_total']];
        }
        return $this->totals[$p['today']] = ['unique' => ['today' => (int) $visitors['today_unique'], 'month' => (int) $visitors['month_unique']],
            'views' => ['today' => (int) $visitors['today_visits'], 'month' => (int) $visitors['month_visits']],
            'signups' => ['today' => (int) $signups['today_total'], 'month' => (int) $signups['month_total'],
                'this' => (int) $signups['month_total'], 'last' => (int) $signups['last_total']],
            'income' => $monthly['income'], 'votes' => $monthly['votes']];
    }

    public function getUnique($type) { return $this->getTotals()['unique'][$type === 'today' ? 'today' : 'month']; }
    public function getViews($type) { return $this->getTotals()['views'][$type === 'today' ? 'today' : 'month']; }
    public function getIncome($type) { return $this->getTotals()['income'][$type === 'this' ? 'this' : 'last']; }
    public function getVotes($type) { return $this->getTotals()['votes'][$type === 'this' ? 'this' : 'last']; }
    public function getSignupsMonthly($type) { return $this->getTotals()['signups'][$type === 'this' ? 'this' : 'last']; }
    public function getSignupsDaily($type) { return $this->getTotals()['signups'][$type === 'today' ? 'today' : 'month']; }

    public function getGraph(bool $daily = false, array $agos = [0], ?DateTimeImmutable $now = null): array
    {
        $today = ($now ?? new DateTimeImmutable('today'))->setTime(0, 0);
        $agoList = [];
        foreach ($agos as $ago) {
            $ago = filter_var($ago, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => 10]]);
            if ($ago !== false) $agoList[$ago] = $ago;
        }
        if (!$agoList) return [];
        $queries = []; $bindings = [];
        foreach ($agoList as $ago) {
            $from = $daily ? $today->modify('first day of this month')->modify('-' . $ago . ' months')
                : $today->setDate((int) $today->format('Y') - $ago, 1, 1);
            $to = $from->modify($daily ? '+1 month' : '+1 year');
            if ($to > $today->modify('+1 day')) $to = $today->modify('+1 day');
            $queries[] = 'SELECT `date`, COUNT(DISTINCT ip) AS ipCount, ? AS ago FROM visitor_log WHERE `date` >= ? AND `date` < ? GROUP BY `date`';
            array_push($bindings, $ago, $from->format('Y-m-d'), $to->format('Y-m-d'));
        }
        return $this->db->query(implode(' UNION ALL ', $queries), $bindings)->getResultArray();
    }

    /* Modules */
    public function getEnabledModules()
    {
        $query = $this->db->query("SELECT id, name, display_name, enabled, type, creator, description, date_added FROM modules WHERE enabled = 1");

        if ($query->getNumRows() > 0) {
            return $query->getResultArray();
        }

        return null;
    }

    public function getDisabledModules()
    {
        $query = $this->db->query("SELECT id, name, display_name, enabled, type, creator, description, date_added FROM modules WHERE enabled = 0");

        if ($query->getNumRows() > 0) {
            return $query->getResultArray();
        }

        return null;
    }

    /* Enable / Disable modules */
    public function enableModule($moduleId)
    {
        $moduleId = (int)$moduleId;

        if (!is_int($moduleId)) {
            throw new Exception("Not a number");
        }

        $query = $this->db->query("UPDATE modules SET enabled = 1 WHERE id = ?", array($moduleId));

        if ($query) {
            return;
        }

        throw new Exception("Could not enable module with id: " . $moduleId);
    }

    public function disableModule($moduleId)
    {
        $moduleId = (int)$moduleId;

        if (!is_int($moduleId)) {
            throw new Exception("Not a number");
        }

        $query = $this->db->query("UPDATE modules SET enabled = 0 WHERE id = ?", array($moduleId));

        if ($query) {
            return;
        }

        throw new Exception("Could not disable module with id: " . $moduleId);
    }

    private function emailLogsQuery(string $search)
    {
        $builder = $this->db->table('email_log');
        if ($search !== '') {
            // Escape LIKE metacharacters; the query builder binds quotes separately.
            $escape = $this->db->likeEscapeChar;
            $term = strtr($search, [$escape => $escape . $escape, '%' => $escape . '%', '_' => $escape . '_']);
            $builder->groupStart()->like('email', $term, 'both', true)->orLike('subject', $term, 'both', true);
            if (ctype_digit($search)) $builder->orWhere('uid', $search);
            $builder->groupEnd();
        }
        return $builder;
    }

    public function countEmailLogs(string $search = ''): int
    {
        return $this->emailLogsQuery($search)->countAllResults();
    }

    public function getEmailLogs($offset = 0, $limit = LogPagination::DEFAULT_SIZE, string $search = ''): array
    {
        $bounds = LogPagination::bounds($offset, $limit);
        if (!$bounds) return [];
        // IDs are monotonic; the legacy timestamp column is a varchar, not a sortable date type.
        return $this->emailLogsQuery($search)->orderBy('id', 'DESC')->limit($bounds[1], $bounds[0])
            ->get()->getResultArray();
    }
}
