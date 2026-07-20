# meteo-api

IP-Symcon Integration für MeteoSchweiz-Daten.

**Erster Schritt:** Ein Modul, das Hagel-/Gewitterwarnungen von MeteoSchweiz für eine
konfigurierbare Postleitzahl abruft und als Variablen in IP-Symcon bereitstellt, damit
darauf basierend Ereignisse (z. B. Markisen einfahren, Push-Benachrichtigung) ausgelöst
werden können.

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
> Es gibt keine Garantie für Stabilität oder Verfügbarkeit dieser Schnittstelle. Sobald
> MeteoSchweiz eine offizielle Open-Data-Schnittstelle für Warnungen veröffentlicht
> (aktuell laut [opendatadocs.meteoswiss.ch](https://opendatadocs.meteoswiss.ch/) in
> Vorbereitung), sollte das Modul darauf umgestellt werden.

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

### Geplante nächste Schritte

- Weitere Warntypen (Wind, Regen, Schnee, Glatteis) als eigene Instanzen/Variablen.
- Offizielle MeteoSchweiz Open-Data-Schnittstelle nutzen, sobald verfügbar.
