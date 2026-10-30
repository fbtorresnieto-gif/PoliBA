from pathlib import Path
from PIL import Image, ImageDraw, ImageFont
from docx import Document
from docx.enum.section import WD_ORIENT
from docx.enum.text import WD_ALIGN_PARAGRAPH
from docx.enum.table import WD_TABLE_ALIGNMENT, WD_CELL_VERTICAL_ALIGNMENT
from docx.oxml import OxmlElement
from docx.oxml.ns import qn
from docx.shared import Inches, Pt, RGBColor

ROOT = Path(__file__).resolve().parents[1]
OUT = ROOT / "diagramas" / "Modelo_de_Datos_y_DER_MultiPro_para_Tesis.docx"
ASSETS = ROOT / "diagramas" / "tesis_assets"
ASSETS.mkdir(parents=True, exist_ok=True)

NAVY = "183153"
BLUE = "2E5D87"
LIGHT_BLUE = "DCEAF5"
PALE = "F3F6F9"
GRAY = "D9D9D9"
TEXT = "1F2933"
GREEN = "DDEFE3"
AMBER = "F8E8C8"

try:
    FONT = ImageFont.truetype("arial.ttf", 29)
    FONT_B = ImageFont.truetype("arialbd.ttf", 32)
    FONT_S = ImageFont.truetype("arial.ttf", 23)
except OSError:
    FONT = ImageFont.load_default()
    FONT_B = ImageFont.load_default()
    FONT_S = ImageFont.load_default()


def set_font(run, name="Aptos", size=11, bold=False, color=TEXT):
    run.font.name = name
    run._element.get_or_add_rPr().rFonts.set(qn("w:ascii"), name)
    run._element.get_or_add_rPr().rFonts.set(qn("w:hAnsi"), name)
    run.font.size = Pt(size)
    run.bold = bold
    run.font.color.rgb = RGBColor.from_string(color)


def shade(cell, fill):
    tcPr = cell._tc.get_or_add_tcPr()
    shd = tcPr.find(qn("w:shd"))
    if shd is None:
        shd = OxmlElement("w:shd")
        tcPr.append(shd)
    shd.set(qn("w:fill"), fill)


def borders(table, color=GRAY, size="6"):
    tblPr = table._tbl.tblPr
    node = tblPr.find(qn("w:tblBorders"))
    if node is None:
        node = OxmlElement("w:tblBorders")
        tblPr.append(node)
    for edge in ("top", "left", "bottom", "right", "insideH", "insideV"):
        el = OxmlElement(f"w:{edge}")
        el.set(qn("w:val"), "single")
        el.set(qn("w:sz"), size)
        el.set(qn("w:color"), color)
        node.append(el)


def set_cell_margins(cell, top=110, start=120, bottom=110, end=120):
    tc = cell._tc
    tcPr = tc.get_or_add_tcPr()
    tcMar = tcPr.first_child_found_in("w:tcMar")
    if tcMar is None:
        tcMar = OxmlElement("w:tcMar")
        tcPr.append(tcMar)
    for m, v in (("top", top), ("start", start), ("bottom", bottom), ("end", end)):
        node = tcMar.find(qn(f"w:{m}"))
        if node is None:
            node = OxmlElement(f"w:{m}")
            tcMar.append(node)
        node.set(qn("w:w"), str(v))
        node.set(qn("w:type"), "dxa")


def repeat_header(row):
    trPr = row._tr.get_or_add_trPr()
    tblHeader = OxmlElement("w:tblHeader")
    tblHeader.set(qn("w:val"), "true")
    trPr.append(tblHeader)


def keep_row_together(row):
    trPr = row._tr.get_or_add_trPr()
    cant_split = OxmlElement("w:cantSplit")
    trPr.append(cant_split)


def add_table(doc, headers, rows, widths=None, font_size=9):
    table = doc.add_table(rows=1, cols=len(headers))
    table.alignment = WD_TABLE_ALIGNMENT.CENTER
    table.autofit = False
    borders(table)
    for i, h in enumerate(headers):
        c = table.rows[0].cells[i]
        shade(c, NAVY)
        c.vertical_alignment = WD_CELL_VERTICAL_ALIGNMENT.CENTER
        set_cell_margins(c)
        p = c.paragraphs[0]
        p.alignment = WD_ALIGN_PARAGRAPH.CENTER
        r = p.add_run(h)
        set_font(r, size=9, bold=True, color="FFFFFF")
    repeat_header(table.rows[0])
    for ridx, row in enumerate(rows):
        added_row = table.add_row()
        keep_row_together(added_row)
        cells = added_row.cells
        for i, value in enumerate(row):
            c = cells[i]
            if ridx % 2:
                shade(c, PALE)
            c.vertical_alignment = WD_CELL_VERTICAL_ALIGNMENT.CENTER
            set_cell_margins(c)
            p = c.paragraphs[0]
            p.paragraph_format.space_after = Pt(0)
            p.alignment = WD_ALIGN_PARAGRAPH.LEFT if i else WD_ALIGN_PARAGRAPH.CENTER
            r = p.add_run(str(value))
            set_font(r, size=font_size)
    if widths:
        for row in table.rows:
            for i, width in enumerate(widths):
                row.cells[i].width = Inches(width)
    doc.add_paragraph().paragraph_format.space_after = Pt(0)
    return table


def add_heading(doc, text, level=1):
    p = doc.add_heading(text, level=level)
    p.paragraph_format.keep_with_next = True
    p.paragraph_format.space_before = Pt(14 if level == 1 else 10)
    p.paragraph_format.space_after = Pt(6)
    for r in p.runs:
        set_font(r, size={1: 16, 2: 13, 3: 11}[level], bold=True, color="000000")
    return p


def add_para(doc, text, bold_lead=None):
    p = doc.add_paragraph()
    p.paragraph_format.space_after = Pt(7)
    p.paragraph_format.line_spacing = 1.12
    if bold_lead and text.startswith(bold_lead):
        r = p.add_run(bold_lead)
        set_font(r, bold=True)
        r2 = p.add_run(text[len(bold_lead):])
        set_font(r2)
    else:
        r = p.add_run(text)
        set_font(r)
    return p


def add_bullets(doc, items):
    for item in items:
        p = doc.add_paragraph(style="List Bullet")
        p.paragraph_format.space_after = Pt(3)
        for r in p.runs:
            set_font(r)
        if not p.runs:
            set_font(p.add_run(item))
        else:
            p.runs[0].text = item


def arrow(draw, a, b, color="#597590", width=4):
    draw.line([a, b], fill=color, width=width)
    import math
    ang = math.atan2(b[1]-a[1], b[0]-a[0])
    length = 15
    for delta in (2.55, -2.55):
        q = (b[0] + length*math.cos(ang+delta), b[1] + length*math.sin(ang+delta))
        draw.line([b, q], fill=color, width=width)


def draw_entity(draw, box, title, fields, fill="#F7FAFC", header="#183153"):
    x, y, w, h = box
    draw.rounded_rectangle([x, y, x+w, y+h], radius=16, fill=fill, outline="#9FB3C8", width=3)
    draw.rounded_rectangle([x, y, x+w, y+52], radius=16, fill=header)
    draw.rectangle([x, y+35, x+w, y+52], fill=header)
    draw.text((x+16, y+10), title, font=FONT_B, fill="white")
    ty = y+65
    for field in fields:
        draw.text((x+16, ty), field, font=FONT_S, fill="#253849")
        ty += 33


def make_diagram(path, entities, edges, size=(3000, 1900), title=None):
    img = Image.new("RGB", size, "white")
    draw = ImageDraw.Draw(img)
    if title:
        draw.text((70, 35), title, font=ImageFont.truetype("arialbd.ttf", 42) if Path("C:/Windows/Fonts/arialbd.ttf").exists() else FONT_B, fill="#183153")
    for src, dst, label in edges:
        s = entities[src][0]
        d = entities[dst][0]
        a = (s[0]+s[2]//2, s[1]+s[3]//2)
        b = (d[0]+d[2]//2, d[1]+d[3]//2)
        arrow(draw, a, b)
        mx, my = (a[0]+b[0])//2, (a[1]+b[1])//2
        if label:
            bbox = draw.textbbox((mx, my), label, font=FONT_S)
            draw.rectangle([bbox[0]-5,bbox[1]-3,bbox[2]+5,bbox[3]+3], fill="white")
            draw.text((mx, my), label, font=FONT_S, fill="#365A75")
    for name, (box, fields, fill, header) in entities.items():
        draw_entity(draw, box, name, fields, fill, header)
    img.save(path, dpi=(220,220))


# Diagrama 1: mapa integral por dominios.
overview = {
    "Identidad y acceso": ((160,210,500,190), ["roles · usuarios · menores", "api_sesiones", "password_resets", "password_reset_solicitudes"], "#EAF2F8", "#244B68"),
    "Organización": ((920,120,500,190), ["entidades · días", "novedades", "espacios"], "#E9F4EC", "#2D6541"),
    "Oferta educativa": ((1670,210,500,210), ["actividades · categorías", "subcategorías · módulos", "días_módulos", "inscripción · asistencia"], "#FFF4DE", "#8A5B18"),
    "Reservas": ((920,650,500,210), ["reservas", "precio y modalidad", "cancelación auditada", "movimientos presenciales"], "#F2EAF8", "#65407A"),
    "Pagos online": ((160,1100,500,230), ["pago_conexiones", "pago_transacciones", "pago_webhook_eventos", "pago_reembolsos"], "#FCEBEC", "#8B3E45"),
    "Comunicaciones": ((1670,1100,500,210), ["notificaciones_email", "inscripcion_promociones", "confirmaciones", "recordatorios"], "#E7F4F7", "#28687A"),
}
overview_edges = [
    ("Identidad y acceso","Organización","pertenece"),
    ("Organización","Oferta educativa","ofrece"),
    ("Identidad y acceso","Oferta educativa","participa"),
    ("Organización","Reservas","dispone"),
    ("Identidad y acceso","Reservas","realiza"),
    ("Reservas","Pagos online","origina"),
    ("Reservas","Comunicaciones","notifica"),
    ("Oferta educativa","Comunicaciones","audita"),
]
make_diagram(ASSETS/"der_general.png", overview, overview_edges, (2400,1550), "DER general de MultiPro por dominios")

# Diagrama 2: oferta e inscripciones.
edu = {
    "roles": ((80,80,370,160), ["PK id", "nombre"], "#EAF2F8", "#244B68"),
    "usuarios": ((570,70,420,250), ["PK id", "FK rol", "FK entidad", "email · estado"], "#EAF2F8", "#244B68"),
    "menores": ((1080,70,420,200), ["PK id", "FK usuario responsable", "identidad · relación"], "#EAF2F8", "#244B68"),
    "entidades": ((80,470,420,230), ["PK id", "FK día apertura/cierre", "datos institucionales", "estado"], "#E9F4EC", "#2D6541"),
    "actividades": ((620,470,400,190), ["PK id", "FK entidad", "nombre · estado"], "#FFF4DE", "#8A5B18"),
    "subcategorias": ((1130,430,430,240), ["PK id", "FK actividad", "FK categoría", "FK entidad", "rango de edad"], "#FFF4DE", "#8A5B18"),
    "modulos": ((1670,430,440,280), ["PK id", "FK profesor", "FK actividad/espacio", "FK categoría/subcategoría", "FK entidad"], "#FFF4DE", "#8A5B18"),
    "dias_modulos": ((2240,470,410,180), ["PK id", "FK módulo", "FK día"], "#FFF4DE", "#8A5B18"),
    "inscripcion": ((670,900,420,240), ["PK id", "FK módulo", "FK usuario o menor", "lista de espera · estado"], "#F2EAF8", "#65407A"),
    "asistencia": ((1300,930,400,210), ["PK id", "FK inscripción", "FK módulo", "fecha · resultado"], "#F2EAF8", "#65407A"),
    "inscripcion_promociones": ((1920,900,520,250), ["PK id", "FK inscripción", "FK módulo anterior/nuevo", "FK actor", "registro inmutable"], "#E7F4F7", "#28687A"),
}
edu_edges = [
    ("roles","usuarios","1:N"),("entidades","usuarios","1:N"),("usuarios","menores","1:N"),
    ("entidades","actividades","1:N"),("actividades","subcategorias","1:N"),
    ("actividades","modulos","1:N"),("usuarios","modulos","profesor"),
    ("modulos","dias_modulos","1:N"),("modulos","inscripcion","1:N"),
    ("usuarios","inscripcion","0..1"),("menores","inscripcion","0..1"),
    ("inscripcion","asistencia","1:N"),("inscripcion","inscripcion_promociones","1:N"),
    ("modulos","inscripcion_promociones","origen/destino"),
]
make_diagram(ASSETS/"der_oferta.png", edu, edu_edges, (2750,1300), "DER de oferta educativa e inscripciones")

# Diagrama 3: reservas, pagos y comunicaciones.
ops = {
    "usuarios": ((80,90,390,210), ["PK id", "FK entidad/rol", "email · estado"], "#EAF2F8", "#244B68"),
    "entidades": ((600,70,400,200), ["PK id", "institución gestora", "estado"], "#E9F4EC", "#2D6541"),
    "espacios": ((1120,70,440,250), ["PK id", "FK entidad", "precio_hora_ars", "online/presencial", "estado"], "#E9F4EC", "#2D6541"),
    "reservas": ((1700,60,470,330), ["PK id", "FK espacio/usuario", "fecha · hora · 60 min", "importe ARS congelado", "modalidad · estado", "auditoría cancelación"], "#F2EAF8", "#65407A"),
    "movimientos_presenciales": ((2280,70,560,280), ["PK id", "FK reserva/entidad/actor", "cobro · solicitud · devolución", "importe total", "registro inmutable"], "#F2EAF8", "#65407A"),
    "pago_conexiones": ((100,650,460,250), ["PK id", "FK entidad", "proveedor · ambiente", "referencia credencial", "estado"], "#FCEBEC", "#8B3E45"),
    "pago_transacciones": ((690,610,520,330), ["PK id", "FK reserva/conexión/entidad", "referencia e idempotencia", "importe · moneda · estado", "confirmo_reserva"], "#FCEBEC", "#8B3E45"),
    "pago_webhook_eventos": ((1360,620,540,300), ["PK id", "FK conexión/pago", "evento proveedor único", "firma validada", "estado · reintentos · lease"], "#FCEBEC", "#8B3E45"),
    "pago_reembolsos": ((2060,620,520,300), ["PK id", "FK pago/conexión/actor", "idempotencia", "importe total · motivo", "estado · reintentos"], "#FCEBEC", "#8B3E45"),
    "notificaciones_email": ((1550,1100,570,310), ["PK id", "FK usuario/reserva", "clave_evento única", "tipo · datos JSONB", "estado · reintentos · lease", "proveedor_message_id"], "#E7F4F7", "#28687A"),
}
ops_edges = [
    ("entidades","espacios","1:N"),("usuarios","reservas","1:N"),("espacios","reservas","1:N"),
    ("reservas","movimientos_presenciales","1:N"),("usuarios","movimientos_presenciales","actor"),
    ("entidades","pago_conexiones","1:N"),("pago_conexiones","pago_transacciones","1:N"),
    ("reservas","pago_transacciones","1:N"),("pago_conexiones","pago_webhook_eventos","1:N"),
    ("pago_transacciones","pago_webhook_eventos","0..N"),("pago_transacciones","pago_reembolsos","0..1"),
    ("usuarios","notificaciones_email","1:N"),("reservas","notificaciones_email","0..N"),
]
make_diagram(ASSETS/"der_operaciones.png", ops, ops_edges, (2940,1530), "DER de reservas pagos y notificaciones")


doc = Document()
sec = doc.sections[0]
sec.page_width = Inches(8.5)
sec.page_height = Inches(11)
sec.top_margin = Inches(0.75)
sec.bottom_margin = Inches(0.7)
sec.left_margin = Inches(0.85)
sec.right_margin = Inches(0.85)

styles = doc.styles
for style_name in ("Normal", "Title", "Subtitle", "Heading 1", "Heading 2", "Heading 3"):
    style = styles[style_name]
    style.font.name = "Aptos"
    style._element.get_or_add_rPr().rFonts.set(qn("w:ascii"), "Aptos")
    style._element.get_or_add_rPr().rFonts.set(qn("w:hAnsi"), "Aptos")
    style.font.color.rgb = RGBColor(0,0,0)
styles["Normal"].font.size = Pt(11)

# Portada.
p = doc.add_paragraph()
p.alignment = WD_ALIGN_PARAGRAPH.CENTER
p.paragraph_format.space_before = Pt(95)
r = p.add_run("MultiPro")
set_font(r, size=19, bold=True, color=BLUE)
p = doc.add_paragraph(style="Title")
p.alignment = WD_ALIGN_PARAGRAPH.CENTER
p.paragraph_format.space_before = Pt(18)
p.paragraph_format.space_after = Pt(14)
r = p.add_run("Modelo de datos y diagrama entidad relación")
set_font(r, size=25, bold=True, color="000000")
p = doc.add_paragraph()
p.alignment = WD_ALIGN_PARAGRAPH.CENTER
r = p.add_run("Documento funcional para incorporación a tesis")
set_font(r, size=14, color="444444")
p = doc.add_paragraph()
p.alignment = WD_ALIGN_PARAGRAPH.CENTER
p.paragraph_format.space_before = Pt(110)
r = p.add_run("Versión 1.0\nOctubre de 2026")
set_font(r, size=11, color="555555")
doc.add_page_break()

add_heading(doc, "Resumen ejecutivo", 1)
add_para(doc, "El modelo de datos de MultiPro articula la gestión institucional, la oferta de actividades, las inscripciones, las reservas de espacios, los cobros presenciales, la preparación de pagos online, la recuperación de contraseña y las comunicaciones transaccionales. La reserva es el eje operativo: relaciona a una persona con un espacio, una fecha y un horario, conserva el importe aplicable al momento de la operación y permite auditar la confirmación, la cancelación y, cuando corresponde, la devolución.")
add_para(doc, "La estructura usa PostgreSQL y aplica integridad referencial, restricciones de dominio, índices únicos parciales, claves de idempotencia y registros inmutables. Estas decisiones evitan doble ocupación, inconsistencias entre reserva y pago, reenvíos duplicados y pérdida de trazabilidad. El esquema de pagos online está incorporado, pero la integración real con Mercado Pago continúa bloqueada hasta completar OAuth, validación de firmas y pruebas externas.")

add_heading(doc, "Alcance del documento", 2)
add_bullets(doc, [
    "Describir el modelo lógico vigente después de las migraciones 001 a 007.",
    "Explicar las entidades y relaciones con lenguaje funcional, sin depender de conocimientos de SQL.",
    "Documentar las reglas de integridad que sostienen reservas, cobros, reembolsos y notificaciones.",
    "Diferenciar las funciones operativas habilitadas de las capacidades preparadas pero aún no activadas.",
])

add_heading(doc, "Criterio de lectura", 2)
add_para(doc, "En los diagramas, PK identifica una clave primaria y FK una clave foránea. La cardinalidad 1:N indica que una fila de la entidad de origen puede relacionarse con varias filas de la entidad de destino. Las relaciones opcionales se representan como 0..1 o 0..N.")

add_heading(doc, "1 Visión funcional del sistema", 1)
add_para(doc, "MultiPro es una plataforma multi entidad. Cada institución administra su información, espacios, actividades y módulos, mientras que los usuarios acceden según su rol. El modelo separa la configuración institucional de los hechos operativos para conservar el historial incluso cuando cambian precios, responsables o configuraciones.")
add_table(doc, ["Dominio", "Finalidad", "Entidades principales"], [
    ("Identidad y acceso", "Representar personas, roles, menores a cargo, sesiones y recuperación de contraseña.", "roles, usuarios, menores, api_sesiones, password_resets, password_reset_solicitudes"),
    ("Organización y oferta", "Describir instituciones, días, novedades, espacios y propuesta educativa.", "entidades, dias, novedades, espacios, actividades, categoria, subcategorias, modulos, dias_modulos"),
    ("Participación", "Registrar inscripciones, lista de espera, asistencia y promoción entre módulos.", "inscripcion, asistencia, inscripcion_promociones"),
    ("Reservas y cobros", "Gestionar turnos, precio congelado, cobro presencial y futura conciliación online.", "reservas, reserva_movimientos_presenciales, pago_conexiones, pago_transacciones, pago_webhook_eventos, pago_reembolsos"),
    ("Comunicaciones", "Encolar y despachar confirmaciones, avisos, recordatorios y recuperaciones.", "notificaciones_email, password_reset_solicitudes"),
], [1.2, 2.7, 3.1], 9)

add_heading(doc, "2 Actores y responsabilidades", 1)
add_table(doc, ["Actor", "Responsabilidad funcional", "Datos relacionados"], [
    ("Alumno", "Consulta oferta, se inscribe, reserva espacios, cancela dentro de las reglas y recibe notificaciones.", "usuarios, inscripcion, reservas, notificaciones_email"),
    ("Responsable de menor", "Mantiene menores asociados y puede inscribirlos en módulos compatibles.", "usuarios, menores, inscripcion"),
    ("Profesor", "Gestiona asistencia y puede promover una inscripción a otro módulo autorizado.", "usuarios, modulos, asistencia, inscripcion_promociones"),
    ("Administrador de entidad", "Gestiona la oferta y los espacios; registra cobros y devoluciones presenciales.", "entidades, espacios, actividades, modulos, reserva_movimientos_presenciales"),
    ("Sistema", "Aplica vencimientos, idempotencia, reintentos y despacho programado de notificaciones.", "reservas, pago_webhook_eventos, pago_reembolsos, notificaciones_email"),
    ("Proveedor de pagos", "Informa el resultado de cobros y devoluciones online mediante eventos autenticados.", "pago_conexiones, pago_transacciones, pago_webhook_eventos, pago_reembolsos"),
], [1.25, 3.35, 2.4], 9)

add_heading(doc, "3 Diagrama entidad relación general", 1)
add_para(doc, "El mapa general presenta los cinco dominios y las relaciones que explican el funcionamiento transversal de MultiPro. Las ampliaciones siguientes muestran las claves y cardinalidades relevantes.")
p = doc.add_paragraph()
p.alignment = WD_ALIGN_PARAGRAPH.CENTER
p.add_run().add_picture(str(ASSETS/"der_general.png"), width=Inches(6.75))
p.paragraph_format.keep_with_next = True
cap = doc.add_paragraph("Figura 1. DER general de MultiPro por dominios")
cap.alignment = WD_ALIGN_PARAGRAPH.CENTER
for r in cap.runs: set_font(r, size=9, color="555555")

# Landscape section for detailed figures.
land = doc.add_section(start_type=1)
land.orientation = WD_ORIENT.LANDSCAPE
land.page_width = Inches(11)
land.page_height = Inches(8.5)
land.top_margin = Inches(0.55)
land.bottom_margin = Inches(0.55)
land.left_margin = Inches(0.55)
land.right_margin = Inches(0.55)
add_heading(doc, "4 DER de oferta educativa e inscripciones", 1)
add_para(doc, "La oferta se organiza por entidad y actividad. Los módulos asignan profesor, espacio, horario, capacidad y segmentación por edad. Cada inscripción representa a un usuario adulto o a un menor, nunca a ambos simultáneamente.")
p = doc.add_paragraph(); p.alignment = WD_ALIGN_PARAGRAPH.CENTER
p.add_run().add_picture(str(ASSETS/"der_oferta.png"), width=Inches(9.7))
cap = doc.add_paragraph("Figura 2. Relaciones entre organización, oferta educativa e inscripciones")
cap.alignment = WD_ALIGN_PARAGRAPH.CENTER
for r in cap.runs: set_font(r, size=9, color="555555")

doc.add_page_break()
add_heading(doc, "5 DER de reservas pagos y notificaciones", 1)
add_para(doc, "La reserva concentra el compromiso de uso del espacio. El modelo presencial registra movimientos inmutables. El modelo online separa conexión, transacción, webhook y reembolso para permitir correlación, reintentos e idempotencia sin almacenar credenciales privadas.")
p = doc.add_paragraph(); p.alignment = WD_ALIGN_PARAGRAPH.CENTER
p.add_run().add_picture(str(ASSETS/"der_operaciones.png"), width=Inches(9.8))
cap = doc.add_paragraph("Figura 3. Relaciones entre reservas, pagos y comunicaciones")
cap.alignment = WD_ALIGN_PARAGRAPH.CENTER
for r in cap.runs: set_font(r, size=9, color="555555")

# Back to portrait.
port = doc.add_section(start_type=1)
port.orientation = WD_ORIENT.PORTRAIT
port.page_width = Inches(8.5)
port.page_height = Inches(11)
port.top_margin = Inches(0.75)
port.bottom_margin = Inches(0.7)
port.left_margin = Inches(0.75)
port.right_margin = Inches(0.75)

add_heading(doc, "6 Diccionario funcional de entidades", 1)
entities_rows = [
    ("roles", "Clasifica permisos y responsabilidades de los usuarios.", "id", "usuarios.fk_rol"),
    ("dias", "Normaliza los días usados por entidades y módulos.", "id", "entidades, dias_modulos"),
    ("entidades", "Representa cada institución participante de MultiPro.", "id", "usuarios, oferta, espacios, pagos"),
    ("usuarios", "Contiene identidad, credenciales, rol y entidad asociada.", "id", "roles, entidades"),
    ("menores", "Registra menores vinculados a un usuario responsable.", "id", "usuarios"),
    ("novedades", "Publica comunicaciones institucionales con vigencia.", "id", "entidades"),
    ("actividades", "Define disciplinas o propuestas de una entidad.", "id", "entidades"),
    ("espacios", "Describe recursos reservables y su configuración de cobro.", "id", "entidades"),
    ("categoria", "Define rangos generales de edad.", "id", "subcategorias, modulos"),
    ("subcategorias", "Especializa rangos de edad por actividad y entidad.", "id", "actividad, categoría, entidad"),
    ("modulos", "Representa una oferta concreta con horario, cupo y profesor.", "id", "actividad, espacio, profesor, entidad"),
    ("dias_modulos", "Resuelve la relación muchos a muchos entre módulos y días.", "id", "modulos, dias"),
    ("inscripcion", "Vincula un módulo con un adulto o un menor.", "id", "modulo, usuario o menor"),
    ("asistencia", "Registra el resultado diario de cada inscripción.", "id", "inscripcion, modulo"),
    ("api_sesiones", "Mantiene sesiones móviles por hash y permite revocación.", "id", "usuarios"),
    ("password_resets", "Guarda tokens de recuperación únicamente como hash y con vencimiento.", "id", "usuarios"),
    ("password_reset_solicitudes", "Cola solicitudes de recuperación sin revelar si una cuenta existe.", "id", "email normalizado y hash"),
    ("reservas", "Registra el turno, importe congelado, modalidad y auditoría de cancelación.", "id", "espacio, usuario, actor cancelación"),
    ("pago_conexiones", "Configura el proveedor de una entidad mediante referencia opaca a credenciales.", "id", "entidades"),
    ("pago_transacciones", "Representa intentos y resultados de cobro online.", "id", "reserva, entidad, conexión"),
    ("pago_webhook_eventos", "Deduplica y procesa eventos autenticados del proveedor.", "id", "conexión, transacción"),
    ("pago_reembolsos", "Registra una devolución total e idempotente por cobro.", "id", "transacción, conexión, actor"),
    ("reserva_movimientos_presenciales", "Audita cobros y devoluciones efectuados fuera de la plataforma.", "id", "reserva, entidad, actor"),
    ("inscripcion_promociones", "Audita el cambio de módulo de una inscripción.", "id", "inscripción, módulos, actor"),
    ("notificaciones_email", "Bandeja transaccional con idempotencia, reintentos y trazabilidad de envío.", "id", "usuario y reserva opcional"),
]
add_table(doc, ["Entidad", "Responsabilidad", "PK", "Relaciones principales"], entities_rows, [1.55, 3.15, 0.55, 2.0], 8.4)

add_heading(doc, "7 Flujos funcionales principales", 1)
add_heading(doc, "7.1 Gestión de identidad y acceso", 2)
add_para(doc, "El usuario se identifica por un registro único y se vincula con un rol. Las sesiones de API almacenan solamente el hash del token, su vencimiento y una eventual revocación. Al cambiar la contraseña se consumen los enlaces activos y se revocan las sesiones móviles vigentes.")

add_heading(doc, "7.2 Inscripción y asistencia", 2)
add_para(doc, "Una inscripción apunta a un módulo y representa de manera excluyente a un usuario adulto o a un menor. La lista de espera se conserva como atributo de la inscripción. La asistencia se registra por fecha y no puede duplicarse para la misma inscripción en el mismo día.")

add_heading(doc, "7.3 Reserva gratuita", 2)
add_para(doc, "Cuando el precio vigente del espacio es cero, la reserva se crea con modalidad gratuita, importe cero y estado reservado. La disponibilidad se valida nuevamente al confirmar para evitar carreras. La misma transacción encola la confirmación y el recordatorio del turno, de modo que la reserva no depende del éxito inmediato del correo.")

add_heading(doc, "7.4 Reserva con pago presencial", 2)
add_para(doc, "Si el espacio tiene precio y habilita pago presencial, la reserva congela el importe y queda pendiente presencial. Un administrador de la entidad registra el cobro, lo que confirma el turno. La cancelación del usuario con al menos 24 horas genera una devolución total pendiente; una cancelación administrativa de una reserva ya cobrada también genera devolución total pendiente sin importar el plazo. El registro de devolución acredita que la entidad la realizó efectivamente.")

add_heading(doc, "7.5 Pago online preparado", 2)
add_para(doc, "La reserva online retiene el turno hasta 15 minutos. Una transacción vincula la reserva con la cuenta del proveedor y conserva importe, moneda, referencia externa y clave de idempotencia. La confirmación solo puede surgir de un evento autenticado y conciliado; el retorno del navegador no confirma el pago. Los cobros tardíos o duplicados originan un reembolso total sobre una única fila reintentable.")
add_para(doc, "Estado funcional actual: el esquema y el servicio interno están preparados, pero Mercado Pago permanece deshabilitado. No existen conexiones activas ni se habilita checkout productivo hasta completar OAuth, firma real de webhooks y pruebas de punta a punta.", bold_lead="Estado funcional actual:")

add_heading(doc, "7.6 Notificaciones transaccionales", 2)
add_para(doc, "Las notificaciones se registran con una clave de evento única. Un proceso programado toma trabajos pendientes, aplica un bloqueo temporal, revalida la reserva cuando corresponde y envía mediante Brevo. Un fallo de correo no revierte la operación de negocio; queda disponible para reintento y termina como omitido si supera el máximo configurado.")

add_heading(doc, "8 Estados del modelo", 1)
add_table(doc, ["Entidad", "Estado", "Significado funcional"], [
    ("reservas", "pendiente_pago", "Turno retenido mientras se espera acreditación online; vence dentro de 15 minutos."),
    ("reservas", "pendiente_presencial", "Turno ocupado y a la espera del cobro registrado por la entidad."),
    ("reservas", "reservado", "Turno confirmado: gratuito, presencial cobrado u online conciliado."),
    ("reservas", "cancelado", "Turno liberado con origen, motivo, fecha y actor cuando corresponde."),
    ("reservas", "vencido", "Retención online expirada; no se muestra en el historial del usuario."),
    ("pago_transacciones", "creado a contracargo", "Ciclo del cobro desde el intento inicial hasta resultado, devolución o contracargo."),
    ("pago_webhook_eventos", "recibido a error", "Ciclo de procesamiento idempotente con arrendamiento y reintentos."),
    ("pago_reembolsos", "solicitado a aprobado", "Ciclo de devolución total y conciliación con proveedor."),
    ("notificaciones_email", "pendiente a enviada", "Ciclo de cola, procesamiento, reintento, envío u omisión."),
], [1.45, 1.5, 4.2], 9)

add_heading(doc, "9 Reglas de integridad y trazabilidad", 1)
rules = [
    ("RN 01", "Un turno activo no puede repetirse para el mismo espacio, fecha y hora."),
    ("RN 02", "Un usuario no puede ocupar dos espacios en el mismo día y horario dentro de MultiPro."),
    ("RN 03", "Toda reserva dura 60 minutos y utiliza ARS como moneda del MVP."),
    ("RN 04", "El precio se congela en la reserva; cambios posteriores del espacio no alteran el histórico."),
    ("RN 05", "Una reserva gratuita tiene importe cero; una reserva paga tiene importe mayor que cero."),
    ("RN 06", "La identidad y el importe de una reserva con movimientos de pago son inmutables."),
    ("RN 07", "El webhook se deduplica por conexión y evento del proveedor."),
    ("RN 08", "Solo un cobro puede ser el confirmador histórico de una reserva."),
    ("RN 09", "El reembolso online es total y existe una sola fila por cobro en el MVP."),
    ("RN 10", "Los movimientos presenciales y las promociones registradas son auditoría inmutable."),
    ("RN 11", "La notificación usa una clave de evento única para impedir duplicados."),
    ("RN 12", "Las credenciales privadas no se almacenan en las tablas de negocio ni en el repositorio."),
]
add_table(doc, ["Regla", "Definición"], rules, [0.8, 6.3], 9.2)

add_heading(doc, "10 Decisiones de seguridad", 1)
add_bullets(doc, [
    "Las contraseñas se almacenan mediante hash y los tokens de recuperación o sesión se persisten únicamente como hash.",
    "Las solicitudes de recuperación se procesan en segundo plano para que la latencia no revele si el correo corresponde a una cuenta.",
    "Las credenciales de proveedores se representan por una referencia UUID opaca y deben resolverse fuera de la base de negocio.",
    "Los payloads crudos de webhooks no se conservan; se almacenan identificadores, estado y trazabilidad mínima.",
    "Las operaciones sensibles usan claves de idempotencia, bloqueos y restricciones de unicidad para resistir reintentos y concurrencia.",
    "Los correos se envían desde una bandeja transaccional desacoplada de la transacción principal.",
])

add_heading(doc, "11 Estado de implementación y límites", 1)
add_table(doc, ["Capacidad", "Estado", "Observación"], [
    ("Reservas gratuitas", "Operativa", "Confirmación inmediata, cancelación auditada y correo de confirmación."),
    ("Pago presencial", "Operativo", "Cobro y devolución se registran; el dinero se gestiona fuera de MultiPro."),
    ("Notificaciones por correo", "Operativa", "Brevo por HTTPS y ejecución programada cada 15 minutos."),
    ("Recuperación de contraseña", "Operativa", "Token de uso único, cola asíncrona y revocación de sesiones móviles."),
    ("Pago online", "Preparado no habilitado", "Faltan OAuth real, checkout, validación de firma y conciliación productiva."),
    ("Aplicación Android", "Distribución directa", "La publicación en Google Play no forma parte del alcance; validaciones específicas continúan separadas."),
], [1.55, 1.35, 4.2], 9)

add_heading(doc, "12 Conclusión", 1)
add_para(doc, "El modelo de datos de MultiPro no se limita a almacenar formularios: representa compromisos de disponibilidad, reglas económicas, autorización por entidad y evidencia de los hechos relevantes. La combinación de relaciones, restricciones e idempotencia permite conservar la coherencia cuando existen accesos simultáneos, reintentos del proveedor o fallas temporales de comunicación.")
add_para(doc, "Para el alcance actual, la plataforma mantiene la compatibilidad con las reservas gratuitas e incorpora un circuito presencial trazable. A la vez, deja preparado un dominio de pagos online desacoplado, sin confundir preparación técnica con habilitación operativa. Esta separación reduce el riesgo de activar cobros antes de contar con credenciales, validaciones y pruebas externas suficientes.")

add_heading(doc, "Anexo A Fuentes técnicas del modelo", 1)
add_para(doc, "El documento se construyó a partir del esquema base PostgreSQL, las migraciones versionadas 001 a 007 y la memoria operativa del MVP. Las fuentes principales son:")
add_bullets(doc, [
    "database/schema.sql",
    "database/migrations/20261026_001_reservas_pagos_base.sql",
    "database/migrations/20261026_002_proveedores_pagos.sql",
    "database/migrations/20261026_003_pago_confirmador.sql",
    "database/migrations/20261026_004_presencial.sql",
    "database/migrations/20261028_005_recuperacion_contrasena_segura.sql",
    "database/migrations/20261028_006_notificaciones_email.sql",
    "database/migrations/20261028_007_recordatorios_reservas.sql",
    "MEMORIA_CONTEXTO_PAGOS_MVP.md",
])

# Cada sección tiene su propio pie para evitar duplicaciones al alternar
# páginas verticales y horizontales.
for index, section in enumerate(doc.sections):
    footer = section.footer
    if index:
        footer.is_linked_to_previous = False
    p = footer.paragraphs[0]
    p.clear()
    p.alignment = WD_ALIGN_PARAGRAPH.CENTER
    r = p.add_run("MultiPro  Modelo de datos")
    set_font(r, size=8, color="666666")

doc.core_properties.title = "Modelo de datos y diagrama entidad relación de MultiPro"
doc.core_properties.subject = "Documento funcional para tesis"
doc.core_properties.author = "MultiPro"
doc.save(OUT)
print(OUT)
