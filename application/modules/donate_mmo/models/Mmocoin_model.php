<?php

class Mmocoin_model extends CI_Model
{
    public function __construct()
    {
        parent::__construct();
        $this->load->config('donate_mmo');
    }

    public function getPackages()
    {
        $query = $this->db->table('donate_mmo_packages')->get();
        if($query->getNumRows() > 0) {
            return $query->getResultArray();
        }
        return false;
    }

    public function getPackage($id)
    {
        $query = $this->db->table('donate_mmo_packages')->where('id', $id)->get();
        if($query->getNumRows() > 0) {
            return $query->getResultArray()[0];
        }
        return false;
    }

    public function addPackage($price, $points)
    {
        return $this->db->table('donate_mmo_packages')->insert([
            'price' => $price,
            'points' => $points
        ]);
    }

    public function updatePackage($id, $price, $points)
    {
        return $this->db->table('donate_mmo_packages')->where('id', $id)->update([
            'price' => $price,
            'points' => $points
        ]);
    }

    public function deletePackage($id)
    {
        return $this->db->table('donate_mmo_packages')->where('id', $id)->delete();
    }

    // Transactions

    public function createTransaction($userId, $mmoAmount, $dpAmount, $reference, $merchant, $mint, $income)
    {
        return $this->db->table('donate_mmo_transactions')->insert([
            'user_id' => $userId,
            'mmo_amount' => $mmoAmount,
            'dp_amount' => $dpAmount,
            'reference' => $reference,
            'merchant_wallet' => $merchant,
            'token_mint' => $mint,
            'income_amount' => $income,
            'created_at' => gmdate('Y-m-d H:i:s'),
            'expires_at' => gmdate('Y-m-d H:i:s', time() + 3600),
            'status' => 'pending'
        ]);
    }

    public function getTransaction($reference, $userId)
    {
        $query = $this->db->table('donate_mmo_transactions')
            ->where('reference', $reference)
            ->where('user_id', $userId)
            ->get();
            
        if($query->getNumRows() > 0) {
            return $query->getResultArray()[0];
        }
        return false;
    }

    public function completeTransaction(string $reference, int $userId, string $signature): bool
    {
        if (!$this->db->transBegin()) {
            return false;
        }
        try {
            $claimed = $this->db->table('donate_mmo_transactions')
                ->where('reference', $reference)->where('user_id', $userId)->where('status', 'pending')
                ->where('expires_at >', gmdate('Y-m-d H:i:s'))
                ->update(['status' => 'completed', 'transaction_signature' => $signature]);
            if (!$claimed || $this->db->affectedRows() !== 1) {
                $this->db->transRollback();
                return false;
            }
            $invoice = $this->getTransaction($reference, $userId);
            if (!$invoice || (int) $invoice['dp_amount'] <= 0 || $invoice['income_amount'] === null) {
                $this->db->transRollback();
                return false;
            }
            $this->db->query('UPDATE account_data SET dp = COALESCE(dp, 0) + ? WHERE id = ?', [(int) $invoice['dp_amount'], $userId]);
            if ($this->db->affectedRows() !== 1) {
                $this->db->transRollback();
                return false;
            }
            $month = gmdate('Y-m');
            $income = (int) $invoice['income_amount'];
            $monthly = $this->db->table('monthly_income')->where('month', $month)->get()->getRowArray();
            if ($monthly) {
                $this->db->query('UPDATE monthly_income SET amount = COALESCE(amount, 0) + ? WHERE month = ?', [$income, $month]);
            } else {
                $this->db->table('monthly_income')->insert(['month' => $month, 'amount' => $income]);
            }
            if (!$this->db->transStatus()) {
                $this->db->transRollback();
                return false;
            }
            return $this->db->transCommit();
        } catch (\Throwable $e) {
            $this->db->transRollback();
            return false;
        }
    }

    public function expireTransaction(string $reference, int $userId): void
    {
        $this->db->table('donate_mmo_transactions')->where('reference', $reference)->where('user_id', $userId)
            ->where('status', 'pending')->update(['status' => 'failed']);
    }
}
