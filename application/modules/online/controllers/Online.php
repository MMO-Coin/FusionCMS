<?php

use MX\MX_Controller;

class Online extends MX_Controller
{
    public function __construct()
    {
        parent::__construct();
        requirePermission('view');
        $this->load->config('online');
        $this->load->model('online_model');
        require_once APPPATH . 'libraries/PageBounds.php';
    }

    public function index()
    {
        foreach (['no_players', 'offline', 'load_failed', 'loading', 'choose_realm', 'updated_at', 'no_matches'] as $key) clientLang($key, 'online');
        $this->template->setTitle(lang('online_players', 'online'));
        $content = $this->template->loadPage('online.tpl', ['realms' => $this->realms->getRealms(), 'url' => $this->template->page_url]);
        $page = $this->template->loadPage('page.tpl', ['module' => 'default', 'headline' => lang('online_players', 'online'), 'content' => $content]);
        $this->template->view($page, 'modules/online/css/online.css', 'modules/online/js/online.js');
    }

    public function online_refresh($realm_id = 0)
    {
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        $draw = PageBounds::offset($this->input->get('draw'));
        $bounds = PageBounds::bounds($this->input->get('start') ?? 0, $this->input->get('length') ?? 10);
        $realmId = filter_var($realm_id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $search = $this->input->get('search', false) ?? '';
        if (!$realmId || !$bounds || !is_string($search) || mb_strlen($search) > 100 || !$this->realms->realmExists($realmId)) {
            \App\Config\Services::response()->setStatusCode(400);
            echo json_encode(['draw' => $draw, 'status' => 'invalid', 'recordsTotal' => 0, 'recordsFiltered' => 0, 'data' => []]); return;
        }
        $search = trim($search);
        $realm = $this->realms->getRealm($realmId);
        $refresh = $this->input->get('refresh') === '1';
        if (!$realm->isOnline($refresh)) {
            echo json_encode(['draw' => $draw, 'status' => 'offline', 'recordsTotal' => 0, 'recordsFiltered' => 0, 'data' => [], 'updated_at' => time()]); return;
        }
        $hideGms = (bool) $this->config->item('hide_gms');
        $ttl = min(120, max(5, (int) ($this->config->item('online_page_cache_seconds') ?: 30)));
        // Bound the number of cached entries. Arbitrary search strings and large direct pages are never written to disk.
        $cacheable = $search === '' && $bounds[1] === 10 && $bounds[0] <= 1000 && $bounds[0] % 10 === 0;
        $key = 'online_page_v2_' . $realmId . '_' . (int) $hideGms . '_' . $bounds[0] . '_' . getLang();
        $cached = $cacheable && !$refresh ? $this->cache->get($key) : false;
        if (is_array($cached) && isset($cached['data'], $cached['updated_at'])) {
            $cached['draw'] = $draw; echo json_encode($cached, JSON_INVALID_UTF8_SUBSTITUTE); return;
        }
        try {
            $excluded = $hideGms ? $this->online_model->excludedAccounts($realmId) : [];
            $characters = $realm->getCharacters();
            $total = $characters->getOnlinePlayerCount('', $excluded);
            $filtered = $search === '' ? $total : $characters->getOnlinePlayerCount($search, $excluded);
            $rows = $bounds[0] < $filtered ? $characters->getOnlinePlayerPage($bounds[0], $bounds[1], $search, $excluded) : [];
            foreach ($rows as &$row) {
                $row['zone'] = $this->realms->getZone($row['zone']);
                $row['race_name'] = $this->realms->getRace((int) $row['race']);
                $row['class_name'] = $this->realms->getClass((int) $row['class']);
            }
            unset($row);
            $response = ['draw' => $draw, 'status' => $total ? 'ok' : 'empty', 'recordsTotal' => $total, 'recordsFiltered' => $filtered,
                'data' => $rows, 'updated_at' => time()];
            if ($cacheable) $this->cache->save($key, $response, $ttl);
            echo json_encode($response, JSON_INVALID_UTF8_SUBSTITUTE);
        } catch (Throwable $error) {
            log_message('error', 'Unable to load online player page for realm ' . $realmId);
            \App\Config\Services::response()->setStatusCode(503);
            echo json_encode(['draw' => $draw, 'status' => 'error', 'recordsTotal' => 0, 'recordsFiltered' => 0, 'data' => []]);
        }
    }
}
