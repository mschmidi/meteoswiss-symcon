# MeteoSchweizHagelwarnung

Ruft Gewitter-/Hagelwarnungen für eine Schweizer Postleitzahl ab und stellt sie
als Variablen in IP-Symcon bereit, damit darauf basierend Ereignisse (z. B.
Markisen einfahren, Push-Benachrichtigung) ausgelöst werden können.

## Voraussetzungen

- IP-Symcon ab Version 6.0
- Internetzugang des IP-Symcon-Servers (keine weiteren Abhängigkeiten)

## Funktionsweise

Das Modul fragt periodisch die Warnungs-API der offiziellen MeteoSwiss-App für
die konfigurierte Postleitzahl ab. MeteoSchweiz führt Hagel nicht als eigenen
Warntyp, sondern als Bestandteil der Gewitterwarnung – das Modul wertet daher
die Gewitterwarnung aus und prüft optional, ob im Warntext explizit "Hagel"
erwähnt wird.

> **Hinweis:** Es handelt sich um die inoffizielle, nicht dokumentierte API der
> MeteoSwiss-App (`app-prod-ws.meteoswiss-app.ch`). Es gibt keine Garantie für
> Stabilität oder Verfügbarkeit dieser Schnittstelle.

## Installation in IP-Symcon

1. In IP-Symcon unter **Modules** → **Module Store** → **Meine eigenen Module**
   die URL dieses Repositories hinzufügen:
   `https://github.com/mschmidi/meteo-api`
2. Modul **MeteoSchweizHagelwarnung** installieren.
3. Neue Instanz unter dem gewünschten Kategorie-Knoten anlegen und PLZ
   konfigurieren.
4. Auf Basis der Variable `HagelAktiv` bzw. `Warnstufe` ein IP-Symcon-Ereignis
   erstellen.

## Konfiguration

| Eigenschaft               | Beschreibung                                                        |
|----------------------------|----------------------------------------------------------------------|
| Postleitzahl (PLZ)         | Schweizer PLZ des zu überwachenden Ortes                              |
| Aktualisierungsintervall   | Abfrageintervall in Minuten                                          |
| Nur bei Hagel-Erwähnung    | `HagelAktiv` nur setzen, wenn der Warntext "Hagel" enthält; sonst gilt jede aktive Gewitterwarnung als `HagelAktiv` |

## Variablen

| Ident                  | Beschreibung                                                   |
|--------------------------|-------------------------------------------------------------------|
| `Warnstufe`             | Aktuelle Gewitter-/Hagel-Warnstufe (0 = keine, 5 = sehr gross)   |
| `HagelAktiv`            | `true`, wenn aktuell eine (Hagel-)Warnung vorliegt               |
| `WarnText`              | Warntext von MeteoSchweiz (Klartext)                             |
| `WarnTextHTML`          | Warntext von MeteoSchweiz (HTML, ausgeblendet)                    |
| `GueltigVon`/`GueltigBis` | Gültigkeitszeitraum der Warnung                                |
| `Ausblick`              | `true`, wenn es sich um eine Vorwarnung/Ausblick handelt          |
| `LetzteAktualisierung`  | Zeitpunkt der letzten erfolgreichen Abfrage                       |

## PHP-Befehlsreferenz

```php
MSH_UpdateWarnung(int $InstanzID): void
```

Stösst eine sofortige Aktualisierung der Warndaten an (auch über den Button
"Jetzt aktualisieren" in der Instanzkonfiguration verfügbar).

## Instanzstatus

| Code | Bedeutung                                  |
|------|---------------------------------------------|
| 102  | Aktiv                                        |
| 104  | Inaktiv                                      |
| 201  | Ungültige Postleitzahl                       |
| 202  | Fehler beim Abrufen der Warndaten            |
