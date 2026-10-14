from pathlib import Path
import tempfile,shutil,subprocess
root=Path(__file__).resolve().parents[1]
p=Path(tempfile.mkdtemp(prefix='uptep-discord-login-tests-'));p.chmod(0o755)
try:
    for src,dest in [('app/discord-login.php','discord-login.php'),('app/discord-login.sql','discord-login.sql'),('app/discord-login-password.sql','discord-login-password.sql'),('app/discord-login-test.sql','discord-login-test.sql'),('app/discord-login-registration.sql','discord-login-registration.sql'),('tests/discord-login.php','test.php')]:
        shutil.copyfile(root/src,p/dest);(p/dest).chmod(0o644)
    subprocess.run(['runuser','-u','postgres','--','php',str(p/'test.php')],check=True)
finally:shutil.rmtree(p)
