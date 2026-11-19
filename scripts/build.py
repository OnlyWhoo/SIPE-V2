"""Construye el ZIP privado con los datos autorizados desde una carpeta externa a htdocs."""

import argparse
from hashlib import sha256
from pathlib import Path
from zipfile import ZIP_DEFLATED, ZipFile


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("private", type=Path)
    args = parser.parse_args()
    root = Path(__file__).resolve().parents[1]
    private = args.private.resolve(strict=True)
    if private.is_relative_to(root.parent):
        raise SystemExit("La carpeta de entrega privada debe quedar fuera de htdocs.")
    files = {}
    for directory in ("app", "assets", "public", "vendor"):
        for file in (root / directory).rglob("*"):
            if file.is_file() and ".git" not in file.relative_to(root).parts:
                files[file.relative_to(root).as_posix()] = file
    for name in (
        "index.php", ".htaccess", "README.md", "LEEME-PRIMERO.txt", "composer.json", "composer.lock",
        "config/example.php", "database/install-plan.json", "database/entrega-plan.json", "database/schema.sql",
        "docs/PRUEBAS.md", "config/protection-check.txt", "database/protection-check.txt",
        "docs/protection-check.txt", "var/protection-check.txt",
    ):
        files[name] = root / name
    for name in ("entrega-privada.json", "inventario-entrega.json", "arranque-privado.json"):
        files["database/" + name] = private / name

    destination = private / "SIPE-V2-ENTREGA-ACADEMICA-PRIVADA.zip"
    temporary = destination.with_suffix(".tmp")
    hashes = {}
    try:
        with ZipFile(temporary,"w",ZIP_DEFLATED,compresslevel=9) as archive:
            for name, file in sorted(files.items()):
                content = file.read_bytes()
                hashes[name] = sha256(content).hexdigest()
                archive.writestr("SIPE-V2/" + name, content)
            manifest = "".join(f"{digest}  {name}\n" for name, digest in hashes.items())
            archive.writestr("SIPE-V2/docs/SHA256SUMS.txt", manifest)
        with ZipFile(temporary) as archive:
            if archive.testzip() or any(
                sha256(archive.read("SIPE-V2/" + name)).hexdigest() != digest
                for name, digest in hashes.items()
            ):
                raise RuntimeError("El contenido del ZIP difiere del origen.")
        temporary.replace(destination)
    finally:
        temporary.unlink(missing_ok=True)
    digest = sha256(destination.read_bytes()).hexdigest()
    destination.with_suffix(".zip.sha256").write_text(f"{digest}  {destination.name}\n", encoding="ascii")
    print(destination)
    print(f"SHA-256 {digest}")


if __name__ == "__main__":
    main()
