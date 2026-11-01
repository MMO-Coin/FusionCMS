<?php

use CodeIgniter\Database\BaseConnection;

class Password_recovery_model extends CI_Model
{
    public const TOKEN_LIFETIME = 3600;

    private BaseConnection $connection;

    public function __construct()
    {
        if (empty($this->connection))
        {
            $this->connection = $this->load->database('account', true);
        }
    }

    public function get_username($email)
    {
        $builder = $this->connection->table(table('account'))->select(column('account', 'username'));
        $builder->where(column('account', 'email'), $email);
        $query = $builder->get();
        $result = $query->getResultArray();
        return $result[0][column('account', 'username')];
    }

    public function get_token($token)
    {
        if (!is_string($token) || !preg_match('/^[a-f0-9]{128}$/D', $token)) {
            return false;
        }
        $now = time();
        return $this->db->table('password_recovery_key')
            ->where('recoverykey', $token)
            ->where('time >', $now - self::TOKEN_LIFETIME)
            ->where('time <=', $now)
            ->get()->getRowArray() ?: false;
    }

    public function consume_token($token)
    {
        $record = $this->get_token($token);
        if (!$record) return false;

        // Only one request may claim the token, even when lookups overlap.
        $now = time();
        $deleted = $this->db->table('password_recovery_key')
            ->where('recoverykey', $token)
            ->where('time >', $now - self::TOKEN_LIFETIME)
            ->where('time <=', $now)
            ->delete();
        return $deleted && $this->db->affectedRows() === 1 ? $record : false;
    }

    public function insert_token($token, $username, $email, $ip)
    {
        if (!$token || !$username || !$email || !$ip)
        {
            return false;
        }

        $data = [
            'recoverykey' => $token,
            'username' => $username,
            'email' => $email,
            'ip' => $ip,
            'time' => time(),
        ];

        $this->db->table('password_recovery_key')->insert($data);
        return true;
    }

    public function delete_token($token)
    {
        if (!$token)
        {
            return false;
        }

        $this->db->table('password_recovery_key')->where('recoverykey', $token)->delete();
        return true;
    }
}
