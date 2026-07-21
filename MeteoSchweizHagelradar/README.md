# MeteoSchweizHagelradar

Liest die offiziellen MeteoSchweiz-Radarprodukte **POH** (Probability of Hail)
und **MESHS** (Maximum Expected Severe Hail Size) für einen konfigurierten
Standort und stellt sie als Variablen in IP-Symcon bereit.

> **Kein eigenständig lauffähiges Modul:** Die Rohdaten sind HDF5-Raster, die
> PHP nicht nativ lesen kann. Dieses Modul liest ausschliesslich eine lokale
> JSON-Datei, die ein separates Python-Helper-Skript (`helper/` im
> Repository-Root) schreibt. Ohne installierten und laufenden Helper liefert
> das Modul keine Daten. Vollständige Installationsanleitung inkl. Helper:
> [`../docs/hagelradar-installation.md`](../docs/hagelradar-installation.md)

## Voraussetzungen

- IP-Symcon ab Version 6.0
- Python-Helper gemäss Installationsanleitung auf demselben Host eingerichtet
  (z. B. Raspberry Pi mit systemd)
- Lesezugriff des IP-Symcon-Prozesses auf die vom Helper geschriebene
  Statusdatei

## Installation in IP-Symcon

1. In IP-Symcon unter **Modules** → **Module Store** → **Meine eigenen Module**
   die URL dieses Repositories hinzufügen:
   `https://github.com/mschmidi/meteo-api`
2. Modul **MeteoSchweizHagelradar** installieren.
3. Python-Helper gemäss [Installationsanleitung](../docs/hagelradar-installation.md)
   einrichten.
4. Neue Instanz anlegen, Pfad zur `status.json` und Schwellenwerte
   konfigurieren.
5. Auf Basis der Variable `HagelGefahr` ein IP-Symcon-Ereignis erstellen.

## Konfiguration

| Eigenschaft                   | Beschreibung                                                        |
|---------------------------------|------------------------------------------------------------------------|
| Pfad zur status.json           | Ausgabedatei des Python-Helpers                                       |
| Aktualisierungsintervall       | Wie oft das Modul die Datei neu einliest                              |
| Schwellenwert POH              | Ab wann `HagelGefahr` gesetzt wird (Prozent)                          |
| Schwellenwert MESHS            | Ab wann `HagelGefahr` gesetzt wird (Millimeter)                       |
| Daten gelten als veraltet nach | Sicherheitsnetz: ab diesem Alter wird `HagelGefahr` nicht mehr gesetzt |

## Variablen

| Ident              | Beschreibung                                                                    |
|----------------------|-------------------------------------------------------------------------------|
| `POH`               | Hagelwahrscheinlichkeit am konfigurierten Standort (%)                        |
| `MESHS`             | Erwartete maximale Hagelkorngrösse am Standort (mm)                            |
| `HagelGefahr`       | `true`, wenn POH oder MESHS über dem Schwellenwert liegt und Daten aktuell sind |
| `Datenzeitstempel`  | Zeitpunkt der zugrunde liegenden Radardaten                                    |
| `SaisonAktiv`       | `true` zwischen April und September (ausserhalb: keine Daten)                  |
| `LetzterFehler`     | Letzte Fehlermeldung des Helper-Skripts, falls vorhanden                       |

## PHP-Befehlsreferenz

```php
MSHR_UpdateWarnung(int $InstanzID): void
```

Liest die Statusdatei sofort erneut ein (auch über den Button "Jetzt
aktualisieren" in der Instanzkonfiguration verfügbar).

## Instanzstatus

| Code | Bedeutung                                                |
|------|------------------------------------------------------------|
| 102  | Aktiv                                                       |
| 104  | Inaktiv                                                     |
| 202  | Statusdatei nicht lesbar oder ungültig                      |
| 203  | Daten des Helper-Skripts sind veraltet                      |
| 204  | Helper-Skript meldet einen Fehler (siehe `LetzterFehler`)   |
