<?php

if (!defined('BASEPATH')) {
    exit('No direct script access allowed');
}

/**
 * @package FusionCMS
 * @author  Jesper Lindström
 * @author  Xavier Geerinck
 * @author  Elliott Robbins
 * @author  Keramat Jokar (Nightprince) <https://github.com/Nightprince>
 * @author  Ehsan Zare (Darksider) <darksider.legend@gmail.com>
 * @link    https://github.com/FusionWowCMS/FusionCMS
 */

class Dblogger
{
    private $CI;

    /**
     * Logs status
     **/
    public const STATUS_FAILED  = 'failed';
    public const STATUS_SUCCEED = 'succeed';

    public function __construct()
    {
        //Get the instance of the CI
        $this->CI = &get_instance();

        // Load the logger model
        $this->CI->load->model('logger_model');
    }

    /**
     * Get all the logs by type and limit
     *
     * @param string|null $type
     * @param int $offset
     * @param int $limit
     * @param int $accountId
     * @param string|array|null $event
     * @return mixed
     */
    public function getLogs(?string $type = "", int $offset = 0, int $limit = 25, int $accountId = 0, string|null|array $event = ""): mixed
    {
        return $this->CI->logger_model->getLogsDb($type, $offset, $limit, $accountId, $event);
    }

    /**
     * Create a new log with the given data
     *
     * @param $type
     * @param string $event
     * @param string $message
     * @param array|string $custom
     * @param string $status
     * @param int|null $user
     */
    public function createLog($type, string $event, string $message, array|string $custom = [], string $status = self::STATUS_SUCCEED, int $user = null): void
    {
        // Module name
        $module = $this->CI->template->getModuleName();

        if ($this->CI->user->isOnline())
        {
            $user = $this->CI->user->getId();
        }

        // Call our model and add to the db.
        $this->CI->logger_model->createLogDb($module, $user, $type, $event, $message, $status, json_encode($custom), $this->CI->input->ip_address());
    }

    /**
     * Get the number of logs.
     *
     * @return mixed
     */
    public function getLogCount(): mixed
    {
        return $this->CI->logger_model->getLogCount();
    }

    public function getGMLogs(string $type = '', $offset = 0, $limit = 25, int $gmId = 0): array
    {
        require_once __DIR__ . '/ListNames.php';
        $rows = $this->CI->logger_model->getGMLogsDb($type, $offset, $limit, $gmId);
        $ids = array_column($rows, 'gm_id'); $byRealm = [];
        foreach ($rows as $row) {
            if ($row['type'] === 'account') $ids[] = $row['affected'];
            if ($row['type'] === 'characters') $byRealm[$row['realm']][] = $row['affected'];
        }
        $accounts = ListNames::accounts($this->CI->external_account_model->getConnection(), $ids);
        $characters = ListNames::characters($this->CI->realms, $byRealm);
        foreach ($rows as &$row) {
            $row['gmName'] = $accounts[$row['gm_id']] ?? (string) $row['gm_id'];
            $row['accountName'] = $accounts[$row['affected']] ?? (string) $row['affected'];
            $row['charName'] = $characters[$row['realm']][$row['affected']] ?? lang('missing_character', 'gm');
        }
        return $rows;
    }

    public function createGMLog($action, $affected, $type, $realmId): void
    {
        $gmId = 0;

        if ($this->CI->user->isOnline())
        {
            $gmId = $this->CI->user->getId();
        }

        // Call our model and add to the db.
        $this->CI->logger_model->createGMLogDb($action, $gmId, $affected, $this->CI->input->ip_address(), $type, $realmId);
    }
}
