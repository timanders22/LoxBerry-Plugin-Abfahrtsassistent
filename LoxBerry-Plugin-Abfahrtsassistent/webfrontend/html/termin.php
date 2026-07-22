<?php
/**
 * Abfahrts-Assistent - Miniserver-Endpunkt
 *
 * Aufruf:  http://<loxberry-ip>/plugins/abfahrtsassistent/termin.php
 *          http://<loxberry-ip>/plugins/abfahrtsassistent/termin.php?debug=1
 *
 * Ausgabe (Flat-Text fuer Virtuellen HTTP-Eingang):
 *   TERMIN;OK=1;MINSTART=42;FAHRT=17.5;ABFAHRT_IN=10
 *
 *   MINSTART   = Minuten bis zum Beginn des naechsten Termins mit Ortsangabe
 *   FAHRT      = aktuelle Fahrzeit dorthin in Minuten (inkl. Verkehrslage)
 *   ABFAHRT_IN = Minuten bis zur empfohlenen Abfahrt
 *                (= MINSTART - FAHRT - Ankunftsreserve - Pufferzeit)
 *   OK         = 1 wenn Termin+Route berechnet, sonst 0 (dann MINSTART=9999)
 *
 * Loxone-Muster: \iMINSTART=\i\v  bzw. \iABFAHRT_IN=\i\v
 */

require_once __DIR__ . '/abfahrt_lib.php';
header('Content-Type: text/plain; charset=utf-8');
$debug = isset($_GET['debug']);

function abfahrt_flags() {
    global $abfcfg;
    $why = '';
    $audio = abfahrt_audio_allowed($abfcfg, $why) ? 1 : 0;
    $push = empty($abfcfg['notify']['push']) ? 0 : 1;
    return ";AUDIO=$audio;PUSH=$push";
}

function abfahrt_log_if_changed($line) {
    $f = abfahrt_tmpdir() . '/last_result.txt';
    $prev = is_file($f) ? trim((string) file_get_contents($f)) : '';
    // Zahlen aendern sich staendig - nur strukturelle Aenderung loggen (OK/Terminwechsel)
    $sig = preg_replace('/MINSTART=[-0-9.]+|FAHRT=[-0-9.]+|ABFAHRT_IN=[-0-9.]+/', '', $line);
    if ($sig !== $prev) {
        abfahrt_log('Ergebnis: ' . $line);
        @file_put_contents($f, $sig);
    }
}

function abfahrt_fail($why, $debug) {
    if ($debug) {
        echo "DEBUG: $why\n";
    }
    $out = "TERMIN;OK=0;MINSTART=9999;FAHRT=0;ABFAHRT_IN=9999" . abfahrt_flags();
    abfahrt_log_if_changed($out . ' (' . $why . ')');
    echo $out . "\n";
    exit;
}

$abfcfg = abfahrt_config();

$hasCal = false;
foreach ($abfcfg['calendars'] as $cal) {
    if (trim((string) ($cal['url'] ?? '')) !== '') {
        $hasCal = true;
        break;
    }
}
if (!$hasCal) {
    abfahrt_fail('Kein Kalender konfiguriert (Plugin-Oberflaeche oeffnen).', $debug);
}
if (trim($abfcfg['api_key']) === '') {
    abfahrt_fail('Kein API-Key konfiguriert (Plugin-Oberflaeche oeffnen).', $debug);
}
if (trim($abfcfg['home_address']) === '') {
    abfahrt_fail('Keine Abfahrtsadresse konfiguriert (Plugin-Oberflaeche oeffnen).', $debug);
}

$diag = [];
$best = abfahrt_next_event($abfcfg, $diag);
if ($debug) {
    foreach ($diag as $d) {
        echo "DEBUG: $d\n";
    }
}
if ($best === null) {
    abfahrt_fail('Kein Termin mit Ort in den naechsten ' . (int) $abfcfg['lookahead_hours'] . ' Stunden.', $debug);
}
list($ts, $loc, $sum, $calname) = $best;
$minstart = (int) round(($ts - time()) / 60);

$err = '';
$fahrt = abfahrt_route_minutes($loc, $abfcfg, $err);
if ($fahrt === false) {
    abfahrt_fail($err, $debug);
}

$abfahrtIn = (int) round($minstart - $fahrt - (int) $abfcfg['arrival_min'] - (int) $abfcfg['buffer_min']);

// Titel + Details fuer die Ansage (termin_say.php) cachen
@file_put_contents(abfahrt_tmpdir() . '/titel.json', json_encode([
    'titel' => $sum,
    'ort' => $loc,
    'kalender' => $calname,
    'beginn' => date('d.m.Y H:i', $ts),
    'minstart' => $minstart,
    'fahrt' => $fahrt,
    'abfahrt_in' => $abfahrtIn,
], JSON_UNESCAPED_UNICODE));

if ($debug) {
    echo "DEBUG Termin: $sum\n";
    echo "DEBUG Kalender: $calname\n";
    echo "DEBUG Ort: $loc\n";
    echo 'DEBUG Beginn: ' . date('d.m.Y H:i', $ts) . " (in $minstart min)\n";
    echo "DEBUG Fahrzeit: $fahrt min (" . $abfcfg['provider'] . ")\n";
    echo 'DEBUG Ankunftsreserve: ' . (int) $abfcfg['arrival_min'] . " min, Puffer: " . (int) $abfcfg['buffer_min'] . " min\n";
    echo "DEBUG Abfahrt in: $abfahrtIn min\n\n";
}
$out = "TERMIN;OK=1;MINSTART=$minstart;FAHRT=$fahrt;ABFAHRT_IN=$abfahrtIn" . abfahrt_flags();
abfahrt_log_if_changed($out . ' (' . $sum . ')');
echo $out . "\n";
