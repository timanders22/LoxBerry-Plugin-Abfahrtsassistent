#!/usr/bin/env php
<?php
/**
 * Abfahrts-Assistent - Hintergrunddienst
 *
 * Rechnet den naechsten Termin samt Fahrzeit aus und schiebt das Ergebnis per
 * MQTT an den Miniserver. Laeuft aus cron.01min.
 *
 * WARUM DAS RECHNEN HIERHIN GEHOERT UND NICHT IN termin.php
 * Frueher rechnete der Endpunkt bei jedem Aufruf selbst. Damit haing die Zahl
 * der Anfragen an TomTom daran, wie oft Loxone fragt - und ein zweiter
 * Abfrager (Browser, zweiter Miniserver, ein neugieriges Skript) verdoppelte
 * sie. Jetzt rechnet genau eine Stelle in genau einem Takt, und alle anderen
 * lesen nur noch ab.
 *
 * WARUM KEIN DAUERLAEUFER
 * Es gibt nichts zu empfangen; Kalender und Kartendienst werden abgefragt. Ein
 * Dauerlaeufer waere ein Prozess mehr, der beim Plugin-Update haengenbleiben
 * kann - ohne einen einzigen Vorteil.
 *
 * Aufrufe:
 *   abfahrt_dienst.php              aus dem Cron
 *   abfahrt_dienst.php jetzt        Takt umgehen
 *   abfahrt_dienst.php zeile        Statuszeile ausgeben, ohne zu rechnen
 *   abfahrt_dienst.php --selbsttest Einrichtung pruefen, ohne Netz
 */

error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);

/* Die Bibliothek ueber eine Kandidatensuche finden - NICHT ueber eine feste
 * Zahl von ".." nach oben.
 *
 * DAS WAR DER SCHWERSTE FEHLER DIESER LINIE. Bis 1.5.7 stand hier
 *
 *     require_once dirname(__DIR__) . '/webfrontend/html/abfahrt_lib.php';
 *
 * Im entpackten Archiv liegen bin/ und webfrontend/ nebeneinander, dort geht
 * das auf. Auf dem installierten LoxBerry liegen sie in GETRENNTEN Baeumen:
 *
 *     <LoxBerry-Wurzel>/bin/plugins/<ordner>/abfahrt_dienst.php
 *     <LoxBerry-Wurzel>/webfrontend/html/plugins/<ordner>/abfahrt_lib.php
 *
 * dirname(__DIR__) ergibt dort <LoxBerry-Wurzel>/bin/plugins, gesucht wurde
 * also <LoxBerry-Wurzel>/bin/plugins/webfrontend/html/abfahrt_lib.php. Die gibt es
 * nicht. Der Dienst brach bei JEDEM Cron-Lauf mit einem fatalen Fehler ab -
 * seit 1.5.0, also seit das Rechnen ueberhaupt hierher verlagert wurde.
 *
 * Bemerkt hat es niemand, weil der Cron nach /dev/null schreibt und
 * termin.php den (nie geschriebenen) Zwischenstand klaglos als OK=0 ausgibt.
 * In Loxone sah das aus wie "kein Termin gefunden", nicht wie ein Defekt.
 *
 * Deshalb: mehrere Kandidaten, und wenn keiner passt, wird gesagt, welche
 * Datei wo gesucht wurde - auf STDERR und mit Rueckgabewert 1, damit ein
 * kuenftiger Ausfall sichtbar ist statt lautlos.
 */
/* SEIT 1.6.13 ENTSCHEIDET DER EIGENE ABLAGEORT, welcher Kandidat gilt.
 * Bis 1.6.12 stand hier eine Liste, deren zweiter Eintrag immer drei Ebenen
 * hinaufrechnete: aus einem ausgepackten Archiv unter / hiess das
 * //webfrontend/html/plugins/bin/abfahrt_lib.php, und eine fremde Datei dort
 * wurde eingebunden und ausgefuehrt (in WSL gemessen,
 * Pruefung-Abfahrtsassistent-1.6.13, Fall C5). Der erste Eintrag galt nur,
 * wenn LBHOMEDIR UND LBPPLUGINDIR ausdruecklich gesetzt sind - so ruft die
 * Deinstallation. Bauart ZendureSolarFlow 0.9.26. */
$abf_kandidaten = array();
$abf_lb = (string) getenv('LBHOMEDIR');
$abf_ordner = basename(rtrim((string) getenv('LBPPLUGINDIR'), '/'));
if ($abf_lb !== '' && $abf_ordner !== ''
    && !in_array($abf_ordner, array('.', 'html', 'htmlauth', 'bin', 'plugins', 'webfrontend'), true)) {
    $abf_kandidaten[] = $abf_lb . '/webfrontend/html/plugins/' . $abf_ordner . '/abfahrt_lib.php';
}
if (basename(dirname(__DIR__)) === 'plugins') {
    // installiert: .../bin/plugins/<ordner>  ->  .../webfrontend/html/plugins/<ordner>
    $abf_kandidaten[] = dirname(dirname(dirname(__DIR__)))
                      . '/webfrontend/html/plugins/' . basename(__DIR__) . '/abfahrt_lib.php';
} else {
    // entpacktes Archiv: bin/ und webfrontend/ liegen nebeneinander
    $abf_kandidaten[] = dirname(__DIR__) . '/webfrontend/html/abfahrt_lib.php';
}

$abf_lib = '';
foreach ($abf_kandidaten as $abf_k) {
    if (is_file($abf_k)) { $abf_lib = $abf_k; break; }
}
if ($abf_lib === '') {
    fwrite(STDERR, "Abfahrts-Assistent: abfahrt_lib.php nicht gefunden. Gesucht wurde in:\n");
    foreach ($abf_kandidaten as $abf_k) { fwrite(STDERR, '  ' . $abf_k . "\n"); }
    exit(1);
}
require_once $abf_lib;


/**
 * Selbstpruefung ohne Netz und ohne Kartendienst.
 *
 * ANGELEGT 31.08.2026. Bis dahin gab es sie nicht: der Dienst nahm
 * "--selbsttest" als unbekannte Betriebsart, lief in den Taktzweig, sagte
 * nichts und endete mit 0. freigabe_pruefen.py meldete dafuer
 * "keine auswertbare Ausgabe" - in jeder Fassung dieser Linie.
 *
 * Markenform, weil das die Form fuer eine EINRICHTUNGSpruefung ist: je Zeile
 * eine Frage, [OK] oder [FEHL] davor. Der Rueckgabewert ist 1, sobald eine
 * Zeile [FEHL] traegt.
 *
 * Was hier NICHT geprueft wird: der Kartendienst und die Kalender-Adressen.
 * Beides kostet Kontingent beziehungsweise fremde Last, und ein Selbsttest,
 * der Kosten verursacht, wird beim zweiten Mal nicht mehr aufgerufen.
 */
function abfahrt_selbsttest(array $abfcfg)
{
    $zeilen = array();
    $fehler = 0;

    $zeilen[] = '[OK]   PHP ' . PHP_VERSION;

    foreach (array('json', 'curl') as $erw) {
        if (extension_loaded($erw)) {
            $zeilen[] = '[OK]   ' . sprintf(abfahrt_t('SELBST.ERW_DA'), $erw);
        } else {
            $fehler++;
            $zeilen[] = '[FEHL] ' . sprintf(abfahrt_t('SELBST.ERW_FEHLT'), $erw);
        }
    }

    /* abfahrt_paths() fuehrt zwei Schluessel: die Konfigurationsdatei und den
     * Zwischenordner. Geprueft wird das VERZEICHNIS der Datei, nicht die
     * Datei - beim ersten Start gibt es sie noch nicht, und das ist kein
     * Fehler. Das Protokoll kommt aus abfahrt_logfile(). */
    $p = abfahrt_paths();
    $orte = array(
        'SELBST.O_CONFIG' => dirname($p['config']),
        'SELBST.O_TMP'    => $p['tmp'],
        'SELBST.O_LOG'    => dirname(abfahrt_logfile()),
    );
    foreach ($orte as $name => $pfad) {
        $ok = is_dir($pfad) && is_writable($pfad);
        $zeilen[] = ($ok ? '[OK]   ' : '[FEHL] ')
                  . sprintf(abfahrt_t('SELBST.ORDNER'), abfahrt_t($name), $pfad);
        if (!$ok) {
            $fehler++;
        }
    }

    $kal = isset($abfcfg['calendars']) && is_array($abfcfg['calendars'])
         ? count($abfcfg['calendars']) : 0;
    if ($kal > 0) {
        $zeilen[] = '[OK]   ' . sprintf(abfahrt_t('SELBST.KALENDER'), $kal);
    } else {
        $fehler++;
        $zeilen[] = '[FEHL] ' . abfahrt_t('SELBST.KEIN_KALENDER');
    }

    /* Der Schluessel wird NUR auf "vorhanden" geprueft, nie ausgegeben - und
     * auch seine Laenge nicht, die verriete den Anbieter. */
    if (trim((string) (isset($abfcfg['api_key']) ? $abfcfg['api_key'] : '')) !== '') {
        $zeilen[] = '[OK]   ' . sprintf(abfahrt_t('SELBST.SCHLUESSEL_DA'),
            (string) (isset($abfcfg['provider']) ? $abfcfg['provider'] : '?'));
    } else {
        $fehler++;
        $zeilen[] = '[FEHL] ' . abfahrt_t('SELBST.SCHLUESSEL_FEHLT');
    }

    if (trim((string) (isset($abfcfg['home_address']) ? $abfcfg['home_address'] : '')) !== '') {
        $zeilen[] = '[OK]   ' . abfahrt_t('SELBST.HEIM_DA');
    } else {
        $fehler++;
        $zeilen[] = '[FEHL] ' . abfahrt_t('SELBST.HEIM_FEHLT');
    }

    /* Das MQTT-Gateway. Massgeblich ist Gatewayautostart, nicht Brokerhost -
     * der steht ab Werk auf localhost und beantwortet die Frage nicht. */
    if (empty($abfcfg['mqtt_ein'])) {
        $zeilen[] = '[INFO] ' . abfahrt_t('SELBST.MQTT_AUS');
    } else {
        $gw = abfahrt_mqtt_zustand();
        if (empty($gw['gefunden'])) {
            $zeilen[] = '[INFO] ' . abfahrt_t('SELBST.MQTT_UNBEKANNT');
        } elseif (empty($gw['autostart'])) {
            $fehler++;
            $zeilen[] = '[FEHL] ' . abfahrt_t('SELBST.MQTT_AUTOSTART');
        } else {
            $zeilen[] = '[OK]   ' . sprintf(abfahrt_t('SELBST.MQTT_AN'),
                (int) $gw['fassung'] > 0 ? (int) $gw['fassung']
                                         : abfahrt_t('SELBST.MQTT_FASSUNG_UNBEKANNT'));
        }
    }

    echo implode("\n", $zeilen) . "\n";
    return $fehler ? 1 : 0;
}

$modus = isset($argv[1]) ? (string) $argv[1] : 'takt';

/* Aus der Deinstallation (uninstall/uninstall): die zurueckbehaltenen
 * MQTT-Themen der Linie leeren (abfahrt_mqtt_leeren()). Keine Rechnung, keine
 * Sperre, keine Datei. */
if ($modus === '--mqtt-leeren') {
    exit(abfahrt_mqtt_leeren());
}
$abfcfg = abfahrt_config();

if ($modus === '--selbsttest') {
    exit(abfahrt_selbsttest($abfcfg));
}

if ($modus === 'zeile') {
    echo abfahrt_zeile(abfahrt_stand(), $abfcfg) . "\n";
    exit(0);
}

/* Rechnen und Senden nur mit den Pfaden der Anlage. Aus einem ausgepackten
 * Archiv oder ohne Wurzel steigt der Dienst hier aus - vor der Sperre, denn
 * schon die legt eine Datei an. Bis 1.6.12 lief er auch dann: aus einem
 * Archiv unter der Anlage schrieb er in deren Protokoll und sendete an deren
 * Gateway, aus einem fremden Baum schrieb er dort hinein (in WSL gemessen,
 * Pruefung-Abfahrtsassistent-1.6.13, Faelle B6, H2, C8). */
abfahrt_keine_wurzel_abbruch('abfahrt_dienst.php');

/* Nur ein Lauf gleichzeitig - sonst fragen zwei Laeufe denselben
   Kartendienst und verbrauchen zwei Kontingente fuer eine Antwort. */
$sperre = abfahrt_tmpdir() . '/dienst.lock';
$fh = @fopen($sperre, 'c');
if ($fh === false) {
    /* Nicht lautlos. Eintrittsweg: ein Handstart als root hinterlaesst die
     * Sperrdatei als root:root, und jeder weitere Cron-Lauf als loxberry
     * scheiterte hier mit Rueckgabewert 1 - ohne eine Zeile, obwohl der Cron
     * die Fehlerausgabe seit 1.6.8 nach cron.err umlenkt. */
    $abf_msg = 'Abfahrts-Assistent: Sperrdatei ' . $sperre . ' laesst sich nicht oeffnen'
             . (is_file($sperre) && function_exists('posix_getpwuid')
                ? ' (gehoert ' . (string) (@posix_getpwuid((int) @fileowner($sperre))['name'] ?? '?') . ')' : '')
             . '. Der Dienst rechnet nicht.';
    fwrite(STDERR, $abf_msg . "\n");
    abfahrt_log($abf_msg);
    exit(1);
}
if (!flock($fh, LOCK_EX | LOCK_NB)) {
    /* Belegt: ein anderer Lauf rechnet noch. Bis 1.6.13 endete jeder weitere
     * Lauf hier ohne eine Zeile - hing ein Lauf, rechnete keiner mehr, und im
     * Protokoll stand nichts (in WSL gemessen, Pruefung-Abfahrtsassistent-1.6.14,
     * Faelle S3-S5). Die Sperrdatei traegt Startzeit und PID des Halters
     * (gleich unten). Laeuft er nachweislich laenger als 10 Minuten: eine Zeile
     * je Stunde ins Protokoll und nach stderr. Beendet wird er nicht. Ein
     * Inhalt, der nicht genau "<Unixzeit> <PID>" ist, bleibt still - mit ihm
     * wird nicht gerechnet (Fall S6). */
    $abf_halter = trim((string) @file_get_contents($sperre));
    if (preg_match('/^([0-9]{1,12}) ([0-9]{1,10})$/', $abf_halter, $abf_m)
        && time() - (int) $abf_m[1] > 600) {
        $abf_msg = 'Abfahrts-Assistent: Ein Lauf (PID ' . $abf_m[2] . ') haelt die Sperre seit '
                 . (int) floor((time() - (int) $abf_m[1]) / 60) . ' Minuten; bis er endet, rechnet'
                 . ' kein weiterer Lauf. Er wird nicht beendet.';
        if (abfahrt_log_gedrosselt('dienst_sperre_lang', $abf_msg, 3600)) {
            fwrite(STDERR, $abf_msg . "\n");
        }
    }
    exit(0);
}
/* Startzeit und PID des Halters - fuer einen Lauf, der die Sperre belegt findet. */
@ftruncate($fh, 0);
@fwrite($fh, time() . ' ' . getmypid() . "\n");
@fflush($fh);

/* Konfiguration pruefen und, wo noetig, heilen - einmal, gemeldet
 * (abfahrt_config_heilen() in der Bibliothek). Hier und in der Oberflaeche,
 * nie im unangemeldeten Endpunkt. Danach neu lesen. */
abfahrt_config_heilen();
$abfcfg = abfahrt_config();

/* Das Lebenszeichen geht bei JEDEM Lauf hinaus, auch wenn gleich nicht
 * gerechnet wird (Hausstandard, Regeln/07). */
abfahrt_lebenszeichen($abfcfg, abfahrt_stand());

/**
 * Wie oft wird ueberhaupt neu gerechnet?
 *
 * Das Einlesen der Kalender kostet wenig, die Route kostet Kontingent - und
 * die bremst sich ohnehin selbst (abfahrt_route_ttl). Trotzdem muss nicht
 * jede Minute der ganze iCal-Satz durchgekaut werden, solange der Termin
 * weit weg ist. Naeher als eine Stunde: jede Minute, damit der Countdown
 * stimmt. Sonst alle fuenf Minuten.
 */
$stand = abfahrt_stand();
$alter = $stand['zeit'] > 0 ? time() - (int) $stand['zeit'] : 999999;
$nah = ((int) $stand['abfahrt_in'] <= 60 && (int) $stand['ok'] === 1);
$faellig = ($modus === 'jetzt') || $alter >= ($nah ? 55 : 295);

if (!$faellig) {
    flock($fh, LOCK_UN);
    fclose($fh);
    exit(0);
}

// Streuung: Cron startet zur Sekunde 00. Ohne sie schlagen alle
// Installationen, die gerade faellig sind, in derselben Sekunde beim
// Kartendienst auf - und nach einer Stoerung, wenn alle zugleich wieder
// anlaufen, erst recht. Beim erzwungenen Lauf entfaellt sie.
if ($modus !== 'jetzt') { usleep(mt_rand(0, 3000000)); }

list($st, $diag) = abfahrt_berechnen($abfcfg);

/* EINE PROTOKOLLZEILE, WENN SICH ETWAS STRUKTURELLES AENDERT.
 *
 * Bis 1.6.6 rief dieser Dienst abfahrt_log() kein einziges Mal - gemessen am
 * 04.09.2026: nach einem vollstaendigen Rechenlauf war das Protokoll leer.
 * Zusammen mit dem >/dev/null des Cron hiess das: der Dienst, der jede
 * Minute unbeaufsichtigt laeuft, hinterlaesst keine Spur. Genau daran ist
 * der Ausfall von 1.5.0 bis 1.5.7 monatelang unbemerkt geblieben.
 *
 * Geschrieben wird nur bei einem Wechsel von OK oder FEHLER - die Zahlen
 * wandern jede Minute, das Protokoll soll deswegen nicht volllaufen. */
$abf_sig = 'OK=' . (int) $st['ok'] . ';FEHLER=' . (int) $st['fehler'];
$abf_sigdatei = abfahrt_tmpdir() . '/dienst_letzte.txt';
$abf_vorher = is_file($abf_sigdatei) ? trim((string) @file_get_contents($abf_sigdatei)) : '';
if ($abf_sig !== $abf_vorher) {
    abfahrt_log('Dienst: ' . $abf_sig
              . ($st['grund'] !== '' ? ' - ' . $st['grund'] : '')
              . ($st['titel'] !== '' ? ' (' . $st['titel'] . ')' : ''));
    foreach ($diag as $abf_d) { abfahrt_log('   ' . $abf_d); }
    @file_put_contents($abf_sigdatei, $abf_sig);
}

/* MQTT nur bei Aenderung.
 *
 * Der Countdown aendert sich zwar jede Minute, aber Loxone braucht ihn auch
 * jede Minute. Was NICHT jede Minute gesendet werden muss, sind die
 * unveraenderten Freigaben und Fehlerzustaende - die stehen sonst
 * hundertmal am Tag gleich im Broker. Verglichen wird deshalb feldweise. */
$werte = abfahrt_werte($st, $abfcfg);
$merker = abfahrt_tmpdir() . '/mqtt_letzte.json';
$vorher = @json_decode((string) @file_get_contents($merker), true);
if (!is_array($vorher)) { $vorher = []; }

/* Vollversand im Takt (neu in 1.6.0; ab Werk alle 15 Minuten).
 *
 * BERICHTIGT 1.6.10: hier stand "ab Werk aus" und "ab Werk bleibt es beim
 * Senden nur bei Aenderung". Die Vorgabe in abfahrt_vorgaben() ist seit 1.6.0
 * 15 Minuten - gemessen am Geraet am 06.09.2026 (wirksamer Wert 15).
 *
 * WOZU: Startet der MINISERVER neu, ohne dass der LoxBerry neu startet, sind
 * seine virtuellen Eingaenge leer - und weil hier nur bei Aenderung gesendet
 * wird, bleiben sie es, bis sich zufaellig ein Wert bewegt. Von 1.6.8 bis
 * 1.6.12 gingen die Zustaende retained hinaus; seit 1.6.13 geht nichts mehr
 * zurueckbehalten hinaus (Regeln/07, Abschnitt 3, siehe abfahrt_felder()),
 * und der Vollversand ist der Weg, auf dem ein neu gestarteter Miniserver
 * seine Werte wiederbekommt. Er heilt zusaetzlich jedes Datagramm, das der
 * UDP-Eingang des Gateways verworfen hat.
 *
 * DIE ZEITMARKE IST EINE EIGENE DATEI (seit 1.6.10). Bis dahin diente die
 * Aenderungszeit von mqtt_letzte.json als Marke - und die wird bei JEDEM
 * Versand fortgeschrieben. Laeuft ein Countdown, aendert sich MINSTART jede
 * Minute, und der Vollversand wurde nie faellig.
 */
$stempel = abfahrt_tmpdir() . '/mqtt_voll.stamp';
$voll = false;
$vollAlle = (int) $abfcfg['mqtt_vollsend_min'];
if ($vollAlle > 0) {
    $letzterVoll = is_file($stempel) ? (int) filemtime($stempel) : 0;
    if (time() - $letzterVoll >= $vollAlle * 60) {
        $voll = true;
    }
}

$neu = [];
foreach ($werte as $k => $v) {
    if (!abfahrt_feld_mqtt($k)) { continue; }   // ALTER nur ueber HTTP
    if ($voll || !array_key_exists($k, $vorher) || (string) $vorher[$k] !== (string) $v) {
        $neu[$k] = $v;
    }
}
/* Altwerte der Vorfassungen abraeumen. Bis 1.6.12 gingen OK, FEHLER, AUDIO
 * und PUSH zurueckbehalten hinaus; ein spaeteres "publish" ersetzt den
 * zurueckbehaltenen Wert im Broker nicht - er stuende nach jedem Neustart von
 * Broker oder Gateway wieder da, auch wenn dieser Dienst laengst nicht mehr
 * laeuft. abfahrt_mqtt_altlast() fragt den Broker; die Themen, die er noch
 * haelt (oder alle vier, wenn er nicht zu fragen ist), gehen in DIESEM Lauf
 * mit, jeweils mit der leeren Nutzlast unmittelbar vor dem Wert - auch wenn
 * sich ihr Wert nicht geaendert hat. Einen Merker gibt es erst, wenn der
 * Broker bestaetigt, dass keines mehr dasteht; auf den Sendeerfolg baut er
 * nicht (der UDP-Eingang verwirft stumm, Regeln/07). In WSL gemessen:
 * Pruefung-Abfahrtsassistent-1.6.13, Faelle R7 bis R22. */
$abf_raeumen = array();
if (!empty($abfcfg['mqtt_ein'])) {
    $abf_alt = abfahrt_mqtt_altlast($abfcfg);
    foreach ($abf_alt['themen'] as $abf_t) {
        if (array_key_exists($abf_t, $werte) && abfahrt_feld_mqtt($abf_t)) {
            $neu[$abf_t] = $werte[$abf_t];
            $abf_raeumen[$abf_t] = true;
        }
    }
}
if ($neu) {
    /* Merker und Zeitmarke nur fortschreiben, wenn wirklich abgeschickt
     * wurde. Bis 1.6.9 wurde der Rueckgabewert nicht angesehen: war das
     * Gateway kurz weg, galten die Werte trotzdem als gesendet, und die
     * unveraenderten Zustaende kamen erst nach der naechsten Frist. Dass
     * "abgeschickt" nicht "angekommen" heisst, steht bei
     * abfahrt_mqtt_senden(). */
    $abf_raus = abfahrt_mqtt_senden($neu, $abfcfg, array(), $abf_raeumen);
    if ($abf_raus !== false) {
        $js = json_encode($werte);
        if ($js !== false) { abfahrt_cache_schreiben($merker, $js); }
        if ($voll) { @touch($stempel); }
    } elseif (!empty($abfcfg['mqtt_ein'])) {
        abfahrt_log_gedrosselt('mqtt_fehl', 'MQTT: Versand nicht moeglich - naechster Versuch im naechsten Lauf.', 900);
    }
} elseif ($voll) {
    // Nichts zu senden, aber die Frist ist abgelaufen - Zeitstempel
    // trotzdem fortschreiben, sonst laeuft der Vollversand jede Minute an.
    @touch($stempel);
}

flock($fh, LOCK_UN);
fclose($fh);

/* RUECKGABEWERT 0 HEISST "DER LAUF IST DURCHGEKOMMEN", nicht "es gibt einen
 * Termin".
 *
 * Bis 1.6.6 stand hier exit((int) $st['ok'] ? 0 : 1). ok=0 ist unter anderem
 * FEHLER=4 "kein Termin mit Ort im Zeitfenster" - der Normalfall an jedem
 * terminfreien Tag. Der Cron endete damit alle fuenf Minuten mit 1, und wer
 * den Dienst nach der Installation einmal von Hand startet und den
 * Rueckgabewert ansieht (Hausregel), konnte "nichts zu tun" nicht von
 * "abgestuerzt" unterscheiden. installationslage_pruefen.py meldete es als
 * "Abbruch ohne Meldung, Rueckgabewert 1".
 *
 * Ein echter Fehlschlag endet weiterhin mit 1 - die fehlende Bibliothek
 * ganz oben, und die Selbstpruefung mit --selbsttest. Was der Lauf
 * inhaltlich gefunden hat, steht in der Statuszeile (OK und FEHLER), nicht
 * im Rueckgabewert. */
exit(0);
