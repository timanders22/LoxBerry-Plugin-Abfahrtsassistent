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

# Die Wirkung pruefen, nicht die Absicht: gemeldet wird erst, wenn der
# Ordner wirklich da ist. Vorher stand die Erfolgsmeldung unbedingt da, auch
# wenn mkdir gescheitert war (jeder Fehlschlag ist mit 2>/dev/null stumm).
if [ ! -d "$BASE/config/plugins/$PFOLDER" ]; then
    echo "<FAIL> Das Konfigurationsverzeichnis liess sich nicht anlegen: $BASE/config/plugins/$PFOLDER"
    exit 1
fi
echo "<OK> Installation completed. Bitte die Plugin-Oberflaeche oeffnen und konfigurieren."

# Konfiguration aus der Zweitschrift wiederherstellen. Sie liegt NEBEN dem
# Plugin-Ordner und uebersteht damit ein Update - eine DEINSTALLATION nicht:
# uninstall/uninstall raeumt sie ausdruecklich ab, damit keine Datei mit
# Zugangsdaten liegen bleibt. Bis 1.6.6 stand hier das Gegenteil.
BK="$BASE/config/plugins/$PFOLDER.backup.json"
CF="$BASE/config/plugins/$PFOLDER/abfahrt.json"
if [ -f "$BK" ]; then
    if [ ! -s "$CF" ] || [ "$(cat "$CF" 2>/dev/null)" = "{}" ]; then
        mkdir -p "$BASE/config/plugins/$PFOLDER"
        cp -p "$BK" "$CF"
        echo "<OK> Konfiguration aus Sicherung wiederhergestellt."
    fi
fi
# Der API-Key des Kartendienstes steht in dieser Datei - nur fuer den
# Eigentuemer lesbar. Gilt auch fuer die Sicherung daneben.
chmod 600 "$BASE/config/plugins/$PFOLDER/abfahrt.json" 2>/dev/null
chmod 600 "$BASE/config/plugins/$PFOLDER.backup.json" 2>/dev/null
exit 0
