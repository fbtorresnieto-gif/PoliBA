"""Descarga pública explícita. No conecta a PostgreSQL ni altera catálogos existentes."""
from pathlib import Path
from urllib.request import urlopen
from datetime import datetime, timezone
from collections import Counter
import hashlib
import json

destino = Path(__file__).resolve().parents[2] / 'database' / 'catalogos' / 'georef'
destino.mkdir(parents=True, exist_ok=True)
manifest = {'fuente': 'API Georef Argentina', 'documentacion': 'https://www.argentina.gob.ar/georef/descarga-de-la-base-completa', 'descargado_en': datetime.now(timezone.utc).isoformat(), 'recursos': {}}
for tipo in ['provincias', 'localidades']:
    url = f'https://apis.datos.gob.ar/georef/api/v2.0/{tipo}.json'
    with urlopen(url, timeout=40) as response:
        raw = response.read()
    payload = json.loads(raw)
    items = payload[tipo] if isinstance(payload, dict) else payload
    if not items or not isinstance(items, list):
        raise ValueError('Respuesta incompleta de Georef')
    normalizados = []
    for item in items:
        fila = {'id': item['id'], 'nombre': item['nombre']}
        if tipo == 'localidades':
            fila.update({'provincia_id': item['provincia']['id'], 'departamento': item['departamento']['nombre'], 'departamento_id': item['departamento']['id'], 'categoria': item['categoria']})
        normalizados.append(fila)
    normalizados.sort(key=lambda r: r['id'])
    contenido = (json.dumps(normalizados, ensure_ascii=False, separators=(',', ':'))+'\n').encode('utf-8')
    (destino / f'{tipo}.json').write_bytes(contenido)
    manifest['recursos'][tipo] = {'url': url, 'cantidad': len(items), 'sha256_respuesta': hashlib.sha256(raw).hexdigest(), 'sha256_archivo': hashlib.sha256(contenido).hexdigest()}
    print(tipo, len(items), 'claves_respuesta', list(payload) if isinstance(payload, dict) else 'lista')
    if tipo == 'localidades':
        nombres = Counter((r['provincia_id'], r['nombre'].casefold()) for r in normalizados)
        duplicados = [k for k, cantidad in nombres.items() if cantidad>1]
        print('nombres homonimos dentro de una provincia:',len(duplicados))
        print('ejemplos:',json.dumps([r for r in normalizados if (r['provincia_id'],r['nombre'].casefold()) in duplicados][:6],ensure_ascii=True))
        print('CABA:',json.dumps([r for r in normalizados if r['provincia_id']=='02'][:18],ensure_ascii=True))
(destino / 'manifest.json').write_text(json.dumps(manifest,ensure_ascii=False,indent=2)+'\n', encoding='utf-8')
