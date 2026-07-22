# Installation: Offizielle Hagelradar-Daten (POH/MESHS) auf Raspberry Pi

Diese Anleitung installiert den Python-Helper, der die offiziellen MeteoSchweiz
Radarprodukte **POH** (Probability of Hail, Hagelwahrscheinlichkeit in %) und
**MESHS** (Maximum Expected Severe Hail Size, erwartete Hagelkorngrösse in mm)
von der [MeteoSchweiz Open-Data-STAC-API](https://opendatadocs.meteoswiss.ch/d-radar-data/d3-hail-radar-products)
abruft, sowie das dazugehörige IP-Symcon-Modul `MeteoSchweizHagelradar`.

Im Gegensatz zum Modul `MeteoSchweizHagelwarnung` (App-Warntext, inoffizielle
API) ist dies die **offizielle, dokumentierte, lizenzierte** MeteoSchweiz
Open-Data-Quelle. Die Rohdaten sind Radar-Raster im HDF5-Format – PHP kann
das nicht nativ lesen, deshalb übernimmt ein separates Python-Skript den
Abruf und die Auswertung und schreibt das Ergebnis in eine JSON-Datei, die
das IP-Symcon-Modul einliest.

Voraussetzung: IP-Symcon läuft nativ auf einem Raspberry Pi mit Raspberry Pi
OS (Raspbian), d. h. PHP und das hier installierte Python-Skript laufen auf
demselben System.

## 1. Systembenutzer anlegen

```bash
sudo useradd --system --no-create-home --shell /usr/sbin/nologin meteoswiss-hail
```

## 2. Python-Abhängigkeiten installieren

`h5py` aus Quellcode zu bauen ist auf dem Raspberry Pi sehr langsam (HDF5
muss mitkompiliert werden). Stattdessen die vorkompilierten Debian-Pakete
verwenden:

```bash
sudo apt update
sudo apt install -y python3-venv python3-h5py python3-requests
```

Virtuelle Umgebung mit Zugriff auf die System-Pakete anlegen (damit das
apt-installierte `h5py` sichtbar ist, ohne es erneut zu kompilieren):

```bash
sudo mkdir -p /opt/meteoswiss-hail-radar
sudo python3 -m venv --system-site-packages /opt/meteoswiss-hail-radar/venv
```

## 3. Skript installieren

Repository auf den Pi klonen oder die Dateien aus `MeteoSchweizHagelradar/helper/` kopieren:

```bash
sudo cp MeteoSchweizHagelradar/helper/meteoswiss_hail_radar.py /opt/meteoswiss-hail-radar/
sudo chown -R meteoswiss-hail:meteoswiss-hail /opt/meteoswiss-hail-radar
```

## 4. Konfigurationsverzeichnis anlegen

Standort und Ausgabepfad müssen **nicht von Hand** in eine Datei eingetragen
werden: Das IP-Symcon-Modul schreibt `config.json` in Schritt 8 selbst, sobald
dort Latitude/Longitude gesetzt sind. Dafür muss das Verzeichnis lediglich
einmalig angelegt und für IP-Symcon beschreibbar gemacht werden – welcher
Systembenutzer das im Detail ist, unterscheidet sich je nach Installation
(z. B. `root` beim offiziellen Symcon-Image). Am einfachsten für eine
Einzelbenutzer-Installation auf dem eigenen Pi:

```bash
sudo mkdir -p /etc/meteoswiss-hail-radar
sudo chmod 777 /etc/meteoswiss-hail-radar
```

Wer striktere Rechte bevorzugt: Den tatsächlichen Benutzer des laufenden
IP-Symcon-Prozesses ermitteln (z. B. `ps -eo user,comm | grep -i symcon`) und
das Verzeichnis stattdessen diesem Benutzer bzw. einer gemeinsamen Gruppe mit
`meteoswiss-hail` zuordnen.

Zum Testen des Helpers unabhängig von IP-Symcon (optional, siehe Schritt 6)
kann `config.json` auch manuell aus der Vorlage erzeugt werden – sie wird
sobald das IP-Symcon-Modul einmal angewendet wurde ohnehin automatisch
überschrieben:

```bash
sudo cp MeteoSchweizHagelradar/helper/config.example.json /etc/meteoswiss-hail-radar/config.json
sudo chown meteoswiss-hail:meteoswiss-hail /etc/meteoswiss-hail-radar/config.json
```

## 5. Ausgabeverzeichnis anlegen

```bash
sudo mkdir -p /var/lib/meteoswiss-hail-radar
sudo chown meteoswiss-hail:meteoswiss-hail /var/lib/meteoswiss-hail-radar
```

Das Skript schreibt `status.json` mit Rechten `644` (für alle lesbar), damit
IP-Symcon die Datei unabhängig vom verwendeten Systembenutzer lesen kann.

## 6. Manueller Testlauf

```bash
sudo -u meteoswiss-hail /opt/meteoswiss-hail-radar/venv/bin/python3 \
    /opt/meteoswiss-hail-radar/meteoswiss_hail_radar.py \
    --config /etc/meteoswiss-hail-radar/config.json -v
cat /var/lib/meteoswiss-hail-radar/status.json
```

Erwartet wird ein JSON etwa wie:

```json
{
    "generated_at": "2026-07-20T14:35:07Z",
    "season_active": true,
    "poh_percent": 63.0,
    "poh_valid_time": "2026-07-20T14:30:00+00:00",
    "meshs_mm": 24.0,
    "meshs_valid_time": "2026-07-20T14:30:00+00:00",
    "last_error": null
}
```

Falls `last_error` gesetzt ist oder die Ausgabe unerwartet aussieht: Abschnitt
[Fehlersuche](#fehlersuche) unten.

## 7. systemd-Service und -Timer einrichten

```bash
sudo cp MeteoSchweizHagelradar/helper/meteoswiss-hail-radar.service MeteoSchweizHagelradar/helper/meteoswiss-hail-radar.timer /etc/systemd/system/
sudo systemctl daemon-reload
sudo systemctl enable --now meteoswiss-hail-radar.timer
```

Status und Logs prüfen:

```bash
systemctl status meteoswiss-hail-radar.timer
journalctl -u meteoswiss-hail-radar.service -f
```

Der Timer startet den Service alle 5 Minuten (passend zum Update-Rhythmus
von POH/MESHS).

## 8. IP-Symcon-Modul einrichten

1. Falls noch nicht geschehen: In IP-Symcon unter **Modules** → **Module
   Store** → **Meine eigenen Module** die Repository-URL hinzufügen:
   `https://github.com/mschmidi/meteo-api`
2. Modul **MeteoSchweizHagelradar** installieren.
3. Neue Instanz anlegen. **Latitude/Longitude** werden beim Anlegen
   automatisch aus dem IP-Symcon-Systemstandort übernommen, falls dieser
   konfiguriert ist (Einstellungen → System → Standort). Falls nicht, oder um
   ihn zu aktualisieren: Button **"Standort aus IP-Symcon übernehmen"**
   klicken, oder die Koordinaten manuell eintragen.
4. Beim Speichern (Übernehmen) schreibt das Modul Standort und Ausgabepfad
   automatisch in die in Schritt 4 vorbereitete `config.json` des Helpers –
   das ist der einzige Punkt, an dem diese Datei berührt wird, und geschieht
   ab hier vollständig aus IP-Symcon heraus.
5. Weitere Konfiguration:
   - **Aktualisierungsintervall**: 5 Minuten (muss nicht kleiner sein als
     der systemd-Timer, da sonst nur alte Werte erneut gelesen werden)
   - **Schwellenwerte** für POH (%) und MESHS (mm), ab denen die Variable
     `HagelGefahr` auf `true` gesetzt wird
   - **Daten gelten als veraltet nach**: Sicherheitsnetz, falls der
     systemd-Timer/Helper ausfällt – dann wird `HagelGefahr` automatisch
     nicht mehr gesetzt und der Instanzstatus zeigt einen Fehler
   - Unter **Erweitert**: Pfade zur Helper-Konfiguration und zur
     `status.json`, nur bei abweichender Installation (z. B. Schritt 4/5
     angepasst) ändern
6. Auf Basis der Variable `HagelGefahr` (oder direkt `POH`/`MESHS` mit
   eigener Logik) ein IP-Symcon-Ereignis erstellen.

## Fehlersuche

**`h5py` lässt sich nicht installieren / Kompilierung schlägt fehl**
→ Nicht per `pip install h5py` versuchen, sondern das Debian-Paket
`python3-h5py` verwenden (Schritt 2). Die venv muss mit
`--system-site-packages` angelegt sein, sonst ist das Paket in der venv
nicht sichtbar.

**`status.json` wird nicht geschrieben / Berechtigungsfehler**
→ Prüfen, ob `/var/lib/meteoswiss-hail-radar` dem Benutzer
`meteoswiss-hail` gehört (Schritt 5), und ob der Service tatsächlich als
dieser Benutzer läuft (`systemctl status meteoswiss-hail-radar.service`).

**IP-Symcon zeigt Status "Statusdatei nicht lesbar"**
→ Datei existiert, ist aber für den IP-Symcon-Prozess nicht lesbar. Mit
`ls -l /var/lib/meteoswiss-hail-radar/status.json` prüfen (sollte `644`
sein). Falls IP-Symcon in einer eingeschränkten Umgebung (z. B. `open_basedir`)
läuft, das Verzeichnis dort freigeben oder `output_path` auf einen
erlaubten Pfad legen.

**`last_error` enthält "HDF5 ... fehlen Attribute" oder ähnliche Struktur-Fehler**
→ MeteoSchweiz hat das Dateiformat geändert oder weicht vom angenommenen
ODIM_H5-Schema ab. Struktur der aktuellen Datei ansehen:

```bash
sudo -u meteoswiss-hail /opt/meteoswiss-hail-radar/venv/bin/python3 \
    /opt/meteoswiss-hail-radar/meteoswiss_hail_radar.py \
    --config /etc/meteoswiss-hail-radar/config.json --inspect poh
```

Die Ausgabe zeigt alle Gruppen, Datasets und Attribute der Datei. Mit diesen
Informationen lässt sich `read_pixel_value()` in
`meteoswiss_hail_radar.py` entsprechend anpassen.

**IP-Symcon zeigt Status "Standort nicht konfiguriert"**
→ Latitude/Longitude sind noch `0.0`/`0.0`. IP-Symcon-Systemstandort setzen
(Einstellungen → System → Standort) und Button "Standort aus IP-Symcon
übernehmen" klicken, oder die Koordinaten direkt in der Instanz eintragen.

**IP-Symcon zeigt Status "Helper-Konfiguration konnte nicht geschrieben werden"**
→ Das in Schritt 4 vorbereitete Verzeichnis (Standard
`/etc/meteoswiss-hail-radar`) ist für den IP-Symcon-Prozess nicht
beschreibbar. Rechte prüfen (`ls -ld /etc/meteoswiss-hail-radar`) und ggf.
`sudo chmod 777 /etc/meteoswiss-hail-radar` erneut ausführen. Details stehen
im IP-Symcon-Meldungsfenster.

**Keine Werte zwischen Oktober und März**
→ Erwartetes Verhalten: POH/MESHS werden laut MeteoSchweiz nur zwischen
1. April und 30. September berechnet. `season_active` in der `status.json`
zeigt das an; ausserhalb der Saison bleiben `poh_percent`/`meshs_mm` `null`.
