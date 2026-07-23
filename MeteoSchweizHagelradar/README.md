# MeteoSchweizHagelradar

Liest die offiziellen MeteoSchweiz-Radarprodukte **POH** (Probability of Hail)
und **MESHS** (Maximum Expected Severe Hail Size) für einen konfigurierten
Standort und stellt sie als Variablen in IP-Symcon bereit.

> **Kein eigenständig lauffähiges Modul:** Die Rohdaten sind HDF5-Raster, die
> PHP nicht nativ lesen kann. Dieses Modul liest ausschliesslich eine lokale
> JSON-Datei, die ein separates Python-Helper-Skript (`helper/` in diesem
> Modulordner) schreibt. Ohne installierten und laufenden Helper liefert das
> Modul keine Daten. Vollständige Installationsanleitung inkl. Helper:
> [`INSTALLATION.md`](INSTALLATION.md)

## Voraussetzungen

- IP-Symcon ab Version 6.0
- Python-Helper gemäss Installationsanleitung auf demselben Host eingerichtet
  (z. B. Raspberry Pi mit systemd)
- Lesezugriff des IP-Symcon-Prozesses auf die vom Helper geschriebene
  Statusdatei

## Installation in IP-Symcon

1. In IP-Symcon unter **Modules** → **Module Store** → **Meine eigenen Module**
   die URL dieses Repositories hinzufügen:
   `https://github.com/mschmidi/meteoswiss-symcon`
2. Modul **MeteoSchweizHagelradar** installieren.
3. Python-Helper gemäss [Installationsanleitung](INSTALLATION.md)
   einrichten (inkl. beschreibbarem Konfigurationsverzeichnis, Schritt 4 dort).
4. Neue Instanz anlegen. Latitude/Longitude werden automatisch aus dem
   IP-Symcon-Systemstandort übernommen, sonst über den Button "Standort aus
   IP-Symcon übernehmen" oder manuell setzen. Schwellenwerte nach Bedarf
   anpassen.
5. Auf Basis der Variable `HagelGefahr` ein IP-Symcon-Ereignis erstellen.
   Zusätzlich auf Basis von `SchutzNichtGewaehrleistet` ein Ereignis
   erstellen, um innerhalb IP-Symcon zu erkennen, wenn die Schnittstelle
   selbst gestört ist (siehe Variablen-Tabelle unten) – z. B. um es auf
   einem Dashboard zu visualisieren oder mit eigener Benachrichtigungslogik
   zu verknüpfen.

Der Standort wird ausschliesslich in IP-Symcon gepflegt: Beim Speichern
schreibt das Modul Latitude/Longitude selbst in die Konfigurationsdatei des
Helpers – kein manuelles Datei-Editieren auf dem Host nötig.

## Konfiguration

| Eigenschaft                       | Beschreibung                                                        |
|-------------------------------------|------------------------------------------------------------------------|
| Latitude / Longitude               | Standort (WGS84), wird an den Helper weitergereicht                   |
| Aktualisierungsintervall           | Wie oft das Modul die Statusdatei neu einliest                        |
| Schwellenwert POH                  | Ab wann `HagelGefahr` gesetzt wird (Prozent, Standard 5 %)             |
| Schwellenwert MESHS                | Ab wann `HagelGefahr` gesetzt wird (Millimeter)                       |
| Daten gelten als veraltet nach     | Sicherheitsnetz: ab diesem Alter wird `HagelGefahr` nicht mehr gesetzt |
| Erweitert: Pfad Helper-Konfiguration | Wohin dieses Modul die Standort-Konfiguration für den Helper schreibt |
| Erweitert: Pfad zur status.json    | Ausgabedatei des Python-Helpers, die dieses Modul liest               |

## Variablen

| Ident                        | Beschreibung                                                                    |
|-------------------------------|---------------------------------------------------------------------------------|
| `SchutzNichtGewaehrleistet`  | **`true`, wenn dem System aktuell nicht vertraut werden kann** (Statusdatei nicht lesbar/veraltet, Standort fehlt oder der Helper selbst einen Fehler meldet). Primäres Signal für ein eigenes "Schnittstelle gestört"-Ereignis in IP-Symcon. |
| `POH`                        | Hagelwahrscheinlichkeit am konfigurierten Standort (%)                          |
| `MESHS`                      | Erwartete maximale Hagelkorngrösse am Standort (mm)                             |
| `HagelGefahr`                | `true`, wenn POH oder MESHS über dem Schwellenwert liegt **und** `SchutzNichtGewaehrleistet` `false` ist |
| `Datenzeitstempel`           | Zeitpunkt der zugrunde liegenden Radardaten                                     |
| `LetztePruefung`             | Zeitpunkt, an dem **dieses Modul selbst** zuletzt gelaufen ist – unabhängig davon, ob das Lesen der Statusdatei geklappt hat |
| `SaisonAktiv`                | `true` zwischen April und September (ausserhalb: keine Daten)                   |
| `LetzterFehler`              | Letzte Fehlermeldung des Helper-Skripts, falls vorhanden                        |

`SchutzNichtGewaehrleistet` wird bei jeder Aktualisierung aktiv neu gesetzt
(auch im Fehlerfall) statt beim letzten bekannten Wert stehen zu bleiben –
damit friert z. B. `HagelGefahr` nicht unbemerkt auf `false` ein, während die
Schnittstelle in Wirklichkeit gestört ist.

### Zwei Ebenen der Überwachung – Beispiel-Ereignisse

`SchutzNichtGewaehrleistet` deckt Störungen der *Datenquelle* ab (Helper-Fehler,
veraltete Daten, kein Standort). Was ein Modul grundsätzlich **nicht** selbst
erkennen kann: dass sein eigener Timer/die Instanz aufgehört hat zu laufen –
dafür bräuchte es einen Watchdog ausserhalb des Moduls. `LetztePruefung`
liefert dafür die Grundlage. Zwei Ereignisse decken damit beide Fälle ab:

1. **Datenquelle gestört:** Ereignis auf `SchutzNichtGewaehrleistet` →
   Bedingung "ändert sich auf `true`".
2. **Modul/Timer selbst gestört (Watchdog):** Ein zeitgesteuertes Ereignis
   (z. B. alle 30 Minuten), das prüft, ob `LetztePruefung` neuer ist als vor
   dem 3-fachen des Aktualisierungsintervalls:

   ```php
   $maxAlterSekunden = 3 * IPS_GetProperty($HAGELRADAR_INSTANZ_ID, 'UpdateInterval') * 60;
   if (time() - GetValue(IPS_GetObjectIDByIdent('LetztePruefung', $HAGELRADAR_INSTANZ_ID)) > $maxAlterSekunden) {
       // z. B. eigene Statusvariable/Dashboard-Anzeige auf "gestört" setzen
   }
   ```

   Dieses zweite Ereignis läuft bewusst unabhängig vom Hagelradar-Modul selbst
   (eigener Timer), damit es auch dann noch funktioniert, wenn Letzteres
   komplett steht.

## PHP-Befehlsreferenz

```php
MSHR_UpdateWarnung(int $InstanzID): void
```

Liest die Statusdatei sofort erneut ein (auch über den Button "Jetzt
aktualisieren" in der Instanzkonfiguration verfügbar).

```php
MSHR_StandortUebernehmen(int $InstanzID): void
```

Übernimmt den aktuellen IP-Symcon-Systemstandort als Latitude/Longitude
dieser Instanz und wendet die Änderung an (auch über den Button "Standort aus
IP-Symcon übernehmen" verfügbar).

## Instanzstatus

| Code | Bedeutung                                                        |
|------|---------------------------------------------------------------------|
| 102  | Aktiv                                                               |
| 104  | Inaktiv                                                             |
| 202  | Statusdatei nicht lesbar oder ungültig                              |
| 203  | Daten des Helper-Skripts sind veraltet                              |
| 204  | Helper-Skript meldet einen Fehler (siehe `LetzterFehler`)           |
| 205  | Standort nicht konfiguriert (Latitude/Longitude sind `0.0`/`0.0`)   |
| 206  | Helper-Konfiguration konnte nicht geschrieben werden                |
