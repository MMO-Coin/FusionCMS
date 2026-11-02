<?php

use MX\MX_Controller;

class Guild extends MX_Controller
{
    public function __construct()
    {
        parent::__construct();
        $this->load->model('guild_model');
    }

    public function index($realm = false, $id = false)
    {
        $realm = filter_var($realm, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $id = filter_var($id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $bounds = PageBounds::bounds($this->input->get('offset') ?? 0, 25);
        if (!$realm || !$id || !$bounds || !$this->realms->realmExists($realm)) { http_response_code(400); die(lang('invalid', 'guild')); }
        $key = 'guild_v2_' . $realm . '_' . $id . '_' . $bounds[0] . '_' . getLang();
        $cached = $this->cache->get($key);
        if (is_array($cached) && isset($cached['page'], $cached['title'])) {
            $this->template->setTitle($cached['title']);
            $this->template->view($cached['page'], 'modules/guild/css/guild.css'); return;
        }
        $guild = $this->guild_model->getGuild($realm, $id);
        $title = $guild ? $guild['guildName'] : lang('invalid_guild', 'guild');
        $this->template->setTitle($title);
        $total = $guild ? $this->guild_model->countGuildMembers($realm, $id) : 0;
        $offset = PageBounds::clamp($bounds[0], $total, 25);
        $members = $guild ? $this->guild_model->getGuildMembers($realm, $id, $offset, 25) : [];
        foreach ($members as &$member) {
            $member['classId'] = $member['class']; $member['raceId'] = $member['race'];
            $member['faction'] = $this->realms->getFactionByRaceId((int) $member['race']);
        }
        unset($member);
        $base = $this->template->page_url . 'guild/' . $realm . '/' . $id;
        $data = ['module' => 'guild', 'guild' => $guild, 'members' => $members, 'member_count' => $total,
            'realmId' => $realm, 'realmName' => $this->realms->getRealm($realm)->getName(), 'url' => $this->template->page_url,
            'guildMotd' => $guild ? $this->template->format($guild['motd']) : '',
            'previous' => $offset > 0 ? $base . '?offset=' . max(0, $offset - 25) : '',
            'next' => $offset + count($members) < $total ? $base . '?offset=' . ($offset + 25) : ''];
        $page = $this->template->loadPage('page.tpl', ['module' => 'default', 'headline' => breadcrumb(['armory' => lang('armory', 'guild'), uri_string() => $title]),
            'content' => $this->template->loadPage('guild.tpl', $data)]);
        // Only valid page offsets get cache entries; arbitrary oversized offsets cannot fill the cache.
        if ($offset === $bounds[0]) $this->cache->save($key, ['page' => $page, 'title' => $title], 300);
        $this->template->view($page, 'modules/guild/css/guild.css');
    }
}
