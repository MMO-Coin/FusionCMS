<?php

use App\Config\Services;
use MX\MX_Controller;

require_once __DIR__ . '/../libraries/SolanaPaymentVerifier.php';

class Donate_mmo extends MX_Controller
{
    public function __construct()
    {
        parent::__construct();

        $this->user->userArea();

        $this->load->config('donate_mmo');
        $this->load->model('mmocoin_model');
    }

    public function index()
    {
        requirePermission("view");
        
        $this->template->setTitle("MMOCoin Donation");

        $data = [
            'packages' => $this->mmocoin_model->getPackages(),
            'server_name' => $this->config->item('server_name'),
            'currency' => 'MMO',
            'token_mint' => (string) $this->config->item('mmo_coin_mint'),
            'csrf_name' => $this->security->get_csrf_token_name(),
            'csrf_hash' => $this->security->get_csrf_hash(),
        ];

        $output = $this->template->loadPage("index.tpl", $data);

        $pageData = [
            "module" => "default",
            "headline" => breadcrumb([
                "ucp" => lang("ucp"),
                "donate" => lang("donate_panel", "donate")
            ]),
            "content" => $output
        ];

        $page = $this->template->loadPage("page.tpl", $pageData);

        $this->template->view($page);
    }

    public function checkout($package_id = null)
    {
        requirePermission("view");
        if (strtoupper($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            show_error('Use the donation package form to create an invoice.', 405);
            return;
        }
        $packageId = filter_var($package_id ?? $this->input->post('package_id'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $package = $packageId ? $this->mmocoin_model->getPackage($packageId) : false;
        if (!$package) {
            show_error('Donation package not found.', 404);
            return;
        }
        $merchant = (string) $this->config->item('merchant_wallet');
        $mint = (string) $this->config->item('mmo_coin_mint');
        try {
            $amount = SolanaPaymentVerifier::amount((string) $package['price']);
            $points = filter_var($package['points'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 2147483647]]);
            $income = SolanaPaymentVerifier::income($amount, (string) $this->config->item('exchange_rate'));
            if (!$points || !SolanaPaymentVerifier::validAddress($merchant) || !SolanaPaymentVerifier::validAddress($mint)) {
                throw new InvalidArgumentException('Invalid donation configuration.');
            }
        } catch (InvalidArgumentException $e) {
            show_error('MMOCoin donations are not configured correctly. Please contact the server administrator.', 503);
            return;
        }
        $reference = $this->generateBase58Reference();
        $userId = (int) $this->user->getId();
        if (!$this->mmocoin_model->createTransaction($userId, $amount, $points, $reference, $merchant, $mint, $income)) {
            show_error('Unable to create a donation invoice. Please try again.', 503);
            return;
        }
        $invoice = $this->mmocoin_model->getTransaction($reference, $userId);
        $package['price'] = $amount;
        $solanaPayUrl = 'solana:' . $merchant . '?' . http_build_query([
            'amount' => $amount, 'spl-token' => $mint, 'reference' => $reference,
            'label' => $this->config->item('server_name'), 'message' => 'Donation',
        ], '', '&', PHP_QUERY_RFC3986);
        $output = $this->template->loadPage('checkout.tpl', [
            'package' => $package, 'reference' => $reference, 'merchant' => $merchant,
            'solana_pay_url' => $solanaPayUrl, 'expires_at' => strtotime($invoice['expires_at'] . ' UTC'),
            'qr_code' => (new \chillerlan\QRCode\QRCode())->render($solanaPayUrl),
            'csrf_name' => $this->security->get_csrf_token_name(), 'csrf_hash' => $this->security->get_csrf_hash(),
        ]);
        $page = $this->template->loadPage('page.tpl', [
            'module' => 'default', 'headline' => breadcrumb(['ucp' => lang('ucp'), 'donate' => lang('donate_panel', 'donate')]),
            'content' => $output,
        ]);
        $this->template->view($page, false, 'modules/donate_mmo/js/checkout.js');
    }

    public function verify_payment($reference = '')
    {
        requirePermission('view');
        Services::response()->setHeader('Content-Type', 'application/json')->setHeader('Cache-Control', 'no-store');
        if (strtoupper($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            Services::response()->setStatusCode(405);
            $this->paymentResponse(['status' => 'error', 'message' => 'Use POST to check payment status.']);
            return;
        }
        $userId = (int) $this->user->getId();
        $invoice = SolanaPaymentVerifier::validAddress((string) $reference) ? $this->mmocoin_model->getTransaction($reference, $userId) : false;
        if (!$invoice) {
            Services::response()->setStatusCode(404);
            $this->paymentResponse(['status' => 'error', 'message' => 'Invoice not found.']);
            return;
        }
        if ($invoice['status'] === 'completed') {
            $this->paymentResponse(['status' => 'success']);
            return;
        }
        if ($invoice['status'] !== 'pending' || empty($invoice['expires_at'])
            || empty($invoice['merchant_wallet']) || empty($invoice['token_mint'])
            || time() >= strtotime($invoice['expires_at'] . ' UTC')) {
            $this->mmocoin_model->expireTransaction($reference, $userId);
            $this->paymentResponse(['status' => 'error', 'message' => 'Invoice expired. Please create a new invoice.']);
            return;
        }
        if (!Services::throttler()->check('donate_mmo_verify_' . $userId, 20, MINUTE)) {
            Services::response()->setStatusCode(429);
            $this->paymentResponse(['status' => 'pending']);
            return;
        }
        try {
            $signatures = $this->rpc('getSignaturesForAddress', [$reference, ['limit' => 5, 'commitment' => 'finalized']]);
            foreach (is_array($signatures) ? $signatures : [] as $candidate) {
                $signature = $candidate['signature'] ?? '';
                if (!is_string($signature) || !preg_match('/^[1-9A-HJ-NP-Za-km-z]{64,88}$/D', $signature)
                    || !array_key_exists('err', $candidate) || $candidate['err'] !== null
                    || ($candidate['confirmationStatus'] ?? '') !== 'finalized') {
                    continue;
                }
                $tx = $this->rpc('getTransaction', [$signature, [
                    'encoding' => 'jsonParsed', 'maxSupportedTransactionVersion' => 0, 'commitment' => 'finalized',
                ]]);
                if (is_array($tx) && SolanaPaymentVerifier::verifies($tx, $invoice, $signature)
                    && $this->mmocoin_model->completeTransaction($reference, $userId, $signature)) {
                    $this->paymentResponse(['status' => 'success']);
                    return;
                }
            }
        } catch (RuntimeException $e) {
            // An unavailable or malformed RPC response never grants points.
        }
        $this->paymentResponse(['status' => 'pending']);
    }

    protected function rpc(string $method, array $params): mixed
    {
        $url = (string) $this->config->item('rpc_url');
        if (strtolower((string) parse_url($url, PHP_URL_SCHEME)) !== 'https') {
            throw new RuntimeException('The RPC endpoint must use HTTPS.');
        }
        $handle = curl_init($url);
        curl_setopt_array($handle, [
            CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_POST => true, CURLOPT_CONNECTTIMEOUT => 2, CURLOPT_TIMEOUT => 5,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS, CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_POSTFIELDS => json_encode(['jsonrpc' => '2.0', 'id' => 1, 'method' => $method, 'params' => $params]),
        ]);
        $body = curl_exec($handle);
        $status = curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $response = is_string($body) ? json_decode($body, true) : null;
        if ($status !== 200 || !is_array($response) || isset($response['error']) || !array_key_exists('result', $response)) {
            throw new RuntimeException('The RPC response is unavailable.');
        }
        return $response['result'];
    }

    private function paymentResponse(array $response): void
    {
        $response['csrf_hash'] = $this->security->get_csrf_hash();
        echo json_encode($response);
    }

    public function success()
    {
        $this->user->getUserData();
        $page = $this->template->loadPage("success.tpl", ['url' => $this->template->page_url]);
        $this->template->box(lang("donate_thanks", "donate"), $page, true);
    }

    private function generateBase58Reference()
    {
        // Quick Base58 encode mapping
        $alphabet = '123456789ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz';
        // generating 32 random bytes
        $bytes = random_bytes(32);
        
        $base58 = '';
        $num = gmp_import($bytes);
        $zero = gmp_init(0);
        $fifty_eight = gmp_init(58);

        while (gmp_cmp($num, $zero) > 0) {
            list($num, $rem) = gmp_div_qr($num, $fifty_eight);
            $base58 = $alphabet[gmp_intval($rem)] . $base58;
        }

        // leading zeros
        for ($i = 0; $i < 32 && ord($bytes[$i]) === 0; $i++) {
            $base58 = '1' . $base58;
        }

        return $base58;
    }
}
