<?php
/**
 * bin/befehle_zuordnung.php - Zuordnungstabelle der Befehlsbruecke, Linie Abfahrts-Assistent (linieneigen).
 *
 * Gruppe 2 / MQTT (1.6.30; Bauvorschrift Gruppe 2 und Gruppe 1 samt Nachtrag 19.10.2026; Entwurf
 * Musterprojekt/entwurf_gruppe2_aa.txt Abschnitt 3, E8, E14; Muster vb_g1_bruecke_bau/beispiel/bin/befehle_zuordnung.php
 * und vb_g1_ab_bau/bin/befehle_zuordnung.php). Die Bruecke selbst (bin/befehlsbruecke.php und .sh) ist byte-gleich
 * die Stammfassung Werkzeuge/gemeinsam/ - linieneigen ist nur diese Datei.
 *
 * Ein Befehl: "ansage" (<praefix>/befehl/ansage, Puls - Loxone sendet feste 1), nicht eingreifend, kein zweiter
 * Haken. Sperrminute 60 s (Vorgabe der Bruecke; die ENTSCHEIDUNGEN verlangen fuer diese Linie keine andere). 'tun'
 * ruft DIESELBE Funktion wie der HTTP-Endpunkt termin_say.php - abfahrt_ansage_ausfuehren() in abfahrt_lib.php - mit
 * Quelle 'mqtt', ohne force und ohne eigenen Text (E8: force umgeht die Sperrzeit, ein Text waere eine beliebige
 * Ansage aus dem offenen UDP-Eingang). Das Merkwort prueft nur der HTTP-Weg; hier gilt der Haken "Befehle ueber MQTT
 * annehmen" (befehle_mqtt_ein, ab Werk aus) zusammen mit "Werte ueber MQTT veroeffentlichen" (Entwurf gemeinsam E13).
 * Selftest und &text sind kein Ausgang des Musterprojekts und kommen nicht auf MQTT.
 *
 * Die Datei setzt keine Konfiguration und schreibt nichts.
 */
if (!function_exists('abfahrt_config')) {
    /* Kandidaten wie bin/abfahrt_dienst.php: ausdruecklich LBHOMEDIR + LBPPLUGINDIR, installiert
     * (bin/plugins/<ordner> -> webfrontend/html/plugins/<ordner>), Archiv (bin/ neben webfrontend/). */
    $abf_kand = array();
    $abf_lb = (string) getenv('LBHOMEDIR');
    $abf_ordner = basename(rtrim((string) getenv('LBPPLUGINDIR'), '/'));
    if ($abf_lb !== '' && $abf_ordner !== ''
        && !in_array($abf_ordner, array('.', 'html', 'htmlauth', 'bin', 'plugins', 'webfrontend'), true)) {
        $abf_kand[] = $abf_lb . '/webfrontend/html/plugins/' . $abf_ordner . '/abfahrt_lib.php';
    }
    if (basename(dirname(__DIR__)) === 'plugins') {
        $abf_kand[] = dirname(dirname(dirname(__DIR__))) . '/webfrontend/html/plugins/' . basename(__DIR__) . '/abfahrt_lib.php';
    } else {
        $abf_kand[] = dirname(__DIR__) . '/webfrontend/html/abfahrt_lib.php';
    }
    foreach ($abf_kand as $abf_k) {
        if (is_file($abf_k)) { require_once $abf_k; break; }
    }
}
// array('ok' => bool, 'grund' => string, ...) der Linie -> array(ok, grund) der Bruecke
$abf_um = function ($r) {
    return array(!empty($r['ok']), isset($r['grund']) ? (string) $r['grund'] : '');
};
return array(
    'fassung' => 1,
    'linie'   => 'abfahrtsassistent',
    'plugin'  => 'Abfahrts-Assistent',
    'geladen' => function_exists('abfahrt_config') && function_exists('abfahrt_ansage_ausfuehren')
                 && function_exists('abfahrt_befehle_einstellungen'),
    'einstellungen' => function () {
        return abfahrt_befehle_einstellungen();
    },
    'protokoll' => function ($stufe, $text) {
        // bb_log() setzt "Befehlsbruecke: " schon vor den Text
        abfahrt_log(($stufe === 'INFO' ? '' : $stufe . ': ') . $text);
    },
    'befehle' => array(
        'ansage' => array('art' => 'puls', 'eingreifend' => false,
                          'titel' => 'Abfahrts-Assistent Ansage jetzt (MQTT)',
                          'kachel' => 'BEFEHL.K_ANSAGE', 'quittung' => 'ausgefuehrt',
                          'tun' => function ($w, $t) use ($abf_um) { return $abf_um(abfahrt_ansage_ausfuehren('mqtt')); }),
    ),
);
