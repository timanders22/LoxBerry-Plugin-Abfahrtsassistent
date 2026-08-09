#!/bin/bash

# Abfahrts-Assistent - postupgrade
# Stellt die vor dem Update gesicherte Konfiguration wieder her.
# command <TEMPFOLDER> <NAME> <FOLDER> <VERSION> <BASEFOLDER>

ARGV1=$1
ARGV3=$3
ARGV5=$5
PFOLDER="${ARGV3:-abfahrtsassistent}"
BASE="${ARGV5:-$LBHOMEDIR}"

mkdir -p "$BASE/config/plugins/$PFOLDER" 2>/dev/null
if [ -f "$ARGV1/abfahrt.json.backup" ]; then
    cp -p "$ARGV1/abfahrt.json.backup" "$BASE/config/plugins/$PFOLDER/abfahrt.json"
    echo "<OK> Konfiguration wiederhergestellt."
else
    echo "<INFO> Keine gesicherte Konfiguration vorhanden."
fi

# Konfiguration aus Sicherung wiederherstellen (ausserhalb des Plugin-Ordners,
# uebersteht Updates UND Deinstallation/Neuinstallation)
BK="$BASE/config/plugins/$PFOLDER.backup.json"
CF="$BASE/config/plugins/$PFOLDER/abfahrt.json"
if [ -f "$BK" ]; then
    if [ ! -s "$CF" ] || [ "$(cat "$CF" 2>/dev/null)" = "{}" ]; then
        mkdir -p "$BASE/config/plugins/$PFOLDER"
        cp -p "$BK" "$CF"
        echo "<OK> Konfiguration aus Sicherung wiederhergestellt."
    fi
fi
if [ -f "$ARGV1/abfahrt.log.backup" ]; then
    mkdir -p "$BASE/log/plugins/$PFOLDER"
    cp -p "$ARGV1/abfahrt.log.backup" "$BASE/log/plugins/$PFOLDER/abfahrt.log"
    echo "<INFO> Logdatei wiederhergestellt."
fi
# Der API-Key des Kartendienstes steht in dieser Datei - nur fuer den
# Eigentuemer lesbar. Gilt auch fuer die Sicherung daneben.
chmod 600 "$BASE/config/plugins/$PFOLDER/abfahrt.json" 2>/dev/null
chmod 600 "$BASE/config/plugins/$PFOLDER.backup.json" 2>/dev/null
exit 0
