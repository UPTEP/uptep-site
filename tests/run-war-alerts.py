from pathlib import Path
import tempfile,shutil,subprocess
root=Path(__file__).resolve().parents[1]
p=Path(tempfile.mkdtemp(prefix='uptep-war-alerts-'));p.chmod(0o755)
try:
 for source,name in [('app/war-alerts.php','war-alerts.php'),('app/war-alerts.sql','war-alerts.sql'),('tests/war-alerts.php','test.php')]:
  shutil.copyfile(root/source,p/name);(p/name).chmod(0o644)
 subprocess.run(['runuser','-u','postgres','--','php',str(p/'test.php')],check=True)
finally:shutil.rmtree(p)
