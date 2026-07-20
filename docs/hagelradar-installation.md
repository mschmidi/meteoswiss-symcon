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

Repository auf den Pi klonen oder die Dateien aus `helper/` kopieren:

```bash
sudo cp helper/meteoswiss_hail_radar.py /opt/meteoswiss-hail-radar/
sudo chown -R meteoswiss-hail:meteoswiss-hail /opt/meteoswiss-hail-radar
```

## 4. Konfiguration anlegen

```bash
sudo mkdir -p /etc/meteoswiss-hail-radar
sudo cp helper/config.example.json /etc/meteoswiss-hail-radar/config.json
sudo nano /etc/meteoswiss-hail-radar/config.json
```

Mindestens `latitude` und `longitude` (WGS84, z. B. aus Google Maps oder den
IP-Symcon Systemstandort-Einstellungen) auf den gewünschten Standort
anpassen:

```json
{
    "latitude": 47.3769,
    "longitude": 8.5417,
    "output_path": "/var/lib/meteoswiss-hail-radar/status.json"
}
```

```bash
sudo chown -R meteoswiss-hail:meteoswiss-hail /etc/meteoswiss-hail-radar
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
sudo cp helper/meteoswiss-hail-radar.service helper/meteoswiss-hail-radar.timer /etc/systemd/system/
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
3. Neue Instanz anlegen. Konfiguration:
   - **Pfad zur status.json**: `/var/lib/meteoswiss-hail-radar/status.json`
     (Standardwert, nur ändern falls in Schritt 4 ein anderer `output_path`
     gewählt wurde)
   - **Aktualisierungsintervall**: 5 Minuten (muss nicht kleiner sein als
     der systemd-Timer, da sonst nur alte Werte erneut gelesen werden)
   - **Schwellenwerte** für POH (%) und MESHS (mm), ab denen die Variable
     `HagelGefahr` auf `true` gesetzt wird
   - **Daten gelten als veraltet nach**: Sicherheitsnetz, falls der
     systemd-Timer/Helper ausfällt – dann wird `HagelGefahr` automatisch
     nicht mehr gesetzt und der Instanzstatus zeigt einen Fehler
4. Auf Basis der Variable `HagelGefahr` (oder direkt `POH`/`MESHS` mit
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

**Keine Werte zwischen Oktober und März**
→ Erwartetes Verhalten: POH/MESHS werden laut MeteoSchweiz nur zwischen
1. April und 30. September berechnet. `season_active` in der `status.json`
zeigt das an; ausserhalb der Saison bleiben `poh_percent`/`meshs_mm` `null`.
