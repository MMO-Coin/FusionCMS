<?php

// Exercise the real CSRF implementation over HTTP, including browser cookie flags.
if (PHP_SAPI === 'cli-server') {
    define('BASEPATH', __DIR__ . '/../system/');
    function config_item($key) {
        return [
            'csrf_protection' => true, 'csrf_expire' => 7200,
            'csrf_token_name' => 'csrf_token_name', 'csrf_cookie_name' => 'csrf_cookie_name',
            'csrf_regenerate' => true, 'csrf_exclude_uris' => [], 'charset' => 'UTF-8',
            'cookie_secure' => false, 'cookie_httponly' => true,
            'cookie_path' => '/', 'cookie_domain' => '', 'cookie_prefix' => '',
        ][$key] ?? null;
    }
    function is_php($version) { return version_compare(PHP_VERSION, $version, '>='); }
    function log_message($level, $message) {}
    function show_error($message, $status) {
        http_response_code($status);
        exit(json_encode(['error' => $message]));
    }
    session_name('fusion_session');
    session_start(['cookie_httponly' => true, 'cookie_samesite' => 'Strict']);
    require BASEPATH . 'core/Security.php';
    $security = new CI_Security();
    $security->csrf_verify();
    if (parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) === '/response') {
        require_once __DIR__ . '/../vendor/autoload.php';
        require_once __DIR__ . '/../application/config/Services.php';
        $response = App\Config\Services::response();
        $response->removeHeader('Cache-Control');
        $response->setStatusCode(200)->setBody('<!DOCTYPE html><html><body>' . str_repeat('Checkout response. ', 200) . '</body></html>')->send();
        return;
    }
    header('Content-Type: application/json');
    echo json_encode(['token' => $security->get_csrf_hash()]);
    return;
}

function check(bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
}

function request(string $url, ?array $post = null, string $cookie = ''): array {
    $headers = [];
    $handle = curl_init($url);
    curl_setopt_array($handle, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 5,
        CURLOPT_COOKIE => $cookie,
        CURLOPT_HTTPHEADER => ['X-Request-Only: do-not-reflect', 'Authorization: Bearer test-only-token'],
        CURLOPT_HEADERFUNCTION => static function ($handle, $header) use (&$headers) {
            $headers[] = trim($header);
            return strlen($header);
        },
    ]);
    if ($post !== null) curl_setopt_array($handle, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => http_build_query($post)]);
    $body = curl_exec($handle);
    return [curl_getinfo($handle, CURLINFO_RESPONSE_CODE), json_decode((string) $body, true), $headers, (string) $body];
}

$socket = stream_socket_server('tcp://127.0.0.1:0', $error, $message);
check($socket !== false, 'Cannot allocate a local HTTP test port.');
$address = stream_socket_get_name($socket, false);
fclose($socket);
$log = tempnam(sys_get_temp_dir(), 'fusion-csrf-');
$process = proc_open([PHP_BINARY, '-S', $address, __FILE__], [0 => ['pipe', 'r'], 1 => ['file', $log, 'a'], 2 => ['file', $log, 'a']], $pipes);
check(is_resource($process), 'Cannot start the local HTTP test server.');
fclose($pipes[0]);
try {
    $ready = false;
    for ($attempt = 0; $attempt < 100; $attempt++) {
        $probe = @stream_socket_client('tcp://' . $address, $error, $message, 0.05);
        if ($probe) { fclose($probe); $ready = true; break; }
        usleep(50000);
    }
    check($ready, 'The local HTTP test server did not start.');
    [$status, $body, $headers] = request('http://' . $address);
    check($status === 200 && preg_match('/^[a-f0-9]{32}$/D', $body['token'] ?? ''), 'GET must issue a valid CSRF nonce.');
    $csrfCookie = $sessionCookie = '';
    foreach ($headers as $header) {
        if (stripos($header, 'Set-Cookie: csrf_cookie_name=') === 0) $csrfCookie = $header;
        if (stripos($header, 'Set-Cookie: fusion_session=') === 0) $sessionCookie = $header;
    }
    check($csrfCookie !== '' && stripos($csrfCookie, 'HttpOnly') === false, 'AJAX must be able to read the CSRF cookie.');
    check(stripos($csrfCookie, 'SameSite=Strict') !== false, 'CSRF cookie must keep SameSite=Strict.');
    check($sessionCookie !== '' && stripos($sessionCookie, 'HttpOnly') !== false, 'Session cookies must remain HttpOnly.');
    $cookie = 'csrf_cookie_name=' . $body['token'];
    [$status, $next] = request('http://' . $address, ['csrf_token_name' => $body['token']], $cookie);
    check($status === 200 && $next['token'] !== $body['token'], 'Valid POST must pass and rotate the nonce.');
    [$status] = request('http://' . $address, [], $cookie);
    check($status === 403, 'POST without a CSRF nonce must fail.');
    [$status] = request('http://' . $address, ['csrf_token_name' => str_repeat('0', 32)], $cookie);
    check($status === 403, 'POST with the wrong CSRF nonce must fail.');
    echo "PASS: CSRF cookie flags, session HttpOnly, valid POST, rotation, missing and incorrect nonce\n";
    [$status, , $headers, $html] = request('http://' . $address . '/response', ['csrf_token_name' => $next['token']], 'csrf_cookie_name=' . $next['token']);
    check($status === 200 && strlen($html) > 3000 && str_ends_with($html, '</body></html>'), 'A form POST must return the complete HTML response.');
    $contentType = '';
    foreach ($headers as $header) {
        if (stripos($header, 'Content-Type:') === 0) $contentType = $header;
        check(!preg_match('/^(Cookie|Authorization|X-Request-Only|Origin|User-Agent):/i', $header), 'Request headers must not be reflected in the HTTP response.');
    }
    check(stripos($contentType, 'text/html') !== false, 'HTML responses must not inherit a form POST content type.');
    echo "PASS: complete HTML POST response keeps its MIME type and does not reflect request headers\n";
} finally {
    proc_terminate($process);
    proc_close($process);
    unlink($log);
}
