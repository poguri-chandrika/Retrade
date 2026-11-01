<?php
// Admin-only utility to normalize unrealistic product prices by category/keywords.
// Usage (browser):
//   /scripts/normalize_prices.php                   -> dry run on all products
//   /scripts/normalize_prices.php?apply=1          -> APPLY changes
//   Optional: min_id, max_id, limit
// Pricing is clamped into sensible INR ranges per category, with keyword overrides.

require_once __DIR__ . '/../config.php';

if (!isLoggedIn() || getUserRole() !== 'admin') {
  http_response_code(403);
  echo 'Forbidden: Admin only.';
  exit;
}

header('Content-Type: text/plain; charset=UTF-8');
@set_time_limit(600);

$apply = ((string)($_GET['apply'] ?? '0') === '1');
$limit = max(1, min(10000, (int)($_GET['limit'] ?? 1000)));
$minId = isset($_GET['min_id']) ? (int)$_GET['min_id'] : null;
$maxId = isset($_GET['max_id']) ? (int)$_GET['max_id'] : null;

// Base ranges (INR) by canonical category name in categories.name
$baseRanges = [
  'Smartphones' => [3000, 70000],
  'Laptops' => [12000, 150000],
  'Headphones & Earbuds' => [400, 15000],
  'Wearables' => [800, 12000],
  'Speakers' => [800, 20000],
  'Cameras' => [6000, 200000],
  'Home Appliances' => [800, 50000],
  'Networking' => [600, 12000],
  'Accessories' => [200, 6000],
  'Bags' => [300, 6000],
  // Generic/legacy categories
  'Electronics' => [500, 200000],
  'Clothing' => [200, 10000],
  'Books' => [100, 5000],
  'Home & Garden' => [300, 50000],
  'Sports' => [300, 50000],
  'Toys' => [200, 15000],
  'Automotive' => [500, 150000],
  'Health & Beauty' => [200, 20000],
];

// Keyword-specific overrides: if title/description matches, use these tighter ranges
$keywordRanges = [
  ['kw' => ['shoe','sneaker','running shoe','jordan','yeezy'], 'range' => [400, 15000]],
  ['kw' => ['backpack','bagpack','school bag','laptop bag'], 'range' => [300, 6000]],
  ['kw' => ['earbud','airdopes','earphone','headphone'], 'range' => [400, 15000]],
  ['kw' => ['pendrive','usb drive','sd card','memory card'], 'range' => [200, 4000]],
  ['kw' => ['power bank'], 'range' => [600, 6000]],
  ['kw' => ['smartwatch','watch','fitbit','amazfit','garmin'], 'range' => [1000, 15000]],
  ['kw' => ['router','wi-fi','wifi','archer'], 'range' => [800, 10000]],
  ['kw' => ['dslr','camera','lens','alpha','eos','nikon','canon'], 'range' => [6000, 200000]],
  ['kw' => ['mixer','grinder','air fryer','pressure cooker','water purifier','microwave','oven','geyser'], 'range' => [1000, 30000]],
  ['kw' => ['t-shirt','shirt','jeans','hoodie','jacket','dress','saree'], 'range' => [200, 10000]],
];

function clampPrice(float $price, array $range): float {
  [$min, $max] = $range;
  if ($price < $min) return (float)$min;
  if ($price > $max) return (float)$max;
  return $price;
}

function pickRange(string $title, string $desc, ?string $catName, array $baseRanges, array $keywordRanges): array {
  $text = strtolower($title . ' ' . $desc);
  // Keyword override if matches
  foreach ($keywordRanges as $r) {
    foreach ($r['kw'] as $kw) {
      if (strpos($text, strtolower($kw)) !== false) {
        return $r['range'];
      }
    }
  }
  // Category-based range if available
  if ($catName && isset($baseRanges[$catName])) {
    return $baseRanges[$catName];
  }
  // Fallback generic range
  return [200, 200000];
}

// Build WHERE
$clauses = [];
if ($minId !== null) { $clauses[] = 'p.id >= :minid'; }
if ($maxId !== null) { $clauses[] = 'p.id <= :maxid'; }
$where = empty($clauses) ? '' : ('WHERE ' . implode(' AND ', $clauses));

$sql = "SELECT p.id, p.title, p.description, p.price, p.category, p.category_id, c.name AS cat_name
        FROM products p
        LEFT JOIN categories c ON c.id = p.category_id
        $where
        ORDER BY p.id DESC
        LIMIT :lim";
$stmt = $pdo->prepare($sql);
$stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
if ($minId !== null) $stmt->bindValue(':minid', $minId, PDO::PARAM_INT);
if ($maxId !== null) $stmt->bindValue(':maxid', $maxId, PDO::PARAM_INT);
$stmt->execute();
$rows = $stmt->fetchAll();

if (!$rows) { echo "No products found for normalization.\n"; exit; }

$updated = 0; $skipped = 0;
foreach ($rows as $r) {
  $pid = (int)$r['id'];
  $title = (string)$r['title'];
  $desc = (string)($r['description'] ?? '');
  $catName = (string)($r['cat_name'] ?? ($r['category'] ?? '')) ?: null;
  $price = (float)$r['price'];

  $range = pickRange($title, $desc, $catName, $baseRanges, $keywordRanges);
  $newPrice = clampPrice($price, $range);

  if (abs($newPrice - $price) < 0.001) { $skipped++; continue; }

  echo "#{$pid}: '{$title}'\n  cat: " . ($catName ?: 'N/A') . "\n  old: ".number_format($price,2)." -> new: ".number_format($newPrice,2)." (range ".$range[0]."-".$range[1].")\n";

  if ($apply) {
    $u = $pdo->prepare('UPDATE products SET price = ? WHERE id = ?');
    $u->execute([$newPrice, $pid]);
    $updated++;
  }
}

echo "\nDone. Updated: {$updated}, Skipped: {$skipped}.\n";
if (!$apply) echo "(dry run) To apply changes, add ?apply=1 to the URL.\n";
