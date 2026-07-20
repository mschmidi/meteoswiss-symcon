# meteo-api

IP-Symcon Integration für MeteoSchweiz-Daten.

**Erster Schritt:** Zwei Module, um Hagelgefahr in IP-Symcon abzubilden, damit
darauf basierend Ereignisse (z. B. Markisen einfahren, Push-Benachrichtigung)
ausgelöst werden können:

- **[`MeteoSchweizHagelwarnung`](#modul-meteoschweizhagelwarnung):** Text-Warnungen
  der MeteoSwiss-App (inoffizielle API, einfach, ohne zusätzliche Abhängigkeiten).
- **[`MeteoSchweizHagelradar`](#modul-meteoschweizhagelradar):** Offizielle
  Radardaten POH/MESHS (offizielle Open-Data-API, benötigt einen zusätzlichen
  Python-Helper, siehe [Installationsanleitung](docs/hagelradar-installation.md)).

Beide Module können unabhängig voneinander oder zusammen betrieben werden.

## Modul: MeteoSchweizHagelwarnung

### Funktionsweise

Das Modul fragt periodisch die Warnungs-API der offiziellen MeteoSwiss-App für die
konfigurierte Postleitzahl ab. MeteoSchweiz führt Hagel nicht als eigenen Warntyp,
sondern als Bestandteil der Gewitterwarnung – das Modul wertet daher die
Gewitterwarnung aus und prüft optional, ob im Warntext explizit "Hagel" erwähnt wird.

> **Hinweis:** Es handelt sich um die inoffizielle, nicht dokumentierte API der
> MeteoSwiss-App (`app-prod-ws.meteoswiss-app.ch`). Sie wurde durch Reverse Engineering
> bekannt (u. a. genutzt in den Open-Source-Projekten
> [`swiss_meteo_warnings`](https://github.com/marquisolivier/swiss_meteo_warnings) für
> Home Assistant und [`ioBroker.meteoswiss`](https://github.com/deMynchi/ioBroker.meteoswiss)).
> Es gibt keine Garantie für Stabilität oder Verfügbarkeit dieser Schnittstelle. Für
> belastbare Werte siehe das Modul `MeteoSchweizHagelradar` unten, das die offizielle
> Datenquelle nutzt.

### Erzeugte Variablen

| Ident                 | Beschreibung                                              |
|------------------------|-------------------------------------------------------------|
| `Warnstufe`            | Aktuelle Gewitter-/Hagel-Warnstufe (0 = keine, 5 = sehr gross) |
| `HagelAktiv`           | `true`, wenn aktuell eine (Hagel-)Warnung vorliegt          |
| `WarnText`             | Warntext von MeteoSchweiz (Klartext)                        |
| `WarnTextHTML`         | Warntext von MeteoSchweiz (HTML, ausgeblendet)               |
| `GueltigVon`/`GueltigBis` | Gültigkeitszeitraum der Warnung                          |
| `Ausblick`             | `true`, wenn es sich um eine Vorwarnung/Ausblick handelt     |
| `LetzteAktualisierung` | Zeitpunkt der letzten erfolgreichen Abfrage                  |

### Konfiguration

- **Postleitzahl (PLZ):** Schweizer PLZ des zu überwachenden Ortes.
- **Aktualisierungsintervall:** Abfrageintervall in Minuten.
- **Nur bei Hagel-Erwähnung:** Wenn aktiviert, wird `HagelAktiv` nur gesetzt, wenn der
  Warntext das Wort "Hagel" enthält. Wenn deaktiviert, gilt jede aktive Gewitterwarnung
  als `HagelAktiv`.

### Installation in IP-Symcon

1. In IP-Symcon unter **Modules** → **Module Store** → **Meine eigenen Module** die URL
   dieses Repositories hinzufügen: `https://github.com/mschmidi/meteo-api`
2. Modul **MeteoSchweizHagelwarnung** installieren.
3. Neue Instanz unter dem gewünschten Kategorie-Knoten anlegen und PLZ konfigurieren.
4. Auf Basis der Variable `HagelAktiv` bzw. `Warnstufe` ein IP-Symcon-Ereignis erstellen.

## Modul: MeteoSchweizHagelradar

### Funktionsweise

Nutzt die **offizielle** MeteoSchweiz Open-Data-Quelle für Hagel: die Radarprodukte
[POH und MESHS](https://opendatadocs.meteoswiss.ch/d-radar-data/d3-hail-radar-products)
(`ch.meteoschweiz.ogd-radar-hail`), abgerufen über die FSDI-STAC-API
(`data.geo.admin.ch`). POH gibt die Hagelwahrscheinlichkeit (0–100 %) pro
Radarpixel an, MESHS die erwartete maximale Hagelkorngrösse (mm). Beide werden
nur zwischen 1. April und 30. September berechnet und alle 5 Minuten
aktualisiert.

Die Rohdaten liegen als Raster im HDF5-Format (ODIM-Standard) vor – PHP kann
das nicht nativ lesen. Ein separates Python-Skript (`helper/`) läuft auf
demselben Raspberry Pi als systemd-Timer, lädt die aktuellste Datei, liest den
Pixelwert an der konfigurierten Koordinate aus und schreibt das Ergebnis in
eine lokale JSON-Datei. Das IP-Symcon-Modul liest ausschliesslich diese Datei
– es braucht selbst keinen HDF5-Zugriff.

**Vollständige Installationsanleitung (Helper + Modul):**
[docs/hagelradar-installation.md](docs/hagelradar-installation.md)

### Erzeugte Variablen

| Ident              | Beschreibung                                                  |
|---------------------|------------------------------------------------------------------|
| `POH`               | Hagelwahrscheinlichkeit am konfigurierten Standort (%)          |
| `MESHS`             | Erwartete maximale Hagelkorngrösse am Standort (mm)              |
| `HagelGefahr`       | `true`, wenn POH oder MESHS über dem konfigurierten Schwellenwert liegt und die Daten aktuell sind |
| `Datenzeitstempel`  | Zeitpunkt der zugrunde liegenden Radardaten                      |
| `SaisonAktiv`       | `true` zwischen April und September (ausserhalb: keine Daten)    |
| `LetzterFehler`     | Letzte Fehlermeldung des Helper-Skripts, falls vorhanden          |

### Konfiguration

- **Pfad zur status.json:** Ausgabedatei des Python-Helpers.
- **Aktualisierungsintervall:** Wie oft das Modul die Datei neu einliest.
- **Schwellenwerte POH/MESHS:** Ab wann `HagelGefahr` gesetzt wird.
- **Max. Alter:** Ab wann Daten als veraltet gelten (z. B. wenn der Helper
  ausfällt) und `HagelGefahr` sicherheitshalber nicht mehr gesetzt wird.

## Geplante nächste Schritte

- Weitere Warntypen (Wind, Regen, Schnee, Glatteis) als eigene Instanzen/Variablen.
- WebFront-Visualisierung der Hagelradar-Daten.
