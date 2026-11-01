<?php
require_once __DIR__ . '/../config.php';
header('Content-Type: application/json');

try {
    if (!isLoggedIn()) {
        http_response_code(401);
        echo json_encode(['error' => 'Unauthorized']);
        exit;
    }
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['error' => 'Method not allowed']);
        exit;
    }

    $uid = (int)($_SESSION['user_id'] ?? 0);
    // Accept JSON or form-encoded
    $payload = [];
    if (!empty($_SERVER['CONTENT_TYPE']) && stripos($_SERVER['CONTENT_TYPE'], 'application/json') !== false) {
        $raw = file_get_contents('php://input');
        $payload = json_decode($raw, true) ?: [];
    } else {
        $payload = $_POST;
    }

    $name = trim($payload['name'] ?? '');
    $phone = trim($payload['phone'] ?? '');
    $address = trim($payload['address'] ?? '');

    if ($name === '') {
        http_response_code(422);
        echo json_encode(['error' => 'Name is required']);
        exit;
    }
    if ($phone !== '' && !preg_match('/^[0-9 +()-]{6,20}$/', $phone)) {
        http_response_code(422);
        echo json_encode(['error' => 'Invalid phone number']);
        exit;
    }

    $stmt = $pdo->prepare('UPDATE users SET name = ?, phone = ?, address = ? WHERE id = ?');
    $stmt->execute([$name, $phone ?: null, $address ?: null, $uid]);

    echo json_encode(['status' => 'ok']);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Server error']);
}
