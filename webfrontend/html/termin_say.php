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

/* Ansage-2 (01.10.2026): Ausgabeart Alexa-NG. Faellt Alexa-NG aus, entfaellt
 * die Ansage - kein stiller Wechsel auf einen anderen Lautsprecher. Protokoll
 * und Antwort nennen HTTP-Code und GRUND, nie das Sprechtoken. */
if ($tts['mode'] === 'alexang') {
    $abf_ag = abfahrt_alexa_sprechen($text, $abfcfg);
    if ($abf_ag === '') {
        abfahrt_log(sprintf(abfahrt_t('TEXT.ALEXA_LOG_OK'), ansage_zeichen($text)) . (abfahrt_schalter('force') ? ' [Test/force]' : ''));
        echo 'OK: TEXTLAENGE=' . ansage_zeichen($text) . "\n";    // Nr. 40: die Laenge, nicht der Text
    } else {
        $abf_agt = abfahrt_grund_text($abf_ag);
        abfahrt_log(sprintf(abfahrt_t('TEXT.ALEXA_LOG_FEHL'), $abf_agt));
        echo sprintf(abfahrt_t('TEXT.ALEXA_ANTWORT_FEHL'), $abf_agt) . "\n";
    }
    exit;
}

/* Ansage-3 (01.10.2026): Ausgabeart Google-Lautsprecher (Chromecast 4 Lox NG).
 * Faellt das Plugin aus, entfaellt die Ansage - kein stiller Wechsel auf einen
 * anderen Lautsprecher, keine eigene Wiederholung. Protokoll und Antwort nennen
 * HTTP-Code und Antwortzeile bzw. GRUND und vom Text nur die Zeichenzahl - nie
 * das Sprechtoken, nie den Text. */
if ($tts['mode'] === 'cc4lox') {
    $abf_ga = null;
    $abf_gg = abfahrt_google_sprechen($text, $abfcfg, $abf_ga);
    $abf_gn = (int) preg_match_all('/./us', (string) $text);
    if ($abf_gg === '') {
        $abf_gk = abfahrt_ng_kurz($abf_ga);
        abfahrt_log(sprintf(abfahrt_t('TEXT.GOOGLE_LOG_OK'), $abf_gn, $abf_gk) . (abfahrt_schalter('force') ? ' [Test/force]' : ''));
        // Beginnt wie bei den anderen Arten mit "OK: TEXTLAENGE=N" (Pruefung 02.10.2026, Nr. 12 b).
        echo sprintf(abfahrt_t('TEXT.GOOGLE_ANTWORT_OK'), $abf_gn, $abf_gk) . "\n";
    } else {
        $abf_ggt = abfahrt_grund_text($abf_gg);
        abfahrt_log(sprintf(abfahrt_t('TEXT.GOOGLE_LOG_FEHL'), $abf_gn, $abf_ggt));
        echo sprintf(abfahrt_t('TEXT.GOOGLE_ANTWORT_FEHL'), $abf_ggt) . "\n";
    }
    exit;
}

$url = abfahrt_tts_url($text, $tts);
// '' heisst: die IP fehlt, obwohl der Modus bzw. die Vorlage sie braucht.
// Die Pruefung sitzt seit 1.5.4 in abfahrt_tts_url() - vorher stand sie hier
// vor dem Aufruf und sperrte auch eigene Vorlagen ohne {ip} aus (AWM 1.2.0).
if ($url === '') {
    /* Seit 1.6.22 strenger geprueft (Pruefung 02.10.2026, Nr. 2/14): ein
     * abgewiesener tts-Block faellt auf die Vorgaben - dann fehlt die IP nicht,
     * sondern die Einstellung wurde abgewiesen. */
    $abf_lage = abfahrt_config_lage();
    if (isset($abf_lage['abgewiesen']['tts'])) {
        echo "FEHLER: Einstellungen der Sprachausgabe abgewiesen (" . $abf_lage['abgewiesen']['tts']
           . ") - Plugin-Oberflaeche oeffnen, Reiter Test.\n";
        exit;
    }
    echo "FEHLER: Keine Audio-Server-IP konfiguriert (Plugin-Oberflaeche oeffnen).\n";
    exit;
}
/* Die WIRKUNG pruefen, nicht den Rueckgabewert.
 *
 * Bisher galt jede Antwort ausser einem Transportfehler als Erfolg - curl
 * liefert den Rumpf auch bei HTTP 404 und 500. Eine falsche Zonennummer
 * fuehrte damit zu "Ansage gesprochen" im Protokoll, obwohl nichts gesagt
 * wurde. Eine stille Falschaussage ist die schlimmste Art von Fehler.
 *
 * $grund sagt jetzt auch, WER geantwortet hat: "Verbindung abgewiesen"
 * (Rechner da, Dienst aus) fuehrt zu einer voellig anderen Suche als eine
 * Zeitueberschreitung. */
$grund = '';
$status = 0;
/* Nr. 36 b: abgerufen ueber den Transport der gemeinsamen Sprachausgabe (ohne Weiterleitung,
 * ohne Proxy, 8 s wie bisher, Erfolg nur bei HTTP 2xx); der Grund ist die Kennung dieser Linie. */
$abf_k = abfahrt_ansage_k();
/* Die fertige Adresse unmittelbar vor dem Senden pruefen (http/https, keine
 * Zugangsdaten vor dem Rechner, Rechner im Heimnetz): sie traegt den
 * Ansagetext. Bis 1.6.21 ging z. B. http://{ip}:80@example.com/... an
 * example.com (Pruefung 02.10.2026, Nr. 2). */
if (!ansage_url_heimnetz($url)) {
    $abf_hg = abfahrt_grund_text($tts['mode'] === 'musicserver' ? 'TTS_IP' : 'TTS_VORLAGE_HEIMNETZ');
    abfahrt_log('FEHLER: Ansage nicht gesendet - ' . $abf_hg);
    echo 'FEHLER: Ansage nicht gesendet - ' . $abf_hg . "\n";
    exit;
}
$abf_a = ansage_ausfuehren(ansage_anfrage('GET', $url, null, 8, $abf_k), $abf_k);
$status = $abf_a['code'];
/* Bis 1.6.21 hiess jeder Transportfehler ausser 6, 7 und 28 "kein curl
 * vorhanden" - auch mit curl (52 leere Antwort, 35/60 TLS, 3 kaputte Adresse).
 * 1.6.20 meldete noch "Netzfehler 52: Empty reply from server" (Pruefung
 * 02.10.2026, Nr. 3). */
if ($status > 0) {
    $abf_gid = abfahrt_http_grund_id(0, '', $status);
} elseif ($abf_a['errno'] > 0) {
    $abf_gid = abfahrt_http_grund_id($abf_a['errno'], $abf_a['fehler'], 0);
} elseif ($abf_a['errno'] === -2) {
    $abf_gid = 'HTTP_KEIN_HTTP';
} else {
    $abf_gid = function_exists('curl_init') ? 'HTTP_OHNE_ANTWORT' : 'HTTP_OHNE_CURL';
}
$grund = $abf_gid !== '' ? abfahrt_grund_text($abf_gid) : '';
$r = ($status >= 200 && $status < 300) ? $abf_a['rumpf'] : false;
if ($r !== false) {
    /* Nr. 40: vom Ansagetext nur seine Laenge, in Protokoll und Antwort. */
    abfahrt_log('Ansage gesprochen (' . ansage_zeichen($text) . ' Zeichen)' . (abfahrt_schalter('force') ? ' [Test/force]' : ''));
    echo 'OK: TEXTLAENGE=' . ansage_zeichen($text) . "\n";
} else {
    abfahrt_log('FEHLER beim Aufruf des Audio-Servers: ' . ($grund !== '' ? $grund : 'unbekannt'));
    echo 'FEHLER beim Aufruf des Audio-Servers'
       . ($grund !== '' ? ': ' . $grund : '.') . "\n";
    /* Nur Schema, Rechner und Port: bis 1.6.21 stand hier die ganze Adresse
     * samt Ansagetext und allem, was eine eigene Vorlage traegt (Pruefung
     * 02.10.2026, Nr. 13). */
    if (abfahrt_schalter('debug')) {
        $abf_u = @parse_url($url);
        echo 'URL: ' . ((is_array($abf_u) && isset($abf_u['scheme'], $abf_u['host']))
            ? $abf_u['scheme'] . '://' . $abf_u['host'] . (isset($abf_u['port']) ? ':' . (int) $abf_u['port'] : '')
            : '-') . "\n";
    }
}
