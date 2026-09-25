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

# Dort hat preupgrade.sh gesichert - NEBEN dem Ordner, weil der
# Installer data/plugins/<x>/ zwischen beiden Skripten loescht.
SICHER="$BASE/data/plugins/$PFOLDER.upgrade_sicherung"
CFGDIR="$BASE/config/plugins/$PFOLDER"
CF="$CFGDIR/abfahrt.json"

mkdir -p "$CFGDIR" 2>/dev/null

# Zurueckgespielt wird NUR hier (postinstall.sh laesst die Datei bei einer
# Aktualisierung in Ruhe). Die Sicherung wird erst geloescht, wenn JEDE
# Wiederherstellung nachweislich gelungen ist - geprueft mit cmp, nicht mit
# "nicht leer". Vorher stand die Meldung bei zwei von drei Kopien unbedingt
# da, und das rm -rf raeumte auch eine Logdatei weg, deren Kopie gescheitert
# war.
ALLES_GUT=1
if [ -f "$SICHER/abfahrt.json" ]; then
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
