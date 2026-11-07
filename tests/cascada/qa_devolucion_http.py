"""Fallback de prueba local: usa el formulario real, sesión y CSRF; no conecta a la BD.

No valida el confirm() nativo del navegador. Solo admite la fixture QA ficticia creada
por preparar_qa_pantallas.ps1 y el servidor 127.0.0.1:8087.
"""
from html.parser import HTMLParser
from http.cookiejar import CookieJar
from pathlib import Path
from urllib.parse import urlencode
from urllib.request import build_opener, HTTPCookieProcessor, Request
from urllib.error import HTTPError
import json
import re
import sys


class Formularios(HTMLParser):
    def __init__(self):
        super().__init__()
        self.formularios = []
        self.actual = None

    def handle_starttag(self, tag, attrs):
        a = dict(attrs)
        if tag == 'form':
            self.actual = {}
        elif tag == 'input' and self.actual is not None and a.get('type') == 'hidden' and 'name' in a:
            self.actual[a['name']] = a.get('value', '')

    def handle_endtag(self, tag):
        if tag == 'form' and self.actual is not None:
            self.formularios.append(self.actual)
            self.actual = None


def main():
    carpeta = Path(sys.argv[1])
    fixture = json.loads((carpeta/'fixture-privada.json').read_text(encoding='utf-8'))
    if fixture.get('url') != 'http://127.0.0.1:8087/' or not re.fullmatch(r'multipro_qa_pantallas_\d{14}', fixture.get('base', '')):
        raise RuntimeError('Solo se admite la fixture QA aislada.')
    cliente = build_opener(HTTPCookieProcessor(CookieJar()))

    def solicitar(ruta, datos=None):
        cuerpo = None if datos is None else urlencode(datos).encode('utf-8')
        req = Request(fixture['url']+ruta, data=cuerpo)
        with cliente.open(req, timeout=15) as respuesta:
            texto = respuesta.read().decode('utf-8')
        if 'Fatal error' in texto or 'Warning:' in texto:
            raise RuntimeError('La página devolvió un error PHP.')
        return texto

    html = solicitar('login.php', {'action': 'login', 'email': fixture['cuentas']['admin']['email'], 'contrasena': fixture['clave']})
    if 'QA Administrador' not in html:
        raise RuntimeError('No se autenticó el administrador ficticio.')
    ruta = 'abm/reservas.php?'+urlencode({'fecha': fixture['fecha']})
    html = solicitar(ruta)
    if '--verificar-cierre' in sys.argv[2:]:
        if 'La administración de la entidad está cerrada.' not in html or 'Devolución total registrada' not in html:
            raise RuntimeError('No se conserva la consulta histórica tras el cierre.')
        if 'Registrar devolución realizada' in html or 'Registrar cobro presencial' in html:
            raise RuntimeError('Quedan acciones económicas visibles en el historial cerrado.')
        try:
            solicitar(ruta, {'action':'registrar_devolucion','reserva_id':str(fixture['reservas'][0]),'csrf_reservas':'QA-prueba-de-autorizacion'})
        except HTTPError as error:
            cuerpo = error.read().decode('utf-8')
            if error.code != 403 or 'Podés consultar el historial' not in cuerpo:
                raise RuntimeError('El acceso no fue rechazado por el cierre administrativo.')
        else:
            raise RuntimeError('La administración cerrada aceptó una solicitud de escritura.')
        resultado = {'base':fixture['base'],'historial':'visible con entidad cerrada','mutaciones':'rechazadas HTTP 403 por autorización de cierre','dinero_real':False}
        (carpeta/'resultado-cierre-http.json').write_text(json.dumps(resultado,ensure_ascii=False,indent=2)+'\n',encoding='utf-8')
        print('QA_CIERRE_HTTP_OK historial_conservado escrituras_rechazadas_403')
        return
    parser = Formularios()
    parser.feed(html)
    reserva = str(fixture['reservas'][0])
    formulario = next((f for f in parser.formularios if f.get('action') == 'registrar_devolucion' and f.get('reserva_id') == reserva), None)
    if formulario is None:
        if 'Devolución total registrada' in html:
            print('QA_DEVOLUCION_YA_REGISTRADA no_se_repite')
            return
        raise RuntimeError('No se encontró el formulario esperado de devolución.')
    if not re.fullmatch(r'[a-f0-9]{64}', formulario.get('csrf_reservas', '')):
        raise RuntimeError('No se obtuvo protección CSRF válida.')
    incorrecto = dict(formulario, csrf_reservas='token-qa-invalido')
    html_invalido = solicitar(ruta, incorrecto)
    if 'Recargá la página antes de continuar.' not in html_invalido:
        raise RuntimeError('No se rechazó el token inválido.')
    html = solicitar(ruta, formulario)
    if 'Devolución total registrada.' not in html:
        raise RuntimeError('El formulario no confirmó la devolución ficticia.')
    html = solicitar(ruta)
    if 'Devolución total registrada' not in html or 'Registrar devolución realizada' in html:
        raise RuntimeError('No quedó visible el estado final esperado.')
    resultado = {'base': fixture['base'], 'reserva': int(reserva), 'canal': 'formulario HTTP autenticado con CSRF', 'token_invalido': 'rechazado', 'resultado': 'devolución ficticia registrada con entidad inactiva', 'limite': 'No se automatizó el confirm() nativo del navegador.'}
    (carpeta/'resultado-devolucion-http.json').write_text(json.dumps(resultado, ensure_ascii=False, indent=2)+'\n', encoding='utf-8')
    print('QA_DEVOLUCION_HTTP_OK entidad_inactiva CSRF_validado sin_dinero_real')


if __name__ == '__main__':
    main()
