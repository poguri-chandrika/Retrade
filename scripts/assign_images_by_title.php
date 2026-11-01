<?php
// Assign real images to products by searching Pexels with each product's title/category (India-focused capable)
// Usage (browser): /scripts/assign_images_by_title.php?scope=missing&limit=200&profile=india&locale=hi-IN&dry_run=0
// - scope: missing | all (default: missing)
// - limit: max number of products to process (default: 200)
// - dry_run: 1 to simulate without writing files/DB (default: 0)
// - min_w, min_h: minimum image dimensions (default: 800x600)
// - profile: 'india' to bias queries and brand list (default: '')
// - locale: Pexels locale hint (default: en-US, india->hi-IN)

require_once __DIR__ . '/../config.php';

if (!isLoggedIn() || getUserRole() !== 'admin') {
  http_response_code(403);
  echo 'Forbidden: Admin only.';
  exit;
}

header('Content-Type: text/plain; charset=UTF-8');

// Allow more time for batch operations
@set_time_limit(600);

if (!PEXELS_API_KEY) {
  http_response_code(500);
  echo "Missing PEXELS_API_KEY. Set it in config or environment.\n";
  exit;
}

$scope = $_GET['scope'] ?? 'missing';
$limit = max(1, min(1000, (int)($_GET['limit'] ?? 200)));
$minId = isset($_GET['min_id']) ? (int)$_GET['min_id'] : null;
$maxId = isset($_GET['max_id']) ? (int)$_GET['max_id'] : null;
$order = strtolower((string)($_GET['order'] ?? 'desc')); // asc|desc
$dry   = ((string)($_GET['dry_run'] ?? '0') === '1');
$minW  = max(400, (int)($_GET['min_w'] ?? 800));
$minH  = max(300, (int)($_GET['min_h'] ?? 600));
$profile = strtolower((string)($_GET['profile'] ?? ''));
$locale  = (string)($_GET['locale'] ?? ($profile === 'india' ? 'hi-IN' : 'en-US'));
// strict=1: for non-electronics categories, avoid electronics-biased hints
$strict  = ((string)($_GET['strict'] ?? '0') === '1');

$brandHints = [
  // Smartphones (India)
  'samsung','galaxy','oneplus','xiaomi','redmi','realme','vivo','oppo','apple','iphone','pixel','motorola','infinix','tecno','lava','iqoo',
  // Laptops
  'hp','dell','lenovo','asus','acer','macbook','msi',
  // Audio & wearables
  'boat','boAt','jbl','sony','noise','fire-boltt','mi','oneplus buds','airpods',
  // Cameras
  'canon','nikon','sony alpha','fujifilm',
  // Other electronics & appliances common in India
  'power bank','router','air purifier','mixer','grinder','pressure cooker','induction cooktop','set top box','water purifier','air fryer'
];

// India-specific popular models/series
$modelHints = [
  // Phones
  'galaxy m14','galaxy a14','galaxy f14','galaxy s21','galaxy s22','galaxy s23','note 12','note 13','redmi note 12','redmi note 13','redmi 12','realme narzo 60','oneplus nord ce 3','oneplus nord 2','vivo y27','oppo a78','pixel 7a','pixel 7','pixel 8',
  // Laptops
  'hp 15s','dell inspiron 15','lenovo ideapad slim 3','asus tuf a15','acer aspire 7','macbook air m1','macbook air m2',
  // Audio & wearables
  'airdopes 141','boat airdopes 141','noise colorfit','fire-boltt ninja','sony wh-1000xm4','jbl flip 5','jbl flip 6',
  // Cameras
  'canon eos 1500d','nikon d3500','sony a6000','alpha a6000',
  // Accessories & appliances
  'mi power bank 3i','tp-link archer c6','kent ro','philips air fryer','prestige pressure cooker','crompton mixer grinder'
];

function makeQuery(string $title, ?string $category, string $profile, array $brandHints, array $modelHints, bool $strict): string {
  $q = strtolower(trim(preg_replace('/\s+/', ' ', $title)));
  $keep = [];
  $catLower = strtolower((string)$category);
  $isElectronicsCat = false;
  if ($category) {
    $isElectronicsCat = (strpos($catLower, 'phone') !== false) || (strpos($catLower, 'laptop') !== false) || (strpos($catLower, 'camera') !== false) || (strpos($catLower, 'audio') !== false) || (strpos($catLower, 'head') !== false) || (strpos($catLower, 'speaker') !== false) || (strpos($catLower, 'network') !== false) || (strpos($catLower, 'monitor') !== false) || (strpos($catLower, 'appliance') !== false) || (strpos($catLower, 'kitchen') !== false) || (strpos($catLower, 'home') !== false);
  }
  // Include brand/model hints only if electronics or not strict
  $allowHints = $isElectronicsCat || !$strict;
  if ($allowHints) {
    foreach ($brandHints as $hint) {
      if (strpos($q, strtolower($hint)) !== false) { $keep[] = $hint; }
    }
    foreach ($modelHints as $mh) {
      if (strpos($q, $mh) !== false) { $keep[] = $mh; }
    }
  }
  // Include category specialization
  $categoryBoost = [];
  if ($category) {
    $c = strtolower($category);
    // Electronics-oriented
    if (strpos($c, 'phone') !== false || strpos($c, 'smartphone') !== false) { $categoryBoost[] = 'smartphone'; }
    if (strpos($c, 'laptop') !== false) { $categoryBoost[] = 'laptop'; }
    if (strpos($c, 'camera') !== false) { $categoryBoost[] = 'camera'; }
    if (strpos($c, 'head') !== false || strpos($c, 'audio') !== false || strpos($c, 'earbud') !== false) { $categoryBoost[] = 'headphones'; $categoryBoost[] = 'earbuds'; }
    if (strpos($c, 'speaker') !== false) { $categoryBoost[] = 'bluetooth speaker'; }
    if (strpos($c, 'monitor') !== false || strpos($c, 'display') !== false) { $categoryBoost[] = 'computer monitor'; $categoryBoost[] = 'led monitor'; }
    if (strpos($c, 'network') !== false) { $categoryBoost[] = 'wifi router'; }
    if (strpos($c, 'appliance') !== false || strpos($c, 'kitchen') !== false || strpos($c, 'home') !== false) { $categoryBoost[] = 'home appliance'; }
    // Non-electronics
    if (strpos($c, 'bag') !== false) { $categoryBoost[] = 'backpack'; $categoryBoost[] = 'bag'; }
    if (strpos($c, 'wear') !== false || strpos($c, 'clothing') !== false) { $categoryBoost[] = 'clothing'; }
    if (strpos($c, 'footwear') !== false || strpos($c, 'shoe') !== false) { $categoryBoost[] = 'running shoes'; $categoryBoost[] = 'sneakers'; }
    if (strpos($c, 'sports') !== false) { $categoryBoost[] = 'sports gear'; }
  }
  $keep = array_merge($keep, $categoryBoost);
  // Add profile locale bias
  if ($profile === 'india') { $keep[] = 'india'; }

  // Build keywords from title (take alnum tokens)
  $tokens = preg_split('/[^a-z0-9]+/i', $title, -1, PREG_SPLIT_NO_EMPTY);
  $tokens = array_slice(array_map('strtolower', $tokens), 0, 8);
  // Prefer brand tokens already present
  $queryParts = array_unique(array_merge($keep, $tokens));
  $query = trim(implode(' ', $queryParts));
  // Guard minimum
  if ($query === '') $query = ($profile === 'india' ? 'electronics india' : 'electronics');
  return $query;
}

function pexelsSearch(string $query, int $perPage, int $page, string $locale): array {
  $url = 'https://api.pexels.com/v1/search?' . http_build_query([
    'query' => $query,
    'per_page' => $perPage,
    'page' => $page,
    'orientation' => 'landscape',
    'locale' => $locale,
  ]);
  $retries = 3; $attempt = 0; $lastErr = null;
  while ($attempt < $retries) {
    $attempt++;
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Authorization: ' . PEXELS_API_KEY]);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
    curl_setopt($ch, CURLOPT_TIMEOUT, 25);
    $resp = curl_exec($ch);
    if ($resp !== false) {
      $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
      curl_close($ch);
      if ($code === 200) {
        $data = json_decode($resp, true);
        if (!is_array($data) || !isset($data['photos'])) return [];
        return $data['photos'];
      }
      $lastErr = 'Pexels HTTP ' . $code;
    } else {
      $lastErr = curl_error($ch);
      curl_close($ch);
    }
    // backoff: 1s, 2s
    sleep($attempt);
  }
  throw new RuntimeException('Pexels error: ' . ($lastErr ?: 'unknown'));
}

function bestPhoto(array $photos, int $minW, int $minH): ?array {
  // pick the largest meeting min size
  $cands = array_filter($photos, function($p) use ($minW, $minH){
    return ($p['width'] ?? 0) >= $minW && ($p['height'] ?? 0) >= $minH;
  });
  if (empty($cands)) return null;
  usort($cands, function($a,$b){ return ($b['width']*$b['height']) <=> ($a['width']*$a['height']); });
  return $cands[0];
}

function tokenize(string $s): array {
  $toks = preg_split('/[^a-z0-9]+/i', strtolower($s), -1, PREG_SPLIT_NO_EMPTY);
  return array_values(array_filter($toks, function($t){ return strlen($t) >= 3; }));
}

function scorePhoto(array $photo, string $title, ?string $category): int {
  $alt = (string)($photo['alt'] ?? '');
  $titleToks = tokenize($title);
  $altToks   = tokenize($alt);
  $catToks   = $category ? tokenize($category) : [];
  $set = array_flip($altToks);
  $score = 0;
  foreach ($titleToks as $t) { if (isset($set[$t])) $score++; }
  foreach ($catToks as $t) { if (isset($set[$t])) $score++; }
  return $score;
}

function bestPhotoByRelevance(array $photos, int $minW, int $minH, string $title, ?string $category): ?array {
  $cands = array_filter($photos, function($p) use ($minW, $minH){
    return ($p['width'] ?? 0) >= $minW && ($p['height'] ?? 0) >= $minH;
  });
  if (empty($cands)) return null;
  $scored = [];
  foreach ($cands as $p) {
    $scored[] = ['score' => scorePhoto($p, $title, $category), 'area' => (($p['width']??0)*($p['height']??0)), 'p' => $p];
  }
  usort($scored, function($a,$b){
    if ($a['score'] === $b['score']) return $b['area'] <=> $a['area'];
    return $b['score'] <=> $a['score'];
  });
  return $scored[0]['p'] ?? null;
}

function downloadTo(string $url, string $dest): void {
  $retries = 3; $attempt = 0; $lastErr = null;
  while ($attempt < $retries) {
    $attempt++;
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
    curl_setopt($ch, CURLOPT_TIMEOUT, 40);
    curl_setopt($ch, CURLOPT_USERAGENT, 'RetradeAssign/1.0');
    $data = curl_exec($ch);
    if ($data !== false) {
      $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
      $ct = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
      curl_close($ch);
      if ($code === 200) {
        if (!$ct || stripos($ct, 'image/') !== 0) throw new RuntimeException('Unexpected content-type: ' . $ct);
        $dir = dirname($dest);
        if (!is_dir($dir)) mkdir($dir, 0755, true);
        if (file_put_contents($dest, $data) === false) throw new RuntimeException('Write failed: ' . $dest);
        return;
      }
      $lastErr = 'Download HTTP ' . $code;
    } else {
      $lastErr = curl_error($ch);
      curl_close($ch);
    }
    sleep($attempt); // backoff
  }
  throw new RuntimeException('Download error: ' . ($lastErr ?: 'unknown'));
}

// Select products
$clauses = [];
if ($scope !== 'all') { $clauses[] = "(image IS NULL OR image = '')"; }
if ($minId !== null) { $clauses[] = "id >= :minid"; }
if ($maxId !== null) { $clauses[] = "id <= :maxid"; }
$whereSql = empty($clauses) ? '' : ('WHERE ' . implode(' AND ', $clauses) . ' ');
$sql = "SELECT id, title, category, image FROM products " . $whereSql . "ORDER BY id " . ($order === 'asc' ? 'ASC' : 'DESC') . " LIMIT :lim";
$stmt = $pdo->prepare($sql);
$stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
if ($minId !== null) $stmt->bindValue(':minid', $minId, PDO::PARAM_INT);
if ($maxId !== null) $stmt->bindValue(':maxid', $maxId, PDO::PARAM_INT);
$stmt->execute();
$rows = $stmt->fetchAll();

if (!$rows) { echo "No products to process.\n"; exit; }

$saveDir = __DIR__ . '/../assets/img';
$updated = 0; $skipped = 0; $errors = 0;

foreach ($rows as $r) {
  $pid = (int)$r['id'];
  $title = (string)$r['title'];
  $cat = $r['category'] ?? '';

  $query = makeQuery($title, $cat, $profile, $brandHints, $modelHints, $strict);
  echo "Product #$pid: $title\n  Query: $query\n";

  // Try page 1
  try {
    $photos = pexelsSearch($query, 15, 1, $locale);
  } catch (Throwable $e) {
    $errors++; echo "  Search error: " . $e->getMessage() . "\n"; continue;
  }
  $best = bestPhotoByRelevance($photos, $minW, $minH, $title, $cat);
  // If low relevance, try page 2
  if (!$best || scorePhoto($best, $title, $cat) < 1) {
    try {
      $photos2 = pexelsSearch($query, 15, 2, $locale);
      $cand2 = bestPhotoByRelevance($photos2, $minW, $minH, $title, $cat);
      if ($cand2 && scorePhoto($cand2, $title, $cat) >= max(1, (int)scorePhoto($best ?? [], $title, $cat))) {
        $best = $cand2;
      }
    } catch (Throwable $e) { /* ignore */ }
  }
  // If still low, refine query to category-focused tokens only
  if (!$best || scorePhoto($best, $title, $cat) < 1) {
    $refined = makeQuery($title, $cat, $profile, [], [], true);
    if ($refined && $refined !== $query) {
      try {
        $photos3 = pexelsSearch($refined, 15, 1, $locale);
        $cand3 = bestPhotoByRelevance($photos3, $minW, $minH, $title, $cat);
        if ($cand3 && scorePhoto($cand3, $title, $cat) >= max(1, (int)scorePhoto($best ?? [], $title, $cat))) {
          $best = $cand3;
          $query = $refined;
          echo "  Refined query used: $refined\n";
        }
      } catch (Throwable $e) { /* ignore */ }
    }
  }
  if (!$best) { $skipped++; echo "  No suitable photo after refinement.\n"; continue; }

  $src = $best['src']['large2x'] ?? ($best['src']['large'] ?? ($best['src']['original'] ?? null));
  if (!$src) { $skipped++; echo "  No downloadable src.\n"; continue; }

  $fname = 'product_' . $pid . '_' . uniqid() . '.jpg';
  $dest  = $saveDir . '/' . $fname;

  if ($dry) {
    echo "  DRY RUN: would save as $fname and update DB.\n";
    continue;
  }

  try {
    downloadTo($src, $dest);
  } catch (Throwable $e) {
    $errors++; echo "  Download error: " . $e->getMessage() . "\n"; continue;
  }

  // Update DB
  try {
    $u = $pdo->prepare('UPDATE products SET image = ? WHERE id = ?');
    $u->execute([$fname, $pid]);
    $updated++;
    echo "  Saved and assigned: $fname\n";
  } catch (Throwable $e) {
    $errors++; echo "  DB update error: " . $e->getMessage() . "\n";
  }
}

echo "\nDone. Updated: $updated, Skipped: $skipped, Errors: $errors.\n";
