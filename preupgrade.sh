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

# ---------- I1: Marke "Aktualisierung laeuft" - als Erstes ----------
# Entscheidung 1 (29.09.2026): preinstall.sh, postinstall.sh und
# postupgrade.sh erkennen eine Aktualisierung allein an dieser Marke, ohne
# Altersvergleich; postupgrade.sh raeumt sie ab. Sie liegt NEBEN dem
# Datenordner, weil purge_installation den Ordner selbst loescht. Laesst sie
# sich nicht anlegen, hielte preinstall.sh das Update fuer eine
# Neuinstallation und legte die Zweitschrift beiseite - deshalb Abbruch mit
# rc 2, VOR purge_installation (Bauform AWM-Abfuhr 1.4.15). Vorher festhalten,
# ob schon eine Marke lag: dann ist ein frueherer Versuch DIESES Updates
# abgebrochen (I3).
MARKE="$BASE/data/plugins/$PFOLDER.upgrade_laeuft"
ABF_MARKE_VORHER=0
[ -f "$MARKE" ] && ABF_MARKE_VORHER=1
# In geschweiften Klammern: sonst schreibt die Schale ihre eigene Meldung
# ("cannot create ...") am 2>/dev/null vorbei ins Protokoll.
{ date +%s > "$MARKE"; } 2>/dev/null
if ! grep -qx '[0-9][0-9]*' "$MARKE" 2>/dev/null; then
    echo "<FAIL> Die Marke $MARKE liess sich nicht anlegen."
    echo "<FAIL> Ohne sie hielte die Installation dieses Update fuer eine Neuinstallation und legte"
    echo "<FAIL> die Einstellungen beiseite. Die Aktualisierung wird abgebrochen; die bisherige"
    echo "<FAIL> Fassung bleibt unveraendert installiert."
    exit 2
fi

# Eingerichtet heisst: lesbares JSON-Objekt mit nicht leerem Merkwort -
# dieselbe Frage wie postinstall.sh, postupgrade.sh und abfahrt_config_heilen().
hat_merkwort() {
    [ -s "$1" ] || return 1
    php -r '$d = json_decode((string) @file_get_contents($argv[1]), true);
        if (!is_array($d) || !isset($d["aktionstoken"]) || !is_string($d["aktionstoken"])
            || $d["aktionstoken"] === "") { exit(1); }
        exit(0);' "$1" 2>/dev/null
}

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
NEU="$SICHER.neu"

# ---------- I3: eine alte Update-Sicherung zuerst wegraeumen ----------
# Entscheidung 1: bei einem Upgrade wird nie ein Bestand aus einem FRUEHEREN
# Vorgang eingespielt. Bis 1.6.15 blieb eine Sicherung liegen, wenn ihr
# Zurueckspielen scheiterte oder sie kein Merkwort trug; fehlte beim naechsten
# Update die Konfiguration, spielte postupgrade.sh die alte zurueck (in WSL
# gemessen, Installer-Pruefer Fall E: Stand vom 01.06.2026). Ausnahme: Lag
# die Marke schon vor diesem Lauf, ist ein Versuch DIESES Updates abgebrochen,
# womoeglich nach dem Aufraeumen - dann ist die alte Sicherung der einzige
# vollstaendige Stand und bleibt, bis eine brauchbare neue sie ersetzt.
if [ "$ABF_MARKE_VORHER" = "0" ] && { [ -e "$SICHER" ] || [ -L "$SICHER" ]; }; then
    rm -rf "${SICHER:?}" 2>/dev/null
    if [ -e "$SICHER" ] || [ -L "$SICHER" ]; then
        echo "<FAIL> Eine Update-Sicherung aus einem frueheren Vorgang liess sich nicht entfernen: $SICHER"
        echo "<FAIL> postupgrade.sh spielte sie sonst zurueck. Die Aktualisierung wird abgebrochen."
        exit 2
    fi
    echo "<INFO> Eine Update-Sicherung aus einem frueheren Vorgang wurde entfernt."
fi
# Die neue Sicherung entsteht unter .neu und wird erst nach der Pruefung
# umbenannt (Regeln/06, Bauform KODI-NG 1.2.8).
rm -rf "${NEU:?}" 2>/dev/null
mkdir -p "$NEU" 2>/dev/null
chmod 0700 "$NEU" 2>/dev/null
ABF_NEU_GUT=0

QUELLE="$BASE/config/plugins/$PFOLDER/abfahrt.json"
if [ -f "$QUELLE" ]; then
    # Die Wirkung pruefen, nicht die Absicht - und nicht nur "nicht leer":
    # cmp vergleicht die Kopie mit dem Original Byte fuer Byte. Eine
    # abgebrochene Kopie ist nicht leer und bestand die alte Pruefung [ -s ].
    if cp -p "$QUELLE" "$NEU/abfahrt.json" 2>/dev/null \
       && cmp -s "$QUELLE" "$NEU/abfahrt.json"; then
        chmod 600 "$NEU/abfahrt.json" 2>/dev/null
        if hat_merkwort "$NEU/abfahrt.json"; then
            ABF_NEU_GUT=1
            echo "<INFO> Konfiguration gesichert (mit Merkwort)."
        else
            echo "<WARNING> Konfiguration gesichert, sie traegt aber kein Merkwort."
        fi
    else
        rm -f "$NEU/abfahrt.json" 2>/dev/null
        echo "<WARNING> Die Konfiguration liess sich nicht sichern: $QUELLE"
    fi
else
    echo "<INFO> Keine bestehende Konfiguration gefunden: $QUELLE"
fi
# log/plugins/<x>/ steht NICHT in der Loeschliste des Installers und
# uebersteht ein Update von selbst. Die Kopie bleibt trotzdem: bricht das
# Update zwischen den Skripten ab, ist sie der einzige vollstaendige Stand.
if [ -f "$BASE/log/plugins/$PFOLDER/abfahrt.log" ]; then
    if cp -p "$BASE/log/plugins/$PFOLDER/abfahrt.log" "$NEU/abfahrt.log" 2>/dev/null \
       && cmp -s "$BASE/log/plugins/$PFOLDER/abfahrt.log" "$NEU/abfahrt.log"; then
        echo "<INFO> Logdatei gesichert."
    fi
fi

# I3: umbenennen. Eine brauchbare Sicherung eines abgebrochenen Versuchs
# (siehe oben) wird nicht durch eine unbrauchbare neue ersetzt.
if [ -f "$SICHER/abfahrt.json" ] && [ "$ABF_NEU_GUT" = "0" ] && hat_merkwort "$SICHER/abfahrt.json"; then
    rm -rf "${NEU:?}" 2>/dev/null
    echo "<INFO> Die Update-Sicherung eines abgebrochenen Versuchs dieses Updates bleibt stehen: $SICHER"
elif [ -n "$(ls -A "$NEU" 2>/dev/null)" ]; then
    rm -rf "${SICHER:?}" 2>/dev/null
    if ! mv "$NEU" "$SICHER" 2>/dev/null; then
        echo "<WARNING> Die Update-Sicherung liess sich nicht an ihren Platz bringen: $NEU"
    fi
else
    rm -rf "${NEU:?}" 2>/dev/null
fi
exit 0
