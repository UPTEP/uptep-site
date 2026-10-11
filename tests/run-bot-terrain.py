from pathlib import Path
import tempfile,shutil,subprocess
root=Path(__file__).resolve().parents[1]
p=Path(tempfile.mkdtemp(prefix='uptep-terrain-tests-'));p.chmod(0o755)
try:
    for src,dest in [('app/bot-terrain.php','bot-terrain.php'),('app/bot-terrain.sql','bot-terrain.sql'),('tests/bot-terrain.php','test.php')]:
        shutil.copyfile(root/src,p/dest);(p/dest).chmod(0o644)
    subprocess.run(['runuser','-u','postgres','--','php',str(p/'test.php')],check=True)
finally:shutil.rmtree(p)
