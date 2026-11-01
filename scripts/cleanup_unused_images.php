<?php
require_once __DIR__ . '/../config.php';
requireRole('admin');

$page_title = 'Cleanup Unused Images';

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(16));
}

// Collect used images from DB
$used = [
    // normalized to relative paths from web root, e.g. "assets/img/xyz.jpg"
];

function add_used(&$set, $path) {
    if (!$path) return;
    // Normalize directory separators
    $rel = str_replace(['\\', '//'], '/', $path);
    // Trim leading ./ or /
    $rel = ltrim($rel, './');
    $set[$rel] = true;
}

// Products main images
try {
    $stmt = $pdo->query("SELECT image FROM products WHERE image IS NOT NULL AND image <> ''");
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        add_used($used, 'assets/img/' . $row['image']);
    }
} catch (Throwable $e) {}

// Products defect photos (CSV in products.defect_photos)
try {
    $stmt = $pdo->query("SELECT defect_photos FROM products WHERE defect_photos IS NOT NULL AND defect_photos <> ''");
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $csv = $row['defect_photos'];
        $parts = array_values(array_filter(array_map('trim', explode(',', (string)$csv))));
        foreach ($parts as $p) { add_used($used, 'assets/img/' . $p); }
    }
} catch (Throwable $e) {}

// Reviews images (CSV under assets/img/reviews)
try {
    $stmt = $pdo->query("SELECT images FROM reviews WHERE images IS NOT NULL AND images <> ''");
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $csv = $row['images'];
        $parts = array_values(array_filter(array_map('trim', explode(',', (string)$csv))));
        foreach ($parts as $p) { add_used($used, 'assets/img/reviews/' . $p); }
    }
} catch (Throwable $e) {}

// Complaints attachments (already stored as relative path)
try {
    $stmt = $pdo->query("SELECT attachment_path FROM complaints WHERE attachment_path IS NOT NULL AND attachment_path <> ''");
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        add_used($used, $row['attachment_path']);
    }
} catch (Throwable $e) {}

// Build present files list
function list_files($dir, $includeSubdirs = false) {
    $files = [];
    if (!is_dir($dir)) return $files;
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $fi) {
        if ($fi->isFile()) {
            $rel = str_replace(['\\', '//'], '/', substr($fi->getPathname(), strlen(dirname(__DIR__)) + 1));
            if (!$includeSubdirs) {
                // Only include files directly under $dir (not subfolders)
                $parent = str_replace(['\\', '//'], '/', dirname($rel));
                $expectedParent = str_replace(['\\', '//'], '/', str_replace(dirname(__DIR__) . '/', '', $dir));
            }
            $files[] = $rel;
        }
    }
    return $files;
}

$present = [];
// Product images (assets/img) including subfolders
$present = array_merge($present, list_files(__DIR__ . '/../assets/img'));
// Complaints
$present = array_merge($present, list_files(__DIR__ . '/../assets/complaints'));

// Compute unused = present - used
$usedSet = $used; // assoc
$presentSet = [];
foreach ($present as $p) { $presentSet[$p] = true; }

$unused = [];
foreach ($presentSet as $path => $_) {
    // Skip readme or placeholder files
    $base = strtolower(basename($path));
    if (in_array($base, ['readme.txt', '.gitkeep'])) continue;
    if (!isset($usedSet[$path])) {
        $unused[] = $path;
    }
}

$deleted = [];
$errorDel = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_unused') {
    $t = $_POST['csrf_token'] ?? '';
    if (!$t || !hash_equals($_SESSION['csrf_token'] ?? '', $t)) {
        http_response_code(400);
        exit('Invalid CSRF token');
    }
    foreach ($unused as $rel) {
        $abs = dirname(__DIR__) . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $rel);
        if (is_file($abs)) {
            if (@unlink($abs)) { $deleted[] = $rel; } else { $errorDel[] = $rel; }
        }
    }
    // Recompute after deletion
    header('Location: cleanup_unused_images.php?deleted=' . count($deleted) . '&failed=' . count($errorDel));
    exit();
}

include __DIR__ . '/../includes/header.php';
?>
<div class="container my-4">
  <div class="d-flex justify-content-between align-items-center mb-3">
    <h2><i class="fas fa-broom text-warning"></i> Cleanup Unused Images</h2>
    <a href="../admin_panel.php" class="btn btn-outline-secondary"><i class="fas fa-arrow-left"></i> Back</a>
  </div>

  <div class="alert alert-info">
    <i class="fas fa-info-circle"></i> This tool scans <code>assets/img/</code> and <code>assets/complaints/</code> and compares against DB references in <code>products</code>, <code>reviews</code>, and <code>complaints</code>. Default is dry-run. Use the Delete button to remove files safely.
  </div>

  <?php if (isset($_GET['deleted']) || isset($_GET['failed'])): ?>
    <div class="alert alert-<?php echo ((int)($_GET['failed'] ?? 0) === 0) ? 'success' : 'warning'; ?>">
      <i class="fas fa-check-circle"></i> Deleted <?php echo (int)($_GET['deleted'] ?? 0); ?> files. <?php $f=(int)($_GET['failed'] ?? 0); if ($f>0) echo $f . ' failed.'; ?>
    </div>
  <?php endif; ?>

  <div class="row g-3">
    <div class="col-md-4">
      <div class="card h-100">
        <div class="card-body">
          <div class="mb-2"><strong>Summary</strong></div>
          <ul class="list-unstyled mb-0">
            <li>Present files: <strong><?php echo count($present); ?></strong></li>
            <li>Referenced (used): <strong><?php echo count($usedSet); ?></strong></li>
            <li>Unused candidates: <strong><?php echo count($unused); ?></strong></li>
          </ul>
          <?php if (!empty($unused)): ?>
          <form method="POST" class="mt-3" onsubmit="return confirm('Delete all listed unused files? This cannot be undone.');">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
            <input type="hidden" name="action" value="delete_unused">
            <button class="btn btn-danger"><i class="fas fa-trash"></i> Delete Unused Files</button>
          </form>
          <?php else: ?>
            <div class="text-muted mt-3">No unused images detected.</div>
          <?php endif; ?>
        </div>
      </div>
    </div>
    <div class="col-md-8">
      <div class="card h-100">
        <div class="card-body">
          <div class="d-flex justify-content-between align-items-center mb-2">
            <strong>Unused Files (dry-run)</strong>
            <span class="badge bg-secondary"><?php echo count($unused); ?></span>
          </div>
          <div class="table-responsive" style="max-height: 480px; overflow:auto;">
            <table class="table table-sm table-striped align-middle mb-0">
              <thead><tr><th>Path</th><th>Size</th><th>Modified</th></tr></thead>
              <tbody>
              <?php if (empty($unused)): ?>
                <tr><td colspan="3" class="text-muted">Nothing to show.</td></tr>
              <?php else: foreach ($unused as $rel): $abs = dirname(__DIR__) . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $rel); ?>
                <tr>
                  <td><code><?php echo htmlspecialchars($rel); ?></code></td>
                  <td><?php echo is_file($abs) ? number_format(filesize($abs)) . ' bytes' : '-'; ?></td>
                  <td><?php echo is_file($abs) ? date('Y-m-d H:i', filemtime($abs)) : '-'; ?></td>
                </tr>
              <?php endforeach; endif; ?>
              </tbody>
            </table>
          </div>
        </div>
      </div>
    </div>
  </div>
</div>
<?php include __DIR__ . '/../includes/footer.php'; ?>
