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
 *     /opt/loxberry/bin/plugins/<ordner>/abfahrt_dienst.php
 *     /opt/loxberry/webfrontend/html/plugins/<ordner>/abfahrt_lib.php
 *
 * dirname(__DIR__) ergibt dort /opt/loxberry/bin/plugins, gesucht wurde also
 * /opt/loxberry/bin/plugins/webfrontend/html/abfahrt_lib.php. Die gibt es
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
$abf_lb = getenv('LBHOMEDIR');
$abf_ordner = getenv('LBPPLUGINDIR') ?: basename(__DIR__);
$abf_kandidaten = array();
if ($abf_lb) {
    $abf_kandidaten[] = $abf_lb . '/webfrontend/html/plugins/' . $abf_ordner . '/abfahrt_lib.php';
}
// installiert, ohne dass die Umgebungsvariablen gesetzt waeren:
// .../bin/plugins/<ordner>  ->  .../webfrontend/html/plugins/<ordner>
$abf_kandidaten[] = dirname(dirname(dirname(__DIR__)))
                  . '/webfrontend/html/plugins/' . basename(__DIR__) . '/abfahrt_lib.php';
// entpacktes Archiv: bin/ und webfrontend/ liegen nebeneinander
$abf_kandidaten[] = dirname(__DIR__) . '/webfrontend/html/abfahrt_lib.php';

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
$abfcfg = abfahrt_config();

if ($modus === '--selbsttest') {
    exit(abfahrt_selbsttest($abfcfg));
}

if ($modus === 'zeile') {
    echo abfahrt_zeile(abfahrt_stand(), $abfcfg) . "\n";
    exit(0);
}

/* Nur ein Lauf gleichzeitig - sonst fragen zwei Laeufe denselben
   Kartendienst und verbrauchen zwei Kontingente fuer eine Antwort. */
$sperre = abfahrt_tmpdir() . '/dienst.lock';
$fh = @fopen($sperre, 'c');
if ($fh === false) { exit(1); }
if (!flock($fh, LOCK_EX | LOCK_NB)) { exit(0); }

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

/* Vollversand im Takt (neu in 1.6.0, ab Werk aus).
 *
 * WOZU: Startet der MINISERVER neu, ohne dass der LoxBerry neu startet, sind
 * seine virtuellen Eingaenge leer - und weil hier nur bei Aenderung gesendet
 * wird, bleiben sie es, bis sich zufaellig ein Wert bewegt. Bei einem Termin,
 * der erst in Stunden ansteht, kann das Stunden dauern.
 *
 * Umgekehrt ist Dauersenden auch nichts: dieselben unveraenderten Freigaben
 * stuenden hundertmal am Tag im Broker. Deshalb ein einstellbarer Abstand,
 * und ab Werk bleibt es beim Senden nur bei Aenderung.
 */
$voll = false;
$vollAlle = (int) $abfcfg['mqtt_vollsend_min'];
if ($vollAlle > 0) {
    $letzterVoll = is_file($merker) ? (int) filemtime($merker) : 0;
    if (time() - $letzterVoll >= $vollAlle * 60) {
        $voll = true;
    }
}

$neu = [];
foreach ($werte as $k => $v) {
    if ($voll || !array_key_exists($k, $vorher) || (string) $vorher[$k] !== (string) $v) {
        $neu[$k] = $v;
    }
}
if ($neu) {
    abfahrt_mqtt_senden($neu, $abfcfg);
    $js = json_encode($werte);
    if ($js !== false) { @file_put_contents($merker, $js); }
} elseif ($voll) {
    // Nichts zu senden, aber die Frist ist abgelaufen - Zeitstempel
    // trotzdem fortschreiben, sonst laeuft der Vollversand jede Minute an.
    @touch($merker);
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
