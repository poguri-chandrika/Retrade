<?php
// Fetch real-world product-like images from Pexels into assets/img/seed/
// Usage (browser): /scripts/fetch_real_images.php?categories=electronics,gadgets&per_cat=10
// Requires: define PEXELS_API_KEY via env or config.php

require_once __DIR__ . '/../config.php';

// Restrict to admin
if (!isLoggedIn() || getUserRole() !== 'admin') {
  http_response_code(403);
  echo 'Forbidden: Admin only.';
  exit;
}

header('Content-Type: text/plain; charset=UTF-8');

if (!PEXELS_API_KEY) {
  http_response_code(500);
  echo "Missing PEXELS_API_KEY. Set it in your environment or config.php.\n";
  echo "Windows PowerShell example:\n$env:PEXELS_API_KEY='YOUR_KEY'\n";
  exit;
}

// Params
$defaultCategories = [
  'electronics','gadgets','smartphone','laptop','headphones',
  'appliances','kitchen','furniture','fashion','shoes',
  'sports','tools','books','toys','camera'
];
// India-focused profile expands queries to common brands and product types in India
$indiaProfile = [
  // Smartphones
  'smartphone india', 'xiaomi smartphone', 'redmi phone', 'realme phone', 'oneplus phone', 'samsung galaxy india', 'vivo smartphone', 'oppo smartphone', 'pixel phone india',
  // Laptops
  'laptop india', 'hp laptop india', 'dell inspiron india', 'lenovo ideapad india', 'asus tuf laptop', 'acer aspire laptop', 'macbook india',
  // Audio & wearables
  'boat headphones', 'noise smartwatch', 'fire-boltt smartwatch', 'oneplus buds', 'sony headphones india', 'jbl speaker india',
  // Appliances & kitchen
  'mixer grinder india', 'pressure cooker india', 'induction cooktop', 'air fryer india', 'water purifier india',
  // Cameras & accessories
  'canon camera india', 'nikon camera india', 'sony alpha camera', 'dslr lens india',
  // Other electronics
  'power bank india', 'bluetooth speaker india', 'set top box india', 'router india'
];
$categories = isset($_GET['categories']) && $_GET['categories'] !== ''
  ? array_filter(array_map('trim', explode(',', $_GET['categories'])))
  : $defaultCategories;
$perCat = max(1, min(30, (int)($_GET['per_cat'] ?? 12))); // Pexels max per_page is 80 for curated, 30 for search
$pages  = max(1, min(5, (int)($_GET['pages'] ?? 1)));
$minWidth  = max(400, (int)($_GET['min_w'] ?? 800));
$minHeight = max(300, (int)($_GET['min_h'] ?? 600));
// When force=1, always save with a unique suffix even if a same-name file exists
$force = isset($_GET['force']) && (string)$_GET['force'] === '1';
// profile=india to bias queries for Indian market
$profile = strtolower((string)($_GET['profile'] ?? ''));
if ($profile === 'india') {
  // If user didn't pass explicit categories, use India profile list
  if (!isset($_GET['categories']) || $_GET['categories'] === '') {
    $categories = $indiaProfile;
  }
}
// Locale hint for Pexels search (hi-IN often helps surface India-relevant content)
$locale = (string)($_GET['locale'] ?? ($profile === 'india' ? 'hi-IN' : 'en-US'));

$seedDir = __DIR__ . '/../assets/img/seed';
if (!is_dir($seedDir)) {
  mkdir($seedDir, 0755, true);
}

function fetchPexelsSearch($query, $page, $perPage, $minW, $minH, $locale) {
  $url = 'https://api.pexels.com/v1/search?' . http_build_query([
    'query' => $query,
    'per_page' => $perPage,
    'page' => $page,
    'orientation' => 'landscape',
    'locale' => $locale,
  ]);
  $ch = curl_init($url);
  curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Authorization: ' . PEXELS_API_KEY
  ]);
  curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
  curl_setopt($ch, CURLOPT_TIMEOUT, 20);
  $resp = curl_exec($ch);
  if ($resp === false) {
    $err = curl_error($ch);
    curl_close($ch);
    throw new RuntimeException('Pexels request failed: ' . $err);
  }
  $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
  curl_close($ch);
  if ($code !== 200) {
    throw new RuntimeException('Pexels HTTP ' . $code . ' body: ' . substr($resp, 0, 500));
  }
  $data = json_decode($resp, true);
  if (!is_array($data) || !isset($data['photos'])) return [];
  // Filter by min size
  $photos = array_filter($data['photos'], function($p) use ($minW, $minH) {
    return ($p['width'] ?? 0) >= $minW && ($p['height'] ?? 0) >= $minH;
  });
  return array_values($photos);
}

function downloadImage($url, $destPath) {
  $ch = curl_init($url);
  curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
  curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
  curl_setopt($ch, CURLOPT_TIMEOUT, 30);
  curl_setopt($ch, CURLOPT_USERAGENT, 'RetradeFetcher/1.0');
  curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
  $data = curl_exec($ch);
  if ($data === false) {
    $err = curl_error($ch);
    curl_close($ch);
    throw new RuntimeException('Download failed: ' . $err);
  }
  $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
  $ct   = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
  curl_close($ch);
  if ($code !== 200) {
    throw new RuntimeException('Download HTTP ' . $code);
  }
  if (!$ct || stripos($ct, 'image/') !== 0) {
    throw new RuntimeException('Unexpected content-type: ' . $ct);
  }
  // Ensure directory exists
  $dir = dirname($destPath);
  if (!is_dir($dir)) mkdir($dir, 0755, true);

  if (file_put_contents($destPath, $data) === false) {
    throw new RuntimeException('Failed to write file: ' . $destPath);
  }
}

$totalSaved = 0;
$skipped = 0;
$errors = 0;

foreach ($categories as $cat) {
  echo "Category: $cat\n";
  for ($p = 1; $p <= $pages; $p++) {
    try {
      $photos = fetchPexelsSearch($cat, $p, $perCat, $minWidth, $minHeight, $locale);
    } catch (Throwable $e) {
      $errors++;
      echo "  Error fetching: " . $e->getMessage() . "\n";
      continue;
    }
    if (empty($photos)) {
      echo "  No results.\n";
      continue;
    }
    foreach ($photos as $ph) {
      $id = $ph['id'] ?? uniqid();
      $src = $ph['src']['large2x'] ?? ($ph['src']['large'] ?? ($ph['src']['original'] ?? null));
      if (!$src) { $skipped++; continue; }
      $safeCat = preg_replace('/[^a-z0-9]+/i', '_', $cat);
      $base = sprintf('seed_%s_%s', strtolower($safeCat), $id);
      $name = $base . '.jpg';
      $dest = $seedDir . '/' . $name;
      if (file_exists($dest)) {
        if ($force) {
          $name = $base . '_' . uniqid('', true) . '.jpg';
          $dest = $seedDir . '/' . $name;
        } else {
          $skipped++;
          continue;
        }
      }
      try {
        downloadImage($src, $dest);
        $totalSaved++;
        echo "  Saved: $name\n";
      } catch (Throwable $e) {
        $errors++;
        echo "  Download error: " . $e->getMessage() . "\n";
      }
    }
  }
}

echo "\nDone. Saved: $totalSaved, Skipped: $skipped, Errors: $errors.\n";

echo "\nNext: Run seed_products.php to populate products using these images.\n";
