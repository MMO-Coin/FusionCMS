<?php

use App\Config\Services;
use CodeIgniter\Events\Events;
use MX\MX_Controller;

/**
 * Admin_items Controller Class
 * @property items_model $items_model items_model Class
 */
class Admin_items extends MX_Controller
{
    public function __construct()
    {
        // Make sure to load the administrator library!
        $this->load->library('administrator');
        $this->load->model('items_model');

        parent::__construct();

        requirePermission("canViewItems");

        $this->load->library('form_validation');
    }

    public function index()
    {
        // Change the title
        $this->administrator->setTitle(lang('items', 'store'));

        // Prepare my data
        $data = [
            'url'    => $this->template->page_url,
            'items'  => $this->items_model->getItems(),
            'groups' => $this->items_model->getGroups(),
            'realms' => $this->realms->getRealms()
        ];

        // Load my view
        $output = $this->template->loadPage("items.tpl", $data);

        // Put my view in the main box with a headline
        $content = $this->administrator->box(lang('store', 'store'), $output);

        // Output my content. The method accepts the same arguments as template->view
        $this->administrator->view($content, false, "modules/store/js/admin_items.js");
    }

    public function add_group()
    {
        // Check for the permission
        requirePermission("canAddGroups");

        // Change the title
        $this->administrator->setTitle(lang('add_group', 'store'));

        $data = [
            'url' => $this->template->page_url,
        ];

        // Load my view
        $output = $this->template->loadPage("admin_add_group.tpl", $data);

        // Put my view in the main box with a headline
        $content = $this->administrator->box(lang('add_group', 'store'), $output);

        // Output my content. The method accepts the same arguments as template->view
        $this->administrator->view($content, false, "modules/store/js/admin_items.js");
    }

    /**
     * Create a group that will group some items.
     */
    public function createGroup()
    {
        // Check for the permission
        requirePermission("canAddGroups");

        $this->form_validation->set_rules('title', 'title', 'trim|required|min_length[2]|max_length[100]');
        $this->form_validation->set_rules('icon', 'icon', 'trim|max_length[50]|alpha_dash');
        $this->form_validation->set_rules('order', 'order', 'trim|required|integer|greater_than_equal_to[0]|less_than_equal_to[999]');

        $this->form_validation->set_error_delimiters('', '');

        if (!$this->form_validation->run()) {
            die(validation_errors());
        }

        $data = [
            'title'       => $this->input->post('title'),
            'icon'        => $this->input->post('icon'),
            'orderNumber' => $this->input->post('order')
        ];

        $this->items_model->addGroup($data);

        $this->cache->delete('store_items.cache');

        // Add log
        $this->dblogger->createLog("admin", "add", "Added item group", ['Group' => $data['title']]);

        Events::trigger('onCreateGroupStore', $data['title']);

        die('yes');
    }

    public function edit_group($id)
    {
        // Check for the permission
        requirePermission("canAddGroups");

        // Change the title
        $this->administrator->setTitle(lang('edit_group', 'store'));

        $group = $this->items_model->getGroup($id);

        $data = [
            'url'   => $this->template->page_url,
            'group' => $group,
        ];

        // Load my view
        $output = $this->template->loadPage("admin_edit_group.tpl", $data);

        // Put my view in the main box with a headline
        $content = $this->administrator->box(lang('edit_group', 'store'), $output);

        // Output my content. The method accepts the same arguments as template->view
        $this->administrator->view($content, false, "modules/store/js/admin_items.js");
    }

    public function add_item()
    {
        // Check for the permission
        requirePermission("canAddItems");

        // Change the title
        $this->administrator->setTitle(lang('add_item', 'store'));

        $data = [
            'url'    => $this->template->page_url,
            'groups' => $this->items_model->getGroups(),
            'realms' => $this->realms->getRealms()
        ];

        // Load my view
        $output = $this->template->loadPage("admin_add_item.tpl", $data);

        // Put my view in the main box with a headline
        $content = $this->administrator->box(lang('add_item', 'store'), $output);

        // Output my content. The method accepts the same arguments as template->view
        $this->administrator->view($content, false, "modules/store/js/admin_items.js");
    }

    /**
     * Add item
     */
    public function createItem()
    {
        // Check for the permission
        requirePermission("canAddItems");

        $type = $this->input->post('item_type');

        $this->form_validation->set_rules('item_type', 'item type', 'trim|required|in_list[item,query,command]');

        switch ($type) {
            case 'query':
                $this->setQueryValidation();
                break;
            case 'command':
                $this->setCommandValidation();
                break;
            case 'item':
                $this->setItemValidation();
                break;
            default:
                die('Invalid item type');
        }

        $this->form_validation->set_error_delimiters('', '');

        if (!$this->form_validation->run()) {
            die(validation_errors());
        }

        $data = match ($type) {
            'query' => $this->getQueryData(),
            'command' => $this->getCommandData(),
            default => $this->getItemData(),
        };

        $this->items_model->add($data);

        $this->cache->delete('store_items.cache');

        // Add log
        $this->dblogger->createLog("admin", "add", "Item added", ['Item' => $data['name']]);

        Events::trigger('onAddItemStore', $data);

        die('yes');
    }

    /**
     * Get the query data
     *
     * @return array
     */
    private function getQueryData(): array
    {
        $data["name"] = $this->input->post("name");
        $data["description"] = $this->input->post("description");
        $data["quality"] = $this->input->post("quality");
        $data["query_database"] = $this->input->post("query_database");
        $data["query_need_character"] = ($this->input->post("query_need_character") == "true") ? 1 : 0;
        $data["require_character_offline"] = ($this->input->post("require_character_offline") == "true") ? 1 : 0;
        $data["query"] = $this->input->post("query");
        $data["realm"] = $this->input->post("realm");
        $data["group"] = $this->input->post("group");
        $data["vp_price"] = $this->input->post("vpCost");
        $data["dp_price"] = $this->input->post("dpCost");
        $data["icon"] = $this->input->post("icon");
        $data["tooltip"] = 0;

        if (!$this->isValidIcon($data["icon"])) {
            $data["icon"] = "inv_misc_questionmark";
        }

        return $data;
    }

    /**
     * Get the command data
     *
     * @return array
     */
    private function getCommandData(): array
    {
        $data["name"] = $this->input->post("name");
        $data["description"] = $this->input->post("description");
        $data["quality"] = $this->input->post("quality");
        $data["command_need_character"] = ($this->input->post("command_need_character") == "true") ? 1 : 0;
        $data["require_character_offline"] = ($this->input->post("require_character_offline") == "true") ? 1 : 0;
        $data["command"] = $this->input->post("command");
        $data["realm"] = $this->input->post("realm");
        $data["group"] = $this->input->post("group");
        $data["vp_price"] = $this->input->post("vpCost");
        $data["dp_price"] = $this->input->post("dpCost");
        $data["icon"] = $this->input->post("icon");
        $data["tooltip"] = 0;

        if (!$this->isValidIcon($data["icon"])) {
            $data["icon"] = "inv_misc_questionmark";
        }

        return $data;
    }

    /**
     * Get the itemdata
     *
     * @return array|void
     */
    private function getItemData()
    {
        $data["itemid"] = $this->input->post("itemid");
        $data["itemcount"] = $this->input->post("itemcount");
        $data["description"] = $this->input->post("description");
        $data["realm"] = $this->input->post("realm");
        $data["group"] = $this->input->post("group");
        $data["vp_price"] = $this->input->post("vpCost");
        $data["dp_price"] = $this->input->post("dpCost");
        $data["icon"] = $this->input->post("icon");

        if (!is_numeric(preg_replace("/,/", "", $data["itemid"]))) {
            die(lang('invalid_item_id', 'store'));
        }

        if ($data["itemcount"] == '' || empty($data["itemcount"]) || is_null($data["itemcount"])) {
            $data["itemcount"] = 1;
        }

        if ($data["group"] == 0) {
            die(lang('group_cant_be_empty', 'store'));
        }

        if (preg_match("/,/", $data["itemid"])) {
            $data["name"] = $this->input->post("name");
            $data["tooltip"] = 0;
            $data["quality"] = 4;
            if (!$this->isValidIcon($data["icon"])) {
                $data["icon"] = "inv_misc_questionmark";
            }
        } else {
            $item_data = $this->realms->getRealm($data["realm"])->getWorld()->getItem($data["itemid"]);

            if (!$item_data || empty($item_data) || is_null($item_data) || $item_data == 'empty') {
                die(lang('invalid_item', 'store'));
            }

            $post_name = $this->input->post('name');
            $data["name"] = $post_name ? $post_name : $item_data['name'];
            $data["tooltip"] = 1;
            $data["quality"] = $item_data['Quality'];
            if (!$this->isValidIcon($data["icon"])) {
                $response = Services::curlrequest()->get($this->template->page_url . "icon/get/" . $data["realm"] . "/" . $data["itemid"]);
                $data["icon"] = $response->getBody();
            }
        }

        return $data;
    }

    /**
     * Load the page to edit the item with the given id.
     *
     * @param bool $id
     */
    public function edit($id = false)
    {
        // Check for the permission
        requirePermission("canEditItems");

        if (!is_numeric($id) || !$id) {
            die();
        }

        $item = $this->items_model->getItem($id);

        if (!$item) {
            die(lang('no_item_with_id', 'store'));
        }

        // Change the title
        $this->administrator->setTitle($item['name']);

        $data = array(
            'url' => $this->template->page_url,
            'item' => $item,
            'groups' => $this->items_model->getGroups(),
            'realms' => $this->realms->getRealms()
        );

        // Load my view
        $output = $this->template->loadPage("admin_edit_item.tpl", $data);

        // Put my view in the main box with a headline
        $content = $this->administrator->box('<a href="' . $this->template->page_url . 'store/admin_items">' . lang('items', 'store') . '</a> &rarr; ' . $item['name'], $output);

        // Output my content. The method accepts the same arguments as template->view
        $this->administrator->view($content, false, "modules/store/js/admin_items.js");
    }

    /**
     * Save the edited details for the given item id.
     *
     * @param bool|int $id
     */
    public function save(bool|int $id = false)
    {
        // Check for the permission
        requirePermission("canEditItems");

        if (!$id || !is_numeric($id)) {
            die();
        }

        $type = '';

        if ($this->input->post('query')) {
            $type = 'query';
        } elseif ($this->input->post('command')) {
            $type = 'command';
        } else {
            $type = 'item';
        }

        switch ($type) {
            case 'query':
                $this->setQueryValidation();
                break;
            case 'command':
                $this->setCommandValidation();
                break;
            default:
                $this->setItemValidation();
                break;
        }

        $this->form_validation->set_error_delimiters('', '');

        if (!$this->form_validation->run()) {
            die(validation_errors());
        }

        $data = match ($type) {
            'query' => $this->getQueryData(),
            'command' => $this->getCommandData(),
            default => $this->getItemData(),
        };

        $this->items_model->edit($id, $data);

        $this->cache->delete('store_items.cache');

        // Add log
        $this->dblogger->createLog("admin", "edit", "Edited item", ['Item' => $data['name']]);

        Events::trigger('onEditItemStore', $id, $data);

        die('yes');
    }

    /**
     * Save a group with the given id
     *
     * @param bool|int $id
     */
    public function saveGroup(bool|int $id = false)
    {
        // Check for the permission
        requirePermission("canEditGroups");

        if (!$id || !is_numeric($id)) {
            die(lang('no_id', 'store'));
        }

        $this->form_validation->set_rules('title', 'title', 'trim|required|min_length[2]|max_length[100]');
        $this->form_validation->set_rules('icon', 'icon', 'trim|max_length[50]');
        $this->form_validation->set_rules('order', 'order', 'trim|required|integer|greater_than_equal_to[0]|less_than_equal_to[9999]');

        $this->form_validation->set_error_delimiters('', '');

        if (!$this->form_validation->run()) {
            die(validation_errors());
        }

        $data = [
            'title'       => $this->input->post('title'),
            'icon'        => $this->input->post('icon'),
            'orderNumber' => $this->input->post('order')
        ];

        $this->items_model->editGroup($id, $data);

        // Add log
        $this->dblogger->createLog("admin", "edit", "Edited item group", ['ID' => $id, 'Group' => $data["title"]]);

        Events::trigger('onEditGroupStore', $id);

        $this->cache->delete('store_items.cache');
        die('yes');
    }

    public function delete(bool|int $id = false)
    {
        // Check for the permission
        requirePermission("canRemoveItems");

        if (!$id || !is_numeric($id)) {
            die();
        }

        $this->items_model->delete($id);

        // Add log
        $this->dblogger->createLog("admin", "delete", "Deleted item", ['ID' => $id]);

        Events::trigger('onDeleteItemStore', $id);

        $this->cache->delete('store_items');
    }

    public function deleteGroup(bool|int $id = false)
    {
        requirePermission("canRemoveGroups");

        if (!$id || !is_numeric($id)) {
            die();
        }

        $this->items_model->deleteGroup($id);

        // Add log
        $this->dblogger->createLog("admin", "delete", "Deleted item group", ['ID' => $id]);

        Events::trigger('onDeleteGroupStore', $id);

        $this->cache->delete('store_items');
    }

    private function isValidIcon($icon): bool
    {
        return preg_match('/^(inv_|ability_|achievement_|spell_|classicon_|ui_)[a-zA-Z0-9_-]+$/i', trim($icon));
    }

    private function setCommonItemValidation(): void
    {
        $this->form_validation->set_rules('name', 'name', 'trim|required|min_length[2]|max_length[255]');
        $this->form_validation->set_rules('description', 'description', 'trim|max_length[5000]');
        $this->form_validation->set_rules('realm', 'realm', 'trim|required|integer|greater_than_equal_to[1]|less_than_equal_to[999]');
        $this->form_validation->set_rules('group', 'group', 'trim|required|integer|greater_than[0]|less_than_equal_to[999999]');
        $this->form_validation->set_rules('vpCost', 'VP Cost', 'trim|integer|greater_than_equal_to[0]|less_than_equal_to[999999999]');
        $this->form_validation->set_rules('dpCost', 'DP Cost', 'trim|integer|greater_than_equal_to[0]|less_than_equal_to[999999999]');
        $this->form_validation->set_rules('icon', 'icon', 'trim|required|max_length[150]');
    }

    private function setItemValidation(): void
    {
        $this->setCommonItemValidation();

        $this->form_validation->set_rules('itemid', 'Item ID', 'trim|required|max_length[100]|regex_match[/^[0-9,\s]+$/]');
        $this->form_validation->set_rules('itemcount', 'Item Count', 'trim|integer|greater_than_equal_to[1]|less_than_equal_to[999999]');
    }

    private function setQueryValidation(): void
    {
        $this->setCommonItemValidation();
        $this->form_validation->set_rules('quality', 'quality', 'trim|required|integer|greater_than_equal_to[0]|less_than_equal_to[7]');
        $this->form_validation->set_rules('query_database', 'query database', 'trim|required|in_list[world,characters,auth,account]');
        $this->form_validation->set_rules('query_need_character', 'query need character', 'trim|required|in_list[true,false]');
        $this->form_validation->set_rules('require_character_offline', 'require character offline', 'trim|required|in_list[true,false]');
        $this->form_validation->set_rules('query', 'query', 'trim|required|min_length[1]|max_length[10000]');
    }

    private function setCommandValidation(): void
    {
        $this->setCommonItemValidation();

        $this->form_validation->set_rules('quality', 'quality', 'trim|required|integer|greater_than_equal_to[0]|less_than_equal_to[7]');
        $this->form_validation->set_rules('command_need_character', 'command need character', 'trim|required|in_list[true,false]');
        $this->form_validation->set_rules('require_character_offline', 'require character offline', 'trim|required|in_list[true,false]');
        $this->form_validation->set_rules('command', 'command', 'trim|required|min_length[1]|max_length[5000]');
    }
}