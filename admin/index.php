<?php
require_once __DIR__ . '/session_bootstrap.php';
require_once __DIR__ . '/auth_helper.php';
smzpulse_init_session();

$expired = isset($_GET['expired']);

$configPath = '../config.json';
$config = is_file($configPath) ? json_decode(file_get_contents($configPath), true) : [];
if (!is_array($config)) $config = [];

if (isset($_POST['login']) && isset($_POST['password'])) {
    $lockedFor = smzpulse_login_locked_for();
    $user = null;

    if ($lockedFor === 0) {
        $user = smzpulse_users_verify($_POST['login'], $_POST['password']);
    }

    if ($user) {
        smzpulse_login_reset();
        smzpulse_session_login($user);
        header("Location: dashboard.php");
        exit;
    } elseif ($lockedFor > 0) {
        $error = "Zu viele Fehlversuche. Bitte in " . ceil($lockedFor / 60) . " Minute(n) erneut versuchen.";
    } else {
        smzpulse_login_register_failure();
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
                    <input type="text" id="login" name="login" required autofocus autocapitalize="none" autocomplete="username">
                </div>
                <div class="form-group">
                    <label for="password">Passwort</label>
                    <input type="password" id="password" name="password" required autocomplete="current-password">
                </div>
                <button type="submit" class="btn-primary">Anmelden</button>
            </form>
        </div>
    </div>
</body>
</html>
