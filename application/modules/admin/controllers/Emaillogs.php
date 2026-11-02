<?php

use MX\MX_Controller;

/**
 * Emaillogs Controller Class
 * @property dashboard_model $dashboard_model dashboard_model Class
 */
class Emaillogs extends MX_Controller
{
    public function __construct()
    {
        parent::__construct();

        $this->load->model('dashboard_model');

        $this->load->library('administrator');
    }

    public function index()
    {
        $search = $this->input->get('search');
        $search = is_string($search) ? mb_substr(trim($search), 0, 255) : '';
        $total = $this->dashboard_model->countEmailLogs($search);
        $pageCount = max(1, (int) ceil($total / 25));
        $page = filter_var($this->input->get('page'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $page = min($page ?: 1, $pageCount);
        $emaillogs = $this->dashboard_model->getEmailLogs(($page - 1) * 25, 25, $search);
        $pageUrl = base_url('admin/emaillogs') . '?search=' . rawurlencode($search) . '&page=';

        $data = array(
            'emaillogs' => $emaillogs,
            'search' => $search,
            'page' => $page,
            'page_count' => $pageCount,
            'total' => $total,
            'previous_url' => $page > 1 ? $pageUrl . ($page - 1) : '',
            'next_url' => $page < $pageCount ? $pageUrl . ($page + 1) : ''
        );

        $output = $this->template->loadPage("emaillogs.tpl", $data);

        $content = $this->administrator->box(lang('admin_email_logs', 'admin'), $output);

        $this->administrator->view($content, false, false);
    }
}
