<?php

use MX\MX_Controller;

require_once __DIR__ . '/../libraries/SolanaPaymentVerifier.php';

class Admin extends MX_Controller
{
    public function __construct()
    {
        parent::__construct();

        // Make sure only admins can access
        $this->load->library('administrator');
        requirePermission('viewAdmin');
        header('X-CSRF-Token: ' . $this->security->get_csrf_hash());

        $this->load->model('mmocoin_model');
    }

    public function index()
    {
        $this->administrator->setTitle("MMOCoin Donations");

        $data = [
            'url' => $this->template->page_url,
            'packages' => $this->mmocoin_model->getPackages(),
            'csrf_name' => $this->security->get_csrf_token_name(),
            'csrf_hash' => $this->security->get_csrf_hash(),
        ];

        $output = $this->template->loadPage("admin.tpl", $data);

        $content = $this->administrator->box('MMOCoin Donations', $output);

        $this->administrator->view($content, false, "modules/donate_mmo/js/admin.js");
    }

    public function add()
    {
        requirePermission("addDonationPackages");

        [$price, $points] = $this->packageValues();

        $this->mmocoin_model->addPackage($price, $points);

        die('SUCCESS');
    }

    public function edit()
    {
        requirePermission("editDonationPackages");

        [$price, $points] = $this->packageValues();
        $id = filter_var($this->input->post('id'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if (!$id) die('Invalid package.');

        $this->mmocoin_model->updatePackage($id, $price, $points);

        die('SUCCESS');
    }

    public function delete($id = null)
    {
        requirePermission("deleteDonationPackages");

        if (strtoupper($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') die('Use POST to delete a package.');
        $id = filter_var($id ?? $this->input->post('id'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if (!$id) die('Invalid package.');

        $this->mmocoin_model->deletePackage($id);

        die('SUCCESS');
    }

    private function packageValues(): array
    {
        if (strtoupper($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') die('Use POST to save a package.');
        try {
            $price = SolanaPaymentVerifier::amount((string) $this->input->post('price'));
        } catch (InvalidArgumentException $e) {
            die($e->getMessage());
        }
        $points = filter_var($this->input->post('points'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 2147483647]]);
        if (!$points) die('Donation points must be a positive whole number.');
        return [$price, $points];
    }
}
