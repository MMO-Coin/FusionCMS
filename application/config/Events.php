<?php

namespace Config;

use App\Config\Services;
use CodeIgniter\Exceptions\FrameworkException;
use MX\CI;
use CodeIgniter\Events\Events;

/*
 * --------------------------------------------------------------------
 * Application Events
 * --------------------------------------------------------------------
 * Events allow you to tap into the execution of the program without
 * modifying or extending core files. This file provides a central
 * location to define your events, though they can always be added
 * at run-time, also, if needed.
 *
 * You create code that can execute by subscribing to events with
 * the 'on()' method. This accepts any form of callable, including
 * Closures, that will be executed when the event is triggered.
 *
 * Example:
 *      Events::on('create', [$myInstance, 'myMethod']);
 */

Events::on('pre_controller', static function () {
    // Global Security Headers
    header("X-Frame-Options: SAMEORIGIN");
    header("X-XSS-Protection: 1; mode=block");
    header("X-Content-Type-Options: nosniff");
    header("Strict-Transport-Security: max-age=31536000; includeSubDomains");
    header("Referrer-Policy: strict-origin-when-cross-origin");

    if (ENVIRONMENT !== 'testing') {
        if (ini_get('zlib.output_compression')) {
            throw FrameworkException::forEnabledZlibOutputCompression();
        }

        while (ob_get_level() > 0) {
            ob_end_flush();
        }

        ob_start(static fn($buffer) => $buffer);
    }

    /*
     * --------------------------------------------------------------------
     * Debug Toolbar Listeners.
     * --------------------------------------------------------------------
     * If you delete, they will no longer be collected.
     */
    if (CI_DEBUG && !is_cli()) {
        Events::on('DBQuery', 'CodeIgniter\Debug\Toolbar\Collectors\Database::collect');
        Services::toolbar()->respond();
    }

    /*
     * --------------------------------------------------------------------
     * FusionCMS Listeners.
     * --------------------------------------------------------------------
     * Validate module and minimum required version. | Handle cookie login.
     */

    // SKIP! Template class not found!
    if (!isset(CI::$APP->template))
        return;

    // Module: Initialize
    $module = [
        'name' => CI::$APP->template->getModuleName(),
        'data' => CI::$APP->template->getModuleData()
    ];

    // Module: Disabled
    if (!isset($module['data']['enabled']) || (isset($module['data']['enabled']) && !$module['data']['enabled']))
        show_404($module['name'], false);

    // Module: Patch | Set min_required_version
    if (!isset($module['data']['min_required_version']))
        $module['data']['min_required_version'] = CI::$APP->config->item('FusionCMSVersion');

    // Module: Requires higher FusionCMS version
    if (version_compare($module['data']['min_required_version'], CI::$APP->config->item('FusionCMSVersion'), '>'))
        show_error(str_replace(['$1', '$2', '$3'], [$module['name'], $module['data']['min_required_version'], 'https://github.com/FusionWowCMS/FusionCMS'], 'The module <b>$1</b> requires FusionCMS v$2, Please update at $3'));

    // Events files: Find modules Events files
    if ($eventFiles = glob(rtrim(str_replace(['\\', '/'], DIRECTORY_SEPARATOR, realpath(APPPATH)), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'modules' . DIRECTORY_SEPARATOR . '*' . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'Event.' . pathinfo(__FILE__, PATHINFO_EXTENSION))) {
        // Events files: Loop through
        foreach ($eventFiles as $eventFile) {
            require_once($eventFile);
        }
    }

    // SKIP! No need to go any further!
    if(!isset(CI::$APP->user))
        return;

    // Password-hash cookies cannot be revoked server-side. Retire that format.
    if(CI::$APP->input->cookie('fcms_password') !== null)
        CI::$APP->input->set_cookie('fcms_password', '');

    // Remember-me cookie login: only when the user is not signed in yet
    if(!CI::$APP->user->isOnline())
    {
        // Username: Read cookie
        $username = CI::$APP->input->cookie('fcms_username');

        // Token: Read the random rotating token (replaces the old password hash)
        $rawToken = CI::$APP->input->cookie('fcms_token');

        if($username !== null || $rawToken !== null)
        {
            $tokenResult = is_string($username) && $username !== '' && is_string($rawToken)
                ? CI::$APP->cms_model->validateRememberMeToken($rawToken) : false;
            $restored = false;
            if($tokenResult)
            {
                // Token valid: authenticate using the account's stored password
                $accountRow = CI::$APP->external_account_model->getInfo($tokenResult['account_id']);
                if($accountRow)
                {
                    $user = CI::$APP->user->setUserDetails($accountRow['username'], $accountRow['verifier'] ?? strtoupper($accountRow['sha_pass_hash']));

                    if($user === 0)
                    {
                        $restored = true;
                        $cookieExpiry = CI::$APP->config->item('cookie_expire');
                        CI::$APP->input->set_cookie('fcms_username', $accountRow['username'], $cookieExpiry);
                        CI::$APP->input->set_cookie('fcms_token', $tokenResult['new_token'], $cookieExpiry);
                        if(strtolower(str_replace(CI::$APP->config->item('controller_suffix') ?? '', '', (CI::$APP->router->module ?? ''))) !== 'api')
                            redirect(str_replace(base_url(), '', current_url()) ?? CI::$APP->router->default_controller);
                    }
                }
                if(!$restored)
                    CI::$APP->cms_model->deleteRememberMeTokenByToken($tokenResult['new_token']);
            }
            if(!$restored)
            {
                CI::$APP->input->set_cookie('fcms_username', '');
                CI::$APP->input->set_cookie('fcms_token', '');
            }
        }
    }

    /*
     * --------------------------------------------------------------------
     * Two-Factor Authentication (TOTP).
     * --------------------------------------------------------------------
     * Enforced in pre_controller so it runs before EVERY controller action,
     * including AJAX/JSON endpoints, and can not be bypassed by calling an
     * action method that never renders a view.
     */
    if(CI::$APP->user->isOnline())
    {
        // Module of the requested action
        $dirModule = strtolower((string)(CI::$APP->router->module ?? ''));

        // The whole "auth" module is pre-2FA plumbing (login, captcha, 2FA
        // entry/verification and logout) and must stay reachable without a code.
        if($dirModule !== 'auth')
        {
            // Account's stored TOTP secret vs. the one verified in this session
            $accountTotp = CI::$APP->external_account_model->getTotpSecret();
            $sessionTotp = CI::$APP->user->getTotpSecret();

            if($accountTotp !== '' && $sessionTotp !== $accountTotp)
            {
                // AJAX/JSON request: reject it, do not execute the action
                if(CI::$APP->input->is_ajax_request())
                {
                    http_response_code(403);
                    die(json_encode(['status' => 'error', 'message' => 'Two-factor authentication required']));
                }

                // Normal request: send the user to the 2FA verification page
                redirect(CI::$APP->template->page_url . 'auth/security');
            }
        }
    }
});
