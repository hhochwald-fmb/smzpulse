<?php
require_once __DIR__ . '/session_bootstrap.php';
require_once __DIR__ . '/auth_helper.php';
smzpulse_init_session();
if (!smzpulse_check_timeout(true)) {
    header("Location: index.php?expired=1");
    exit;
}
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['csrf_token'];

$me = smzpulse_current_user();
if ($me === null) {
    header("Location: index.php?expired=1");
    exit;
}
$isAdmin = smzpulse_is_admin($me);
$allUsers = smzpulse_users_load();
$ownDefaultPassword = smzpulse_hash_is_default($allUsers[$me['name']]['hash'] ?? '');
$defaultPassword = $isAdmin && smzpulse_auth_is_default_password();

$config = is_file('../config.json') ? json_decode(file_get_contents('../config.json'), true) : [];
if (!is_array($config)) $config = [];
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES); ?>">
    <title>smzPulse Backend - Dashboard</title>
    <link rel="stylesheet" href="vendor/inter/inter.css">
    <link rel="stylesheet" href="vendor/fontawesome/css/all.min.css">
    <link rel="stylesheet" href="style.css">
    <script src="vendor/sortablejs/Sortable.min.js"></script>
</head>
<body class="dashboard-page">
    <div class="dashboard">
        <header>
            <div class="header-left">
                <?php 
                    $logo = $config['logo'] ?? (file_exists('../images/logo.svg') ? 'logo.svg' : 'logo.png');
                    $logoPath = "../images/" . $logo;
                ?>
                <img src="<?php echo $logoPath; ?>" alt="Logo" id="header-logo" style="height: 40px; margin-bottom: 10px;" onerror="this.style.display='none'">
                <h2>smzPulse <span>Backend</span></h2>
            </div>
            <div class="header-right">
                <span class="current-user"><i class="fas fa-user-circle"></i> <?php echo htmlspecialchars($me['name']); ?> <span class="role-badge role-<?php echo htmlspecialchars($me['role']); ?>"><?php echo htmlspecialchars(SMZPULSE_ROLES[$me['role']] ?? $me['role']); ?></span></span>
                <a href="index.php?logout=1" class="logout-link"><i class="fas fa-sign-out-alt"></i> Abmelden</a>
            </div>
        </header>

        <?php if ($ownDefaultPassword): ?>
        <div class="update-alert update-alert-error" id="default-password-warning">
            <i class="fas fa-exclamation-triangle"></i>
            <strong>Du nutzt noch das Standard-Passwort.</strong> Es ist öffentlich bekannt – bitte sofort unter <strong>Mein Konto</strong> ein eigenes Passwort setzen.<?php if ($isAdmin): ?> Bis dahin sind Online-Updates gesperrt.<?php endif; ?>
        </div>
        <?php elseif ($defaultPassword): ?>
        <div class="update-alert update-alert-error" id="default-password-warning">
            <i class="fas fa-exclamation-triangle"></i>
            <strong>Ein Admin-Konto nutzt noch das Standard-Passwort.</strong> Bitte unter <strong>Nutzer</strong> ein neues Passwort setzen. Bis dahin sind Online-Updates gesperrt.
        </div>
        <?php endif; ?>

        <div class="tabs">
            <button class="tab-btn active" onclick="showTab('images')">Bilder Gallerie</button>
            <button class="tab-btn" onclick="showTab('news')">News Ticker</button>
            <?php if ($isAdmin): ?>
            <button class="tab-btn" onclick="showTab('devices')">Apple TV Geräte</button>
            <button class="tab-btn" onclick="showTab('settings')">Einstellungen</button>
            <button class="tab-btn" onclick="showTab('users')">Nutzer</button>
            <button class="tab-btn" onclick="showTab('updates')">Updates</button>
            <?php endif; ?>
            <button class="tab-btn" onclick="showTab('account')">Mein Konto</button>
        </div>

        <!-- Images Tab -->
        <div id="images-tab" class="tab-content active">
            <div class="card">
                <h3>Bilder verwalten</h3>
                <p class="text-muted" style="margin-bottom: 20px;">Bilder hochladen, löschen oder per Drag & Drop sortieren.</p>
                
                <input type="file" id="file-upload" style="display: none" accept="image/*" onchange="handleFileUpload(this)">
                <div id="drop-zone" class="upload-card" onclick="document.getElementById('file-upload').click()">
                    <i class="fas fa-cloud-upload-alt"></i>
                    <p>Bild hierher ziehen oder klicken zum Hochladen</p>
                    <span style="font-size: 12px; color: #999;">PNG, JPG (empfohlen 1920x1080)</span>
                </div>

                <div id="images-container" class="images-container">
                    <!-- Loaded via JS -->
                </div>

                <div class="action-bar">
                    <p id="image-status" style="margin-right: 20px; font-size: 14px; color: var(--secondary-color); display: none;">Reihenfolge gespeichert!</p>
                </div>
            </div>
        </div>

        <!-- News Tab -->
        <div id="news-tab" class="tab-content">
            <div class="card">
                <h3>News Ticker Texte</h3>
                <p class="text-muted" style="margin-bottom: 20px;">Hier können Sie die Texte für den Newsticker am unteren Bildschirmrand bearbeiten.</p>
                <div id="news-list" class="news-list">
                    <!-- Loaded via JS -->
                </div>
                <div style="margin-top: 20px;">
                    <button class="btn-primary" style="width: auto; padding: 10px 20px;" onclick="addNewsItem()">
                        <i class="fas fa-plus"></i> News hinzufügen
                    </button>
                </div>
                <div class="action-bar">
                    <button class="btn-save" onclick="saveNews()">Änderungen speichern</button>
                </div>
            </div>
        </div>

        <?php if ($isAdmin): ?>
        <!-- Devices Tab -->
        <div id="devices-tab" class="tab-content">
            <div class="card">
                <h3>Apple TV Geräte (Config)</h3>
                <p class="text-muted" style="margin-bottom: 20px;">Verwalten Sie die UUIDs und Namen der Apple TV Geräte.</p>
                
                <div id="device-list">
                    <div class="device-row" style="font-weight: 600; font-size: 14px; color: var(--text-muted); border-bottom: 1px solid #eee; padding-bottom: 10px; margin-bottom: 15px;">
                        <div>UUID</div>
                        <div>Anzeigename</div>
                        <div></div>
                    </div>
                    <!-- Loaded via JS -->
                </div>

                <div style="margin-top: 20px;">
                    <button class="btn-primary" style="width: auto; padding: 10px 20px;" onclick="addDeviceItem()">
                        <i class="fas fa-plus"></i> Gerät hinzufügen
                    </button>
                </div>

                <div class="action-bar">
                    <button class="btn-save" onclick="saveDevices()">Geräte speichern</button>
                </div>
            </div>
        </div>

        <!-- Settings Tab -->
        <div id="settings-tab" class="tab-content">
            <div class="card">
                <h3>System-Einstellungen</h3>
                <p class="text-muted" style="margin-bottom: 20px;">Hier können Sie das Logo und die Farben der App anpassen. Passwörter: siehe „Mein Konto“ bzw. „Nutzer“.</p>
                
                <div class="settings-form">
                    <div class="form-group">
                        <label>Logo hochladen (ersetzt aktuelles Logo)</label>
                        <input type="file" id="settings-logo" accept="image/*">
                        <p class="text-muted" style="font-size: 12px; margin-top: 5px;">Empfohlen: PNG mit transparentem Hintergrund.</p>
                    </div>
                    
                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px;">
                        <div class="form-group">
                            <label>Primärfarbe</label>
                            <div style="display: flex; gap: 10px;">
                                <input type="color" id="settings-primary-color" style="width: 50px; padding: 2px; height: 45px;">
                                <input type="text" id="settings-primary-text" placeholder="#4a657a">
                            </div>
                        </div>
                        <div class="form-group">
                            <label>Sekundärfarbe</label>
                            <div style="display: flex; gap: 10px;">
                                <input type="color" id="settings-secondary-color" style="width: 50px; padding: 2px; height: 45px;">
                                <input type="text" id="settings-secondary-text" placeholder="#f39200">
                            </div>
                        </div>
                    </div>
                </div>

                <div class="action-bar">
                    <button class="btn-save" onclick="saveSettings()">Einstellungen speichern</button>
                </div>
            </div>
        </div>

        <!-- Updates Tab -->
        <div id="updates-tab" class="tab-content">
            <div class="card">
                <h3>Updates</h3>
                <p class="text-muted" style="margin-bottom: 20px;">Installierte Version: <strong id="update-installed">v…</strong></p>

                <div id="update-git-hint" class="update-alert update-alert-info" style="display: none;">
                    <div>Im smzPulse-Ordner liegt ein Git-Ordner (<code>.git</code>). Wurde smzPulse per <code>git clone</code> installiert (z.&nbsp;B. für Docker), aktualisierst du es besser mit <code>git pull</code>, damit Git und die Dateien nicht auseinanderlaufen.</div>
                    <div style="margin-top: 6px;">Wurde smzPulse per FTP/SFTP hochgeladen, kannst du diesen Hinweis ignorieren und den Knopf unten nutzen. Den Ordner <code>.git</code> kannst du dann auch löschen.</div>
                </div>
                <div id="update-default-pw" class="update-alert update-alert-error" style="display: none;">
                    Updates sind gesperrt, solange das Standard-Passwort aktiv ist. Bitte zuerst unter <strong>Einstellungen</strong> ein eigenes Passwort setzen und die Seite neu laden.
                </div>
                <div id="update-zip-missing" class="update-alert update-alert-error" style="display: none;">
                    Die PHP-Erweiterung „zip“ fehlt auf dem Server – Online-Updates sind nicht möglich. Bitte die Dateien per FTP einspielen.
                </div>

                <p style="margin-bottom: 12px;">Online-Updates aus <code id="update-repo">–</code></p>
                <button class="btn-primary btn-inline" id="update-check-btn" onclick="checkForUpdate()">
                    <i class="fas fa-sync-alt"></i> Nach Updates suchen
                </button>

                <div id="update-result"></div>

                <hr class="update-divider">

                <h4>Update-Paket hochladen (ZIP)</h4>
                <p class="text-muted" style="font-size: 13px; margin: 6px 0 12px;">
                    Ein smzPulse-Release als ZIP (z.&nbsp;B. von GitHub „Source code (zip)“). Bilder, News, Geräte, Einstellungen und Passwort bleiben erhalten.
                    Max. Upload-Größe des Servers: <span id="update-max-upload">–</span>
                </p>
                <div style="display: flex; gap: 12px; align-items: center; flex-wrap: wrap;">
                    <input type="file" id="update-package" accept=".zip">
                    <button class="btn-primary btn-inline" id="update-upload-btn" onclick="uploadUpdate()">
                        <i class="fas fa-upload"></i> Hochladen &amp; installieren
                    </button>
                </div>

                <div id="update-backups" style="display: none;">
                    <hr class="update-divider">
                    <h4>Backups</h4>
                    <ul id="update-backup-list" class="text-muted" style="font-size: 13px; padding-left: 20px; margin: 8px 0;"></ul>
                    <p class="text-muted" style="font-size: 13px;">Liegen im Ordner <code>storage/backups/</code>.</p>
                </div>
            </div>
        </div>

        <!-- Users Tab -->
        <div id="users-tab" class="tab-content">
            <div class="card">
                <h3>Nutzer</h3>
                <p class="text-muted" style="margin-bottom: 20px;"><strong>Admins</strong> dürfen alles. <strong>Redakteure</strong> dürfen nur Bilder und Ticker-Texte bearbeiten.</p>

                <div id="user-list" class="user-list"></div>

                <hr class="update-divider">

                <h4>Neuen Nutzer anlegen</h4>
                <div class="user-create">
                    <div class="form-group">
                        <label>Benutzername</label>
                        <input type="text" id="new-user-name" placeholder="z. B. mueller" autocapitalize="none" autocomplete="off" maxlength="32">
                    </div>
                    <div class="form-group">
                        <label>Rolle</label>
                        <select id="new-user-role">
                            <option value="editor">Redakteur</option>
                            <option value="admin">Admin</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Passwort (mind. 8 Zeichen)</label>
                        <input type="password" id="new-user-password" autocomplete="new-password">
                    </div>
                </div>
                <p class="text-muted" style="font-size: 12px;">Benutzername: Kleinbuchstaben, Zahlen, Punkt, Minus, Unterstrich. Das Passwort gibst du der Person persönlich – sie kann es danach unter „Mein Konto“ ändern.</p>
                <div class="action-bar">
                    <button class="btn-save" onclick="createUser()">Nutzer anlegen</button>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Account Tab -->
        <div id="account-tab" class="tab-content">
            <div class="card">
                <h3>Mein Konto</h3>
                <p class="text-muted" style="margin-bottom: 20px;">Angemeldet als <strong><?php echo htmlspecialchars($me['name']); ?></strong> (<?php echo htmlspecialchars(SMZPULSE_ROLES[$me['role']] ?? $me['role']); ?>). Nach einer Passwortänderung werden deine anderen Sitzungen (z. B. auf anderen Geräten) abgemeldet.</p>
                <div class="settings-form">
                    <div class="form-group">
                        <label>Aktuelles Passwort</label>
                        <input type="password" id="account-current" autocomplete="current-password">
                    </div>
                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px;">
                        <div class="form-group">
                            <label>Neues Passwort (mind. 8 Zeichen)</label>
                            <input type="password" id="account-new" autocomplete="new-password">
                        </div>
                        <div class="form-group">
                            <label>Neues Passwort wiederholen</label>
                            <input type="password" id="account-confirm" autocomplete="new-password">
                        </div>
                    </div>
                </div>
                <div class="action-bar">
                    <button class="btn-save" onclick="changeOwnPassword()">Passwort ändern</button>
                </div>
            </div>
        </div>
    </div>

    <script>
        let currentData = {
            news: [],
            images: [],
            config: { devices: {} }
        };

        const CSRF_TOKEN = document.querySelector('meta[name="csrf-token"]').getAttribute('content');
        const IS_ADMIN = <?php echo $isAdmin ? 'true' : 'false'; ?>;

        let sessionExpiredHandled = false;
        function handleSessionExpired() {
            if (sessionExpiredHandled) return;
            sessionExpiredHandled = true;
            window.location.href = 'index.php?expired=1';
        }

        async function apiPost(action, body, isFormData = false) {
            const headers = { 'X-CSRF-Token': CSRF_TOKEN };
            let payload;
            if (isFormData) {
                payload = body;
                payload.append('csrf_token', CSRF_TOKEN);
            } else {
                headers['Content-Type'] = 'application/json';
                payload = JSON.stringify(body);
            }
            const response = await fetch('api.php?action=' + action, {
                method: 'POST',
                headers,
                body: payload
            });
            if (response.status === 401) {
                handleSessionExpired();
                return { error: 'Session expired' };
            }
            try {
                return await response.json();
            } catch (e) {
                return { error: 'Ungültige Server-Antwort' };
            }
        }

        // Initialize
        async function loadData() {
            const response = await fetch('api.php?action=get_data');
            if (response.status === 401) {
                handleSessionExpired();
                return;
            }
            currentData = await response.json();
            renderAll();
        }

        // --- Session keep-alive & expiration detection ---
        // Heartbeat every 60 s purely to detect a server-side timeout. Does
        // NOT extend the session. If user interacts with the page, a separate
        // activity-ping (throttled to 5 min) extends the session so people
        // editing without saving aren't kicked out mid-work.
        const HEARTBEAT_MS = 60 * 1000;
        const ACTIVITY_THROTTLE_MS = 5 * 60 * 1000;
        let lastActivityPingAt = Date.now();

        async function heartbeat() {
            try {
                const r = await fetch('api.php?action=ping');
                if (r.status === 401) handleSessionExpired();
            } catch (e) { /* network blip — ignore */ }
        }
        setInterval(heartbeat, HEARTBEAT_MS);

        async function activityPing() {
            try {
                const r = await fetch('api.php?action=ping&active=1');
                if (r.status === 401) handleSessionExpired();
            } catch (e) { /* ignore */ }
        }

        function recordUserActivity() {
            const now = Date.now();
            if (now - lastActivityPingAt >= ACTIVITY_THROTTLE_MS) {
                lastActivityPingAt = now;
                activityPing();
            }
        }
        ['keydown', 'click', 'pointerdown'].forEach(ev => {
            document.addEventListener(ev, recordUserActivity, { passive: true });
        });

        function renderAll() {
            renderNews();
            renderImages();
            if (IS_ADMIN) {
                renderDevices();
                renderSettings();
            }
        }

        // Tab Management
        function showTab(tabId) {
            document.querySelectorAll('.tab-btn').forEach(btn => btn.classList.remove('active'));
            document.querySelectorAll('.tab-content').forEach(content => content.classList.remove('active'));
            
            event.target.classList.add('active');
            document.getElementById(tabId + '-tab').classList.add('active');
        }

        // News Management
        function renderNews() {
            const container = document.getElementById('news-list');
            container.innerHTML = '';
            currentData.news.forEach((text, index) => {
                container.innerHTML += `
                    <div class="news-item">
                        <input type="text" value="${text.replace(/"/g, '&quot;')}" onchange="updateNewsText(${index}, this.value)">
                        <button class="btn-icon delete" onclick="removeNewsItem(${index})"><i class="fas fa-trash-alt"></i></button>
                    </div>
                `;
            });
        }

        function addNewsItem() {
            currentData.news.push("");
            renderNews();
        }

        function updateNewsText(index, val) {
            currentData.news[index] = val;
        }

        function removeNewsItem(index) {
            currentData.news.splice(index, 1);
            renderNews();
        }

        async function saveNews() {
            const res = await apiPost('save_news', { news: currentData.news });
            if (res.success) alert('News gespeichert!');
            else alert('Fehler beim Speichern: ' + (res.error || 'Unbekannt'));
        }

        // Image Management
        function renderImages() {
            const container = document.getElementById('images-container');
            container.innerHTML = '';
            currentData.images.forEach((img) => {
                const filename = img.filename ?? img;
                const duration = img.duration ?? '';
                container.innerHTML += `
                    <div class="image-item" data-id="${filename}">
                        <img src="../images/${filename}" alt="${filename}" draggable="false">
                        <button class="delete-btn" onclick="deleteImage('${filename}')"><i class="fas fa-times"></i></button>
                        <div class="duration-row">
                            <input type="number" min="1" max="3600" placeholder="Standard (8s)"
                                   value="${duration}"
                                   onchange="updateImageDuration('${filename}', this)"
                                   title="Anzeigedauer in Sekunden (leer = Standard 8s)">
                            <span>Sek.</span>
                        </div>
                    </div>
                `;
            });

            // Re-init Sortable
            if (window.sortableInstance) window.sortableInstance.destroy();
            window.sortableInstance = new Sortable(container, {
                animation: 150,
                ghostClass: 'sortable-ghost',
                forceFallback: true,
                onEnd: function() {
                    const newOrder = Array.from(container.querySelectorAll('.image-item')).map(el => el.dataset.id);
                    currentData.images = newOrder.map(fn => currentData.images.find(i => (i.filename ?? i) === fn) || {filename: fn});
                    saveImageOrder(newOrder);
                }
            });
        }

        async function handleFileUpload(inputOrFile) {
            let file;
            if (inputOrFile.files) {
                file = inputOrFile.files[0];
            } else {
                file = inputOrFile;
            }
            
            if (!file) return;
            
            const formData = new FormData();
            formData.append('image', file);

            const res = await apiPost('upload_image', formData, true);
            if (res.success) {
                loadData(); // Reload all
            } else {
                alert('Fehler beim Upload: ' + (res.error || 'Unbekannt'));
            }
        }

        // Drag & Drop Upload Logic
        const dropZone = document.getElementById('drop-zone');
        
        ['dragenter', 'dragover', 'dragleave', 'drop'].forEach(eventName => {
            dropZone.addEventListener(eventName, preventDefaults, false);
        });

        function preventDefaults (e) {
            e.preventDefault();
            e.stopPropagation();
        }

        ['dragenter', 'dragover'].forEach(eventName => {
            dropZone.addEventListener(eventName, () => dropZone.classList.add('highlight'), false);
        });

        ['dragleave', 'drop'].forEach(eventName => {
            dropZone.addEventListener(eventName, () => dropZone.classList.remove('highlight'), false);
        });

        dropZone.addEventListener('drop', handleDrop, false);

        function handleDrop(e) {
            const dt = e.dataTransfer;
            const files = dt.files;
            handleFileUpload(files[0]);
        }

        async function updateImageDuration(filename, inputEl) {
            const raw = inputEl.value.trim();
            const duration = raw === '' ? null : parseInt(raw, 10);

            if (duration !== null && (isNaN(duration) || duration < 1 || duration > 3600)) {
                alert('Ungültige Dauer. Bitte 1–3600 Sekunden eingeben oder leer lassen für Standard.');
                inputEl.value = '';
                return;
            }

            const res = await apiPost('update_image_duration', { filename, duration });
            if (res.success) {
                const item = currentData.images.find(i => (i.filename ?? i) === filename);
                if (item && typeof item === 'object') {
                    if (duration !== null) item.duration = duration;
                    else delete item.duration;
                }
            } else {
                alert('Fehler beim Speichern der Dauer: ' + (res.error || 'Unbekannt'));
                inputEl.value = '';
            }
        }

        async function deleteImage(filename) {
            if (!confirm('Bild wirklich löschen?')) return;

            const res = await apiPost('delete_image', { filename });
            if (res.success) {
                loadData();
            } else {
                alert('Fehler beim Löschen: ' + (res.error || 'Unbekannt'));
            }
        }

        async function saveImageOrder(newOrder) {
            currentData.images = newOrder;
            const res = await apiPost('reorder_images', { images: newOrder });
            const status = document.getElementById('image-status');
            if (res.success) {
                status.style.display = 'block';
                setTimeout(() => status.style.display = 'none', 2000);
            } else {
                alert('Fehler beim Sortieren: ' + (res.error || 'Unbekannt'));
            }
        }

        // Device Management
        function renderDevices() {
            const container = document.getElementById('device-list');
            // Keep the header
            const header = container.firstElementChild.outerHTML;
            container.innerHTML = header;

            Object.entries(currentData.config.devices).forEach(([uuid, name]) => {
                container.innerHTML += `
                    <div class="device-row">
                        <input type="text" value="${uuid}" placeholder="UUID" onchange="updateDeviceUUID('${uuid}', this.value)">
                        <input type="text" value="${name}" placeholder="Name" onchange="updateDeviceName('${uuid}', this.value)">
                        <button class="btn-icon delete" onclick="removeDevice('${uuid}')"><i class="fas fa-trash-alt"></i></button>
                    </div>
                `;
            });
        }

        function addDeviceItem() {
            const newUuid = 'NEW-' + Math.random().toString(36).substr(2, 9);
            currentData.config.devices[newUuid] = "Neues Gerät";
            renderDevices();
        }

        function updateDeviceUUID(oldUuid, newUuid) {
            const name = currentData.config.devices[oldUuid];
            delete currentData.config.devices[oldUuid];
            currentData.config.devices[newUuid] = name;
            // No re-render to avoid losing focus, but data is updated
        }

        function updateDeviceName(uuid, newName) {
            currentData.config.devices[uuid] = newName;
        }

        function removeDevice(uuid) {
            delete currentData.config.devices[uuid];
            renderDevices();
        }

        async function saveDevices() {
            // Need to collect from inputs because UUID changes aren't tracked perfectly without re-render
            const rows = document.querySelectorAll('#device-list .device-row');
            const newDevices = {};
            rows.forEach((row, index) => {
                if (index === 0) return; // Skip header
                const inputs = row.querySelectorAll('input');
                const uuid = inputs[0].value.trim();
                const name = inputs[1].value.trim();
                if (uuid) newDevices[uuid] = name;
            });

            const res = await apiPost('save_devices', { devices: newDevices });
            if (res.success) alert('Geräte gespeichert!');
            else alert('Fehler beim Speichern: ' + (res.error || 'Unbekannt'));
        }

        // Settings Management
        function renderSettings() {
            document.getElementById('settings-primary-color').value = currentData.config.primary_color || '#4a657a';
            document.getElementById('settings-primary-text').value = currentData.config.primary_color || '#4a657a';
            document.getElementById('settings-secondary-color').value = currentData.config.secondary_color || '#f39200';
            document.getElementById('settings-secondary-text').value = currentData.config.secondary_color || '#f39200';

            // Sync color picker with text input
            document.getElementById('settings-primary-color').addEventListener('input', (e) => {
                document.getElementById('settings-primary-text').value = e.target.value;
            });
            document.getElementById('settings-primary-text').addEventListener('input', (e) => {
                if(/^#[0-9A-F]{6}$/i.test(e.target.value)) {
                    document.getElementById('settings-primary-color').value = e.target.value;
                }
            });
            document.getElementById('settings-secondary-color').addEventListener('input', (e) => {
                document.getElementById('settings-secondary-text').value = e.target.value;
            });
            document.getElementById('settings-secondary-text').addEventListener('input', (e) => {
                if(/^#[0-9A-F]{6}$/i.test(e.target.value)) {
                    document.getElementById('settings-secondary-color').value = e.target.value;
                }
            });
        }

        async function saveSettings() {
            const primary = document.getElementById('settings-primary-text').value;
            const secondary = document.getElementById('settings-secondary-text').value;
            const logoFile = document.getElementById('settings-logo').files[0];

            const formData = new FormData();
            formData.append('primary_color', primary);
            formData.append('secondary_color', secondary);
            if (logoFile) {
                formData.append('logo', logoFile);
            }

            const res = await apiPost('save_settings', formData, true);
            if (res.success) {
                alert('Einstellungen gespeichert!');
                document.getElementById('settings-logo').value = '';

                // Refresh data and update header logo if changed
                await loadData();
                if (currentData.config.logo) {
                    document.getElementById('header-logo').src = '../images/' + currentData.config.logo + '?t=' + Date.now();
                }
            } else {
                alert('Fehler beim Speichern: ' + (res.error || 'Unbekannt'));
            }
        }

        // --- Online-Update ---
        function escapeHtml(str) {
            return String(str).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
        }

        function formatBytes(bytes) {
            if (bytes >= 1024 * 1024) return (bytes / 1024 / 1024).toFixed(1) + ' MB';
            return Math.round(bytes / 1024) + ' KB';
        }

        let updateZipOk = true;

        async function loadUpdateInfo() {
            const r = await fetch('api.php?action=update_info');
            if (r.status === 401) { handleSessionExpired(); return; }
            const info = await r.json();
            updateZipOk = !!info.zip_ok && !info.default_password;
            document.getElementById('update-default-pw').style.display = info.default_password ? 'block' : 'none';
            document.getElementById('update-installed').textContent = 'v' + info.version;
            document.getElementById('update-repo').textContent = info.repo;
            document.getElementById('update-max-upload').textContent = info.max_upload;
            document.getElementById('update-git-hint').style.display = info.git_checkout ? 'block' : 'none';
            document.getElementById('update-zip-missing').style.display = info.zip_ok ? 'none' : 'block';
            document.getElementById('update-upload-btn').disabled = !updateZipOk;

            const list = document.getElementById('update-backup-list');
            list.innerHTML = (info.backups || []).map(b =>
                `<li>${escapeHtml(b.name)} (${formatBytes(b.size)})</li>`).join('');
            document.getElementById('update-backups').style.display = (info.backups || []).length ? 'block' : 'none';
        }

        async function checkForUpdate() {
            const btn = document.getElementById('update-check-btn');
            const result = document.getElementById('update-result');
            btn.disabled = true;
            btn.innerHTML = '<i class="fas fa-sync-alt fa-spin"></i> Suche läuft …';
            result.innerHTML = '';
            const res = await apiPost('update_check', {});
            btn.disabled = false;
            btn.innerHTML = '<i class="fas fa-sync-alt"></i> Nach Updates suchen';

            if (!res.success) {
                result.innerHTML = `<div class="update-alert update-alert-error">${escapeHtml(res.error || 'Unbekannter Fehler')}</div>`;
                return;
            }
            if (!res.newer) {
                result.innerHTML = `<div class="update-alert update-alert-success"><i class="fas fa-check-circle"></i> Du hast die neueste Version (v${escapeHtml(res.version)}).</div>`;
                return;
            }
            const sub = (res.name && res.name !== 'smzPulse ' + res.version ? escapeHtml(res.name) + ' · ' : '')
                + 'installiert ist v' + escapeHtml(res.installed);
            result.innerHTML = `
                <div class="update-card">
                    <div class="update-card-head">
                        <span class="update-badge">Neu</span>
                        <div>
                            <div class="update-card-title">Version ${escapeHtml(res.version)} ist verfügbar</div>
                            <div class="update-card-sub">${sub}</div>
                        </div>
                    </div>
                    ${res.notes_html ? `<div class="update-notes">${res.notes_html}</div>` : ''}
                    ${updateZipOk ? `
                    <div class="update-card-actions">
                        <button class="btn-primary btn-inline" id="update-install-btn" data-version="${escapeHtml(res.version)}" onclick="installUpdate(this)">
                            <i class="fas fa-download"></i> Jetzt installieren
                        </button>
                        <span>Vorher wird automatisch ein Backup angelegt.</span>
                    </div>` : ''}
                </div>`;
        }

        async function runUpdate(btn, action, body, isFormData) {
            btn.disabled = true;
            btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Update läuft … bitte warten';
            const res = await apiPost(action, body, isFormData);
            if (res.success) {
                alert('Update auf Version ' + res.version + ' installiert. Die Seite wird neu geladen.');
                window.location.reload();
            } else {
                alert(res.error || 'Update fehlgeschlagen.');
                loadUpdateInfo();
                btn.disabled = false;
                btn.innerHTML = action === 'update_install'
                    ? '<i class="fas fa-download"></i> Jetzt installieren'
                    : '<i class="fas fa-upload"></i> Hochladen &amp; installieren';
            }
        }

        function installUpdate(btn) {
            const version = btn.dataset.version;
            if (!confirm('Update auf Version ' + version + ' jetzt installieren? Vorher wird automatisch ein Backup der Programmdateien angelegt.')) return;
            runUpdate(btn, 'update_install', {}, false);
        }

        function uploadUpdate() {
            const file = document.getElementById('update-package').files[0];
            if (!file) {
                alert('Bitte zuerst eine Update-Datei (ZIP) auswählen.');
                return;
            }
            if (!confirm('Update-Paket jetzt installieren? Vorher wird automatisch ein Backup der Programmdateien angelegt.')) return;
            const formData = new FormData();
            formData.append('package', file);
            runUpdate(document.getElementById('update-upload-btn'), 'update_upload', formData, true);
        }

        // --- Mein Konto ---
        async function changeOwnPassword() {
            const current = document.getElementById('account-current').value;
            const pw = document.getElementById('account-new').value;
            const confirmPw = document.getElementById('account-confirm').value;
            if (!current) { alert('Bitte das aktuelle Passwort eingeben.'); return; }
            if (pw !== confirmPw) { alert('Die neuen Passwörter stimmen nicht überein!'); return; }
            if (pw.length < 8) { alert('Das Passwort muss mindestens 8 Zeichen lang sein.'); return; }
            const res = await apiPost('change_password', { current_password: current, new_password: pw });
            if (res.success) {
                ['account-current', 'account-new', 'account-confirm'].forEach(id => document.getElementById(id).value = '');
                alert('Passwort geändert.');
                window.location.reload();
            } else {
                alert('Fehler: ' + (res.error || 'Unbekannt'));
            }
        }

        // --- Nutzerverwaltung (nur Admins) ---
        const ROLE_LABELS = { admin: 'Admin', editor: 'Redakteur' };

        async function loadUsers() {
            const r = await fetch('api.php?action=users_list');
            if (r.status === 401) { handleSessionExpired(); return; }
            const data = await r.json();
            const list = document.getElementById('user-list');
            list.innerHTML = '';
            (data.users || []).forEach(u => {
                const isMe = u.name === data.me;
                const row = document.createElement('div');
                row.className = 'user-row';
                row.innerHTML = `
                    <div class="user-name">
                        <i class="fas fa-user"></i> <strong></strong>
                        ${isMe ? '<span class="text-muted">(du)</span>' : ''}
                        ${u.default_password ? '<span class="role-badge role-warn">Standard-Passwort!</span>' : ''}
                    </div>
                    <select class="user-role" ${isMe ? 'disabled title="Die eigene Rolle kann ein anderer Admin ändern."' : ''}>
                        <option value="editor">Redakteur</option>
                        <option value="admin">Admin</option>
                    </select>
                    <button class="btn-icon" title="Passwort neu setzen" ${isMe ? 'disabled' : ''}><i class="fas fa-key"></i></button>
                    <button class="btn-icon delete" title="Nutzer löschen" ${isMe ? 'disabled' : ''}><i class="fas fa-trash-alt"></i></button>
                    <div class="user-pw-reset" style="display: none;">
                        <input type="password" placeholder="Neues Passwort (mind. 8 Zeichen)" autocomplete="new-password">
                        <button class="btn-primary btn-inline">Setzen</button>
                    </div>
                `;
                row.querySelector('strong').textContent = u.name;   // textContent: kein HTML aus Namen
                const sel = row.querySelector('select');
                sel.value = u.role;
                sel.addEventListener('change', () => updateUserRole(u.name, sel));
                const [keyBtn, delBtn, setBtn] = row.querySelectorAll('button');
                const pwBox = row.querySelector('.user-pw-reset');
                keyBtn.addEventListener('click', () => {
                    pwBox.style.display = pwBox.style.display === 'none' ? 'flex' : 'none';
                    pwBox.querySelector('input').focus();
                });
                setBtn.addEventListener('click', () => resetUserPassword(u.name, pwBox.querySelector('input')));
                delBtn.addEventListener('click', () => deleteUser(u.name));
                list.appendChild(row);
            });
        }

        async function createUser() {
            const name = document.getElementById('new-user-name').value.trim().toLowerCase();
            const role = document.getElementById('new-user-role').value;
            const password = document.getElementById('new-user-password').value;
            if (!/^[a-z0-9._-]{2,32}$/.test(name)) {
                alert('Benutzername: 2–32 Zeichen, nur Kleinbuchstaben, Zahlen, Punkt, Minus, Unterstrich.');
                return;
            }
            if (password.length < 8) { alert('Das Passwort muss mindestens 8 Zeichen lang sein.'); return; }
            const res = await apiPost('user_create', { name, role, password });
            if (res.success) {
                document.getElementById('new-user-name').value = '';
                document.getElementById('new-user-password').value = '';
                document.getElementById('new-user-role').value = 'editor';
                loadUsers();
            } else {
                alert('Fehler: ' + (res.error || 'Unbekannt'));
            }
        }

        async function updateUserRole(name, sel) {
            const role = sel.value;
            if (!confirm(`Rolle von „${name}“ auf „${ROLE_LABELS[role]}“ ändern?`)) { loadUsers(); return; }
            const res = await apiPost('user_update', { name, role });
            if (!res.success) alert('Fehler: ' + (res.error || 'Unbekannt'));
            loadUsers();
        }

        async function resetUserPassword(name, input) {
            const pw = input.value;
            if (pw.length < 8) { alert('Das Passwort muss mindestens 8 Zeichen lang sein.'); return; }
            if (!confirm(`Passwort für „${name}“ neu setzen? Die Person wird dabei überall abgemeldet.`)) return;
            const res = await apiPost('user_update', { name, password: pw });
            if (res.success) {
                alert('Passwort für „' + name + '“ gesetzt.');
                if (IS_ADMIN) loadUpdateInfo();
                loadUsers();
            } else {
                alert('Fehler: ' + (res.error || 'Unbekannt'));
            }
        }

        async function deleteUser(name) {
            if (!confirm(`Nutzer „${name}“ wirklich löschen? Er wird sofort abgemeldet.`)) return;
            const res = await apiPost('user_delete', { name });
            if (!res.success) alert('Fehler: ' + (res.error || 'Unbekannt'));
            loadUsers();
        }

        // Start
        loadData();
        if (IS_ADMIN) {
            loadUpdateInfo();
            loadUsers();
        }
    </script>
</body>
</html>
