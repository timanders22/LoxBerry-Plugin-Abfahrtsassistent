<?php
/**
 * Abfahrts-Assistent - gemeinsame Bibliothek
 *
 * Kalender (bis zu 10 iCal-URLs) + Verkehrslage (TomTom / Google / HERE)
 * -> naechster Termin mit Ortsangabe, aktuelle Fahrzeit, Abfahrts-Countdown.
 *
 * Keine persoenlichen Daten im Code - alles kommt aus der Plugin-Konfiguration
 * ($LBHOMEDIR/config/plugins/<plugin>/abfahrt.json), die ueber die
 * Admin-Oberflaeche gepflegt wird.
 */

error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);
date_default_timezone_set('Europe/Berlin');

function abfahrt_paths() {
    $lbhomedir = getenv('LBHOMEDIR') ?: (is_dir('/opt/loxberry') ? '/opt/loxberry' : '');
    $plugindir = getenv('LBPPLUGINDIR') ?: basename(dirname(__DIR__, 1));
    // Public html dir: .../webfrontend/html/plugins/<plugindir>/ -> plugin name is folder name
    $self = basename(__DIR__);
    if ($lbhomedir && is_dir($lbhomedir . '/config/plugins/' . $self)) {
        $plugindir = $self;
    }
    if ($lbhomedir) {
        return [
            'config' => $lbhomedir . '/config/plugins/' . $plugindir . '/abfahrt.json',
            'tmp' => '/tmp/abfahrtsassistent',
        ];
    }
    // Fallback (Entwicklung/Test): relativ zum Skript
    return [
        'config' => dirname(__DIR__, 2) . '/config/abfahrt.json',
        'tmp' => sys_get_temp_dir() . '/abfahrtsassistent',
    ];
}

function abfahrt_config() {
    $p = abfahrt_paths();
    $abfcfg = is_file($p['config']) ? (json_decode((string) file_get_contents($p['config']), true) ?: []) : [];
    // Defaults
    $abfcfg += [
        'calendars' => [],
        'provider' => 'tomtom',
        'api_key' => '',
        'home_address' => '',
        'buffer_min' => 10,
        'arrival_min' => 10,
        'lookahead_hours' => 15,
        'ignore_locations' => 'online, teams, zoom, webex, google meet, skype, videokonferenz, telefontermin',
        'tts' => [],
        'notify' => [],
        'quiet' => [],
        'mqtt_ein' => 1,
        'mqtt_topic' => 'abfahrt',
        // Schuetzt die beiden AUSLOESENDEN Aufrufe im unangemeldeten Bereich:
        // termin_say.php (spricht im Haus) und termin.php?debug=1 (rechnet neu
        // und fragt dabei den Kartendienst). Der reine Leseaufruf von
        // termin.php bleibt frei - den holt Loxone zyklisch ab, und er kostet
        // nichts.
        'aktionstoken' => '',
    ];
    $abfcfg['mqtt_ein'] = empty($abfcfg['mqtt_ein']) ? 0 : 1;
    $abfcfg['mqtt_topic'] = preg_replace('#[^A-Za-z0-9_/\-]#', '', (string) $abfcfg['mqtt_topic']);
    if ($abfcfg['mqtt_topic'] === '') { $abfcfg['mqtt_topic'] = 'abfahrt'; }
    $abfcfg['notify'] += ['audio' => 1, 'push' => 1];
    foreach (abfahrt_quiet_keys() as $d) {
        if (!isset($abfcfg['quiet'][$d]) || !is_array($abfcfg['quiet'][$d])) {
            $abfcfg['quiet'][$d] = [];
        }
        // Sondertage starten spaeter: Vorgabe 20:00-09:00 statt 20:00-07:00
        $abfcfg['quiet'][$d] += ['on' => 0, 'from' => '20:00', 'to' => $d >= 8 ? '09:00' : '07:00'];
    }
    $abfcfg['tts'] += [
        'mode' => 'musicserver',
        'ip' => '',
        'port' => 7091,
        'zones' => '1~25',
        'volume' => 8,
        'lang' => 'de',
        'template' => '',
    ];
    return $abfcfg;
}

/**
 * Ein neues Merkwort erzeugen.
 *
 * random_bytes ist die kryptografisch geeignete Quelle. Faellt sie aus, wird
 * NICHT stillschweigend auf rand() ausgewichen - ein vorhersagbares Merkwort
 * waere schlechter als gar keins, weil es Sicherheit nur vortaeuscht.
 */
function abfahrt_token_erzeugen() {
    return bin2hex(random_bytes(12));
}

/**
 * Merkwort pruefen - fail-closed.
 *
 * Verglichen wird mit hash_equals: ein einfaches == liesse sich ueber die
 * Antwortzeit Zeichen fuer Zeichen erraten. Ist noch keins gesetzt, wird
 * NICHT durchgelassen; ein leeres Soll, das alles annimmt, waere die
 * gefaehrlichste Variante.
 */
function abfahrt_token_ok(array $abfcfg) {
    $soll = isset($abfcfg['aktionstoken']) ? (string) $abfcfg['aktionstoken'] : '';
    $ist  = isset($_GET['token']) ? (string) $_GET['token'] : '';
    if ($soll === '' || $ist === '') { return false; }
    return hash_equals($soll, $ist);
}

/** Antwort bei fehlendem oder falschem Merkwort. Beendet das Skript. */
function abfahrt_token_abweisen($praefix, array $abfcfg) {
    header('Content-Type: text/plain; charset=utf-8');
    http_response_code(403);
    if (empty($abfcfg['aktionstoken'])) {
        echo $praefix . ";OK=0;GRUND=KEIN_TOKEN_GESETZT\n"
           . "Einmal die Plugin-Oberflaeche oeffnen - dort wird eines erzeugt und\n"
           . "im Reiter \"Einbindung in Loxone\" samt fertiger Adresse angezeigt.\n";
    } else {
        echo $praefix . ";OK=0;GRUND=TOKEN\n";
    }
    exit;
}

function abfahrt_tmpdir() {
    $p = abfahrt_paths();
    if (!is_dir($p['tmp'])) {
        @mkdir($p['tmp'], 0775, true);
    }
    return $p['tmp'];
}

function abfahrt_http_get($url, $timeout = 12) {
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_USERAGENT => 'LoxBerry Abfahrts-Assistent',
        ]);
        $r = curl_exec($ch);
        curl_close($ch);
        return $r;
    }
    $ctx = stream_context_create(['http' => ['timeout' => $timeout, 'user_agent' => 'LoxBerry Abfahrts-Assistent']]);
    return @file_get_contents($url, false, $ctx);
}


/**
 * Schluessel der Sperrzeiten-Tabelle:
 *   1-7  = Montag bis Sonntag
 *   8    = Feiertag, 9 = Ferien, 10 = Urlaub (abwesend)
 * Die Sondertage 8-10 haben Vorrang vor dem Wochentag (siehe abfahrt_quiet_rule()).
 */
function abfahrt_quiet_keys() {
    return [1, 2, 3, 4, 5, 6, 7, 8, 9, 10];
}
function abfahrt_quiet_labels() {
    return [1 => 'Montag', 2 => 'Dienstag', 3 => 'Mittwoch', 4 => 'Donnerstag', 5 => 'Freitag',
            6 => 'Samstag', 7 => 'Sonntag', 8 => 'Feiertag', 9 => 'Ferien', 10 => 'Urlaub'];
}

/**
 * Welche Sondertage gelten heute? Quelle ist das (optionale) LoxBerry-Plugin
 * "Ferien und Feiertage". Ist es nicht installiert, sind alle Werte 0 und es
 * bleibt bei der reinen Wochentagslogik - das Plugin funktioniert also auch
 * allein. Ergebnis wird 15 Minuten zwischengespeichert.
 */
/**
 * Der Port, unter dem der LoxBerry-Webserver oertlich erreichbar ist.
 *
 * Hart auf 80 zu setzen geht meistens gut, aber eben nur meistens: wer den
 * Webserver umgestellt hat, bei dem laufen die oertlichen Aufrufe ins Leere -
 * und zwar lautlos, weil sie alle mit @ unterdrueckt sind. Der Port steht in
 * der general.json von LoxBerry; 80 bleibt der Rueckfall.
 */
function abfahrt_webport() {
    static $port = null;
    if ($port !== null) { return $port; }
    $port = 80;
    $lb = getenv('LBHOMEDIR') ?: (is_dir('/opt/loxberry') ? '/opt/loxberry' : '');
    if ($lb !== '') {
        $f = $lb . '/config/system/general.json';
        if (is_file($f)) {
            $g = json_decode((string) @file_get_contents($f), true);
            foreach (array('Webserver', 'WEBSERVER') as $ab) {
                if (isset($g[$ab]['Port']) && (int) $g[$ab]['Port'] > 0) {
                    $port = (int) $g[$ab]['Port'];
                    break;
                }
            }
        }
    }
    return $port;
}

/** Adresse eines oertlichen Plugin-Skripts, mit dem richtigen Port. */
function abfahrt_lokal_url($pfad) {
    $port = abfahrt_webport();
    return 'http://127.0.0.1' . ($port === 80 ? '' : ':' . $port) . $pfad;
}

function abfahrt_daytype() {
    static $cacheMem = null;
    if ($cacheMem !== null) {
        return $cacheMem;
    }
    $leer = ['feiertag' => 0, 'ferien' => 0, 'urlaub' => 0, 'quelle' => 'keine', 'name' => ''];
    $tmp = sys_get_temp_dir() . '/abfahrt_daytype.json';
    if (is_file($tmp) && time() - filemtime($tmp) < 900) {
        $c = json_decode((string) @file_get_contents($tmp), true);
        if (is_array($c) && ($c['datum'] ?? '') === date('Y-m-d')) {
            return $cacheMem = ($c + $leer);
        }
    }
    $res = $leer;
    // 1) JSON-Schnittstelle des Ferien-Plugins (laeuft dort mit korrekter Umgebung)
    $js = @file_get_contents(abfahrt_lokal_url('/plugins/ferien/ferien.php?json=1'), false,
        stream_context_create(['http' => ['timeout' => 4, 'user_agent' => 'LoxBerry Abfahrts-Assistent']]));
    $d = @json_decode((string) $js, true);
    if (is_array($d) && isset($d['heute'])) {
        $res['feiertag'] = !empty($d['heute']['feiertag']) ? 1 : 0;
        $res['ferien'] = !empty($d['heute']['ferien']) ? 1 : 0;
        $res['urlaub'] = !empty($d['heute']['urlaub']) ? 1 : 0;
        $res['name'] = (string) (($d['heute']['feiertag_name'] ?? '') ?: ($d['heute']['ferien_name'] ?? ''));
        $res['quelle'] = 'Ferien-Plugin';
    }
    // 2) Ersatzweise die Bibliothek direkt einbinden. WICHTIG: LBPPLUGINDIR zeigt
    //    hier auf den Abfahrts-Assistenten - ohne Umschalten wuerde das Ferien-
    //    Plugin im falschen Konfigurations- und Datenverzeichnis suchen.
    if ($res['quelle'] === 'keine') {
        $lb = getenv('LBHOMEDIR') ?: (is_dir('/opt/loxberry') ? '/opt/loxberry' : '');
        $kandidaten = [];
        if ($lb !== '') { $kandidaten[] = $lb . '/webfrontend/html/plugins/ferien/ferien_lib.php'; }
        $kandidaten[] = dirname(__DIR__, 3) . '/html/plugins/ferien/ferien_lib.php';
        $treffer = '';
        foreach ($kandidaten as $cand) {
            if (is_file($cand)) { $treffer = $cand; break; }
        }
        if ($treffer !== '') {
            $merker = getenv('LBPPLUGINDIR');
            putenv('LBPPLUGINDIR=ferien');
            include_once $treffer;
            if (function_exists('fer_state')) {
                $st = fer_state();
                if (is_array($st) && isset($st['heute'])) {
                    $res['feiertag'] = !empty($st['heute']['feiertag']) ? 1 : 0;
                    $res['ferien'] = !empty($st['heute']['ferien']) ? 1 : 0;
                    $res['urlaub'] = !empty($st['heute']['urlaub']) ? 1 : 0;
                    $res['name'] = (string) ($st['heute']['feiertag_name'] ?: $st['heute']['ferien_name']);
                    $res['quelle'] = 'Ferien-Plugin (Bibliothek)';
                }
            }
            putenv($merker === false ? 'LBPPLUGINDIR' : 'LBPPLUGINDIR=' . $merker);
        }
    }
    $res['datum'] = date('Y-m-d');
    @file_put_contents($tmp, json_encode($res));
    return $cacheMem = $res;
}

/**
 * Welcher Eintrag der Sperrzeiten-Tabelle gilt jetzt?
 * Reihenfolge: Urlaub -> Feiertag -> Ferien -> Wochentag. Ein Sondertag greift
 * nur, wenn sein Haken gesetzt ist; sonst faellt die Logik auf den Wochentag
 * zurueck. Rueckgabe: [Schluessel, Bezeichnung] oder [0, ''] wenn nichts aktiv.
 */
function abfahrt_quiet_rule(array $abfcfg) {
    $an = function ($k) use ($abfcfg) {
        return !empty($abfcfg['quiet'][$k]['on']);
    };
    $tag = abfahrt_daytype();
    if (!empty($tag['urlaub']) && $an(10)) { return [10, 'Urlaub']; }
    if (!empty($tag['feiertag']) && $an(8)) { return [8, 'Feiertag']; }
    if (!empty($tag['ferien']) && $an(9)) { return [9, 'Ferien']; }
    $d = (int) date('N'); // 1 = Montag ... 7 = Sonntag
    $namen = abfahrt_quiet_labels();
    return $an($d) ? [$d, $namen[$d]] : [0, ''];
}

/** Liegt "jetzt" in der Audio-Sperrzeit (Sondertag oder Wochentag)? */
function abfahrt_in_quiet(array $abfcfg, &$info = '') {
    list($k, $bez) = abfahrt_quiet_rule($abfcfg);
    if ($k === 0) {
        return false;
    }
    $q = $abfcfg['quiet'][$k];
    $now = (int) date('H') * 60 + (int) date('i');
    $p = function ($s) { $x = explode(':', (string) $s); return ((int) ($x[0] ?? 0)) * 60 + (int) ($x[1] ?? 0); };
    $from = $p($q['from']);
    $to = $p($q['to']);
    $in = ($from <= $to) ? ($now >= $from && $now < $to) : ($now >= $from || $now < $to);
    if ($in) {
        $info = 'Sperrzeit ' . $bez . ' ' . $q['from'] . '-' . $q['to'] . ' Uhr';
    }
    return $in;
}

/** Audio-Freigabe (Checkbox + Sperrzeit). */
function abfahrt_audio_allowed(array $abfcfg, &$why = '') {
    if (empty($abfcfg['notify']['audio'])) {
        $why = 'Audioausgabe deaktiviert (Plugin-Einstellung)';
        return false;
    }
    $info = '';
    if (abfahrt_in_quiet($abfcfg, $info)) {
        $why = $info;
        return false;
    }
    return true;
}



/** Ist die Ortsangabe ein Online-/Video-Termin (keine Fahrt noetig)? */
function abfahrt_loc_ignored($loc, array $abfcfg) {
    $loc = trim((string) $loc);
    if ($loc === '') {
        return false;
    }
    if (preg_match('#^https?://#i', $loc)) {
        return true; // Meeting-Link statt Adresse
    }
    foreach (explode(',', (string) ($abfcfg['ignore_locations'] ?? '')) as $kw) {
        $kw = trim($kw);
        if ($kw === '') {
            continue;
        }
        if (preg_match('/(?<![\p{L}\p{N}])' . preg_quote($kw, '/') . '(?![\p{L}\p{N}])/ui', $loc)) {
            return true;
        }
    }
    return false;
}

/* ---------------- Logging ---------------- */

function abfahrt_logfile() {
    $lbhomedir = getenv('LBHOMEDIR') ?: (is_dir('/opt/loxberry') ? '/opt/loxberry' : '');
    $self = basename(__DIR__);
    if ($lbhomedir) {
        $dir = $lbhomedir . '/log/plugins/' . $self;
    } else {
        $dir = sys_get_temp_dir() . '/abfahrtsassistent';
    }
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    return $dir . '/abfahrt.log';
}

function abfahrt_log($msg) {
    $f = abfahrt_logfile();
    if (is_file($f) && filesize($f) > 512000) { // Rotation: letzte 200 Zeilen behalten
        $tail = array_slice(file($f, FILE_IGNORE_NEW_LINES) ?: [], -200);
        @file_put_contents($f, implode("\n", $tail) . "\n");
    }
    @file_put_contents($f, '[' . date('Y-m-d H:i:s') . '] ' . $msg . "\n", FILE_APPEND);
}

/* ---------------- iCal ---------------- */

/** ICS einer Kalender-URL holen (10 Minuten Cache). */
function abfahrt_fetch_ics($url) {
    $cache = abfahrt_tmpdir() . '/ics_' . md5($url);
    if (is_file($cache) && time() - filemtime($cache) < 600) {
        return (string) file_get_contents($cache);
    }
    $neu = abfahrt_http_get($url);
    if ($neu !== false && strpos($neu, 'BEGIN:VCALENDAR') !== false) {
        file_put_contents($cache, $neu);
        return $neu;
    }
    return is_file($cache) ? (string) file_get_contents($cache) : false;
}

/**
 * DTSTART/EXDATE/RECURRENCE-ID-Rohwert -> Unix-ts (null bei Ganztages-/Parsefehler).
 */
function abfahrt_dt2ts($raw, $tzid) {
    $raw = trim($raw);
    if (strlen($raw) == 8) {
        return null; // reines Datum (ganztaegig)
    }
    if (substr($raw, -1) == 'Z') {
        return strtotime($raw);
    }
    try {
        $tz = $tzid ? new DateTimeZone($tzid) : new DateTimeZone('Europe/Berlin');
    } catch (Exception $e) {
        $tz = new DateTimeZone('Europe/Berlin');
    }
    $d = DateTime::createFromFormat('Ymd\THis', $raw, $tz);
    return $d ? $d->getTimestamp() : null;
}

/**
 * Alle Kalender parsen: naechster zukuenftiger Termin MIT Ortsangabe im
 * Zeitfenster. Serientermine werden vollstaendig expandiert:
 * RRULE FREQ=DAILY/WEEKLY/MONTHLY/YEARLY mit INTERVAL, BYDAY (woechentlich),
 * UNTIL, COUNT; EXDATE; verschobene/geloeschte Einzel-Instanzen einer Serie
 * (RECURRENCE-ID / STATUS:CANCELLED). Zeitzonen-/DST-sicher via DateTime.
 * Rueckgabe: [ts, location, summary, calendar_name] oder null.
 */
function abfahrt_next_event(array $abfcfg, &$diag = []) {
    $now = time();
    $maxTs = $now + max(1, (int) $abfcfg['lookahead_hours']) * 3600;
    $WD = ['MO' => 1, 'TU' => 2, 'WE' => 3, 'TH' => 4, 'FR' => 5, 'SA' => 6, 'SU' => 7];
    $best = null;

    foreach ($abfcfg['calendars'] as $cal) {
        $url = trim((string) ($cal['url'] ?? ''));
        $name = trim((string) ($cal['name'] ?? ''));
        if ($url === '') {
            continue;
        }
        $ics = abfahrt_fetch_ics($url);
        if ($ics === false) {
            $diag[] = "Kalender '$name': nicht ladbar";
            continue;
        }
        $ics = preg_replace("/\r?\n[ \t]/", '', $ics); // Zeilenfaltung aufloesen

        $singles = [];    // [ts, loc, sum]
        $masters = [];
        $overridden = []; // "uid|origTs" => 1
        foreach (explode('BEGIN:VEVENT', $ics) as $i => $ev) {
            if ($i === 0) {
                continue;
            }
            if (!preg_match('/DTSTART(?:;TZID=([^:;]+))?(?:;VALUE=DATE)?:([0-9TZ]+)/', $ev, $md)) {
                continue;
            }
            $tzid = $md[1];
            $ts = abfahrt_dt2ts($md[2], $tzid);
            $uid = '';
            if (preg_match('/UID:([^\r\n]+)/', $ev, $mu)) {
                $uid = trim($mu[1]);
            }
            $sum = '';
            if (preg_match('/SUMMARY(?:;[^:]*)?:([^\r\n]+)/', $ev, $ms)) {
                $sum = trim(str_replace(['\\,', '\\;', '\\n'], [',', ';', ' '], $ms[1]));
            }
            $loc = '';
            if (preg_match('/LOCATION:([^\r\n]+)/', $ev, $ml)) {
                $loc = trim(str_replace(['\\,', '\\;', '\\n'], [',', ';', ' '], $ml[1]));
            }
            if ($loc !== '' && abfahrt_loc_ignored($loc, $abfcfg)) {
                $loc = ''; // Online-/Videotermin: keine Fahrzeitberechnung
            }
            $cancelled = (bool) preg_match('/STATUS:CANCELLED/', $ev);

            // Verschobene/geloeschte Einzel-Instanz einer Serie
            if (preg_match('/RECURRENCE-ID(?:;TZID=([^:;]+))?(?:;VALUE=DATE)?:([0-9TZ]+)/', $ev, $mr)) {
                $orig = abfahrt_dt2ts($mr[2], $mr[1] ?: $tzid);
                if ($orig !== null) {
                    $overridden[$uid . '|' . $orig] = 1;
                }
                if (!$cancelled && $ts !== null) {
                    $singles[] = [$ts, $loc, $sum];
                }
                continue;
            }
            if ($cancelled || $ts === null) {
                continue;
            }

            if (preg_match('/RRULE:([^\r\n]+)/', $ev, $mrr)) {
                $ex = [];
                if (preg_match_all('/EXDATE(?:;TZID=([^:;]+))?(?:;VALUE=DATE)?:([^\r\n]+)/', $ev, $me, PREG_SET_ORDER)) {
                    foreach ($me as $e) {
                        foreach (explode(',', trim($e[2])) as $v) {
                            $x = abfahrt_dt2ts($v, $e[1] ?: $tzid);
                            if ($x !== null) {
                                $ex[$x] = 1;
                            }
                        }
                    }
                }
                $masters[] = ['uid' => $uid, 'ts' => $ts, 'tzid' => $tzid ?: 'Europe/Berlin',
                              'loc' => $loc, 'sum' => $sum, 'rrule' => trim($mrr[1]), 'ex' => $ex];
            } else {
                $singles[] = [$ts, $loc, $sum];
            }
        }

        // Serien expandieren (Vorkommen im Fenster jetzt..maxTs)
        foreach ($masters as $mst) {
            $r = [];
            foreach (explode(';', $mst['rrule']) as $kv) {
                $p = explode('=', $kv, 2);
                if (count($p) == 2) {
                    $r[strtoupper($p[0])] = strtoupper(trim($p[1]));
                }
            }
            $freq = $r['FREQ'] ?? '';
            if ($freq === '') {
                continue;
            }
            $iv = max(1, (int) ($r['INTERVAL'] ?? 1));
            $until = null;
            if (isset($r['UNTIL'])) {
                $until = abfahrt_dt2ts($r['UNTIL'], null);
                if ($until === null) {
                    $until = strtotime(substr($r['UNTIL'], 0, 8) . ' 23:59:59');
                }
            }
            $count = isset($r['COUNT']) ? (int) $r['COUNT'] : null;
            try {
                $tz = new DateTimeZone($mst['tzid']);
            } catch (Exception $e) {
                $tz = new DateTimeZone('Europe/Berlin');
            }
            $start = (new DateTime('@' . $mst['ts']))->setTimezone($tz);

            $byday = [];
            if ($freq == 'WEEKLY') {
                foreach (explode(',', $r['BYDAY'] ?? '') as $d) {
                    $d = preg_replace('/[^A-Z]/', '', $d);
                    if (isset($WD[$d])) {
                        $byday[$WD[$d]] = 1;
                    }
                }
                if (!$byday) {
                    $byday[(int) $start->format('N')] = 1;
                }
            }

            $emitted = 0;
            if ($freq == 'DAILY' || $freq == 'WEEKLY') {
                $wkRef = clone $start;
                $wkRef->modify('monday this week')->setTime(12, 0, 0);
                $cur = clone $start;

                /* Vorspulen statt Tag fuer Tag hinlaufen.
                 *
                 * Die Schleife bricht zwar ab, sobald $ts ueber $maxTs liegt -
                 * sie laeuft also nicht bis in alle Ewigkeit. Sie beginnt aber
                 * bei DTSTART, und das kann Jahre zurueckliegen: eine
                 * woechentliche Serie von 2019 bedeutet rund 2500 Durchlaeufe
                 * mit je mehreren DateTime-Operationen. Bei einem
                 * gewachsenen Google-Kalender mit dreissig solcher Serien sind
                 * das Zehntausende - je Aufruf, alle fuenf Minuten, auf einem
                 * Raspberry Pi.
                 *
                 * Also wird in ganzen Intervallschritten bis kurz vor die
                 * Gegenwart gesprungen. Ganze Schritte deshalb, weil sonst die
                 * Ausrichtung kaputtginge, an der die Schleife erkennt, ob ein
                 * Tag zur Serie gehoert.
                 *
                 * Bei gesetztem COUNT wird NICHT vorgespult: dort zaehlt die
                 * Schleife die Vorkommen mit, und eine uebersprungene Anzahl
                 * liesse sich nur mit Muehe fehlerfrei nachrechnen. Solche
                 * Serien sind ohnehin kurz - und der Abbruch bei $maxTs greift
                 * dort genauso.
                 */
                if ($count === null && $cur->getTimestamp() < $now) {
                    if ($freq == 'DAILY') {
                        $tage = (int) $start->diff(new DateTime('@' . $now))->format('%a');
                        $sprung = intdiv($tage, $iv) * $iv;
                        if ($sprung > 0) { $cur->modify('+' . $sprung . ' day'); }
                    } else {
                        $wochen = (int) floor(($now - $mst['ts']) / (7 * 86400));
                        $sprung = intdiv($wochen, $iv) * $iv;
                        if ($sprung > 0) { $cur->modify('+' . ($sprung * 7) . ' day'); }
                    }
                }

                // 40000 bleibt als letzte Reissleine stehen. Erreicht wird sie
                // nach dem Vorspulen nicht mehr - der Abbruch bei $maxTs kommt
                // lange vorher.
                $iter = 0;
                while ($iter++ < 40000) {
                    $ts = $cur->getTimestamp();
                    if ($freq == 'DAILY') {
                        $days = (int) $start->diff($cur)->format('%a');
                        $okDay = ($days % $iv) == 0;
                    } else {
                        $wk = clone $cur;
                        $wk->modify('monday this week')->setTime(12, 0, 0);
                        $weeks = (int) round(((int) $wkRef->diff($wk)->format('%a')) / 7);
                        $okDay = isset($byday[(int) $cur->format('N')]) && ($weeks % $iv) == 0;
                    }
                    if ($okDay && $ts >= $mst['ts']) {
                        $emitted++;
                        if ($count !== null && $emitted > $count) {
                            break;
                        }
                        if ($until !== null && $ts > $until) {
                            break;
                        }
                        if ($ts > $now && $ts <= $maxTs && !isset($mst['ex'][$ts]) && !isset($overridden[$mst['uid'] . '|' . $ts])) {
                            $singles[] = [$ts, $mst['loc'], $mst['sum']];
                        }
                    }
                    if ($ts > $maxTs || ($until !== null && $ts > $until)) {
                        break;
                    }
                    $cur->modify('+1 day');
                }
            } elseif ($freq == 'MONTHLY' || $freq == 'YEARLY') {
                $step = ($freq == 'MONTHLY') ? 'month' : 'year';
                for ($k = 0; $k < 1200; $k++) {
                    $occ = clone $start;
                    if ($k > 0) {
                        $occ->modify('+' . ($k * $iv) . ' ' . $step);
                    }
                    $ts = $occ->getTimestamp();
                    $emitted++;
                    if ($count !== null && $emitted > $count) {
                        break;
                    }
                    if ($until !== null && $ts > $until) {
                        break;
                    }
                    if ($ts > $maxTs) {
                        break;
                    }
                    if ($ts > $now && !isset($mst['ex'][$ts]) && !isset($overridden[$mst['uid'] . '|' . $ts])) {
                        $singles[] = [$ts, $mst['loc'], $mst['sum']];
                    }
                }
            }
        }

        $count2 = 0;
        foreach ($singles as $s) {
            list($ts, $loc, $sum) = $s;
            if ($ts <= $now || $ts > $maxTs || $loc === '') {
                continue;
            }
            $count2++;
            if ($best === null || $ts < $best[0]) {
                $best = [$ts, $loc, $sum, $name];
            }
        }
        $diag[] = "Kalender '$name': $count2 Termin(e) mit Ort im Zeitfenster";
    }
    return $best;
}

/* ---------------- Routing (Verkehrslage) ---------------- */

/**
 * Adresse -> "lat,lon" (dauerhafter Cache je Provider+Adresse).
 *
 * Nur fuer TomTom und HERE: deren Routenschnittstellen wollen Koordinaten.
 * Google nimmt Adressen direkt entgegen und loest sie selbst auf - dort wird
 * diese Funktion deshalb gar nicht erst aufgerufen, und ein Google-Zweig
 * hier waere Code, den nie jemand ausfuehrt.
 */
function abfahrt_geocode($address, array $abfcfg, &$err = '') {
    $key = $abfcfg['api_key'];
    $provider = $abfcfg['provider'];
    $cache = abfahrt_tmpdir() . '/geo_' . md5($provider . '|' . $address);
    if (is_file($cache)) {
        return trim((string) file_get_contents($cache));
    }
    $pos = null;
    if ($provider === 'tomtom') {
        $url = 'https://api.tomtom.com/search/2/geocode/' . rawurlencode($address) . '.json?key=' . rawurlencode($key) . '&limit=1&countrySet=DE,AT,CH';
        $g = @json_decode((string) abfahrt_http_get($url), true);
        if (isset($g['results'][0]['position'])) {
            $pos = $g['results'][0]['position']['lat'] . ',' . $g['results'][0]['position']['lon'];
        }
    } elseif ($provider === 'here') {
        $url = 'https://geocode.search.hereapi.com/v1/geocode?q=' . rawurlencode($address) . '&apiKey=' . rawurlencode($key);
        $g = @json_decode((string) abfahrt_http_get($url), true);
        if (isset($g['items'][0]['position'])) {
            $pos = $g['items'][0]['position']['lat'] . ',' . $g['items'][0]['position']['lng'];
        }
    }
    if ($pos === null) {
        $err = "Geocoding fehlgeschlagen ($provider) fuer: $address";
        return false;
    }
    file_put_contents($cache, $pos);
    return $pos;
}

/**
 * Wie lange darf eine berechnete Fahrzeit gelten?
 *
 * Bisher starr fuenf Minuten - auch dann, wenn der Termin erst in zehn Stunden
 * beginnt. Die Verkehrslage in zehn Stunden interessiert aber niemanden, und
 * TomTom zaehlt jede Abfrage gegen das Tageskontingent von 2500. Also skaliert
 * die Haltbarkeit mit der Naehe zum Termin: aus der Ferne stuendlich, in der
 * letzten Stunde minutengenau.
 */
function abfahrt_route_ttl($minutenBisTermin) {
    if ($minutenBisTermin === null) { return 300; }
    $m = (int) $minutenBisTermin;
    if ($m > 180) { return 3600; }   // mehr als drei Stunden hin: stuendlich
    if ($m > 60)  { return 900; }    // eine bis drei Stunden: viertelstuendlich
    return 300;                      // letzte Stunde: wie bisher
}

/** Wie lange darf im Stoerungsfall eine alte Fahrzeit weiterbenutzt werden? */
define('ABFAHRT_ROUTE_GNADE', 3600);

/**
 * Aktuelle Fahrzeit (Minuten, inkl. Verkehr) von der Abfahrtsadresse zum Ziel.
 *
 * Haltbarkeit je nach Naehe zum Termin, siehe abfahrt_route_ttl().
 *
 * WARUM BEI EINEM API-FEHLER DER ALTE WERT WEITERGILT
 * Faellt der Kartendienst kurz aus, lieferte diese Funktion frueher false, und
 * termin.php brach die ganze Berechnung mit OK=0 und ABFAHRT_IN=9999 ab. Der
 * Schwellwertschalter in Loxone fiel damit ab - und sprang fuenf Minuten
 * spaeter, wenn der Dienst wieder da war, erneut an. Ergebnis: derselbe Termin
 * loeste ein zweites Mal Ansage und Push aus. Ein Aussetzer des Anbieters darf
 * aber nicht wie ein neuer Termin aussehen.
 *
 * Deshalb wird der letzte gute Wert bis zu ABFAHRT_ROUTE_GNADE Sekunden
 * weiterverwendet. Das ist vertretbar: eine Fahrzeit aendert sich in einer
 * Stunde selten dramatisch, und ein leicht veralteter Wert ist allemal besser
 * als eine Falschmeldung. $veraltet sagt dem Aufrufer, dass es so weit
 * gekommen ist - die Statuszeile traegt das als FEHLER=7 nach Loxone.
 */
function abfahrt_route_minutes($destAddress, array $abfcfg, &$err = '', $minutenBisTermin = null, &$veraltet = false) {
    $veraltet = false;
    $cache = abfahrt_tmpdir() . '/route_' . md5($abfcfg['provider'] . '|' . $destAddress);
    $alter = is_file($cache) ? time() - filemtime($cache) : null;
    if ($alter !== null && $alter < abfahrt_route_ttl($minutenBisTermin)) {
        return (float) file_get_contents($cache);
    }
    $key = $abfcfg['api_key'];
    $provider = $abfcfg['provider'];
    $minutes = false;

    if ($provider === 'google') {
        // Google akzeptiert Adressen direkt (kein separates Geocoding noetig)
        $url = 'https://maps.googleapis.com/maps/api/directions/json?origin=' . rawurlencode($abfcfg['home_address'])
             . '&destination=' . rawurlencode($destAddress)
             . '&mode=driving&departure_time=now&traffic_model=best_guess&key=' . rawurlencode($key);
        $r = @json_decode((string) abfahrt_http_get($url), true);
        if (isset($r['routes'][0]['legs'][0])) {
            $leg = $r['routes'][0]['legs'][0];
            $sec = $leg['duration_in_traffic']['value'] ?? ($leg['duration']['value'] ?? null);
            if ($sec !== null) {
                $minutes = round($sec / 60, 1);
            }
        }
        if ($minutes === false) {
            $err = 'Google-Routing fehlgeschlagen' . (isset($r['status']) ? ' (' . $r['status'] . ')' : '');
        }
    } else {
        // Kein vorzeitiges return: auch ein misslungenes Geocoding soll unten
        // noch in die Gnadenfrist laufen duerfen, sonst flattert es genauso.
        $home = abfahrt_geocode($abfcfg['home_address'], $abfcfg, $err);
        $dest = ($home === false) ? false : abfahrt_geocode($destAddress, $abfcfg, $err);
        if ($home === false || $dest === false) {
            $minutes = false;
        } elseif ($provider === 'tomtom') {
            $url = 'https://api.tomtom.com/routing/1/calculateRoute/' . $home . ':' . $dest . '/json?key=' . rawurlencode($key) . '&traffic=true&travelMode=car';
            $r = @json_decode((string) abfahrt_http_get($url), true);
            if (isset($r['routes'][0]['summary']['travelTimeInSeconds'])) {
                $minutes = round($r['routes'][0]['summary']['travelTimeInSeconds'] / 60, 1);
            } else {
                $err = 'TomTom-Routing fehlgeschlagen';
            }
        } elseif ($provider === 'here') {
            $url = 'https://router.hereapi.com/v8/routes?transportMode=car&origin=' . $home . '&destination=' . $dest . '&return=summary&apikey=' . rawurlencode($key);
            $r = @json_decode((string) abfahrt_http_get($url), true);
            if (isset($r['routes'][0]['sections'][0]['summary']['duration'])) {
                $minutes = round($r['routes'][0]['sections'][0]['summary']['duration'] / 60, 1);
            } else {
                $err = 'HERE-Routing fehlgeschlagen';
            }
        } else {
            $err = 'Unbekannter Kartendienst: ' . $provider;
        }
    }
    if ($minutes !== false) {
        file_put_contents($cache, $minutes);
        return $minutes;
    }

    // Der Kartendienst hat nicht geliefert. Gibt es einen Wert aus der letzten
    // Stunde, wird der weitergereicht, statt die Berechnung abzubrechen.
    if ($alter !== null && $alter <= ABFAHRT_ROUTE_GNADE) {
        $veraltet = true;
        abfahrt_log('Kartendienst antwortet nicht (' . $err . ') - benutze die Fahrzeit von vor '
                  . (int) round($alter / 60) . ' min weiter.');
        $err = '';
        return (float) file_get_contents($cache);
    }
    return false;
}

/* ---------------- TTS ---------------- */

/** TTS-URL fuer die konfigurierte Ausgabe bauen. Fuer mode=audioserver: null. */
function abfahrt_tts_url($text, array $tts) {
    $mode = $tts['mode'];
    if ($mode === 'audioserver') {
        return null; // Original Loxone Audioserver: TTS nur ueber Loxone Config (Textgenerator -> TTS-Eingang)
    }
    if ($mode === 'musicserver') {
        // Zonenliste normalisieren: "2,4,6" + Lautstaerke-Feld -> "2~25,4~25,6~25".
        // Explizite Angaben "Zone~Lautstaerke" haben Vorrang.
        $vol = max(1, min(100, (int) $tts['volume']));
        $zones = [];
        foreach (explode(',', (string) $tts['zones']) as $z) {
            $z = trim($z);
            if ($z === '') {
                continue;
            }
            $zones[] = (strpos($z, '~') === false) ? $z . '~' . $vol : $z;
        }
        $zoneStr = $zones ? implode(',', $zones) : '1~' . $vol;
        return 'http://' . $tts['ip'] . ':' . (int) $tts['port'] . '/audio/grouped/tts/' . $zoneStr . '/' . rawurlencode($tts['lang'] . '|' . $text);
    }
    // ms4h (MusicServer4Home / Audioserver4Home) und custom: Vorlage mit Platzhaltern
    $tpl = trim((string) $tts['template']);
    if ($tpl === '') {
        // Standard-Vorlage MusicServer4Home
        $tpl = 'http://{ip}:{port}/tts?text={text}&zone={zones}&vol={vol}';
    }
    return str_replace(
        ['{ip}', '{port}', '{zones}', '{vol}', '{lang}', '{text}'],
        [$tts['ip'], (int) $tts['port'], $tts['zones'], (int) $tts['volume'], $tts['lang'], rawurlencode($text)],
        $tpl
    );
}

/* ==================================================================
 * Sprache (Pflicht: Deutsch und Englisch)
 *
 * Englisch ist die Rueckfallebene, nicht Deutsch: wer eine dritte Sprache
 * eingestellt hat, versteht eher Englisch. Deshalb muss language_en.ini
 * immer vollstaendig sein.
 * ================================================================== */

/* ==================================================================
 * Feldtabelle - EINE Quelle fuer Statuszeile, MQTT-Themen und Vorlage
 *
 * Drei Stellen, die dieselben Felder aufzaehlen, laufen frueher oder spaeter
 * auseinander; dann stimmt die Vorlage nicht mehr zur Wirklichkeit, und der
 * Anwender sucht den Fehler in Loxone Config.
 * ================================================================== */

/** name => [analog, min, max, Sprachschluessel] */
function abfahrt_felder() {
    return [
        'OK'         => [0, 0, 1, 'FELD.OK'],
        'MINSTART'   => [1, 0, 99999, 'FELD.MINSTART'],
        'FAHRT'      => [1, 0, 1440, 'FELD.FAHRT'],
        'ABFAHRT_IN' => [1, -9999, 9999, 'FELD.ABFAHRT_IN'],
        'FEHLER'     => [1, 0, 9, 'FELD.FEHLER'],
        'ALTER'      => [1, 0, 86400, 'FELD.ALTER'],
        'AUDIO'      => [0, 0, 1, 'FELD.AUDIO'],
        'PUSH'       => [0, 0, 1, 'FELD.PUSH'],
    ];
}

/* ==================================================================
 * Zwischenstand
 *
 * Gerechnet wird im Hintergrund (bin/abfahrt_dienst.php), abgeholt wird nur
 * noch. Frueher rechnete termin.php bei jedem Aufruf selbst - damit haing die
 * Zahl der Anfragen an den Kartendienst daran, wie oft Loxone fragt, und ein
 * zweiter Abfrager haette das Kontingent verdoppelt.
 * ================================================================== */

function abfahrt_standfile() {
    return abfahrt_tmpdir() . '/stand.json';
}

function abfahrt_stand() {
    $d = is_file(abfahrt_standfile())
        ? json_decode((string) @file_get_contents(abfahrt_standfile()), true) : null;
    if (!is_array($d)) { $d = []; }
    return $d + [
        'zeit' => 0, 'ok' => 0, 'fehler' => 0, 'grund' => '',
        'minstart' => 9999, 'fahrt' => 0, 'abfahrt_in' => 9999,
        'titel' => '', 'ort' => '', 'kalender' => '', 'beginn' => '',
    ];
}

function abfahrt_stand_write(array $st) {
    $js = json_encode($st, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($js === false) { return false; }
    return @file_put_contents(abfahrt_standfile(), $js, LOCK_EX) !== false;
}

/**
 * Die Statuszeile fuer den Miniserver.
 *
 * Jedem Feld geht ein Semikolon voran, und die Befehlserkennungen in der
 * Vorlage suchen ebenfalls mit fuehrendem Semikolon. Das ist kein Zierat:
 * Loxone sucht die Zeichenkette woertlich und nimmt den ersten Treffer, und
 * ohne Semikolon faende "FAHRT=" auch die Stelle in "ABFAHRT_IN=" - sobald
 * die Reihenfolge einmal wechselt, stuende der falsche Wert im Eingang.
 */
function abfahrt_zeile(array $st, array $abfcfg) {
    $why = '';
    $werte = abfahrt_werte($st, $abfcfg);
    $teile = ['TERMIN'];
    foreach ($werte as $k => $v) { $teile[] = $k . '=' . $v; }
    return implode(';', $teile);
}

/** Alle Feldwerte als name => Wert (fuer Zeile, MQTT und Anzeige). */
function abfahrt_werte(array $st, array $abfcfg) {
    $why = '';
    $alter = $st['zeit'] > 0 ? time() - (int) $st['zeit'] : 86400;
    return [
        'OK'         => (int) $st['ok'] ? 1 : 0,
        'MINSTART'   => (int) $st['minstart'],
        'FAHRT'      => 0 + $st['fahrt'],
        'ABFAHRT_IN' => (int) $st['abfahrt_in'],
        'FEHLER'     => (int) $st['fehler'],
        'ALTER'      => max(0, min(86400, $alter)),
        'AUDIO'      => abfahrt_audio_allowed($abfcfg, $why) ? 1 : 0,
        'PUSH'       => empty($abfcfg['notify']['push']) ? 0 : 1,
    ];
}

/**
 * Alles rechnen und den Zwischenstand fortschreiben.
 * Rueckgabe: [Zwischenstand, Diagnosezeilen]
 */
function abfahrt_berechnen(array $abfcfg = null) {
    if ($abfcfg === null) { $abfcfg = abfahrt_config(); }
    $st = abfahrt_stand();
    $diag = [];

    $setz = function ($code, $grund) use (&$st) {
        $st['ok'] = 0;
        $st['fehler'] = $code;
        $st['grund'] = $grund;
        $st['minstart'] = 9999;
        $st['fahrt'] = 0;
        $st['abfahrt_in'] = 9999;
        $st['zeit'] = time();
        abfahrt_stand_write($st);
        return $st;
    };

    $hasCal = false;
    foreach ($abfcfg['calendars'] as $cal) {
        if (trim((string) ($cal['url'] ?? '')) !== '') { $hasCal = true; break; }
    }
    if (!$hasCal)                                { return [$setz(1, 'Kein Kalender konfiguriert (Plugin-Oberflaeche oeffnen).'), $diag]; }
    if (trim($abfcfg['api_key']) === '')         { return [$setz(2, 'Kein API-Key konfiguriert (Plugin-Oberflaeche oeffnen).'), $diag]; }
    if (trim($abfcfg['home_address']) === '')    { return [$setz(3, 'Keine Abfahrtsadresse konfiguriert (Plugin-Oberflaeche oeffnen).'), $diag]; }

    $best = abfahrt_next_event($abfcfg, $diag);
    if ($best === null) {
        return [$setz(4, 'Kein Termin mit Ort in den naechsten ' . (int) $abfcfg['lookahead_hours'] . ' Stunden.'), $diag];
    }
    list($ts, $loc, $sum, $calname) = $best;
    $minstart = (int) round(($ts - time()) / 60);

    $err = '';
    $veraltet = false;
    $fahrt = abfahrt_route_minutes($loc, $abfcfg, $err, $minstart, $veraltet);
    if ($fahrt === false) {
        $st = $setz(6, $err);
        // Titel und Ort sind bekannt, nur die Fahrzeit fehlt - das gehoert in
        // die Anzeige, sonst steht dort nach einer Stoerung gar nichts mehr.
        $st['titel'] = $sum;
        $st['ort'] = $loc;
        $st['kalender'] = $calname;
        $st['beginn'] = date('d.m.Y H:i', $ts);
        $st['minstart'] = $minstart;
        abfahrt_stand_write($st);
        return [$st, $diag];
    }

    $st['ok'] = 1;
    $st['fehler'] = $veraltet ? 7 : 0;
    $st['grund'] = $veraltet ? 'Kartendienst nicht erreichbar - letzte bekannte Fahrzeit gilt weiter.' : '';
    $st['minstart'] = $minstart;
    $st['fahrt'] = $fahrt;
    $st['abfahrt_in'] = (int) round($minstart - $fahrt - (int) $abfcfg['arrival_min'] - (int) $abfcfg['buffer_min']);
    $st['titel'] = $sum;
    $st['ort'] = $loc;
    $st['kalender'] = $calname;
    $st['beginn'] = date('d.m.Y H:i', $ts);
    $st['zeit'] = time();
    abfahrt_stand_write($st);

    // Titel fuer die Ansage (termin_say.php) - Format unveraendert, damit
    // bestehende Einbindungen weiterlaufen.
    $js = json_encode([
        'titel' => $sum, 'ort' => $loc, 'kalender' => $calname,
        'beginn' => $st['beginn'], 'minstart' => $minstart,
        'fahrt' => $fahrt, 'abfahrt_in' => $st['abfahrt_in'],
    ], JSON_UNESCAPED_UNICODE);
    if ($js !== false) { @file_put_contents(abfahrt_tmpdir() . '/titel.json', $js); }

    return [$st, $diag];
}

/* ==================================================================
 * MQTT - der Regelweg nach Loxone
 * ================================================================== */

function abfahrt_mqtt_zustand() {
    $lb = getenv('LBHOMEDIR') ?: (is_dir('/opt/loxberry') ? '/opt/loxberry' : '');
    $aus = ['gefunden' => false, 'udpport' => 0, 'autostart' => false];
    if ($lb === '') { return $aus; }
    $f = $lb . '/config/system/general.json';
    if (!is_file($f)) { return $aus; }
    $d = json_decode((string) @file_get_contents($f), true);
    if (!isset($d['Mqtt'])) { return $aus; }
    $aus['gefunden'] = true;
    $aus['udpport'] = isset($d['Mqtt']['Udpinport']) ? (int) $d['Mqtt']['Udpinport'] : 0;
    $aus['autostart'] = !empty($d['Mqtt']['Autostart']);
    return $aus;
}

/**
 * Werte an das MQTT-Gateway von LoxBerry schieben (UDP-Weiterleitung).
 *
 * Das Gateway ist Teil des Systems, kein Plugin - eingeschaltet wird es unter
 * System -> MQTT Gateway.
 */
function abfahrt_mqtt_senden(array $werte, array $abfcfg = null) {
    if ($abfcfg === null) { $abfcfg = abfahrt_config(); }
    if (empty($abfcfg['mqtt_ein'])) { return false; }
    $m = abfahrt_mqtt_zustand();
    if (!$m['gefunden'] || !$m['udpport']) { return false; }
    $sock = @fsockopen('udp://127.0.0.1', (int) $m['udpport'], $en, $es, 2);
    if (!$sock) { return false; }
    $raus = 0;
    foreach ($werte as $name => $wert) {
        if (@fwrite($sock, 'publish ' . $abfcfg['mqtt_topic'] . '/' . $name . ' ' . $wert . "\n") !== false) {
            $raus++;
        }
    }
    @fclose($sock);
    if ($werte && $raus === 0) {
        abfahrt_log('MQTT: keine Zeile liess sich absenden (UDP-Eingang ' . (int) $m['udpport'] . ').');
        return false;
    }
    return true;
}

/* ==================================================================
 * Loxone-Vorlage (XML-Export)
 *
 * Geprueefter PHP-Nachbau des LoxoneTemplateBuilder - Attributreihenfolge,
 * CRLF und der Tabulator vor den Kindelementen entsprechen dem Original.
 * Uebernommen aus LoxBerry-Plugin-APC-UPS, nur das Kuerzel getauscht.
 * ================================================================== */

function abfahrt_x($s) {
    return htmlspecialchars((string) $s, ENT_QUOTES | ENT_XML1, 'UTF-8');
}

function abfahrt_xml_virtual_in_http($kopf, $cmds) {
    $crlf = "\r\n";
    $o = '<?xml version="1.0" encoding="utf-8"?>' . $crlf;
    $o .= '<VirtualInHttp ';
    $o .= 'Title="' . abfahrt_x($kopf['title']) . '" ';
    $o .= 'Comment="' . abfahrt_x($kopf['comment'] ?? '') . '" ';
    $o .= 'Address="' . abfahrt_x($kopf['address'] ?? '') . '" ';
    $o .= 'PollingTime="' . abfahrt_x($kopf['polling'] ?? '60') . '"';
    $o .= '>' . $crlf;
    foreach ($cmds as $c) {
        $o .= "\t" . '<VirtualInHttpCmd ';
        $o .= 'Title="' . abfahrt_x($c['title']) . '" ';
        $o .= 'Comment="' . abfahrt_x($c['comment']) . '" ';
        $o .= 'Check="' . abfahrt_x($c['check']) . '" ';
        $o .= 'Signed="' . ($c['min'] < 0 ? 'true' : 'false') . '" ';
        $o .= 'Analog="' . (!empty($c['analog']) ? 'true' : 'false') . '" ';
        $o .= 'SourceValLow="0" ';
        $o .= 'DestValLow="0" ';
        $o .= 'SourceValHigh="1" ';
        $o .= 'DestValHigh="1" ';
        $o .= 'DefVal="0" ';
        $o .= 'MinVal="' . (int) $c['min'] . '" ';
        $o .= 'MaxVal="' . (int) $c['max'] . '"';
        $o .= '/>' . $crlf;
    }
    $o .= '</VirtualInHttp>' . $crlf;
    return $o;
}

/** [Dateiname, Inhalt] der Importdatei fuer Loxone Config. */
function abfahrt_vorlage($host = '') {
    if ($host === '') { $host = gethostname() ?: 'loxberry'; }
    $plugindir = getenv('LBPPLUGINDIR') ?: 'abfahrtsassistent';
    $cmds = [];
    foreach (abfahrt_felder() as $name => $d) {
        list($analog, $min, $max, $schluessel) = $d;
        $cmds[] = [
            'title'   => 'ABFAHRT_' . $name,
            'comment' => trim(strip_tags(html_entity_decode(abfahrt_t($schluessel), ENT_QUOTES, 'UTF-8'))),
            'check'   => '\i;' . $name . '=\i\v',
            'analog'  => $analog, 'min' => $min, 'max' => $max,
        ];
    }
    return ['VI_abfahrtsassistent.xml', abfahrt_xml_virtual_in_http([
        'title'   => 'Abfahrts-Assistent',
        'address' => 'http://' . $host . '/plugins/' . $plugindir . '/termin.php',
        'polling' => '60',
        'comment' => 'Erzeugt vom LoxBerry-Plugin Abfahrts-Assistent (' . date('d.m.Y') . '). '
                   . 'Loxone Config legt beim Import neu an und ueberschreibt nichts - '
                   . 'zweimal eingelesen ergibt doppelte Bausteine.',
    ], $cmds)];
}

/* ==================================================================
 * Selbstpruefung
 *
 * Beantwortet OHNE Loxone: traegt die Einrichtung? Von unten nach oben -
 * der erste Kreuz-Eintrag ist in aller Regel die Ursache.
 * ================================================================== */

function abfahrt_pruefungen(array $abfcfg = null) {
    if ($abfcfg === null) { $abfcfg = abfahrt_config(); }
    $z = [];
    $zeile = function ($stand, $frage, $antwort) use (&$z) {
        $z[] = [(int) $stand, $frage, $antwort];
    };

    /* Kalender */
    $n = 0;
    foreach ($abfcfg['calendars'] as $cal) {
        if (trim((string) ($cal['url'] ?? '')) !== '') { $n++; }
    }
    $zeile($n > 0 ? 1 : 0, abfahrt_t('TEST.F_KALENDER'),
        $n > 0 ? sprintf(abfahrt_t('TEST.A_KALENDER_OK'), $n) : abfahrt_t('TEST.A_KALENDER_KEINER'));

    /* Zugangsdaten - Form beurteilen, Wert nie zeigen */
    $key = trim((string) $abfcfg['api_key']);
    $zeile($key !== '' ? 1 : 0, abfahrt_t('TEST.F_KEY'),
        $key !== '' ? sprintf(abfahrt_t('TEST.A_KEY_OK'), strtoupper($abfcfg['provider']), strlen($key))
                    : abfahrt_t('TEST.A_KEY_FEHLT'));

    /* Abfahrtsadresse */
    $adr = trim((string) $abfcfg['home_address']);
    $zeile($adr !== '' ? 1 : 0, abfahrt_t('TEST.F_ADRESSE'),
        $adr !== '' ? abfahrt_e($adr) : abfahrt_t('TEST.A_ADRESSE_FEHLT'));

    /* Rechte der Konfiguration */
    $p = abfahrt_paths();
    if (is_file($p['config'])) {
        $rechte = substr(sprintf('%o', fileperms($p['config'])), -3);
        $zeile($rechte === '600' ? 1 : 0, abfahrt_t('TEST.F_RECHTE'),
            sprintf(abfahrt_t($rechte === '600' ? 'TEST.A_RECHTE_OK' : 'TEST.A_RECHTE_OFFEN'), $rechte));
    }

    /* Laeuft der Hintergrunddienst? */
    $st = abfahrt_stand();
    if ((int) $st['zeit'] === 0) {
        $zeile(0, abfahrt_t('TEST.F_DIENST'), abfahrt_t('TEST.A_DIENST_NIE'));
    } else {
        $alter = time() - (int) $st['zeit'];
        $zeile($alter <= 600 ? 1 : 0, abfahrt_t('TEST.F_DIENST'),
            sprintf(abfahrt_t($alter <= 600 ? 'TEST.A_DIENST_OK' : 'TEST.A_DIENST_ALT'),
                    $alter < 90 ? $alter . ' s' : round($alter / 60) . ' min'));
    }

    /* Letztes Ergebnis */
    if ((int) $st['ok'] === 1 && (int) $st['fehler'] === 0) {
        $zeile(1, abfahrt_t('TEST.F_ERGEBNIS'),
            sprintf(abfahrt_t('TEST.A_ERGEBNIS_OK'), abfahrt_e($st['titel']),
                    (int) $st['minstart'], 0 + $st['fahrt'], (int) $st['abfahrt_in']));
    } elseif ((int) $st['fehler'] === 7) {
        $zeile(-1, abfahrt_t('TEST.F_ERGEBNIS'), abfahrt_t('TEST.A_ERGEBNIS_VERALTET'));
    } else {
        $zeile((int) $st['fehler'] === 4 ? -1 : 0, abfahrt_t('TEST.F_ERGEBNIS'),
            sprintf(abfahrt_t('TEST.A_ERGEBNIS_FEHLER'), (int) $st['fehler'], abfahrt_e($st['grund'])));
    }

    /* Sondertage */
    $tag = abfahrt_daytype();
    $zeile($tag['quelle'] === 'keine' ? -1 : 1, abfahrt_t('TEST.F_FERIEN'),
        $tag['quelle'] === 'keine' ? abfahrt_t('TEST.A_FERIEN_KEINS')
                                   : sprintf(abfahrt_t('TEST.A_FERIEN_OK'), abfahrt_e($tag['quelle'])));

    /* Audioausgabe */
    $why = '';
    $erlaubt = abfahrt_audio_allowed($abfcfg, $why);
    $modus = $abfcfg['tts']['mode'];
    if ($modus === 'audioserver') {
        $zeile(-1, abfahrt_t('TEST.F_AUDIO'), abfahrt_t('TEST.A_AUDIO_LOXONE'));
    } elseif (trim((string) $abfcfg['tts']['ip']) === '') {
        $zeile(0, abfahrt_t('TEST.F_AUDIO'), abfahrt_t('TEST.A_AUDIO_KEINE_IP'));
    } else {
        $zeile(1, abfahrt_t('TEST.F_AUDIO'),
            sprintf(abfahrt_t('TEST.A_AUDIO_OK'), abfahrt_e($abfcfg['tts']['ip']),
                    (int) $abfcfg['tts']['port'], abfahrt_e($abfcfg['tts']['zones'])));
    }
    $zeile($erlaubt ? 1 : -1, abfahrt_t('TEST.F_SPERRZEIT'),
        $erlaubt ? abfahrt_t('TEST.A_SPERRZEIT_FREI')
                 : sprintf(abfahrt_t('TEST.A_SPERRZEIT_AKTIV'), abfahrt_e($why)));

    /* MQTT */
    $m = abfahrt_mqtt_zustand();
    if (empty($abfcfg['mqtt_ein'])) {
        $zeile(-1, abfahrt_t('TEST.F_MQTT'), abfahrt_t('TEST.A_MQTT_AUS'));
    } elseif (!$m['gefunden']) {
        $zeile(0, abfahrt_t('TEST.F_MQTT'), abfahrt_t('TEST.A_MQTT_KEIN_ABSCHNITT'));
    } elseif (!$m['udpport']) {
        $zeile(0, abfahrt_t('TEST.F_MQTT'), abfahrt_t('TEST.A_MQTT_KEIN_PORT'));
    } elseif (!$m['autostart']) {
        $zeile(0, abfahrt_t('TEST.F_MQTT'), abfahrt_t('TEST.A_MQTT_KEIN_AUTOSTART'));
    } else {
        $zeile(1, abfahrt_t('TEST.F_MQTT'),
            sprintf(abfahrt_t('TEST.A_MQTT_OK'), (int) $m['udpport'], abfahrt_e($abfcfg['mqtt_topic'])));
    }

    /* Vorlage wohlgeformt - gehoert hierher, nicht erst in die Pruefung vor
       dem Ausliefern: eine kaputte Vorlage merkt der Anwender sonst erst in
       Loxone Config, und dort sucht er den Fehler bei sich. */
    $v = abfahrt_vorlage();
    $alt = libxml_use_internal_errors(true);
    $gut = simplexml_load_string($v[1]) !== false;
    libxml_clear_errors();
    libxml_use_internal_errors($alt);
    $zeile($gut ? 1 : 0, abfahrt_t('TEST.F_VORLAGE'),
        abfahrt_t($gut ? 'TEST.A_VORLAGE_OK' : 'TEST.A_VORLAGE_KAPUTT'));

    return $z;
}

function abfahrt_e($s) {
    return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
}

/* ==================================================================
 * Audioserver4Home / MusicServer4Home selbst finden
 * ================================================================== */

/**
 * Sucht das MS4H-Plugin auf diesem LoxBerry und liest Port und Zonen aus.
 *
 * Rueckgabe: ['gefunden'=>bool, 'port'=>int, 'zonen'=>string, 'quelle'=>string]
 *
 * BEWUSST MIT QUELLENANGABE: Die Konfiguration von MS4H ist nicht Teil dieses
 * Plugins und kann sich aendern. Statt einen Fund als Tatsache auszugeben,
 * wird gesagt, WOHER er stammt - dann kann der Nutzer nachsehen, ob es passt.
 * Wird nichts gefunden, bleibt die Handeingabe stehen; geraten wird nichts.
 */
function abfahrt_ms4h_suchen() {
    $aus = ['gefunden' => false, 'port' => 0, 'zonen' => '', 'quelle' => ''];
    $lb = getenv('LBHOMEDIR') ?: (is_dir('/opt/loxberry') ? '/opt/loxberry' : '');

    // 1) Konfiguration eines installierten MS4H-Plugins lesen
    if ($lb !== '') {
        foreach (['audioserver4home', 'musicserver4home', 'ms4h', 'as4h', 'audioserver'] as $kandidat) {
            $dir = $lb . '/config/plugins/' . $kandidat;
            if (!is_dir($dir)) { continue; }
            foreach (glob($dir . '/*.{json,cfg}', GLOB_BRACE) ?: [] as $datei) {
                $roh = (string) @file_get_contents($datei);
                $d = json_decode($roh, true);
                if (is_array($d)) {
                    foreach (['port', 'Port', 'httpport', 'HttpPort', 'webport'] as $pk) {
                        if (isset($d[$pk]) && (int) $d[$pk] > 0) {
                            $aus['port'] = (int) $d[$pk];
                            break;
                        }
                    }
                } elseif (preg_match('/^\s*(?:port|httpport)\s*=\s*(\d+)/mi', $roh, $m)) {
                    $aus['port'] = (int) $m[1];
                }
                if ($aus['port'] > 0) {
                    $aus['gefunden'] = true;
                    $aus['quelle'] = str_replace($lb, '', $datei);
                    break 2;
                }
            }
        }
    }

    // 2) Sonst die ueblichen Ports oertlich anklopfen. Antwortet einer, ist er es
    //    vermutlich - "vermutlich" steht dann auch so in der Quellenangabe.
    if (!$aus['gefunden']) {
        foreach ([7091, 7090, 7095, 7092] as $port) {
            $fp = @fsockopen('127.0.0.1', $port, $en, $es, 0.4);
            if ($fp) {
                fclose($fp);
                $aus['gefunden'] = true;
                $aus['port'] = $port;
                $aus['quelle'] = 'Port ' . $port . ' antwortet auf 127.0.0.1';
                break;
            }
        }
    }
    return $aus;
}

function abfahrt_sprache()
{
    $sprache = 'de';
    if (class_exists('LBSystem', false) && method_exists('LBSystem', 'lblanguage')) {
        $sprache = LBSystem::lblanguage();
    } elseif (getenv('LBLANG')) {
        $sprache = getenv('LBLANG');
    }
    $sprache = strtolower(substr((string) $sprache, 0, 2));
    return in_array($sprache, array('de', 'en'), true) ? $sprache : 'en';
}

/**
 * Text zu einem Schluessel "ABSCHNITT.SCHLUESSEL".
 *
 * Ist der Schluessel unbekannt, wird er selbst zurueckgegeben - so faellt
 * beim Durchsehen sofort auf, was noch fehlt, statt dass die Seite leer
 * bleibt.
 */
function abfahrt_t($schluessel)
{
    static $texte = null;
    if ($texte === null) {
        // Installiert liegen die Dateien unter
        // <home>/templates/plugins/<ordner>/lang/ - der Ordnername ergibt
        // sich aus dem Ablageort dieser Datei.
        $home = getenv('LBHOMEDIR');
        if (!$home || !is_dir($home)) {
            foreach (array('/opt/loxberry', '/home/loxberry/loxberry') as $k) {
                if (is_dir($k)) { $home = $k; break; }
            }
        }
        $ordner = basename(dirname(__FILE__));
        $pfad = $home . '/templates/plugins/' . $ordner . '/lang';
        if (!is_dir($pfad)) {
            // Nicht installiert (Entwicklung): neben dem Plugin nachsehen.
            $pfad = dirname(dirname(dirname(__FILE__))) . '/templates/lang';
        }
        $texte = @parse_ini_file($pfad . '/language_' . abfahrt_sprache() . '.ini',
                                 true, INI_SCANNER_RAW);
        if (!is_array($texte)) { $texte = array(); }
        $rueck = @parse_ini_file($pfad . '/language_en.ini', true, INI_SCANNER_RAW);
        if (is_array($rueck)) { $texte = array_replace_recursive($rueck, $texte); }
        // parse_ini_file mit INI_SCANNER_RAW liefert die Werte samt der
        // Anfuehrungszeichen zurueck, in die sie in der Datei stehen muessen.
        // Die gehoeren nicht in die Ausgabe.
        foreach ($texte as $ab => $paare) {
            if (!is_array($paare)) { continue; }
            foreach ($paare as $s => $w) {
                $texte[$ab][$s] = trim((string) $w, '"');
            }
        }
    }
    list($a, $s) = array_pad(explode('.', $schluessel, 2), 2, '');
    return isset($texte[$a][$s]) ? $texte[$a][$s] : $schluessel;
}
