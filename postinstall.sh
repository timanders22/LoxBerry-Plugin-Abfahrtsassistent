#!/bin/bash

# Abfahrts-Assistent - postinstall
# command <TEMPFOLDER> <NAME> <FOLDER> <VERSION> <BASEFOLDER>
#
# LoxBerry ruft beim Upgrade BEIDE Haken auf: postinstall.sh und danach
# postupgrade.sh (Regeln/06). Zurueckgespielt wird deshalb an genau EINER
# Stelle je Fall:
#   - Aktualisierung (die Sicherung von preupgrade.sh liegt da):
#     postupgrade.sh spielt zurueck, dieses Skript fasst die Datei nicht an.
#   - Neuinstallation nach einer Deinstallation ohne Aufraeumen, oder eine
#     zerstoerte Datei: dieses Skript spielt die Zweitschrift zurueck.
# Bis 1.6.9 taten es beide, und das Protokoll trug zwei Erfolgsmeldungen aus
# zwei verschiedenen Quellen.

ARGV3=$3 # Plugin installation folder
ARGV5=$5 # LoxBerry base folder
PFOLDER="${ARGV3:-abfahrtsassistent}"
BASE="${ARGV5:-$LBHOMEDIR}"

if [ -z "$BASE" ] || [ ! -d "$BASE/config/plugins" ]; then
    echo "<FAIL> Kein LoxBerry-Wurzelverzeichnis erkannt ('$BASE')."
    exit 1
fi

CFGDIR="$BASE/config/plugins/$PFOLDER"
CF="$CFGDIR/abfahrt.json"
BK="$BASE/config/plugins/$PFOLDER.backup.json"
SICHER="$BASE/data/plugins/$PFOLDER.upgrade_sicherung"

mkdir -p "$CFGDIR" 2>/dev/null
# Die Wirkung pruefen, nicht die Absicht.
if [ ! -d "$CFGDIR" ]; then
    echo "<FAIL> Das Konfigurationsverzeichnis liess sich nicht anlegen: $CFGDIR"
    exit 1
fi

# Heil heisst: die Datei traegt ein Merkwort (Inhalt, nicht Form - Regeln/05).
hat_merkwort() {
    [ -f "$1" ] && grep -q '"aktionstoken": *"[^"]' "$1"
}

if [ -f "$SICHER/abfahrt.json" ]; then
    AKTUALISIERUNG=1
else
    AKTUALISIERUNG=0
fi

if [ "$AKTUALISIERUNG" = "0" ] && ! hat_merkwort "$CF" && hat_merkwort "$BK"; then
    if cp "$BK" "$CF" 2>/dev/null && cmp -s "$BK" "$CF"; then
        chmod 600 "$CF" 2>/dev/null
        echo "<OK> Konfiguration aus der Zweitschrift wiederhergestellt: $BK"
    else
        echo "<WARNING> Die Zweitschrift liess sich nicht zurueckspielen: $BK"
    fi
fi

# Leere Konfiguration anlegen, falls noch keine da ist (KEINE persoenlichen
# Daten). Die Oberflaeche vervollstaendigt sie beim ersten Oeffnen.
if [ ! -f "$CF" ]; then
    if echo '{}' > "$CF" 2>/dev/null && [ -f "$CF" ]; then
        chmod 600 "$CF" 2>/dev/null
        echo "<INFO> Leere Konfiguration angelegt."
    else
        echo "<FAIL> Die Konfigurationsdatei liess sich nicht anlegen: $CF"
        exit 1
    fi
fi
# Der API-Key des Kartendienstes steht in dieser Datei - nur fuer den
# Eigentuemer lesbar. Gilt auch fuer die Zweitschrift daneben.
chmod 600 "$CF" 2>/dev/null
[ -f "$BK" ] && chmod 600 "$BK" 2>/dev/null

if [ "$AKTUALISIERUNG" = "1" ]; then
    echo "<OK> Dateien eingespielt. Die gesicherte Konfiguration wird im naechsten Schritt zurueckgespielt."
elif hat_merkwort "$CF"; then
    echo "<OK> Installation abgeschlossen. Die Konfiguration ist vorhanden."
else
    echo "<OK> Installation abgeschlossen. Bitte die Plugin-Oberflaeche oeffnen und konfigurieren."
fi
exit 0
