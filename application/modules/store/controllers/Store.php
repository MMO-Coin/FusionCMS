<?php

use MX\MX_Controller;

class Store extends MX_Controller
{
    public function __construct()
    {
        parent::__construct();
        $this->output->set_header('Cache-Control: no-store, no-cache, must-revalidate');
        $this->output->set_header('Pragma: no-cache');
        $this->user->userArea();
        $this->load->model('store_model');
        $this->load->config('store');
        requirePermission('view');
    }

    public function index()
    {
        $this->template->setTitle(lang('item_store', 'store'));
        foreach (['cant_afford','hide','show','loading','want_to_buy','yes','checkout','vp','dp','catalogue_failed','catalogue_more_failed','catalogue_all_groups','catalogue_ungrouped','catalogue_missing_group','catalogue_loading','catalogue_empty','catalogue_progress'] as $key) clientLang($key, 'store');
        $realms = [];
        foreach ($this->realms->getRealms() as $realm) $realms[] = ['id' => $realm->getId(), 'name' => $realm->getName()];
        $realmId = $realms[0]['id'] ?? 0;
        $filters = ['group' => 'all', 'quality' => 'ALL', 'currency' => 'all', 'name' => '', 'sort' => 'standard'];
        $total = $realmId ? $this->store_model->countCatalogueItems($realmId, $filters) : 0;
        $items = $total ? $this->store_model->getCatalogueItems($realmId, $filters) : [];
        $data = ['url' => $this->template->page_url, 'vp' => $this->user->getVp(), 'dp' => $this->user->getDp(), 'realms' => $realms,
            'realmId' => $realmId, 'groups' => $realmId ? $this->groups($realmId) : [], 'total' => $total, 'loaded' => count($items),
            'items_html' => $this->itemPage($items, $realmId), 'next' => count($items) < $total ? count($items) : null];
        $content = $this->template->loadPage('store.tpl', $data);
        $page = $this->template->loadPage('page.tpl', ['module' => 'default', 'headline' => breadcrumb(['ucp' => lang('ucp'), 'store' => lang('item_store', 'store')]), 'content' => $content]);
        $this->template->view($page, 'modules/store/css/store.css', 'modules/store/js/store.js');
    }

    private function groups(int $realm): array
    {
        $groups = [];
        foreach ($this->store_model->getCatalogueGroups($realm) as $group) {
            $id = (int) ($group['id'] ?? 0);
            $groups[$id] = ['id' => $id, 'title' => $id ? $group['title'] : lang('catalogue_ungrouped', 'store')];
        }
        return array_values($groups);
    }

    private function itemPage(array $items, int $realm): string
    {
        foreach ($items as &$item) $item['prices'] = ['vp' => (int) $item['vp_price'], 'dp' => (int) $item['dp_price']];
        unset($item);
        return $this->template->loadPage('catalogue_items.tpl', ['items' => $items,
            'realmId' => $realm, 'realmName' => $realm && $this->realms->realmExists($realm) ? $this->realms->getRealm($realm)->getName() : '']);
    }

    public function catalogue()
    {
        header('Content-Type: application/json; charset=utf-8');
        $realm = filter_var($this->input->post('realm'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $bounds = PageBounds::bounds($this->input->post('offset') ?? 0, $this->input->post('limit') ?? 25);
        $filters = ['group' => 'all', 'quality' => 'ALL', 'currency' => 'all', 'name' => '', 'sort' => 'standard'];
        foreach ($filters as $key => $default) $filters[$key] = $this->input->post($key, false) ?? $default;
        $valid = $this->input->is_ajax_request() && $realm && $bounds && $this->realms->realmExists($realm)
            && is_string($filters['group']) && ($filters['group'] === 'all' || filter_var($filters['group'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]) !== false)
            && is_string($filters['quality']) && in_array($filters['quality'], ['ALL','0','1','2','3','4','5','6','7'], true)
            && in_array($filters['currency'], ['all','vp','dp'], true) && is_string($filters['name']) && mb_strlen($filters['name']) <= 100
            && in_array($filters['sort'], ['standard','name','priceVp','priceDp','quality'], true);
        if (!$valid) { http_response_code(400); die(json_encode(['error' => lang('catalogue_failed', 'store')])); }
        $total = $this->store_model->countCatalogueItems($realm, $filters);
        $items = $bounds[0] < $total ? $this->store_model->getCatalogueItems($realm, $filters, $bounds[0], $bounds[1]) : [];
        $next = $bounds[0] + count($items);
        die(json_encode(['html' => $this->itemPage($items, $realm), 'groups' => $this->groups($realm), 'total' => $total,
            'offset' => $bounds[0], 'next' => $next < $total && $items ? $next : null], JSON_INVALID_UTF8_SUBSTITUTE));
    }
}
