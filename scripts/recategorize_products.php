<?php
// Admin-only utility to recategorize existing products based on title/description keywords.
// Usage examples (browser):
//   /scripts/recategorize_products.php               -> dry run, only products with NULL/empty category_id/category
//   /scripts/recategorize_products.php?force=1       -> dry run, consider all products
//   /scripts/recategorize_products.php?apply=1       -> APPLY updates to products needing change
//   /scripts/recategorize_products.php?force=1&apply=1 -> APPLY to all products (use carefully)
// Optional: limit, min_id, max_id

require_once __DIR__ . '/../config.php';

if (!isLoggedIn() || getUserRole() !== 'admin') {
  http_response_code(403);
  echo 'Forbidden: Admin only.';
  exit;
}

header('Content-Type: text/plain; charset=UTF-8');
@set_time_limit(600);

$force = ((string)($_GET['force'] ?? '0') === '1');
$apply = ((string)($_GET['apply'] ?? '0') === '1');
$limit = max(1, min(5000, (int)($_GET['limit'] ?? 500)));
$minId = isset($_GET['min_id']) ? (int)$_GET['min_id'] : null;
$maxId = isset($_GET['max_id']) ? (int)$_GET['max_id'] : null;

// Canonical category names expected in `categories.name`
$canonical = [
  'Smartphones', 'Laptops', 'Headphones & Earbuds', 'Wearables', 'Speakers',
  'Cameras', 'Home Appliances', 'Networking', 'Accessories', 'Bags',
  'Electronics', 'Clothing', 'Books', 'Home & Garden', 'Sports', 'Toys', 'Automotive', 'Health & Beauty'
];

// Map of keywords -> canonical category name
$keywordMap = [
  'Smartphones' => ['smartphone','phone','iphone','galaxy','redmi','oneplus','realme','vivo','oppo','pixel','motorola','iqoo','infinix','tecno','nokia','xiaomi'],
  'Laptops' => ['laptop','notebook','macbook','ideapad','inspiron','vivobook','tuf','thinkpad','aspire','victus','omen','pavilion','chrome book','chromebook'],
  'Headphones & Earbuds' => ['headphone','earbud','earphone','airdopes','buds','wh-','xm4','xm5','headset'],
  'Wearables' => ['smartwatch','watch','colorfit','ninja','band','fitbit','amazfit','garmin'],
  'Speakers' => ['speaker','soundbar','flip 5','flip 6','homepod','bluetooth speaker'],
  'Cameras' => ['camera','dslr','eos','nikon','alpha','a6000','lens','mirrorless'],
  'Home Appliances' => ['mixer','grinder','pressure cooker','air fryer','water purifier','purifier','induction cooktop','microwave','oven','vacuum','geyser','fridge','refrigerator','washing machine'],
  'Networking' => ['router','wi-fi','wifi','archer','repeater','extender','modem'],
  'Accessories' => ['power bank','charger','cable','adapter','pendrive','sd card','memory card','mouse','keyboard','case','cover','tripod'],
  'Bags' => ['backpack','bagpack','bag','laptop bag','sling'],
  'Clothing' => ['shirt','tshirt','t-shirt','jeans','kurta','saree','dress','jacket','hoodie'],
  'Books' => ['book','novel','textbook','guide','ebook'],
  'Sports' => ['cricket','bat','football','yoga','dumbbell','treadmill','cycle','bicycle','shoes running'],
  'Toys' => ['toy','lego','puzzle','doll','action figure'],
  'Automotive' => ['car','bike','helmet','tyre','tire','seat cover','dashcam'],
  'Health & Beauty' => ['cream','lotion','shampoo','trimmer','hair dryer','makeup']
];

function guessCategory(string $title, ?string $desc, array $keywordMap): ?string {
  $t = strtolower($title . ' ' . (string)$desc);
  // score by keyword hits; first category with highest hits wins
  $bestCat = null; $bestScore = 0;
  foreach ($keywordMap as $cat => $keywords) {
    $score = 0;
    foreach ($keywords as $kw) { if (strpos($t, strtolower($kw)) !== false) { $score++; } }
    if ($score > $bestScore) { $bestScore = $score; $bestCat = $cat; }
  }
  return $bestScore > 0 ? $bestCat : null;
}

function ensureCategory(PDO $pdo, string $name): int {
  $st = $pdo->prepare('SELECT id FROM categories WHERE name = ?');
  $st->execute([$name]);
  $id = $st->fetchColumn();
  if ($id) return (int)$id;
  $ins = $pdo->prepare('INSERT INTO categories (name) VALUES (?)');
  $ins->execute([$name]);
  return (int)$pdo->lastInsertId();
}

// Build WHERE clause
$clauses = [];
if (!$force) { $clauses[] = '(category_id IS NULL OR category_id = 0 OR category IS NULL OR category = "")'; }
if ($minId !== null) { $clauses[] = 'id >= :minid'; }
if ($maxId !== null) { $clauses[] = 'id <= :maxid'; }
$where = empty($clauses) ? '' : ('WHERE ' . implode(' AND ', $clauses));

$sql = "SELECT id, title, description, category, category_id FROM products $where ORDER BY id DESC LIMIT :lim";
$stmt = $pdo->prepare($sql);
$stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
if ($minId !== null) $stmt->bindValue(':minid', $minId, PDO::PARAM_INT);
if ($maxId !== null) $stmt->bindValue(':maxid', $maxId, PDO::PARAM_INT);
$stmt->execute();
$rows = $stmt->fetchAll();

if (!$rows) { echo "No products matched selection.\n"; exit; }

echo "Scanning ".count($rows)." products...\n\n";
$updated = 0; $skipped = 0; $noGuess = 0;

foreach ($rows as $r) {
  $pid = (int)$r['id'];
  $title = (string)$r['title'];
  $desc  = (string)($r['description'] ?? '');
  $currentName = (string)($r['category'] ?? '');
  $currentId = (int)($r['category_id'] ?? 0);

  $guess = guessCategory($title, $desc, $keywordMap);
  if (!$guess) { $noGuess++; echo "#{$pid}: NO MATCH -> '$title'\n"; continue; }

  // Ensure category exists and get id
  $cid = ensureCategory($pdo, $guess);
  $needs = ($currentId !== $cid) || (strcasecmp((string)$currentName, $guess) !== 0);

  if (!$needs) { $skipped++; continue; }

  echo "#{$pid}: '$title'\n  old: [{$currentId}] {$currentName}\n  new: [{$cid}] {$guess}\n";

  if ($apply) {
    $u = $pdo->prepare('UPDATE products SET category_id = ?, category = ? WHERE id = ?');
    $u->execute([$cid, $guess, $pid]);
    $updated++;
  }
}

echo "\nDone. Updated: {$updated}, Skipped (already ok): {$skipped}, No-guess: {$noGuess}.\n";
if (!$apply) echo "(dry run) To apply changes, add ?apply=1 to the URL.\n";
