<?php
// Admin-only: deduplicate products by normalized (title + category)
// Usage (browser): /scripts/dedupe_products.php?dry_run=1&delete_files=1&keep=oldest
// Params:
// - dry_run: 1 (default) to preview, 0 to execute deletes
// - delete_files: 1 to remove associated image files (assets/img/*) and defects; 0 to leave files (default 1)
// - keep: oldest | newest | approved_first (default: oldest)
// - scope: all | category:<name> (optional filter by category)
// Safety:
// - Skips any product having transactions
// - Preserves exactly one record per duplicate group
// - Logs a clear summary

require_once __DIR__ . '/../config.php';

if (!isLoggedIn() || getUserRole() !== 'admin') {
  http_response_code(403);
  echo 'Forbidden: Admin only.';
  exit;
}

header('Content-Type: text/plain; charset=UTF-8');
@set_time_limit(600);

$dry = ((string)($_GET['dry_run'] ?? '1') === '1');
$deleteFiles = ((string)($_GET['delete_files'] ?? '1') === '1');
$keep = strtolower((string)($_GET['keep'] ?? 'oldest'));
$scope = (string)($_GET['scope'] ?? 'all');

$imgBase = realpath(__DIR__ . '/../assets/img') ?: (__DIR__ . '/../assets/img');

function normTitle(string $s): string {
  $s = strtolower($s);
  // remove punctuation/specials
  $s = preg_replace('/[^a-z0-9\s]+/i', ' ', $s);
  // collapse spaces
  $s = trim(preg_replace('/\s+/', ' ', $s));
  return $s;
}

function groupKey(string $title, ?string $category): string {
  return normTitle($title) . '||' . strtolower((string)$category);
}

function hasTransactions(PDO $pdo, int $productId): bool {
  $st = $pdo->prepare('SELECT COUNT(*) FROM transactions WHERE product_id = ?');
  $st->execute([$productId]);
  return ((int)$st->fetchColumn()) > 0;
}

function removeFiles(string $imgBase, ?string $image, ?string $defects, bool $dry): array {
  $deleted = ['main' => 0, 'defects' => 0];
  if ($dry) return $deleted;
  if ($image) {
    $p = $imgBase . '/' . ltrim($image, '/');
    if (is_file($p) && @unlink($p)) { $deleted['main']++; }
  }
  if ($defects) {
    $parts = array_filter(array_map('trim', explode(',', $defects)));
    foreach ($parts as $rel) {
      $pf = $imgBase . '/' . ltrim($rel, '/');
      if (is_file($pf) && @unlink($pf)) { $deleted['defects']++; }
    }
  }
  return $deleted;
}

// Fetch candidates (optionally scoped)
$where = '';
$params = [];
if (strpos($scope, 'category:') === 0) {
  $cat = trim(substr($scope, strlen('category:')));
  $where = 'WHERE (category = :cat)';
  $params[':cat'] = $cat;
}
$sql = "SELECT id, seller_id, title, category, status, image, defect_photos, created_at FROM products $where ORDER BY id ASC";
$stmt = $pdo->prepare($sql);
foreach ($params as $k => $v) { $stmt->bindValue($k, $v); }
$stmt->execute();
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (!$rows) { echo "No products found (scope: $scope).\n"; exit; }

echo "Scanning products: " . count($rows) . "\n";

// Build groups by normalized (title+category)
$groups = [];
foreach ($rows as $r) {
  $key = groupKey($r['title'] ?? '', $r['category'] ?? '');
  if ($key === '||') continue; // skip empty
  if (!isset($groups[$key])) $groups[$key] = [];
  $groups[$key][] = $r;
}

$dupeGroups = array_filter($groups, function($items){ return count($items) > 1; });
if (empty($dupeGroups)) { echo "No duplicate groups found by (title+category).\n"; exit; }

echo "Duplicate groups found: " . count($dupeGroups) . "\n";

$totalToDelete = 0; $skippedTx = 0; $filesDeleted = 0; $defsDeleted = 0; $keptTotal = 0;

foreach ($dupeGroups as $key => $items) {
  // Choose one to keep based on strategy
  $keepItem = null;
  if ($keep === 'newest') {
    usort($items, function($a,$b){ return strcmp((string)$b['created_at'], (string)$a['created_at']); });
    $keepItem = $items[0];
  } elseif ($keep === 'approved_first') {
    usort($items, function($a,$b){
      $ra = ($a['status'] === 'approved') ? 0 : 1;
      $rb = ($b['status'] === 'approved') ? 0 : 1;
      if ($ra === $rb) { return strcmp((string)$a['created_at'], (string)$b['created_at']); }
      return $ra <=> $rb;
    });
    $keepItem = $items[0];
  } else { // oldest default
    usort($items, function($a,$b){ return strcmp((string)$a['created_at'], (string)$b['created_at']); });
    $keepItem = $items[0];
  }

  $keepId = (int)$keepItem['id'];
  $keptTotal++;

  // Others are candidates for deletion
  foreach ($items as $r) {
    $pid = (int)$r['id'];
    if ($pid === $keepId) continue;

    if (hasTransactions($pdo, $pid)) {
      $skippedTx++;
      echo "SKIP (has transactions) #$pid: " . $r['title'] . " [" . ($r['category'] ?? '') . "]\n";
      continue;
    }

    echo ($dry ? 'DRY RUN: would delete ' : 'Deleting ') . "duplicate #$pid (keep #$keepId): " . $r['title'] . "\n";

    if (!$dry) {
      // Delete files optionally
      if ($deleteFiles) {
        $del = removeFiles($imgBase, $r['image'] ?? null, $r['defect_photos'] ?? null, $dry);
        $filesDeleted += (int)$del['main'];
        $defsDeleted  += (int)$del['defects'];
      }
      // Delete row
      $delStmt = $pdo->prepare('DELETE FROM products WHERE id = ?');
      $delStmt->execute([$pid]);
      $totalToDelete++;
    } else {
      $totalToDelete++;
    }
  }
}

echo "\nSummary:\n";
echo "- Groups processed: " . count($dupeGroups) . "\n";
echo "- Kept one per group: $keptTotal\n";
echo "- Duplicates scheduled: $totalToDelete" . ($dry ? ' (dry run)' : '') . "\n";
echo "- Skipped due to transactions: $skippedTx\n";
if ($deleteFiles) {
  echo "- Files deleted: $filesDeleted (main), $defsDeleted (defects)" . ($dry ? ' (0 in dry run)' : '') . "\n";
} else {
  echo "- File deletion disabled\n";
}
