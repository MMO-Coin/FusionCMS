<?php

use App\Config\Services;
use CodeIgniter\Events\Events;
use MX\MX_Controller;

/**
 * Settings Controller Class
 * @property settings_model $settings_model settings_model Class
 */
class Settings extends MX_Controller
{
    public function __construct()
    {
        //Call the constructor of MX_Controller
        parent::__construct();

        $this->load->config('settings');
        $this->load->config('links');

        //Make sure that we are logged in
        $this->user->userArea();

        $this->load->library('form_validation');

        $this->load->helper('cookie');
    }

    public function index()
    {
        requirePermission("canUpdateAccountSettings");

        clientLang("nickname_error", "ucp");
        clientLang("location_error", "ucp");
        clientLang("pw_doesnt_match", "ucp");
        clientLang("changes_saved", "ucp");
        clientLang("invalid_pw", "ucp");
        clientLang("nickname_taken", "ucp");
        clientLang("invalid_language", "ucp");
        foreach (['settings_saving', 'settings_failed', 'settings_reload'] as $key) clientLang($key, 'ucp');

        $this->template->setTitle(lang("settings", "ucp"));

        $settings_data = [
            'nickname' => html_entity_decode($this->user->getNickname(), ENT_QUOTES | ENT_HTML5, 'UTF-8'),
            'location' => html_entity_decode($this->internal_user_model->getLocation(), ENT_QUOTES | ENT_HTML5, 'UTF-8'),
            'show_language_chooser' => $this->config->item('show_language_chooser'),
            'userLanguage' => $this->language->getLanguage(),
            "avatar" => $this->user->getAvatar($this->user->getId()),

            "config" => [
                "vote" => $this->config->item('ucp_vote'),
                "donate" => $this->config->item('ucp_donate'),
                "store" => $this->config->item('ucp_store'),
                "settings" => $this->config->item('ucp_settings'),
                "security" => $this->config->item('ucp_security'),
                "teleport" => $this->config->item('ucp_teleport'),
                "admin" => $this->config->item('ucp_admin'),
                "gm" => $this->config->item('ucp_mod')
            ]
        ];

        if ($this->config->item('show_language_chooser')) {
            $settings_data['languages'] = $this->language->getAllLanguages();
        }

        $data = [
            "module" => "default",
            "headline" => breadcrumb([
                            "ucp" => lang("ucp"),
                            "ucp/settings" => lang("settings", "ucp")
            ]),
            "content" => $this->template->loadPage("settings.tpl", $settings_data)
        ];

        $page = $this->template->loadPage("page.tpl", $data);

        //Load the template form
        $this->template->view($page, "modules/ucp/css/ucp.css", "modules/ucp/js/settings.js");
    }

    public function submit()
    {
        requirePermission('canUpdateAccountSettings');
        header('Content-Type: application/json; charset=utf-8');
        if ($this->input->method() !== 'post') {
            show_error('Invalid request', 403);
        }

        $this->form_validation->set_rules('old_password', lang("old_password", "ucp"), 'trim|required|min_length[6]|max_length[16]');
        $this->form_validation->set_rules('new_password', lang("new_password", "ucp"), 'trim|required|min_length[6]|max_length[16]');

        if ($this->form_validation->run() === false) {
            $response = [
                'status'  => 'error',
                'message' => trim(strip_tags(validation_errors())),
                'errors' => array_map('strip_tags', $this->form_validation->error_array())
            ];
            die(json_encode($response));
        }

        $oldPassword = $this->input->post('old_password');
        $newPassword = $this->input->post('new_password');

        // Get the current password
        $currentPassword = $this->user->getPassword();

        // Generate the verifier from the entered old password
        $passwordHash = $this->user->getAccountPassword($this->user->getUsername(), $oldPassword);

        // Check if passwords match
        if (strtoupper($currentPassword) === strtoupper($passwordHash["verifier"])) {
            $this->user->setPassword($newPassword);

            // Revoke active sessions and remembered logins for every device.
            $this->account_session_model->invalidate($this->user->getId());

            delete_cookie("fcms_username");
            delete_cookie("fcms_token");
            delete_cookie("fcms_password");

            Services::session()->destroy();

            $response = [
                'status'  => 'success',
                'message' => lang("changes_saved", "ucp")
            ];
        } else {
            $response = [
                'status'  => 'error',
                'message' => lang("invalid_pw", "ucp"),
                'errors' => ['old_password' => lang('invalid_pw', 'ucp')]
            ];
        }

        die(json_encode($response));
    }

    private function profileResponse(string $status, string $message, array $errors = [], bool $reload = false): void
    {
        header('Content-Type: application/json; charset=utf-8');
        die(json_encode(['status' => $status, 'message' => $message, 'errors' => $errors, 'reload' => $reload], JSON_INVALID_UTF8_SUBSTITUTE));
    }

    public function submitInfo()
    {
        requirePermission('canUpdateAccountSettings');
        if ($this->input->method() !== 'post') { http_response_code(405); header('Allow: POST'); $this->profileResponse('error', lang('settings_failed', 'ucp')); }
        $this->load->model('settings_model');
        $nickname = $this->input->post('nickname', false);
        $location = $this->input->post('location', false);
        $errors = [];
        if (!is_string($nickname) || !is_string($location)) $this->profileResponse('error', lang('settings_failed', 'ucp'));
        $nickname = trim($nickname); $location = trim($location);
        $currentNickname = html_entity_decode($this->user->getNickname(), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        if ($nickname !== $currentNickname) {
            if (!preg_match('/^[A-Za-z0-9]{4,14}$/D', $nickname)) $errors['nickname_field'] = lang('nickname_error', 'ucp');
            elseif ($this->internal_user_model->nicknameExists($nickname)) $errors['nickname_field'] = lang('nickname_taken', 'ucp');
        }
        if (mb_strlen($location) > 32) $errors['location_field'] = lang('location_error', 'ucp');
        $language = null;
        if ($this->config->item('show_language_chooser')) {
            $language = $this->input->post('language', false);
            if (!is_string($language) || !in_array($language, $this->language->getAllLanguages(), true)) $errors['language_field'] = lang('invalid_language', 'ucp');
        }
        if ($errors) $this->profileResponse('error', reset($errors), $errors);
        $values = ['location' => $this->template->format($location)];
        if ($nickname !== $currentNickname) $values['nickname'] = $nickname;
        if ($language !== null) $values['language'] = $language;
        try {
            if (!$this->settings_model->saveSettings($values)) throw new RuntimeException('Profile write failed');
        } catch (Throwable $error) {
            http_response_code(503); $this->profileResponse('error', lang('settings_failed', 'ucp'));
        }
        $reload = $language !== null && $language !== $this->language->getLanguage();
        if ($language !== null) Services::session()->set('language', $language);
        try {
            if ($reload) Events::trigger('onSetLanguage', $this->user->getId(), $language);
            Events::trigger('onSaveSettingsAccount', $this->user->getId(), $values);
        } catch (Throwable $error) { log_message('error', 'Account settings notification failed'); }
        $this->profileResponse('success', lang('changes_saved', 'ucp'), [], $reload);
    }
}
