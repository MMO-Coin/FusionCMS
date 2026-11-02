<?php

use MX\MX_Controller;

class Check extends MX_Controller
{
    public function __construct()
    {
        parent::__construct();

        $this->user->guestArea();
        $this->load->library('form_validation');
    }

    public function username($value = false)
    {
        if ($value != false) {
            if (!$this->external_account_model->usernameExists($value)) {
                die("1");
            } else {
                die("0");
            }
        }
    }

    public function email()
    {
        $value = $this->input->post("email");

        if (!is_string($value) || !$this->form_validation->valid_email($value)) die('invalid');
        if ($value !== '') {
            if (!$this->external_account_model->emailExists($value)) {
                die("1");
            } else {
                die("0");
            }
        }
    }
}
