<?php
declare(strict_types=1);
function gamePlayer(int $id): ?array {
    $q=db(true)->prepare("SELECT player_id,pseudo,alliance_tag FROM players WHERE server='s5' AND player_id=? AND is_present=true");
    $q->execute([$id]);return $q->fetch(PDO::FETCH_ASSOC)?:null;
}
function saveGamePlayer(array $actor, ?int $playerId): void {
    if($playerId!==null && ($playerId<1 || !gamePlayer($playerId))) throw new DomainException('Cet ID ne correspond à aucun joueur présent dans le dernier relevé du serveur 5.',422);
    // Identity is a personal display preference, never a source of authorization.
    $q=db()->prepare("UPDATE users SET game_player_id=? WHERE id=? AND status='active' AND session_version=? AND must_change_password=false RETURNING id");
    $q->execute([$playerId,$actor['id'],$actor['session_version']]);
    if(!$q->fetchColumn()) throw new DomainException('Ta session a changé. Reconnecte-toi avant de réessayer.',403);
}
