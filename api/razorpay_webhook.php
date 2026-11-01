<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../payment_config.php';

// Razorpay webhook: verify signature and update transactions.
// Set the endpoint URL in Razorpay dashboard and set RAZORPAY_WEBHOOK_SECRET.

http_response_code(200);
header('Content-Type: application/json');

if (!RAZORPAY_ENABLED) {
    echo json_encode(['ok' => false, 'error' => 'Razorpay disabled']);
    exit;
}

$raw = file_get_contents('php://input');
$signature = $_SERVER['HTTP_X_RAZORPAY_SIGNATURE'] ?? '';
$event_type = $_SERVER['HTTP_X_RAZORPAY_EVENT'] ?? '';

if (!$signature || !$raw) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Missing signature/body']);
    exit;
}

$expected = hash_hmac('sha256', $raw, RAZORPAY_WEBHOOK_SECRET);
if (!hash_equals($expected, $signature)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Invalid signature']);
    exit;
}

$payload = json_decode($raw, true);
if (!is_array($payload)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Invalid JSON']);
    exit;
}

// Handle common events: payment.captured (authoritative), order.paid (optional)
$handled = false;
try {
    if (($payload['event'] ?? '') === 'payment.captured') {
        $payment = $payload['payload']['payment']['entity'] ?? [];
        $payment_id = $payment['id'] ?? '';
        $order_id = $payment['order_id'] ?? '';
        $amount = isset($payment['amount']) ? ((int)$payment['amount']) / 100.0 : null;

        if ($payment_id) {
            $pdo->beginTransaction();
            // Find existing tx by payment or order
            $stmt = $pdo->prepare("SELECT * FROM transactions WHERE razorpay_payment_id = ? OR razorpay_order_id = ? LIMIT 1");
            $stmt->execute([$payment_id, $order_id]);
            $tx = $stmt->fetch();

            if ($tx) {
                // Idempotent update
                $upd = $pdo->prepare("UPDATE transactions SET razorpay_payment_id = COALESCE(razorpay_payment_id, ?), payment_status = 'paid', status = IF(status IN ('refunded','released'), status, 'pending') WHERE id = ?");
                $upd->execute([$payment_id, $tx['id']]);
            } else {
                // If no tx row (edge), we cannot safely create without product/buyer context.
                // Store a minimal placeholder only if order_id is known in our orders (not implemented). For now, ignore.
            }
            $pdo->commit();
            $handled = true;
        }
    } elseif (($payload['event'] ?? '') === 'order.paid') {
        // Optional: ensure order is marked as paid
        $order = $payload['payload']['order']['entity'] ?? [];
        $order_id = $order['id'] ?? '';
        if ($order_id) {
            $stmt = $pdo->prepare("UPDATE transactions SET payment_status='paid' WHERE razorpay_order_id = ?");
            $stmt->execute([$order_id]);
            $handled = true;
        }
    }
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Server error']);
    exit;
}

echo json_encode(['ok' => true, 'handled' => $handled, 'event' => $payload['event'] ?? null]);
