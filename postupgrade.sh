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
# das rm -rf am Ende griffe dorthin. uninstall/uninstall prueft das seit
# jeher, dieses Skript tat es nicht.
if [ -z "$BASE" ] || [ ! -d "$BASE" ]; then
    echo "<INFO> Kein Wurzelverzeichnis uebergeben - nichts wiederhergestellt."
    exit 0
fi

# Dort hat preupgrade.sh gesichert - NEBEN dem Ordner, weil der
# Installer data/plugins/<x>/ zwischen beiden Skripten loescht.
SICHER="$BASE/data/plugins/$PFOLDER.upgrade_sicherung"

mkdir -p "$BASE/config/plugins/$PFOLDER" 2>/dev/null
# ZURUECKGESPIELT = 1 heisst: die Datei liegt nachweislich wieder da. Nur dann
# darf die Sicherung unten weg. Vorher wurde unbedingt gemeldet und unbedingt
# geloescht - schlug das cp fehl (Platte voll, Rechte), war die einzige
# vollstaendige Fassung samt Merkwort und Schluessel des Kartendienstes fort,
# und der Anwender las "<OK> Konfiguration wiederhergestellt."
ZURUECKGESPIELT=0
if [ -f "$SICHER/abfahrt.json" ]; then
    if cp -p "$SICHER/abfahrt.json" "$BASE/config/plugins/$PFOLDER/abfahrt.json" \
       && [ -s "$BASE/config/plugins/$PFOLDER/abfahrt.json" ]; then
        ZURUECKGESPIELT=1
        echo "<OK> Konfiguration wiederhergestellt."
    else
        echo "<FAIL> Die gesicherte Konfiguration liess sich NICHT zurueckspielen."
        echo "<INFO> Sie bleibt liegen: $SICHER/abfahrt.json"
    fi
else
    ZURUECKGESPIELT=1
    echo "<INFO> Keine gesicherte Konfiguration vorhanden."
fi

# Zweitschrift der Oberflaeche. Sie liegt NEBEN dem Plugin-Ordner und
# uebersteht damit ein Update - eine DEINSTALLATION nicht: uninstall/uninstall
# raeumt sie ausdruecklich ab. Bis 1.6.6 stand hier das Gegenteil.
BK="$BASE/config/plugins/$PFOLDER.backup.json"
CF="$BASE/config/plugins/$PFOLDER/abfahrt.json"
if [ -f "$BK" ]; then
    if [ ! -s "$CF" ] || [ "$(cat "$CF" 2>/dev/null)" = "{}" ]; then
        mkdir -p "$BASE/config/plugins/$PFOLDER"
        cp -p "$BK" "$CF"
        echo "<OK> Konfiguration aus Sicherung wiederhergestellt."
    fi
fi
if [ -f "$SICHER/abfahrt.log" ]; then
    mkdir -p "$BASE/log/plugins/$PFOLDER"
    cp -p "$SICHER/abfahrt.log" "$BASE/log/plugins/$PFOLDER/abfahrt.log"
    echo "<INFO> Logdatei wiederhergestellt."
fi
# Der API-Key des Kartendienstes steht in dieser Datei - nur fuer den
# Eigentuemer lesbar. Gilt auch fuer die Sicherung daneben.
chmod 600 "$BASE/config/plugins/$PFOLDER/abfahrt.json" 2>/dev/null
chmod 600 "$BASE/config/plugins/$PFOLDER.backup.json" 2>/dev/null

# Der Nachbar hat seinen Zweck erfuellt. Was neben dem Ordner liegt,
# raeumt niemand sonst weg - und er traegt die Zugangsdaten mit.
# Aber NUR, wenn das Zurueckspielen nachweislich geklappt hat.
if [ "$ZURUECKGESPIELT" = "1" ]; then
    rm -rf "$SICHER" 2>/dev/null
fi
exit 0
