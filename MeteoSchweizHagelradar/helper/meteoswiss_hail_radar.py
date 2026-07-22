#!/usr/bin/env python3
"""MeteoSchweiz Hagelradar-Helper.

Holt die aktuellsten POH- (Probability of Hail) und MESHS-Raster
(Maximum Expected Severe Hail Size) aus der offiziellen MeteoSchweiz
Open-Data-STAC-API (https://data.geo.admin.ch, Collection
"ch.meteoschweiz.ogd-radar-hail"), liest den Pixelwert an einer
konfigurierten Koordinate aus den ODIM-HDF5-Dateien und schreibt das
Ergebnis als JSON-Datei, die vom IP-Symcon-Modul "MeteoSchweizHagelradar"
gelesen wird.

Gedacht zum Betrieb als systemd-Timer alle 5 Minuten (siehe
meteoswiss-hail-radar.service/.timer in diesem Verzeichnis).
"""

from __future__ import annotations

import argparse
import datetime
import json
import logging
import os
import re
import sys
import tempfile

import h5py
import requests

ASSET_RE = re.compile(
    r'^(?P<code>BZC|MZC)(?P<yy>\d{2})(?P<jjj>\d{3})(?P<hhmm>\d{4})(?P<kk>\d{2})\.(?P<xyz>[^.]+)\.h5$'
)
CODE_BY_PARAM = {'poh': 'BZC', 'meshs': 'MZC'}
# Tagessummen (00:00-24:00 bzw. 06:00-06:00 UTC), keine 5-Minuten-Momentaufnahme.
DAILY_SUM_HHMM = {'2400', '3000'}

DEFAULT_CONFIG_PATH = '/etc/meteoswiss-hail-radar/config.json'
DEFAULT_OUTPUT_PATH = '/var/lib/meteoswiss-hail-radar/status.json'
DEFAULT_COLLECTION_BASE_URL = 'https://data.geo.admin.ch/api/stac/v1'
DEFAULT_COLLECTION_ID = 'ch.meteoschweiz.ogd-radar-hail'
USER_AGENT = 'IP-Symcon-MeteoSchweizHagelradar/1.0 (+https://github.com/mschmidi/meteoswiss-symcon)'

log = logging.getLogger('meteoswiss_hail_radar')


class HailRadarError(Exception):
    """Fachlicher Fehler beim Abruf oder der Auswertung der Radardaten."""


def wgs84_to_lv95(lat: float, lon: float) -> tuple[float, float]:
    """Naeherungsformel swisstopo: WGS84 (Grad) -> LV95 (E, N in Metern)."""
    lat_sec = lat * 3600
    lon_sec = lon * 3600
    lat_aux = (lat_sec - 169028.66) / 10000
    lon_aux = (lon_sec - 26782.5) / 10000

    e = (
        2600072.37
        + 211455.93 * lon_aux
        - 10938.51 * lon_aux * lat_aux
        - 0.36 * lon_aux * lat_aux ** 2
        - 44.54 * lon_aux ** 3
    )
    n = (
        1200147.07
        + 308807.95 * lat_aux
        + 3745.25 * lon_aux ** 2
        + 76.63 * lat_aux ** 2
        - 194.56 * lon_aux ** 2 * lat_aux
        + 119.79 * lat_aux ** 3
    )
    return e, n


def now_iso() -> str:
    return datetime.datetime.now(datetime.timezone.utc).strftime('%Y-%m-%dT%H:%M:%SZ')


def load_config(path: str) -> dict:
    with open(path, 'r', encoding='utf-8') as f:
        config = json.load(f)

    if 'latitude' not in config or 'longitude' not in config:
        raise HailRadarError(f'{path}: "latitude" und "longitude" sind Pflichtfelder.')

    config.setdefault('collection_base_url', DEFAULT_COLLECTION_BASE_URL)
    config.setdefault('collection_id', DEFAULT_COLLECTION_ID)
    config.setdefault('output_path', DEFAULT_OUTPUT_PATH)
    return config


def load_existing_status(path: str) -> dict:
    try:
        with open(path, 'r', encoding='utf-8') as f:
            return json.load(f)
    except (OSError, json.JSONDecodeError):
        return {
            'poh_percent': None,
            'poh_valid_time': None,
            'meshs_mm': None,
            'meshs_valid_time': None,
            'season_active': None,
            'generated_at': None,
            'last_checked_at': None,
            'last_error': None,
        }


def write_status(path: str, status: dict) -> None:
    directory = os.path.dirname(path)
    os.makedirs(directory, exist_ok=True)
    fd, tmp_path = tempfile.mkstemp(dir=directory, suffix='.tmp')
    try:
        with os.fdopen(fd, 'w', encoding='utf-8') as f:
            json.dump(status, f, indent=2, sort_keys=True, default=str)
        os.replace(tmp_path, path)
        os.chmod(path, 0o644)
    except Exception:
        if os.path.exists(tmp_path):
            os.remove(tmp_path)
        raise


def parse_asset_timestamp(match: 're.Match[str]') -> datetime.datetime:
    # Rollierendes 14-Tage-Fenster -> Jahrhundert eindeutig aus "yy" ableitbar.
    year = 2000 + int(match.group('yy'))
    day_of_year = int(match.group('jjj'))
    hour = int(match.group('hhmm')[:2])
    minute = int(match.group('hhmm')[2:])
    base = datetime.datetime(year, 1, 1, tzinfo=datetime.timezone.utc)
    return base + datetime.timedelta(days=day_of_year - 1, hours=hour, minutes=minute)


def find_latest_asset(session: requests.Session, base_url: str, collection_id: str, param: str) -> dict | None:
    """Sucht ueber die STAC-Items der letzten 2 Tage den juengsten Asset-Link fuer POH/MESHS.

    Die Reihenfolge der von der API zurueckgegebenen Items ist nicht dokumentiert,
    daher wird ueber alle Items/Assets im Zeitraum das Maximum selbst bestimmt,
    statt sich auf eine bestimmte Sortierung zu verlassen.
    """
    code = CODE_BY_PARAM[param]
    now = datetime.datetime.now(datetime.timezone.utc)
    start = (now - datetime.timedelta(days=1)).strftime('%Y-%m-%dT00:00:00Z')
    end = now.strftime('%Y-%m-%dT23:59:59Z')

    url = f'{base_url}/collections/{collection_id}/items'
    params = {'datetime': f'{start}/{end}', 'limit': 10}

    best = None
    # Items sind auf ein rollierendes 14-Tage-Fenster begrenzt; mehr als eine
    # Handvoll Seiten fuer einen 2-Tage-Zeitraum waeren ein Hinweis auf eine
    # fehlerhafte "next"-Verlinkung der API - Obergrenze als Schutz vor einer
    # Endlosschleife.
    for _ in range(10):
        if not url:
            break
        response = session.get(url, params=params, timeout=20)
        response.raise_for_status()
        payload = response.json()

        for item in payload.get('features', []):
            for asset in item.get('assets', {}).values():
                href = asset.get('href')
                if not href:
                    continue
                match = ASSET_RE.match(os.path.basename(href))
                if not match or match.group('code') != code:
                    continue
                if match.group('hhmm') in DAILY_SUM_HHMM:
                    continue
                timestamp = parse_asset_timestamp(match)
                if best is None or timestamp > best['timestamp']:
                    best = {'href': href, 'timestamp': timestamp}

        next_link = next((l['href'] for l in payload.get('links', []) if l.get('rel') == 'next'), None)
        url = next_link
        params = None  # Der "next"-Link enthaelt die Query bereits vollstaendig.

    return best


def download_to_temp(session: requests.Session, href: str) -> str:
    response = session.get(href, timeout=30)
    response.raise_for_status()
    fd, path = tempfile.mkstemp(suffix='.h5')
    with os.fdopen(fd, 'wb') as f:
        f.write(response.content)
    return path


def read_pixel_value(h5_path: str, target_e: float, target_n: float) -> tuple[float | None, str]:
    """Liest den Pixelwert einer ODIM-HDF5-Cartesian-Datei an der gegebenen LV95-Koordinate.

    Struktur gemaess ODIM_H5 v2.4 (referenziert in der MeteoSchweiz-Doku):
    /where enthaelt die vier Eckkoordinaten in Lon/Lat sowie die Rastergroesse,
    /dataset1/data1/data das Raster, /dataset1/data1/what Gain/Offset/nodata/undetect.
    """
    with h5py.File(h5_path, 'r') as f:
        if 'where' not in f:
            raise HailRadarError('HDF5-Datei enthaelt keine "/where"-Gruppe. Struktur mit --inspect pruefen.')
        where = f['where'].attrs
        required = ('LL_lon', 'LL_lat', 'UR_lon', 'UR_lat', 'xsize', 'ysize')
        missing = [key for key in required if key not in where]
        if missing:
            raise HailRadarError(f'HDF5 "/where" fehlen Attribute {missing}. Struktur mit --inspect pruefen.')

        ll_e, ll_n = wgs84_to_lv95(float(where['LL_lat']), float(where['LL_lon']))
        ur_e, ur_n = wgs84_to_lv95(float(where['UR_lat']), float(where['UR_lon']))
        xsize = int(where['xsize'])
        ysize = int(where['ysize'])

        if not (ll_e <= target_e <= ur_e and ll_n <= target_n <= ur_n):
            raise HailRadarError('Konfigurierte Koordinaten liegen ausserhalb des Radar-Rasters.')

        col = int((target_e - ll_e) / (ur_e - ll_e) * (xsize - 1))
        row = int((ur_n - target_n) / (ur_n - ll_n) * (ysize - 1))

        if 'dataset1' not in f or 'data1' not in f['dataset1'] or 'data' not in f['dataset1']['data1']:
            raise HailRadarError('HDF5-Datei enthaelt kein "/dataset1/data1/data". Struktur mit --inspect pruefen.')

        dataset_group = f['dataset1']['data1']
        raw = dataset_group['data'][row, col]
        what = dataset_group['what'].attrs if 'what' in dataset_group else {}
        gain = float(what.get('gain', 1.0))
        offset = float(what.get('offset', 0.0))
        nodata = what.get('nodata')
        undetect = what.get('undetect')

        if nodata is not None and raw == nodata:
            return None, 'nodata'
        if undetect is not None and raw == undetect:
            return 0.0, 'undetect'

        return float(raw) * gain + offset, 'measured'


def inspect_h5(path: str) -> None:
    """Gibt die vollstaendige Gruppen-/Attribut-Struktur einer HDF5-Datei aus.

    Hilfsmittel, falls MeteoSchweiz von der angenommenen ODIM_H5-Struktur
    abweicht und read_pixel_value() angepasst werden muss.
    """
    with h5py.File(path, 'r') as f:
        print('Root-Attribute:')
        for key, value in f.attrs.items():
            print(f'  @{key} = {value!r}')

        def visit(name, obj):
            kind = 'Gruppe' if isinstance(obj, h5py.Group) else 'Dataset'
            extra = f' shape={obj.shape} dtype={obj.dtype}' if isinstance(obj, h5py.Dataset) else ''
            print(f'{kind}: /{name}{extra}')
            for key, value in obj.attrs.items():
                print(f'    @{key} = {value!r}')

        f.visititems(visit)


def fetch_parameter(session: requests.Session, config: dict, param: str, target_e: float, target_n: float) -> tuple[float | None, datetime.datetime | None]:
    asset = find_latest_asset(session, config['collection_base_url'], config['collection_id'], param)
    if asset is None:
        log.warning('Kein aktuelles %s-Asset im Zeitraum gefunden (ausserhalb der Saison?).', param.upper())
        return None, None

    path = download_to_temp(session, asset['href'])
    try:
        value, kind = read_pixel_value(path, target_e, target_n)
        log.info('%s: %s (%s) vom %s', param.upper(), value, kind, asset['timestamp'].isoformat())
        return value, asset['timestamp']
    finally:
        os.remove(path)


def run(config_path: str, inspect_param: str | None) -> int:
    config = load_config(config_path)

    session = requests.Session()
    session.headers.update({'Accept': 'application/json', 'User-Agent': USER_AGENT})

    if inspect_param:
        asset = find_latest_asset(session, config['collection_base_url'], config['collection_id'], inspect_param)
        if asset is None:
            print(f'Kein aktuelles {inspect_param.upper()}-Asset gefunden.')
            return 1
        path = download_to_temp(session, asset['href'])
        try:
            inspect_h5(path)
        finally:
            os.remove(path)
        return 0

    status = load_existing_status(config['output_path'])
    status['last_checked_at'] = now_iso()

    try:
        target_e, target_n = wgs84_to_lv95(float(config['latitude']), float(config['longitude']))

        poh_value, poh_time = fetch_parameter(session, config, 'poh', target_e, target_n)
        meshs_value, meshs_time = fetch_parameter(session, config, 'meshs', target_e, target_n)

        status['poh_percent'] = poh_value
        status['poh_valid_time'] = poh_time.isoformat() if poh_time else None
        status['meshs_mm'] = meshs_value
        status['meshs_valid_time'] = meshs_time.isoformat() if meshs_time else None

        month = datetime.datetime.now(datetime.timezone.utc).month
        status['season_active'] = 4 <= month <= 9
        status['generated_at'] = now_iso()
        status['last_error'] = None
    except Exception as exc:  # noqa: BLE001 - Fehler wird bewusst nach status['last_error'] durchgereicht.
        log.exception('Aktualisierung der Hagelradar-Daten fehlgeschlagen')
        status['last_error'] = str(exc)

    write_status(config['output_path'], status)
    return 0 if status.get('last_error') is None else 1


def main() -> int:
    parser = argparse.ArgumentParser(description='MeteoSchweiz Hagelradar-Helper (POH/MESHS) fuer IP-Symcon.')
    parser.add_argument('--config', default=DEFAULT_CONFIG_PATH, help=f'Pfad zur config.json (Default: {DEFAULT_CONFIG_PATH})')
    parser.add_argument(
        '--inspect',
        choices=['poh', 'meshs'],
        help='Statt eines Updates: neueste Datei des Parameters laden und HDF5-Struktur ausgeben.',
    )
    parser.add_argument('-v', '--verbose', action='store_true', help='Debug-Logging aktivieren.')
    args = parser.parse_args()

    logging.basicConfig(
        level=logging.DEBUG if args.verbose else logging.INFO,
        format='%(asctime)s %(levelname)s %(message)s',
    )

    try:
        return run(args.config, args.inspect)
    except HailRadarError as exc:
        log.error(str(exc))
        return 1


if __name__ == '__main__':
    sys.exit(main())
