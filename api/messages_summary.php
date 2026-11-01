<?php
require_once __DIR__ . '/../config.php';
header('Content-Type: application/json');

try {
    if (!isLoggedIn()) {
        http_response_code(401);
        echo json_encode(['error' => 'Unauthorized']);
        exit;
    }
    $userId = (int)($_SESSION['user_id'] ?? 0);
    $role = getUserRole();
    $limit = (int)($_GET['limit'] ?? 10);
    $limit = max(1, min(30, $limit));
    $offset = (int)($_GET['offset'] ?? 0);
    $offset = max(0, $offset);
    $unreadOnly = isset($_GET['unread']) && (int)$_GET['unread'] === 1;
    // Ensure read-tracking table exists to avoid errors in subquery
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS negotiation_reads (
          negotiation_id INT NOT NULL,
          user_id INT NOT NULL,
          last_read_at DATETIME NOT NULL DEFAULT '1970-01-01 00:00:00',
          PRIMARY KEY (negotiation_id, user_id),
          FOREIGN KEY (negotiation_id) REFERENCES negotiations(id) ON DELETE CASCADE,
          FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
    } catch (Throwable $e) { /* ignore */ }

    // Recent negotiations involving this user (buyer or seller)
    $sql = "SELECT n.id AS negotiation_id, n.product_id, n.buyer_id, n.seller_id,
                   p.title AS product_title,
                   CASE WHEN n.buyer_id = :uid1 THEN su.name ELSE bu.name END AS other_name,
                   (SELECT m.message FROM messages m WHERE m.negotiation_id = n.id ORDER BY m.id DESC LIMIT 1) AS last_message,
                   (SELECT m.created_at FROM messages m WHERE m.negotiation_id = n.id ORDER BY m.id DESC LIMIT 1) AS last_time,
                   (
                     SELECT COUNT(*) FROM messages m2
                     WHERE m2.negotiation_id = n.id
                       AND m2.sender_id <> :uid2
                       AND m2.created_at > COALESCE((SELECT r.last_read_at FROM negotiation_reads r WHERE r.negotiation_id = n.id AND r.user_id = :uid2), '1970-01-01')
                   ) AS unread_count
            FROM negotiations n
            JOIN products p ON p.id = n.product_id
            JOIN users bu ON bu.id = n.buyer_id
            JOIN users su ON su.id = n.seller_id
            WHERE (n.buyer_id = :uid1 OR n.seller_id = :uid1)
            ORDER BY COALESCE((SELECT m.created_at FROM messages m WHERE m.negotiation_id = n.id ORDER BY m.id DESC LIMIT 1), n.id) DESC
            LIMIT :lim OFFSET :off";

    $stmt = $pdo->prepare($sql);
    $stmt->bindValue(':uid1', $userId, PDO::PARAM_INT);
    $stmt->bindValue(':uid2', $userId, PDO::PARAM_INT);
    $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
    $stmt->bindValue(':off', $offset, PDO::PARAM_INT);
    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if ($unreadOnly) {
        $rows = array_values(array_filter($rows, function($r){ return (int)($r['unread_count'] ?? 0) > 0; }));
    }

    $totalUnread = array_reduce($rows, function($c,$r){ return $c + (int)($r['unread_count'] ?? 0); }, 0);

    echo json_encode([
        'unread' => $totalUnread,
        'items' => array_map(function($r) use ($role, $userId){
            return [
                'negotiation_id' => (int)$r['negotiation_id'],
                'product_id' => (int)$r['product_id'],
                'buyer_id' => (int)$r['buyer_id'],
                'seller_id' => (int)$r['seller_id'],
                'product_title' => $r['product_title'],
                'other_name' => $r['other_name'],
                'last_message' => $r['last_message'] ?? '',
                'last_time' => $r['last_time'] ?? null,
                'unread' => (int)($r['unread_count'] ?? 0),
                // Role to request chat token with
                'role' => ((int)$r['seller_id'] === $userId) ? 'seller' : 'buyer',
            ];
        }, $rows)
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Server error']);
}
