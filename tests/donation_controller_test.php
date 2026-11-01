<?php

namespace MX {
    class MX_Controller
    {
        public $user;
        public $security;
        public $mmocoin_model;
    }
}
namespace App\Config {
    class Services
    {
        public static $response;
        public static $allowRequest = true;
        public static function response() {
            return self::$response ??= (new \ReflectionClass(\CodeIgniter\HTTP\Response::class))->newInstanceWithoutConstructor();
        }
        public static function throttler() { return new class { public function check(...$arguments) { return Services::$allowRequest; } }; }
    }
}
namespace {
    ob_start();
    define('MINUTE', 60);
    function requirePermission($permission) { if ($permission !== 'view') throw new \RuntimeException('Unexpected permission'); }
    require_once __DIR__ . '/../vendor/autoload.php';
    require_once __DIR__ . '/../application/modules/donate_mmo/controllers/Donate_mmo.php';
    class PaymentControllerFixture extends \Donate_mmo
    {
        public $rpcResult;
        public $rpcCalls = [];
        protected function rpc(string $method, array $params): mixed
        {
            $this->rpcCalls[] = [$method, $params];
            if ($this->rpcResult === false) throw new \RuntimeException('RPC unavailable');
            return $this->rpcResult[$method] ?? null;
        }
    }
    $reference = str_repeat('1', 32);
    $signature = str_repeat('2', 88);
    $invoice = [
        'reference' => $reference, 'status' => 'pending', 'mmo_amount' => '1',
        'merchant_wallet' => 'merchant', 'token_mint' => 'mint',
        'created_at' => gmdate('Y-m-d H:i:s', time() - 5), 'expires_at' => gmdate('Y-m-d H:i:s', time() + 3600),
    ];
    $rpc = [
        'getSignaturesForAddress' => [['signature' => $signature, 'err' => null, 'confirmationStatus' => 'finalized']],
        'getTransaction' => [
            'blockTime' => time() - 1,
            'transaction' => ['signatures' => [$signature], 'message' => ['accountKeys' => [
                ['pubkey' => $reference, 'signer' => false, 'writable' => false],
            ]]],
            'meta' => ['err' => null, 'preTokenBalances' => [], 'postTokenBalances' => [[
                'mint' => 'mint', 'owner' => 'merchant', 'programId' => \SolanaPaymentVerifier::TOKEN_PROGRAM,
                'uiTokenAmount' => ['amount' => '1000000000', 'decimals' => 9],
            ]]],
        ],
    ];
    $cases = [
        'valid payment' => ['success', 1],
        'GET request' => ['error', 0],
        'foreign invoice' => ['error', 0],
        'expired invoice' => ['error', 0],
        'legacy invoice' => ['error', 0],
        'completed invoice' => ['success', 0],
        'RPC unavailable' => ['pending', 0],
        'unfinalized signature' => ['pending', 0],
        'underpayment' => ['pending', 0],
        'missing transaction' => ['pending', 0],
        'credit failed' => ['pending', 1],
        'rate limited' => ['pending', 0],
    ];
    foreach ($cases as $name => [$expected, $completions]) {
        $current = $invoice;
        if ($name === 'expired invoice') $current['expires_at'] = gmdate('Y-m-d H:i:s', time() - 1);
        if ($name === 'legacy invoice') $current['merchant_wallet'] = null;
        if ($name === 'completed invoice') $current['status'] = 'completed';
        $model = new class($name === 'foreign invoice' ? false : $current, $name !== 'credit failed') {
            public $invoice;
            public $credit;
            public $completions = 0;
            public function __construct($invoice, $credit) { $this->invoice = $invoice; $this->credit = $credit; }
            public function getTransaction($reference, $user) {
                if ($user !== 7) throw new \RuntimeException('Owner ID missing from invoice lookup');
                return $this->invoice;
            }
            public function completeTransaction($reference, $user, $signature) { $this->completions++; return $this->credit; }
            public function expireTransaction(...$arguments) {}
        };
        $controller = (new \ReflectionClass(PaymentControllerFixture::class))->newInstanceWithoutConstructor();
        $controller->user = new class { public function getId() { return 7; } };
        $controller->security = new class { public function get_csrf_hash() { return 'renewed-token'; } };
        $controller->mmocoin_model = $model;
        $controller->rpcResult = $rpc;
        if ($name === 'RPC unavailable') $controller->rpcResult = false;
        if ($name === 'unfinalized signature') $controller->rpcResult['getSignaturesForAddress'][0]['confirmationStatus'] = 'confirmed';
        if ($name === 'underpayment') $controller->rpcResult['getTransaction']['meta']['postTokenBalances'][0]['uiTokenAmount']['amount'] = '999999999';
        if ($name === 'missing transaction') $controller->rpcResult['getTransaction'] = null;
        $_SERVER['REQUEST_METHOD'] = $name === 'GET request' ? 'GET' : 'POST';
        \App\Config\Services::$allowRequest = $name !== 'rate limited';
        \App\Config\Services::response()->setStatusCode(200);
        ob_start();
        $controller->verify_payment($reference);
        $response = json_decode(ob_get_clean(), true, 512, JSON_THROW_ON_ERROR);
        if ($response['status'] !== $expected || $model->completions !== $completions || $response['csrf_hash'] !== 'renewed-token') {
            throw new \RuntimeException("Controller failed: $name");
        }
        $expectedCode = ['GET request' => 405, 'foreign invoice' => 404, 'rate limited' => 429][$name] ?? 200;
        if (\App\Config\Services::response()->getStatusCode() !== $expectedCode
            || \App\Config\Services::response()->getHeaderLine('Content-Type') !== 'application/json'
            || \App\Config\Services::response()->getHeaderLine('Cache-Control') !== 'no-store') {
            throw new \RuntimeException("Incorrect HTTP response for: $name");
        }
        foreach ($controller->rpcCalls as [$method, $params]) {
            if ($params[1]['commitment'] !== 'finalized') throw new \RuntimeException('Finalized RPC commitment missing');
        }
        echo "PASS: controller handles $name without unintended credits\n";
    }
    ob_end_flush();
}
