<?php
declare(strict_types=1);
/** Highlights depend on the saved hierarchy, not the current visual table sort. */
function chaineFocus(array $players, ?int $playerId): array {
    $result=['self'=>null,'targets'=>[],'reason'=>'unlinked'];
    if($playerId===null)return $result;
    $self=null;
    foreach($players as $player)if((int)$player['player_id']===$playerId){$self=$player;break;}
    if(!$self){$result['reason']='absent';return $result;}
    $result['self']=$playerId;$result['reason']='ready';
    $grades=['Grenier'=>0,'Passeur'=>1,'Chasseur'=>2,'A flooder'=>3];
    if(!in_array($self['chaine_grade'],['Grenier','Passeur','Chasseur'],true)){$result['reason']='unranked';return $result;}
    $own=(int)($self['field']??0);
    if($own<1 || $self['on_holiday'] || $self['is_banned']){$result['reason']='unavailable';return $result;}
    // Exact inclusive 50%–300% interval, avoiding bigint overflow.
    $min=intdiv($own,2)+$own%2;
    $max=$own>intdiv(PHP_INT_MAX,3)?PHP_INT_MAX:$own*3;
    foreach($players as $target) {
        $id=(int)$target['player_id'];$grade=$target['chaine_grade'];
        if($id===$playerId || !isset($grades[$grade??'']) || $target['on_holiday'] || $target['is_banned'])continue;
        $gradeComparison=$grades[$grade]<=>$grades[$self['chaine_grade']];
        $below=$gradeComparison>0 || ($gradeComparison===0 && $self['sort_order']!==null && $target['sort_order']!==null && (int)$target['sort_order']>(int)$self['sort_order']);
        $field=(int)($target['field']??0);
        if($below && $field>0 && $field>=$min && $field<=$max)$result['targets'][]=$id;
    }
    return $result;
}
