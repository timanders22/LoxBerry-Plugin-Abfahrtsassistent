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
/* Drei Stellen gehoeren immer zusammen: Reiterleiste, Bereich (sm-pane mit
   gleicher id) und diese Positivliste. Fehlt ein Name hier, ist der Reiter
   sichtbar und anklickbar - aber nach jedem Absenden springt die Seite
   zurueck auf Einstellungen. */
$abf_muster = '/^tab-(settings|mqtt|loxone|test|log)$/';
$active_tab = preg_match($abf_muster, (string) ($_POST['activetab'] ?? '')) ? $_POST['activetab'] : 'tab-settings';
if (isset($_GET['form']) && preg_match($abf_muster, 'tab-' . $_GET['form'])) {
    $active_tab = 'tab-' . $_GET['form'];
}

// ---------- Loxone-Vorlage herunterladen ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['download'])) {
    $host = $_SERVER['HTTP_HOST'] ?? '';
    $host = $host !== '' ? preg_replace('/[^A-Za-z0-9\.\-:]/', '', $host) : '';
    $v = abfahrt_vorlage($host);
    header('Content-Type: application/x-download');
    // Die Anfuehrungszeichen um den Dateinamen sind Pflicht - ohne sie bricht
    // jeder Name, der ein Leerzeichen enthaelt.
    header('Content-Disposition: attachment; filename="' . $v[0] . '"');
    header('Content-Length: ' . strlen($v[1]));
    echo $v[1];
    exit;
}

// ---------- Audio-Server suchen ----------
$abf_ms4h = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ms4h_suchen'])) {
    $abf_ms4h = abfahrt_ms4h_suchen();
    $active_tab = 'tab-settings';
}

// ---------- Log leeren ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['clearlog'])) {
    @file_put_contents($log_file, '[' . date('Y-m-d H:i:s') . "] Log geleert (Admin-Oberflaeche)\n");
    $active_tab = 'tab-log';
}

// ---------- Speichern: nur die MQTT-Werte ----------
// Bewusst ein eigener Zweig. Der grosse Speichervorgang unten baut die
// Konfiguration von Grund auf neu; wuerde das MQTT-Formular dieselbe Taste
// druecken, waeren Kalender, Zugangsdaten und Sperrzeiten anschliessend leer.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_mqtt'])) {
    $abf_neu = abfahrt_config();
    $abf_neu['mqtt_ein'] = empty($_POST['mqtt_ein']) ? 0 : 1;
    $abf_t = preg_replace('#[^A-Za-z0-9_/\-]#', '', trim((string) ($_POST['mqtt_topic'] ?? '')));
    if ($abf_t === '') {
        $save_error = abfahrt_t('MQTT.FEHLER_TOPIC');
    } else {
        $abf_neu['mqtt_topic'] = $abf_t;
        if (!is_dir($config_dir)) { @mkdir($config_dir, 0775, true); }
        $abf_js = json_encode($abf_neu, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($abf_js !== false && @file_put_contents($config_file, $abf_js) !== false) {
            @chmod($config_file, 0600);
            @copy($config_file, $backup_file);
            @chmod($backup_file, 0600);
            $saved = true;
        } else {
            $save_error = 'Konfiguration konnte nicht gespeichert werden: ' . $config_file;
        }
    }
    $active_tab = 'tab-mqtt';
}

// ---------- Speichern (auch bei "Kalender neu einlesen") ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (isset($_POST['save']) || isset($_POST['refresh'])) && !isset($_POST['clearlog'])) {
    // ACHTUNG: hier wird die Konfiguration KOMPLETT neu aufgebaut. Alles, was
    // nicht in diesem Formular steht, waere danach weg. Die MQTT-Werte stehen
    // in einem eigenen Reiter mit eigenem Formular - sie werden deshalb aus
    // dem alten Stand uebernommen.
    $abf_alt = abfahrt_config();
    $abfcfg = [];
    $abfcfg['mqtt_ein'] = (int) $abf_alt['mqtt_ein'];
    $abfcfg['mqtt_topic'] = (string) $abf_alt['mqtt_topic'];
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
        // json_encode liefert bei ungueltigem UTF-8 false, und file_put_contents
        // schriebe dann eine Datei mit NULL Bytes - und meldete das als Erfolg.
        if ($json !== false && @file_put_contents($config_file, $json) !== false) {
            $saved = true;
            // In dieser Datei steht der API-Key des Kartendienstes. Bei TomTom
            // haengt daran ein Tageskontingent, bei Google eine Rechnung -
            // sie geht niemanden ausser den Eigentuemer etwas an. Gilt fuer die
            // Sicherung genauso, sonst liegt die Kopie offen daneben.
            @chmod($config_file, 0600);
            @copy($config_file, $backup_file); // Sicherung ausserhalb des Plugin-Ordners (uebersteht Updates & Neuinstallation)
            @chmod($backup_file, 0600);
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
            // Port aus der general.json, nicht hart 80 - siehe abfahrt_webport().
            $warm = function_exists('abfahrt_lokal_url')
                ? abfahrt_lokal_url('/plugins/' . $plugindir . '/termin.php')
                : 'http://127.0.0.1/plugins/' . $plugindir . '/termin.php';
            @file_get_contents($warm, false, $ctx);
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

/* Merkwort fuer die beiden ausloesenden Aufrufe (termin_say.php und
 * termin.php?debug=1). Wird beim ersten Oeffnen der Oberflaeche erzeugt und
 * danach NIE wieder angefasst - sonst wuerden einmal eingetragene
 * Loxone-Adressen ungueltig. Bestehende Anlagen bekommen es beim naechsten
 * Aufruf dieser Seite.
 *
 * Geschrieben wird direkt und nicht ueber den Speichern-Zweig oben: der
 * laeuft nur bei einem abgesendeten Formular. */
if (empty($abfcfg['aktionstoken'])) {
    $abfcfg['aktionstoken'] = abfahrt_token_erzeugen();
    $abf_tokjson = json_encode($abfcfg, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($abf_tokjson !== false) {
        if (!is_dir($config_dir)) { @mkdir($config_dir, 0775, true); }
        if (@file_put_contents($config_file, $abf_tokjson) !== false) {
            @chmod($config_file, 0600);
            @copy($config_file, $backup_file);
            @chmod($backup_file, 0600);
        }
    }
}
$abf_token = (string) $abfcfg['aktionstoken'];

$status = @json_decode((string) @file_get_contents('/tmp/abfahrtsassistent/titel.json'), true) ?: [];
$log_lines = [];
if (is_file($log_file)) {
    $log_lines = array_slice(array_reverse(file($log_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: []), 0, 300);
}

function e($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); }

$use_frame = class_exists('LBWeb', false);
if ($use_frame) {
    LBWeb::lbheader('Abfahrts-Assistent', 'https://wiki.loxberry.de/', 'help.html');
}
$host = e($_SERVER['HTTP_HOST'] ?? '<loxberry-ip>');
?>
<style>
.sm-wrap { max-width: 940px; margin: 0 auto; font-family: -apple-system, 'Segoe UI', Roboto, sans-serif; color: #333; }
.sm-wrap h2 { color: #6dac20; margin: 24px 0 10px; font-size: 1.15em; border-bottom: 2px solid #e0e0e0; padding-bottom: 6px; }
.sm-wrap h3 { color: #6dac20; margin: 18px 0 6px; font-size: 1.0em; }
.sm-wrap label { display: block; font-weight: 600; font-size: 0.88em; color: #555; margin: 10px 0 4px; }
.sm-wrap input[type=text], .sm-wrap input[type=password], .sm-wrap input[type=number], .sm-wrap select, .sm-wrap textarea {
  width: 100%; padding: 8px 10px; border: 1px solid #ccc; border-radius: 6px; font-size: 0.95em; box-sizing: border-box; }
.sm-wrap input[type=time] { width: 92px !important; min-width: 92px; padding: 4px 6px !important; border: 1px solid #ccc; border-radius: 6px; font-size: 0.95em; display: inline-block; box-sizing: border-box; }
.sm-wrap input[type=checkbox] { width: 17px; height: 17px; margin: 0; vertical-align: middle; }
.sm-row { display: flex; gap: 12px; }
.sm-row > div { flex: 1; }
.sm-cal { display: flex; gap: 8px; margin-bottom: 6px; align-items: center; }
.sm-cal input:first-child { flex: 0 0 170px; }
.sm-cal .sm-rfbtn { flex: 0 0 auto; background: #607d8b; color: #fff; border: 0; border-radius: 6px; padding: 7px 10px; font-size: 0.82em; cursor: pointer; white-space: nowrap; }
.sm-btn { background: #6dac20; color: #fff; border: 0; border-radius: 6px; padding: 10px 22px; font-size: 1em; cursor: pointer; margin-top: 18px; }
.sm-alert { border-radius: 8px; padding: 10px 14px; margin: 12px 0; }
.sm-ok { background: #e8f5e9; border: 1px solid #a5d6a7; }
.sm-err { background: #ffebee; border: 1px solid #ef9a9a; }
.sm-info { background: #e3f2fd; border: 1px solid #90caf9; font-size: 0.9em; }
.sm-mono { font-family: ui-monospace, monospace; background: #f5f5f5; padding: 2px 6px; border-radius: 4px; }
.sm-small { font-size: 0.82em; color: #666; margin-top: 3px; }
.sm-qt { border-collapse: collapse; }
.sm-qt td { vertical-align: middle; padding: 3px 0; }
.sm-tabs { display: flex; gap: 4px; margin: 14px 0 0; border-bottom: 2px solid #6dac20; flex-wrap: wrap; }
.sm-tab { background: #eee; border: 1px solid #ccc; border-bottom: 0; border-radius: 8px 8px 0 0; padding: 9px 18px; cursor: pointer; font-size: 0.95em; color: #444; }
.sm-tab.sm-active { background: #6dac20; color: #fff; border-color: #6dac20; font-weight: 600; }
.sm-pane { display: none; padding-top: 4px; }
.sm-pane.sm-active { display: block; }
.sm-log { text-shadow: none !important; background: #1e1e1e; color: #d4d4d4; font-family: ui-monospace, monospace; font-size: 0.82em; padding: 12px; border-radius: 8px; max-height: 480px; overflow: auto; white-space: pre-wrap; }
.sm-step { margin: 10px 0; padding: 10px 14px; background: #fafafa; border-left: 4px solid #6dac20; border-radius: 0 8px 8px 0; }
.sm-tbl { border-collapse: collapse; margin: 8px 0; }
.sm-tbl th, .sm-tbl td { border: 1px solid #ddd; padding: 6px 10px; text-align: left; font-size: 0.9em; }
.sm-tbl th { background: #f0f0f0; }
/* LoxBerry/jQuery-Mobile-Styles neutralisieren */
.sm-wrap .sm-tab, .sm-wrap .sm-btn, .sm-wrap .sm-rfbtn, .sm-wrap a.sm-btn, .sm-wrap button {
  text-shadow: none !important; box-shadow: none !important; }
.sm-wrap .sm-btn, .sm-wrap a.sm-btn { color: #fff !important; font-weight: 600; }
.sm-wrap .sm-rfbtn { color: #fff !important; width: auto !important; }
.sm-wrap .sm-tab { color: #444 !important; }
.sm-wrap .sm-tab.sm-active { color: #fff !important; }
.sm-cal input:first-child { flex: 0 0 170px !important; width: 170px !important; }
.sm-cal input:nth-child(2) { flex: 1 1 auto !important; width: auto !important; min-width: 200px; }
.sm-cal .sm-rfbtn { flex: 0 0 auto !important; }
.sm-wrap a.sm-btn:visited, .sm-wrap a.sm-btn:hover, .sm-wrap a.sm-btn:active { color: #fff !important; }

/* --- Einheitliches Kachel-Raster im Reiter <?php echo abfahrt_t('TEXT.TEST'); ?> (Standard aller <?php echo abfahrt_t('TEXT.PLUGIN'); ?>s) --- */
.sm-h3 { color: #4f7d17; font-size: 1.0em; font-weight: 700; margin: 16px 0 2px; text-shadow: none !important; }
.sm-knopfreihe { display: flex; flex-wrap: wrap; gap: 10px; margin: 10px 0 4px; align-items: stretch; }
.sm-knopfreihe form { margin: 0; display: flex; }
.sm-knopfreihe .sm-btn { flex: 0 0 auto; min-width: 250px; text-align: center;
    display: inline-flex; align-items: center; justify-content: center; line-height: 1.25; }
.sm-legende { display: flex; flex-wrap: wrap; gap: 14px; margin: 10px 0 2px; font-size: 0.86em; color: #555; }
.sm-legende span { display: inline-flex; align-items: center; gap: 6px; }
.sm-punkt { width: 13px; height: 13px; border-radius: 3px; display: inline-block; }
.sm-btn.sm-b-lesen   { background: #6dac20; }
.sm-btn.sm-b-technik { background: #546e7a; }
.sm-btn.sm-b-aktion  { background: #e0620d; }
.sm-punkt.sm-b-lesen   { background: #6dac20; }
.sm-punkt.sm-b-technik { background: #546e7a; }
.sm-punkt.sm-b-aktion  { background: #e0620d; }
</style>
<div class="sm-wrap">

<?php if ($saved) { ?><div class="sm-alert sm-ok"><b><?php echo abfahrt_t('TEXT.KONFIGURATION_GESPEICHERT'); ?></b> <?php echo abfahrt_t('TEXT.INKL_SICHERUNGSKOPIE_FR_UPDATES'); ?></div><?php } ?>
<?php if ($refreshed_msg !== '') { ?><div class="sm-alert sm-ok"><b><?= e($refreshed_msg) ?></b> <?php echo abfahrt_t('TEXT.ERGEBNIS_SIEHE_STATUS_ZEILE_UNTEN_'); ?></div><?php } ?>
<?php if ($save_error !== '') { ?><div class="sm-alert sm-err"><b><?php echo abfahrt_t('TEXT.FEHLER'); ?></b> <?= e($save_error) ?></div><?php } ?>

<?php if (!empty($status['titel'])) { ?>
<div class="sm-alert sm-info"><b><?php echo abfahrt_t('TEXT.LETZTER_BERECHNETER_TERMIN'); ?></b> <?= e($status['titel']) ?> (<?= e($status['kalender'] ?? '') ?>), <?= e($status['beginn'] ?? '') ?><?php echo abfahrt_t('TEXT.ORT'); ?> <?= e($status['ort'] ?? '') ?> <?php echo abfahrt_t('TEXT.FAHRZEIT'); ?> <?= isset($status['fahrt']) ? (int) ceil((float) $status['fahrt']) : '?' ?> <?php echo abfahrt_t('TEXT.MINUTEN_ABFAHRT_IN'); ?> <?= isset($status['abfahrt_in']) ? (int) $status['abfahrt_in'] : '?' ?> <?php echo abfahrt_t('TEXT.MINUTEN'); ?></div>
<?php } ?>

<!-- Reiterleiste: echte Links, JavaScript faengt den Klick ab. Warum beides:
     Der Link traegt die Adresse - jeder Reiter ist damit verlinkbar, die
     Zurueck-Taste tut das Erwartete, und faellt das Skript aus, bleibt die
     Seite bedienbar. -->
<div class="sm-tabs">
    <a class="sm-tab" data-pane="tab-settings" href="index.php?form=settings"><?php echo abfahrt_t('REITER.EINSTELLUNGEN'); ?></a>
    <a class="sm-tab" data-pane="tab-mqtt"     href="index.php?form=mqtt"><?php echo abfahrt_t('REITER.MQTT'); ?></a>
    <a class="sm-tab" data-pane="tab-loxone"   href="index.php?form=loxone"><?php echo abfahrt_t('REITER.LOXONE'); ?></a>
    <a class="sm-tab" data-pane="tab-test"     href="index.php?form=test"><?php echo abfahrt_t('REITER.TEST'); ?></a>
    <a class="sm-tab" data-pane="tab-log"      href="index.php?form=log"><?php echo abfahrt_t('REITER.LOG'); ?></a>
</div>

<!-- ================= Reiter: Einstellungen ================= -->
<div class="sm-pane" id="tab-settings">
<form action="index.php" method="post" autocomplete="off">
<input data-role="none" type="hidden" name="save" value="1">
<input data-role="none" type="hidden" name="activetab" value="tab-settings">

<h2><?php echo abfahrt_t('TEXT.KALENDER_BIS_ZU_10_ICAL_URLS'); ?></h2>
<p class="sm-small"><?php echo abfahrt_t('TEXT.PRIVATE_ICAL_ADRESSE_Z_B_GOOGLE_KA'); ?> <b><?php echo abfahrt_t('TEXT.MIT_ORTSANGABE'); ?></b> <?php echo abfahrt_t('TEXT.IM_EINGESTELLTEN_ZEITFENSTER_AUCH_'); ?></p>
<?php for ($i = 0; $i < 10; $i++) { $cal = $abfcfg['calendars'][$i]; ?>
<div class="sm-cal">
    <input data-role="none" type="text" name="cal_name[]" value="<?= e($cal['name']) ?>" placeholder="Name (z. B. Familie)">
    <input data-role="none" type="text" name="cal_url[]" value="<?= e($cal['url']) ?>" placeholder="https://calendar.google.com/calendar/ical/.../basic.ics">
    <button data-role="none" class="sm-rfbtn" type="submit" name="refresh" value="<?= $i ?>" formnovalidate title="Cache leeren und diesen Kalender sofort neu laden"><?php echo abfahrt_t('TEXT.NEU_EINLESEN_2'); ?></button>
</div>
<?php } ?>

<h2><?php echo abfahrt_t('TEXT.KARTENDIENST_VERKEHRSLAGE'); ?></h2>
<div class="sm-row">
    <div>
        <label><?php echo abfahrt_t('TEXT.DIENST'); ?></label>
        <select data-role="none" name="provider" id="provider">
            <option value="tomtom"<?= $abfcfg['provider'] === 'tomtom' ? ' selected' : '' ?><?php echo abfahrt_t('TEXT.TOMTOM_KOSTENLOS_2500_ABFRAGEN_TAG'); ?></option>
            <option value="google"<?= $abfcfg['provider'] === 'google' ? ' selected' : '' ?><?php echo abfahrt_t('TEXT.GOOGLE_MAPS_DIRECTIONS_API'); ?></option>
            <option value="here"<?= $abfcfg['provider'] === 'here' ? ' selected' : '' ?><?php echo abfahrt_t('TEXT.HERE_ROUTING_V8'); ?></option>
        </select>
        <div class="sm-small"><?php echo abfahrt_t('TEXT.API_KEY'); ?> <a href="https://<?php echo abfahrt_t('TEXT.DEVELOPER_TOMTOM_COM'); ?>" target="_blank">developer.tomtom.com</a> | <a href="https://console.cloud.google.com" target="_blank"><?php echo abfahrt_t('TEXT.GOOGLE_CLOUD_CONSOLE'); ?></a> | <a href="https://<?php echo abfahrt_t('TEXT.PLATFORM_HERE_COM'); ?>" target="_blank">platform.here.com</a></div>
    </div>
    <div>
        <label><?php echo abfahrt_t('TEXT.API_KEY_2'); ?></label>
        <input data-role="none" type="password" name="api_key" value="<?= e($abfcfg['api_key']) ?>" placeholder="API-Key des gew&auml;hlten Dienstes">
    </div>
</div>

<label><?php echo abfahrt_t('TEXT.ABFAHRTSADRESSE_ZUHAUSE'); ?></label>
<input data-role="none" type="text" name="home_address" value="<?= e($abfcfg['home_address']) ?>" placeholder="Stra&szlig;e Hausnummer, PLZ Ort">

<label><?php echo abfahrt_t('TEXT.IGNORIERTE_ORTE_ONLINE_TERMINE'); ?></label>
<input data-role="none" type="text" name="ignore_locations" value="<?= e($abfcfg['ignore_locations']) ?>" placeholder="online, teams, zoom, ...">
<div class="sm-small"><?php echo abfahrt_t('TEXT.TERMINE_DEREN_ORTSANGABE_EINES_DIE'); ?> <b><?php echo abfahrt_t('TEXT.OHNE'); ?></b> <?php echo abfahrt_t('TEXT.ORT_BEHANDELT_KEINE_FAHRZEITBERECH'); ?></div>

<h2><?php echo abfahrt_t('TEXT.ZEITEN'); ?></h2>
<div class="sm-row">
    <div>
        <label><?php echo abfahrt_t('TEXT.ANKUNFT_VOR_TERMIN_MINUTEN'); ?></label>
        <input data-role="none" type="number" name="arrival_min" value="<?= (int) $abfcfg['arrival_min'] ?>" min="0" max="120">
        <div class="sm-small"><?php echo abfahrt_t('TEXT.SO_VIELE_MINUTEN_VOR_TERMINBEGINN_'); ?></div>
    </div>
    <div>
        <label><?php echo abfahrt_t('TEXT.PUFFERZEIT_MINUTEN'); ?></label>
        <input data-role="none" type="number" name="buffer_min" value="<?= (int) $abfcfg['buffer_min'] ?>" min="0" max="120">
        <div class="sm-small"><?php echo abfahrt_t('TEXT.ZUSTZLICHER_PUFFER_FR_ANZIEHEN_AUT'); ?></div>
    </div>
    <div>
        <label><?php echo abfahrt_t('TEXT.ZEITFENSTER_STUNDEN'); ?></label>
        <input data-role="none" type="number" name="lookahead_hours" value="<?= (int) $abfcfg['lookahead_hours'] ?>" min="1" max="48">
        <div class="sm-small"><?php echo abfahrt_t('TEXT.WIE_WEIT_VORAUSGESCHAUT_WIRD_STAND'); ?></div>
    </div>
</div>

<h2><?php echo abfahrt_t('TEXT.SPRACHAUSGABE'); ?></h2>
<div class="sm-row">
    <div>
        <label><?php echo abfahrt_t('TEXT.AUDIO_AUSGABE'); ?></label>
        <select data-role="none" name="tts_mode" id="tts_mode" onchange="abfTtsMode()">
            <option value="musicserver"<?= $abfcfg['tts']['mode'] === 'musicserver' ? ' selected' : '' ?><?php echo abfahrt_t('TEXT.LOXONE_MUSIC_SERVER_KLASSISCH'); ?></option>
            <option value="ms4h"<?= $abfcfg['tts']['mode'] === 'ms4h' ? ' selected' : '' ?><?php echo abfahrt_t('TEXT.AUDIOSERVER4HOME_MUSICSERVER4HOME'); ?></option>
            <option value="audioserver"<?= $abfcfg['tts']['mode'] === 'audioserver' ? ' selected' : '' ?><?php echo abfahrt_t('TEXT.ORIGINAL_LOXONE_AUDIOSERVER_VIA_LO'); ?></option>
            <option value="custom"<?= $abfcfg['tts']['mode'] === 'custom' ? ' selected' : '' ?><?php echo abfahrt_t('TEXT.EIGENE_URL_VORLAGE'); ?></option>
        </select>
    </div>
    <div>
        <label><?php echo abfahrt_t('TEXT.IP_DES_AUDIO_SERVERS'); ?></label>
        <input data-role="none" type="text" name="tts_ip" value="<?= e($abfcfg['tts']['ip']) ?>" placeholder="z. B. 192.168.1.50">
<?php if ($abf_ms4h !== null) { ?>
  <?php if ($abf_ms4h['gefunden']) { ?>
    <div class="sm-alert sm-info"><?= sprintf(abfahrt_t('MS4H.GEFUNDEN'), (int) $abf_ms4h['port'], e($abf_ms4h['quelle'])) ?></div>
  <?php } else { ?>
    <div class="sm-alert sm-err"><?php echo abfahrt_t('MS4H.NICHTS'); ?></div>
  <?php } ?>
<?php } ?>
<div class="sm-legende" style="margin-top:6px;">
<span><i class="sm-punkt sm-b-lesen"></i> <?php echo abfahrt_t('LEGENDE.LESEN'); ?></span>
</div>
<div class="sm-knopfreihe">
<form action="index.php" method="post" style="margin:0;">
    <input data-role="none" type="hidden" name="ms4h_suchen" value="1">
    <input data-role="none" type="hidden" name="activetab" value="tab-settings">
    <button data-role="none" class="sm-btn sm-b-lesen" type="submit"><?php echo abfahrt_t('MS4H.K_SUCHEN'); ?></button>
</form>
</div>
<div class="sm-small"><?php echo abfahrt_t('MS4H.HINWEIS'); ?></div>
    </div>
    <div>
        <label><?php echo abfahrt_t('TEXT.PORT'); ?></label>
        <input data-role="none" type="number" name="tts_port" value="<?= (int) $abfcfg['tts']['port'] ?>" min="1" max="65535">
    </div>
</div>
<div class="sm-row">
    <div>
        <label><?php echo abfahrt_t('TEXT.ZONEN'); ?></label>
        <input data-role="none" type="text" name="tts_zones" value="<?= e($abfcfg['tts']['zones']) ?>" placeholder="z. B. 2,4,6">
        <div class="sm-small"><?php echo abfahrt_t('TEXT.ZONENNUMMERN_MIT_KOMMA_Z_B'); ?> <span class="sm-mono">2,4,6</span><?php echo abfahrt_t('TEXT.DIE_LAUTSTRKE_KOMMT_AUS_DEM_FELD_D'); ?> <span class="sm-mono"><?php echo abfahrt_t('TEXT.ZONE_LAUTSTRKE'); ?></span> <?php echo abfahrt_t('TEXT.Z_B'); ?> <span class="sm-mono">2~25,4~40</span><?php echo abfahrt_t('TEXT.LEERZEICHEN_NACH_DEM_KOMMA_SIND_ER'); ?> <span class="sm-mono">2,4,6</span> und <span class="sm-mono">2, 4, 6</span> <?php echo abfahrt_t('TEXT.FUNKTIONIEREN_BEIDE'); ?></div>
    </div>
    <div>
        <label><?php echo abfahrt_t('TEXT.LAUTSTRKE'); ?></label>
        <input data-role="none" type="number" name="tts_volume" value="<?= (int) $abfcfg['tts']['volume'] ?>" min="1" max="100">
    </div>
    <div>
        <label><?php echo abfahrt_t('TEXT.SPRACHE'); ?></label>
        <input data-role="none" type="text" name="tts_lang" value="<?= e($abfcfg['tts']['lang']) ?>" maxlength="2">
    </div>
</div>
<div id="tts_template_row">
    <label><?php echo abfahrt_t('TEXT.URL_VORLAGE_FR_AUDIOSERVER4HOME_MS'); ?></label>
    <textarea data-role="none" name="tts_template" id="tts_template" rows="2" placeholder="<?php echo abfahrt_t('TEXT.HTTP'); ?>{ip}:{port}/tts?text={text}&amp;zone={zones}&amp;vol={vol}"><?= e($abfcfg['tts']['template']) ?></textarea>
    <div class="sm-small"><?php echo abfahrt_t('TEXT.PLATZHALTER'); ?> <span class="sm-mono"><?php echo abfahrt_t('TEXT.IP_PORT_ZONES_VOL_LANG_TEXT'); ?></span><?php echo abfahrt_t('TEXT.LEER_STANDARD_VORLAGE'); ?></div>
</div>
<div id="tts_audioserver_hint" class="sm-alert sm-info" style="display:none;">
    <?php echo abfahrt_t('TEXT.DER_ORIGINALE_LOXONE_AUDIOSERVER_B'); ?> <b><?php echo abfahrt_t('TEXT.KEINE_HTTP_TTS_SCHNITTSTELLE'); ?></b><?php echo abfahrt_t('TEXT.IN_DIESEM_MODUS_LIEFERT'); ?>
    <span class="sm-mono"><?php echo abfahrt_t('TEXT.TERMIN_SAY_PHP'); ?></span> <?php echo abfahrt_t('TEXT.NUR_DEN_ANSAGETEXT'); ?><span class="sm-mono"><?php echo abfahrt_t('TEXT.TEXT'); ?></span><?php echo abfahrt_t('TEXT.SPRACHAUSGABE_DANN_IN_LOXONE_CONFI'); ?>
</div>

<h2><?php echo abfahrt_t('TEXT.BENACHRICHTIGUNGEN'); ?></h2>
<div style="margin-bottom:10px;">
    <label style="display:inline-flex;align-items:center;gap:6px;margin-right:24px;">
        <input data-role="none" type="checkbox" name="notify_audio" <?= !empty($abfcfg['notify']['audio']) ? 'checked' : '' ?><?php echo abfahrt_t('TEXT.AUDIOAUSGABE_AKTIV'); ?>
    </label>
    <label style="display:inline-flex;align-items:center;gap:6px;">
        <input data-role="none" type="checkbox" name="notify_push" <?= !empty($abfcfg['notify']['push']) ? 'checked' : '' ?><?php echo abfahrt_t('TEXT.PUSH_NACHRICHT_AKTIV'); ?>
    </label>
    <div class="sm-small"><?php echo abfahrt_t('TEXT.BEIDES_AN_ANSAGE_PUSH_NUR_EINES_AN'); ?> <span class="sm-mono"><?php echo abfahrt_t('TEXT.AUDIO'); ?></span>/<span class="sm-mono"><?php echo abfahrt_t('TEXT.PUSH'); ?></span> <?php echo abfahrt_t('TEXT.AN_LOXONE_BERGEBEN_DEN_PUSH_VERSCH'); ?></div>
</div>

<h2><?php echo abfahrt_t('TEXT.SPERRZEITEN_FR_DIE_AUDIOAUSGABE'); ?></h2>
<div class="sm-small" style="margin-bottom:6px;"><?php echo abfahrt_t('TEXT.IN_DER_SPERRZEIT_BLEIBT_DER_LAUTSP'); ?></div>
<table class="sm-qt">
<?php $days = abfahrt_quiet_labels();
foreach ($days as $d => $dayname) { ?>
<tr<?= $d === 8 ? ' style="height:34px;vertical-align:bottom;"' : '' ?>>
    <td style="width:28px;"><input data-role="none" type="checkbox" name="quiet_on[<?= $d ?>]" <?= !empty($abfcfg['quiet'][$d]['on']) ? 'checked' : '' ?>></td>
    <td style="width:105px;"><?= $d >= 8 ? '<b>' . $dayname . '</b>' : $dayname ?></td>
    <td style="width:100px;"><?php echo abfahrt_t('TEXT.SPERRZEIT_VON'); ?></td>
    <td style="width:100px;"><input data-role="none" type="time" name="quiet_from[<?= $d ?>]" value="<?= e($abfcfg['quiet'][$d]['from']) ?>"></td>
    <td style="width:34px;text-align:center;">bis</td>
    <td style="width:100px;"><input data-role="none" type="time" name="quiet_to[<?= $d ?>]" value="<?= e($abfcfg['quiet'][$d]['to']) ?>"></td>
    <td>Uhr</td>
</tr>
<?php } ?>
</table>
<?php $abf_tag = function_exists('abfahrt_daytype') ? abfahrt_daytype() : ['quelle' => 'keine'];
$abf_regel = function_exists('abfahrt_quiet_rule') ? abfahrt_quiet_rule($abfcfg) : [0, '']; ?>
<div class="sm-small" style="margin-top:6px;">
<b><?php echo abfahrt_t('TEXT.FEIERTAG_FERIEN_UND_URLAUB_GEHEN_D'); ?></b> <?php echo abfahrt_t('TEXT.DAMIT_LSST_SICH_DIE_ANSAGE_AN_FREI'); ?>
<b><?php echo abfahrt_t('TEXT.URLAUB_FEIERTAG_FERIEN_WOCHENTAG'); ?></b><?php echo abfahrt_t('TEXT.EIN_SONDERTAG_GREIFT_NUR_WENN_SEIN'); ?><br>
<?php echo abfahrt_t('TEXT.WOHER_DIE_SONDERTAGE_KOMMEN_AUS_DE'); ?> <b><?php echo abfahrt_t('TEXT.FERIEN_UND_FEIERTAGE'); ?></b> <?php echo abfahrt_t('TEXT.DORT_AUCH_DER_URLAUB_ALS_EIGENER_T'); ?><br>
<?php echo abfahrt_t('TEXT.STATUS_JETZT'); ?> <?php if ($abf_tag['quelle'] === 'keine') {
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

<button data-role="none" class="sm-btn" type="submit"><?php echo abfahrt_t('TEXT.SPEICHERN'); ?></button>
</form>
</div>

<!-- ================= Reiter: MQTT ================= -->
<div class="sm-pane" id="tab-mqtt">
<h2><?php echo abfahrt_t('MQTT.H_TITEL'); ?></h2>
<?php $abf_m = abfahrt_mqtt_zustand(); ?>
<?php if (!$abf_m['gefunden']) { ?>
<div class="sm-alert sm-err"><?php echo abfahrt_t('MQTT.KEIN_ABSCHNITT'); ?></div>
<?php } elseif (!$abf_m['autostart']) { ?>
<div class="sm-alert sm-err"><?php echo abfahrt_t('MQTT.KEIN_AUTOSTART'); ?></div>
<?php } else { ?>
<div class="sm-alert sm-info"><?= sprintf(abfahrt_t('MQTT.OK'), (int) $abf_m['udpport']) ?></div>
<?php } ?>

<div class="sm-step"><?php echo abfahrt_t('MQTT.WARUM'); ?></div>

<h3 class="sm-h3"><?php echo abfahrt_t('MQTT.H_ABO'); ?></h3>
<div class="sm-small"><?php echo abfahrt_t('MQTT.ABO_TEXT'); ?></div>
<div class="sm-mono" style="display:block;padding:8px;margin:6px 0;"><?= e($abfcfg['mqtt_topic']) ?>/#</div>
<div class="sm-alert sm-err"><?php echo abfahrt_t('MQTT.ABO_WARNUNG'); ?></div>

<h3 class="sm-h3"><?php echo abfahrt_t('MQTT.H_THEMEN'); ?></h3>
<table class="sm-tbl">
<tr><th><?php echo abfahrt_t('MQTT.T_THEMA'); ?></th><th><?php echo abfahrt_t('MQTT.T_BEDEUTUNG'); ?></th><th><?php echo abfahrt_t('MQTT.T_WERT'); ?></th></tr>
<?php $abf_w = abfahrt_werte(abfahrt_stand(), $abfcfg); ?>
<?php foreach (abfahrt_felder() as $abf_n => $abf_d) { ?>
<tr><td><span class="sm-mono"><?= e($abfcfg['mqtt_topic']) ?>/<?= e($abf_n) ?></span></td>
    <td><?php echo abfahrt_t($abf_d[3]); ?></td>
    <td><?= isset($abf_w[$abf_n]) ? e($abf_w[$abf_n]) : '&mdash;' ?></td></tr>
<?php } ?>
</table>

<form action="index.php" method="post">
<input data-role="none" type="hidden" name="save_mqtt" value="1">
<input data-role="none" type="hidden" name="activetab" value="tab-mqtt">
<div class="sm-row"><label><input data-role="none" type="checkbox" name="mqtt_ein" value="1"<?= !empty($abfcfg['mqtt_ein']) ? ' checked' : '' ?>> <?php echo abfahrt_t('MQTT.L_EIN'); ?></label></div>
<div class="sm-row"><label><?php echo abfahrt_t('MQTT.L_TOPIC'); ?></label>
<input data-role="none" type="text" name="mqtt_topic" value="<?= e($abfcfg['mqtt_topic']) ?>" size="24"></div>
<div class="sm-legende"><span><i class="sm-punkt sm-b-aktion"></i> <?php echo abfahrt_t('LEGENDE.AKTION'); ?></span></div>
<div class="sm-knopfreihe">
<button data-role="none" class="sm-btn sm-b-aktion" type="submit"><?php echo abfahrt_t('TEXT.SPEICHERN'); ?></button>
</div>
</form>
</div>

<!-- ================= Reiter: Einbindung in Loxone ================= -->
<div class="sm-pane" id="tab-loxone">
<h2><?php echo abfahrt_t('TEXT.EINBINDUNG_IN_LOXONE_SCHRITT_FR_SC'); ?></h2>
<p><?php echo abfahrt_t('TEXT.DAS_PLUGIN_BERECHNET_AUF_DEM_LOXBE'); ?></p>

<div class="sm-step"><b><?php echo abfahrt_t('LOX.S1_T'); ?></b><br><br>
<?php echo abfahrt_t('LOX.S1'); ?></div>

<div class="sm-step"><b><?php echo abfahrt_t('LOX.S2_T'); ?></b><br><br>
<?php echo abfahrt_t('LOX.S2'); ?>
<div class="sm-mono" style="display:block;padding:8px;margin:6px 0;"><?= e($abfcfg['mqtt_topic']) ?>/#</div>
<div class="sm-alert sm-err"><?php echo abfahrt_t('MQTT.ABO_WARNUNG'); ?></div>
<?php echo abfahrt_t('LOX.S2_THEMEN'); ?>
<table class="sm-tbl">
<tr><th><?php echo abfahrt_t('MQTT.T_THEMA'); ?></th><th><?php echo abfahrt_t('MQTT.T_BEDEUTUNG'); ?></th></tr>
<?php foreach (abfahrt_felder() as $abf_n => $abf_d) { ?>
<tr><td><span class="sm-mono"><?= e($abfcfg['mqtt_topic']) ?>/<?= e($abf_n) ?></span></td><td><?php echo abfahrt_t($abf_d[3]); ?></td></tr>
<?php } ?>
</table>
</div>

<h3 class="sm-h3"><?php echo abfahrt_t('LOX.H_ALTERNATIVE'); ?></h3>
<div class="sm-small"><?php echo abfahrt_t('LOX.ALTERNATIVE_TEXT'); ?></div>

<div class="sm-step"><b><?php echo abfahrt_t('TEXT.SCHRITT_1_VIRTUELLEN_HTTP_EINGANG_'); ?></b><br><br>
In <b><?php echo abfahrt_t('TEXT.LOXONE_CONFIG'); ?></b><?php echo abfahrt_t('TEXT.MINISERVER_IN_DER_BAUMANSICHT_ANKL'); ?> <i><?php echo abfahrt_t('TEXT.VIRTUELLE_EINGNGE'); ?></i> <?php echo abfahrt_t('TEXT.TEXT_2'); ?>
<i><?php echo abfahrt_t('TEXT.VIRTUELLER_HTTP_EINGANG'); ?></i> <?php echo abfahrt_t('TEXT.ANLEGEN_DANN_RECHTS_IN_DEN_EIGENSC'); ?>
<table class="sm-tbl">
<tr><th><?php echo abfahrt_t('TEXT.EIGENSCHAFT'); ?></th><th><?php echo abfahrt_t('TEXT.WERT'); ?></th></tr>
<tr><td>URL</td><td><span class="sm-mono">http://<?= $host ?><?php echo abfahrt_t('TEXT.PLUGINS'); ?><?= e($plugindir) ?><?php echo abfahrt_t('TEXT.TERMIN_PHP'); ?></span></td></tr>
<tr><td><?php echo abfahrt_t('TEXT.ABFRAGEZYKLUS'); ?></td><td><?php echo abfahrt_t('TEXT.300_SEKUNDEN_ALLE_5_MINUTEN'); ?></td></tr>
</table>
<?php echo abfahrt_t('TEXT.DER_MINISERVER_RUFT_DIESE_ADRESSE_'); ?><br>
<span class="sm-mono"><?php echo abfahrt_t('TEXT.TERMIN_OK_1_MINSTART_55_FAHRT_43_7'); ?></span>
</div>

<div class="sm-step"><b><?php echo abfahrt_t('TEXT.SCHRITT_2_BEFEHLE_WERTE_IM_HTTP_EI'); ?></b><br><br>
<?php echo abfahrt_t('TEXT.UNTER_DEM_HTTP_EINGANG_LEGT_MAN_PE'); ?> <i><?php echo abfahrt_t('TEXT.VIRTUELLEN_HTTP_EINGANG_BEFEHL'); ?></i> <?php echo abfahrt_t('TEXT.AN_EINEN_JE_WERT_ENTSCHEIDEND_IST_'); ?> <b><?php echo abfahrt_t('TEXT.BEFEHLSERKENNUNG'); ?></b><?php echo abfahrt_t('TEXT.SIE_SAGT_LOXONE'); ?> <i>wo</i> <?php echo abfahrt_t('TEXT.IN_DER_ANTWORTZEILE_DIE_ZAHL_STEHT'); ?><br><br>
<b><?php echo abfahrt_t('TEXT.SO_LIEST_MAN_DAS_MUSTER'); ?></b> <span class="sm-mono"><?php echo abfahrt_t('TEXT.IABFAHRT_IN_I_V'); ?></span> <?php echo abfahrt_t('TEXT.HEIT_SUCHE_DEN_TEXT_ZWISCHEN_DEN_B'); ?> <span class="sm-mono">\i</span> <?php echo abfahrt_t('TEXT.HIER'); ?> <span class="sm-mono"><?php echo abfahrt_t('TEXT.ABFAHRT_IN'); ?></span><?php echo abfahrt_t('TEXT.UND_BERNIMM_DIE_ZAHL_DIE_DIREKT_DA'); ?><span class="sm-mono">\v</span> <?php echo abfahrt_t('TEXT.WERT_2'); ?>
<table class="sm-tbl">
<tr><th>Befehlserkennung</th><th><?php echo abfahrt_t('TEXT.BEDEUTUNG'); ?></th><th><?php echo abfahrt_t('TEXT.BEISPIELWERT'); ?></th></tr>
<tr><td><span class="sm-mono">\i<?php echo abfahrt_t('TEXT.ABFAHRT_IN_2'); ?>=\i\v</span></td><td><?php echo abfahrt_t('TEXT.MINUTEN_BIS_ZUR'); ?> <b><?php echo abfahrt_t('TEXT.EMPFOHLENEN_ABFAHRT'); ?></b> <?php echo abfahrt_t('TEXT.TERMINBEGINN_FAHRZEIT_ANKUNFTSRESE'); ?></td><td>12</td></tr>
<tr><td><span class="sm-mono"><?php echo abfahrt_t('TEXT.IMINSTART_I_V'); ?></span></td><td><?php echo abfahrt_t('TEXT.MINUTEN_BIS_ZUM'); ?> <b><?php echo abfahrt_t('TEXT.TERMINBEGINN'); ?></b>.</td><td>55</td></tr>
<tr><td><span class="sm-mono"><?php echo abfahrt_t('TEXT.IFAHRT_I_V'); ?></span></td><td><?php echo abfahrt_t('TEXT.AKTUELLE'); ?> <b><?php echo abfahrt_t('TEXT.FAHRZEIT_2'); ?></b> <?php echo abfahrt_t('TEXT.IN_MINUTEN_INKLUSIVE_STAU_VERKEHRS'); ?></td><td>43.7</td></tr>
<tr><td><span class="sm-mono"><?php echo abfahrt_t('TEXT.IOK_I_V'); ?></span></td><td><b>1</b> <?php echo abfahrt_t('TEXT.TERMIN_GEFUNDEN_UND_ROUTE_BERECHNE'); ?> <b>0</b> <?php echo abfahrt_t('TEXT.KEIN_TERMIN_MIT_ORT_IM_ZEITFENSTER'); ?></td><td>1</td></tr>
<tr><td><span class="sm-mono">\iFEHLER=\i\v</span></td><td><?php echo abfahrt_t('TEXT.FEHLER_BEDEUTUNG'); ?></td><td>0</td></tr>
<tr><td><span class="sm-mono"><?php echo abfahrt_t('TEXT.IAUDIO_I_V'); ?></span></td><td><b>1</b> <?php echo abfahrt_t('TEXT.ANSAGE_ERLAUBT_CHECKBOX_AN_UND_KEI'); ?> <b>0</b> <?php echo abfahrt_t('TEXT.LAUTSPRECHER_SOLL_STUMM_BLEIBEN'); ?></td><td>1</td></tr>
<tr><td><span class="sm-mono"><?php echo abfahrt_t('TEXT.IPUSH_I_V'); ?></span></td><td><b>1</b> <?php echo abfahrt_t('TEXT.PUSH_NACHRICHT_ERLAUBT_CHECKBOX'); ?> <b>0</b> <?php echo abfahrt_t('TEXT.KEIN_PUSH'); ?></td><td>1</td></tr>
</table>
<div class="sm-alert sm-info"><?php echo abfahrt_t('TEXT.FEHLER_ERLAEUTERUNG'); ?></div>

<h3 class="sm-h3"><?php echo abfahrt_t('LOX.H_VORLAGE'); ?></h3>
<div class="sm-small"><?php echo abfahrt_t('LOX.VORLAGE_TEXT'); ?></div>
<div class="sm-legende">
<span><i class="sm-punkt sm-b-technik"></i> <?php echo abfahrt_t('LEGENDE.TECHNIK'); ?></span>
</div>
<div class="sm-knopfreihe">
<form action="index.php" method="post" style="margin:0;">
    <input data-role="none" type="hidden" name="download" value="xml_in">
    <input data-role="none" type="hidden" name="activetab" value="tab-loxone">
    <button data-role="none" class="sm-btn sm-b-technik" type="submit"><?php echo abfahrt_t('LOX.K_VORLAGE'); ?></button>
</form>
</div>
</div>

<div class="sm-step"><b><?php echo abfahrt_t('TEXT.SCHRITT_3_AUSLSEN_VON_ANSAGE_UND_P'); ?></b><br><br>
<b><?php echo abfahrt_t('TEXT.A_VIRTUELLEN_AUSGANG_ANLEGEN'); ?></b> <?php echo abfahrt_t('TEXT.MINISERVER'); ?> <i><?php echo abfahrt_t('TEXT.VIRTUELLE_AUSGNGE'); ?></i>):
<table class="sm-tbl">
<tr><th>Eigenschaft</th><th>Wert</th></tr>
<tr><td><?php echo abfahrt_t('TEXT.ADRESSE_VIRTUELLER_AUSGANG'); ?></td><td><span class="sm-mono">http://<?= $host ?></span></td></tr>
<tr><td><?php echo abfahrt_t('TEXT.BEFEHL_BEI_EIN_AUSGANG_BEFEHL'); ?></td><td><span class="sm-mono">/plugins/<?= e($plugindir) ?><?php echo abfahrt_t('TEXT.TERMIN_SAY_PHP_2'); ?>?token=<?= e($abf_token) ?></span></td></tr>
<tr><td><?php echo abfahrt_t('TEXT.AKTUELLES_MERKWORT'); ?></td><td><span class="sm-mono"><?= e($abf_token) ?></span></td></tr>
</table>
<div class="sm-alert sm-warn"><?php echo abfahrt_t('TEXT.MERKWORT_HINWEIS'); ?></div>
<b><?php echo abfahrt_t('TEXT.B_LOGIK_AUF_DER_PROGRAMMIERSEITE'); ?></b> <?php echo abfahrt_t('TEXT.MAN_VERGLEICHT'); ?> <span class="sm-mono">AB<?php echo abfahrt_t('TEXT.FAHRT'); ?>_IN</span> <?php echo abfahrt_t('TEXT.MIT_DER_EIGENEN_VORWARNZEIT_Z_B_5_'); ?>
<span class="sm-mono">OK=1</span><?php echo abfahrt_t('TEXT.DANN'); ?><br>
<?php echo abfahrt_t('TEXT.BEI'); ?> <span class="sm-mono"><?php echo abfahrt_t('TEXT.AUDIO_1'); ?></span> <?php echo abfahrt_t('TEXT.DEN_VIRTUELLEN_AUSGANG_SCHALTEN_DA'); ?><br>
<?php echo abfahrt_t('TEXT.TEXT_3'); ?> bei <span class="sm-mono"><?php echo abfahrt_t('TEXT.PUSH_1'); ?></span> <?php echo abfahrt_t('TEXT.ZUSTZLICH_EINEN_BENACHRICHTIGUNGS_'); ?><br><br>
<i><?php echo abfahrt_t('TEXT.HINWEIS'); ?></i> <?php echo abfahrt_t('TEXT.DIE_ANSAGE_SELBST_MACHT_DAS_PLUGIN'); ?> <span class="sm-mono"><?php echo abfahrt_t('TEXT.PUSH_2'); ?></span><?php echo abfahrt_t('TEXT.FLAG_GESTEUERT'); ?><br><br>
<b><?php echo abfahrt_t('TEXT.WICHTIGE_STOLPERFALLEN_BEIM_BENACH'); ?></b><br>
<?php echo abfahrt_t('TEXT.DER_BAUSTEIN_SENDET_NUR_BEI_EINEM'); ?> <b><?php echo abfahrt_t('TEXT.WECHSEL_VON_AUS_AUF_EIN'); ?></b> <?php echo abfahrt_t('TEXT.AN_SEINEM_EINGANG_STEHT_DER_AUSLSE'); ?><br>
&bull; <b><?php echo abfahrt_t('TEXT.NIEMALS_MEHRERE_QUELLEN_DIREKT_AUF'); ?></b> <?php echo abfahrt_t('TEXT.LIEGT_EINE_QUELLE_DAUERHAFT_AUF_EI'); ?><br>
<?php echo abfahrt_t('TEXT.FR_TESTS_EMPFIEHLT_SICH_EIN_EIGENE'); ?> <b><?php echo abfahrt_t('TEXT.EIGENEN'); ?></b> <?php echo abfahrt_t('TEXT.BENACHRICHTIGUNGS_BAUSTEIN_TEST_ER'); ?>
</div>

<div class="sm-step"><b><?php echo abfahrt_t('TEXT.SCHRITT_4_OPTIONAL_STATUSBAUSTEIN_'); ?></b><br><br>
<?php echo abfahrt_t('TEXT.DAMIT_MAN_IN_DER_LOXONE_APP_JEDERZ'); ?>
<b><?php echo abfahrt_t('TEXT.STATUS_BAUSTEIN'); ?></b> <?php echo abfahrt_t('TEXT.AN_BAUSTEIN_STATUS'); ?>
<table class="sm-tbl">
<tr><th><?php echo abfahrt_t('TEXT.EINGANG'); ?></th><th><?php echo abfahrt_t('TEXT.VERBINDEN_MIT'); ?></th></tr>
<tr><td><?php echo abfahrt_t('TEXT.EINGANG_1_V1'); ?></td><td><?php echo abfahrt_t('TEXT.HTTP_EINGANGS_BEFEHL'); ?> <span class="sm-mono">ABFAHRT_IN</span></td></tr>
<tr><td><?php echo abfahrt_t('TEXT.EINGANG_2_V2'); ?></td><td>HTTP-Eingangs-Befehl <span class="sm-mono">FAHRT</span></td></tr>
<tr><td><?php echo abfahrt_t('TEXT.EINGANG_3_V3'); ?></td><td>HTTP-Eingangs-Befehl <span class="sm-mono">OK</span></td></tr>
</table>
<?php echo abfahrt_t('TEXT.DANN_IM_BAUSTEIN_ZWEI_STATUSTEXTE_'); ?><br>
<?php echo abfahrt_t('TEXT.BEDINGUNG'); ?> <i><?php echo abfahrt_t('TEXT.EINGANG3_GLEICH_1'); ?></i> <?php echo abfahrt_t('TEXT.TEXT_4'); ?> <span class="sm-mono"><?php echo abfahrt_t('TEXT.NCHSTER_TERMIN_ABFAHRT_IN_V1_0_MIN'); ?></span><br>
<?php echo abfahrt_t('TEXT.STANDARDTEXT_KEINE_BEDINGUNG'); ?> <span class="sm-mono"><?php echo abfahrt_t('TEXT.KEIN_TERMIN_MIT_ORTSANGABE_IM_ZEIT'); ?></span><br>
<?php echo abfahrt_t('TEXT.DEN_BAUSTEIN_IN_DER_VISUALISIERUNG'); ?>
</div>

<div class="sm-step"><b><?php echo abfahrt_t('TEXT.SCHRITT_5_MUSS_ICH_MUSIC_SERVER_AU'); ?></b><br><br>
<b><?php echo abfahrt_t('TEXT.LOXONE_MUSIC_SERVER_KLASSISCH_UND_'); ?></b>
<?php echo abfahrt_t('TEXT.DIE_ANSAGE_VERSCHICKT_DAS'); ?> <i>Plugin</i> <?php echo abfahrt_t('TEXT.DIREKT_PER_HTTP_AN_DEN_AUDIO_SERVE'); ?> <b><?php echo abfahrt_t('TEXT.KEINE'); ?></b> <?php echo abfahrt_t('TEXT.VERBINDUNG_ZU_EINEM_MUSIK_AUDIO_BA'); ?> <span class="sm-mono">termin_say.php</span> <?php echo abfahrt_t('TEXT.AUFRUFT_DIE_MUSIKZONEN_BAUSTEINE_I'); ?><br><br>
<b><?php echo abfahrt_t('TEXT.ORIGINAL_LOXONE_AUDIOSERVER_JA_HIE'); ?></b> <?php echo abfahrt_t('TEXT.DER_AUDIOSERVER_HAT_KEINE_HTTP_TTS'); ?>
<table class="sm-tbl">
<tr><th><?php echo abfahrt_t('TEXT.BAUSTEIN'); ?></th><th><?php echo abfahrt_t('TEXT.VERKNPFUNG'); ?></th></tr>
<tr><td><?php echo abfahrt_t('TEXT.TEXTGENERATOR'); ?></td><td><?php echo abfahrt_t('TEXT.ERZEUGT_DEN_ANSAGETEXT_FESTER_TEXT'); ?></td></tr>
<tr><td><?php echo abfahrt_t('TEXT.AUDIOPLAYER_DER_GEWNSCHTEN_ZONE'); ?></td><td><?php echo abfahrt_t('TEXT.AUSGANG_DES_TEXTGENERATORS'); ?> <b><?php echo abfahrt_t('TEXT.TTS_EINGANG'); ?></b> <?php echo abfahrt_t('TEXT.DES_AUDIOPLAYER_BAUSTEINS_FR_MEHRE'); ?></td></tr>
</table>
<?php echo abfahrt_t('TEXT.IN_DIESEM_MODUS_LIEFERT_2'); ?> <span class="sm-mono">termin_say.php</span> <?php echo abfahrt_t('TEXT.NUR_DEN_TEXT'); ?><span class="sm-mono">TEXT=...</span><?php echo abfahrt_t('TEXT.UND_SPRICHT_NICHT_SELBST'); ?>
</div>

<div class="sm-step"><b><?php echo abfahrt_t('LOX.H_BAUSTEINE'); ?></b><br><br>
<?php echo abfahrt_t('LOX.BAUSTEINE_TEXT'); ?>
<table class="sm-tbl">
<tr><th>#</th><th><?php echo abfahrt_t('LOX.T_BAUSTEIN'); ?></th><th><?php echo abfahrt_t('LOX.T_NAME'); ?></th>
    <th><?php echo abfahrt_t('LOX.T_PARAMETER'); ?></th><th><?php echo abfahrt_t('LOX.T_VERBINDEN'); ?></th></tr>
<?php
/* Die Eingaenge kommen aus derselben Feldtabelle wie Statuszeile, MQTT-Themen
   und Importdatei. Eine von Hand gepflegte Liste liefe frueher oder spaeter
   auseinander - und dann steht in der Anleitung ein Thema, das es nicht gibt. */
$abf_i = 0;
foreach (abfahrt_felder() as $abf_n => $abf_d) {
    $abf_i++; ?>
<tr><td><?= $abf_i ?></td><td><?php echo abfahrt_t('LOX.B_EINGANG'); ?></td>
    <td><span class="sm-mono">Abfahrt <?= e($abf_n) ?></span></td>
    <td><?php echo abfahrt_t('LOX.B_THEMA'); ?> <span class="sm-mono"><?= e($abfcfg['mqtt_topic']) ?>/<?= e($abf_n) ?></span></td>
    <td>&mdash;</td></tr>
<?php } ?>
<?php for ($abf_b = 1; $abf_b <= 14; $abf_b++) { $abf_i++; ?>
<tr><td><?= $abf_i ?></td><td><?php echo abfahrt_t('BAUSTEIN.B' . $abf_b . '_TYP'); ?></td>
    <td><span class="sm-mono"><?php echo abfahrt_t('BAUSTEIN.B' . $abf_b . '_NAME'); ?></span></td>
    <td><?php echo abfahrt_t('BAUSTEIN.B' . $abf_b . '_PARAM'); ?></td>
    <td><?php echo abfahrt_t('BAUSTEIN.B' . $abf_b . '_VERB'); ?></td></tr>
<?php } ?>
</table>
<div class="sm-alert sm-info"><?php echo abfahrt_t('LOX.BAUSTEINE_ERLAEUTERUNG'); ?></div>
</div>

<div class="sm-step"><b><?php echo abfahrt_t('LOX.H_GEGENPROBE'); ?></b><br><br>
<?php echo abfahrt_t('LOX.GEGENPROBE'); ?></div>

</div>

<!-- ================= Reiter: Test ================= -->
<div class="sm-pane" id="tab-test">
<h2>Test</h2>

<h3 class="sm-h3"><?php echo abfahrt_t('TEST.H_SELBSTTEST'); ?></h3>
<div class="sm-small"><?php echo abfahrt_t('TEST.SELBSTTEST_TEXT'); ?></div>
<?php
$abf_pr = abfahrt_pruefungen($abfcfg);
$abf_schlecht = 0;
foreach ($abf_pr as $abf_z) { if ($abf_z[0] === 0) { $abf_schlecht++; } }
?>
<div class="sm-alert <?= $abf_schlecht ? 'sm-err' : 'sm-info' ?>">
<?= sprintf(abfahrt_t($abf_schlecht ? 'TEST.SELBSTTEST_FEHL' : 'TEST.SELBSTTEST_OK'),
            count($abf_pr) - $abf_schlecht, count($abf_pr)) ?>
</div>
<table class="sm-tbl">
<tr><th style="width:34px;"></th><th style="width:34%;"><?php echo abfahrt_t('TEST.T_FRAGE'); ?></th><th><?php echo abfahrt_t('TEST.T_ANTWORT'); ?></th></tr>
<?php foreach ($abf_pr as $abf_z) { ?>
<tr><td style="text-align:center;"><?= $abf_z[0] === 1 ? '<span class="sm-ok">&#10004;</span>'
        : ($abf_z[0] === 0 ? '<span class="sm-err">&#10008;</span>' : '<b>i</b>') ?></td>
    <td><?= $abf_z[1] ?></td><td><?= $abf_z[2] ?></td></tr>
<?php } ?>
</table>

<div class="sm-legende">
<span><i class="sm-punkt sm-b-technik"></i><?php echo abfahrt_t('LEGENDE.TECHNIK'); ?></span>
<span><i class="sm-punkt sm-b-aktion"></i><?php echo abfahrt_t('LEGENDE.AKTION'); ?></span>
</div>

<h3 class="sm-h3"><?php echo abfahrt_t('TEXT.TECHNISCHE_AUSKUNFT'); ?></h3>
<div class="sm-knopfreihe">
<a class="sm-btn sm-b-technik" style="text-decoration:none;" href="/plugins/<?= e($plugindir) ?>/termin.php?debug=1&amp;token=<?= e($abf_token) ?>" target="_blank"><?php echo abfahrt_t('TEXT.TERMIN_ABFRAGE_DEBUG_2'); ?></a>
</div>

<h3 class="sm-h3"><?php echo abfahrt_t('TEXT.LST_ETWAS_AUS'); ?></h3>
<div class="sm-knopfreihe">
<a class="sm-btn sm-b-aktion" style="text-decoration:none;" href="/plugins/<?= e($plugindir) ?>/termin_say.php?<?php echo abfahrt_t('TEXT.FORCE_1'); ?>&amp;token=<?= e($abf_token) ?>" target="_blank"><?php echo abfahrt_t('TEXT.ANSAGE_JETZT_AUSLSEN_2'); ?></a>
</div>


<div class="sm-small">
&bull; <b><?php echo abfahrt_t('TEXT.TERMIN_ABFRAGE_DEBUG'); ?></b> <?php echo abfahrt_t('TEXT.ZEIGT_WELCHER_TERMIN_GEFUNDEN_WURD'); ?><br>
&bull; <b><?php echo abfahrt_t('TEXT.ANSAGE_JETZT_AUSLSEN'); ?></b> <?php echo abfahrt_t('TEXT.SPRICHT_SOFORT_BER_DIE_LAUTSPRECHE'); ?> <span class="sm-mono">force=1</span><?php echo abfahrt_t('TEXT.IDEAL_ZUM_LAUTSPRECHER_TEST_EINE_P'); ?><br>
<?php echo abfahrt_t('TEXT.NEUE_TERMINE_IM_GOOGLE_KALENDER_ER'); ?> <b><?php echo abfahrt_t('TEXT.NEU_EINLESEN'); ?></b> <?php echo abfahrt_t('TEXT.KLICKEN'); ?>
</div>
</div>

<!-- ================= Reiter: <?php echo abfahrt_t('TEXT.LOGDATEI'); ?>en ================= -->
<div class="sm-pane" id="tab-log">
<h2>Logdatei</h2>
<div class="sm-small" style="margin-bottom:8px;"><?php echo abfahrt_t('TEXT.PROTOKOLLIERT_WERDEN_ERGEBNIS_AUML'); ?><br><?php echo abfahrt_t('TEXT.DATEI'); ?> <span class="sm-mono"><?= e($log_file) ?></span></div>
<?php if ($log_lines) { ?>
<div class="sm-log"><?= e(implode("\n", $log_lines)) ?></div>
<?php } else { ?>
<div class="sm-alert sm-info"><?php echo abfahrt_t('TEXT.NOCH_KEINE_LOG_EINTRGE_VORHANDEN'); ?></div>
<?php } ?>
<div class="sm-legende">
<span><i class="sm-punkt sm-b-aktion"></i> <?php echo abfahrt_t('LEGENDE.AKTION'); ?></span>
</div>
<form action="index.php" method="post" style="margin-top:10px;">
    <input data-role="none" type="hidden" name="clearlog" value="1">
    <input data-role="none" type="hidden" name="activetab" value="tab-log">
    <button data-role="none" class="sm-btn sm-b-aktion" type="submit"><?php echo abfahrt_t('TEXT.LOG_LEEREN'); ?></button>
</form>
<?php if (class_exists('LBWeb', false) && method_exists('LBWeb', 'loglist_html')) { echo LBWeb::loglist_html(); } ?>
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
    var tabs = document.querySelectorAll('.sm-tab');
    // Links: Klick abfangen, damit Eingaben in anderen Reitern erhalten bleiben.
    function activate(id) {
        tabs.forEach(function (t) { t.classList.toggle('sm-active', t.dataset.pane === id); });
        document.querySelectorAll('.sm-pane').forEach(function (p) { p.classList.toggle('sm-active', p.id === id); });
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
