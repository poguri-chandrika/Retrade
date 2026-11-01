<?php
require_once __DIR__ . '/../config.php';
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$negotiationId = (int)($_POST['negotiation_id'] ?? 0);
$senderId = (int)($_POST['sender_id'] ?? 0);
$message = trim((string)($_POST['message'] ?? ''));

if (!$negotiationId || !$senderId) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing fields']);
    exit;
}

// Auth: allow either session user or internal shared-secret header
$internal = false;
$authHeader = $_SERVER['HTTP_X_WS_INTERNAL'] ?? '';
if ($authHeader && hash_equals($authHeader, WS_SHARED_SECRET)) {
    $internal = true;
} else {
    if (!isLoggedIn() || (int)($_SESSION['user_id'] ?? 0) !== $senderId) {
        http_response_code(401);
        echo json_encode(['error' => 'Unauthorized']);
        exit;
    }
}

try {
    // Verify membership
    $stmt = $pdo->prepare('SELECT product_id, seller_id, buyer_id FROM negotiations WHERE id = ?');
    $stmt->execute([$negotiationId]);
    $n = $stmt->fetch();
    if (!$n) {
        http_response_code(404);
        echo json_encode(['error' => 'Negotiation not found']);
        exit;
    }
    if ($senderId !== (int)$n['buyer_id'] && $senderId !== (int)$n['seller_id']) {
        http_response_code(403);
        echo json_encode(['error' => 'Not a participant']);
        exit;
    }

    // Server-side validations
    $msg = $message;
    if (mb_strlen($msg) > 1000) {
        http_response_code(422);
        echo json_encode(['error' => 'Message too long']);
        exit;
    }

    // Basic rate limiting: max 10 messages per 10 seconds per sender
    try {
        $rate = $pdo->prepare("SELECT COUNT(*) FROM messages WHERE sender_id = ? AND created_at > (NOW() - INTERVAL 10 SECOND)");
        $rate->execute([$senderId]);
        $recent = (int)$rate->fetchColumn();
        if ($recent >= 10) {
            http_response_code(429);
            echo json_encode(['error' => 'Too many messages, please slow down']);
            exit;
        }
    } catch (Throwable $e) { /* ignore */ }

    // Attachment handling (optional)
    $attachmentUrl = null;
    if (!empty($_FILES['attachment']) && isset($_FILES['attachment']['tmp_name']) && is_uploaded_file($_FILES['attachment']['tmp_name'])) {
        $file = $_FILES['attachment'];
        $maxBytes = 5 * 1024 * 1024; // 5MB
        if ($file['size'] > $maxBytes) {
            http_response_code(413);
            echo json_encode(['error' => 'Attachment too large (max 5MB)']);
            exit;
        }
        $allowed = [
            'image/jpeg','image/png','image/gif','image/webp','application/pdf',
            'application/msword','application/vnd.openxmlformats-officedocument.wordprocessingml.document'
        ];
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime = finfo_file($finfo, $file['tmp_name']);
        finfo_close($finfo);
        if (!in_array($mime, $allowed, true)) {
            http_response_code(415);
            echo json_encode(['error' => 'Unsupported attachment type']);
            exit;
        }
        $extMap = [
            'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif', 'image/webp' => 'webp',
            'application/pdf' => 'pdf', 'application/msword' => 'doc',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx'
        ];
        $ext = $extMap[$mime] ?? 'bin';
        $dir = __DIR__ . '/../assets/uploads/chat';
        if (!is_dir($dir)) @mkdir($dir, 0775, true);
        $fname = 'att_' . date('Ymd_His') . '_' . bin2hex(random_bytes(6)) . '.' . $ext;
        $dest = $dir . '/' . $fname;
        if (!move_uploaded_file($file['tmp_name'], $dest)) {
            http_response_code(500);
            echo json_encode(['error' => 'Failed to store attachment']);
            exit;
        }
        $attachmentUrl = 'assets/uploads/chat/' . $fname;
        // If no message text, create a default caption
        if ($msg === '') $msg = '[Attachment] ' . $attachmentUrl;
        else $msg .= "\n" . $attachmentUrl;
    }

    if ($msg === '') {
        http_response_code(422);
        echo json_encode(['error' => 'Empty message']);
        exit;
    }

    // Insert message
    $stmt = $pdo->prepare('INSERT INTO messages (negotiation_id, sender_id, message) VALUES (?,?,?)');
    $stmt->execute([$negotiationId, $senderId, $msg]);

    // Touch negotiation for recency ordering
    try {
        $pdo->prepare('UPDATE negotiations SET updated_at = NOW() WHERE id = ?')->execute([$negotiationId]);
    } catch (Throwable $e) {}

    // Create notification for the recipient (buyer or seller)
    try {
        $recipientId = ($senderId === (int)$n['buyer_id']) ? (int)$n['seller_id'] : (int)$n['buyer_id'];
        // Find product title to enrich notification
        $pstmt = $pdo->prepare('SELECT title FROM products WHERE id = ?');
        $pstmt->execute([(int)$n['product_id']]);
        $pTitle = (string)($pstmt->fetchColumn() ?: 'Product');

        // Ensure notifications table exists (no-op if already)
        $pdo->exec("CREATE TABLE IF NOT EXISTS notifications (
          id INT AUTO_INCREMENT PRIMARY KEY,
          user_id INT NOT NULL,
          type VARCHAR(50) DEFAULT 'info',
          title VARCHAR(150) NOT NULL,
          message TEXT,
          link VARCHAR(255) DEFAULT NULL,
          is_read TINYINT(1) NOT NULL DEFAULT 0,
          created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
          INDEX idx_user_read (user_id, is_read, created_at),
          FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

        $title = 'New message on ' . $pTitle;
        $preview = mb_strimwidth($message, 0, 120, '…', 'UTF-8');
        $link = 'product_details.php?id=' . ((int)$n['product_id']);
        $ins = $pdo->prepare('INSERT INTO notifications (user_id, type, title, message, link) VALUES (?,?,?,?,?)');
        $ins->execute([$recipientId, 'chat', $title, $preview, $link]);
    } catch (Throwable $e) {}

    echo json_encode(['ok' => true, 'attachment' => $attachmentUrl]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => 'DB error']);
}
