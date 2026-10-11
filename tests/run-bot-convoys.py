"""Run with local postgres peer access; fixtures live in a disposable schema."""
from pathlib import Path
import tempfile, shutil, subprocess
root=Path(__file__).resolve().parents[1]
p=Path(tempfile.mkdtemp(prefix='uptep-bot-tests-'));p.chmod(0o755)
try:
    for source,name in [('tests/bot-reports-checks.php','bot-reports-checks.php'),('app/bot-reports.php','bot-reports-service.php'),('tests/bot-convoys.php','test.php'),('app/bot-convoys.php','bot-convoys-service.php'),('app/bot-convoys.sql','bot-convoys-schema.sql')]:
        dest=p/name;shutil.copyfile(root/source,dest);dest.chmod(0o644)
    with (p/'bot-convoys-schema.sql').open('a') as f:f.write((root/'app/bot-ui.sql').read_text());f.write((root/'app/bot-convoy-cancel.sql').read_text());f.write((root/'app/bot-demand-adjustments.sql').read_text())
    shutil.chown(p,user='postgres')
    subprocess.run(['runuser','-u','postgres','--','php',str(p/'test.php')],check=True)
finally:
    shutil.rmtree(p)
