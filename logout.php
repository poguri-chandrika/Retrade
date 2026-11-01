<?php
require_once 'config.php';

// Clear all session data and cookie
if (session_status() === PHP_SESSION_ACTIVE) {
    // Unset all variables
    $_SESSION = [];

    // Delete the session cookie
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
    }

    // Destroy the session
    session_destroy();
}

header('Location: index.php?message=logged_out');
exit();
?>
