<?php

use MX\MX_Controller;

/**
 * Logging Controller Class
 * @property logging_model $logging_model logging_model Class
 */
class Logging extends MX_Controller
{
    private int $logsToLoad = 10;

    public function __construct()
    {
        parent::__construct();
        $this->load->library('administrator');
        $this->load->model('logging_model');
        requirePermission('viewLogs');
    }

    private function formatLogs(array $logs): array
    {
        foreach ($logs as &$log) $log['custom'] = json_decode($log['custom']);
        return $logs;
    }

    public function index()
    {
        $this->administrator->setTitle(lang('admin_logs', 'admin'));
        $logs = $this->logging_model->findLogs('', '', 0, $this->logsToLoad);
        $data = [
            'logs' => $this->formatLogs($logs),
            'modules' => $this->administrator->getEnabledModules(),
            'show_more' => max(0, $this->logging_model->countLogs() - count($logs)),
        ];
        $output = $this->template->loadPage('logging/logging.tpl', $data);
        $this->administrator->view($this->administrator->box(lang('admin_website_logs', 'admin'), $output), false, 'modules/admin/js/logging.js');
    }

    public function loadMoreLogs()
    {
        $this->search();
    }

    public function search()
    {
        $module = $this->input->post('module');
        $search = $this->input->post('search');
        $module = is_string($module) ? mb_substr($module, 0, 255) : '';
        $search = is_string($search) ? mb_substr(trim($search), 0, 255) : '';
        $offset = $this->input->post('offset') ?? 0;
        $logs = $this->logging_model->findLogs($search, $module, $offset, $this->logsToLoad);
        if (!$logs) die('<span>' . lang('no_results', 'admin') . '</span>');
        die($this->template->loadPage('logging/logging_found.tpl', [
            'logs' => $this->formatLogs($logs),
            'show_more' => max(0, $this->logging_model->countLogs($search, $module) - (int) $offset - count($logs)),
        ]));
    }
}
