<?php
// Shared session setup & inactivity-timeout logic.
// Included by index.php, dashboard.php, and api.php.

const SMZPULSE_INACTIVITY_TIMEOUT = 1800; // 30 minutes

function smzpulse_init_session() {
    if (session_status() === PHP_SESSION_ACTIVE) return;
    ini_set('session.cookie_httponly', '1');
    ini_set('session.use_strict_mode', '1');
    ini_set('session.cookie_samesite', 'Lax');
    // Unter HTTPS das Cookie nur verschlüsselt übertragen
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['SERVER_PORT'] ?? '') == 443);
    if ($https) {
        ini_set('session.cookie_secure', '1');
    }
    session_start();
}

/**
 * Verify the user is logged in and the session hasn't timed out.
 *
 * @param bool $touch  If true, treat this request as user activity and refresh
 *                     last_activity. Pass false for pure liveness checks (ping).
 * @return bool  true if session is valid, false if not logged in or expired.
 *               When false, $_SESSION is already cleared.
 */
function smzpulse_check_timeout($touch = true) {
    if (empty($_SESSION['loggedin'])) {
        return false;
    }

    $now  = time();
    $last = $_SESSION['last_activity'] ?? $now;

    if (($now - $last) > SMZPULSE_INACTIVITY_TIMEOUT) {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000,
                $p['path'], $p['domain'], $p['secure'], $p['httponly']);
        }
        session_destroy();
        return false;
    }

    if ($touch) {
        $_SESSION['last_activity'] = $now;
    }
    return true;
}
