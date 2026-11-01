<?php
// Admin-only: assign a placeholder image to products missing images
// Usage: /scripts/set_placeholder_images.php?file=placeholder.jpg&scope=missing&dry_run=1
// - file: filename under assets/img/ to assign (default: placeholder.jpg)
// - scope: missing | all (default: missing)
// - dry_run: 1 to preview (default), 0 to execute

require_once __DIR__ . '/../config.php';

if (!isLoggedIn() || getUserRole() !== 'admin') {
  http_response_code(403);
  echo 'Forbidden: Admin only.';
  exit;
}

header('Content-Type: text/plain; charset=UTF-8');

$fname = basename((string)($_GET['file'] ?? 'placeholder.jpg'));
$scope = (string)($_GET['scope'] ?? 'missing');
$dry   = ((string)($_GET['dry_run'] ?? '1') === '1');

$imgPath = __DIR__ . '/../assets/img/' . $fname;
if (!is_file($imgPath)) {
  http_response_code(400);
  echo "Image not found under assets/img: $fname\n";
  exit;
}

$where = ($scope === 'all') ? '1=1' : '(image IS NULL OR image = "")';
$sqlCount = "SELECT COUNT(*) FROM products WHERE $where";
$toUpdate = (int)$pdo->query($sqlCount)->fetchColumn();

echo "Products matching scope '$scope': $toUpdate\n";
if ($toUpdate === 0) { exit; }

if ($dry) {
  echo "DRY RUN: would set image='$fname' for these products.\n";
  exit;
}

$u = $pdo->prepare("UPDATE products SET image = ? WHERE $where");
$u->execute([$fname]);
$affected = $u->rowCount();

echo "Updated products: $affected (image set to $fname)\n";
