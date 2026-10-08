<?php
/**
 * Abfahrts-Assistent - Ansage-Endpunkt
 *
 * Wird vom Miniserver aufgerufen (Virtueller Ausgang), wenn die Abfahrt
 * ansteht. Spricht die Ansage ueber die konfigurierte Audio-Ausgabe:
 *
 *   - Loxone Music Server (klassisch): direkte TTS-URL (Port 7091)
 *   - MusicServer4Home / Audioserver4Home: URL-Vorlage (anpassbar)
 *   - Alexa-NG (Plugin fuer Amazon Echo, ab Werk nicht gewaehlt): POST an
 *     dessen Endpunkt, das Sprechtoken steht im Koerper, nie in der Adresse
 *   - Google-Lautsprecher ueber Chromecast 4 Lox NG (ab Werk nicht gewaehlt):
 *     dieselbe Schnittstelle wie Alexa-NG, eigenes Sprechtoken
 *   - Original Loxone Audioserver: KEINE HTTP-TTS-Schnittstelle vorhanden -
 *     dieser Endpunkt liefert dann nur den Text (TEXT=...); die Sprachausgabe
 *     erfolgt in Loxone Config ueber einen Textgenerator-Baustein am
 *     TTS-Eingang des Audioplayer-Bausteins.
 *
 * Aufruf: /plugins/abfahrtsassistent/termin_say.php?token=...
 *         ?selftest=1&token=...  prueft NUR das Token, spricht nicht
 *         &text=...   eigener Text statt des automatischen
 *         &force=1    umgeht Ruhezeiten und die Audio-Freigabe (Testknopf)
 *
 * DIESER ENDPUNKT SPRICHT IM HAUS und verlangt deshalb das Merkwort aus dem
 * Reiter "Einbindung in Loxone". Er liegt im unangemeldeten Bereich, damit
 * Loxone ihn ohne Zugangsdaten erreicht - ohne Pruefung koennte jeder im Netz
 * beliebigen Text ansagen lassen, und mit &force=1 auch mitten in der Nacht,
 * denn force umgeht die Ruhezeiten.
 */

require_once __DIR__ . '/abfahrt_lib.php';
header('Content-Type: text/plain; charset=utf-8');

$abfcfg = abfahrt_config();

if (!abfahrt_token_ok($abfcfg)) {
    abfahrt_token_abweisen('SAY', $abfcfg);
}

/* ---------- Selbsttest: Token pruefen, ohne zu sprechen ----------
 *
 * WOZU
 * Ob das in Loxone eingetragene Token noch stimmt, liess sich bisher nur
 * pruefen, indem man den Endpunkt aufrief - und dann sprach das Haus. Wer nur
 * nachsehen wollte, stoerte alle Anwesenden. ?force=1 machte es schlimmer,
 * nicht besser.
 *
 * ?selftest=1&token=... laeuft durch dieselbe Token-Pruefung (steht bewusst
 * DAHINTER, damit ein falsches Token dieselbe Abweisung bekommt wie sonst) und
 * endet dann sofort: keine Ansage, kein Music-Server-Aufruf, keine
 * Freigabepruefung.
 *
 * Antwort: SELFTEST;OK=1;TOKEN=OK
 */
if (abfahrt_schalter('selftest')) {
    echo "SELFTEST;OK=1;TOKEN=OK\n";
    exit;
}

$tts = $abfcfg['tts'];

// Freigabe pruefen (Test-Button nutzt ?force=1 und umgeht die Sperren)
if (!abfahrt_schalter('force')) {
    $why = '';
    if (!abfahrt_audio_allowed($abfcfg, $why)) {
        abfahrt_log("Ansage unterdrueckt: $why");
        echo "SKIP: $why\n";
        exit;
    }
}

/* Ansagetext bauen.
 *
 * Ein Feld statt einer Zeichenkette (?text[]=a) wird ABGEWIESEN, nicht
 * umgewandelt: (string) auf ein Feld ergibt "Array" samt Warnung, und trim()
 * darauf war unter PHP 8 ein TypeError - also HTTP 500 samt Dateipfad in der
 * Antwort an den Miniserver.
 *
 * Der mitgegebene Text laeuft durch denselben Filter wie der automatische
 * Titel. Ohne ihn erzeugte ein Zeilenumbruch darin im Protokoll eine zweite,
 * frei erfundene Zeile mit eigenem Zeitstempel. Was im Protokoll steht, muss
 * stimmen. */
if (isset($_GET['text']) && !is_string($_GET['text'])) {
    http_response_code(400);
    echo "FEHLER: Der Parameter text muss ein einzelner Text sein.\n";
    exit;
}
$eigener = isset($_GET['text']) ? abfahrt_tts_sauber($_GET['text']) : '';

/* Keine Ansage ohne gueltigen Termin (seit 1.6.10).
 *
 * abfahrt_berechnen() loescht titel.json, sobald kein Termin mehr gilt, und
 * begruendet das damit, dass keine Ansage fuer einen ungueltigen Termin
 * entstehen soll. Hier wurde trotzdem gesprochen - mit dem allgemeinen Satz
 * "Dein naechster Termin steht an". Ausgeloest wird dieser Endpunkt vom
 * Miniserver, und der entscheidet nach SEINEN Eingaengen: haengt die
 * MQTT-Weiterleitung (gemessen 16.09.2026: der Miniserver zeigte "Abfahrt in
 * 734 Min", das Plugin meldete seit Stunden OK=0), spraeche das Haus fuer
 * einen Termin, den es nicht gibt. Mit ?force=1 oder eigenem ?text= bleibt
 * die Ansage moeglich (Testknopf). */
$abf_stand = abfahrt_stand();
/* C4 (Entscheidung 4, Durchgang 29.09.2026): dieselbe Grenze wie OK im
 * Endpunkt - ein Stand, der aelter ist als das Dreifache des Rechentakts, ist
 * kein gueltiger Termin mehr. Bis 1.6.15 sprach die Ansage fuer einen Stand
 * beliebigen Alters (gemessen: ALTER=7200). */
if (!abfahrt_schalter('force') && $eigener === '' && abfahrt_ok_wirksam($abf_stand) !== 1) {
    if ((int) $abf_stand['ok'] === 1) {
        abfahrt_log('Ansage unterdrueckt: der Stand ist ' . (time() - (int) $abf_stand['zeit'])
            . ' s alt (Grenze ' . (3 * abfahrt_rechentakt($abf_stand)) . ' s) - rechnet der Dienst noch?');
        echo "SKIP: Stand zu alt (OK=0)\n";
        exit;
    }
    abfahrt_log('Ansage unterdrueckt: kein gueltiger Termin (OK=0, FEHLER=' . (int) $abf_stand['fehler'] . ')');
    echo "SKIP: kein gueltiger Termin (OK=0)\n";
    exit;
}
$info = @json_decode((string) @file_get_contents(abfahrt_tmpdir() . '/titel.json'), true);
if (!is_array($info)) { $info = []; }
$text = ($eigener !== '') ? $eigener : abfahrt_ansagetext($info, $abfcfg);

/* Bis 1.6.21 ging ein leerer Ansagetext hinaus und stand als gesprochen im
 * Protokoll ("OK: TEXTLAENGE=0"), etwa bei einer eigenen Vorlage "{titel}"
 * ohne Titel (Pruefung 02.10.2026, Nr. 11). Dieselbe Pruefung wie in
 * ansage_sprechen(), fuer alle Ausgabearten. */
list($abf_ts, $abf_tk) = ansage_text_pruefen($text, $tts['mode']);
if ($abf_ts === -1) {
    abfahrt_log('Ansage entfaellt: leerer Ansagetext');
    echo "SKIP: leerer Ansagetext\n";
    exit;
}
if ($abf_ts !== 1) {
    abfahrt_log('Ansage entfaellt: Ansagetext abgewiesen (' . $abf_tk . ', ' . ansage_zeichen($text) . ' Zeichen)');
    echo 'FEHLER: Ansagetext abgewiesen (' . $abf_tk . ")\n";
    exit;
}

if ($tts['mode'] === 'audioserver') {
    // Kein HTTP-Push moeglich - Text fuer Loxone Config bereitstellen
    echo "TEXT=" . $text . "\n";
    echo "HINWEIS: Original Loxone Audioserver hat keine HTTP-TTS-Schnittstelle.\n";
    echo "Die Ansage in Loxone Config ueber Textgenerator -> TTS-Eingang des Audioplayers ausloesen.\n";
    exit;
}

/* Nr. 36 b, Stufe 2: alle uebrigen Ausgabearten spricht die gemeinsame Sprachausgabe
 * (ansage_sprechen()): Music Server und Vorlagen per GET (Erfolg nur HTTP 2xx, die fertige
 * Adresse vorher erneut auf das Heimnetz geprueft), Alexa-NG und Chromecast 4 Lox NG per
 * POST (Erfolg nur HTTP 200 und SPRECHEN;OK=1), ohne Weiterleitung, ohne Proxy, 10 s.
 * Faellt die Gegenseite aus, entfaellt die Ansage - kein stiller Wechsel auf einen
 * anderen Lautsprecher, keine eigene Wiederholung. Ins Protokoll kommt nur ansage_kurz()
 * (nie Text, Token oder die Adresse des Music Servers). Die Antwort an Loxone bleibt in
 * der Form bis 1.6.22: "OK: TEXTLAENGE=N" bzw. eine Zeile, die mit "FEHLER" beginnt. */
$abf_k = abfahrt_ansage_k();
$abf_r = ansage_sprechen($text, $tts, $abf_k);
abfahrt_log('Ansage: ' . ansage_kurz($abf_r) . (abfahrt_schalter('force') ? ' [Test/force]' : ''));
if ($abf_r['stand'] === 1) {
    if ($abf_r['art'] === 'cc4lox') {
        // Wie bisher: "OK: TEXTLAENGE=N; an Google-Lautsprecher gesendet, HTTP 200, <Antwortzeile>".
        echo sprintf(abfahrt_t('TEXT.GOOGLE_ANTWORT_OK'), $abf_r['zeichen'],
                     'HTTP ' . (int) $abf_r['http'] . ', ' . ($abf_r['zeile'] !== '' ? $abf_r['zeile'] : '-')) . "\n";
    } else {
        echo 'OK: TEXTLAENGE=' . $abf_r['zeichen'] . "\n";     // Nr. 40: die Laenge, nicht der Text
    }
    exit;
}
$abf_kid = explode('|', (string) $abf_r['kennung']);
if ($abf_kid[0] === 'KEINE_IP') {
    /* Seit 1.6.22 strenger geprueft (Pruefung 02.10.2026, Nr. 2/14): ein abgewiesener
     * tts-Block faellt beim Laden auf die Vorgaben - dann fehlt die IP nicht, sondern die
     * Einstellung wurde abgewiesen. */
    $abf_lage = abfahrt_config_lage();
    if (isset($abf_lage['abgewiesen']['tts'])) {
        echo "FEHLER: Einstellungen der Sprachausgabe abgewiesen (" . $abf_lage['abgewiesen']['tts']
           . ") - Plugin-Oberflaeche oeffnen, Reiter Test.\n";
        exit;
    }
    echo "FEHLER: Keine Audio-Server-IP konfiguriert (Plugin-Oberflaeche oeffnen).\n";
    exit;
}
$abf_grund = ansage_kennung_text($abf_r['kennung'], $abf_k);
if ($abf_r['art'] === 'alexang') {
    echo sprintf(abfahrt_t('TEXT.ALEXA_ANTWORT_FEHL'), $abf_grund) . "\n";
    exit;
}
if ($abf_r['art'] === 'cc4lox') {
    echo sprintf(abfahrt_t('TEXT.GOOGLE_ANTWORT_FEHL'), $abf_grund) . "\n";
    exit;
}
if ($abf_kid[0] === 'EINSTELLUNG') {
    // Die fertige Adresse liegt nicht im Heimnetz oder eine Einstellung taugt nicht - nichts gesendet.
    echo 'FEHLER: Ansage nicht gesendet - ' . $abf_grund . "\n";
    exit;
}
echo 'FEHLER beim Aufruf des Audio-Servers: ' . $abf_grund . "\n";
/* k1: mit debug=1 nur Schema, Rechner und Port der Adresse - nie der Pfad, der den
 * Ansagetext und alles traegt, was eine eigene Vorlage enthaelt (bis 1.6.21 die ganze
 * Adresse, Pruefung 02.10.2026, Nr. 13). */
if (abfahrt_schalter('debug')) {
    $abf_u = @parse_url((string) ansage_tts_url($text, $tts));
    echo 'URL: ' . ((is_array($abf_u) && isset($abf_u['scheme'], $abf_u['host']))
        ? $abf_u['scheme'] . '://' . $abf_u['host'] . (isset($abf_u['port']) ? ':' . (int) $abf_u['port'] : '')
        : '-') . "\n";
}
