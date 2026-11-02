<?php

use CodeIgniter\Events\Events;
use MX\MX_Controller;

class Admin_orders extends MX_Controller
{
    public function __construct()
    {
        $this->load->library('administrator');
        $this->load->model('store_model');
        parent::__construct();
        requirePermission('canViewOrders');
        require_once APPPATH . 'libraries/ListNames.php';
    }

    public function index()
    {
        $status = $this->input->get('status') === 'successful' ? 'successful' : 'failed';
        $search = $this->input->get('account');
        $search = is_string($search) ? mb_substr(trim($search), 0, 32) : '';
        $userId = $search !== '' ? (int) $this->user->getId($search) : 0;
        $completed = $status === 'successful' ? 1 : 0;
        $total = $search !== '' && !$userId ? 0 : $this->store_model->countOrders($completed, $userId);
        $offset = PageBounds::clamp($this->input->get('offset'), $total, 25);
        $rows = $total ? $this->store_model->getOrders($completed, $offset, 25, $userId) : [];
        $rows = $this->details($rows);
        $base = $this->template->page_url . 'store/admin_orders?' . http_build_query(['status' => $status, 'account' => $search]);
        $data = ['url' => $this->template->page_url, 'results' => $rows, 'status' => $status, 'search' => $search, 'total' => $total,
            'previous' => $offset > 0 ? $base . '&offset=' . max(0, $offset - 25) : '',
            'next' => $offset + count($rows) < $total ? $base . '&offset=' . ($offset + 25) : ''];
        foreach (['refund_failed', 'refund_done', 'refund_question', 'refund'] as $key) clientLang($key, 'store');
        $this->administrator->setTitle(lang('orders', 'store'));
        $this->administrator->view($this->administrator->box(lang('orders', 'store'), $this->template->loadPage('admin_orders.tpl', $data)), false, 'modules/store/js/admin_orders.js');
    }

    private function details(array $rows): array
    {
        $itemIds = [];
        foreach ($rows as &$row) {
            $cart = json_decode($row['cart'], true);
            $row['json'] = is_array($cart) ? array_values(array_filter($cart, 'is_array')) : [];
            foreach ($row['json'] as &$entry) {
                $entry['id'] = filter_var($entry['id'] ?? 0, FILTER_VALIDATE_INT) ?: 0;
                $entry['character'] = filter_var($entry['character'] ?? 0, FILTER_VALIDATE_INT) ?: 0;
            }
            unset($entry);
            foreach ($row['json'] as $entry) $itemIds[] = (int) ($entry['id'] ?? 0);
        }
        unset($row);
        $items = $this->store_model->getItemsByIds($itemIds);
        $accounts = ListNames::accounts($this->external_account_model->getConnection(), array_column($rows, 'user_id'));
        $byRealm = [];
        foreach ($rows as $row) foreach ($row['json'] as $entry) {
            $item = $items[$entry['id'] ?? 0] ?? null;
            if ($item && !empty($entry['character'])) $byRealm[$item['realm']][] = (int) $entry['character'];
        }
        $characters = ListNames::characters($this->realms, $byRealm);
        foreach ($rows as &$row) {
            $row['username'] = $accounts[$row['user_id']] ?? lang('unknown', 'store');
            foreach ($row['json'] as &$entry) {
                $item = $items[$entry['id'] ?? 0] ?? null;
                $entry['itemName'] = $item['name'] ?? lang('unknown', 'store');
                $entry['characterName'] = $item ? ($characters[$item['realm']][$entry['character'] ?? 0] ?? lang('unknown', 'store')) : lang('unknown', 'store');
            }
            unset($entry);
        }
        return $rows;
    }

    public function search($type = 'failed')
    {
        $search = $this->input->post('string');
        redirect($this->template->page_url . 'store/admin_orders?' . http_build_query(['status' => $type === 'successful' ? 'successful' : 'failed', 'account' => is_string($search) ? $search : '']));
    }

    public function refund($id = false)
    {
        requirePermission('canRefundOrders');
        header('Content-Type: application/json; charset=utf-8');
        if ($this->input->method() !== 'post') { http_response_code(405); header('Allow: POST'); die(json_encode(['ok' => false])); }
        $id = filter_var($id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if (!$id) { http_response_code(400); die(json_encode(['ok' => false])); }
        try { $order = $this->store_model->refundOrder($id); }
        catch (Throwable $error) { log_message('error', 'Store refund failed for order ' . $id); http_response_code(503); die(json_encode(['ok' => false])); }
        if (!$order) { http_response_code(409); die(json_encode(['ok' => false])); }
        try {
            $this->dblogger->createLog('admin', 'refund', 'Refunded order', ['ID' => $id, 'User' => $order['user_id'], 'VP' => $order['vp_cost'], 'DP' => $order['dp_cost']]);
            Events::trigger('onRefundStore', $id, $order['user_id'], $order['vp_cost'], $order['dp_cost']);
        } catch (Throwable $error) { log_message('error', 'Store refund notification failed for order ' . $id); }
        die(json_encode(['ok' => true, 'id' => $id]));
    }
}
