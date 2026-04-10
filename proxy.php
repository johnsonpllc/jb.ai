<?php
/**
 * JB.AI — Secure Anthropic API Proxy
 * Hosted at: tmjpllc.com/jb.ai/proxy.php
 *
 * API key lives in config.php which is gitignored — never in this file.
 * Upload config.php to Hostinger manually once. Never commit it to Git.
 */

// ── LOAD API KEY ──
// config.php is gitignored and uploaded manually to Hostinger once
$config_file = __DIR__ . '/config.php';
if (!file_exists($config_file)) {
    http_response_code(500);
    die(json_encode(['error' => 'config.php not found. Upload it to public_html/jb.ai/config.php on Hostinger.']));
}
require_once $config_file;

$api_key = defined('ANTHROPIC_API_KEY') ? ANTHROPIC_API_KEY : '';
if (!$api_key || $api_key === 'sk-ant-YOUR_KEY_HERE') {
    http_response_code(500);
    die(json_encode(['error' => 'API key not set. Edit config.php and add your real Anthropic key.']));
}

// ── CORS — Only allow your domain ──
$allowed_origins = [
    'https://tmjpllc.com',
    'https://www.tmjpllc.com',
    'http://localhost',
    'http://localhost:3000',
    'http://127.0.0.1',
];

$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if (in_array($origin, $allowed_origins)) {
    header("Access-Control-Allow-Origin: $origin");
} else {
    http_response_code(403);
    die(json_encode(['error' => 'Forbidden origin']));
}

header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    die(json_encode(['error' => 'Method not allowed']));
}

// ── RATE LIMITING ──
session_start();
$now = time();
$window = 60;
$max_requests = 20;

if (!isset($_SESSION['rate_limit'])) {
    $_SESSION['rate_limit'] = ['count' => 0, 'start' => $now];
}
if ($now - $_SESSION['rate_limit']['start'] > $window) {
    $_SESSION['rate_limit'] = ['count' => 0, 'start' => $now];
}
$_SESSION['rate_limit']['count']++;

if ($_SESSION['rate_limit']['count'] > $max_requests) {
    http_response_code(429);
    die(json_encode(['error' => 'Rate limit exceeded. Please wait a moment.']));
}

// ── VALIDATE INPUT ──
$raw = file_get_contents('php://input');
if (!$raw) {
    http_response_code(400);
    die(json_encode(['error' => 'Empty request body']));
}

$body = json_decode($raw, true);
if (!$body || !isset($body['messages'])) {
    http_response_code(400);
    die(json_encode(['error' => 'Invalid request format']));
}

// ── SAFETY: Force model to claude-sonnet only ──
$allowed_models = [
    'claude-sonnet-4-20250514',
    'claude-haiku-4-5-20251001',
];
if (!isset($body['model']) || !in_array($body['model'], $allowed_models)) {
    $body['model'] = 'claude-sonnet-4-20250514';
}

// ── CAP max_tokens ──
if (!isset($body['max_tokens']) || $body['max_tokens'] > 2000) {
    $body['max_tokens'] = 1000;
}

// ── FORWARD TO ANTHROPIC ──
$ch = curl_init('https://api.anthropic.com/v1/messages');

curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => json_encode($body),
    CURLOPT_HTTPHEADER     => [
        'Content-Type: application/json',
        'x-api-key: ' . $api_key,
        'anthropic-version: 2023-06-01',
        'anthropic-beta: mcp-client-2025-04-04',
    ],
    CURLOPT_TIMEOUT        => 60,
    CURLOPT_SSL_VERIFYPEER => true,
]);

$response = curl_exec($ch);
$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curl_error = curl_error($ch);
curl_close($ch);

if ($curl_error) {
    http_response_code(502);
    die(json_encode(['error' => 'Connection to AI service failed. Please try again.']));
}

http_response_code($http_code);
echo $response;
