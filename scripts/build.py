"""Build the academic distribution with an explicit allowlist; Python is development-only."""
from pathlib import Path
import zipfile,hashlib
root=Path(__file__).resolve().parents[1];dist=root/'dist';dist.mkdir(exist_ok=True)
files=[]
for folder in ['app','assets','public','config','database','vendor','docs']:
    files.extend(p for p in (root/folder).rglob('*') if p.is_file() and not p.name.endswith('.local.php') and p.name!='local.php')
for name in ['index.php','.htaccess','README.md','LEEME-PRIMERO.txt','composer.json','composer.lock','var/protection-check.txt']:
    files.append(root/name)
files=[p for p in sorted(set(files)) if p.name!='SHA256SUMS.txt']
for p in files:
    rel=p.relative_to(root).as_posix()
    assert not any(part in rel for part in ['.git/','/sessions/','backup','admin.local','test-config','php-error','.env'])
manifest=root/'docs/SHA256SUMS.txt'
manifest.write_text(''.join(hashlib.sha256(p.read_bytes()).hexdigest()+'  '+p.relative_to(root).as_posix()+'\n' for p in files),encoding='utf-8');files.append(manifest)
archive=dist/'SIPE-V2-ENTREGA-FINAL.zip'
with zipfile.ZipFile(archive,'w',zipfile.ZIP_DEFLATED,compresslevel=9) as z:
    for p in files:z.write(p,'SIPE-V2/'+p.relative_to(root).as_posix())
with zipfile.ZipFile(archive) as z:
    assert z.testzip() is None
    for p in files:assert z.read('SIPE-V2/'+p.relative_to(root).as_posix())==p.read_bytes()
digest=hashlib.sha256(archive.read_bytes()).hexdigest()
(dist/(archive.name+'.sha256')).write_text(digest+'  '+archive.name+'\n')
print(archive);print('bytes',archive.stat().st_size);print('SHA256',digest)
