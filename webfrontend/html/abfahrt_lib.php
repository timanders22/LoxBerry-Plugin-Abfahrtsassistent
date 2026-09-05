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


/* Den LoxBerry-Wurzelordner ohne festen Systempfad bestimmen.
 *
 * Vom eigenen Ablageort aufwaerts, bis ein Verzeichnis gefunden ist, das
 * config/plugins UND webfrontend enthaelt. Das trifft die uebliche
 * Installation genauso wie eine an einem anderen Ort - und es trifft auch
 * den Fall, dass das Plugin noch als entpacktes Archiv daliegt (dann findet
 * es nichts und gibt einen Leerstring zurueck, was der Aufrufer ohnehin
 * abfangen muss).
 *
 * Der Name traegt kein Plugin-Kuerzel und ist deshalb abgesichert: zwei
 * Bibliotheken landen nie im selben Prozess, aber die Pruefung kostet nichts.
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

function abfahrt_paths() {
    $lbhomedir = getenv('LBHOMEDIR') ?: lb_wurzel_ermitteln();
    /* basename(__DIR__), NICHT basename(dirname(__DIR__, 1)).
     *
     * Gemessen am 04.09.2026: installiert liegt diese Datei unter
     * .../webfrontend/html/plugins/<ordner>/. dirname(__DIR__, 1) ergibt
     * dort .../plugins, und basename davon ist "plugins" - der Pfad zeigte
     * ohne gesetztes LBPPLUGINDIR auf config/plugins/plugins/abfahrt.json.
     * Gerettet hat das nur die is_dir()-Pruefung zwei Zeilen tiefer.
     *
     * Die zweite Wirkung war schlimmer, weil sie ein Werkzeug blind machte:
     * index.php leitet den Ordner aus basename(__DIR__) ab, diese Datei aus
     * dem Elternordner. Im Archivbau landeten beide auf VERSCHIEDENEN
     * Dateien (config/plugins/htmlauth gegen config/plugins/html), und
     * wirkungstest.py meldete deshalb bei JEDEM Lauf, das Aktionstoken gehe
     * bei jeder Absendung verloren. Ein Fehlalarm bei jedem Lauf ist eine
     * abgeschaltete Pruefung - und zwar an genau der Stelle, die diese Linie
     * schon einmal gekostet hat. */
    $self = basename(__DIR__);
    $umgebung = (string) getenv('LBPPLUGINDIR');
    if ($umgebung !== '') {
        /* DIE UMGEBUNG STICHT. Vorher ueberschrieb die is_dir()-Ruckfrage
         * darunter auch ein gesetztes LBPPLUGINDIR - und weil im Archivbau
         * $self den Wert "html" hat, genuegte ein liegengebliebener Ordner
         * config/plugins/html, damit Bibliothek und Oberflaeche in
         * VERSCHIEDENE Dateien schrieben. Gemessen am 05.09.2026: der
         * Wirkungstest meldete daraufhin bei jeder Absendung ein verlorenes
         * Aktionstoken, obwohl es am Geraet unveraendert ueberlebt. */
        $plugindir = $umgebung;
    } elseif ($lbhomedir && is_dir($lbhomedir . '/config/plugins/' . $self)) {
        $plugindir = $self;
    } else {
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

function abfahrt_vorgaben()
{
    /* Herausgezogen aus abfahrt_config(): die Vorgaben stehen weiterhin an
     * EINER Stelle, jetzt aber an einer abrufbaren. Die Sicherung
     * braucht die Schluesselliste, um Fremdes zu erkennen - ohne sie
     * koennte sie nur alles durchwinken. */
    return [
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
    /* --- neu in 1.6.0 -------------------------------------------------
     * Bis auf zwei stehen alle ab Werk aus beziehungsweise leer.
     *
     * ZWEI STEHEN AB WERK AN, und das ist eine bewusste Entscheidung vom
     * 16.08.2026 gegen die Hausregel "neue Funktionen ab Werk aus":
     *
     *   route_departat     weil eine Fahrzeit, die fuer die Verkehrslage
     *                      von JETZT gilt, bei einem Termin in Stunden
     *                      schlicht falsch ist - das ist keine neue
     *                      Funktion, sondern eine Berichtigung.
     *   mqtt_vollsend_min  weil ein Miniserver nach einem Neustart sonst
     *                      mit leeren Eingaengen dasteht, und das sieht
     *                      aus wie ein Defekt des Plugins.
     *
     * BEIDE WIRKEN AUCH AUF BESTEHENDE ANLAGEN, sobald aktualisiert wird -
     * die Schluessel fehlen dort, also greift die Vorgabe. Wer bei TomTom
     * am Tageskontingent kratzt, nimmt den Haken in den Einstellungen
     * heraus; das ueberlebt jedes weitere Speichern. In der
     * Release-Beschreibung steht es an erster Stelle. */
    // Fahrzeit fuer den ABFAHRTSZEITPUNKT statt fuer jetzt berechnen.
    // Kostet je Berechnung eine zweite Abfrage beim Kartendienst.
    'route_departat' => 1,
    // Ortsangabe -> echte Adresse. [['muster'=>'Buero','adresse'=>'...'], ...]
    'ortsbuch' => [],
    // Sperrzeit auch auf die Push-Nachricht anwenden.
    'quiet_push' => 0,
    // Eigener Ansagetext mit {titel} {ort} {fahrt} {abfahrt_in} {beginn}.
    'ansage_vorlage' => '',
    // Alle MQTT-Werte erneut senden, auch wenn sie sich nicht geaendert
    // haben - damit ein Miniserver nach einem Neustart nicht mit leeren
    // Eingaengen dasteht. 0 = aus.
    'mqtt_vollsend_min' => 15,
    // Ganztagestermine mit Ortsangabe beruecksichtigen, Abfahrt zur
    // angegebenen Uhrzeit dieses Tages.
    'ganztags_ein' => 0,
    'ganztags_zeit' => '08:00',
    // Schuetzt die beiden AUSLOESENDEN Aufrufe im unangemeldeten Bereich:
    // termin_say.php (spricht im Haus) und termin.php?debug=1 (rechnet neu
    // und fragt dabei den Kartendienst). Der reine Leseaufruf von
    // termin.php bleibt frei - den holt Loxone zyklisch ab, und er kostet
    // nichts.
    'aktionstoken' => '',
];
}

function abfahrt_config() {
    $p = abfahrt_paths();
    $abfcfg = is_file($p['config']) ? (json_decode((string) file_get_contents($p['config']), true) ?: []) : [];
    // Defaults
    $abfcfg += abfahrt_vorgaben();
    $abfcfg['mqtt_ein'] = empty($abfcfg['mqtt_ein']) ? 0 : 1;
    $abfcfg['mqtt_topic'] = preg_replace('#[^A-Za-z0-9_/\-]#', '', (string) $abfcfg['mqtt_topic']);
    if ($abfcfg['mqtt_topic'] === '') { $abfcfg['mqtt_topic'] = 'abfahrt'; }
    /* Vor jedem "+=" pruefen, ob wirklich ein Feld dasteht. Eine von Hand
     * verbogene abfahrt.json mit "notify": "x" riss sonst unter PHP 8 jeden
     * Aufruf mit einem TypeError ab - die Oberflaeche liess sich dann nicht
     * einmal mehr oeffnen, um den Fehler zu beheben. Bei 'quiet' stand die
     * Pruefung laengst; hier fehlte sie. */
    foreach (['calendars', 'notify', 'quiet', 'tts', 'ortsbuch'] as $feld) {
        if (!isset($abfcfg[$feld]) || !is_array($abfcfg[$feld])) {
            $abfcfg[$feld] = [];
        }
    }
    $abfcfg['route_departat'] = empty($abfcfg['route_departat']) ? 0 : 1;
    $abfcfg['quiet_push'] = empty($abfcfg['quiet_push']) ? 0 : 1;
    $abfcfg['ganztags_ein'] = empty($abfcfg['ganztags_ein']) ? 0 : 1;
    $abfcfg['mqtt_vollsend_min'] = max(0, min(1440, (int) $abfcfg['mqtt_vollsend_min']));
    if (!preg_match('/^([01]?\d|2[0-3]):[0-5]\d$/', (string) $abfcfg['ganztags_zeit'])) {
        $abfcfg['ganztags_zeit'] = '08:00';
    }
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
        // Vorgabe ohne "~25": eine ausdrueckliche Lautstaerke an der Zone hat
        // Vorrang vor dem Lautstaerkefeld. Ab Werk sprach die Anlage deshalb
        // mit 25 %, obwohl Feld, README und Hilfe uebereinstimmend 8 % sagten.
        'ip' => '',
        'port' => 7091,
        'zones' => '1',
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
/**
 * Einen Schalter aus der Adresse lesen - und dabei auf den WERT sehen.
 *
 * ANLASS: die Endpunkte pruefen mit isset(). Damit schaltete jeder Wert ein,
 * auch die 0: ?debug=0 rechnete neu und verbrauchte Kontingent beim
 * Kartendienst, ?force=0 umging die Sperrzeiten. Wer "0" schreibt, meint
 * "aus".
 *
 * Der blosse Parameter ohne Wert (?debug) bleibt eingeschaltet - so steht er
 * in den Adressen, die die Oberflaeche anbietet, und so ist er gemeint.
 */
function abfahrt_schalter($name) {
    if (!isset($_GET[$name]) || is_array($_GET[$name])) {
        return false;
    }
    $v = strtolower(trim((string) $_GET[$name]));
    return !in_array($v, array('0', 'aus', 'nein', 'false', 'off'), true);
}

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

/**
 * Der Zwischenordner - mit oder ohne Anlegen.
 *
 * Der Schalter ist seit 05.09.2026 da: der UNANGEMELDETE Endpunkt darf
 * nichts anlegen (Hausstandard). Gemessen am 04.09.2026 legte ein einziger
 * anonymer GET auf termin.php ohne jeden Parameter /tmp/abfahrtsassistent/
 * samt last_result.txt und log/plugins/<ordner>/ samt abfahrt.log an.
 */
function abfahrt_tmpdir($anlegen = true) {
    $p = abfahrt_paths();
    if ($anlegen && !is_dir($p['tmp'])) {
        @mkdir($p['tmp'], 0775, true);
    }
    return $p['tmp'];
}

/**
 * Eine Zwischenspeicherdatei unteilbar schreiben.
 *
 * Die Nebendatei traegt die PID im Namen: schreiben Cron, Dienst und
 * Oberflaeche gleichzeitig, ueberschriebe sonst einer die Nebendatei des
 * anderen, und umbenannt wuerde eine Mischung. Verglichen wird gegen die
 * erwartete Laenge - eine halb geschriebene Datei ist genauso kaputt wie gar
 * keine, meldet sich aber nicht als Fehler.
 *
 * Ohne das entstand genau der Fehler, der am teuersten war: eine leere
 * Cache-Datei, die (float) zu 0.0 machte - also eine Fahrzeit von null
 * Minuten, mit OK=1 und FEHLER=0. Die Abfahrtswarnung kam dann zu spaet, und
 * die Anlage sah dabei kerngesund aus.
 */
function abfahrt_cache_schreiben($datei, $inhalt) {
    $inhalt = (string) $inhalt;
    $neben = $datei . '.' . getmypid() . '.tmp';
    $n = @file_put_contents($neben, $inhalt);
    if ($n !== strlen($inhalt)) {
        @unlink($neben);
        return false;
    }
    if (!@rename($neben, $datei)) {
        @unlink($neben);
        return false;
    }
    return true;
}

/**
 * Eine Zwischenspeicherdatei lesen und dabei pruefen, ob der Inhalt taugt.
 *
 * $muster ist ein regulaerer Ausdruck, dem der Inhalt genuegen muss. Passt er
 * nicht - leere Datei, abgebrochener Schreibvorgang, Fremdinhalt -, wird die
 * Datei entfernt und false zurueckgegeben, damit beim naechsten Lauf ein
 * frischer Versuch stattfindet. Stillschweigend weiterrechnen waere der
 * schlimmere Weg: der Fehler saehe dann wie ein gueltiges Ergebnis aus.
 */
function abfahrt_cache_lesen($datei, $muster) {
    if (!is_file($datei)) { return false; }
    $roh = @file_get_contents($datei);
    if ($roh === false) { return false; }
    $roh = trim($roh);
    if ($roh === '' || !preg_match($muster, $roh)) {
        @unlink($datei);
        return false;
    }
    return $roh;
}

/**
 * Kopfzeilen, die an JEDE Anfrage gehoeren.
 *
 * Vor mancher Schnittstelle sitzt ein Waechter, der eine Anfrage ohne Accept
 * oder mit der Vorgabe-Kennung einer Bibliothek abweist. Bisher stand hier nur
 * der User-Agent.
 */
function abfahrt_http_kopf() {
    return [
        'User-Agent: LoxBerry Abfahrts-Assistent',
        'Accept: */*',
        'Accept-Language: de,en;q=0.8',
        'Accept-Encoding: identity',
    ];
}

/**
 * Einen Betriebssystem- oder Protokollfehler in einen Satz uebersetzen, der
 * sagt, WER geantwortet hat.
 *
 * Der nackte Fehlertext hilft niemandem: "erreichbar, aber es antwortet nichts"
 * und "kein Weg dorthin" fuehren zu voellig verschiedenen Suchen.
 */
function abfahrt_http_grund($errno, $fehler, $status) {
    if ($errno === 7)  { return 'Verbindung abgewiesen - die Gegenstelle ist erreichbar, der Dienst laeuft aber nicht'; }
    if ($errno === 6)  { return 'Name laesst sich nicht aufloesen - stimmt die Adresse?'; }
    if ($errno === 28) { return 'Zeitueberschreitung - es antwortet nichts'; }
    if ($errno === 35 || $errno === 60) { return 'Verschluesselung scheiterte (Zertifikat oder Protokoll)'; }
    if ($errno !== 0)  { return 'Netzfehler ' . (int) $errno . ': ' . (string) $fehler; }
    if ($status === 401 || $status === 403) { return 'HTTP ' . $status . ' - der Zugang wurde abgewiesen (Schluessel falsch oder abgelaufen?)'; }
    if ($status === 404) { return 'HTTP 404 - die Adresse gibt es nicht'; }
    if ($status === 429) { return 'HTTP 429 - das Kontingent des Anbieters ist erschoepft'; }
    if ($status >= 500)  { return 'HTTP ' . $status . ' - Fehler auf der Gegenseite'; }
    if ($status >= 400)  { return 'HTTP ' . $status; }
    /* Eine Weiterleitung, der nicht gefolgt wurde, ist KEIN Erfolg. Ohne
     * diese Zeile kam der Rumpf der Weiterleitungsseite als Nutzdaten
     * zurueck (gemessen 04.09.2026 im Zweig ohne php-curl). */
    if ($status >= 300)  { return 'HTTP ' . $status . ' - Weiterleitung, der nicht gefolgt wurde'; }
    return '';
}

/**
 * Eine Adresse abrufen.
 *
 * Rueckgabe: der Rumpf, oder false. Ueber &$grund kommt heraus, WARUM es
 * schiefging - und der Statuscode wird ausgewertet: curl liefert den Rumpf
 * auch bei HTTP 404 und 500, und wer nur auf "!== false" prueft, haelt die
 * Fehlerseite eines Music Servers fuer eine gesprochene Ansage.
 *
 * BEIDE ABRUFWEGE VERHALTEN SICH GLEICH. file_get_contents folgt von sich aus
 * bis zu zwanzig Weiterleitungen, curl ohne Zutun keiner. Damit haetten die
 * Adressen - in denen der API-Schluessel steht - je nach vorhandenem php-curl
 * unterschiedlich weit wandern koennen. Beide folgen jetzt hoechstens einer.
 */
function abfahrt_http_get($url, $timeout = 12, &$grund = '', &$status = 0) {
    $grund = '';
    $status = 0;
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 1,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_CONNECTTIMEOUT => min(8, $timeout),
            CURLOPT_HTTPHEADER => abfahrt_http_kopf(),
        ]);
        $r = curl_exec($ch);
        $errno = curl_errno($ch);
        $fehler = curl_error($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        $grund = abfahrt_http_grund($errno, $fehler, $status);
        if ($r === false || $grund !== '') { return false; }
        return $r;
    }
    /* max_redirects = 2, NICHT 1.
     *
     * Gemessen am 04.09.2026 gegen einen eigenen Testserver: der Wert 1
     * heisst in PHP "KEINER Weiterleitung folgen" (die Zahl zaehlt die
     * erste Anfrage mit), waehrend curl mit CURLOPT_MAXREDIRS => 1 genau
     * einer folgt. Der Kommentar oben behauptete, beide Wege verhielten
     * sich gleich; sie taten es nicht. Ergebnis ohne php-curl: eine
     * weiterleitende Kalenderadresse (webcal->https, Nextcloud-Freigabe)
     * scheiterte grundsaetzlich, und der Rumpf der Weiterleitungsseite
     * kam als Nutzdaten zurueck, weil abfahrt_http_grund() nur >= 400
     * kannte. */
    $ctx = stream_context_create(['http' => [
        'timeout' => $timeout,
        'header' => implode("\r\n", abfahrt_http_kopf()),
        'follow_location' => 1,
        'max_redirects' => 2,
        'ignore_errors' => true,   // sonst gibt es bei 404 gar keinen Rumpf zum Ansehen
    ]]);
    $r = @file_get_contents($url, false, $ctx);
    /* Die LETZTE Statuszeile zaehlt, nicht die erste: wurde gefolgt, stehen
     * beide in $http_response_header, und $http_response_header[0] waere
     * dauerhaft die 302. */
    if (isset($http_response_header) && is_array($http_response_header)) {
        foreach ($http_response_header as $kopf) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', (string) $kopf, $m)) {
                $status = (int) $m[1];
            }
        }
    }
    $grund = abfahrt_http_grund(0, '', $status);
    if ($r === false) {
        $grund = $grund !== '' ? $grund : 'Abruf gescheitert (kein curl vorhanden)';
        return false;
    }
    return $grund === '' ? $r : false;
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
/**
 * Beschriftungen der Sperrzeiten-Tabelle.
 *
 * Aus der Sprachdatei, nicht fest im Code: bei englisch eingestellter
 * Oberflaeche standen hier bisher deutsche Wochentage - in der Tabelle, in der
 * Selbstpruefung und im Protokolleintrag "Sperrzeit Samstag ...".
 */
function abfahrt_quiet_labels() {
    $aus = [];
    foreach (abfahrt_quiet_keys() as $d) {
        $aus[$d] = abfahrt_t('TAG.T' . $d);
    }
    return $aus;
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
    $lb = getenv('LBHOMEDIR') ?: lb_wurzel_ermitteln();
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
    /* 1) JSON-Schnittstelle des Ferien-Plugins (laeuft dort mit korrekter Umgebung)
     *
     * ERST NACHSEHEN, OB ES DAS PLUGIN UEBERHAUPT GIBT. Vorher wurde bei
     * jedem Aufruf eine HTTP-Anfrage an 127.0.0.1 abgesetzt, auch auf einer
     * Anlage ohne das Ferien-Plugin - dort lief sie ins Leere und stand in
     * jedem Prueflauf als Warnung. Der Weg 2 weiter unten sucht ohnehin nach
     * derselben Datei; hier wird nur vorgezogen, was dort schon steht. */
    $abf_lb = getenv('LBHOMEDIR') ?: lb_wurzel_ermitteln();
    $abf_ferien_da = ($abf_lb !== '' && is_dir($abf_lb . '/webfrontend/html/plugins/ferien'))
                  || is_dir(dirname(__DIR__, 3) . '/html/plugins/ferien');
    $js = false;
    if ($abf_ferien_da) {
        $js = @file_get_contents(abfahrt_lokal_url('/plugins/ferien/ferien.php?json=1'), false,
            stream_context_create(['http' => ['timeout' => 4, 'user_agent' => 'LoxBerry Abfahrts-Assistent']]));
    }
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
        $lb = getenv('LBHOMEDIR') ?: lb_wurzel_ermitteln();
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
                    // ?? statt ?: - liefert das Ferien-Plugin einen der beiden
                    // Schluessel einmal nicht, stuende sonst eine PHP-Warnung
                    // im Ausgabestrom, und zwar VOR der Statuszeile.
                    $res['name'] = (string) (($st['heute']['feiertag_name'] ?? '')
                                          ?: ($st['heute']['ferien_name'] ?? ''));
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
function abfahrt_quiet_rule(array $abfcfg, $tagversatz = 0) {
    $an = function ($k) use ($abfcfg) {
        return !empty($abfcfg['quiet'][$k]['on']);
    };
    $tag = abfahrt_daytype();
    /* Die Namen kommen aus der Sprachdatei, nicht aus dem Code. Hier standen
     * bis 1.6.6 die drei deutschen Woerter fest, waehrend der Wochentagszweig
     * fuenf Zeilen tiefer laengst uebersetzte - auf englischer Oberflaeche
     * stand also "Es gilt Urlaub." Die Uebersetzungen liegen seit jeher
     * bereit (TAG.T8 bis TAG.T10). */
    $namen = abfahrt_quiet_labels();
    if (!empty($tag['urlaub']) && $an(10)) { return [10, $namen[10]]; }
    if (!empty($tag['feiertag']) && $an(8)) { return [8, $namen[8]]; }
    if (!empty($tag['ferien']) && $an(9)) { return [9, $namen[9]]; }
    // 1 = Montag ... 7 = Sonntag; $tagversatz = -1 fragt nach gestern.
    $d = (int) date('N', time() + ((int) $tagversatz) * 86400);
    return $an($d) ? [$d, $namen[$d]] : [0, ''];
}

/**
 * Liegt "jetzt" in der Audio-Sperrzeit (Sondertag oder Wochentag)?
 *
 * ES WERDEN ZWEI ZEILEN GEPRUEFT, NICHT EINE. Eine Sperrzeit 20:00-07:00
 * gehoert zu dem Tag, an dem sie BEGINNT. Um 01:13 in der Nacht zum Sonntag
 * gilt deshalb die Zeile des Samstags. Bisher wurde nur die Zeile des gerade
 * laufenden Tages angesehen: wer nur die Nacht zum Montag sperrte, wurde nach
 * Mitternacht doch angesprochen - und die Sonntagszeile griff schon ab
 * Sonntag 00:00, also am Ende der Samstagnacht.
 *
 * Fuer die Sondertage (Feiertag/Ferien/Urlaub) fragt abfahrt_quiet_rule()
 * weiterhin den HEUTIGEN Zustand ab - das Ferien-Plugin gibt nur Auskunft
 * ueber heute. Bei mehrtaegigen Zeitraeumen stimmt das; fuer den einzelnen
 * Feiertag ist die Nacht davor damit noch nicht erfasst.
 */
function abfahrt_in_quiet(array $abfcfg, &$info = '') {
    $now = (int) date('H') * 60 + (int) date('i');
    $p = function ($s) { $x = explode(':', (string) $s); return ((int) ($x[0] ?? 0)) * 60 + (int) ($x[1] ?? 0); };
    foreach ([0, -1] as $versatz) {
        list($k, $bez) = abfahrt_quiet_rule($abfcfg, $versatz);
        if ($k === 0 || !isset($abfcfg['quiet'][$k])) {
            continue;
        }
        $q = $abfcfg['quiet'][$k];
        $from = $p($q['from']);
        $to = $p($q['to']);
        if ($from === $to) {
            // Gleiche Anfangs- und Endzeit heisst ganztaegig. Bisher hiess es
            // "nie" - eine 24-Stunden-Sperre liess sich gar nicht einstellen.
            $in = true;
        } elseif ($from < $to) {
            // Fenster innerhalb eines Tages - nur die heutige Zeile zaehlt.
            if ($versatz !== 0) { continue; }
            $in = ($now >= $from && $now < $to);
        } else {
            // Fenster ueber Mitternacht: heute der Abendteil, gestern der Morgenteil.
            $in = ($versatz === 0) ? ($now >= $from) : ($now < $to);
        }
        if ($in) {
            $info = 'Sperrzeit ' . $bez . ' ' . $q['from'] . '-' . $q['to'] . ' Uhr';
            return true;
        }
    }
    return false;
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



/**
 * Ortsangabe aus dem Kalender in eine Adresse uebersetzen (Ortsbuch).
 *
 * WOZU: Im Feld LOCATION steht fast nie eine Adresse, sondern "Buero",
 * "Besprechungsraum 3" oder "Praxis Dr. Weber". Der Kartendienst kann damit
 * nichts anfangen, die Berechnung endete mit FEHLER=6, und der Anwender sah
 * nur, dass nichts geht.
 *
 * WIE GENAU VERGLICHEN WIRD - und warum nicht schlauer:
 * Erst wortgleich (ohne Beachtung von Gross- und Kleinschreibung und ohne
 * fuehrende/folgende Leerzeichen), dann als eigenstaendiges Wort innerhalb
 * der Ortsangabe. Kein Teilwort, keine Aehnlichkeit, kein Raten. Wer "Bad"
 * einträgt, soll nicht "Badstrasse 5" umgebogen bekommen.
 *
 * Rueckgabe: [Adresse, getroffenes Muster] oder [Ortsangabe, ''].
 */
function abfahrt_ort_aufloesen($loc, array $abfcfg) {
    $loc = trim((string) $loc);
    if ($loc === '' || empty($abfcfg['ortsbuch']) || !is_array($abfcfg['ortsbuch'])) {
        return [$loc, ''];
    }
    foreach ($abfcfg['ortsbuch'] as $e) {
        if (!is_array($e)) { continue; }
        $muster = trim((string) ($e['muster'] ?? ''));
        $adresse = trim((string) ($e['adresse'] ?? ''));
        if ($muster === '' || $adresse === '') { continue; }
        if (function_exists('mb_strtolower')) {
            $gleich = mb_strtolower($muster, 'UTF-8') === mb_strtolower($loc, 'UTF-8');
        } else {
            $gleich = strcasecmp($muster, $loc) === 0;
        }
        if ($gleich) {
            return [$adresse, $muster];
        }
    }
    foreach ($abfcfg['ortsbuch'] as $e) {
        if (!is_array($e)) { continue; }
        $muster = trim((string) ($e['muster'] ?? ''));
        $adresse = trim((string) ($e['adresse'] ?? ''));
        if ($muster === '' || $adresse === '') { continue; }
        if (preg_match('/(?<![\p{L}\p{N}])' . preg_quote($muster, '/') . '(?![\p{L}\p{N}])/ui', $loc)) {
            return [$adresse, $muster];
        }
    }
    return [$loc, ''];
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

function abfahrt_logfile($anlegen = true) {
    $lbhomedir = getenv('LBHOMEDIR') ?: lb_wurzel_ermitteln();
    // Dieselbe Reihenfolge wie in abfahrt_paths(): erst die Umgebung, dann
    // der eigene Ablageort. Vorher stand hier nur der Ablageort, und das
    // Protokoll lag im Prueflauf unter log/plugins/html/.
    $self = getenv('LBPPLUGINDIR') ?: basename(__DIR__);
    if ($lbhomedir) {
        $dir = $lbhomedir . '/log/plugins/' . $self;
    } else {
        $dir = sys_get_temp_dir() . '/abfahrtsassistent';
    }
    if ($anlegen && !is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    return $dir . '/abfahrt.log';
}

function abfahrt_log($msg) {
    $f = abfahrt_logfile();
    clearstatcache(true, $f);
    if (is_file($f) && filesize($f) > 512000) { // Rotation: letzte 200 Zeilen behalten
        $tail = array_slice(file($f, FILE_IGNORE_NEW_LINES) ?: [], -200);
        @file_put_contents($f, implode("\n", $tail) . "\n");
    }
    @file_put_contents($f, '[' . date('Y-m-d H:i:s') . '] ' . $msg . "\n", FILE_APPEND);
}

/* ---------------- iCal ---------------- */

/**
 * ICS einer Kalender-URL holen (10 Minuten Cache).
 *
 * Geprueft wird auf BEGIN **und** END:VCALENDAR. Ein abgebrochener Download,
 * der nur den Anfang enthaelt, wurde bisher fuer gueltig gehalten und zehn
 * Minuten lang weiterbenutzt - mit genau den Terminen, die noch im Bruchstueck
 * standen. Ueber &$grund sagt die Funktion, was schiefging.
 */
/* Wie lange darf ein Kalender aus dem Zwischenspeicher weitergelten, wenn
 * er sich nicht mehr laden laesst?
 *
 * ANLASS (gemessen 04.09.2026): es gab gar keine Grenze. Kalenderadresse
 * entfernt, Zwischenspeicher kuenstlich auf 30 Tage gealtert - das Plugin
 * lieferte den Termin aus der 30 Tage alten Kopie weiter, mit OK=1 und
 * FEHLER=0. Ein abgesagter oder verschobener Termin loeste damit unbegrenzt
 * lange Warnung und Ansage aus, und die Anlage sah dabei gesund aus.
 *
 * Die Fahrzeit-Seite macht es seit jeher richtig (ABFAHRT_ROUTE_GNADE und
 * FEHLER=7); hier fehlten beide Haelften. Sechs Stunden, weil das Zeitfenster
 * ab Werk 15 Stunden betraegt: ein kurzer Ausfall wird ueberbrueckt, ein
 * abgelaufenes Kalendertoken faellt noch am selben Tag auf. */
define('ABFAHRT_ICS_GNADE', 21600);

function abfahrt_fetch_ics($url, &$grund = '', &$veraltet = false, &$alter = 0) {
    $grund = '';
    $veraltet = false;
    $alter = 0;
    $cache = abfahrt_tmpdir() . '/ics_' . md5($url);
    if (is_file($cache) && time() - filemtime($cache) < 600) {
        $roh = @file_get_contents($cache);
        if ($roh !== false && $roh !== '') { return (string) $roh; }
    }
    $neu = abfahrt_http_get($url, 12, $grund);
    if ($neu !== false && strpos($neu, 'BEGIN:VCALENDAR') !== false
                       && strpos($neu, 'END:VCALENDAR') !== false) {
        abfahrt_cache_schreiben($cache, $neu);
        return $neu;
    }
    if ($neu !== false && $grund === '') {
        $grund = 'Antwort ist kein vollstaendiger iCal-Kalender';
    }
    /* Fehlgeschlagen - notfalls der alte Stand, aber NUR innerhalb der
     * Gnadenfrist, und der Aufrufer erfaehrt es ueber $veraltet. */
    if (is_file($cache)) {
        clearstatcache(true, $cache);
        $alter = max(0, time() - (int) @filemtime($cache));
        $roh = @file_get_contents($cache);
        if ($roh !== false && $roh !== '') {
            if ($alter <= ABFAHRT_ICS_GNADE) {
                $veraltet = true;
                return (string) $roh;
            }
            $grund = ($grund !== '' ? $grund . '; ' : '')
                   . sprintf(abfahrt_t('MELDUNG.ICS_ZU_ALT'),
                             (int) round($alter / 60), (int) (ABFAHRT_ICS_GNADE / 60));
        }
    }
    return false;
}

/**
 * Eine iCal-Eigenschaft samt ihrer Parameter aus einem VEVENT holen.
 *
 * ZWEI GRUENDE FUER DIESE FUNKTION - beide waren echte Fehler:
 *
 * 1. VERANKERT AM ZEILENANFANG (^ mit /m). Ohne den Anker nimmt preg_match
 *    den ersten Treffer irgendwo im Text. Google stellt DESCRIPTION vor
 *    LOCATION - ein "LOCATION:" im Beschreibungstext wurde damit als
 *    Ortsangabe uebernommen, geokodiert und geroutet. Ebenso liess ein
 *    "STATUS:CANCELLED" im Fliesstext einen Termin verschwinden.
 * 2. PARAMETER IN BELIEBIGER REIHENFOLGE UND ANZAHL. Outlook und Exchange
 *    schicken LOCATION;LANGUAGE=de-DE: und DTSTART;TZID=...;VALUE=DATE-TIME:.
 *    Wer eine feste Reihenfolge erwartet, verliert den ganzen Termin - und
 *    zwar lautlos, die Diagnose meldet dann nur "0 Termin(e)".
 *
 * Ein Parameterwert in Anfuehrungszeichen (ALTREP="http://...") darf einen
 * Doppelpunkt enthalten; dafuer der eigene Zweig im Muster.
 *
 * Rueckgabe: array(Wert, Parameter mit GROSS geschriebenen Namen) oder null.
 */
/**
 * Eine Zeitzone aus einem TZID-Wert bilden - auch aus einem Windows-Namen.
 *
 * ANLASS (gemessen 04.09.2026): new DateTimeZone('Eastern Standard Time')
 * wirft. Der catch-Zweig setzte still Europe/Berlin, ein Termin in New York
 * lag damit SECHS STUNDEN falsch, ohne eine einzige Meldung. Outlook und
 * Exchange schicken genau diese Namen, IANA-Namen kennen sie nicht.
 *
 * Die Tabelle ist ein Auszug aus der CLDR-Liste windowsZones (nur die
 * Zonen, die hier vorkommen koennen). Was nicht darin steht, faellt
 * weiterhin auf Europe/Berlin - aber es wird GESAGT, siehe
 * abfahrt_tz_meldung(). Geraten wird nichts.
 */
function abfahrt_tz_karte() {
    return array(
        'W. Europe Standard Time'       => 'Europe/Berlin',
        'Central Europe Standard Time'  => 'Europe/Budapest',
        'Central European Standard Time' => 'Europe/Warsaw',
        'Romance Standard Time'         => 'Europe/Paris',
        'GMT Standard Time'             => 'Europe/London',
        'Greenwich Standard Time'       => 'Atlantic/Reykjavik',
        'W. Central Africa Standard Time' => 'Africa/Lagos',
        'FLE Standard Time'             => 'Europe/Kiev',
        'GTB Standard Time'             => 'Europe/Bucharest',
        'E. Europe Standard Time'       => 'Europe/Chisinau',
        'Russian Standard Time'         => 'Europe/Moscow',
        'Turkey Standard Time'          => 'Europe/Istanbul',
        'Israel Standard Time'          => 'Asia/Jerusalem',
        'Eastern Standard Time'         => 'America/New_York',
        'Central Standard Time'         => 'America/Chicago',
        'Mountain Standard Time'        => 'America/Denver',
        'US Mountain Standard Time'     => 'America/Phoenix',
        'Pacific Standard Time'         => 'America/Los_Angeles',
        'Alaskan Standard Time'         => 'America/Anchorage',
        'Hawaiian Standard Time'        => 'Pacific/Honolulu',
        'Atlantic Standard Time'        => 'America/Halifax',
        'SA Eastern Standard Time'      => 'America/Cayenne',
        'E. South America Standard Time' => 'America/Sao_Paulo',
        'India Standard Time'           => 'Asia/Kolkata',
        'China Standard Time'           => 'Asia/Shanghai',
        'Tokyo Standard Time'           => 'Asia/Tokyo',
        'Korea Standard Time'           => 'Asia/Seoul',
        'Singapore Standard Time'       => 'Asia/Singapore',
        'SE Asia Standard Time'         => 'Asia/Bangkok',
        'AUS Eastern Standard Time'     => 'Australia/Sydney',
        'New Zealand Standard Time'     => 'Pacific/Auckland',
        'UTC'                           => 'UTC',
    );
}

/**
 * Sammelt die Zeitzonennamen, die NICHT aufgeloest werden konnten.
 *
 * Als stiller Sammler und nicht ueber einen Parameter, weil abfahrt_dt2ts()
 * an sechs Stellen gerufen wird und die Meldung erst am Ende der Kalender-
 * auswertung in die Diagnose gehoert. Doppelte werden nicht zweimal genannt.
 */
function abfahrt_tz_meldung($eintrag = null) {
    static $liste = array();
    if ($eintrag !== null && !in_array($eintrag, $liste, true)) {
        $liste[] = $eintrag;
    }
    return $liste;
}

/** Zeitzone zu einem TZID - leer, IANA-Name, Windows-Name oder Unsinn. */
function abfahrt_tz($tzid) {
    $t = trim((string) $tzid);
    if ($t === '') {
        return new DateTimeZone('Europe/Berlin');
    }
    try {
        return new DateTimeZone($t);
    } catch (Exception $e) {
        // weiter unten
    }
    $karte = abfahrt_tz_karte();
    if (isset($karte[$t])) {
        try {
            $tz = new DateTimeZone($karte[$t]);
            abfahrt_tz_meldung(sprintf(abfahrt_t('MELDUNG.TZ_KARTE'), $t, $karte[$t]));
            return $tz;
        } catch (Exception $e) {
            // weiter unten
        }
    }
    abfahrt_tz_meldung(sprintf(abfahrt_t('MELDUNG.TZ_UNBEKANNT'), $t));
    return new DateTimeZone('Europe/Berlin');
}

function abfahrt_prop($ev, $name)
{
    $muster = '/^' . $name . '((?:;(?:"[^"]*"|[^:;"\r\n])*)*):([^\r\n]*)/mi';
    if (!preg_match($muster, $ev, $m)) {
        return null;
    }
    $par = array();
    foreach (explode(';', (string) $m[1]) as $stueck) {
        if ($stueck === '' || strpos($stueck, '=') === false) {
            continue;
        }
        list($k, $v) = explode('=', $stueck, 2);
        // TZID darf in Anfuehrungszeichen stehen. Ohne dieses trim() fiel die
        // Zeitzone stillschweigend auf Europe/Berlin zurueck - bei einem
        // Termin in New York sind das sechs Stunden Fehler.
        $par[strtoupper(trim($k))] = trim($v, " \t\"");
    }
    return array(trim($m[2]), $par);
}

/** Text einer iCal-Eigenschaft entmaskieren (RFC 5545, Abschnitt 3.3.11). */
function abfahrt_unesc($s)
{
    $s = (string) $s;
    $s = str_replace(array('\\n', '\\N'), array(' ', ' '), $s);
    $s = str_replace(array('\\,', '\\;'), array(',', ';'), $s);
    return trim(str_replace('\\\\', '\\', $s));
}

/**
 * DTSTART/EXDATE/RECURRENCE-ID-Rohwert -> Unix-ts (null bei Ganztages-/Parsefehler).
 */
function abfahrt_dt2ts($raw, $tzid, $ganztagsZeit = null) {
    $raw = trim($raw);
    if (strlen($raw) == 8 && ctype_digit($raw)) {
        /* Reines Datum, also ein Ganztagestermin.
         *
         * Bis 1.5.8 wurde er ausnahmslos verworfen - richtig, solange niemand
         * sagen kann, wann man dorthin losfahren soll. Ist die Uhrzeit in den
         * Einstellungen hinterlegt, gilt sie: der Termin beginnt an diesem Tag
         * zu dieser Uhrzeit. Ohne Angabe bleibt es beim Verwerfen; geraten
         * wird nichts. */
        if (!preg_match('/^([01]?\d|2[0-3]):([0-5]\d)$/', (string) $ganztagsZeit, $mz)) {
            return null;
        }
        $tz = abfahrt_tz($tzid);
        $d = DateTime::createFromFormat('Ymd H:i:s', $raw . ' ' . $mz[1] . ':' . $mz[2] . ':00', $tz);
        return $d ? $d->getTimestamp() : null;
    }
    if (strlen($raw) == 8) {
        return null;
    }
    if (substr($raw, -1) == 'Z') {
        /* strtotime() liefert bei Unsinn FALSE, nicht null - und alle sechs
         * Aufrufer dieser Funktion pruefen auf === null. Gemessen am
         * 04.09.2026: ein VEVENT mit DTSTART:20261301T000000Z und einer
         * RRULE liess false bis in new DateTime('@' . $ts) durch und brach
         * den ganzen Rechenweg mit einem ungefangenen
         * DateMalformedStringException ab (Rueckgabewert 255); der
         * Miniserver bekam statt der Statuszeile eine Fehlerseite. */
        $ts = strtotime($raw);
        return $ts === false ? null : $ts;
    }
    $tz = abfahrt_tz($tzid);
    $d = DateTime::createFromFormat('Ymd\THis', $raw, $tz);
    return $d ? $d->getTimestamp() : null;
}

/**
 * Ein Vorkommen einer Serie in die Trefferliste aufnehmen - oder eben nicht.
 *
 * Steht als eigene Funktion da, weil beide Expansionszweige (taeglich/
 * woechentlich und monatlich/jaehrlich) genau dieselben vier Ausschlussgruende
 * pruefen muessen. Zwei Kopien liefen frueher oder spaeter auseinander.
 */
function abfahrt_serie_aufnehmen(array &$singles, array $mst, $ts, $now, $maxTs,
                                 array $overridden, array $verschoben)
{
    if (isset($mst['ex'][$ts]) || isset($overridden[$mst['uid'] . '|' . $ts])) {
        return;
    }
    // RANGE=THISANDFUTURE - die Liste ist nach 'ab' aufsteigend sortiert, es
    // gilt der letzte passende Eintrag (nicht die Summe aller: jede Angabe
    // bezieht sich auf die urspruengliche Serienzeit, nicht auf die zuvor
    // verschobene).
    if (isset($verschoben[$mst['uid']])) {
        $treffer = null;
        foreach ($verschoben[$mst['uid']] as $v) {
            if ($ts >= $v['ab']) { $treffer = $v; }
        }
        if ($treffer !== null) {
            if (!empty($treffer['weg'])) { return; }
            $ts += $treffer['delta'];
        }
    }
    if ($ts > $now && $ts <= $maxTs) {
        $singles[] = [$ts, $mst['loc'], $mst['sum']];
    }
}

/**
 * Alle Kalender parsen: naechster zukuenftiger Termin MIT Ortsangabe im
 * Zeitfenster. Serientermine werden vollstaendig expandiert:
 * RRULE FREQ=DAILY/WEEKLY/MONTHLY/YEARLY mit INTERVAL, BYDAY (woechentlich),
 * UNTIL, COUNT; EXDATE; verschobene/geloeschte Einzel-Instanzen einer Serie
 * (RECURRENCE-ID / STATUS:CANCELLED). Zeitzonen-/DST-sicher via DateTime.
 * Rueckgabe: [ts, location, summary, calendar_name] oder null.
 */
function abfahrt_next_event(array $abfcfg, &$diag = [], &$kallage = null) {
    /* $kallage sagt dem Aufrufer, wie es um die Kalender steht - er kann
     * daraus einen Fehlercode fuer den Miniserver bilden. Die Zeilen in
     * $diag reichen dafuer nicht: sie gehen nur nach ?debug=1 und in die
     * Oberflaeche, nie in die Statuszeile. */
    $kallage = array('eingerichtet' => 0, 'gelesen' => 0, 'veraltet' => 0, 'tot' => 0);
    $now = time();
    $maxTs = $now + max(1, (int) $abfcfg['lookahead_hours']) * 3600;
    $WD = ['MO' => 1, 'TU' => 2, 'WE' => 3, 'TH' => 4, 'FR' => 5, 'SA' => 6, 'SU' => 7];
    // Ganztagestermine: leer heisst "verwerfen wie bisher".
    $gz = !empty($abfcfg['ganztags_ein']) ? (string) $abfcfg['ganztags_zeit'] : null;
    $best = null;

    foreach ($abfcfg['calendars'] as $cal) {
        $url = trim((string) ($cal['url'] ?? ''));
        $name = trim((string) ($cal['name'] ?? ''));
        if ($url === '') {
            continue;
        }
        $kallage['eingerichtet']++;
        $ladegrund = '';
        $kalAlt = false;
        $kalAlter = 0;
        $ics = abfahrt_fetch_ics($url, $ladegrund, $kalAlt, $kalAlter);
        if ($ics === false) {
            $kallage['tot']++;
            $diag[] = "Kalender '$name': nicht ladbar"
                    . ($ladegrund !== '' ? ' - ' . $ladegrund : '');
            continue;
        }
        $kallage['gelesen']++;
        if ($kalAlt) {
            $kallage['veraltet']++;
            $diag[] = "Kalender '$name': Abruf gescheitert ($ladegrund) - es gilt der letzte Stand,"
                    . ' ' . (int) round($kalAlter / 60) . ' Minuten alt';
        } elseif ($ladegrund !== '') {
            $diag[] = "Kalender '$name': $ladegrund";
        }
        $ics = preg_replace("/\r?\n[ \t]/", '', $ics); // Zeilenfaltung aufloesen

        $singles = [];    // [ts, loc, sum]
        $masters = [];
        $overridden = []; // "uid|origTs" => 1
        $verschoben = []; // uid => [['ab'=>ts, 'delta'=>s, 'weg'=>0|1], ...]  (RANGE=THISANDFUTURE)
        foreach (explode('BEGIN:VEVENT', $ics) as $i => $ev) {
            if ($i === 0) {
                continue;
            }
            $pDt = abfahrt_prop($ev, 'DTSTART');
            if ($pDt === null) {
                continue;
            }
            $tzid = isset($pDt[1]['TZID']) ? $pDt[1]['TZID'] : '';
            $ts = abfahrt_dt2ts($pDt[0], $tzid, $gz);
            $pUid = abfahrt_prop($ev, 'UID');
            $uid = $pUid === null ? '' : $pUid[0];
            $pSum = abfahrt_prop($ev, 'SUMMARY');
            $sum = $pSum === null ? '' : abfahrt_unesc($pSum[0]);
            $pLoc = abfahrt_prop($ev, 'LOCATION');
            $loc = $pLoc === null ? '' : abfahrt_unesc($pLoc[0]);
            if ($loc !== '' && abfahrt_loc_ignored($loc, $abfcfg)) {
                $loc = ''; // Online-/Videotermin: keine Fahrzeitberechnung
            }
            /* Ortsbuch NACH dem Online-Filter: wer "Teams" als Ort hat, soll
             * keine Fahrzeit bekommen, auch wenn im Ortsbuch etwas dazu
             * stuende. Und VOR allem Weiteren, damit ab hier ueberall die
             * echte Adresse steht - auch im Zwischenspeicher der Route und in
             * der Anzeige. */
            if ($loc !== '') {
                list($loc, $abf_treffer) = abfahrt_ort_aufloesen($loc, $abfcfg);
                if ($abf_treffer !== '') {
                    $diag[] = "Ortsbuch: '" . $abf_treffer . "' -> '" . $loc . "'";
                }
            }
            $pSt = abfahrt_prop($ev, 'STATUS');
            $cancelled = ($pSt !== null && strtoupper($pSt[0]) === 'CANCELLED');

            // Verschobene/geloeschte Einzel-Instanz einer Serie
            $pRec = abfahrt_prop($ev, 'RECURRENCE-ID');
            if ($pRec !== null) {
                $rtz = isset($pRec[1]['TZID']) ? $pRec[1]['TZID'] : $tzid;
                $orig = abfahrt_dt2ts($pRec[0], $rtz, $gz);
                if ($orig !== null) {
                    $overridden[$uid . '|' . $orig] = 1;
                    // RANGE=THISANDFUTURE heisst: die Aenderung gilt ab hier
                    // fuer den ganzen Rest der Serie. Der Parameter wurde
                    // bisher nicht gelesen - die Serie lief zur alten Uhrzeit
                    // weiter, und ein abgesagter Rest wurde weiter angesagt.
                    $range = isset($pRec[1]['RANGE']) ? strtoupper($pRec[1]['RANGE']) : '';
                    if ($range === 'THISANDFUTURE') {
                        if (!isset($verschoben[$uid])) { $verschoben[$uid] = []; }
                        $verschoben[$uid][] = ['ab' => $orig, 'weg' => $cancelled ? 1 : 0,
                                               'delta' => ($ts === null ? 0 : $ts - $orig)];
                    }
                }
                if (!$cancelled && $ts !== null) {
                    $singles[] = [$ts, $loc, $sum];
                }
                continue;
            }
            if ($cancelled || $ts === null) {
                continue;
            }

            $pRr = abfahrt_prop($ev, 'RRULE');
            if ($pRr !== null) {
                $ex = [];
                // EXDATE traegt bei manchen Kalendern VALUE=DATE-TIME. Mit der
                // frueheren festen Reihenfolge wurde die Zeile nicht erkannt,
                // und die geloeschte Instanz erschien weiter.
                if (preg_match_all('/^EXDATE((?:;(?:"[^"]*"|[^:;"\r\n])*)*):([^\r\n]*)/mi',
                                   $ev, $me, PREG_SET_ORDER)) {
                    foreach ($me as $e) {
                        $etz = $tzid;
                        if (preg_match('/;TZID=("?)([^;"]+)\1/i', $e[1], $mt)) { $etz = $mt[2]; }
                        foreach (explode(',', trim($e[2])) as $v) {
                            $x = abfahrt_dt2ts($v, $etz, $gz);
                            if ($x !== null) {
                                $ex[$x] = 1;
                            }
                        }
                    }
                }
                $masters[] = ['uid' => $uid, 'ts' => $ts, 'tzid' => $tzid ?: 'Europe/Berlin',
                              'loc' => $loc, 'sum' => $sum, 'rrule' => $pRr[0], 'ex' => $ex];
            } else {
                $singles[] = [$ts, $loc, $sum];
            }
        }

        // Aufsteigend sortieren: abfahrt_serie_aufnehmen() nimmt den letzten
        // passenden Eintrag, und "letzter" ist nur bei sortierter Liste der
        // spaeteste. Die Reihenfolge im ICS sagt darueber nichts.
        foreach ($verschoben as $u => $liste) {
            usort($liste, function ($a, $b) { return $a['ab'] < $b['ab'] ? -1 : ($a['ab'] > $b['ab'] ? 1 : 0); });
            $verschoben[$u] = $liste;
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
                    // Auch hier kann strtotime false liefern; ein $until,
                    // das false ist, liesse jeden Vergleich $ts > $until
                    // wahr werden und die Serie sofort abbrechen.
                    $until = strtotime(substr($r['UNTIL'], 0, 8) . ' 23:59:59');
                    if ($until === false) { $until = null; }
                }
            }
            $count = isset($r['COUNT']) ? (int) $r['COUNT'] : null;
            $tz = abfahrt_tz($mst['tzid']);
            $start = (new DateTime('@' . $mst['ts']))->setTimezone($tz);

            /* BYDAY gilt fuer BEIDE Takte, nicht nur fuer den woechentlichen.
             *
             * Bei FREQ=DAILY ist BYDAY ein Filter: "jeden Tag, aber nur
             * montags bis freitags". Dass er bisher nur bei WEEKLY gefuellt
             * wurde, hiess: eine reine Werktagsserie loeste am Samstag und
             * Sonntag mit aus. Der Unterschied zum woechentlichen Takt ist,
             * dass hier NICHT auf den Wochentag des DTSTART zurueckgefallen
             * wird - ohne BYDAY meint DAILY wirklich jeden Tag. */
            $byday = [];
            if ($freq == 'WEEKLY' || $freq == 'DAILY') {
                foreach (explode(',', $r['BYDAY'] ?? '') as $d) {
                    $d = preg_replace('/[^A-Z]/', '', $d);
                    if (isset($WD[$d])) {
                        $byday[$WD[$d]] = 1;
                    }
                }
                if (!$byday && $freq == 'WEEKLY') {
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
                 * AUCH BEI GESETZTEM COUNT wird vorgespult. Frueher nicht -
                 * mit der Begruendung, solche Serien seien ohnehin kurz. Das
                 * stimmt nicht: gemessen kosteten 30 woechentliche Serien seit
                 * 2015 mit COUNT 658 ms gegenueber 25 ms ohne, und das alle
                 * fuenf Minuten auf einem Raspberry Pi. Die uebersprungene
                 * Anzahl laesst sich exakt nachrechnen, siehe $emitted:
                 *   taeglich    - je Intervallschritt genau ein Vorkommen
                 *   woechentlich- erste (angebrochene) Woche nur die Tage ab
                 *                 dem Wochentag des DTSTART, jede weitere
                 *                 volle Serienwoche alle BYDAY-Tage
                 * Bei DAILY mit BYDAY-Filter wird nicht vorgespult, solange
                 * COUNT gesetzt ist - dort waere die Zaehlung nur mit Muehe
                 * fehlerfrei, und geraten wird hier nichts.
                 */
                $emitted = 0;
                $darfSpulen = ($count === null) || ($freq == 'WEEKLY') || !$byday;
                if ($darfSpulen && $cur->getTimestamp() < $now) {
                    if ($freq == 'DAILY') {
                        $tage = (int) $start->diff(new DateTime('@' . $now))->format('%a');
                        $sprung = intdiv($tage, $iv) * $iv;
                        if ($sprung > 0) {
                            $cur->modify('+' . $sprung . ' day');
                            $emitted = intdiv($sprung, $iv);
                        }
                    } else {
                        $wochen = (int) floor(($now - $mst['ts']) / (7 * 86400));
                        $sprung = intdiv($wochen, $iv) * $iv;
                        if ($sprung > 0) {
                            $cur->modify('+' . ($sprung * 7) . ' day');
                            /* Wie viele Vorkommen liegen zwischen DTSTART und
                             * dem Punkt, auf den vorgespult wurde?
                             *
                             * BERICHTIGT 05.09.2026. Vorher stand hier
                             *     $erste + (intdiv($sprung,$iv) - 1) * count($byday)
                             * mit $erste = Zahl der BYDAY-Tage ab dem
                             * Wochentag des DTSTART. Nicht mitgezaehlt wurden
                             * die BYDAY-Tage der ANKUNFTSWOCHE, die vor dem
                             * Wochentag des DTSTART liegen - die Schleife
                             * beginnt bei $cur und besucht sie nie.
                             * Untergezaehlt wurde also um
                             * |{wd aus BYDAY : wd < wdStart}|, und bei
                             * gesetztem COUNT lieferte die Serie danach genau
                             * so viele Termine zu viel.
                             *
                             * Gemessen am 04.09.2026: DTSTART Sonntag
                             * 02.08.2026, FREQ=WEEKLY;BYDAY=SA,SU;COUNT=9 -
                             * die Serie endet am 30.08., gemeldet wurde ein
                             * Termin am 05.09. Dieselbe Serie mit DTSTART
                             * Samstag (kein BYDAY-Tag vor dem Starttag) war
                             * richtig.
                             *
                             * Richtig ist es einfacher: es wird auf einen Tag
                             * mit demselben Wochentag wie DTSTART gesprungen,
                             * also liegen zwischen DTSTART und $cur genau
                             * (sprung/iv) volle Serienwochen mit je
                             * count($byday) Vorkommen. Die Tage der
                             * Startwoche vor DTSTART faellt der Vergleich
                             * $ts >= $mst['ts'] in der Schleife heraus, sie
                             * duerfen hier gar nicht zaehlen. */
                            $emitted = intdiv($sprung, $iv) * count($byday);
                        }
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
                        // BYDAY ist hier ein Filter, kein eigener Takt.
                        $okDay = ($days % $iv) == 0
                              && (!$byday || isset($byday[(int) $cur->format('N')]));
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
                        abfahrt_serie_aufnehmen($singles, $mst, $ts, $now, $maxTs,
                                                $overridden, $verschoben);
                    }
                    if ($ts > $maxTs || ($until !== null && $ts > $until)) {
                        break;
                    }
                    $cur->modify('+1 day');
                }
            } elseif ($freq == 'MONTHLY' || $freq == 'YEARLY') {
                /* Monatlich und jaehrlich - vollstaendig neu gebaut.
                 *
                 * WARUM NICHT MEHR "+N month" AUF DAS STARTDATUM
                 * PHP rechnet 31.01. + 1 Monat = 03.03. RFC 5545 verlangt das
                 * Gegenteil: ein Monat ohne den Starttag faellt aus. Gemessen
                 * hat das alte Verfahren fuer DTSTART 31.01.2026 mit
                 * INTERVAL=2 den 01.10.2026 gemeldet - einen Termin, den es
                 * nie gab, waehrend der echte fehlte. Deshalb wird jetzt vom
                 * MONATSERSTEN aus geschritten und der Tag danach im Monat
                 * gesucht; existiert er nicht, entfaellt der Monat.
                 *
                 * WARUM BYDAY/BYMONTHDAY/BYMONTH/BYSETPOS
                 * "Jeder dritte Donnerstag" (BYDAY=3TH) und "letzter Freitag"
                 * (BYDAY=-1FR) sind die beiden haeufigsten Serienformen im
                 * Beruf. Bisher wurde stur der Tag des DTSTART fortgeschrieben
                 * - ab dem zweiten Monat lag der Termin dauerhaft falsch.
                 */
                $std  = (int) $start->format('H');
                $minu = (int) $start->format('i');
                $sek  = (int) $start->format('s');

                $bytag = [];        // [Wochentag 1-7, Ordnungszahl, 0 = jeder]
                foreach (explode(',', $r['BYDAY'] ?? '') as $d) {
                    $d = trim($d);
                    if ($d !== '' && preg_match('/^([+-]?\d+)?(MO|TU|WE|TH|FR|SA|SU)$/', $d, $mb)) {
                        $bytag[] = [$WD[$mb[2]], (int) ($mb[1] !== '' ? $mb[1] : 0)];
                    }
                }
                $bymonatstag = [];
                foreach (explode(',', $r['BYMONTHDAY'] ?? '') as $d) {
                    $d = (int) trim($d);
                    if ($d !== 0) { $bymonatstag[] = $d; }
                }
                $bymonat = [];
                foreach (explode(',', $r['BYMONTH'] ?? '') as $d) {
                    $d = (int) trim($d);
                    if ($d >= 1 && $d <= 12) { $bymonat[] = $d; }
                }
                $bysetpos = [];
                foreach (explode(',', $r['BYSETPOS'] ?? '') as $d) {
                    $d = (int) trim($d);
                    if ($d !== 0) { $bysetpos[] = $d; }
                }

                $schritt = ($freq == 'MONTHLY') ? 'month' : 'year';
                $anker = new DateTime(
                    ($freq == 'MONTHLY' ? $start->format('Y-m') . '-01' : $start->format('Y') . '-01-01')
                    . ' 12:00:00', $tz);

                $fertig = false;
                for ($k = 0; $k < 1200 && !$fertig; $k++) {
                    $per = clone $anker;
                    if ($k > 0) {
                        $per->modify('+' . ($k * $iv) . ' ' . $schritt);
                    }
                    if ($per->getTimestamp() > $maxTs) {
                        break;   // die ganze Periode liegt hinter dem Fenster
                    }
                    $jahr = (int) $per->format('Y');
                    $mons = ($freq == 'MONTHLY')
                          ? [(int) $per->format('n')]
                          : ($bymonat ?: [(int) $start->format('n')]);

                    $kandidaten = [];
                    foreach ($mons as $mon) {
                        $imMonat = (int) date('t', mktime(12, 0, 0, $mon, 1, $jahr));
                        $tage = [];
                        if ($bytag) {
                            foreach ($bytag as $bt) {
                                list($wd, $ord) = $bt;
                                $treffer = [];
                                for ($t = 1; $t <= $imMonat; $t++) {
                                    $d = new DateTime(sprintf('%04d-%02d-%02d 12:00:00', $jahr, $mon, $t), $tz);
                                    if ((int) $d->format('N') === $wd) { $treffer[] = $t; }
                                }
                                if ($ord > 0) {
                                    if (isset($treffer[$ord - 1])) { $tage[] = $treffer[$ord - 1]; }
                                } elseif ($ord < 0) {
                                    $i = count($treffer) + $ord;
                                    if ($i >= 0 && isset($treffer[$i])) { $tage[] = $treffer[$i]; }
                                } else {
                                    $tage = array_merge($tage, $treffer);
                                }
                            }
                        } elseif ($bymonatstag) {
                            foreach ($bymonatstag as $t) {
                                $tt = $t > 0 ? $t : $imMonat + 1 + $t;
                                if ($tt >= 1 && $tt <= $imMonat) { $tage[] = $tt; }
                            }
                        } else {
                            $t = (int) $start->format('j');
                            if ($t <= $imMonat) { $tage[] = $t; }   // sonst faellt der Monat aus
                        }
                        foreach (array_unique($tage) as $t) {
                            $d = new DateTime(sprintf('%04d-%02d-%02d %02d:%02d:%02d',
                                                      $jahr, $mon, $t, $std, $minu, $sek), $tz);
                            $kandidaten[] = $d->getTimestamp();
                        }
                    }
                    sort($kandidaten);
                    if ($bysetpos) {
                        $aus = [];
                        foreach ($bysetpos as $pos) {
                            $i = $pos > 0 ? $pos - 1 : count($kandidaten) + $pos;
                            if (isset($kandidaten[$i])) { $aus[] = $kandidaten[$i]; }
                        }
                        sort($aus);
                        $kandidaten = $aus;
                    }

                    foreach ($kandidaten as $ts) {
                        if ($ts < $mst['ts']) {
                            continue;
                        }
                        $emitted++;
                        if ($count !== null && $emitted > $count) { $fertig = true; break; }
                        if ($until !== null && $ts > $until)      { $fertig = true; break; }
                        if ($ts > $maxTs)                          { $fertig = true; break; }
                        abfahrt_serie_aufnehmen($singles, $mst, $ts, $now, $maxTs,
                                                $overridden, $verschoben);
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
/** Muster einer gueltigen Koordinatenangabe "Breite,Laenge". */
define('ABFAHRT_GEO_MUSTER', '/^-?\d{1,3}(\.\d+)?,-?\d{1,3}(\.\d+)?$/');

/** Wie lange eine einmal ermittelte Koordinate gilt: 90 Tage. */
define('ABFAHRT_GEO_TTL', 90 * 86400);

function abfahrt_geocode($address, array $abfcfg, &$err = '') {
    $key = $abfcfg['api_key'];
    $provider = $abfcfg['provider'];
    $cache = abfahrt_tmpdir() . '/geo_' . md5($provider . '|' . $address);
    /* Zwei Aenderungen gegenueber frueher:
     * - der Inhalt wird geprueft. Eine leere Datei (abgebrochener
     *   Schreibvorgang, volle Ramdisk) lieferte bisher '' zurueck - und ''
     *   ist nicht false, die Pruefung des Aufrufers griff also nicht. Die
     *   Routenadresse wurde dann zu ".../calculateRoute/:52.5,13.4/json" und
     *   die Berechnung war DAUERHAFT tot, ohne je einen neuen Versuch.
     * - der Eintrag verfaellt. "Dauerhaft" hiess bisher wirklich dauerhaft:
     *   ein einmal falsch aufgeloester Ort blieb bis zum Loeschen von Hand. */
    if (is_file($cache) && time() - filemtime($cache) < ABFAHRT_GEO_TTL) {
        $alt = abfahrt_cache_lesen($cache, ABFAHRT_GEO_MUSTER);
        if ($alt !== false) {
            return $alt;
        }
    }
    $pos = null;
    $grund = '';
    if ($provider === 'tomtom') {
        $url = 'https://api.tomtom.com/search/2/geocode/' . rawurlencode($address) . '.json?key=' . rawurlencode($key) . '&limit=1&countrySet=DE,AT,CH';
        $g = @json_decode((string) abfahrt_http_get($url, 12, $grund), true);
        if (isset($g['results'][0]['position'])) {
            $pos = $g['results'][0]['position']['lat'] . ',' . $g['results'][0]['position']['lon'];
        }
    } elseif ($provider === 'here') {
        $url = 'https://geocode.search.hereapi.com/v1/geocode?q=' . rawurlencode($address) . '&apiKey=' . rawurlencode($key);
        $g = @json_decode((string) abfahrt_http_get($url, 12, $grund), true);
        if (isset($g['items'][0]['position'])) {
            $pos = $g['items'][0]['position']['lat'] . ',' . $g['items'][0]['position']['lng'];
        }
    }
    if ($pos === null || !preg_match(ABFAHRT_GEO_MUSTER, $pos)) {
        $err = 'Adresse konnte nicht in Koordinaten umgesetzt werden (' . $provider . '): ' . $address
             . ($grund !== '' ? ' - ' . $grund : '');
        return false;
    }
    abfahrt_cache_schreiben($cache, $pos);
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
/**
 * Den Abfahrtszeitpunkt so formatieren, wie ihn der jeweilige Dienst versteht.
 *
 * Die drei Schreibweisen stehen so in der Dokumentation der Anbieter, nachgelesen
 * am 16.08.2026 - sie sind nicht abgeleitet und nicht geraten:
 *
 *   Google Directions  departure_time   Unix-Sekunden, nur jetzt oder kuenftig;
 *                                       duration_in_traffic gibt es nur damit
 *   TomTom Routing     departAt         ISO 8601. OHNE Zeitzonen-Versatz, wie in
 *                                       den Beispielen der Dokumentation; TomTom
 *                                       nimmt dann die Zeitzone des Startpunkts,
 *                                       und der ist die Abfahrtsadresse.
 *   HERE Routing v8    departureTime    ISO 8601 MIT Zeitzonen-Versatz - dort
 *                                       ausdruecklich verlangt.
 */
function abfahrt_departat_param($provider, $ts) {
    if ($provider === 'google') { return '&departure_time=' . (int) $ts; }
    if ($provider === 'tomtom') { return '&departAt=' . rawurlencode(date('Y-m-d\TH:i:s', $ts)); }
    if ($provider === 'here')   { return '&departureTime=' . rawurlencode(date('Y-m-d\TH:i:sP', $ts)); }
    return '';
}

/**
 * @param int|null $abfahrtTs Fuer WELCHEN Abfahrtszeitpunkt gerechnet werden
 *        soll. null = jetzt (bisheriges Verhalten).
 */
function abfahrt_route_minutes($destAddress, array $abfcfg, &$err = '', $minutenBisTermin = null, &$veraltet = false, $abfahrtTs = null) {
    $veraltet = false;
    /* Die Abfahrtsadresse gehoert in den Schluessel. Ohne sie galt nach einem
     * Umzug bis zu eine Stunde lang die Fahrzeit von der alten Adresse - und
     * zwar ohne jeden Hinweis. */
    /* Der Abfahrtszeitpunkt gehoert in den Schluessel, auf eine Viertelstunde
     * gerundet. Ohne ihn teilten sich "Fahrzeit jetzt" und "Fahrzeit um 07:40"
     * denselben Eintrag, und je nachdem, welche zuerst gerechnet wurde, stuende
     * die falsche in Loxone. Gerundet, damit nicht jede Minute ein neuer
     * Eintrag entsteht und die Ersparnis des Zwischenspeichers verpufft. */
    $abf_zeitschluessel = ($abfahrtTs === null) ? 'jetzt' : (string) (((int) $abfahrtTs) - (((int) $abfahrtTs) % 900));
    $cache = abfahrt_tmpdir() . '/route_'
           . md5($abfcfg['provider'] . '|' . $abfcfg['home_address'] . '|' . $destAddress
                 . '|' . $abf_zeitschluessel);
    $alter = is_file($cache) ? time() - filemtime($cache) : null;
    // Inhalt pruefen statt (float) darauf loszulassen: (float) einer leeren
    // Datei ist 0.0 - eine Fahrzeit von null Minuten, die wie ein gueltiges
    // Ergebnis aussieht.
    if ($alter !== null && $alter < abfahrt_route_ttl($minutenBisTermin)) {
        $alt = abfahrt_cache_lesen($cache, '/^\d+(\.\d+)?$/');
        if ($alt !== false) {
            return (float) $alt;
        }
        $alter = null;   // Datei war unbrauchbar und ist jetzt weg
    }
    $key = $abfcfg['api_key'];
    $provider = $abfcfg['provider'];
    $minutes = false;

    if ($provider === 'google') {
        // Google akzeptiert Adressen direkt (kein separates Geocoding noetig)
        $url = 'https://maps.googleapis.com/maps/api/directions/json?origin=' . rawurlencode($abfcfg['home_address'])
             . '&destination=' . rawurlencode($destAddress)
             . '&mode=driving&traffic_model=best_guess&key=' . rawurlencode($key)
             . ($abfahrtTs === null ? '&departure_time=now'
                                    : abfahrt_departat_param('google', $abfahrtTs));
        $grund = '';
        $r = @json_decode((string) abfahrt_http_get($url, 12, $grund), true);
        if (isset($r['routes'][0]['legs'][0])) {
            $leg = $r['routes'][0]['legs'][0];
            $sec = $leg['duration_in_traffic']['value'] ?? ($leg['duration']['value'] ?? null);
            if ($sec !== null) {
                $minutes = round($sec / 60, 1);
            }
        }
        if ($minutes === false) {
            $err = 'Google-Routing fehlgeschlagen'
                 . ($grund !== '' ? ' - ' . $grund : '')
                 . (isset($r['status']) ? ' (' . $r['status'] . ')' : '');
        }
    } else {
        // Kein vorzeitiges return: auch ein misslungenes Geocoding soll unten
        // noch in die Gnadenfrist laufen duerfen, sonst flattert es genauso.
        $home = abfahrt_geocode($abfcfg['home_address'], $abfcfg, $err);
        $dest = ($home === false) ? false : abfahrt_geocode($destAddress, $abfcfg, $err);
        $grund = '';
        if ($home === false || $dest === false) {
            $minutes = false;
        } elseif ($provider === 'tomtom') {
            $url = 'https://api.tomtom.com/routing/1/calculateRoute/' . $home . ':' . $dest
                 . '/json?key=' . rawurlencode($key) . '&traffic=true&travelMode=car'
                 . ($abfahrtTs === null ? '' : abfahrt_departat_param('tomtom', $abfahrtTs));
            $r = @json_decode((string) abfahrt_http_get($url, 12, $grund), true);
            if (isset($r['routes'][0]['summary']['travelTimeInSeconds'])) {
                $minutes = round($r['routes'][0]['summary']['travelTimeInSeconds'] / 60, 1);
            } else {
                $err = 'TomTom-Routing fehlgeschlagen' . ($grund !== '' ? ' - ' . $grund : '');
            }
        } elseif ($provider === 'here') {
            $url = 'https://router.hereapi.com/v8/routes?transportMode=car&origin=' . $home
                 . '&destination=' . $dest . '&return=summary&apikey=' . rawurlencode($key)
                 . ($abfahrtTs === null ? '' : abfahrt_departat_param('here', $abfahrtTs));
            $r = @json_decode((string) abfahrt_http_get($url, 12, $grund), true);
            if (isset($r['routes'][0]['sections'][0]['summary']['duration'])) {
                $minutes = round($r['routes'][0]['sections'][0]['summary']['duration'] / 60, 1);
            } else {
                $err = 'HERE-Routing fehlgeschlagen' . ($grund !== '' ? ' - ' . $grund : '');
            }
        } else {
            $err = 'Unbekannter Kartendienst: ' . $provider;
        }
    }
    if ($minutes !== false) {
        abfahrt_cache_schreiben($cache, $minutes);
        return $minutes;
    }

    // Der Kartendienst hat nicht geliefert. Gibt es einen Wert aus der letzten
    // Stunde, wird der weitergereicht, statt die Berechnung abzubrechen.
    if ($alter !== null && $alter <= ABFAHRT_ROUTE_GNADE) {
        $alt = abfahrt_cache_lesen($cache, '/^\d+(\.\d+)?$/');
        if ($alt !== false) {
            $veraltet = true;
            abfahrt_log('Kartendienst antwortet nicht (' . $err . ') - benutze die Fahrzeit von vor '
                      . (int) round($alter / 60) . ' min weiter.');
            $err = '';
            return (float) $alt;
        }
    }
    return false;
}

/* ---------------- TTS ---------------- */

/**
 * Einen Text auf das beschraenken, was eine Sprachausgabe vertraegt.
 *
 * Gilt fuer den automatischen Titel UND fuer einen von aussen mitgegebenen
 * Text. Bisher wurde nur der Titel gefiltert; der mitgegebene Text ging roh
 * ins Protokoll, und ein Zeilenumbruch darin erzeugte dort eine frei
 * erfundene zweite Zeile mit eigenem Zeitstempel.
 */
function abfahrt_tts_sauber($text, $max = 300) {
    $text = preg_replace('/[^\p{L}\p{N} .,:!?\-]/u', ' ', (string) $text);
    $text = trim(preg_replace('/ {2,}/', ' ', (string) $text));
    if (function_exists('mb_substr')) {
        return mb_substr($text, 0, $max, 'UTF-8');
    }
    return substr($text, 0, $max);
}

/**
 * Den Ansagetext aus dem letzten Ergebnis bauen.
 *
 * Steht hier und nicht in termin_say.php, weil er zweisprachig sein muss: bis
 * 1.5.7 war er fest deutsch, auch bei englisch eingestellter Oberflaeche.
 */
function abfahrt_ansagetext(array $info, array $abfcfg = array()) {
    $titel = abfahrt_tts_sauber($info['titel'] ?? '', 120);
    /* Eigene Vorlage, falls hinterlegt. Sie gilt auch ohne Titel - wer sie
     * schreibt, weiss selbst, was drinstehen soll. Die Platzhalter werden
     * einzeln gesaeubert, nicht der fertige Satz: sonst faellt die
     * Zeichenbegrenzung auf den ganzen Text statt auf die Einsetzung. */
    $vorlage = trim((string) ($abfcfg['ansage_vorlage'] ?? ''));
    if ($vorlage !== '') {
        return abfahrt_tts_sauber(str_replace(
            array('{titel}', '{ort}', '{fahrt}', '{abfahrt_in}', '{beginn}'),
            array($titel,
                  abfahrt_tts_sauber($info['ort'] ?? '', 120),
                  (string) (int) ceil((float) ($info['fahrt'] ?? 0)),
                  (string) (int) ($info['abfahrt_in'] ?? 0),
                  abfahrt_tts_sauber($info['beginn'] ?? '', 40)),
            $vorlage), 400);
    }
    if ($titel === '') {
        return abfahrt_t('ANSAGE.OHNE_TITEL');
    }
    $text = sprintf(abfahrt_t('ANSAGE.MIT_TITEL'), $titel);
    if (!empty($info['fahrt'])) {
        $text .= ' ' . sprintf(abfahrt_t('ANSAGE.FAHRZEIT'), (int) ceil((float) $info['fahrt']));
    }
    return $text;
}

/** TTS-URL fuer die konfigurierte Ausgabe bauen. Fuer mode=audioserver: null,
 *  bei fehlender (aber benoetigter) IP: '' - uebernommen aus AWM-Abfuhr 1.2.0:
 *  die IP wird nur verlangt, wenn der Modus bzw. die Vorlage sie benutzt,
 *  sonst liess sich eine eigene Vorlage ohne {ip} gar nicht verwenden. */
function abfahrt_tts_url($text, array $tts) {
    $mode = $tts['mode'];
    if ($mode === 'audioserver') {
        return null; // Original Loxone Audioserver: TTS nur ueber Loxone Config (Textgenerator -> TTS-Eingang)
    }

    /* Zonenliste EINMAL fuer alle Modi normalisieren.
     *
     * Bis hierher wurde nur im Modus musicserver je Zone getrimmt. In den
     * Modi ms4h und "eigene Vorlage" ging die Eingabe roh in {zones} - aus
     * "2, 4, 6" wurde eine Adresse mit Leerzeichen, also eine kaputte
     * Adresse. Der Hilfetext sagt zu, dass beide Schreibweisen gehen;
     * hier wird das eingeloest. */
    $zl = array();
    foreach (explode(',', (string) $tts['zones']) as $z) {
        $z = trim($z);
        if ($z !== '') { $zl[] = $z; }
    }
    $tts['zones'] = implode(',', $zl);
    if ($mode === 'musicserver' && trim((string) $tts['ip']) === '') {
        return '';   // ohne IP laesst sich die Music-Server-Adresse nicht bauen
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
    // Die IP wird nur verlangt, wenn die Vorlage sie auch verwendet (AWM 1.2.0).
    if (trim((string) $tts['ip']) === '' && strpos($tpl, '{ip}') !== false) {
        return '';
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
        // Neu in 1.6.0. Steht am ENDE, damit die Reihenfolge der bisherigen
        // Felder - und damit jede eingetragene Befehlserkennung - gleich
        // bleibt. 1440 heisst "unbekannt"; gueltige Werte sind 0..1439.
        'ANKUNFT'    => [1, 0, 1440, 'FELD.ANKUNFT'],
    ];
}

/**
 * Platzhalter fuer Zeilennummern in der Baustein-Liste ersetzen.
 *
 * WARUM DAS SEIN MUSS: In der Baustein-Tabelle stehen zuerst die Felder (je
 * eines je Zeile), danach die Bausteine. Verweise wie "Ausgang von #10" waren
 * bis 1.5.8 als Zahl in die Sprachdatei getippt. Ein neuntes Feld verschiebt
 * damit JEDEN dieser Verweise um eins - und zwar lautlos, denn eine Zahl sieht
 * immer richtig aus. Genau davor warnen die Hausregeln bei der Feldtabelle;
 * fuer die Verweise darauf galt es bisher nicht.
 *
 *   {B7}        -> Nummer des siebten Bausteins  (Felderzahl + 7)
 *   {F:FEHLER}  -> Nummer des Feldes FEHLER      (Rang in abfahrt_felder())
 */
function abfahrt_nummern($text) {
    $felder = array_keys(abfahrt_felder());
    $anz = count($felder);
    $text = preg_replace_callback('/\{B(\d+)\}/',
        function ($m) use ($anz) { return '#' . ($anz + (int) $m[1]); }, (string) $text);
    return preg_replace_callback('/\{F:([A-Z_]+)\}/',
        function ($m) use ($felder) {
            $i = array_search($m[1], $felder, true);
            return $i === false ? $m[0] : '#' . ($i + 1);
        }, $text);
}

/** Sprachtext mit aufgeloesten Baustein-Nummern. */
function abfahrt_tn($schluessel) {
    return abfahrt_nummern(abfahrt_t($schluessel));
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
    /* NICHT anlegen. Diese Funktion liefert einen Pfad; wer schreibt, legt
     * an. Gemessen am 05.09.2026: der unangemeldete Endpunkt ruft
     * abfahrt_stand() zum blossen LESEN, und ueber diese Zeile entstand
     * /tmp/abfahrtsassistent/ auf einen anonymen GET hin. */
    return abfahrt_tmpdir(false) . '/stand.json';
}

function abfahrt_stand() {
    $d = is_file(abfahrt_standfile())
        ? json_decode((string) @file_get_contents(abfahrt_standfile()), true) : null;
    if (!is_array($d)) { $d = []; }
    return $d + [
        'zeit' => 0, 'ok' => 0, 'fehler' => 0, 'grund' => '',
        'minstart' => 9999, 'fahrt' => 0, 'abfahrt_in' => 9999, 'ankunft' => 1440,
        'titel' => '', 'ort' => '', 'kalender' => '', 'beginn' => '',
    ];
}

function abfahrt_stand_write(array $st) {
    $js = json_encode($st, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($js === false) { return false; }
    abfahrt_tmpdir();   // wer schreibt, legt an - und nur der
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
        /* Auf die Grenzen klemmen, die abfahrt_felder() dem virtuellen
         * Eingang als MinVal/MaxVal mitgibt - sonst traegt die erzeugte
         * Vorlage eine Zusage, die die Zeile nicht einhaelt. */
        'FAHRT'      => max(0, min(1440, 0 + $st['fahrt'])),
        'ABFAHRT_IN' => max(-9999, min(9999, (int) $st['abfahrt_in'])),
        'FEHLER'     => (int) $st['fehler'],
        'ALTER'      => max(0, min(86400, $alter)),
        'AUDIO'      => abfahrt_audio_allowed($abfcfg, $why) ? 1 : 0,
        // Die Sperrzeit wirkte bisher nur auf die Ansage. Wer nachts auch
        // keine Push-Nachricht will, konnte das nicht einstellen.
        'PUSH'       => (empty($abfcfg['notify']['push'])
                         || (!empty($abfcfg['quiet_push']) && abfahrt_in_quiet($abfcfg))) ? 0 : 1,
        'ANKUNFT'    => (int) $st['ankunft'],
    ];
}

/**
 * Alles rechnen und den Zwischenstand fortschreiben.
 * Rueckgabe: [Zwischenstand, Diagnosezeilen]
 */
function abfahrt_berechnen(?array $abfcfg = null) {
    if ($abfcfg === null) { $abfcfg = abfahrt_config(); }
    $st = abfahrt_stand();
    $diag = [];

    /* Bei einem Fehler werden auch Titel, Ort, Kalender und Beginn geleert,
     * und titel.json verschwindet.
     *
     * WARUM: Bisher blieben sie stehen. Nach "kein Termin im Zeitfenster"
     * zeigte die Oberflaeche weiter den Zahnarzttermin von vorgestern, und
     * termin_say.php baute daraus seine Ansage - ohne jede Pruefung, ob die
     * Angaben noch gelten. Eine Ansage fuer einen laengst vergangenen Termin
     * ist schlimmer als gar keine.
     *
     * Der Fall FEHLER=6 (Kartendienst tot) traegt sie unmittelbar danach
     * bewusst wieder nach: dort SIND Titel und Ort bekannt, nur die Fahrzeit
     * fehlt. */
    $setz = function ($code, $grund) use (&$st) {
        $st['ok'] = 0;
        $st['fehler'] = $code;
        $st['grund'] = $grund;
        $st['minstart'] = 9999;
        $st['fahrt'] = 0;
        $st['abfahrt_in'] = 9999;
        $st['ankunft'] = 1440;      // 1440 = unbekannt
        $st['titel'] = '';
        $st['ort'] = '';
        $st['kalender'] = '';
        $st['beginn'] = '';
        $st['zeit'] = time();
        abfahrt_stand_write($st);
        @unlink(abfahrt_tmpdir() . '/titel.json');
        return $st;
    };

    $hasCal = false;
    foreach ($abfcfg['calendars'] as $cal) {
        if (trim((string) ($cal['url'] ?? '')) !== '') { $hasCal = true; break; }
    }
    if (!$hasCal)                                { return [$setz(1, 'Kein Kalender konfiguriert (Plugin-Oberflaeche oeffnen).'), $diag]; }
    if (trim($abfcfg['api_key']) === '')         { return [$setz(2, 'Kein API-Key konfiguriert (Plugin-Oberflaeche oeffnen).'), $diag]; }
    if (trim($abfcfg['home_address']) === '')    { return [$setz(3, 'Keine Abfahrtsadresse konfiguriert (Plugin-Oberflaeche oeffnen).'), $diag]; }

    $kallage = null;
    $best = abfahrt_next_event($abfcfg, $diag, $kallage);
    /* Die Zeitzonen-Meldungen gehoeren in die Diagnose - vorher fiel eine
     * nicht aufgeloeste Zeitzone lautlos auf Europe/Berlin zurueck. */
    foreach (abfahrt_tz_meldung() as $tzm) { $diag[] = $tzm; }
    if ($best === null) {
        /* Kein einziger Kalender liess sich lesen: das ist NICHT dasselbe
         * wie "kein Termin". Vorher stand in beiden Faellen FEHLER=4, und in
         * Loxone sah ein toter Kalender aus wie ein freier Tag. */
        if (!empty($kallage['eingerichtet']) && (int) $kallage['gelesen'] === 0) {
            return [$setz(5, 'Kein Kalender liess sich lesen (' . (int) $kallage['tot']
                            . ' von ' . (int) $kallage['eingerichtet'] . ').'), $diag];
        }
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

    /* Zweiter Durchgang: die Fahrzeit fuer den ABFAHRTSZEITPUNKT holen.
     *
     * Der erste Durchgang rechnet mit der Verkehrslage von jetzt. Fuer einen
     * Termin in acht Stunden sagt die nichts - gerade der Berufsverkehr wird
     * dadurch verlässlich falsch geschaetzt. Aus dem ersten Ergebnis ergibt
     * sich der voraussichtliche Abfahrtszeitpunkt; mit dem wird ein zweites
     * Mal gefragt.
     *
     * Nur EIN zweiter Durchgang, nicht bis zur Konvergenz: die Aenderung der
     * Fahrzeit wirkt sich auf den Abfahrtszeitpunkt nur noch um Minuten aus,
     * und jede weitere Abfrage kostet Kontingent.
     *
     * Und nur, wenn die Abfahrt mehr als 20 Minuten entfernt ist. Naeher dran
     * sind "jetzt" und "der Abfahrtszeitpunkt" praktisch dasselbe, und die
     * zweite Abfrage waere verschenkt.
     *
     * Ab Werk ist das AUS: es verdoppelt die Zahl der Abfragen, und bei TomTom
     * haengt daran ein Tageskontingent, bei Google eine Rechnung.
     */
    $vorlaeufig = (int) round($minstart - $fahrt - (int) $abfcfg['arrival_min'] - (int) $abfcfg['buffer_min']);
    if (!empty($abfcfg['route_departat']) && $vorlaeufig > 20) {
        $abfahrtTs = time() + $vorlaeufig * 60;
        $err2 = '';
        $veraltet2 = false;
        $fahrt2 = abfahrt_route_minutes($loc, $abfcfg, $err2, $minstart, $veraltet2, $abfahrtTs);
        if ($fahrt2 !== false) {
            $diag[] = sprintf('Fahrzeit fuer die Abfahrt um %s: %s min (statt %s min fuer jetzt)',
                              date('H:i', $abfahrtTs), $fahrt2, $fahrt);
            $fahrt = $fahrt2;
            $veraltet = $veraltet || $veraltet2;
        } else {
            // Der zweite Durchgang ist kein Muss. Scheitert er, gilt der
            // erste weiter - eine Fahrzeit von jetzt ist besser als keine.
            $diag[] = 'Fahrzeit fuer den Abfahrtszeitpunkt nicht zu bekommen ('
                    . ($err2 !== '' ? $err2 : 'ohne Angabe') . ') - es gilt die Fahrzeit fuer jetzt.';
        }
    }

    $st['ok'] = 1;
    /* Reihenfolge: die veraltete Fahrzeit (7) sticht den veralteten Kalender
     * (5), weil sie unmittelbar auf den Abfahrtszeitpunkt wirkt. */
    if ($veraltet) {
        $st['fehler'] = 7;
        $st['grund'] = 'Kartendienst nicht erreichbar - letzte bekannte Fahrzeit gilt weiter.';
    } elseif (!empty($kallage['veraltet'])) {
        $st['fehler'] = 5;
        $st['grund'] = 'Kalender nicht erreichbar - es gilt der letzte gelesene Stand.';
    } else {
        $st['fehler'] = 0;
        $st['grund'] = '';
    }
    $st['minstart'] = $minstart;
    $st['fahrt'] = $fahrt;
    $st['abfahrt_in'] = (int) round($minstart - $fahrt - (int) $abfcfg['arrival_min'] - (int) $abfcfg['buffer_min']);
    /* ANKUNFT: wann waere man da, wenn man JETZT losfuehre - als Minuten seit
     * Mitternacht, damit ein virtueller Eingang die Zahl tragen kann. Der Wert
     * beantwortet die Frage, die ABFAHRT_IN nicht beantwortet: bin ich schon
     * zu spaet, und um wie viel. 1440 heisst unbekannt. */
    $abf_ank = (int) round((time() + $fahrt * 60 - strtotime('today')) / 60);
    $st['ankunft'] = ($abf_ank >= 0 && $abf_ank < 1440) ? $abf_ank : 1440;
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
    $lb = getenv('LBHOMEDIR') ?: lb_wurzel_ermitteln();
    $aus = ['gefunden' => false, 'udpport' => 0, 'autostart' => false];
    if ($lb === '') { return $aus; }
    $f = $lb . '/config/system/general.json';
    if (!is_file($f)) { return $aus; }
    $d = json_decode((string) @file_get_contents($f), true);
    if (!isset($d['Mqtt'])) { return $aus; }
    $aus['gefunden'] = true;
    $aus['udpport'] = isset($d['Mqtt']['Udpinport']) ? (int) $d['Mqtt']['Udpinport'] : 0;
    $aus['autostart'] = !empty($d['Mqtt']['Gatewayautostart']); // NICHT 'Autostart' - den Schluessel gibt es nicht (Fehlerklasse ACTiKamera 1.9.2)
    /* Die FASSUNG des MQTT-Gateways, ab Werk 1. Sie entscheidet, was der
     * Anwender eintragen muss: unter V1 jedes Thema von Hand, ab V2
     * erscheint die Themengruppe von selbst in den Subscriptions.
     * 0 heisst "nicht feststellbar" - dann wird nichts behauptet,
     * sondern es werden beide Faelle genannt. */
    $aus['fassung'] = isset($d['Mqtt']['Gatewayversion'])
        ? (int) $d['Mqtt']['Gatewayversion'] : 0;
    return $aus;
}

/**
 * Der Hinweis zum MQTT-Abo - in der Fassung, die zum GATEWAY passt.
 *
 * Bis hierher stand an den Ausgabestellen unbedingt "Ohne diesen Eintrag
 * kommt am Miniserver nichts an". Das gilt fuer Gateway V1, wo jedes Thema
 * von Hand einzutragen ist. Ab V2 erscheint die Themengruppe von selbst in
 * den Subscriptions - der Satz schickte jeden V2-Anwender zu einem
 * Eingabeplatz, den es nicht gibt.
 *
 * Drei Ausgaenge, nicht zwei: ist die Fassung nicht feststellbar, werden
 * BEIDE Faelle genannt statt einer behauptet.
 */
function abfahrt_abo_text()
{
    $m = abfahrt_mqtt_zustand();
    $f = isset($m['fassung']) ? (int) $m['fassung'] : 0;
    if ($f <= 0) {
        return abfahrt_t('MQTT.ABO_UNBEKANNT');
    }
    $gemessen = ' <span class="sm-mono">'
              . sprintf(abfahrt_t('MQTT.ABO_GEMESSEN'), $f) . '</span>';
    return abfahrt_t($f >= 2 ? 'MQTT.ABO_V2' : 'MQTT.ABO_WARNUNG') . $gemessen;
}


/**
 * Werte an das MQTT-Gateway von LoxBerry schieben (UDP-Weiterleitung).
 *
 * Das Gateway ist Teil des Systems, kein Plugin - eingeschaltet wird es unter
 * System -> MQTT Gateway.
 */
/**
 * Einen Wert fuer den UDP-Eingang des MQTT-Gateways unschaedlich machen.
 *
 * Das Gateway liest ZEILENWEISE. Ein Zeilenumbruch im Wert - aus einer
 * Fehlermeldung, einem Geraetenamen oder der Ausgabe eines Systembefehls -
 * zerlegt die Uebertragung, und aus den Bruchstuecken bildet das Gateway
 * erfundene Themen. Ein Tabulator schadet ebenso, weil Leerzeichen Thema und
 * Wert trennt.
 */
function abf_mqtt_wert_saeubern($v)
{
    $wert = str_replace(array("\r\n", "\r", "\n", "\t"), ' ', (string) $v);
    return trim(preg_replace('/ {2,}/', ' ', $wert));
}

function abfahrt_mqtt_senden(array $werte, ?array $abfcfg = null) {
    if ($abfcfg === null) { $abfcfg = abfahrt_config(); }
    if (empty($abfcfg['mqtt_ein'])) { return false; }
    $m = abfahrt_mqtt_zustand();
    if (!$m['gefunden'] || !$m['udpport']) { return false; }
    $sock = @fsockopen('udp://127.0.0.1', (int) $m['udpport'], $en, $es, 2);
    if (!$sock) { return false; }
    $raus = 0;
    foreach ($werte as $name => $wert) {
        if (@fwrite($sock, 'publish ' . $abfcfg['mqtt_topic'] . '/' . $name . ' '
                . abf_mqtt_wert_saeubern($wert) . "\n") !== false) {
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
        /* Mit dem Port, auf dem der Webserver wirklich hoert.
         * abfahrt_webport() gibt es seit 1.5.0, benutzt wurde er nur fuer die
         * oertlichen Aufrufe - in der Adresse, die der Anwender nach Loxone
         * Config importiert, stand bis 1.6.6 immer Port 80.
         *
         * NUR, WENN DER NAME NICHT SCHON EINEN PORT TRAEGT: $host kommt aus
         * HTTP_HOST, und dort steht der Port mit drin, sobald die Oberflaeche
         * ueber einen anderen als 80 aufgerufen wird. Der erste Anlauf am
         * 05.09.2026 baute daraus "127.0.0.1:8741:8080". */
        'address' => 'http://' . $host
                   . ((strpos($host, ':') === false && abfahrt_webport() !== 80)
                      ? ':' . abfahrt_webport() : '')
                   . '/plugins/' . $plugindir . '/termin.php',
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

function abfahrt_pruefungen(?array $abfcfg = null) {
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
 * Diagnose je Kalender (neu in 1.6.0)
 *
 * Beantwortet die Frage, die FEHLER=4 offenlaesst: hat er im Kalender
 * nachgesehen und nichts gefunden - oder hat er gar nicht hingesehen?
 * Bis 1.5.8 stand das nur im Text von ?debug=1, und dort nur fuer den
 * gefundenen Termin, nicht je Kalender.
 * ================================================================== */

function abfahrt_kalender_diagnose(?array $abfcfg = null) {
    if ($abfcfg === null) { $abfcfg = abfahrt_config(); }
    $aus = [];
    foreach ($abfcfg['calendars'] as $i => $cal) {
        $url = trim((string) ($cal['url'] ?? ''));
        if ($url === '') { continue; }
        $z = [
            'nr' => $i + 1,
            'name' => trim((string) ($cal['name'] ?? '')),
            'gastgeber' => (string) (parse_url($url, PHP_URL_HOST) ?: '?'),
            'alter' => null, 'grund' => '', 'vevents' => 0,
            'mit_ort' => 0, 'naechste' => [],
        ];
        $cache = abfahrt_tmpdir() . '/ics_' . md5($url);
        $grund = '';
        $ics = abfahrt_fetch_ics($url, $grund);
        $z['grund'] = $grund;
        if (is_file($cache)) { $z['alter'] = time() - filemtime($cache); }
        if ($ics === false) {
            $aus[] = $z;
            continue;
        }
        $z['vevents'] = substr_count($ics, 'BEGIN:VEVENT');

        /* Fuer die Zaehlung wird derselbe Weg benutzt wie im Betrieb - eine
         * zweite, eigene Zaehlung liefe frueher oder spaeter auseinander, und
         * dann stuende in der Diagnose etwas anderes als im Ergebnis. */
        $einzeln = $abfcfg;
        $einzeln['calendars'] = [$cal];
        $d = [];
        $best = abfahrt_next_event($einzeln, $d);
        foreach ($d as $zeile) {
            if (preg_match('/: (\d+) Termin/', $zeile, $m)) { $z['mit_ort'] = (int) $m[1]; }
        }
        if ($best !== null) {
            $z['naechste'][] = ['zeit' => date('d.m.Y H:i', $best[0]),
                                'titel' => $best[2], 'ort' => $best[1]];
        }
        $aus[] = $z;
    }
    return $aus;
}

/* ==================================================================
 * Geokodierung sichtbar machen (neu in 1.6.0)
 *
 * Der Zwischenspeicher haelt 90 Tage. Ein Tippfehler in der Abfahrtsadresse
 * fuehrt so lange zu einer Fahrzeit, die plausibel aussieht und von der
 * falschen Stelle aus gerechnet ist. Wer die Koordinaten sieht, merkt es.
 * ================================================================== */

function abfahrt_geo_stand(array $abfcfg) {
    $adr = trim((string) $abfcfg['home_address']);
    $aus = ['adresse' => $adr, 'da' => false, 'koordinaten' => '', 'alter' => null,
            'karte' => ''];
    if ($adr === '') { return $aus; }
    $cache = abfahrt_tmpdir() . '/geo_' . md5($abfcfg['provider'] . '|' . $adr);
    if (!is_file($cache)) { return $aus; }
    $wert = abfahrt_cache_lesen($cache, ABFAHRT_GEO_MUSTER);
    if ($wert === false) { return $aus; }
    $aus['da'] = true;
    $aus['koordinaten'] = $wert;
    $aus['alter'] = time() - filemtime($cache);
    // Zum Nachsehen auf einer Karte. Nur die Koordinaten, keine Adresse -
    // die geht niemanden etwas an, der die Adresszeile mitliest.
    $aus['karte'] = 'https://www.openstreetmap.org/?mlat=' . rawurlencode(strtok($wert, ','))
                  . '&mlon=' . rawurlencode(substr($wert, strpos($wert, ',') + 1)) . '#map=16/'
                  . rawurlencode(str_replace(',', '/', $wert));
    return $aus;
}

/** Alle zwischengespeicherten Koordinaten verwerfen. Rueckgabe: Anzahl. */
function abfahrt_geo_verwerfen() {
    $n = 0;
    foreach (glob(abfahrt_tmpdir() . '/geo_*') ?: [] as $f) {
        if (is_file($f) && @unlink($f)) { $n++; }
    }
    return $n;
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
    $lb = getenv('LBHOMEDIR') ?: lb_wurzel_ermitteln();

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
            foreach (array(lb_wurzel_ermitteln(), '/home/loxberry/loxberry') as $k) {
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


/**
 * Den ganzen Konfigurationsstand ablegen - und sagen, ob es geklappt hat.
 *
 * Bisher schrieb diese Linie mitten in index.php. Das Zurueckspielen einer
 * Sicherung braucht aber EINE Stelle, sonst steht die Pruefung "hat es
 * geklappt?" an vier Orten verschieden da.
 *
 * Der Schreibweg ist der, den die Linie ohnehin benutzt - hier wird kein
 * Verhalten geaendert, nur ein vorhandenes zusammengefasst.
 */
function abfahrt_config_speichern($cfg)
{
    $p = abfahrt_paths();
    $js = json_encode($cfg, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE
                            | JSON_UNESCAPED_SLASHES);
    if ($js === false) {
        return false;   /* ungueltiges UTF-8 - lieber gar nicht schreiben
                           als eine halbe Datei hinterlassen */
    }
    @mkdir(dirname($p['config']), 0775, true);
    if (@file_put_contents($p['config'], $js) === false) {
        return false;
    }
    /* Rechte und Zweitschrift gehoeren HIERHIN, nicht in die vier Aufrufer.
     *
     * BERICHTIGT 05.09.2026. Die vier Speicherwege in index.php machten je
     * chmod 0600 und legten die Zweitschrift an; der einzige Aufrufer dieser
     * Funktion - das Zurueckspielen - machte beides nicht. Folge: nach einem
     * Zurueckspielen lag die Datei mit dem Schluessel des Kartendienstes mit
     * den Umask-Rechten da, und die Zweitschrift trug weiter den Stand VOR
     * dem Zurueckspielen. Aus genau dieser Zweitschrift heilen sich
     * index.php und postupgrade.sh selbst - das Zurueckspielen waere beim
     * naechsten Anlass stillschweigend wieder verworfen worden. */
    @chmod($p['config'], 0600);
    $zweit = dirname($p['config']) . '.backup.json';
    @copy($p['config'], $zweit);
    @chmod($zweit, 0600);
    return true;
}


/**
 * Die Sicherungsdatei bauen - mit lesbarem Kopf.
 *
 * Der Kopf ist Hausstandard und war bis 1.6.6 nicht da: die Datei bestand
 * nur aus den Einstellungen. Wer sie ein halbes Jahr spaeter in einem Ordner
 * wiederfindet, sieht ihr sonst nicht an, wohin sie gehoert - und dass sie
 * ein Geheimnis traegt.
 *
 * Die Schluessel mit dem Unterstrich sind KEINE Einstellungen;
 * abfahrt_sicherung_lesen() uebergeht sie deshalb, statt sie zu beanstanden.
 */
function abfahrt_sicherung_bauen(array $cfg)
{
    /* KEINE Fassungsnummer im Kopf. Es gaebe dafuer keine belastbare
     * Quelle: die plugin.cfg wird nicht in den Plugin-Baum installiert, und
     * parse_ini_file() liest sie ohnehin nicht (gemessen 05.09.2026: sie
     * bricht an der ersten Raute-Kommentarzeile ab, PHP kennt '#' nicht mehr
     * als Kommentarzeichen). Der Aufbau der Plugindatenbank ist an keiner
     * Anlage nachgesehen - eine geratene Feldbezeichnung waere schlimmer als
     * eine fehlende Zeile. */
    $kopf = array(
        '_plugin'  => 'abfahrtsassistent',
        '_stand'   => date('Y-m-d H:i:s'),
        '_hinweis' => 'Diese Datei enthaelt das Merkwort und den Schluessel des '
                    . 'Kartendienstes. Wie ein Passwort behandeln.',
    );
    return json_encode($kopf + $cfg,
        JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}


/**
 * Taugt dieser Wert fuer diese Einstellung?
 *
 * ANLASS (gemessen 04.09.2026): abfahrt_sicherung_lesen() pruefte nur die
 * SCHLUESSELNAMEN. Eine Sicherungsdatei mit provider="boese",
 * buffer_min="abc", arrival_min=-999 und lookahead_hours=99999 wurde
 * anstandslos uebernommen und stand danach roh in der abfahrt.json;
 * abfahrt_config() reparierte davon nichts. arrival_min=-999 verschiebt die
 * Abfahrtsempfehlung um mehr als sechzehn Stunden.
 *
 * Geprueft wird gegen dieselben Grenzen, die der Speichern-Handler der
 * Oberflaeche schon immer angelegt hat - dort war es richtig, nur hier
 * nicht. Rueckgabe: der gepruefte Wert, oder null mit Begruendung.
 */
function abfahrt_wert_pruefen($schluessel, $wert, &$grund = '')
{
    $grund = '';
    $zahl = function ($w, $min, $max) use (&$grund) {
        if (is_array($w) || is_bool($w) || is_null($w)) { $grund = 'keine Zahl'; return null; }
        if (!is_int($w) && !is_float($w) && !preg_match('/^-?\d+$/', trim((string) $w))) {
            $grund = 'keine Zahl'; return null;
        }
        $i = (int) $w;
        if ($i < $min || $i > $max) { $grund = 'ausserhalb ' . $min . '..' . $max; return null; }
        return $i;
    };
    $text = function ($w, $max) use (&$grund) {
        if (is_array($w) || is_bool($w) || is_null($w)) { $grund = 'kein Text'; return null; }
        $s = (string) $w;
        if (strlen($s) > $max) { $grund = 'laenger als ' . $max . ' Zeichen'; return null; }
        // Steuerzeichen ausser Tabulator, CR und LF: die haben in keiner
        // dieser Einstellungen etwas zu suchen.
        if (preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $s)) {
            $grund = 'enthaelt Steuerzeichen'; return null;
        }
        return $s;
    };
    $schalter = function ($w) use (&$grund) {
        if (is_array($w)) { $grund = 'kein Schalter'; return null; }
        return empty($w) ? 0 : 1;
    };

    switch ($schluessel) {
        case 'provider':
            $s = (string) (is_array($wert) ? '' : $wert);
            if (!in_array($s, array('tomtom', 'google', 'here'), true)) {
                $grund = 'unbekannter Kartendienst'; return null;
            }
            return $s;
        case 'buffer_min':
        case 'arrival_min':   return $zahl($wert, 0, 120);
        case 'lookahead_hours': return $zahl($wert, 1, 48);
        case 'mqtt_vollsend_min': return $zahl($wert, 0, 1440);
        case 'mqtt_ein':
        case 'route_departat':
        case 'quiet_push':
        case 'ganztags_ein':  return $schalter($wert);
        case 'api_key':       return $text($wert, 200);
        case 'home_address':  return $text($wert, 300);
        case 'ansage_vorlage': return $text($wert, 500);
        case 'ignore_locations': return $text($wert, 2000);
        case 'mqtt_topic':
            $s = $text($wert, 64);
            if ($s === null) { return null; }
            if ($s !== '' && !preg_match('#^[A-Za-z0-9_/\-]+$#', $s)) {
                $grund = 'unzulaessiges Zeichen im Thema'; return null;
            }
            return $s;
        case 'ganztags_zeit':
            $s = $text($wert, 5);
            if ($s === null) { return null; }
            if (!preg_match('/^([01]?\d|2[0-3]):[0-5]\d$/', $s)) {
                $grund = 'keine Uhrzeit HH:MM'; return null;
            }
            return $s;
        case 'aktionstoken':
            /* Das Muster bleibt WEIT: zugelassen wird, was ohne Kodierung in
             * eine Adresse passt. Ein zu enges Muster verwirft ein von Hand
             * gesetztes oder aus einer aelteren Fassung uebernommenes
             * Merkwort, und der Schaden ist derselbe wie bei einem
             * verlorenen. Die Laenge 0 ist zulaessig und heisst "kein
             * Merkwort gesichert" - was damit geschieht, entscheidet
             * abfahrt_sicherung_lesen(), nicht diese Wertpruefung. */
            $s = $text($wert, 64);
            if ($s === null) { return null; }
            if ($s !== '' && !preg_match('/^[A-Za-z0-9_.\-]+$/', $s)) {
                $grund = 'unzulaessiges Zeichen im Merkwort'; return null;
            }
            return $s;
        case 'calendars':
            if (!is_array($wert)) { $grund = 'keine Liste'; return null; }
            if (count($wert) > 10) { $grund = 'mehr als 10 Kalender'; return null; }
            $aus = array();
            foreach ($wert as $c) {
                if (!is_array($c)) { $grund = 'Eintrag ist keine Zeile'; return null; }
                $n = $text(isset($c['name']) ? $c['name'] : '', 100);
                $u = $text(isset($c['url']) ? $c['url'] : '', 500);
                if ($n === null || $u === null) { return null; }
                if ($u !== '' && !preg_match('#^https?://#i', $u)) {
                    $grund = 'Kalenderadresse ohne http(s)'; return null;
                }
                $aus[] = array('name' => $n, 'url' => $u);
            }
            return $aus;
        case 'tts':
        case 'notify':
        case 'quiet':
        case 'ortsbuch':
            if (!is_array($wert)) { $grund = 'kein Feld'; return null; }
            /* Der Feinschliff dieser vier uebernimmt abfahrt_config(): es
             * setzt fehlende Unterschluessel, klemmt die Sperrzeiten und
             * wirft alles weg, was keine Zeile ist. Hier wird nur die Form
             * geprueft - alles andere waere eine zweite Stelle, die
             * auseinanderlaeuft. */
            return $wert;
    }
    $grund = 'unbekannte Einstellung';
    return null;
}


/**
 * Eine Sicherungsdatei einlesen - und dabei NICHTS durchgehen lassen.
 *
 * Der wichtigste Punkt: eine halb gueltige Datei ueberschreibt GAR NICHTS.
 * Wer eine Sicherung zurueckspielt, will entweder den ganzen Stand oder
 * gar keinen - eine zur Haelfte uebernommene Konfiguration ist schlimmer
 * als die alte, und man sieht es ihr nicht an.
 *
 * Unbekannte Schluessel sind eine Beanstandung, kein stiller Verlust: sie
 * stammen aus einer anderen Fassung oder einem anderen Plugin.
 *
 * Rueckgabe: array(Konfiguration|null, Beanstandungen[], uebernommene Werte).
 */
function abfahrt_sicherung_lesen($roh)
{
    $mangel = array();
    $hinweise = array();
    $daten = json_decode((string) $roh, true);
    if (!is_array($daten)) {
        return array(null, array(abfahrt_t('TEXT.SICH_KEIN_JSON')), 0);
    }

    /* Grundlage ist der JETZIGE Stand, nicht die Werkseinstellung.
     *
     * BERICHTIGT 05.09.2026, und das war der schwerste Befund der Durchsicht.
     * Vorher wurde auf abfahrt_vorgaben() aufgesetzt: eine Datei mit EINEM
     * bekannten Schluessel wurde angenommen, meldete "1 Werte uebernommen"
     * und setzte alles andere auf Werk zurueck. Gemessen am 04.09.2026 mit
     * {"home_address":"..."} - danach waren Kalender, Schluessel des
     * Kartendienstes und Merkwort weg, und beim naechsten Oeffnen der
     * Oberflaeche entstand ein neues Merkwort: JEDE in Loxone Config
     * eingetragene Adresse war ab da ungueltig, und zwar stumm, weil ein
     * Virtueller Ausgang die 403-Antwort nicht auswertet.
     *
     * Ein Schluessel, der in der Sicherung fehlt, behaelt jetzt seinen
     * jetzigen Wert, und es wird gesagt, wie viele das waren. */
    $jetzt = abfahrt_config();
    $vorgaben = abfahrt_vorgaben();
    $neu = array();
    foreach (array_keys($vorgaben) as $k) {
        $neu[$k] = array_key_exists($k, $jetzt) ? $jetzt[$k] : $vorgaben[$k];
    }

    $anzahl = 0;
    $gesehen = array();
    foreach ($daten as $k => $w) {
        /* Der lesbare Kopf wird UEBERGANGEN, nicht beanstandet. Bis 1.6.6
         * lehnte diese Funktion eine sonst gueltige Sicherung mit _hinweis
         * und _stand vollstaendig ab - waehrend die eigene Ausfuhr gar
         * keinen Kopf schrieb. */
        if ($k !== '' && $k[0] === '_') {
            continue;
        }
        if (!array_key_exists($k, $vorgaben)) {
            $mangel[] = sprintf(abfahrt_t('TEXT.SICH_FREMD'), (string) $k);
            continue;
        }
        $grund = '';
        $wert = abfahrt_wert_pruefen($k, $w, $grund);
        if ($wert === null) {
            $mangel[] = sprintf(abfahrt_t('TEXT.SICH_WERT'), (string) $k, $grund);
            continue;
        }
        $neu[$k] = $wert;
        $gesehen[$k] = 1;
        $anzahl++;
    }

    if ($anzahl === 0) {
        $mangel[] = abfahrt_t('TEXT.SICH_LEER');
    }

    /* Ein leeres Merkwort in einer Sicherungsdatei heisst "kein Merkwort
     * gesichert" - es ist kein unzulaessiger Wert, aber es darf auch nicht
     * das vorhandene loeschen. Beides zusammen ergibt: das jetzige behalten
     * und es sagen. */
    if (isset($gesehen['aktionstoken']) && $neu['aktionstoken'] === ''
        && trim((string) (isset($jetzt['aktionstoken']) ? $jetzt['aktionstoken'] : '')) !== '') {
        $neu['aktionstoken'] = $jetzt['aktionstoken'];
        $hinweise[] = abfahrt_t('TEXT.SICH_TOKEN_BEHALTEN');
    }

    $fehlend = array();
    foreach (array_keys($vorgaben) as $k) {
        if (!isset($gesehen[$k])) { $fehlend[] = $k; }
    }

    if ($mangel) {
        return array(null, $mangel, $anzahl);
    }
    /* Kein Mangel, aber unvollstaendig: das ist kein Grund abzulehnen (eine
     * Sicherung aus einer aelteren Fassung kennt neue Schluessel nicht),
     * wohl aber einer, es zu sagen. */
    if ($fehlend) {
        $hinweise[] = sprintf(abfahrt_t('TEXT.SICH_FEHLEND'),
                              count($fehlend), implode(', ', $fehlend));
    }
    return array($neu, $hinweise, $anzahl);
}
