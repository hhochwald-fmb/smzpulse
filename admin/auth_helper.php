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
