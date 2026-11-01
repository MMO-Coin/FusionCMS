<?php

use App\Config\Services;
use MX\MX_Controller;

class Info_login extends MX_Controller
{
    public function view()
    {
        if ($this->user->isOnline()) {
            $data = [
                'module'    => 'sidebox_info_login',
                'url'       => $this->template->page_url,
                'currentIp' => $this->input->ip_address(),
                'lastIp'    => $this->user->getLastIp(),
                'vp'        => $this->user->getVp(),
                'dp'        => $this->user->getDp()
            ];

            $page = $this->template->loadPage('info.tpl', $data);
        } else {
            $this->load->helper('form');

            $data = [
                'module'       => 'sidebox_info_login',
                'url'          => $this->template->page_url,
                'use_captcha'  => $this->config->item('use_captcha'),
                'captcha_type' => $this->config->item('captcha_type'),
                'has_smtp'     => $this->config->item('has_smtp')
            ];
                
            if ($this->config->item('use_captcha') || (int)Services::session()->get('attempts') >= $this->config->item('captcha_attemps')) {
                $data['use_captcha'] = true;
            }

            $page = $this->template->loadPage('login.tpl', $data);
        }

        return $page;
    }
}
