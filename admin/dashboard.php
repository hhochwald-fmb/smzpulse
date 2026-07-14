<?php
require_once __DIR__ . '/session_bootstrap.php';
smzpulse_init_session();
if (!smzpulse_check_timeout(true)) {
    header("Location: index.php?expired=1");
    exit;
}
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['csrf_token'];

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
                <a href="index.php?logout=1" class="logout-link"><i class="fas fa-sign-out-alt"></i> Abmelden</a>
            </div>
        </header>

        <div class="tabs">
            <button class="tab-btn active" onclick="showTab('images')">Bilder Gallerie</button>
            <button class="tab-btn" onclick="showTab('news')">News Ticker</button>
            <button class="tab-btn" onclick="showTab('devices')">Apple TV Geräte</button>
            <button class="tab-btn" onclick="showTab('settings')">Einstellungen</button>
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
                <p class="text-muted" style="margin-bottom: 20px;">Hier können Sie das Admin-Passwort und die Farben der App anpassen.</p>
                
                <div class="settings-form">
                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px;">
                        <div class="form-group">
                            <label>Admin Passwort</label>
                            <input type="password" id="settings-password" placeholder="Neues Passwort">
                        </div>
                        <div class="form-group">
                            <label>Passwort wiederholen</label>
                            <input type="password" id="settings-password-confirm" placeholder="Passwort bestätigen">
                        </div>
                    </div>
                    
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
    </div>

    <script>
        let currentData = {
            news: [],
            images: [],
            config: { devices: {} }
        };

        const CSRF_TOKEN = document.querySelector('meta[name="csrf-token"]').getAttribute('content');

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
            renderDevices();
            renderSettings();
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
            const password = document.getElementById('settings-password').value;
            const confirm = document.getElementById('settings-password-confirm').value;
            const primary = document.getElementById('settings-primary-text').value;
            const secondary = document.getElementById('settings-secondary-text').value;
            const logoFile = document.getElementById('settings-logo').files[0];

            if (password !== confirm) {
                alert('Passwörter stimmen nicht überein!');
                return;
            }

            const formData = new FormData();
            formData.append('admin_password', password);
            formData.append('primary_color', primary);
            formData.append('secondary_color', secondary);
            if (logoFile) {
                formData.append('logo', logoFile);
            }

            const res = await apiPost('save_settings', formData, true);
            if (res.success) {
                alert('Einstellungen gespeichert!');
                document.getElementById('settings-password').value = '';
                document.getElementById('settings-password-confirm').value = '';
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

        // Start
        loadData();
    </script>
</body>
</html>
