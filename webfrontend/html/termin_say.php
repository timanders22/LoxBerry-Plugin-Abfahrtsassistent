<?php
/**
 * Abfahrts-Assistent - Ansage-Endpunkt
 *
 * Wird vom Miniserver aufgerufen (Virtueller Ausgang), wenn die Abfahrt
 * ansteht. Spricht die Ansage ueber die konfigurierte Audio-Ausgabe:
 *
 *   - Loxone Music Server (klassisch): direkte TTS-URL (Port 7091)
 *   - MusicServer4Home / Audioserver4Home: URL-Vorlage (anpassbar)
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
if (isset($_GET['selftest'])) {
    echo "SELFTEST;OK=1;TOKEN=OK\n";
    exit;
}

$tts = $abfcfg['tts'];

// Freigabe pruefen (Test-Button nutzt ?force=1 und umgeht die Sperren)
if (!isset($_GET['force'])) {
    $why = '';
    if (!abfahrt_audio_allowed($abfcfg, $why)) {
        abfahrt_log("Ansage unterdrueckt: $why");
        echo "SKIP: $why\n";
        exit;
    }
}

// Ansagetext bauen
$info = @json_decode((string) @file_get_contents(abfahrt_tmpdir() . '/titel.json'), true) ?: [];
if (isset($_GET['text']) && trim($_GET['text']) !== '') {
    $text = trim($_GET['text']);
} else {
    $titel = trim((string) ($info['titel'] ?? ''));
    if ($titel === '') {
        $text = 'Hallo! Zeit zum Losfahren! Dein nächster Termin steht an.';
    } else {
        $titel = preg_replace('/[^\p{L}\p{N} .,:!?\-]/u', ' ', $titel); // TTS-sichere Zeichen
        $text = 'Hallo! Zeit zum Losfahren! Nächster Termin: ' . $titel . '.';
        if (!empty($info['fahrt'])) {
            $text .= ' Fahrzeit etwa ' . ceil((float) $info['fahrt']) . ' Minuten.';
        }
    }
}

if ($tts['mode'] === 'audioserver') {
    // Kein HTTP-Push moeglich - Text fuer Loxone Config bereitstellen
    echo "TEXT=" . $text . "\n";
    echo "HINWEIS: Original Loxone Audioserver hat keine HTTP-TTS-Schnittstelle.\n";
    echo "Die Ansage in Loxone Config ueber Textgenerator -> TTS-Eingang des Audioplayers ausloesen.\n";
    exit;
}

$url = abfahrt_tts_url($text, $tts);
// '' heisst: die IP fehlt, obwohl der Modus bzw. die Vorlage sie braucht.
// Die Pruefung sitzt seit 1.5.4 in abfahrt_tts_url() - vorher stand sie hier
// vor dem Aufruf und sperrte auch eigene Vorlagen ohne {ip} aus (AWM 1.2.0).
if ($url === '') {
    echo "FEHLER: Keine Audio-Server-IP konfiguriert (Plugin-Oberflaeche oeffnen).\n";
    exit;
}
$r = abfahrt_http_get($url, 8);
if ($r !== false) {
    abfahrt_log('Ansage gesprochen: ' . $text . (isset($_GET['force']) ? ' [Test/force]' : ''));
    echo "OK: $text\n";
} else {
    abfahrt_log('FEHLER beim Aufruf des Audio-Servers');
    echo "FEHLER beim Aufruf des Audio-Servers.\n";
    if (isset($_GET['debug'])) {
        echo "URL: $url\n";
    }
}
