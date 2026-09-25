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

/* ==================================================================
 * DIE HANDLER STEHEN VOR lbheader() - DAS IST BAUVORSCHRIFT
 *
 * Stand der Kopf davor, war er beim Aufruf von header() schon geschrieben -
 * "Cannot modify header information", und weder ein Download noch die
 * Umleitung nach einem Absenden haetten funktioniert.
 *
 * Reihenfolge: Bibliothek, Konfiguration, Wachposten, Reiterwahl,
 * ALLE Handler samt Downloads und Umleitung, dann lbheader(), dann HTML.
 * ================================================================== */
/* Bibliothek einbinden - nach dem EIGENEN Ablageort, nicht ueber eine
 * Kandidatenliste. Installiert liegt diese Datei unter
 * <Wurzel>/webfrontend/htmlauth/plugins/<ordner>/, die Bibliothek unter
 * <Wurzel>/webfrontend/html/plugins/<ordner>/ (getrennte Baeume); im
 * entpackten Archiv liegen htmlauth/ und html/ nebeneinander. Bis 1.6.12
 * standen beide in einer Liste, der erste Eintrag mit dirname(__DIR__)
 * dreimal hinauf - aus einem Archiv unter / hiess das
 * //html/plugins/htmlauth/abfahrt_lib.php, und eine fremde Datei dort wurde
 * eingebunden (in WSL gemessen, Pruefung-Abfahrtsassistent-1.6.13, Fall C4).
 * Dazu trug diese Datei eine eigene Wurzelsuche ohne general.json (Fall H3).
 * Wurzel, Ordner und alle Pfade kommen jetzt aus abfahrt_paths(). Bauart
 * ZendureSolarFlow 0.9.26. FEHLT DIE BIBLIOTHEK, WIRD ABGEBROCHEN - mit dem
 * Satz, welche Datei wo erwartet wurde. */
if (basename(dirname(__DIR__)) === 'plugins') {
    $abf_kandidaten = [dirname(dirname(dirname(__DIR__))) . '/html/plugins/' . basename(__DIR__) . '/abfahrt_lib.php'];
} else {
    $abf_kandidaten = [dirname(__DIR__) . '/html/abfahrt_lib.php'];
}
$abf_geladen = false;
foreach ($abf_kandidaten as $abf_cand) {
    if (is_file($abf_cand)) { require_once $abf_cand; $abf_geladen = true; break; }
}
if (!$abf_geladen || !function_exists('abfahrt_config')) {
    header('Content-Type: text/html; charset=utf-8');
    echo '<h2>Abfahrts-Assistent</h2>';
    echo '<p>Die Programmbibliothek <code>abfahrt_lib.php</code> wurde an der '
       . 'erwarteten Stelle nicht gefunden. Bitte das Plugin neu '
       . 'installieren.</p><ul>';
    foreach ($abf_kandidaten as $abf_cand) {
        echo '<li><code>' . htmlspecialchars($abf_cand, ENT_QUOTES, 'UTF-8') . '</code></li>';
    }
    echo '</ul>';
    exit;
}
$abf_p = abfahrt_paths();
$lbhomedir = $abf_p['lbhome'];
$plugindir = $abf_p['plugin'];
if ($lbhomedir !== '') {
    $sdk_system = $lbhomedir . '/libs/phplib/loxberry_system.php';
    $sdk_web = $lbhomedir . '/libs/phplib/loxberry_web.php';
    if (file_exists($sdk_system)) {
        require_once $sdk_system;
        require_once $sdk_web;
    }
}
/* Datenordner und Protokoll aus der Bibliothek - im Archivmodus deren
 * Ersatzpfade im Temp-Ordner, nie die der Anlage (Fall B7). */
$data_dir = $abf_p['data'];
$log_file = abfahrt_logfile(false);
$config_file = $abf_p['config'];

function e($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); }

/* ---------- Ergebnis eines Absendens: die Einmalmeldung ----------
 *
 * Jeder Handler endet seit 1.6.10 mit einer Umleitung (HTTP 303) auf die
 * Seite. Bis 1.6.9 wurde die Seite unmittelbar nach dem POST gerendert:
 * ein Neuladen wiederholte die Handlung - ein F5 nach "Merkwort neu
 * wuerfeln" wuerfelte ein zweites und machte die Adressen im Miniserver ein
 * zweites Mal ungueltig. (Regeln/04, "Jeder POST-Handler endet mit einer
 * Umleitung".)
 *
 * Das Ergebnis reist in einer Datei unter data/plugins/<ordner>/, Rechte
 * 0600, und wird NUR beim GET gelesen und dabei geloescht. Aelter als zwei
 * Minuten wird verworfen - sie erschiene sonst als Antwort auf eine
 * Handlung, die niemand ausgeloest hat. */
function abf_flash_datei($data_dir) {
    return rtrim($data_dir, '/') . '/einmalmeldung.json';
}
function abf_flash_schreiben($data_dir, array $inhalt) {
    if (!is_dir($data_dir)) { @mkdir($data_dir, 0775, true); }
    $inhalt['zeit'] = time();
    $js = json_encode($inhalt, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    return $js !== false && abfahrt_datei_geheim_schreiben(abf_flash_datei($data_dir), $js);
}
function abf_flash_lesen($data_dir) {
    $f = abf_flash_datei($data_dir);
    if (!is_file($f)) { return array(); }
    $d = json_decode((string) @file_get_contents($f), true);
    @unlink($f);
    if (!is_array($d) || !isset($d['zeit']) || time() - (int) $d['zeit'] > 120) { return array(); }
    return $d;
}
function abf_umleiten($data_dir, $tab, array $inhalt) {
    $inhalt['tab'] = $tab;
    abf_flash_schreiben($data_dir, $inhalt);
    $form = preg_replace('/^tab-/', '', $tab);
    header('Location: index.php?form=' . rawurlencode($form), true, 303);
    exit;
}

/* ---------- Schutz gegen seitenfremd ausgeloeste Formulare ----------
 *
 * htmlauth schuetzt gegen den unangemeldeten Aufruf - nicht dagegen, dass der
 * Browser eines angemeldeten Bedieners ein Formular abschickt, das auf einer
 * fremden Seite steht. Das Merkmal wird aus dem Aktionstoken ABGELEITET und
 * nicht gespeichert; eine fremde Seite kann den Wert nicht lesen. */
function abf_formtoken(array $cfg)
{
    return hash_hmac('sha256', 'formular-v1', (string) $cfg['aktionstoken']);
}
function abf_formtoken_ok(array $cfg)
{
    $soll = abf_formtoken($cfg);
    $ist = (isset($_POST['formtoken']) && is_string($_POST['formtoken'])) ? $_POST['formtoken'] : '';
    if ($ist === '' || (string) $cfg['aktionstoken'] === '') { return false; }
    return hash_equals($soll, $ist);
}

/* Drei Stellen gehoeren immer zusammen: Reiterleiste, Bereich (sm-seite mit
   gleicher id) und diese Positivliste. */
$abf_muster = '/^tab-(settings|mqtt|loxone|test|log)$/';
$abf_methode = isset($_SERVER['REQUEST_METHOD']) ? (string) $_SERVER['REQUEST_METHOD'] : 'GET';
$abf_post = ($abf_methode === 'POST');
$active_tab = ($abf_post && isset($_POST['activetab']) && is_string($_POST['activetab'])
               && preg_match($abf_muster, $_POST['activetab']))
            ? $_POST['activetab'] : 'tab-settings';
if (isset($_GET['form']) && is_string($_GET['form'])
    && preg_match($abf_muster, 'tab-' . $_GET['form'])) {
    $active_tab = 'tab-' . $_GET['form'];
}

/* ---------- Konfiguration pruefen und heilen ----------
 *
 * Entscheidet nach INHALT (Merkwort vorhanden?), nicht nach Form - siehe
 * abfahrt_config_heilen() in der Bibliothek. Bis 1.6.9 stand hier "fehlt,
 * leer oder {}": eine halb geschriebene Datei fiel durch, und weiter unten
 * wurde ueber ihr die Werkseinstellung samt neuem Merkwort geschrieben -
 * gemessen am 06.09.2026, auch die Zweitschrift war danach ueberschrieben. */
$abf_heilmeldungen = abfahrt_config_heilen();

/* Merkwort fuer die beiden ausloesenden Aufrufe. Wird beim ersten Oeffnen der
 * Oberflaeche erzeugt und danach NIE wieder angefasst - sonst wuerden einmal
 * eingetragene Loxone-Adressen ungueltig.
 *
 * Entschieden wird an der ROHEN Datei (Hausstandard: array_key_exists statt
 * empty), nicht an der vervollstaendigten Konfiguration: dort steht das
 * Merkwort immer, notfalls leer aus den Vorgaben. Und nie ueber einer Datei,
 * die sich nicht lesen liess - die hat abfahrt_config_heilen() beiseite
 * gelegt; liess sie sich nicht verschieben, wird hier nichts geschrieben. */
list($abf_roh, $abf_rohzustand) = abfahrt_config_roh();
$abf_hat_token = is_array($abf_roh) && array_key_exists('aktionstoken', $abf_roh)
                 && is_string($abf_roh['aktionstoken']) && $abf_roh['aktionstoken'] !== '';
if (!$abf_hat_token && $abf_rohzustand !== 'kaputt') {
    $abf_neu = abfahrt_config();
    $abf_neu['aktionstoken'] = abfahrt_token_erzeugen();
    if (abfahrt_config_speichern($abf_neu)) {
        abfahrt_log('Konfiguration: Merkwort neu angelegt (keines vorhanden).');
    }
}
$abfcfg = abfahrt_config();

/* ---------- Wachposten ---------- */
if ($abf_post && !abf_formtoken_ok($abfcfg)) {
    /* Doppelt gesichert: kein Handler darf mehr laufen, auch wenn die
     * Umleitung einmal nicht beendete. Die Zuweisung ist ausserdem die Form,
     * an der wachposten_pruefen.py die Wache erkennt. */
    $abf_post = false;
    abf_umleiten($data_dir, $active_tab, array('fehler' => abfahrt_t('MELDUNG.FORMTOKEN')));
}

// ---------- Loxone-Vorlage herunterladen (Download: keine Umleitung) ----------
if ($abf_post && isset($_POST['download'])) {
    $host = isset($_SERVER['HTTP_HOST']) ? (string) $_SERVER['HTTP_HOST'] : '';
    $host = $host !== '' ? preg_replace('/[^A-Za-z0-9\.\-:]/', '', $host) : '';
    $v = abfahrt_vorlage($host);
    header('Content-Type: application/x-download');
    header('Content-Disposition: attachment; filename="' . $v[0] . '"');
    header('Content-Length: ' . strlen($v[1]));
    echo $v[1];
    exit;
}

/* ---------------- Einstellungen sichern (Download) ----------------
 * Ausgegeben wird die VOLLE Konfiguration - samt Aktionstoken. Der lesbare
 * Kopf traegt _plugin, _stand und _hinweis (keine Fassungsnummer, Begruendung
 * bei abfahrt_sicherung_bauen()). */
if ($abf_post && isset($_POST['abfahrt_sichern'])) {
    $abfahrt_js = abfahrt_sicherung_bauen(abfahrt_config());
    if ($abfahrt_js !== false) {
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="abfahrtsassistent_einstellungen_'
               . date('Ymd_His') . '.json"');
        echo $abfahrt_js;
        exit;
    }
    abf_umleiten($data_dir, 'tab-settings', array('hinweise' => array(abfahrt_t('TEXT.SICH_SCHREIBFEHLER'))));
}

// ---------- Audio-Server suchen ----------
if ($abf_post && isset($_POST['ms4h_suchen'])) {
    abf_umleiten($data_dir, 'tab-settings', array('ms4h' => abfahrt_ms4h_suchen()));
}

// ---------- Selbsttest des Merkworts (loest nichts aus) ----------
/* Drei Ausgaenge (Regeln/04): die richtige Antwort, eine andere Antwort, gar
 * keine. "Konnte nicht messen" darf weder wie "in Ordnung" noch wie ein
 * Fehler aussehen. */
if ($abf_post && isset($_POST['selftest'])) {
    $abf_url = abfahrt_lokal_url('/plugins/' . $plugindir . '/termin.php?selftest=1&token='
                                 . rawurlencode((string) $abfcfg['aktionstoken']));
    $abf_grund = '';
    $abf_status = 0;
    $abf_antw = abfahrt_http_get($abf_url, 8, $abf_grund, $abf_status);
    if ($abf_antw !== false && strpos((string) $abf_antw, 'SELFTEST;OK=1') === 0) {
        $abf_st = array('stufe' => 1, 'text' => sprintf(abfahrt_t('MELDUNG.SELFTEST_OK'), trim((string) $abf_antw)));
    } elseif ($abf_status > 0) {
        $abf_st = array('stufe' => 0, 'text' => sprintf(abfahrt_t('MELDUNG.SELFTEST_FALSCH'), $abf_status,
                         trim((string) $abf_antw) !== '' ? trim((string) $abf_antw) : $abf_grund));
    } else {
        $abf_st = array('stufe' => -1, 'text' => sprintf(abfahrt_t('MELDUNG.SELFTEST_UNKLAR'),
                         $abf_grund !== '' ? $abf_grund : '?'));
    }
    abf_umleiten($data_dir, 'tab-test', array('selftest' => $abf_st));
}

// ---------- Koordinaten verwerfen ----------
if ($abf_post && isset($_POST['geo_verwerfen'])) {
    abf_umleiten($data_dir, 'tab-test', array('meldung' =>
        sprintf(abfahrt_t('MELDUNG.GEO_VERWORFEN'), abfahrt_geo_verwerfen())));
}

// ---------- Merkwort neu wuerfeln ----------
if ($abf_post && isset($_POST['token_neu'])) {
    $abf_cfg0 = abfahrt_config();
    $abf_cfg0['aktionstoken'] = abfahrt_token_erzeugen();
    if (abfahrt_config_speichern($abf_cfg0)) {
        abfahrt_log('Konfiguration: Merkwort auf Wunsch neu gewuerfelt.');
        abf_umleiten($data_dir, 'tab-test', array('meldung' => abfahrt_t('MELDUNG.TOKEN_NEU')));
    }
    abf_umleiten($data_dir, 'tab-test', array('fehler' => sprintf(abfahrt_t('MELDUNG.SPEICHERN_FEHL'), $config_file)));
}

// ---------- Kalender durchsehen ----------
if ($abf_post && isset($_POST['kal_diagnose'])) {
    abf_umleiten($data_dir, 'tab-test', array('kaldiag' => abfahrt_kalender_diagnose($abfcfg)));
}

// ---------- Log leeren ----------
if ($abf_post && isset($_POST['clearlog'])) {
    $abf_ok = @file_put_contents($log_file, '[' . date('Y-m-d H:i:s') . "] Log geleert (Admin-Oberflaeche)\n") !== false;
    abf_umleiten($data_dir, 'tab-log', $abf_ok ? array() : array('fehler' => abfahrt_t('MELDUNG.LOG_FEHL')));
}

/* Einen Wert aus dem Formular pruefen - mit denselben Grenzen wie beim
 * Zurueckspielen und beim Lesen (abfahrt_wert_pruefen()). Ein abgewiesener
 * Wert wird GEMELDET, und es bleibt der bisherige stehen. Bis 1.6.9 klemmte
 * der Handler still (max/min) und trug eigene Rueckfallwerte, die mit der
 * Vorgabenliste der Bibliothek uebereinstimmen mussten, ohne dass etwas das
 * pruefte. */
function abf_feld(array &$ziel, array $alt, $schluessel, $wert, array &$hinweise) {
    $grund = '';
    $gut = abfahrt_wert_pruefen($schluessel, $wert, $grund);
    if ($gut === null) {
        $hinweise[] = sprintf(abfahrt_t('MELDUNG.FELD_ABGEWIESEN'), abfahrt_t('FELDNAME.' . strtoupper($schluessel)), $grund);
        $ziel[$schluessel] = $alt[$schluessel];
        return false;
    }
    $ziel[$schluessel] = $gut;
    return true;
}

// ---------- Speichern: nur die MQTT-Werte ----------
// Bewusst ein eigener Zweig. Der grosse Speichervorgang unten baut die
// Konfiguration von Grund auf neu; wuerde das MQTT-Formular dieselbe Taste
// druecken, waeren Kalender, Zugangsdaten und Sperrzeiten anschliessend leer.
if ($abf_post && isset($_POST['save_mqtt'])) {
    $abf_alt = abfahrt_config();
    $abf_neu = $abf_alt;
    $abf_hw = array();
    $abf_neu['mqtt_ein'] = empty($_POST['mqtt_ein']) ? 0 : 1;
    abf_feld($abf_neu, $abf_alt, 'mqtt_vollsend_min', isset($_POST['mqtt_vollsend_min']) ? trim((string) $_POST['mqtt_vollsend_min']) : '', $abf_hw);
    $abf_t = isset($_POST['mqtt_topic']) && is_string($_POST['mqtt_topic']) ? trim($_POST['mqtt_topic']) : '';
    if ($abf_t === '') {
        $abf_hw[] = abfahrt_t('MQTT.FEHLER_TOPIC');
    } else {
        abf_feld($abf_neu, $abf_alt, 'mqtt_topic', $abf_t, $abf_hw);
    }
    if (!abfahrt_config_speichern($abf_neu)) {
        abf_umleiten($data_dir, 'tab-mqtt', array('fehler' => sprintf(abfahrt_t('MELDUNG.SPEICHERN_FEHL'), $config_file)));
    }
    abf_umleiten($data_dir, 'tab-mqtt', array('gespeichert' => 1, 'hinweise' => $abf_hw));
}

// ---------- Speichern (auch bei "Kalender neu einlesen") ----------
if ($abf_post && (isset($_POST['save']) || isset($_POST['refresh']))) {
    /* ACHTUNG: hier wird die Konfiguration aus dem Formular neu aufgebaut.
     * Grundlage ist der ALTE Stand - was nicht in diesem Formular steht
     * (MQTT-Werte, Merkwort), bleibt damit unberuehrt. Bis 1.5.7 fehlte das
     * Merkwort hier, und jedes Speichern erzeugte ein neues. */
    $abf_alt = abfahrt_config();
    $abfneu = $abf_alt;
    $abf_hw = array();

    /* Kalender. Kommt eine der beiden Listen nicht als Feld an, bleibt die
     * bestehende Kalenderliste unangetastet und es wird gemeldet. */
    $abf_cal_name = isset($_POST['cal_name']) && is_array($_POST['cal_name']) ? $_POST['cal_name'] : null;
    $abf_cal_url  = isset($_POST['cal_url'])  && is_array($_POST['cal_url'])  ? $_POST['cal_url']  : null;
    if ($abf_cal_name === null || $abf_cal_url === null) {
        if (isset($_POST['cal_name']) || isset($_POST['cal_url'])) {
            $abf_hw[] = abfahrt_t('MELDUNG.KAL_FORM');
        }
    } else {
        $abf_liste = array();
        for ($i = 0; $i < 10; $i++) {
            $name = trim((string) (isset($abf_cal_name[$i]) && is_string($abf_cal_name[$i]) ? $abf_cal_name[$i] : ''));
            $url  = trim((string) (isset($abf_cal_url[$i])  && is_string($abf_cal_url[$i])  ? $abf_cal_url[$i]  : ''));
            if ($url !== '' && !preg_match('#^https?://#i', $url)) {
                $abf_hw[] = sprintf(abfahrt_t('MELDUNG.KAL_URL'), $i + 1);
                $url = trim((string) ($abf_alt['calendars'][$i]['url'] ?? ''));   // alten Wert behalten
            }
            if ($name === '' && $url === '') { continue; }
            $abf_liste[] = array('name' => $name, 'url' => $url);
        }
        abf_feld($abfneu, $abf_alt, 'calendars', $abf_liste, $abf_hw);
    }

    $abf_post_text = function ($k) {
        return (isset($_POST[$k]) && is_string($_POST[$k])) ? trim($_POST[$k]) : '';
    };
    foreach (array('provider', 'api_key', 'home_address', 'buffer_min', 'arrival_min',
                   'lookahead_hours', 'ignore_locations', 'ansage_vorlage', 'ganztags_zeit') as $abf_k) {
        abf_feld($abfneu, $abf_alt, $abf_k, $abf_post_text($abf_k), $abf_hw);
    }
    foreach (array('route_departat', 'quiet_push', 'ganztags_ein') as $abf_k) {
        $abfneu[$abf_k] = isset($_POST[$abf_k]) ? 1 : 0;
    }
    $abfneu['notify'] = array(
        'audio' => isset($_POST['notify_audio']) ? 1 : 0,
        'push'  => isset($_POST['notify_push']) ? 1 : 0,
    );
    abf_feld($abfneu, $abf_alt, 'tts', array(
        'mode'     => $abf_post_text('tts_mode'),
        'ip'       => $abf_post_text('tts_ip'),
        'port'     => $abf_post_text('tts_port'),
        'zones'    => $abf_post_text('tts_zones'),
        'volume'   => $abf_post_text('tts_volume'),
        'lang'     => $abf_post_text('tts_lang'),
        'template' => $abf_post_text('tts_template'),
    ), $abf_hw);

    /* Ortsbuch. Eine Zeile zaehlt nur, wenn BEIDE Felder gefuellt sind; eine
     * halbe wird gemeldet statt weggelassen. */
    $abf_ob = array();
    $abf_obm = isset($_POST['ob_muster']) && is_array($_POST['ob_muster']) ? $_POST['ob_muster'] : array();
    $abf_oba = isset($_POST['ob_adresse']) && is_array($_POST['ob_adresse']) ? $_POST['ob_adresse'] : array();
    for ($i = 0; $i < 10; $i++) {
        $m = trim((string) (isset($abf_obm[$i]) && is_string($abf_obm[$i]) ? $abf_obm[$i] : ''));
        $a = trim((string) (isset($abf_oba[$i]) && is_string($abf_oba[$i]) ? $abf_oba[$i] : ''));
        if ($m === '' && $a === '') { continue; }
        if ($m === '' || $a === '') {
            $abf_hw[] = sprintf(abfahrt_t('MELDUNG.ORTSBUCH_HALB'), $i + 1);
            continue;
        }
        $abf_ob[] = array('muster' => $m, 'adresse' => $a);
    }
    abf_feld($abfneu, $abf_alt, 'ortsbuch', $abf_ob, $abf_hw);

    // Ein geleertes Zeitfeld schickt der Browser als leere Zeichenkette. Es
    // behaelt den bisherigen Wert dieses Tages - sonst wuerde ein einziges
    // leeres Feld die ganze Tabelle abweisen (1.6.9 nahm hier die Vorgabe).
    $abf_q = array();
    foreach (abfahrt_quiet_keys() as $d) {
        $abf_q[$d] = array('on' => isset($_POST['quiet_on'][$d]) ? 1 : 0);
        foreach (array('from' => 'quiet_from', 'to' => 'quiet_to') as $abf_k => $abf_f) {
            $abf_v = (isset($_POST[$abf_f][$d]) && is_string($_POST[$abf_f][$d])) ? trim($_POST[$abf_f][$d]) : '';
            $abf_q[$d][$abf_k] = $abf_v !== '' ? $abf_v : $abf_alt['quiet'][$d][$abf_k];
        }
    }
    abf_feld($abfneu, $abf_alt, 'quiet', $abf_q, $abf_hw);

    if (!abfahrt_config_speichern($abfneu)) {
        abf_umleiten($data_dir, 'tab-settings', array('fehler' => sprintf(abfahrt_t('MELDUNG.SPEICHERN_FEHL'), $config_file), 'hinweise' => $abf_hw));
    }
    $abf_erg = array('gespeichert' => 1, 'hinweise' => $abf_hw);

    // ---------- Kalender neu einlesen ----------
    if (isset($_POST['refresh']) && is_string($_POST['refresh'])) {
        $ri = (int) $_POST['refresh'];
        $abfcfg2 = abfahrt_config();
        $rurl = trim((string) ($abfcfg2['calendars'][$ri]['url'] ?? ''));
        $rname = trim((string) ($abfcfg2['calendars'][$ri]['name'] ?? '')) ?: ('#' . ($ri + 1));
        if ($rurl !== '') {
            @unlink(abfahrt_tmpdir() . '/ics_' . md5($rurl));
            $abf_g = '';
            $abf_ics = abfahrt_fetch_ics($rurl, $abf_g);
            $abf_erg['meldung'] = ($abf_ics !== false)
                ? sprintf(abfahrt_t('MELDUNG.KAL_NEU'), $rname)
                : sprintf(abfahrt_t('MELDUNG.KAL_NEU_FEHL'), $rname, $abf_g !== '' ? $abf_g : '?');
        } else {
            $abf_erg['meldung'] = sprintf(abfahrt_t('MELDUNG.KAL_LEER'), $ri + 1);
        }
    }
    abf_umleiten($data_dir, 'tab-settings', $abf_erg);
}

/* ---------------- Einstellungen zurueckspielen ----------------
 *
 * is_uploaded_file() ZUERST, dann die Groessengrenze: 64 kB (Hausmass,
 * Regeln/05). Eine Sicherung dieses Plugins ist wenige Kilobyte gross; bis
 * 1.6.9 stand hier das Vierfache. */
if ($abf_post && isset($_POST['abfahrt_zurueck'])) {
    $abf_hw = array();
    $abf_erg = array();
    if (!isset($_FILES['abfahrt_sicherung']) || !is_array($_FILES['abfahrt_sicherung'])
        || !isset($_FILES['abfahrt_sicherung']['tmp_name'])
        || !is_string($_FILES['abfahrt_sicherung']['tmp_name'])
        || !@is_uploaded_file($_FILES['abfahrt_sicherung']['tmp_name'])) {
        $abf_hw[] = abfahrt_t('TEXT.SICH_KEINE_DATEI');
    } elseif ((int) $_FILES['abfahrt_sicherung']['size'] > 65536) {
        $abf_hw[] = abfahrt_t('TEXT.SICH_ZU_GROSS');
    } else {
        list($abfahrt_neu, $abfahrt_mangel, $abfahrt_n) = abfahrt_sicherung_lesen(
            (string) @file_get_contents($_FILES['abfahrt_sicherung']['tmp_name']));
        if ($abfahrt_neu === null) {
            // ALLE Beanstandungen, nicht nur die erste - und geaendert wird nichts.
            $abf_hw[] = abfahrt_t('TEXT.SICH_ABGELEHNT') . ' ' . implode(' ', $abfahrt_mangel);
        } elseif (abfahrt_config_speichern($abfahrt_neu)) {
            $abf_erg['meldung'] = sprintf(abfahrt_t('TEXT.SICH_UEBERNOMMEN'), $abfahrt_n);
            foreach ($abfahrt_mangel as $abf_m) { $abf_hw[] = $abf_m; }
            /* DEN DIENST NACHZIEHEN (Hausstandard) - und sagen, was geschah.
             * Zwischenstand, MQTT-Merker und Kalenderkopien gehoeren zur alten
             * Konfiguration; sie werden verworfen, damit der Dienst beim
             * naechsten Minutentakt neu rechnet und alle Werte neu sendet.
             * Bis 1.6.9 lieferte der Miniserver bis zu fuenf Minuten lang
             * Werte der alten Konfiguration. */
            $abf_tmp = abfahrt_tmpdir(false);
            $abf_weg = 0;
            foreach (array_merge(array($abf_tmp . '/stand.json', $abf_tmp . '/mqtt_letzte.json',
                                       $abf_tmp . '/mqtt_voll.stamp', $abf_tmp . '/titel.json'),
                                 glob($abf_tmp . '/ics_*') ?: array()) as $abf_f) {
                if (is_file($abf_f) && @unlink($abf_f)) { $abf_weg++; }
            }
            abfahrt_log('Konfiguration: Sicherung zurueckgespielt (' . (int) $abfahrt_n . ' Werte), '
                        . $abf_weg . ' Zwischenstaende verworfen.');
            $abf_hw[] = sprintf(abfahrt_t('MELDUNG.DIENST_NACHGEZOGEN'), $abf_weg);
        } else {
            $abf_hw[] = abfahrt_t('TEXT.SICH_SCHREIBFEHLER');
        }
    }
    $abf_erg['hinweise'] = $abf_hw;
    abf_umleiten($data_dir, 'tab-settings', $abf_erg);
}

/* Ein POST, den kein Handler kannte (Knopf ohne Namen): trotzdem umleiten,
 * damit der Browser nichts erneut sendet. */
if ($abf_post) {
    abf_umleiten($data_dir, $active_tab, array());
}

/* ---------- GET: das Ergebnis des vorigen Absendens ---------- */
$abf_flash = abf_flash_lesen($data_dir);
if (!empty($abf_flash['tab']) && is_string($abf_flash['tab']) && preg_match($abf_muster, $abf_flash['tab'])
    && !isset($_GET['form'])) {
    $active_tab = $abf_flash['tab'];
}
$saved         = !empty($abf_flash['gespeichert']);
$save_error    = isset($abf_flash['fehler']) ? (string) $abf_flash['fehler'] : '';
$refreshed_msg = isset($abf_flash['meldung']) ? (string) $abf_flash['meldung'] : '';
$abf_hinweise  = isset($abf_flash['hinweise']) && is_array($abf_flash['hinweise']) ? $abf_flash['hinweise'] : array();
$abf_ms4h      = isset($abf_flash['ms4h']) && is_array($abf_flash['ms4h']) ? $abf_flash['ms4h'] : null;
$abf_selftest  = isset($abf_flash['selftest']) && is_array($abf_flash['selftest']) ? $abf_flash['selftest'] : null;
$abf_kaldiag   = isset($abf_flash['kaldiag']) && is_array($abf_flash['kaldiag']) ? $abf_flash['kaldiag'] : null;
foreach ($abf_heilmeldungen as $abf_m) { $abf_hinweise[] = $abf_m; }

$abfcfg = abfahrt_config();
while (count($abfcfg['calendars']) < 10) {
    $abfcfg['calendars'][] = ['name' => '', 'url' => ''];
}
$abf_token = (string) $abfcfg['aktionstoken'];

/* is_file() davor: titel.json fehlt, solange der Dienst noch nicht gerechnet
 * hat - das ist der Normalfall nach der Installation und kein Fehler. */
$abf_titeldatei = abfahrt_tmpdir(false) . '/titel.json';
$status = is_file($abf_titeldatei)
        ? (@json_decode((string) @file_get_contents($abf_titeldatei), true) ?: [])
        : [];
$log_lines = [];
if (is_file($log_file)) {
    $log_lines = array_slice(array_reverse(file($log_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: []), 0, 300);
}

/**
 * Ein Alter in Sekunden lesbar machen.
 */
function abf_alter_text($sek) {
    $sek = max(0, (int) $sek);
    if ($sek < 90)    { return $sek . ' s'; }
    if ($sek < 5400)  { return (int) round($sek / 60) . ' min'; }
    if ($sek < 172800) { return (int) round($sek / 3600) . ' h'; }
    return (int) round($sek / 86400) . ' d';
}

$use_frame = class_exists('LBWeb', false);
$host = e($_SERVER['HTTP_HOST'] ?? '<loxberry-ip>');
$abf_mz = abfahrt_mqtt_zustand();
/* Die Farbe des Abo-Hinweises folgt der Gateway-Fassung: nur unter V1 ist
 * ein fehlender Eintrag ein Fehler. Bis 1.6.9 stand auch der V2-Satz
 * "einzutragen ist hier nichts" in einem roten Kasten. */
$abf_abo_klasse = ((int) ($abf_mz['fassung'] ?? 0) === 1) ? 'sm-alert sm-err'
                : (((int) ($abf_mz['fassung'] ?? 0) >= 2) ? 'sm-hinweis' : 'sm-warnung');

if ($use_frame) {
    LBWeb::lbheader('Abfahrts-Assistent', 'https://wiki.loxberry.de/', 'help.html');
}

?>
<style>
/* Hausstandard: eigener Behaelter, kein Schattenwurf, Reiter im Fluss */
.sm-wrap { max-width: 980px; margin: 0 auto; font-family: -apple-system, 'Segoe UI', Roboto, sans-serif; color: #333; }
.sm-wrap, .sm-wrap *, .sm-tabs, .sm-tabs * { text-shadow: none !important; }
.sm-wrap h2 { color: #6dac20; margin: 24px 0 10px; font-size: 1.15em; border-bottom: 2px solid #e0e0e0; padding-bottom: 6px; }
.sm-wrap h3 { color: #4f7d17; font-size: 1.0em; font-weight: 700; margin: 16px 0 2px; }
.sm-tabs { display: flex; gap: 4px; margin: 14px 0 0; border-bottom: 2px solid #6dac20; flex-wrap: wrap; }
.sm-tab { background: #eee; border: 1px solid #ccc; border-bottom: 0; border-radius: 8px 8px 0 0;
          padding: 9px 18px; font-size: 0.95em; color: #444 !important; text-decoration: none; display: inline-block; }
.sm-tab.sm-active { background: #6dac20; color: #fff !important; border-color: #6dac20; font-weight: 600; }
.sm-feld { margin: 14px 0; }
.sm-feld > label { display: block; font-weight: 600; font-size: 0.9em; color: #555; margin: 0 0 4px; }
/* Bedienelemente werden von jQuery Mobile umgebaut und bekommen einen eigenen
   Behaelter. Begrenzt man das Feld selbst, bleibt der Behaelter breit - man
   sieht ein schmales Feld in einem breiten weissen Kasten. Und beim
   Auswahlfeld liegt das unsichtbare <select> ueber dem Knopf und faengt die
   Klicks ab; wer es gestaltet, schiebt es weg. Deshalb wird ausschliesslich
   der Behaelter begrenzt. */
.sm-feld .ui-input-text, .sm-feld .ui-select, .sm-feld .ui-textinput { max-width: 520px; }
.sm-feld .ui-input-text input, .sm-feld .ui-input-text textarea { font-size: 0.95em; }
.sm-hilfe { font-size: 0.85em; color: #555; margin: 4px 0 0; max-width: 640px; }
.sm-step { border: 1px solid #ddd; border-left: 4px solid #6dac20; background: #fafafa;
    border-radius: 6px; padding: 12px 14px; margin: 12px 0; font-size: 0.92em; line-height: 1.5; }
.sm-tbl { border-collapse: collapse; width: 100%; margin: 8px 0; font-size: 0.9em; }
.sm-tbl th, .sm-tbl td { border: 1px solid #ccc; padding: 5px 7px; text-align: left; vertical-align: top; }
.sm-tbl th { background: #eef3e6; font-weight: 600; }
.sm-mono { font-family: Consolas, "Courier New", monospace; background: #f0f0f0;
    padding: 1px 4px; border-radius: 3px; font-size: 0.94em; word-break: break-all; }
.sm-pre { background: #f4f4f4; border: 1px solid #ccc; padding: 10px; font-size: 0.85em;
    overflow: auto; margin: 8px 0; }
.sm-knopfreihe { display: flex; flex-wrap: wrap; gap: 10px; margin: 10px 0 4px; align-items: stretch; }
/* LoxBerry bringt jQuery Mobile mit. Das formatiert JEDES <button> mit eigenem
   Hintergrund UND eigenen Hover-Regeln. Ohne !important steht weisse Schrift
   auf hellgrauem Grund - und beim Ueberfahren weiss auf weiss. Die
   Hover-Farben unten sind kein Feinschliff, sondern Pflicht: fehlen sie, kommt
   der Hover-Zustand vom Rahmen und ist unlesbar. */
.sm-wrap .sm-knopfreihe .sm-btn, .sm-wrap a.sm-btn, .sm-wrap button.sm-btn {
    flex: 0 0 auto; min-width: 250px; text-align: center; display: inline-flex;
    align-items: center; justify-content: center; line-height: 1.25;
    padding: 10px 14px !important; border-radius: 6px !important;
    color: #fff !important; text-decoration: none !important; font-size: 0.92em;
    border: 0 !important; cursor: pointer; font-weight: 600 !important;
    text-shadow: none !important; box-shadow: none !important;
    opacity: 1 !important; margin: 0 !important; width: auto !important; }
/* Statuskacheln - bewusst ein anderer Name als sm-knopfreihe.
   Beide zu verwechseln hat am 26.07.2026 die Statusanzeige zerlegt. */
.sm-kacheln { display: flex; flex-wrap: wrap; gap: 10px; margin: 10px 0; }
.sm-kachel { border: 1px solid #ddd; border-radius: 10px; padding: 10px 14px; min-width: 130px; }
.sm-kachel b { display: block; font-size: 1.35em; color: #33691e; }

.sm-legende { display: flex; flex-wrap: wrap; gap: 14px; margin: 10px 0 2px; font-size: 0.86em; color: #555; }
.sm-legende span { display: inline-flex; align-items: center; gap: 6px; }
.sm-punkt { width: 13px; height: 13px; border-radius: 3px; display: inline-block; }
.sm-wrap .sm-btn.sm-b-lesen   { background: #6dac20 !important; }
.sm-wrap .sm-btn.sm-b-technik { background: #546e7a !important; }
.sm-wrap .sm-btn.sm-b-aktion  { background: #e0620d !important; }
/* Eigene Hover- und Fokusfarben je Gruppe - sonst uebernimmt der Rahmen. */
.sm-wrap .sm-btn.sm-b-lesen:hover,   .sm-wrap .sm-btn.sm-b-lesen:focus   { background: #5c9219 !important; color: #fff !important; }
.sm-wrap .sm-btn.sm-b-technik:hover, .sm-wrap .sm-btn.sm-b-technik:focus { background: #435962 !important; color: #fff !important; }
.sm-wrap .sm-btn.sm-b-aktion:hover,  .sm-wrap .sm-btn.sm-b-aktion:focus  { background: #b84f0a !important; color: #fff !important; }
.sm-punkt.sm-b-lesen   { background: #6dac20; }
.sm-punkt.sm-b-technik { background: #546e7a; }
.sm-punkt.sm-b-aktion  { background: #e0620d; }
/* Reiterinhalte: nur der aktive ist sichtbar.
   Ohne diese zwei Zeilen stehen alle fuenf Reiter untereinander.
   MIT ihnen und OHNE serverseitiges sm-active ist die Seite dagegen
   vollstaendig leer, sobald das Skript nicht laeuft - genau das war bis
   07.08.2026 der Fall. Die Klasse gehoert deshalb schon ins ausgelieferte
   HTML, siehe die Reiterleiste weiter unten. */
.sm-seite { display: none; padding-top: 4px; }
.sm-seite.sm-active { display: block; }
.sm-hinweis { border: 1px solid #cfe3b0; background: #f2f8ea; border-radius: 6px;
    padding: 10px 12px; margin: 12px 0; font-size: 0.9em; }
.sm-warnung { border: 1px solid #f0c9a0; background: #fdf4ec; border-radius: 6px;
    padding: 10px 12px; margin: 12px 0; font-size: 0.9em; }
.sm-an  { color: #1a7f1a; font-weight: 700; }
.sm-aus { color: #b00000; font-weight: 700; }
/* Jede Tabelle mit mehr als sechs Spalten oder mit Eingabefeldern kommt in
   sm-breit (Hausvorlage). */
.sm-breit { overflow-x: auto; -webkit-overflow-scrolling: touch; margin: 10px 0; }
.sm-breit .sm-tbl { margin: 0; min-width: 760px; }
/* Ein Auswahlfeld muss man als Auswahlfeld erkennen (Hausvorlage).
   Die Raute im SVG wird als %23 geschrieben: eine rohe Raute beendet in
   einer CSS-Adresse den Wert. */
.sm-wrap select {
    appearance: none; -webkit-appearance: none; -moz-appearance: none;
    background-image: url("data:image/svg+xml;charset=UTF-8,%3Csvg xmlns='http://www.w3.org/2000/svg' width='14' height='9' viewBox='0 0 14 9'%3E%3Cpath d='M1 1l6 6 6-6' fill='none' stroke='%234f7d17' stroke-width='2'/%3E%3C/svg%3E");
    background-repeat: no-repeat; background-position: right 10px center;
    padding-right: 32px; cursor: pointer; }
.sm-tbl select { padding-right: 28px; background-position: right 7px center; }

/* ---- Ab hier Ergaenzungen dieses Plugins, nicht Teil der Hausvorlage ---- */
.sm-wrap label { display: block; font-weight: 600; font-size: 0.88em; color: #555; margin: 10px 0 4px; }
.sm-wrap input[type=text], .sm-wrap input[type=password], .sm-wrap input[type=number], .sm-wrap select, .sm-wrap textarea {
  width: 100%; padding: 8px 10px; border: 1px solid #ccc; border-radius: 6px; font-size: 0.95em; box-sizing: border-box; }
.sm-wrap input[type=time] { width: 92px !important; min-width: 92px; padding: 4px 6px !important; border: 1px solid #ccc; border-radius: 6px; font-size: 0.95em; display: inline-block; box-sizing: border-box; }
.sm-wrap input[type=checkbox] { width: 17px; height: 17px; margin: 0; vertical-align: middle; }
.sm-row { display: flex; gap: 12px; }
.sm-row > div { flex: 1; }
.sm-cal { display: flex; gap: 8px; margin-bottom: 6px; align-items: center; }
.sm-cal input:first-child { flex: 0 0 170px !important; width: 170px !important; }
.sm-cal input:nth-child(2) { flex: 1 1 auto !important; width: auto !important; min-width: 200px; }
/* "Neu einlesen" speichert das Formular und laedt neu - er loest also etwas
   aus und traegt die Aktionsfarbe. Bis 1.6.9 stand er in einer vierten Farbe
   (#607d8b), die keine Legende nannte. */
.sm-wrap .sm-cal .sm-rfbtn { flex: 0 0 auto !important; width: auto !important; background: #e0620d !important;
    color: #fff !important; border: 0 !important; border-radius: 6px !important; padding: 7px 10px !important;
    font-size: 0.82em; cursor: pointer; white-space: nowrap; box-shadow: none !important; margin: 0 !important; }
.sm-wrap .sm-cal .sm-rfbtn:hover, .sm-wrap .sm-cal .sm-rfbtn:focus { background: #b84f0a !important; color: #fff !important; }
.sm-wrap .sm-speichern { margin-top: 18px !important; }
.sm-alert { border-radius: 8px; padding: 10px 14px; margin: 12px 0; }
.sm-ok { background: #e8f5e9; border: 1px solid #a5d6a7; }
.sm-err { background: #ffebee; border: 1px solid #ef9a9a; }
.sm-info { background: #e3f2fd; border: 1px solid #90caf9; font-size: 0.9em; }
.sm-warn { background: #fdf3e3; border: 1px solid #e0620d; }
.sm-small { font-size: 0.82em; color: #666; margin-top: 3px; }
.sm-qt { border-collapse: collapse; }
.sm-qt td { vertical-align: middle; padding: 3px 0; }
.sm-log { background: #1e1e1e; color: #d4d4d4; font-family: ui-monospace, monospace; font-size: 0.82em; padding: 12px; border-radius: 8px; max-height: 480px; overflow: auto; white-space: pre-wrap; }
.sm-h3 { color: #4f7d17; font-size: 1.0em; font-weight: 700; margin: 16px 0 2px; }
</style>
<div class="sm-wrap">

<?php if ($saved) { ?><div class="sm-alert sm-ok"><b><?= e(abfahrt_t('SEITE.GESPEICHERT')) ?></b></div><?php } ?>
<?php if ($refreshed_msg !== '') { ?><div class="sm-alert sm-ok"><b><?= e($refreshed_msg) ?></b></div><?php } ?>
<?php if ($save_error !== '') { ?><div class="sm-alert sm-err"><b><?= e(abfahrt_t('SEITE.FEHLER')) ?></b> <?= e($save_error) ?></div><?php } ?>
<?php if ($abf_hinweise) { ?><div class="sm-alert sm-warn"><?php
    foreach ($abf_hinweise as $abf_h) { echo e($abf_h) . '<br>'; } ?></div><?php } ?>

<?php if (!empty($status['titel'])) { ?>
<div class="sm-alert sm-info"><?= sprintf(abfahrt_t('SEITE.LETZTER_TERMIN'),
    e($status['titel']), e($status['kalender'] ?? ''), e($status['beginn'] ?? ''), e($status['ort'] ?? ''),
    isset($status['fahrt']) ? (int) ceil((float) $status['fahrt']) : 0,
    isset($status['abfahrt_in']) ? (int) $status['abfahrt_in'] : 0) ?></div>
<?php } ?>

<?php /* Welcher Reiter offen ist, entscheidet der SERVER: sm-active steht schon
         im ausgelieferten HTML, an der Leiste UND am Bereich. Die fuenf Zeilen
         stehen bewusst ausgeschrieben da - die Pruefwerkzeuge lesen den
         QUELLTEXT. */ ?>
<div class="sm-tabs">
    <a class="sm-tab<?= $active_tab === 'tab-settings' ? ' sm-active' : '' ?>" data-ziel="tab-settings" href="index.php?form=settings"><?= e(abfahrt_t('REITER.EINSTELLUNGEN')) ?></a>
    <a class="sm-tab<?= $active_tab === 'tab-mqtt' ? ' sm-active' : '' ?>" data-ziel="tab-mqtt" href="index.php?form=mqtt">MQTT</a>
    <a class="sm-tab<?= $active_tab === 'tab-loxone' ? ' sm-active' : '' ?>" data-ziel="tab-loxone" href="index.php?form=loxone"><?= e(abfahrt_t('REITER.LOXONE')) ?></a>
    <a class="sm-tab<?= $active_tab === 'tab-test' ? ' sm-active' : '' ?>" data-ziel="tab-test" href="index.php?form=test"><?= e(abfahrt_t('REITER.TEST')) ?></a>
    <a class="sm-tab<?= $active_tab === 'tab-log' ? ' sm-active' : '' ?>" data-ziel="tab-log" href="index.php?form=log"><?= e(abfahrt_t('REITER.LOG')) ?></a>
</div>

<!-- ================= Reiter: Einstellungen ================= -->
<div class="sm-seite<?= $active_tab === 'tab-settings' ? ' sm-active' : '' ?>" id="tab-settings">
<?php /* Eine Legende oben im Reiter, mit genau den Farben, die hier als Knopf
         vorkommen (Regeln/04). Bis 1.6.9 standen zwei Legenden mitten im
         Reiter, und keine nannte alle Farben. */ ?>
<div class="sm-legende">
<span><i class="sm-punkt sm-b-lesen"></i> <?= e(abfahrt_t('LEGENDE.LESEN')) ?></span>
<span><i class="sm-punkt sm-b-aktion"></i> <?= e(abfahrt_t('LEGENDE.AKTION')) ?></span>
</div>
<form action="index.php" method="post" autocomplete="off">
<input data-role="none" type="hidden" name="save" value="1">
<input data-role="none" type="hidden" name="activetab" value="tab-settings">
<input data-role="none" type="hidden" name="formtoken" value="<?= e(abf_formtoken($abfcfg)) ?>">
<?php /* Dieser Knopf ist der ERSTE Absendeknopf des Formulars und deshalb
         derjenige, den die Eingabetaste ausloest. */ ?>
<button data-role="none" type="submit" name="save" value="1" tabindex="-1"
        aria-hidden="true" style="position:absolute;left:-9999px;width:1px;height:1px;"></button>

<h2><?= e(abfahrt_t('SEITE.H_KALENDER')) ?></h2>
<p class="sm-small"><?= abfahrt_t('SEITE.KALENDER_HINWEIS') ?></p>
<?php for ($i = 0; $i < 10; $i++) { $cal = $abfcfg['calendars'][$i]; ?>
<div class="sm-cal">
    <input data-role="none" type="text" name="cal_name[]" value="<?= e($cal['name']) ?>" placeholder="<?= e(abfahrt_t('SEITE.P_KAL_NAME')) ?>">
    <input data-role="none" type="text" name="cal_url[]" value="<?= e($cal['url']) ?>" placeholder="https://calendar.google.com/calendar/ical/.../basic.ics">
    <button data-role="none" class="sm-rfbtn" type="submit" name="refresh" value="<?= $i ?>" formnovalidate title="<?= e(abfahrt_t('SEITE.T_NEU_EINLESEN')) ?>"><?= e(abfahrt_t('SEITE.K_NEU_EINLESEN')) ?></button>
</div>
<?php } ?>

<h2><?= e(abfahrt_t('SEITE.H_KARTENDIENST')) ?></h2>
<div class="sm-row">
    <div>
        <label><?= e(abfahrt_t('SEITE.L_DIENST')) ?></label>
        <select data-role="none" name="provider" id="provider">
            <option value="tomtom"<?= $abfcfg['provider'] === 'tomtom' ? ' selected' : '' ?>><?= e(abfahrt_t('SEITE.O_TOMTOM')) ?></option>
            <option value="google"<?= $abfcfg['provider'] === 'google' ? ' selected' : '' ?>>Google Maps (Directions API)</option>
            <option value="here"<?= $abfcfg['provider'] === 'here' ? ' selected' : '' ?>>HERE (Routing v8)</option>
        </select>
        <div class="sm-small"><?= e(abfahrt_t('SEITE.SCHLUESSEL_BEI')) ?>
            <a href="https://developer.tomtom.com" target="_blank" rel="noopener noreferrer">developer.tomtom.com</a> |
            <a href="https://console.cloud.google.com" target="_blank" rel="noopener noreferrer">Google Cloud Console</a> |
            <a href="https://platform.here.com" target="_blank" rel="noopener noreferrer">platform.here.com</a></div>
    </div>
    <div>
        <label><?= e(abfahrt_t('SEITE.L_API_KEY')) ?></label>
        <input data-role="none" type="password" name="api_key" value="<?= e($abfcfg['api_key']) ?>" placeholder="<?= e(abfahrt_t('SEITE.P_API_KEY')) ?>">
    </div>
</div>

<label><?= e(abfahrt_t('SEITE.L_ADRESSE')) ?></label>
<input data-role="none" type="text" name="home_address" value="<?= e($abfcfg['home_address']) ?>" placeholder="<?= e(abfahrt_t('SEITE.P_ADRESSE')) ?>">

<label><?= e(abfahrt_t('SEITE.L_IGNORIERT')) ?></label>
<input data-role="none" type="text" name="ignore_locations" value="<?= e($abfcfg['ignore_locations']) ?>" placeholder="online, teams, zoom, ...">
<div class="sm-small"><?= abfahrt_t('SEITE.IGNORIERT_HINWEIS') ?></div>

<h2><?= e(abfahrt_t('SEITE.H_ZEITEN')) ?></h2>
<div class="sm-row">
    <div>
        <label><?= e(abfahrt_t('SEITE.L_ANKUNFT')) ?></label>
        <input data-role="none" type="number" name="arrival_min" value="<?= (int) $abfcfg['arrival_min'] ?>" min="0" max="120">
        <div class="sm-small"><?= e(abfahrt_t('SEITE.ANKUNFT_HINWEIS')) ?></div>
    </div>
    <div>
        <label><?= e(abfahrt_t('SEITE.L_PUFFER')) ?></label>
        <input data-role="none" type="number" name="buffer_min" value="<?= (int) $abfcfg['buffer_min'] ?>" min="0" max="120">
        <div class="sm-small"><?= e(abfahrt_t('SEITE.PUFFER_HINWEIS')) ?></div>
    </div>
    <div>
        <label><?= e(abfahrt_t('SEITE.L_ZEITFENSTER')) ?></label>
        <input data-role="none" type="number" name="lookahead_hours" value="<?= (int) $abfcfg['lookahead_hours'] ?>" min="1" max="48">
        <div class="sm-small"><?= e(abfahrt_t('SEITE.ZEITFENSTER_HINWEIS')) ?></div>
    </div>
</div>

<h2><?= e(abfahrt_t('TEXT.H_FAHRZEIT')) ?></h2>
<label style="display:inline-flex;align-items:center;gap:6px;">
    <input data-role="none" type="checkbox" name="route_departat" <?= !empty($abfcfg['route_departat']) ? 'checked' : '' ?>> <?= abfahrt_t('TEXT.L_DEPARTAT') ?>
</label>
<div class="sm-small"><?= abfahrt_t('TEXT.DEPARTAT_HINWEIS') ?></div>

<h2><?= e(abfahrt_t('TEXT.H_ORTSBUCH')) ?></h2>
<div class="sm-small" style="margin-bottom:6px;"><?= abfahrt_t('TEXT.ORTSBUCH_HINWEIS') ?></div>
<div class="sm-breit">
<table class="sm-tbl">
<tr><th style="width:34%"><?= e(abfahrt_t('TEXT.TH_ORTSANGABE')) ?></th><th><?= e(abfahrt_t('TEXT.TH_ADRESSE')) ?></th></tr>
<?php for ($abf_o = 0; $abf_o < 10; $abf_o++) {
    $abf_e = isset($abfcfg['ortsbuch'][$abf_o]) && is_array($abfcfg['ortsbuch'][$abf_o])
           ? $abfcfg['ortsbuch'][$abf_o] : ['muster' => '', 'adresse' => '']; ?>
<tr><td><input data-role="none" type="text" name="ob_muster[]" value="<?= e($abf_e['muster'] ?? '') ?>"></td>
    <td><input data-role="none" type="text" name="ob_adresse[]" value="<?= e($abf_e['adresse'] ?? '') ?>"></td></tr>
<?php } ?>
</table>
</div>

<h2><?= e(abfahrt_t('TEXT.H_GANZTAGS')) ?></h2>
<label style="display:inline-flex;align-items:center;gap:6px;margin-right:18px;">
    <input data-role="none" type="checkbox" name="ganztags_ein" <?= !empty($abfcfg['ganztags_ein']) ? 'checked' : '' ?>> <?= e(abfahrt_t('TEXT.L_GANZTAGS')) ?>
</label>
<label style="display:inline-flex;align-items:center;gap:6px;">
    <?= e(abfahrt_t('TEXT.L_GANZTAGS_ZEIT')) ?>
    <input data-role="none" type="time" name="ganztags_zeit" value="<?= e($abfcfg['ganztags_zeit']) ?>">
</label>
<div class="sm-small"><?= abfahrt_t('TEXT.GANZTAGS_HINWEIS') ?></div>

<h2><?= e(abfahrt_t('SEITE.H_SPRACHAUSGABE')) ?></h2>
<div class="sm-row">
    <div>
        <label><?= e(abfahrt_t('SEITE.L_AUDIO')) ?></label>
        <select data-role="none" name="tts_mode" id="tts_mode" onchange="abfTtsMode()">
            <option value="musicserver"<?= $abfcfg['tts']['mode'] === 'musicserver' ? ' selected' : '' ?>><?= e(abfahrt_t('SEITE.O_MUSICSERVER')) ?></option>
            <option value="ms4h"<?= $abfcfg['tts']['mode'] === 'ms4h' ? ' selected' : '' ?>>Audioserver4Home / MusicServer4Home</option>
            <option value="audioserver"<?= $abfcfg['tts']['mode'] === 'audioserver' ? ' selected' : '' ?>><?= e(abfahrt_t('SEITE.O_AUDIOSERVER')) ?></option>
            <option value="custom"<?= $abfcfg['tts']['mode'] === 'custom' ? ' selected' : '' ?>><?= e(abfahrt_t('SEITE.O_EIGENE')) ?></option>
        </select>
    </div>
    <div>
        <label><?= e(abfahrt_t('SEITE.L_TTS_IP')) ?></label>
        <input data-role="none" type="text" name="tts_ip" value="<?= e($abfcfg['tts']['ip']) ?>" placeholder="<?= e(abfahrt_t('SEITE.P_TTS_IP')) ?>">
<?php if ($abf_ms4h !== null) { ?>
  <?php if (!empty($abf_ms4h['gefunden'])) { ?>
    <div class="sm-alert sm-info"><?= sprintf(abfahrt_t('MS4H.GEFUNDEN'), (int) $abf_ms4h['port'], e($abf_ms4h['quelle'])) ?></div>
  <?php } else { ?>
    <div class="sm-warnung"><?= abfahrt_t('MS4H.NICHTS') ?></div>
  <?php } ?>
<?php } ?>
<div class="sm-knopfreihe">
<?php /* Der Suchknopf traegt seinen Namen selbst und sitzt im
         Einstellungsformular: ein eigenes Formular an dieser Stelle waere
         ein Formular im Formular, und das verwirft der Browser. */ ?>
    <button data-role="none" class="sm-btn sm-b-lesen" type="submit" name="ms4h_suchen" value="1"><?= e(abfahrt_t('MS4H.K_SUCHEN')) ?></button>
</div>
<div class="sm-small"><?= abfahrt_t('MS4H.HINWEIS') ?></div>
    </div>
    <div>
        <label><?= e(abfahrt_t('SEITE.L_PORT')) ?></label>
        <input data-role="none" type="number" name="tts_port" value="<?= (int) $abfcfg['tts']['port'] ?>" min="1" max="65535">
    </div>
</div>
<div class="sm-row">
    <div>
        <label><?= e(abfahrt_t('SEITE.L_ZONEN')) ?></label>
        <input data-role="none" type="text" name="tts_zones" value="<?= e($abfcfg['tts']['zones']) ?>" placeholder="<?= e(abfahrt_t('SEITE.P_TTS_ZONEN')) ?>">
        <div class="sm-small"><?= abfahrt_t('SEITE.ZONEN_HINWEIS') ?></div>
    </div>
    <div>
        <label><?= e(abfahrt_t('SEITE.L_LAUTSTAERKE')) ?></label>
        <input data-role="none" type="number" name="tts_volume" value="<?= (int) $abfcfg['tts']['volume'] ?>" min="1" max="100">
    </div>
    <div>
        <label><?= e(abfahrt_t('SEITE.L_SPRACHE')) ?></label>
        <input data-role="none" type="text" name="tts_lang" value="<?= e($abfcfg['tts']['lang']) ?>" maxlength="2">
    </div>
</div>
<div id="tts_template_row">
    <label><?= e(abfahrt_t('SEITE.L_VORLAGE_URL')) ?></label>
    <textarea data-role="none" name="tts_template" id="tts_template" rows="2" placeholder="http://{ip}:{port}/tts?text={text}&amp;zone={zones}&amp;vol={vol}"><?= e($abfcfg['tts']['template']) ?></textarea>
    <div class="sm-small"><?= abfahrt_t('SEITE.VORLAGE_URL_HINWEIS') ?></div>
</div>
<div id="tts_audioserver_hint" class="sm-alert sm-info" style="display:none;">
    <?= abfahrt_t('SEITE.AUDIOSERVER_HINWEIS') ?>
</div>

<label><?= e(abfahrt_t('TEXT.L_ANSAGE_VORLAGE')) ?></label>
<input data-role="none" type="text" name="ansage_vorlage" value="<?= e($abfcfg['ansage_vorlage']) ?>">
<div class="sm-small"><?= abfahrt_t('TEXT.ANSAGE_HINWEIS') ?></div>

<h2><?= e(abfahrt_t('SEITE.H_BENACHRICHTIGUNGEN')) ?></h2>
<div style="margin-bottom:10px;">
    <label style="display:inline-flex;align-items:center;gap:6px;margin-right:24px;">
        <input data-role="none" type="checkbox" name="notify_audio" <?= !empty($abfcfg['notify']['audio']) ? 'checked' : '' ?>> <?= e(abfahrt_t('SEITE.L_AUDIO_AKTIV')) ?>
    </label>
    <label style="display:inline-flex;align-items:center;gap:6px;">
        <input data-role="none" type="checkbox" name="notify_push" <?= !empty($abfcfg['notify']['push']) ? 'checked' : '' ?>> <?= e(abfahrt_t('SEITE.L_PUSH_AKTIV')) ?>
    </label>
    <div class="sm-small"><?= abfahrt_t('SEITE.BENACHRICHTIGUNG_HINWEIS') ?></div>
</div>

<h2><?= e(abfahrt_t('SEITE.H_SPERRZEITEN')) ?></h2>
<div class="sm-small" style="margin-bottom:6px;"><?= abfahrt_t('SEITE.SPERRZEITEN_HINWEIS') ?></div>
<div style="margin-bottom:8px;">
    <label style="display:inline-flex;align-items:center;gap:6px;">
        <input data-role="none" type="checkbox" name="quiet_push" <?= !empty($abfcfg['quiet_push']) ? 'checked' : '' ?>> <?= e(abfahrt_t('TEXT.L_QUIET_PUSH')) ?>
    </label>
</div>
<div class="sm-breit">
<table class="sm-qt">
<?php $days = abfahrt_quiet_labels();
foreach ($days as $d => $dayname) { ?>
<tr<?= $d === 8 ? ' style="height:34px;vertical-align:bottom;"' : '' ?>>
    <td style="width:28px;"><input data-role="none" type="checkbox" name="quiet_on[<?= $d ?>]" <?= !empty($abfcfg['quiet'][$d]['on']) ? 'checked' : '' ?>></td>
    <td style="width:105px;"><?= $d >= 8 ? '<b>' . e($dayname) . '</b>' : e($dayname) ?></td>
    <td style="width:100px;"><?= e(abfahrt_t('SEITE.SPERRZEIT_VON')) ?></td>
    <td style="width:100px;"><input data-role="none" type="time" name="quiet_from[<?= $d ?>]" value="<?= e($abfcfg['quiet'][$d]['from']) ?>"></td>
    <td style="width:34px;text-align:center;"><?= e(abfahrt_t('SEITE.SPERRZEIT_BIS')) ?></td>
    <td style="width:100px;"><input data-role="none" type="time" name="quiet_to[<?= $d ?>]" value="<?= e($abfcfg['quiet'][$d]['to']) ?>"></td>
    <td><?= e(abfahrt_t('SEITE.SPERRZEIT_UHR')) ?></td>
</tr>
<?php } ?>
</table>
</div>
<?php $abf_tag = abfahrt_daytype();
$abf_regel = abfahrt_quiet_rule($abfcfg); ?>
<div class="sm-small" style="margin-top:6px;">
<?= abfahrt_t('SEITE.SONDERTAGE_HINWEIS') ?><br>
<?php if ($abf_tag['quelle'] === 'keine') {
    echo e(abfahrt_t('SEITE.STATUS_KEIN_FERIENPLUGIN'));
} else {
    $abf_namen = abfahrt_quiet_labels();
    $liste = [];
    if (!empty($abf_tag['urlaub'])) { $liste[] = $abf_namen[10]; }
    if (!empty($abf_tag['feiertag'])) { $liste[] = $abf_namen[8]; }
    if (!empty($abf_tag['ferien'])) { $liste[] = $abf_namen[9]; }
    $abf_heute = $liste ? implode(' + ', $liste) . ((($abf_tag['name'] ?? '') !== '') ? ' (' . $abf_tag['name'] . ')' : '')
                        : abfahrt_t('SEITE.STATUS_NORMALTAG');
    echo sprintf(e(abfahrt_t('SEITE.STATUS_HEUTE')), '<b>' . e($abf_heute) . '</b>',
                 '<b>' . e($abf_regel[0] ? $abf_regel[1] : abfahrt_t('SEITE.STATUS_KEINE_ZEILE')) . '</b>');
} ?>
</div>

<div class="sm-knopfreihe">
<button data-role="none" class="sm-btn sm-b-aktion sm-speichern" type="submit"><?= e(abfahrt_t('SEITE.K_SPEICHERN')) ?></button>
</div>
</form>

<?php /* Sichern und Zurueckspielen: HINTER dem Ende des Einstellungsformulars,
         mit zwei eigenen Formularen (der Download ruft exit, das Zurueckspielen
         braucht multipart). */ ?>
<h2><?= e(abfahrt_t('TEXT.H_SICHERUNG')) ?></h2>
<div class="sm-hinweis"><?= abfahrt_t('TEXT.SICH_ERKLAERUNG') ?></div>
<div class="sm-warnung"><?= abfahrt_t('TEXT.SICH_WARNUNG') ?></div>
<div class="sm-knopfreihe">
  <form action="index.php" method="post">
    <input data-role="none" type="hidden" name="activetab" value="tab-settings">
    <input data-role="none" type="hidden" name="formtoken" value="<?= e(abf_formtoken($abfcfg)) ?>">
    <button data-role="none" class="sm-btn sm-b-lesen" type="submit" name="abfahrt_sichern" value="1"><?= e(abfahrt_t('TEXT.K_SICHERN')) ?></button>
  </form>
  <form action="index.php" method="post" enctype="multipart/form-data">
    <input data-role="none" type="hidden" name="activetab" value="tab-settings">
    <input data-role="none" type="hidden" name="formtoken" value="<?= e(abf_formtoken($abfcfg)) ?>">
    <input data-role="none" type="file" name="abfahrt_sicherung" accept=".json">
    <button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="abfahrt_zurueck" value="1"><?= e(abfahrt_t('TEXT.K_ZURUECK')) ?></button>
  </form>
</div>
</div>

<!-- ================= Reiter: MQTT ================= -->
<div class="sm-seite<?= $active_tab === 'tab-mqtt' ? ' sm-active' : '' ?>" id="tab-mqtt">
<h2>MQTT</h2>
<?php if (!$abf_mz['gefunden']) { ?>
<div class="sm-alert sm-err"><?= abfahrt_t('MQTT.KEIN_ABSCHNITT') ?></div>
<?php } elseif (!$abf_mz['autostart']) { ?>
<div class="sm-alert sm-err"><?= abfahrt_t('MQTT.KEIN_AUTOSTART') ?></div>
<?php } else { ?>
<div class="sm-alert sm-info"><?= sprintf(abfahrt_t('MQTT.OK'), (int) $abf_mz['udpport']) ?></div>
<?php } ?>

<div class="sm-step"><?= abfahrt_t('MQTT.WARUM') ?></div>

<h3 class="sm-h3"><?= e(abfahrt_t('MQTT.H_ABO')) ?></h3>
<div class="sm-small"><?= e(abfahrt_t('MQTT.ABO_TEXT')) ?></div>
<div class="sm-mono" style="display:block;padding:8px;margin:6px 0;"><?= e($abfcfg['mqtt_topic']) ?>/#</div>
<div class="<?= $abf_abo_klasse ?>"><?= abfahrt_abo_text() ?></div>

<h3 class="sm-h3"><?= e(abfahrt_t('MQTT.H_THEMEN')) ?></h3>
<?php $abf_w = abfahrt_werte(abfahrt_stand(), $abfcfg); ?>
<table class="sm-tbl">
<tr><th><?= e(abfahrt_t('MQTT.T_THEMA')) ?></th><th><?= e(abfahrt_t('MQTT.T_BEDEUTUNG')) ?></th><th><?= e(abfahrt_t('MQTT.T_RETAIN')) ?></th><th><?= e(abfahrt_t('MQTT.T_WERT')) ?></th></tr>
<?php foreach (abfahrt_felder() as $abf_n => $abf_d) { if (empty($abf_d[5])) { continue; } ?>
<tr><td><span class="sm-mono"><?= e($abfcfg['mqtt_topic']) ?>/<?= e($abf_n) ?></span></td>
    <td><?= e(abfahrt_t($abf_d[3])) ?></td>
    <td><?= e(abfahrt_t(!empty($abf_d[4]) ? 'MQTT.RETAIN_JA' : 'MQTT.RETAIN_NEIN')) ?></td>
    <td><?= isset($abf_w[$abf_n]) ? e($abf_w[$abf_n]) : '&mdash;' ?></td></tr>
<?php } ?>
<?php foreach (array('status/ts', 'status/zaehler', 'status/ok') as $abf_lz) { ?>
<tr><td><span class="sm-mono"><?= e($abfcfg['mqtt_topic']) ?>/<?= e($abf_lz) ?></span></td>
    <td><?= e(abfahrt_t('MQTT.LZ_' . strtoupper(str_replace('status/', '', $abf_lz)))) ?></td>
    <td><?= e(abfahrt_t('MQTT.RETAIN_NIE')) ?></td>
    <td>&mdash;</td></tr>
<?php } ?>
</table>
<div class="sm-small"><?= abfahrt_t('MQTT.RETAIN_ERKLAERUNG') ?></div>

<form action="index.php" method="post">
<input data-role="none" type="hidden" name="save_mqtt" value="1">
<input data-role="none" type="hidden" name="formtoken" value="<?= e(abf_formtoken($abfcfg)) ?>">
<input data-role="none" type="hidden" name="activetab" value="tab-mqtt">
<div class="sm-row"><label><input data-role="none" type="checkbox" name="mqtt_ein" value="1"<?= !empty($abfcfg['mqtt_ein']) ? ' checked' : '' ?>> <?= e(abfahrt_t('MQTT.L_EIN')) ?></label></div>
<div class="sm-row"><label><?= e(abfahrt_t('MQTT.L_TOPIC')) ?></label>
<input data-role="none" type="text" name="mqtt_topic" value="<?= e($abfcfg['mqtt_topic']) ?>" size="24"></div>
<div class="sm-row"><label><?= e(abfahrt_t('TEXT.L_MQTT_VOLLSEND')) ?></label>
<input data-role="none" type="number" name="mqtt_vollsend_min" value="<?= (int) $abfcfg['mqtt_vollsend_min'] ?>" min="0" max="1440"></div>
<div class="sm-small"><?= abfahrt_t('TEXT.MQTT_VOLLSEND_HINWEIS') ?></div>
<div class="sm-legende"><span><i class="sm-punkt sm-b-aktion"></i> <?= e(abfahrt_t('LEGENDE.AKTION')) ?></span></div>
<div class="sm-knopfreihe">
<button data-role="none" class="sm-btn sm-b-aktion" type="submit"><?= e(abfahrt_t('SEITE.K_SPEICHERN')) ?></button>
</div>
</form>
</div>

<!-- ================= Reiter: Einbindung in Loxone ================= -->
<div class="sm-seite<?= $active_tab === 'tab-loxone' ? ' sm-active' : '' ?>" id="tab-loxone">
<h2><?= e(abfahrt_t('SEITE.H_EINBINDUNG')) ?></h2>
<p><?= abfahrt_t('SEITE.EINBINDUNG_TEXT') ?></p>

<div class="sm-step"><b><?= e(abfahrt_t('LOX.S1_T')) ?></b><br><br>
<?= abfahrt_t('LOX.S1') ?></div>

<div class="sm-step"><b><?= e(abfahrt_t('LOX.S2_T')) ?></b><br><br>
<?= e(abfahrt_t('LOX.S2')) ?>
<div class="sm-mono" style="display:block;padding:8px;margin:6px 0;"><?= e($abfcfg['mqtt_topic']) ?>/#</div>
<div class="<?= $abf_abo_klasse ?>"><?= abfahrt_abo_text() ?></div>
<?= abfahrt_t('LOX.S2_THEMEN') ?>
<?php /* Die Tabelle kommt aus abfahrt_felder(), damit Tabelle und Sendeweg nicht
         auseinanderlaufen. Die Spalte "Name in Loxone" ist der Name, den das
         Gateway VERGIBT - Schraegstrich und Prozentzeichen werden zu "_"
         (am Geraet gemessen: mqttgateway.pl, Zeile 503-504). Bis 1.6.9 stand
         in der Baustein-Liste "Abfahrt ABFAHRT_IN" - einen Eingang dieses
         Namens legt das Gateway nie an. */ ?>
<table class="sm-tbl">
<tr><th><?= e(abfahrt_t('MQTT.T_THEMA')) ?></th><th><?= e(abfahrt_t('LOX.T_LOXONE_NAME')) ?></th><th><?= e(abfahrt_t('MQTT.T_BEDEUTUNG')) ?></th><th><?= e(abfahrt_t('MQTT.T_RETAIN')) ?></th></tr>
<?php foreach (abfahrt_felder() as $abf_n => $abf_d) { if (empty($abf_d[5])) { continue; } ?>
<tr><td><span class="sm-mono"><?= e($abfcfg['mqtt_topic']) ?>/<?= e($abf_n) ?></span></td>
    <td><span class="sm-mono"><?= e(str_replace(array('/', '%'), '_', $abfcfg['mqtt_topic'] . '/' . $abf_n)) ?></span></td>
    <td><?= e(abfahrt_t($abf_d[3])) ?></td>
    <td><?= e(abfahrt_t(!empty($abf_d[4]) ? 'MQTT.RETAIN_JA' : 'MQTT.RETAIN_NEIN')) ?></td></tr>
<?php } ?>
<?php foreach (array('status/ts', 'status/zaehler', 'status/ok') as $abf_lz) { ?>
<tr><td><span class="sm-mono"><?= e($abfcfg['mqtt_topic']) ?>/<?= e($abf_lz) ?></span></td>
    <td><span class="sm-mono"><?= e(str_replace(array('/', '%'), '_', $abfcfg['mqtt_topic'] . '/' . $abf_lz)) ?></span></td>
    <td><?= e(abfahrt_t('MQTT.LZ_' . strtoupper(str_replace('status/', '', $abf_lz)))) ?></td>
    <td><?= e(abfahrt_t('MQTT.RETAIN_NIE')) ?></td></tr>
<?php } ?>
</table>
<div class="sm-small"><?= abfahrt_t('MQTT.LZ_ERKLAERUNG') ?></div>
</div>

<h3 class="sm-h3"><?= e(abfahrt_t('LOX.H_ALTERNATIVE')) ?></h3>
<div class="sm-small"><?= abfahrt_t('LOX.ALTERNATIVE_TEXT') ?></div>

<?php $abf_http_adresse = 'http://' . $host . '/plugins/' . e($plugindir) . '/termin.php'; ?>
<div class="sm-step"><b><?= e(abfahrt_t('SEITE.H_HTTP1')) ?></b><br><br>
<?= abfahrt_t('SEITE.HTTP1_TEXT') ?>
<table class="sm-tbl">
<tr><th><?= e(abfahrt_t('SEITE.T_EIGENSCHAFT')) ?></th><th><?= e(abfahrt_t('SEITE.T_WERT')) ?></th></tr>
<tr><td>URL</td><td><span class="sm-mono"><?= $abf_http_adresse ?></span></td></tr>
<tr><td><?= e(abfahrt_t('SEITE.T_ABFRAGEZYKLUS')) ?></td><td><?= e(abfahrt_t('SEITE.ABFRAGEZYKLUS_WERT')) ?></td></tr>
</table>
<?= e(abfahrt_t('SEITE.HTTP1_ANTWORT')) ?><br>
<span class="sm-mono">TERMIN;OK=1;MINSTART=55;FAHRT=43.7;ABFAHRT_IN=-4;FEHLER=0;ALTER=23;AUDIO=1;PUSH=1;ANKUNFT=754</span>
</div>

<div class="sm-step"><b><?= e(abfahrt_t('SEITE.H_HTTP2')) ?></b><br><br>
<?= sprintf(abfahrt_t('SEITE.HTTP2_TEXT'), '<span class="sm-mono">' . e(abfahrt_suchtext('ABFAHRT_IN')) . '</span>',
            '<span class="sm-mono">;ABFAHRT_IN=</span>') ?>
<?php /* Aus abfahrt_felder() und abfahrt_suchtext() - derselben Quelle, aus der
         auch die Importvorlage entsteht. Bis 1.6.9 stand die Tabelle von Hand
         da, trug sieben von neun Feldern (ALTER und ANKUNFT fehlten) und nahm
         die Suchtexte aus der Sprachdatei. */ ?>
<table class="sm-tbl">
<tr><th><?= e(abfahrt_t('SEITE.T_BEFEHLSERKENNUNG')) ?></th><th><?= e(abfahrt_t('MQTT.T_BEDEUTUNG')) ?></th></tr>
<?php foreach (abfahrt_felder() as $abf_n => $abf_d) { ?>
<tr><td><span class="sm-mono"><?= e(abfahrt_suchtext($abf_n)) ?></span></td><td><?= e(abfahrt_t($abf_d[3])) ?></td></tr>
<?php } ?>
</table>
<div class="sm-alert sm-info"><?= abfahrt_t('SEITE.FEHLER_CODES') ?></div>
<div class="sm-alert sm-info"><?= abfahrt_t('TEXT.FEHLER_ERLAEUTERUNG') ?></div>

<h3 class="sm-h3"><?= e(abfahrt_t('LOX.H_VORLAGE')) ?></h3>
<div class="sm-small"><?= abfahrt_t('LOX.VORLAGE_TEXT') ?></div>
<div class="sm-legende">
<span><i class="sm-punkt sm-b-technik"></i> <?= e(abfahrt_t('LEGENDE.TECHNIK')) ?></span>
</div>
<div class="sm-knopfreihe">
<form action="index.php" method="post" style="margin:0;">
    <input data-role="none" type="hidden" name="download" value="xml_in">
    <input data-role="none" type="hidden" name="formtoken" value="<?= e(abf_formtoken($abfcfg)) ?>">
    <input data-role="none" type="hidden" name="activetab" value="tab-loxone">
    <button data-role="none" class="sm-btn sm-b-technik" type="submit"><?= e(abfahrt_t('LOX.K_VORLAGE')) ?></button>
</form>
</div>
</div>

<div class="sm-step"><b><?= e(abfahrt_t('SEITE.H_AUSGANG')) ?></b><br><br>
<?= abfahrt_t('SEITE.AUSGANG_TEXT') ?>
<table class="sm-tbl">
<tr><th><?= e(abfahrt_t('SEITE.T_EIGENSCHAFT')) ?></th><th><?= e(abfahrt_t('SEITE.T_WERT')) ?></th></tr>
<tr><td><?= e(abfahrt_t('SEITE.T_ADRESSE_AUSGANG')) ?></td><td><span class="sm-mono">http://<?= $host ?></span></td></tr>
<tr><td><?= e(abfahrt_t('SEITE.T_BEFEHL_EIN')) ?></td><td><span class="sm-mono">/plugins/<?= e($plugindir) ?>/termin_say.php?token=<?= e($abf_token) ?></span></td></tr>
<tr><td><?= e(abfahrt_t('SEITE.T_MERKWORT')) ?></td><td><span class="sm-mono"><?= e($abf_token) ?></span></td></tr>
</table>
<div class="sm-alert sm-warn"><?= abfahrt_t('TEXT.MERKWORT_HINWEIS') ?></div>
<?= abfahrt_t('SEITE.LOGIK_TEXT') ?>
</div>

<div class="sm-step"><b><?= e(abfahrt_t('SEITE.H_STATUS')) ?></b><br><br>
<?= abfahrt_t('SEITE.STATUS_TEXT') ?>
</div>

<div class="sm-step"><b><?= e(abfahrt_t('SEITE.H_AUDIO')) ?></b><br><br>
<?= abfahrt_t('SEITE.AUDIO_TEXT') ?>
</div>

<div class="sm-step"><b><?= e(abfahrt_t('LOX.H_BAUSTEINE')) ?></b><br><br>
<?= abfahrt_t('LOX.BAUSTEINE_TEXT') ?>
<div class="sm-breit">
<table class="sm-tbl">
<tr><th>#</th><th><?= e(abfahrt_t('LOX.T_BAUSTEIN')) ?></th><th><?= e(abfahrt_t('LOX.T_NAME')) ?></th>
    <th><?= e(abfahrt_t('LOX.T_PARAMETER')) ?></th><th><?= e(abfahrt_t('LOX.T_VERBINDEN')) ?></th></tr>
<?php
$abf_i = 0;
foreach (abfahrt_felder() as $abf_n => $abf_d) {
    $abf_i++; ?>
<tr><td><?= $abf_i ?></td><td><?= e(abfahrt_t('LOX.B_EINGANG')) ?></td>
    <td><span class="sm-mono"><?= e(str_replace(array('/', '%'), '_', $abfcfg['mqtt_topic'] . '/' . $abf_n)) ?></span></td>
    <td><?= empty($abf_d[5]) ? e(abfahrt_t('LOX.B_NUR_HTTP')) : e(abfahrt_t('LOX.B_THEMA')) . ' <span class="sm-mono">' . e($abfcfg['mqtt_topic']) . '/' . e($abf_n) . '</span>' ?></td>
    <td>&mdash;</td></tr>
<?php } ?>
<?php for ($abf_b = 1; $abf_b <= 14; $abf_b++) { $abf_i++; ?>
<tr><td><?= $abf_i ?></td><td><?= abfahrt_tn('BAUSTEIN.B' . $abf_b . '_TYP') ?></td>
    <td><span class="sm-mono"><?= abfahrt_tn('BAUSTEIN.B' . $abf_b . '_NAME') ?></span></td>
    <td><?= abfahrt_tn('BAUSTEIN.B' . $abf_b . '_PARAM') ?></td>
    <td><?= abfahrt_tn('BAUSTEIN.B' . $abf_b . '_VERB') ?></td></tr>
<?php } ?>
</table>
</div>
<div class="sm-alert sm-info"><?= abfahrt_tn('LOX.BAUSTEINE_ERLAEUTERUNG') ?></div>
</div>

<div class="sm-step"><b><?= e(abfahrt_t('LOX.H_GEGENPROBE')) ?></b><br><br>
<?= sprintf(abfahrt_t('LOX.GEGENPROBE'), '<span class="sm-mono">' . e(str_replace(array('/', '%'), '_', $abfcfg['mqtt_topic'] . '/status/zaehler')) . '</span>',
            '<span class="sm-mono">' . e(str_replace(array('/', '%'), '_', $abfcfg['mqtt_topic'] . '/ABFAHRT_IN')) . '</span>') ?></div>

</div>

<!-- ================= Reiter: Test ================= -->
<div class="sm-seite<?= $active_tab === 'tab-test' ? ' sm-active' : '' ?>" id="tab-test">
<h2><?= e(abfahrt_t('REITER.TEST')) ?></h2>

<h3 class="sm-h3"><?= e(abfahrt_t('TEST.H_SELBSTTEST')) ?></h3>
<div class="sm-small"><?= abfahrt_t('TEST.SELBSTTEST_TEXT') ?></div>
<?php
$abf_pr = abfahrt_pruefungen($abfcfg);
$abf_zahl = array(1 => 0, 0 => 0, -1 => 0);
foreach ($abf_pr as $abf_z) { $abf_zahl[$abf_z[0] === 1 ? 1 : ($abf_z[0] === 0 ? 0 : -1)]++; }
/* Drei Zahlen, und die Farbe folgt dem schlechtesten Punkt. Bis 1.6.9 zaehlte
 * "nicht feststellbar" als bestanden: bis zu fuenf Zeilen mit "i", und oben
 * stand in Blau "12 von 12 Pruefungen bestanden". */
$abf_klasse = $abf_zahl[0] ? 'sm-alert sm-err' : ($abf_zahl[-1] ? 'sm-alert sm-warn' : 'sm-alert sm-ok');
?>
<div class="<?= $abf_klasse ?>">
<?= sprintf(e(abfahrt_t('TEST.SELBSTTEST_ZAHLEN')), $abf_zahl[1], $abf_zahl[0], $abf_zahl[-1], count($abf_pr)) ?>
</div>
<table class="sm-tbl">
<tr><th style="width:34px;"></th><th style="width:34%;"><?= e(abfahrt_t('TEST.T_FRAGE')) ?></th><th><?= e(abfahrt_t('TEST.T_ANTWORT')) ?></th></tr>
<?php foreach ($abf_pr as $abf_z) { ?>
<tr><td style="text-align:center;"><?= $abf_z[0] === 1 ? '<span class="sm-an">&#10004;</span>'
        : ($abf_z[0] === 0 ? '<span class="sm-aus">&#10008;</span>' : '<b>i</b>') ?></td>
    <td><?= $abf_z[1] ?></td><td><?= $abf_z[2] ?></td></tr>
<?php } ?>
</table>

<?php /* Die Legende fuehrt genau die Farben, die im Reiter vorkommen. */ ?>
<div class="sm-legende">
<span><i class="sm-punkt sm-b-lesen"></i> <?= e(abfahrt_t('LEGENDE.LESEN')) ?></span>
<span><i class="sm-punkt sm-b-technik"></i> <?= e(abfahrt_t('LEGENDE.TECHNIK')) ?></span>
<span><i class="sm-punkt sm-b-aktion"></i> <?= e(abfahrt_t('LEGENDE.AKTION')) ?></span>
</div>

<?php if ($abf_selftest !== null) {
    $abf_sk = ((int) $abf_selftest['stufe'] === 1) ? 'sm-alert sm-ok'
            : (((int) $abf_selftest['stufe'] === 0) ? 'sm-alert sm-err' : 'sm-alert sm-warn'); ?>
<div class="<?= $abf_sk ?>"><?= e($abf_selftest['text']) ?></div>
<?php } ?>

<h3 class="sm-h3"><?= e(abfahrt_t('TEST.H_GEO')) ?></h3>
<div class="sm-small"><?= e(abfahrt_t('TEST.GEO_TEXT')) ?></div>
<?php $abf_geo = abfahrt_geo_stand($abfcfg); ?>
<table class="sm-tbl">
<tr><th style="width:34px;"></th><th style="width:34%;"><?= e(abfahrt_t('TEST.F_GEO')) ?></th><th><?= e(abfahrt_t('TEST.T_ANTWORT')) ?></th></tr>
<tr><td style="text-align:center;"><?= $abf_geo['da'] ? '<span class="sm-an">&#10004;</span>' : '<b>i</b>' ?></td>
    <td><?= e($abf_geo['adresse']) ?></td>
    <td><?php if ($abf_geo['da']) {
            printf(e(abfahrt_t('TEST.A_GEO_OK')), '<span class="sm-mono">' . e($abf_geo['koordinaten']) . '</span>',
                   e(abf_alter_text((int) $abf_geo['alter'])));
            echo ' &mdash; <a href="' . e($abf_geo['karte']) . '" target="_blank" rel="noopener noreferrer">'
               . e(abfahrt_t('TEST.AUF_KARTE')) . '</a>';
        } else {
            echo e(abfahrt_t('TEST.A_GEO_FEHLT'));
        } ?></td></tr>
</table>

<h3 class="sm-h3"><?= e(abfahrt_t('TEST.H_KALENDER')) ?></h3>
<div class="sm-small"><?= abfahrt_t('TEST.KALENDER_TEXT') ?></div>
<?php if (is_array($abf_kaldiag)) { ?>
<div class="sm-breit">
<table class="sm-tbl">
<tr><th><?= e(abfahrt_t('TEST.T_KAL_NAME')) ?></th><th><?= e(abfahrt_t('TEST.T_KAL_QUELLE')) ?></th>
    <th><?= e(abfahrt_t('TEST.T_KAL_ALTER')) ?></th><th><?= e(abfahrt_t('TEST.T_KAL_TERMINE')) ?></th>
    <th><?= e(abfahrt_t('TEST.T_KAL_MITORT')) ?></th><th><?= e(abfahrt_t('TEST.T_KAL_NAECHSTER')) ?></th></tr>
<?php foreach ($abf_kaldiag as $abf_kd) { if (!is_array($abf_kd)) { continue; } ?>
<tr><td><?= e(($abf_kd['name'] ?? '') !== '' ? $abf_kd['name'] : '#' . (int) ($abf_kd['nr'] ?? 0)) ?></td>
    <td><span class="sm-mono"><?= e($abf_kd['gastgeber'] ?? '') ?></span></td>
    <td><?= !isset($abf_kd['alter']) ? '&mdash;' : ((int) round($abf_kd['alter'] / 60) . ' min') ?></td>
    <td><?= ($abf_kd['grund'] ?? '') !== '' ? '<span class="sm-aus">' . e(abfahrt_t('TEST.KAL_NICHT_LADBAR')) . '</span>' : (int) ($abf_kd['vevents'] ?? 0) ?></td>
    <td><?= (int) ($abf_kd['mit_ort'] ?? 0) ?></td>
    <td><?php if (!empty($abf_kd['naechste'][0])) {
            $abf_n1 = $abf_kd['naechste'][0];
            echo e($abf_n1['zeit'] ?? '') . ' &mdash; ' . e($abf_n1['titel'] ?? '') . ' (' . e($abf_n1['ort'] ?? '') . ')';
        } else { echo e(abfahrt_t('TEST.KAL_KEIN_TREFFER')); } ?></td></tr>
<?php } ?>
</table>
</div>
<?php } ?>

<h3 class="sm-h3"><?= e(abfahrt_t('SEITE.H_TECHNIK')) ?></h3>
<div class="sm-knopfreihe">
<a class="sm-btn sm-b-technik" href="/plugins/<?= e($plugindir) ?>/termin.php?debug=1&amp;token=<?= e($abf_token) ?>" target="_blank" rel="noopener noreferrer"><?= e(abfahrt_t('SEITE.K_DEBUG')) ?></a>
<form action="index.php" method="post" style="margin:0;">
    <input data-role="none" type="hidden" name="activetab" value="tab-test">
    <input data-role="none" type="hidden" name="formtoken" value="<?= e(abf_formtoken($abfcfg)) ?>">
    <button data-role="none" class="sm-btn sm-b-lesen" type="submit" name="selftest" value="1"><?= e(abfahrt_t('TEST.K_SELFTEST')) ?></button>
</form>
<form action="index.php" method="post" style="margin:0;">
    <input data-role="none" type="hidden" name="activetab" value="tab-test">
    <input data-role="none" type="hidden" name="formtoken" value="<?= e(abf_formtoken($abfcfg)) ?>">
    <button data-role="none" class="sm-btn sm-b-lesen" type="submit" name="kal_diagnose" value="1"><?= e(abfahrt_t('TEST.K_KALENDER')) ?></button>
</form>
<form action="index.php" method="post" style="margin:0;">
    <input data-role="none" type="hidden" name="activetab" value="tab-test">
    <input data-role="none" type="hidden" name="formtoken" value="<?= e(abf_formtoken($abfcfg)) ?>">
    <button data-role="none" class="sm-btn sm-b-technik" type="submit" name="geo_verwerfen" value="1"><?= e(abfahrt_t('TEST.K_GEO_VERWERFEN')) ?></button>
</form>
</div>

<h3 class="sm-h3"><?= e(abfahrt_t('SEITE.H_LOEST_AUS')) ?></h3>
<div class="sm-knopfreihe">
<a class="sm-btn sm-b-aktion" href="/plugins/<?= e($plugindir) ?>/termin_say.php?force=1&amp;token=<?= e($abf_token) ?>" target="_blank" rel="noopener noreferrer"><?= e(abfahrt_t('SEITE.K_ANSAGE')) ?></a>
<form action="index.php" method="post" style="margin:0;"
      onsubmit="return confirm('<?= e(strip_tags(abfahrt_t('TEST.TOKEN_NEU_WARNUNG'))) ?>');">
    <input data-role="none" type="hidden" name="activetab" value="tab-test">
    <input data-role="none" type="hidden" name="formtoken" value="<?= e(abf_formtoken($abfcfg)) ?>">
    <button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="token_neu" value="1"><?= e(abfahrt_t('TEST.K_TOKEN_NEU')) ?></button>
</form>
</div>
<div class="sm-alert sm-warn"><?= abfahrt_t('TEST.TOKEN_NEU_WARNUNG') ?></div>
<div class="sm-small"><?= abfahrt_t('SEITE.TEST_KNOEPFE_HINWEIS') ?></div>
</div>

<!-- ================= Reiter: Logdateien ================= -->
<div class="sm-seite<?= $active_tab === 'tab-log' ? ' sm-active' : '' ?>" id="tab-log">
<h2><?= e(abfahrt_t('REITER.LOG')) ?></h2>
<div class="sm-small" style="margin-bottom:8px;"><?= e(abfahrt_t('SEITE.LOG_TEXT')) ?><br><?= e(abfahrt_t('SEITE.LOG_DATEI')) ?> <span class="sm-mono"><?= e($log_file) ?></span></div>
<?php if ($log_lines) { ?>
<div class="sm-log"><?= e(implode("\n", $log_lines)) ?></div>
<?php } else { ?>
<div class="sm-alert sm-info"><?= e(abfahrt_t('SEITE.LOG_LEER')) ?></div>
<?php } ?>
<div class="sm-legende">
<span><i class="sm-punkt sm-b-aktion"></i> <?= e(abfahrt_t('LEGENDE.AKTION')) ?></span>
</div>
<div class="sm-knopfreihe">
<form action="index.php" method="post" style="margin:0;">
    <input data-role="none" type="hidden" name="clearlog" value="1">
    <input data-role="none" type="hidden" name="formtoken" value="<?= e(abf_formtoken($abfcfg)) ?>">
    <input data-role="none" type="hidden" name="activetab" value="tab-log">
    <button data-role="none" class="sm-btn sm-b-aktion" type="submit"><?= e(abfahrt_t('SEITE.K_LOG_LEEREN')) ?></button>
</form>
</div>
<?php if (class_exists('LBWeb', false) && method_exists('LBWeb', 'loglist_html')) { echo LBWeb::loglist_html(); } ?>
</div>
</div>
<script>
function abfTtsMode() {
    var m = document.getElementById('tts_mode').value;
    document.getElementById('tts_audioserver_hint').style.display = (m === 'audioserver') ? 'block' : 'none';
    document.getElementById('tts_template_row').style.display = (m === 'ms4h' || m === 'custom') ? 'block' : 'none';
    var port = document.getElementsByName('tts_port')[0];
    /* Nur ein LEERES Feld wird vorbelegt - eine bewusst eingetragene 80 bleibt. */
    if (m === 'musicserver' && !port.value) { port.value = 7091; }
}
abfTtsMode();
(function () {
    var tabs = document.querySelectorAll('.sm-tab');
    function activate(id) {
        tabs.forEach(function (t) { t.classList.toggle('sm-active', t.getAttribute('data-ziel') === id); });
        document.querySelectorAll('.sm-seite').forEach(function (p) { p.classList.toggle('sm-active', p.id === id); });
    }
    // preventDefault: ohne es folgt der Browser dem href, laedt die Seite neu
    // und wirft alle noch nicht gespeicherten Eingaben weg. Die Adresse in der
    // Leiste wird trotzdem nachgezogen, damit der Reiter verlinkbar bleibt.
    tabs.forEach(function (t) {
        t.addEventListener('click', function (ereignis) {
            ereignis.preventDefault();
            activate(t.getAttribute('data-ziel'));
            if (window.history && window.history.replaceState) {
                window.history.replaceState(null, '', t.getAttribute('href'));
            }
        });
    });
    activate(<?= json_encode($active_tab) ?>);
})();
</script>
<?php
if ($use_frame) {
    LBWeb::lbfooter();
}
