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
/* display_errors aus - auch hier, nicht nur in der Oberflaeche. Diese
 * Bibliothek wird von den beiden Endpunkten im UNANGEMELDETEN Bereich
 * geladen; eine Warnung, die dort vor der Antwortzeile hinausgeht, verhindert
 * den Statuscode und zeigt dem Anfragenden Dateipfade. */
ini_set('display_errors', '0');
// Die Zeitzone setzt abfahrt_zeitzone_setzen() weiter unten (nach abfahrt_lbhome()).
/* Gemeinsame Sprachausgabe (Abschrift von Werkzeuge/gemeinsam/sprachausgabe.php, Nr. 36 b).
 * Seit 1.6.23 Stufe 2: Sprechen, Formular, Zeile im Reiter Test und Testansage kommen aus
 * dem Modul (Fassung 1.1.1; bis 1.6.22 die Zwischenfassung 1.0.3). Liegt neben dieser
 * Datei. Bindet diese Bibliothek spaeter ferien_lib.php des Plugins Ferien und Feiertage
 * ein und traegt jenes eine eigene Abschrift, gilt die hier zuerst geladene; die Datei
 * schuetzt sich selbst gegen doppeltes Laden. */
require_once __DIR__ . '/sprachausgabe.php';


/* Den LoxBerry-Wurzelordner ohne festen Systempfad bestimmen.
 *
 * Vom eigenen Ablageort aufwaerts, bis ein Verzeichnis gefunden ist, das
 * config/plugins, data/plugins UND config/system/general.json traegt. Das
 * trifft die uebliche Installation genauso wie eine an einem anderen Ort;
 * liegt das Plugin als entpacktes Archiv ausserhalb eines LoxBerry, findet
 * die Suche nichts und gibt einen Leerstring zurueck.
 *
 * general.json ist die entscheidende Bedingung (Regeln/06, Raumklima-Vorfall).
 * Bis 1.6.12 genuegten config/plugins und webfrontend - genau diese Ordner
 * hinterlaesst ein Pruefstand auf einem Arbeitsrechner. In WSL gemessen
 * (Pruefung-Abfahrtsassistent-1.6.13, Faelle H1 bis H3): in einem fremden
 * Baum ohne general.json nahm diese Bibliothek den Baum als Wurzel; der
 * Dienst schrieb sein Protokoll dorthin, die Oberflaeche legte dort eine
 * Konfiguration samt Merkwort an.
 *
 * Der Name traegt kein Plugin-Kuerzel und ist deshalb abgesichert: zwei
 * Bibliotheken landen nie im selben Prozess, aber die Pruefung kostet nichts.
 */
if (!function_exists('lb_wurzel_ermitteln')) {
    function lb_wurzel_ermitteln()
    {
        $d = __DIR__;
        for ($i = 0; $i < 8; $i++) {
            if (is_dir($d . '/config/plugins') && is_dir($d . '/data/plugins')
                && is_file($d . '/config/system/general.json')) {
                return $d;
            }
            $eltern = dirname($d);
            if ($eltern === $d) { break; }
            $d = $eltern;
        }
        return '';
    }
}

/* Die Wurzel in der Reihenfolge der Hausregel: erst die Umgebung, dann die
 * Suche - und danach nichts mehr, kein fester Pfad.
 *
 * Ein gesetztes LBHOMEDIR gilt mit config/plugins UND data/plugins darunter;
 * general.json wird hier nicht verlangt, damit die Attrappen der
 * Pruefwerkzeuge weiter tragen. Rueckgabe '' heisst "keine Wurzel"; jeder
 * Aufrufer muss das abfangen. Bauart awm_lbhome() aus AWM-Abfuhr 1.4.13. */
function abfahrt_lbhome()
{
    $h = getenv('LBHOMEDIR');
    if ($h && is_dir($h . '/config/plugins') && is_dir($h . '/data/plugins')) {
        return rtrim($h, '/');
    }
    return lb_wurzel_ermitteln();
}

/* Die Zeitzone der Anlage statt fest Europe/Berlin (bis 1.6.21; Pruefung
 * 02.10.2026, Nr. 28b). Reihenfolge: general.json von LoxBerry
 * (Timeserver.Timezone), /etc/timezone, /etc/localtime, date.timezone aus
 * der php.ini. Jeder Name wird beim Setzen geprueft; gilt keiner, bleibt es
 * bei Europe/Berlin. */
function abfahrt_zeitzone_setzen()
{
    $kandidaten = array();
    $home = abfahrt_lbhome();
    if ($home !== '' && is_file($home . '/config/system/general.json')) {
        $g = @json_decode((string) @file_get_contents($home . '/config/system/general.json'), true);
        if (is_array($g) && isset($g['Timeserver']['Timezone']) && is_string($g['Timeserver']['Timezone'])) {
            $kandidaten[] = $g['Timeserver']['Timezone'];
        }
    }
    if (is_file('/etc/timezone')) {
        $kandidaten[] = (string) @file_get_contents('/etc/timezone');
    }
    $ziel = @readlink('/etc/localtime');
    if (is_string($ziel) && preg_match('#zoneinfo/(.+)$#', $ziel, $m)) {
        $kandidaten[] = $m[1];
    }
    $kandidaten[] = (string) ini_get('date.timezone');
    $kandidaten[] = 'Europe/Berlin';
    foreach ($kandidaten as $k) {
        $k = trim($k);
        if ($k !== '' && preg_match('#^[A-Za-z][A-Za-z0-9_+\-/]*$#', $k) && @date_default_timezone_set($k)) {
            return $k;
        }
    }
    return date_default_timezone_get();
}
abfahrt_zeitzone_setzen();

/**
 * Die Pfade - der Anlage, oder im Archivmodus die Ersatzpfade.
 *
 * Die Pfade DER ANLAGE gelten nur, wenn diese Bibliothek dort installiert
 * liegt (<Wurzel>/webfrontend/html/plugins/<ordner>, physisch verglichen)
 * oder der Aufrufer Wurzel UND Ordner ausdruecklich nennt ($LBHOMEDIR und
 * $LBPPLUGINDIR - so arbeiten die Pruefwerkzeuge mit ihrer Attrappe, und so
 * ruft die Deinstallation den Dienst). Sonst ist das ein ausgepacktes Archiv
 * oder ein Pruefordner, und es gelten Ersatzpfade im Temp-Ordner unter einem
 * eigenen Namen - nie ein Pfad der Anlage, nie einer ab der Laufwerkswurzel
 * und nie der Zwischenordner /tmp/<ordner> der Anlage.
 *
 * Bis 1.6.12 nahm ein Archiv unterhalb einer echten Wurzel diese Wurzel und
 * den Ordnernamen "html": der Dienst aus dem Archiv schrieb sein Protokoll
 * nach log/plugins/html/ der Anlage und sendete OK, FEHLER und das
 * Lebenszeichen an deren MQTT-Gateway; die Oberflaeche legte beim Oeffnen
 * config/plugins/html/abfahrt.json samt Merkwort an - mit $LBHOMEDIR allein,
 * wie es am Geraet in /etc/environment steht, ebenso (in WSL gemessen,
 * Pruefung-Abfahrtsassistent-1.6.13, Faelle B1, B2, B6, B7). Ohne Wurzel lag
 * die Konfiguration im Archiv selbst, aus /webfrontend/html also ab der
 * Laufwerkswurzel (Fall C7), und das Protokoll im Zwischenordner der Anlage.
 * Bauart awm_paths() aus AWM-Abfuhr 1.4.13.
 *
 * Einmal je Prozess bestimmt: abfahrt_daytype() setzt LBPPLUGINDIR fuer die
 * Bibliothek des Ferien-Plugins voruebergehend um - danach darf diese
 * Funktion nicht auf fremde Pfade zeigen.
 */
function abfahrt_paths() {
    static $p = null;
    if ($p !== null) {
        return $p;
    }
    $home = abfahrt_lbhome();
    /* basename(__DIR__), NICHT basename(dirname(__DIR__, 1)).
     *
     * Gemessen am 04.09.2026: installiert liegt diese Datei unter
     * .../webfrontend/html/plugins/<ordner>/. dirname(__DIR__, 1) ergibt
     * dort .../plugins, und basename davon ist "plugins" - der Pfad zeigte
     * ohne gesetztes LBPPLUGINDIR auf config/plugins/plugins/abfahrt.json.
     *
     * Die zweite Wirkung war schlimmer, weil sie ein Werkzeug blind machte:
     * index.php leitet den Ordner aus basename(__DIR__) ab, diese Datei aus
     * dem Elternordner. Im Archivbau landeten beide auf VERSCHIEDENEN
     * Dateien (config/plugins/htmlauth gegen config/plugins/html), und
     * wirkungstest.py meldete deshalb bei JEDEM Lauf, das Aktionstoken gehe
     * bei jeder Absendung verloren. Seit 1.6.13 nimmt index.php Ordner und
     * Pfade von hier. */
    $self = basename(__DIR__);
    /* DIE UMGEBUNG STICHT: LBPPLUGINDIR ist die Auskunft von LoxBerry selbst
     * (gemessen am 05.09.2026: sonst schrieben Bibliothek und Oberflaeche im
     * Archivbau in verschiedene Dateien). Von ihr zaehlt nur der letzte
     * Pfadteil, und die Namen, die nachweislich kein Pluginordner sind,
     * gelten auch dort nicht. Der feste Name greift nur, wo der abgeleitete
     * kein Pluginordner sein KANN - aus dem ausgepackten Archiv heisst er
     * "html". */
    $nie = array('', '.', '/', 'html', 'htmlauth', 'bin', 'plugins', 'webfrontend');
    $lbp = basename(rtrim((string) getenv('LBPPLUGINDIR'), '/'));
    $lbp_gilt = !in_array($lbp, $nie, true);
    if ($lbp_gilt) {
        $plugindir = $lbp;
    } elseif (!in_array($self, $nie, true)) {
        $plugindir = $self;
    } else {
        $plugindir = 'abfahrtsassistent';
    }
    $gefunden = $home;
    if ($home !== '') {
        $soll = @realpath($home . '/webfrontend/html/plugins/' . $self);
        $ist = @realpath(__DIR__);
        $installiert = ($soll !== false && $ist !== false && $soll === $ist);
        $ausdruecklich = $lbp_gilt && $home === rtrim((string) getenv('LBHOMEDIR'), '/');
        if (!$installiert && !$ausdruecklich) { $home = ''; }
    }
    /* Der Zwischenordner traegt den ermittelten Ordnernamen (seit 1.6.10).
     * Bis 1.6.9 stand hier fest /tmp/abfahrtsassistent: eine zweite
     * Installation (LoxBerry haengt dann _01 an) teilte sich stand.json,
     * titel.json, dienst.lock und alle Zwischenspeicher mit der ersten - je
     * Minute rechnete nur einer der beiden Dienste, und beide Endpunkte
     * gaben denselben Termin aus. Fuer die uebliche Installation aendert
     * sich der Pfad nicht. Der Ordnername wird auf sichere Zeichen
     * beschraenkt, weil er aus der Umgebung kommen kann. */
    $tmpname = preg_replace('/[^A-Za-z0-9_\-]/', '', (string) $plugindir);
    if ($tmpname === '') { $tmpname = 'abfahrtsassistent'; }
    if ($home !== '') {
        $p = array(
            'config'  => $home . '/config/plugins/' . $plugindir . '/abfahrt.json',
            'backup'  => $home . '/config/plugins/' . $plugindir . '.backup.json',
            'tmp'     => '/tmp/' . $tmpname,
            'log'     => $home . '/log/plugins/' . $plugindir . '/abfahrt.log',
            'data'    => $home . '/data/plugins/' . $plugindir,
            'lbhome'  => $home,
            'plugin'  => $plugindir,
            'general' => $home . '/config/system/general.json',
            'archiv'  => '',
        );
        return $p;
    }
    /* Keine Wurzel (Entwicklung, Pruefstand, fremder Baum) oder Archivmodus:
     * die Ersatzpfade unter dem Temp-Ordner. Der Dienst steigt in beiden
     * Faellen vorher aus (abfahrt_keine_wurzel_abbruch()); MQTT verlangt eine
     * Wurzel. */
    $a = sys_get_temp_dir() . '/abfahrtsassistent-archiv';
    $p = array(
        'config'  => $a . '/abfahrt.json',
        'backup'  => $a . '/abfahrt.backup.json',
        'tmp'     => $a . '/tmp',
        'log'     => $a . '/abfahrt.log',
        'data'    => $a . '/data',
        'lbhome'  => '',
        'plugin'  => $plugindir,
        'general' => '',
        // Die gefundene Wurzel, wenn diese Datei NICHT darin installiert
        // liegt (Archivmodus) - fuer die Meldung; sonst leer.
        'archiv'  => $gefunden,
    );
    return $p;
}

/* Fuer den Dienst: ohne Pfade der Anlage (keine Wurzel oder ausgepacktes
 * Archiv) nichts tun, eine Meldung auf stderr, Rueckgabewert 1. Steht dort
 * VOR der Sperre, denn schon die legt eine Datei an. Bauart
 * awm_keine_wurzel_abbruch() aus AWM-Abfuhr 1.4.13. */
function abfahrt_keine_wurzel_abbruch($programm)
{
    $p = abfahrt_paths();
    if ($p['lbhome'] !== '') { return; }
    if ($p['archiv'] !== '') {
        fwrite(STDERR, $programm . ': Diese Datei liegt nicht in der Installation unter '
            . $p['archiv'] . "\n"
            . '(ausgepacktes Archiv oder Pruefordner). Damit nichts in die Anlage kommt,' . "\n"
            . 'wurde nichts berechnet, nichts gesendet und nichts geschrieben.' . "\n"
            . 'Abhilfe: das Programm aus ' . $p['archiv'] . '/bin/plugins/<ordner>' . "\n"
            . 'aufrufen oder LBHOMEDIR und LBPPLUGINDIR ausdruecklich setzen.' . "\n");
        exit(1);
    }
    fwrite(STDERR, $programm . ': Es wurde kein LoxBerry-Wurzelverzeichnis gefunden.' . "\n"
        . '$LBHOMEDIR ist nicht gesetzt, und oberhalb von ' . __DIR__ . ' traegt kein' . "\n"
        . 'Verzeichnis config/plugins, data/plugins und config/system/general.json.' . "\n"
        . 'Es wurde nichts berechnet, nichts gesendet und nichts geschrieben.' . "\n");
    exit(1);
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
    /* MQTT steht ab Werk AN - seit 1.5.x, also keine neue Funktion. Die
     * Vorgabe bleibt 1, entschieden am 16.09.2026: auf jeder Anlage, deren
     * abfahrt.json aus der Zeit vor dem MQTT-Reiter stammt, fehlt der
     * Schluessel, und dort greift die Vorgabe. Gemessen an der Anlage des
     * Hausherrn: Konfiguration vom 11.08.2026 ohne mqtt_ein, die Projektdatei
     * liest alle neun Werte ueber abfahrt_*-Eingaenge des Gateways. Eine 0
     * haette dort beim Update jede Uebertragung abgeschaltet. */
    'mqtt_ein' => 1,
    'mqtt_topic' => 'abfahrt',
    /* --- neu in 1.6.0 -------------------------------------------------
     * Bis auf zwei stehen alle ab Werk aus beziehungsweise leer (mqtt_ein
     * oben ist aelter und zaehlt hier nicht mit).
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

/**
 * Die Konfigurationsdatei roh lesen und sagen, in welchem Zustand sie ist.
 *
 * Rueckgabe: array(Feld|null, Zustand) mit Zustand
 *   'fehlt'   - keine Datei
 *   'leer'    - Datei ohne Inhalt oder nur "{}"
 *   'kaputt'  - Inhalt, aber kein gueltiges JSON-Objekt
 *   'unlesbar'- Datei da, aber fuer diesen Prozess nicht lesbar (Rechte)
 *   'ok'      - gueltiges Objekt
 *
 * ANLASS (gemessen 06.09.2026 an 1.6.9): hier stand json_decode(...) ?: [].
 * Eine halb geschriebene abfahrt.json wurde damit still zu einer leeren
 * Konfiguration, die Oberflaeche sah kein Merkwort, wuerfelte ein neues und
 * schrieb die Werkseinstellung ueber Konfiguration UND Zweitschrift -
 * Kartendienst-Schluessel, Kalender und alle Loxone-Adressen weg, ohne eine
 * Zeile im Protokoll. Ungueltiges JSON ist ein Fehler, kein leerer Zustand.
 */
function abfahrt_config_roh($datei = null) {
    if ($datei === null) {
        $p = abfahrt_paths();
        $datei = $p['config'];
    }
    if (!is_file($datei)) { return array(null, 'fehlt'); }
    /* Bis 1.6.21 hiess "nicht lesbar" hier 'kaputt': eine gueltige, aber nach
     * einem Handstart als root unlesbare Datei (root:root 0600) wurde von
     * abfahrt_config_heilen() beiseitegelegt, und ohne lesbare Zweitschrift
     * erzeugte die Oberflaeche ein neues Merkwort (Pruefung 02.10.2026, Nr. 5).
     * Unlesbar ist kein Inhaltsbefund - angefasst wird dann nichts. */
    if (!is_readable($datei)) { return array(null, 'unlesbar'); }
    $roh = @file_get_contents($datei);
    if ($roh === false) { return array(null, 'unlesbar'); }
    $roh = trim($roh);
    if ($roh === '' || $roh === '{}' || $roh === '[]') { return array(array(), 'leer'); }
    $d = json_decode($roh, true);
    if (!is_array($d) || ($d !== array() && array_keys($d) === range(0, count($d) - 1))) {
        return array(null, 'kaputt');
    }
    return array($d, 'ok');
}

/**
 * Welche Werte hat abfahrt_config() beim letzten Lesen abgewiesen, und in
 * welchem Zustand war die Datei? Wird von der Selbstheilung und vom Reiter
 * Test gelesen, damit ein abgewiesener Wert nicht nur still auf die Vorgabe
 * faellt.
 */
function abfahrt_config_lage($neu = null) {
    static $lage = array('zustand' => 'fehlt', 'abgewiesen' => array(), 'fehlend' => array());
    if ($neu !== null) { $lage = $neu; }
    return $lage;
}

/**
 * Das MQTT-Praefix in seiner einzigen gueltigen Form: nur erlaubte Zeichen,
 * kein '/' am Rand, kein '//'. Bis 1.6.21 nahm die Pruefung "/abfahrt/" an,
 * gesendet wurde an "/abfahrt//OK", abonniert aber "abfahrt/#" - unter V1
 * kam nichts an, und der Reiter Test meldete das Abo als geliefert
 * (Pruefung 02.10.2026, Nr. 15). Bestehende Einstellungen heilen beim Lesen.
 */
function abfahrt_mqtt_praefix_norm($t) {
    $t = preg_replace('#[^A-Za-z0-9_/\-]#', '', (string) $t);
    $t = preg_replace('#/{2,}#', '/', $t);
    return trim($t, '/');
}

function abfahrt_config() {
    list($abfcfg, $zustand) = abfahrt_config_roh();
    if (!is_array($abfcfg)) { $abfcfg = array(); }
    $vorgaben = abfahrt_vorgaben();
    // Nr. 15: nur die Schraegstriche vorab glaetten; unzulaessige Zeichen
    // weist die Pruefung unten weiterhin ab und meldet sie.
    if (isset($abfcfg['mqtt_topic']) && is_string($abfcfg['mqtt_topic'])) {
        $abfcfg['mqtt_topic'] = trim(preg_replace('#/{2,}#', '/', $abfcfg['mqtt_topic']), '/');
    }
    /* Jeder vorhandene Wert wird gegen dieselben Grenzen geprueft wie beim
     * Zurueckspielen einer Sicherung. Bis 1.6.9 sass die Pruefung nur dort:
     * eine von Hand bearbeitete abfahrt.json mit arrival_min=-999 oder
     * api_key als Feld lief ungeprueft in die Berechnung (unter PHP 8 ein
     * TypeError im Minutentakt). Ein abgewiesener Wert faellt auf die Vorgabe
     * und wird GEMELDET - abfahrt_config_lage(), Protokoll ueber
     * abfahrt_config_heilen(). Unbekannte Schluessel bleiben unberuehrt
     * stehen; sie koennen aus einer neueren Fassung stammen. */
    $abgewiesen = array();
    foreach ($vorgaben as $k => $v) {
        if (!array_key_exists($k, $abfcfg)) { continue; }
        $grund = '';
        $gut = abfahrt_wert_pruefen($k, $abfcfg[$k], $grund);
        if ($gut === null) {
            $abgewiesen[$k] = $grund;
            unset($abfcfg[$k]);
        } else {
            $abfcfg[$k] = $gut;
        }
    }
    $fehlend = array_values(array_diff(array_keys($vorgaben), array_keys($abfcfg)));
    abfahrt_config_lage(array('zustand' => $zustand, 'abgewiesen' => $abgewiesen,
                              'fehlend' => $fehlend));
    // Vorgaben fuer das, was fehlt oder abgewiesen wurde
    $abfcfg += $vorgaben;
    $abfcfg['mqtt_ein'] = empty($abfcfg['mqtt_ein']) ? 0 : 1;
    $abfcfg['mqtt_topic'] = abfahrt_mqtt_praefix_norm($abfcfg['mqtt_topic']);
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
    /* Nr. 36 b, Stufe 2: die Vorgaben des Blocks tts kommen aus der gemeinsamen
     * Sprachausgabe - dieselben Schluessel wie bis 1.6.22 (dazu sonos_zone/sonos_laut seit
     * Modul 1.1.0, hier ohne Wirkung). Ab Werk weiterhin "musicserver" ohne Adresse und
     * Zonen "1" ohne "~25" (eine ausdrueckliche Lautstaerke an der Zone haette Vorrang
     * vor dem Lautstaerkefeld). Die Sprechtoken sind Geheimnisse: nie in der Seite, nicht
     * in der Sicherung. Vervollstaendigt, nicht ersetzt. */
    list($abfcfg['tts']) = ansage_vervollstaendigen($abfcfg['tts'], 'musicserver');
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
    /* is_string() vor der Umwandlung: ?token[]=x gab sonst unter PHP 7.4 und
     * 8.4 "Array to string conversion" aus (gemessen 06.09.2026). Am Geraet
     * folgenlos, weil display_errors aus ist - auf einer Anlage mit
     * eingeschalteter Anzeige ginge die Warnung vor der 403 hinaus. */
    $ist  = (isset($_GET['token']) && is_string($_GET['token'])) ? $_GET['token'] : '';
    if ($soll === '' || $ist === '') { return false; }
    return hash_equals($soll, $ist);
}

/**
 * Antwort bei fehlendem oder falschem Merkwort. Beendet das Skript.
 *
 * Die Antwortzeile traegt seit 1.6.10 ERR= nach Hausstandard (Regeln/03,
 * Abschnitt 4) UND weiterhin GRUND= mit dem bisherigen Wert. GRUND= bleibt
 * stehen, weil an ihm eine Befehlserkennung in einer fremden Anlage haengen
 * kann (Hausregel: bestehende Namen behalten ihre Bedeutung).
 *
 * Jede Abweisung wird protokolliert - mit Anfragendem und Grund, NIE mit
 * dem uebergebenen Merkwort. Bis 1.6.9 hinterliess eine Abweisung keine
 * Spur; "der Miniserver ruft nicht an" war von "er ruft an und wird
 * abgewiesen" nicht zu unterscheiden. Geschrieben wird nur, wenn das
 * Protokollverzeichnis schon besteht (der unangemeldete Bereich legt nichts
 * an), und dieselbe Abweisung vom selben Absender hoechstens einmal je
 * Viertelstunde, damit ein falsch eingetragener Ausgang das Protokoll nicht
 * fuellt.
 */
function abfahrt_token_abweisen($praefix, array $abfcfg) {
    $kein = empty($abfcfg['aktionstoken']);
    $wer = isset($_SERVER['REMOTE_ADDR']) ? preg_replace('/[^0-9a-fA-F:.]/', '', (string) $_SERVER['REMOTE_ADDR']) : 'unbekannt';
    abfahrt_log_gedrosselt('abweisung_' . $praefix . '_' . $wer . ($kein ? '_kein' : ''),
        'Abgewiesen: ' . $praefix . ' von ' . $wer . ' - '
        . ($kein ? 'kein Merkwort eingerichtet' : 'Merkwort fehlt oder falsch'), 900);
    header('Content-Type: text/plain; charset=utf-8');
    http_response_code(403);
    if ($kein) {
        echo $praefix . ";OK=0;ERR=KEIN_TOKEN_EINGERICHTET;GRUND=KEIN_TOKEN_GESETZT\n"
           . "Einmal die Plugin-Oberflaeche oeffnen - dort wird eines erzeugt und\n"
           . "im Reiter \"Einbindung in Loxone\" samt fertiger Adresse angezeigt.\n";
    } else {
        echo $praefix . ";OK=0;ERR=TOKEN;GRUND=TOKEN\n";
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
 *
 * NUR FUER DEN EIGENTUEMER seit 1.6.22 (Pruefung 02.10.2026, Nr. 23). Bis
 * 1.6.21 entstand der Ordner mit 0775 und jede Datei darin mit 0644: die
 * Kopien privater Kalender, stand.json und titel.json mit Titel und Ort
 * waren fuer jedes Konto auf dem LoxBerry lesbar, und ein vorab angelegter
 * fremder Ordner oder Verweis wurde klaglos benutzt. Jetzt: anlegen mit
 * 0700, einen eigenen Ordner mit offeneren Rechten auf 0700 ziehen. Nicht
 * benutzt wird ein Verweis, ein Ordner, der weder diesem Prozess noch root
 * noch dem Eigentuemer des Konfigurationsordners gehoert (Handstart als
 * root: der Ordner gehoert loxberry), und einer, in den dieser Prozess nicht
 * schreiben kann (nach einem Handstart als root); dann gilt
 * data/plugins/<ordner>/tmp, gemeldet einmal je Stunde. Kein umask() hier:
 * die Bibliothek laeuft auch im Webserver, und dort gilt umask fuer den
 * ganzen Prozess (der Dienst setzt seine eigene).
 */
function abfahrt_tmpdir($anlegen = true) {
    static $ersatz = null;
    $p = abfahrt_paths();
    $d = $ersatz !== null ? $ersatz : $p['tmp'];
    if ($ersatz === null
        && (is_link($d) || (is_dir($d) && (!abfahrt_tmpdir_vertraut($d) || !is_writable($d))))) {
        $ersatz = $p['lbhome'] !== '' ? $p['data'] . '/tmp' : $p['tmp'] . '-ersatz';
        $grund = is_link($d) ? 'ist ein Verweis'
               : (abfahrt_tmpdir_vertraut($d) ? 'ist fuer dieses Konto nicht beschreibbar'
                                              : 'gehoert einem fremden Konto');
        $d = $ersatz;
        if ($anlegen && !is_dir($d)) { @mkdir($d, 0700, true); }
        abfahrt_log_gedrosselt('tmp_fremd', 'Zwischenordner ' . $p['tmp'] . ' ' . $grund
            . ' - er wird nicht benutzt; ersatzweise ' . $d . '.', 3600);
    }
    if ($anlegen && !is_dir($d)) {
        @mkdir($d, 0700, true);
    }
    if ($anlegen && is_dir($d) && !is_link($d) && function_exists('posix_geteuid')
        && @fileowner($d) === posix_geteuid() && (@fileperms($d) & 0077)) {
        @chmod($d, 0700);
    }
    return $d;
}

/** Gehoert der Zwischenordner jemandem, dem dieser Prozess trauen darf? */
function abfahrt_tmpdir_vertraut($d) {
    if (!function_exists('posix_geteuid')) { return true; }
    $wer = @fileowner($d);
    if ($wer === false) { return false; }
    $p = abfahrt_paths();
    $cfgwer = @fileowner(dirname($p['config']));
    return $wer === posix_geteuid() || $wer === 0 || ($cfgwer !== false && $wer === $cfgwer);
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
    /* Nr. 23: Rechte 0600 VOR dem Inhalt (wie abfahrt_datei_geheim_schreiben());
     * wer eine Datei fuer andere lesbar braucht, setzt das danach selbst. */
    if (@file_put_contents($neben, '') === false) { return false; }
    @chmod($neben, 0600);
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
function abfahrt_http_grund_id($errno, $fehler, $status) {
    /* U5 (Durchgang 29.09.2026): Kennung plus Sprachschluessel GRUND.*
     * (abfahrt_grund_text()). Bis 1.6.15 standen hier feste deutsche Saetze in
     * Umschrift - auch in der englischen Oberflaeche.
     * b1 (Welle 2, 30.09.2026): Rueckgabe ist die KENNUNG, nicht der Satz.
     * Geokodierung und Routing haengen sie an ihre eigene Kennung, damit der
     * ganze Grund in der Sprache dessen erscheint, der ihn liest (Oberflaeche
     * oder Protokoll), nicht in der des Minutentakts, der ihn schrieb. */
    if ($errno === 7)  { return 'HTTP_ABGEWIESEN'; }
    if ($errno === 6)  { return 'HTTP_NAME'; }
    if ($errno === 28) { return 'HTTP_ZEIT'; }
    if ($errno === 35 || $errno === 60) { return 'HTTP_TLS'; }
    if ($errno !== 0)  { return 'HTTP_NETZ|' . (int) $errno . '|' . abfahrt_grund_teil($fehler); }
    if ($status === 401 || $status === 403) { return 'HTTP_ZUGANG|' . (int) $status; }
    if ($status === 404) { return 'HTTP_404'; }
    if ($status === 429) { return 'HTTP_429'; }
    if ($status >= 500)  { return 'HTTP_GEGENSEITE|' . (int) $status; }
    if ($status >= 400)  { return 'HTTP_STATUS|' . (int) $status; }
    /* Eine Weiterleitung, der nicht gefolgt wurde, ist KEIN Erfolg. Ohne
     * diese Zeile kam der Rumpf der Weiterleitungsseite als Nutzdaten
     * zurueck (gemessen 04.09.2026 im Zweig ohne php-curl). */
    if ($status >= 300)  { return 'HTTP_WEITERLEITUNG|' . (int) $status; }
    return '';
}

/** Ein Wert fuer eine Kennung: ohne senkrechten Strich (b1, Welle 2). */
function abfahrt_grund_teil($x) {
    return str_replace('|', '/', (string) $x);
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
function abfahrt_http_get($url, $timeout = 12, &$grund = '', &$status = 0, &$grund_id = '') {
    $grund = '';
    $status = 0;
    $grund_id = '';     // b1: derselbe Grund als Kennung (GRUND.*)
    if (!preg_match('#^https?://#i', (string) $url)) {
        // Beide Abrufwege: file://, php:// und Verwandte werden nie geoeffnet.
        $grund_id = 'HTTP_KEIN_HTTP';     // U5, b1
        $grund = abfahrt_grund_text($grund_id);
        return false;
    }
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
        /* Nur http und https - auch nach einer Weiterleitung. Ohne diese
         * Grenze las curl eine von Hand eingetragene file:///-Adresse. */
        if (defined('CURLOPT_PROTOCOLS') && defined('CURLPROTO_HTTP') && defined('CURLPROTO_HTTPS')) {
            curl_setopt($ch, CURLOPT_PROTOCOLS, CURLPROTO_HTTP | CURLPROTO_HTTPS);
            curl_setopt($ch, CURLOPT_REDIR_PROTOCOLS, CURLPROTO_HTTP | CURLPROTO_HTTPS);
        }
        $r = curl_exec($ch);
        $errno = curl_errno($ch);
        $fehler = curl_error($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        if (PHP_VERSION_ID < 80000) { curl_close($ch); }
        $grund_id = abfahrt_http_grund_id($errno, $fehler, $status);
        $grund = $grund_id !== '' ? abfahrt_grund_text($grund_id) : '';
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
    /* Seit 1.6.15 ueber fopen und stream_get_meta_data statt file_get_contents:
     * die magische Kopfzeilenvariable, die file_get_contents hinterlaesst,
     * meldet PHP 8.5 schon beim Uebersetzen als "Deprecated" - auch in einem
     * Rueckfallzweig, der unter 8.5 nie laeuft (1.6.14, Zeile 673). wrapper_data
     * traegt dieselben Zeilen, bei einer Weiterleitung alle Antworten, in PHP
     * 7.4 bis 8.5 gleich; mit ignore_errors oeffnet fopen auch 4xx und 5xx.
     * Scheitert fopen (kein Strom), gilt http_get_last_response_headers(), wo
     * es sie gibt (ab 8.4) - sonst kein Status. Einzige Abweichung von 1.6.14:
     * PHP < 8.4 ohne php-curl, eine Weiterleitung auf ein Ziel, das nicht
     * antwortet - statt "HTTP 302 - Weiterleitung, der nicht gefolgt wurde"
     * heisst es dann "Abruf gescheitert"; false bleibt false. An der
     * Weiterleitungsgrenze liefert fopen mit ignore_errors den Strom der
     * letzten Antwort, dort ist alles wie vorher (gemessen 7.4/8.4/8.5,
     * Pruefung-Abfahrtsassistent-1.6.15, http_15.sh, Faelle Q3-Q10). */
    $r = false;
    $abf_koepfe = null;
    $abf_fh = @fopen($url, 'rb', false, $ctx);
    if ($abf_fh !== false) {
        $r = stream_get_contents($abf_fh);
        $abf_meta = stream_get_meta_data($abf_fh);
        fclose($abf_fh);
        if (isset($abf_meta['wrapper_data']) && is_array($abf_meta['wrapper_data'])) {
            $abf_koepfe = $abf_meta['wrapper_data'];
        }
    } elseif (function_exists('http_get_last_response_headers')) {
        $abf_koepfe = http_get_last_response_headers();
    }
    /* Die LETZTE Statuszeile zaehlt, nicht die erste: wurde gefolgt, stehen
     * beide in den Kopfzeilen, und die erste waere dauerhaft die 302. */
    if (is_array($abf_koepfe)) {
        foreach ($abf_koepfe as $kopf) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', (string) $kopf, $m)) {
                $status = (int) $m[1];
            }
        }
    }
    $grund_id = abfahrt_http_grund_id(0, '', $status);
    if ($r === false && $grund_id === '') {
        $grund_id = 'HTTP_OHNE_CURL';     // U5, b1
    }
    $grund = $grund_id !== '' ? abfahrt_grund_text($grund_id) : '';
    if ($r === false) {
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
    /* Nr. 36 b: aus der gemeinsamen Sprachausgabe (Webserver.Port oder WEBSERVER.Port, sonst 80). */
    static $port = null;
    if ($port !== null) { return $port; }
    $abf_p = abfahrt_paths();
    $port = ansage_webport($abf_p['general']);
    return $port;
}

/** Die Ausgabearten, die dieses Plugin anbietet: wie bis 1.6.22 und dazu "aus" (ohne Sonos4Lox). */
function abfahrt_ansage_modi() {
    return array('aus', 'musicserver', 'ms4h', 'audioserver', 'custom', 'alexang', 'cc4lox');
}

/**
 * Kontext fuer die gemeinsame Sprachausgabe: Webport, Kopfzeilen, Ordner der letzten
 * Ansage (Zwischenordner; angelegt wird dafuer nichts, wie bis 1.6.22) und die Texte
 * ([ANSAGE] der Sprachdateien). Zwei Saetze des Moduls sagen "ab Werk aus"; hier ist ab
 * Werk der Music Server ohne Adresse eingestellt - dafuer stehen eigene Saetze unter [TTS].
 */
function abfahrt_ansage_k() {
    return array('port' => abfahrt_webport(), 'kopf' => array('User-Agent: LoxBerry Abfahrts-Assistent'),
                 'ordner' => abfahrt_tmpdir(false),
                 't' => function ($s) { return abfahrt_t($s); },
                 'schluessel' => array('ART_HINWEIS' => 'TTS.ART_HINWEIS', 'O_AUS' => 'TTS.O_AUS'));
}

/** Adresse eines oertlichen Plugin-Skripts, mit dem richtigen Port. */
function abfahrt_lokal_url($pfad) {
    $port = abfahrt_webport();
    return 'http://127.0.0.1' . ($port === 80 ? '' : ':' . $port) . $pfad;
}

/**
 * Nur-Lese-Betrieb fuer den unangemeldeten Endpunkt.
 *
 * termin.php schaltet ihn ein. Dann fragt abfahrt_daytype() weder das
 * Ferien-Plugin ueber HTTP, noch bindet es dessen Bibliothek ein, noch
 * schreibt es seinen Zwischenspeicher - es liest nur, was der Dienst
 * hinterlegt hat. Bis 1.6.9 legte ein anonymer GET ohne jeden Parameter
 * /tmp/abfahrt_daytype.json an (gemessen 06.09.2026: 0 -> 1 Datei), gegen die
 * Zusage im Kopf von termin.php.
 */
function abfahrt_nur_lesen($setzen = null) {
    static $an = false;
    if ($setzen !== null) { $an = (bool) $setzen; }
    return $an;
}

function abfahrt_daytype() {
    static $cacheMem = null;
    if ($cacheMem !== null) {
        return $cacheMem;
    }
    $leer = ['feiertag' => 0, 'ferien' => 0, 'urlaub' => 0, 'quelle' => 'keine', 'name' => ''];
    /* Im eigenen Zwischenordner, nicht im gemeinsamen /tmp - der Name traegt
     * sonst keinen Bezug zur Installation (zwei Installationen teilten ihn). */
    $tmp = abfahrt_tmpdir(false) . '/daytype.json';
    if (is_file($tmp) && time() - filemtime($tmp) < 900) {
        $c = json_decode((string) @file_get_contents($tmp), true);
        if (is_array($c) && ($c['datum'] ?? '') === date('Y-m-d')) {
            return $cacheMem = ($c + $leer);
        }
    }
    if (abfahrt_nur_lesen()) {
        // Nichts Frisches da: ohne Sondertage rechnen, nichts nachfragen.
        return $cacheMem = $leer;
    }
    $res = $leer;
    /* 1) JSON-Schnittstelle des Ferien-Plugins (laeuft dort mit korrekter Umgebung)
     *
     * ERST NACHSEHEN, OB ES DAS PLUGIN UEBERHAUPT GIBT. Vorher wurde bei
     * jedem Aufruf eine HTTP-Anfrage an 127.0.0.1 abgesetzt, auch auf einer
     * Anlage ohne das Ferien-Plugin - dort lief sie ins Leere und stand in
     * jedem Prueflauf als Warnung. Der Weg 2 weiter unten sucht ohnehin nach
     * derselben Datei; hier wird nur vorgezogen, was dort schon steht. */
    /* Nur unter der Wurzel der Anlage (abfahrt_paths()). Bis 1.6.12 stand
     * hier zusaetzlich dirname(__DIR__, 3) . '/html/plugins/ferien' - aus
     * einem ausgepackten Archiv ein Pfad neben dem Archiv, aus
     * /webfrontend/html einer ab der Laufwerkswurzel; eine fremde
     * ferien_lib.php dort wurde eingebunden und ausgefuehrt (in WSL gemessen,
     * Pruefung-Abfahrtsassistent-1.6.13, Fall C6). Installiert ergab der
     * Ausdruck denselben Ordner wie die Wurzel. */
    $abf_pp = abfahrt_paths();
    $abf_lb = $abf_pp['lbhome'];
    $abf_ferien_da = ($abf_lb !== '' && is_dir($abf_lb . '/webfrontend/html/plugins/ferien'));
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
        $kandidaten = [];
        if ($abf_lb !== '') { $kandidaten[] = $abf_lb . '/webfrontend/html/plugins/ferien/ferien_lib.php'; }
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
    if (is_dir(dirname($tmp))) {
        abfahrt_cache_schreiben($tmp, json_encode($res));
    }
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
    /* Die Sondertage gelten nur fuer heute (das Ferien-Plugin kennt nur den
     * heutigen Zustand). Bis 1.6.21 kam die Sondertagszeile auch fuer
     * "gestern" zurueck, die Wochentagszeile von gestern wurde dann nie
     * geprueft (Pruefung 02.10.2026, Nr. 8). Die heutige Sondertagszeile fuer
     * den Morgenteil prueft abfahrt_in_quiet() zusaetzlich. */
    if ((int) $tagversatz === 0) {
        if (!empty($tag['urlaub']) && $an(10)) { return [10, $namen[10]]; }
        if (!empty($tag['feiertag']) && $an(8)) { return [8, $namen[8]]; }
        if (!empty($tag['ferien']) && $an(9)) { return [9, $namen[9]]; }
    }
    // 1 = Montag ... 7 = Sonntag; $tagversatz = -1 fragt nach gestern.
    /* C5 (Durchgang 29.09.2026): "gestern" nach dem Kalender, nicht nach
     * 86400 s. Bis 1.6.15 ergab es am 30.03. um 00:30 den Samstag statt des
     * Sonntags (die Nacht der Umstellung hat 23 Stunden); eine Sperrzeit, die
     * am Sonntag beginnt und ueber Mitternacht laeuft, griff dann zwischen
     * 00:00 und 01:00 nicht. */
    $abf_tag = ((int) $tagversatz === 0) ? time() : strtotime(((int) $tagversatz) . ' day');
    $d = (int) date('N', $abf_tag);
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
    $p = function ($s) { $x = explode(':', (string) $s); return ((int) $x[0]) * 60 + (int) ($x[1] ?? 0); };
    /* Geprueft werden: die heutige Zeile, die Wochentagszeile von gestern
     * und - als Naeherung fuer mehrtaegige Zeitraeume - der Morgenteil der
     * heutigen Sondertagszeile (Pruefung 02.10.2026, Nr. 8). */
    $heute = abfahrt_quiet_rule($abfcfg, 0);
    $pruefen = [[0, $heute], [-1, abfahrt_quiet_rule($abfcfg, -1)]];
    if ($heute[0] >= 8) { $pruefen[] = [-1, $heute]; }
    foreach ($pruefen as $eintrag) {
        list($versatz, list($k, $bez)) = $eintrag;
        if ($k === 0 || !isset($abfcfg['quiet'][$k])) {
            continue;
        }
        $q = $abfcfg['quiet'][$k];
        $from = $p($q['from']);
        $to = $p($q['to']);
        if ($from === $to) {
            // Gleiche Anfangs- und Endzeit heisst ganztaegig. Bisher hiess es
            // "nie" - eine 24-Stunden-Sperre liess sich gar nicht einstellen.
            // Nur fuer heute: bis 1.6.21 sperrte ein ganzer Sonntag auch den
            // ganzen Montag (Pruefung 02.10.2026, Nr. 1).
            if ($versatz !== 0) { continue; }
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
 * "Besprechungsraum 3" oder "Zahnarzt". Der Kartendienst kann damit
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
    /* Aus abfahrt_paths(), wie alle anderen Pfade. Bis 1.6.12 rechnete diese
     * Funktion selbst: aus einem Archiv unter der Anlage schrieb sie nach
     * log/plugins/html/ der Anlage, ohne Wurzel in den Zwischenordner
     * /tmp/abfahrtsassistent der Anlage (in WSL gemessen,
     * Pruefung-Abfahrtsassistent-1.6.13, Faelle B6 und H2). */
    $p = abfahrt_paths();
    $dir = dirname($p['log']);
    if ($anlegen && !is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    return $p['log'];
}

function abfahrt_log($msg, $anlegen = true) {
    $f = abfahrt_logfile($anlegen);
    if (!$anlegen && !is_dir(dirname($f))) { return; }
    clearstatcache(true, $f);
    /* Rotation unter einer Sperre und unteilbar. Bis 1.6.9 wurde die Datei
     * ohne Sperre gekuerzt: schrieben Dienst und Endpunkt gleichzeitig,
     * gingen Zeilen verloren oder die gekuerzte Fassung ueberschrieb eine
     * eben angehaengte Zeile. */
    $fh = @fopen($f . '.lock', 'c');
    if ($fh) { @flock($fh, LOCK_EX); }
    if (is_file($f) && filesize($f) > 512000) { // letzte 200 Zeilen behalten
        $tail = array_slice(file($f, FILE_IGNORE_NEW_LINES) ?: [], -200);
        abfahrt_cache_schreiben($f, implode("\n", $tail) . "\n");
    }
    @file_put_contents($f, '[' . date('Y-m-d H:i:s') . '] ' . $msg . "\n", FILE_APPEND | LOCK_EX);
    if ($fh) { @flock($fh, LOCK_UN); @fclose($fh); }
}

/**
 * Eine Protokollzeile hoechstens einmal je $sekunden je Schluessel - und
 * ohne etwas anzulegen. Fuer die Wege im unangemeldeten Bereich.
 * Rueckgabe: true, wenn die Zeile diesmal an der Reihe war (der Dienst gibt
 * sie dann auch nach stderr), sonst false.
 */
function abfahrt_log_gedrosselt($schluessel, $msg, $sekunden) {
    $tmp = abfahrt_tmpdir(false);
    if (!is_dir($tmp)) { return false; }
    $merker = $tmp . '/drossel_' . md5((string) $schluessel);
    clearstatcache(true, $merker);
    if (is_file($merker) && time() - (int) @filemtime($merker) < (int) $sekunden) { return false; }
    @touch($merker);
    abfahrt_log($msg, false);
    return true;
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

/* Rueckzug nach einem gescheiterten Abruf und Zeitbudget je Lauf (Pruefung
 * 02.10.2026, Nr. 25). Bis 1.6.21 holte jeder faellige Lauf jeden toten
 * Kalender neu, mit bis zu 12 s je Kalender; zehn davon machten den Lauf
 * laenger als eine Minute, und die naechsten Laeufe endeten still an der
 * Sperre. Jetzt: nach einem Fehlschlag 5 Minuten Pause je Adresse (Merker
 * ics_<md5>.fehl mit dem Grund), solange eine Kopie da ist; und nach 30 s
 * Abrufzeit im selben Lauf wird nichts Neues mehr geholt. In beiden Faellen
 * gilt die Kopie innerhalb der Gnadenfrist. */
define('ABFAHRT_ICS_PAUSE', 300);
define('ABFAHRT_ICS_BUDGET', 30);

function abfahrt_fetch_ics($url, &$grund = '', &$veraltet = false, &$alter = 0) {
    static $verbraucht = 0.0;
    static $budget_gemeldet = false;
    $grund = '';
    $veraltet = false;
    $alter = 0;
    $cache = abfahrt_tmpdir() . '/ics_' . md5($url);
    $merker = $cache . '.fehl';
    if (is_file($cache) && time() - filemtime($cache) < 600) {
        $roh = @file_get_contents($cache);
        if ($roh !== false && $roh !== '') { return (string) $roh; }
    }
    clearstatcache(true, $merker);
    $neu = false;
    if (is_file($cache) && is_file($merker) && time() - (int) @filemtime($merker) < ABFAHRT_ICS_PAUSE) {
        // Pause nach einem Fehlschlag - der Grund von damals gilt weiter.
        $grund = trim((string) @file_get_contents($merker));
        if ($grund === '') { $grund = '?'; }
    } elseif ($verbraucht >= ABFAHRT_ICS_BUDGET) {
        $grund = sprintf(abfahrt_t('MELDUNG.ICS_ZEITBUDGET'), ABFAHRT_ICS_BUDGET);
        if (!$budget_gemeldet) {
            $budget_gemeldet = true;
            abfahrt_log($grund);
        }
    } else {
        $t0 = microtime(true);
        $neu = abfahrt_http_get($url, 12, $grund);
        $verbraucht += microtime(true) - $t0;
        if ($neu !== false && strpos($neu, 'BEGIN:VCALENDAR') !== false
                           && strpos($neu, 'END:VCALENDAR') !== false) {
            abfahrt_cache_schreiben($cache, $neu);
            @unlink($merker);
            return $neu;
        }
        if ($neu !== false && $grund === '') {
            $grund = abfahrt_grund_text('ICS_UNVOLLSTAENDIG');     // b1
        }
        if (is_dir(dirname($merker))) {
            abfahrt_cache_schreiben($merker, $grund !== '' ? $grund : '?');
        }
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
 * auf die Zeitzone der Anlage (bis 1.6.21 fest Europe/Berlin) - aber es
 * wird GESAGT, siehe abfahrt_tz_meldung(). Geraten wird nichts.
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
        return new DateTimeZone(date_default_timezone_get());
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
    abfahrt_tz_meldung(sprintf(abfahrt_t('MELDUNG.TZ_UNBEKANNT'), $t, date_default_timezone_get()));
    return new DateTimeZone(date_default_timezone_get());
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

/**
 * Einen Kalender auf gueltiges UTF-8 bringen (C3, Durchgang 29.09.2026).
 *
 * Bis 1.6.15 lief ein Titel in ISO-8859-1 (B\xFCro) ungeprueft bis
 * json_encode(); das lieferte false, stand.json und titel.json blieben auf dem
 * vorigen Termin stehen, waehrend MQTT den neuen sendete, und im Protokoll
 * stand nichts (in WSL gemessen, Code-Pruefer C3). Gerechnet wird NACH dem
 * Entfalten (eine Faltung darf mitten in einer UTF-8-Folge liegen). Nur
 * Zeilen, die kein gueltiges UTF-8 sind, werden angefasst, und darin nur die
 * Bytes, die zu keiner gueltigen Folge gehoeren: sie gelten als Windows-1252
 * (Obermenge von ISO-8859-1). Gueltige Umlaute derselben Zeile bleiben.
 */
function abfahrt_utf8($s)
{
    $s = (string) $s;
    if ($s === '' || preg_match('//u', $s)) { return $s; }
    $teile = preg_split('/(\r?\n)/', $s, -1, PREG_SPLIT_DELIM_CAPTURE);
    if (!is_array($teile)) { return $s; }
    foreach ($teile as $i => $t) {
        if ($t === '' || preg_match('//u', $t)) { continue; }
        $teile[$i] = abfahrt_cp1252_utf8($t);
    }
    return implode('', $teile);
}

/** Eine Zeile Byte fuer Byte: gueltige UTF-8-Folgen bleiben, jedes andere Byte
 *  ab 0x80 wird als Windows-1252 gelesen (undefinierte Stellen: U+FFFD). */
function abfahrt_cp1252_utf8($s)
{
    static $tab = null;
    if ($tab === null) {
        $tab = array(0x80 => 0x20AC, 0x82 => 0x201A, 0x83 => 0x0192, 0x84 => 0x201E, 0x85 => 0x2026,
            0x86 => 0x2020, 0x87 => 0x2021, 0x88 => 0x02C6, 0x89 => 0x2030, 0x8A => 0x0160,
            0x8B => 0x2039, 0x8C => 0x0152, 0x8E => 0x017D, 0x91 => 0x2018, 0x92 => 0x2019,
            0x93 => 0x201C, 0x94 => 0x201D, 0x95 => 0x2022, 0x96 => 0x2013, 0x97 => 0x2014,
            0x98 => 0x02DC, 0x99 => 0x2122, 0x9A => 0x0161, 0x9B => 0x203A, 0x9C => 0x0153,
            0x9E => 0x017E, 0x9F => 0x0178);
    }
    $folge = '/\G(?:[\xC2-\xDF][\x80-\xBF]|\xE0[\xA0-\xBF][\x80-\xBF]|[\xE1-\xEC\xEE\xEF][\x80-\xBF]{2}'
           . '|\xED[\x80-\x9F][\x80-\xBF]|\xF0[\x90-\xBF][\x80-\xBF]{2}|[\xF1-\xF3][\x80-\xBF]{3}'
           . '|\xF4[\x80-\x8F][\x80-\xBF]{2})/';
    $aus = '';
    $n = strlen($s);
    $i = 0;
    while ($i < $n) {
        $b = ord($s[$i]);
        if ($b < 0x80) { $aus .= $s[$i]; $i++; continue; }
        if (preg_match($folge, $s, $m, 0, $i)) { $aus .= $m[0]; $i += strlen($m[0]); continue; }
        $cp = isset($tab[$b]) ? $tab[$b] : ($b >= 0xA0 ? $b : 0xFFFD);
        if ($cp < 0x800) {
            $aus .= chr(0xC0 | ($cp >> 6)) . chr(0x80 | ($cp & 0x3F));
        } else {
            $aus .= chr(0xE0 | ($cp >> 12)) . chr(0x80 | (($cp >> 6) & 0x3F)) . chr(0x80 | ($cp & 0x3F));
        }
        $i++;
    }
    return $aus;
}

/** Text einer iCal-Eigenschaft entmaskieren (RFC 5545, Abschnitt 3.3.11). */
function abfahrt_unesc($s)
{
    /* In einem Durchgang. Bis 1.6.21 kam \n vor \\ dran, aus C:\\new wurde
     * "C:\ ew" (Pruefung 02.10.2026, Nr. 16). */
    $s = preg_replace_callback('/\\\\([\\\\;,nN])/', function ($m) {
        return ($m[1] === 'n' || $m[1] === 'N') ? ' ' : $m[1];
    }, (string) $s);
    return trim((string) $s);
}

/**
 * DTSTART/EXDATE/RECURRENCE-ID-Rohwert -> Unix-ts (null bei Ganztages-/Parsefehler).
 */
/**
 * Ist ein iCal-Datum (JJJJMMTT, optional THHMMSS) ein Datum, das es gibt?
 *
 * ANLASS (gemessen 06.09.2026, PHP 7.4 und 8.4): DateTime::createFromFormat()
 * rechnet Ueberlaeufe still um. 20261301T000000 wurde zum 01.01.2027,
 * 20260230T100000 zum 02.03.2026. Ein Termin, den es nicht gibt, lief damit
 * mit OK=1 in Countdown, Ansage und Push; ein vertauschtes EXDATE traf nicht.
 * Eingaben werden abgewiesen, nicht zurechtgebogen.
 */
function abfahrt_ical_datum_gueltig($raw) {
    if (!preg_match('/^(\d{4})(\d{2})(\d{2})(?:T(\d{2})(\d{2})(\d{2})Z?)?$/', (string) $raw, $m)) {
        return false;
    }
    if (!checkdate((int) $m[2], (int) $m[3], (int) $m[1])) { return false; }
    if (isset($m[4]) && $m[4] !== '') {
        if ((int) $m[4] > 23 || (int) $m[5] > 59 || (int) $m[6] > 60) { return false; }
    }
    return true;
}

function abfahrt_dt2ts($raw, $tzid, $ganztagsZeit = null) {
    $raw = trim((string) $raw);
    if (!abfahrt_ical_datum_gueltig($raw)) {
        return null;
    }
    // preg_match statt ctype_digit: ctype ist eine Erweiterung, die nicht
    // garantiert geladen ist (Regeln/02).
    if (strlen($raw) == 8 && preg_match('/^\d{8}$/', $raw)) {
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
 * Alle Zeitpunkte einer Datumsliste (EXDATE, RDATE) eines VEVENT: [ts => 1].
 *
 * EXDATE traegt bei manchen Kalendern VALUE=DATE-TIME. Mit der frueheren
 * festen Reihenfolge wurde die Zeile nicht erkannt, und die geloeschte
 * Instanz erschien weiter. Bei RDATE;VALUE=PERIOD zaehlt der Anfang.
 */
function abfahrt_datumsliste($ev, $name, $tzid, $gz)
{
    $aus = [];
    if (preg_match_all('/^' . $name . '((?:;(?:"[^"]*"|[^:;"\r\n])*)*):([^\r\n]*)/mi',
                       $ev, $me, PREG_SET_ORDER)) {
        foreach ($me as $e) {
            $etz = $tzid;
            if (preg_match('/;TZID=("?)([^;"]+)\1/i', $e[1], $mt)) { $etz = $mt[2]; }
            foreach (explode(',', trim($e[2])) as $v) {
                $v = explode('/', $v, 2);
                $x = abfahrt_dt2ts($v[0], $etz, $gz);
                if ($x !== null) {
                    $aus[$x] = 1;
                }
            }
        }
    }
    return $aus;
}

/**
 * Welcher Regelteil einer RRULE wird hier NICHT ausgerollt? '' = alle bekannt.
 *
 * Bis 1.6.21 wurden diese Teile still uebergangen, die Serie lief dann an
 * falschen Tagen oder zu falschen Uhrzeiten (Pruefung 02.10.2026, Nr. 10).
 * Lieber kein Termin als ein falscher. BYHOUR, BYMINUTE und BYSECOND gelten
 * nur, wenn sie genau die Uhrzeit des DTSTART wiederholen (das schicken
 * manche Kalender so mit) - dann aendern sie nichts.
 */
function abfahrt_rrule_fehlt(array $r, DateTime $start)
{
    $freq = $r['FREQ'] ?? '';
    if (!in_array($freq, ['DAILY', 'WEEKLY', 'MONTHLY', 'YEARLY'], true)) {
        return 'FREQ=' . ($freq !== '' ? $freq : '?');
    }
    foreach (['BYWEEKNO', 'BYYEARDAY', 'RSCALE'] as $k) {
        if (isset($r[$k])) { return $k; }
    }
    foreach (['BYHOUR' => 'G', 'BYMINUTE' => 'i', 'BYSECOND' => 's'] as $k => $f) {
        if (isset($r[$k]) && !(preg_match('/^\d{1,2}$/', $r[$k])
                               && (int) $r[$k] === (int) $start->format($f))) {
            return $k;
        }
    }
    if (isset($r['BYSETPOS']) && ($freq === 'DAILY' || $freq === 'WEEKLY')) {
        return 'BYSETPOS';
    }
    return '';
}

/** Passt der Tag $t eines Monats mit $imMonat Tagen auf BYMONTHDAY (auch negativ)? */
function abfahrt_monatstag_passt($t, $imMonat, array $bymonatstag)
{
    foreach ($bymonatstag as $v) {
        if ($t === ($v > 0 ? $v : $imMonat + 1 + $v)) { return true; }
    }
    return false;
}

/**
 * Alle Kalender parsen: naechster zukuenftiger Termin MIT Ortsangabe im
 * Zeitfenster. Serientermine werden vollstaendig expandiert:
 * RRULE FREQ=DAILY/WEEKLY/MONTHLY/YEARLY mit INTERVAL, BYDAY, BYMONTHDAY,
 * BYMONTH, BYSETPOS (monatlich/jaehrlich), WKST, UNTIL, COUNT; RDATE; EXDATE;
 * andere Regelteile -> Serie entfaellt mit Diagnosezeile; verschobene/
 * geloeschte Einzel-Instanzen einer Serie
 * (RECURRENCE-ID / STATUS:CANCELLED). Zeitzonen-/DST-sicher via DateTime.
 * Rueckgabe: [ts, location, summary, calendar_name] oder null.
 */
function abfahrt_next_event(array $abfcfg, &$diag = [], &$kallage = null) {
    /* $kallage sagt dem Aufrufer, wie es um die Kalender steht - er kann
     * daraus einen Fehlercode fuer den Miniserver bilden. Die Zeilen in
     * $diag reichen dafuer nicht: sie gehen nur nach ?debug=1 und in die
     * Oberflaeche, nie in die Statuszeile. */
    $kallage = array('eingerichtet' => 0, 'gelesen' => 0, 'veraltet' => 0, 'tot' => 0, 'mit_ort' => 0);
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
            // b1: Diagnosezeilen aus [DIAG] (Sprache des Lesers bzw. des Laufs).
            $diag[] = $ladegrund !== '' ? sprintf(abfahrt_t('DIAG.KAL_NICHT_LADBAR_GRUND'), $name, $ladegrund)
                                        : sprintf(abfahrt_t('DIAG.KAL_NICHT_LADBAR'), $name);
            continue;
        }
        $kallage['gelesen']++;
        if ($kalAlt) {
            $kallage['veraltet']++;
            $diag[] = sprintf(abfahrt_t('DIAG.KAL_ALTER_STAND'), $name, $ladegrund, (int) round($kalAlter / 60));
        } elseif ($ladegrund !== '') {
            $diag[] = sprintf(abfahrt_t('DIAG.KAL_HINWEIS'), $name, $ladegrund);
        }
        $ics = preg_replace("/\r?\n[ \t]/", '', $ics); // Zeilenfaltung aufloesen
        // C3: erst entfalten, dann auf gueltiges UTF-8 bringen (bis 1.6.15 fehlte das).
        $ics = abfahrt_utf8($ics);

        $singles = [];    // [ts, loc, sum]
        $masters = [];
        $overridden = []; // "uid|origTs" => 1
        $verschoben = []; // uid => [['ab'=>ts, 'delta'=>s, 'weg'=>0|1], ...]  (RANGE=THISANDFUTURE)
        /* Gross/klein egal und nur als ganze Zeile (RFC 5545). Bis 1.6.21
         * explode('BEGIN:VEVENT'): "Begin:VEvent" ging verloren (Pruefung
         * 02.10.2026, Nr. 17). Teil 0 ist weiter der Kopf vor dem ersten Termin. */
        $abf_teile = preg_split('/^BEGIN:VEVENT[ \t]*\r?$/mi', $ics);
        foreach (is_array($abf_teile) ? $abf_teile : array() as $i => $ev) {
            if ($i === 0) {
                continue;
            }
            /* C2 (Durchgang 29.09.2026): Der Abschnitt endet an END:VEVENT, nicht
             * erst am naechsten BEGIN:VEVENT. Bis 1.6.15 las abfahrt_prop() eine
             * dahinter stehende VTIMEZONE mit (deren RRULE:FREQ=YEARLY machte aus
             * einem Einzeltermin eine Serie, der Termin verschwand; gemessen,
             * Code-Pruefer C2), ebenso ein folgendes VTODO. Eingeschachtelte
             * VALARM-Bloecke kommen heraus: ihr SUMMARY oder DESCRIPTION stuende
             * sonst als erster Treffer vor dem des Termins. */
            $abf_ende = stripos($ev, "\nEND:VEVENT");
            if ($abf_ende !== false) { $ev = substr($ev, 0, $abf_ende + 1); }
            if (stripos($ev, 'BEGIN:VALARM') !== false) {
                $abf_ohne_alarm = preg_replace('/^BEGIN:VALARM\b.*?^END:VALARM[^\r\n]*(?:\r?\n|\z)/msi', '', $ev);
                if (is_string($abf_ohne_alarm)) { $ev = $abf_ohne_alarm; }
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
                    $diag[] = sprintf(abfahrt_t('DIAG.ORTSBUCH'), $abf_treffer, $loc);     // b1
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
            /* RDATE (zusaetzliche Einzeltermine) wurde bis 1.6.21 ueberlesen
             * (Pruefung 02.10.2026, Nr. 10). Gelesen wie EXDATE. */
            $rd = abfahrt_datumsliste($ev, 'RDATE', $tzid, $gz);
            if ($pRr !== null || $rd) {
                $ex = abfahrt_datumsliste($ev, 'EXDATE', $tzid, $gz);
                /* C1 (Durchgang 29.09.2026): Eine Serie mit UTC-Beginn
                 * (DTSTART:...Z, ohne TZID) wird in UTC ausgerollt. Bis 1.6.15
                 * fiel sie auf Europe/Berlin zurueck: nach einem Wechsel der
                 * Sommerzeit lag jedes Vorkommen eine Stunde falsch, und ein
                 * EXDATE in UTC traf nicht mehr (gemessen: 30.09. 07:00Z statt
                 * 08:00Z, der gestrichene Termin kam trotzdem). Ohne Z und ohne
                 * TZID (schwebende Zeit) gilt die Zeitzone der Anlage (bis
                 * 1.6.21 fest Europe/Berlin, Pruefung 02.10.2026, Nr. 28b). */
                $abf_serien_tz = ($tzid !== '') ? $tzid
                    : (strtoupper(substr(trim((string) $pDt[0]), -1)) === 'Z' ? 'UTC' : date_default_timezone_get());
                $masters[] = ['uid' => $uid, 'ts' => $ts, 'tzid' => $abf_serien_tz,
                              'loc' => $loc, 'sum' => $sum, 'rrule' => $pRr === null ? '' : $pRr[0],
                              'ex' => $ex, 'rd' => $rd];
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
            $tz = abfahrt_tz($mst['tzid']);
            $start = (new DateTime('@' . $mst['ts']))->setTimezone($tz);
            $abf_fehlt = ($mst['rrule'] === '') ? '' : abfahrt_rrule_fehlt($r, $start);
            if ($abf_fehlt !== '') {
                $diag[] = sprintf(abfahrt_t('DIAG.SERIE_NICHT_UNTERSTUETZT'), $name, $mst['sum'], $abf_fehlt);
                continue;
            }
            /* RANGE=THISANDFUTURE verschiebt Vorkommen nach vorn oder hinten.
             * Bis 1.6.21 endeten die Schleifen an der URSPRUENGLICHEN Zeit >
             * $maxTs: ein vom Montag 12.10. auf Freitag 09.10. vorgezogener
             * Termin fehlte (Pruefung 02.10.2026, Nr. 28a). Also um die
             * groesste Verschiebung nach vorn weiter ausrollen und um die
             * groesste nach hinten frueher mit dem Vorspulen aufhoeren. */
            $minDelta = 0;
            $maxDelta = 0;
            foreach ($verschoben[$mst['uid']] ?? [] as $v) {
                if (empty($v['weg'])) {
                    $minDelta = min($minDelta, (int) $v['delta']);
                    $maxDelta = max($maxDelta, (int) $v['delta']);
                }
            }
            $grenze = $maxTs - $minDelta;
            $spulZiel = $now - $maxDelta;
            $schon = [];     // aufgenommene Serienzeiten - ein RDATE darauf zaehlt nicht doppelt
            if ($mst['rrule'] === '') {
                // Nur RDATE, keine RRULE: DTSTART ist das erste Vorkommen.
                $schon[$mst['ts']] = 1;
                abfahrt_serie_aufnehmen($singles, $mst, $mst['ts'], $now, $maxTs,
                                        $overridden, $verschoben);
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
                    // Mit BYMONTHDAY ist jeder Wochentag Kandidat (RFC 5545, wie dateutil).
                    if ($bymonatstag) {
                        $byday = [1 => 1, 2 => 1, 3 => 1, 4 => 1, 5 => 1, 6 => 1, 7 => 1];
                    } else {
                        $byday[(int) $start->format('N')] = 1;
                    }
                }
            }

            $emitted = 0;
            if ($freq == 'DAILY' || $freq == 'WEEKLY') {
                /* Die Woche beginnt am WKST-Tag (Vorgabe Montag). Bis 1.6.21
                 * immer Montag - mit INTERVAL>1 und WKST=SU lag eine Serie
                 * dann in der falschen Woche (Pruefung 02.10.2026, Nr. 10). */
                $wkst = $WD[$r['WKST'] ?? 'MO'] ?? 1;
                $wochenanfang = function (DateTime $d) use ($wkst) {
                    $w = clone $d;
                    $w->modify('-' . ((((int) $d->format('N')) - $wkst + 7) % 7) . ' day')->setTime(12, 0, 0);
                    return $w;
                };
                $wkRef = $wochenanfang($start);
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
                 * fehlerfrei, und geraten wird hier nichts. Dasselbe gilt fuer
                 * die Filter BYMONTH und BYMONTHDAY bei beiden Takten.
                 */
                $emitted = 0;
                $darfSpulen = ($count === null)
                           || (($freq == 'WEEKLY' || !$byday) && !$bymonat && !$bymonatstag);
                if ($darfSpulen && $cur->getTimestamp() < $spulZiel) {
                    if ($freq == 'DAILY') {
                        $tage = (int) $start->diff(new DateTime('@' . $spulZiel))->format('%a');
                        $sprung = intdiv($tage, $iv) * $iv;
                        if ($sprung > 0) {
                            $cur->modify('+' . $sprung . ' day');
                            $emitted = intdiv($sprung, $iv);
                        }
                    } else {
                        $wochen = (int) floor(($spulZiel - $mst['ts']) / (7 * 86400));
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
                        $weeks = (int) round(((int) $wkRef->diff($wochenanfang($cur))->format('%a')) / 7);
                        $okDay = isset($byday[(int) $cur->format('N')]) && ($weeks % $iv) == 0;
                    }
                    // BYMONTH und BYMONTHDAY sind hier Filter; bis 1.6.21
                    // uebergangen (Pruefung 02.10.2026, Nr. 10).
                    if ($okDay && $bymonat && !in_array((int) $cur->format('n'), $bymonat, true)) {
                        $okDay = false;
                    }
                    if ($okDay && $bymonatstag
                        && !abfahrt_monatstag_passt((int) $cur->format('j'), (int) $cur->format('t'), $bymonatstag)) {
                        $okDay = false;
                    }
                    if ($okDay && $ts >= $mst['ts']) {
                        $emitted++;
                        if ($count !== null && $emitted > $count) {
                            break;
                        }
                        if ($until !== null && $ts > $until) {
                            break;
                        }
                        $schon[$ts] = 1;
                        abfahrt_serie_aufnehmen($singles, $mst, $ts, $now, $maxTs,
                                                $overridden, $verschoben);
                    }
                    if ($ts > $grenze || ($until !== null && $ts > $until)) {
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
                $bysetpos = [];
                foreach (explode(',', $r['BYSETPOS'] ?? '') as $d) {
                    $d = (int) trim($d);
                    if ($d !== 0) { $bysetpos[] = $d; }
                }

                $schritt = ($freq == 'MONTHLY') ? 'month' : 'year';
                $anker = new DateTime(
                    ($freq == 'MONTHLY' ? $start->format('Y-m') . '-01' : $start->format('Y') . '-01-01')
                    . ' 12:00:00', $tz);
                $starttag = (int) $start->format('j');

                $fertig = false;
                for ($k = 0; $k < 1200 && !$fertig; $k++) {
                    $per = clone $anker;
                    if ($k > 0) {
                        $per->modify('+' . ($k * $iv) . ' ' . $schritt);
                    }
                    if ($per->getTimestamp() > $grenze) {
                        break;   // die ganze Periode liegt hinter dem Fenster
                    }
                    $jahr = (int) $per->format('Y');
                    /* Bereiche, in denen BYDAY-Ordnungszahlen (2MO, -1FR) zaehlen.
                     * Bei MONTHLY ist BYMONTH ein FILTER (RFC 5545, 3.3.10):
                     * "jeden ersten Montag, aber nur im Maerz und September".
                     * Bis 1.6.9 fiel er weg, die Serie lief in jedem Monat.
                     * YEARLY ohne BYMONTH, aber mit BYDAY oder BYMONTHDAY, meint
                     * das ganze Jahr (Pruefung 02.10.2026, Nr. 10). */
                    if ($freq == 'MONTHLY') {
                        $monat = (int) $per->format('n');
                        $bereiche = ($bymonat && !in_array($monat, $bymonat, true)) ? [] : [[$monat]];
                    } elseif ($bymonat) {
                        $bereiche = [];
                        foreach ($bymonat as $mon) { $bereiche[] = [$mon]; }
                    } elseif ($bytag || $bymonatstag) {
                        $bereiche = [range(1, 12)];
                    } else {
                        $bereiche = [[(int) $start->format('n')]];
                    }

                    $kandidaten = [];
                    foreach ($bereiche as $mons) {
                        $tage = [];          // [Monat, Tag, Wochentag, Tage im Monat]
                        $jeWt = [];          // Wochentag => Stellen in $tage
                        foreach ($mons as $mon) {
                            $imMonat = (int) date('t', mktime(12, 0, 0, $mon, 1, $jahr));
                            $wd = (int) date('N', mktime(12, 0, 0, $mon, 1, $jahr));
                            for ($t = 1; $t <= $imMonat; $t++) {
                                $jeWt[$wd][] = count($tage);
                                $tage[] = [$mon, $t, $wd, $imMonat];
                                $wd = $wd % 7 + 1;
                            }
                        }
                        $erlaubt = [];
                        foreach ($bytag as $bt) {
                            list($wd, $ord) = $bt;
                            $liste = $jeWt[$wd] ?? [];
                            if ($ord === 0) {
                                foreach ($liste as $i) { $erlaubt[$i] = 1; }
                            } else {
                                $i = $ord > 0 ? $ord - 1 : count($liste) + $ord;
                                if ($i >= 0 && isset($liste[$i])) { $erlaubt[$liste[$i]] = 1; }
                            }
                        }
                        foreach ($tage as $i => $tg) {
                            list($mon, $t, , $imMonat) = $tg;
                            // BYDAY und BYMONTHDAY zusammen: beide muessen passen
                            // (bis 1.6.21 galt nur BYDAY, Pruefung 02.10.2026, Nr. 10).
                            if ($bytag && !isset($erlaubt[$i])) { continue; }
                            if ($bymonatstag) {
                                if (!abfahrt_monatstag_passt($t, $imMonat, $bymonatstag)) { continue; }
                            } elseif (!$bytag && $t !== $starttag) {
                                continue;   // ohne beides der Tag des DTSTART - fehlt er, faellt der Monat aus
                            }
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
                            if (isset($kandidaten[$i])) { $aus[$kandidaten[$i]] = 1; }
                        }
                        $kandidaten = array_keys($aus);
                        sort($kandidaten);
                    }

                    foreach ($kandidaten as $ts) {
                        if ($ts < $mst['ts']) {
                            continue;
                        }
                        $emitted++;
                        if ($count !== null && $emitted > $count) { $fertig = true; break; }
                        if ($until !== null && $ts > $until)      { $fertig = true; break; }
                        if ($ts > $grenze)                         { $fertig = true; break; }
                        $schon[$ts] = 1;
                        abfahrt_serie_aufnehmen($singles, $mst, $ts, $now, $maxTs,
                                                $overridden, $verschoben);
                    }
                }
            }
            // RDATE: weitere Einzeltermine der Serie (Nr. 10); EXDATE und
            // RECURRENCE-ID gelten fuer sie genauso.
            foreach ($mst['rd'] as $rts => $_) {
                if (!isset($schon[$rts])) {
                    abfahrt_serie_aufnehmen($singles, $mst, $rts, $now, $maxTs,
                                            $overridden, $verschoben);
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
        $kallage['mit_ort'] += $count2;
        $diag[] = sprintf(abfahrt_t('DIAG.KAL_ANZAHL'), $name, $count2);     // b1
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

/* Datei im Zwischenspeicher fuer eine Koordinate. Der Zusatz "|2" seit
 * Pruefung 02.10.2026, Nr. 9: bis 1.6.21 suchte TomTom nur in DE, AT und CH;
 * ein dort gefundener gleichnamiger Ort fuer eine Adresse in NL, BE oder FR
 * haette sonst noch 90 Tage gegolten. $naehe (die Koordinate der
 * Abfahrtsadresse) gehoert mit hinein, sie beeinflusst das Ergebnis. */
function abfahrt_geo_cachedatei($provider, $address, $naehe = '') {
    return abfahrt_tmpdir() . '/geo_' . md5($provider . '|' . $address . '|2|' . $naehe);
}

/**
 * $naehe: "lat,lon" der Abfahrtsadresse oder ''. Damit wird ein Ziel ohne
 * Land in der Naehe gesucht (TomTom lat/lon, HERE at=) statt ueber eine feste
 * Laenderliste.
 */
function abfahrt_geocode($address, array $abfcfg, &$err = '', &$err_id = '', $naehe = '') {
    $key = $abfcfg['api_key'];
    $provider = $abfcfg['provider'];
    $naehe = preg_match(ABFAHRT_GEO_MUSTER, (string) $naehe) ? (string) $naehe : '';
    $cache = abfahrt_geo_cachedatei($provider, $address, $naehe);
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
    $grund_id = '';
    $abf_hs = 0;
    if ($provider === 'tomtom') {
        $url = 'https://api.tomtom.com/search/2/geocode/' . rawurlencode($address) . '.json?key=' . rawurlencode($key) . '&limit=1';
        if ($naehe !== '') {
            list($abf_lat, $abf_lon) = explode(',', $naehe);
            $url .= '&lat=' . rawurlencode($abf_lat) . '&lon=' . rawurlencode($abf_lon);
        }
        $g = @json_decode((string) abfahrt_http_get($url, 12, $grund, $abf_hs, $grund_id), true);
        if (isset($g['results'][0]['position'])) {
            $pos = $g['results'][0]['position']['lat'] . ',' . $g['results'][0]['position']['lon'];
        }
    } elseif ($provider === 'here') {
        $url = 'https://geocode.search.hereapi.com/v1/geocode?q=' . rawurlencode($address) . '&apiKey=' . rawurlencode($key)
             . ($naehe !== '' ? '&at=' . rawurlencode($naehe) : '');
        $g = @json_decode((string) abfahrt_http_get($url, 12, $grund, $abf_hs, $grund_id), true);
        if (isset($g['items'][0]['position'])) {
            $pos = $g['items'][0]['position']['lat'] . ',' . $g['items'][0]['position']['lng'];
        }
    }
    if ($pos === null || !preg_match(ABFAHRT_GEO_MUSTER, $pos)) {
        /* b1: Kennung mit Anbieter und Adresse; der HTTP-Grund haengt als
         * eigene Kennung dahinter (GRUND.MIT). */
        $err_id = 'GEO_FEHL|' . abfahrt_grund_teil($provider) . '|' . abfahrt_grund_teil($address)
                . ($grund_id !== '' ? '|' . $grund_id : '');
        $err = abfahrt_grund_text($err_id);
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
 * ueber seine Haltbarkeit hinaus weiterverwendet. Das ist vertretbar: eine Fahrzeit aendert sich in einer
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
function abfahrt_route_minutes($destAddress, array $abfcfg, &$err = '', $minutenBisTermin = null, &$veraltet = false, $abfahrtTs = null, &$err_id = '') {
    $veraltet = false;
    $err_id = '';     // b1: der Grund als Kennung (GRUND.*), $err ist derselbe als Satz
    $grund_id = '';
    $abf_hs = 0;
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
        $r = @json_decode((string) abfahrt_http_get($url, 12, $grund, $abf_hs, $grund_id), true);
        if (isset($r['routes'][0]['legs'][0])) {
            $leg = $r['routes'][0]['legs'][0];
            $sec = $leg['duration_in_traffic']['value'] ?? ($leg['duration']['value'] ?? null);
            if ($sec !== null) {
                $minutes = round($sec / 60, 1);
            }
        }
        if ($minutes === false) {
            /* b1: der Status der Directions-API (REQUEST_DENIED, ZERO_RESULTS ...)
             * geht nur mit Grossbuchstaben und Unterstrich in die Kennung. */
            $abf_gs = (isset($r['status']) && is_scalar($r['status']))
                    ? preg_replace('/[^A-Z_]/', '', strtoupper((string) $r['status'])) : '';
            $err_id = ($abf_gs !== '' ? 'ROUTE_FEHL_STATUS|Google|' . $abf_gs : 'ROUTE_FEHL|Google')
                    . ($grund_id !== '' ? '|' . $grund_id : '');
            $err = abfahrt_grund_text($err_id);
        }
    } elseif ($provider !== 'tomtom' && $provider !== 'here') {
        /* VOR dem Geokodieren. Bis 1.6.9 stand dieser Zweig hinter der
         * Geokodierung, die bei einem unbekannten Dienst schon gescheitert
         * war - er wurde nie erreicht, und gemeldet wurde "Adresse konnte
         * nicht umgesetzt werden", waehrend die Adresse stimmte. */
        $err_id = 'KARTENDIENST_NAME|' . abfahrt_grund_teil($provider);     // b1
        $err = abfahrt_grund_text($err_id);
    } else {
        // Kein vorzeitiges return: auch ein misslungenes Geocoding soll unten
        // noch in die Gnadenfrist laufen duerfen, sonst flattert es genauso.
        $home = abfahrt_geocode($abfcfg['home_address'], $abfcfg, $err, $err_id);
        $dest = ($home === false) ? false : abfahrt_geocode($destAddress, $abfcfg, $err, $err_id, $home);
        $grund = '';
        if ($home === false || $dest === false) {
            $minutes = false;
        } elseif ($provider === 'tomtom') {
            $url = 'https://api.tomtom.com/routing/1/calculateRoute/' . $home . ':' . $dest
                 . '/json?key=' . rawurlencode($key) . '&traffic=true&travelMode=car'
                 . ($abfahrtTs === null ? '' : abfahrt_departat_param('tomtom', $abfahrtTs));
            $r = @json_decode((string) abfahrt_http_get($url, 12, $grund, $abf_hs, $grund_id), true);
            if (isset($r['routes'][0]['summary']['travelTimeInSeconds'])) {
                $minutes = round($r['routes'][0]['summary']['travelTimeInSeconds'] / 60, 1);
            } else {
                $err_id = 'ROUTE_FEHL|TomTom' . ($grund_id !== '' ? '|' . $grund_id : '');     // b1
                $err = abfahrt_grund_text($err_id);
            }
        } elseif ($provider === 'here') {
            $url = 'https://router.hereapi.com/v8/routes?transportMode=car&origin=' . $home
                 . '&destination=' . $dest . '&return=summary&apikey=' . rawurlencode($key)
                 . ($abfahrtTs === null ? '' : abfahrt_departat_param('here', $abfahrtTs));
            $r = @json_decode((string) abfahrt_http_get($url, 12, $grund, $abf_hs, $grund_id), true);
            if (isset($r['routes'][0]['sections'][0]['summary']['duration'])) {
                $minutes = round($r['routes'][0]['sections'][0]['summary']['duration'] / 60, 1);
            } else {
                $err_id = 'ROUTE_FEHL|HERE' . ($grund_id !== '' ? '|' . $grund_id : '');     // b1
                $err = abfahrt_grund_text($err_id);
            }
        }
    }
    if ($minutes !== false) {
        abfahrt_cache_schreiben($cache, $minutes);
        return $minutes;
    }

    // Der Kartendienst hat nicht geliefert. Ist der letzte Wert hoechstens eine
    // Stunde ueber seiner Haltbarkeit, wird er weitergereicht, statt die
    // Berechnung abzubrechen. Bis 1.6.21 zaehlte die Stunde ab dem Abruf - bei
    // einem Termin in mehr als drei Stunden (Haltbarkeit selbst eine Stunde)
    // war sie beim ersten neuen Versuch schon um, es kam FEHLER=6 statt
    // FEHLER=7 (Pruefung 02.10.2026, Nr. 7).
    if ($alter !== null && $alter <= abfahrt_route_ttl($minutenBisTermin) + ABFAHRT_ROUTE_GNADE) {
        $alt = abfahrt_cache_lesen($cache, '/^\d+(\.\d+)?$/');
        if ($alt !== false) {
            $veraltet = true;
            abfahrt_log(sprintf(abfahrt_t('DIAG.ROUTE_GNADE'), $err, (int) round($alter / 60)));     // b1
            $err = '';
            $err_id = '';
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
        return abfahrt_t('ANSAGETEXT.OHNE_TITEL');
    }
    $text = sprintf(abfahrt_t('ANSAGETEXT.MIT_TITEL'), $titel);
    if (!empty($info['fahrt'])) {
        $text .= ' ' . sprintf(abfahrt_t('ANSAGETEXT.FAHRZEIT'), (int) ceil((float) $info['fahrt']));
    }
    return $text;
}

/* ---------------- Ausgabe der Ansage (Nr. 36 b, Stufe 2) ----------------
 *
 * Bis 1.6.22 standen hier die Wege zu Alexa-NG und Chromecast 4 Lox NG (Ansage-2/3:
 * Aufruf, Bewertung, letzte Ansage, Zeile im Reiter Test) und die Adresse des Music
 * Servers. Seit 1.6.23 spricht die gemeinsame Sprachausgabe: ansage_sprechen() in
 * termin_say.php und ansage_testansage() im Reiter Test, ansage_pruefzeile() in
 * abfahrt_pruefungen(). Dieselben Kennungen (ALEXA_*, GOOGLE_*, HTTP_*), dieselbe
 * Bewertung (Alexa-NG/Chromecast nur HTTP 200 und SPRECHEN;OK=1, Music Server und
 * Vorlagen HTTP 2xx), dieselbe Heimnetz-Pflicht vor jedem Senden; Saetze aus [ANSAGE].
 */

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
    /* Das fuenfte Element sagt, ob das MQTT-Thema RETAINED gesendet wird.
     *
     * SEIT 1.6.13 KEINES. Von 1.6.8 bis 1.6.12 gingen OK, FEHLER, AUDIO und
     * PUSH zurueckbehalten hinaus ("Zustaende retained", Hausstandard vom
     * 03.09.2026). Die Entscheidungen des Hausherrn vom 18., 19. und
     * 24.09.2026 (Regeln/07, Abschnitt 3) ordnen alle vier anders ein:
     *   OK      "Termin und Route berechnet" - eine Aussage des Dienstes ueber
     *           seine eigene Rechnung; ok ist nie retained.
     *   FEHLER  Fehlergrund 0-9, darunter "Kalender bzw. Kartendienst nicht
     *           erreichbar" - ebenfalls der Dienst ueber sich selbst.
     *   AUDIO   haengt an den Sperrzeiten, PUSH mit "Sperrzeit auch fuer Push"
     *   PUSH    ebenso: beide werden allein durch die Uhr falsch (Zeitbezug).
     * Stirbt der Dienst, bliebe jeder dieser Werte zurueckbehalten stehen, und
     * nach einem Neustart von Broker oder Gateway laese Loxone "in Ordnung"
     * bzw. "Ansage erlaubt" von einem Dienst, der nicht mehr rechnet. Preis:
     * nach einem Neustart des Miniservers fehlen die Werte bis zum naechsten
     * Vollversand (mqtt_vollsend_min, ab Werk 15 Minuten). Die Altwerte der
     * Vorfassungen raeumt abfahrt_mqtt_altlast() ab, die Deinstallation
     * abfahrt_mqtt_leeren(). In WSL gemessen: Pruefung-Abfahrtsassistent-1.6.13,
     * Faelle R1 bis R23 und U1 bis U7.
     *
     * Die fuenf Zahlen mit Zeitbezug waren nie retained - ALTER ganz
     * besonders: es IST die Ausfallerkennung, und retained behauptete es fuer
     * immer, die Rechnung sei eben erst gelaufen. */
    /* Seit 1.6.10 drei weitere Elemente:
     *   [5] geht ueber MQTT hinaus (1/0)
     *   [6] Einheit fuer die Importvorlage ('' = keine)
     *   [7] Fehlwert - DefVal der Importvorlage, damit ein Eingang vor dem
     *       ersten Wert nicht "jetzt losfahren" (ABFAHRT_IN=0) behauptet
     *
     * ALTER GEHT NICHT MEHR UEBER MQTT. Gemessen 06.09.2026: der Dienst
     * rechnet ALTER unmittelbar nach der Berechnung, der Wert war ueber MQTT
     * ausnahmslos 0 (mqtt_letzte.json am Geraet: "ALTER":0). Ein Eingang, der
     * immer 0 zeigt, ist keine Ausfallerkennung, sondern das Gegenteil. Ueber
     * MQTT gibt es kein Alter, nur einen Zeitstempel (Regeln/07): dafuer geht
     * jetzt das Lebenszeichen abfahrt/status/ts, .../zaehler und .../ok bei
     * JEDEM Cron-Lauf hinaus, nie retained. Ueber HTTP bleibt ALTER, dort
     * stimmt es (termin.php liest zum Abrufzeitpunkt). */
    return [
        'OK'         => [0, 0, 1, 'FELD.OK', 0, 1, '', 0],
        'MINSTART'   => [1, 0, 99999, 'FELD.MINSTART', 0, 1, 'min', 9999],
        'FAHRT'      => [1, 0, 1440, 'FELD.FAHRT', 0, 1, 'min', 0],
        'ABFAHRT_IN' => [1, -9999, 9999, 'FELD.ABFAHRT_IN', 0, 1, 'min', 9999],
        'FEHLER'     => [1, 0, 9, 'FELD.FEHLER', 0, 1, '', 0],
        'ALTER'      => [1, 0, 86400, 'FELD.ALTER', 0, 0, 's', 86400],
        'AUDIO'      => [0, 0, 1, 'FELD.AUDIO', 0, 1, '', 0],
        'PUSH'       => [0, 0, 1, 'FELD.PUSH', 0, 1, '', 0],
        // Neu in 1.6.0. Steht am ENDE, damit die Reihenfolge der bisherigen
        // Felder - und damit jede eingetragene Befehlserkennung - gleich
        // bleibt. 1440 heisst "unbekannt"; gueltige Werte sind 0..1439.
        'ANKUNFT'    => [1, 0, 1440, 'FELD.ANKUNFT', 0, 1, '', 1440],
    ];
}

/** Geht dieses Feld ueber MQTT hinaus? Eine Quelle: abfahrt_felder(). */
function abfahrt_feld_mqtt($name) {
    $f = abfahrt_felder();
    return !empty($f[$name][5]);
}

/** Der Suchtext eines Feldes - fuer Vorlage UND Anleitung, eine Stelle. */
function abfahrt_suchtext($name) {
    return '\\i;' . $name . '=\\i\\v';
}

/** Wird dieses Feld retained gesendet? Eine Quelle: abfahrt_felder(). */
function abfahrt_feld_retain($name) {
    $f = abfahrt_felder();
    return isset($f[$name][4]) && $f[$name][4] ? true : false;
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
        'zeit' => 0, 'ok' => 0, 'fehler' => 0, 'grund' => '', 'grund_id' => '',
        'minstart' => 9999, 'fahrt' => 0, 'abfahrt_in' => 9999, 'ankunft' => 1440,
        'titel' => '', 'ort' => '', 'kalender' => '', 'beginn' => '',
    ];
}

function abfahrt_stand_write(array $st) {
    /* JSON_INVALID_UTF8_SUBSTITUTE (ab PHP 7.2) als zweite Sicherung zu
     * abfahrt_utf8(): ein einzelnes kaputtes Byte darf den ganzen Stand nicht
     * am Schreiben hindern (C3). */
    $js = json_encode($st, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
                           | (defined('JSON_INVALID_UTF8_SUBSTITUTE') ? JSON_INVALID_UTF8_SUBSTITUTE : 0));
    if ($js === false) { return false; }
    abfahrt_tmpdir();   // wer schreibt, legt an - und nur der
    /* Unteilbar. LOCK_EX half nur gegen andere Schreiber; termin.php liest
     * ohne Sperre, und file_put_contents kuerzt die Datei vor dem Schreiben.
     * Wer dazwischen las, bekam die Vorgaben - OK=0 bei FEHLER=0, eine
     * Kombination, die die Fehlertabelle nicht kennt. */
    return abfahrt_cache_schreiben(abfahrt_standfile(), $js);
}

/**
 * Den Stand schreiben und die Wirkung pruefen (C3, Durchgang 29.09.2026).
 *
 * Bis 1.6.15 wurde der Rueckgabewert von abfahrt_stand_write() nirgends
 * angesehen: scheiterte das Schreiben, gaben termin.php und die Ansage den
 * VORIGEN Termin aus, MQTT den neuen, und das Protokoll schwieg. Jetzt: OK=0
 * im Rueckgabewert (geht so auch ueber MQTT hinaus), der alte Stand und
 * titel.json werden entfernt, damit Endpunkt und Ansage keinen alten Termin
 * mehr lesen, und eine Protokollzeile je Stunde. Laesst sich auch das
 * Entfernen nicht (Ordner schreibgeschuetzt), greift die Altersgrenze aus
 * abfahrt_ok_wirksam() nach dem Dreifachen des Rechentakts.
 */
function abfahrt_stand_sichern(array &$st) {
    if (abfahrt_stand_write($st)) { return true; }
    $st['ok'] = 0;
    /* Bis 1.6.21 blieb FEHLER stehen - bei einem Stand mit OK=1 hiess das
     * OK=0;FEHLER=0, die Kombination, die die Tabelle nicht kennt. Jetzt 9
     * (Pruefung 02.10.2026, Nr. 20). */
    $st['fehler'] = 9;
    $st['grund_id'] = 'STAND_SCHREIBEN';
    $st['grund'] = abfahrt_grund_text('STAND_SCHREIBEN');
    @unlink(abfahrt_standfile());
    @unlink(abfahrt_tmpdir(false) . '/titel.json');
    abfahrt_log_gedrosselt('stand_schreiben', 'Dienst: Der Zwischenstand liess sich nicht schreiben ('
        . abfahrt_standfile() . '). OK=0;FEHLER=9, bis es wieder gelingt.', 3600);
    return false;
}

/**
 * Die Sperre des Rechnens: hoechstens ein Lauf von abfahrt_berechnen() zur
 * selben Zeit, gleich ob aus dem Dienst oder aus termin.php?debug=1. Bis
 * 1.6.21 hielt nur der Dienst sie; ?debug=1 rechnete daneben, fragte den
 * Kartendienst ein zweites Mal und schrieb stand.json und titel.json im
 * Wettlauf (Pruefung 02.10.2026, Nr. 21).
 *
 * Rueckgabe: der Dateizeiger (gehalten, freigeben mit abfahrt_sperre_frei())
 * oder false; $grund ist dann 'oeffnen' (Sperrdatei nicht zu oeffnen, etwa
 * nach einem Handstart als root) oder 'belegt'. Der Halter traegt Startzeit
 * und PID ein - fuer einen Lauf, der die Sperre belegt findet.
 */
function abfahrt_sperrdatei() {
    return abfahrt_tmpdir() . '/dienst.lock';
}

function abfahrt_sperre(&$grund = '') {
    $grund = '';
    $fh = @fopen(abfahrt_sperrdatei(), 'c');
    if ($fh === false) { $grund = 'oeffnen'; return false; }
    if (!flock($fh, LOCK_EX | LOCK_NB)) {
        fclose($fh);
        $grund = 'belegt';
        return false;
    }
    @ftruncate($fh, 0);
    @fwrite($fh, time() . ' ' . getmypid() . "\n");
    @fflush($fh);
    return $fh;
}

function abfahrt_sperre_frei($fh) {
    if (is_resource($fh)) {
        flock($fh, LOCK_UN);
        fclose($fh);
    }
}

/**
 * Der Rechentakt, der fuer diesen Stand gilt (C4): 60 s, solange die Abfahrt
 * hoechstens eine Stunde entfernt ist, sonst 300 s. EINE Stelle fuer den
 * Dienst (wann ist ein Lauf faellig?) und fuer die Altersgrenze von OK.
 */
function abfahrt_rechentakt(array $st) {
    $st += array('abfahrt_in' => 9999, 'ok' => 0);
    /* 65, nicht 60: der abgelegte Wert kann aus einem Lauf im 300-s-Takt
     * stammen und bis zu fuenf Minuten alt sein. Mit 60 kam der erste Wert
     * der letzten Stunde als 56 bis 60 an (Pruefung 02.10.2026, Nr. 19). */
    return ((int) $st['abfahrt_in'] <= 65 && (int) $st['ok'] === 1) ? 60 : 300;
}

/**
 * Ist der Stand zu alt, um noch zu gelten? Kein Stand, ein Stand aus der
 * Zukunft (die Uhr ist zurueckgesprungen - NTP auf einem Pi ohne Uhrbaustein;
 * 60 s Spiel) oder aelter als das Dreifache des Rechentakts. Bis 1.6.21 galt
 * ein Stand aus der Zukunft als frisch, bis die Uhr ihn eingeholt hatte
 * (Pruefung 02.10.2026, Nr. 18).
 */
function abfahrt_stand_veraltet(array $st) {
    $st += array('zeit' => 0);
    $zeit = (int) $st['zeit'];
    if ($zeit <= 0 || $zeit > time() + 60) { return true; }
    return (time() - $zeit) > 3 * abfahrt_rechentakt($st);
}

/**
 * OK, wie es an Loxone geht (C4, Entscheidung 4 vom 29.09.2026): 0, sobald
 * der Stand aelter ist als das Dreifache des gerade geltenden Rechentakts
 * (900 s, in der letzten Stunde vor der Abfahrt 180 s). ALTER bleibt daneben
 * unveraendert stehen. Bis 1.6.15 blieb OK=1 bei einem Stand beliebigen
 * Alters (gemessen: ALTER=7200, OK=1, und termin_say.php sprach).
 */
function abfahrt_ok_wirksam(array $st) {
    $st += array('ok' => 0, 'zeit' => 0);
    if ((int) $st['ok'] !== 1) { return 0; }
    return abfahrt_stand_veraltet($st) ? 0 : 1;
}

/**
 * FEHLER, wie es an Loxone geht: 9, solange kein frischer Stand da ist
 * (abfahrt_stand_veraltet()), sonst der abgelegte Wert. Bis 1.6.21 gingen ein
 * fehlender und ein veralteter Stand als OK=0;FEHLER=0 hinaus (Pruefung
 * 02.10.2026, Nr. 20).
 */
function abfahrt_fehler_wirksam(array $st) {
    $st += array('fehler' => 0);
    return abfahrt_stand_veraltet($st) ? 9 : (int) $st['fehler'];
}

/** Der Grund zu abfahrt_fehler_wirksam(), in der Sprache des Lesers. */
function abfahrt_grund_wirksam(array $st) {
    $st += array('zeit' => 0, 'fehler' => 0);
    if (abfahrt_stand_veraltet($st) && (int) $st['fehler'] !== 9) {
        return abfahrt_grund_text((int) $st['zeit'] <= 0 ? 'STAND_FEHLT' : 'STAND_VERALTET');
    }
    return abfahrt_stand_grund($st);
}

/**
 * ANKUNFT in Minuten seit Mitternacht (C5, Durchgang 29.09.2026), 1440 heisst
 * unbekannt (Ankunft erst am naechsten Tag).
 *
 * Aus der Uhrzeit (date('G')/date('i')), nicht aus dem Abstand zu
 * strtotime('today'): an den beiden Umstelltagen lag der Wert bis 1.6.15 den
 * ganzen Tag um 60 Minuten falsch, und am 25.10. ab 23:00 kam 1440
 * (gemessen, Code-Pruefer C5). Gerundet wird wie bisher auf die naechste
 * Minute (+30 s).
 */
function abfahrt_ankunft_minuten($fahrt, $jetzt = null) {
    $jetzt = ($jetzt === null) ? time() : (int) $jetzt;
    $t = $jetzt + (int) round($fahrt * 60) + 30;
    if (date('Y-m-d', $t) !== date('Y-m-d', $jetzt)) { return 1440; }
    return (int) date('G', $t) * 60 + (int) date('i', $t);
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
        // C4: mit Altersgrenze (abfahrt_ok_wirksam()); bis 1.6.15 nur $st['ok'].
        'OK'         => abfahrt_ok_wirksam($st),
        'MINSTART'   => (int) $st['minstart'],
        /* Auf die Grenzen klemmen, die abfahrt_felder() dem virtuellen
         * Eingang als MinVal/MaxVal mitgibt - sonst traegt die erzeugte
         * Vorlage eine Zusage, die die Zeile nicht einhaelt. */
        'FAHRT'      => max(0, min(1440, 0 + $st['fahrt'])),
        'ABFAHRT_IN' => max(-9999, min(9999, (int) $st['abfahrt_in'])),
        'FEHLER'     => abfahrt_fehler_wirksam($st),     // Nr. 20
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
    /* U5: $kennung ist der Grund als Kennung (GRUND.*), fuer die Anzeige in der
     * Sprache der Oberflaeche; 'grund' bleibt der Text fuer Protokoll und
     * ?debug=1. */
    $setz = function ($code, $grund, $kennung = '') use (&$st) {
        $st['ok'] = 0;
        $st['fehler'] = $code;
        // b1: der Satz kommt aus der Kennung (Sprache dieses Laufs); ohne Kennung der Text.
        $st['grund'] = $kennung !== '' ? abfahrt_grund_text($kennung) : $grund;
        $st['grund_id'] = $kennung;
        $st['minstart'] = 9999;
        $st['fahrt'] = 0;
        $st['abfahrt_in'] = 9999;
        $st['ankunft'] = 1440;      // 1440 = unbekannt
        $st['titel'] = '';
        $st['ort'] = '';
        $st['kalender'] = '';
        $st['beginn'] = '';
        $st['zeit'] = time();
        abfahrt_stand_sichern($st);     // C3: Rueckgabe geprueft, bei Fehlschlag OK=0
        @unlink(abfahrt_tmpdir() . '/titel.json');
        return $st;
    };

    $hasCal = false;
    foreach ($abfcfg['calendars'] as $cal) {
        if (trim((string) ($cal['url'] ?? '')) !== '') { $hasCal = true; break; }
    }
    if (!$hasCal)                                { return [$setz(1, '', 'KEIN_KALENDER'), $diag]; }
    if (trim($abfcfg['api_key']) === '')         { return [$setz(2, '', 'KEIN_SCHLUESSEL'), $diag]; }
    if (trim($abfcfg['home_address']) === '')    { return [$setz(3, '', 'KEINE_ADRESSE'), $diag]; }

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
            /* FEHLER=8 seit 1.6.10. Bis dahin stand hier die 5 - dieselbe
             * Zahl wie "Kalender nicht erreichbar, es gilt der letzte
             * gelesene Stand" (OK=1). Ein Statusbaustein nach der Tabelle
             * zeigte bei einem abgelaufenen Kalendertoken also die
             * beruhigende Meldung, waehrend nichts mehr gelesen wurde. */
            return [$setz(8, '',
                          'KAL_KEINER|' . (int) $kallage['tot'] . '|' . (int) $kallage['eingerichtet']), $diag];
        }
        return [$setz(4, '',
                      'KEIN_TERMIN|' . (int) $abfcfg['lookahead_hours']), $diag];
    }
    list($ts, $loc, $sum, $calname) = $best;
    $minstart = (int) round(($ts - time()) / 60);

    $err = '';
    $err_id = '';
    $veraltet = false;
    $fahrt = abfahrt_route_minutes($loc, $abfcfg, $err, $minstart, $veraltet, null, $err_id);
    if ($fahrt === false) {
        /* b1: FEHLER=6 mit Kennung - bis 1.6.18 ohne, die Oberflaeche zeigte
         * den Satz des Minutentakts (deutsch, auch in der englischen). */
        $st = $setz(6, $err, $err_id);
        // Titel und Ort sind bekannt, nur die Fahrzeit fehlt - das gehoert in
        // die Anzeige, sonst steht dort nach einer Stoerung gar nichts mehr.
        $st['titel'] = $sum;
        $st['ort'] = $loc;
        $st['kalender'] = $calname;
        $st['beginn'] = date('d.m.Y H:i', $ts);
        $st['minstart'] = $minstart;
        abfahrt_stand_sichern($st);     // C3
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
            $diag[] = sprintf(abfahrt_t('DIAG.FAHRZEIT_ABFAHRT'),
                              date('H:i', $abfahrtTs), $fahrt2, $fahrt);     // b1
            $fahrt = $fahrt2;
            $veraltet = $veraltet || $veraltet2;
        } else {
            // Der zweite Durchgang ist kein Muss. Scheitert er, gilt der
            // erste weiter - eine Fahrzeit von jetzt ist besser als keine.
            $diag[] = sprintf(abfahrt_t('DIAG.FAHRZEIT_ABFAHRT_FEHL'),
                              $err2 !== '' ? $err2 : abfahrt_t('DIAG.OHNE_ANGABE'));     // b1
        }
    }

    $st['ok'] = 1;
    /* Reihenfolge: die veraltete Fahrzeit (7) sticht den veralteten Kalender
     * (5), weil sie unmittelbar auf den Abfahrtszeitpunkt wirkt. */
    if ($veraltet) {
        $st['fehler'] = 7;
        $st['grund'] = abfahrt_grund_text('ROUTE_VERALTET');     // b1
        $st['grund_id'] = 'ROUTE_VERALTET';
    } elseif (!empty($kallage['veraltet'])) {
        $st['fehler'] = 5;
        $st['grund'] = abfahrt_grund_text('KAL_VERALTET');     // b1
        $st['grund_id'] = 'KAL_VERALTET';
    } else {
        $st['fehler'] = 0;
        $st['grund'] = '';
        $st['grund_id'] = '';
    }
    $st['minstart'] = $minstart;
    $st['fahrt'] = $fahrt;
    $st['abfahrt_in'] = (int) round($minstart - $fahrt - (int) $abfcfg['arrival_min'] - (int) $abfcfg['buffer_min']);
    /* ANKUNFT: wann waere man da, wenn man JETZT losfuehre - als Minuten seit
     * Mitternacht, damit ein virtueller Eingang die Zahl tragen kann. Der Wert
     * beantwortet die Frage, die ABFAHRT_IN nicht beantwortet: bin ich schon
     * zu spaet, und um wie viel. 1440 heisst unbekannt. */
    $st['ankunft'] = abfahrt_ankunft_minuten($fahrt);   // C5: aus der Uhrzeit
    $st['titel'] = $sum;
    $st['ort'] = $loc;
    $st['kalender'] = $calname;
    $st['beginn'] = date('d.m.Y H:i', $ts);
    $st['zeit'] = time();
    if (!abfahrt_stand_sichern($st)) {     // C3: kein titel.json zu einem Stand, der nicht steht
        return [$st, $diag];
    }

    // Titel fuer die Ansage (termin_say.php) - Format unveraendert, damit
    // bestehende Einbindungen weiterlaufen.
    $js = json_encode([
        'titel' => $sum, 'ort' => $loc, 'kalender' => $calname,
        'beginn' => $st['beginn'], 'minstart' => $minstart,
        'fahrt' => $fahrt, 'abfahrt_in' => $st['abfahrt_in'],
    ], JSON_UNESCAPED_UNICODE | (defined('JSON_INVALID_UTF8_SUBSTITUTE') ? JSON_INVALID_UTF8_SUBSTITUTE : 0));
    /* C3: Bis 1.6.15 blieb ein altes titel.json stehen, wenn das Schreiben
     * scheiterte, und die Ansage nannte den vorigen Termin. Jetzt wird es
     * entfernt (die Ansage spricht dann ohne Terminnamen) und gemeldet. */
    if ($js === false || !abfahrt_cache_schreiben(abfahrt_tmpdir() . '/titel.json', $js)) {
        @unlink(abfahrt_tmpdir() . '/titel.json');
        abfahrt_log_gedrosselt('titel_schreiben', 'Dienst: titel.json liess sich nicht schreiben'
            . ($js === false ? ' (' . json_last_error_msg() . ')' : '') . ' - die Ansage nennt keinen Terminnamen.', 3600);
    }

    return [$st, $diag];
}

/* ==================================================================
 * MQTT - der Regelweg nach Loxone
 * ================================================================== */

function abfahrt_mqtt_zustand() {
    /* Nur mit den Pfaden der Anlage (abfahrt_paths()): aus einem Archiv geht
     * nichts an das Gateway der Anlage (Pruefung-Abfahrtsassistent-1.6.13,
     * Fall B6). */
    $abf_p = abfahrt_paths();
    $aus = ['gefunden' => false, 'udpport' => 0, 'autostart' => false];
    if ($abf_p['general'] === '') { return $aus; }
    $f = $abf_p['general'];
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
function abfahrt_abo_text($praefix = null)
{
    $m = abfahrt_mqtt_zustand();
    $f = isset($m['fassung']) ? (int) $m['fassung'] : 0;
    if ($f <= 0) {
        return abfahrt_t('MQTT.ABO_UNBEKANNT');
    }
    $gemessen = ' <span class="sm-mono">'
              . sprintf(abfahrt_t('MQTT.ABO_GEMESSEN'), $f) . '</span>';
    if ($f >= 2) {
        return abfahrt_t('MQTT.ABO_V2') . $gemessen;
    }
    /* M5: Unter V1 traegt das Plugin sein Abo selbst (mqtt_subscriptions.cfg).
     * Die Warnung bleibt fuer den Fall, dass die Datei das Praefix nicht traegt. */
    if ($praefix === null) {
        $c = abfahrt_config();
        $praefix = $c['mqtt_topic'];
    }
    list(, $da) = abfahrt_abo_datei($praefix);
    return abfahrt_t($da ? 'MQTT.ABO_MITGELIEFERT' : 'MQTT.ABO_WARNUNG') . $gemessen;
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

/* Abstand zwischen zwei Datagrammen an den UDP-Eingang des Gateways, in
 * Mikrosekunden. Gemessen an dieser Anlage (Regeln/07, 13.09.2026): ein Stoss
 * von 90 Datagrammen ohne Pause kam zu 0 bis 7 % an, mit 50 ms zu 44 %; der
 * Eingang verwirft stumm, und sendto() meldet trotzdem Erfolg. Bei neun
 * Themen kostet die Pause knapp eine halbe Sekunde je Vollversand. */
define('ABFAHRT_MQTT_PAUSE_US', 50000);

/**
 * Werte an das MQTT-Gateway schieben.
 *
 * Rueckgabe: Zahl der VERSUCHTEN Zeilen, oder false, wenn gar nicht gesendet
 * werden konnte (MQTT aus, kein Gateway, kein Sockel). Eine Zahl heisst
 * "abgeschickt", NICHT "angekommen" - UDP kennt keine Empfangsbestaetigung,
 * und der Eingang dieser Anlage verwirft unter Last (gemessen 16.09.2026:
 * 1 220 023 von 8 001 444 Datagrammen, 15 %).
 *
 * $extra nimmt Themen ausserhalb der Feldtabelle auf (das Lebenszeichen):
 * array(thema_ohne_vorsatz => wert), immer "publish".
 *
 * $raeumen (feldname => true): vor diesen Feldern geht zuerst eine leere
 * retain-Nutzlast hinaus, die den Altwert einer Vorfassung loescht
 * (abfahrt_mqtt_altlast()).
 */
function abfahrt_mqtt_senden(array $werte, ?array $abfcfg = null, array $extra = array(), array $raeumen = array()) {
    if ($abfcfg === null) { $abfcfg = abfahrt_config(); }
    if (empty($abfcfg['mqtt_ein'])) { return false; }
    $m = abfahrt_mqtt_zustand();
    if (!$m['gefunden'] || !$m['udpport']) { return false; }
    $sock = @fsockopen('udp://127.0.0.1', (int) $m['udpport'], $en, $es, 2);
    if (!$sock) {
        abfahrt_log('MQTT: UDP-Eingang ' . (int) $m['udpport'] . ' liess sich nicht oeffnen (' . (string) $es . ').');
        return false;
    }
    /* Der UDP-Eingang des Gateways kennt vier Befehle: publish, retain,
     * reconnect, save_relayed_states - gemessen 06.09.2026 im Quelltext des
     * Geraets (sbin/mqttgateway.pl, Zeile 293). Ein Zustand geht mit
     * "retain", ein Messwert mit "publish"; was das ist, sagt die
     * Feldtabelle, nicht diese Stelle. Ein leerer Wert geht nie retained
     * hinaus - eine leere Nutzlast loescht ein zurueckbehaltenes Thema. */
    $zeilen = array();
    foreach ($werte as $name => $wert) {
        if (!abfahrt_feld_mqtt($name)) { continue; }
        $w = abf_mqtt_wert_saeubern($wert);
        if (isset($raeumen[$name])) {
            /* Den Altwert einer Vorfassung abraeumen: die leere retain-Nutzlast
             * geht UNMITTELBAR vor dem gueltigen Wert hinaus. "retain <thema> "
             * mit dem Leerzeichen und ohne Zeilenende ist die Form, die das
             * Gateway als Loeschung an den Broker gibt (mqttgateway.pl:281,
             * :357; am Geraet am 19.09.2026 belegt, Regeln/07). Wer das Thema
             * abonniert hat, bekommt die Loeschung als leere Nachricht und den
             * Wert gleich dahinter. */
            $zeilen[] = 'retain ' . $abfcfg['mqtt_topic'] . '/' . $name . ' ';
        }
        $befehl = (abfahrt_feld_retain($name) && $w !== '') ? 'retain' : 'publish';
        $zeilen[] = $befehl . ' ' . $abfcfg['mqtt_topic'] . '/' . $name . ' ' . $w . "\n";
    }
    foreach ($extra as $thema => $wert) {
        $zeilen[] = 'publish ' . $abfcfg['mqtt_topic'] . '/' . $thema . ' ' . abf_mqtt_wert_saeubern($wert) . "\n";
    }
    $raus = 0;
    foreach ($zeilen as $i => $z) {
        if ($i > 0) { usleep(ABFAHRT_MQTT_PAUSE_US); }
        if (@fwrite($sock, $z) !== false) {
            $raus++;
        }
    }
    @fclose($sock);
    if ($zeilen && $raus === 0) {
        abfahrt_log('MQTT: keine Zeile liess sich absenden (UDP-Eingang ' . (int) $m['udpport'] . ').');
        return false;
    }
    return $raus;
}

/**
 * Die Themen, die frueher zurueckbehalten hinausgingen und es heute nicht
 * mehr tun: OK, FEHLER, AUDIO und PUSH, retained gesendet von 1.6.8 bis
 * 1.6.12 (gemessen am 25.09.2026 an den Archiven 1.6.4 bis 1.6.12; bis 1.6.7
 * ging alles mit "publish" hinaus, das Lebenszeichen nie retained).
 * Abgezogen wird, was heute noch zurueckbehalten hinausgeht. Ihre Altwerte
 * stehen auf bestehenden Anlagen im Broker, bis jemand sie loescht - ein
 * spaeteres "publish" ersetzt einen zurueckbehaltenen Wert nicht.
 */
function abfahrt_mqtt_frueher_behalten()
{
    $aus = array();
    foreach (array('OK', 'FEHLER', 'AUDIO', 'PUSH') as $t) {
        if (!abfahrt_feld_retain($t)) { $aus[] = $t; }
    }
    return $aus;
}

/**
 * Alle Themen, die diese Linie je zurueckbehalten gesendet hat oder heute
 * sendet - fuer die Deinstallation.
 */
function abfahrt_mqtt_leer_themen()
{
    $t = array();
    foreach (abfahrt_mqtt_frueher_behalten() as $k) { $t[$k] = true; }
    foreach (array_keys(abfahrt_felder()) as $k) {
        if (abfahrt_feld_retain($k)) { $t[$k] = true; }
    }
    return array_keys($t);
}

/**
 * Den Broker fragen, welche der Themen $themen er zurueckbehaelt - in EINER
 * Verbindung.
 *
 * Rueckgabe array('lage' => 'ok'|'unbekannt', 'belegt' => array(thema => true)).
 * 'ok' heisst: der Broker hat JEDES Abonnement bestaetigt; was dann nicht
 * unter 'belegt' steht, ist leer. 'unbekannt': er war nicht zu fragen
 * (keine Wurzel, keine Verbindung, Anmeldung abgewiesen - CONNACK ungleich 0 -,
 * ein Abonnement abgelehnt - SUBACK-Rueckgabebyte ab 0x80 -, eine unpassende
 * oder gar keine Antwort). "Nicht zu fragen" heisst nie "nichts belegt".
 *
 * Warum ueberhaupt fragen: gesendet wird ueber den UDP-Eingang des Gateways,
 * und dort meldet sendto() auch fuer ein verworfenes Datagramm Erfolg. Am
 * Geraet gemessen (Regeln/07, "Ein Absender merkt nichts davon", Nachtraege
 * vom 19.09.2026): Beschattungswaechter 0.9.19 und KODI-NG 1.2.7 setzten
 * ihren Merker nach dem Senden, der Eingang verwarf, und der Altwert stand
 * weiter im Broker. Belegt ist das Abraeumen erst, wenn der Broker selbst
 * sagt, dass nichts mehr dasteht.
 *
 * MQTT 3.1.1 von Hand, nur CONNECT, SUBSCRIBE (QoS 0) und DISCONNECT - ohne
 * fremde Bibliothek; Bauart awm_mqtt_behalten_liste() aus AWM-Abfuhr 1.4.14
 * (dort aus Spotpreis-Tibber 0.9.19, SUBACK-Pruefung aus
 * Beschattungswaechter 0.9.21). Die Anmeldung nimmt Brokeruser/Brokerpass
 * aus der general.json (Regeln/07, Abschnitt 2); das Kennwort steht nur im
 * CONNECT-Paket, nie in einem Protokoll und nie auf einer Kommandozeile.
 */
function abfahrt_mqtt_behalten_liste(array $themen)
{
    $aus = array('lage' => 'unbekannt', 'belegt' => array());
    $soll = array();
    foreach ($themen as $t) {
        if ((string) $t !== '') { $soll[(string) $t] = true; }
    }
    if (!$soll) {
        $aus['lage'] = 'ok';
        return $aus;
    }
    $p = abfahrt_paths();
    if ($p['lbhome'] === '' || !is_file($p['general'])) { return $aus; }
    $gen = json_decode((string) @file_get_contents($p['general']), true);
    if (!is_array($gen)) { return $aus; }
    $m = array();
    if (isset($gen['Mqtt']) && is_array($gen['Mqtt'])) { $m = $gen['Mqtt']; }
    elseif (isset($gen['mqtt']) && is_array($gen['mqtt'])) { $m = $gen['mqtt']; }
    if (!$m) { return $aus; }
    $hol = function ($gross, $klein) use ($m) {
        if (isset($m[$gross])) { return (string) $m[$gross]; }
        return isset($m[$klein]) ? (string) $m[$klein] : '';
    };
    $host = trim($hol('Brokerhost', 'brokerhost'));
    if ($host === '' || $host === 'localhost') { $host = '127.0.0.1'; }
    $port = (int) $hol('Brokerport', 'brokerport');
    if ($port <= 0 || $port > 65535) { $port = 1883; }
    $benutzer = $hol('Brokeruser', 'brokeruser');
    $kennwort = $hol('Brokerpass', 'brokerpass');

    $s = @stream_socket_client('tcp://' . $host . ':' . $port, $errno, $errstr, 2);
    if (!$s) { return $aus; }
    stream_set_timeout($s, 1);

    $zk = function ($t) { return pack('n', strlen($t)) . $t; };
    $laenge = function ($n) {
        $o = '';
        do {
            $b = $n % 128;
            $n = intdiv($n, 128);
            if ($n > 0) { $b |= 128; }
            $o .= chr($b);
        } while ($n > 0);
        return $o;
    };
    /* Genau $n Bytes lesen oder null - bei Zeitablauf und Verbindungsende. */
    $lies = function ($n) use ($s) {
        $d = '';
        while (strlen($d) < $n) {
            $t = @fread($s, $n - strlen($d));
            if ($t === false || $t === '') {
                $meta = stream_get_meta_data($s);
                if (!empty($meta['timed_out']) || !empty($meta['eof']) || feof($s)) { return null; }
                continue;
            }
            $d .= $t;
        }
        return $d;
    };
    /* Ein Paket: array(kopfbyte, rumpf) oder null. */
    $paket = function () use ($lies) {
        $k = $lies(1);
        if ($k === null) { return null; }
        $n = 0; $mult = 1;
        for ($i = 0; $i < 4; $i++) {
            $b = $lies(1);
            if ($b === null) { return null; }
            $n += (ord($b) & 127) * $mult;
            $mult *= 128;
            if (!(ord($b) & 128)) { break; }
        }
        $r = ($n > 0) ? $lies($n) : '';
        return ($r === null) ? null : array(ord($k), $r);
    };

    $flags = 0x02;                                  // saubere Sitzung
    $nutz = $zk('abfrueck' . getmypid());
    if ($benutzer !== '') {
        $flags |= 0x80;
        // Ein Kennwort ohne Benutzer laesst MQTT 3.1.1 nicht zu (Abschnitt
        // CONNECT, Kennwort-Merkmal).
        if ($kennwort !== '') { $flags |= 0x40; }
    }
    $kopf = $zk('MQTT') . chr(4) . chr($flags) . pack('n', 10);
    if ($benutzer !== '') {
        $nutz .= $zk($benutzer);
        if ($kennwort !== '') { $nutz .= $zk($kennwort); }
    }
    if (@fwrite($s, chr(0x10) . $laenge(strlen($kopf . $nutz)) . $kopf . $nutz) !== false) {
        $ack = $paket();
        /* CONNACK: Rueckgabecode im zweiten Byte, nur 0 heisst angemeldet. */
        if ($ack !== null && ($ack[0] >> 4) === 2 && strlen($ack[1]) >= 2 && ord($ack[1][1]) === 0) {
            $pakete = array_chunk(array_keys($soll), 50);
            $kennung = 0;
            foreach ($pakete as $teil) {
                $kennung++;
                $sub = pack('n', $kennung);
                foreach ($teil as $t) { $sub .= $zk($t) . chr(0); }
                @fwrite($s, chr(0x82) . $laenge(strlen($sub)) . $sub);
            }
            $bestaetigt = 0;
            $ende = microtime(true) + 3.0;
            while (microtime(true) < $ende) {
                $pk = $paket();
                if ($pk === null) { break; }           // Zeitablauf: nichts mehr gekommen
                $art = $pk[0] >> 4;
                if ($art === 9) {
                    /* Hinter der Paketkennung je Filter ein Rueckgabebyte, in der
                       Reihenfolge des SUBSCRIBE mit dieser Kennung; ab 0x80 heisst
                       abgelehnt (etwa durch eine ACL). Danach schickt der Broker
                       nichts - ein bloss gezaehltes SUBACK hiesse dann "nichts
                       belegt", und der Merker laege auf einer Antwort, die keine
                       war (AWM-Abfuhr 1.4.14; hier Faelle R18 und R19). Ein
                       abgelehntes oder unpassendes SUBACK zaehlt nicht, die
                       Rueckfrage endet "nicht zu fragen". */
                    $rc = (string) substr($pk[1], 2);
                    $nr = (strlen($pk[1]) >= 2) ? (int) unpack('n', substr($pk[1], 0, 2))[1] : 0;
                    if (!isset($pakete[$nr - 1]) || strlen($rc) !== count($pakete[$nr - 1])) { break; }
                    $abgelehnt = false;
                    for ($i = 0; $i < strlen($rc); $i++) {
                        if (ord($rc[$i]) >= 0x80) { $abgelehnt = true; }
                    }
                    if ($abgelehnt) { break; }
                    $bestaetigt++;
                    // Zurueckbehaltenes kommt unmittelbar nach dem SUBACK.
                    if ($bestaetigt >= count($pakete)) {
                        $ende = min($ende, microtime(true) + 1.0);
                    }
                } elseif ($art === 3 && strlen($pk[1]) >= 2) {
                    $tl = unpack('n', substr($pk[1], 0, 2));
                    $t = substr($pk[1], 2, $tl[1]);
                    $versatz = 2 + $tl[1] + ((($pk[0] >> 1) & 3) > 0 ? 2 : 0);
                    $wert = (string) substr($pk[1], $versatz);
                    if (isset($soll[$t]) && ($pk[0] & 1) && $wert !== '') {
                        $aus['belegt'][$t] = true;
                    }
                }
            }
            if ($bestaetigt >= count($pakete)) { $aus['lage'] = 'ok'; }
        }
        @fwrite($s, chr(0xE0) . chr(0));
    }
    fclose($s);
    return $aus;
}

/**
 * Welche Altwerte muessen in diesem Lauf abgeraeumt werden?
 *
 * Rueckgabe array('lage' => 'erledigt'|'belegt'|'unbekannt',
 *                 'themen' => array(<feldname>, ...)).
 *
 * Je Lauf, bis der Merker liegt:
 *   1. den Broker nach allen Themen aus abfahrt_mqtt_frueher_behalten()
 *      unter dem eingestellten Praefix fragen;
 *   2. keines belegt -> Merker schreiben, nichts abraeumen ('erledigt');
 *      einige belegt -> genau diese abraeumen, kein Merker ('belegt'),
 *      hoechstens dreimal am Tag je Praefix (.mqtt_altlast_belegt, seit 1.6.22);
 *      nicht zu fragen -> alle, aber hoechstens EINMAL AM TAG je Praefix
 *      (Merker .mqtt_altlast_versucht, M3 seit 1.6.16; bis 1.6.15 in jedem
 *      Lauf vier leere retain-Datagramme), kein Merker ('unbekannt').
 * Der Dienst schickt die genannten Themen in diesem Lauf mit, auch wenn sich
 * ihr Wert nicht geaendert hat, jeweils mit der leeren retain-Nutzlast
 * unmittelbar davor. Ueber den UDP-Eingang gibt es keinen Merker auf den
 * Sendeerfolg (Regeln/07, Nachtrag 19.09.2026; Auftrag der Nachlese,
 * berichtigt 25.09.2026). Der Merker traegt die Kennung
 * "leer-bestaetigt <praefix>: <Themenliste>" - ein anderer Inhalt, ein
 * anderes Praefix, eine andere Liste gilt nicht, ebenso wenig ein Merker, den
 * eine Vorfassung angelegt haette. Er liegt im Datenordner;
 * purge_installation raeumt ihn bei jedem Upgrade mit ab, dann wird genau
 * einmal nachgefragt. Bauart awm_mqtt_altlast() aus AWM-Abfuhr 1.4.13.
 */
function abfahrt_mqtt_altlast(array $abfcfg)
{
    static $cache = array();
    $praefix = (string) $abfcfg['mqtt_topic'];
    if (isset($cache[$praefix])) { return $cache[$praefix]; }
    $liste = abfahrt_mqtt_frueher_behalten();
    $p = abfahrt_paths();
    if ($p['lbhome'] === '' || !$liste) {
        return $cache[$praefix] = array('lage' => 'erledigt', 'themen' => array());
    }
    $merker = $p['data'] . '/.mqtt_altlast_geraeumt';
    $kennung = 'leer-bestaetigt ' . $praefix . ': ' . implode(' ', $liste);
    if (is_file($merker) && trim((string) @file_get_contents($merker)) === $kennung) {
        return $cache[$praefix] = array('lage' => 'erledigt', 'themen' => array());
    }
    /* M3 (Durchgang 29.09.2026): War der Broker heute schon einmal nicht zu
     * fragen, wird heute weder gefragt noch abgeraeumt. Bis 1.6.15 gingen dann
     * in JEDEM Rechenlauf vier leere retain-Nutzlasten vor OK, FEHLER, AUDIO und
     * PUSH hinaus (gemessen, MQTT-Pruefer M3); geht das Wert-Datagramm
     * dahinter verloren, steht der Eingang leer. */
    $versucht = $p['data'] . '/.mqtt_altlast_versucht';
    $heute = 'versucht ' . date('Y-m-d') . ' ' . $praefix;
    if (is_file($versucht) && trim((string) @file_get_contents($versucht)) === $heute) {
        return $cache[$praefix] = array('lage' => 'unbekannt', 'themen' => array());
    }
    /* Ebenso gedrosselt: der Zweig 'belegt', hoechstens drei Versuche je Tag
     * und Praefix. Bis 1.6.21 fragte der Dienst jede Minute und schickte jede
     * Minute leere Nutzlasten samt Werten fuer OK, FEHLER, AUDIO und PUSH,
     * wenn ein Altwert immer wieder auftauchte (etwa von einem zweiten
     * Absender) - der Eingang in Loxone flackerte (Pruefung 02.10.2026, Nr. 24). */
    $belegt = $p['data'] . '/.mqtt_altlast_belegt';
    $bel_n = 0;
    if (is_file($belegt) && preg_match('/^belegt ' . preg_quote(date('Y-m-d') . ' ' . $praefix, '/') . ' ([0-9]+)\z/',
            trim((string) @file_get_contents($belegt)), $bel_m)) {
        $bel_n = (int) $bel_m[1];
    }
    if ($bel_n >= 3) {
        abfahrt_log_gedrosselt('mqtt_altlast_belegt', 'MQTT: unter ' . $praefix . '/ stehen nach drei '
            . 'Raeumversuchen heute noch frueher zurueckbehaltene Werte im Broker - sendet sie ein anderer '
            . 'Absender? Der naechste Versuch folgt morgen. Von Hand: mosquitto_pub -r -n -t <thema>', 86400);
        return $cache[$praefix] = array('lage' => 'belegt', 'themen' => array());
    }
    $voll = array();
    foreach ($liste as $t) { $voll[] = $praefix . '/' . $t; }
    $f = abfahrt_mqtt_behalten_liste($voll);
    if ($f['lage'] === 'ok' && !$f['belegt']) {
        if (!is_dir($p['data'])) { @mkdir($p['data'], 0775, true); }
        if (@file_put_contents($merker, $kennung . "\n") === false) {
            abfahrt_log_gedrosselt('mqtt_merker', 'MQTT: Der Merker ' . $merker . ' liess sich nicht schreiben - '
                . 'der Broker wird im naechsten Lauf wieder gefragt.', 3600);
        } else {
            abfahrt_log('MQTT: unter ' . $praefix . '/ steht keines der ' . count($liste)
                . ' frueher zurueckbehaltenen Themen mehr im Broker (vom Broker bestaetigt).');
        }
        return $cache[$praefix] = array('lage' => 'erledigt', 'themen' => array());
    }
    if ($f['lage'] === 'ok') {
        $l = strlen($praefix) + 1;
        $t = array();
        foreach (array_keys($f['belegt']) as $v) { $t[] = substr($v, $l); }
        if (!is_dir($p['data'])) { @mkdir($p['data'], 0775, true); }
        @file_put_contents($belegt, 'belegt ' . date('Y-m-d') . ' ' . $praefix . ' ' . ($bel_n + 1) . "\n");
        return $cache[$praefix] = array('lage' => 'belegt', 'themen' => $t);
    }
    if (!is_dir($p['data'])) { @mkdir($p['data'], 0775, true); }
    @file_put_contents($versucht, $heute . "\n");
    abfahrt_log_gedrosselt('mqtt_rueckfrage', 'MQTT: Der Broker liess sich nicht befragen, ob unter '
        . $praefix . '/ noch frueher zurueckbehaltene Werte stehen. Sie werden in diesem Lauf '
        . 'unmittelbar vor dem Wert geloescht; der naechste Versuch folgt fruehestens morgen.', 86400);
    return $cache[$praefix] = array('lage' => 'unbekannt', 'themen' => $liste);
}

/**
 * Aus der Deinstallation (abfahrt_dienst.php --mqtt-leeren): die
 * zurueckbehaltenen Themen der Linie leeren - unter dem eingestellten Praefix.
 *
 * Der Weg ist derselbe wie beim Senden - der UDP-Eingang des Gateways,
 * "retain <thema> " mit leerer Nutzlast (am Geraet belegt: die leere
 * Nachricht geht als Loeschung an den Broker, Regeln/07, Nachtraege vom
 * 19.09.2026). VOR der ersten Runde und nach jeder wird der Broker gefragt
 * (abfahrt_mqtt_behalten_liste()); hinaus geht nur, was dort noch steht,
 * hoechstens $runden Runden. Ist der Broker nicht zu fragen, gehen alle
 * Themen in jeder Runde hinaus, und die Ausgabe sagt, dass nicht nachgelesen
 * wurde - der Eingang verwirft unter Last Datagramme (Regeln/07), ein blosses
 * Senden ist kein Beleg. Geleert wird auch bei ausgeschaltetem MQTT: die
 * Altwerte koennen aus der Zeit stammen, als es an war.
 *
 * Bis 1.6.12 raeumte die Deinstallation nichts ab: die zurueckbehaltenen
 * Themen blieben im Broker, und nach jedem Neustart von Broker oder Gateway
 * bekam der Miniserver sie wieder - von einem Plugin, das es nicht mehr gibt
 * (in WSL gemessen, Pruefung-Abfahrtsassistent-1.6.13, Faelle U1 bis U7).
 * Bauart awm_mqtt_leeren() aus AWM-Abfuhr 1.4.13.
 *
 * Liest die Konfiguration ohne Selbstheilung und schreibt weder Protokoll
 * noch Datei. Ausgabe im Format der Hakenskripte (<OK>/<INFO>/<WARNING>).
 * Rueckgabe 0 geleert oder nicht nachpruefbar, 1 es steht noch etwas bzw.
 * der Eingang war nicht erreichbar, 2 nicht moeglich.
 */
function abfahrt_mqtt_leeren($runden = 3, $pause = 1.0)
{
    /* Seit 1.6.16 ein Mantel um abfahrt_mqtt_leeren_lauf() (M1): dieselbe
     * Arbeit raeumt jetzt auch beim Abschalten von MQTT und beim
     * Praefixwechsel das BISHERIGE Praefix. Die Ausgabe der Deinstallation
     * bleibt Wort fuer Wort dieselbe. */
    $l = abfahrt_mqtt_leeren_lauf(null, $runden, $pause);
    foreach ($l['zeilen'] as $z) { echo $z; }
    return $l['rc'];
}

/**
 * Die Arbeit von abfahrt_mqtt_leeren() fuer ein beliebiges Praefix (M1).
 * $basis null heisst: das eingestellte. Rueckgabe array('rc', 'zustand',
 * 'zeilen', 'offen') mit zustand keine_wurzel | kein_port | nichts |
 * kein_eingang | geleert | rest | nicht_nachgelesen.
 */
function abfahrt_mqtt_leeren_lauf($basis = null, $runden = 3, $pause = 1.0)
{
    $aus = array('rc' => 0, 'zustand' => '', 'zeilen' => array(), 'offen' => array());
    $p = abfahrt_paths();
    if ($p['lbhome'] === '') {
        $aus['zeilen'][] = "<WARNING> MQTT: kein LoxBerry-Wurzelverzeichnis (oder ein ausgepacktes Archiv) - "
           . "zurueckbehaltene Themen wurden nicht geleert.\n";
        $aus['rc'] = 2; $aus['zustand'] = 'keine_wurzel';
        return $aus;
    }
    if ($basis === null) {
        $cfg = abfahrt_config();
        $basis = (string) $cfg['mqtt_topic'];
    }
    $basis = (string) $basis;
    $gen = @json_decode((string) @file_get_contents($p['general']), true);
    $udpport = 0;
    if (isset($gen['Mqtt']['Udpinport'])) { $udpport = (int) $gen['Mqtt']['Udpinport']; }
    if (!$udpport && isset($gen['mqtt']['udpinport'])) { $udpport = (int) $gen['mqtt']['udpinport']; }
    if (!$udpport) {
        $aus['zeilen'][] = "<INFO> MQTT: in der general.json steht kein UDP-Eingangsport des Gateways - "
           . "zurueckbehaltene Themen unter " . $basis . "/ wurden nicht geleert.\n";
        $aus['rc'] = 2; $aus['zustand'] = 'kein_port';
        return $aus;
    }
    $alle = array();
    foreach (abfahrt_mqtt_leer_themen() as $t) { $alle[] = $basis . '/' . $t; }
    $n = count($alle);
    $f = abfahrt_mqtt_behalten_liste($alle);
    $nachgelesen = ($f['lage'] === 'ok');
    $offen = $nachgelesen ? array_keys($f['belegt']) : $alle;
    if ($nachgelesen && !$offen) {
        $aus['zeilen'][] = "<OK> MQTT: der Broker bestaetigt: keines der " . $n . " Themen unter " . $basis
           . "/ steht zurueckbehalten - nichts zu leeren.\n";
        $aus['zustand'] = 'nichts';
        return $aus;
    }
    $strom = @stream_socket_client('udp://127.0.0.1:' . (int) $udpport, $errno, $errstr, 2);
    if (!$strom) {
        $aus['zeilen'][] = "<WARNING> MQTT: der UDP-Eingang des Gateways war nicht erreichbar - "
           . "zurueckbehaltene Themen unter " . $basis . "/ wurden nicht geleert.\n";
        $aus['rc'] = 1; $aus['zustand'] = 'kein_eingang'; $aus['offen'] = $offen;
        return $aus;
    }
    $zu_leeren = count($offen);
    $datagramme = 0;
    for ($r = 1; $r <= max(1, (int) $runden) && $offen; $r++) {
        if ($r > 1) { usleep((int) ($pause * 1000000)); }
        foreach ($offen as $i => $t) {
            if ($i > 0) { usleep(ABFAHRT_MQTT_PAUSE_US); }
            // Ein Leerzeichen hinter dem Thema, sonst keine Nutzlast: die
            // Form, die das Gateway als Loeschung liest.
            @fwrite($strom, 'retain ' . $t . ' ');
            $datagramme++;
        }
        usleep(300000);     // dem Gateway Zeit bis zum Broker lassen
        $f = abfahrt_mqtt_behalten_liste($offen);
        if ($f['lage'] === 'ok') {
            $nachgelesen = true;
            $offen = array_keys($f['belegt']);
        } else {
            $nachgelesen = false;
        }
    }
    fclose($strom);
    $aus['zeilen'][] = "<INFO> MQTT: " . $zu_leeren . " von " . $n . " Themen unter " . $basis . "/ mit leerer "
       . "Nutzlast an den UDP-Eingang " . (int) $udpport . " des Gateways gesendet ("
       . $datagramme . " Datagramme).\n";
    if ($nachgelesen && !$offen) {
        $aus['zeilen'][] = "<OK> MQTT: der Broker bestaetigt: keines der " . $n . " Themen steht mehr "
           . "zurueckbehalten.\n";
        $aus['zustand'] = 'geleert';
        return $aus;
    }
    if ($nachgelesen) {
        $aus['zeilen'][] = "<WARNING> MQTT: " . count($offen) . " Themen stehen noch zurueckbehalten im Broker ("
           . implode(', ', $offen) . "). Von Hand: mosquitto_pub -r -n -t <thema>\n";
        $aus['rc'] = 1; $aus['zustand'] = 'rest'; $aus['offen'] = $offen;
        return $aus;
    }
    $aus['zeilen'][] = "<INFO> MQTT: der Broker liess sich nicht befragen - nicht nachgelesen. Der UDP-Eingang "
       . "verwirft unter Last Datagramme; was stehen bleibt, laesst sich mit "
       . "mosquitto_pub -r -n -t <thema> von Hand loeschen.\n";
    $aus['zustand'] = 'nicht_nachgelesen';
    return $aus;
}

/**
 * M1 (Durchgang 29.09.2026): Beim Abschalten von MQTT und beim
 * Praefixwechsel die Themen unter dem BISHERIGEN Praefix raeumen - falls dort
 * etwas zurueckbehalten stehen kann. Heute geht kein Thema retained hinaus;
 * stehen koennen nur die Altwerte aus 1.6.8 bis 1.6.12
 * (abfahrt_mqtt_frueher_behalten()). Hat der Broker fuer dieses Praefix
 * schon bestaetigt, dass keiner mehr dasteht (Merker von
 * abfahrt_mqtt_altlast()), wird nichts gesendet und nichts gefragt. Bis 1.6.15
 * blieben sie beim Praefixwechsel unter dem alten Praefix stehen.
 * Rueckgabe: der Satz fuer die Oberflaeche (auch ins Protokoll).
 */
function abfahrt_mqtt_praefix_raeumen($praefix, $anlass)
{
    $p = abfahrt_paths();
    if ($p['lbhome'] === '') { return ''; }
    $was = abfahrt_t($anlass === 'aus' ? 'MQTT.M_ANLASS_AUS' : 'MQTT.M_ANLASS_WECHSEL');
    $heute = false;
    foreach (array_keys(abfahrt_felder()) as $k) {
        if (abfahrt_feld_retain($k)) { $heute = true; }
    }
    $liste = abfahrt_mqtt_frueher_behalten();
    $merker = $p['data'] . '/.mqtt_altlast_geraeumt';
    $kennung = 'leer-bestaetigt ' . $praefix . ': ' . implode(' ', $liste);
    if (!$heute && (!$liste || (is_file($merker) && trim((string) @file_get_contents($merker)) === $kennung))) {
        $text = sprintf(abfahrt_t('MQTT.M_RAEUMEN_NICHTS'), $was, $praefix);
    } else {
        $l = abfahrt_mqtt_leeren_lauf($praefix, 3, 1.0);
        $schl = array('nichts' => 'MQTT.M_RAEUMEN_BESTAETIGT', 'geleert' => 'MQTT.M_RAEUMEN_GELEERT',
                      'rest' => 'MQTT.M_RAEUMEN_REST', 'nicht_nachgelesen' => 'MQTT.M_RAEUMEN_UNBEKANNT',
                      'kein_port' => 'MQTT.M_RAEUMEN_KEIN_PORT', 'kein_eingang' => 'MQTT.M_RAEUMEN_KEIN_EINGANG');
        $k = isset($schl[$l['zustand']]) ? $schl[$l['zustand']] : 'MQTT.M_RAEUMEN_UNBEKANNT';
        $text = sprintf(abfahrt_t($k), $was, $praefix, implode(', ', $l['offen']));
    }
    abfahrt_log('MQTT: ' . strip_tags($text));
    return $text;
}

/**
 * Das Lebenszeichen: bei JEDEM Cron-Lauf, nie retained, am
 * Doppelt-senden-Filter vorbei (Hausstandard, Regeln/07).
 *
 *   <thema>/status/ts       Unix-Sekunden dieses Laufs. In Loxone:
 *                           Alter = (Loxone-Zeit + 1230768000) - ts
 *   <thema>/status/zaehler  laeuft 0..999 um - bleibt er stehen, steht der Dienst
 *   <thema>/status/ok       1 = die letzte Berechnung ist hoechstens 11 Minuten alt
 *
 * Einen Dauerlaeufer gibt es nicht, also kein viertes Thema.
 */
function abfahrt_lebenszeichen(array $abfcfg, array $st) {
    $datei = abfahrt_tmpdir() . '/zaehler.txt';
    $n = is_file($datei) ? (int) trim((string) @file_get_contents($datei)) : -1;
    $n = ($n + 1) % 1000;
    @file_put_contents($datei, (string) $n);
    // Nr. 18: ein Stand aus der Zukunft (Uhr zurueckgesprungen) ist nicht frisch.
    $frisch = ((int) $st['zeit'] > 0 && (int) $st['zeit'] <= time() + 60
               && time() - (int) $st['zeit'] <= 660) ? 1 : 0;
    $raus = abfahrt_mqtt_senden(array(), $abfcfg, abfahrt_lebenszeichen_werte($n, $frisch));
    /* M4 (Durchgang 29.09.2026): Die Marke fuer den Reiter Test entsteht nur,
     * wenn wirklich abgeschickt wurde. Bis 1.6.15 las der Reiter die
     * Aenderungszeit von zaehler.txt, die VOR dem Senden und unabhaengig vom
     * Ergebnis geschrieben wird: ohne Udpinport gingen 0 Datagramme hinaus,
     * und die Zeile zeigte einen Haken (gemessen, MQTT-Pruefer M4). */
    if ($raus !== false && (int) $raus > 0) {
        @touch(abfahrt_lebenszeichen_marke());
    }
    return $raus;
}

/**
 * Die Themen des Lebenszeichens mit ihrer Bedeutung - EINE Stelle fuer den
 * Sender und die beiden Tabellen der Oberflaeche (M6). Bis 1.6.15 standen sie
 * an drei Stellen woertlich.
 */
function abfahrt_lebenszeichen_themen() {
    return array(
        'status/ts'      => 'MQTT.LZ_TS',
        'status/zaehler' => 'MQTT.LZ_ZAEHLER',
        'status/ok'      => 'MQTT.LZ_OK',
    );
}

/** Der Sendecode des Lebenszeichens: Thema => Wert, je Thema aus der Liste. */
function abfahrt_lebenszeichen_werte($n, $frisch) {
    $aus = array();
    foreach (array_keys(abfahrt_lebenszeichen_themen()) as $t) {
        switch ($t) {
            case 'status/ts':      $aus[$t] = time(); break;
            case 'status/zaehler': $aus[$t] = (int) $n; break;
            case 'status/ok':      $aus[$t] = $frisch ? 1 : 0; break;
        }
    }
    return $aus;
}

/** Die Marke "Lebenszeichen wirklich abgeschickt" (M4). */
function abfahrt_lebenszeichen_marke() {
    return abfahrt_tmpdir(false) . '/lebenszeichen.gesendet';
}

/**
 * Die Abo-Datei des MQTT-Gateways: config/plugins/<ordner>/mqtt_subscriptions.cfg
 * (M5, Bauform eb_abo_datei() aus Einspeisebremse 0.9.28).
 *
 * Das Gateway (V1) liest sie selbst und abonniert jede Zeile (Regeln/07, am
 * Geraet belegt am 13.09.2026). Das Archiv bringt sie mit dem Vorgabepraefix
 * abfahrt/# mit; das Praefix ist einstellbar, deshalb wird sie beim Speichern
 * und im Takt des Dienstes nachgefuehrt, nur wenn sie abweicht. Bis 1.6.15
 * gab es die Datei nicht, und unter V1 kam ohne Handeintrag nichts an.
 * Rueckgabe: array(Pfad, traegt das Abo).
 */
function abfahrt_abo_datei($praefix, $schreiben = false)
{
    $p = abfahrt_paths();
    $dir = dirname($p['config']);
    $pfad = $dir . '/mqtt_subscriptions.cfg';
    $soll = abfahrt_mqtt_praefix_norm($praefix) . '/#';     // Nr. 15: dieselbe Form wie beim Senden
    if ($p['lbhome'] === '') { return array($pfad, false); }
    $roh = is_readable($pfad) ? (string) @file_get_contents($pfad) : '';
    $da = in_array($soll, array_map('trim', preg_split('/\r?\n/', $roh)), true);
    if ($schreiben && $roh !== $soll . "\n" && is_dir($dir)) {
        if (abfahrt_cache_schreiben($pfad, $soll . "\n")) {
            @chmod($pfad, 0644);
            abfahrt_log('MQTT: Gateway-Abo nachgefuehrt: ' . $soll . ' (mqtt_subscriptions.cfg)');
            $da = true;
        } else {
            abfahrt_log_gedrosselt('abo_datei', 'MQTT: ' . $pfad . ' liess sich nicht schreiben - das Gateway (V1) '
                . 'abonniert ' . $soll . ' dann nicht von selbst.', 3600);
        }
    }
    return array($pfad, $da);
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
    /* Aufbau nach den massgeblichen Ausfuhren von Loxone Config
     * (VI_Rasenmaeher und VI_Marstek, 12.08.2026): HintText als erstes
     * Wurzelattribut, <Info> als erstes Kind, je Befehl Unit und HintText
     * hinter MaxVal. Bis 1.6.9 fehlten alle vier; in der App stand eine nackte
     * Zahl statt "12 min". */
    $o .= '<VirtualInHttp HintText="" ';
    $o .= 'Title="' . abfahrt_x($kopf['title']) . '" ';
    $o .= 'Comment="' . abfahrt_x($kopf['comment'] ?? '') . '" ';
    $o .= 'Address="' . abfahrt_x($kopf['address'] ?? '') . '" ';
    $o .= 'PollingTime="' . abfahrt_x($kopf['polling'] ?? '60') . '"';
    $o .= '>' . $crlf;
    $o .= "\t" . '<Info templateType="2" minVersion="17010727"/>' . $crlf;
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
        $o .= 'DefVal="' . (int) ($c['defval'] ?? 0) . '" ';
        $o .= 'MinVal="' . (int) $c['min'] . '" ';
        $o .= 'MaxVal="' . (int) $c['max'] . '" ';
        $o .= 'Unit="' . abfahrt_x($c['unit'] ?? '<v>') . '" ';
        $o .= 'HintText=""';
        $o .= '/>' . $crlf;
    }
    $o .= '</VirtualInHttp>' . $crlf;
    return $o;
}

/** [Dateiname, Inhalt] der Importdatei fuer Loxone Config. */
function abfahrt_vorlage($host = '') {
    if ($host === '') { $host = gethostname() ?: 'loxberry'; }
    /* U2 (Durchgang 29.09.2026): der Ordner aus abfahrt_paths(), derselben
     * Quelle wie die Seite. Bis 1.6.15 stand hier getenv('LBPPLUGINDIR') mit
     * festem Rueckfall; Apache reicht die Variable nicht durch, und bei einer
     * zweiten Installation (<ordner>_01) zeigte die Vorlage auf die erste. */
    $abf_pv = abfahrt_paths();
    $plugindir = $abf_pv['plugin'];
    $cmds = [];
    foreach (abfahrt_felder() as $name => $d) {
        list($analog, $min, $max) = $d;
        /* Der Comment wird beim Import zum Kachelnamen - daher der kurze
         * Name (hoechstens 40 Zeichen), nicht die Erklaerung aus FELD.*.
         * Bis 1.6.9 trug ANKUNFT hier einen Satz mit 100 Zeichen. */
        $einheit = (string) $d[6];
        $nachkomma = ($name === 'FAHRT') ? 1 : 0;
        $cmds[] = [
            'title'   => 'ABFAHRT_' . $name,
            'comment' => trim(strip_tags(html_entity_decode(abfahrt_t('FELDKURZ.' . $name), ENT_QUOTES, 'UTF-8'))),
            'check'   => abfahrt_suchtext($name),
            'analog'  => $analog, 'min' => $min, 'max' => $max,
            'defval'  => (int) $d[7],
            'unit'    => '<v.' . $nachkomma . '>' . ($einheit !== '' ? ' ' . $einheit : ''),
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
        'comment' => abfahrt_t('LOX.VORLAGE_KOMMENTAR') . ' (' . date('d.m.Y') . ')',
    ], $cmds)];
}

/**
 * Virtueller Ausgang (U7, Durchgang 29.09.2026) - Aufbau nach der Ausfuhr
 * "VO_Rasenmaeher steuern (LoxBerry-Plugin)_Test.xml" aus Loxone Config
 * (Regeln/07): HintText vorn, CmdInit/CloseAfterSend/CmdSep an der Wurzel,
 * Info templateType 3, je Befehl die Attribute in der Reihenfolge der Ausfuhr.
 */
function abfahrt_xml_virtual_out($kopf, $cmds) {
    $crlf = "\r\n";
    $o = '<?xml version="1.0" encoding="utf-8"?>' . $crlf;
    $o .= '<VirtualOut HintText="" ';
    $o .= 'Title="' . abfahrt_x($kopf['title']) . '" ';
    $o .= 'Comment="' . abfahrt_x($kopf['comment'] ?? '') . '" ';
    $o .= 'Address="' . abfahrt_x($kopf['address'] ?? '') . '" ';
    $o .= 'CmdInit="" CloseAfterSend="true" CmdSep="">' . $crlf;
    $o .= "\t" . '<Info templateType="3" minVersion="17010727"/>' . $crlf;
    foreach ($cmds as $c) {
        $o .= "\t" . '<VirtualOutCmd ';
        $o .= 'Title="' . abfahrt_x($c['title']) . '" ';
        $o .= 'Comment="' . abfahrt_x($c['comment']) . '" ';
        $o .= 'CmdOnMethod="GET" CmdOffMethod="GET" ';
        $o .= 'CmdOn="' . abfahrt_x($c['on']) . '" ';
        $o .= 'CmdOnHTTP="" CmdOnPost="" ';
        $o .= 'CmdOff="' . abfahrt_x($c['off'] ?? '') . '" ';
        $o .= 'CmdOffHTTP="" CmdOffPost="" CmdAnswer="" ';
        $o .= 'Analog="false" Repeat="0" RepeatRate="0" HintText=""';
        $o .= '/>' . $crlf;
    }
    $o .= '</VirtualOut>' . $crlf;
    return $o;
}

/**
 * [Dateiname, Inhalt] der Vorlage der Steuerbefehle (U7): die Ansage ueber
 * termin_say.php mit dem Merkwort. Regeln/04 ("Alles auf einmal anlegen"):
 * ein schaltendes Plugin bekommt neben der Eingangsvorlage einen zweiten
 * Knopf; bis 1.6.15 stand die Adresse samt 24-stelligem Merkwort nur als
 * Tabelle zum Abtippen da. Die Datei traegt das Merkwort.
 */
function abfahrt_vorlage_ausgang($host = '') {
    if ($host === '') { $host = gethostname() ?: 'loxberry'; }
    $abf_pa = abfahrt_paths();
    $abf_cfg = abfahrt_config();
    return ['VO_abfahrtsassistent.xml', abfahrt_xml_virtual_out([
        'title'   => 'Abfahrts-Assistent Ansage',
        'address' => 'http://' . $host
                   . ((strpos($host, ':') === false && abfahrt_webport() !== 80) ? ':' . abfahrt_webport() : ''),
        'comment' => abfahrt_t('LOX.VO_KOMMENTAR') . ' (' . date('d.m.Y') . ')',
    ], [[
        'title'   => 'Abfahrt: Ansage',
        'comment' => trim(strip_tags(html_entity_decode(abfahrt_t('LOX.VO_BEFEHL'), ENT_QUOTES, 'UTF-8'))),
        'on'      => '/plugins/' . $abf_pa['plugin'] . '/termin_say.php?token='
                   . rawurlencode((string) $abf_cfg['aktionstoken']),
    ]])];
}

/** U1: Eine Kalenderadresse gekuerzt zeigen - Rechner und Anfang des Pfads,
 *  nie den privaten Teil (der steht bei Google und Nextcloud weiter hinten). */
function abfahrt_adresse_kurz($url)
{
    $u = @parse_url((string) $url);
    if (!is_array($u) || empty($u['host'])) { return '…'; }
    $pfad = isset($u['path']) ? (string) $u['path'] : '';
    $anfang = function_exists('mb_substr') ? mb_substr($pfad, 0, 10, 'UTF-8') : substr($pfad, 0, 10);
    return $u['host'] . (isset($u['port']) ? ':' . (int) $u['port'] : '') . $anfang . '…';
}

/* ==================================================================
 * Selbstpruefung
 *
 * Beantwortet OHNE Loxone: traegt die Einrichtung? Von unten nach oben -
 * der erste Kreuz-Eintrag ist in aller Regel die Ursache.
 * ================================================================== */

/* $oberflaeche (U6): array('quelle' => Quelltext von index.php,
 * 'muster' => Positivliste der Reiter) - nur dann gibt es die zwei Zeilen,
 * die in der Oberflaeche zaehlen. */
function abfahrt_pruefungen(?array $abfcfg = null, array $oberflaeche = array()) {
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

    /* Letztes Ergebnis. Ohne Stand neutral: bis 1.6.21 stand hier ein rotes
     * "FEHLER=0: " ohne Grund, solange der Dienst noch nie gerechnet hatte
     * (Pruefung 02.10.2026, Nr. 20). */
    if ((int) $st['zeit'] <= 0) {
        $zeile(-1, abfahrt_t('TEST.F_ERGEBNIS'),
            sprintf(abfahrt_t('TEST.A_ERGEBNIS_FEHLER'), 9, abfahrt_e(abfahrt_grund_text('STAND_FEHLT'))));
    } elseif ((int) $st['ok'] === 1 && (int) $st['fehler'] === 0) {
        $zeile(1, abfahrt_t('TEST.F_ERGEBNIS'),
            sprintf(abfahrt_t('TEST.A_ERGEBNIS_OK'), abfahrt_e($st['titel']),
                    (int) $st['minstart'], 0 + $st['fahrt'], (int) $st['abfahrt_in']));
    } elseif ((int) $st['fehler'] === 7) {
        $zeile(-1, abfahrt_t('TEST.F_ERGEBNIS'), abfahrt_t('TEST.A_ERGEBNIS_VERALTET'));
    } else {
        $zeile((int) $st['fehler'] === 4 ? -1 : 0, abfahrt_t('TEST.F_ERGEBNIS'),
            sprintf(abfahrt_t('TEST.A_ERGEBNIS_FEHLER'), (int) $st['fehler'],
                    abfahrt_e(abfahrt_stand_grund($st))));     // b1
    }

    /* Sondertage */
    $tag = abfahrt_daytype();
    $zeile($tag['quelle'] === 'keine' ? -1 : 1, abfahrt_t('TEST.F_FERIEN'),
        $tag['quelle'] === 'keine' ? abfahrt_t('TEST.A_FERIEN_KEINS')
                                   : sprintf(abfahrt_t('TEST.A_FERIEN_OK'), abfahrt_e($tag['quelle'])));

    /* Audioausgabe */
    $why = '';
    $erlaubt = abfahrt_audio_allowed($abfcfg, $why);
    /* Nr. 36 b, Stufe 2: eine Zeile fuer alle Ausgabearten (ansage_pruefzeile()). Alexa-NG
     * und Chromecast werden nur bei offenem Reiter Test mit selftest=1 gefragt (spricht
     * nicht), der Music Server nie - eine Probe dort spraeche. Dazu die letzte Ansage.
     * Stand -2 (aus, nicht gefragt) erscheint wie bisher als "i". */
    list($abf_ps, $abf_pt) = ansage_pruefzeile($abfcfg['tts'], !empty($oberflaeche['test_offen']), abfahrt_ansage_k());
    $zeile($abf_ps === -2 ? -1 : $abf_ps, abfahrt_t('TEST.F_AUDIO'), $abf_pt);
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

    /* Konfiguration heil und vollstaendig? (neu in 1.6.10)
     * Liest nur, was abfahrt_config() eben festgestellt hat - kein
     * Schreibzugriff im Seitenaufbau. */
    abfahrt_config();
    $lage = abfahrt_config_lage();
    /* U4 (Durchgang 29.09.2026): Die Zeile nennt den Zustand VOR der
     * Selbstheilung (abfahrt_config_erstbefund(), Regeln/05). Bis 1.6.15 las
     * sie nur den Zustand danach: im Aufruf, der eine abgeschnittene Datei
     * beiseitelegte und aus der Zweitschrift ueberschrieb, stand ein Haken. */
    $abf_eb = abfahrt_config_erstbefund();
    $abf_ebs = $abf_eb['schritte'];
    if (in_array('kaputt', $abf_ebs, true) || in_array('kaputt_fest', $abf_ebs, true)) {
        $zeile(0, abfahrt_t('TEST.F_CFG'), sprintf(abfahrt_t(in_array('kaputt_fest', $abf_ebs, true)
                ? 'TEST.A_CFG_WAR_KAPUTT_FEST' : 'TEST.A_CFG_WAR_KAPUTT'),
            abfahrt_e($abf_eb['datei'] !== '' ? $abf_eb['datei'] : '?'),
            abfahrt_t(in_array('aus_zweit', $abf_ebs, true) ? 'TEST.A_CFG_ZWEIT_JA' : 'TEST.A_CFG_ZWEIT_NEIN')));
    } elseif (in_array('aus_zweit', $abf_ebs, true)) {
        $zeile(0, abfahrt_t('TEST.F_CFG'), abfahrt_t('TEST.A_CFG_WAR_LEER'));
    } elseif (in_array('token_aus_zweit', $abf_ebs, true)) {
        $zeile(0, abfahrt_t('TEST.F_CFG'), abfahrt_t('TEST.A_CFG_WAR_TOKEN'));
    } elseif ($lage['zustand'] === 'unlesbar') {
        $zeile(0, abfahrt_t('TEST.F_CFG'), sprintf(abfahrt_t('TEST.A_CFG_UNLESBAR'),
            abfahrt_e(abfahrt_datei_rechte($p['config']))));
    } elseif ($lage['zustand'] === 'kaputt') {
        $zeile(0, abfahrt_t('TEST.F_CFG'), abfahrt_t('TEST.A_CFG_KAPUTT'));
    } elseif ($lage['zustand'] !== 'ok') {
        $zeile(-1, abfahrt_t('TEST.F_CFG'), abfahrt_t('TEST.A_CFG_FEHLT'));
    } elseif ($lage['abgewiesen']) {
        $teile = array();
        foreach ($lage['abgewiesen'] as $k => $g) { $teile[] = abfahrt_e($k . ': ' . abfahrt_grund_text($g)); }
        $zeile(0, abfahrt_t('TEST.F_CFG'), sprintf(abfahrt_t('TEST.A_CFG_ABGEWIESEN'), implode('; ', $teile)));
    } elseif ($lage['fehlend']) {
        $zeile(-1, abfahrt_t('TEST.F_CFG'), sprintf(abfahrt_t('TEST.A_CFG_FEHLEND'),
            count($lage['fehlend']), abfahrt_e(implode(', ', $lage['fehlend']))));
    } elseif (in_array('vervollstaendigt', $abf_ebs, true)) {
        $zeile(-1, abfahrt_t('TEST.F_CFG'), sprintf(abfahrt_t('TEST.A_CFG_WAR_UNVOLLST'),
            count($abf_eb['fehlend']), abfahrt_e(implode(', ', $abf_eb['fehlend']))));
    } else {
        $zeile(1, abfahrt_t('TEST.F_CFG'), sprintf(abfahrt_t('TEST.A_CFG_OK'), count(abfahrt_vorgaben())));
    }

    /* Steht der Cron-Eintrag da, und ist er eine Datei? MarstekVenus hatte
     * an dieser Stelle monatelang ein Verzeichnis (Regeln/06). */
    $abf_pc = abfahrt_paths();
    $lb = $abf_pc['lbhome'];
    $ordner = $abf_pc['plugin'];
    if ($lb === '') {
        $zeile(-1, abfahrt_t('TEST.F_CRON'), abfahrt_t('TEST.A_CRON_UNBEKANNT'));
    } else {
        $cron = $lb . '/system/cron/cron.01min/' . $ordner;
        if (is_file($cron)) {
            $zeile(1, abfahrt_t('TEST.F_CRON'), sprintf(abfahrt_t('TEST.A_CRON_OK'), abfahrt_e($cron)));
        } elseif (file_exists($cron)) {
            $zeile(0, abfahrt_t('TEST.F_CRON'), sprintf(abfahrt_t('TEST.A_CRON_KEINE_DATEI'), abfahrt_e($cron)));
        } else {
            $zeile(0, abfahrt_t('TEST.F_CRON'), sprintf(abfahrt_t('TEST.A_CRON_FEHLT'), abfahrt_e($cron)));
        }
    }

    /* Geht das Lebenszeichen hinaus? Gelesen wird die Marke, die der Dienst
     * nur schreibt, wenn abfahrt_mqtt_senden() tatsaechlich abgeschickt hat
     * (M4; bis 1.6.15 stand hier zaehler.txt, das auch ohne Versand
     * fortgeschrieben wird). Ob es am Broker ankommt, kann das Plugin nicht
     * wissen (UDP, siehe abfahrt_mqtt_senden()). */
    $zdatei = abfahrt_lebenszeichen_marke();
    if (empty($abfcfg['mqtt_ein'])) {
        $zeile(-1, abfahrt_t('TEST.F_LEBENSZEICHEN'), abfahrt_t('TEST.A_MQTT_AUS'));
    } elseif (!is_file($zdatei)) {
        $zeile(0, abfahrt_t('TEST.F_LEBENSZEICHEN'), abfahrt_t('TEST.A_LEBENSZEICHEN_NIE'));
    } else {
        $za = time() - (int) @filemtime($zdatei);
        $zeile($za <= 150 ? 1 : 0, abfahrt_t('TEST.F_LEBENSZEICHEN'),
            sprintf(abfahrt_t($za <= 150 ? 'TEST.A_LEBENSZEICHEN_OK' : 'TEST.A_LEBENSZEICHEN_ALT'),
                    abfahrt_e($abfcfg['mqtt_topic']), $za));
    }

    /* M6 (Durchgang 29.09.2026): Stimmt die Themenliste mit dem Sendecode
     * ueberein - in beiden Richtungen (Regeln/04, Pflichtzeilen; Regeln/07).
     * Gesendet: die Felder, die abfahrt_werte() liefert und die ueber MQTT
     * gehen, dazu der Sendecode des Lebenszeichens. Gelistet: die Tabelle der
     * Oberflaeche (abfahrt_felder() Spalte 5 und abfahrt_lebenszeichen_themen()). */
    $abf_ges = array();
    foreach (abfahrt_werte(abfahrt_stand(), $abfcfg) as $abf_k => $abf_v) {
        if (abfahrt_feld_mqtt($abf_k)) { $abf_ges[] = $abf_k; }
    }
    foreach (array_keys(abfahrt_lebenszeichen_werte(0, 0)) as $abf_k) { $abf_ges[] = $abf_k; }
    $abf_lis = array();
    foreach (abfahrt_felder() as $abf_k => $abf_d) {
        if (!empty($abf_d[5])) { $abf_lis[] = $abf_k; }
    }
    foreach (array_keys(abfahrt_lebenszeichen_themen()) as $abf_k) { $abf_lis[] = $abf_k; }
    $abf_nur_ges = array_values(array_unique(array_diff($abf_ges, $abf_lis)));
    $abf_nur_lis = array_values(array_unique(array_diff($abf_lis, $abf_ges)));
    if (!$abf_ges || !$abf_lis) {
        $zeile(0, abfahrt_t('TEST.F_THEMEN'), abfahrt_t('TEST.A_THEMEN_LEER'));
    } elseif ($abf_nur_ges || $abf_nur_lis) {
        $zeile(0, abfahrt_t('TEST.F_THEMEN'), sprintf(abfahrt_t('TEST.A_THEMEN_FEHL'),
            abfahrt_e($abf_nur_ges ? implode(', ', $abf_nur_ges) : '-'),
            abfahrt_e($abf_nur_lis ? implode(', ', $abf_nur_lis) : '-')));
    } else {
        $zeile(1, abfahrt_t('TEST.F_THEMEN'), sprintf(abfahrt_t('TEST.A_THEMEN_OK'),
            count(array_unique($abf_lis))));
    }

    /* Trifft jeder Suchtext genau EINE Stelle der Antwortzeile? (Regeln/03)
     * Gemessen an der erzeugten Zeile, nicht am Quelltext: ohne das
     * fuehrende Semikolon faende FAHRT= auch die Stelle in ABFAHRT_IN=. */
    $abf_zeile = abfahrt_zeile(abfahrt_stand(), $abfcfg);
    $abf_mehr = array();
    foreach (array_keys(abfahrt_felder()) as $abf_f) {
        $abf_nadel = ';' . $abf_f . '=';
        if (substr_count($abf_zeile, $abf_nadel) !== 1) { $abf_mehr[] = $abf_f; }
    }
    $zeile($abf_mehr ? 0 : 1, abfahrt_t('TEST.F_SUCHTEXT'),
        $abf_mehr ? sprintf(abfahrt_t('TEST.A_SUCHTEXT_FEHL'), abfahrt_e(implode(', ', $abf_mehr)))
                  : sprintf(abfahrt_t('TEST.A_SUCHTEXT_OK'), count(abfahrt_felder())));

    /* Vorlage wohlgeformt - gehoert hierher, nicht erst in die Pruefung vor
       dem Ausliefern: eine kaputte Vorlage merkt der Anwender sonst erst in
       Loxone Config, und dort sucht er den Fehler bei sich. */
    $v = abfahrt_vorlage();
    $v2 = abfahrt_vorlage_ausgang();     // U7: jede erzeugbare Datei
    $alt = libxml_use_internal_errors(true);
    $gut = simplexml_load_string($v[1]) !== false && simplexml_load_string($v2[1]) !== false;
    libxml_clear_errors();
    libxml_use_internal_errors($alt);
    $zeile($gut ? 1 : 0, abfahrt_t('TEST.F_VORLAGE'),
        abfahrt_t($gut ? 'TEST.A_VORLAGE_OK' : 'TEST.A_VORLAGE_KAPUTT'));

    /* U6 (Durchgang 29.09.2026): die zwei Pflichtzeilen aus Regeln/04, gezaehlt
     * im Quelltext der Oberflaeche. Bis 1.6.15 fehlten beide. */
    if (isset($oberflaeche['quelle'])) {
        list($abf_s, $abf_t) = abfahrt_pruef_reiter($oberflaeche['quelle'],
            isset($oberflaeche['muster']) ? $oberflaeche['muster'] : '');
        $zeile($abf_s, abfahrt_t('TEST.F_REITER'), $abf_t);
        list($abf_s, $abf_t) = abfahrt_pruef_formulare($oberflaeche['quelle']);
        $zeile($abf_s, abfahrt_t('TEST.F_FORMULARE'), $abf_t);
    }

    return $z;
}

/**
 * U6: Passen Reiterleiste, Bereiche und Positivliste zusammen? Gezaehlt im
 * Quelltext der Oberflaeche (Bauform awm_pruef_reiter(), AWM-Abfuhr 1.4.15):
 * jeder Reiter muss in allen dreien stehen, und sm-active setzt der Server an
 * Leiste und Bereich. Rueckgabe array(Stand 1/0, Antwort).
 */
function abfahrt_pruef_reiter($quelle, $muster)
{
    preg_match_all('/<a class="sm-tab(.*?)"\s+data-ziel="(tab-[a-z]+)"/', (string) $quelle, $ml);
    preg_match_all('/<div class="sm-seite(.*?)"\s+id="(tab-[a-z]+)"/', (string) $quelle, $mb);
    $leiste = $ml[2];
    $bereiche = $mb[2];
    $liste = array();
    if (preg_match('/\(([a-z|]+)\)/', (string) $muster, $mm)) {
        foreach (explode('|', $mm[1]) as $r) { $liste[] = 'tab-' . $r; }
    }
    $fehl = array();
    if (!$leiste || !$bereiche || !$liste) { $fehl[] = abfahrt_t('TEST.P_LEER'); }
    foreach (array_unique(array_merge($leiste, $bereiche, $liste)) as $r) {
        if (!in_array($r, $leiste, true))   { $fehl[] = sprintf(abfahrt_t('TEST.P_NICHT_LEISTE'), $r); }
        if (!in_array($r, $bereiche, true)) { $fehl[] = sprintf(abfahrt_t('TEST.P_NICHT_BEREICH'), $r); }
        if (!in_array($r, $liste, true))    { $fehl[] = sprintf(abfahrt_t('TEST.P_NICHT_LISTE'), $r); }
    }
    foreach (array($ml, $mb) as $x) {
        foreach ($x[2] as $i => $r) {
            if (strpos($x[1][$i], "'" . $r . "'") === false || strpos($x[1][$i], 'sm-active') === false) {
                $fehl[] = sprintf(abfahrt_t('TEST.P_KEIN_ACTIVE'), $r);
            }
        }
    }
    if ($fehl) {
        return array(0, sprintf(abfahrt_t('TEST.A_REITER_FEHL'), count($leiste), count($bereiche), count($liste),
            abfahrt_e(implode('; ', array_unique($fehl)))));
    }
    return array(1, sprintf(abfahrt_t('TEST.A_REITER_OK'), count($liste)));
}

/** U6: Tragen alle POST-Formulare der Oberflaeche das Formularmerkmal?
 *  Gezaehlt im Quelltext (Bauform awm_pruef_formulare()). Eine leere Menge
 *  ist kein Haken. */
function abfahrt_pruef_formulare($quelle)
{
    $n = 0;
    $ohne = 0;
    foreach (preg_split('/<form\b/i', (string) $quelle) as $i => $teil) {
        if ($i === 0) { continue; }
        $ende = stripos($teil, '</form>');
        $block = ($ende === false) ? $teil : substr($teil, 0, $ende);
        if (!preg_match('/^[^>]*method="post"/i', $block)) { continue; }
        $n++;
        if (strpos($block, 'name="formtoken"') === false) { $ohne++; }
    }
    if ($n === 0) { return array(0, abfahrt_t('TEST.A_FORM_LEER')); }
    if ($ohne > 0) { return array(0, sprintf(abfahrt_t('TEST.A_FORM_FEHL'), $ohne, $n)); }
    return array(1, sprintf(abfahrt_t('TEST.A_FORM_OK'), $n));
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
        $z['vevents'] = (int) preg_match_all('/^BEGIN:VEVENT[ \t]*\r?$/mi', $ics);

        /* Fuer die Zaehlung wird derselbe Weg benutzt wie im Betrieb - eine
         * zweite, eigene Zaehlung liefe frueher oder spaeter auseinander, und
         * dann stuende in der Diagnose etwas anderes als im Ergebnis. */
        $einzeln = $abfcfg;
        $einzeln['calendars'] = [$cal];
        $d = [];
        $lage = null;
        $best = abfahrt_next_event($einzeln, $d, $lage);
        /* Die Zahl kommt als Wert, nicht aus der eigenen Prosa: bis 1.6.9
         * wurde sie mit einem Suchmuster aus der deutschen Diagnosezeile
         * gelesen - eine umformulierte Zeile haette still 0 ergeben. */
        $z['mit_ort'] = is_array($lage) ? (int) $lage['mit_ort'] : 0;
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
    $cache = abfahrt_geo_cachedatei($abfcfg['provider'], $adr);
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
    $abf_pm = abfahrt_paths();
    $lb = $abf_pm['lbhome'];

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
                /* Bis 1.6.21 fest deutsch, auch in der englischen
                 * Oberflaeche (Pruefung 02.10.2026, Nr. 27g). */
                $aus['quelle'] = sprintf(abfahrt_t('MS4H.QUELLE_PORT'), $port);
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
    } else {
        /* b1 (Welle 2, 30.09.2026): Der Minutentakt und termin.php laden
         * LBSystem nicht, und LBLANG steht dort nicht in der Umgebung. Bis
         * 1.6.18 galt dann fest 'de' - Protokoll und ?debug=1 blieben auf einer
         * englischen Anlage deutsch. Jetzt gilt, was LoxBerry selbst liest:
         * Base.Lang in config/system/general.json (nur dieses eine Feld). */
        $abf_gj = abfahrt_paths();
        $abf_gj = (string) $abf_gj['general'];
        if ($abf_gj !== '' && is_file($abf_gj)) {
            $abf_gd = json_decode((string) @file_get_contents($abf_gj), true);
            if (is_array($abf_gd) && isset($abf_gd['Base']['Lang']) && is_string($abf_gd['Base']['Lang'])) {
                $sprache = $abf_gd['Base']['Lang'];
            }
        }
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
        // <home>/templates/plugins/<ordner>/lang/. Wurzel und Ordner kommen
        // aus abfahrt_paths(). Bis 1.6.12 stand hier ein fester Rueckfall
        // auf das Heimverzeichnis des Benutzers loxberry, und ohne Wurzel
        // wurde der Pfad ab der Laufwerkswurzel gebildet
        // (/templates/plugins/html/lang) - eine
        // fremde Sprachdatei dort gewann (in WSL gemessen,
        // Pruefung-Abfahrtsassistent-1.6.13, Faelle C1 und C2).
        $abf_pt = abfahrt_paths();
        $pfad = '';
        if ($abf_pt['lbhome'] !== '') {
            $pfad = $abf_pt['lbhome'] . '/templates/plugins/' . $abf_pt['plugin'] . '/lang';
        }
        if ($pfad === '' || !is_dir($pfad)) {
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
 * Einen Grund als Kennung in einen Satz der Oberflaechensprache uebersetzen
 * (U5, Durchgang 29.09.2026). Form: KENNUNG oder KENNUNG|wert|wert, der Satz
 * steht unter GRUND.KENNUNG; UNTER|feld|<Kennung> stellt einem inneren Grund
 * den Unterschluessel voran. Ohne Kennung (ein Text aus einer aelteren
 * Fassung) bleibt der Text, wie er ist. Bis 1.6.15 standen die Gruende fest
 * deutsch und in Umschrift im Code - auch in der englischen Oberflaeche
 * (gemessen, Oberflaechen-Pruefer 5).
 */
function abfahrt_grund_text($g)
{
    $teile = explode('|', (string) $g);
    $k = array_shift($teile);
    if ($k === 'UNTER' && count($teile) >= 2) {
        $feld = array_shift($teile);
        return sprintf(abfahrt_t('GRUND.UNTER'), $feld, abfahrt_grund_text(implode('|', $teile)));
    }
    if (!preg_match('/^[A-Z0-9_]+$/', (string) $k)) { return (string) $g; }
    $t = abfahrt_t('GRUND.' . $k);
    if ($t === 'GRUND.' . $k) { return (string) $g; }
    $n = preg_match_all('/%(?:\d+\$)?[sd]/', $t);
    $werte = array_pad(array_slice($teile, 0, $n), $n, '');
    $satz = $n > 0 ? vsprintf($t, $werte) : $t;
    /* b1 (Welle 2): Stehen hinter den Werten weitere Teile und beginnen sie mit
     * einer bekannten Kennung, ist das ein innerer Grund (GEO_FEHL|anbieter|
     * adresse|HTTP_ZEIT); er wird ueber GRUND.MIT angehaengt. Ein Rest ohne
     * bekannte Kennung bleibt wie bisher ungenutzt. */
    $rest = array_slice($teile, $n);
    if ($rest && preg_match('/^[A-Z][A-Z0-9_]*$/', (string) $rest[0])
        && abfahrt_t('GRUND.' . $rest[0]) !== 'GRUND.' . $rest[0]) {
        return sprintf(abfahrt_t('GRUND.MIT'), $satz, abfahrt_grund_text(implode('|', $rest)));
    }
    return $satz;
}

/** Der Grund eines abgelegten Stands in der Sprache des Lesers (b1): die
 *  Kennung, wo es eine gibt; sonst der abgelegte Satz (Stand einer aelteren
 *  Fassung). */
function abfahrt_stand_grund(array $st) {
    $id = isset($st['grund_id']) ? (string) $st['grund_id'] : '';
    return $id !== '' ? abfahrt_grund_text($id) : (string) (isset($st['grund']) ? $st['grund'] : '');
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
/**
 * Eine Datei mit Rechten 0600 unteilbar schreiben.
 *
 * Nebendatei im selben Verzeichnis, Rechte VOR dem Inhalt, Laengenvergleich,
 * dann rename(). Bis 1.6.9 schrieb abfahrt_config_speichern() direkt mit
 * file_put_contents(...) === false: eine Kurzschreibung (volle Karte) liefert
 * aber die Zahl der geschriebenen Bytes, nicht false, und die halbe Datei
 * wurde anschliessend auch noch ueber die Zweitschrift kopiert. Zwischen
 * Schreiben und chmod lag der Schluessel des Kartendienstes mit den
 * Umask-Rechten da.
 */
function abfahrt_datei_geheim_schreiben($datei, $inhalt) {
    $inhalt = (string) $inhalt;
    $verz = dirname($datei);
    if (!is_dir($verz)) { @mkdir($verz, 0775, true); }
    $neben = $datei . '.' . getmypid() . '.neu';
    if (@file_put_contents($neben, '') === false) { return false; }
    @chmod($neben, 0600);
    $n = @file_put_contents($neben, $inhalt);
    if ($n !== strlen($inhalt)) { @unlink($neben); return false; }
    if (!@rename($neben, $datei)) { @unlink($neben); return false; }
    clearstatcache(true, $datei);
    return @file_get_contents($datei) === $inhalt;
}

/**
 * Liegt ein Rechner im Heimnetz? Fuer tts.ip und die Vorlage der Ansage.
 *
 * Zugelassen: Loopback, die privaten IPv4-Bereiche, und Namen ohne Punkt
 * oder mit den ueblichen Heimnetz-Endungen. Alles andere wird abgewiesen:
 * die Ansage traegt den Titel des naechsten Termins in der Adresse.
 *
 * Bis 1.6.21 eine eigene Rechnung: eine Zahl als Name (134744072, 0x8080808)
 * galt als "Name ohne Punkt", 10.8.8.8 mit fuehrender Null als 10.8.8.8 - gerufen wurde beide Male
 * 8.8.8.8 (Pruefung 02.10.2026, Nr. 2). Jetzt eine Quelle: die gemeinsame
 * Sprachausgabe, die diese Bibliothek als erste laedt (ganz oben).
 */
function abfahrt_heimnetz_host($h) {
    return ansage_heimnetz_host($h);
}

/**
 * Die Konfiguration pruefen und, wo noetig, heilen - EINMAL, und gemeldet.
 *
 * Aufgerufen von der Oberflaeche und vom Dienst, NIE vom unangemeldeten
 * Endpunkt (der schreibt nichts). Entscheidet nach INHALT, nicht nach Form
 * (Hausstandard, Regeln/05): eine Konfiguration gilt als heil, wenn sie
 * gueltiges JSON ist und ein Merkwort traegt.
 *
 *   1. Datei kaputt (kein JSON):   nach <datei>.kaputt-<zeit> verschieben.
 *   2. Datei fehlt, leer oder ohne Merkwort, Zweitschrift traegt eines:
 *      aus der Zweitschrift zurueckschreiben (fehlt nur das Merkwort, wird
 *      NUR das Merkwort uebernommen - die uebrigen Werte sind juenger).
 *   3. Datei heil, aber Schluessel aus neueren Fassungen fehlen:
 *      vervollstaendigen (Hausstandard: vervollstaendigen, nicht ergaenzen).
 *   4. Abgewiesene Werte: melden. Sie werden NICHT ueberschrieben - wer die
 *      Datei von Hand bearbeitet hat, soll seinen Wert wiederfinden.
 *
 * Jeder Schritt schreibt eine Protokollzeile. Rueckgabe: Liste der Meldungen
 * (leer, wenn nichts zu tun war).
 */
/**
 * Der Zustand der Konfiguration VOR der ersten Selbstheilung dieses Aufrufs
 * (U4, Regeln/05): array('zustand', 'schritte' => kaputt | kaputt_fest |
 * aus_zweit | token_aus_zweit | vervollstaendigt, 'datei' => .kaputt-Datei,
 * 'fehlend'). Festgehalten wird nur der ERSTE Aufruf je Prozess.
 */
function abfahrt_config_erstbefund($neu = null) {
    static $b = null;
    if ($neu !== null && $b === null) { $b = $neu; }
    return $b !== null ? $b : array('zustand' => '', 'schritte' => array(), 'datei' => '', 'fehlend' => array());
}

function abfahrt_config_heilen() {
    $p = abfahrt_paths();
    $datei = $p['config'];
    $zweit = $p['backup'];
    $meldungen = array();

    list($roh, $zustand) = abfahrt_config_roh($datei);
    $abf_erst = array('zustand' => $zustand, 'schritte' => array(), 'datei' => '', 'fehlend' => array());
    list($zroh, $zzustand) = abfahrt_config_roh($zweit);
    $zweit_gut = ($zzustand === 'ok' && isset($zroh['aktionstoken'])
                  && is_string($zroh['aktionstoken']) && $zroh['aktionstoken'] !== '');

    /* Nicht lesbar (Rechte): nichts verschieben, nichts aus der Zweitschrift
     * darueberschreiben, kein Merkwort - nur melden, mit Eigentuemer und
     * Rechten. Bis 1.6.21 lief eine unlesbare Datei als 'kaputt' hier durch
     * (Pruefung 02.10.2026, Nr. 5). Ins Protokoll einmal je Stunde: der Dienst
     * ruft diese Funktion jede Minute. */
    if ($zustand === 'unlesbar') {
        $m = sprintf(abfahrt_t('MELDUNG.CFG_UNLESBAR'), $datei, abfahrt_datei_rechte($datei));
        abfahrt_log_gedrosselt('cfg_unlesbar', 'Konfiguration: ' . strip_tags($m), 3600);
        $abf_erst['schritte'][] = 'unlesbar';
        abfahrt_config_erstbefund($abf_erst);
        abfahrt_config();     // Lage fuer den Reiter Test
        return array($m);
    }

    if ($zustand === 'kaputt') {
        $ziel = $datei . '.kaputt-' . date('Ymd-His');
        if (@rename($datei, $ziel)) {
            @chmod($ziel, 0600);
            $meldungen[] = sprintf(abfahrt_t('MELDUNG.CFG_KAPUTT'), basename($ziel));
            $abf_erst['schritte'][] = 'kaputt';
            $abf_erst['datei'] = basename($ziel);
        } else {
            // Nicht verschiebbar: nichts weiter anfassen, sonst ginge der
            // Inhalt verloren. Die Oberflaeche erzeugt dann auch kein Merkwort.
            $meldungen[] = abfahrt_t('MELDUNG.CFG_KAPUTT_FEST');
            foreach ($meldungen as $m) { abfahrt_log('Konfiguration: ' . strip_tags($m)); }
            $abf_erst['schritte'][] = 'kaputt_fest';
            abfahrt_config_erstbefund($abf_erst);
            return $meldungen;
        }
        $roh = null;
        $zustand = 'fehlt';
    }

    $hat_token = is_array($roh) && isset($roh['aktionstoken'])
                 && is_string($roh['aktionstoken']) && $roh['aktionstoken'] !== '';

    if (!$hat_token && $zweit_gut) {
        if ($zustand === 'ok' && $roh) {
            // Nur das Merkwort fehlt - die uebrigen Werte der Datei sind juenger.
            $roh['aktionstoken'] = $zroh['aktionstoken'];
            $inhalt = $roh;
            $meldungen[] = abfahrt_t('MELDUNG.CFG_TOKEN_AUS_ZWEIT');
            $abf_erst['schritte'][] = 'token_aus_zweit';
        } else {
            $inhalt = $zroh;
            $meldungen[] = abfahrt_t('MELDUNG.CFG_AUS_ZWEIT');
            $abf_erst['schritte'][] = 'aus_zweit';
        }
        $js = json_encode($inhalt, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($js === false || !abfahrt_datei_geheim_schreiben($datei, $js)) {
            $meldungen[] = sprintf(abfahrt_t('MELDUNG.SPEICHERN_FEHL'), $datei);
        }
        list($roh, $zustand) = abfahrt_config_roh($datei);
    }

    if ($zustand === 'ok' && is_array($roh)) {
        $fehlend = array_diff(array_keys(abfahrt_vorgaben()), array_keys($roh));
        if ($fehlend && isset($roh['aktionstoken']) && $roh['aktionstoken'] !== '') {
            $voll = $roh;
            foreach (abfahrt_vorgaben() as $k => $v) {
                if (!array_key_exists($k, $voll)) { $voll[$k] = $v; }
            }
            if (abfahrt_config_speichern($voll)) {
                $meldungen[] = sprintf(abfahrt_t('MELDUNG.CFG_VERVOLLSTAENDIGT'),
                                       count($fehlend), implode(', ', $fehlend));
                $abf_erst['schritte'][] = 'vervollstaendigt';
                $abf_erst['fehlend'] = array_values($fehlend);
            }
        }
    }

    foreach ($meldungen as $m) {
        abfahrt_log('Konfiguration: ' . strip_tags($m));
    }
    abfahrt_config_erstbefund($abf_erst);     // U4
    /* Abgewiesene Werte stehen bei JEDEM Aufruf auf dem Bildschirm, ins
     * Protokoll aber nur, wenn sich die Liste geaendert hat - der Dienst
     * ruft diese Funktion jede Minute. */
    abfahrt_config();
    $lage = abfahrt_config_lage();
    $abgew = array();
    foreach ($lage['abgewiesen'] as $k => $g) {
        $abgew[] = sprintf(abfahrt_t('MELDUNG.CFG_WERT_ABGEWIESEN'), $k, abfahrt_grund_text($g));
    }
    $merker = abfahrt_tmpdir() . '/cfg_abgewiesen.txt';
    $sig = $abgew ? md5(implode("\n", $abgew)) : '';
    $alt = is_file($merker) ? trim((string) @file_get_contents($merker)) : '';
    if ($sig !== $alt) {
        foreach ($abgew as $m) { abfahrt_log('Konfiguration: ' . strip_tags($m)); }
        @file_put_contents($merker, $sig);
    }
    return array_merge($meldungen, $abgew);
}

/** Eigentuemer, Gruppe und Rechte einer Datei fuer eine Meldung, z. B. "root:root 0600". */
function abfahrt_datei_rechte($datei) {
    clearstatcache(true, $datei);
    $u = @fileowner($datei);
    $g = @filegroup($datei);
    $r = @fileperms($datei);
    if ($u === false || $r === false) { return '?'; }
    $un = (string) $u;
    $gn = (string) $g;
    if (function_exists('posix_getpwuid')) {
        $x = @posix_getpwuid($u);
        if (is_array($x) && isset($x['name'])) { $un = $x['name']; }
    }
    if ($g !== false && function_exists('posix_getgrgid')) {
        $x = @posix_getgrgid($g);
        if (is_array($x) && isset($x['name'])) { $gn = $x['name']; }
    }
    return $un . ':' . $gn . ' ' . sprintf('%04o', $r & 07777);
}

function abfahrt_config_speichern($cfg)
{
    $p = abfahrt_paths();
    /* Nr. 5: ueber eine Datei, die dieser Prozess nicht lesen kann, wird nicht
     * geschrieben - der Aufrufer haette sonst die Vorgaben statt ihres
     * Inhalts in der Hand, und die Zweitschrift ginge gleich mit. */
    list(, $abf_zst) = abfahrt_config_roh($p['config']);
    if ($abf_zst === 'unlesbar') {
        abfahrt_log_gedrosselt('cfg_unlesbar_speichern', 'Konfiguration: ' . $p['config']
            . ' ist nicht lesbar (' . abfahrt_datei_rechte($p['config']) . ') - nicht gespeichert.', 3600);
        return false;
    }
    $js = json_encode($cfg, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE
                            | JSON_UNESCAPED_SLASHES);
    if ($js === false) {
        return false;   /* ungueltiges UTF-8 - lieber gar nicht schreiben
                           als eine halbe Datei hinterlassen */
    }
    if (!abfahrt_datei_geheim_schreiben($p['config'], $js)) {
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
    /* Die Zweitschrift ebenso unteilbar. Scheitert sie, ist das Speichern
     * trotzdem gelungen - gemeldet wird es im Protokoll, weil die Heilung
     * sonst beim naechsten Anlass auf einen alten Stand zurueckfiele. */
    $zweit = $p['backup'];
    list(, $abf_zzst) = abfahrt_config_roh($zweit);
    if ($abf_zzst === 'unlesbar') {
        // Nr. 5: eine Zweitschrift, die sich nicht lesen laesst, kann das
        // einzige verbliebene Merkwort tragen - stehen lassen, melden.
        abfahrt_log_gedrosselt('zweit_unlesbar', 'Konfiguration: Zweitschrift ' . $zweit . ' ist nicht lesbar ('
            . abfahrt_datei_rechte($zweit) . ') - sie wurde nicht ueberschrieben.', 3600);
    } elseif (!abfahrt_datei_geheim_schreiben($zweit, $js)) {
        abfahrt_log('Konfiguration: Zweitschrift ' . $zweit . ' liess sich nicht schreiben.');
    }
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
function abfahrt_sicherung_bauen(array $cfg, $pruefen = true)
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
                    . 'Kartendienstes. Wie ein Passwort behandeln. Die '
                    . 'Sprechtoken fuer Alexa-NG und Chromecast 4 Lox NG sind '
                    . 'absichtlich NICHT enthalten.',
    );
    /* Ansage-2 (01.10.2026): das Sprechtoken fuer Alexa-NG ist ein Kennwort
     * eines anderen Plugins und geht nie mit; das Zurueckspielen behaelt das
     * geltende (abfahrt_sicherung_lesen()). */
    if (isset($cfg['tts']) && is_array($cfg['tts'])) {
        $cfg['tts'] = ansage_sicherung_bereinigen($cfg['tts']);    // Ansage-2/3, Nr. 36 b: eine Quelle
    }
    /* X-3 (Welle 2, 30.09.2026): Wuerde das eigene Zurueckspielen diese Datei
     * abweisen, sagt es der Kopf - nur Namen, nie Werte. Geliefert wird sie
     * trotzdem vollstaendig. */
    if ($pruefen) {
        $abf_namen = abfahrt_rueckspiel_altwerte($cfg);
        if ($abf_namen) {
            $kopf['_warnung'] = sprintf(abfahrt_t('TEXT.SICH_WARN_KOPF'), implode(', ', $abf_namen));
        }
    }
    return json_encode($kopf + $cfg,
        JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

/**
 * X-3: Welche Einstellungen wuerden beim Zurueckspielen der EIGENEN Sicherung
 * abgewiesen? Gebaut wird genau die Datei, die "Einstellungen sichern"
 * liefert, und durch dieselbe Pruefung geschickt wie beim Zurueckspielen.
 * Rueckgabe: Namen (nie Werte), leer heisst "wuerde angenommen".
 *
 * Der Fall, der hier anschlaegt: ein unbekannter Schluessel in abfahrt.json
 * (aus einer neueren Fassung nach einem Zurueckgehen, oder von Hand). Die
 * Lesefunktion laesst ihn stehen und die Sicherung traegt ihn mit - das
 * Zurueckspielen weist die ganze Datei ab ("Unbekannte Einstellung").
 * Ein unzulaessiger WERT kann hier nicht stehen: abfahrt_config() setzt ihn
 * schon beim Lesen auf die Vorgabe und meldet es (Selbstheilung).
 */
function abfahrt_rueckspiel_altwerte(array $cfg)
{
    $js = abfahrt_sicherung_bauen($cfg, false);
    if ($js === false) { return array(); }     // Sichern meldet dann selbst SICH_SCHREIBFEHLER
    $namen = array();
    list($neu) = abfahrt_sicherung_lesen($js, $namen);
    $namen = array_values(array_unique($namen));
    sort($namen);
    if ($neu === null && !$namen) { $namen[] = abfahrt_t('TEXT.SICH_GANZE_DATEI'); }
    return $namen;
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
 *
 * Die Muster enden auf \z, nicht auf $: in PCRE passt $ auch vor einem
 * Zeilenumbruch am Ende, und $text() laesst Umbrueche mit Absicht zu. Bis
 * 1.6.10 nahm eine Sicherungsdatei deshalb "aktionstoken": "abc123\n" an,
 * und jede in Loxone eingetragene Adresse war danach stumm ungueltig
 * (gemessen 24.09.2026 unter PHP 7.4 und 8.4; zuerst an AWM-Abfuhr 1.4.10).
 * tts.zones erlaubt Leerzeichen um das Komma (ansage_zonen_ok()), seit der
 * Pruefung 02.10.2026 aber keinen Tabulator und keinen Umbruch mehr.
 */
function abfahrt_wert_pruefen($schluessel, $wert, &$grund = '')
{
    $grund = '';
    /* U5 (Durchgang 29.09.2026): $grund ist eine KENNUNG (KENNUNG|wert|...),
     * ausgegeben ueber abfahrt_grund_text() und die Sprachschluessel GRUND.*.
     * Bis 1.6.15 stand hier deutscher Text in Umschrift ("ausserhalb 0..120"),
     * auch in der englischen Oberflaeche. Werte aus der Eingabe (Schluessel,
     * Tag) gehen ohne senkrechten Strich in die Kennung. */
    $zahl = function ($w, $min, $max) use (&$grund) {
        if (is_array($w) || is_bool($w) || is_null($w)) { $grund = 'KEINE_ZAHL'; return null; }
        if (!is_int($w) && !is_float($w) && !preg_match('/^-?\d+$/', trim((string) $w))) {
            $grund = 'KEINE_ZAHL'; return null;
        }
        $i = (int) $w;
        if ($i < $min || $i > $max) { $grund = 'AUSSERHALB|' . $min . '|' . $max; return null; }
        return $i;
    };
    $text = function ($w, $max) use (&$grund) {
        if (is_array($w) || is_bool($w) || is_null($w)) { $grund = 'KEIN_TEXT'; return null; }
        $s = (string) $w;
        if (strlen($s) > $max) { $grund = 'ZU_LANG|' . $max; return null; }
        // Steuerzeichen ausser Tabulator, CR und LF: die haben in keiner
        // dieser Einstellungen etwas zu suchen.
        if (preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $s)) {
            $grund = 'STEUERZEICHEN'; return null;
        }
        return $s;
    };
    $schalter = function ($w) use (&$grund) {
        if (is_array($w)) { $grund = 'KEIN_SCHALTER'; return null; }
        return empty($w) ? 0 : 1;
    };
    $teil = function ($x) { return str_replace('|', '/', (string) $x); };

    switch ($schluessel) {
        case 'provider':
            $s = (string) (is_array($wert) ? '' : $wert);
            if (!in_array($s, array('tomtom', 'google', 'here'), true)) {
                $grund = 'KARTENDIENST'; return null;
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
            if ($s !== '' && !preg_match('#^[A-Za-z0-9_/\-]+\z#', $s)) {
                $grund = 'THEMA_ZEICHEN'; return null;
            }
            /* Kein '/' am Rand, kein '//' (Nr. 15, abfahrt_mqtt_praefix_norm()). */
            if ($s !== '' && !preg_match('#^[A-Za-z0-9_\-]+(/[A-Za-z0-9_\-]+)*\z#', $s)) {
                $grund = 'THEMA_FORM'; return null;
            }
            return $s;
        case 'ganztags_zeit':
            /* Auf HH:MM gebracht: "8:00" wurde bis 1.6.21 angenommen, aber
             * <input type="time"> zeigt den Wert dann leer, und jedes Speichern
             * scheiterte an dem leeren Feld (Pruefung 02.10.2026). \z statt $:
             * "8:00\n" passte sonst. */
            $s = $text($wert, 5);
            if ($s === null) { return null; }
            if (!preg_match('/^([01]?\d|2[0-3]):([0-5]\d)\z/', $s, $hm)) {
                $grund = 'UHRZEIT'; return null;
            }
            return sprintf('%02d:%02d', (int) $hm[1], (int) $hm[2]);
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
            if ($s !== '' && !preg_match('/^[A-Za-z0-9_.\-]+\z/', $s)) {
                $grund = 'MERKWORT_ZEICHEN'; return null;
            }
            return $s;
        case 'calendars':
            if (!is_array($wert)) { $grund = 'KEINE_LISTE'; return null; }
            if (count($wert) > 10) { $grund = 'MEHR_KALENDER|10'; return null; }
            $aus = array();
            foreach ($wert as $c) {
                if (!is_array($c)) { $grund = 'KEINE_ZEILE'; return null; }
                $n = $text(isset($c['name']) ? $c['name'] : '', 100);
                $u = $text(isset($c['url']) ? $c['url'] : '', 500);
                if ($n === null || $u === null) { return null; }
                if ($u !== '' && !preg_match('#^https?://#i', $u)) {
                    $grund = 'KAL_HTTP'; return null;
                }
                $aus[] = array('name' => $n, 'url' => $u);
            }
            return $aus;
        /* Die vier Felder werden seit 1.6.10 bis in die Unterschluessel
         * geprueft.
         *
         * BERICHTIGT: hier stand nur "ist es ein Feld?", und der Kommentar
         * behauptete, abfahrt_config() klemme den Rest. Das tat es nie - es
         * setzte nur fehlende Unterschluessel. Gemessen 06.09.2026 unter PHP
         * 7.4 und 8.4: eine Sicherungsdatei mit der Sperrzeit "XX" bis "YY"
         * wurde angenommen und sperrte die Ansage auf Dauer; eine tts-Vorlage
         * mit einem fremden Rechner schickte den Titel des naechsten Termins
         * aus dem Haus, file:/// wurde ebenso gebaut. */
        case 'notify':
            if (!is_array($wert)) { $grund = 'KEIN_FELD'; return null; }
            $aus = array();
            foreach ($wert as $uk => $uw) {
                if (!in_array((string) $uk, array('audio', 'push'), true)) {
                    $grund = 'EINTRAG|' . $teil($uk); return null;
                }
                $s = $schalter($uw);
                if ($s === null) { return null; }
                $aus[(string) $uk] = $s;
            }
            return $aus;
        case 'quiet':
            if (!is_array($wert)) { $grund = 'KEIN_FELD'; return null; }
            $aus = array();
            foreach ($wert as $tag => $zeile) {
                $t = is_int($tag) ? $tag : (preg_match('/^\d{1,2}$/', (string) $tag) ? (int) $tag : 0);
                if (!in_array($t, abfahrt_quiet_keys(), true)) {
                    $grund = 'TAG_UNBEKANNT|' . $teil($tag); return null;
                }
                if (!is_array($zeile)) { $grund = 'TAG_ZEILE|' . $t; return null; }
                $neu = array();
                foreach ($zeile as $uk => $uw) {
                    if ($uk === 'on') {
                        $s = $schalter($uw);
                        if ($s === null) { return null; }
                        $neu['on'] = $s;
                    } elseif ($uk === 'from' || $uk === 'to') {
                        if (is_array($uw) || !preg_match('/^([01]?\d|2[0-3]):([0-5]\d)\z/', (string) $uw, $hm)) {
                            $grund = 'TAG_UHRZEIT|' . $t; return null;
                        }
                        $neu[$uk] = sprintf('%02d:%02d', (int) $hm[1], (int) $hm[2]);     // wie ganztags_zeit
                    } else {
                        $grund = 'TAG_EINTRAG|' . $t . '|' . $teil($uk); return null;
                    }
                }
                $aus[$t] = $neu;
            }
            return $aus;
        case 'ortsbuch':
            if (!is_array($wert)) { $grund = 'KEINE_LISTE'; return null; }
            if (count($wert) > 10) { $grund = 'MEHR_ZEILEN|10'; return null; }
            $aus = array();
            foreach ($wert as $e) {
                if (!is_array($e)) { $grund = 'KEINE_ZEILE'; return null; }
                $m = $text(isset($e['muster']) ? $e['muster'] : '', 100);
                $a = $text(isset($e['adresse']) ? $e['adresse'] : '', 300);
                if ($m === null || $a === null) { return null; }
                if (trim($m) === '' || trim($a) === '') {
                    $grund = 'ORTSBUCH_HALB'; return null;
                }
                $aus[] = array('muster' => $m, 'adresse' => $a);
            }
            return $aus;
        case 'tts':
            if (!is_array($wert)) { $grund = 'KEIN_FELD'; return null; }
            $aus = array();
            foreach ($wert as $uk => $uw) {
                switch ((string) $uk) {
                    case 'mode':
                        /* Nr. 36 b, Stufe 2: die Arten des Formulars (abfahrt_ansage_modi(), mit "aus"). */
                        if (is_array($uw) || !in_array((string) $uw, abfahrt_ansage_modi(), true)) {
                            $grund = 'TTS_MODUS'; return null;
                        }
                        $aus['mode'] = (string) $uw;
                        break;
                    /* Bis 1.6.21 liessen ip, zones und template Tabulator, CR
                     * und LF durch (die gemeinsame Pruefung weist sie ab), und
                     * als Zonen galt auch "1 2" (Pruefung 02.10.2026, Nr. 14).
                     * Leerraum am Rand faellt still weg. */
                    case 'ip':
                        $s = $text($uw, 253);
                        if ($s === null) { return null; }
                        if (preg_match('/[\x00-\x1F\x7F]/', trim($s))) { $grund = 'STEUERZEICHEN'; return null; }
                        if (trim($s) !== '' && !abfahrt_heimnetz_host(trim($s))) {
                            $grund = 'TTS_IP'; return null;
                        }
                        $aus['ip'] = trim($s);
                        break;
                    case 'port':
                        $z = $zahl($uw, 1, 65535);
                        if ($z === null) { $grund = 'UNTER|tts.port|' . $grund; return null; }
                        $aus['port'] = $z;
                        break;
                    case 'volume':
                        $z = $zahl($uw, 1, 100);
                        if ($z === null) { $grund = 'UNTER|tts.volume|' . $grund; return null; }
                        $aus['volume'] = $z;
                        break;
                    case 'zones':
                        $s = $text($uw, 200);
                        if ($s === null) { return null; }
                        $s = trim($s);
                        if (preg_match('/[\x00-\x1F\x7F]/', $s)) { $grund = 'STEUERZEICHEN'; return null; }
                        if (!ansage_zonen_ok($s)) {
                            $grund = 'TTS_ZONEN'; return null;
                        }
                        $aus['zones'] = $s;
                        break;
                    case 'lang':
                        if (is_array($uw) || !preg_match('/^[a-z]{2}\z/', (string) $uw)) {
                            $grund = 'TTS_SPRACHE'; return null;
                        }
                        $aus['lang'] = (string) $uw;
                        break;
                    case 'template':
                        $s = $text($uw, 500);
                        if ($s === null) { return null; }
                        $s = trim($s);
                        if (preg_match('/[\x00-\x1F\x7F]/', $s)) { $grund = 'STEUERZEICHEN'; return null; }
                        /* Bis 1.6.21 endete der Rechner am ersten Doppelpunkt:
                         * http://{ip}:80@example.com/ galt als {ip} (Pruefung
                         * 02.10.2026, Nr. 2 a). Jetzt die gemeinsame Pruefung. */
                        if ($s !== '') {
                            $grund = ansage_vorlage_grund($s);
                            if ($grund !== '') { return null; }
                        }
                        $aus['template'] = $s;
                        break;
                    /* Ansage-2 (01.10.2026): Ausgabeart Alexa-NG. Kein Wert
                     * erscheint in einem Grund (das Token schon gar nicht). */
                    case 'alexa_geraet':
                        if (!ansage_geraet_ok($uw)) { $grund = 'TTS_ALEXA_GERAET'; return null; }
                        $aus['alexa_geraet'] = $uw;
                        break;
                    case 'alexa_laut':
                        $z = $zahl($uw, -1, 100);
                        if ($z === null) { $grund = 'UNTER|tts.alexa_laut|' . $grund; return null; }
                        $aus['alexa_laut'] = $z;
                        break;
                    case 'alexa_token':
                        // Leer heisst "keins gespeichert".
                        if (!is_string($uw) || ($uw !== '' && !ansage_token_ok($uw))) {
                            $grund = 'TTS_ALEXA_TOKEN'; return null;
                        }
                        $aus['alexa_token'] = $uw;
                        break;
                    /* Ansage-3 (01.10.2026): Google-Lautsprecher, dieselben
                     * Formen wie bei Alexa-NG. Kein Wert erscheint in einem Grund. */
                    case 'google_geraet':
                        if (!ansage_geraet_ok($uw)) { $grund = 'TTS_GOOGLE_GERAET'; return null; }
                        $aus['google_geraet'] = $uw;
                        break;
                    case 'google_laut':
                        $z = $zahl($uw, -1, 100);
                        if ($z === null) { $grund = 'UNTER|tts.google_laut|' . $grund; return null; }
                        $aus['google_laut'] = $z;
                        break;
                    case 'google_token':
                        // Leer heisst "keins gespeichert".
                        if (!is_string($uw) || ($uw !== '' && !ansage_token_ok($uw))) {
                            $grund = 'TTS_GOOGLE_TOKEN'; return null;
                        }
                        $aus['google_token'] = $uw;
                        break;
                    /* Nr. 36 b, Stufe 2: die Schluessel des Moduls fuer Sonos4Lox (seit 1.1.0).
                     * Die Linie bietet die Art nicht an, aber ansage_vervollstaendigen() traegt
                     * sie in jeden gespeicherten Block ein - ohne diese zwei Faelle fiele der
                     * ganze Block beim Laden auf die Vorgaben (TTS_EINTRAG). */
                    case 'sonos_zone':
                        if (!ansage_geraet_ok($uw)) { $grund = 'TTS_SONOS_ZONE'; return null; }
                        $aus['sonos_zone'] = $uw;
                        break;
                    case 'sonos_laut':
                        $z = $zahl($uw, -1, 100);
                        if ($z === null) { $grund = 'UNTER|tts.sonos_laut|' . $grund; return null; }
                        $aus['sonos_laut'] = $z;
                        break;
                    default:
                        $grund = 'TTS_EINTRAG|' . $teil($uk); return null;
                }
            }
            return $aus;
    }
    $grund = 'UNBEKANNT';
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
function abfahrt_sicherung_lesen($roh, &$namen = null)
{
    $namen = array();     // X-3: Namen der beanstandeten Einstellungen, nie Werte
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
        // is_string(): eine Liste ([1,2]) hat Zahlen als Schluessel, und $k[0]
        // gab darauf unter PHP 8 eine Warnung (Pruefung 02.10.2026, Nr. 28c).
        if (is_string($k) && $k !== '' && $k[0] === '_') {
            continue;
        }
        if (!array_key_exists($k, $vorgaben)) {
            $mangel[] = sprintf(abfahrt_t('TEXT.SICH_FREMD'), (string) $k);
            $namen[] = (string) $k;
            continue;
        }
        /* Ansage-2: Sicherungen tragen nie ein Sprechtoken fuer Alexa-NG.
         * Bringt eine Datei eines mit, wird sie abgewiesen - sie stammt nicht
         * aus "Einstellungen sichern", und das geltende Token bleibt. */
        if ($k === 'tts' && is_array($w)) {
            $abf_mit_token = false;
            if (array_key_exists('alexa_token', $w) && $w['alexa_token'] !== '') {
                $mangel[] = abfahrt_t('TEXT.SICH_ALEXA_TOKEN');
                $namen[] = 'tts.alexa_token';
                $abf_mit_token = true;
            }
            /* Ansage-3: ebenso das Sprechtoken fuer Chromecast 4 Lox NG. */
            if (array_key_exists('google_token', $w) && $w['google_token'] !== '') {
                $mangel[] = abfahrt_t('TEXT.SICH_GOOGLE_TOKEN');
                $namen[] = 'tts.google_token';
                $abf_mit_token = true;
            }
            if ($abf_mit_token) { continue; }
        }
        $grund = '';
        $wert = abfahrt_wert_pruefen($k, $w, $grund);
        if ($wert === null) {
            $mangel[] = sprintf(abfahrt_t('TEXT.SICH_WERT'), (string) $k, abfahrt_grund_text($grund));
            $namen[] = (string) $k;
            continue;
        }
        $neu[$k] = $wert;
        $gesehen[$k] = 1;
        $anzahl++;
    }

    if ($anzahl === 0) {
        $mangel[] = abfahrt_t('TEXT.SICH_LEER');
    }

    // Ansage-2: das geltende Sprechtoken bleibt (die Sicherung traegt keines).
    if (isset($gesehen['tts']) && is_array($neu['tts'])) {
        $neu['tts']['alexa_token'] = (isset($jetzt['tts']['alexa_token']) && is_string($jetzt['tts']['alexa_token']))
            ? $jetzt['tts']['alexa_token'] : '';
        $neu['tts']['google_token'] = (isset($jetzt['tts']['google_token']) && is_string($jetzt['tts']['google_token']))
            ? $jetzt['tts']['google_token'] : '';     // Ansage-3
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
