<?php

use App\Config\Services;
use MX\MX_Controller;

/**
 * Admin Controller Class
 * @property dashboard_model $dashboard_model dashboard_model Class
 */
class Admin extends MX_Controller
{
    private array $totals = [];
    public function __construct()
    {
        parent::__construct();

        $this->load->library('administrator');

        $this->load->model('dashboard_model');

        requirePermission("view");
    }

    public function index()
    {
        $benchmark = Services::timer(true);
        $benchmark->start('admin_execution');

        $this->administrator->setTitle(lang('admin_dashboard', 'admin'));

        $server_software = 'Unknown';
        if (array_key_exists( 'SERVER_SOFTWARE',  $_SERVER)) {
            $server_software = $_SERVER['SERVER_SOFTWARE'];

            if (stripos($server_software, 'Apache') !== false && stripos($server_software, 'Win') !== false) {

                if (preg_match('/^Apache\/[\d\.]+(?:\s*\(Win\d+\))?/', $server_software, $matches)) {
                    $server_software = $matches[0];
                }
            }
        }

        $summaryKey = 'dashboard_totals_v2_' . date('Y_m_d');
        $summary = $this->cache->get($summaryKey);
        $this->totals = is_array($summary) ? $summary : $this->dashboard_model->getTotals();
        if (!is_array($summary)) $this->cache->save($summaryKey, $this->totals, 60);

        $graphMonthly = $this->getGraphData(false, [0, 1]);
        $graphDaily   = $this->getGraphData(true, [0, 1, 2]);

        $data = [
            'url' => $this->template->page_url,
            'theme' => $this->template->theme_data,
            'version' => $this->administrator->getVersion(),
            'php_version' => phpversion(),
            'ci_version' => CI_VERSION,
            'smarty_version'  => $this->smarty::SMARTY_VERSION,
            'os' => $this->getOsName(),
            'php_sapi' => PHP_SAPI,
            'server_software' => $server_software,
            'theme_value' => $this->config->item('theme'),
            'unique' => $this->getUnique(),
            'views' => $this->getViews(),
            'income' => $this->getIncome(),
            'votes' => $this->getVotes(),
            'nickname' => $this->user->getNickname(),
            'avatar' => $this->user->getAvatar($this->user->getId()),
            'groups' => $this->acl_model->getGroupsByUser($this->user->getId()),
            'email' => $this->user->getEmail(),
            'location' => $this->internal_user_model->getLocation(),
            'register_date' => $this->user->getRegisterDate(),
            'signups' => $this->getSignups(),
            'graphMonthly' => is_array($graphMonthly) ? [$graphMonthly[0], $graphMonthly[1]] : [],
            'graphDaily' => is_array($graphDaily) ? [$graphDaily[0], $graphDaily[1], $graphDaily[2]] : [],
            'realm_status' => $this->config->item('disable_realm_status'),
            'latestVersion' => $this->getLatestVersion(),
            'isOldTheme' => empty($this->template->theme_data['min_required_version']),
        ];

        $data['benchmark'] = $benchmark->stop('admin_execution')->getElapsedTime('admin_execution')  * 1000 . ' ms';
        $data['memory_usage'] = round(memory_get_usage() / 1024 / 1024, 2) . 'MB';

        $output = $this->template->loadPage("dashboard.tpl", $data);

        $content = $this->administrator->box(lang('admin_dashboard', 'admin'), $output);

        $this->administrator->view($content, false);
    }

    private function getUnique()
    {
        $data = $this->totals['unique'];

        return $data;
    }

    private function getViews()
    {
        $data = $this->totals['views'];

        return $data;
    }

    private function getIncome()
    {
        $data = $this->totals['income'];
        $data['growth'] = ($data['this'] > 0 && $data['last'] > 0) ? round((($data['this'] - $data['last']) / $data['last']) * 100, 2) : 0;

        return $data;
    }

    private function getVotes()
    {
        $data = $this->totals['votes'];
        $data['growth'] = ($data['this'] > 0 && $data['last'] > 0) ? round((($data['this'] - $data['last']) / $data['last']) * 100, 2) : 0;

        return $data;
    }

    private function getSignups()
    {
        $data = $this->totals['signups'];
        $data['growth'] = ($data['this'] > 0 && $data['last'] > 0) ? round((($data['this'] - $data['last']) / $data['last']) * 100, 2) : 0;

        $cache = $this->cache->get("dashboard_total_accounts_v2");

        if ($cache !== false)
        {
            $data['total'] = $cache;
        } else {
            $data['total'] = $this->external_account_model->getAccountCount();
            $this->cache->save("dashboard_total_accounts_v2", $data['total'], 60);
        }

        return $data;
    }

    private function getGraphData(bool $daily, array $agoList)
    {
        if ($this->config->item('disable_visitor_graph'))
            return false;

        $type = $daily ? 'daily' : 'monthly';
        $results = [];

        $agoDataList = [];
        foreach ($agoList as $ago) {
            $cacheKey = $this->getGraphCacheKey($type, $ago);
            $cached = $this->cache->get($cacheKey);
            if ($cached !== false) {
                $results[$ago] = $cached;
            } else {
                $agoDataList[] = $ago;
            }
        }

        if (empty($agoDataList)) {
            return $results;
        }

        $rows = $this->dashboard_model->getGraph($daily, $agoDataList);

        foreach ($agoDataList as $ago) {

            $range = [];
            $max = $daily ? 31 : 12;

            for ($i = 1; $i <= $max; $i++) {
                $range[sprintf('%02d', $i)] = 0;
            }

            $results[$ago] = $range;
        }

        foreach ($rows as $row) {
            [$year, $month, $day] = explode('-', $row['date']);
            $ago = (int) $row['ago'];
            $key = $daily ? $day : $month;
            $key = (int) $key < 10 ? '0' . (int) $key : (string)(int) $key;

            if (!isset($results[$ago][$key])) {
                $results[$ago][$key] = 0;
            }

            $results[$ago][$key] += $row['ipCount'];
        }

        if (!$daily) {
            foreach ($agoDataList as $ago) {
                $months = $results[$ago];
                $year = date('Y') - $ago;
                $results[$ago] = [
                    (string) $year => [
                        'month' => $months
                    ]
                ];
            }
        }

        foreach ($agoDataList as $ago) {
            $cacheKey = $this->getGraphCacheKey($type, $ago);
            $ttl = $this->getGraphCacheTTL($type, $ago);
            $this->cache->save($cacheKey, $results[$ago], $ttl);
        }

        return $results;
    }

    private function getGraphCacheKey(string $type, int $ago): string
    {
        $period = new DateTimeImmutable($type === 'daily' ? 'first day of this month' : date('Y') . '-01-01');
        $period = $period->modify('-' . $ago . ($type === 'daily' ? ' months' : ' years'));
        return 'dashboard_graph_v2_' . $type . '_' . $period->format('Y_m');
    }

    private function getGraphCacheTTL(string $type, int $ago): int
    {
        return $ago === 0 ? 60 : 86400;
    }

    public function checkSoap()
    {
        if (!extension_loaded('soap'))
        {
            show_error('SOAP not installed', 501);
        }

        $realms = $this->realms->getRealms();

        foreach ($realms as $realm)
        {
            if ($realm->isOnline(true))
            {
                $this->realms->getRealm($realm->getId())->getEmulator()->sendCommand('.server info', $realm);
            }
        }
    }

	public function realmstatus()
    {
        $realms = $this->realms->getRealms();
        $uptimes = $this->flush_uptime($realms);

        echo json_encode([
            'realms' => array_map(function($realm) use ($uptimes) {
                return [
                    'id' => $realm->getId(),
                    'name' => $realm->getName(),
                    'is_online' => $realm->isOnline(),
                    'percentage' => $realm->getPercentage(),
                    'online_players' => $realm->getOnline(),
                    'uptime' => $realm->isOnline() ? ($uptimes[$realm->getId()] ?? 0) : 0,
                ];
            }, $realms)
        ]);
    }

    public function destroySession()
    {
        Services::session()->remove('admin_access');
    }

    public function notifications($count = false)
    {
        if ($count)
        {
            $notifications = $this->cms_model->getNotifications($this->user->getId(), true);

            echo $notifications;
			die();
        } else {
            $notifications = $this->cms_model->getNotifications($this->user->getId(), false);

            $data = array(
                'notifications' => $notifications,
            );

            $out = $this->template->loadPage("notifications.tpl", $data);

            echo $out;
			die();
        }
    }

    public function markReadNotification($id, $all = false)
    {
        if ($all)
        {
            $uid = $this->user->getId();
            $this->cms_model->setReadNotification($id, $uid, true);
            die('yes');
        } else {
            $uid = $this->user->getId();
            $this->cms_model->setReadNotification($id, $uid, false);
            die('yes');
        }
    }

    private function flush_uptime($realms)
    {
        $uptimes = array();
        foreach ($realms as $realm) {
            $uptimes[$realm->getId()] = $this->uptime($realm->getId());
        }
        return $uptimes;
    }

    private function uptime($realm_id)
    {
        $this->connection = $this->load->database("account", true);
        $query = $this->connection->table('uptime')->where('realmid', $realm_id)->get();
        $last = $query->getLastRow('array');
        if (isset($last)) {
            $first_date = new DateTime(date('Y-m-d H:i:s', $last['starttime']));
            $second_date = new DateTime(date('Y-m-d H:i:s'));

            $difference = $first_date->diff($second_date);

            return $this->format_interval($difference);
        } else {
            return "Offline";
        }
    }

    private function format_interval(DateInterval $interval)
    {
        $result = "";
        if ($interval->y) {
            $result .= $interval->format("<span>%y</span>y ");
        }
        if ($interval->m) {
            $result .= $interval->format("<span>%m</span>m ");
        }
        if ($interval->d) {
            $result .= $interval->format("<span>%d</span>d ");
        }
        if ($interval->h) {
            $result .= $interval->format("<span>%h</span>h ");
        }
        if ($interval->i) {
            $result .= $interval->format("<span>%i</span>m ");
        }
        if ($interval->s) {
            $result .= $interval->format("<span>%s</span>s ");
        }

        return $result;
    }

    private function getLatestVersion(): bool
    {
        $response = Services::curlrequest()->get('https://raw.githubusercontent.com/FusionWowCMS/FusionCMS/master/application/config/version.php');
        $content = $response->getBody();
        if ($content)
            $newVersion = substr($content, 37, 5);
        else
            $newVersion = false;

        if ($this->template->compareVersions($newVersion, $this->config->item('FusionCMSVersion'), true))
            return true;

        return false;
    }

	private function getOsName(): string
	{
        if (strtoupper(substr(PHP_OS_FAMILY, 0, 3)) === 'WIN') {
            $os = substr(php_uname('v'), strpos(strtolower(php_uname('v')), 'windows'), -1);
        } else {
            $build = '';

            if (strpos(php_uname('v'), 'Ubuntu'))
                $build = 'Ubuntu';
            else if (strpos(php_uname('v'), 'Debian'))
                $build = 'Debian';

            $os = PHP_OS_FAMILY . $build;
        }

        return $os;
	}
}
