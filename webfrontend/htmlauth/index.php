<?php
/**
 * Abfahrts-Assistent - Admin-Oberflaeche
 * Reiter: Einstellungen | MQTT | Einbindung in Loxone | Test | Logdateien
 */

error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);
// display_errors bleibt aus. In einer ausgelieferten Fassung zeigt es dem
// Bediener Dateipfade und Meldungen, mit denen er nichts anfangen kann - unter
// PHP 8 stand so eine Warnung mitten in der Oberflaeche. Wer sucht, schaut in
// das Fehlerprotokoll des Webservers.
ini_set('display_errors', '0');

/* Den LoxBerry-Wurzelordner ohne festen Systempfad bestimmen.
 *
 * DIESER BLOCK STEHT GANZ OBEN, und das war er bis 1.5.7 nicht: er stand rund
 * 260 Zeilen tiefer, wurde aber schon in der ersten Anweisung gebraucht.
 * Bedingte Funktionsdefinitionen zieht PHP nicht vor - ohne gesetztes
 * LBHOMEDIR endete die Seite deshalb mit "Call to undefined function
 * lb_wurzel_ermitteln()", also weiss. Verdeckt wurde das allein davon, dass
 * ?: die rechte Seite gar nicht auswertet, solange die Variable steht.
 *
 * Vom eigenen Ablageort aufwaerts, bis ein Verzeichnis gefunden ist, das
 * config/plugins UND webfrontend enthaelt. Das trifft die uebliche
 * Installation, eine an einem anderen Ort, und den Fall, dass das Plugin noch
 * als entpacktes Archiv daliegt (dann kommt ein Leerstring zurueck).
 */
if (!function_exists('lb_wurzel_ermitteln')) {
    function lb_wurzel_ermitteln()
    {
        $d = __DIR__;
        for ($i = 0; $i < 8; $i++) {
            if (is_dir($d . '/config/plugins') && is_dir($d . '/webfrontend')) {
                return $d;
            }
            $eltern = dirname($d);
            if ($eltern === $d) { break; }
            $d = $eltern;
        }
        return '';
    }
}

$lbhomedir = getenv('LBHOMEDIR') ?: lb_wurzel_ermitteln();
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

/* Bibliothek einbinden.
 *
 * Beide Kandidaten sind noetig, weil html/ und htmlauth/ auf dem installierten
 * LoxBerry in GETRENNTEN Baeumen liegen, im entpackten Archiv dagegen
 * nebeneinander.
 *
 * FEHLT SIE, WIRD ABGEBROCHEN - mit dem Satz, welche Datei wo erwartet wurde.
 * Bis 1.5.7 standen hier zwei Ersatzdefinitionen und daneben die Zusage, die
 * Oberflaeche bleibe "in jedem Fall bedienbar". Sie stimmte nicht: ohne die
 * Bibliothek fehlen auch abfahrt_config(), abfahrt_t(), abfahrt_vorlage() und
 * ein Dutzend weitere, und die Seite endete zweihundert Zeilen spaeter doch
 * mit einem fatalen Fehler - nur ohne Hinweis, woran es lag. */
$abf_kandidaten = [
    dirname(dirname(dirname(__DIR__))) . '/html/plugins/' . $plugindir . '/abfahrt_lib.php',
    dirname(__DIR__) . '/html/abfahrt_lib.php',
];
$abf_geladen = false;
foreach ($abf_kandidaten as $abf_cand) {
    if (is_file($abf_cand)) { require_once $abf_cand; $abf_geladen = true; break; }
}
if (!$abf_geladen || !function_exists('abfahrt_config')) {
    header('Content-Type: text/html; charset=utf-8');
    echo '<h2>Abfahrts-Assistent</h2>';
    echo '<p>Die Programmbibliothek <code>abfahrt_lib.php</code> wurde an keiner '
       . 'der beiden erwarteten Stellen gefunden. Bitte das Plugin neu '
       . 'installieren.</p><ul>';
    foreach ($abf_kandidaten as $abf_cand) {
        echo '<li><code>' . htmlspecialchars($abf_cand, ENT_QUOTES, 'UTF-8') . '</code></li>';
    }
    echo '</ul>';
    exit;
}

// Falls Konfiguration fehlt/leer, aber Sicherung existiert: wiederherstellen
if ((!is_file($config_file) || trim((string) @file_get_contents($config_file)) === '' || trim((string) @file_get_contents($config_file)) === '{}') && is_file($backup_file)) {
    @mkdir($config_dir, 0775, true);
    @copy($backup_file, $config_file);
}

$saved = false;
$save_error = '';
$refreshed_msg = '';
/* Beanstandungen, die das Speichern NICHT verhindern.
 *
 * Eine einzelne halb ausgefuellte Zeile im Ortsbuch darf nicht dazu fuehren,
 * dass Kalender, Zugangsdaten und Sperrzeiten ungespeichert bleiben - dann
 * tippt der Benutzer alles noch einmal, wegen einer Zeile, die er ohnehin
 * loeschen wollte. Gemeldet wird sie trotzdem: stillschweigend uebergehen
 * waere das andere Extrem. */
$abf_hinweise = array();

/* ---------- Schutz gegen seitenfremd ausgeloeste Formulare ----------
 *
 * htmlauth schuetzt gegen den unangemeldeten Aufruf - nicht dagegen, dass der
 * Browser eines angemeldeten Bedieners ein Formular abschickt, das auf einer
 * fremden Seite steht. Ohne Einmalmerkmal liessen sich so Ansage-Adresse,
 * API-Schluessel und Kalenderliste ueberschreiben und danach mit clearlog=1
 * die Spur loeschen.
 *
 * Das Merkmal wird aus dem Aktionstoken ABGELEITET und nicht gespeichert -
 * sonst haette die Konfiguration einen Schluessel mehr, den ein Speichern-
 * Handler vergessen kann. Genau dieser Fehler hat das Aktionstoken gekostet.
 * Eine fremde Seite kann den Wert nicht lesen (gleiche-Herkunft-Regel) und
 * ihn deshalb nicht mitschicken.
 */
function abf_formtoken(array $cfg)
{
    return hash_hmac('sha256', 'formular-v1', (string) $cfg['aktionstoken']);
}
function abf_formtoken_ok(array $cfg)
{
    $soll = abf_formtoken($cfg);
    $ist = isset($_POST['formtoken']) ? (string) $_POST['formtoken'] : '';
    if ($ist === '' || (string) $cfg['aktionstoken'] === '') { return false; }
    return hash_equals($soll, $ist);
}

$abf_post = ($_SERVER['REQUEST_METHOD'] === 'POST');
if ($abf_post && !abf_formtoken_ok(abfahrt_config())) {
    $save_error = abfahrt_t('MELDUNG.FORMTOKEN');
    $abf_post = false;   // kein Handler laeuft, nichts wird veraendert
}
/* Drei Stellen gehoeren immer zusammen: Reiterleiste, Bereich (sm-pane mit
   gleicher id) und diese Positivliste. Fehlt ein Name hier, ist der Reiter
   sichtbar und anklickbar - aber nach jedem Absenden springt die Seite
   zurueck auf Einstellungen. */
$abf_muster = '/^tab-(settings|mqtt|loxone|test|log)$/';
$active_tab = preg_match($abf_muster, (string) ($_POST['activetab'] ?? ''))
            ? (string) $_POST['activetab'] : 'tab-settings';
if (isset($_GET['form']) && !is_array($_GET['form'])
    && preg_match($abf_muster, 'tab-' . (string) $_GET['form'])) {
    $active_tab = 'tab-' . $_GET['form'];
}

// ---------- Loxone-Vorlage herunterladen ----------
if ($abf_post && isset($_POST['download'])) {
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
if ($abf_post && isset($_POST['ms4h_suchen'])) {
    $abf_ms4h = abfahrt_ms4h_suchen();
    $active_tab = 'tab-settings';
}

// ---------- Selbsttest des Merkworts (loest nichts aus) ----------
$abf_selftest = '';
if ($abf_post && isset($_POST['selftest'])) {
    $abf_cfg0 = abfahrt_config();
    $abf_url = abfahrt_lokal_url('/plugins/' . $plugindir . '/termin.php?selftest=1&token='
                                 . rawurlencode((string) $abf_cfg0['aktionstoken']));
    $abf_grund = '';
    $abf_antw = abfahrt_http_get($abf_url, 8, $abf_grund);
    $abf_selftest = ($abf_antw !== false)
        ? sprintf(abfahrt_t('MELDUNG.SELFTEST_OK'), trim((string) $abf_antw))
        : sprintf(abfahrt_t('MELDUNG.SELFTEST_FEHL'), $abf_grund !== '' ? $abf_grund : '?');
    $active_tab = 'tab-test';
}

// ---------- Koordinaten verwerfen ----------
if ($abf_post && isset($_POST['geo_verwerfen'])) {
    $refreshed_msg = sprintf(abfahrt_t('MELDUNG.GEO_VERWORFEN'), abfahrt_geo_verwerfen());
    $active_tab = 'tab-test';
}

// ---------- Merkwort neu wuerfeln ----------
/* Getrennter Zweig mit eigenem Knopf, und der Knopf steht orange unter
   "Loest etwas aus": das Merkwort steckt in den Adressen im Miniserver, ein
   neues macht sie alle ungueltig. Bis 1.5.7 geschah genau das ungewollt bei
   jedem Speichern - jetzt geschieht es nur noch auf ausdruecklichen Wunsch. */
if ($abf_post && isset($_POST['token_neu'])) {
    $abf_cfg0 = abfahrt_config();
    $abf_cfg0['aktionstoken'] = abfahrt_token_erzeugen();
    $abf_js0 = json_encode($abf_cfg0, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($abf_js0 !== false && @file_put_contents($config_file, $abf_js0) !== false) {
        @chmod($config_file, 0600);
        @copy($config_file, $backup_file);
        @chmod($backup_file, 0600);
        $refreshed_msg = abfahrt_t('MELDUNG.TOKEN_NEU');
    } else {
        $save_error = sprintf(abfahrt_t('MELDUNG.SPEICHERN_FEHL'), $config_file);
    }
    $active_tab = 'tab-test';
}

// ---------- Kalender durchsehen ----------
$abf_kaldiag = null;
if ($abf_post && isset($_POST['kal_diagnose'])) {
    $abf_kaldiag = abfahrt_kalender_diagnose(abfahrt_config());
    $active_tab = 'tab-test';
}

// ---------- Log leeren ----------
if ($abf_post && isset($_POST['clearlog'])) {
    @file_put_contents($log_file, '[' . date('Y-m-d H:i:s') . "] Log geleert (Admin-Oberflaeche)\n");
    $active_tab = 'tab-log';
}

// ---------- Speichern: nur die MQTT-Werte ----------
// Bewusst ein eigener Zweig. Der grosse Speichervorgang unten baut die
// Konfiguration von Grund auf neu; wuerde das MQTT-Formular dieselbe Taste
// druecken, waeren Kalender, Zugangsdaten und Sperrzeiten anschliessend leer.
if ($abf_post && isset($_POST['save_mqtt'])) {
    $abf_neu = abfahrt_config();
    $abf_neu['mqtt_ein'] = empty($_POST['mqtt_ein']) ? 0 : 1;
    $abf_neu['mqtt_vollsend_min'] = max(0, min(1440, (int) ($_POST['mqtt_vollsend_min'] ?? 0)));
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
            $save_error = sprintf(abfahrt_t('MELDUNG.SPEICHERN_FEHL'), $config_file);
        }
    }
    $active_tab = 'tab-mqtt';
}

// ---------- Speichern (auch bei "Kalender neu einlesen") ----------
/* Der MS4H-Suchknopf sitzt im selben Formular und schickt 'save' mit.
 * Er soll aber nur suchen, nicht speichern. */
if ($abf_post && (isset($_POST['save']) || isset($_POST['refresh']))
    && !isset($_POST['clearlog']) && !isset($_POST['ms4h_suchen'])) {
    // ACHTUNG: hier wird die Konfiguration KOMPLETT neu aufgebaut. Alles, was
    // nicht in diesem Formular steht, waere danach weg. Die MQTT-Werte stehen
    // in einem eigenen Reiter mit eigenem Formular - sie werden deshalb aus
    // dem alten Stand uebernommen.
    $abf_alt = abfahrt_config();
    $abfcfg = [];
    $abfcfg['mqtt_ein'] = (int) $abf_alt['mqtt_ein'];
    $abfcfg['mqtt_topic'] = (string) $abf_alt['mqtt_topic'];
    // Auch dieser Wert wohnt im MQTT-Reiter und hat dort seinen eigenen
    // Handler - er MUSS hier uebernommen werden, sonst loescht ihn jedes
    // Speichern der Einstellungen. Siehe die Begruendung beim Merkwort.
    $abfcfg['mqtt_vollsend_min'] = (int) $abf_alt['mqtt_vollsend_min'];
    /* DAS MERKWORT MUSS MIT UEBERNOMMEN WERDEN.
     *
     * Bis 1.5.7 stand es hier nicht. Folge: jedes Speichern loeschte es aus
     * der abfahrt.json, der naechste Seitenaufbau erzeugte weiter unten ein
     * neues - und der Virtuelle Ausgang im Miniserver, der noch das alte
     * trug, bekam ab da HTTP 403. Die Ansage verstummte ohne jede Meldung.
     * Dieselbe Stelle war am 13.08.2026 schon in drei anderen Linien falsch.
     * Wer diesen Handler kopiert: diese Zeile gehoert mit. */
    $abfcfg['aktionstoken'] = (string) $abf_alt['aktionstoken'];
    $abfcfg['calendars'] = [];
    /* Beanstandungen werden GESAMMELT, und die Schleife bricht nicht mehr ab.
     * Vorher warf ein Tippfehler in Kalender 2 die gesamte uebrige Eingabe
     * weg - der Benutzer tippte alles noch einmal. */
    $abf_cal_name = isset($_POST['cal_name']) && is_array($_POST['cal_name']) ? $_POST['cal_name'] : null;
    $abf_cal_url  = isset($_POST['cal_url'])  && is_array($_POST['cal_url'])  ? $_POST['cal_url']  : null;
    if (($abf_cal_name === null && isset($_POST['cal_name']))
        || ($abf_cal_url === null && isset($_POST['cal_url']))) {
        /* Kommt cal_name als Zeichenkette statt als Feld, griff frueher der
         * Zeichenketten-Index: aus "Familie" wurden die zehn Namen
         * "F","a","m","i","l","i","e","","","". Was nicht ins Muster passt,
         * wird abgewiesen und gemeldet, nicht zurechtgebogen. */
        $abf_hinweise[] = abfahrt_t('MELDUNG.KAL_FORM');
    }
    for ($i = 0; $i < 10; $i++) {
        $name = trim((string) ($abf_cal_name[$i] ?? ''));
        $url = trim((string) ($abf_cal_url[$i] ?? ''));
        if ($url !== '' && !preg_match('#^https?://#i', $url)) {
            $abf_hinweise[] = sprintf(abfahrt_t('MELDUNG.KAL_URL'), $i + 1);
            $url = trim((string) ($abf_alt['calendars'][$i]['url'] ?? ''));   // alten Wert behalten
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
    $abfcfg['route_departat'] = isset($_POST['route_departat']) ? 1 : 0;
    $abfcfg['quiet_push'] = isset($_POST['quiet_push']) ? 1 : 0;
    $abfcfg['ganztags_ein'] = isset($_POST['ganztags_ein']) ? 1 : 0;
    $abfcfg['ansage_vorlage'] = trim((string) ($_POST['ansage_vorlage'] ?? ''));
    $abf_gz = (string) ($_POST['ganztags_zeit'] ?? '');
    $abfcfg['ganztags_zeit'] = preg_match('/^([01]?\\d|2[0-3]):[0-5]\\d$/', $abf_gz) ? $abf_gz : '08:00';
    /* Ortsbuch. Eine Zeile zaehlt nur, wenn BEIDE Felder gefuellt sind - eine
     * Ortsangabe ohne Adresse waere eine Regel, die nichts einsetzt, und eine
     * Adresse ohne Ortsangabe eine, die nie greift. Beides waere stiller
     * Unsinn, deshalb wird es gemeldet statt weggelassen. */
    $abfcfg['ortsbuch'] = [];
    $abf_obm = isset($_POST['ob_muster']) && is_array($_POST['ob_muster']) ? $_POST['ob_muster'] : [];
    $abf_oba = isset($_POST['ob_adresse']) && is_array($_POST['ob_adresse']) ? $_POST['ob_adresse'] : [];
    for ($i = 0; $i < 10; $i++) {
        $m = trim((string) ($abf_obm[$i] ?? ''));
        $a = trim((string) ($abf_oba[$i] ?? ''));
        if ($m === '' && $a === '') { continue; }
        if ($m === '' || $a === '') {
            $abf_hinweise[] = sprintf(abfahrt_t('MELDUNG.ORTSBUCH_HALB'), $i + 1);
            continue;
        }
        $abfcfg['ortsbuch'][] = ['muster' => $m, 'adresse' => $a];
    }
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
            $save_error = sprintf(abfahrt_t('MELDUNG.SPEICHERN_FEHL'), $config_file);
        }
    }
    // ---------- Kalender neu einlesen ----------
    if ($save_error === '' && isset($_POST['refresh'])) {
        $ri = (int) $_POST['refresh'];
        $rurl = trim((string) ($abfcfg['calendars'][$ri]['url'] ?? ''));
        $rname = trim((string) ($abfcfg['calendars'][$ri]['name'] ?? '')) ?: ('#' . ($ri + 1));
        if ($rurl !== '') {
            @unlink(abfahrt_tmpdir() . '/ics_' . md5($rurl));
            // Frische Berechnung anstossen (aktualisiert auch die Status-Zeile oben)
            $ctx = stream_context_create(['http' => ['timeout' => 25]]);
            // Port aus der general.json, nicht hart 80 - siehe abfahrt_webport().
            $warm = function_exists('abfahrt_lokal_url')
                ? abfahrt_lokal_url('/plugins/' . $plugindir . '/termin.php')
                : 'http://127.0.0.1/plugins/' . $plugindir . '/termin.php';
            @file_get_contents($warm, false, $ctx);
            $refreshed_msg = sprintf(abfahrt_t('MELDUNG.KAL_NEU'), $rname);
        } else {
            $refreshed_msg = sprintf(abfahrt_t('MELDUNG.KAL_LEER'), $ri + 1);
        }
    }
}

/* ---------- Laden ----------
 *
 * Ueber abfahrt_config(), NICHT ueber ein eigenes Laden mit eigener
 * Vorgabeliste. Bis 1.5.4 stand hier eine zweite, aeltere Kopie der Vorgaben.
 * Als der MQTT-Reiter dazukam, wurden die Vorgaben mqtt_ein und mqtt_topic
 * nur in der Bibliothek nachgezogen - die Oberflaeche kannte sie nicht. Auf
 * jeder Anlage mit einer abfahrt.json aus der Zeit davor lief das Abonnement
 * dann auf "/#" statt "abfahrt/#", das Themenfeld war leer, und der Haken
 * "MQTT einschalten" stand auf aus, waehrend der Dienst tatsaechlich sendete.
 *
 * Vorgabewerte gehoeren an genau EINE Stelle. */
$abfcfg = abfahrt_config();
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

$status = @json_decode((string) @file_get_contents(abfahrt_tmpdir() . '/titel.json'), true) ?: [];
$log_lines = [];
if (is_file($log_file)) {
    $log_lines = array_slice(array_reverse(file($log_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: []), 0, 300);
}


// lb_wurzel_ermitteln() steht jetzt ganz oben - Begruendung dort.

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

/* Nachgetragene Definitionen (CSS-Luecken-Durchgang 13.08.2026):
   benutzt, aber nie definiert - wortgleich aus der Hausstandard-Vorlage
   bzw. der Referenzimplementierung uebernommen. */
.sm-warn { background: #fdf3e3; border: 1px solid #e0620d; }
</style>
<div class="sm-wrap">

<?php if ($saved) { ?><div class="sm-alert sm-ok"><b><?php echo abfahrt_t('TEXT.KONFIGURATION_GESPEICHERT'); ?></b> <?php echo abfahrt_t('TEXT.INKL_SICHERUNGSKOPIE_FR_UPDATES'); ?></div><?php } ?>
<?php if ($refreshed_msg !== '') { ?><div class="sm-alert sm-ok"><b><?= e($refreshed_msg) ?></b> <?php echo abfahrt_t('TEXT.ERGEBNIS_SIEHE_STATUS_ZEILE_UNTEN_'); ?></div><?php } ?>
<?php if ($save_error !== '') { ?><div class="sm-alert sm-err"><b><?php echo abfahrt_t('TEXT.FEHLER'); ?></b> <?= e($save_error) ?></div><?php } ?>
<?php if ($abf_hinweise) { ?><div class="sm-alert sm-warn"><?php
    foreach ($abf_hinweise as $abf_h) { echo e($abf_h) . '<br>'; } ?></div><?php } ?>

<?php if (!empty($status['titel'])) { ?>
<div class="sm-alert sm-info"><b><?php echo abfahrt_t('TEXT.LETZTER_BERECHNETER_TERMIN'); ?></b> <?= e($status['titel']) ?> (<?= e($status['kalender'] ?? '') ?>), <?= e($status['beginn'] ?? '') ?><?php echo abfahrt_t('TEXT.ORT'); ?> <?= e($status['ort'] ?? '') ?> <?php echo abfahrt_t('TEXT.FAHRZEIT'); ?> <?= isset($status['fahrt']) ? (int) ceil((float) $status['fahrt']) : '?' ?> <?php echo abfahrt_t('TEXT.MINUTEN_ABFAHRT_IN'); ?> <?= isset($status['abfahrt_in']) ? (int) $status['abfahrt_in'] : '?' ?> <?php echo abfahrt_t('TEXT.MINUTEN'); ?></div>
<?php } ?>

<!-- Reiterleiste: echte Links, JavaScript faengt den Klick ab. Warum beides:
     Der Link traegt die Adresse - jeder Reiter ist damit verlinkbar, die
     Zurueck-Taste tut das Erwartete, und faellt das Skript aus, bleibt die
     Seite bedienbar. -->
<?php /* Welcher Reiter offen ist, entscheidet der SERVER: sm-active steht schon
         im ausgelieferten HTML, an der Leiste UND am Bereich. Bis 1.5.7 tat
         das allein das JavaScript am Seitenende - und weil .sm-pane auf
         display:none steht, war die Seite ohne Skript vollstaendig leer,
         waehrend der Kommentar daneben das Gegenteil versprach.

         Die fuenf Zeilen stehen bewusst ausgeschrieben da und werden nicht
         aus einem Feld erzeugt: die Prueffolge liest den QUELLTEXT und sucht
         darin data-pane="tab-...". Aus einer printf-Schleife findet sie
         nichts mehr und meldet den Reiter als fehlend - eine Pruefung, die
         nichts mehr sieht, ist schlimmer als keine. */ ?>
<div class="sm-tabs">
    <a class="sm-tab<?= $active_tab === 'tab-settings' ? ' sm-active' : '' ?>" data-pane="tab-settings" href="index.php?form=settings"><?php echo abfahrt_t('REITER.EINSTELLUNGEN'); ?></a>
    <a class="sm-tab<?= $active_tab === 'tab-mqtt' ? ' sm-active' : '' ?>" data-pane="tab-mqtt"     href="index.php?form=mqtt"><?php echo abfahrt_t('REITER.MQTT'); ?></a>
    <a class="sm-tab<?= $active_tab === 'tab-loxone' ? ' sm-active' : '' ?>" data-pane="tab-loxone"   href="index.php?form=loxone"><?php echo abfahrt_t('REITER.LOXONE'); ?></a>
    <a class="sm-tab<?= $active_tab === 'tab-test' ? ' sm-active' : '' ?>" data-pane="tab-test"     href="index.php?form=test"><?php echo abfahrt_t('REITER.TEST'); ?></a>
    <a class="sm-tab<?= $active_tab === 'tab-log' ? ' sm-active' : '' ?>" data-pane="tab-log"      href="index.php?form=log"><?php echo abfahrt_t('REITER.LOG'); ?></a>
</div>

<!-- ================= Reiter: Einstellungen ================= -->
<div class="sm-pane<?= $active_tab === 'tab-settings' ? ' sm-active' : '' ?>" id="tab-settings">
<form action="index.php" method="post" autocomplete="off">
<input data-role="none" type="hidden" name="save" value="1">
<input data-role="none" type="hidden" name="activetab" value="tab-settings">
<input data-role="none" type="hidden" name="formtoken" value="<?= e(abf_formtoken($abfcfg)) ?>">
<?php /* Dieser Knopf ist der ERSTE Absendeknopf des Formulars und deshalb
         derjenige, den die Eingabetaste ausloest. Vorher war das der
         "Neu einlesen"-Knopf des ersten Kalenders - wer im Feld fuer den
         API-Schluessel Enter drueckte, speicherte und las nebenbei Kalender 1
         neu, unter Umgehung der Feldpruefungen (formnovalidate). */ ?>
<button data-role="none" type="submit" name="save" value="1" tabindex="-1"
        aria-hidden="true" style="position:absolute;left:-9999px;width:1px;height:1px;"></button>

<h2><?php echo abfahrt_t('TEXT.KALENDER_BIS_ZU_10_ICAL_URLS'); ?></h2>
<p class="sm-small"><?php echo abfahrt_t('TEXT.PRIVATE_ICAL_ADRESSE_Z_B_GOOGLE_KA'); ?> <b><?php echo abfahrt_t('TEXT.MIT_ORTSANGABE'); ?></b> <?php echo abfahrt_t('TEXT.IM_EINGESTELLTEN_ZEITFENSTER_AUCH_'); ?></p>
<?php for ($i = 0; $i < 10; $i++) { $cal = $abfcfg['calendars'][$i]; ?>
<div class="sm-cal">
    <input data-role="none" type="text" name="cal_name[]" value="<?= e($cal['name']) ?>" placeholder="<?php echo e(abfahrt_t('TEXT.P_KAL_NAME')); ?>">
    <input data-role="none" type="text" name="cal_url[]" value="<?= e($cal['url']) ?>" placeholder="https://calendar.google.com/calendar/ical/.../basic.ics">
    <button data-role="none" class="sm-rfbtn" type="submit" name="refresh" value="<?= $i ?>" formnovalidate title="<?php echo e(abfahrt_t('TEXT.T_NEU_EINLESEN')); ?>"><?php echo abfahrt_t('TEXT.NEU_EINLESEN_2'); ?></button>
</div>
<?php } ?>

<h2><?php echo abfahrt_t('TEXT.KARTENDIENST_VERKEHRSLAGE'); ?></h2>
<div class="sm-row">
    <div>
        <label><?php echo abfahrt_t('TEXT.DIENST'); ?></label>
        <select data-role="none" name="provider" id="provider">
            <option value="tomtom"<?= $abfcfg['provider'] === 'tomtom' ? ' selected' : '' ?>><?php echo abfahrt_t('TEXT.TOMTOM_KOSTENLOS_2500_ABFRAGEN_TAG'); ?></option>
            <option value="google"<?= $abfcfg['provider'] === 'google' ? ' selected' : '' ?>><?php echo abfahrt_t('TEXT.GOOGLE_MAPS_DIRECTIONS_API'); ?></option>
            <option value="here"<?= $abfcfg['provider'] === 'here' ? ' selected' : '' ?>><?php echo abfahrt_t('TEXT.HERE_ROUTING_V8'); ?></option>
        </select>
        <div class="sm-small"><?php echo abfahrt_t('TEXT.API_KEY'); ?> <a href="https://<?php echo abfahrt_t('TEXT.DEVELOPER_TOMTOM_COM'); ?>" target="_blank" rel="noopener noreferrer">developer.tomtom.com</a> | <a href="https://console.cloud.google.com" target="_blank" rel="noopener noreferrer"><?php echo abfahrt_t('TEXT.GOOGLE_CLOUD_CONSOLE'); ?></a> | <a href="https://<?php echo abfahrt_t('TEXT.PLATFORM_HERE_COM'); ?>" target="_blank" rel="noopener noreferrer">platform.here.com</a></div>
    </div>
    <div>
        <label><?php echo abfahrt_t('TEXT.API_KEY_2'); ?></label>
        <input data-role="none" type="password" name="api_key" value="<?= e($abfcfg['api_key']) ?>" placeholder="<?php echo e(abfahrt_t('TEXT.P_API_KEY')); ?>">
    </div>
</div>

<label><?php echo abfahrt_t('TEXT.ABFAHRTSADRESSE_ZUHAUSE'); ?></label>
<input data-role="none" type="text" name="home_address" value="<?= e($abfcfg['home_address']) ?>" placeholder="<?php echo e(abfahrt_t('TEXT.P_ADRESSE')); ?>">

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

<h2><?php echo abfahrt_t('TEXT.H_FAHRZEIT'); ?></h2>
<label style="display:inline-flex;align-items:center;gap:6px;">
    <input data-role="none" type="checkbox" name="route_departat" <?= !empty($abfcfg['route_departat']) ? 'checked' : '' ?>> <?php echo abfahrt_t('TEXT.L_DEPARTAT'); ?>
</label>
<div class="sm-small"><?php echo abfahrt_t('TEXT.DEPARTAT_HINWEIS'); ?></div>

<h2><?php echo abfahrt_t('TEXT.H_ORTSBUCH'); ?></h2>
<div class="sm-small" style="margin-bottom:6px;"><?php echo abfahrt_t('TEXT.ORTSBUCH_HINWEIS'); ?></div>
<table class="sm-tbl">
<tr><th style="width:34%"><?php echo abfahrt_t('TEXT.TH_ORTSANGABE'); ?></th><th><?php echo abfahrt_t('TEXT.TH_ADRESSE'); ?></th></tr>
<?php for ($abf_o = 0; $abf_o < 10; $abf_o++) {
    $abf_e = isset($abfcfg['ortsbuch'][$abf_o]) && is_array($abfcfg['ortsbuch'][$abf_o])
           ? $abfcfg['ortsbuch'][$abf_o] : ['muster' => '', 'adresse' => '']; ?>
<tr><td><input data-role="none" type="text" name="ob_muster[]" value="<?= e($abf_e['muster'] ?? '') ?>"></td>
    <td><input data-role="none" type="text" name="ob_adresse[]" value="<?= e($abf_e['adresse'] ?? '') ?>"></td></tr>
<?php } ?>
</table>

<h2><?php echo abfahrt_t('TEXT.H_GANZTAGS'); ?></h2>
<label style="display:inline-flex;align-items:center;gap:6px;margin-right:18px;">
    <input data-role="none" type="checkbox" name="ganztags_ein" <?= !empty($abfcfg['ganztags_ein']) ? 'checked' : '' ?>> <?php echo abfahrt_t('TEXT.L_GANZTAGS'); ?>
</label>
<label style="display:inline-flex;align-items:center;gap:6px;">
    <?php echo abfahrt_t('TEXT.L_GANZTAGS_ZEIT'); ?>
    <input data-role="none" type="time" name="ganztags_zeit" value="<?= e($abfcfg['ganztags_zeit']) ?>">
</label>
<div class="sm-small"><?php echo abfahrt_t('TEXT.GANZTAGS_HINWEIS'); ?></div>

<h2><?php echo abfahrt_t('TEXT.SPRACHAUSGABE'); ?></h2>
<div class="sm-row">
    <div>
        <label><?php echo abfahrt_t('TEXT.AUDIO_AUSGABE'); ?></label>
        <select data-role="none" name="tts_mode" id="tts_mode" onchange="abfTtsMode()">
            <option value="musicserver"<?= $abfcfg['tts']['mode'] === 'musicserver' ? ' selected' : '' ?>><?php echo abfahrt_t('TEXT.LOXONE_MUSIC_SERVER_KLASSISCH'); ?></option>
            <option value="ms4h"<?= $abfcfg['tts']['mode'] === 'ms4h' ? ' selected' : '' ?>><?php echo abfahrt_t('TEXT.AUDIOSERVER4HOME_MUSICSERVER4HOME'); ?></option>
            <option value="audioserver"<?= $abfcfg['tts']['mode'] === 'audioserver' ? ' selected' : '' ?>><?php echo abfahrt_t('TEXT.ORIGINAL_LOXONE_AUDIOSERVER_VIA_LO'); ?></option>
            <option value="custom"<?= $abfcfg['tts']['mode'] === 'custom' ? ' selected' : '' ?>><?php echo abfahrt_t('TEXT.EIGENE_URL_VORLAGE'); ?></option>
        </select>
    </div>
    <div>
        <label><?php echo abfahrt_t('TEXT.IP_DES_AUDIO_SERVERS'); ?></label>
        <input data-role="none" type="text" name="tts_ip" value="<?= e($abfcfg['tts']['ip']) ?>" placeholder="<?php echo e(abfahrt_t('TEXT.P_TTS_IP')); ?>">
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
<?php /* KEIN eigenes <form> mehr an dieser Stelle: es lag im Einstellungs-
         formular, und HTML verbietet Formulare im Formular. Der Browser warf
         es weg - das versteckte Feld "ms4h_suchen" gehoerte damit zum
         AEUSSEREN Formular und wurde bei JEDEM Speichern mitgeschickt, die
         Suche lief also jedes Mal mit. Jetzt traegt der Knopf den Namen
         selbst; er wird nur gesendet, wenn er gedrueckt wird. */ ?>
    <button data-role="none" class="sm-btn sm-b-lesen" type="submit" name="ms4h_suchen" value="1"><?php echo abfahrt_t('MS4H.K_SUCHEN'); ?></button>
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
        <input data-role="none" type="text" name="tts_zones" value="<?= e($abfcfg['tts']['zones']) ?>" placeholder="<?php echo e(abfahrt_t('TEXT.P_TTS_ZONEN')); ?>">
        <div class="sm-small"><?php echo abfahrt_t('TEXT.ZONENNUMMERN_MIT_KOMMA_Z_B'); ?> <span class="sm-mono">2,4,6</span><?php echo abfahrt_t('TEXT.DIE_LAUTSTRKE_KOMMT_AUS_DEM_FELD_D'); ?> <span class="sm-mono"><?php echo abfahrt_t('TEXT.ZONE_LAUTSTRKE'); ?></span> <?php echo abfahrt_t('TEXT.Z_B'); ?> <span class="sm-mono">2~25,4~40</span><?php echo abfahrt_t('TEXT.LEERZEICHEN_NACH_DEM_KOMMA_SIND_ER'); ?> <span class="sm-mono">2,4,6</span> <?php echo abfahrt_t('TEXT.L_UND'); ?> <span class="sm-mono">2, 4, 6</span> <?php echo abfahrt_t('TEXT.FUNKTIONIEREN_BEIDE'); ?></div>
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

<label><?php echo abfahrt_t('TEXT.L_ANSAGE_VORLAGE'); ?></label>
<input data-role="none" type="text" name="ansage_vorlage" value="<?= e($abfcfg['ansage_vorlage']) ?>">
<div class="sm-small"><?php echo abfahrt_t('TEXT.ANSAGE_HINWEIS'); ?></div>

<h2><?php echo abfahrt_t('TEXT.BENACHRICHTIGUNGEN'); ?></h2>
<div style="margin-bottom:10px;">
    <label style="display:inline-flex;align-items:center;gap:6px;margin-right:24px;">
        <input data-role="none" type="checkbox" name="notify_audio" <?= !empty($abfcfg['notify']['audio']) ? 'checked' : '' ?>> <?php echo abfahrt_t('TEXT.AUDIOAUSGABE_AKTIV'); ?>
    </label>
    <label style="display:inline-flex;align-items:center;gap:6px;">
        <input data-role="none" type="checkbox" name="notify_push" <?= !empty($abfcfg['notify']['push']) ? 'checked' : '' ?>> <?php echo abfahrt_t('TEXT.PUSH_NACHRICHT_AKTIV'); ?>
    </label>
    <div class="sm-small"><?php echo abfahrt_t('TEXT.BEIDES_AN_ANSAGE_PUSH_NUR_EINES_AN'); ?> <span class="sm-mono"><?php echo abfahrt_t('TEXT.AUDIO'); ?></span>/<span class="sm-mono"><?php echo abfahrt_t('TEXT.PUSH'); ?></span> <?php echo abfahrt_t('TEXT.AN_LOXONE_BERGEBEN_DEN_PUSH_VERSCH'); ?></div>
</div>

<h2><?php echo abfahrt_t('TEXT.SPERRZEITEN_FR_DIE_AUDIOAUSGABE'); ?></h2>
<div class="sm-small" style="margin-bottom:6px;"><?php echo abfahrt_t('TEXT.IN_DER_SPERRZEIT_BLEIBT_DER_LAUTSP'); ?></div>
<div style="margin-bottom:8px;">
    <label style="display:inline-flex;align-items:center;gap:6px;">
        <input data-role="none" type="checkbox" name="quiet_push" <?= !empty($abfcfg['quiet_push']) ? 'checked' : '' ?>> <?php echo abfahrt_t('TEXT.L_QUIET_PUSH'); ?>
    </label>
</div>
<table class="sm-qt">
<?php $days = abfahrt_quiet_labels();
foreach ($days as $d => $dayname) { ?>
<tr<?= $d === 8 ? ' style="height:34px;vertical-align:bottom;"' : '' ?>>
    <td style="width:28px;"><input data-role="none" type="checkbox" name="quiet_on[<?= $d ?>]" <?= !empty($abfcfg['quiet'][$d]['on']) ? 'checked' : '' ?>></td>
    <td style="width:105px;"><?= $d >= 8 ? '<b>' . $dayname . '</b>' : $dayname ?></td>
    <td style="width:100px;"><?php echo abfahrt_t('TEXT.SPERRZEIT_VON'); ?></td>
    <td style="width:100px;"><input data-role="none" type="time" name="quiet_from[<?= $d ?>]" value="<?= e($abfcfg['quiet'][$d]['from']) ?>"></td>
    <td style="width:34px;text-align:center;"><?php echo abfahrt_t('TEXT.L_BIS'); ?></td>
    <td style="width:100px;"><input data-role="none" type="time" name="quiet_to[<?= $d ?>]" value="<?= e($abfcfg['quiet'][$d]['to']) ?>"></td>
    <td><?php echo abfahrt_t('TEXT.L_UHR'); ?></td>
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
    echo abfahrt_t('TEXT.STATUS_KEIN_FERIENPLUGIN');
} else {
    $abf_namen = abfahrt_quiet_labels();
    $liste = [];
    if (!empty($abf_tag['urlaub'])) { $liste[] = $abf_namen[10]; }
    if (!empty($abf_tag['feiertag'])) { $liste[] = $abf_namen[8]; }
    if (!empty($abf_tag['ferien'])) { $liste[] = $abf_namen[9]; }
    echo abfahrt_t('TEXT.STATUS_HEUTE') . ' '
       . ($liste ? '<b>' . implode(' + ', $liste) . '</b>'
                 . (($abf_tag['name'] ?? '') !== '' ? ' (' . e($abf_tag['name']) . ')' : '')
                 : abfahrt_t('TEXT.STATUS_NORMALTAG'))
       . ' ' . abfahrt_t('TEXT.STATUS_GILT') . ' <b>'
       . ($abf_regel[0] ? e($abf_regel[1]) : abfahrt_t('TEXT.STATUS_KEINE_ZEILE')) . '</b>.';
} ?>
</div>

<button data-role="none" class="sm-btn" type="submit"><?php echo abfahrt_t('TEXT.SPEICHERN'); ?></button>
</form>
</div>

<!-- ================= Reiter: MQTT ================= -->
<div class="sm-pane<?= $active_tab === 'tab-mqtt' ? ' sm-active' : '' ?>" id="tab-mqtt">
<h2><?php echo abfahrt_t('MQTT.H_TITEL'); ?></h2>
<?php /* Der frueher hier eingeschobene Zweitpruefblock (abfahrt_hs_autostart) ist
   entfallen: abfahrt_mqtt_zustand() prueft seit 1.5.4 den richtigen Schluessel
   Gatewayautostart - zwei wortgleiche Warnungen uebereinander waren die Folge. */ ?>
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
    <input data-role="none" type="hidden" name="formtoken" value="<?= e(abf_formtoken($abfcfg)) ?>">
<input data-role="none" type="hidden" name="activetab" value="tab-mqtt">
<div class="sm-row"><label><input data-role="none" type="checkbox" name="mqtt_ein" value="1"<?= !empty($abfcfg['mqtt_ein']) ? ' checked' : '' ?>> <?php echo abfahrt_t('MQTT.L_EIN'); ?></label></div>
<div class="sm-row"><label><?php echo abfahrt_t('MQTT.L_TOPIC'); ?></label>
<input data-role="none" type="text" name="mqtt_topic" value="<?= e($abfcfg['mqtt_topic']) ?>" size="24"></div>
<div class="sm-row"><label><?php echo abfahrt_t('TEXT.L_MQTT_VOLLSEND'); ?></label>
<input data-role="none" type="number" name="mqtt_vollsend_min" value="<?= (int) $abfcfg['mqtt_vollsend_min'] ?>" min="0" max="1440"></div>
<div class="sm-small"><?php echo abfahrt_t('TEXT.MQTT_VOLLSEND_HINWEIS'); ?></div>
<div class="sm-legende"><span><i class="sm-punkt sm-b-aktion"></i> <?php echo abfahrt_t('LEGENDE.AKTION'); ?></span></div>
<div class="sm-knopfreihe">
<button data-role="none" class="sm-btn sm-b-aktion" type="submit"><?php echo abfahrt_t('TEXT.SPEICHERN'); ?></button>
</div>
</form>
</div>

<!-- ================= Reiter: Einbindung in Loxone ================= -->
<div class="sm-pane<?= $active_tab === 'tab-loxone' ? ' sm-active' : '' ?>" id="tab-loxone">
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
<tr><th><?php echo abfahrt_t('TEXT.TH_BEFEHLSERKENNUNG'); ?></th><th><?php echo abfahrt_t('TEXT.BEDEUTUNG'); ?></th><th><?php echo abfahrt_t('TEXT.BEISPIELWERT'); ?></th></tr>
<tr><td><span class="sm-mono">\i;<?php echo abfahrt_t('TEXT.ABFAHRT_IN_2'); ?>=\i\v</span></td><td><?php echo abfahrt_t('TEXT.MINUTEN_BIS_ZUR'); ?> <b><?php echo abfahrt_t('TEXT.EMPFOHLENEN_ABFAHRT'); ?></b> <?php echo abfahrt_t('TEXT.TERMINBEGINN_FAHRZEIT_ANKUNFTSRESE'); ?></td><td>12</td></tr>
<tr><td><span class="sm-mono"><?php echo abfahrt_t('TEXT.IMINSTART_I_V'); ?></span></td><td><?php echo abfahrt_t('TEXT.MINUTEN_BIS_ZUM'); ?> <b><?php echo abfahrt_t('TEXT.TERMINBEGINN'); ?></b>.</td><td>55</td></tr>
<tr><td><span class="sm-mono"><?php echo abfahrt_t('TEXT.IFAHRT_I_V'); ?></span></td><td><?php echo abfahrt_t('TEXT.AKTUELLE'); ?> <b><?php echo abfahrt_t('TEXT.FAHRZEIT_2'); ?></b> <?php echo abfahrt_t('TEXT.IN_MINUTEN_INKLUSIVE_STAU_VERKEHRS'); ?></td><td>43.7</td></tr>
<tr><td><span class="sm-mono"><?php echo abfahrt_t('TEXT.IOK_I_V'); ?></span></td><td><b>1</b> <?php echo abfahrt_t('TEXT.TERMIN_GEFUNDEN_UND_ROUTE_BERECHNE'); ?> <b>0</b> <?php echo abfahrt_t('TEXT.KEIN_TERMIN_MIT_ORT_IM_ZEITFENSTER'); ?></td><td>1</td></tr>
<tr><td><span class="sm-mono">\i;FEHLER=\i\v</span></td><td><?php echo abfahrt_t('TEXT.FEHLER_BEDEUTUNG'); ?></td><td>0</td></tr>
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
    <input data-role="none" type="hidden" name="formtoken" value="<?= e(abf_formtoken($abfcfg)) ?>">
    <input data-role="none" type="hidden" name="activetab" value="tab-loxone">
    <button data-role="none" class="sm-btn sm-b-technik" type="submit"><?php echo abfahrt_t('LOX.K_VORLAGE'); ?></button>
</form>
</div>
</div>

<div class="sm-step"><b><?php echo abfahrt_t('TEXT.SCHRITT_3_AUSLSEN_VON_ANSAGE_UND_P'); ?></b><br><br>
<b><?php echo abfahrt_t('TEXT.A_VIRTUELLEN_AUSGANG_ANLEGEN'); ?></b> <?php echo abfahrt_t('TEXT.MINISERVER'); ?> <i><?php echo abfahrt_t('TEXT.VIRTUELLE_AUSGNGE'); ?></i>):
<table class="sm-tbl">
<tr><th><?php echo abfahrt_t('TEXT.TH_EIGENSCHAFT'); ?></th><th><?php echo abfahrt_t('TEXT.TH_WERT'); ?></th></tr>
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
<tr><td><?php echo abfahrt_t('TEXT.EINGANG_2_V2'); ?></td><td><?php echo abfahrt_t('TEXT.HTTP_EINGANGS_BEFEHL'); ?> <span class="sm-mono">FAHRT</span></td></tr>
<tr><td><?php echo abfahrt_t('TEXT.EINGANG_3_V3'); ?></td><td><?php echo abfahrt_t('TEXT.HTTP_EINGANGS_BEFEHL'); ?> <span class="sm-mono">OK</span></td></tr>
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
<tr><td><?= $abf_i ?></td><td><?php echo abfahrt_tn('BAUSTEIN.B' . $abf_b . '_TYP'); ?></td>
    <td><span class="sm-mono"><?php echo abfahrt_tn('BAUSTEIN.B' . $abf_b . '_NAME'); ?></span></td>
    <td><?php echo abfahrt_tn('BAUSTEIN.B' . $abf_b . '_PARAM'); ?></td>
    <td><?php echo abfahrt_tn('BAUSTEIN.B' . $abf_b . '_VERB'); ?></td></tr>
<?php } ?>
</table>
<div class="sm-alert sm-info"><?php echo abfahrt_tn('LOX.BAUSTEINE_ERLAEUTERUNG'); ?></div>
</div>

<div class="sm-step"><b><?php echo abfahrt_t('LOX.H_GEGENPROBE'); ?></b><br><br>
<?php echo abfahrt_t('LOX.GEGENPROBE'); ?></div>

</div>

<!-- ================= Reiter: Test ================= -->
<div class="sm-pane<?= $active_tab === 'tab-test' ? ' sm-active' : '' ?>" id="tab-test">
<h2><?php echo abfahrt_t('TEXT.H_TEST'); ?></h2>

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

<?php /* Grün kam mit 1.6.0 dazu (Merkwort prüfen, Kalender durchsehen) - beides
         liest nur. Die Legende führt genau die Farben, die im Reiter
         vorkommen, und sie steht einmal oben statt über jeder Knopfreihe. */ ?>
<div class="sm-legende">
<span><i class="sm-punkt sm-b-lesen"></i> <?php echo abfahrt_t('LEGENDE.LESEN'); ?></span>
<span><i class="sm-punkt sm-b-technik"></i> <?php echo abfahrt_t('LEGENDE.TECHNIK'); ?></span>
<span><i class="sm-punkt sm-b-aktion"></i> <?php echo abfahrt_t('LEGENDE.AKTION'); ?></span>
</div>

<?php if ($abf_selftest !== '') { ?>
<div class="sm-alert sm-info"><?= e($abf_selftest) ?></div>
<?php } ?>

<h3 class="sm-h3"><?php echo abfahrt_t('TEST.H_GEO'); ?></h3>
<div class="sm-small"><?php echo abfahrt_t('TEST.GEO_TEXT'); ?></div>
<?php $abf_geo = abfahrt_geo_stand($abfcfg); ?>
<table class="sm-tbl">
<tr><th style="width:34px;"></th><th style="width:34%;"><?php echo abfahrt_t('TEST.F_GEO'); ?></th><th><?php echo abfahrt_t('TEST.T_ANTWORT'); ?></th></tr>
<tr><td style="text-align:center;"><?= $abf_geo['da'] ? '<span class="sm-ok">&#10004;</span>' : '<b>i</b>' ?></td>
    <td><?= e($abf_geo['adresse']) ?></td>
    <td><?php if ($abf_geo['da']) {
            printf(abfahrt_t('TEST.A_GEO_OK'), '<span class="sm-mono">' . e($abf_geo['koordinaten']) . '</span>',
                   $abf_geo['alter'] < 90 ? (int) $abf_geo['alter'] . ' s' : round($abf_geo['alter'] / 3600) . ' h');
            echo ' &mdash; <a href="' . e($abf_geo['karte']) . '" target="_blank" rel="noopener noreferrer">'
               . abfahrt_t('TEST.AUF_KARTE') . '</a>';
        } else {
            echo abfahrt_t('TEST.A_GEO_FEHLT');
        } ?></td></tr>
</table>

<h3 class="sm-h3"><?php echo abfahrt_t('TEST.H_KALENDER'); ?></h3>
<div class="sm-small"><?php echo abfahrt_t('TEST.KALENDER_TEXT'); ?></div>
<?php if (is_array($abf_kaldiag)) { ?>
<table class="sm-tbl">
<tr><th><?php echo abfahrt_t('TEST.T_KAL_NAME'); ?></th><th><?php echo abfahrt_t('TEST.T_KAL_QUELLE'); ?></th>
    <th><?php echo abfahrt_t('TEST.T_KAL_ALTER'); ?></th><th><?php echo abfahrt_t('TEST.T_KAL_TERMINE'); ?></th>
    <th><?php echo abfahrt_t('TEST.T_KAL_MITORT'); ?></th><th><?php echo abfahrt_t('TEST.T_KAL_NAECHSTER'); ?></th></tr>
<?php foreach ($abf_kaldiag as $abf_kd) { ?>
<tr><td><?= e($abf_kd['name'] !== '' ? $abf_kd['name'] : '#' . $abf_kd['nr']) ?></td>
    <td><span class="sm-mono"><?= e($abf_kd['gastgeber']) ?></span></td>
    <td><?= $abf_kd['alter'] === null ? '&mdash;' : ((int) round($abf_kd['alter'] / 60) . ' min') ?></td>
    <td><?= $abf_kd['grund'] !== '' ? '<span class="sm-err">' . e(abfahrt_t('TEST.KAL_NICHT_LADBAR')) . '</span>' : (int) $abf_kd['vevents'] ?></td>
    <td><?= (int) $abf_kd['mit_ort'] ?></td>
    <td><?php if ($abf_kd['naechste']) {
            $abf_n1 = $abf_kd['naechste'][0];
            echo e($abf_n1['zeit']) . ' &mdash; ' . e($abf_n1['titel']) . ' (' . e($abf_n1['ort']) . ')';
        } else { echo abfahrt_t('TEST.KAL_KEIN_TREFFER'); } ?></td></tr>
<?php } ?>
</table>
<?php } ?>

<h3 class="sm-h3"><?php echo abfahrt_t('TEXT.TECHNISCHE_AUSKUNFT'); ?></h3>
<div class="sm-knopfreihe">
<a class="sm-btn sm-b-technik" style="text-decoration:none;" href="/plugins/<?= e($plugindir) ?>/termin.php?debug=1&amp;token=<?= e($abf_token) ?>" target="_blank" rel="noopener noreferrer"><?php echo abfahrt_t('TEXT.TERMIN_ABFRAGE_DEBUG_2'); ?></a>
<form action="index.php" method="post" style="margin:0;">
    <input data-role="none" type="hidden" name="activetab" value="tab-test">
    <input data-role="none" type="hidden" name="formtoken" value="<?= e(abf_formtoken($abfcfg)) ?>">
    <button data-role="none" class="sm-btn sm-b-lesen" type="submit" name="selftest" value="1"><?php echo abfahrt_t('TEST.K_SELFTEST'); ?></button>
</form>
<form action="index.php" method="post" style="margin:0;">
    <input data-role="none" type="hidden" name="activetab" value="tab-test">
    <input data-role="none" type="hidden" name="formtoken" value="<?= e(abf_formtoken($abfcfg)) ?>">
    <button data-role="none" class="sm-btn sm-b-lesen" type="submit" name="kal_diagnose" value="1"><?php echo abfahrt_t('TEST.K_KALENDER'); ?></button>
</form>
<form action="index.php" method="post" style="margin:0;">
    <input data-role="none" type="hidden" name="activetab" value="tab-test">
    <input data-role="none" type="hidden" name="formtoken" value="<?= e(abf_formtoken($abfcfg)) ?>">
    <button data-role="none" class="sm-btn sm-b-technik" type="submit" name="geo_verwerfen" value="1"><?php echo abfahrt_t('TEST.K_GEO_VERWERFEN'); ?></button>
</form>
</div>

<h3 class="sm-h3"><?php echo abfahrt_t('TEXT.LST_ETWAS_AUS'); ?></h3>
<div class="sm-knopfreihe">
<a class="sm-btn sm-b-aktion" style="text-decoration:none;" href="/plugins/<?= e($plugindir) ?>/termin_say.php?<?php echo abfahrt_t('TEXT.FORCE_1'); ?>&amp;token=<?= e($abf_token) ?>" target="_blank" rel="noopener noreferrer"><?php echo abfahrt_t('TEXT.ANSAGE_JETZT_AUSLSEN_2'); ?></a>
<form action="index.php" method="post" style="margin:0;"
      onsubmit="return confirm('<?= e(strip_tags(abfahrt_t('TEST.TOKEN_NEU_WARNUNG'))) ?>');">
    <input data-role="none" type="hidden" name="activetab" value="tab-test">
    <input data-role="none" type="hidden" name="formtoken" value="<?= e(abf_formtoken($abfcfg)) ?>">
    <button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="token_neu" value="1"><?php echo abfahrt_t('TEST.K_TOKEN_NEU'); ?></button>
</form>
</div>
<div class="sm-alert sm-warn"><?php echo abfahrt_t('TEST.TOKEN_NEU_WARNUNG'); ?></div>


<div class="sm-small">
&bull; <b><?php echo abfahrt_t('TEXT.TERMIN_ABFRAGE_DEBUG'); ?></b> <?php echo abfahrt_t('TEXT.ZEIGT_WELCHER_TERMIN_GEFUNDEN_WURD'); ?><br>
&bull; <b><?php echo abfahrt_t('TEXT.ANSAGE_JETZT_AUSLSEN'); ?></b> <?php echo abfahrt_t('TEXT.SPRICHT_SOFORT_BER_DIE_LAUTSPRECHE'); ?> <span class="sm-mono">force=1</span><?php echo abfahrt_t('TEXT.IDEAL_ZUM_LAUTSPRECHER_TEST_EINE_P'); ?><br>
<?php echo abfahrt_t('TEXT.NEUE_TERMINE_IM_GOOGLE_KALENDER_ER'); ?> <b><?php echo abfahrt_t('TEXT.NEU_EINLESEN'); ?></b> <?php echo abfahrt_t('TEXT.KLICKEN'); ?>
</div>
</div>

<!-- ================= Reiter: <?php echo abfahrt_t('TEXT.LOGDATEI'); ?>en ================= -->
<div class="sm-pane<?= $active_tab === 'tab-log' ? ' sm-active' : '' ?>" id="tab-log">
<h2><?php echo abfahrt_t('TEXT.H_LOG'); ?></h2>
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
    <input data-role="none" type="hidden" name="formtoken" value="<?= e(abf_formtoken($abfcfg)) ?>">
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
    // preventDefault: ohne es folgt der Browser dem href, laedt die Seite neu
    // und wirft alle noch nicht gespeicherten Eingaben weg - genau das, was
    // der Kommentar oben zu verhindern versprach. Die Adresse in der Leiste
    // wird trotzdem nachgezogen, damit der Reiter verlinkbar bleibt.
    tabs.forEach(function (t) {
        t.addEventListener('click', function (ereignis) {
            ereignis.preventDefault();
            activate(t.dataset.pane);
            if (window.history && window.history.replaceState) {
                window.history.replaceState(null, '', t.getAttribute('href'));
            }
        });
    });
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
