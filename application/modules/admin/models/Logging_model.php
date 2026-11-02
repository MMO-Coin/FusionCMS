<?php

require_once dirname(__DIR__, 3) . '/libraries/LogPagination.php';

/**
 * @package FusionCMS
 * @version 6.X
 * @author  Jesper Lindström
 * @author  Xavier Geerinck
 * @link    https://github.com/FusionWowCMS/FusionCMS
 */
class Logging_model extends CI_Model
{
    private function filteredLogs(string $search, string $module)
    {
        $builder = $this->db->table('logs');
        if ($module !== '') $builder->where('module', $module);
        if ($search !== '') {
            $userId = ctype_digit($search) ? filter_var($search, FILTER_VALIDATE_INT) :
                (filter_var($search, FILTER_VALIDATE_IP) ? false : $this->user->getId($search));
            $builder->groupStart()->where('ip', $search);
            if ($userId !== false && ($userId > 0 || $search === '0')) $builder->orWhere('user_id', $userId);
            $builder->groupEnd();
        }
        return $builder;
    }

    public function findLogs($search = '', $module = '', $offset = 0, $limit = LogPagination::DEFAULT_SIZE): array
    {
        $bounds = LogPagination::bounds($offset, $limit);
        if (!$bounds || !is_string($search) || !is_string($module)) return [];
        return $this->filteredLogs($search, $module)->orderBy('time', 'DESC')->orderBy('id', 'DESC')
            ->limit($bounds[1], $bounds[0])->get()->getResultArray();
    }

    public function countLogs(string $search = '', string $module = ''): int
    {
        return $this->filteredLogs($search, $module)->countAllResults();
    }

    public function countUserLogs($id): int
    {
        $id = filter_var($id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
        return $id === false ? 0 : $this->db->table('logs')->where('user_id', $id)->countAllResults();
    }

    public function getLogs($id = false, $offset = 0, $limit = LogPagination::DEFAULT_SIZE): array
    {
        $bounds = LogPagination::bounds($offset, $limit);
        $id = filter_var($id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
        if (!$bounds || $id === false) return [];
        return $this->db->table('logs')->where('user_id', $id)->orderBy('time', 'DESC')->orderBy('id', 'DESC')
            ->limit($bounds[1], $bounds[0])->get()->getResultArray();
    }
}
