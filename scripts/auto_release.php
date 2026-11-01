<?php
require_once __DIR__ . '/../config.php';

// Auto-release escrowed payments after a dispute window if buyer did not dispute.
// Configure via environment variable AUTO_RELEASE_DAYS (default 3 days).

$days = intval(getenv('AUTO_RELEASE_DAYS') ?: 3);
if ($days < 1) { $days = 3; }

$cutoffExpr = "NOW() - INTERVAL {$days} DAY";

try {
    // Find candidate transactions: status=pending, delivery_status=pending, created_at older than cutoff
    $stmt = $pdo->query("SELECT t.* FROM transactions t WHERE t.status='pending' AND t.delivery_status='pending' AND t.created_at < ($cutoffExpr)");
    $candidates = $stmt->fetchAll();

    foreach ($candidates as $t) {
        $pdo->beginTransaction();
        try {
            // Mark as released and delivery confirmed
            $upd = $pdo->prepare("UPDATE transactions SET status='released', delivery_status='confirmed', notes = CONCAT(notes, '\n\nAuto-released after {$days} days on ', NOW()) WHERE id = ? AND status='pending' AND delivery_status='pending'");
            $upd->execute([$t['id']]);

            if ($upd->rowCount() > 0) {
                // Transfer seller amount from admin to seller balance
                $stmt = $pdo->prepare("UPDATE users SET balance = balance + ? WHERE id = ?");
                $stmt->execute([$t['seller_amount'], $t['seller_id']]);

                $stmt = $pdo->prepare("UPDATE users SET balance = balance - ? WHERE role = 'admin'");
                $stmt->execute([$t['seller_amount']]);
            }

            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            // Continue to next transaction without halting entire job
        }
    }

    echo json_encode(['ok' => true, 'processed' => count($candidates)]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false]);
}
