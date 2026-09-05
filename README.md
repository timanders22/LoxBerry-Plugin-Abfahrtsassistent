# LoxBerry-Plugin: Abfahrts-Assistent

Version 1.6.7 · LoxBerry ab 3.0 · PHP 7.4 und 8.x

Sagt an, wann man losfahren muss: Das Plugin liest bis zu **10 iCal-Kalender**
(z. B. Google Kalender), sucht den nächsten Termin **mit Ortsangabe**, ermittelt
die aktuelle **Fahrzeit inkl. Verkehrslage** (TomTom, Google Maps oder HERE) und
berechnet daraus den Abfahrtszeitpunkt:

```
Abfahrt = Terminbeginn − Fahrzeit − Ankunftsreserve − Pufferzeit
```

Kompatibel mit LoxBerry 3.x und **LoxBerry 4.0**. Der Code läuft unter
PHP **7.4 und 8.x** &mdash; LoxBerry führt bis Debian 13 PHP 7.4 als
Standardfassung. Keine Zusatzpakete. Serientermine werden vollständig expandiert (täglich/wöchentlich/monatlich/
jährlich mit INTERVAL, BYDAY, UNTIL, COUNT; EXDATE; einzeln verschobene oder
gelöschte Instanzen via RECURRENCE-ID/STATUS:CANCELLED; DST-sicher). [v1.1.0]

**v1.1.1:** Konfiguration bleibt bei Updates erhalten (preupgrade/postupgrade);
Zonen-Feld akzeptiert einfache Zonenliste (`2,4,6`) &mdash; Lautstärke kommt dann aus
dem Lautstärke-Feld; `Zone~Lautstärke` je Zone weiterhin möglich.

## Neu in 1.6.7

Eine Durchsicht, keine neue Funktion. Alles, was hier steht, war vorher falsch
oder irreführend.

**Das Zurückspielen einer Sicherung löscht nichts mehr.** Bisher wurde eine
unvollständige Datei angenommen — sie meldete „1 Werte übernommen" und setzte
alles Übrige auf Werkseinstellung zurück. Kalender, Schlüssel des
Kartendienstes und Merkwort waren danach weg, und weil beim nächsten Öffnen der
Oberfläche ein neues Merkwort entstand, war **jede in Loxone Config eingetragene
Adresse stumm ungültig**. Jetzt gilt: ein Schlüssel, der in der Datei fehlt,
behält seinen bisherigen Wert; jeder Wert wird geprüft (ein `provider` außerhalb
der drei Kartendienste oder ein `arrival_min` von −999 kommt nicht mehr durch);
ein leeres Merkwort in der Datei löscht das vorhandene nicht; und die Datei
trägt jetzt einen lesbaren Kopf (`_plugin`, `_fassung`, `_stand`, `_hinweis`),
den die Leseseite übergeht statt beanstandet.

**Eine abgelaufene Serie liefert keinen Phantomtermin mehr.** Beim Vorspulen
langer Wochenserien wurden die Wochentage der Ankunftswoche vor dem Starttag
nicht mitgezählt. Eine Serie mit `COUNT`, die zu Ende war, gab dadurch noch
einen Termin aus — mit Abfahrtswarnung, Ansage und Push für einen Termin, den
es nicht gab.

**Ein toter Kalender fällt jetzt auf.** Ließ sich ein Kalender nicht mehr laden,
rechnete das Plugin unbegrenzt lange mit der letzten heruntergeladenen Fassung
weiter, und zwar mit `OK=1;FEHLER=0`. Jetzt gilt die alte Fassung höchstens
sechs Stunden, danach meldet die Statuszeile `FEHLER=5`. Lässt sich gar kein
Kalender lesen, steht ebenfalls `FEHLER=5` statt `FEHLER=4` („kein Termin") —
ein toter Kalender sah in Loxone bisher aus wie ein freier Tag.

**Zeitzonen aus Outlook und Exchange.** `TZID:Eastern Standard Time` ist kein
IANA-Name; die Zeitzone fiel still auf Europe/Berlin zurück, ein Termin in New
York lag damit sechs Stunden falsch. Die verbreiteten Windows-Namen werden
jetzt zugeordnet, und was sich nicht zuordnen lässt, steht in der Diagnose.

**Der Hintergrunddienst schreibt jetzt ins Protokoll**, wenn sich `OK` oder
`FEHLER` ändert — bisher hinterließ er keine einzige Zeile. Und er meldet einen
terminfreien Tag nicht mehr als Fehlschlag: der Rückgabewert sagt jetzt, ob der
Lauf durchgekommen ist, nicht, ob es einen Termin gibt.

**Kleineres, das dennoch wirkte:** `?debug=0` heißt jetzt aus (bisher schaltete
jeder Wert ein, auch die 0 — und `?force=0` umging die Sperrzeiten); der
unangemeldete Endpunkt legt keine Verzeichnisse mehr an; das Feld `ANKUNFT`
wandert nicht mehr in jede Protokollzeile (das Protokoll lief im Minutentakt
voll); die Importdatei trägt den Port des Webservers; die Sicherungsknöpfe
stehen im Reiter *Einstellungen* statt unter jedem Reiter; die beiden Kästen
darüber haben wieder einen Rahmen; nach dem Zurückspielen zeigt die Oberfläche
den neuen Stand statt des alten; `postupgrade.sh` löscht die Sicherung erst,
wenn das Zurückspielen nachweislich geklappt hat.

**Berichtigte Zusagen:** die Importdatei trägt **neun** Eingänge, nicht acht;
die Beispielzeile zeigt `ANKUNFT`; die Hilfe nennt die Endpunkt-Adressen mit
`?token=` (ohne den antwortet der Endpunkt mit HTTP 403); `postinstall.sh` und
`postupgrade.sh` behaupten nicht mehr, die Zweitschrift überstehe eine
Deinstallation — sie wird dabei absichtlich gelöscht.

## Neu in 1.6.0

Zehn Erweiterungen. **Acht davon stehen ab Werk aus** &mdash; wer aktualisiert,
bekommt dort genau das Verhalten von 1.5.8.

> ### Zwei Dinge ändern sich beim Update von selbst
>
> **1. Die Fahrzeit wird für den Abfahrtszeitpunkt berechnet, nicht für jetzt.**
> Das ist die Berichtigung eines schiefen Ergebnisses und deshalb ab Werk an —
> aber es **verdoppelt die Abfragen beim Kartendienst**. Bei TomTom sind das
> statt rund 50 nun rund 100 Abfragen am Tag gegen ein Kontingent von 2500;
> bei Google steht dahinter eine Rechnung. Wer das nicht will, nimmt in den
> *Einstellungen* unter „Fahrzeitberechnung" den Haken heraus.
>
> **2. Alle MQTT-Werte werden alle 15 Minuten erneut gesendet**, auch
> unverändert. Damit steht ein neu gestarteter Miniserver nicht mit leeren
> Eingängen da. Abschalten mit `0` im Reiter *MQTT*.
>
> Alles Übrige — Ortsbuch, Ganztagestermine, Sperrzeit für Push, eigener
> Ansagetext — bleibt aus, bis Sie es einschalten.

### Genauere Fahrzeit: für den Abfahrtszeitpunkt statt für jetzt

Bisher fragte das Plugin den Kartendienst immer nach der Verkehrslage von
*jetzt*. Für einen Termin in acht Stunden sagt die nichts &mdash; gerade der
Berufsverkehr wird dadurch verlässlich falsch geschätzt.

Mit dem neuen Haken **Fahrzeit für den Abfahrtszeitpunkt berechnen** wird
zweimal gefragt: einmal grob, dann noch einmal für den daraus ermittelten
Abfahrtszeitpunkt. Die Parameter stehen so in der Dokumentation der Anbieter:

| Dienst | Parameter | Format |
|---|---|---|
| Google Directions | `departure_time` | Unix-Sekunden |
| TomTom Routing | `departAt` | ISO 8601 ohne Versatz (TomTom nimmt die Zeitzone des Startpunkts) |
| HERE Routing v8 | `departureTime` | ISO 8601 **mit** Zeitzonen-Versatz |

**Das verdoppelt die Abfragen** (TomTom: Tageskontingent, Google: Rechnung).
Es greift nur, wenn die Abfahrt mehr als 20 Minuten entfernt ist &mdash; näher
dran sind „jetzt" und „der Abfahrtszeitpunkt" dasselbe. Der Routen-Zwischen&shy;speicher
unterscheidet die beiden Fälle. **Ab Werk eingeschaltet**, siehe den Kasten oben.

### Ortsbuch: „Büro" ist keine Adresse

Im Kalenderfeld `LOCATION` steht selten eine Adresse, sondern „Büro",
„Besprechungsraum 3" oder „Praxis Dr. Weber". Der Kartendienst kann damit
nichts anfangen, und die Berechnung endete mit `FEHLER=6`.

Die neue Tabelle im Reiter *Einstellungen* übersetzt das. Verglichen wird
zuerst wortgleich, dann als **eigenständiges Wort** innerhalb der Ortsangabe:

```
Büro                 -> Hauptstr. 5, Berlin      (Treffer)
büro                 -> Hauptstr. 5, Berlin      (Treffer, Schreibung egal)
Im Büro, 2. Stock    -> Hauptstr. 5, Berlin      (Treffer)
Büroklammer          -> Büroklammer              (KEIN Treffer - kein Teilwort)
```

### Diagnose je Kalender

Der Knopf **Kalender jetzt durchsehen** im Reiter *Test* beantwortet, was
`FEHLER=4` offenlässt: hat das Plugin im Kalender nachgesehen und nichts
gefunden &mdash; oder hat es gar nicht hingesehen? Je Kalender: Gegenstelle,
Alter des Zwischenspeichers, Zahl der Termine, davon mit Ort im Zeitfenster,
und der nächste Treffer.

### Merkwort prüfen und neu würfeln

Der Reiter *Test* hat jetzt **Merkwort prüfen (ohne Ansage)** &mdash; grün, es
löst nichts aus &mdash; und daneben **Merkwort neu würfeln** in Orange, mit
Rückfrage. Bis 1.5.7 geschah das Zweite ungewollt bei jedem Speichern.

### Geokodierung sichtbar

Ein Tippfehler in der Abfahrtsadresse führt zu einer Fahrzeit, die plausibel
aussieht und von der falschen Stelle aus gerechnet ist. Der Reiter *Test* zeigt
jetzt die Koordinaten samt Kartenlink und hat einen Knopf, sie zu verwerfen.

### Sperrzeit auch für die Push-Nachricht

Die Sperrzeiten-Tabelle wirkte nur auf die Ansage. Ein Haken schaltet sie
zusätzlich auf `PUSH`.

### Eigener Ansagetext

Ein Feld mit den Platzhaltern `{titel} {ort} {fahrt} {abfahrt_in} {beginn}`.
Leer bleibt es beim mitgelieferten Text &mdash; der ist seit 1.5.8 zweisprachig.

### Neues Feld `ANKUNFT`

Die voraussichtliche Ankunft in **Minuten seit Mitternacht**, wenn man jetzt
losführe (1440 = unbekannt). Damit beantwortet die Anlage die Frage, die
`ABFAHRT_IN` nicht beantwortet: bin ich schon zu spät, und um wie viel.

Das Feld steht **am Ende** der Liste, damit alle bisherigen Befehlserkennungen
unverändert gültig bleiben. Wer die Importdatei neu einliest, bekommt es
mit; wer den virtuellen Eingang von Hand anlegt, braucht
`\i;ANKUNFT=\i\v`.

> **Nebenbei behoben:** Die Verweise in der Baustein-Liste („Ausgang von #10")
> waren als Zahlen in die Sprachdatei getippt. Ein neuntes Feld hätte jeden
> davon um eins verschoben &mdash; lautlos, denn eine Zahl sieht immer richtig
> aus. Sie werden jetzt aus der Feldtabelle berechnet.

### MQTT: alle Werte im Takt erneut senden

Gesendet wird nur, was sich geändert hat. Startet der **Miniserver** neu, ohne
dass der LoxBerry neu startet, bleiben seine virtuellen Eingänge deshalb leer,
bis sich zufällig ein Wert bewegt. Im Reiter *MQTT* lässt sich ein Abstand
einstellen, in dem alles erneut gesendet wird. **Vorgabe 15 Minuten**, `0` = aus.

### Ganztagestermine

Ein Ganztagestermin nennt keine Uhrzeit &mdash; ohne Angabe lässt sich nicht
sagen, wann man losfahren soll, und er wird verworfen. Mit dem neuen Haken gilt
eine einstellbare Uhrzeit dieses Tages als Terminbeginn.

## Neu in 1.5.8

Fehlerbehebungen aus einer Zeile-für-Zeile-Prüfung. **Drei davon betreffen
bestehende Anlagen unmittelbar:**

* **Der Hintergrunddienst konnte nie starten &mdash; seit 1.5.0.**
  `bin/abfahrt_dienst.php` suchte seine Programmbibliothek über
  `dirname(__DIR__) . '/webfrontend/html/abfahrt_lib.php'`. Im entpackten
  Archiv liegen `bin/` und `webfrontend/` nebeneinander, dort geht das auf; auf
  dem installierten LoxBerry liegen sie in **getrennten Bäumen**, und der
  Aufruf endete bei jedem Cron-Lauf mit
  `Failed opening required '/opt/loxberry/bin/plugins/webfrontend/html/abfahrt_lib.php'`.
  Damit wurde seit 1.5.0 nie gerechnet: kein `stand.json`, nichts über MQTT,
  und `termin.php` lieferte an Loxone dauerhaft `OK=0;MINSTART=9999`. Bemerkt
  hat es niemand, weil der Cron nach `/dev/null` schreibt und `OK=0` in Loxone
  aussieht wie &bdquo;kein Termin gefunden&ldquo; statt wie ein Defekt.
  Die Bibliothek wird jetzt über eine Kandidatensuche gefunden, und wenn
  keiner der Pfade passt, sagt der Dienst auf der Fehlerausgabe, **welche
  Datei er wo gesucht hat**.

* **Das Merkwort überlebt jetzt das Speichern.** Bisher löschte jedes
  Speichern der Einstellungen das Merkwort aus der Konfiguration; beim
  nächsten Seitenaufbau entstand ein neues. Der Virtuelle Ausgang im
  Miniserver trug weiter das alte und bekam ab da HTTP 403 &mdash; die Ansage
  verstummte, ohne dass irgendwo etwas zu sehen war. **Nach dem Update einmal
  die Oberfläche öffnen und das im Reiter *Einbindung in Loxone* angezeigte
  Merkwort mit dem in Loxone Config vergleichen.**
* **Die Baustein-Liste nennt das Merkwort in der Ausgangsadresse.** Wer sie
  bis 1.5.7 eins zu eins nachgebaut hat, hat einen Ausgang gebaut, der nie
  funktionieren konnte.

Im Kalender behoben (alle nachgerechnet):

| Fall | bisher | jetzt |
|---|---|---|
| `FREQ=MONTHLY`, DTSTART 31.01., `INTERVAL=2` | 01.10.2026 | 31.01.2027 |
| `FREQ=YEARLY`, DTSTART 29.02.2024 | 01.03.2027 | 29.02.2028 |
| `FREQ=MONTHLY;BYDAY=3TH` (jeder dritte Donnerstag) | So 16.08. | Do 20.08. |
| `FREQ=MONTHLY;BYDAY=-1FR` (letzter Freitag) | Mo 31.08. | Fr 28.08. |
| `FREQ=DAILY;BYDAY=MO,TU,WE,TH,FR` | löst auch am Wochenende aus | nur werktags |
| `LOCATION;LANGUAGE=de-DE:` (Outlook/Exchange) | Termin wird nicht gefunden | gefunden |
| `DTSTART;TZID=...;VALUE=DATE-TIME:` | Termin wird nicht gefunden | gefunden |
| `EXDATE;VALUE=DATE-TIME:` | gelöschte Instanz erscheint weiter | entfällt |
| `TZID="America/New_York"` (in Anführungszeichen) | als Berliner Zeit gerechnet | richtig |
| `RECURRENCE-ID;RANGE=THISANDFUTURE` | wurde nicht ausgewertet | verschiebt bzw. beendet die Serie |
| `LOCATION:` im Beschreibungstext | wurde als Ortsangabe genommen | nur echte Eigenschaftszeilen |

Weiter behoben:

* **Sperrzeit über Mitternacht** gilt jetzt der Nacht, in der sie *beginnt*.
  Wer nur den Samstag sperrte, wurde bisher am Sonntag um 01:13 doch
  angesprochen. Gleiche Anfangs- und Endzeit bedeutet jetzt ganztägig statt
  nie.
* **Die Befehlserkennungen im Reiter *Einbindung in Loxone*** tragen das
  führende Semikolon (`\i;FAHRT=\i\v`) &mdash; wie die Importdatei und wie
  seit 1.5.0 angekündigt.
* **Die Vorgabelautstärke wirkt wieder.** Die Zonenvorgabe lautete `1~25`,
  und eine Zonenangabe mit Lautstärke hat Vorrang: ab Werk sprach die Anlage
  mit 25 %, obwohl überall 8 % stand.
* **&bdquo;Ansage gesprochen&ldquo; steht nur noch im Protokoll, wenn sie
  gesprochen wurde.** Bisher galt auch die Fehlerseite eines Music Servers als
  Erfolg. Meldungen sagen jetzt, wer geantwortet hat (abgewiesen, Zeitablauf,
  Name unbekannt, HTTP-Code).
* **Eine leere oder abgebrochene Zwischenspeicherdatei** ergab eine Fahrzeit
  von null Minuten &mdash; mit `OK=1` und `FEHLER=0`. Inhalte werden geprüft,
  geschrieben wird unteilbar. Koordinaten verfallen nach 90 Tagen.
* **Nach einem Fehler stehen keine alten Termindaten mehr da** &mdash; Anzeige
  und Ansage nannten sonst einen längst vergangenen Termin.
* **Die Oberfläche ist ohne JavaScript bedienbar** (der offene Reiter steht
  jetzt im ausgelieferten HTML), ein Reiterwechsel wirft keine Eingaben mehr
  weg, und alle vier Formulare tragen ein Einmalmerkmal gegen seitenfremd
  ausgelöste Absendungen.
* **Zweisprachigkeit vervollständigt:** Wochentage, Sperrzeiten-Beschriftung,
  Meldungen, Platzhalter und der **Ansagetext** kamen bisher auch bei
  englischer Oberfläche auf Deutsch.
* `termin.php` beantwortet jetzt ebenfalls `?selftest=1&token=...`.
* Ohne gesetztes `LBHOMEDIR` endete die Oberfläche mit einem fatalen Fehler;
  fehlte die Programmbibliothek, ebenso &mdash; jetzt gibt es eine Meldung,
  die sagt, welche Datei wo erwartet wurde.

## Neu in 1.5.7
**Token prüfbar, ohne dass das Haus spricht.** Bisher ließ sich das Merkwort
nur prüfen, indem man den Ansage-Endpunkt aufrief — und dann redete die Anlage.
Mit `?force=1` sogar an den Ruhezeiten vorbei.

Neu: `?selftest=1&token=…` durchläuft dieselbe Token-Prüfung und endet dann
sofort mit `SELFTEST;OK=1;TOKEN=OK`. Keine Ansage, kein Aufruf des
Audio-Servers, keine Freigabeprüfung. Ein falsches Token bekommt unverändert
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
| `/plugins/abfahrtsassistent/termin.php` | Flat-Text: `TERMIN;OK=1;MINSTART=..;FAHRT=..;ABFAHRT_IN=..;FEHLER=..;ALTER=..;AUDIO=..;PUSH=..;ANKUNFT=..` |
| `/plugins/abfahrtsassistent/termin.php?debug=1&token=…` | Diagnose — **rechnet neu**, alle anderen Aufrufe lesen nur ab |
| `/plugins/abfahrtsassistent/termin_say.php?token=…` | Ansage auslösen (bzw. `TEXT=...` im Audioserver-Modus) |
| `…/termin.php?selftest=1&token=…` bzw. `…/termin_say.php?selftest=1&token=…` | Merkwort prüfen, **ohne** dass etwas ausgelöst wird — Antwort `SELFTEST;OK=1;TOKEN=OK` |

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
Loxone* erzeugt auf Knopfdruck eine fertige Importdatei mit allen neun
Eingängen.

`FEHLER` ist eine Zahl für den Statusbaustein: 0 in Ordnung, 1 kein Kalender,
2 kein API-Key, 3 keine Abfahrtsadresse, 4 kein Termin, 6 Kartendienst tot,
**7 Kartendienst tot, letzte bekannte Fahrzeit gilt weiter** (OK bleibt 1).

## Hinweise

- Termine ohne Ortsangabe (LOCATION) werden ignoriert — nur für Termine mit
  Ziel lässt sich eine Fahrzeit berechnen.
- Ganztagestermine werden ab Werk übergangen; sie lassen sich in den
  Einstellungen mit einer festen Uhrzeit einschalten (siehe oben).
- Abfragelimits: ICS-Zwischenspeicher 10 min; ein Kalender, der sich nicht
  mehr laden lässt, gilt höchstens 6 Stunden weiter (dann `FEHLER=5`).
  Koordinaten verfallen nach 90 Tagen. Der **Routen-Cache
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

## Fassung 1.6.5 — der Stat-Zwischenspeicher
Die Protokollkappung (512 000 Byte) stand in
`webfrontend/html/abfahrt_lib.php:666`. PHP merkt sich aber die Antworten
von `stat()`: innerhalb **eines** Prozesses sieht `filesize()` die erste
Größe und danach nie wieder eine neue — `file_put_contents(…, FILE_APPEND)`
macht den Eintrag nicht ungültig. Die Kappung fällt dann still aus.

Gemessen am 29.08.2026, 20 000 Zeilen im selben Prozess:

| | ohne `clearstatcache` | mit |
|---|---|---|
| PHP 7.4.33 | 1 220 000 Byte, **nicht gekappt** | 220 332 Byte, gekappt |
| PHP 8.4.24 | 220 332 Byte, gekappt | 220 332 Byte, gekappt |

Die beiden PHP-Fassungen verhalten sich also verschieden — und LoxBerry 3.x
fährt 7.4. Wer nur unter 8.4 misst, sieht den Fehler nie. Folgen hatte das
hier nicht: die Aufrufer sind kurzlebig, und ein **frischer** Prozess kappt
richtig. Eine Funktion darf aber nicht davon abhängen, wer sie wie oft ruft.

Abhilfe: `clearstatcache(true, …)` **vor** dem Tor; der zweite Parameter
beschränkt das Leeren auf diese eine Datei. Dasselbe Muster tragen Robonect,
Saugroboter, SignalBot, Octopus, Sprachsteuerung und WärmepumpeCloud schon
länger — es ist am 29.08.2026 im ganzen Bestand nachgezogen worden.

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
