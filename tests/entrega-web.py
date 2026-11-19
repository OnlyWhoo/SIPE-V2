"""Ensayo HTTP del ZIP privado recién extraído en XAMPP. Opera solo en una base de prueba."""

import argparse
from datetime import datetime, timedelta, timezone
import html
from http.cookiejar import CookieJar
import json
from pathlib import Path
import re
from urllib.error import HTTPError
from urllib.parse import urlencode
from urllib.request import HTTPCookieProcessor, Request, build_opener


def client():
    return build_opener(HTTPCookieProcessor(CookieJar()))


def request(browser, url, fields=None, raw=None, content_type=None):
    body = urlencode(fields).encode() if fields is not None else raw
    headers = {"Content-Type": content_type} if content_type else {}
    try:
        response = browser.open(Request(url, body, headers), timeout=25)
    except HTTPError as error:
        response = error
    with response:
        return response.status, response.url, response.headers, response.read()


def check(value, description):
    if not value:
        raise SystemExit(f"FALLO {description}")
    print(f"OK {description}", flush=True)


def csrf(page):
    return re.search(rb'name="csrf" value="([a-f0-9]+)"', page)[1].decode()


def text(response):
    return html.unescape(response[3].decode("utf-8"))


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("base")
    parser.add_argument("private", type=Path)
    parser.add_argument("--solo-arranque", action="store_true")
    args = parser.parse_args()
    base = args.base.rstrip("/") + "/"
    if not re.fullmatch(r"http://127\.0\.0\.1/_sipe-entrega-qa-[a-z0-9]+/SIPE-V2/", base):
        raise SystemExit("Usa una extracción de prueba _sipe-entrega-qa-* de localhost.")
    source = json.loads((args.private / "entrega-privada.json").read_text(encoding="utf-8"))["personas"]
    inventory = json.loads((args.private / "inventario-entrega.json").read_text(encoding="utf-8"))
    credential_lines = (args.private / "acceso-profesor.txt").read_text(encoding="utf-8").splitlines()
    email = credential_lines[1].split(": ", 1)[1]
    password = credential_lines[2].split(": ", 1)[1]
    browser = client()

    def get(route, **params):
        return request(browser, base + "index.php?" + urlencode({"r": route, **params}))

    def post(route, fields, **params):
        return request(browser, base + "index.php?" + urlencode({"r": route, **params}), fields)

    login = request(browser, base)
    check(login[0] == 200 and "r=login" in login[1], "instalación automática en una extracción limpia")
    for path in ("database/entrega-privada.json", "database/arranque-privado.json", "database/inventario-entrega.json"):
        check(request(browser, base + path)[0] == 403, "Apache bloquea " + path)
    for route in ("photo", "qr"):
        check("r=login" in get(route, id=1)[1], "acceso anónimo bloqueado para " + route)
    check(post("login", {"correo": email, "password": password})[0] == 403, "CSRF exige token al iniciar sesión")
    dashboard = post("login", {"csrf": csrf(login[3]), "correo": email, "password": password})
    check(dashboard[0] == 200 and "r=dashboard" in dashboard[1], "inicio de sesión de evaluación")
    token = csrf(dashboard[3])

    student = next((i, person) for i, person in enumerate(source, 1) if person["rol"] == "ESTUDIANTE")
    teacher = next((i, person) for i, person in enumerate(source, 1) if person["rol"] == "DOCENTE")
    student_id, person = student
    check("person=" + str(student_id) in post("borrower", {"csrf": token, "identity": person["codigo"]})[1],
          "identificación por código en Solicitud de Recursos")
    check("person=" + str(teacher[0]) in post("borrower", {"csrf": token, "identity": teacher[1]["qr"]})[1],
          "identificación docente por token QR")
    profile = text(get("student", id=student_id))
    check(all(value in profile for value in (
        person["codigo"], person["cedula"], person["codigo"] + "@fadp.edu.co", person["programa"],
        "31/12/2026", "Fotografía ilustrativa", "+ Solicitar Recurso",
        "Préstamos activos 0", "Reservas vigentes 0", "Devoluciones 0",
    )), "hoja de vida completa")
    check("Asignación provisional" in text(get("student", id=teacher[0])), "programa docente marcado como provisional")
    check(get("photo", id=student_id)[2].get_content_type() == "image/jpeg", "fotografía desde ruta autenticada")
    check(b"<svg" in get("qr", id=student_id)[3], "QR SVG generado localmente")
    check(get("borrower", person=student_id)[0] == 200, "enlace de la ficha mantiene el prestatario")
    for route,values,params in (
        ("catalog", {"action":"search","q":person["codigo"]}, {"table":"estudiantes"}),
        ("requests", {"action":"search","q":person["codigo"]}, {}),
        ("reports", {"report":"historial","identity":person["codigo"]}, {}),
    ):
        result = post(route, values | {"csrf": token}, **params)
        check(result[0] == 200 and person["codigo"] not in result[1] and person["codigo"] in text(result),
              "filtro de "+route+" conserva datos personales fuera de la URL")
    if args.solo_arranque:
        return

    now = datetime.now(timezone(timedelta(hours=-5)))
    tomorrow = now.date() + timedelta(days=1)
    start = {"csrf": token, "day": tomorrow.isoformat(), "time": "14:00", "recurso_id": 1,
             "estudiante_id": student_id, "tipo_prestamo": "INTERNO", "grupo_trabajo": "ESTUDIANTE"}
    check(get("resources", **{key: value for key, value in start.items() if key != "csrf"})[0] == 200,
          "selección y disponibilidad en el flujo existente")
    check(post("confirm", start)[0] == 422, "préstamo estudiantil requiere autorización por categoría")
    program_id = inventory["programas"].index(person["programa"]) + 1
    check(post("authorizations", {"csrf": token, "programa_id": program_id, "categoria_id": 1,
                                 "permitir": "1"})[0] == 200, "autorización desde el panel existente")
    check("20:00" in text(post("confirm", start)), "plazo del préstamo interno en el mismo día")
    check("Reserva confirmada" in text(post("confirm", start | {"action": "reserve"})),
          "reserva interna del estudiante")

    external = start | {"estudiante_id": teacher[0], "grupo_trabajo": "DOCENTE", "recurso_id": 2,
                        "tipo_prestamo": "EXTERNO", "fin": str(tomorrow) + "T16:30"}
    check("Solicitar aprobación" in text(post("confirm", external)), "docente usa el formulario de aprobación existente")
    pending = text(post("confirm", external | {"action": "reserve"}))
    check("PENDIENTE APROBACION" in pending and "Aprobar solicitud" in pending, "solicitud docente queda pendiente")
    check(post("requests", {"csrf": token, "id": 2, "action": "approve", "reason": "Verificación aislada"})[0] == 200,
          "administrador aprueba la solicitud docente")
    check(get("deliver", id=2)[0] == 422, "la reserva futura mantiene la restricción de horario de entrega")

    now = datetime.now(timezone(timedelta(hours=-5)))
    finish = now + timedelta(hours=2)
    immediate = start | {"tipo_prestamo": "EXTERNO", "day": now.strftime("%Y-%m-%d"), "time": now.strftime("%H:%M"),
                         "fin": finish.strftime("%Y-%m-%dT%H:%M"), "action": "lend", "estado_fisico": "BUENO"}
    check(post("confirm", immediate)[0] == 200, "entrega externa inmediata")
    check("Vale de préstamo externo" in text(get("voucher", id=1)), "generación de vale")
    check(post("return-loan", {"csrf": token, "id": 1, "estado_fisico": "BUENO", "apto": "1"}, id=1)[0] == 200,
          "devolución actualiza inventario y movimientos")
    profile = text(get("student", id=student_id))
    check(all(value in profile for value in ("Devoluciones 1", "Reservas vigentes 1", "DEVUELTO", "CONFIRMADA")),
          "historial de préstamos, reservas y devoluciones")
    check(get("reports")[0] == 200 and get("audit")[0] == 200, "reportes y auditoría")
    profile_change = {
        "csrf":token, "id":student_id, "codigo_estudiante":person["codigo"], "cedula":person["cedula"],
        "nombres":person["nombres"], "apellidos":person["apellidos"], "tipo_persona":"ESTUDIANTE",
        "programa_id":program_id, "correo":person["codigo"]+"@fadp.edu.co", "carnet_vigencia":"2027-12-31",
    }
    boundary = "----sipeQaMultipart"
    body = b""
    for key,value in profile_change.items():
        body += f'--{boundary}\r\nContent-Disposition: form-data; name="{key}"\r\n\r\n{value}\r\n'.encode()
    picture = (Path(__file__).resolve().parents[1] / "assets/img/foto-generica.png").read_bytes()
    body += (f'--{boundary}\r\nContent-Disposition: form-data; name="fotografia"; filename="qa.png"\r\n'
             f"Content-Type: image/png\r\n\r\n").encode() + picture + f"\r\n--{boundary}--\r\n".encode()
    updated = request(browser, base+"index.php?r=catalog&table=estudiantes", raw=body,
                      content_type="multipart/form-data; boundary="+boundary)
    check(updated[0] == 200 and get("photo", id=student_id)[3] == picture
          and "31/12/2027" in text(get("student", id=student_id)), "administrador edita vigencia y fotografía desde el catálogo")
    check("r=login" in post("logout", {"csrf": token})[1], "cierre de sesión")
    check("r=login" in request(client(), base)[1], "la instalación existente abre sin volver a sembrar")


if __name__ == "__main__":
    main()
