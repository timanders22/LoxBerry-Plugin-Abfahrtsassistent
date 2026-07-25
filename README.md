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
| `/plugins/abfahrtsassistent/termin.php` | Flat-Text: `TERMIN;OK=1;MINSTART=..;FAHRT=..;ABFAHRT_IN=..` |
| `/plugins/abfahrtsassistent/termin.php?debug=1` | Diagnose |
| `/plugins/abfahrtsassistent/termin_say.php` | Ansage auslösen (bzw. `TEXT=...` im Audioserver-Modus) |

**Virtueller HTTP-Eingang** (alle 5 min): Befehlserkennung `\iABFAHRT_IN=\i\v`.
**Virtueller Ausgang** (bei Abfahrt/Vorwarnung): `/plugins/abfahrtsassistent/termin_say.php`.

## Hinweise

- Termine ohne Ortsangabe (LOCATION) werden ignoriert — nur für Termine mit
  Ziel lässt sich eine Fahrzeit berechnen.
- Ganztagestermine werden ignoriert.
- Abfragelimits: ICS-Cache 10 min, Routen-Cache 5 min, Geocoding dauerhaft.


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
