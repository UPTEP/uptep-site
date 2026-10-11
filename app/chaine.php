<?php
declare(strict_types=1);
const CHAINE_SERVER = 's5';
const CHAINE_GRADES = ['Grenier','Passeur','Chasseur','A flooder','Ne pas toucher','Hors chaîne'];
/** Compare the TDC ranking with the saved chain, independently of table sorting. */
function chaineMovements(array $players): array {
    $grades=['Grenier'=>0,'Passeur'=>1,'Chasseur'=>2];
    $eligible=array_values(array_filter($players,static fn(array $p):bool=>isset($grades[$p['chaine_grade']??'']) && $p['field']!==null));
    $result=[];
    foreach($players as $player) {
        $id=(string)$player['player_id'];
        if(!isset($grades[$player['chaine_grade']??''])) {
            $result[$id]=['kind'=>'excluded','symbol'=>'—','sort'=>'','label'=>$player['chaine_grade']===null?'Attribue un grade de chaîne pour calculer la position.':'Ce grade ne participe pas au classement Grenier, Passeur, Chasseur.'];continue;
        }
        if($player['field']===null) {$result[$id]=['kind'=>'excluded','symbol'=>'—','sort'=>'','label'=>'Terrain de chasse indisponible.'];continue;}
        $actualStart=1;$actualEqual=0;$targetStart=1;$targetEqual=0;
        foreach($eligible as $other) {
            if((int)$other['field']>(int)$player['field'])$actualStart++;
            if((int)$other['field']===(int)$player['field'])$actualEqual++;
            $cmp=($grades[$other['chaine_grade']]<=>$grades[$player['chaine_grade']]) ?: (($other['sort_order']??PHP_INT_MAX)<=>($player['sort_order']??PHP_INT_MAX));
            if($cmp<0)$targetStart++;
            if($cmp===0)$targetEqual++;
        }
        // Equal TDCs and equal chain orders are interchangeable: no arbitrary arrow.
        $actualEnd=$actualStart+$actualEqual-1;$targetEnd=$targetStart+$targetEqual-1;
        $kind=$actualStart>$targetEnd?'up':($actualEnd<$targetStart?'down':'aligned');
        $label=['up'=>'Monter','down'=>'Descendre','aligned'=>'À sa place'][$kind];
        $actual=$actualStart===$actualEnd?(string)$actualStart:$actualStart.'–'.$actualEnd;
        $target=$targetStart===$targetEnd?(string)$targetStart:$targetStart.'–'.$targetEnd;
        $result[$id]=['kind'=>$kind,'symbol'=>['up'=>'↑','down'=>'↓','aligned'=>'✓'][$kind],'sort'=>['up'=>'-1','down'=>'1','aligned'=>'0'][$kind],
            'label'=>$label.' · Position par terrain de chasse : '.$actual.' ; position prévue : '.$target.' (parmi les Greniers, Passeurs et Chasseurs).'];
    }
    return $result;
}
function chainePlayers(): array {
    $q = db(true)->prepare("SELECT server,player_id,pseudo,field,x,y,grade AS game_grade,on_holiday,is_banned,export_version FROM players WHERE server = ? AND upper(alliance_tag) = 'UPTEP' AND is_present = true ORDER BY lower(pseudo),player_id");
    $q->execute([CHAINE_SERVER]);
    return $q->fetchAll(PDO::FETCH_ASSOC);
}
function chaineDate(string $version): string {
    $date = DateTimeImmutable::createFromFormat('!YmdHi',trim($version),new DateTimeZone('UTC'));
    return $date ? $date->setTimezone(new DateTimeZone('Europe/Paris'))->format('d/m/Y à H:i') : 'Date indisponible';
}
function saveChaine(array $actor, int $playerId, int $version, string $grade, ?int $order): void {
    if ($playerId < 1 || $version < 0 || ($grade !== '' && !in_array($grade,CHAINE_GRADES,true)) || ($order !== null && ($order < 1 || $order > 999999))) throw new DomainException('Grade ou ordre invalide.',422);
    $p=db();$p->beginTransaction();
    try {
        $q=$p->prepare('SELECT is_chaine,status,must_change_password,session_version FROM users WHERE id = ? FOR SHARE');
        $q->execute([$actor['id']]);$current=$q->fetch(PDO::FETCH_ASSOC);
        if (!$current || !$current['is_chaine'] || $current['status'] !== 'active' || $current['must_change_password'] || (int)$current['session_version'] !== (int)$actor['session_version']) throw new DomainException('Le droit chaine est nécessaire pour modifier ce tableau.',403);
        $q=db(true)->prepare("SELECT pseudo FROM players WHERE server = ? AND player_id = ? AND upper(alliance_tag) = 'UPTEP' AND is_present = true");
        $q->execute([CHAINE_SERVER,$playerId]);
        $pseudo=$q->fetchColumn();
        if ($pseudo===false) throw new DomainException('Ce joueur ne fait plus partie des membres UPTEP présents dans le dernier relevé.',409);
        if ($version===0) {
            $q=$p->prepare('INSERT INTO chaine_members (server,player_id,pseudo,grade,sort_order,updated_by) VALUES (?,?,?,?,?,?) ON CONFLICT (server,player_id) DO NOTHING RETURNING version');
            $q->execute([CHAINE_SERVER,$playerId,$pseudo,$grade===''?null:$grade,$order,$actor['id']]);
        } else {
            $q=$p->prepare('UPDATE chaine_members SET pseudo=?,grade=?,sort_order=?,updated_by=?,updated_at=now(),version=version+1 WHERE server=? AND player_id=? AND version=? RETURNING version');
            $q->execute([$pseudo,$grade===''?null:$grade,$order,$actor['id'],CHAINE_SERVER,$playerId,$version]);
        }
        if (!$q->fetchColumn()) throw new DomainException('Cette ligne a été modifiée entre-temps. Vérifie les valeurs actualisées avant de réessayer.',409);
        $p->commit();
    } catch(Throwable $error) {if($p->inTransaction())$p->rollBack();throw $error;}
}

/** Delete only a saved chain row whose player is no longer a current UPTEP member. */
function deleteDepartedChaine(array $actor,int $playerId,int $version): void {
    if($playerId<1 || $version<1)throw new DomainException('Ligne invalide.',422);
    $p=db();$p->beginTransaction();
    try {
        $q=$p->prepare('SELECT is_chaine,status,must_change_password,session_version FROM users WHERE id=? FOR SHARE');$q->execute([$actor['id']]);$current=$q->fetch(PDO::FETCH_ASSOC);
        if(!$current || !$current['is_chaine'] || $current['status']!=='active' || $current['must_change_password'] || (int)$current['session_version']!==(int)$actor['session_version'])throw new DomainException('Le droit chaine est nécessaire pour supprimer cette ligne.',403);
        $q=$p->prepare('SELECT version FROM chaine_members WHERE server=? AND player_id=? FOR UPDATE');$q->execute([CHAINE_SERVER,$playerId]);$saved=$q->fetchColumn();
        if($saved===false || (int)$saved!==$version)throw new DomainException('Cette ligne a changé. Recharge la page avant de réessayer.',409);
        $q=db(true)->prepare("SELECT 1 FROM players WHERE server=? AND player_id=? AND upper(alliance_tag)='UPTEP' AND is_present=true");$q->execute([CHAINE_SERVER,$playerId]);
        if($q->fetchColumn())throw new DomainException('Ce joueur est toujours dans l’alliance : sa ligne ne peut pas être supprimée.',409);
        $q=$p->prepare('DELETE FROM chaine_members WHERE server=? AND player_id=? AND version=?');$q->execute([CHAINE_SERVER,$playerId,$version]);
        $p->commit();
    } catch(Throwable $e){if($p->inTransaction())$p->rollBack();throw $e;}
}
