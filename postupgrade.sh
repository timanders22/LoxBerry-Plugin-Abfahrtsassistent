#!/bin/bash

# Abfahrts-Assistent - postupgrade
# Stellt die vor dem Update gesicherte Konfiguration wieder her.
# command <TEMPFOLDER> <NAME> <FOLDER> <VERSION> <BASEFOLDER>

ARGV1=$1
ARGV3=$3
ARGV5=$5
# Rueckfall, falls sudo die Umgebung ausgeraeumt hat (env_reset).
# Das fuenfte Argument ist das Wurzelverzeichnis und traegt immer.
LBHOMEDIR="${LBHOMEDIR:-$5}"
PFOLDER="${ARGV3:-abfahrtsassistent}"
BASE="${ARGV5:-$LBHOMEDIR}"

# Ohne Wurzelverzeichnis wird nichts angefasst. Sonst zeigte jeder Pfad
# unten auf /config/... und /data/..., also neben den LoxBerry-Baum - und
# das rm -rf am Ende griffe dorthin.
# Verlangt werden config/plugins, data/plugins UND config/system/general.json
# (Regeln/06, Raumklima-Vorfall; wie preupgrade.sh). Bis 1.6.12 genuegte
# config/plugins - in einem fremden Baum spielte dieses Skript dessen
# Sicherung zurueck und loeschte sie (in WSL gemessen,
# Pruefung-Abfahrtsassistent-1.6.13, Fall H5).
if [ -z "$BASE" ] || [ ! -d "$BASE/config/plugins" ] || [ ! -d "$BASE/data/plugins" ] \
   || [ ! -f "$BASE/config/system/general.json" ]; then
    echo "<WARNING> Kein LoxBerry-Wurzelverzeichnis erkannt ('$BASE') - nichts wiederhergestellt."
    exit 0
fi

# Eingerichtet heisst: lesbares JSON-Objekt mit nicht leerem Merkwort -
# dieselbe Frage wie postinstall.sh und abfahrt_config_heilen() (Inhalt, nicht
# Form - Regeln/05). Bis 1.6.12 genuegte eine Zeile mit dem Merkwort, auch in
# einer abgeschnittenen Datei (Fall N3). Ohne PHP gilt eine Datei als ohne
# Inhalt.
hat_merkwort() {
    [ -s "$1" ] || return 1
    php -r '$d = json_decode((string) @file_get_contents($argv[1]), true);
        if (!is_array($d) || !isset($d["aktionstoken"]) || !is_string($d["aktionstoken"])
            || $d["aktionstoken"] === "") { exit(1); }
        exit(0);' "$1" 2>/dev/null
}

# I1 (Entscheidung 1, Durchgang 29.09.2026): die Marke von preupgrade.sh
# (data/plugins/<ordner>.upgrade_laeuft) raeumt dieses Skript ab - per trap,
# also auch, wenn es vorzeitig endet. Der Rueckgabewert bleibt der des
# Skripts. Bliebe sie liegen, hielte sich eine spaetere Neuinstallation fuer
# eine Aktualisierung.
MARKE="$BASE/data/plugins/$PFOLDER.upgrade_laeuft"
# Gruppe 2 / MQTT (1.6.30; Entwurf aa Abschnitt 3): die Befehlsbruecke nach dem Update wieder starten - erst
# NACH dem Abraeumen der Marke (befehlsbruecke.sh lehnt den Start waehrend einer frischen Marke ab), also hier
# in der trap. Sie startet nur, wenn die zurueckgespielte Konfiguration den Haken "Befehle ueber MQTT
# annehmen" traegt (befehlsbruecke.php --soll); sonst holt es der Minutenlauf nach. Nie als root.
abf_bruecke_start() {
    BBSH="$BASE/bin/plugins/$PFOLDER/befehlsbruecke.sh"
    if [ -f "$BBSH" ] && [ "$(id -u)" != "0" ]; then
        timeout 40 bash "$BBSH" start 2>&1 | sed 's/^/<INFO> /'
    fi
}
abf_marke_weg() {
    abf_rc=$?
    rm -f "$MARKE" 2>/dev/null
    if [ -e "$MARKE" ]; then
        echo "<WARNING> Die Marke $MARKE liess sich nicht entfernen - bitte von Hand loeschen, sonst haelt sich eine spaetere Neuinstallation fuer eine Aktualisierung."
    else
        abf_bruecke_start
    fi
    exit $abf_rc
}
trap abf_marke_weg EXIT

# Dort hat preupgrade.sh gesichert - NEBEN dem Ordner, weil der
# Installer data/plugins/<x>/ zwischen beiden Skripten loescht.
SICHER="$BASE/data/plugins/$PFOLDER.upgrade_sicherung"
CFGDIR="$BASE/config/plugins/$PFOLDER"
CF="$CFGDIR/abfahrt.json"
BK="$BASE/config/plugins/$PFOLDER.backup.json"

mkdir -p "$CFGDIR" 2>/dev/null

# Zurueckgespielt wird NUR hier (postinstall.sh laesst die Datei bei einer
# Aktualisierung in Ruhe). Die Sicherung wird erst geloescht, wenn JEDE
# Wiederherstellung nachweislich gelungen ist - geprueft mit cmp, nicht mit
# "nicht leer". Vorher stand die Meldung bei zwei von drei Kopien unbedingt
# da, und das rm -rf raeumte auch eine Logdatei weg, deren Kopie gescheitert
# war.
ALLES_GUT=1
ABF_UNBRAUCHBAR=0
# I2 (Durchgang 29.09.2026): Die Sicherung wird VOR dem Kopieren geprueft.
# Bis 1.6.15 kopierte dieses Skript auch eine Sicherung ohne lesbares Merkwort
# ueber die Konfiguration - auch ueber eine, die der Minutentakt in der
# Upgrade-Luecke schon aus der Zweitschrift geheilt hatte (in WSL gemessen,
# Installer-Pruefer Fall C2b: danach 62 Byte, kein JSON), und forderte zur
# Neueinrichtung auf. Jetzt: die heile Datei bleibt stehen, sonst kommt die
# Zweitschrift (die laufende Rueckfallkopie, Entscheidung 1), und die
# unbrauchbare Sicherung geht nach .alt.
abf_aus_zweitschrift() {
    if cp "$BK" "$CF" 2>/dev/null && cmp -s "$BK" "$CF"; then
        echo "<OK> Konfiguration aus der Zweitschrift wiederhergestellt: $BK"
    else
        ALLES_GUT=0
        echo "<WARNING> Die Zweitschrift liess sich nicht zurueckspielen: $BK"
    fi
}
if [ -f "$SICHER/abfahrt.json" ] && ! hat_merkwort "$SICHER/abfahrt.json"; then
    ABF_UNBRAUCHBAR=1
    echo "<WARNING> Die Update-Sicherung traegt keine eingerichtete Konfiguration (kein lesbares Merkwort) - sie wird nicht zurueckgespielt."
    if hat_merkwort "$CF"; then
        echo "<INFO> Die vorhandene Konfiguration traegt ein Merkwort und bleibt stehen."
    elif hat_merkwort "$BK"; then
        abf_aus_zweitschrift
    fi
elif [ -f "$SICHER/abfahrt.json" ]; then
    if cp -p "$SICHER/abfahrt.json" "$CF" 2>/dev/null && cmp -s "$SICHER/abfahrt.json" "$CF"; then
        # Geloescht wird die Sicherung nur, wenn das Zurueckgespielte Inhalt
        # traegt. Bis 1.6.12 fiel sie auch nach einer abgeschnittenen oder
        # leeren Sicherung weg (in WSL gemessen, Fall N4).
        if hat_merkwort "$CF"; then
            echo "<OK> Konfiguration aus der Update-Sicherung wiederhergestellt."
        else
            ALLES_GUT=0
            echo "<WARNING> Die Update-Sicherung ist zurueckgespielt, traegt aber keine eingerichtete Konfiguration (kein lesbares Merkwort)."
            echo "<INFO> Sie bleibt liegen: $SICHER/abfahrt.json"
        fi
    else
        ALLES_GUT=0
        echo "<FAIL> Die gesicherte Konfiguration liess sich NICHT zurueckspielen."
        echo "<INFO> Sie bleibt liegen: $SICHER/abfahrt.json"
    fi
elif ! hat_merkwort "$CF" && hat_merkwort "$BK"; then
    # Keine Update-Sicherung, keine eingerichtete Konfiguration: bei einer
    # Aktualisierung darf die Zweitschrift zurueckgespielt werden
    # (Entscheidung 1). Fall E: die Sicherung eines frueheren Vorgangs hat
    # preupgrade.sh weggeraeumt (I3).
    echo "<INFO> Keine Update-Sicherung vorhanden."
    abf_aus_zweitschrift
else
    echo "<INFO> Keine Update-Sicherung vorhanden - die Konfiguration bleibt, wie sie ist."
fi
# Die Logdatei nur zurueckspielen, wenn sie fehlt. purge_installation loescht
# log/plugins/<x>/ nicht (Regeln/06); die Kopie ist fuer ein Update gedacht,
# das zwischen den Skripten abbricht. Bis 1.6.12 wurde sie immer ueber das
# Protokoll gelegt, und jede Zeile, die der Minutentakt in der Upgrade-Luecke
# schrieb, ging verloren - auch die Meldung, dass er die Konfiguration aus der
# Zweitschrift geheilt hatte (in WSL gemessen, Fall N7).
if [ -f "$SICHER/abfahrt.log" ]; then
    if [ -f "$BASE/log/plugins/$PFOLDER/abfahrt.log" ]; then
        echo "<INFO> Logdatei ist erhalten geblieben - die Kopie aus der Sicherung wird nicht darueber gelegt."
    else
        mkdir -p "$BASE/log/plugins/$PFOLDER" 2>/dev/null
        if cp -p "$SICHER/abfahrt.log" "$BASE/log/plugins/$PFOLDER/abfahrt.log" 2>/dev/null \
           && cmp -s "$SICHER/abfahrt.log" "$BASE/log/plugins/$PFOLDER/abfahrt.log"; then
            echo "<INFO> Logdatei wiederhergestellt."
        else
            ALLES_GUT=0
            echo "<WARNING> Die Logdatei liess sich nicht zurueckspielen; sie bleibt in $SICHER."
        fi
    fi
fi
# cp -p bringt die Rechte der Sicherung mit; deshalb das chmod DANACH
# (ein chmod vor cp -p ist wirkungslos, Regeln/06).
chmod 600 "$CF" 2>/dev/null
[ -f "$BASE/config/plugins/$PFOLDER.backup.json" ] && chmod 600 "$BASE/config/plugins/$PFOLDER.backup.json" 2>/dev/null

# Gruppe 18 (1.6.31; Entscheidung ab 2, Entwurf G18 3.2): eine bestehende Anlage behaelt den HTTP-Weg termin.php und
# termin_say.php (endpunkt_ein=1) - die Lese-Regel in abfahrt_config() gilt ohnehin, hier steht es ausdruecklich in
# Konfiguration UND Zweitschrift. Nur bei eingerichteter Konfiguration (hat_merkwort) ohne den Schluessel; ueber eine
# Nebendatei, Gegenprobe (alles gleich bis auf den Schluessel), mv, 0600. Scheitert es, gilt die Lese-Regel.
abf_endpunkt_ein() {
    [ -f "$1" ] || return 0
    hat_merkwort "$1" || return 0
    php -r '$d = json_decode((string) @file_get_contents($argv[1]), true);
        exit((is_array($d) && !array_key_exists("endpunkt_ein", $d)) ? 0 : 1);' "$1" 2>/dev/null || return 0
    rm -f "$1.neu" 2>/dev/null
    if php -r '$d = json_decode((string) @file_get_contents($argv[1]), true);
        $d["endpunkt_ein"] = 1;
        $js = json_encode($d, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($js === false) { exit(1); }
        umask(0077);
        exit(@file_put_contents($argv[2], $js) === strlen($js) ? 0 : 1);' "$1" "$1.neu" 2>/dev/null \
       && php -r '$a = json_decode((string) @file_get_contents($argv[1]), true);
        $b = json_decode((string) @file_get_contents($argv[2]), true);
        if (!is_array($a) || !is_array($b) || !isset($b["endpunkt_ein"]) || $b["endpunkt_ein"] !== 1) { exit(1); }
        unset($b["endpunkt_ein"]);
        exit($a === $b ? 0 : 1);' "$1" "$1.neu" 2>/dev/null \
       && chmod 600 "$1.neu" 2>/dev/null && mv -f "$1.neu" "$1"; then
        echo "<INFO> Konfiguration ergaenzt: endpunkt_ein=1 in $(basename "$1") (bestehende Anlage - termin.php und termin_say.php bleiben erreichbar; abschalten im Reiter Einbindung)"
    else
        rm -f "$1.neu" 2>/dev/null
        echo "<WARNING> endpunkt_ein liess sich in $1 nicht eintragen - es gilt die Lese-Regel des Plugins (bestehende Anlage: an)."
    fi
}
abf_endpunkt_ein "$CF"
abf_endpunkt_ein "$BK"
# Gruppe 18 (Entwurf G18 2.5): der Merker des Anbieters haus/abfahrt/in_min geht mit dem Update weg - der erste Lauf
# danach sendet den Wert vollstaendig (Vollversand ohne Merker).
rm -f "/tmp/$PFOLDER/haus_letzte.json" 2>/dev/null

# I2: eine unbrauchbare Sicherung geht nach .alt (die Deinstallation raeumt
# sie ab) - sie bleibt nicht als Update-Sicherung liegen.
if [ "$ABF_UNBRAUCHBAR" = "1" ] && [ -d "$SICHER" ]; then
    rm -rf "${SICHER:?}.alt" 2>/dev/null
    if mv -f "$SICHER" "$SICHER.alt" 2>/dev/null; then
        echo "<INFO> Die unbrauchbare Update-Sicherung liegt beiseite: $SICHER.alt"
    else
        echo "<WARNING> Die unbrauchbare Update-Sicherung liess sich nicht beiseitelegen: $SICHER"
    fi
fi

# Der Nachbar hat seinen Zweck erfuellt. Was neben dem Ordner liegt,
# raeumt niemand sonst weg - und er traegt die Zugangsdaten mit.
if [ "$ALLES_GUT" = "1" ] && [ -d "$SICHER" ]; then
    rm -rf "$SICHER" 2>/dev/null
    if [ -d "$SICHER" ]; then
        echo "<WARNING> Die Update-Sicherung liess sich nicht entfernen: $SICHER"
    fi
fi
# Die Schlusszeile nach INHALT, nicht nach dem Umstand "Upgrade".
# postinstall.sh schweigt im Aktualisierungsfall (dort: "wird im naechsten
# Schritt zurueckgespielt"); die Anleitung muss deshalb HIER stehen, wenn
# nach dem Zurueckspielen keine eingerichtete Konfiguration da ist - leere
# Sicherung, gescheitertes Zurueckspielen oder gar keine Sicherung. Bis 1.6.11
# stand hier unbedingt "Aktualisierung abgeschlossen", auch ueber "{}"
# (gemessen 24.09.2026, Pruefung-Abfahrtsassistent-1.6.12/postinstall_hinweis.md,
# Fall c). Merkmal ist dasselbe Merkwort, nach dem postinstall.sh die
# Zweitschrift beurteilt: ein nicht leeres aktionstoken in einem lesbaren
# JSON-Objekt (hat_merkwort() oben).
if hat_merkwort "$CF"; then
    echo "<OK> Aktualisierung abgeschlossen, Einstellungen uebernommen. Beim ersten Oeffnen der Oberflaeche wird die Konfiguration um neue Einstellungen vervollstaendigt."
else
    echo "<WARNING> Nach der Aktualisierung liegt keine eingerichtete Konfiguration vor."
    echo "<OK> Dateien aktualisiert. Bitte die Plugin-Oberflaeche oeffnen und konfigurieren."
fi
exit 0
