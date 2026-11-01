<?php
// Seed 100 realistic Indian second-hand products using real images from assets/img/seed/
// Usage (browser): /scripts/seed_real_india_products.php?count=100&status=approved
// Requires admin login. Run fetch first:
//   /scripts/fetch_real_images.php?profile=india&per_cat=12&pages=2&force=1

require_once __DIR__ . '/../config.php';

if (!isLoggedIn() || getUserRole() !== 'admin') {
  http_response_code(403);
  echo 'Forbidden: Admin only.';
  exit;
}

header('Content-Type: text/plain; charset=UTF-8');
@set_time_limit(600);

$count  = max(1, min(500, (int)($_GET['count'] ?? 100)));
$status = (string)($_GET['status'] ?? 'approved'); // approved|pending|mixed

$seedDir = __DIR__ . '/../assets/img/seed';
$imgDir  = __DIR__ . '/../assets/img';
$defDir  = __DIR__ . '/../assets/img/defects';
if (!is_dir($seedDir)) {
  echo "Seed directory not found: $seedDir\nRun fetch_real_images.php first.\n";
  exit;
}
if (!is_dir($imgDir)) mkdir($imgDir, 0755, true);
if (!is_dir($defDir)) mkdir($defDir, 0755, true);

// Brand-model pools common in India
$catalog = [
  'Smartphones' => [
    ['Samsung','Galaxy A52'], ['Samsung','Galaxy S21'], ['Xiaomi','Redmi Note 10'], ['realme','Narzo 50'],
    ['OnePlus','Nord CE'], ['Vivo','Y21'], ['OPPO','F19'], ['Motorola','G60'], ['Google','Pixel 6a'],
  ],
  'Laptops' => [
    ['HP','Pavilion 15'], ['Dell','Inspiron 3511'], ['Lenovo','IdeaPad Slim 3'], ['ASUS','TUF Gaming F15'],
    ['Acer','Aspire 5'], ['Apple','MacBook Air M1']
  ],
  'Headphones & Earbuds' => [
    ['Sony','WH-CH720N'], ['boAt','Airdopes 141'], ['OnePlus','Buds Z2'], ['JBL','Tune 510BT'],
    ['Realme','Buds Air 3']
  ],
  'Wearables' => [
    ['Noise','ColorFit Pro'], ['Fire-Boltt','Ninja'], ['Amazfit','GTS 2 Mini']
  ],
  'Speakers' => [
    ['JBL','Flip 6'], ['boAt','Stone 650'], ['Sony','SRS-XB13']
  ],
  'Cameras' => [
    ['Canon','EOS 200D'], ['Nikon','D5600'], ['Sony','Alpha a6000']
  ],
  'Home Appliances' => [
    ['Prestige','Mixer Grinder'], ['Hawkins','Pressure Cooker'], ['Philips','Air Fryer'], ['Kent','Water Purifier']
  ],
  'Networking' => [
    ['TP-Link','Archer C6'], ['D-Link','DIR-615']
  ],
  'Accessories' => [
    ['Mi','Power Bank 20000mAh'], ['Anker','PowerPort Charger'], ['SanDisk','Ultra 64GB']
  ],
  'Bags' => [
    ['Wildcraft','Backpack'], ['American Tourister','Backpack']
  ]
];

// Map from seed filename keyword -> catalog category
$seedToCategory = [
  'smartphone' => 'Smartphones',
  'phone' => 'Smartphones',
  'galaxy' => 'Smartphones',
  'laptop' => 'Laptops',
  'headphone' => 'Headphones & Earbuds',
  'earbuds' => 'Headphones & Earbuds',
  'headphones' => 'Headphones & Earbuds',
  'smartwatch' => 'Wearables',
  'watch' => 'Wearables',
  'speaker' => 'Speakers',
  'camera' => 'Cameras',
  'dslr' => 'Cameras',
  'mixer' => 'Home Appliances',
  'pressure_cooker' => 'Home Appliances',
  'air_fryer' => 'Home Appliances',
  'water_purifier' => 'Home Appliances',
  'router' => 'Networking',
  'power_bank' => 'Accessories',
  'charger' => 'Accessories',
  'pendrive' => 'Accessories',
  'sd_card' => 'Accessories',
  'bag' => 'Bags',
  'backpack' => 'Bags'
];

// Ensure categories exist
$ensureCategory = function(PDO $pdo, string $name): string {
  $st = $pdo->prepare('SELECT name FROM categories WHERE name = ?');
  $st->execute([$name]);
  if ($st->fetchColumn()) return $name;
  $ins = $pdo->prepare('INSERT INTO categories (name) VALUES (?)');
  $ins->execute([$name]);
  return $name;
};
// Fetch category id by name
$getCategoryId = function(PDO $pdo, string $name): ?int {
  $st = $pdo->prepare('SELECT id FROM categories WHERE name = ?');
  $st->execute([$name]);
  $id = $st->fetchColumn();
  return $id ? (int)$id : null;
};

// Pick or create a seller (prefer an existing real seller if available)
function getAnySellerId(PDO $pdo): int {
  $sid = (int)($pdo->query("SELECT id FROM users WHERE role='seller' ORDER BY id LIMIT 1")->fetchColumn() ?: 0);
  if ($sid > 0) return $sid;
  $name = 'Marketplace Seller';
  $email = 'seller_' . time() . '@retrade.com';
  $password = password_hash('password', PASSWORD_BCRYPT);
  $st = $pdo->prepare("INSERT INTO users (name, email, password, role, balance) VALUES (?,?,?,?,0)");
  $st->execute([$name, $email, $password, 'seller']);
  return (int)$pdo->lastInsertId();
}

function priceFor(string $category): float {
  switch ($category) {
    case 'Smartphones': return rand(4000, 35000) + rand(0,99)/100;
    case 'Laptops': return rand(15000, 90000) + rand(0,99)/100;
    case 'Headphones & Earbuds': return rand(800, 8000) + rand(0,99)/100;
    case 'Wearables': return rand(1200, 6000) + rand(0,99)/100;
    case 'Speakers': return rand(1500, 9000) + rand(0,99)/100;
    case 'Cameras': return rand(9000, 70000) + rand(0,99)/100;
    case 'Home Appliances': return rand(1200, 12000) + rand(0,99)/100;
    case 'Networking': return rand(900, 6000) + rand(0,99)/100;
    case 'Accessories': return rand(300, 3000) + rand(0,99)/100;
    case 'Bags': return rand(400, 2500) + rand(0,99)/100;
    default: return rand(500, 5000) + rand(0,99)/100;
  }
}

function conditionPair(): array {
  $grades = ['Poor','Fair','Good','Excellent','Like New'];
  $weights = [5, 18, 45, 25, 7];
  $r = rand(1,100); $acc = 0; $cond = 'Good';
  for ($i=0;$i<count($grades);$i++){ $acc += $weights[$i]; if ($r <= $acc){ $cond = $grades[$i]; break; } }
  $notes = in_array($cond, ['Poor','Fair']) ? 'Visible wear; tested working. See photos for scratches/dents.' : 'Well maintained; fully functional.';
  return [$cond, $notes];
}

// Gather available seed images
$pool = [];
foreach (scandir($seedDir) as $f) {
  if ($f === '.' || $f === '..') continue;
  if (!preg_match('/\.(jpe?g|png|webp)$/i', $f)) continue;
  $key = strtolower($f);
  $pool[] = $f;
}
if (empty($pool)) {
  echo "No seed images found in $seedDir\nRun fetch_real_images.php first.\n";
  exit;
}

echo "Seeding $count real products...\n";

$pdo->beginTransaction();
try {
  $sellerId = getAnySellerId($pdo);
  $ins = $pdo->prepare("INSERT INTO products (seller_id, title, description, price, image, category, category_id, status, condition_grade, condition_notes, defect_photos) VALUES (?,?,?,?,?,?,?,?,?,?,?)");

  for ($i=0; $i<$count; $i++) {
    $pick = $pool[array_rand($pool)];
    $pickLower = strtolower($pick);

    // infer category from filename keywords
    $cat = 'Accessories';
    foreach ($seedToCategory as $needle => $mapped) {
      if (strpos($pickLower, $needle) !== false) { $cat = $mapped; break; }
    }
    // ensure category exists and get id
    $finalCat = $ensureCategory($pdo, $cat);
    $finalCatId = $getCategoryId($pdo, $finalCat);

    // build a realistic title from catalog
    $brandModel = $catalog[$finalCat][array_rand($catalog[$finalCat])];
    [$brand, $model] = $brandModel;
    $title = $brand . ' ' . $model;

    $price = priceFor($finalCat);
    [$cond, $notes] = conditionPair();
    $desc = "Pre-owned $brand $model in $cond condition. All functions checked. Includes generic accessories if any.";

    // copy main image into assets/img with product_* name
    $ext = pathinfo($pick, PATHINFO_EXTENSION);
    $destName = 'product_' . uniqid() . '.' . $ext;
    if (!copy($seedDir . '/' . $pick, $imgDir . '/' . $destName)) {
      // fallback: skip this iteration if copy fails
      $i--; // keep target count
      continue;
    }

    // random 0-1 defect photo (reuse seed img just for placeholder realism)
    $defList = null;
    if (rand(0,100) < 30) {
      $p2 = $pool[array_rand($pool)];
      $ext2 = pathinfo($p2, PATHINFO_EXTENSION);
      $defName = 'defect_' . uniqid() . '.' . $ext2;
      if (copy($seedDir . '/' . $p2, $defDir . '/' . $defName)) {
        $defList = 'defects/' . $defName; // relative from assets/img
      }
    }

    $st = $status === 'mixed' ? (rand(0, 100) < 75 ? 'approved' : (rand(0,1) ? 'pending' : 'rejected')) : $status;

    $ins->execute([$sellerId, $title, $desc, $price, $destName, $finalCat, $finalCatId, $st, $cond, $notes, $defList]);
  }

  $pdo->commit();
  echo "Done. Inserted $count real products.\n";
} catch (Throwable $e) {
  if ($pdo->inTransaction()) $pdo->rollBack();
  echo 'Error: ' . $e->getMessage() . "\n";
}
