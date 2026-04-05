<?php
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// Do not print PHP warnings/notices into API response
ini_set('display_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);

// Helper to always return JSON and stop
function respond(int $status, array $payload): void {
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES);
    exit();
}

// Handle preflight
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    respond(200, ['success' => true]);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond(405, ['success' => false, 'message' => 'Only POST allowed']);
}

define('CHIP_API_KEY', 'SlPBAn2NqZQgQXvkJ9rcTwIXB3ozMvDtSz5dOq2By44ezFChEkcSmHuvqBnYcT8KtVOx22mp5NrFyFDlXoLgMg==');
define('CHIP_BRAND_ID', '1424d2cf-0e77-491c-b498-638d5afda5b1');

// Read and validate JSON input
$raw = file_get_contents('php://input');
$input = json_decode($raw, true);

if (!is_array($input)) {
    respond(400, ['success' => false, 'message' => 'Invalid JSON body']);
}

$email       = isset($input['email'])       ? trim((string)$input['email'])       : '';
$amount      = isset($input['amount'])      ? (int)$input['amount']               : 0; // in sen
$description = isset($input['description']) ? trim((string)$input['description']) : 'Bahanza Order';
$seller_id   = isset($input['seller_id'])   ? trim((string)$input['seller_id'])   : '';
$product_id  = isset($input['product_id'])  ? trim((string)$input['product_id'])  : '';
$buyer_id    = isset($input['buyer_id'])    ? trim((string)$input['buyer_id'])     : '';

if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || $amount <= 0) {
    respond(400, ['success' => false, 'message' => 'Invalid email or amount']);
}

// Build Chip API request
$payload = [
    'purchase' => [
        'currency' => 'MYR',
        'products' => [[
            'name' => $description,
            'quantity' => 1,
            'price' => $amount, // in sen
        ]],
    ],
    'brand_id' => CHIP_BRAND_ID,
    'client' => [
        'email' => $email,
    ],
    'reference' => implode('|', array_filter([
        $seller_id  !== '' ? 'seller:'  . $seller_id  : '',
        $product_id !== '' ? 'product:' . $product_id : '',
        $buyer_id   !== '' ? 'buyer:'   . $buyer_id   : '',
    ])),
    'success_redirect' => 'https://example.com/success',
    'failure_redirect' => 'https://example.com/failed',
];

$ch = curl_init('https://gate.chip-in.asia/api/v1/purchases/');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => json_encode($payload),
    CURLOPT_HTTPHEADER => [
        'Authorization: Bearer ' . CHIP_API_KEY,
        'Content-Type: application/json',
        'Accept: application/json',
    ],
    CURLOPT_CONNECTTIMEOUT => 15,
    CURLOPT_TIMEOUT => 30,
    CURLOPT_SSL_VERIFYPEER => true,
    CURLOPT_SSL_VERIFYHOST => 2,
]);

$response = curl_exec($ch);
$httpStatus = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlError = curl_error($ch);
curl_close($ch);

if ($curlError) {
    respond(502, ['success' => false, 'message' => 'Network error: ' . $curlError]);
}

$data = json_decode((string)$response, true);
if (!is_array($data)) {
    respond(502, [
        'success' => false,
        'message' => 'Invalid response from Chip API',
        'http_status' => $httpStatus,
    ]);
}

// Chip usually returns 201 for created purchase
if ($httpStatus === 201 && !empty($data['checkout_url'])) {
    respond(200, [
        'success'     => true,
        'checkout_url' => $data['checkout_url'],
        'purchase_id' => $data['id'] ?? '',
    ]);
}

$errorMsg = $data['detail']
    ?? $data['message']
    ?? ('Chip API error (HTTP ' . $httpStatus . ')');

respond(200, ['success' => false, 'message' => $errorMsg]);