<?php
require_once __DIR__ . '/config.php';

// Safety: allow only admins to run this
if (!isLoggedIn() || getUserRole() !== 'admin') {
    http_response_code(403);
    echo 'Forbidden: Admin only.';
    exit;
}

$page_title = 'Seed Demo Products';

// Helper: get or create a demo seller
function getDemoSellerId(PDO $pdo): int {
    // Try to find any seller
    $stmt = $pdo->query("SELECT id FROM users WHERE role = 'seller' ORDER BY id LIMIT 1");
    $sid = (int)($stmt->fetchColumn() ?: 0);
    if ($sid > 0) return $sid;

    // Create a seller if none exists
    $name = 'Demo Seller';
    $email = 'demo_seller_' . time() . '@retrade.com';
    $password = password_hash('password', PASSWORD_BCRYPT);
    $stmt = $pdo->prepare("INSERT INTO users (name, email, password, role, balance) VALUES (?, ?, ?, 'seller', 0)");
    $stmt->execute([$name, $email, $password]);
    return (int)$pdo->lastInsertId();
}

$messages = [];
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $count = max(1, min(200, (int)($_POST['count'] ?? 50)));
    $status = $_POST['status'] ?? 'approved'; // approved | pending | mixed
    $ensureAll = isset($_POST['ensure_all_categories']) ? (bool)$_POST['ensure_all_categories'] : true;
    $allowNoImages = isset($_POST['allow_no_images']) ? (bool)$_POST['allow_no_images'] : true;
    $useImages = true; // will be overridden if no images and allowNoImages is true

    try {
        $pdo->beginTransaction();

        // Categories pool
        $cats = [];
        $q = $pdo->query("SELECT name FROM categories ORDER BY name");
        $cats = $q->fetchAll(PDO::FETCH_COLUMN);
        if (empty($cats)) {
            $cats = ['Electronics','Clothing','Books','Home & Garden','Sports','Toys','Automotive','Health & Beauty'];
        }

        $sellerId = getDemoSellerId($pdo);

        // Helpers: ensure category exists and guess category from title
        $ensureCategory = function(PDO $pdo, string $name): string {
            $name = trim($name);
            if ($name === '') return $name;
            $st = $pdo->prepare('SELECT name FROM categories WHERE name = ?');
            $st->execute([$name]);
            if ($st->fetchColumn()) return $name;
            $ins = $pdo->prepare('INSERT INTO categories (name) VALUES (?)');
            $ins->execute([$name]);
            return $name;
        };
        $getCategoryId = function(PDO $pdo, string $name): ?int {
            $st = $pdo->prepare('SELECT id FROM categories WHERE name = ?');
            $st->execute([$name]);
            $id = $st->fetchColumn();
            return $id ? (int)$id : null;
        };
        $guessCategory = function(string $title): ?string {
            $t = strtolower($title);
            $map = [
                'Smartphones' => ['smartphone','phone','iphone','galaxy','redmi','oneplus','realme','vivo','oppo','pixel','motorola','iqoo','infinix','tecno'],
                'Laptops' => ['laptop','macbook','ideapad','inspiron','vivobook','tuf','thinkpad','aspire','victus','omen','pavilion'],
                'Headphones & Earbuds' => ['headphone','earbud','earphone','airdopes','buds','wh-','xm4','xm5'],
                'Wearables' => ['smartwatch','watch','colorfit','ninja'],
                'Speakers' => ['speaker','soundbar','flip 5','flip 6'],
                'Cameras' => ['camera','dslr','eos','nikon','alpha','a6000','lens'],
                'Home & Kitchen' => ['mixer','grinder','pressure cooker','air fryer','water purifier','purifier','induction cooktop'],
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
        };

        // Title fragments
        $brands = ['Acme','Nova','Zenith','Pulse','Vertex','Nimbus','Orion','Quantum','Aero','Vivid'];
        $items  = ['Headphones','Smartwatch','Backpack','Coffee Maker','LED Monitor','Wireless Mouse','Keyboard','Running Shoes','Bluetooth Speaker','DSLR Lens','Air Purifier','Gaming Chair','Portable SSD','Action Camera','E-book Reader'];
        $adjs   = ['Pro','Max','Lite','Ultra','Plus','Mini','X','S','Prime','Edge'];

        $insert = $pdo->prepare("INSERT INTO products (seller_id, title, description, price, image, category, category_id, status, condition_grade, condition_notes, defect_photos) VALUES (?,?,?,?,?,?,?,?,?,?,?)");

        // Seed images pool (place sample images in assets/img/seed/)
        $seedDir = __DIR__ . '/assets/img/seed';
        $imagePool = [];
        if (is_dir($seedDir)) {
            foreach (scandir($seedDir) as $f) {
                if (preg_match('/\.(jpe?g|png|gif|webp)$/i', $f)) { $imagePool[] = $f; }
            }
        }

        // If no images are present, either disable image usage (preferred for this task) or error out
        if (empty($imagePool)) {
            if ($allowNoImages) {
                $useImages = false;
            } else {
                throw new RuntimeException("No seed images found in assets/img/seed/. Upload seed images or check 'Allow seeding without images'.");
            }
        }

        // Prepare even distribution across categories when requested
        $catIndex = 0;

        for ($i=0; $i<$count; $i++) {
            $title = $brands[array_rand($brands)] . ' ' . $items[array_rand($items)] . ' ' . $adjs[array_rand($adjs)];
            // Choose category
            if ($ensureAll && !empty($cats)) {
                // cycle through the known categories to ensure coverage
                $pick = $cats[$catIndex % count($cats)];
                $cat = $ensureCategory($pdo, $pick);
                $catIndex++;
            } else {
                // Guess category from title; if none, fall back to random existing
                $g = $guessCategory($title);
                if ($g) {
                    $cat = $ensureCategory($pdo, $g);
                } else {
                    $cat = $cats[array_rand($cats)];
                }
            }
            $catId = $getCategoryId($pdo, $cat);
            $price = rand(900, 95000) + (rand(0,99)/100);
            $desc  = "Pre-owned $title. Fully functional. Minor cosmetic wear consistent with age.";

            // Condition seeding (weighted)
            $grades = ['Poor','Fair','Good','Excellent','Like New','New'];
            $weights = [5, 15, 40, 25, 12, 3]; // sums to 100
            $r = rand(1,100); $acc=0; $cond='Good';
            for ($g=0;$g<count($grades);$g++){ $acc+=$weights[$g]; if ($r <= $acc){ $cond=$grades[$g]; break; } }
            $notes = in_array($cond, ['Poor','Fair']) ? 'Visible wear and tear; tested working. See photos for scratches/dents.' : 'Well maintained; 100% functional.';

            // Main image + optional defect photos
            $img = null; $defect = [];
            if ($useImages && !empty($imagePool)) {
                $pick = $imagePool[array_rand($imagePool)];
                $ext  = pathinfo($pick, PATHINFO_EXTENSION);
                $destName = 'product_' . uniqid() . '.' . $ext;
                $destPath = __DIR__ . '/assets/img/' . $destName;
                if (!copy($seedDir . '/' . $pick, $destPath)) { $destName = null; }
                $img = $destName;

                // up to 2 defect photos (can repeat pool)
                $addCount = rand(0,2);
                for ($d=0; $d<$addCount; $d++) {
                    $p2 = $imagePool[array_rand($imagePool)];
                    $ext2 = pathinfo($p2, PATHINFO_EXTENSION);
                    $defName = 'defect_' . uniqid() . '.' . $ext2;
                    $defPath = __DIR__ . '/assets/img/defects/' . $defName;
                    if (!is_dir(__DIR__ . '/assets/img/defects')) mkdir(__DIR__ . '/assets/img/defects', 0755, true);
                    if (copy($seedDir . '/' . $p2, $defPath)) {
                        $defect[] = 'defects/' . $defName; // stored relative to assets/img
                    }
                }
            }

            $st = $status === 'mixed' ? (rand(0, 100) < 70 ? 'approved' : (rand(0,1) ? 'pending' : 'rejected')) : $status;

            $insert->execute([$sellerId, $title, $desc, $price, $img, $cat, $catId, $st, $cond, $notes, empty($defect) ? null : implode(',', $defect)]);
        }

        $pdo->commit();
        $messages[] = "Inserted $count demo products successfully.";
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $errors[] = 'Seeding failed: ' . $e->getMessage();
    }
}

include __DIR__ . '/includes/header.php';
?>
<div class="container my-4">
  <div class="row justify-content-center">
    <div class="col-lg-7">
      <div class="card">
        <div class="card-header d-flex align-items-center justify-content-between">
          <h5 class="mb-0"><i class="fas fa-seedling me-2 text-success"></i>Seed Demo Products</h5>
        </div>
        <div class="card-body">
          <?php foreach ($messages as $m): ?>
            <div class="alert alert-success"><i class="fas fa-check-circle me-1"></i><?php echo htmlspecialchars($m); ?></div>
          <?php endforeach; ?>
          <?php foreach ($errors as $er): ?>
            <div class="alert alert-danger"><i class="fas fa-exclamation-triangle me-1"></i><?php echo htmlspecialchars($er); ?></div>
          <?php endforeach; ?>

          <form method="post" class="row g-3">
            <div class="col-md-6">
              <label for="count" class="form-label">Number of products</label>
              <input type="number" min="1" max="200" class="form-control" id="count" name="count" value="100" required>
            </div>
            <div class="col-md-6">
              <label for="status" class="form-label">Default status</label>
              <select id="status" name="status" class="form-select">
                <option value="approved">Approved</option>
                <option value="pending">Pending</option>
                <option value="mixed" selected>Mixed</option>
              </select>
            </div>
            <div class="col-12">
              <div class="form-check">
                <input class="form-check-input" type="checkbox" id="ensure_all_categories" name="ensure_all_categories" checked>
                <label class="form-check-label" for="ensure_all_categories">Ensure even distribution across all categories</label>
              </div>
            </div>
            <div class="col-12">
              <div class="form-check">
                <input class="form-check-input" type="checkbox" id="allow_no_images" name="allow_no_images" checked>
                <label class="form-check-label" for="allow_no_images">Allow seeding without images (set images later)</label>
              </div>
            </div>
            <div class="col-12 small text-muted">
              If you place any sample images in <code>assets/img/seed/</code>, the seeder will copy them into <code>assets/img/</code> and attach them to products.
            </div>
            <div class="col-12 d-flex justify-content-between">
              <a href="index.php" class="btn btn-secondary"><i class="fas fa-arrow-left me-1"></i>Back</a>
              <button class="btn btn-success"><i class="fas fa-rocket me-1"></i>Seed Now</button>
            </div>
          </form>

          <div class="mt-3 text-muted-2 small">
            Tip: Upload image files to <code>assets/img/</code> and update the seeder to assign filenames if you want images.
          </div>
        </div>
      </div>
    </div>
  </div>
</div>
<?php include __DIR__ . '/includes/footer.php'; ?>
