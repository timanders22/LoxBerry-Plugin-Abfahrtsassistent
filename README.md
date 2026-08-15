# LoxBerry-Plugin: Abfahrts-Assistent

Sagt an, wann man losfahren muss: Das Plugin liest bis zu **10 iCal-Kalender**
(z. B. Google Kalender), sucht den nächsten Termin **mit Ortsangabe**, ermittelt
die aktuelle **Fahrzeit inkl. Verkehrslage** (TomTom, Google Maps oder HERE) und
berechnet daraus den Abfahrtszeitpunkt:

```
Abfahrt = Terminbeginn − Fahrzeit − Ankunftsreserve − Pufferzeit
```

Kompatibel mit LoxBerry 3.x und **LoxBerry 4.0** (reines PHP 8, keine
Zusatzpakete). Serientermine werden vollständig expandiert (täglich/wöchentlich/monatlich/
jährlich mit INTERVAL, BYDAY, UNTIL, COUNT; EXDATE; einzeln verschobene oder
gelöschte Instanzen via RECURRENCE-ID/STATUS:CANCELLED; DST-sicher). [v1.1.0]

**v1.1.1:** Konfiguration bleibt bei Updates erhalten (preupgrade/postupgrade);
Zonen-Feld akzeptiert einfache Zonenliste (`2,4,6`) &mdash; Lautst&auml;rke kommt dann aus
dem Lautst&auml;rke-Feld; `Zone~Lautst&auml;rke` je Zone weiterhin m&ouml;glich.

## Neu in 1.5.7
**Token pruefbar, ohne dass das Haus spricht.** Bisher liess sich das Merkwort
nur pruefen, indem man den Ansage-Endpunkt aufrief — und dann redete die Anlage.
Mit `?force=1` sogar an den Ruhezeiten vorbei.

Neu: `?selftest=1&token=…` durchlaeuft dieselbe Token-Pruefung und endet dann
sofort mit `SELFTEST;OK=1;TOKEN=OK`. Keine Ansage, kein Aufruf des
Audio-Servers, keine Freigabepruefung. Ein falsches Token bekommt unveraendert
dieselbe Abweisung wie zuvor.

## Konfiguration (Plugin-Oberfläche)

- Bis zu 10 Kalender (Name + private iCal-URL)
- Kartendienst: **TomTom** / **Google Maps** / **HERE** + eigener API-Key
- Abfahrtsadresse (Zuhause)
- **Ankunft X Minuten vor Termin** und **Pufferzeit**
- Sprachausgabe:
  - **Loxone Music Server** (klassisch): direkte TTS-Ansage über Port 7091
  - **Audioserver4Home / MusicServer4Home**: URL-Vorlage (anpassbar)
  - **Original Loxone Audioserver**: hat keine HTTP-TTS-Schnittstelle — das
    Plugin liefert den Ansagetext, die Ausgabe erfolgt in Loxone Config über
    Textgenerator → TTS-Eingang des Audioplayer-Bausteins
  - **Eigene URL-Vorlage** mit Platzhaltern `{ip} {port} {zones} {vol} {lang} {text}`

Es sind **keine persönlichen Daten** im Plugin enthalten — Kalender-URLs,
API-Key und Adressen werden ausschließlich in der lokalen Konfiguration
(`config/plugins/<plugin>/abfahrt.json`) gespeichert.

## Endpunkte (für Loxone)

| Endpunkt | Zweck |
|---|---|
| `/plugins/abfahrtsassistent/termin.php` | Flat-Text: `TERMIN;OK=1;MINSTART=..;FAHRT=..;ABFAHRT_IN=..;FEHLER=..;ALTER=..;AUDIO=..;PUSH=..` |
| `/plugins/abfahrtsassistent/termin.php?debug=1&token=…` | Diagnose — **rechnet neu**, alle anderen Aufrufe lesen nur ab |
| `/plugins/abfahrtsassistent/termin_say.php?token=…` | Ansage auslösen (bzw. `TEXT=...` im Audioserver-Modus) |

**Die beiden auslösenden Aufrufe verlangen ein Merkwort.** Sie liegen im
unangemeldeten Bereich, damit Loxone sie ohne Zugangsdaten erreicht — ohne
Prüfung könnte jeder im Netz beliebigen Text ansagen lassen, mit `&force=1`
auch nachts, denn *force* umgeht die Sperrzeiten. `?debug=1` rechnet neu und
fragt dabei den Kartendienst, kostet also Kontingent. Verglichen wird mit
`hash_equals`, fail-closed: ohne gesetztes Merkwort wird nichts durchgelassen.
Der zyklische Leseaufruf von `termin.php` ohne Parameter bleibt frei — er gibt
nur den Zwischenstand aus. Das Merkwort steht im Reiter *Einbindung in Loxone*,
die dort angezeigten Adressen tragen es bereits.

**MQTT ist ab 1.5.0 der bevorzugte Weg.** Das Plugin rechnet im Hintergrund
(`cron.01min`) und schiebt jede Änderung selbst zum Miniserver — Abo
`abfahrt/#` im MQTT-Gateway eintragen. Gesendet wird nur, was sich geändert
hat.

**Virtueller HTTP-Eingang** bleibt daneben bestehen. Befehlserkennung mit
**führendem Semikolon**: `\i;ABFAHRT_IN=\i\v`. Der Reiter *Einbindung in
Loxone* erzeugt auf Knopfdruck eine fertige Importdatei mit allen acht
Eingängen.

`FEHLER` ist eine Zahl für den Statusbaustein: 0 in Ordnung, 1 kein Kalender,
2 kein API-Key, 3 keine Abfahrtsadresse, 4 kein Termin, 6 Kartendienst tot,
**7 Kartendienst tot, letzte bekannte Fahrzeit gilt weiter** (OK bleibt 1).

## Hinweise

- Termine ohne Ortsangabe (LOCATION) werden ignoriert — nur für Termine mit
  Ziel lässt sich eine Fahrzeit berechnen.
- Ganztagestermine werden ignoriert.
- Abfragelimits: ICS-Cache 10 min, Geocoding dauerhaft. Der **Routen-Cache
  skaliert** mit der Nähe zum Termin: über 3 h stündlich, 1–3 h
  viertelstündlich, letzte Stunde alle 5 min. Über zwölf Stunden gerechnet
  sind das 51 statt 144 Abfragen.
- Fällt der Kartendienst aus, gilt die letzte bekannte Fahrzeit **bis zu eine
  Stunde** weiter (`FEHLER=7`) statt die Berechnung abzubrechen. Ohne das fiel
  der Schwellwertschalter in Loxone ab und löste beim nächsten gelungenen
  Abruf ein zweites Mal aus — derselbe Termin sagte zweimal Bescheid.
- Der API-Key liegt mit Rechten `0600` in der Konfiguration, ebenso die
  Sicherung daneben.


**v1.2.0:** Getrennte Aktivierung von Audioausgabe und Push-Nachricht;
Sperrzeiten für die Audioausgabe je Wochentag (auch über Mitternacht);
Flags `AUDIO=`/`PUSH=` in der termin.php-Ausgabe für Loxone;
Umlaute in der Ansage korrekt („Nächster“); Test-Button umgeht Sperren (`?force=1`).

**v1.3.0:** Admin-Oberfläche mit Reitern (Einstellungen / Einbindung in Loxone mit
Laien-Anleitung / Test / Logdateien); Button „Kalender neu einlesen“ je Kalender;
Logging (Ergebnis-Änderungen, Ansagen, Fehler); Konfig-Sicherung zusätzlich außerhalb
des Plugin-Ordners (übersteht auch Neuinstallation); leere Kalendernamen bleiben leer
(Platzhalter); Sperrzeit-Defaults 20:00–07:00; kompaktes Sperrzeiten-Layout.

**v1.3.1:** Layout-Korrekturen: LoxBerry-eigene jQuery-Mobile-Styles neutralisiert
(`data-role="none"` + CSS) — Kalenderfelder wieder voll breit, „Neu einlesen“ kompakt,
Reiter/Buttons ohne Schatten, weiße Schrift auf farbigen Buttons.

**v1.3.2:** Loxone-Anleitung um Statusbaustein (Schritt 4) und Audio-System-Verkabelung
(Schritt 5: Music Server/MS4H = keine Loxone-Verknüpfung nötig; Original-Audioserver =
Textgenerator→TTS-Eingang) ergänzt; Log-Kasten ohne Schatten; Status-Zeile in vollen
Minuten (aufgerundet); Standard-Lautstärke 8 %; Zonen: Leerzeichen nach Komma erlaubt;
Logdatei übersteht Updates.

**v1.3.3:** Loxone-Anleitung: Schritt 6 mit kompletter Baustein-Liste zum 1:1-Nachbau
(inkl. Vorwarnung, invertierter Schwellwertschalter, UND-Gatter, Audio/Push-Gates,
Neustart-Nachholung; Erklärung, warum kein Ankunftsreserve-Baustein nötig ist).

**v1.3.4:** Oberfläche im LoxBerry-Grün (#6dac20).

**v1.3.5:** Anleitung: Ankunftsreserve-Absatz entfernt; Praxis-Hinweise zum
Benachrichtigungs-Baustein ergänzt (Flankenverhalten, keine Mehrfachquellen am
Push-Eingang, eigener Test-Baustein).

**v1.3.6:** Online-Termin-Filter: Ortsangaben wie „ONLINE“, „Teams“, „Zoom“ (konfigurierbare
Liste) sowie Meeting-Links (http/https) werden ignoriert — keine Fahrzeitberechnung für
Videokonferenzen.

**v1.3.7:** Standard-Lautstärke der Ansagen von 20 % auf 8 % gesenkt; Ansagen
beginnen jetzt mit „Hallo!" statt „Ding Dong!". Beides betrifft nur die
Voreinstellung neuer Installationen — bestehende Einstellungen bleiben erhalten.
Neu bei den Sperrzeiten: drei zusätzliche Zeilen **Feiertag, Ferien und Urlaub**,
die dem Wochentag vorgehen (Prüfreihenfolge Urlaub → Feiertag → Ferien →
Wochentag). Damit lässt sich die Ansage an freien Tagen später freigeben.
Die Sondertage kommen aus dem optionalen LoxBerry-Plugin „Ferien und Feiertage"
(Urlaub = eigener Termin der Art „Urlaub (abwesend)"); ist es nicht installiert,
bleiben die drei Zeilen wirkungslos und es gilt allein die Wochentagstabelle.
Für die Veröffentlichung anonymisiert (Autorenangabe, MIT-Lizenz, Datenschutz-
Hinweis) — Hinweis: durch die geänderte Autorenangabe kann LoxBerry das Plugin
als „neu" ansehen; die Konfiguration überlebt das dank der Sicherung außerhalb
des Plugin-Ordners.

## Datenschutz

Es sind **keine persönlichen Daten** im Plugin enthalten. Kalender-URLs, API-Key,
Adressen und Zonen liegen ausschließlich in der lokalen Konfiguration
(`config/plugins/abfahrtsassistent/abfahrt.json`) und werden nie mitgeliefert.
Externe Verbindungen bestehen nur zu den vom Nutzer selbst eingetragenen
Kalendern und zum gewählten Kartendienst (TomTom, Google Maps oder HERE).

## Lizenz

MIT — siehe [LICENSE](LICENSE).

**v1.5.0:** MQTT-Push über das LoxBerry-Gateway samt eigenem Reiter (nur
Änderungen werden gesendet); Rechnen in den Hintergrunddienst
`bin/abfahrt_dienst.php` verlagert — `termin.php` liest nur noch ab, damit die
Zahl der Anfragen an den Kartendienst nicht mehr daran hängt, wie oft Loxone
fragt; Importdatei für Loxone Config auf Knopfdruck; Selbstprüfung im Reiter
*Test*; Feld `FEHLER` mit Zahlencode; Fahrzeit-Gnadenfrist gegen doppelte
Ansagen; Routen-Cache skaliert mit der Nähe zum Termin; Serientermine werden
vorgespult statt Tag für Tag ab DTSTART durchlaufen; Audio-Server-Suche;
Webserver-Port aus der `general.json` statt hart 80; Konfiguration auf `0600`;
`prerelease.cfg` ergänzt.

**Zweiter Bruch gegenüber 1.4.0:** Der Virtuelle Ausgang, der die Ansage
auslöst, braucht jetzt `?token=…`. Ohne das antwortet `termin_say.php` mit
HTTP 403 und sagt nichts mehr an. Das Merkwort wird beim ersten Öffnen der
Oberfläche erzeugt und steht im Reiter *Einbindung in Loxone*, zusammen mit der
fertigen Adresse zum Übernehmen.

**Bruch gegenüber 1.4.0:** Die Befehlserkennungen im Miniserver brauchen ein
**führendes Semikolon** — aus `\iABFAHRT_IN=\i\v` wird `\i;ABFAHRT_IN=\i\v`.
Grund: Loxone sucht wörtlich und nimmt den ersten Treffer; ohne Semikolon fände
`FAHRT=` auch die Stelle in `ABFAHRT_IN=`, sobald sich die Feldreihenfolge
einmal ändert. Die alten Muster funktionieren weiter, solange die Reihenfolge
bleibt — wer auf Nummer sicher gehen will, lädt die neue Importdatei.
