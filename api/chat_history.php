<?php
require_once __DIR__ . '/../config.php';
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$negotiationId = (int)($_GET['negotiation_id'] ?? 0);
$limit = (int)($_GET['limit'] ?? 50);
$limit = max(1, min(200, $limit));

if (!$negotiationId) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing negotiation_id']);
    exit;
}

try {
    $stmt = $pdo->prepare('SELECT m.id, m.sender_id, m.message, m.created_at, u.name AS sender_name
                           FROM messages m
                           JOIN users u ON m.sender_id = u.id
                           WHERE m.negotiation_id = ?
                           ORDER BY m.id DESC
                           LIMIT ?');
    $stmt->bindValue(1, $negotiationId, PDO::PARAM_INT);
    $stmt->bindValue(2, $limit, PDO::PARAM_INT);
    $stmt->execute();
    $rows = $stmt->fetchAll();
    // Reverse to chronological ASC
    $rows = array_reverse($rows);
    echo json_encode(['messages' => $rows]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => 'DB error']);
}
