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
 *   abfahrt_dienst.php          aus dem Cron
 *   abfahrt_dienst.php jetzt    Takt umgehen
 *   abfahrt_dienst.php zeile    Statuszeile ausgeben, ohne zu rechnen
 */

error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);
require_once dirname(__DIR__) . '/webfrontend/html/abfahrt_lib.php';

$modus = isset($argv[1]) ? (string) $argv[1] : 'takt';
$abfcfg = abfahrt_config();

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

/* MQTT nur bei Aenderung.
 *
 * Der Countdown aendert sich zwar jede Minute, aber Loxone braucht ihn auch
 * jede Minute. Was NICHT jede Minute gesendet werden muss, sind die
 * unveraenderten Freigaben und Fehlerzustaende - die stehen sonst
 * hundertmal am Tag gleich im Broker. Verglichen wird deshalb feldweise. */
$werte = abfahrt_werte($st, $abfcfg);
$vorher = @json_decode((string) @file_get_contents(abfahrt_tmpdir() . '/mqtt_letzte.json'), true);
if (!is_array($vorher)) { $vorher = []; }
$neu = [];
foreach ($werte as $k => $v) {
    if (!array_key_exists($k, $vorher) || (string) $vorher[$k] !== (string) $v) { $neu[$k] = $v; }
}
if ($neu) {
    abfahrt_mqtt_senden($neu, $abfcfg);
    $js = json_encode($werte);
    if ($js !== false) { @file_put_contents(abfahrt_tmpdir() . '/mqtt_letzte.json', $js); }
}

flock($fh, LOCK_UN);
fclose($fh);
exit((int) $st['ok'] ? 0 : 1);
