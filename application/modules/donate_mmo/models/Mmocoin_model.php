<?php

class Mmocoin_model extends CI_Model
{
    public function __construct()
    {
        parent::__construct();
        $this->load->config('donate_mmo');
        $this->load->model('donate/donate_model', 'donate_core');
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

    public function createTransaction($userId, $mmoAmount, $dpAmount, $reference)
    {
        return $this->db->table('donate_mmo_transactions')->insert([
            'user_id' => $userId,
            'mmo_amount' => $mmoAmount,
            'dp_amount' => $dpAmount,
            'reference' => $reference,
            'status' => 'pending'
        ]);
    }

    public function getPendingTransaction($reference)
    {
        $query = $this->db->table('donate_mmo_transactions')
            ->where('reference', $reference)
            ->where('status', 'pending')
            ->get();
            
        if($query->getNumRows() > 0) {
            return $query->getResultArray()[0];
        }
        return false;
    }

    public function updateTransactionStatus($reference, $status, $signature = null)
    {
        $data = ['status' => $status];
        if($signature) {
            $data['transaction_signature'] = $signature;
        }

        return $this->db->table('donate_mmo_transactions')
            ->where('reference', $reference)
            ->update($data);
    }
}
