"""Ensayo HTTP del ZIP privado recién extraído en XAMPP. Opera solo en una base de prueba."""

import argparse
from datetime import datetime, timedelta, timezone
import html
from http.cookiejar import CookieJar
import json
from pathlib import Path
import re
import secrets
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
    parser.add_argument("--database", help="Base nueva aislada de XAMPP para el ensayo")
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
    if args.database:
        if not re.fullmatch(r"sipe_v2_qa_[a-z0-9_]+", args.database):
            raise SystemExit("Usa una base de prueba nueva sipe_v2_qa_*.")
        check(login[0] == 200 and "Preparar SIPE V2" in text(login),
              "asistente de la extracción limpia con una base activa preexistente")
        login = request(browser, base, {
            "csrf": csrf(login[3]), "host": "127.0.0.1", "port": "3306",
            "database": args.database, "user": "root", "password": "",
        })
    check(login[0] == 200 and "r=login" in login[1], "instalación desde el navegador")
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
    administrative = next((i, person) for i, person in enumerate(source, 1) if person["rol"] == "ADMINISTRATIVO")
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

    other_resource = next(i for i, resource in enumerate(inventory["recursos"], 1)
                          if resource["tipo"] != inventory["recursos"][0]["tipo"])
    blocked = post("confirm", start | {"recurso_id": other_resource, "action": "reserve"})
    check(blocked[0] == 422 and "no está autorizado para esta categoría" in text(blocked),
          "estudiante sigue limitado a su categoría tras autorizar otro recurso")
    profile = text(get("student", id=student_id))
    check(inventory["recursos"][0]["tipo"] in profile, "categoría autorizada visible en hoja de vida")

    external = start | {"estudiante_id": teacher[0], "grupo_trabajo": "DOCENTE", "recurso_id": other_resource,
                        "tipo_prestamo": "EXTERNO", "fin": str(tomorrow) + "T16:30"}
    review = text(post("confirm", external))
    check("RESERVAR" in review and "Solicitar aprobación" not in review and "data-single-submit" in review,
          "docente conserva el paso de revisión con reserva directa")
    check("Reserva confirmada" in text(post("confirm", external | {"action": "reserve"})),
          "docente reserva una categoría distinta sin aprobación")
    conflict = post("confirm", external | {
        "estudiante_id": administrative[0], "grupo_trabajo": "ADMINISTRATIVO", "action": "reserve",
    })
    check(conflict[0] == 422 and "se cruza con una reserva confirmada" in text(conflict),
          "el conflicto se muestra integrado en la pantalla de confirmación")
    admin_request = external | {"estudiante_id": administrative[0], "grupo_trabajo": "ADMINISTRATIVO",
                                "time": "16:30", "fin": str(tomorrow) + "T17:30", "action": "reserve"}
    check("Reserva confirmada" in text(post("confirm", admin_request)),
          "administrativo reserva sin aprobación en horario disponible")
    panel = text(get("requests", estado="PENDIENTE_APROBACION"))
    check("0 registros" in panel and "Aprobar y reservar" not in panel,
          "nuevas reservas sin pendientes ni botón de aprobación")
    check(post("requests", {"csrf": token, "id": 2, "action": "approve"})[0] == 422,
          "servidor rechaza una acción de aprobación obsoleta")
    check(get("deliver", id=2)[0] == 422, "la reserva futura mantiene la restricción de horario de entrega")
    check("Solicitud cancelada" in text(post("requests", {"csrf": token, "id": 3, "action": "cancel"})),
          "cancelación conserva su confirmación y su resultado en página")

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
    staff_loan = immediate | {"estudiante_id": teacher[0], "grupo_trabajo": "DOCENTE",
                              "recurso_id": other_resource}
    check("Entrega física registrada" in text(post("confirm", staff_loan)),
          "entrega directa a docente sin reserva aprobada")

    current_reservation = immediate | {"action": "reserve"}
    check("Reserva confirmada" in text(post("confirm", current_reservation)),
          "reserva del estudiante antes de la sanción con horario de entrega vigente")

    sanctions = get("sanctions")
    check(sanctions[0] == 200 and "Buscar usuario por código institucional" in text(sanctions)
          and 'name="estudiante_id"' not in text(sanctions), "sanciones abre sin listado de personas")
    check(post("sanctions", {"action": "search", "code": person["codigo"]})[0] == 403,
          "búsqueda de sanciones exige CSRF")
    lookup = post("sanctions", {"csrf": token, "action": "search", "code": person["codigo"]})
    lookup_page = text(lookup)
    check(lookup[0] == 200 and person["codigo"] not in lookup[1]
          and person["nombres"] + " " + person["apellidos"] in lookup_page
          and teacher[1]["nombres"] + " " + teacher[1]["apellidos"] not in lookup_page
          and "Rol / grupo de trabajo" in lookup_page and "Estado en SIPE" in lookup_page,
          "búsqueda por código exacto muestra únicamente la ficha encontrada")
    chooser = re.search(r'<select name="prestamo_id".*?</select>', lookup_page, re.S)[0]
    check(re.findall(r'<option value="([0-9]+)"', chooser) == ["1"]
          and all(label in chooser for label in ("Ref.", "Entrega", "Vencimiento", "Devolución", "Devuelto")),
          "selector automático muestra solo el préstamo del usuario con detalle")
    sanction = {"csrf": token, "action": "register", "tipo": "DANIO", "motivo": "Evidencia sintética de daño"}
    check(request(browser, lookup[1], sanction | {"prestamo_id": "2", "estudiante_id": teacher[0]})[0] == 422,
          "servidor rechaza préstamo ajeno y manipulación del prestatario")
    check(request(browser, lookup[1], sanction | {"tipo": "MORA", "prestamo_id": ""})[0] == 422,
          "mora exige préstamo incluso sin validación de navegador")
    check(request(browser, lookup[1], sanction | {"tipo": "MORA", "prestamo_id": "1"})[0] == 422,
          "mora rechaza préstamos con retraso de 24 horas o menos")
    check(request(browser, lookup[1], sanction | {"prestamo_id": "1", "motivo": ""})[0] == 422,
          "sanciones exigen fundamento y evidencia")
    saved = request(browser, lookup[1], sanction | {"prestamo_id": "1"})
    check("Sanción registrada" in text(saved) and "Evidencia sintética de daño" in text(saved)
          and inventory["recursos"][0]["nombre"] in text(saved), "daño guarda sanción y recurso relacionado")
    block = "El usuario tiene una sanción activa y no puede solicitar recursos hasta que sea resuelta"
    badge = "BLOQUEADO POR SISTEMAS"
    check("<td>DAÑO</td>" in text(saved) and " · DAÑO</option>" in text(saved),
          "DAÑO en historial de sanciones y selector de resolución")
    for identity in (person["codigo"], person["qr"]):
        identified = post("borrower", {"csrf": token, "identity": identity})
        page = text(identified)
        check(identified[0] == 200 and f"person={student_id}" in identified[1]
              and badge in page and "Hoja de vida</a>" in page and block in page
              and re.search(r"<button disabled[^>]*>INGRESAR", page) and "<td>DAÑO</td>" in page,
              "código y QR identifican al estudiante bloqueado con INGRESAR deshabilitado")
    profile = text(get("student", id=student_id))
    check("<td>DAÑO</td>" in profile and block in profile and "Reservas vigentes 2" in profile,
          "hoja de vida conserva reservas y muestra DAÑO y la sanción abierta")
    report = text(get("reports", report="sanciones", identity=person["codigo"]))
    check("<td>DAÑO</td>" in report and "<td>DANIO</td>" not in report, "informe de sanciones muestra DAÑO")
    parameters = {k: v for k, v in start.items() if k != "csrf"}
    direct = get("resources", **parameters)
    check(direct[0] == 422 and block in text(direct) and 'id="resource-filters"' not in text(direct),
          "el enlace directo a recursos muestra el aviso sin habilitar la operación")
    for fields in (start, start | {"action": "reserve"}, immediate):
        direct = post("confirm", fields)
        check(direct[0] == 422 and block in text(direct) and 'value="reserve"' not in text(direct),
              "POST directo de revisión, reserva o entrega queda bloqueado")
    alternative = post("inspections", immediate)
    check(alternative[0] == 422 and block in text(alternative),
          "ruta alternativa de registro físico aplica la misma restricción PHP")
    for result in (get("deliver", id=4), post("deliver", {
        "csrf": token, "id": "4", "estado_fisico": "BUENO", "estudiante_id": administrative[0],
    })):
        check(result[0] == 422 and block in text(result) and "Confirmar entrega</button>" not in text(result),
              "la reserva anterior no permite entrega por GET ni POST con identidad sustituida")
    empty = post("sanctions", {"csrf": token, "action": "search", "code": administrative[1]["codigo"]})
    check("Este usuario no tiene préstamos registrados." in text(empty) and 'option value="2"' not in text(empty),
          "usuario sin préstamos ve el aviso y carece de préstamos ajenos")
    optional = request(browser, empty[1], sanction | {"tipo": "OTRA", "prestamo_id": ""})
    check("Sanción registrada" in text(optional), "otra sanción conserva préstamo opcional")
    staff_block = get("resources", **(parameters | {"estudiante_id": administrative[0], "grupo_trabajo": "ADMINISTRATIVO"}))
    check(staff_block[0] == 422 and block in text(staff_block), "sanción OTRA abierta bloquea al administrativo")
    resolved = request(browser, lookup[1], {"csrf": token, "action": "resolve", "id": "1",
                                           "estado": "RESUELTA", "observaciones": "Resolución sintética"})
    check("Resolución registrada" in text(resolved) and "RESUELTA" in text(resolved),
          "historial y resolución de la sanción se conservan")
    unblocked = text(get("borrower", person=student_id))
    check(badge not in unblocked and block not in unblocked and not re.search(r"<button disabled[^>]*>INGRESAR", unblocked)
          and get("resources", **parameters)[0] == 200, "resolver la sanción reanuda INGRESAR y recursos sin cambiar cuenta")
    teacher_lookup = post("sanctions", {"csrf": token, "action": "search", "code": teacher[1]["codigo"]})
    check("Sanción registrada" in text(request(browser, teacher_lookup[1], sanction | {"prestamo_id": "2"})),
          "docente queda sancionado con un préstamo activo")
    teacher_page = text(get("borrower", person=teacher[0]))
    check(badge in teacher_page and post("confirm", external | {"action": "reserve"})[0] == 422,
          "docente sancionado queda bloqueado para nuevas reservas")
    check("Devolución registrada" in text(post("return-loan", {
        "csrf": token, "id": "2", "estado_fisico": "BUENO", "apto": "1",
    }, id=2)), "una sanción abierta permite devolver el préstamo existente")

    not_found = post("sanctions", {"csrf": token, "action": "search", "code": "NO-EXISTE-QA"})
    check("No se encontró un usuario" in text(not_found) and 'name="prestamo_id"' not in text(not_found),
          "código inexistente no carga préstamos ni formulario de sanción")

    employee_email = f"operador-{secrets.token_hex(5)}@example.test"
    employee_password = secrets.token_urlsafe(25)
    check(post("catalog", {"csrf": token, "nombre_completo": "Personal de prueba",
                           "correo": employee_email, "rol_id": 2, "password": employee_password},
               table="usuarios")[0] == 200, "creación de operador sintético de prueba")
    staff = client()
    staff_login = request(staff, base)
    staff_dashboard = request(staff, staff_login[1], {"csrf": csrf(staff_login[3]), "correo": employee_email,
                                                       "password": employee_password})
    check(staff_dashboard[0] == 200 and "r=dashboard" in staff_dashboard[1], "ingreso de operador")
    no_access = request(staff, lookup[1])
    check(no_access[0] == 403 and person["codigo"] not in text(no_access),
          "operador no autorizado no recibe ficha ni préstamos")
    check(request(staff, lookup[1], sanction | {"csrf": csrf(staff_dashboard[3]), "prestamo_id": "1"})[0] == 403,
          "operador con CSRF válido no puede sancionar")
    check("r=login" in request(client(), lookup[1])[1], "sanciones no expone datos a una sesión anónima")

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
