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

# Ohne erkennbaren LoxBerry wird nichts angefasst (seit 1.6.10). postupgrade.sh
# und uninstall/uninstall prueften das schon, dieses Skript nicht: ohne
# Wurzelverzeichnis suchte es /config/plugins/..., fand nichts und meldete
# "Keine bestehende Konfiguration gefunden" - die Rettung fiel aus, und die
# Meldung sagte, es habe nichts zu retten gegeben.
if [ -z "$BASE" ] || [ ! -d "$BASE/config/plugins" ] || [ ! -f "$BASE/config/system/general.json" ]; then
    echo "<WARNING> Kein LoxBerry-Wurzelverzeichnis erkannt ('$BASE') - die Konfiguration wurde NICHT gesichert."
    exit 0
fi

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

QUELLE="$BASE/config/plugins/$PFOLDER/abfahrt.json"
if [ -f "$QUELLE" ]; then
    # Die Wirkung pruefen, nicht die Absicht - und nicht nur "nicht leer":
    # cmp vergleicht die Kopie mit dem Original Byte fuer Byte. Eine
    # abgebrochene Kopie ist nicht leer und bestand die alte Pruefung [ -s ].
    if cp -p "$QUELLE" "$SICHER/abfahrt.json" 2>/dev/null \
       && cmp -s "$QUELLE" "$SICHER/abfahrt.json"; then
        chmod 600 "$SICHER/abfahrt.json" 2>/dev/null
        if grep -q '"aktionstoken": *"[^"]' "$SICHER/abfahrt.json"; then
            echo "<INFO> Konfiguration gesichert (mit Merkwort)."
        else
            echo "<WARNING> Konfiguration gesichert, sie traegt aber kein Merkwort."
        fi
    else
        echo "<WARNING> Die Konfiguration liess sich nicht sichern: $QUELLE"
    fi
else
    echo "<INFO> Keine bestehende Konfiguration gefunden: $QUELLE"
fi
# log/plugins/<x>/ steht NICHT in der Loeschliste des Installers und
# uebersteht ein Update von selbst. Die Kopie bleibt trotzdem: bricht das
# Update zwischen den Skripten ab, ist sie der einzige vollstaendige Stand.
if [ -f "$BASE/log/plugins/$PFOLDER/abfahrt.log" ]; then
    if cp -p "$BASE/log/plugins/$PFOLDER/abfahrt.log" "$SICHER/abfahrt.log" 2>/dev/null \
       && cmp -s "$BASE/log/plugins/$PFOLDER/abfahrt.log" "$SICHER/abfahrt.log"; then
        echo "<INFO> Logdatei gesichert."
    fi
fi
exit 0
