<?php
// Online-Update für smzPulse (nach dem Vorbild von Clipo/LaborBit):
//  - "Nach Updates suchen" fragt das neueste GitHub-Release ab
//  - "Jetzt installieren" lädt es herunter (Release-Asset smzpulse-*.zip oder das Quellcode-ZIP des Tags)
//  - alternativ kann ein ZIP von Hand hochgeladen werden
//  - vorher wird ein Backup der ersetzten Programmdateien in storage/backups angelegt
//  - Daten werden nie überschrieben: auth.php, config.json, theme.js, news/, images/ (Bilder + images.json)

require_once __DIR__ . '/auth_helper.php';

define('SMZPULSE_ROOT', dirname(__DIR__));
define('SMZPULSE_UPDATE_REPO', 'hhochwald-fmb/smzpulse');
define('SMZPULSE_UPDATE_MAX_ZIP', 40 * 1024 * 1024);
// Grenzen für den entpackten Inhalt (Schutz gegen Zip-Bomben)
define('SMZPULSE_UPDATE_MAX_UNPACKED', 60 * 1024 * 1024);
define('SMZPULSE_UPDATE_MAX_FILE', 20 * 1024 * 1024);
define('SMZPULSE_VERSION', trim((string) @file_get_contents(SMZPULSE_ROOT . '/VERSION')) ?: '1.1.0');

function smzpulse_is_git_checkout() {
    return is_dir(SMZPULSE_ROOT . '/.git');
}

function smzpulse_storage_dir($sub) {
    $d = SMZPULSE_ROOT . '/storage/' . $sub;
    if (!is_dir($d)) {
        @mkdir($d, 0775, true);
    }
    if (!is_dir($d) || !is_writable($d)) {
        throw new RuntimeException("Der Ordner storage/$sub ist nicht beschreibbar. Bitte Schreibrechte setzen (chmod 775).");
    }
    return $d;
}

function smzpulse_http_get($url, $saveTo = null) {
    $allowedHosts = ['api.github.com', 'github.com', 'codeload.github.com', 'objects.githubusercontent.com', 'release-assets.githubusercontent.com'];
    if (parse_url($url, PHP_URL_SCHEME) !== 'https' || !in_array(parse_url($url, PHP_URL_HOST), $allowedHosts, true)) {
        throw new RuntimeException('Unerlaubte Update-Adresse.');
    }
    $ua = 'smzPulse-Updater/' . SMZPULSE_VERSION;

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        $fh = $saveTo ? fopen($saveTo, 'wb') : null;
        curl_setopt_array($ch, [
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_TIMEOUT => 120,
            CURLOPT_USERAGENT => $ua,
            CURLOPT_HTTPHEADER => ['Accept: application/vnd.github+json, application/octet-stream'],
        ]);
        if ($fh) {
            curl_setopt($ch, CURLOPT_FILE, $fh);
        } else {
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        }
        $res = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        if ($fh) {
            fclose($fh);
        }
        if ($res === false || $code >= 400) {
            if ($saveTo) {
                @unlink($saveTo);
            }
            if ($code === 404) {
                throw new RuntimeException('Nicht gefunden (HTTP 404) – gibt es im Repository schon ein veröffentlichtes Release?');
            }
            if ($code === 403) {
                throw new RuntimeException('GitHub hat die Anfrage abgelehnt (HTTP 403, evtl. zu viele Anfragen). Bitte später erneut versuchen.');
            }
            throw new RuntimeException('Verbindung fehlgeschlagen (' . ($err ?: 'HTTP ' . $code) . ').');
        }
        return $fh ? '' : (string) $res;
    }

    if (!ini_get('allow_url_fopen')) {
        throw new RuntimeException('Der Server kann keine Updates herunterladen (weder cURL noch allow_url_fopen verfügbar). Bitte das ZIP von Hand hochladen.');
    }
    $ctx = stream_context_create(['http' => [
        'header' => "User-Agent: $ua\r\nAccept: application/vnd.github+json\r\n",
        'timeout' => 120,
        'follow_location' => 1,
    ]]);
    $data = @file_get_contents($url, false, $ctx);
    if ($data === false) {
        throw new RuntimeException('Download fehlgeschlagen.');
    }
    if ($saveTo) {
        file_put_contents($saveTo, $data);
        return '';
    }
    return $data;
}

/** @return array{version:string, name:string, notes:string, zip_url:string, newer:bool} */
function smzpulse_check_latest() {
    $repo = SMZPULSE_UPDATE_REPO;
    $json = json_decode(smzpulse_http_get("https://api.github.com/repos/$repo/releases/latest"), true);
    if (!is_array($json) || empty($json['tag_name'])) {
        throw new RuntimeException('Keine Release-Informationen gefunden.');
    }
    $version = ltrim((string) $json['tag_name'], 'vV');

    // Bevorzugt ein angehängtes Paket smzpulse-x.y.z.zip, sonst das automatische Quellcode-ZIP des Tags
    $zip = (string) ($json['zipball_url'] ?? '');
    foreach ($json['assets'] ?? [] as $a) {
        if (preg_match('/^smzpulse-.*\.zip$/i', (string) ($a['name'] ?? ''))) {
            $zip = (string) $a['browser_download_url'];
            break;
        }
    }
    return [
        'version' => $version,
        'name' => (string) ($json['name'] ?? ''),
        'notes' => mb_substr((string) ($json['body'] ?? ''), 0, 4000),
        'zip_url' => $zip,
        'newer' => version_compare($version, SMZPULSE_VERSION, '>'),
    ];
}

function smzpulse_download_latest() {
    if (smzpulse_auth_is_default_password()) {
        throw new RuntimeException('Updates sind gesperrt, solange das Standard-Passwort aktiv ist. Bitte zuerst unter Einstellungen ein eigenes Passwort setzen.');
    }
    $info = smzpulse_check_latest();
    if (!$info['newer']) {
        throw new RuntimeException('Es ist bereits die neueste Version installiert.');
    }
    if ($info['zip_url'] === '') {
        throw new RuntimeException('Das Release enthält kein Download-Paket.');
    }
    $target = smzpulse_storage_dir('tmp') . '/update-' . bin2hex(random_bytes(4)) . '.zip';
    smzpulse_http_get($info['zip_url'], $target);
    return $target;
}

/** Darf ein Update diese Datei (relativer Pfad) schreiben? Daten bleiben immer unangetastet. */
function smzpulse_update_allowed($rel) {
    if ($rel === '' || strpos($rel, '..') !== false || strpos($rel, '\\') !== false || $rel[0] === '/' || strpos($rel, "\0") !== false) {
        return false;
    }
    $files = ['index.php', 'VERSION', 'README.md', 'CHANGELOG.md', 'LICENSE',
        '.htaccess', '.gitignore', '.dockerignore', 'Dockerfile', 'docker-compose.yml',
        'images/.htaccess', 'storage/.htaccess'];
    if (in_array($rel, $files, true)) {
        return true;
    }
    // Admin-Bereich (inkl. vendor/), aber keine Dotfiles/-ordner wie admin/.claude
    if (strpos($rel, 'admin/') === 0 && strpos($rel, '/.') === false) {
        return true;
    }
    return false;
}

/** Prüft und installiert ein Update-ZIP. Gibt die neue Version zurück. */
function smzpulse_apply_update_zip($zipPath) {
    if (smzpulse_auth_is_default_password()) {
        throw new RuntimeException('Updates sind gesperrt, solange das Standard-Passwort aktiv ist. Bitte zuerst unter Einstellungen ein eigenes Passwort setzen.');
    }
    if (!class_exists('ZipArchive')) {
        throw new RuntimeException('Die PHP-Erweiterung "zip" fehlt auf dem Server.');
    }
    if (!is_file($zipPath) || filesize($zipPath) > SMZPULSE_UPDATE_MAX_ZIP) {
        throw new RuntimeException('Update-Datei fehlt oder ist zu groß.');
    }
    $zip = new ZipArchive();
    if ($zip->open($zipPath) !== true) {
        throw new RuntimeException('Die Datei ist kein gültiges ZIP-Archiv.');
    }
    try {
        // Wurzel des Pakets finden (Ordner mit VERSION und admin/api.php).
        // Beim Quellcode-ZIP von GitHub ist das z. B. "hhochwald-fmb-smzpulse-1a2b3c4/".
        $prefix = null;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $n = (string) $zip->getNameIndex($i);
            if (preg_match('#^(.*/)?VERSION$#', $n, $m)) {
                $p = isset($m[1]) ? $m[1] : '';
                if ($zip->locateName($p . 'admin/api.php') !== false && ($prefix === null || strlen($p) < strlen($prefix))) {
                    $prefix = $p;
                }
            }
        }
        if ($prefix === null) {
            throw new RuntimeException('Das ZIP ist kein smzPulse-Paket (VERSION oder admin/ fehlen).');
        }
        $newVersion = trim((string) $zip->getFromName($prefix . 'VERSION'));
        if (!preg_match('/^\d+\.\d+(\.\d+)?([.-][0-9A-Za-z.-]+)?$/', $newVersion)) {
            throw new RuntimeException('Ungültige Versionsangabe im Paket.');
        }
        if (version_compare($newVersion, SMZPULSE_VERSION, '<')) {
            throw new RuntimeException("Das Paket ($newVersion) ist älter als die installierte Version (" . SMZPULSE_VERSION . ').');
        }

        $entries = [];
        $unpacked = 0;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $n = (string) $zip->getNameIndex($i);
            if (strpos($n, $prefix) !== 0 || substr($n, -1) === '/') {
                continue;
            }
            $rel = substr($n, strlen($prefix));
            if (smzpulse_update_allowed($rel)) {
                $stat = $zip->statIndex($i);
                $size = (int) ($stat['size'] ?? 0);
                if ($size > SMZPULSE_UPDATE_MAX_FILE) {
                    throw new RuntimeException("Die Datei $rel im Paket ist ungewöhnlich groß – Update abgebrochen, nichts wurde verändert.");
                }
                $unpacked += $size;
                $entries[$i] = $rel;
            }
        }
        if ($unpacked > SMZPULSE_UPDATE_MAX_UNPACKED) {
            throw new RuntimeException('Das Paket ist entpackt zu groß – Update abgebrochen, nichts wurde verändert.');
        }

        // Vollständigkeit prüfen, damit ein kaputtes Paket den Admin-Bereich nicht lahmlegt
        $required = [
            'admin/api.php' => 'smzpulse_check_timeout',
            'admin/dashboard.php' => 'smzpulse_init_session',
            'admin/index.php' => 'smzpulse_init_session',
            'admin/session_bootstrap.php' => 'function smzpulse_init_session',
            'admin/auth_helper.php' => 'function smzpulse_auth_load',
            'admin/updater.php' => 'function smzpulse_apply_update_zip',
            'admin/style.css' => '.dashboard',
        ];
        foreach ($required as $req => $marker) {
            $content = $zip->getFromName($prefix . $req);
            if ($content === false || strpos($content, $marker) === false) {
                throw new RuntimeException("Das Paket ist unvollständig oder beschädigt ($req fehlt) – nichts wurde verändert.");
            }
        }
        if (count($entries) < 15) {
            throw new RuntimeException('Das Paket enthält zu wenige Dateien – ist es wirklich ein vollständiges smzPulse-Release?');
        }

        // Schreibrechte prüfen, bevor irgendetwas verändert wird
        foreach ($entries as $rel) {
            $target = SMZPULSE_ROOT . '/' . $rel;
            $dir = dirname($target);
            while (!is_dir($dir)) {
                $dir = dirname($dir);
            }
            if ((file_exists($target) && !is_writable($target)) || !is_writable($dir)) {
                throw new RuntimeException("Keine Schreibrechte für $rel – Update abgebrochen, nichts wurde verändert.");
            }
        }

        smzpulse_backup_files(array_values($entries));

        foreach ($entries as $i => $rel) {
            $target = SMZPULSE_ROOT . '/' . $rel;
            if (!is_dir(dirname($target))) {
                mkdir(dirname($target), 0775, true);
            }
            $data = $zip->getFromIndex($i, SMZPULSE_UPDATE_MAX_FILE + 1);
            if ($data !== false && strlen($data) > SMZPULSE_UPDATE_MAX_FILE) {
                throw new RuntimeException("Die Datei $rel im Paket ist zu groß. Ein Backup liegt in storage/backups.");
            }
            if ($data === false || file_put_contents($target, $data, LOCK_EX) === false) {
                throw new RuntimeException("Fehler beim Schreiben von $rel. Ein Backup liegt in storage/backups.");
            }
        }
    } finally {
        $zip->close();
    }
    if (function_exists('opcache_reset')) {
        @opcache_reset();
    }
    return $newVersion;
}

function smzpulse_backup_files(array $relPaths) {
    $file = smzpulse_storage_dir('backups') . '/backup-' . SMZPULSE_VERSION . '-' . date('Ymd-His') . '-' . bin2hex(random_bytes(2)) . '.zip';
    $zip = new ZipArchive();
    if ($zip->open($file, ZipArchive::CREATE) !== true) {
        throw new RuntimeException('Backup konnte nicht angelegt werden – Update abgebrochen.');
    }
    foreach ($relPaths as $rel) {
        if (is_file(SMZPULSE_ROOT . '/' . $rel)) {
            $zip->addFile(SMZPULSE_ROOT . '/' . $rel, $rel);
        }
    }
    if (!$zip->close()) {
        throw new RuntimeException('Backup konnte nicht gespeichert werden – Update abgebrochen.');
    }
}

/** Release-Notizen (einfaches Markdown) sicher als HTML darstellen: Listen, **fett**, `code`, Überschriften. */
function smzpulse_format_release_notes($text) {
    $html = '';
    $inList = false;
    foreach (preg_split('/\r?\n/', trim($text)) as $line) {
        $line = rtrim($line);
        $safe = htmlspecialchars(ltrim($line));
        $safe = preg_replace('/\*\*(.+?)\*\*/', '<strong>$1</strong>', $safe);
        $safe = preg_replace('/`([^`]+)`/', '<code>$1</code>', $safe);
        if (preg_match('/^\s*[-*]\s+/', $line)) {
            if (!$inList) {
                $html .= '<ul>';
                $inList = true;
            }
            $html .= '<li>' . preg_replace('/^[-*]\s+/', '', $safe) . '</li>';
            continue;
        }
        if ($inList) {
            $html .= '</ul>';
            $inList = false;
        }
        if ($line === '') {
            continue;
        }
        if (preg_match('/^#{1,6}\s+/', $line)) {
            $html .= '<p class="notes-heading">' . preg_replace('/^#{1,6}\s+/', '', $safe) . '</p>';
        } else {
            $html .= '<p>' . $safe . '</p>';
        }
    }
    if ($inList) {
        $html .= '</ul>';
    }
    return $html;
}

function smzpulse_backups() {
    $files = glob(SMZPULSE_ROOT . '/storage/backups/backup-*.zip') ?: [];
    usort($files, function ($a, $b) {
        return filemtime($b) - filemtime($a);
    });
    return array_map(function ($f) {
        return ['name' => basename($f), 'size' => filesize($f)];
    }, $files);
}
