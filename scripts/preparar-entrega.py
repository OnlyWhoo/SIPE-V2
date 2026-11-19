"""Prepara los datos de la entrega a partir de los dos insumos definitivos."""

import argparse
from collections import Counter
from hashlib import sha256
from io import BytesIO
import json
from pathlib import Path
import re
import secrets
from xml.etree import ElementTree as ET
from zipfile import ZipFile

from PIL import Image, ImageOps


NS = {"s": "http://schemas.openxmlformats.org/spreadsheetml/2006/main"}
ROLES = {"ESTUDIANTE": 84, "DOCENTE": 21, "ADMINISTRATIVO": 18}


def worksheet(book, sheet, last_row):
    strings = [
        "".join(t.text or "" for t in item.iterfind(".//s:t", NS))
        for item in ET.fromstring(book.read("xl/sharedStrings.xml"))
    ]
    rows = ET.fromstring(book.read(f"xl/worksheets/sheet{sheet}.xml"))
    for row in rows.findall("s:sheetData/s:row", NS):
        number = int(row.get("r"))
        if not 6 <= number <= last_row:
            continue
        cells = {}
        for cell in row:
            value = cell.findtext("s:v", namespaces=NS)
            if value is None:
                continue
            col = cell.get("r").rstrip("0123456789")
            kind = cell.get("t")
            cells[col] = (strings[int(value)] if kind == "s" else value, kind)
        yield number, cells


def field(cells, col, default=""):
    return cells.get(col, (default, None))[0]


def identifier(cells, col, number):
    value, kind = cells.get(col, ("", None))
    if kind != "s" or not re.fullmatch(r"[0-9]{1,40}", value):
        raise ValueError(f"Identificador no textual o inválido en fila {number}, columna {col}")
    return value


def split_name(original, review, row):
    entry = review.get(str(row), {})
    boundary = entry.get("fin_apellidos")
    if (entry.get("nombre_original") != original or type(boundary) is not int
            or not 0 < boundary < len(original) - 1 or original[boundary] != " "):
        raise ValueError(f"Revisa la separación de nombres y apellidos de la fila {row}")
    surname, given = original[:boundary], original[boundary + 1:]
    if any(not value or value != value.strip() or len(value) > 100 for value in (surname, given)):
        raise ValueError(f"Nombres o apellidos inválidos en la revisión de la fila {row}")
    return given, surname


def people(book, excluded, review):
    result = []
    for number, cells in worksheet(book, 1, 128):
        code = identifier(cells, "B", number)
        if code == excluded:
            continue
        name = field(cells, "A")
        given, surname = split_name(name, review, number)
        role = field(cells, "D").upper()
        program = field(cells, "E")
        if role not in ROLES:
            raise ValueError(f"Fila de persona incompleta: {number}")
        if (program == "No aplica") != (role == "ADMINISTRATIVO"):
            raise ValueError(f"Programa incompatible con el rol en fila {number}")
        result.append({
            "fila": number,
            "codigo": code,
            "cedula": identifier(cells, "C", number),
            "nombre_original": name,
            "nombres": given,
            "apellidos": surname,
            "rol": role,
            "programa": None if role == "ADMINISTRATIVO" else program,
            "rol_pendiente": field(cells, "I") == "Asignado 50/50",
            "programa_provisional": role == "DOCENTE",
            "nombre_pendiente": "Nombre truncado" in field(cells, "G"),
            "programa_pendiente": field(cells, "F").startswith("No asignada"),
        })
    if Counter(p["rol"] for p in result) != ROLES or sum(p["rol_pendiente"] for p in result) != 21:
        raise ValueError("El total de personas o de roles provisionales difiere del esperado")
    if any(len({p[key] for p in result}) != 123 for key in ("codigo", "cedula")):
        raise ValueError("El Excel contiene códigos o documentos duplicados")
    return result


def inventory(book):
    valid, pending, seen = [], [], set()
    for number, cells in worksheet(book, 2, 166):
        code, kind = cells.get("C", ("", None))
        item = {
            "fila": number, "tipo": field(cells, "A"),
            "nombre": field(cells, "B"), "referencia": code, "observaciones": field(cells, "D"),
        }
        if code.casefold() in seen:
            raise ValueError(f"Referencia duplicada en fila {number}")
        seen.add(code.casefold())
        if kind != "s" or not re.fullmatch(r"[A-Za-z0-9]{1,50}", code):
            pending.append(item)
        else:
            valid.append(item)
    if len(valid) + len(pending) != 161 or len(pending) != 11:
        raise ValueError("El inventario no coincide con las 161 filas y 11 referencias pendientes")
    return valid, pending


def portraits(archive, records):
    import base64

    members = sorted(
        name for name in archive.namelist()
        if name.lower().endswith((".jpg", ".jpeg", ".png", ".webp"))
    )
    hashes = set()
    for slot, person in enumerate(sorted(records, key=lambda p: p["codigo"])):
        person["foto"] = None
        if slot >= len(members) or archive.getinfo(members[slot]).file_size > 5_000_000:
            continue
        try:
            with Image.open(BytesIO(archive.read(members[slot]))) as source:
                image = ImageOps.exif_transpose(source).convert("RGB")
                image.thumbnail((320, 320), Image.Resampling.LANCZOS)
                output = BytesIO()
                image.save(output, "JPEG", quality=82, optimize=True)
        except (OSError, ValueError, Image.DecompressionBombError):
            continue
        photo = output.getvalue()
        digest = sha256(photo).hexdigest()
        if digest not in hashes:
            hashes.add(digest)
            person["foto"] = base64.b64encode(photo).decode("ascii")


def save(path, data):
    path.write_text(json.dumps(data, ensure_ascii=False, separators=(",", ":")) + "\n", encoding="utf-8")


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("excel", type=Path)
    parser.add_argument("fotos", type=Path)
    parser.add_argument("salida_privada", type=Path)
    parser.add_argument("--codigo-excluido", required=True)
    parser.add_argument(
        "--nombres-revisados", type=Path, required=True,
        help="JSON privado por fila de Excel con nombre_original y fin_apellidos "
             "(índice del espacio que separa apellidos y nombres)",
    )
    args = parser.parse_args()
    review = json.loads(args.nombres_revisados.read_text(encoding="utf-8"))
    with ZipFile(args.excel) as book, ZipFile(args.fotos) as archive:
        records = people(book, args.codigo_excluido, review)
        valid, pending = inventory(book)
        portraits(archive, records)
    previous = args.salida_privada / "entrega-privada.json"
    tokens = {}
    if previous.exists():
        tokens = {p["codigo"]: p.get("qr") for p in json.loads(previous.read_text(encoding="utf-8"))["personas"]}
    for person in records:
        person["qr"] = tokens.get(person["codigo"]) or secrets.token_hex(32)
    catalog = {
        "programas": sorted({p["programa"] for p in records if p["programa"]}),
        "recursos": valid,
        "referencias_pendientes": pending,
    }
    args.salida_privada.mkdir(exist_ok=True, parents=True)
    save(previous, {"codigo_excluido": args.codigo_excluido, "personas": records})
    save(args.salida_privada / "inventario-entrega.json", catalog)
    print(json.dumps({
        "roles": Counter(p["rol"] for p in records),
        "programas": {role: Counter(p["programa"] or "No aplica" for p in records if p["rol"] == role) for role in ROLES},
        "fotos": sum(bool(p["foto"]) for p in records),
        "fotos_pendientes_filas": [p["fila"] for p in records if not p["foto"]],
        "nombre_pendiente_filas": [p["fila"] for p in records if p["nombre_pendiente"]],
        "programa_pendiente_filas": [p["fila"] for p in records if p["programa_pendiente"]],
        "referencias_validas": len(valid),
        "referencias_pendientes": len(pending),
    }, ensure_ascii=False))


if __name__ == "__main__":
    main()
