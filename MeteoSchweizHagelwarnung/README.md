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
   `https://github.com/mschmidi/meteoswiss-symcon`
2. Modul **MeteoSchweizHagelwarnung** installieren.
3. Neue Instanz unter dem gewünschten Kategorie-Knoten anlegen und PLZ
   konfigurieren.
4. Auf Basis der Variable `HagelAktiv` bzw. `Warnstufe` ein IP-Symcon-Ereignis
   erstellen. Zusätzlich auf Basis von `SchutzNichtGewaehrleistet` ein
   Ereignis erstellen, um innerhalb IP-Symcon zu erkennen, wenn die
   Schnittstelle selbst gestört ist (siehe Variablen-Tabelle unten).

## Konfiguration

| Eigenschaft               | Beschreibung                                                        |
|----------------------------|----------------------------------------------------------------------|
| Postleitzahl (PLZ)         | Schweizer PLZ des zu überwachenden Ortes                              |
| Aktualisierungsintervall   | Abfrageintervall in Minuten                                          |
| Nur bei Hagel-Erwähnung    | `HagelAktiv` nur setzen, wenn der Warntext "Hagel" enthält; sonst gilt jede aktive Gewitterwarnung als `HagelAktiv` |

## Variablen

| Ident                        | Beschreibung                                                   |
|--------------------------------|-------------------------------------------------------------------|
| `SchutzNichtGewaehrleistet`  | **`true`, wenn den Warndaten aktuell nicht vertraut werden kann** (Abruf- oder Parse-Fehler, ungültige PLZ). Primäres Signal für ein eigenes "Schnittstelle gestört"-Ereignis. Wird bei jedem Durchlauf aktiv neu gesetzt, friert also nicht auf einem alten Wert ein. |
| `Warnstufe`                  | Aktuelle Gewitter-/Hagel-Warnstufe (0 = keine, 5 = sehr gross)   |
| `HagelAktiv`                 | `true`, wenn aktuell eine (Hagel-)Warnung vorliegt **und** `SchutzNichtGewaehrleistet` `false` ist |
| `WarnText`                   | Warntext von MeteoSchweiz (Klartext)                             |
| `WarnTextHTML`               | Warntext von MeteoSchweiz (HTML, ausgeblendet)                    |
| `GueltigVon`/`GueltigBis`    | Gültigkeitszeitraum der Warnung                                |
| `Ausblick`                   | `true`, wenn es sich um eine Vorwarnung/Ausblick handelt          |
| `LetzteAktualisierung`       | Zeitpunkt der letzten **erfolgreichen** Abfrage                   |
| `LetztePruefung`             | Zeitpunkt, an dem **dieses Modul selbst** zuletzt gelaufen ist (auch bei Fehlern) – Watchdog-Basis, siehe unten |

### Watchdog für den Fall, dass der Timer selbst ausfällt

`SchutzNichtGewaehrleistet` erkennt Abruf-/Parse-Fehler der Schnittstelle,
aber nicht, wenn die Instanz/der Timer dieses Moduls selbst aufhört zu
laufen – das kann ein Modul grundsätzlich nicht selbst feststellen. Dafür
eignet sich ein zweites, unabhängiges, zeitgesteuertes IP-Symcon-Ereignis,
das `LetztePruefung` gegen das Aktualisierungsintervall prüft, z. B.:

```php
$maxAlterSekunden = 3 * IPS_GetProperty($HAGELWARNUNG_INSTANZ_ID, 'UpdateInterval') * 60;
if (time() - GetValue(IPS_GetObjectIDByIdent('LetztePruefung', $HAGELWARNUNG_INSTANZ_ID)) > $maxAlterSekunden) {
    // z. B. eigene Statusvariable/Dashboard-Anzeige auf "gestört" setzen
}
```

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
