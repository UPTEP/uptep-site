<?php
declare(strict_types=1);

function combatAnalyze(string $raw, string $location): array {
    if (strlen($raw) > 100000 || trim($raw) === '') throw new DomainException('Colle un RC de moins de 100 000 caractères.');
    if (!in_array($location, ['auto','terrain','dome','loge'], true)) throw new DomainException('Lieu invalide.');
    $process = proc_open(['/usr/bin/timeout', '25s', '/usr/bin/node', __DIR__.'/combat-analyze.cjs'], [['pipe','r'],['pipe','w'],['file','/dev/null','a']], $pipes);
    if (!is_resource($process)) throw new RuntimeException('Moteur indisponible.');
    fwrite($pipes[0], json_encode(['raw'=>$raw,'location'=>$location], JSON_THROW_ON_ERROR)); fclose($pipes[0]);
    $result = stream_get_contents($pipes[1], 24000000); fclose($pipes[1]);
    $status = proc_close($process);
    $analysis = json_decode($result ?: '', true);
    if (!is_array($analysis) || $status !== 0) throw new DomainException($analysis['error'] ?? 'Analyse trop longue ou indisponible. Vérifie le RC et précise le lieu.');
    return $analysis;
}
function combatPlayers(string $query): array {
    $query=trim($query);
    if (mb_strlen($query)<2 || mb_strlen($query)>100) return [];
    $q=db(true)->prepare("SELECT player_id,pseudo FROM players WHERE server='s5' AND (pseudo ILIKE ? ESCAPE '!' OR player_id::text = ?) ORDER BY lower(pseudo),player_id LIMIT 15");
    $q->execute(['%'.str_replace(['!','%','_'],['!!','!%','!_'],$query).'%', $query]);
    return $q->fetchAll(PDO::FETCH_ASSOC);
}
function combatPlayer(string $id, string $name, bool $required): array {
    if ($id==='') {
        if ($required) throw new DomainException('Sélectionne la cible dans les suggestions pour la relier à son identifiant S5.');
        if(mb_strlen($name)>255) throw new DomainException('Pseudo trop long.');
        return ['id'=>null,'name'=>trim($name) ?: null];
    }
    if (!ctype_digit($id) || strlen($id)>18) throw new DomainException('Identifiant joueur invalide.');
    $q=db(true)->prepare("SELECT player_id,pseudo FROM players WHERE server='s5' AND player_id=?");$q->execute([$id]);$p=$q->fetch(PDO::FETCH_ASSOC);
    if (!$p) throw new DomainException('Joueur introuvable sur S5.');
    return ['id'=>$p['player_id'],'name'=>$p['pseudo']];
}
function combatDate(string $input): DateTimeImmutable {
    $d=DateTimeImmutable::createFromFormat('!Y-m-d\TH:i', $input, new DateTimeZone('Europe/Paris'));
    if (!$d || $d->format('Y-m-d\TH:i')!==$input) throw new DomainException('Renseigne une date et une heure valides (heure de Paris).');
    return $d;
}
function combatSave(array $u, array $draft): int {
    $a=$draft['analysis']; $date=combatDate(post('combat_at'));$target=post('target_side');
    if(!in_array($target,['attacker','defender'],true))throw new DomainException('Camp cible invalide.');
    $sides=[];
    foreach(['attacker','defender'] as $i=>$side){
        $s=$a['sides'][$i];$p=combatPlayer(post($side.'_id'),post($side.'_name'),$target===$side);
        $provenance=['computed'=>$s,'manual'=>[]];
        foreach(['arms','shield','dome','loge'] as $field){
            $value=post($side.'_'.$field);
            if($value==='') continue;
            if(!ctype_digit($value)||(int)$value>100)throw new DomainException('Les niveaux doivent être compris entre 0 et 100.');
            $s[$field]=(int)$value;$provenance['manual'][$field]=(int)$value;
        }
        // Un bâtiment ne protège que le défenseur dans ce lieu ; inconnu reste NULL.
        $s['attack_bonus']=$s['arms']===null?null:$s['attack_base']*(1+.1*$s['arms']);
        $factor=null;
        if($s['shield']!==null){
            if($i===0 || $a['location']==='terrain') $factor=1+.1*$s['shield'];
            elseif($a['location']==='dome' && $s['dome']!==null)$factor=1+.1*$s['shield']+.1+.05*$s['dome'];
            elseif($a['location']==='loge' && $s['loge']!==null)$factor=1+.1*$s['shield']+.3+.15*$s['loge'];
        }
        if($factor!==null) $s['hp_bonus']=$s['hp_base']*$factor;
        elseif(array_intersect_key($provenance['manual'],array_flip(['shield','dome','loge'])))$s['hp_bonus']=null;
        if($provenance['manual']){$s['hp_min']=null;$s['hp_max']=null;}
        $sides[]=[$s,$p,$provenance];
    }
    $db=db();$db->beginTransaction();
    try{
        $q=$db->prepare('SELECT role,status,must_change_password,session_version FROM users WHERE id=? FOR SHARE');$q->execute([$u['id']]);$actor=$q->fetch(PDO::FETCH_ASSOC);
        if(!$actor||$actor['role']!=='admin'||$actor['status']!=='active'||$actor['must_change_password']||(int)$actor['session_version']!==(int)$u['session_version']||isset($u['_preview']))throw new DomainException('Accès administrateur requis.');
        $q=$db->prepare('INSERT INTO combat_reports(raw_text,content_hash,combat_at,date_source,location,target_side,created_by,analysis_version) VALUES(?,?,?,?,?,?,?,?) RETURNING id');
        $q->execute([$draft['raw'],hash('sha256',preg_replace('/\s+/u',' ',trim($draft['raw']))),$date->format(DateTimeInterface::ATOM),post('combat_at')===($a['date']??null)?'detected':'manual',$a['location'],$target,$u['id'],$a['version']]);$id=(int)$q->fetchColumn();
        $ids=[];
        $insert=$db->prepare('INSERT INTO combat_observations(report_id,side,player_id,player_name,observed_at,arms,shield,dome,loge,attack_base,attack_bonus,hp_base,hp_bonus,hp_min,hp_max,provenance) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?) RETURNING id');
        $unit=$db->prepare('INSERT INTO combat_observation_units(observation_id,unit_id,quantity) VALUES(?,?,?)');
        foreach($sides as $i=>[$s,$p,$provenance]){
            $insert->execute([$id,['attacker','defender'][$i],$p['id'],$p['name'],$date->format(DateTimeInterface::ATOM),$s['arms'],$s['shield'],$s['dome'],$s['loge'],$s['attack_base'],$s['attack_bonus'],$s['hp_base'],$s['hp_bonus'],$s['hp_min'],$s['hp_max'],json_encode($provenance,JSON_THROW_ON_ERROR)]);
            $oid=(int)$insert->fetchColumn();$ids[]=$oid;
            foreach($a['units'] as $j=>$type)$unit->execute([$oid,$type['id'],$s['army'][$j]]);
        }
        $q=$db->prepare('INSERT INTO combat_observation_candidates(attacker_observation_id,defender_observation_id,attacker_shield,defender_shield,location,building_level) SELECT ?,?,c."aBou",c."dBou",c.lieu,c.niveau FROM jsonb_to_recordset(?::jsonb) AS c("aBou" integer,"dBou" integer,lieu text,niveau integer)');
        $q->execute([$ids[0],$ids[1],json_encode($a['candidates'],JSON_THROW_ON_ERROR)]);
        $db->commit();return $id;
    }catch(Throwable $e){$db->rollBack();if($e instanceof PDOException && $e->getCode()==='23505')throw new DomainException('Ce rapport est déjà enregistré. Retrouve-le dans la liste.');throw $e;}
}
function combatNumber(mixed $n): string {return $n===null?'Inconnu':number_format((float)$n, is_numeric($n)&&floor((float)$n)!=(float)$n?2:0,',',' ');}
function combatDisplayDate(string $date): string {return (new DateTimeImmutable($date))->setTimezone(new DateTimeZone('Europe/Paris'))->format('d/m/Y H:i');}
