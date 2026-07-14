# smzPulse — Apple-TV Digital-Signage-System
**Version 1.1.0**

Web-Backend zur Verwaltung von Bildern, News-Texten und Geräten für eine Apple-TV-App, die im SMZ Stuttgart als digitales Display-System läuft.

Die Apple-TV-App ruft sich Inhalte und Konfiguration als statische Dateien direkt vom Webserver ab (kein API-Aufruf). Geändert werden diese Inhalte über das hier enthaltene PHP-Admin-Backend.

---

## Inhaltsverzeichnis

- [Systemanforderungen](#systemanforderungen)
- [Installation](#installation)
  - [Variante A — Externes Hosting (z.B. Mittwald)](#variante-a--externes-hosting)
  - [Variante B — Lokaler Server im Schulnetz (MAMP / XAMPP)](#variante-b--lokaler-server-im-schulnetz)
  - [Variante C — Docker](#variante-c--docker)
- [Erstes Login](#erstes-login)
- [Verzeichnisstruktur](#verzeichnisstruktur)
- [Was die Apple-TV-App braucht](#was-die-apple-tv-app-braucht)
- [Apple-TV-App konfigurieren](#apple-tv-app-konfigurieren)
- [Sicherheits-Check nach Installation](#sicherheits-check-nach-installation)
- [Konfiguration anpassen](#konfiguration-anpassen)
- [Backup](#backup)
- [Troubleshooting](#troubleshooting)
- [Externe Komponenten / Lizenzen](#externe-komponenten--lizenzen)

---

## Systemanforderungen

| Komponente | Anforderung |
|---|---|
| Webserver | Apache 2.4+ (oder LiteSpeed). Nginx funktioniert nur mit zusätzlich übersetzten `location`-Regeln, weil hier `.htaccess`-Dateien für die Sicherheit zuständig sind. |
| Apache-Config | `AllowOverride All` (oder mindestens `Limit FileInfo`) für das Web-Verzeichnis |
| PHP | 7.4 oder höher (8.x empfohlen) |
| PHP-Extension | `fileinfo` (für die MIME-Prüfung beim Upload) |
| Schreibrechte | PHP muss schreiben können auf: `config.json`, `auth.php`, `theme.js`, `news/news.json`, `images/images.json` und in den Ordnern `images/`, `news/` |
| Browser für Admin | Aktueller Chrome / Safari / Firefox / Edge |
| Apple-TV-Zugriff | HTTP (oder HTTPS) auf die statischen Dateien — keine Cookies/Sessions nötig |

---

## Installation

### Variante A — Externes Hosting

Geeignet wenn die Apple TVs Internetzugriff haben und das Backend nicht zwingend im Schulnetz stehen muss. Beispiel: Mittwald, All-Inkl, Hetzner, Strato.

1. Per FTP **die gesamte Verzeichnisstruktur** in den Web-Root des Hosting-Pakets hochladen (z.B. `htdocs/smzpulse/` oder direkt in den Root, je nachdem wie deine URL aussehen soll).
2. **FTP-Client muss versteckte Dateien anzeigen** — sonst werden die `.htaccess`-Dateien nicht übertragen, und damit fehlen die wichtigsten Schutzmechanismen. In FileZilla z.B. unter *Server → Versteckte Dateien anzeigen*.
3. Sicherstellen, dass diese Dateien tatsächlich auf dem Server liegen:
   - `/smzpulse/.htaccess`
   - `/smzpulse/images/.htaccess`
4. `auth.php` muss schreibbar sein (z.B. 644 oder 664), gleiches gilt für die JSON-Dateien.
5. Im Browser `https://deine-domain.de/smzpulse/admin/` öffnen → siehe [Erstes Login](#erstes-login).
6. Den [Sicherheits-Check](#sicherheits-check-nach-installation) durchgehen.

Die default `index.php` mancher Hoster (z.B. Mittwalds "Hallo 👋"-Seite) im Web-Root kann gelöscht werden — der Apple TV braucht keine Root-URL, und die `.htaccess` schließt das Directory-Listing.

### Variante B — Lokaler Server im Schulnetz

Geeignet wenn die Apple TVs ohnehin im selben Netz hängen, du keinen Internet-Hosting-Vertrag brauchst und alles offline funktionieren soll. Kein DSGVO-Risiko durch externe Server.

**Voraussetzungen für den Server-Rechner:**

- Mac (mit MAMP) oder Windows-PC (mit XAMPP)
- Dauerhaft eingeschaltet (kein Ruhezustand)
- Feste IP-Adresse im Schulnetz
- Per Ethernet ans Netzwerk (kein WLAN)
- Erreichbar von allen Apple TVs (kein VLAN-Trennung, ggf. Firewall)

#### 1. Webserver installieren

**Mac (MAMP):**
- Von [mamp.info](https://www.mamp.info) MAMP herunterladen und in `/Applications/MAMP/` installieren
- MAMP starten, in den Einstellungen prüfen:
  - *Apache & MySQL → Apache Port:* 80 (Standard 8888 vermeiden, damit URLs ohne Port aufgerufen werden können)
  - *PHP → Version:* 8.x
- Den smzPulse-Ordner nach `/Applications/MAMP/htdocs/smzpulse/` legen

**Windows (XAMPP):**
- Von [apachefriends.org](https://www.apachefriends.org) XAMPP herunterladen und installieren
- Nur Apache + PHP nötig (MySQL nicht erforderlich, kann deaktiviert werden)
- Den smzPulse-Ordner nach `C:\xampp\htdocs\smzpulse\` legen
- Im XAMPP Control Panel: Apache **starten**

#### 2. Feste IP-Adresse einrichten

**Variante 1 (empfohlen):** Im Router (Fritzbox, Schul-Router) eine DHCP-Reservierung für die MAC-Adresse des Server-Rechners anlegen. Vorteil: am Rechner nichts ändern, IP bleibt auch nach Neustart gleich.

**Variante 2:** Am Rechner manuell festlegen.
- *Mac:* Systemeinstellungen → Netzwerk → Ethernet → Details → TCP/IP → IPv4 konfigurieren: *Manuell*. IP / Subnetz / Router / DNS eintragen.
- *Windows:* Systemsteuerung → Netzwerk- und Freigabecenter → Adaptereinstellungen ändern → Eigenschaften des Ethernet-Adapters → IPv4 → Folgende IP-Adresse verwenden.

Notiere die gewählte IP — du brauchst sie in der Apple-TV-App.

#### 3. Server soll nicht schlafen

**Mac:**
- *Systemeinstellungen → Energie:*
  - „Computer-Ruhezustand": **Nie**
  - „Festplatten in den Ruhezustand": aus
  - „Automatisch starten nach Stromausfall": ein
- Bildschirm darf ausgehen — der Server läuft trotzdem weiter

**Windows:**
- *Systemsteuerung → Energieoptionen → Höchstleistung*
- *Energieoptionen → Planeinstellungen ändern → Erweiterte Energieeinstellungen:*
  - Festplatten: nie ausschalten
  - Energiesparmodus / Ruhezustand: nie
- *Einstellungen → System → Netzbetrieb und Energiesparen:* alle Sleep-Optionen auf „Nie"

#### 4. Autostart von Webserver + Software

**Mac (MAMP):**
- MAMP → *Einstellungen → Start/Stop:* „Apache Server beim Start von MAMP starten" aktivieren, „Stoppe Server beim Beenden" deaktivieren
- Systemeinstellungen → Allgemein → Anmeldeobjekte: MAMP.app hinzufügen, damit MAMP nach Login automatisch startet
- Automatischen Login einrichten (optional): Systemeinstellungen → Benutzer → Anmeldeoptionen → automatisches Anmelden für den Server-Account

**Windows (XAMPP):**
- XAMPP Control Panel als Administrator öffnen → *Config → Service and Port Settings → Apache:* „Service" aktivieren → speichern. Apache läuft jetzt als Windows-Dienst und startet automatisch.
- Alternativ: XAMPP Control Panel in den Autostart-Ordner legen

#### 5. Firewall öffnen

Port 80 (oder welchen Port du gewählt hast) für eingehende Verbindungen freigeben:

- *Mac:* Systemeinstellungen → Netzwerk → Firewall → Optionen → „httpd" / „MAMP" eingehende Verbindungen erlauben
- *Windows:* Windows Defender Firewall → Erweiterte Einstellungen → Eingehende Regeln → Neue Regel → Port → TCP 80 → Verbindung zulassen

#### 6. Funktion testen

- Vom Server-Rechner: `http://localhost/smzpulse/admin/` → Login-Seite muss erscheinen
- Von einem anderen Gerät im Netz: `http://<feste-IP>/smzpulse/admin/` → muss ebenfalls erscheinen

### Variante C — Docker

Geeignet wenn Docker bereits vorhanden ist — egal ob auf Mac, Windows oder Linux. Kein MAMP/XAMPP nötig, läuft vollständig isoliert.

#### Voraussetzungen

- [Docker Desktop](https://www.docker.com/products/docker-desktop/) (Mac/Windows) oder Docker + Docker Compose (Linux)

#### Installation per GitHub (empfohlen)

```bash
git clone https://github.com/hhochwald-fmb/smzpulse.git
cd smzpulse
docker compose up -d --build
```

#### Installation per ZIP

1. Auf der GitHub-Projektseite: **Code → Download ZIP**
2. ZIP entpacken
3. Im Terminal in den entpackten Ordner wechseln:

```bash
cd smzpulse-main
docker compose up -d --build
```

Nach dem Start ist das Admin-Backend erreichbar unter:

```
http://localhost:8081/admin/
```

> **Port belegt?** In `docker-compose.yml` den linken Port ändern, z.B. `"8082:80"`.

#### Nützliche Befehle

```bash
docker compose stop             # Backend stoppen (Daten bleiben erhalten)
docker compose up -d            # Backend wieder starten
docker compose up -d --build    # Nach Code-Änderungen neu bauen
docker compose logs -f          # Logs live verfolgen
```

#### Datenpersistenz

Das gesamte Projektverzeichnis wird als Volume in den Container eingebunden. Alle Änderungen — hochgeladene Bilder, News-Texte, Passwort, Konfiguration — werden direkt im Projektordner auf dem Host gespeichert und überleben Container-Neustarts und Rebuilds.

---

## Erstes Login

URL: `http://<dein-server>/smzpulse/admin/`

Initial-Zugang (Standard aus dem Repository):
- **Benutzer:** `admin`
- **Passwort:** `smzpulse`

> ⚠️ **Direkt nach dem ersten Login das Passwort ändern**: Einstellungen → Admin Passwort.  
> Das Standard-Passwort ist öffentlich bekannt — bei produktivem Einsatz unbedingt sofort ersetzen.
>
> Das Backend hasht das neue Passwort automatisch mit bcrypt und speichert es in `auth.php`. Der Klartext landet nirgendwo.

Nach dem Login findest du im Dashboard vier Tabs:
- **Bilder Gallerie** — Upload, Löschen, Sortieren per Drag&Drop
- **News Ticker** — Texte für den unteren Bildschirmrand
- **Apple TV Geräte** — UUIDs der registrierten TVs (siehe deine Apple-TV-App-Dokumentation, wie du an die UUID kommst)
- **Einstellungen** — Passwort, Logo, Primär-/Sekundärfarbe

---

## Verzeichnisstruktur

```
smzpulse/
├── .htaccess              # Schützt auth.php / .bak / .tmp, blockt Directory-Listing
├── config.json            # Devices, Farben, Logo-Dateiname (öffentlich lesbar — App braucht das)
├── auth.php               # Passwort-Hash (geschützt, nur Server-seitig)
├── theme.js               # Wird beim Speichern in Einstellungen automatisch generiert
├── news/
│   └── news.json          # Array der Ticker-Texte
├── images/
│   ├── .htaccess          # Verbietet PHP-Execution, blockt Directory-Listing
│   ├── images.json        # Array der Bild-Dateinamen (Reihenfolge = Anzeige-Reihenfolge)
│   ├── logo.svg           # Default-Logo (kann durch Custom-Upload überschrieben werden)
│   └── *.png / *.jpg      # Slideshow-Bilder
└── admin/
    ├── index.php          # Login
    ├── dashboard.php      # Hauptansicht (Tabs)
    ├── api.php            # JSON-API für AJAX-Aktionen
    ├── session_bootstrap.php
    ├── auth_helper.php
    ├── style.css
    └── vendor/            # Lokale Kopien von Inter, Font Awesome, SortableJS
```

---

## Was die Apple-TV-App braucht

Die App ruft folgende URLs ab — diese müssen ohne Login erreichbar sein:

```
http://<server>/smzpulse/config.json
http://<server>/smzpulse/theme.js
http://<server>/smzpulse/news/news.json
http://<server>/smzpulse/images/images.json
http://<server>/smzpulse/images/<dateiname>
```

Was die App **nicht** sehen sollte (durch `.htaccess` blockiert):
- `auth.php` — Passwort-Hash
- `*.bak*` — alte Backup-Dateien
- `*.tmp.*` — temporäre Schreibdateien während Updates
- Directory-Listings (`/smzpulse/`, `/smzpulse/images/`)

---

## Apple-TV-App konfigurieren

Die SMZ Pulse One App benötigt zwei Parameter: die Server-URL und einen Gerätenamen. Diese können entweder manuell in der App eingegeben oder per MDM automatisch verteilt werden.

### Manuelle Konfiguration

Beim ersten Start der App auf dem Apple TV die Server-URL direkt eingeben — **ersetze die URL durch die eigene Server-Adresse**, unter der das Backend erreichbar ist:

```
https://smzpulse.smz-stuttgart.de
```

Den Gerätenamen individuell pro Apple TV vergeben (erscheint im Admin-Backend unter „Apple TV Geräte").

### Konfiguration per Jamf Pro (empfohlen bei mehreren Geräten)

Jamf Pro kann die App-Konfiguration automatisch an alle verwalteten Apple TVs ausrollen. Dazu in Jamf Pro unter **Devices → Apps → SMZ Pulse One → App Configuration** folgenden Plist-Inhalt einfügen:

```xml
<dict>
    <key>serverURL</key>
    <string>https://smzpulse.smz-stuttgart.de</string>
    <key>deviceName</key>
    <string>$DEVICENAME</string>
</dict>
```

> ⚠️ Den Wert `https://smzpulse.smz-stuttgart.de` im `<string>`-Tag durch die eigene Server-URL ersetzen, unter der das smzPulse-Backend läuft (z.B. `https://mein-server.de/smzpulse` oder `http://192.168.1.100`).

`$DEVICENAME` ist eine Jamf-Variable und wird beim Ausrollen automatisch durch den jeweiligen Gerätenamen aus Jamf ersetzt — jedes Apple TV trägt damit seinen eigenen Namen, ohne manuelle Eingabe.

---

## Sicherheits-Check nach Installation

Im Browser aufrufen, gewünschtes Ergebnis prüfen:

| URL | Soll |
|---|---|
| `http://<server>/smzpulse/admin/` | Login-Seite (mit korrekter Schrift "Inter" und Icons) |
| `http://<server>/smzpulse/config.json` | JSON wird ausgegeben |
| `http://<server>/smzpulse/auth.php` | **Leere Antwort oder 403 Forbidden** — keinesfalls Inhalt |
| `http://<server>/smzpulse/` | 403 Forbidden (kein Verzeichnis-Listing) |
| `http://<server>/smzpulse/images/` | 403 Forbidden |
| `http://<server>/smzpulse/news/news.json` | JSON wird ausgegeben |

Wenn `auth.php` Inhalt liefert oder ein Verzeichnis-Listing erscheint → `.htaccess` wird vom Apache nicht ausgewertet. In dem Fall:
- Beim Hoster `AllowOverride All` aktivieren lassen, oder
- Bei MAMP/XAMPP in der Apache-Konfiguration prüfen (sollte standardmäßig OK sein)

---

## Konfiguration anpassen

**Login-Timeout** (Inaktivität bis automatischer Logout):
- Datei: `admin/session_bootstrap.php`
- Konstante: `SMZPULSE_INACTIVITY_TIMEOUT` — Wert in Sekunden, default **1800** (= 30 min)

**Upload-Größenlimit:**
- Datei: `admin/api.php`, Action `upload_image`
- Aktuell 20 MB für Bilder, 5 MB für Logo
- PHP muss auch `upload_max_filesize` und `post_max_size` entsprechend hoch gesetzt haben (`php.ini`)

**Erlaubte Bildformate:**
- Datei: `admin/api.php`, Funktion `validate_uploaded_image`
- Aktuell: JPEG, PNG, WebP, GIF, SVG

---

## Backup

Regelmäßig sichern (z.B. wöchentlich):

| Pfad | Inhalt |
|---|---|
| `auth.php` | Admin-Passwort-Hash |
| `config.json` | Devices, Farben, Logo-Dateiname |
| `news/news.json` | Ticker-Texte |
| `images/images.json` | Bildreihenfolge |
| `images/` (komplett) | Alle Bilddateien + Logo |

Die Dateien unter `admin/` sind statisch — die kannst du jederzeit aus dem Original-Repo wiederherstellen.

---

## Troubleshooting

**Login funktioniert nicht / kommt sofort zurück:**
- PHP-Sessions schreibbar? In `php.ini` `session.save_path` prüfen
- Cookies im Browser aktiviert?
- `auth.php` lesbar? Inhalt sollte mit `<?php` beginnen und ein Array mit `admin_password` zurückgeben

**„Sie wurden aufgrund von Inaktivität abgemeldet" zu früh:**
- Vermutlich war die Session beim Reload wirklich abgelaufen, oder PHP-Sessions liegen auf einem Storage, das nicht persistiert
- Timeout in `admin/session_bootstrap.php` ggf. erhöhen

**Upload schlägt fehl:**
- Schreibrechte auf `images/` und `images/images.json` prüfen
- PHP-Limits prüfen: `upload_max_filesize`, `post_max_size`, `max_file_uploads`
- Bei großen Bildern: ggf. `memory_limit` in `php.ini` erhöhen

**Icons fehlen (leere Quadrate statt Trash/Plus/Upload):**
- `admin/vendor/fontawesome/` ist nicht hochgeladen oder nicht erreichbar
- Im Browser-DevTools (Netzwerk-Tab) nach 404 auf `fa-solid-900.woff2` suchen

**Schrift sieht aus wie Times New Roman:**
- `admin/vendor/inter/` fehlt oder die WOFF2-Dateien wurden nicht binär übertragen
- FTP-Client auf Binär-Modus stellen (nicht ASCII)

**Apple TV zeigt keine Bilder / News:**
- Vom Apple TV oder einem anderen Gerät im selben Netz die JSON-URLs direkt im Browser aufrufen — kommen sie?
- Firewall am Server: Port 80 für eingehend offen?
- Apple TV im richtigen Netz / VLAN?

**Konfig-Änderungen werden nicht wirksam:**
- Browser-Cache leeren (insbesondere wenn `theme.js` neu geschrieben wurde)
- Apple TV: App neu starten kann nötig sein

---

## Externe Komponenten / Lizenzen

Alle Drittanbieter-Ressourcen sind lokal eingebunden (`admin/vendor/`) — **keine externen CDNs**, keine Datenübertragung an Dritte, DSGVO-konform:

| Komponente | Version | Lizenz | Pfad |
|---|---|---|---|
| Inter (Google Fonts, Variable Style) | latin + latin-ext | SIL Open Font License 1.1 | `admin/vendor/inter/` |
| Font Awesome Free | 6.4.0 | CC BY 4.0 (Icons) + SIL OFL 1.1 (Fonts) | `admin/vendor/fontawesome/` |
| SortableJS | 1.15.0 | MIT | `admin/vendor/sortablejs/` |

---

## Sicherheits-Architektur (Kurzfassung)

- Login: Passwort wird mit `password_hash()` (bcrypt) gespeichert in `auth.php`
- Sessions: HttpOnly + SameSite=Lax Cookies, `session_regenerate_id` beim Login
- CSRF: Jede mutierende API-Aktion erwartet `X-CSRF-Token` Header (im Dashboard automatisch beigefügt)
- Upload-Validierung: MIME-Check via `finfo`, Endung-Whitelist, Größenlimit, System-generierte Dateinamen
- Path-Traversal-Schutz: `basename()` + Whitelist-Abgleich gegen `images.json` beim Löschen
- `images/`-Ordner: PHP-Execution per `.htaccess` deaktiviert, Directory-Listing aus
- Inaktivitäts-Logout: 30 min Default mit JS-Heartbeat zur Detektion
