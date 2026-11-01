<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo isset($page_title) ? $page_title . ' - Retrade' : 'Retrade - Marketplace'; ?></title>
    
    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <!-- Inter font for Material feel -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <!-- Custom CSS with cache-busting -->
    <?php
        $styleV = @filemtime(__DIR__ . '/../assets/css/style.css') ?: time();
        $adminV = @filemtime(__DIR__ . '/../assets/css/admin.css') ?: time();
        $chatV  = @filemtime(__DIR__ . '/../assets/css/chat.css') ?: time();
    ?>
    <link rel="stylesheet" href="assets/css/style.css?v=<?php echo $styleV; ?>">
    <link rel="stylesheet" href="assets/css/admin.css?v=<?php echo $adminV; ?>">
    <link rel="stylesheet" href="assets/css/chat.css?v=<?php echo $chatV; ?>">
    <link rel="stylesheet" href="assets/css/order-actions.css">
  </head>
  <body>
    <div class="layout">
        <?php include __DIR__ . '/sidebar.php'; ?>
        <div class="main">
            <header class="topbar">
                <div class="d-flex align-items-center gap-2">
                    <button class="btn btn-link text-white d-lg-none p-0 me-1" id="sidebarToggle" aria-label="Toggle sidebar">
                        <i class="fas fa-bars"></i>
                    </button>
                    <div class="title"><?php echo htmlspecialchars($page_title ?? ''); ?></div>
                </div>
                <div class="actions">
                    <?php if (isLoggedIn() && getUserRole()==='buyer'): ?>
                        <?php
                          try {
                            $cc = (int)($pdo->query("SELECT COUNT(*) FROM cart WHERE buyer_id=".(int)($_SESSION['user_id']??0))->fetchColumn() ?: 0);
                          } catch (Throwable $e) { $cc = 0; }
                        ?>
                        <a href="cart.php" class="icon-btn position-relative" title="Cart" aria-label="Open cart">
                            <i class="fas fa-shopping-cart"></i>
                            <?php if ($cc>0): ?><span class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-warning text-dark"><?php echo $cc; ?></span><?php endif; ?>
                        </a>
                    <?php endif; ?>
                    <div class="dropdown me-2">
                        <a href="#" class="icon-btn position-relative" id="notifDropdown" data-bs-toggle="dropdown" aria-expanded="false" title="Notifications" aria-label="Open notifications">
                            <i class="far fa-bell"></i>
                            <span id="notifBadge" class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger" style="display:none">0</span>
                        </a>
                        <div class="dropdown-menu dropdown-menu-end p-0" aria-labelledby="notifDropdown" style="min-width: 360px;">
                            <div class="p-2 d-flex justify-content-between align-items-center border-bottom">
                                <strong>Notifications</strong>
                                <button class="btn btn-sm btn-outline-secondary" id="notifMarkAll">Mark all as read</button>
                            </div>
                            <div id="notifList" style="max-height: 360px; overflow:auto;">
                                <div class="p-3 text-muted small">Loading…</div>
                            </div>
                            <div class="p-2 border-top text-center small text-muted">Latest updates appear here</div>
                        </div>
                    </div>
                    <div class="dropdown me-2">
                        <a href="#" class="icon-btn position-relative" id="messagesDropdown" data-bs-toggle="dropdown" aria-expanded="false" title="Messages" aria-label="Open messages">
                            <i class="far fa-envelope"></i>
                            <span id="messagesBadge" class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-warning text-dark" style="display:none">0</span>
                        </a>
                        <div class="dropdown-menu dropdown-menu-end p-0" aria-labelledby="messagesDropdown" style="min-width: 380px;">
                            <div class="p-2 d-flex justify-content-between align-items-center border-bottom">
                                <div class="d-flex align-items-center gap-2">
                                  <strong>Messages</strong>
                                  <div class="form-check form-switch m-0 small">
                                    <input class="form-check-input" type="checkbox" id="messagesOnlyUnread">
                                    <label class="form-check-label" for="messagesOnlyUnread">Unread only</label>
                                  </div>
                                </div>
                            </div>
                            <div id="messagesList" style="max-height: 380px; overflow:auto;">
                                <div class="p-3 text-muted small">Loading…</div>
                            </div>
                            <div class="p-2 border-top text-center small text-muted">Your recent chats</div>
                        </div>
                    </div>
                    <?php if (isset($_SESSION['user_name'])): ?>
                        <span class="d-none d-md-inline text-white-50 me-1"><?php echo htmlspecialchars($_SESSION['user_name']); ?></span>
                    <?php endif; ?>
                    <img id="profileAvatarBtn" src="https://ui-avatars.com/api/?name=<?php echo urlencode($_SESSION['user_name'] ?? 'User'); ?>&background=3f51b5&color=fff" alt="avatar" class="avatar" style="cursor:pointer" title="View Profile">
                </div>
            </header>
            <main class="content">
