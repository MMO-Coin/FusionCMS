<?php

use App\Config\Services;
use MX\MX_Controller;

class Security extends MX_Controller
{
    public function __construct()
    {
        parent::__construct();
        $this->user->userArea();
        requirePermission('canUpdateAccountSettings');
        $this->load->library('GoogleAuthenticator');
        $this->load->model('totp_model');
        $this->load->model('account_device_model');
    }

    public function index()
    {
        $this->template->setTitle(lang('account_security', 'ucp'));
        $enabled = $this->external_account_model->getTotpSecret() !== '';
        $secret = '';
        $qr = '';
        if ($this->config->item('totp_secret') && !$enabled) {
            $secret = $this->googleauthenticator->createSecret();
            Services::session()->set('totp_enrollment', [
                'account_id' => $this->user->getId(), 'secret' => $secret, 'expires' => time() + 600,
                'version' => Services::session()->get('auth_session_version') ?? '',
            ]);
            $qr = $this->googleauthenticator->getQRCode($this->config->item('title') . ' - ' . $this->user->getUsername(), $secret);
        }
        $rememberedToken = $this->input->cookie('fcms_token');
        $settings = ['security_enabled' => $enabled, 'secret_key' => $secret, 'qr_code' => $qr,
            'two_factor_available' => (bool) $this->config->item('totp_secret'),
            'active_sessions' => $this->account_device_model->sessions($this->user->getId(),
                Services::session()->get('auth_session_version') ?? '', (string) Services::session()->get('account_device_token')),
            'remembered_devices' => $this->account_device_model->remembered($this->user->getId(), is_string($rememberedToken) ? $rememberedToken : ''),
            'security_timezone' => date_default_timezone_get(),
        ];
        $data = [
            'module' => 'default',
            'headline' => breadcrumb(['ucp' => lang('ucp'), 'ucp/security' => lang('account_security', 'ucp')]),
            'content' => $this->template->loadPage('security.tpl', $settings),
        ];
        $this->template->view($this->template->loadPage('page.tpl', $data), 'modules/ucp/css/ucp.css', 'modules/ucp/js/security.js');
    }

    public function submit()
    {
        if ($this->input->method() !== 'post') show_error('Invalid request', 405);
        if (!$this->config->item('totp_secret')) $this->respond(false, 'Two-factor authentication is not available.', 403);
        if (!Services::throttler()->check('totp_verify_' . $this->user->getId(), 5, MINUTE)) {
            $this->respond(false, 'Too many attempts. Wait a minute before trying again.', 429);
        }
        $mode = $this->input->post('security_enabled');
        $password = $this->input->post('current_password');
        $code = $this->input->post('auth_code');
        if (!in_array($mode, ['true', 'false'], true) || !is_string($password)
            || strlen($password) < 6 || strlen($password) > 16 || !is_string($code)) {
            $this->respond(false, 'Enter your current password and a new six-digit authenticator code.');
        }
        $verifier = $this->user->getAccountPassword($this->user->getUsername(), $password);
        if (!hash_equals(strtoupper($this->external_account_model->getPassword()), strtoupper($verifier['verifier']))) {
            $this->respond(false, 'The password or authenticator code is invalid, expired or already used.');
        }
        $current = $this->external_account_model->getTotpSecret();
        $enabling = $mode === 'true';
        if ($enabling === ($current !== '')) {
            $this->respond(false, 'Reload the page. Disable the existing authenticator before setting up a replacement.');
        }
        $version = Services::session()->get('auth_session_version') ?? '';
        $replacement = null;
        if ($enabling) {
            $pending = Services::session()->get('totp_enrollment');
            if (!is_array($pending) || $pending['account_id'] !== $this->user->getId()
                || $pending['expires'] <= time() || !hash_equals($version, $pending['version'])) {
                $this->respond(false, 'Setup expired. Reload the page and scan the new QR code.');
            }
            // Only the server's pending secret is eligible for enrollment.
            $replacement = $this->config->item('totp_secret_name') === 'totp_secret'
                ? $this->googleauthenticator->createTotpAes($pending['secret']) : $pending['secret'];
        }
        $secret = $enabling ? $replacement : $current;
        $step = $this->googleauthenticator->matchingTimeStep($secret, $code);
        if ($step === null || !$this->totp_model->claim($this->user->getId(), $this->googleauthenticator->storedSecretKey($secret), $step)) {
            $this->respond(false, 'The password or authenticator code is invalid, expired or already used.');
        }
        if (!hash_equals($this->account_session_model->getVersion($this->user->getId()), $version)
            || !$this->external_account_model->changeTotpSecret($this->user->getId(), $current, $replacement, $this->external_account_model->getPassword())) {
            $this->respond(false, 'The account changed during verification. Sign in again and retry.');
        }
        $this->account_session_model->invalidate($this->user->getId());
        foreach (['fcms_username', 'fcms_token', 'fcms_password'] as $cookie) $this->input->set_cookie($cookie, '');
        Services::session()->destroy();
        $this->respond(true, $enabling
            ? 'Two-factor authentication enabled. Sign in again using a new authenticator code.'
            : 'Two-factor authentication disabled. Sign in again.');
    }

    private function respond(bool $success, string $message, int $status = 200): never
    {
        http_response_code($status);
        header('Content-Type: application/json');
        die(json_encode(['status' => $success, 'message' => $message]));
    }

    public function signOutEverywhere()
    {
        if ($this->input->method() !== 'post') show_error('Invalid request', 405);
        // Identity always comes from the authenticated session, never POST data.
        $this->account_session_model->invalidate($this->user->getId());
        foreach (['fcms_username', 'fcms_token', 'fcms_password'] as $cookie) $this->input->set_cookie($cookie, '');
        Services::session()->destroy();
        $this->respond(true, 'You have been signed out everywhere. Sign in again to continue.');
    }
}
