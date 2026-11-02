<?php

use App\Config\Services;

/**
 * @package FusionCMS
 * @author  MMO-Coin <https://github.com/MMO-Coin/FusionCMS>
 * @link    https://github.com/FusionWowCMS/FusionCMS
 */

class Cms_model extends CI_Model
{
    //private $db;
    /**
     * Connect to the database
     */
    public function __construct()
    {
        parent::__construct();

        //$this->db = $this->load->database("cms", true);

        $this->logVisit();

        if ($this->config->item('detect_language')) {
            $this->setLangugage();
        }
    }

    private function logVisit(): void
    {
        if (!$this->input->is_ajax_request() && !$this->input->get('is_json_ajax')) {
            $ip = $this->input->ip_address();
            $date = date("Y-m-d");

            $exists = $this->db->table('visitor_log')
                ->select('id')
                ->where('date', $date)
                ->where('ip', $ip)
                ->limit(1)
                ->get()
                ->getRow();

            if (!$exists) {
                $this->db->table('visitor_log')->insert([
                    'date' => $date,
                    'ip' => $ip,
                    'timestamp' => time()
                ]);
            }
        }
    }

    public function getSideboxes(string $page = '*'): array
    {
        // Query: Prepare
        $query = $this->db->table('sideboxes')
            ->select('*')
            ->orderBy('order', 'ASC');

        // Query: Filter (Page)
        if ($page && $page !== '*')
            $query = $query->groupStart()
                ->like('pages', str_replace(':page', $page, '":page"'), 'both')
                ->orLike('pages', '"*"', 'both')
                ->groupEnd();

        // Query: Execute
        $query = $query->get();

        // Query: Make sure we have results
        if ($query->getNumRows())
            return $query->getResultArray();

        return [];
    }

    /**
     * Load the slider images
     *
     * @return array|null
     */
    public function getSlides(): ?array
    {
        $query = $this->db->query("SELECT * FROM image_slider ORDER BY `order` ASC");

        if ($query->getNumRows() > 0) {
            return $query->getResultArray();
        }

        return null;
    }

    /**
     * Get the links of one direction
     *
     * @return array
     */
    public function getLinks(): array
    {
        $query = $this->db->query("SELECT * FROM menu ORDER BY `parent_id` ASC, `order` ASC");

        if ($query->getNumRows() > 0) {
            return $query->getResultArray();
        }

        return [];
    }

    /**
     * Get the selected page from the database
     *
     * @param string $page
     * @return array|null
     */
    public function getPage(string $page): ?array
    {
        $query = $this->db->table('pages')->select('*')->where('identifier', $page)->get();

        if ($query->getNumRows() > 0) {
            $result = $query->getResultArray();
            return $result[0];
        }

        return null;
    }

    /**
     * Get any old rank ID (to avoid foreign key errors)
     *
     * @return bool|int
     */
    public function getAnyOldRank(): bool|int
    {
        $query = $this->db->query("SELECT id FROM `ranks` ORDER BY id ASC LIMIT 1");

        if ($query->getNumRows() > 0) {
            $result = $query->getRowArray();
            return $result['id'];
        }

        return false;
    }

    /**
     * Get all pages
     *
     * @return array|null
     */
    public function getPages(): ?array
    {
        $query = $this->db->table('pages')->select()->get();

        if ($query->getNumRows() > 0) {
            return $query->getResultArray();
        }

        return null;
    }

    /**
     * Get all data from the realms table
     *
     * @return array|null
     */
    public function getRealms(): ?array
    {
        $query = $this->db->table('realms')->select()->get();

        if ($query->getNumRows() > 0) {
            return $query->getResultArray();
        }

        return null;
    }

    /**
     * Get the realm database information
     *
     * @param int $id
     * @return array|null
     */
    public function getRealm(int $id): ?array
    {
        $query = $this->db->table('realms')->select()->where('id', $id)->get();

        if ($query->getNumRows() > 0) {
            $result = $query->getResultArray();
            return $result[0];
        }

        return null;
    }

    public function getBackups($id = false)
    {
        if ($id) {
            $query = $this->db->query("SELECT backup_name FROM backup where id = ?", [$id]);

            if ($query->getNumRows() > 0) {
                $result = $query->getRowArray();
                return $result['backup_name'];
            } else {
                return false;
            }
        } else {
            $query = $this->db->query("SELECT * FROM backup ORDER BY `id` ASC");

            if ($query->getNumRows() > 0) {
                return $query->getResultArray();
            } else {
                return false;
            }
        }
    }

    public function getBackupCount()
    {
        $query = $this->db->table('backup')->select("COUNT(id) 'count'")->get();

        if ($query->getNumRows() > 0) {
            $result = $query->getRowArray();
            return $result['count'];
        }

        return null;
    }

    public function deleteBackups($id)
    {
        $this->db->query("delete FROM backup WHERE id = ?", [$id]);
    }

    public function getTemplate($id)
    {
        $query = $this->db->query("SELECT * FROM email_templates WHERE id= ? LIMIT 1", [$id]);

        if ($query->getNumRows() > 0) {
            return $query->getRowArray();
        } else {
            return false;
        }
    }

    public function getNotifications($id, $count = false)
    {
        if ($count) {
            return $this->db->table('notifications')->select()->where('uid', $id)->where('read', 0)->countAllResults();
        } else {
            $query = $this->db->table('notifications')->select()->where('uid', $id)->get();

            if ($query->getNumRows() > 0) {
                return $query->getResultArray();
            }
        }

        return null;
    }

    public function setReadNotification($id, $uid, $all = false)
    {
        $builder = $this->db->table('notifications')->set('read', 1);
        if (!$all) {
            $builder->where('id', $id);
        }
        $builder->where('uid', $uid);
        $builder->update();
    }

    private function setLangugage()
    {
        $supported_languages = $this->config->item('supported_languages');
        if (!is_array($supported_languages)) {
            $supported_languages = [];
        }

        $langs = [Services::request()->negotiate('language', $supported_languages) ?? $this->config->item('language')];

        $lang = '';
        foreach ($langs as $lang_item) {
            // Check if its in the array
            if (in_array($lang_item, array_keys($supported_languages))) {
                $setLang = $supported_languages[$lang_item]['name'];
                $lang = $lang_item;
                break;
            }
        }

        // If no language has been worked out - or it is not supported - use the default
        if (!isset($setLang) || !in_array($lang, array_keys($supported_languages))) {
            $setLang = $this->config->item('default_language');
        }

        if (Services::session()->get('online')) {
            $this->user->setLanguage($setLang);
        } else {
            Services::session()->set(['language' => $setLang]);
        }
    }

    private function getSession($session)
    {
        $builder = $this->db->table("ci_sessions");
        $builder->where('ip_address', $session['ip_address']);
        $builder->where('user_agent', $session['user_agent']);
        $query = $builder->get();

        if ($query->getNumRows() > 0) {
            return $query->getResultArray();
        } else {
            return false;
        }
    }

    /**
     * Get the number of unread messages
     * @return int
     */
    public function getMessagesCount(): int
    {
        $builder = $this->db->table('private_message');
        $builder->select('COUNT(*) as `total`');
        $builder->where('user_id', $this->user->getId());
        $builder->where('read', 0);
        $query = $builder->get();

        if ($query->getNumRows() > 0) {
            $result = $query->getResultArray();

            return $result[0]['total'];
        }

        return 0;
    }

    /**
     * Get the ucp menus
     * @return array
     */
    public function getUcpMenu(): array
    {
        return $this->db->table('menu_ucp')
            ->select(['id', 'name', 'description', 'link', 'icon', 'order', 'group', 'permission', 'permissionModule'])
            ->orderBy('`group`', 'ASC')
            ->orderBy('`order`', 'ASC')
            ->get()->getResultArray();
    }

    /**
     * Issue a new remember-me token for the given account.
     *
     * @param int $accountId
     * @param string|null $expectedSessionVersion Version captured when the account signed in.
     * @return string The raw token to set in the cookie (never stored as-is).
     */
    public function issueRememberMeToken(int $accountId, ?string $expectedSessionVersion = null, string $ip = '', string $agent = ''): string
    {
        $lifetime = (int) $this->config->item('cookie_expire');
        if ($accountId <= 0 || $lifetime <= 0) {
            throw new InvalidArgumentException('Invalid remembered-login account or lifetime.');
        }
        $sessionVersion = $expectedSessionVersion ?? $this->account_session_model->getVersion($accountId);
        // 64 bytes = 128 hex chars of randomness
        $rawToken = bin2hex(random_bytes(64));
        $tokenHash = hash('sha256', $rawToken);

        $inserted = $this->db->table('remember_me_tokens')->insert([
            'account_id'  => $accountId,
            'token_hash'  => $tokenHash,
            'expires_at'  => date('Y-m-d H:i:s', time() + $lifetime),
            'created_at'  => date('Y-m-d H:i:s'),
            'ip_address' => substr($ip, 0, 45),
            'user_agent' => mb_substr(mb_convert_encoding($agent, 'UTF-8', 'UTF-8'), 0, 255),
            'last_used_at' => null,
        ]);
        if (!$inserted) throw new RuntimeException('Unable to store the remembered login.');
        // Check after insertion so a reset that already deleted tokens cannot
        // be followed by this request leaving a remembered login behind.
        if (!hash_equals($sessionVersion, $this->account_session_model->getVersion($accountId))) {
            $this->deleteRememberMeTokenByToken($rawToken);
            throw new RuntimeException('Account sessions changed during sign-in. Please sign in again.');
        }

        return $rawToken;
    }

    /**
     * Validate a raw token from a cookie and return the matching account_id.
     *
     * Rotate the hash in place so only one request can use the old token and
     * account-wide revocation cannot miss a replacement inserted later.
     *
     * @param string $rawToken
     * @return array|false Account ID, successor token and captured session version, or false.
     */
    public function validateRememberMeToken(string $rawToken, string $ip = '', string $agent = ''): array|false
    {
        if (!preg_match('/^[a-f0-9]{128}$/D', $rawToken)) return false;
        $tokenHash = hash('sha256', $rawToken);
        $row = $this->findRememberMeToken($tokenHash);
        if (!$row) return false;
        $sessionVersion = $this->account_session_model->getVersion((int) $row['account_id']);
        $now = time();
        $expires = strtotime($row['expires_at']);
        if ($expires === false || $expires <= $now || (int) $row['account_id'] <= 0) {
            $this->db->table('remember_me_tokens')->where('id', $row['id'])->where('token_hash', $tokenHash)->delete();
            return false;
        }
        $lifetime = (int) $this->config->item('cookie_expire');
        if ($lifetime <= 0) return false;
        $newRawToken = bin2hex(random_bytes(64));
        $updated = $this->db->table('remember_me_tokens')
            ->where('id', $row['id'])->where('token_hash', $tokenHash)
            ->where('expires_at >', date('Y-m-d H:i:s', $now))
            ->update(['token_hash' => hash('sha256', $newRawToken), 'expires_at' => date('Y-m-d H:i:s', $now + $lifetime),
                'last_used_at' => date('Y-m-d H:i:s', $now), 'ip_address' => substr($ip, 0, 45),
                'user_agent' => mb_substr(mb_convert_encoding($agent, 'UTF-8', 'UTF-8'), 0, 255)]);
        if (!$updated || $this->db->affectedRows() !== 1) return false;
        return [
            'account_id' => (int) $row['account_id'],
            'new_token'  => $newRawToken,
            'session_version' => $sessionVersion,
        ];
    }

    protected function findRememberMeToken(string $tokenHash): ?array
    {
        return $this->db->table('remember_me_tokens')->select('id, account_id, expires_at')
            ->where('token_hash', $tokenHash)->get()->getRowArray();
    }

    /**
     * Delete all remember-me tokens for an account (e.g. on logout or
     * password change).
     *
     * @param int $accountId
     */
    public function deleteRememberMeTokenByAccount(int $accountId): void
    {
        if (!$this->db->table('remember_me_tokens')->delete(['account_id' => $accountId])) {
            throw new RuntimeException('Unable to revoke remembered logins.');
        }
    }

    /**
     * Revoke a replacement token if restoring its account fails.
     *
     * @param string $rawToken
     */
    public function deleteRememberMeTokenByToken(string $rawToken): void
    {
        $tokenHash = hash('sha256', $rawToken);
        $this->db->table('remember_me_tokens')->delete(['token_hash' => $tokenHash]);
    }

    /**
     * Remove expired tokens (garbage collection).
     */
    public function purgeRememberMeTokenExpired(): void
    {
        $this->db->table('remember_me_tokens')->where('expires_at <=', date('Y-m-d H:i:s'))->delete();
    }
}
