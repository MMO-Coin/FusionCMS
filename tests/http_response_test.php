<?php

namespace CodeIgniter\HTTP {
    function headers_sent(): bool { return false; }
    function header(string $value, bool $replace = true, int $status = 0): void { $GLOBALS['response_headers'][] = $value; }
}
namespace {
    require_once __DIR__ . '/../vendor/autoload.php';
    require_once __DIR__ . '/../application/config/Services.php';
    $shared = \App\Config\Services::response();
    $shared->setStatusCode(405);
    if ($shared !== \App\Config\Services::response() || \App\Config\Services::response()->getStatusCode() !== 405
        || $shared === \App\Config\Services::response(false)) {
        throw new \RuntimeException('Controllers and the output pipeline must share the response.');
    }
    echo "PASS: response service shares controller status and supports explicit fresh instances\n";
    $server = $_SERVER;
    $_SERVER['CONTENT_TYPE'] = 'application/x-www-form-urlencoded';
    $_SERVER['HTTP_CONTENT_LENGTH'] = '61';
    $_SERVER['HTTP_COOKIE'] = 'session=test-only-cookie';
    $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer test-only-token';
    $empty = new \CodeIgniter\HTTP\Response();
    $empty->removeHeader('Cache-Control');
    if ($empty->headers() !== [] || $empty->getHeaders() !== []) {
        throw new \RuntimeException('An empty response imported incoming request headers.');
    }
    $_SERVER = $server;
    echo "PASS: empty response headers do not import request content type, length or credentials\n";
    foreach ([[null, 200, 'HTTP/1.1 200 OK'], [null, 403, 'HTTP/1.1 403 Forbidden'], ['1.0', 200, 'HTTP/1.0 200 OK']] as [$version, $status, $expected]) {
        $GLOBALS['response_headers'] = [];
        $response = (new \ReflectionClass(\CodeIgniter\HTTP\Response::class))->newInstanceWithoutConstructor();
        $response->setStatusCode($status);
        if ($version !== null) $response->setProtocolVersion($version);
        $response->sendHeaders();
        if (($GLOBALS['response_headers'][0] ?? null) !== $expected) throw new \RuntimeException('Invalid outgoing HTTP status line');
        echo "PASS: $expected\n";
    }
}
