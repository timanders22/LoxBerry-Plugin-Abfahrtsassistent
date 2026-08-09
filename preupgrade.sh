#!/bin/bash

# Abfahrts-Assistent - preupgrade
# Sichert die bestehende Konfiguration vor dem Update.
# command <TEMPFOLDER> <NAME> <FOLDER> <VERSION> <BASEFOLDER>

ARGV1=$1
ARGV3=$3
ARGV5=$5
PFOLDER="${ARGV3:-abfahrtsassistent}"
BASE="${ARGV5:-$LBHOMEDIR}"

if [ -f "$BASE/config/plugins/$PFOLDER/abfahrt.json" ]; then
    cp -p "$BASE/config/plugins/$PFOLDER/abfahrt.json" "$ARGV1/abfahrt.json.backup"
    chmod 600 "$ARGV1/abfahrt.json.backup" 2>/dev/null
    echo "<INFO> Konfiguration gesichert."
else
    echo "<INFO> Keine bestehende Konfiguration gefunden."
fi
if [ -f "$BASE/log/plugins/$PFOLDER/abfahrt.log" ]; then
    cp -p "$BASE/log/plugins/$PFOLDER/abfahrt.log" "$ARGV1/abfahrt.log.backup"
    echo "<INFO> Logdatei gesichert."
fi
exit 0
