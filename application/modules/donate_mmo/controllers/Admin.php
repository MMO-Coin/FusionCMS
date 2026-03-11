<?php

use MX\MX_Controller;

class Admin extends MX_Controller
{
    public function __construct()
    {
        parent::__construct();

        // Make sure only admins can access
        $this->load->library('administrator');
        requirePermission("view");

        $this->load->model('mmocoin_model');
    }

    public function index()
    {
        $this->administrator->setTitle("MMOCoin Donations");

        $data = [
            'url' => $this->template->page_url,
            'packages' => $this->mmocoin_model->getPackages()
        ];

        $output = $this->template->loadPage("admin.tpl", $data);

        $content = $this->administrator->box('MMOCoin Donations', $output);

        $this->administrator->view($content, false, "modules/donate_mmo/js/admin.js");
    }

    public function add()
    {
        requirePermission("addDonationPackages");

        $price = $this->input->post('price');
        $points = $this->input->post('points');

        if(empty($price) || empty($points)) {
            die('Fields cannot be empty.');
        }

        $this->mmocoin_model->addPackage($price, $points);

        die('SUCCESS');
    }

    public function edit()
    {
        requirePermission("editDonationPackages");

        $id = $this->input->post('id');
        $price = $this->input->post('price');
        $points = $this->input->post('points');

        if(empty($price) || empty($points) || empty($id)) {
             die('Fields cannot be empty.');
        }

        $this->mmocoin_model->updatePackage($id, $price, $points);

        die('SUCCESS');
    }

    public function delete($id)
    {
        requirePermission("deleteDonationPackages");

        if(empty($id)) {
            die();
        }

        $this->mmocoin_model->deletePackage($id);

        die('SUCCESS');
    }
}
