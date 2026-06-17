<?php
require_once __DIR__ . '/session_bootstrap.php';
require_once __DIR__ . '/auth_helper.php';
smzpulse_init_session();

$expired = isset($_GET['expired']);

$configPath = '../config.json';
$config = is_file($configPath) ? json_decode(file_get_contents($configPath), true) : [];
if (!is_array($config)) $config = [];

$admin_user = "admin";

// Prefer the new auth.php. If it's empty (first run after upgrade), fall back
// to the legacy admin_password field that used to live inside config.json.
$stored_password = smzpulse_auth_get_password_hash();
$legacy_source   = false;
if ($stored_password === null && isset($config['admin_password'])) {
    $stored_password = $config['admin_password'];
    $legacy_source   = true;
}

function is_hashed_password($pw) {
    return is_string($pw) && (strpos($pw, '$2y$') === 0
        || strpos($pw, '$argon2') === 0
        || strpos($pw, '$2a$') === 0);
}

if (isset($_POST['login']) && isset($_POST['password'])) {
    $loginOK = false;

    if ($stored_password && $_POST['login'] === $admin_user) {
        if (is_hashed_password($stored_password)) {
            $loginOK = password_verify($_POST['password'], $stored_password);
            // Hash already correct — if it came from the legacy slot, move it.
            if ($loginOK && $legacy_source) {
                smzpulse_auth_save_password($stored_password);
                unset($config['admin_password']);
                $tmp = $configPath . '.tmp.' . bin2hex(random_bytes(4));
                file_put_contents($tmp, json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
                rename($tmp, $configPath);
            }
        } else {
            // Legacy plaintext — verify once, hash, save, scrub from config.json
            if (hash_equals((string)$stored_password, (string)$_POST['password'])) {
                $loginOK = true;
                $newHash = password_hash($_POST['password'], PASSWORD_DEFAULT);
                smzpulse_auth_save_password($newHash);
                if ($legacy_source) {
                    unset($config['admin_password']);
                    $tmp = $configPath . '.tmp.' . bin2hex(random_bytes(4));
                    file_put_contents($tmp, json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
                    rename($tmp, $configPath);
                }
            }
        }
    }

    if ($loginOK) {
        session_regenerate_id(true);
        $_SESSION['loggedin'] = true;
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        $_SESSION['last_activity'] = time();
        header("Location: dashboard.php");
        exit;
    } else {
        $error = "Ungültiger Benutzername oder Passwort.";
    }
}

if (isset($_GET['logout'])) {
    $_SESSION = [];
    session_destroy();
    header("Location: index.php");
    exit;
}
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>smzPulse Backend - Login</title>
    <link rel="stylesheet" href="vendor/inter/inter.css">
    <link rel="stylesheet" href="style.css">
</head>
<body class="login-page">
    <div class="login-container">
        <div class="login-card">
            <div class="logo-section">
                <?php
                    $logo = $config['logo'] ?? (file_exists('../images/logo.svg') ? 'logo.svg' : 'logo.png');
                    $logoPath = "../images/" . $logo;
                ?>
                <img src="<?php echo htmlspecialchars($logoPath, ENT_QUOTES); ?>" alt="Logo" class="logo" onerror="this.onerror=null;this.style.display='none';">
                <h1>smzPulse<span>Backend</span></h1>
            </div>

            <?php if ($expired && !isset($error)): ?>
                <div class="error-msg" style="background:#fff7e6;color:#7a4a00;border:1px solid #f3c97a;">
                    Sie wurden aufgrund von Inaktivität automatisch abgemeldet. Bitte erneut anmelden.
                </div>
            <?php endif; ?>
            <?php if (isset($error)): ?>
                <div class="error-msg"><?php echo htmlspecialchars($error, ENT_QUOTES); ?></div>
            <?php endif; ?>

            <form method="POST">
                <div class="form-group">
                    <label for="login">Benutzername</label>
                    <input type="text" id="login" name="login" required autofocus>
                </div>
                <div class="form-group">
                    <label for="password">Passwort</label>
                    <input type="password" id="password" name="password" required>
                </div>
                <button type="submit" class="btn-primary">Anmelden</button>
            </form>
        </div>
    </div>
</body>
</html>
