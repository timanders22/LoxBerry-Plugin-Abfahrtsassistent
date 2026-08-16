<?php
/**
 * Abfahrts-Assistent - Miniserver-Endpunkt
 *
 * Aufruf:  http://<loxberry-ip>/plugins/abfahrtsassistent/termin.php
 *          http://<loxberry-ip>/plugins/abfahrtsassistent/termin.php?debug=1
 *
 * Ausgabe (Flat-Text fuer Virtuellen HTTP-Eingang):
 *   TERMIN;OK=1;MINSTART=42;FAHRT=17.5;ABFAHRT_IN=10;FEHLER=0;ALTER=23;AUDIO=1;PUSH=1
 *
 *   MINSTART   = Minuten bis zum Beginn des naechsten Termins mit Ortsangabe
 *   FAHRT      = aktuelle Fahrzeit dorthin in Minuten (inkl. Verkehrslage)
 *   ABFAHRT_IN = Minuten bis zur empfohlenen Abfahrt
 *                (= MINSTART - FAHRT - Ankunftsreserve - Pufferzeit)
 *   OK         = 1 wenn Termin+Route berechnet, sonst 0 (dann MINSTART=9999)
 *   ALTER      = Alter der Berechnung in Sekunden - fuer die Ausfallerkennung
 *   FEHLER     = 0 kein Fehler
 *                1 kein Kalender eingerichtet
 *                2 kein API-Key eingerichtet
 *                3 keine Abfahrtsadresse eingerichtet
 *                4 kein Termin mit Ort im Zeitfenster
 *                6 Kartendienst nicht erreichbar (keine Fahrzeit)
 *                7 Kartendienst nicht erreichbar, letzte bekannte Fahrzeit
 *                  wird weiterbenutzt - OK bleibt 1, die Werte gelten
 *
 * WARUM EINE ZAHL UND KEIN TEXT bei FEHLER: In Loxone laesst sich eine Zahl
 * auf einen Statusbaustein legen und dort in Klartext uebersetzen. Eine
 * Zeichenkette kann ein virtueller Eingang nicht auswerten - der Grund
 * stuende dann nur im Protokoll, wo ihn niemand sucht.
 *
 * DIESER ENDPUNKT RECHNET NICHT.
 * Er gibt den Zwischenstand aus, den bin/abfahrt_dienst.php im Minutentakt
 * fortschreibt. Frueher rechnete er bei jedem Aufruf selbst - damit haing die
 * Zahl der Anfragen an TomTom daran, wie oft Loxone fragt, und ein zweiter
 * Abfrager verdoppelte sie. Wer trotzdem eine frische Berechnung braucht,
 * ruft mit ?debug=1 auf; das ist eine Handlung eines Menschen und darf kosten.
 *
 * Der bevorzugte Weg nach Loxone ist ohnehin MQTT (Reiter MQTT im Plugin) -
 * dieser Endpunkt bleibt fuer alle, die den virtuellen HTTP-Eingang gewohnt
 * sind, und als Gegenprobe.
 *
 * Loxone-Muster: \i;MINSTART=\i\v  bzw. \i;ABFAHRT_IN=\i\v  bzw. \i;FEHLER=\i\v
 * Das fuehrende Semikolon gehoert dazu: ohne es faende "FAHRT=" auch die
 * Stelle in "ABFAHRT_IN=".
 */

require_once __DIR__ . '/abfahrt_lib.php';
header('Content-Type: text/plain; charset=utf-8');
header('Cache-Control: no-store');
$debug = isset($_GET['debug']);

$abfcfg = abfahrt_config();

/* ?debug=1 rechnet neu und fragt dabei den Kartendienst - das kostet
 * Kontingent, bei TomTom ein Tageslimit, bei Google Geld. Diese Datei liegt
 * im unangemeldeten Bereich, damit Loxone sie ohne Zugangsdaten erreicht.
 * Ohne Pruefung koennte jeder im Netz das Kontingent leerlaufen lassen.
 *
 * Der reine Leseaufruf ohne Parameter bleibt frei: er gibt nur den
 * Zwischenstand aus, den der Hintergrunddienst ohnehin fortschreibt, und
 * genau den holt Loxone zyklisch ab. */
if ($debug && !abfahrt_token_ok($abfcfg)) {
    abfahrt_token_abweisen('TERMIN', $abfcfg);
}

/* ---------- Selbsttest: Merkwort pruefen, ohne zu rechnen ----------
 *
 * Auch dieser Endpunkt ist tokengeschuetzt (?debug=1 rechnet neu und fragt
 * dabei den Kartendienst), also bekommt auch er seinen Selbsttest - so
 * verlangt es der Hausstandard fuer JEDEN tokengeschuetzten Endpunkt im
 * unangemeldeten Bereich.
 *
 * Der Zweig steht bewusst HINTER der Token-Pruefung: ein falsches Merkwort
 * bekommt dieselbe Abweisung wie sonst auch, der Selbsttest ist keine
 * Abkuerzung an der Sicherheit vorbei. Er fragt aber seinerseits nach dem
 * Merkwort, damit er auch ohne ?debug=1 nicht offensteht. */
if (isset($_GET['selftest'])) {
    if (!abfahrt_token_ok($abfcfg)) {
        abfahrt_token_abweisen('SELFTEST', $abfcfg);
    }
    echo "SELFTEST;OK=1;TOKEN=OK\n";
    exit;
}

$diag = [];
if ($debug) {
    // Nur im Debug-Fall wird gerechnet - und dann bewusst und sichtbar.
    list($st, $diag) = abfahrt_berechnen($abfcfg);
} else {
    $st = abfahrt_stand();
}

if ($debug) {
    foreach ($diag as $d) {
        echo "DEBUG: $d\n";
    }
    if ((int) $st['zeit'] === 0) {
        echo "DEBUG: Noch keine Berechnung vorhanden. Laeuft der Cron?\n";
    }
    if ($st['grund'] !== '') {
        echo 'DEBUG: ' . $st['grund'] . "\n";
    }
    if ((int) $st['ok'] === 1) {
        echo 'DEBUG Termin: ' . $st['titel'] . "\n";
        echo 'DEBUG Kalender: ' . $st['kalender'] . "\n";
        echo 'DEBUG Ort: ' . $st['ort'] . "\n";
        echo 'DEBUG Beginn: ' . $st['beginn'] . ' (in ' . (int) $st['minstart'] . " min)\n";
        echo 'DEBUG Fahrzeit: ' . $st['fahrt'] . ' min (' . $abfcfg['provider'] . ")\n";
        echo 'DEBUG Ankunftsreserve: ' . (int) $abfcfg['arrival_min']
           . ' min, Puffer: ' . (int) $abfcfg['buffer_min'] . " min\n";
        echo 'DEBUG Abfahrt in: ' . (int) $st['abfahrt_in'] . " min\n";
        if ((int) $st['fehler'] === 7) {
            echo "DEBUG Hinweis: Kartendienst nicht erreichbar - Fahrzeit aus dem Zwischenspeicher (FEHLER=7)\n";
        }
    }
    echo "\n";
}

$out = abfahrt_zeile($st, $abfcfg);

/* Nur strukturelle Aenderungen protokollieren. Die Zahlen wandern jede Minute,
   das Protokoll soll deswegen nicht volllaufen - ein Wechsel bei OK oder
   FEHLER dagegen ist genau das, was man spaeter sucht. */
$f = abfahrt_tmpdir() . '/last_result.txt';
$sig = preg_replace('/(MINSTART|FAHRT|ABFAHRT_IN|ALTER)=[-0-9.]+/', '', $out);
$prev = is_file($f) ? trim((string) @file_get_contents($f)) : '';
if ($sig !== $prev) {
    abfahrt_log('Ergebnis: ' . $out . ($st['titel'] !== '' ? ' (' . $st['titel'] . ')' : '')
              . ($st['grund'] !== '' ? ' [' . $st['grund'] . ']' : ''));
    @file_put_contents($f, $sig);
}

echo $out . "\n";
