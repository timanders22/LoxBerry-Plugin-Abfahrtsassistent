#!/bin/bash

# Abfahrts-Assistent - postinstall
# command <TEMPFOLDER> <NAME> <FOLDER> <VERSION> <BASEFOLDER>

ARGV3=$3 # Plugin installation folder
ARGV5=$5 # LoxBerry base folder
PFOLDER="${ARGV3:-abfahrtsassistent}"
BASE="${ARGV5:-$LBHOMEDIR}"

echo "<INFO> Creating config directory..."
mkdir -p "$BASE/config/plugins/$PFOLDER" 2>/dev/null

# Leere Konfiguration anlegen, falls nicht vorhanden (KEINE persoenlichen Daten!)
if [ ! -f "$BASE/config/plugins/$PFOLDER/abfahrt.json" ]; then
    echo '{}' > "$BASE/config/plugins/$PFOLDER/abfahrt.json"
    echo "<INFO> Empty configuration created."
fi

echo "<OK> Installation completed. Bitte die Plugin-Oberflaeche oeffnen und konfigurieren."

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
exit 0
