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
if [ -z "$BASE" ] || [ ! -d "$BASE/config/plugins" ]; then
    echo "<WARNING> Kein LoxBerry-Wurzelverzeichnis erkannt ('$BASE') - nichts wiederhergestellt."
    exit 0
fi

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
        echo "<OK> Konfiguration aus der Update-Sicherung wiederhergestellt."
    else
        ALLES_GUT=0
        echo "<FAIL> Die gesicherte Konfiguration liess sich NICHT zurueckspielen."
        echo "<INFO> Sie bleibt liegen: $SICHER/abfahrt.json"
    fi
else
    echo "<INFO> Keine Update-Sicherung vorhanden - die Konfiguration bleibt, wie sie ist."
fi
if [ -f "$SICHER/abfahrt.log" ]; then
    mkdir -p "$BASE/log/plugins/$PFOLDER" 2>/dev/null
    if cp -p "$SICHER/abfahrt.log" "$BASE/log/plugins/$PFOLDER/abfahrt.log" 2>/dev/null \
       && cmp -s "$SICHER/abfahrt.log" "$BASE/log/plugins/$PFOLDER/abfahrt.log"; then
        echo "<INFO> Logdatei wiederhergestellt."
    else
        ALLES_GUT=0
        echo "<WARNING> Die Logdatei liess sich nicht zurueckspielen; sie bleibt in $SICHER."
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
# Zweitschrift beurteilt: ein nicht leeres aktionstoken.
hat_merkwort() {
    [ -f "$1" ] && grep -q '"aktionstoken": *"[^"]' "$1"
}
if hat_merkwort "$CF"; then
    echo "<OK> Aktualisierung abgeschlossen, Einstellungen uebernommen. Beim ersten Oeffnen der Oberflaeche wird die Konfiguration um neue Einstellungen vervollstaendigt."
else
    echo "<WARNING> Nach der Aktualisierung liegt keine eingerichtete Konfiguration vor."
    echo "<OK> Dateien aktualisiert. Bitte die Plugin-Oberflaeche oeffnen und konfigurieren."
fi
exit 0
