<?php
require_once __DIR__ . '/session_bootstrap.php';
require_once __DIR__ . '/auth_helper.php';
require_once __DIR__ . '/updater.php';
smzpulse_init_session();

header('Content-Type: application/json');

$action = $_GET['action'] ?? '';

// Heartbeats are pure liveness checks and must NOT extend the session.
// All other actions count as user activity.
$isPing = ($action === 'ping');
$touchActivity = !$isPing || (isset($_GET['active']) && $_GET['active'] === '1');

if (!smzpulse_check_timeout($touchActivity)) {
    http_response_code(401);
    echo json_encode(['error' => 'Session expired']);
    exit;
}

// Nutzer frisch aus users.php – gelöschte Nutzer / geänderte Passwörter fliegen sofort raus
$currentUser = smzpulse_current_user();
if ($currentUser === null) {
    http_response_code(401);
    echo json_encode(['error' => 'Session expired']);
    exit;
}

// ---- Rechte: diese Aktionen dürfen nur Admins ----
$adminOnly = ['save_devices', 'save_settings',
              'update_info', 'update_check', 'update_install', 'update_upload',
              'users_list', 'user_create', 'user_update', 'user_delete'];
if (in_array($action, $adminOnly, true) && !smzpulse_is_admin($currentUser)) {
    http_response_code(403);
    echo json_encode(['error' => 'Dafür fehlen die Rechte (nur Admins).']);
    exit;
}

$configPath     = '../config.json';
$imagesJsonPath = '../images/images.json';
$newsPath       = '../news/news.json';
$imagesDir      = '../images/';

// ---- CSRF check for state-changing actions ----
$stateChanging = ['save_news', 'save_devices', 'upload_image', 'delete_image', 'reorder_images', 'save_settings', 'update_image_duration',
                  'update_check', 'update_install', 'update_upload',
                  'change_password', 'user_create', 'user_update', 'user_delete'];
if (in_array($action, $stateChanging, true)) {
    $token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? $_POST['csrf_token'] ?? '';
    if (empty($_SESSION['csrf_token']) || !is_string($token) || !hash_equals($_SESSION['csrf_token'], $token)) {
        http_response_code(403);
        echo json_encode(['error' => 'Invalid CSRF token']);
        exit;
    }
}

// ---- Helpers ----
function read_json($path, $default = []) {
    if (!file_exists($path)) return $default;
    $data = json_decode(file_get_contents($path), true);
    return is_array($data) ? $data : $default;
}

function write_json_atomic($path, $data) {
    $tmp = $path . '.tmp.' . bin2hex(random_bytes(4));
    $bytes = file_put_contents($tmp, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    if ($bytes === false) {
        @unlink($tmp);
        return false;
    }
    return rename($tmp, $path);
}

function is_safe_image_filename($filename) {
    if (!is_string($filename) || $filename === '') return false;
    if ($filename !== basename($filename)) return false;
    if (strpbrk($filename, "/\\\0") !== false) return false;
    $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    return in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'gif', 'svg'], true);
}

function validate_uploaded_image($file, $maxBytes) {
    if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        return [null, 'Upload fehlgeschlagen (Code ' . ($file['error'] ?? 'n/a') . ')'];
    }
    if (($file['size'] ?? 0) <= 0 || $file['size'] > $maxBytes) {
        return [null, 'Datei zu groß oder leer (max ' . round($maxBytes / 1024 / 1024) . ' MB)'];
    }
    if (!is_uploaded_file($file['tmp_name'])) {
        return [null, 'Ungültiger Upload'];
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->file($file['tmp_name']);

    $allowed = [
        'image/jpeg'    => 'jpg',
        'image/png'     => 'png',
        'image/webp'    => 'webp',
        'image/gif'     => 'gif',
        'image/svg+xml' => 'svg',
    ];
    if (!isset($allowed[$mime])) {
        return [null, 'Dateityp nicht erlaubt (' . htmlspecialchars((string)$mime) . ')'];
    }
    return [$allowed[$mime], null];
}

function generate_image_filename($ext, $prefix = 'img') {
    return $prefix . '_' . date('Ymd_His') . '_' . bin2hex(random_bytes(6)) . '.' . $ext;
}

// Converts a mixed old/new images list to always-object format.
// Old format: ["file.jpg", ...] → New format: [{"filename":"file.jpg"}, ...]
function normalize_images_list($images) {
    return array_values(array_map(function ($item) {
        if (is_string($item)) {
            return ['filename' => $item];
        }
        return $item;
    }, $images));
}

// ---- Actions ----
switch ($action) {
    case 'ping': {
        $last = $_SESSION['last_activity'] ?? time();
        echo json_encode([
            'ok'         => true,
            'expires_in' => max(0, SMZPULSE_INACTIVITY_TIMEOUT - (time() - $last)),
        ]);
        break;
    }

    case 'get_data':
        echo json_encode([
            'config' => read_json($configPath),
            'images' => normalize_images_list(read_json($imagesJsonPath)),
            'news'   => read_json($newsPath),
            'me'     => ['name' => $currentUser['name'], 'role' => $currentUser['role']],
        ]);
        break;

    case 'save_news': {
        $data = json_decode(file_get_contents('php://input'), true);
        if (!isset($data['news']) || !is_array($data['news'])) {
            http_response_code(400);
            echo json_encode(['error' => 'Invalid news data']);
            break;
        }
        $news = array_values(array_map(function ($n) {
            return is_string($n) ? $n : '';
        }, $data['news']));
        if (!write_json_atomic($newsPath, $news)) {
            http_response_code(500);
            echo json_encode(['error' => 'Write failed']);
            break;
        }
        echo json_encode(['success' => true]);
        break;
    }

    case 'save_devices': {
        $data = json_decode(file_get_contents('php://input'), true);
        if (!isset($data['devices']) || !is_array($data['devices'])) {
            http_response_code(400);
            echo json_encode(['error' => 'Invalid devices data']);
            break;
        }
        // Sanitize: keys + values to strings, drop empty keys
        $clean = [];
        foreach ($data['devices'] as $uuid => $name) {
            $uuid = trim((string)$uuid);
            if ($uuid === '') continue;
            $clean[$uuid] = (string)$name;
        }
        $config = read_json($configPath);
        $config['devices'] = $clean;
        if (!write_json_atomic($configPath, $config)) {
            http_response_code(500);
            echo json_encode(['error' => 'Write failed']);
            break;
        }
        echo json_encode(['success' => true]);
        break;
    }

    case 'upload_image': {
        $file = $_FILES['image'] ?? null;
        [$ext, $err] = validate_uploaded_image($file, 20 * 1024 * 1024);
        if ($err) {
            http_response_code(400);
            echo json_encode(['error' => $err]);
            break;
        }

        // Always assign a system-generated filename — never trust user input
        $filename = generate_image_filename($ext, 'img');
        $targetPath = $imagesDir . $filename;

        if (!move_uploaded_file($file['tmp_name'], $targetPath)) {
            http_response_code(500);
            echo json_encode(['error' => 'Konnte Datei nicht speichern']);
            break;
        }
        @chmod($targetPath, 0644);

        $images = normalize_images_list(read_json($imagesJsonPath));
        $images[] = ['filename' => $filename];
        if (!write_json_atomic($imagesJsonPath, $images)) {
            // Roll back the file write so JSON & disk stay in sync
            @unlink($targetPath);
            http_response_code(500);
            echo json_encode(['error' => 'JSON konnte nicht aktualisiert werden']);
            break;
        }
        echo json_encode(['success' => true, 'filename' => $filename]);
        break;
    }

    case 'delete_image': {
        $data = json_decode(file_get_contents('php://input'), true);
        $raw  = $data['filename'] ?? '';
        $filename = is_string($raw) ? basename($raw) : '';

        if (!is_safe_image_filename($filename)) {
            http_response_code(400);
            echo json_encode(['error' => 'Ungültiger Dateiname']);
            break;
        }

        $images = normalize_images_list(read_json($imagesJsonPath));
        $filenames = array_column($images, 'filename');
        if (!in_array($filename, $filenames, true)) {
            http_response_code(404);
            echo json_encode(['error' => 'Bild nicht in der Liste gefunden']);
            break;
        }

        $filePath = $imagesDir . $filename;
        $fileDeleted = false;
        if (is_file($filePath)) {
            $fileDeleted = @unlink($filePath);
            if (!$fileDeleted) {
                http_response_code(500);
                echo json_encode(['error' => 'Datei konnte nicht vom Server gelöscht werden']);
                break;
            }
        }

        $images = array_values(array_filter($images, function ($img) use ($filename) {
            return ($img['filename'] ?? '') !== $filename;
        }));
        if (!write_json_atomic($imagesJsonPath, $images)) {
            http_response_code(500);
            echo json_encode(['error' => 'JSON konnte nicht aktualisiert werden']);
            break;
        }
        echo json_encode(['success' => true, 'file_deleted' => $fileDeleted]);
        break;
    }

    case 'reorder_images': {
        $data = json_decode(file_get_contents('php://input'), true);
        if (!isset($data['images']) || !is_array($data['images'])) {
            http_response_code(400);
            echo json_encode(['error' => 'Invalid order data']);
            break;
        }
        // Only allow filenames that already exist in images.json — prevents injection
        $existing = normalize_images_list(read_json($imagesJsonPath));
        // Build a lookup map: filename → full object (preserves duration)
        $existingMap = [];
        foreach ($existing as $item) {
            $existingMap[$item['filename']] = $item;
        }
        $newOrder = [];
        $seen = [];
        foreach ($data['images'] as $f) {
            if (is_string($f) && isset($existingMap[$f]) && !isset($seen[$f])) {
                $newOrder[] = $existingMap[$f];
                $seen[$f] = true;
            }
        }
        if (!write_json_atomic($imagesJsonPath, $newOrder)) {
            http_response_code(500);
            echo json_encode(['error' => 'Write failed']);
            break;
        }
        echo json_encode(['success' => true]);
        break;
    }

    case 'save_settings': {
        if (!isset($_POST['primary_color']) || !isset($_POST['secondary_color'])) {
            http_response_code(400);
            echo json_encode(['error' => 'Felder fehlen']);
            break;
        }
        $primary   = (string)$_POST['primary_color'];
        $secondary = (string)$_POST['secondary_color'];
        if (!preg_match('/^#[0-9A-Fa-f]{6}$/', $primary) || !preg_match('/^#[0-9A-Fa-f]{6}$/', $secondary)) {
            http_response_code(400);
            echo json_encode(['error' => 'Ungültiges Farbformat']);
            break;
        }

        $config = read_json($configPath);
        $config['primary_color']   = $primary;
        $config['secondary_color'] = $secondary;
        // Defensive: never let the password hash land in config.json — it's
        // publicly readable for the Apple TV app.
        unset($config['admin_password']);

        if (isset($_FILES['logo']) && ($_FILES['logo']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
            [$ext, $err] = validate_uploaded_image($_FILES['logo'], 5 * 1024 * 1024);
            if ($err) {
                http_response_code(400);
                echo json_encode(['error' => 'Logo: ' . $err]);
                break;
            }
            $logoFilename = generate_image_filename($ext, 'logo_custom');
            $targetPath = $imagesDir . $logoFilename;
            if (!move_uploaded_file($_FILES['logo']['tmp_name'], $targetPath)) {
                http_response_code(500);
                echo json_encode(['error' => 'Logo-Upload fehlgeschlagen']);
                break;
            }
            @chmod($targetPath, 0644);

            // Remove previous custom logo (only if it was a generated one)
            if (!empty($config['logo']) && strpos($config['logo'], 'logo_custom_') === 0) {
                $oldPath = $imagesDir . basename($config['logo']);
                if (is_file($oldPath)) @unlink($oldPath);
            }
            $config['logo'] = $logoFilename;
        }

        if (!write_json_atomic($configPath, $config)) {
            http_response_code(500);
            echo json_encode(['error' => 'Konfig konnte nicht gespeichert werden']);
            break;
        }

        // Update theme.js for the Apple TV app
        $themeJs = "const SMZ_THEME = " . json_encode([
            'primary'   => $primary,
            'secondary' => $secondary,
            'logo'      => $config['logo'] ?? (file_exists('../images/logo.svg') ? 'logo.svg' : 'logo.png'),
        ], JSON_PRETTY_PRINT) . ";";
        file_put_contents('../theme.js', $themeJs);

        echo json_encode(['success' => true]);
        break;
    }

    case 'update_image_duration': {
        $data = json_decode(file_get_contents('php://input'), true);
        $raw  = $data['filename'] ?? '';
        $filename = is_string($raw) ? basename($raw) : '';

        if (!is_safe_image_filename($filename)) {
            http_response_code(400);
            echo json_encode(['error' => 'Ungültiger Dateiname']);
            break;
        }

        $duration = $data['duration'] ?? null;
        if ($duration !== null) {
            $duration = filter_var($duration, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 3600]]);
            if ($duration === false) {
                http_response_code(400);
                echo json_encode(['error' => 'Ungültige Dauer (1–3600 Sekunden oder leer für Standard)']);
                break;
            }
        }

        $images = normalize_images_list(read_json($imagesJsonPath));
        $found = false;
        foreach ($images as &$img) {
            if (($img['filename'] ?? '') === $filename) {
                if ($duration !== null) {
                    $img['duration'] = $duration;
                } else {
                    unset($img['duration']);
                }
                $found = true;
                break;
            }
        }
        unset($img);

        if (!$found) {
            http_response_code(404);
            echo json_encode(['error' => 'Bild nicht in der Liste gefunden']);
            break;
        }

        if (!write_json_atomic($imagesJsonPath, array_values($images))) {
            http_response_code(500);
            echo json_encode(['error' => 'JSON konnte nicht aktualisiert werden']);
            break;
        }
        echo json_encode(['success' => true]);
        break;
    }

    // ---- Eigenes Passwort ändern (alle Rollen) ----
    case 'change_password': {
        $data = json_decode(file_get_contents('php://input'), true);
        $current = (string)($data['current_password'] ?? '');
        $new     = (string)($data['new_password'] ?? '');
        if (!smzpulse_users_verify($currentUser['name'], $current)) {
            http_response_code(400);
            echo json_encode(['error' => 'Das aktuelle Passwort ist falsch.']);
            break;
        }
        if ($problem = smzpulse_password_problem($new)) {
            http_response_code(400);
            echo json_encode(['error' => $problem]);
            break;
        }
        $name = $currentUser['name'];
        $newPwv = null;
        $res = smzpulse_users_update(function (&$users) use ($name, $new, &$newPwv) {
            if (!isset($users[$name])) return 'Nutzer nicht gefunden.';
            $users[$name]['hash'] = password_hash($new, PASSWORD_DEFAULT);
            $users[$name]['pwv']  = $newPwv = (int)($users[$name]['pwv'] ?? 1) + 1;
        });
        if ($res !== true) {
            http_response_code(500);
            echo json_encode(['error' => is_string($res) ? $res : 'Passwort konnte nicht gespeichert werden (Schreibrechte?)']);
            break;
        }
        // Diese Sitzung bleibt gültig, alle anderen Sitzungen des Nutzers nicht
        $_SESSION['pwv'] = $newPwv;
        session_regenerate_id(true);
        echo json_encode(['success' => true]);
        break;
    }

    // ---- Nutzerverwaltung (nur Admins, siehe $adminOnly) ----
    case 'users_list': {
        $list = [];
        foreach (smzpulse_users_load() as $name => $u) {
            $list[] = [
                'name'    => $name,
                'role'    => $u['role'] ?? 'editor',
                'created' => $u['created'] ?? null,
                'default_password' => smzpulse_hash_is_default($u['hash'] ?? ''),
            ];
        }
        echo json_encode(['users' => $list, 'me' => $currentUser['name']]);
        break;
    }

    case 'user_create': {
        $data = json_decode(file_get_contents('php://input'), true);
        $name = strtolower(trim((string)($data['name'] ?? '')));
        $role = (string)($data['role'] ?? '');
        $pw   = (string)($data['password'] ?? '');
        if (!smzpulse_valid_username($name)) {
            http_response_code(400);
            echo json_encode(['error' => 'Benutzername: 2–32 Zeichen, nur Kleinbuchstaben, Zahlen, Punkt, Minus, Unterstrich.']);
            break;
        }
        if (!isset(SMZPULSE_ROLES[$role])) {
            http_response_code(400);
            echo json_encode(['error' => 'Ungültige Rolle.']);
            break;
        }
        if ($problem = smzpulse_password_problem($pw)) {
            http_response_code(400);
            echo json_encode(['error' => $problem]);
            break;
        }
        $hash = password_hash($pw, PASSWORD_DEFAULT);
        $res = smzpulse_users_update(function (&$users) use ($name, $role, $hash) {
            if (isset($users[$name])) return 'Diesen Benutzernamen gibt es schon.';
            $users[$name] = ['hash' => $hash, 'role' => $role, 'pwv' => 1, 'created' => date('c')];
        });
        if ($res !== true) {
            http_response_code(is_string($res) ? 400 : 500);
            echo json_encode(['error' => is_string($res) ? $res : 'Nutzer konnte nicht gespeichert werden (Schreibrechte?)']);
            break;
        }
        echo json_encode(['success' => true]);
        break;
    }

    case 'user_update': {
        // Rolle ändern und/oder Passwort neu setzen
        $data = json_decode(file_get_contents('php://input'), true);
        $name = strtolower(trim((string)($data['name'] ?? '')));
        $role = isset($data['role']) ? (string)$data['role'] : null;
        $pw   = isset($data['password']) && $data['password'] !== '' ? (string)$data['password'] : null;
        if ($role !== null && !isset(SMZPULSE_ROLES[$role])) {
            http_response_code(400);
            echo json_encode(['error' => 'Ungültige Rolle.']);
            break;
        }
        if ($pw !== null && ($problem = smzpulse_password_problem($pw))) {
            http_response_code(400);
            echo json_encode(['error' => $problem]);
            break;
        }
        if ($pw !== null && $name === $currentUser['name']) {
            http_response_code(400);
            echo json_encode(['error' => 'Dein eigenes Passwort änderst du unter „Mein Konto“.']);
            break;
        }
        $hash = $pw !== null ? password_hash($pw, PASSWORD_DEFAULT) : null;
        $res = smzpulse_users_update(function (&$users) use ($name, $role, $hash) {
            if (!isset($users[$name])) return 'Nutzer nicht gefunden.';
            if ($role !== null && $role !== 'admin' && ($users[$name]['role'] ?? '') === 'admin'
                && smzpulse_admin_count($users) <= 1) {
                return 'Der letzte Admin kann nicht herabgestuft werden.';
            }
            if ($role !== null) $users[$name]['role'] = $role;
            if ($hash !== null) {
                $users[$name]['hash'] = $hash;
                // Alle bestehenden Sitzungen dieses Nutzers beenden
                $users[$name]['pwv'] = (int)($users[$name]['pwv'] ?? 1) + 1;
            }
        });
        if ($res !== true) {
            http_response_code(is_string($res) ? 400 : 500);
            echo json_encode(['error' => is_string($res) ? $res : 'Nutzer konnte nicht gespeichert werden (Schreibrechte?)']);
            break;
        }
        echo json_encode(['success' => true]);
        break;
    }

    case 'user_delete': {
        $data = json_decode(file_get_contents('php://input'), true);
        $name = strtolower(trim((string)($data['name'] ?? '')));
        if ($name === $currentUser['name']) {
            http_response_code(400);
            echo json_encode(['error' => 'Du kannst dich nicht selbst löschen.']);
            break;
        }
        $res = smzpulse_users_update(function (&$users) use ($name) {
            if (!isset($users[$name])) return 'Nutzer nicht gefunden.';
            if (($users[$name]['role'] ?? '') === 'admin' && smzpulse_admin_count($users) <= 1) {
                return 'Der letzte Admin kann nicht gelöscht werden.';
            }
            unset($users[$name]);
        });
        if ($res !== true) {
            http_response_code(is_string($res) ? 400 : 500);
            echo json_encode(['error' => is_string($res) ? $res : 'Nutzer konnte nicht gelöscht werden (Schreibrechte?)']);
            break;
        }
        echo json_encode(['success' => true]);
        break;
    }

    // ---- Online-Update ----
    case 'update_info':
        echo json_encode([
            'version'      => SMZPULSE_VERSION,
            'repo'         => SMZPULSE_UPDATE_REPO,
            'git_checkout' => smzpulse_is_git_checkout(),
            'zip_ok'       => class_exists('ZipArchive'),
            'default_password' => smzpulse_auth_is_default_password(),
            'max_upload'   => ini_get('upload_max_filesize'),
            'backups'      => array_slice(smzpulse_backups(), 0, 5),
        ]);
        break;

    case 'update_check':
        try {
            $latest = smzpulse_check_latest();
            echo json_encode([
                'success'    => true,
                'installed'  => SMZPULSE_VERSION,
                'version'    => $latest['version'],
                'name'       => $latest['name'],
                'notes_html' => smzpulse_format_release_notes($latest['notes']),
                'newer'      => $latest['newer'],
            ]);
        } catch (Throwable $e) {
            http_response_code(502);
            echo json_encode(['error' => 'Update-Prüfung fehlgeschlagen: ' . $e->getMessage()]);
        }
        break;

    case 'update_install':
    case 'update_upload': {
        $zipPath = null;
        $isDownload = ($action === 'update_install');
        @set_time_limit(300);
        try {
            if ($isDownload) {
                $zipPath = smzpulse_download_latest();
            } else {
                $f = $_FILES['package'] ?? null;
                if (!$f || ($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file($f['tmp_name'])) {
                    throw new RuntimeException('Bitte eine Update-Datei (ZIP) auswählen. Der Server erlaubt Uploads bis ' . ini_get('upload_max_filesize') . '.');
                }
                $zipPath = $f['tmp_name'];
            }
            $version = smzpulse_apply_update_zip($zipPath);
            echo json_encode(['success' => true, 'version' => $version]);
        } catch (Throwable $e) {
            http_response_code(500);
            echo json_encode(['error' => 'Update fehlgeschlagen: ' . $e->getMessage()]);
        } finally {
            if ($isDownload && $zipPath) {
                @unlink($zipPath);
            }
        }
        break;
    }

    default:
        http_response_code(400);
        echo json_encode(['error' => 'Unknown action']);
        break;
}
