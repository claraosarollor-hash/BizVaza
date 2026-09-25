<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/paystack.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

validate_csrf();

$user_id = (int)($_SESSION['user_id'] ?? 0);
if (!$user_id) {
    http_response_code(401);
    echo json_encode(['error' => 'Not authenticated']);
    exit;
}

$conf = paystack_settings($pdo);
if (!paystack_configured($pdo)) {
    echo json_encode(['error' => 'Payment gateway not configured']);
    exit;
}

// Plan is always the Pro plan from the plans table (kept dynamic).
$plan = getProPlan($pdo);
if (!$plan || (float)$plan['price'] <= 0) {
    echo json_encode(['error' => 'Invalid plan']);
    exit;
}
$plan_id = (int)$plan['id'];

// Client sends plan_id defensively; ignore if it disagrees with the Pro plan.
$req_plan_id = (int)($_POST['plan_id'] ?? 0);

// Unique reference — entropy removes same-second collision risk.
$reference = 'BF-' . $user_id . '-' . bin2hex(random_bytes(6));

$amount = (int)round((float)$plan['price'] * 100);

$result = paystack_api('POST', 'transaction/initialize', $conf['secret'], [
    'email' => $_SESSION['email'] ?? '',
    'amount' => $amount,
    'reference' => $reference,
    'currency' => 'NGN',
    'callback_url' => site_url() . '/upgrade-success.php',
    'metadata' => [
        'user_id' => $user_id,
        'plan_id' => $plan_id,
        'plan_name' => $plan['name'],
        'custom_fields' => [
            [
                'display_name' => 'User ID',
                'variable_name' => 'user_id',
                'value' => $user_id,
            ],
            [
                'display_name' => 'Plan',
                'variable_name' => 'plan_name',
                'value' => $plan['name'],
            ]
        ]
    ],
]);

if ($result['errno'] !== 0) {
    echo json_encode(['error' => 'Payment initialization failed. Please try again.']);
    exit;
}

$body = $result['data'];
if ($result['code'] !== 200 || !is_array($body) || ($body['status'] ?? false) !== true) {
    echo json_encode(['error' => is_array($body) ? ($body['message'] ?? 'Payment initialization failed') : 'Payment initialization failed']);
    exit;
}

// Store pending payment record (idempotency guard on reference).
try {
    $stmt = $pdo->prepare("INSERT INTO subscription_payments (user_id, reference, amount, method, status, paystack_reference) VALUES (?, ?, ?, 'paystack', 'pending', ?)");
    $stmt->execute([$user_id, $reference, $plan['price'], $reference]);
} catch (PDOException $e) {
    if ($e->getCode() === '23000') {
        echo json_encode(['error' => 'Please try again.']);
        exit;
    }
    throw $e;
}

echo json_encode([
    'success' => true,
    'authorization_url' => $body['data']['authorization_url'] ?? '',
    'reference' => $reference,
]);