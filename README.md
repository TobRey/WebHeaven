# WebAtze

Moderne Websites aus der Schweiz – die eigene Website von WebAtze samt
verstecktem Bereich, in dem neue Kundenwebsites entstehen.

> **Kurzfassung:** ZIP herunterladen, im cPanel-Dateimanager nach
> `public_html` entpacken, `install.php` im Browser aufrufen, fertig.
> Die ausführliche Anleitung steht in [`docs/installation.md`](docs/installation.md).

## Aufbau

```
public_html/     das, was auf den Server kommt
  index.php        nimmt jede Anfrage entgegen
  worker.php       arbeitet Aufträge ab (per Cronjob)
  install.php      einmalige Einrichtung, löscht sich danach selbst
  assets/          fertig gebautes Frontend (nicht von Hand ändern)
  app/             der Programmcode
    Core/            Router, Datenbank, Sitzungen, Sicherheit, Sprachen
    Http/            die einzelnen Seiten und Schnittstellen
    Ai/              Anbindung an Claude
    Build/           Website-Erzeugung, ZIP, Upload, Domainumzug
    Domain/          das Formular als geprüfte Daten (Brief)
    Templates/       die Section-Vorlagen und ihr Schema
    Views/           HTML-Vorlagen der eigenen Seiten
    Kit/             was in die Kundenwebsites eingebaut wird
      site/            Stylesheets, Skript, Kontaktformular, Zähler,
                       Hilfeseite, Terminbuchung
      admin/           der Bearbeitungsbereich beim Kunden
    Lang/            alle Texte auf Deutsch und Englisch
    Support/         Anbieter-Anleitungen und kleine Hilfen
  storage/         Projekte, ZIPs, Vorschauen, Protokolle

frontend/        Quellcode der sichtbaren Website (Vite, three.js)
docs/            Einrichtung Schritt für Schritt
tests/           der Testlauf
build.php        erzeugt das Auslieferungs-ZIP
```

## Wie eine Kundenwebsite entsteht

```
Formular (/create/neu)
      │
      ▼   Auftrag in der Datenbank, worker.php arbeitet ihn ab
analyse → plan → theme → inhalte → bilder → sprachen → bauen → pruefen
      │                                                            │
      │                                    vorschau → zip → referenz
      │                                        │        │
      │                                        │        └─ liegt im Adminbereich bereit
      │                                        └─ /vorschau/<kennung>, 24 Stunden
      └─ alte Website lesen (nur Texte und Bilder)
```

Jeder Schritt hat ein Zeitbudget von etwa 50 Sekunden und macht dort
weiter, wo er aufgehört hat. So läuft nichts in das Zeitlimit des
Shared-Hostings.

Claude liefert **strukturierte Daten**, kein HTML: Seitenaufbau,
Abschnitte, Texte, Farbtokens. Daraus baut PHP die Seite aus geprüften
Vorlagen. Deshalb ist das Ergebnis verlässlich responsiv – und deshalb
kann eine Änderung an einem Abschnitt den Rest der Website nicht
berühren.

**Eine Datenbank bekommt eine erzeugte Website nie von sich aus.** Nur
wenn im Zusatzinfo-Feld etwas beschrieben ist, das etwas speichern muss,
entsteht ein Verwaltungssystem dazu – und auch das ohne Datenbank: Die
Daten liegen als Datei neben der Website.

### Was zusätzlich mitgeliefert werden kann

Alles davon wird im Formular einzeln angehakt. Was nicht angehakt ist,
wird nicht mitgeliefert – keine Datei, keine Zeile im Quelltext.

| Im Formular | Beim Kunden | Was es tut |
|---|---|---|
| Bearbeitungsbereich | `/admin` | Texte, Bilder, Abschnitte selbst ändern |
| Besucher zählen | `/zaehler.php` | Pixel-Zählung auf dem eigenen Server, Wochenbericht per E-Mail |
| Hilfeseite | `/support` | Fragen stellen, Antwort erscheint auf derselben Seite |
| Anleitung schreiben | `/doc` | Anleitung in einfacher Sprache, aus dem Gebauten erzeugt |
| Termine (über Zusatzinfos) | `/buchen` | Terminbuchung mit Öffnungszeiten und Übersicht im Bereich des Kunden |

Die Besucherzählung braucht **keinen Zustimmungsbanner**: Gezählt wird
auf dem Server des Kunden, die Kennung entsteht aus einem Salz, das jede
Nacht wechselt, und zu WebAtze wandern nur Summen.

### Nach dem Bauen wird geprüft

Vier Prüfungen laufen am fertigen Ergebnis, nicht am Bauplan, und stehen
danach beim Projekt: **Tempo** (Gewicht der Seite, springende Bilder,
bremsende Skripte), **Verweise** (interne und fremde), **Rechtliches**
(Impressum, Datenschutz, eingebundene Fremddienste) und
**Rechtschreibung**. Keine davon kann einen Auftrag scheitern lassen –
der Kunde bekäme sonst wegen eines Tippfehlers gar keine Website.

### Was von selbst weiterläuft

`worker.php` erledigt stündlich mehr als nur die Aufträge:

* **Sicherungen** – einmal am Tag WebAtze selbst, und zwar **zweimal
  getrennt**: die Datenbank als SQL mitsamt Uploads, und daneben ein
  zweites Archiv mit **allen Dateien der Installation**. Bei jedem
  Durchgang kommt zusätzlich die Kundenwebsite dran, deren Sicherung am
  längsten zurückliegt. Von der eigenen Datenbank bleiben 14 Tage und je
  der Monatserste der letzten sechs Monate liegen; von einer
  Kundenwebsite nur die neuesten Stände – wie viele, steht im
  Wartungscenter, Standard sind zwei. Beide Archive stehen im
  Wartungscenter zum Herunterladen **und zum Löschen**.

  Warum zwei Archive und nicht eines: Im Dateiarchiv steckt
  zwangsläufig `app/config.php` und damit der Tresorschlüssel – ohne ihn
  wäre eine zurückgespielte Installation nur eine Hülle. Die Trennung
  liegt deshalb nicht in der Datei, sondern in den zwei Archiven: Wer
  die Datenbanksicherung findet, hat die verschlüsselten Zugänge, aber
  nicht den Schlüssel. Bewahre die beiden folglich nicht am selben Ort
  auf; im Archiv selbst steht das noch einmal.
* **Hosting-Überwachung** – jede eingetragene Kundenwebsite wird alle
  15 Minuten aufgerufen. Eine Website mit Domain nimmt sich die Aufsicht
  von selbst vor: beim Eintragen, und für ältere Einträge in der
  stündlichen Pflege. Ein Haken dafür war eine Entscheidung, die man
  vergessen konnte – und wer sie vergass, hatte eine Website, die
  niemand prüft. Gemeldet wird beim zweiten Fehlschlag in Folge, nicht
  beim ersten; ein einzelner Aussetzer ist meistens das Netz. Der Ablauf
  des Sicherheitszertifikats wird mitgelesen.
* **Besucherzahlen** – die eigene Website zählt sich laufend selbst,
  Kundenwebsites melden sich über den Einzeiler. Selbst gebaute Seiten
  mit eigener Zählung werden zusätzlich einmal täglich abgeholt;
  montags geht der Bericht raus.
* **Wartungsverträge** – am Fälligkeitstag entsteht ein
  Rechnungsentwurf. Verschickt wird er von Hand.

### Besucher: die eigene Website und alle Kundenwebsites

Unter *Besucher* stehen alle Websites nebeneinander – die eigene und
jede, die unter *Websites* eingetragen ist. Gezählt wird auf zwei Wegen:

* **Die eigene Website zählt sich selbst**, hier auf dem Server, mitten
  in der Antwort. Kein Skript, das jemand blockieren kann.
* **Jede andere Website** bekommt einen Einzeiler, der vor `</body>`
  eingesetzt wird. Er steht auf der Seite der jeweiligen Website zum
  Kopieren bereit. Damit lässt sich auch eine WordPress-, Wix- oder von
  Hand gebaute Seite zählen – nicht nur eine, die WebAtze gebaut hat.

Gespeichert wird **keine IP-Adresse, kein Cookie und kein dauerhafter
Wiedererkennungswert**. Ob jemand heute schon da war, entscheidet ein
Streuwert aus IP, Browserkennung und einem Salz, das jede Nacht
wechselt; das Salz von gestern wird nicht aufbewahrt. Deshalb braucht
weder die eigene noch eine Kundenwebsite dafür ein Zustimmungsbanner.

Wer sich als Programm zu erkennen gibt, wird nicht gezählt. Vom Verweis
bleibt nur der Rechnername (`google.com`), nie die Suchanfrage – die
kann persönliche Angaben enthalten.

### Der Auftragstext ist fertig verdrahtet

Im Formular stehen drei Haken – Anleitung, Hilfeseite, Besucherzählung.
Nicht jede Website braucht alle drei: Eine einzelne Landingpage mit einem
Formular hat nichts zu dokumentieren, und wer nicht gezählt werden will,
soll nicht gezählt werden.

Was gewählt ist, ist danach aber **fertig verdrahtet**. Im Auftragstext
stehen dann nicht Platzhalter, sondern die echten Werte: Adresse,
Schlüssel, Zugangscode, die Zählzeile mit dem richtigen Schlüssel darin.
Wer die fertigen Dateien hochlädt, hat eine Website, die zählt und über
die man Support bekommt – ohne einen einzigen Handgriff. Das gilt auch,
wenn der Auftragstext von Hand irgendwo eingefügt wird.

Genau darum geht es: Ein Haken ist eine Entscheidung, ein Platzhalter ist
eine Hausaufgabe. Entscheidungen trifft man gern, Hausaufgaben vergisst
man.

Was gewählt wurde, steht gleich im Kopf des Auftrags und noch einmal als
Liste zum Abhaken am Schluss:

* `/doc` – die Anleitung für den Kunden
* `/support` – die Hilfeseite, hinter einem Zugangscode
* die Zählzeile vor `</body>` auf **jeder** Seite

Nichts davon gewählt heisst: keine Zeile darüber im Auftrag. Ein Auftrag,
der Dinge verlangt, die niemand bestellt hat, wird nicht gelesen.

**Zum Schlüssel im Auftragstext.** Ein Auftragstext wird kopiert und
weitergereicht, also steht darin bewusst nicht der `assistant_token` –
mit dem liesse sich der Abschnitts-Editor ansteuern, und der kostet bei
jedem Aufruf Geld. Es ist ein eigener `support_token`, der genau
zweierlei kann: eine Supportnachricht dieser einen Website senden und
deren Gesprächsfaden lesen. Sollte ein Auftragstext irgendwo landen, wo
er nicht hingehört, erzeugt *Projekt → Schlüssel und Code neu erzeugen*
einen neuen; der alte gilt dann nicht mehr.

Der Zugangscode ist das Einzige, was von Hand weitergegeben wird – an
den Kunden, damit nur er über `/support` schreiben kann. Er steht auf der
Projektseite und lässt sich dort neu erzeugen.

### Intranet: was ich mir merken will

Kurze Beiträge hinter der Anmeldung, nur für mich. Kein Kunde sieht sie,
keine Schnittstelle gibt sie heraus. Deshalb dürfen hier Dinge stehen,
die auf der Website nichts zu suchen haben – Preise zum Beispiel.

Zwei Beiträge stehen von Anfang an da: der ganze Ablauf vom neuen Kunden
bis zur bezahlten Rechnung, und die Preisorientierung. Beide lassen sich
ändern und löschen; gelöscht kommen sie nicht wieder.

Überschriften, Aufzählungen, Tabellen, fett und Code werden dargestellt.
Der Text wird dabei **zuerst maskiert und erst danach umgeformt** –
damit kann aus einem Beitrag prinzipiell keine Auszeichnung entstehen,
die nicht vorgesehen ist.

### Zeichen im Menü

An den Menüpunkten steht eine Zahl, wenn dort etwas auf dich wartet:
neue Anfragen, unbeantwortete Supportfragen, ausgefallene Websites,
überfällige Rechnungen, ausgefüllte Fragebögen, Termine heute. Rot heisst
«da stimmt etwas nicht», ruhig heisst «da liegt etwas».

Zwanzig Menüpunkte einzeln durchzuklicken tut niemand jeden Tag – eine
Supportfrage lag deshalb schon einmal drei Tage.

### Websites: gebaute und hinzugefügte

Unter *Websites* stehen alle an einem Ort. Eine, die WebAtze gebaut hat,
führt auf ihre Projektseite mit Auftrag, Abschnitten und Ständen. Eine,
die schon da war – von jemand anderem gemacht, in WordPress, irgendwo
gehostet –, wird über *Website hinzufügen* eingetragen: Name, Adresse,
Kunde, womit sie gebaut ist, wo sie liegt, Notizen.

Beide liegen in derselben Tabelle, unterschieden durch `source`
(`ki` oder `hand`). Der Grund ist einfach: Wer wissen will, was er
betreut, will eine Liste und nicht zwei, die er im Kopf zusammenfügt.
Eine hinzugefügte Website wird genauso einem Kunden zugeordnet,
überwacht und abgerechnet – sie hat nur keinen Aufbau.

Beim Eintragen lässt sich die Erreichbarkeitsüberwachung gleich
mitanschalten; sie erscheint dann im Wartungscenter.

### Der Editor

Ein Werkzeug, drei Orte: bei WebAtze fuer jede Kundenwebsite, im
Bearbeitungsbereich beim Kunden, und - als naechster Schritt - auf der
eigenen Website. Dasselbe Buendel, dieselben Adressen, dieselben
Antworten.

**Ziehen.** Abschnitte am Griff anfassen, die moeglichen Plaetze werden
als Linien gezeigt, ablegen setzt sie dorthin. Dasselbe fuer die Listen
darin: Karten, Punkte, Bilder, Teammitglieder, Fragen. Kopf und Fuss
bleiben, wo sie sind.

**Auf dem Handy** dieselbe Oberflaeche. Die Maus zieht ab fuenf Pixeln,
der Finger erst nach 250 Millisekunden Halten - ohne diese Wartezeit
waere jede Wischbewegung ein Zug und der Editor dort unbenutzbar. Wer
nicht ziehen mag, tippt *Verschieben* an und dann den Platz. Am Rand
rollt die Seite von selbst weiter.

**Ohne Neuladen.** Der Server liefert den einen geaenderten Abschnitt als
HTML zurueck, der Editor tauscht genau dieses Element aus. Bildlauf,
Auswahl und Verlauf bleiben stehen.

**Desktop, Tablet, Handy** machen den Rahmen wirklich schmaler, sodass
die Umbruchregeln der Seite ausloesen. Was man sieht, ist, was der
Besucher sieht - nachgemessen am tatsaechlich wirksamen Raster: drei
Spalten, zwei, eine.

**Ein Promptfeld je Abschnitt und je Seite.** Erst wird gespeichert, was
von Hand geaendert wurde, dann laeuft die Anweisung; andersherum
arbeitete sie auf einem Stand, den es nicht mehr gibt. Der
Abschnitts-Prompt darf nur diesen einen Abschnitt aendern, der
Seiten-Prompt zusaetzlich Abschnitte anlegen, loeschen und umordnen - und
zwar ueber einen Plan aus Schritten, die es im Editor auch von Hand gibt.
Eine Antwort, die die ganze Seite auf einmal zurueckgaebe, schriebe bei
jedem Versuch auch die Abschnitte neu, die niemand angefasst haben
wollte.

Hintergrund, Bewegung und Parallaxe kommen aus einem festen Wortschatz
statt als freier Code. Neun Hintergrundarten mal drei Toene mal drei
Staerken sind 81 Hintergruende, dazu sechs Bewegungen und vier
Parallaxenstufen. Was fehlt, wird eine neue geprueft Zeile - und gilt
dann fuer jede Website. Freies CSS aus einem Sprachmodell kann eine Seite
zerlegen, und niemand merkt es, bis ein Kunde anruft.

**Der freie Abschnitt.** Eine leere Flaeche mit acht Bausteinen:
Ueberschrift, Text, Bild, Schaltflaeche, Symbol, Abstand, Trennlinie,
Spalten. Spalten sind der einzige Baustein, der andere aufnimmt, und nur
eine Ebene tief - Spalten in Spalten in Spalten sind auf einem Telefon
nicht mehr aufzuloesen, und was sich nicht aufloest, wird dort zu einer
Textwurst. Eine Ebene loest sich immer auf: Sie rutscht untereinander.

**Entwurf und Veroeffentlichen.** Gearbeitet wird am Entwurf; erst ein
Knopf schiebt ihn live. Jeder veroeffentlichte Stand bleibt als Fassung
liegen, *Entwurf verwerfen* holt den letzten zurueck.

### Die Bruecke: auf der Kundendomain veroeffentlichen

Eine Kundenwebsite live von hier aus fernzusteuern geht nicht, und zwar
grundsaetzlich nicht: Sie sendet `X-Frame-Options: SAMEORIGIN`, und
JavaScript darf ueber eine fremde Domain hinweg den Inhalt eines Rahmens
weder lesen noch veraendern. Das ist eine Grundregel des Browsers, keine
Einstellung.

Deshalb wird die **Kopie auf dem eigenen Server** bearbeitet - gleiche
Herkunft, also faellt beides weg - und ueber die **Bruecke**
veroeffentlicht: eine kleine Datei auf dem Kundenserver, die den neuen
Stand entgegennimmt und die Seite dort neu schreibt. Rund eine Sekunde.

Sie ist die einzige Stelle, an der ein anderer Server dort schreiben
darf, und entsprechend abgesichert:

* **Unterschrift statt Kennwort.** HMAC-SHA256 ueber Methode, Pfad,
  Zeitstempel, Einmalwert und den vollstaendigen Rumpf. Der Schluessel
  steht auf beiden Servern und geht nie ueber die Leitung.
* **Zeitfenster von zwei Minuten** und ein vermerkter Einmalwert. Eine
  mitgeschnittene Anfrage ist damit entweder zu alt oder war schon da.
* **Nur Daten, nie Code.** Was ankommt, geht durch dieselbe Pruefung wie
  eine Eingabe im Kundenbackend; gerendert wird mit dem Vorlagenwerk,
  das dort ohnehin liegt. Selbst eine gefaelschte Anfrage koennte kein
  PHP auf den Kundenserver bringen.
* **Abschaltbar.** `bridge_secret` in `data/config.php` leeren genuegt.
  Es ist die Website des Kunden.

Sie ist fuer den Editor gedacht und nimmt Daten entgegen, nie Code. Eine ganze
Website - die aus PHP besteht - geht den anderen Weg, ueber das ZIP darunter.

### Die Werkstatt: ZIP herein, ZIP hinaus

Von WebAtze aus geht **nichts** auf den Kundenserver. Drei Wege dorthin waren
durchgemessen und alle drei tot: FTP mit verworfener Datenverbindung, eine von Hand
hingelegte Empfangsdatei ueber HTTPS, eine dauerhafte Leseschnittstelle. Von einem
Hosting zum anderen kommt nichts durch - vom eigenen Rechner aus dagegen problemlos.

Also uebertraegt der Mensch, mit seinem eigenen FTP-Programm, in beide Richtungen.
WebAtze ist das, was dazwischen liegt:

1. Website beim Kunden herunterladen (FileZilla), als ZIP hier hochladen.
2. Das Archiv wird nach `storage/projects/<slug>/live` ausgepackt. Genau dieser
   Schritt war jahrelang der Einwand - fremde PHP-Dateien auf dem eigenen Webserver
   sind eine Hintertuer -, deshalb hier ohne Beschoenigung, was ihn traegt:
   `public_html/storage/.htaccess` verbietet jeden direkten Zugriff, und **das ist
   die einzige Sperre**. Der Ordner liegt unter `public_html`, weil WebAtze als ein
   Ordner ausgeliefert wird. Auf Apache und cPanel wirkt sie; wer sie loescht oder
   auf einen Server ohne `.htaccess` umzieht, macht die Kundendateien erreichbar.
   Der Testlauf prueft sie deshalb mit. Ausgeliefert wird sonst nur ueber
   `PreviewController` und `DirektController`, und beide schieben Bytes mit einer
   festen Typenliste.
3. Liegt `data/site.php` im Archiv - die Website als Daten, geschrieben von
   `AdminKit` -, kommt ihr Inhalt zurueck in die Datenbank. Danach steht im Editor
   wirklich das, was beim Kunden lag: seine Bilder, seine Anfragen, seine im eigenen
   Backend geaenderten Texte. Vor dem Ueberschreiben legt `Publications::record()`
   eine Fassung an.
4. Bearbeiten, speichern, Vorschau ansehen - alles auf dem eigenen Hosting.
5. **Website herunterladen**: der gebaute Stand plus alles aus dem uebernommenen
   Archiv, was der Bau nicht selbst erzeugt. Ohne das loeschte der naechste Upload
   beim Kunden genau die Bilder, die er selbst hochgeladen hat.
6. Mit dem FTP-Programm hinaufladen.

### Zwei Editoren, weil es zwei Arten von Website gibt

Der **Abschnittseditor** arbeitet auf Seiten und Abschnitten in der Datenbank. Den gibt
es nur fuer Websites, die WebAtze selbst gebaut hat.

Ein ZIP vom Hosting des Kunden hat davon nichts - es ist HTML. Frueher stand dort
"Diese Website hat noch keine Seiten", und das war wortwoertlich richtig und in der
Sache unbrauchbar: Die Seiten liegen ja da, sie stehen nur nicht in der Datenbank.
Dafuer gibt es die **Direktbearbeitung** (`/create/direkt/<id>`):

* Die Seite laeuft in einem Rahmen mit **gleicher Herkunft** wie der Adminbereich -
  deshalb greift das Skript daneben direkt hinein. In die Kundendatei wird nichts
  eingeschleust, und beim Speichern bleibt keine Spur der Bearbeitung.
* **Text anklicken und schreiben**, **Bild anklicken und tauschen**, Seite fuer Seite.
  Verschieben und neue Abschnitte gibt es nicht - dafuer braucht es das Datenmodell.
* Gespeichert wird die Datei selbst, mit **denselben Zeilenenden wie vorher**. Sonst
  gaelte beim naechsten Vergleich jede Zeile als geaendert.
* Der Weg vom Kunden aus: *Kunde → Website editieren →* ZIP einwerfen, bearbeiten,
  herunterladen. Das Fenster dazu enthaelt genau zwei Dinge: das Feld und die Liste
  der Staende.

Der Ordner, in dem gearbeitet wird, ist derselbe `live`-Ordner - was dort steht, faehrt
beim naechsten Herunterladen mit hinaus.

### Staende

Ein **Stand** ist eine Fassung der Website: ein Archiv und der Zeitpunkt, an dem
zuletzt darin gespeichert wurde. Einer ist aktiv - der, an dem gearbeitet wird.
Die letzten zehn aelteren bleiben liegen und lassen sich wieder aktiv setzen
(`Build\Staende`, Tabelle `site_versions`).

Die Regel, um die es dabei geht, steht in einem Satz:

> **Herunterladen packt immer den Ordner, in dem gearbeitet wird - frisch, bei jedem
> Klick. Ein Archiv, das schon dalag, wird dafuer nie benutzt.**

Vorher zeigte der Knopf auf den zuletzt abgelegten Eintrag. Nach einer Uebernahme war
das ausgerechnet das gerade hochgeladene, unbearbeitete Archiv: Wer eine Ueberschrift
aenderte, speicherte und herunterlud, bekam die Seite von vorher zurueck - und musste
glauben, das Speichern sei kaputt. Deshalb gibt es die Adresse mit der Nummer darin
(`/projekt/<id>/zip/<build>`) nicht mehr; `/projekt/<id>/stand` packt in diesem
Augenblick.

*Wiederherstellen* sichert vorher, woran gerade gearbeitet wird - immer, auch wenn der
gewaehlte Stand schon der aktive ist. Gerade dann: Zwischen seinem Archiv und dem
Ordner liegt genau die Arbeit, um die es geht.

**Was noch nicht draussen ist, steht in der Liste.** Nicht als gepflegtes Merkmal,
sondern aus den Daten: Ist die juengste Aenderung an einer Seite oder einem Abschnitt
juenger als der letzte Download, traegt die Website ein Abzeichen. Ein Merkmal, das
man pflegen muss, weicht irgendwann von der Wirklichkeit ab - und sagt dann das
Falsche an der Stelle, an der man sich darauf verlaesst.

Die **FTP-Zugangsdaten bleiben** trotzdem gespeichert, verschluesselt. Nicht zum
Verbinden - zum Nachschlagen, wenn FileZilla nach Server, Benutzer und Verzeichnis
fragt.

### Die CSS-Notfallebene

Sie hiess einmal *Frontend-Fix* und hatte einen eigenen Menuepunkt. Der
ist weg: Was sie leistete, leistet jetzt der Editor, und zwar besser - er
zeigt, was er aendert, statt es zu beschreiben.

Geblieben ist sie, weil sie der einzige Weg ist, das Aussehen der eigenen
Website zu aendern, **ohne etwas auszuliefern**. Wenn nach einer
Aktualisierung etwas verrutscht ist und niemand gerade ein ZIP hochladen
kann, ist das der Rettungsweg. Einen Rettungsweg abzubauen, weil er
selten benutzt wird, waere ein Fehler. Erreichbar bleibt sie ueber ihre
Adresse `/create/frontend-fix`.

### Frontend-Fix: die eigene Website per Textbefehl

Unter *Frontend-Fix* steht ein Textfeld. «Mach die Section Leistungen mit
einem dunkleren Hintergrund» – der Satz geht an die Schnittstelle, zurück
kommt CSS, und das liegt eine Sekunde später auf dem Server. Kein
Hochladen, kein Bauen, kein Dateimanager.

Damit die Beschreibung nicht ins Leere zielt, bekommt die Schnittstelle
eine Karte der Website mit: welche Abschnitte es gibt und unter welchen
Klassennamen sie im Quelltext stehen. Die Karte wird aus den echten
Ansichten gelesen, nicht gepflegt – eine gepflegte Liste wäre nach der
zweiten Änderung falsch.

**Nur CSS, und das mit Absicht.** Das sichtbare Frontend ist ein gebautes
Paket; es entsteht aus Quelldateien mit einem Werkzeug, das auf
gemietetem Hosting nicht läuft. Eine Änderung am Quelltext wäre also erst
nach dem nächsten Bauen zu sehen – und das findet dort nicht statt. Eine
Regelebene, die nach dem Stylesheet geladen wird, ändert dagegen alles am
Aussehen: Farben, Abstände, Schriften, Hintergründe, Sichtbarkeit. Was
sie nicht kann – neue Texte, neue Abschnitte, neue Seiten – steht so auch
in der Oberfläche.

Jede Anpassung steht einzeln in der Liste, mit dem Befehl, der zu ihr
geführt hat. Sie lässt sich **abschalten** (bleibt liegen) oder
**löschen**. Ein Knopf räumt alle auf einmal weg. Damit ist jede Änderung
in einem Klick zurückgenommen – das ist der Grund, warum sich hier ein
Textbefehl überhaupt an die eigene Website heranlassen darf.

Was zurückkommt, wird geprüft, bevor es auf die Website darf: nicht
grösser als 12 000 Zeichen, geschlossene Klammern, kein `@import`, kein
`url()` auf einen fremden Server, keine der alten Eigenheiten, über die
sich früher Code einschleusen liess. Fällt eine Anpassung durch, wird sie
gar nicht erst gespeichert. Die Datei wird nur verlinkt, solange
mindestens eine Anpassung eingeschaltet ist – ohne kostet die Funktion
keinen einzigen Aufruf.

### Vom Auftrag bis zur Buchhaltung

Was beim Anlegen einer Website erfasst wird, wird auch weiterverwendet –
statt an drei Stellen dasselbe abzutippen:

* **Passwörter landen von selbst im Tresor.** Backend, FTP und
  Zugangscode werden beim Abschicken des Formulars abgelegt, solange der
  Klartext noch da ist; eine Minute später steht in der Datenbank nur
  noch der Argon2id-Hash, und dann wäre nichts mehr abzulegen. In der
  Notiz steht **immer die Adresse des Kundenbackends** (`/admin`, und
  solange die Domain fehlt, mit dem Vermerk, dass sie nachkommt). Ein
  Passwort ohne die zugehörige Adresse ist ein Passwort, das man sucht.
  Ein Eintrag, den es unter demselben Namen schon gibt, wird
  übersprungen – ein zweiter Anlauf erzeugt keine Dubletten.
* **Offerten und Rechnungen lassen sich löschen.** Mit dem Beleg gehen
  das PDF und, war er bezahlt, die Einnahme. Eine Rechnung zu löschen und
  die Einnahme stehen zu lassen, wäre die schlechteste aller Varianten:
  Die Zahl in der Statistik hätte dann keinen Beleg mehr.
* **Auf «bezahlt» gestellt wird eine Rechnung zur Einnahme.** Sie
  erscheint im selben Moment unter *Buchhaltung*, im Monat des
  Zahlungsdatums. Wird der Zustand zurückgenommen, verschwindet sie
  wieder. Verbucht werden nur Rechnungen und nur einmal – eine Offerte,
  die versehentlich auf «bezahlt» steht, wird nicht stillschweigend zu
  Geld.
* **Anfragen und Supportfäden lassen sich löschen**, nicht nur als Spam
  markieren. Markieren ist richtig, solange man den Absender noch
  einordnen will; für den vierten Werbebrief derselben Firma will man
  einen Knopf, der ihn wegräumt. Gelöscht ist gelöscht, mitsamt allen
  Nachrichten des Fadens.

### Der Betrieb rundherum

Neben dem Bauen von Websites verwaltet `/create` auch das Geschäft:

* **Kunden, Kosten, Zahlungen** – ein Posten je Zeile mit eigenem
  Rhythmus (einmalig, monatlich, jährlich). Bezahlt wird je Periode
  abgehakt, nichts wird zurückgesetzt, und die Geschichte bleibt lesbar.
* **Kundensuche** – WebAtze schreibt den Suchauftrag, du lässt ihn dort
  laufen, wo eine KI mit Websuche zur Verfügung steht, und fügst die
  Antwort wieder ein. Danach eine Firma nach der anderen ansehen:
  annehmen, weglegen, oder für immer wegräumen. Weggelegte werden
  aufbewahrt, damit sie beim nächsten Suchlauf nicht wieder auftauchen.
  Dieser Server durchsucht Google **nicht** selbst – das verstösst gegen
  die Nutzungsbedingungen und liefert von einem Mietserver aus ohnehin
  meist eine Sperrseite.
* **Tresor** – alle Kundenzugänge, mit libsodium verschlüsselt. Kein
  Passwort steht je im Quelltext einer Seite; es wird einzeln geholt und
  geht direkt in die Zwischenablage, die nach 45 Sekunden geleert wird.
  Vor dem ersten Ansehen wird das Anmeldepasswort erneut verlangt, der
  Tresor bleibt danach 15 Minuten offen, falsche Versuche werden
  gebremst, und jeder Blick landet im Protokoll. Der Schlüssel steht in
  `app/config.php`, nicht in der Datenbank – eine gestohlene
  Datenbanksicherung allein ist damit wertlos.
* **Kalender aufs Telefon** – unter *Kalender* steht eine Adresse, die
  ein iPhone abonnieren kann (`.ics`). Termine und Aufgaben mit Frist
  erscheinen dann im Telefonkalender, mit Erinnerung 30 Minuten vorher
  beziehungsweise morgens um 8. Zwei Dinge dazu, die man wissen muss:
  iOS wirft die Erinnerungen abonnierter Kalender standardmässig weg –
  in den Einstellungen des Abonnements muss *Erinnerungen entfernen*
  ausgeschaltet werden. Und die Verbindung geht nur in eine Richtung:
  Ein Termin, der auf dem Telefon eingetragen wird, kommt nicht hierher
  zurück; dafür bräuchte es CalDAV, also einen eigenen Serverdienst, den
  geteiltes Hosting nicht anbietet. Wer die Adresse hat, sieht die
  Termine – sie enthält deshalb ein langes Zufallswort und lässt sich
  jederzeit neu erzeugen.

## Entwickeln

Voraussetzung: PHP 8.1+, Node 22+.

```bash
# Frontend bauen (erzeugt public_html/assets)
cd frontend && npm install && npm run build && cd ..

# Konfiguration anlegen
cp public_html/app/config.example.php public_html/app/config.php
# darin driver auf 'sqlite' stellen und die beiden Schlüssel erzeugen:
php -r 'echo bin2hex(random_bytes(32)), "\n";'

# Server starten
php -S 127.0.0.1:8080 -t public_html public_html/index.php
```

| Befehl | Wofür |
|---|---|
| `php tests/run.php` | der Testlauf (2154 Prüfungen) |
| `php tests/run.php --seiten-festhalten` | den Aufbau der eigenen Seiten neu festhalten |
| `php tools/eigene-website-uebernehmen.php` | zeigt, was aus der eigenen Website als Daten entstünde |
| `php tools/probelauf/durchlauf.php` | eine ganze Website bauen, vom Formular bis zum Paket |
| `php build.php` | beide ZIPs erzeugen (voll und nur zum Aktualisieren) |
| `cd frontend && npm run build` | Frontend neu bauen |
| `cd frontend && npm run dev` | Frontend mit Sofortaktualisierung |

### Ein ganzer Durchlauf vor dem Ausliefern

Der Testlauf prüft Einzelteile. Ob aus einem ausgefüllten Formular eine
vollständige Website wird, prüft er nicht – und genau dort sind
nacheinander drei Fehler in Betrieb gegangen, die eine einzige echte
Anfrage aufgedeckt hätte.

```bash
# In einem zweiten Fenster: die Attrappe der Schnittstelle
php -S 127.0.0.1:8126 tools/probelauf/api-attrappe.php

# Der Durchlauf, mit Aufrufen an die Attrappe
php tools/probelauf/durchlauf.php --mit-api

# Oder ohne Schnittstelle, im Übungsmodus
php tools/probelauf/durchlauf.php
```

Die Attrappe antwortet nicht einfach "ja". Sie setzt dieselben Regeln
durch, an denen der echte Dienst Anfragen abweist: die Kopfzeile mit dem
Arbeitsbereich, keine Grössenangaben im Schema, und eine Grammatik, die
nicht ausufert. Was sie annimmt, scheitert dort nicht mehr an der Form
der Anfrage.

Was der Durchlauf **nicht** leistet: Er sagt nichts über die Qualität der
Texte. Dafür braucht es den echten Dienst und einen Schlüssel.

### Zwei Pakete

`php build.php` erzeugt beide:

| Datei | Wofür |
|---|---|
| `webatze-<version>.zip` | die erste Einrichtung – mit `install.php` |
| `webatze-update-<version>.zip` | jede spätere Korrektur – **ohne** `install.php`, ohne `storage/` |

Das zweite wird über eine bestehende Installation entpackt. Konfiguration,
Datenbank, Projekte und Sicherungen bleiben unangetastet, und es ist
nichts neu einzugeben.

## Regeln

1. **Farbwerte stehen ausschliesslich in `frontend/src/styles/tokens.css`.**
   Überall sonst `var(--wa-...)`.
2. **Kein Text fest im Code.** Alle Texte in `public_html/app/Lang/de.php`
   und `en.php`.
3. **Jede Ausgabe läuft durch `e()`.** Auch das, was von der KI kommt.
4. **`app/config.php` wird niemals committet.** Nur die Beispieldatei.
5. Auf der öffentlichen Website steht nirgends, wie die Websites entstehen –
   und keine einzige Preiszahl. Beides prüft der Testlauf.
6. **Der Anthropic-Schlüssel verlässt diesen Server nicht.** Das
   Kundenbackend fragt über `/assistant/v1/edit` hier an; beim Kunden
   liegt nur ein Kennwort, das für eine einzige Website gilt.
7. Beim Kunden heisst es **„WebAtze-Assistent"**, nie Claude. Auch in der
   Anleitung unter `/doc` steht kein Wort darüber, wie die Website
   entstanden ist. Der Testlauf prüft das.
8. **`robots.txt` nennt den versteckten Bereich nicht.** Wer wissen will,
   wo er liegt, müsste sonst nur eine Datei öffnen. Die Adressen sind
   ohnehin `noindex`; das ist die stärkere Zusage. Auch das prüft der
   Testlauf – die Zeile kann nicht unbemerkt zurückkommen.
9. **Geld steht überall in Rappen, als ganze Zahl.** Fliesskomma und Geld
   gehören nicht zusammen; bei einer Rechnung merkt man das erst, wenn
   sich der Kunde meldet.

### Wenn ein Update neue Tabellen mitbringt

Das Update-Paket enthält bewusst keine `install.php` – ein Fehlklick dort
würde die Einrichtung überschreiben. Damit die neuen Tabellen trotzdem
entstehen, prüft WebAtze bei jedem Aufruf einen kleinen Merker in
`storage/schema-version.txt`: Passt er nicht zur eingespielten Fassung,
werden die fehlenden Tabellen angelegt und der Merker neu geschrieben.
Der Normalfall kostet einen Dateizugriff.

Du musst dafür nichts tun. Nach dem Entpacken genügt ein Aufruf einer
beliebigen Seite.

### Die Briefvorlage

`docs/vorlagen/WebAtze-Briefvorlage.docx` – A4, Kopfzeile mit Logo und
Absender, Verlaufslinie, dreispaltige Fusszeile mit Seitenzahl. In
eckigen Klammern steht, was auszufüllen ist. Sie lässt sich unter
*Einstellungen → Briefkopf* hochladen; WebAtze liest daraus die
Absenderzeilen und das Logo für Offerten und Rechnungen.

Erzeugt wird sie aus `tools/briefvorlage/` – wie, steht im README dort.

### Wenn der Tresor nicht verschlüsseln kann

Der Schlüssel dafür steht in `app/config.php` unter `crypto_key` – 64
Zeichen, nur `0-9` und `a-f`. Fehlt er oder taugt er nichts, kann der
Tresor kein Passwort ablegen. Das fällt spät auf: Die Seite lädt, das
Aufschliessen klappt, nur das Speichern geht nicht.

Deshalb sagt WebAtze es jetzt oben auf der Tresorseite, benennt den
Grund und bietet – solange noch nichts verschlüsselt ist – einen Knopf
an, der einen neuen Schlüssel in `app/config.php` einträgt. Liegt schon
etwas Verschlüsseltes vor, wird das verweigert: Ein neuer Schlüssel
macht Bisheriges unlesbar, und das ist keine Entscheidung für einen
Knopfdruck nebenbei.

### Zwei Verfahren, weil eines nicht überall da ist

WebAtze verschlüsselt mit **libsodium**, und wo das fehlt, mit
**OpenSSL (AES-256-GCM)**. Eines von beiden genügt; OpenSSL ist auf
praktisch jedem PHP vorhanden.

Der Grund für die zweite Möglichkeit ist ein konkreter Vorfall: Auf dem
Hosting war `sodium` in cPanel als eingeschaltet angezeigt, und PHP
kannte die Erweiterung trotzdem nicht. Der Tresor stand still, obwohl
auf dem Server alles Nötige vorhanden war.

Am Anfang eines gespeicherten Werts steht, womit er geschrieben wurde:

    v1.…   libsodium, XSalsa20-Poly1305
    v2.…   OpenSSL, AES-256-GCM

Gelesen wird beides. Ein Serverwechsel macht also nichts unlesbar,
solange eines der Verfahren da ist. Nur ein `v1`-Wert braucht wirklich
libsodium – die Oberfläche sagt das dann so, statt einen gewechselten
Schlüssel zu behaupten.

Wichtig für alles Weitere: Kein Programmteil ausserhalb von
`Core/Crypto.php` darf ein `SODIUM_*` oder `sodium_*` anfassen. Fehlt
die Erweiterung, gibt es weder die Funktionen noch die Konstanten – und
ein Zugriff auf eine undefinierte Konstante ist in PHP 8 keine Warnung,
sondern das Ende der Anfrage. Genau daran ist die Tresorseite einmal
gestorben, ausgerechnet an der Zeile, die einen Ersatzschlüssel
vorschlagen sollte. Der Testlauf prüft das jetzt.

Stimmt die Meldung im Adminbereich einmal nicht mit dem überein, was
cPanel anzeigt: Auf der Tresorseite steht unter *Was dieser Server
mitbringt*, was PHP tatsächlich sieht.

### Warum MySQL und SQLite auseinanderlaufen können

Der Testlauf hier läuft auf SQLite, das Hosting auf MySQL. An drei
Stellen verzeiht SQLite, was MySQL abweist – und jede davon endet auf dem
Hosting in einer Fehlerseite, die hier niemand sieht:

* **Reservierte Wörter als Spaltenname.** `interval` etwa. SQLite nimmt
  es an, MySQL bricht die Migration ab – und dann fehlen alle Tabellen ab
  dieser Stelle. Eine Prüfung im Testlauf geht alle Spaltennamen gegen
  die Liste der reservierten Wörter durch.
* **Ein benannter Platzhalter, zweimal in derselben Abfrage.** MySQL
  erlaubt das mit echten Prepared Statements nicht. Eine statische Suche
  im Quelltext half hier nur halb: Beim zweiten Vorfall war die Abfrage
  aus Stücken zusammengesetzt, und dann sieht die Suche nur Fragmente.
  Deshalb löst `Db::run()` wiederholte Platzhalter inzwischen selbst auf
  – sie bekommen eigene Namen und denselben Wert. Damit ist die Regel
  aufgehoben statt bloss überwacht.
* **`UPDATE` zählt anders.** MySQL meldet nur die Zeilen, in denen sich
  wirklich etwas geändert hat, SQLite die getroffenen. Wer denselben Wert
  noch einmal setzt, bekäme auf dem Hosting «nichts passiert». Deshalb
  läuft die MySQL-Verbindung mit `MYSQL_ATTR_FOUND_ROWS`.
