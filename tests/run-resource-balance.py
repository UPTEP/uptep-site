from pathlib import Path
import tempfile,shutil,subprocess
root=Path(__file__).resolve().parents[1]
p=Path(tempfile.mkdtemp(prefix='uptep-balance-tests-'));p.chmod(0o755)
try:
    for src in ['app/resource-balance.php','app/resource-activity.php','app/convoy-consumption.php','app/script-convoy-colonies.php','tests/resource-balance.php']:
        (p/src).parent.mkdir(mode=0o755,exist_ok=True);shutil.copyfile(root/src,p/src);(p/src).chmod(0o644)
    subprocess.run(['runuser','-u','postgres','--','php',str(p/'tests/resource-balance.php')],check=True)
finally:shutil.rmtree(p)
