"""Optional development lab. Not included in the professor's ZIP; never uses an existing datadir."""
from pathlib import Path
import argparse,secrets,subprocess,time
p=argparse.ArgumentParser();p.add_argument('--xampp-root',required=True,type=Path);args=p.parse_args()
root=Path(__file__).resolve().parents[1];run=root/'var/runtime';run.mkdir(parents=True,exist_ok=True)
data=run/'mysql-data'
if data.exists():raise SystemExit('Lab data already exists. Preserve it; this script never reinitializes existing data.')
bin=args.xampp_root.resolve()/'mysql/bin';assert (bin/'mysql_install_db.exe').is_file()
password=secrets.token_hex(32);(run/'db-secret.txt').write_text(password)
with (run/'bootstrap.log').open('wb') as log:
    result=subprocess.run([str(bin/'mysql_install_db.exe'),f'--datadir={data}','--port=3312',f'--password={password}'],stdin=subprocess.DEVNULL,stdout=log,stderr=subprocess.STDOUT,cwd=run,close_fds=True)
if result.returncode:raise SystemExit('Initialization failed; inspect the private lab log.')
cfg=run/'mysql.ini';cfg.write_text('[mysqld]\nbasedir='+bin.parent.as_posix()+'\ndatadir='+data.as_posix()+'\nport=3312\nbind-address=127.0.0.1\ncharacter-set-server=utf8mb4\ncollation-server=utf8mb4_unicode_ci\n')
log=(run/'mysql-console.log').open('ab',buffering=0)
proc=subprocess.Popen([str(bin/'mysqld.exe'),f'--defaults-file={cfg}','--console'],stdin=subprocess.DEVNULL,stdout=log,stderr=log,cwd=run,close_fds=True,creationflags=getattr(subprocess,'CREATE_NO_WINDOW',0))
time.sleep(2)
if proc.poll() is not None:raise SystemExit('Lab server failed; inspect its log and port availability.')
(run/'mysql-pid.txt').write_text(str(proc.pid))
for folder in ['sessions','test-results']:(root/'var'/folder).mkdir(exist_ok=True)
(root/'config/admin.local.php').write_text("<?php return ['dsn'=>'mysql:host=127.0.0.1;port=3312;charset=utf8mb4','user'=>'root','password'=>'"+password+"'];\n")
(run/'admin-client.ini').write_text('[client]\nhost=127.0.0.1\nport=3312\nuser=root\npassword='+password+'\n')
print('Authenticated isolated lab running on 127.0.0.1:3312. No Windows service installed.')
print('CLI tests: unit.php, integration.php, workflow.php, concurrency.php, installer.php, in that order.')
print('Stop only this lab with mysqladmin.exe and --defaults-extra-file pointing to var/runtime/admin-client.ini, command shutdown.')
