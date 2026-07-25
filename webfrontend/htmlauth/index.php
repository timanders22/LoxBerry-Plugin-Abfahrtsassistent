<?php
/**
 * Abfahrts-Assistent - Admin-Oberflaeche (v1.3.7)
 * Reiter: Einstellungen | Einbindung in Loxone | Test | Logdateien
 */

error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);
ini_set('display_errors', '1');

$lbhomedir = getenv('LBHOMEDIR') ?: (is_dir('/opt/loxberry') ? '/opt/loxberry' : '');
$plugindir = getenv('LBPPLUGINDIR') ?: basename(__DIR__);
if ($lbhomedir && is_dir($lbhomedir . '/bin/plugins/' . $plugindir) === false && is_dir($lbhomedir . '/config/plugins/' . $plugindir) === false) {
    $plugindir = basename(dirname(__DIR__));
}
if ($lbhomedir) {
    $sdk_system = $lbhomedir . '/libs/phplib/loxberry_system.php';
    $sdk_web = $lbhomedir . '/libs/phplib/loxberry_web.php';
    if (file_exists($sdk_system)) {
        require_once $sdk_system;
        require_once $sdk_web;
    }
    $config_dir = $lbhomedir . '/config/plugins/' . $plugindir;
    $backup_file = $lbhomedir . '/config/plugins/' . $plugindir . '.backup.json';
    $log_file = $lbhomedir . '/log/plugins/' . $plugindir . '/abfahrt.log';
} else {
    $config_dir = dirname(dirname(__DIR__)) . '/config';
    $backup_file = $config_dir . '/abfahrt.backup.json';
    $log_file = sys_get_temp_dir() . '/abfahrtsassistent/abfahrt.log';
}
$config_file = $config_dir . '/abfahrt.json';

// Bibliothek einbinden (liefert u. a. die Sperrzeiten-Schluessel und die
// Sondertags-Erkennung). Falls sie einmal fehlt, greifen die Ersatzdefinitionen
// weiter unten, damit die Oberflaeche in jedem Fall bedienbar bleibt.
foreach ([
    dirname(dirname(dirname(__DIR__))) . '/html/plugins/' . $plugindir . '/abfahrt_lib.php',
    dirname(__DIR__) . '/html/abfahrt_lib.php',
] as $abf_cand) {
    if (is_file($abf_cand)) { require_once $abf_cand; break; }
}
if (!function_exists('abfahrt_quiet_keys')) {
    function abfahrt_quiet_keys() { return [1, 2, 3, 4, 5, 6, 7, 8, 9, 10]; }
}
if (!function_exists('abfahrt_quiet_labels')) {
    function abfahrt_quiet_labels() {
        return [1 => 'Montag', 2 => 'Dienstag', 3 => 'Mittwoch', 4 => 'Donnerstag', 5 => 'Freitag',
                6 => 'Samstag', 7 => 'Sonntag', 8 => 'Feiertag', 9 => 'Ferien', 10 => 'Urlaub'];
    }
}

// Falls Konfiguration fehlt/leer, aber Sicherung existiert: wiederherstellen
if ((!is_file($config_file) || trim((string) @file_get_contents($config_file)) === '' || trim((string) @file_get_contents($config_file)) === '{}') && is_file($backup_file)) {
    @mkdir($config_dir, 0775, true);
    @copy($backup_file, $config_file);
}

$saved = false;
$save_error = '';
$refreshed_msg = '';
$active_tab = preg_match('/^tab-(settings|loxone|test|log)$/', (string) ($_POST['activetab'] ?? '')) ? $_POST['activetab'] : 'tab-settings';

// ---------- Log leeren ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['clearlog'])) {
    @file_put_contents($log_file, '[' . date('Y-m-d H:i:s') . "] Log geleert (Admin-Oberflaeche)\n");
    $active_tab = 'tab-log';
}

// ---------- Speichern (auch bei "Kalender neu einlesen") ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (isset($_POST['save']) || isset($_POST['refresh'])) && !isset($_POST['clearlog'])) {
    $abfcfg = [];
    $abfcfg['calendars'] = [];
    for ($i = 0; $i < 10; $i++) {
        $name = trim((string) ($_POST['cal_name'][$i] ?? ''));
        $url = trim((string) ($_POST['cal_url'][$i] ?? ''));
        if ($url !== '' && !preg_match('#^https?://#i', $url)) {
            $save_error = 'Kalender ' . ($i + 1) . ': URL muss mit http(s):// beginnen.';
            break;
        }
        $abfcfg['calendars'][] = ['name' => $name, 'url' => $url]; // leerer Name bleibt leer (Platzhalter)
    }
    $prov = (string) ($_POST['provider'] ?? 'tomtom');
    $abfcfg['provider'] = in_array($prov, ['tomtom', 'google', 'here'], true) ? $prov : 'tomtom';
    $abfcfg['api_key'] = trim((string) ($_POST['api_key'] ?? ''));
    $abfcfg['home_address'] = trim((string) ($_POST['home_address'] ?? ''));
    $abfcfg['buffer_min'] = max(0, min(120, (int) ($_POST['buffer_min'] ?? 10)));
    $abfcfg['arrival_min'] = max(0, min(120, (int) ($_POST['arrival_min'] ?? 10)));
    $abfcfg['lookahead_hours'] = max(1, min(48, (int) ($_POST['lookahead_hours'] ?? 15)));
    $abfcfg['ignore_locations'] = trim((string) ($_POST['ignore_locations'] ?? 'online, teams, zoom, webex, google meet, skype, videokonferenz, telefontermin'));
    $mode = (string) ($_POST['tts_mode'] ?? 'musicserver');
    $abfcfg['tts'] = [
        'mode' => in_array($mode, ['musicserver', 'ms4h', 'audioserver', 'custom'], true) ? $mode : 'musicserver',
        'ip' => trim((string) ($_POST['tts_ip'] ?? '')),
        'port' => max(1, min(65535, (int) ($_POST['tts_port'] ?? 7091))),
        'zones' => trim((string) ($_POST['tts_zones'] ?? '1')),
        'volume' => max(1, min(100, (int) ($_POST['tts_volume'] ?? 8))),
        'lang' => preg_match('/^[a-z]{2}$/', (string) ($_POST['tts_lang'] ?? '')) ? $_POST['tts_lang'] : 'de',
        'template' => trim((string) ($_POST['tts_template'] ?? '')),
    ];
    $abfcfg['notify'] = [
        'audio' => isset($_POST['notify_audio']) ? 1 : 0,
        'push' => isset($_POST['notify_push']) ? 1 : 0,
    ];
    $abfcfg['quiet'] = [];
    foreach (abfahrt_quiet_keys() as $d) {
        $t = function ($v) { return preg_match('/^([01]?\d|2[0-3]):[0-5]\d$/', (string) $v) ? $v : ''; };
        $abfcfg['quiet'][$d] = [
            'on' => isset($_POST['quiet_on'][$d]) ? 1 : 0,
            'from' => $t($_POST['quiet_from'][$d] ?? '') ?: '20:00',
            'to' => $t($_POST['quiet_to'][$d] ?? '') ?: ($d >= 8 ? '09:00' : '07:00'),
        ];
    }
    if ($save_error === '') {
        if (!is_dir($config_dir)) {
            @mkdir($config_dir, 0775, true);
        }
        $json = json_encode($abfcfg, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (@file_put_contents($config_file, $json) !== false) {
            $saved = true;
            @copy($config_file, $backup_file); // Sicherung ausserhalb des Plugin-Ordners (uebersteht Updates & Neuinstallation)
        } else {
            $save_error = 'Konfiguration konnte nicht gespeichert werden: ' . $config_file;
        }
    }
    // ---------- Kalender neu einlesen ----------
    if ($save_error === '' && isset($_POST['refresh'])) {
        $ri = (int) $_POST['refresh'];
        $rurl = trim((string) ($abfcfg['calendars'][$ri]['url'] ?? ''));
        $rname = trim((string) ($abfcfg['calendars'][$ri]['name'] ?? '')) ?: ('Kalender ' . ($ri + 1));
        if ($rurl !== '') {
            @unlink('/tmp/abfahrtsassistent/ics_' . md5($rurl));
            // Frische Berechnung anstossen (aktualisiert auch die Status-Zeile oben)
            $ctx = stream_context_create(['http' => ['timeout' => 25]]);
            @file_get_contents('http://127.0.0.1/plugins/' . $plugindir . '/termin.php', false, $ctx);
            $refreshed_msg = "Kalender \"$rname\" neu eingelesen.";
        } else {
            $refreshed_msg = "Kalender " . ($ri + 1) . ": keine URL eingetragen.";
        }
    }
}

// ---------- Laden ----------
$abfcfg = is_file($config_file) ? (json_decode((string) file_get_contents($config_file), true) ?: []) : [];
$abfcfg += ['calendars' => [], 'provider' => 'tomtom', 'api_key' => '', 'home_address' => '', 'buffer_min' => 10, 'arrival_min' => 10, 'lookahead_hours' => 15, 'ignore_locations' => 'online, teams, zoom, webex, google meet, skype, videokonferenz, telefontermin', 'tts' => []];
$abfcfg['tts'] += ['mode' => 'musicserver', 'ip' => '', 'port' => 7091, 'zones' => '1', 'volume' => 8, 'lang' => 'de', 'template' => ''];
$abfcfg += ['notify' => [], 'quiet' => []];
$abfcfg['notify'] += ['audio' => 1, 'push' => 1];
foreach (abfahrt_quiet_keys() as $d) {
    if (!isset($abfcfg['quiet'][$d]) || !is_array($abfcfg['quiet'][$d])) { $abfcfg['quiet'][$d] = []; }
    $abfcfg['quiet'][$d] += ['on' => 0, 'from' => '20:00', 'to' => $d >= 8 ? '09:00' : '07:00'];
}
while (count($abfcfg['calendars']) < 10) {
    $abfcfg['calendars'][] = ['name' => '', 'url' => ''];
}

$status = @json_decode((string) @file_get_contents('/tmp/abfahrtsassistent/titel.json'), true) ?: [];
$log_lines = [];
if (is_file($log_file)) {
    $log_lines = array_slice(array_reverse(file($log_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: []), 0, 300);
}

function e($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); }

$use_frame = class_exists('LBWeb', false);
if ($use_frame) {
    LBWeb::lbheader('Abfahrts-Assistent', 'https://wiki.loxberry.de/', '');
}
$host = e($_SERVER['HTTP_HOST'] ?? '<loxberry-ip>');
?>
<style>
.abf-wrap { max-width: 940px; margin: 0 auto; font-family: -apple-system, 'Segoe UI', Roboto, sans-serif; color: #333; }
.abf-wrap h2 { color: #6dac20; margin: 24px 0 10px; font-size: 1.15em; border-bottom: 2px solid #e0e0e0; padding-bottom: 6px; }
.abf-wrap h3 { color: #6dac20; margin: 18px 0 6px; font-size: 1.0em; }
.abf-wrap label { display: block; font-weight: 600; font-size: 0.88em; color: #555; margin: 10px 0 4px; }
.abf-wrap input[type=text], .abf-wrap input[type=password], .abf-wrap input[type=number], .abf-wrap select, .abf-wrap textarea {
  width: 100%; padding: 8px 10px; border: 1px solid #ccc; border-radius: 6px; font-size: 0.95em; box-sizing: border-box; }
.abf-wrap input[type=time] { width: 92px !important; min-width: 92px; padding: 4px 6px !important; border: 1px solid #ccc; border-radius: 6px; font-size: 0.95em; display: inline-block; box-sizing: border-box; }
.abf-wrap input[type=checkbox] { width: 17px; height: 17px; margin: 0; vertical-align: middle; }
.abf-row { display: flex; gap: 12px; }
.abf-row > div { flex: 1; }
.abf-cal { display: flex; gap: 8px; margin-bottom: 6px; align-items: center; }
.abf-cal input:first-child { flex: 0 0 170px; }
.abf-cal .abf-rfbtn { flex: 0 0 auto; background: #607d8b; color: #fff; border: 0; border-radius: 6px; padding: 7px 10px; font-size: 0.82em; cursor: pointer; white-space: nowrap; }
.abf-btn { background: #6dac20; color: #fff; border: 0; border-radius: 6px; padding: 10px 22px; font-size: 1em; cursor: pointer; margin-top: 18px; }
.abf-alert { border-radius: 8px; padding: 10px 14px; margin: 12px 0; }
.abf-ok { background: #e8f5e9; border: 1px solid #a5d6a7; }
.abf-err { background: #ffebee; border: 1px solid #ef9a9a; }
.abf-info { background: #e3f2fd; border: 1px solid #90caf9; font-size: 0.9em; }
.abf-mono { font-family: ui-monospace, monospace; background: #f5f5f5; padding: 2px 6px; border-radius: 4px; }
.abf-small { font-size: 0.82em; color: #666; margin-top: 3px; }
.abf-qt { border-collapse: collapse; }
.abf-qt td { vertical-align: middle; padding: 3px 0; }
.abf-tabs { display: flex; gap: 4px; margin: 14px 0 0; border-bottom: 2px solid #6dac20; flex-wrap: wrap; }
.abf-tab { background: #eee; border: 1px solid #ccc; border-bottom: 0; border-radius: 8px 8px 0 0; padding: 9px 18px; cursor: pointer; font-size: 0.95em; color: #444; }
.abf-tab.abf-active { background: #6dac20; color: #fff; border-color: #6dac20; font-weight: 600; }
.abf-pane { display: none; padding-top: 4px; }
.abf-pane.abf-active { display: block; }
.abf-log { text-shadow: none !important; background: #1e1e1e; color: #d4d4d4; font-family: ui-monospace, monospace; font-size: 0.82em; padding: 12px; border-radius: 8px; max-height: 480px; overflow: auto; white-space: pre-wrap; }
.abf-step { margin: 10px 0; padding: 10px 14px; background: #fafafa; border-left: 4px solid #6dac20; border-radius: 0 8px 8px 0; }
.abf-tbl { border-collapse: collapse; margin: 8px 0; }
.abf-tbl th, .abf-tbl td { border: 1px solid #ddd; padding: 6px 10px; text-align: left; font-size: 0.9em; }
.abf-tbl th { background: #f0f0f0; }
/* LoxBerry/jQuery-Mobile-Styles neutralisieren */
.abf-wrap .abf-tab, .abf-wrap .abf-btn, .abf-wrap .abf-rfbtn, .abf-wrap a.abf-btn, .abf-wrap button {
  text-shadow: none !important; box-shadow: none !important; }
.abf-wrap .abf-btn, .abf-wrap a.abf-btn { color: #fff !important; font-weight: 600; }
.abf-wrap .abf-rfbtn { color: #fff !important; width: auto !important; }
.abf-wrap .abf-tab { color: #444 !important; }
.abf-wrap .abf-tab.abf-active { color: #fff !important; }
.abf-cal input:first-child { flex: 0 0 170px !important; width: 170px !important; }
.abf-cal input:nth-child(2) { flex: 1 1 auto !important; width: auto !important; min-width: 200px; }
.abf-cal .abf-rfbtn { flex: 0 0 auto !important; }
.abf-wrap a.abf-btn:visited, .abf-wrap a.abf-btn:hover, .abf-wrap a.abf-btn:active { color: #fff !important; }
</style>
<div class="abf-wrap">

<?php if ($saved) { ?><div class="abf-alert abf-ok"><b>Konfiguration gespeichert</b> (inkl. Sicherungskopie f&uuml;r Updates).</div><?php } ?>
<?php if ($refreshed_msg !== '') { ?><div class="abf-alert abf-ok"><b><?= e($refreshed_msg) ?></b> Ergebnis siehe Status-Zeile unten bzw. Reiter &bdquo;Test&ldquo;.</div><?php } ?>
<?php if ($save_error !== '') { ?><div class="abf-alert abf-err"><b>Fehler:</b> <?= e($save_error) ?></div><?php } ?>

<?php if (!empty($status['titel'])) { ?>
<div class="abf-alert abf-info"><b>Letzter berechneter Termin:</b> <?= e($status['titel']) ?> (<?= e($status['kalender'] ?? '') ?>), <?= e($status['beginn'] ?? '') ?>,
Ort: <?= e($status['ort'] ?? '') ?> &mdash; Fahrzeit <?= isset($status['fahrt']) ? (int) ceil((float) $status['fahrt']) : '?' ?> Minuten, Abfahrt in <?= isset($status['abfahrt_in']) ? (int) $status['abfahrt_in'] : '?' ?> Minuten</div>
<?php } ?>

<div class="abf-tabs">
    <div class="abf-tab" data-pane="tab-settings">Einstellungen</div>
    <div class="abf-tab" data-pane="tab-loxone">Einbindung in Loxone</div>
    <div class="abf-tab" data-pane="tab-test">Test</div>
    <div class="abf-tab" data-pane="tab-log">Logdateien</div>
</div>

<!-- ================= Reiter: Einstellungen ================= -->
<div class="abf-pane" id="tab-settings">
<form method="post" autocomplete="off">
<input data-role="none" type="hidden" name="save" value="1">
<input data-role="none" type="hidden" name="activetab" value="tab-settings">

<h2>Kalender (bis zu 10 iCal-URLs)</h2>
<p class="abf-small">Private iCal-Adresse, z.&nbsp;B. Google Kalender &rarr; Einstellungen &rarr; [Kalender] &rarr; &bdquo;Privatadresse im iCal-Format&ldquo;. Ber&uuml;cksichtigt werden Termine <b>mit Ortsangabe</b> im eingestellten Zeitfenster (auch Serientermine). Kalender werden 10 Minuten zwischengespeichert &mdash; &bdquo;Neu einlesen&ldquo; holt sie sofort frisch.</p>
<?php for ($i = 0; $i < 10; $i++) { $cal = $abfcfg['calendars'][$i]; ?>
<div class="abf-cal">
    <input data-role="none" type="text" name="cal_name[]" value="<?= e($cal['name']) ?>" placeholder="Name (z. B. Familie)">
    <input data-role="none" type="text" name="cal_url[]" value="<?= e($cal['url']) ?>" placeholder="https://calendar.google.com/calendar/ical/.../basic.ics">
    <button data-role="none" class="abf-rfbtn" type="submit" name="refresh" value="<?= $i ?>" formnovalidate title="Cache leeren und diesen Kalender sofort neu laden">Neu einlesen</button>
</div>
<?php } ?>

<h2>Kartendienst / Verkehrslage</h2>
<div class="abf-row">
    <div>
        <label>Dienst</label>
        <select data-role="none" name="provider" id="provider">
            <option value="tomtom"<?= $abfcfg['provider'] === 'tomtom' ? ' selected' : '' ?>>TomTom (kostenlos, 2500 Abfragen/Tag)</option>
            <option value="google"<?= $abfcfg['provider'] === 'google' ? ' selected' : '' ?>>Google Maps (Directions API)</option>
            <option value="here"<?= $abfcfg['provider'] === 'here' ? ' selected' : '' ?>>HERE (Routing v8)</option>
        </select>
        <div class="abf-small">API-Key: <a href="https://developer.tomtom.com" target="_blank">developer.tomtom.com</a> | <a href="https://console.cloud.google.com" target="_blank">Google Cloud Console</a> | <a href="https://platform.here.com" target="_blank">platform.here.com</a></div>
    </div>
    <div>
        <label>API-Key</label>
        <input data-role="none" type="password" name="api_key" value="<?= e($abfcfg['api_key']) ?>" placeholder="API-Key des gew&auml;hlten Dienstes">
    </div>
</div>

<label>Abfahrtsadresse (Zuhause)</label>
<input data-role="none" type="text" name="home_address" value="<?= e($abfcfg['home_address']) ?>" placeholder="Stra&szlig;e Hausnummer, PLZ Ort">

<label>Ignorierte Orte (Online-Termine)</label>
<input data-role="none" type="text" name="ignore_locations" value="<?= e($abfcfg['ignore_locations']) ?>" placeholder="online, teams, zoom, ...">
<div class="abf-small">Termine, deren Ortsangabe eines dieser W&ouml;rter enth&auml;lt (Komma-Liste, Gro&szlig;-/Kleinschreibung egal),
werden wie Termine <b>ohne</b> Ort behandelt &mdash; keine Fahrzeitberechnung, keine Abfahrts-Ansage
(z.&nbsp;B. Videokonferenzen mit Ort &bdquo;ONLINE&ldquo;). Meeting-Links (http/https) werden immer ignoriert.</div>

<h2>Zeiten</h2>
<div class="abf-row">
    <div>
        <label>Ankunft vor Termin (Minuten)</label>
        <input data-role="none" type="number" name="arrival_min" value="<?= (int) $abfcfg['arrival_min'] ?>" min="0" max="120">
        <div class="abf-small">So viele Minuten vor Terminbeginn m&ouml;chten Sie ankommen.</div>
    </div>
    <div>
        <label>Pufferzeit (Minuten)</label>
        <input data-role="none" type="number" name="buffer_min" value="<?= (int) $abfcfg['buffer_min'] ?>" min="0" max="120">
        <div class="abf-small">Zus&auml;tzlicher Puffer f&uuml;r Anziehen, Auto holen usw.</div>
    </div>
    <div>
        <label>Zeitfenster (Stunden)</label>
        <input data-role="none" type="number" name="lookahead_hours" value="<?= (int) $abfcfg['lookahead_hours'] ?>" min="1" max="48">
        <div class="abf-small">Wie weit vorausgeschaut wird (Standard 15&nbsp;h).</div>
    </div>
</div>

<h2>Sprachausgabe</h2>
<div class="abf-row">
    <div>
        <label>Audio-Ausgabe</label>
        <select data-role="none" name="tts_mode" id="tts_mode" onchange="abfTtsMode()">
            <option value="musicserver"<?= $abfcfg['tts']['mode'] === 'musicserver' ? ' selected' : '' ?>>Loxone Music Server (klassisch)</option>
            <option value="ms4h"<?= $abfcfg['tts']['mode'] === 'ms4h' ? ' selected' : '' ?>>Audioserver4Home / MusicServer4Home</option>
            <option value="audioserver"<?= $abfcfg['tts']['mode'] === 'audioserver' ? ' selected' : '' ?>>Original Loxone Audioserver (via Loxone Config)</option>
            <option value="custom"<?= $abfcfg['tts']['mode'] === 'custom' ? ' selected' : '' ?>>Eigene URL-Vorlage</option>
        </select>
    </div>
    <div>
        <label>IP des Audio-Servers</label>
        <input data-role="none" type="text" name="tts_ip" value="<?= e($abfcfg['tts']['ip']) ?>" placeholder="z. B. 192.168.1.50">
    </div>
    <div>
        <label>Port</label>
        <input data-role="none" type="number" name="tts_port" value="<?= (int) $abfcfg['tts']['port'] ?>" min="1" max="65535">
    </div>
</div>
<div class="abf-row">
    <div>
        <label>Zonen</label>
        <input data-role="none" type="text" name="tts_zones" value="<?= e($abfcfg['tts']['zones']) ?>" placeholder="z. B. 2,4,6">
        <div class="abf-small">Zonennummern mit Komma (z.&nbsp;B. <span class="abf-mono">2,4,6</span>) &mdash; die Lautst&auml;rke kommt aus dem Feld daneben. Optional je Zone eigene Lautst&auml;rke: <span class="abf-mono">Zone~Lautst&auml;rke</span> (z.&nbsp;B. <span class="abf-mono">2~25,4~40</span>). Leerzeichen nach dem Komma sind erlaubt &mdash; <span class="abf-mono">2,4,6</span> und <span class="abf-mono">2, 4, 6</span> funktionieren beide.</div>
    </div>
    <div>
        <label>Lautst&auml;rke (%)</label>
        <input data-role="none" type="number" name="tts_volume" value="<?= (int) $abfcfg['tts']['volume'] ?>" min="1" max="100">
    </div>
    <div>
        <label>Sprache</label>
        <input data-role="none" type="text" name="tts_lang" value="<?= e($abfcfg['tts']['lang']) ?>" maxlength="2">
    </div>
</div>
<div id="tts_template_row">
    <label>URL-Vorlage (f&uuml;r Audioserver4Home/MS4H bzw. eigene Ausgabe)</label>
    <textarea data-role="none" name="tts_template" id="tts_template" rows="2" placeholder="http://{ip}:{port}/tts?text={text}&amp;zone={zones}&amp;vol={vol}"><?= e($abfcfg['tts']['template']) ?></textarea>
    <div class="abf-small">Platzhalter: <span class="abf-mono">{ip} {port} {zones} {vol} {lang} {text}</span>. Leer = Standard-Vorlage.</div>
</div>
<div id="tts_audioserver_hint" class="abf-alert abf-info" style="display:none;">
    Der originale Loxone Audioserver bietet <b>keine HTTP-TTS-Schnittstelle</b>. In diesem Modus liefert
    <span class="abf-mono">termin_say.php</span> nur den Ansagetext (<span class="abf-mono">TEXT=...</span>).
    Sprachausgabe dann in Loxone Config: Textgenerator &rarr; TTS-Eingang des Audioplayers.
</div>

<h2>Benachrichtigungen</h2>
<div style="margin-bottom:10px;">
    <label style="display:inline-flex;align-items:center;gap:6px;margin-right:24px;">
        <input data-role="none" type="checkbox" name="notify_audio" <?= !empty($abfcfg['notify']['audio']) ? 'checked' : '' ?>> Audioausgabe aktiv
    </label>
    <label style="display:inline-flex;align-items:center;gap:6px;">
        <input data-role="none" type="checkbox" name="notify_push" <?= !empty($abfcfg['notify']['push']) ? 'checked' : '' ?>> Push-Nachricht aktiv
    </label>
    <div class="abf-small">Beides an = Ansage + Push. Nur eines an = nur diese Ausgabe. Beides aus = keine Meldung.
    Die Freigaben werden als <span class="abf-mono">AUDIO=</span>/<span class="abf-mono">PUSH=</span> an Loxone &uuml;bergeben (den Push verschickt der Miniserver).</div>
</div>

<h2>Sperrzeiten f&uuml;r die Audioausgabe</h2>
<div class="abf-small" style="margin-bottom:6px;">In der Sperrzeit bleibt der Lautsprecher stumm (damit fr&uuml;he
Abfahrtszeiten niemanden wecken) &mdash; die Push-Nachricht kommt trotzdem, wenn oben aktiviert.
Zeitr&auml;ume &uuml;ber Mitternacht (z.&nbsp;B. 20:00&ndash;07:00) sind erlaubt.</div>
<table class="abf-qt">
<?php $days = abfahrt_quiet_labels();
foreach ($days as $d => $dayname) { ?>
<tr<?= $d === 8 ? ' style="height:34px;vertical-align:bottom;"' : '' ?>>
    <td style="width:28px;"><input data-role="none" type="checkbox" name="quiet_on[<?= $d ?>]" <?= !empty($abfcfg['quiet'][$d]['on']) ? 'checked' : '' ?>></td>
    <td style="width:105px;"><?= $d >= 8 ? '<b>' . $dayname . '</b>' : $dayname ?></td>
    <td style="width:100px;">Sperrzeit von</td>
    <td style="width:100px;"><input data-role="none" type="time" name="quiet_from[<?= $d ?>]" value="<?= e($abfcfg['quiet'][$d]['from']) ?>"></td>
    <td style="width:34px;text-align:center;">bis</td>
    <td style="width:100px;"><input data-role="none" type="time" name="quiet_to[<?= $d ?>]" value="<?= e($abfcfg['quiet'][$d]['to']) ?>"></td>
    <td>Uhr</td>
</tr>
<?php } ?>
</table>
<?php $abf_tag = function_exists('abfahrt_daytype') ? abfahrt_daytype() : ['quelle' => 'keine'];
$abf_regel = function_exists('abfahrt_quiet_rule') ? abfahrt_quiet_rule($abfcfg) : [0, '']; ?>
<div class="abf-small" style="margin-top:6px;">
<b>Feiertag, Ferien und Urlaub gehen dem Wochentag vor</b> &mdash; damit l&auml;sst sich die Ansage an freien Tagen
sp&auml;ter freigeben (z.&nbsp;B. Wochentags bis 07:00 stumm, an Feiertagen bis 09:00). Reihenfolge der Pr&uuml;fung:
<b>Urlaub &rarr; Feiertag &rarr; Ferien &rarr; Wochentag</b>. Ein Sondertag greift nur, wenn sein H&auml;kchen gesetzt ist;
sonst gilt weiterhin der normale Wochentag.<br>
Woher die Sondertage kommen: aus dem LoxBerry-Plugin <b>&bdquo;Ferien und Feiertage&ldquo;</b> (dort auch der Urlaub als
eigener Termin der Art &bdquo;Urlaub (abwesend)&ldquo;). Ist das Plugin nicht installiert, bleiben die drei Zeilen
wirkungslos und es gilt allein die Wochentagstabelle.<br>
Status jetzt: <?php if ($abf_tag['quelle'] === 'keine') {
    echo 'Ferien-Plugin nicht erreichbar &mdash; nur Wochentagslogik aktiv.';
} else {
    $liste = [];
    if (!empty($abf_tag['urlaub'])) { $liste[] = 'Urlaub'; }
    if (!empty($abf_tag['feiertag'])) { $liste[] = 'Feiertag'; }
    if (!empty($abf_tag['ferien'])) { $liste[] = 'Ferien'; }
    echo 'heute ' . ($liste ? '<b>' . implode(' + ', $liste) . '</b>' . (($abf_tag['name'] ?? '') !== '' ? ' (' . e($abf_tag['name']) . ')' : '') : 'normaler Tag')
       . ' &mdash; es gilt die Zeile <b>' . ($abf_regel[0] ? e($abf_regel[1]) : 'keine (Ansage jederzeit erlaubt)') . '</b>.';
} ?>
</div>

<button data-role="none" class="abf-btn" type="submit">Speichern</button>
</form>
</div>

<!-- ================= Reiter: Einbindung in Loxone ================= -->
<div class="abf-pane" id="tab-loxone">
<h2>Einbindung in Loxone &mdash; Schritt f&uuml;r Schritt</h2>
<p>Das Plugin berechnet auf dem LoxBerry alles Wichtige und stellt es als einfache Textzeile bereit.
Der Miniserver holt sich diese Zeile regelm&auml;&szlig;ig ab (Schritt&nbsp;1), wertet die Zahlen aus (Schritt&nbsp;2)
und st&ouml;&szlig;t bei Erreichen der Abfahrtszeit die Ansage an (Schritt&nbsp;3).</p>

<div class="abf-step"><b>Schritt 1: Virtuellen HTTP-Eingang anlegen</b><br><br>
In <b>Loxone Config</b>: Miniserver in der Baumansicht anklicken &rarr; Men&uuml; <i>Virtuelle Eing&auml;nge</i> &rarr;
<i>Virtueller HTTP-Eingang</i> anlegen. Dann rechts in den Eigenschaften:
<table class="abf-tbl">
<tr><th>Eigenschaft</th><th>Wert</th></tr>
<tr><td>URL</td><td><span class="abf-mono">http://<?= $host ?>/plugins/<?= e($plugindir) ?>/termin.php</span></td></tr>
<tr><td>Abfragezyklus</td><td>300 Sekunden (= alle 5 Minuten)</td></tr>
</table>
Der Miniserver ruft diese Adresse dann selbstst&auml;ndig auf. Die Antwort sieht z.&nbsp;B. so aus:<br>
<span class="abf-mono">TERMIN;OK=1;MINSTART=55;FAHRT=43.7;ABFAHRT_IN=-4;AUDIO=1;PUSH=1</span>
</div>

<div class="abf-step"><b>Schritt 2: Befehle (Werte) im HTTP-Eingang anlegen</b><br><br>
Unter dem HTTP-Eingang legt man per Rechtsklick <i>Virtuellen HTTP-Eingang Befehl</i> an &mdash; einen je Wert.
Entscheidend ist die <b>Befehlserkennung</b>: Sie sagt Loxone, <i>wo</i> in der Antwortzeile die Zahl steht.<br><br>
<b>So liest man das Muster:</b> <span class="abf-mono">\iABFAHRT_IN=\i\v</span> hei&szlig;t:
&bdquo;Suche den Text zwischen den beiden <span class="abf-mono">\i</span> (hier: <span class="abf-mono">ABFAHRT_IN=</span>)
und &uuml;bernimm die Zahl, die direkt dahinter steht (<span class="abf-mono">\v</span> = Wert).&ldquo;
<table class="abf-tbl">
<tr><th>Befehlserkennung</th><th>Bedeutung</th><th>Beispielwert</th></tr>
<tr><td><span class="abf-mono">\iABFAHRT_IN=\i\v</span></td><td>Minuten bis zur <b>empfohlenen Abfahrt</b> (= Terminbeginn &minus; Fahrzeit &minus; Ankunftsreserve &minus; Puffer). Negativ = man ist schon zu sp&auml;t.</td><td>12</td></tr>
<tr><td><span class="abf-mono">\iMINSTART=\i\v</span></td><td>Minuten bis zum <b>Terminbeginn</b>.</td><td>55</td></tr>
<tr><td><span class="abf-mono">\iFAHRT=\i\v</span></td><td>Aktuelle <b>Fahrzeit</b> in Minuten, inklusive Stau/Verkehrslage.</td><td>43.7</td></tr>
<tr><td><span class="abf-mono">\iOK=\i\v</span></td><td><b>1</b> = Termin gefunden und Route berechnet, alles g&uuml;ltig. <b>0</b> = kein Termin mit Ort im Zeitfenster (dann steht MINSTART auf 9999) &mdash; damit verhindert man Fehlalarme.</td><td>1</td></tr>
<tr><td><span class="abf-mono">\iAUDIO=\i\v</span></td><td><b>1</b> = Ansage erlaubt (Checkbox an und keine Sperrzeit aktiv), <b>0</b> = Lautsprecher soll stumm bleiben.</td><td>1</td></tr>
<tr><td><span class="abf-mono">\iPUSH=\i\v</span></td><td><b>1</b> = Push-Nachricht erlaubt (Checkbox), <b>0</b> = kein Push.</td><td>1</td></tr>
</table>
</div>

<div class="abf-step"><b>Schritt 3: Ausl&ouml;sen von Ansage und Push</b><br><br>
<b>a) Virtuellen Ausgang anlegen</b> (Miniserver &rarr; <i>Virtuelle Ausg&auml;nge</i>):
<table class="abf-tbl">
<tr><th>Eigenschaft</th><th>Wert</th></tr>
<tr><td>Adresse (Virtueller Ausgang)</td><td><span class="abf-mono">http://<?= $host ?></span></td></tr>
<tr><td>Befehl bei EIN (Ausgang-Befehl)</td><td><span class="abf-mono">/plugins/<?= e($plugindir) ?>/termin_say.php</span></td></tr>
</table>
<b>b) Logik auf der Programmierseite:</b> Man vergleicht <span class="abf-mono">ABFAHRT_IN</span> mit der eigenen
Vorwarnzeit (z.&nbsp;B. 5 Minuten): F&auml;llt der Wert auf/unter die Schwelle (Schwellwertschalter-Baustein) UND ist
<span class="abf-mono">OK=1</span>, dann:<br>
&bull; bei <span class="abf-mono">AUDIO=1</span> den virtuellen Ausgang schalten &rarr; das Plugin spricht die Ansage
mit Terminnamen und Fahrzeit &uuml;ber die Lautsprecher;<br>
&bull; bei <span class="abf-mono">PUSH=1</span> zus&auml;tzlich einen Benachrichtigungs-Baustein (Push an die Loxone-App) ausl&ouml;sen.<br><br>
<i>Hinweis:</i> Die Ansage selbst macht das Plugin (inklusive Sperrzeiten-Pr&uuml;fung als doppeltes Netz) &mdash;
die Push-Nachricht verschickt der Miniserver, deshalb wird sie &uuml;ber das <span class="abf-mono">PUSH</span>-Flag gesteuert.<br><br>
<b>Wichtige Stolperfallen beim Benachrichtigungs-Baustein (aus der Praxis):</b><br>
&bull; Der Baustein sendet nur bei einem <b>Wechsel von Aus auf Ein</b> an seinem Eingang. Steht der Ausl&ouml;ser
bereits auf Ein (z.&nbsp;B. weil der Miniserver mitten in eine f&auml;llige Abfahrt hinein neu gestartet ist oder ein
Testtermin schon &uuml;berf&auml;llig war), passiert nichts &mdash; daf&uuml;r gibt es die Neustart-Nachholung (siehe unten).<br>
&bull; <b>Niemals mehrere Quellen direkt auf denselben Benachrichtigungs-Eingang legen.</b> Liegt eine Quelle dauerhaft
auf Ein, gehen die Impulse der anderen unter (1&rarr;1 ist kein Wechsel). Stattdessen die Quellen zuerst in einem
ODER-Baustein b&uuml;ndeln und nur dessen Ausgang anschlie&szlig;en.<br>
&bull; F&uuml;r Tests empfiehlt sich ein eigener Taster mit einem <b>eigenen</b> Benachrichtigungs-Baustein
(&bdquo;TEST erfolgreich&hellip;&ldquo;) &mdash; der liefert immer eine saubere Flanke, unabh&auml;ngig vom Terminstatus.
</div>

<div class="abf-step"><b>Schritt 4 (optional): Statusbaustein f&uuml;r die Anzeige in der App</b><br><br>
Damit man in der Loxone-App jederzeit sieht, wann man losfahren muss, legt man auf der Programmierseite einen
<b>Status-Baustein</b> an (Baustein &bdquo;Status&ldquo;):
<table class="abf-tbl">
<tr><th>Eingang</th><th>Verbinden mit</th></tr>
<tr><td>Eingang 1 (v1)</td><td>HTTP-Eingangs-Befehl <span class="abf-mono">ABFAHRT_IN</span></td></tr>
<tr><td>Eingang 2 (v2)</td><td>HTTP-Eingangs-Befehl <span class="abf-mono">FAHRT</span></td></tr>
<tr><td>Eingang 3 (v3)</td><td>HTTP-Eingangs-Befehl <span class="abf-mono">OK</span></td></tr>
</table>
Dann im Baustein zwei Statustexte anlegen (Doppelklick auf den Baustein):<br>
&bull; Bedingung: <i>Eingang&nbsp;3 gleich 1</i> &rarr; Text: <span class="abf-mono">N&auml;chster Termin: Abfahrt in &lt;v1.0&gt; Min (Fahrzeit &lt;v2.0&gt; Min)</span><br>
&bull; Standardtext (keine Bedingung): <span class="abf-mono">Kein Termin mit Ortsangabe im Zeitfenster</span><br>
Den Baustein in der Visualisierung aktivieren (H&auml;kchen &bdquo;Visualisierung&ldquo;) &mdash; fertig ist die App-Kachel.
</div>

<div class="abf-step"><b>Schritt 5: Muss ich Music Server / Audioserver in Loxone verkn&uuml;pfen?</b><br><br>
<b>Loxone Music Server (klassisch) und Audioserver4Home/MusicServer4Home: NEIN.</b>
Die Ansage verschickt das <i>Plugin</i> direkt per HTTP an den Audio-Server (IP/Port/Zonen aus dem Reiter
&bdquo;Einstellungen&ldquo;). In Loxone Config ist daf&uuml;r <b>keine</b> Verbindung zu einem Musik-/Audio-Baustein
n&ouml;tig &mdash; es gen&uuml;gt der virtuelle Ausgang aus Schritt&nbsp;3, der <span class="abf-mono">termin_say.php</span> aufruft.
Die Musikzonen-Bausteine in Loxone bleiben unber&uuml;hrt; eine laufende Wiedergabe wird vom Music Server nach der
Ansage automatisch fortgesetzt.<br><br>
<b>Original Loxone Audioserver: JA, hier braucht es zwei Bausteine in Loxone Config</b> (der Audioserver hat keine
HTTP-TTS-Schnittstelle, die Ansage muss der Miniserver selbst ausl&ouml;sen):
<table class="abf-tbl">
<tr><th>Baustein</th><th>Verkn&uuml;pfung</th></tr>
<tr><td>Textgenerator</td><td>Erzeugt den Ansagetext (fester Text oder mit eingef&uuml;gten Werten, z.&nbsp;B. Fahrzeit aus dem HTTP-Befehl FAHRT). Ausl&ouml;ser-Eingang = derselbe Impuls wie in Schritt&nbsp;3b (Schwellwert erreicht UND OK=1 UND AUDIO=1).</td></tr>
<tr><td>Audioplayer (der gew&uuml;nschten Zone)</td><td>Ausgang des Textgenerators &rarr; <b>TTS-Eingang</b> des Audioplayer-Bausteins. F&uuml;r mehrere Zonen den Textgenerator-Ausgang mit mehreren Audioplayern verbinden.</td></tr>
</table>
In diesem Modus liefert <span class="abf-mono">termin_say.php</span> nur den Text (<span class="abf-mono">TEXT=...</span>) und spricht nicht selbst.
</div>

<div class="abf-step"><b>Schritt 6: Komplette Baustein-Liste zum 1:1-Nachbauen</b><br><br>
So sieht die vollst&auml;ndige Logik auf der Loxone-Programmierseite aus (jede Zeile = ein Baustein).
Alle Bausteine findet man in Loxone Config &uuml;ber die Baustein-Suche (F5 bzw. Men&uuml; &bdquo;Bausteine&ldquo;):
<table class="abf-tbl">
<tr><th>#</th><th>Baustein (Typ)</th><th>Name (Vorschlag)</th><th>Parameter</th><th>Eing&auml;nge verbinden mit</th></tr>
<tr><td>1</td><td>Virtueller HTTP-Eingang + 6 Befehle</td><td>Abfahrts-Assistent</td><td>URL wie Schritt&nbsp;1, Abfrage 300&nbsp;s</td><td>&mdash; (holt die Daten selbst)</td></tr>
<tr><td>2</td><td>Auswahltasten (+/&minus;) oder fester Wert</td><td>Abfahrt: Vorwarnung (min)</td><td>Standard 5, Schrittweite 1, Min 0, Max 30, Visualisierung EIN</td><td>&mdash; (Bedienung in der App)</td></tr>
<tr><td>3</td><td>Formel</td><td>Minuten bis Warnung</td><td>Formel: <span class="abf-mono">I1-I2</span></td><td>I1 = HTTP-Befehl <span class="abf-mono">ABFAHRT_IN</span>, I2 = AQ der Vorwarnung (#2)</td></tr>
<tr><td>4</td><td>Schwellwertschalter</td><td>Warnzeit erreicht</td><td>Ein-Schwelle <b>0,4</b> / Aus-Schwelle <b>0,6</b> (Ein &lt; Aus = schaltet beim UNTERschreiten ein!)</td><td>Eingang = Ausgang der Formel (#3)</td></tr>
<tr><td>5</td><td>Schwellwertschalter</td><td>Termin in Reichweite</td><td>Ein <b>899</b> / Aus <b>901</b> (Ein bei MINSTART unter 15&nbsp;h)</td><td>Eingang = HTTP-Befehl <span class="abf-mono">MINSTART</span></td></tr>
<tr><td>6</td><td>Schwellwertschalter</td><td>Daten OK</td><td>Ein 0,5 / Aus 0,4</td><td>Eingang = HTTP-Befehl <span class="abf-mono">OK</span></td></tr>
<tr><td>7</td><td>UND</td><td>Termin g&uuml;ltig</td><td>&mdash;</td><td>I1 = #5, I2 = #6</td></tr>
<tr><td>8</td><td>UND</td><td>Abfahrt jetzt melden</td><td>&mdash;</td><td>I1 = #4, I2 = #7</td></tr>
<tr><td>9</td><td>Schwellwertschalter</td><td>Audio erlaubt</td><td>Ein 0,5 / Aus 0,4</td><td>Eingang = HTTP-Befehl <span class="abf-mono">AUDIO</span></td></tr>
<tr><td>10</td><td>Schwellwertschalter</td><td>Push erlaubt</td><td>Ein 0,5 / Aus 0,4</td><td>Eingang = HTTP-Befehl <span class="abf-mono">PUSH</span></td></tr>
<tr><td>11</td><td>UND</td><td>Ansage erlaubt</td><td>&mdash;</td><td>I1 = #8, I2 = #9 &rarr; Ausgang an den <b>virtuellen Ausgang</b> aus Schritt&nbsp;3 (termin_say.php)</td></tr>
<tr><td>12</td><td>UND</td><td>Push erlaubt (Abfahrt)</td><td>&mdash;</td><td>I1 = #8, I2 = #10 &rarr; Ausgang an einen <b>Benachrichtigungs-Baustein</b> (Meldungstext z.&nbsp;B. &bdquo;Zeit zum Losfahren!&ldquo;)</td></tr>
<tr><td>13</td><td>Status (optional)</td><td>Abfahrts-Assistent</td><td>siehe Schritt&nbsp;4</td><td>v1 = ABFAHRT_IN, v2 = FAHRT, v3 = OK</td></tr>
</table>
<br><br>
<b>Optional, empfohlen (Neustart-Nachholung):</b> Damit eine bereits f&auml;llige Abfahrt auch direkt nach einem
Miniserver-Neustart gemeldet wird: Baustein <i>Systemstart-Impuls</i> &rarr; <i>Ausschaltverz&ouml;gerung</i> (300&nbsp;s)
&rarr; je ein <i>UND</i> mit #11 bzw. #12 &rarr; je eine <i>Einschaltverz&ouml;gerung</i> (60&nbsp;s) &rarr; als ZUS&Auml;TZLICHE
Quelle auf den virtuellen Ausgang bzw. den Benachrichtigungs-Baustein. Hintergrund: Ansage/Push feuern nur auf
die steigende Flanke &mdash; bootet der Miniserver mitten in eine f&auml;llige Abfahrt hinein, g&auml;be es ohne
Nachholung keine Flanke.
</div>
</div>

<!-- ================= Reiter: Test ================= -->
<div class="abf-pane" id="tab-test">
<h2>Test</h2>
<p><a class="abf-btn" style="display:inline-block;text-decoration:none;margin-right:10px;" href="/plugins/<?= e($plugindir) ?>/termin.php?debug=1" target="_blank">Termin-Abfrage (Debug)</a>
<a class="abf-btn" style="display:inline-block;text-decoration:none;background:#607d8b;" href="/plugins/<?= e($plugindir) ?>/termin_say.php?force=1" target="_blank">Ansage jetzt ausl&ouml;sen</a></p>
<div class="abf-small">
&bull; <b>Termin-Abfrage (Debug)</b> zeigt, welcher Termin gefunden wurde, Fahrzeit, Abfahrts-Countdown und je Kalender die Trefferzahl.<br>
&bull; <b>Ansage jetzt ausl&ouml;sen</b> spricht sofort &uuml;ber die Lautsprecher (umgeht bewusst Checkbox und Sperrzeiten, Parameter <span class="abf-mono">force=1</span>) &mdash; ideal zum Lautsprecher-Test. Eine Push-Nachricht wird dabei nicht verschickt (die kommt vom Miniserver).<br>
&bull; Neue Termine im Google Kalender erscheinen wegen des 10-Minuten-Caches evtl. verz&ouml;gert &mdash; im Reiter &bdquo;Einstellungen&ldquo; beim jeweiligen Kalender auf <b>Neu einlesen</b> klicken.
</div>
</div>

<!-- ================= Reiter: Logdateien ================= -->
<div class="abf-pane" id="tab-log">
<h2>Logdatei</h2>
<div class="abf-small" style="margin-bottom:8px;">Protokolliert werden Ergebnis-&Auml;nderungen der Termin-Berechnung, jede Ansage (bzw. deren Unterdr&uuml;ckung durch Checkbox/Sperrzeit) und Fehler. Neueste Eintr&auml;ge oben (max. 300 angezeigt).<br>Datei: <span class="abf-mono"><?= e($log_file) ?></span></div>
<?php if ($log_lines) { ?>
<div class="abf-log"><?= e(implode("\n", $log_lines)) ?></div>
<?php } else { ?>
<div class="abf-alert abf-info">Noch keine Log-Eintr&auml;ge vorhanden.</div>
<?php } ?>
<form method="post" style="margin-top:10px;">
    <input data-role="none" type="hidden" name="clearlog" value="1">
    <input data-role="none" type="hidden" name="activetab" value="tab-log">
    <button data-role="none" class="abf-btn" type="submit" style="background:#c62828;">Log leeren</button>
</form>
</div>

</div>
<script>
function abfTtsMode() {
    var m = document.getElementById('tts_mode').value;
    document.getElementById('tts_audioserver_hint').style.display = (m === 'audioserver') ? 'block' : 'none';
    document.getElementById('tts_template_row').style.display = (m === 'ms4h' || m === 'custom') ? 'block' : 'none';
    var port = document.getElementsByName('tts_port')[0];
    if (m === 'musicserver' && (!port.value || port.value === '80')) { port.value = 7091; }
}
abfTtsMode();
(function () {
    var tabs = document.querySelectorAll('.abf-tab');
    function activate(id) {
        tabs.forEach(function (t) { t.classList.toggle('abf-active', t.dataset.pane === id); });
        document.querySelectorAll('.abf-pane').forEach(function (p) { p.classList.toggle('abf-active', p.id === id); });
        try { localStorage.setItem('abf_tab', id); } catch (e) {}
    }
    tabs.forEach(function (t) { t.addEventListener('click', function () { activate(t.dataset.pane); }); });
    var initial = <?= json_encode($active_tab) ?>;
    if (initial === 'tab-settings') {
        try { /* nach Speichern auf Einstellungen bleiben, sonst letzten Tab merken */ } catch (e) {}
    }
    activate(initial);
})();
</script>
<?php
if ($use_frame) {
    LBWeb::lbfooter();
}
