<?php
// Read/write the admin password hash from a separate PHP file outside the
// admin/ directory. Storing it in PHP (returns array via `return`) means it
// is never served as plain content over HTTP, even if .htaccess fails.

function smzpulse_auth_path() {
    return __DIR__ . '/../auth.php';
}

function smzpulse_auth_load() {
    $path = smzpulse_auth_path();
    if (!is_file($path)) return [];
    $data = @include $path;
    return is_array($data) ? $data : [];
}

function smzpulse_auth_get_password_hash() {
    $auth = smzpulse_auth_load();
    return isset($auth['admin_password']) && is_string($auth['admin_password'])
        ? $auth['admin_password']
        : null;
}

function smzpulse_auth_save_password($hash) {
    $path = smzpulse_auth_path();
    $content = "<?php\nreturn " . var_export(['admin_password' => $hash], true) . ";\n";
    $tmp = $path . '.tmp.' . bin2hex(random_bytes(4));
    if (file_put_contents($tmp, $content) === false) {
        @unlink($tmp);
        return false;
    }
    @chmod($tmp, 0640);
    return rename($tmp, $path);
}

// ---- Standard-Passwort erkennen ----
// Diese Passwörter sind öffentlich bekannt (README / frühere Versionen).
// Solange eines davon aktiv ist, sind Online-Updates gesperrt.
function smzpulse_auth_is_default_password() {
    $hash = smzpulse_auth_get_password_hash();
    if (!is_string($hash) || $hash === '') return false;
    foreach (['smzpulse', 'admin'] as $known) {
        if (password_verify($known, $hash)) return true;
    }
    return false;
}

// ---- Schutz gegen Passwort-Durchprobieren ----
// Nach SMZPULSE_LOGIN_MAX_FAILS Fehlversuchen wird die IP für
// SMZPULSE_LOGIN_LOCK_SECONDS gesperrt. Gespeichert in storage/ (per .htaccess gesperrt).
const SMZPULSE_LOGIN_MAX_FAILS    = 5;
const SMZPULSE_LOGIN_LOCK_SECONDS = 900; // 15 Minuten

function smzpulse_login_attempts_path() {
    $dir = __DIR__ . '/../storage';
    if (!is_dir($dir)) @mkdir($dir, 0775, true);
    return $dir . '/login_attempts.json';
}

function smzpulse_login_client_key() {
    // Nur REMOTE_ADDR – X-Forwarded-For wäre vom Angreifer fälschbar.
    return hash('sha256', (string)($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
}

/** Liest/ändert die Fehlversuch-Liste unter Dateisperre. $fn bekommt das Array per Referenz. */
function smzpulse_login_attempts_update(callable $fn) {
    $fh = @fopen(smzpulse_login_attempts_path(), 'c+');
    if (!$fh) return null; // storage/ nicht beschreibbar → kein Lockout möglich
    flock($fh, LOCK_EX);
    $data = json_decode(stream_get_contents($fh), true);
    if (!is_array($data)) $data = [];
    // Abgelaufene Einträge aufräumen
    $now = time();
    foreach ($data as $k => $e) {
        if (($e['last'] ?? 0) < $now - SMZPULSE_LOGIN_LOCK_SECONDS) unset($data[$k]);
    }
    $result = $fn($data);
    ftruncate($fh, 0);
    rewind($fh);
    fwrite($fh, json_encode($data));
    fflush($fh);
    flock($fh, LOCK_UN);
    fclose($fh);
    return $result;
}

/** Sekunden, die diese IP noch gesperrt ist (0 = nicht gesperrt). */
function smzpulse_login_locked_for() {
    $key = smzpulse_login_client_key();
    $res = smzpulse_login_attempts_update(function (&$data) use ($key) {
        $e = $data[$key] ?? null;
        if (!$e || ($e['fails'] ?? 0) < SMZPULSE_LOGIN_MAX_FAILS) return 0;
        return max(0, ($e['last'] + SMZPULSE_LOGIN_LOCK_SECONDS) - time());
    });
    return (int)$res;
}

function smzpulse_login_register_failure() {
    $key = smzpulse_login_client_key();
    smzpulse_login_attempts_update(function (&$data) use ($key) {
        $fails = ($data[$key]['fails'] ?? 0) + 1;
        $data[$key] = ['fails' => $fails, 'last' => time()];
    });
}

function smzpulse_login_reset() {
    $key = smzpulse_login_client_key();
    smzpulse_login_attempts_update(function (&$data) use ($key) {
        unset($data[$key]);
    });
}
