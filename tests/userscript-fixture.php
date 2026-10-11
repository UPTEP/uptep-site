<?php
require '/home/uptep/private/app/bootstrap.php';
$player=db(true)->query("SELECT player_id,pseudo FROM players WHERE server='s5' AND upper(alliance_tag)='UPTEP' AND is_present=true ORDER BY player_id LIMIT 1")->fetch(PDO::FETCH_ASSOC);
$q=db()->prepare("UPDATE users SET game_player_id=? WHERE username='qa_chaine_editor'");$q->execute([$player['player_id']]);umask(0077);file_put_contents('/root/uptep-script-test-player.json',json_encode($player));
