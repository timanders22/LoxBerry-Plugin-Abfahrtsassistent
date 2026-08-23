#!/bin/bash

# Abfahrts-Assistent - preupgrade
# Sichert die bestehende Konfiguration vor dem Update.
# command <TEMPFOLDER> <NAME> <FOLDER> <VERSION> <BASEFOLDER>

ARGV1=$1
ARGV3=$3
ARGV5=$5
# Rueckfall, falls sudo die Umgebung ausgeraeumt hat (env_reset).
# Das fuenfte Argument ist das Wurzelverzeichnis und traegt immer.
LBHOMEDIR="${LBHOMEDIR:-$5}"
PFOLDER="${ARGV3:-abfahrtsassistent}"
BASE="${ARGV5:-$LBHOMEDIR}"

# Die Sicherung liegt NEBEN dem Ordner. Zwei Gruende, beide gemessen an
# sbin/plugininstall.pl (Zweig master, 23.08.2026):
#   1. $1 ist NICHT der Arbeitsordner, sondern eine zehnstellige
#      Zufallskennung aus &generate(10). "cp ... $1/datei" schrieb bisher in
#      einen Unterordner, den niemand angelegt hat - es ist nie etwas
#      gesichert worden, und die Meldung sagte das Gegenteil.
#   2. Der Installer loescht zwischen preupgrade und postinstall
#      config/plugins/<x>/, bin/, data/, templates/ und beide webfrontend/
#      (&purge_installation im Upgrade-Zweig, :886 -> :1629 ff.). Nur der
#      Nachbar mit dem Punkt bleibt stehen.
SICHER="$BASE/data/plugins/$PFOLDER.upgrade_sicherung"
mkdir -p "$SICHER" 2>/dev/null
chmod 0700 "$SICHER" 2>/dev/null

if [ -f "$BASE/config/plugins/$PFOLDER/abfahrt.json" ]; then
    # Die Wirkung pruefen, nicht die Absicht: gemeldet wird erst, wenn die
    # Datei danach wirklich dort liegt.
    if cp -p "$BASE/config/plugins/$PFOLDER/abfahrt.json" "$SICHER/abfahrt.json" \
       && [ -s "$SICHER/abfahrt.json" ]; then
        chmod 600 "$SICHER/abfahrt.json" 2>/dev/null
        echo "<INFO> Konfiguration gesichert."
    else
        echo "<INFO> Die Konfiguration liess sich nicht sichern."
    fi
else
    echo "<INFO> Keine bestehende Konfiguration gefunden."
fi
# log/plugins/<x>/ steht NICHT in der Loeschliste des Installers und
# uebersteht ein Update von selbst. Die Kopie bleibt trotzdem: bricht das
# Update zwischen den Skripten ab, ist sie der einzige vollstaendige Stand.
if [ -f "$BASE/log/plugins/$PFOLDER/abfahrt.log" ]; then
    cp -p "$BASE/log/plugins/$PFOLDER/abfahrt.log" "$SICHER/abfahrt.log" \
        && echo "<INFO> Logdatei gesichert."
fi
exit 0
