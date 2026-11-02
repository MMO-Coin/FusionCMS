<?php

use MX\MX_Controller;

/**
 * Armory Controller Class
 * @property armory_model $armory_model armory_model Class
 */
class Armory extends MX_Controller
{
    private $weapon_sub;
    private $armor_sub;
    private $slots;

    public function __construct()
    {
        parent::__construct();

        requirePermission("view");

        $this->load->model('armory_model');


        $this->weapon_sub = lang('weapon_sub', 'wow_tooltip');
        $this->armor_sub = lang('armor_sub', 'wow_tooltip');
        $this->slots = lang('slots', 'wow_tooltip');
    }

    public function index()
    {
        clientLang("search_too_short", "armory");
        clientLang("search_failed", "armory");
        clientLang("search_invalid", "armory");
        clientLang("view_character_profile", "armory");

        $this->template->setTitle(lang("search_title", "armory"));

        $realms = $this->realms->getRealms();

        $data = array(
            "realms" => $realms,
        );

        $this->template->view($this->template->loadPage("page.tpl", array(
            "module" => "default", 
            "headline" => lang("search_headline", "armory"),
            "content" => $this->template->loadPage("search.tpl", $data)
        )), "modules/armory/css/search.css", "modules/armory/js/search.js");
    }
    
    public function search()
    {
        $draw = PageBounds::offset($this->input->post('draw'));
        $bounds = PageBounds::bounds($this->input->post('start') ?? 0, $this->input->post('length') ?? 10);
        $realm = filter_var($this->input->post('realm'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $type = $this->input->post('table');
        // Search text is bound by the model and escaped when rendered, not rewritten as HTML.
        $search = $this->input->post('search', false);
        $search = is_string($search) ? trim($search) : '';
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        if (!$this->input->is_ajax_request() || !$bounds || !$realm || !in_array($type, ['items', 'guilds', 'characters'], true)
            || mb_strlen($search) < 3 || mb_strlen($search) > 100 || !$this->realms->realmExists($realm)) {
            http_response_code(400);
            die(json_encode(['draw' => $draw, 'recordsTotal' => 0, 'recordsFiltered' => 0, 'data' => [], 'error' => lang('search_invalid', 'armory')]));
        }
        $total = $this->armory_model->get_search_count($type, $search, $realm, $this->input->post('refresh') === '1');
        $rows = $bounds[0] < $total ? $this->armory_model->{'get_' . $type}($search, $bounds[1], $bounds[0], $realm) : [];
        $result = [];
        foreach ($rows as $row) {
            if ($type === 'items') {
                $result[] = ['id' => $row['entry'], 'name' => $row['name'], 'level' => $row['ItemLevel'], 'required' => $row['RequiredLevel'],
                    'type' => $this->getItemType($row['InventoryType'], $row['class'], $row['subclass']), 'quality' => $row['Quality'],
                    'realm' => $realm, 'icon' => $this->getIcon($row['entry'], $realm)];
            } elseif ($type === 'guilds') {
                $result[] = ['id' => $row['guildid'], 'name' => $row['name'], 'members' => $row['GuildMemberCount'], 'realm' => $realm,
                    'ownerGuid' => $row['leaderguid'], 'ownerName' => $row['leaderName']];
            } else {
                $result[] = ['guid' => $row['guid'], 'name' => $row['name'], 'race' => $this->realms->getFactionByRaceId((int) $row['race']),
                    'gender' => $row['gender'], 'class' => $row['class'], 'level' => $row['level'],
                    'avatar' => $this->realms->formatAvatarPath($row), 'realm' => $realm];
            }
        }
        die(json_encode(['draw' => $draw, 'recordsTotal' => $total, 'recordsFiltered' => $total, 'data' => $result], JSON_INVALID_UTF8_SUBSTITUTE));
    }

    private function getIcon($id, $realm)
    {
        $cache = $this->cache->get("items/item_" . $realm . "_" . $id);

        if ($cache !== false) {
            if ($cache['icon']) {
                return '<img src="' . $this->config->item('api_item_icons') . '/small/' . $cache['icon'] . '.jpg" align="absmiddle" />';
            } else {
                return '<img src="' . $this->config->item('api_item_icons') . '/small/inv_misc_questionmark.jpg" align="absmiddle" />';
            }
        } else {
            return $this->template->loadPage("icon_ajax.tpl", array('id' => $id, 'realm' => $realm, 'url' => $this->template->page_url));
        }
    }

    private function getItemType($slot, $class, $subclass)
    {
        // Weapons
        if ($class == 2) {
            $type = (array_key_exists($subclass, $this->weapon_sub)) ? $this->weapon_sub[$subclass] : "Unknown";
        }

        // Armor
        elseif ($class == 4) {
            $type = (array_key_exists($subclass, $this->armor_sub)) ? $this->armor_sub[$subclass] : "Unknown";
        }

        // Anything else
        else {
            $type = null;
        }

        $slot = $this->slots[$slot];

        if ($slot && $type) {
            if (strlen($slot) && strlen($type)) {
                return $slot . " (" . $type . ")";
            } else {
                return lang("misc", "armory");
            }
        } else {
            return lang("misc", "armory");
        }
    }
}
