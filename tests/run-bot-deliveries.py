from pathlib import Path
import tempfile,shutil,subprocess
root=Path(__file__).resolve().parents[1]
p=Path(tempfile.mkdtemp(prefix='uptep-deliveries-'));p.chmod(0o755)
try:
 for source,name in [('app/bot-convoys.php','bot-convoys.php'),('app/bot-deliveries.php','bot-deliveries.php'),('tests/bot-deliveries.php','test.php')]:
  shutil.copyfile(root/source,p/name);(p/name).chmod(0o644)
 subprocess.run(['runuser','-u','postgres','--','php',str(p/'test.php')],check=True)
finally:shutil.rmtree(p)
