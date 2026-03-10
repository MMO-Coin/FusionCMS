<?php

if (!defined('BASEPATH')) {
    exit('No direct script access allowed');
}

use MX\CI;

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
 * @property-read \CI_Input $input
 * @property-read \CI_Config $config
 * @property-read \Language $language
 * @property-read \CI_Output $output
 * @property-read \External_account_model $external_account_model
 * @property-read \CI_Cache $cache
 * @method void view(string $template, string $css = null, string $js = null)
 */
class Template
{
    private $CI;
    private string $title;
    private bool|string $custom_description;
    private bool|string $custom_keywords;
    private bool|string $custom_page;
    public string $theme_path;
    public string $full_theme_path;
    public string $image_path;
    public string $writable_path;
    public ?string $theme;
    public string $page_url;
    public mixed $theme_data;
    public mixed $theme_config;
    public mixed $module_data;
    public string $style_path;
    public string $view_path;
    public $module_name;

    /**
     * Get the CI instance and create the paths
     */
    public function __construct()
    {
        $this->CI =& get_instance();

        // Get the theme name
        $this->theme = $this->config->item('theme');

        // Construct the paths
        $this->module_name = $this->router->module ?? '';
        $this->theme_path = "themes/" . $this->theme . "/";
        $this->view_path = "views/";
        $this->style_path = base_url() . basename(APPPATH) . '/' . "themes/" . $this->theme . "/css/";
        $this->image_path = base_url() . basename(APPPATH) . '/' . "themes/" . $this->theme . "/images/";
        $this->full_theme_path = base_url() . basename(APPPATH) . '/' . $this->theme_path;
        $this->writable_path = base_url() . 'writable/';
        $this->page_url = ($this->config->item('rewrite')) ? base_url() : base_url() . 'index.php/';
        $this->loadManifest();
        $this->loadModuleManifest();
        $this->title = "";
        $this->custom_page = false;
        $this->custom_keywords = false;
        $this->custom_description = false;

        if (!defined("pageURL")) {
            define("pageURL", $this->page_url);
        }
    }

    public function __get($variable)
    {
        return $this->CI->$variable;
    }

    /**
     * Loads the current theme values
     */
    private function loadManifest(): void
    {
        if (!file_exists(APPPATH . $this->theme_path)) {
            show_error("Invalid theme. The folder <b>" . APPPATH . $this->theme_path . "</b> doesn't exist!");
        } elseif (!file_exists(APPPATH . $this->theme_path . "/manifest.json")) {
            show_error("Invalid theme. The file <b>manifest.json</b> is missing!");
        }

        // Load the manifest
        $data = file_get_contents(APPPATH . $this->theme_path . "manifest.json");

        // Convert to array
        $array = json_decode($data, true);

        // Fix the favicon link
        $array['favicon'] = $this->image_path . $array['favicon'];

        if (!isset($array['blank_header'])) {
            $array['blank_header'] = '';
        }

        // Save the data
        $this->theme_data = $array;

        // Check if the theme has any configs
        if ($this->hasConfigs($this->theme)) {
            // Load the theme configs
            $this->loadConfigs();

            // Assign theme configs to smarty template
            $this->CI->smarty->assign('theme_configs', $this->theme_config ?? null);
        }
    }

    public function hasConfigs($theme): bool
    {
        if (file_exists("application/themes/" . $theme . "/config")) {
            return true;
        } else {
            return false;
        }
    }

    /**
     * Load the theme configs
     */
    private function loadConfigs(): void
    {
        foreach (glob("application/themes/" . $this->theme . "/config/*") as $file) {
            $this->getConfig($file);
        }
    }

    /**
     * Load the config into the function variable scope and assign it to the config array
     */
    private function getConfig($file): void
    {
        include($file);

        $this->theme_config[$this->getConfigName($file)] = $config;
        $this->theme_config[$this->getConfigName($file)]['source'] = $this->getConfigSource($file);
    }

    private function getConfigSource($file): false|string
    {
        $handle = fopen($file, "r");
        $data = fread($handle, filesize($file));
        fclose($handle);

        return $data;
    }

    /**
     * Get the config name out of the path
     *
     * @param string $path
     * @return string
     */
    private function getConfigName(string $path = ""): string
    {
        return preg_replace("/application\/themes\/" . $this->theme . "\/config\/([A-Za-z0-9_-]*)\.php/", "$1", $path);
    }

    /**
     * Loads the current module values
     */
    private function loadModuleManifest(): void
    {
        if (!file_exists(APPPATH . "modules/" . strtolower($this->getModuleName()))) {
            show_error("Invalid Module. The folder <b>" . APPPATH . "modules/" . strtolower($this->getModuleName()) . "</b> doesn't exist!");
        } elseif (!file_exists(APPPATH . $this->theme_path . "/manifest.json")) {
            show_error("The manifest.json file for <b>" . strtolower($this->getModuleName()) . "</b> does not exist");
        }

        // Load the manifest
        $data = file_get_contents(APPPATH . "modules/" . strtolower($this->getModuleName()) . "/manifest.json");

        // Convert to array
        $array = json_decode($data, true);

        if (!is_array($array)) {
            show_error("The manifest.json file for <b>" . strtolower($this->getModuleName()) . "</b> is not properly formatted");
        }

        // Save the data
        $this->module_data = $array;
    }

    /**
     * Returns if the slider should be shown on the current page.
     *
     * @return bool
     */
    private function isSliderShown(): bool
    {
        // Is it enabled?
        if ($this->config->item('slider')) {
            // Only on news page?, if yes, make sure we are on the news page, then show it
            if ($this->config->item('slider_home') && $this->router->class == "news") {
                return true;
            }

            // If we want to only show it on the home page, then do not show it on the other pages.
            elseif ($this->config->item('slider_home') && $this->router->class != "news") {
                return false;
            }

            return true;
        }

        return false;
    }

    /**
     * Loads the template
     *
     * @param string $content The page content
     * @param bool|string $css Full path to your css file
     * @param bool|string $js Full path to your js file
     */
    public function view(string $content, bool|string|array $css = false, bool|string|array $js = false)
    {
        if ($this->config->item("message_enabled") && ($this->router->class ?? '') != "auth" && !hasPermission("view", "gm")) {
            $output = $this->handleAnnouncement();
        } elseif ($this->input->is_ajax_request() && $this->input->get('is_json_ajax') == 1) {
            $output = $this->handleAjaxRequest($content, $css, $js);
        } else {
            $output = $this->handleNormalPage($content, $css, $js);
        }

        $dirModule = strtolower(($this->router->module ?? '') . '/' . ($this->router->method ?? ''));

        if ($this->external_account_model->getTotpSecret() !== null && ($this->user->getTotpSecret() != $this->external_account_model->getTotpSecret()) && $dirModule != 'auth/security') {
            redirect($this->CI->template->page_url . "auth/security");
        }

        return $this->output->set_output($output);
    }

    /**
     * Handles the normal loading.
     *
     * @param  $content
     * @param bool|string|array $css
     * @param bool|string|array $js
     * @return mixed
     */
    private function handleNormalPage($content, bool|string|array $css, bool|string|array $js): mixed
    {
        //Load the sideboxes
        $sideboxes = $this->loadSideboxes();
        $sideboxes_side = $sideboxes['side'] ?? [];
        $sideboxes_top = $sideboxes['top'] ?? [];
        $sideboxes_bottom = $sideboxes['bottom'] ?? [];
        $header = $this->getHeader($css, $js);
        $modals = $this->getModals();

        $url = $this->router->class ?? '';

        if (($this->router->method ?? '') != "index") {
            $url .= "/" . ($this->router->method ?? '');
        }

        // Gather the theme data
        $theme_data = [
            "currentPage" => $url,
            "url" => $this->page_url,
            "theme_path" => "application/" . $this->theme_path,
            "writable_path" => $this->writable_path,
            "full_theme_path" => $this->page_url . "application/" . $this->theme_path,
            "serverName" => $this->config->item('server_name'),
            "page" => '<div id="content_ajax">' . $content . '</div>',
            "slider" => $this->getSlider(),
            "show_slider" => $this->isSliderShown(),
            "head" => $header,
            "modals" => $modals,
            "CI" => $this->CI,
            "image_path" => $this->image_path,
            "isOnline" => $this->user->isOnline(),
            "isRTL" => $this->language->getLanguage() == 'persian' || $this->language->getClientData() == 'persian',
            "sideboxes" => $sideboxes_side,
            "sideboxes_top" => $sideboxes_top,
            "sideboxes_bottom" => $sideboxes_bottom
        ];

        // Load the main template
        return $this->smarty->view($this->theme_path . "template.tpl", $theme_data, true);
    }

    /**
     * When an ajax request is made to a page, it calls this.
     *
     * @param string $content
     * @param bool|string|array $css
     * @param bool|string|array $js
     * @return string
     */
    private function handleAjaxRequest(string $content = "", bool|string|array $css = "", bool|string|array $js = ""): string
    {
        $array = [
            "title" => $this->title . $this->config->item('title'),
            "content" => $content,
            "js" => $js,
            "css" => $css,
            "slider" => $this->isSliderShown(),
            "serverName" => $this->config->item('server_name'),
            "language" => $this->language->getClientData()
        ];

        return json_encode($array);
    }

    /**
     * Display the global announcement message
     */
    private function handleAnnouncement(): string
    {
        $data = array(
            'module' => 'default',
            'title' => $this->config->item("title"),
            'headline' => $this->config->item("message_headline"),
            'message' => $this->config->item("message_text"),
            'size' => $this->config->item('message_headline_size')
        );

        return $this->loadPage("message.tpl", $data);
    }

    /**
     * Gets the modals
     *
     * @return mixed
     */
    private function getModals(): mixed
    {
        $modal_data = array(
            'url' => $this->page_url,
            'vote_reminder' => $this->config->item('vote_reminder'),
            'vote_reminder_image' => $this->config->item('vote_reminder_image')
        );

        // Load the modals
        return $this->smarty->view($this->theme_path . "views/modals.tpl", $modal_data, true);
    }

    /**
     * Gets the header completely loaded.
     *
     * @param bool|string $css
     * @param bool|string $js
     * @return mixed
     */
    private function getHeader(bool|string|array $css = false, bool|string|array $js = false): mixed
    {
        header('X-XSS-Protection: 1; mode=block');
        header('X-Frame-Options: SAMEORIGIN');
        header('X-Content-Type-Options: nosniff');

        $menus = $this->getMenu();

        // Gather the header data
        $header_data = [
            "style_path" => $this->style_path,
            "theme_path" => $this->theme_path,
            "full_theme_path" => $this->page_url . "application/" . $this->theme_path,
            "image_path" => $this->image_path,
            "writable_path" => $this->writable_path,
            "url" => $this->page_url,
            "title" => $this->title . $this->config->item('title'),
            "serverName" => $this->config->item('server_name'),
            "slider_interval" => $this->config->item('slider_interval'),
            "slider_style" => $this->config->item('slider_style'),
            "vote_reminder" => $this->voteReminder(),
            "keywords" => ($this->custom_keywords) ?? $this->config->item("keywords"),
            "description" => ($this->custom_description) ?? $this->config->item("description"),
            "menu_top" => $menus['top'] ?? [],
            "menu_side" => $menus['side'] ?? [],
            "menu_bottom" => $menus['bottom'] ?? [],
            "path" => base_url() . basename(APPPATH) . '/',
            "favicon" => $this->theme_data['favicon'],
            "minify_js" => !$this->config->item('enable_minify_js'),
            "minify_css" => !$this->config->item('enable_minify_css'),
            "extra_css" => $css,
            "extra_js" => $js,
            "analytics" => $this->config->item('analytics'),
            "use_fcms_tooltip" => true,
            "slider" => $this->theme_data['slider_text'],
            "slider_id" => $this->theme_data['slider_id'],
            "csrf_cookie" => $this->input->cookie('csrf_token_name'),
            "client_language" => $this->language->getClientData(),
            "activeLanguage" => $this->language->getLanguage(),
            "cdn_link" => $this->config->item('cdn') === true ? $this->config->item('cdn_link') : null,
            "isOnline" => $this->user->isOnline(),
            "isRTL" => $this->language->getLanguage() == 'persian' || $this->language->getClientData() == 'persian',
            "social_media" => [
                'facebook' => $this->config->item('facebook'),
                'twitter' => $this->config->item('twitter'),
                'youtube' => $this->config->item('youtube'),
                'discord' => $this->config->item('discord'),
                'instagram' => $this->config->item('instagram')
            ],
            "use_captcha" => false,
            "captcha_type" => $this->config->item('captcha_type')
        ];

        $headerView = "application/" . $this->theme_path . "views/header.tpl";

        // Check if this theme wants to replace our view with its own
        if (file_exists($headerView)) {
            return $this->smarty->view($headerView, $header_data, true);
        } else {
            // Load the theme
            return $this->smarty->view($this->view_path . "header.tpl", $header_data, true);
        }
    }

    /**
     * Determinate whether or not we should show the vote reminder popup
     *
     * @return bool|string
     */
    private function voteReminder(): bool|string
    {
        if ($this->config->item('vote_reminder') && !$this->input->cookie("vote_reminder")) {
            $this->input->set_cookie("vote_reminder", "1", $this->config->item('reminder_interval'));

            return true;
        }

        return false;
    }

    /**
     * Loads the sideboxes, and returns the result
     *
     * @return array
     */
    public function loadSideboxes(): array
    {
        $output = [
            'side' => [],
            'top' => [],
            'bottom' => [],
        ];

        $module = CI::$APP->router->module ?? '';

        $allSideboxes = $this->cms_model->getSideboxes($module);

        foreach ((array) $allSideboxes as $sideBox) {
            $location = $sideBox['location'] ?? 'side';

            if ($sideBox['permission'] && !hasViewPermission($sideBox['permission'], "--SIDEBOX--")) {
                continue;
            }

            $sideboxType = $sideBox['type'];
            $fileLocation = APPPATH . 'modules/sidebox_' . $sideboxType . '/controllers/' . ucfirst($sideboxType) . '.php';

            if (!file_exists($fileLocation)) {
                $output[$location][] = [
                    'name' => "Oops, something went wrong",
                    'data' => 'The following sideBox module is missing or contains an invalid module structure: <b>sidebox_' . $sideboxType . '</b>'
                ];
                continue;
            }

            require_once($fileLocation);

            $object = ($sideboxType === 'custom') ? new $sideboxType($sideBox['id']) : new $sideboxType();

            $output[$location][] = [
                'name' => langColumn($sideBox['displayName']),
                'location' => $location,
                'data' => $object->view(),
                'type' => $sideboxType,
            ];
        }

        return $output;
    }

    /**
     * Load a page template
     *
     * @param string $page Filename
     * @param array $data Array of additional template data
     * @return string
     */
    public function loadPage(string $page, array $data = []): string
    {
        // Get the module, we need to check if it's enabled first
        $data['module'] = array_key_exists("module", $data) ? $data['module'] : $this->module_name;

        // Get the rest of the data
        $data['url'] = array_key_exists("url", $data) ? $data['url'] : $this->page_url;
        $data['theme_path'] = array_key_exists("theme_path", $data) ? $data['theme_path'] : $this->theme_path;
        $data['image_path'] = array_key_exists("image_path", $data) ? $data['image_path'] : $this->image_path;
        $data['full_theme_path'] = array_key_exists("full_theme_path", $data) ? $data['full_theme_path'] : $this->full_theme_path;
        $data['writable_path'] = array_key_exists("writable_path", $data) ? $data['writable_path'] : $this->writable_path;
        $data['CI'] = array_key_exists("CI", $data) ? $data['CI'] : $this->CI;
        $data['ucp_menus'] = array_key_exists("ucp_menus", $data) ? $data['ucp_menus'] : $this->getUcpMenu();

        // Should we load from the default views or not?
        if ($data['module'] == "default") {
            // Shorthand for loading views/page.tpl
            $page = ($page == "page.tpl") ? "views/page.tpl" : $page;

            return $this->smarty->view($this->theme_path . $page, $data, true);
        }

        $isOldTheme = empty($this->theme_data['min_required_version']);

        // Construct the path
        $oldThemeView = 'application/views/old/modules/' . $data['module'] . '/views/' . $page;

        // Construct the path
        $themeView = 'application/' . $this->theme_path . 'modules/' . $data['module'] . '/' . $page;

        // Check if this theme wants to replace our view with its own
        if (file_exists($themeView)) {
            return $this->smarty->view($themeView, $data, true);
        } else if ($isOldTheme && file_exists($oldThemeView)) {
            return $this->smarty->view($oldThemeView, $data, true);
        }

        return $this->smarty->view('modules/' . $data['module'] . '/views/' . $page, $data, true);
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
    public function box(string $title, string $body, bool $full = false, bool|string $css = false, bool|string $js = false): string
    {
        $data = array(
            "module" => "default",
            "headline" => $title,
            "content" => $body,
            "serverName" => $this->config->item('server_name')
        );

        $page = $this->loadPage("page.tpl", $data);

        if ($full) {
            $this->view($page, $css, $js);
        }

        return $page;
    }

    /**
     * Get the menu links
     *
     * @return array
     */
    public function getMenu(): array
    {
        $result = [
            'top' => [],
            'side' => [],
            'bottom' => [],
        ];

        // Get the database values
        $links = $this->cms_model->getLinks();
        $moduleName = $this->getModuleName();

        foreach ($links as $item) {
            $side = $item['type'] ?? 'side';

            if ($item['permission'] && !hasViewPermission($item['permission'], "--MENU--"))
                continue;

            // Xss protect out names
            $item['name'] = $this->format(langColumn($item['name']), false, false);
            $item['active'] = false;

            // Hard coded PM count
            if ($item['link'] == "messages") {
                $count = $this->cms_model->getMessagesCount();
                if ($count > 0) {
                    $item['name'] .= " <b>(" . $count . ")</b>";
                }
            }

            if (!preg_match("/^\/|[a-z][a-z0-9+\-.]*:/i", $item['link'])) {
                if ($moduleName == $item['link']) {
                    $item['active'] = true;
                } elseif ($moduleName == "page" && ($moduleName . "/" . $this->custom_page == $item['link'])) {
                    $item['active'] = true;
                }

                $item['link'] = $this->page_url . $item['link'];
            }

            // Append if it's a direct link or not
            $item['link'] = 'href="' . $item['link'] . '"';

            $result[$side][] = $item;
        }

        return $result;
    }

    /**
     * Load the image slider
     */
    public function getSlider()
    {
        // Load the slides from the database
        $slides_arr = $this->cms_model->getSlides();

        if ($slides_arr) {
            foreach ($slides_arr as $key => $image) {
                $slides_arr[$key]['header'] = langColumn($image['header']);
                $slides_arr[$key]['body'] = langColumn($image['body']);
                $slides_arr[$key]['footer'] = langColumn($image['footer']);

                // Replace {image_path} by the theme image path
                $slides_arr[$key]['image'] = preg_replace("/\{image_path\}/", $this->image_path, $image['image']);
            }
        }

        return $slides_arr;
    }

    /**
     * Show the 404 error
     */
    public function show404()
    {
        $this->setTitle(lang("404_title", "error"));

        $message = $this->loadPage("error.tpl", [
            'module' => 'errors',
            'is404' => true
        ]);

        $output = $this->box(lang("404", "error"), $message);

        $this->view($output);

        $this->output->_display();
        exit();
    }

    /**
     * Show an error message
     *
     * @param bool|string $error
     */
    public function showError(bool|string $error = false)
    {
        $message = $this->loadPage("error.tpl", [
            'module' => 'errors',
            'errorMessage' => $error
        ]);

        $output = $this->box($error, $message);

        $this->view($output);

        $this->output->_display();
        exit();
    }

    /**
     * Returns true if $a >= $b
     *
     * @param string $a
     * @param string $b
     * @param bool $notEqual
     * @return bool
     */
    public function compareVersions(string $a, string $b, bool $notEqual = false): bool
    {
        $maxLength = 4;

        $a = str_pad(preg_replace("/\./", "", $a), $maxLength, "0", STR_PAD_RIGHT);
        $b = str_pad(preg_replace("/\./", "", $b), $maxLength, "0", STR_PAD_RIGHT);

        if ($notEqual) {
            return (int) $a > (int) $b;
        } else {
            return (int) $a >= (int) $b;
        }
    }

    /**
     * Format text
     *
     * @param mixed $text
     * @param bool $nl2br
     * @param bool $xss
     * @param bool $break
     * @return string
     */
    public function format(mixed $text, bool $nl2br = false, bool $xss = true, bool $break = false): mixed
    {
        // Prevent Cross-Site Scripting
        if ($xss && is_string($text)) {
            $text = $this->CI->security->xss_clean($text);
            $text = htmlspecialchars($text);
        }

        // Wordwrap
        if ($break) {
            $text = wordwrap($text, $break, "<br />", true);
        }

        // Convert new lines to <br>
        if ($nl2br) {
            $text = nl2br($text);
        }

        return $text;
    }

    /**
     * Format time as "XX days/hours/minutes/seconds"
     *
     * @param int $time
     * @return string
     */
    public function formatTime(int $time): string
    {
        if (!is_numeric($time)) {
            return "Not a number";
        }

        $a = array(
            30 * 24 * 60 * 60 => 'month',
            24 * 60 * 60 => 'day',
            60 * 60 => 'hour',
            60 => 'minute',
            1 => 'second'
        );

        foreach ($a as $secs => $str) {
            $d = $time / $secs;

            if ($d >= 1) {
                $r = round($d);

                return $r . ' ' . ($r > 1 ? lang($str . 's') : lang($str));
            }
        }

        return '';
    }

    /**
     * Gets the domain name we are on
     *
     * @return string|array|null
     */
    public function getDomainName(): string|array|null
    {
        return preg_replace("/^[\w]{2,6}:\/\/([\w\d\.\-]+).*$/", "$1", $this->config->slash_item('base_url'));
    }

    /**
     * Getter for the title
     *
     * @return string
     */
    public function getTitle(): string
    {
        return $this->title;
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
     * Add an extra description
     *
     * @param string $description
     */
    public function setDescription(string $description): void
    {
        $this->custom_description = $description;
    }

    /**
     * Add extra keywords
     *
     * @param string $keywords
     */
    public function setKeywords(string $keywords): void
    {
        $this->custom_keywords = $keywords;
    }

    /**
     * Get the module name
     *
     * @return string
     */
    public function getModuleName(): string
    {
        return $this->module_name;
    }

    public function getModuleData()
    {
        return $this->module_data;
    }

    /**
     * Set the custom page
     *
     * @param $page
     * @return void
     */
    public function setCustomPage($page): void
    {
        $this->custom_page = $page;
    }

    /**
     * Get the ucp menus
     * @return array
     */
    private function getUcpMenu(): array
    {
        $menus = $this->CI->cache->get("ucp_menu_data");

        if ($menus === false) {
            $menus = $this->cms_model->getUcpMenu();
            $this->CI->cache->save('ucp_menu_data', $menus, 86400); // 1 day
        }

        $groupedMenus = [];
        foreach ($menus as &$menu) {
            $menu['name'] = $this->format(langColumn($menu['name']), false, false);
            $menu['description'] = $this->format(langColumn($menu['description']), false, false);

            // Add the website path if internal link
            if (!preg_match("/https?:\/\//", $menu['link'])) {
                $menu['link'] = $this->page_url . $menu['link'];
            }

            if ($menu['permission'] == 'securityAccount') {
                if ($this->config->item('totp_secret')) {
                    $groupedMenus[$menu['group']][] = $menu;
                }
                continue;
            }

            if (hasPermission($menu['permission'], $menu['permissionModule']))
                $groupedMenus[$menu['group']][] = $menu;
        }

        return $groupedMenus;
    }
}
