<?php

declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', '0'); // never leak stack traces to clients

$root = dirname(__DIR__);

require_once $root . '/config/config.php';
require_once $root . '/services/Logger.php';
require_once $root . '/services/RateLimiter.php';
require_once $root . '/services/HttpClient.php';
require_once $root . '/services/DataEnvelope.php';
require_once $root . '/services/ProviderFactory.php';
require_once $root . '/services/RiskEngine.php';

Config::load();

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

// --- CORS ---
$allowedOrigins = array_map('trim', explode(',', (string) Config::get('CORS_ALLOWED_ORIGINS', '')));
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if (in_array('*', $allowedOrigins, true)) {
    header('Access-Control-Allow-Origin: *');
} elseif ($origin !== '' && in_array($origin, $allowedOrigins, true)) {
    header('Access-Control-Allow-Origin: ' . $origin);
    header('Vary: Origin');
}
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

function json_fail(int $httpStatus, string $code, string $message): never
{
    http_response_code($httpStatus);
    echo json_encode([
        'ok' => false,
        'error' => ['code' => $code, 'message' => $message],
    ]);
    exit;
}

function json_ok(array $data): never
{
    http_response_code(200);
    echo json_encode(['ok' => true] + $data, JSON_UNESCAPED_SLASHES);
    exit;
}

/** Read + validate a float query/body param within Philippine-ish bounds. */
function require_float(array $src, string $key, float $min, float $max): float
{
    if (!isset($src[$key]) || !is_numeric($src[$key])) {
        json_fail(400, 'INVALID_PARAM', "Missing or invalid parameter: {$key}");
    }
    $v = (float) $src[$key];
    if ($v < $min || $v > $max) {
        json_fail(400, 'INVALID_PARAM', "Parameter {$key} out of range");
    }
    return $v;
}

function require_string(array $src, string $key, int $maxLen = 200): string
{
    if (!isset($src[$key]) || !is_string($src[$key]) || trim($src[$key]) === '') {
        json_fail(400, 'INVALID_PARAM', "Missing or invalid parameter: {$key}");
    }
    $v = trim($src[$key]);
    $len = function_exists('mb_strlen') ? mb_strlen($v) : strlen($v);
    if ($len > $maxLen) {
        json_fail(400, 'INVALID_PARAM', "Parameter {$key} is too long");
    }
    // Strip control characters; keep it a plain-text query.
    return preg_replace('/[\x00-\x1F\x7F]/u', '', $v) ?? '';
}

function enforce_rate_limit(string $bucket): void
{
    if (!RateLimiter::check($bucket)) {
        Logger::warning('Rate limit exceeded', ['bucket' => $bucket]);
        json_fail(429, 'RATE_LIMITED', 'Too many requests. Please slow down and try again shortly.');
    }
}

set_exception_handler(function (Throwable $e): void {
    Logger::error('Unhandled exception', ['message' => $e->getMessage(), 'file' => $e->getFile(), 'line' => $e->getLine()]);
    json_fail(500, 'INTERNAL_ERROR', 'Something went wrong on our end. Please try again.');
});

/** Merge query string + JSON body into one associative array. */
function request_params(): array
{
    $params = $_GET;
    $raw = file_get_contents('php://input');
    if ($raw !== false && $raw !== '') {
        $body = json_decode($raw, true);
        if (is_array($body)) {
            $params = array_merge($params, $body);
        }
    }
    return $params;
}
