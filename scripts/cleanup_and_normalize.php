<?php
// Cleanup unreal seed images and normalize product categories based on title keywords
// Usage (browser): /scripts/cleanup_and_normalize.php?fix_categories=1&remove_placeholders=1&aggressive=0&dry_run=0
// - fix_categories: if 1, updates product.category using title keywords and ensures categories exist
// - remove_placeholders: if 1, deletes generated placeholder seed files (seed_#.jpg/svg) in assets/img/seed/
// - aggressive=1: also null out product images and optionally delete product_* files under assets/img/ (DANGEROUS)
// - dry_run=1: show what would happen without making changes

require_once __DIR__ . '/../config.php';

if (!isLoggedIn() || getUserRole() !== 'admin') {
  http_response_code(403);
  echo 'Forbidden: Admin only.';
  exit;
}

header('Content-Type: text/plain; charset=UTF-8');
@set_time_limit(600);

$fixCategories = (string)($_GET['fix_categories'] ?? '0') === '1';
$removePlace   = (string)($_GET['remove_placeholders'] ?? '0') === '1';
$aggressive    = (string)($_GET['aggressive'] ?? '0') === '1';
$dry           = (string)($_GET['dry_run'] ?? '0') === '1';

$seedDir = __DIR__ . '/../assets/img/seed';
$imgDir  = __DIR__ . '/../assets/img';

function ensureCategory(PDO $pdo, string $name): string {
  $name = trim($name);
  if ($name === '') return $name;
  $stmt = $pdo->prepare('SELECT name FROM categories WHERE name = ?');
  $stmt->execute([$name]);
  if ($stmt->fetchColumn()) return $name;
  $ins = $pdo->prepare('INSERT INTO categories (name) VALUES (?)');
  $ins->execute([$name]);
  return $name;
}

function guessCategory(string $title): ?string {
  $t = strtolower($title);
  $map = [
    'Smartphones' => ['smartphone','phone','iphone','galaxy','redmi','oneplus','realme','vivo','oppo','pixel','motorola','iqoo','infinix','tecno'],
    'Laptops' => ['laptop','macbook','ideapad','inspiron','vivobook','tuf','thinkpad','aspire','victus','omen','pavilion'],
    'Headphones & Earbuds' => ['headphone','earbud','earphone','airdopes','buds','wh-','xm4','xm5'],
    'Wearables' => ['smartwatch','watch','colorfit','ninja'],
    'Speakers' => ['speaker','soundbar','flip 5','flip 6'],
    'Cameras' => ['camera','dslr','eos','nikon','alpha','a6000','lens'],
    'Home Appliances' => ['mixer','grinder','pressure cooker','air fryer','water purifier','purifier','induction cooktop'],
    'Networking' => ['router','wi-fi','wifi','archer'],
    'Accessories' => ['power bank','charger','cable','adapter','pendrive','sd card','memory card'],
    'Bags' => ['backpack','bagpack','bag']
  ];
  foreach ($map as $cat => $keywords) {
    foreach ($keywords as $kw) {
      if (strpos($t, $kw) !== false) return $cat;
    }
  }
  return null;
}

$deletedSeed = 0; $nulledImages = 0; $deletedImages = 0; $updatedCats = 0;

// 1) Remove generated placeholder seeds (seed_#.jpg / .svg) in seed folder
if ($removePlace) {
  if (is_dir($seedDir)) {
    $files = scandir($seedDir);
    foreach ($files as $f) {
      if ($f === '.' || $f === '..') continue;
      // generator pattern: seed_1.jpg ... seed_40.jpg or .svg
      if (preg_match('/^seed_\d+\.(jpg|jpeg|svg)$/i', $f)) {
        $path = $seedDir . '/' . $f;
        if ($dry) {
          echo "DRY RUN: would delete placeholder seed: $path\n";
        } else {
          if (@unlink($path)) { $deletedSeed++; echo "Deleted placeholder seed: $path\n"; }
        }
      }
    }
  } else {
    echo "Seed directory not found: $seedDir\n";
  }
}

// 2) Aggressive cleanup: null out product images and delete product_* files (dangerous)
if ($aggressive) {
  echo "Aggressive cleanup enabled.\n";
  if (!$dry) {
    $pdo->exec("UPDATE products SET image = NULL");
    $nulledImages = $pdo->query("SELECT ROW_COUNT()")->fetchColumn();
  }
  // Delete product_* files under assets/img
  if (is_dir($imgDir)) {
    $rii = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($imgDir, FilesystemIterator::SKIP_DOTS));
    foreach ($rii as $file) {
      if ($file->isDir()) continue;
      $name = $file->getFilename();
      if (preg_match('/^product_.*\.(jpe?g|png|gif|webp)$/i', $name)) {
        $p = $file->getPathname();
        if ($dry) {
          echo "DRY RUN: would delete image: $p\n";
        } else {
          if (@unlink($p)) { $deletedImages++; echo "Deleted image: $p\n"; }
        }
      }
    }
  }
}

// 3) Fix categories based on title
if ($fixCategories) {
  $stmt = $pdo->query('SELECT id, title, category FROM products');
  $rows = $stmt->fetchAll();
  foreach ($rows as $r) {
    $pid = (int)$r['id'];
    $title = (string)$r['title'];
    $curr = (string)($r['category'] ?? '');
    $guess = guessCategory($title);
    if ($guess && strcasecmp($guess, $curr) !== 0) {
      $finalCat = ensureCategory($pdo, $guess);
      if ($dry) {
        echo "DRY RUN: would set category for #$pid from '$curr' to '$finalCat' (title: $title)\n";
      } else {
        $u = $pdo->prepare('UPDATE products SET category = ? WHERE id = ?');
        $u->execute([$finalCat, $pid]);
        $updatedCats++;
        echo "Updated category for #$pid to '$finalCat'\n";
      }
    }
  }
}

echo "\nSummary:\n";
if ($removePlace) echo "- Placeholder seeds deleted: $deletedSeed\n";
if ($aggressive) echo "- Product images nulled in DB: $nulledImages, files deleted: $deletedImages\n";
if ($fixCategories) echo "- Product categories updated: $updatedCats\n";
if (!$removePlace && !$aggressive && !$fixCategories) echo "Nothing to do. Pass parameters.\n";
