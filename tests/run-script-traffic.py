from pathlib import Path
import tempfile, shutil, subprocess
root=Path(__file__).resolve().parents[1]
folder=Path(tempfile.mkdtemp(prefix='uptep-script-traffic-'));folder.chmod(0o755)
try:
    files=[('app/script-traffic.php','script-traffic.php.service'),('app/script-traffic.sql','script-traffic.sql'),('tests/script-traffic.php','test.php'),('tests/laboratory-settings.php','settings-test.php')]
    files += [('app/'+name,name) for name in ['userscript.php','script-settings.php','stats.php','hourly-collection.php','laboratory-settings.sql']]
    for source,name in files:
        shutil.copyfile(root/source,folder/name);(folder/name).chmod(0o644)
    for test in ['test.php','settings-test.php']:
        subprocess.run(['runuser','-u','postgres','--','php',str(folder/test)],check=True)
finally:
    shutil.rmtree(folder)
