<?php
/**
 * Abfahrts-Assistent - Miniserver-Endpunkt
 *
 * Aufruf:  http://<loxberry-ip>/plugins/abfahrtsassistent/termin.php
 *          http://<loxberry-ip>/plugins/abfahrtsassistent/termin.php?debug=1
 *
 * Ausgabe (Flat-Text fuer Virtuellen HTTP-Eingang):
 *   TERMIN;OK=1;MINSTART=42;FAHRT=17.5;ABFAHRT_IN=10;FEHLER=0;ALTER=23;AUDIO=1;PUSH=1;ANKUNFT=754
 *
 *   MINSTART   = Minuten bis zum Beginn des naechsten Termins mit Ortsangabe
 *   FAHRT      = aktuelle Fahrzeit dorthin in Minuten (inkl. Verkehrslage)
 *   ABFAHRT_IN = Minuten bis zur empfohlenen Abfahrt
 *                (= MINSTART - FAHRT - Ankunftsreserve - Pufferzeit)
 *   OK         = 1 wenn Termin+Route berechnet, sonst 0 (dann MINSTART=9999).
 *                Seit 1.6.16 auch 0, sobald ALTER das Dreifache des
 *                Rechentakts uebersteigt (900 s, in der letzten Stunde vor der
 *                Abfahrt 180 s; abfahrt_ok_wirksam(), Entscheidung 4)
 *   ALTER      = Alter der Berechnung in Sekunden - fuer die Ausfallerkennung
 *   ANKUNFT    = Ankunftszeit in Minuten seit Mitternacht, 1440 = unbekannt
 *   FEHLER     = 0 kein Fehler
 *                1 kein Kalender eingerichtet
 *                2 kein API-Key eingerichtet
 *                3 keine Abfahrtsadresse eingerichtet
 *                4 kein Termin mit Ort im Zeitfenster
 *                5 Kalender nicht erreichbar - es gilt der letzte gelesene
 *                  Stand (neu in 1.6.7; OK bleibt 1, solange einer da war)
 *                6 Kartendienst nicht erreichbar (keine Fahrzeit)
 *                7 Kartendienst nicht erreichbar, letzte bekannte Fahrzeit
 *                  wird weiterbenutzt - OK bleibt 1, die Werte gelten
 *                8 kein einziger Kalender liess sich lesen, und es gibt
 *                  keinen gueltigen Stand mehr - OK=0 (neu in 1.6.10; bis
 *                  dahin trug dieser Fall ebenfalls die 5)
 *                9 kein frischer Stand: der Dienst hat noch nicht gerechnet,
 *                  rechnet nicht mehr (Stand aelter als die Grenze von OK)
 *                  oder konnte ihn nicht ablegen - OK=0 (neu in 1.6.22; bis
 *                  dahin stand hier OK=0;FEHLER=0)
 *
 *   ALTER steht nur in dieser Zeile, nicht ueber MQTT (seit 1.6.10): ueber
 *   MQTT war der Wert immer 0. Dort heisst die Ausfallerkennung
 *   abfahrt/status/ts.
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
/* ?debug=0 heisst AUS, nicht an.
 *
 * Vorher stand hier isset(). Damit schaltete jeder Wert die Funktion ein -
 * auch die 0. Bei ?debug=0 hiess das: es wird gerechnet und beim
 * Kartendienst angefragt, obwohl der Aufrufer das Gegenteil geschrieben hat;
 * bei ?force=0 in termin_say.php wurden die Sperrzeiten umgangen. Das blosse
 * ?debug ohne Wert bleibt eingeschaltet, dafuer ist es da. */
$debug = abfahrt_schalter('debug');

/* Nur-Lese-Betrieb, solange nicht ein Mensch mit Merkwort neu rechnet: dann
 * fragt die Sondertag-Pruefung weder das Ferien-Plugin noch schreibt sie einen
 * Zwischenspeicher (abfahrt_nur_lesen() in der Bibliothek). Bis 1.6.9 legte
 * ein anonymer GET ohne Parameter /tmp/abfahrt_daytype.json an - gegen die
 * Zusage weiter unten. */
abfahrt_nur_lesen(true);

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
if (abfahrt_schalter('selftest')) {
    if (!abfahrt_token_ok($abfcfg)) {
        abfahrt_token_abweisen('SELFTEST', $abfcfg);
    }
    echo "SELFTEST;OK=1;TOKEN=OK\n";
    exit;
}

$diag = [];
$abf_belegt = false;
if ($debug) {
    // Nur im Debug-Fall wird gerechnet - und dann bewusst und sichtbar.
    abfahrt_nur_lesen(false);
    /* Unter der Sperre des Dienstes (abfahrt_sperre()). Bis 1.6.21 rechnete
     * ?debug=1 daneben: doppeltes Kontingent beim Kartendienst und ein
     * Wettlauf um stand.json und titel.json (Pruefung 02.10.2026, Nr. 21).
     * Ist sie belegt, gilt der abgelegte Stand. */
    $abf_fh = abfahrt_sperre();
    if ($abf_fh !== false) {
        list($st, $diag) = abfahrt_berechnen($abfcfg);
        abfahrt_sperre_frei($abf_fh);
    } else {
        $abf_belegt = true;
        $st = abfahrt_stand();
    }
} else {
    $st = abfahrt_stand();
}

if ($debug) {
    if ($abf_belegt) {
        echo 'DEBUG: ' . abfahrt_t('DIAG.SPERRE_BELEGT') . "\n";
    }
    foreach ($diag as $d) {
        echo "DEBUG: $d\n";
    }
    /* b1 (Welle 2, 30.09.2026): die Zeilen aus [DIAG], der Grund aus der
     * Kennung des Stands. Der Vorsatz "DEBUG" bleibt in jeder Sprache gleich. */
    if ((int) $st['zeit'] === 0) {
        echo 'DEBUG: ' . abfahrt_t('DIAG.NOCH_KEINE') . "\n";
    }
    $abf_grund = abfahrt_grund_wirksam($st);
    if ($abf_grund !== '') {
        echo 'DEBUG: ' . $abf_grund . "\n";
    }
    if ((int) $st['ok'] === 1) {
        echo 'DEBUG ' . sprintf(abfahrt_t('DIAG.D_TERMIN'), $st['titel']) . "\n";
        echo 'DEBUG ' . sprintf(abfahrt_t('DIAG.D_KALENDER'), $st['kalender']) . "\n";
        echo 'DEBUG ' . sprintf(abfahrt_t('DIAG.D_ORT'), $st['ort']) . "\n";
        echo 'DEBUG ' . sprintf(abfahrt_t('DIAG.D_BEGINN'), $st['beginn'], (int) $st['minstart']) . "\n";
        echo 'DEBUG ' . sprintf(abfahrt_t('DIAG.D_FAHRZEIT'), $st['fahrt'], $abfcfg['provider']) . "\n";
        echo 'DEBUG ' . sprintf(abfahrt_t('DIAG.D_RESERVE'), (int) $abfcfg['arrival_min'], (int) $abfcfg['buffer_min']) . "\n";
        echo 'DEBUG ' . sprintf(abfahrt_t('DIAG.D_ABFAHRT_IN'), (int) $st['abfahrt_in']) . "\n";
        if ((int) $st['fehler'] === 7) {
            echo 'DEBUG ' . abfahrt_t('DIAG.D_HINWEIS_7') . "\n";
        }
    }
    echo "\n";
}

$out = abfahrt_zeile($st, $abfcfg);

/* Nur strukturelle Aenderungen protokollieren. Die Zahlen wandern jede Minute,
   das Protokoll soll deswegen nicht volllaufen - ein Wechsel bei OK oder
   FEHLER dagegen ist genau das, was man spaeter sucht.

   ANKUNFT GEHOERT IN DIESE LISTE, und bis 1.6.6 stand es nicht darin. Es ist
   seit 1.6.0 das neunte Feld und wandert jede Minute (Ankunftszeit in
   Minuten seit Mitternacht). Gemessen am 04.09.2026: drei Aufrufe, bei denen
   sich NUR ANKUNFT um je 1 erhoehte, ergaben drei Protokollzeilen; mit
   festem ANKUNFT eine einzige. Bei minuetlicher Abfrage lief das Protokoll
   damit voll, und die Rotation auf 200 Zeilen warf nach gut drei Stunden
   genau die OK-/FEHLER-Wechsel heraus, die man spaeter sucht.

   NICHTS ANLEGEN: Diese Datei liegt im unangemeldeten Bereich. Ein anonymer
   GET ohne Parameter hat bis 1.6.6 zwei Verzeichnisse und zwei Dateien
   entstehen lassen. Geschrieben wird deshalb nur, wo der Dienst seine
   Ablagen schon angelegt hat. */
$abf_tmp = abfahrt_tmpdir(false);
$abf_logdir = dirname(abfahrt_logfile(false));
if (is_dir($abf_tmp) && is_dir($abf_logdir)) {
    $f = $abf_tmp . '/last_result.txt';
    $sig = preg_replace('/(MINSTART|FAHRT|ABFAHRT_IN|ALTER|ANKUNFT)=[-0-9.]+/', '', $out);
    $prev = is_file($f) ? trim((string) @file_get_contents($f)) : '';
    if ($sig !== $prev) {
        $abf_sg = abfahrt_grund_wirksam($st);     // b1: Grund in der Sprache dieses Laufs
        /* Ohne den Terminnamen (Pruefung 02.10.2026, Nr. 12a): diese Zeile
         * loest auch ein anonymer Lesezugriff aus. */
        abfahrt_log('Ergebnis: ' . $out . ($abf_sg !== '' ? ' [' . $abf_sg . ']' : ''));
        @file_put_contents($f, $sig);
    }
}

echo $out . "\n";
