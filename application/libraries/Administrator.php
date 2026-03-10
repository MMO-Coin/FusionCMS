<?php

use App\Config\Services;
use CodeIgniter\Session\Session;

if (!defined('BASEPATH')) {
    exit('No direct script access allowed');
}

/**
 * @package FusionCMS
 * @author  Jesper Lindström
 * @author  Xavier Geerinck
 * @author  Elliott Robbins
 * @author  Keramat Jokar (Nightprince) <https://github.com/Nightprince>
 * @author  Ehsan Zare (Darksider) <darksider.legend@gmail.com>
 * @author  MMO-Coin <https://github.com/MMO-Coin/FusionCMS>
 * @link    https://github.com/FusionWowCMS/FusionCMS
 */
/**
 * @property-read \Smartyengine $smarty
 * @property-read \User $user
 * @property-read \MX\MX_Router $router
 * @property-read \Cms_model $cms_model
 * @property-read \Acl_model $acl_model
 * @property-read \CI_Input $input
 * @property-read \Template $template
 * @property-read \Language $language
 * @property-read \CI_Config $config
 */
class Administrator
{
    protected $CI;
    protected $modules;
    private string $theme_path;
    private array $menu;
    private string $title;
    private string $currentPage;

    /**
     * Define our paths and objects
     */
    public function __construct()
    {
        $this->CI = &get_instance();
        $this->theme_path = "application/themes/admin/";
        $this->menu = [];
        $this->title = '';
        $this->currentPage = '';

        if (!hasPermission('view', 'gm')) {
            show_404('admin', false);
        }

        $this->showLogIn();

        if (!$this->input->is_ajax_request() && !$this->input->get('is_json_ajax')) {
            $this->loadModules();
            $this->getMenuLinks();
        }
    }

    public function __get($variable)
    {
        return $this->CI->$variable;
    }

    /**
     * Handle admin log ins
     */
    private function logIn()
    {
        $security_code = $this->input->post('security_code');

        // Make sure the user has permission to view the admin panel
        if (!hasPermission("view", "admin")) {
            die("permission");
        }

        if ($security_code == $this->config->item('security_code')) {
            Services::session()->set(['admin_access' => true]);

            die("welcome");
        } else {
            die("key");
        }
    }

    /**
     * Add an extra page title
     *
     * @param string $title
     */
    public function setTitle(string $title): void
    {
        $this->title = $title . " - ";
    }

    /**
     * Get the modules and their manifests as an array
     *
     * @return mixed
     */
    public function getModules(): mixed
    {
        $this->loadModules();

        return $this->modules;
    }


    /**
     * Load and read all modules manifest
     */
    public function loadModules(): void
    {
        if (empty($this->modules)) {
            foreach (glob("application/modules/*") as $file) {
                if (is_dir($file)) {
                    $name = $this->getModuleName($file);

                    $this->modules[$name] = @file_get_contents($file . "/manifest.json");

                    if (!$this->modules[$name]) {
                        die("The module <b>" . $name . "</b> is missing manifest.json");
                    } else {
                        $manifestData = json_decode($this->modules[$name], true);

                        if (is_array($manifestData)) {
                            // Add the module folder name as name if none was specified
                            if (!array_key_exists("name", $manifestData)) {
                                $manifestData['name'] = $name;
                            }

                            // Add the enabled disabled setting, DEFAULT: disabled
                            if (!array_key_exists("enabled", $manifestData)) {
                                $manifestData["enabled"] = false;
                            }

                            // Add default description if none was specified
                            if (!array_key_exists("description", $manifestData)) {
                                $manifestData['description'] = "This module has no description";
                            }

                            // Check if the module has any configs
                            if ($this->hasConfigs($name)) {
                                $manifestData['has_configs'] = true;
                            } else {
                                $manifestData['has_configs'] = false;
                            }

                            $this->modules[$name] = $manifestData;
                        }
                    }
                }
            }
        }
    }

    /**
     * Get the module name out of the path
     *
     * @param string $path
     * @return string
     */
    private function getModuleName(string $path = ""): string
    {
        return preg_replace("/application\/modules\//", "", $path);
    }

    /**
     * Check if the module has any configs
     *
     * @param string $moduleName
     * @return bool
     */
    public function hasConfigs(string $moduleName): bool
    {
        if (file_exists("application/modules/" . $moduleName . "/config")) {
            return true;
        } else {
            return false;
        }
    }

    /**
     * Get the menu of tools
     *
     * @return void
     */
    private function getMenuLinks(): void
    {
        // Loop through all modules that have manifests
        foreach ($this->modules as $module => $manifest) {
            if (empty($manifest['enabled']) || empty($manifest['admin'])) {
                continue;
            }

            $adminManifests = isset($manifest['admin']['group']) ? array($manifest['admin']) : $manifest['admin'];

            foreach ($adminManifests as $menuGroup) {
                if (is_array($menuGroup) && isset($menuGroup['text']) && isset($menuGroup['icon'])) {
                    $text = $menuGroup['text'];
                    $icon = $menuGroup['icon'];

                    if (!isset($this->menu[$text])) {
                        $this->menu[$text] = ['links' => [], 'icon' => $icon];
                    }

                    $currentPageSet = false;

                    $currentModule = $this->router->module;
                    $currentClass = $this->router->class;
                    $currentMethod = $this->router->method;

                    if (isset($menuGroup['links']) && is_array($menuGroup['links'])) {
                        foreach ($menuGroup['links'] as $link) {
                            if (!empty($link['requirePermission']) && !hasPermission($link['requirePermission'], $module)) {
                                continue;
                            }

                            $linkModule = $link['module'] = $module;

                            if ($currentModule == $linkModule) {
                                $url = $currentClass . ($currentMethod != "index" ? "/" . $currentMethod : "");

                                if ($url == $link['controller']) {
                                    $link['active'] = true;
                                    $this->currentPage = "$module/" . $link['controller'];
                                    $currentPageSet = true;
                                }
                            }

                            $this->menu[$text]['links'][] = $link;
                        }
                    }

                    if (!$currentPageSet && $currentModule == "admin") {
                        $this->currentPage = $currentClass;
                    }
                }
            }
        }
    }

    /**
     * Loads the template
     *
     * @param string $content The page content
     * @param bool|string $css Full path to your css file
     * @param bool|string $js Full path to your js file
     */
    public function view(string $content, bool|string $css = false, bool|string $js = false)
    {
        if ($this->input->is_ajax_request() && $this->input->get('is_json_ajax') == 1) {
            $array = [
                "title" => ($this->title) ? $this->title : "",
                "content" => $content,
                "js" => $js,
                "css" => $css
            ];

            return json_encode($array);
        }

        $menu = $this->menu;
        if ($menu) {
            $menui = 1;
            array_walk($menu, function (&$value) use (&$menui) {
                $value['nr'] = $menui++;
                foreach ($value['links'] as $lvalue) {
                    if (isset($lvalue['active'])) {
                        $value['active'] = true;
                        break;
                    }
                }
            });
        }


        $notifications = $this->cms_model->getNotifications($this->user->getId(), true);

        // Gather the theme data
        $data = [
            "page" => '<div id="content_ajax">' . $content . '</div>',
            "url" => $this->template->page_url,
            "menu" => $menu,
            "title" => $this->title,
            "extra_js" => $js,
            "extra_css" => $css,
            "nickname" => $this->user->getNickname(),
            "current_page" => $this->currentPage,
            "defaultLanguage" => $this->config->item('language'),
            "client_language" => $this->CI->language->getClientData(),
            "languages" => $this->CI->language->getAllLanguages(),
            "abbreviationLanguage" => $this->CI->language->getAbbreviationByLanguage($this->CI->language->getLanguage()),
            "serverName" => $this->config->item('server_name'),
            "avatar" => $this->user->getAvatar($this->user->getId()),
            "groups" => $this->acl_model->getGroupsByUser(),
            "notifications" => $notifications,
            "cdn_link" => $this->config->item('cdn') === true ? $this->config->item('cdn_link') : null
        ];

        // Load the main template
        $output = $this->CI->smarty->view($this->theme_path . 'template.tpl', $data, true);

        return $this->CI->output->set_output($output);
    }

    /**
     * Shorthand for loading a content box
     *
     * @param string $title
     * @param string $body
     * @param bool $full
     * @param bool|string $css
     * @param bool|string $js
     * @return string
     */
    public function box(string $title, string $body, bool $full = false, bool|string $css = false, bool|string $js = false)
    {
        $data = [
            'headline' => $title,
            'content' => $body
        ];

        $page = $this->CI->smarty->view($this->theme_path . 'box.tpl', $data, true);

        if ($full) {
            return $this->view($page, $css, $js);
        } else {
            return $page;
        }
    }

    /**
     * Get the FusionCMS version
     *
     * @return string
     */
    public function getVersion(): string
    {
        return $this->config->item('FusionCMSVersion');
    }

    /**
     * Get if the module is enabled or not
     *
     * @param $moduleName
     * @return bool
     */
    public function isEnabled($moduleName): bool
    {
        return (bool) $this->modules[$moduleName]["enabled"];
    }

    public function getEnabledModules(): array
    {
        $enabled = [];

        foreach ($this->getModules() as $name => $manifest) {
            if ($manifest['enabled']) {
                $enabled[$name] = $manifest;
            }
        }

        return $enabled;
    }

    public function getDisabledModules(): array
    {
        $disabled = [];

        foreach ($this->getModules() as $name => $manifest) {
            if (!array_key_exists("enabled", $manifest) || !$manifest['enabled']) {
                $disabled[$name] = $manifest;
            }
        }

        return $disabled;
    }

    /**
     * Make sure only admins and owners can access
     */
    private function showLogIn()
    {
        if (!Services::session()->get('admin_access') || !hasPermission("view", "admin")) {
            if ($this->input->post('send')) {
                $this->logIn();
            } else {
                if (!$this->input->is_ajax_request() && !$this->input->get('is_json_ajax')) {
                    $data = [
                        "url" => $this->template->page_url,
                        "isOnline" => $this->user->isOnline(),
                        "username" => $this->user->getUsername(),
                        "avatar" => $this->user->getAvatar($this->user->getId()),
                        "cdn_link" => $this->config->item('cdn') === true ? $this->config->item('cdn_link') : null
                    ];

                    $output = $this->CI->smarty->view($this->theme_path . "login.tpl", $data, true);

                    die($output);
                } else {
                    die('<script>window.location.reload(true);</script>');
                }
            }
        }
    }
}
