<?php
// Nutzerverwaltung ohne Datenbank.
//
// Die Nutzer liegen in users.php im smzPulse-Hauptordner. Als PHP-Datei, die
// ihre Daten per `return` liefert, gibt sie bei direktem Aufruf über HTTP
// nichts aus – zusätzlich sperrt die .htaccess sie. Passwörter: bcrypt.
//
// Rollen:
//   admin   – alles (Geräte, Einstellungen, Nutzer, Updates)
//   editor  – Bilder und Ticker-Texte, eigenes Passwort
//
// Jeder Nutzer hat eine Passwort-Version (pwv). Sie steht auch in der Session;
// ändert sich das Passwort oder wird der Nutzer gelöscht, sind alle anderen
// Sitzungen sofort ungültig.

const SMZPULSE_ROLES = ['admin' => 'Admin', 'editor' => 'Redakteur'];
const SMZPULSE_DEFAULT_PASSWORDS = ['smzpulse', 'admin'];
const SMZPULSE_PASSWORD_MIN_LENGTH = 8;

// ---- Altes Einzel-Passwort (auth.php) – nur noch für die Übernahme ----
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

// ---- Nutzer-Datei ----
function smzpulse_users_path() {
    return __DIR__ . '/../users.php';
}

function smzpulse_is_password_hash($pw) {
    return is_string($pw) && (strpos($pw, '$2y$') === 0 || strpos($pw, '$2a$') === 0 || strpos($pw, '$argon2') === 0);
}

/** Erster Start nach dem Update: bisherigen Admin-Zugang als Nutzer "admin" übernehmen. */
function smzpulse_users_initial() {
    $hash = smzpulse_auth_get_password_hash();
    if (!smzpulse_is_password_hash($hash)) {
        // Sehr alte Installationen: Passwort stand in config.json (Hash oder Klartext)
        $config = @json_decode((string)@file_get_contents(__DIR__ . '/../config.json'), true);
        $legacy = is_array($config) ? ($config['admin_password'] ?? null) : null;
        if (smzpulse_is_password_hash($legacy)) {
            $hash = $legacy;
        } elseif (is_string($legacy) && $legacy !== '') {
            $hash = password_hash($legacy, PASSWORD_DEFAULT);
        } else {
            $hash = password_hash('smzpulse', PASSWORD_DEFAULT);
        }
    }
    return ['admin' => ['hash' => $hash, 'role' => 'admin', 'pwv' => 1, 'created' => date('c')]];
}

function smzpulse_users_read_file() {
    $path = smzpulse_users_path();
    if (!is_file($path)) return null;
    $data = @include $path;
    return (is_array($data) && isset($data['users']) && is_array($data['users'])) ? $data['users'] : null;
}

function smzpulse_users_write_file(array $users) {
    $path = smzpulse_users_path();
    ksort($users);
    $content = "<?php\n// smzPulse-Nutzer – wird vom Admin-Bereich verwaltet, nicht von Hand bearbeiten.\nreturn "
        . var_export(['users' => $users], true) . ";\n";
    $tmp = $path . '.tmp.' . bin2hex(random_bytes(4));
    if (file_put_contents($tmp, $content) === false) {
        @unlink($tmp);
        return false;
    }
    @chmod($tmp, 0640);
    if (!rename($tmp, $path)) {
        @unlink($tmp);
        return false;
    }
    if (function_exists('opcache_invalidate')) @opcache_invalidate($path, true);
    return true;
}

/** Alle Nutzer lesen (legt users.php beim ersten Aufruf an). */
function smzpulse_users_load() {
    $users = smzpulse_users_read_file();
    if ($users !== null) return $users;
    return smzpulse_users_update(function (&$u) { /* nur anlegen */ }) === false
        ? smzpulse_users_initial()   // nicht beschreibbar: wenigstens Login ermöglichen
        : (smzpulse_users_read_file() ?? []);
}

/**
 * Nutzerliste unter Dateisperre ändern. $fn bekommt das Array per Referenz und
 * darf einen Fehlertext (string) zurückgeben, dann wird nichts gespeichert.
 * Rückgabe: true, Fehlertext oder false (Datei nicht beschreibbar).
 */
function smzpulse_users_update(callable $fn) {
    $lockFile = __DIR__ . '/../users.lock';
    $fh = @fopen($lockFile, 'c');
    if (!$fh) return false;
    flock($fh, LOCK_EX);
    try {
        $users = smzpulse_users_read_file();
        if ($users === null) $users = smzpulse_users_initial();
        $err = $fn($users);
        if (is_string($err)) return $err;
        return smzpulse_users_write_file($users);
    } finally {
        flock($fh, LOCK_UN);
        fclose($fh);
    }
}

function smzpulse_valid_username($name) {
    return is_string($name) && preg_match('/^[a-z0-9._-]{2,32}$/', $name);
}

/** Fehlertext oder null. */
function smzpulse_password_problem($pw) {
    if (!is_string($pw) || strlen($pw) < SMZPULSE_PASSWORD_MIN_LENGTH) {
        return 'Das Passwort muss mindestens ' . SMZPULSE_PASSWORD_MIN_LENGTH . ' Zeichen lang sein.';
    }
    if (in_array(strtolower($pw), array_merge(SMZPULSE_DEFAULT_PASSWORDS, ['password', 'passwort']), true)) {
        return 'Dieses Passwort ist zu bekannt. Bitte ein anderes wählen.';
    }
    if (strlen($pw) > 200) {
        return 'Das Passwort ist zu lang.';
    }
    return null;
}

function smzpulse_hash_is_default($hash) {
    if (!is_string($hash) || $hash === '') return false;
    foreach (SMZPULSE_DEFAULT_PASSWORDS as $known) {
        if (password_verify($known, $hash)) return true;
    }
    return false;
}

/**
 * Hat irgendein Admin-Konto noch ein öffentlich bekanntes Standard-Passwort?
 * Solange ja, sind Online-Updates gesperrt (ein Update-ZIP kann Programmcode enthalten).
 */
function smzpulse_auth_is_default_password() {
    foreach (smzpulse_users_load() as $u) {
        if (($u['role'] ?? '') === 'admin' && smzpulse_hash_is_default($u['hash'] ?? '')) return true;
    }
    return false;
}

function smzpulse_admin_count(array $users) {
    return count(array_filter($users, function ($u) { return ($u['role'] ?? '') === 'admin'; }));
}

/** Prüft Benutzername + Passwort. Gibt den Nutzer-Datensatz (mit 'name') oder null zurück. */
function smzpulse_users_verify($name, $password) {
    $name = strtolower(trim((string)$name));
    $users = smzpulse_users_load();
    $user = smzpulse_valid_username($name) ? ($users[$name] ?? null) : null;
    // Auch bei unbekanntem Namen ein Hash prüfen, damit die Antwortzeit nichts verrät
    $hash = $user['hash'] ?? '$2y$10$JGhlUos.Z4rlXYoa2TLoMeUm5bL157JQ/zDXF481ri3NFxh17ZO22';
    $ok = password_verify((string)$password, $hash);
    if (!$user || !$ok) return null;
    $user['name'] = $name;
    return $user;
}

// ---- Angemeldeter Nutzer ----

/** Nach erfolgreichem Login aufrufen. */
function smzpulse_session_login(array $user) {
    session_regenerate_id(true);
    $_SESSION['loggedin']      = true;
    $_SESSION['user']          = $user['name'];
    $_SESSION['pwv']           = (int)($user['pwv'] ?? 1);
    $_SESSION['csrf_token']    = bin2hex(random_bytes(32));
    $_SESSION['last_activity'] = time();
}

/**
 * Der aktuell angemeldete Nutzer – frisch aus users.php gelesen, damit gelöschte
 * Nutzer, geänderte Rollen und geänderte Passwörter sofort wirken.
 * Gibt null zurück (und beendet die Session), wenn er nicht mehr gültig ist.
 */
function smzpulse_current_user() {
    static $cache = null;
    if ($cache !== null) return $cache;
    $name = $_SESSION['user'] ?? null;
    $users = smzpulse_users_load();
    $user = is_string($name) ? ($users[$name] ?? null) : null;
    if (!$user || (int)($user['pwv'] ?? 1) !== (int)($_SESSION['pwv'] ?? 0)) {
        $_SESSION = [];
        if (session_status() === PHP_SESSION_ACTIVE) session_destroy();
        return null;
    }
    $user['name'] = $name;
    unset($user['hash']);
    return $cache = $user;
}

function smzpulse_is_admin(?array $user) {
    return $user !== null && ($user['role'] ?? '') === 'admin';
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
