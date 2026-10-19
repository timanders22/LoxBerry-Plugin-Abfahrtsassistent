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
 * Gruppe 2 / MQTT (1.6.30): dieselbe Ansage loest auch der Befehl <praefix>/befehl/ansage ueber die
 * Befehlsbruecke aus (abfahrt_ansage_ausfuehren() in abfahrt_lib.php, ohne force und ohne eigenen Text).
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

/* Gruppe 2 / MQTT (1.6.30; Entwurf aa Abschnitt 3): der Rumpf - Freigabe, ?text, Termin und Alter des Stands,
 * Ansagetext, Sprachausgabe, Antwort - steht jetzt in abfahrt_ansage_ausfuehren() (abfahrt_lib.php), die auch die
 * Befehlsbruecke ruft (Befehl "ansage" ueber MQTT, ohne force und ohne eigenen Text). Reihenfolge, Protokoll,
 * Statuscode und Antwort sind unveraendert (Wirkungstest Quelle 1.6.30 gegen Bau). Hier bleiben Merkwort und
 * Selbsttest. Bis 1.6.29 stand der Rumpf in dieser Datei; Begruendungen der einzelnen Pruefungen stehen jetzt dort. */
$abf_erg = abfahrt_ansage_ausfuehren('http', abfahrt_schalter('force'), isset($_GET['text']) ? $_GET['text'] : null,
                                     abfahrt_schalter('debug'), $abfcfg);
if ($abf_erg['http'] !== 200) {
    http_response_code($abf_erg['http']);
}
echo $abf_erg['antwort'];
exit;
