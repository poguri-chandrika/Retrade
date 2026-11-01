<?php
// Remove demo/seeded products everywhere (admin-only)
// Usage:
//   /scripts/remove_demo_products.php?dry_run=1            -> show what would be deleted (default)
//   /scripts/remove_demo_products.php?dry_run=0            -> actually delete
//   /scripts/remove_demo_products.php?limit=500&dry_run=0  -> limit batch size
// Heuristics used to identify demo items:
// - Description contains the seeder phrase: "Fully functional. Minor cosmetic wear consistent with age."
// - Title looks like a seeded combo: Brand + Item + Suffix, using known lists from seeder
// - Defect photos path pattern like 'defects/defect_*.ext'
// - Seller email looks like demo_seller_*@retrade.com
// Safety:
// - Skips products with any transactions
// - Deletes associated images under assets/img/ and defects under assets/img/defects/
// - Prints a summary

require_once __DIR__ . '/../config.php';

if (!isLoggedIn() || getUserRole() !== 'admin') {
    http_response_code(403);
    echo 'Forbidden: Admin only.';
    exit;
}

header('Content-Type: text/plain; charset=UTF-8');
@set_time_limit(600);

$dry     = (string)($_GET['dry_run'] ?? '1') === '1'; // default DRY RUN
$limit   = max(1, min(2000, (int)($_GET['limit'] ?? 500)));

// Seed lists from seed_products.php
$brands = ['Acme','Nova','Zenith','Pulse','Vertex','Nimbus','Orion','Quantum','Aero','Vivid'];
$items  = ['Headphones','Smartwatch','Backpack','Coffee Maker','LED Monitor','Wireless Mouse','Keyboard','Running Shoes','Bluetooth Speaker','DSLR Lens','Air Purifier','Gaming Chair','Portable SSD','Action Camera','E-book Reader'];
$adjs   = ['Pro','Max','Lite','Ultra','Plus','Mini','X','S','Prime','Edge'];

// MySQL REGEXP for brand/item/adj lists
$brandsRe = '(' . implode('|', array_map(function($s){ return preg_quote($s, '/'); }, $brands)) . ')';
$itemsRe  = '(' . implode('|', array_map(function($s){ return preg_quote($s, '/'); }, $items)) . ')';
$adjsRe   = '(' . implode('|', array_map(function($s){ return preg_quote($s, '/'); }, $adjs)) . ')';

// Additional relaxed patterns
// - Title starts with one of the seed brands
$brandPrefixRe = '^' . $brandsRe . '\\b';
// - Title contains any seeded item term anywhere
$itemAnywhereRe = $itemsRe; // used with REGEXP without anchors

// Candidate query with multiple heuristics and join to users for seller email check
$sql = "
SELECT p.*,
       u.email AS seller_email,
       (SELECT COUNT(*) FROM transactions t WHERE t.product_id = p.id) AS tx_count
FROM products p
LEFT JOIN users u ON u.id = p.seller_id
WHERE (
    -- Exact seeder phrase in description
    p.description LIKE :seedPhrase
    -- Known seeded condition notes
    OR p.condition_notes LIKE :condNotes1
    OR p.condition_notes LIKE :condNotes2
    -- Defect photos pattern
    OR p.defect_photos REGEXP :defectRe
    -- Strict title pattern Brand ... Item Adj
    OR p.title REGEXP :titleRe
    -- Relaxed: title starts with a seed brand
    OR p.title REGEXP :brandPrefixRe
    -- Relaxed: title contains any seeded item term
    OR p.title REGEXP :itemAnywhereRe
    -- Demo seller email
    OR u.email REGEXP :demoEmailRe
    -- Image name created by seeders (product_*.ext)
    OR p.image REGEXP :productImgRe
)
ORDER BY p.id DESC
LIMIT :lim
";

$seedPhrase = '%Fully functional. Minor cosmetic wear consistent with age.%';
$condNotes1 = '%Well maintained; 100% functional.%';
$condNotes2 = '%Visible wear and tear; tested working.%';
$defectRe = '(^|,)?defects/defect_.*\.(jpe?g|png|gif|webp)($|,)';
$titleRe  = '^' . $brandsRe . ' .* ' . $itemsRe . ' ' . $adjsRe . '$';
$brandPrefix = $brandPrefixRe;
$itemAnywhere = $itemAnywhereRe;
$demoEmailRe = '^demo_seller_.*@retrade\.com$';
$productImgRe = '^product_.*\.(jpe?g|png|gif|webp)$';

$stmt = $pdo->prepare($sql);
$stmt->bindValue(':seedPhrase', $seedPhrase, PDO::PARAM_STR);
$stmt->bindValue(':condNotes1', $condNotes1, PDO::PARAM_STR);
$stmt->bindValue(':condNotes2', $condNotes2, PDO::PARAM_STR);
$stmt->bindValue(':defectRe', $defectRe, PDO::PARAM_STR);
$stmt->bindValue(':titleRe', $titleRe, PDO::PARAM_STR);
$stmt->bindValue(':brandPrefixRe', $brandPrefix, PDO::PARAM_STR);
$stmt->bindValue(':itemAnywhereRe', $itemAnywhere, PDO::PARAM_STR);
$stmt->bindValue(':demoEmailRe', $demoEmailRe, PDO::PARAM_STR);
$stmt->bindValue(':productImgRe', $productImgRe, PDO::PARAM_STR);
$stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
$stmt->execute();
$rows = $stmt->fetchAll();

if (!$rows) {
    echo "No candidate demo products found.\n";
    exit;
}

echo "Candidates found: " . count($rows) . " (showing up to $limit).\n";

$imgDir = realpath(__DIR__ . '/../assets/img') ?: (__DIR__ . '/../assets/img');
$deleted = 0; $skippedTx = 0; $filesDeleted = 0; $defFilesDeleted = 0; $mainFilesDeleted = 0;

foreach ($rows as $p) {
    $pid = (int)$p['id'];
    $title = (string)$p['title'];
    $email = (string)($p['seller_email'] ?? '');
    $txc = (int)$p['tx_count'];

    if ($txc > 0) {
        $skippedTx++;
        echo "SKIP #$pid (has $txc transactions): $title\n";
        continue;
    }

    echo ($dry ? 'DRY RUN: would delete ' : 'Deleting ') . "product #$pid: $title (seller: $email)\n";

    if (!$dry) {
        // Delete files: main image
        if (!empty($p['image'])) {
            $mf = $imgDir . '/' . ltrim($p['image'], '/');
            if (is_file($mf) && @unlink($mf)) { $filesDeleted++; $mainFilesDeleted++; }
        }
        // Delete defect photos (comma-separated relative paths stored under assets/img)
        if (!empty($p['defect_photos'])) {
            $parts = array_filter(array_map('trim', explode(',', $p['defect_photos'])));
            foreach ($parts as $rel) {
                $pf = $imgDir . '/' . ltrim($rel, '/');
                if (is_file($pf) && @unlink($pf)) { $filesDeleted++; $defFilesDeleted++; }
            }
        }
        // Finally delete product row
        $del = $pdo->prepare('DELETE FROM products WHERE id = ?');
        $del->execute([$pid]);
        $deleted++;
    }
}

if ($dry) {
    echo "\nDRY RUN complete. To execute, re-run with dry_run=0.\n";
}

echo "\nSummary:\n";
echo "- Products deleted: $deleted" . ($dry ? ' (0 in dry run)' : '') . "\n";
echo "- Skipped due to transactions: $skippedTx\n";
echo "- Files deleted: $filesDeleted (main: $mainFilesDeleted, defects: $defFilesDeleted)\n";
