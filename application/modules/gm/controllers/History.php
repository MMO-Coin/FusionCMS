<?php

use MX\MX_Controller;

class History extends MX_Controller
{
	public function __construct()
	{
		parent::__construct();

		requirePermission("view");
	}

	public function index(): void
    {
        require_once APPPATH . 'libraries/PageBounds.php';
        $type = $this->input->get('type'); $type = is_string($type) ? mb_substr($type, 0, 32) : '';
        $search = $this->input->get('account'); $search = is_string($search) ? mb_substr(trim($search), 0, 32) : '';
        $gmId = $search !== '' ? (int) $this->user->getId($search) : 0;
        $total = $search !== '' && !$gmId ? 0 : $this->logger_model->countGMLogsDb($type, $gmId);
        $offset = PageBounds::clamp($this->input->get('offset'), $total, 25);
        $gmLogs = $total ? $this->dblogger->getGMLogs($type, $offset, 25, $gmId) : [];
        $base = pageURL . 'gm/history?' . http_build_query(['type' => $type, 'account' => $search]);

        $data = array(
            'url' => pageURL,
            'link_active' => 'history',
            'gmLogs' => $gmLogs, 'total' => $total, 'search' => $search, 'filter_type' => $type,
            'previous' => $offset > 0 ? $base . '&offset=' . max(0, $offset - 25) : '',
            'next' => $offset + count($gmLogs) < $total ? $base . '&offset=' . ($offset + 25) : '',
        );

        $content = $this->template->loadPage('history.tpl', $data);

        $output = $this->template->box(breadcrumb([
            "gm" => lang("gm_panel", "gm"),
            "gm/history" => lang("history_list", "gm")
        ]), $content);

        $this->template->view($output, "modules/gm/css/gm.css", "modules/gm/js/gm.js");
	}
}