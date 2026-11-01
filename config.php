<?php
// Database configuration
define('DB_HOST', 'localhost');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_NAME', 'retrade_db');
define('PEXELS_API_KEY', 'yzCDMU4dIp1bLxi3i97HsIMptIbEA5vDnzqsW9jVcyWAejxVkndAuoCK');

// Create database connection
try {
    $pdo = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME, DB_USER, DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
} catch(PDOException $e) {
    die("Connection failed: " . $e->getMessage());
}

// Start session if not already started with safer cookie params
if (session_status() == PHP_SESSION_NONE) {
    // Ensure cookies are scoped to the whole app and are httpOnly
    $params = session_get_cookie_params();
    $cookieOptions = [
        'lifetime' => 0,
        'path' => '/',
        'domain' => $params['domain'] ?: '',
        'secure' => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on', // false on localhost
        'httponly' => true,
        'samesite' => 'Lax',
    ];
    if (PHP_VERSION_ID >= 70300) {
        session_set_cookie_params($cookieOptions);
    } else {
        // Fallback for older PHP (without samesite support)
        session_set_cookie_params($cookieOptions['lifetime'], $cookieOptions['path'], $cookieOptions['domain'], $cookieOptions['secure'], $cookieOptions['httponly']);
    }
    session_start();
}

// WebSocket configuration
// Change the secret in production. Keep it in sync with ws-server/server.js WS_SHARED_SECRET.
if (!defined('WS_SERVER_URL')) {
    define('WS_SERVER_URL', 'http://localhost:3001');
}
if (!defined('WS_SHARED_SECRET')) {
    define('WS_SHARED_SECRET', 'change_this_ws_secret');
}

// External photo provider API keys (optional)
// Prefer setting via environment variables PEXELS_API_KEY / UNSPLASH_ACCESS_KEY
if (!defined('PEXELS_API_KEY')) {
    define('PEXELS_API_KEY', getenv('PEXELS_API_KEY') ?: '');
}
if (!defined('UNSPLASH_ACCESS_KEY')) {
    define('UNSPLASH_ACCESS_KEY', getenv('UNSPLASH_ACCESS_KEY') ?: '');
}

// Helper functions
function isLoggedIn() {
    return isset($_SESSION['user_id']);
}

function getUserRole() {
    return $_SESSION['user_role'] ?? null;
}

function requireLogin() {
    if (!isLoggedIn()) {
        header('Location: login.php');
        exit();
    }
}

function requireRole($role) {
    requireLogin();
    if (getUserRole() !== $role) {
        header('Location: dashboard.php');
        exit();
    }
}

function sanitizeInput($data) {
    return htmlspecialchars(strip_tags(trim($data)));
}

function formatPrice($price) {
    return '₹' . number_format($price, 2);
}

function getTimeAgo($datetime) {
    $time = time() - strtotime($datetime);
    
    if ($time < 60) return 'just now';
    if ($time < 3600) return floor($time/60) . ' minutes ago';
    if ($time < 86400) return floor($time/3600) . ' hours ago';
    if ($time < 2592000) return floor($time/86400) . ' days ago';
    if ($time < 31536000) return floor($time/2592000) . ' months ago';
    return floor($time/31536000) . ' years ago';
}

// Compute seller trust metrics and a derived trust score
// Returns: [
//   'trusted' => bool,
//   'score' => float (0-100),
//   'metrics' => [ 'avg_rating'=>float, 'reviews_count'=>int, 'total_sales'=>int, 'confirmed_ratio'=>float, 'open_complaints_last_90d'=>int, 'complaint_rate'=>float, 'account_age_days'=>int ]
// ]
function getSellerTrust(PDO $pdo, int $seller_id): array {
    $seller_id = (int)$seller_id;
    if ($seller_id <= 0) return ['trusted'=>false,'score'=>0.0,'metrics'=>[]];

    // Account age
    $ua = $pdo->prepare("SELECT created_at FROM users WHERE id=? AND role='seller' LIMIT 1");
    $ua->execute([$seller_id]);
    $created = $ua->fetchColumn();
    $ageDays = $created ? max(0, (int) floor((time() - strtotime($created)) / 86400)) : 0;

    // Ratings across seller's products
    $r = $pdo->prepare("SELECT ROUND(AVG(r.rating),2) avg_rating, COUNT(*) cnt\n                        FROM reviews r\n                        JOIN products p ON p.id=r.product_id\n                        WHERE p.seller_id=? AND r.status='approved'");
    $r->execute([$seller_id]);
    $rowR = $r->fetch() ?: ['avg_rating'=>0,'cnt'=>0];
    $avgRating = (float)($rowR['avg_rating'] ?? 0);
    $reviewsCount = (int)($rowR['cnt'] ?? 0);

    // Sales and confirmed deliveries
    $s = $pdo->prepare("SELECT 
                          COUNT(*) total_sales,
                          SUM(CASE WHEN delivery_status='confirmed' THEN 1 ELSE 0 END) confirmed
                        FROM transactions 
                        WHERE seller_id=?");
    $s->execute([$seller_id]);
    $rowS = $s->fetch() ?: ['total_sales'=>0,'confirmed'=>0];
    $totalSales = (int)($rowS['total_sales'] ?? 0);
    $confirmed = (int)($rowS['confirmed'] ?? 0);
    $confirmedRatio = $totalSales > 0 ? round($confirmed / max(1,$totalSales), 2) : 0.0;

    // Complaints
    // Count open complaints in last 90 days and total complaints lifetime
    $cOpen = $pdo->prepare("SELECT COUNT(*) FROM complaints WHERE seller_id=? AND status IN ('open','in_review') AND created_at >= DATE_SUB(NOW(), INTERVAL 90 DAY)");
    $cOpen->execute([$seller_id]);
    $open90 = (int)$cOpen->fetchColumn();

    $cAll = $pdo->prepare("SELECT COUNT(*) FROM complaints WHERE seller_id=?");
    $cAll->execute([$seller_id]);
    $allComplaints = (int)$cAll->fetchColumn();
    $complaintRate = $totalSales > 0 ? round($allComplaints / max(1,$totalSales), 3) : 0.0;

    // Heuristics for trust score (0-100)
    // Weighting: rating (50), confirmations (30), complaints inverse (15), tenure (5)
    $ratingScore = min(1.0, max(0.0, ($avgRating - 3.0) / 2.0)); // 3.0 -> 0, 5.0 -> 1
    $confirmScore = min(1.0, $confirmedRatio); // 0..1
    $complaintsScore = min(1.0, max(0.0, 1.0 - min(1.0, $complaintRate * 5))); // fast penalty as rate grows
    $tenureScore = min(1.0, $ageDays / 365.0);

    $score = round(100 * (0.50*$ratingScore + 0.30*$confirmScore + 0.15*$complaintsScore + 0.05*$tenureScore), 1);

    // Trusted threshold + minimum gates
    $trusted = ($confirmed >= 3);

    return [
        'trusted' => $trusted,
        'score' => $score,
        'metrics' => [
            'avg_rating' => $avgRating,
            'reviews_count' => $reviewsCount,
            'total_sales' => $totalSales,
            'confirmed_ratio' => $confirmedRatio,
            'open_complaints_last_90d' => $open90,
            'complaint_rate' => $complaintRate,
            'account_age_days' => $ageDays,
        ]
    ];
}
?>
