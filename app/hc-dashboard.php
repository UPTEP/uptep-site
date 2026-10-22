<?php
declare(strict_types=1);
require_once __DIR__.'/stats.php';
require_once __DIR__.'/chaine.php';

/** Compact figure with 3 significant digits and k/M/G/T/P suffix (French decimal comma). */
function hcCompact(int|string $value):string{
    $n=(float)$value;
    if($n<1000)return (string)(int)$n;
    $units=['','k','M','G','T','P'];$scale=min(5,(int)floor(log10($n)/3));
    $v=$n/(1000**$scale);
    $digits=strlen((string)(int)$v);$v=round($v,max(0,3-$digits));
    if($v>=1000&&$scale<5){$scale++;$v=round($n/(1000**$scale),2);}
    $text=number_format($v,max(0,3-strlen((string)(int)$v)),',','');
    if(str_contains($text,','))$text=rtrim(rtrim($text,'0'),',');
    return $text.$units[$scale];
}
function hcDate(?string $value,string $format='d/m/Y H:i'):string{
    if($value===null)return '';
    try{return (new DateTimeImmutable($value))->setTimezone(new DateTimeZone('Europe/Paris'))->format($format);}catch(Throwable){return '';}
}
function hcStatus(string $kind,string $label):string{
    $icons=['yes'=>'<path d="m5 12.5 4.5 4.5L19 7.5"/>','no'=>'<path d="m6 6 12 12M18 6 6 18"/>','pending'=>'<circle cx="12" cy="12" r="8.5"/><path d="M12 7.5V12l3 2"/>'];
    return '<span class="hc-status is-'.$kind.'"><svg viewBox="0 0 24 24" aria-hidden="true">'.$icons[$kind].'</svg>'.e($label).'</span>';
}
/** Digits grouped by thousands for display ("1 234 567"); anything else is shown as is. */
function hcGroup(mixed $value):string{
    if(is_int($value))$value=(string)$value;
    if(!is_string($value)||!preg_match('/^[0-9]+$/D',$value))return '';
    return strrev(trim(chunk_split(strrev($value),3,' ')));
}

/* ---- Member detail modal ---- */
function hcYesNo(?bool $v):string{return $v===null?'<span class="hc-unknown">—</span>':($v?hcStatus('yes','Oui'):hcStatus('no','Non'));}
function hcText(mixed $v):string{$v=$v===null||$v===''?'':(string)$v;return $v===''?'<span class="hc-unknown">—</span>':e($v);}
function hcWhen(?string $v):string{$d=hcDate($v);return $d===''?'<span class="hc-unknown">—</span>':e($d);}
/** Definition list; values are trusted HTML built by the helpers above. */
function hcDl(array $pairs):string{
    $html='<dl class="hc-dl">';
    foreach($pairs as $label=>$value)$html.='<div><dt>'.e((string)$label).'</dt><dd>'.$value.'</dd></div>';
    return $html.'</dl>';
}
function hcSection(string $title,string $body,string $note=''):string{
    return '<section class="hc-detail-section"><h3>'.e($title).($note!==''?'<span class="hc-detail-note">'.e($note).'</span>':'').'</h3>'.$body.'</section>';
}

/** Loads everything that is not already in the dashboard rows, once for all members. */
function hcDetailSources(array $ids):array{
    $out=['game'=>[],'users'=>[],'tokens'=>[],'stats'=>[],'convoys_out'=>[],'convoys_in'=>[],'floods_out'=>[],'floods_in'=>[],'hunts'=>[],'hunts_sync'=>[]];
    $q=db(true)->prepare("SELECT player_id,x,y,field,grade,building_score,technology_score,trophy_score,on_holiday,is_banned,export_version FROM players WHERE server=? AND upper(alliance_tag)='UPTEP' AND is_present=true");
    $q->execute([CHAINE_SERVER]);
    foreach($q->fetchAll(PDO::FETCH_ASSOC) as $r)$out['game'][(string)$r['player_id']]=$r;
    $q=db()->query("SELECT id,game_player_id,username,display_name,role,status,is_chef,is_chaine,script_enabled,must_change_password,(discord_login_id IS NOT NULL) AS discord_linked,created_at,approved_at,last_login_at,last_site_seen_at,last_script_seen_at,terms_accepted_at,terms_version,privacy_notice_version FROM users WHERE game_player_id IS NOT NULL ORDER BY id");
    foreach($q->fetchAll(PDO::FETCH_ASSOC) as $r)$out['users'][(string)$r['game_player_id']][]=$r;
    $q=db()->query('SELECT user_id,count(*)::int AS n,min(created_at) AS first_at,max(created_at) AS last_at FROM script_tokens GROUP BY user_id');
    foreach($q->fetchAll(PDO::FETCH_ASSOC) as $r)$out['tokens'][(string)$r['user_id']]=$r;
    $q=db()->query('SELECT user_id,data,buildings_synced_at,research_synced_at,army_synced_at,resources_synced_at,workers_synced_at FROM user_stats');
    foreach($q->fetchAll(PDO::FETCH_ASSOC) as $r)$out['stats'][(string)$r['user_id']]=$r;
    // Convoys on their way: sent by a member (idenvoyeur) and received by the requester of the demand.
    $q=db()->query("SELECT c.idenvoyeur,c.pseudo_envoyeur,d.iddemandeur,d.pseudo_demandeur,c.nbpomme,c.nbmateriaux,c.date_convoi,c.date_arrivee FROM convois_effectues c JOIN demandes_convois d ON d.id=c.iddemande WHERE c.annule=false AND c.date_convoi<=now() AND c.date_arrivee>now() ORDER BY c.date_arrivee,c.id");
    foreach($q->fetchAll(PDO::FETCH_ASSOC) as $r){
        $out['convoys_out'][(string)$r['idenvoyeur']][]=$r;
        $out['convoys_in'][(string)$r['iddemandeur']][]=$r;
    }
    // Hunts in progress read by UPTEP+ (opt-in), and each player's last hunt collection.
    foreach(db()->query("SELECT player_id::text,area::text,arrival_at,source FROM script_hunts WHERE cancelled_at IS NULL AND arrival_at>now() ORDER BY arrival_at,id") as $r)$out['hunts'][$r['player_id']][]=$r;
    foreach(db()->query("SELECT u.game_player_id::text AS player_id,max(s.hunts_synced_at) AS synced,bool_or(coalesce((s.preferences->>'hunts')::boolean,false)) AS enabled FROM users u JOIN script_settings s ON s.user_id=u.id WHERE u.status='active' AND u.game_player_id IS NOT NULL GROUP BY u.game_player_id") as $r)$out['hunts_sync'][$r['player_id']]=$r;
    // Floods still on their way, indexed by sender and by target (both are game player ids).
    $q=db()->query("SELECT sender_id,sender_pseudo,target_id,target_pseudo,location,troops,arrival_at FROM floods WHERE arrival_at>now() ORDER BY arrival_at,id");
    foreach($q->fetchAll(PDO::FETCH_ASSOC) as $r){
        $out['floods_out'][(string)$r['sender_id']][]=$r;
        if($r['target_id']!==null)$out['floods_in'][(string)$r['target_id']][]=$r;
    }
    return $out;
}

function hcDetailHtml(array $row,array $src,?array $chain):string{
    $id=$row['id'];$g=$src['game'][$id]??null;$html='';
    // Game profile
    if($g){
        $html.=hcSection('Joueur en jeu',hcDl([
            'Pseudo'=>hcText($row['name']),'ID joueur'=>hcText($id),'Alliance'=>'UPTEP · Serveur 5',
            'Coordonnées'=>hcText($g['x']!==null?$g['x'].' / '.$g['y']:null),
            'Terrain de chasse'=>$g['field']===null?hcText(null):e(hcGroup((string)$g['field'])).' <span class="hc-detail-note">('.e(hcCompact((int)$g['field'])).')</span>',
            'Grade en jeu'=>hcText($g['grade']),
            'Score bâtiments'=>hcText($g['building_score']),'Score technologies'=>hcText($g['technology_score']),'Score trophées'=>hcText($g['trophy_score']),
            'En vacances'=>hcYesNo((bool)$g['on_holiday']),'Banni'=>hcYesNo((bool)$g['is_banned']),
        ]),'Relevé du '.chaineDate((string)$g['export_version']));
    }
    $html.=hcSection('Chaîne',hcDl(['Grade'=>hcText($chain['grade']??'Non attribué'),'Ordre'=>hcText($chain['sort_order']??null)]));
    // Site accounts
    $accounts=$src['users'][$id]??[];
    if(!$accounts)$html.=hcSection('Compte sur le site','<p class="hc-detail-empty">Aucun compte du site n’est lié à ce joueur.</p>');
    foreach($accounts as $a){
        $t=$src['tokens'][(string)$a['id']]??null;
        $rights=array_filter([$a['role']==='admin'?'Admin':null,$a['is_chef']?'Chef':null,$a['is_chaine']?'Chaîne':null]);
        $status=['active'=>hcStatus('yes','Actif'),'pending'=>hcStatus('pending','En attente'),'disabled'=>hcStatus('no','Désactivé')][$a['status']]??hcText($a['status']);
        $html.=hcSection('Compte sur le site'.(count($accounts)>1?' · '.$a['username']:''),hcDl([
            'Identifiant'=>hcText($a['username']),'Nom affiché'=>hcText($a['display_name']),'Statut'=>$status,
            'Droits'=>hcText($rights?implode(', ',$rights):'Membre'),
            'Discord lié'=>hcYesNo((bool)$a['discord_linked']),
            'Mot de passe à changer'=>hcYesNo((bool)$a['must_change_password']),
            'Userscript autorisé'=>hcYesNo((bool)$a['script_enabled']),
            'Clés userscript'=>$t?e((string)$t['n']).' <span class="hc-detail-note">(du '.e(hcDate($t['first_at'],'d/m/Y')).' au '.e(hcDate($t['last_at'],'d/m/Y')).')</span>':hcText(null),
            'Compte créé'=>hcWhen($a['created_at']),'Compte validé'=>hcWhen($a['approved_at']),
            'Dernière connexion'=>hcWhen($a['last_login_at']),'Dernière visite du site'=>hcWhen($a['last_site_seen_at']),
            'Dernière activité userscript'=>hcWhen($a['last_script_seen_at']),
            'CGU acceptées'=>$a['terms_accepted_at']?e(hcDate($a['terms_accepted_at'])).' <span class="hc-detail-note">(version '.e((string)$a['terms_version']).')</span>':hcText(null),
            'Confidentialité (version)'=>hcText($a['privacy_notice_version']),
        ]));
    }
    // Derived figures and UPTEP+ statistics (latest synchronised active account)
    $derived=[
        'TDP'=>$row['tdp']!==null?e((string)$row['tdp']).' <span class="hc-detail-note">('.e($row['tdp_detail']).')</span>':hcText(null),
        'FDF'=>$row['fdf']!==null?e(hcGroup($row['fdf'])).' <span class="hc-detail-note">(armée × attaque de base × (1 + 10 % × niveau d’armes))</span>':hcText(null),
        'Ouvrières'=>$row['workers']!==null?e(hcGroup($row['workers'])).($row['workers_transit']>0?' <span class="hc-detail-note">(dont '.e(hcGroup($row['workers_transit'])).' en convoi)</span>':'').($row['workers_back']>0?' <span class="hc-detail-note">(dont '.e(hcGroup($row['workers_back'])).' revenus de convoi depuis la récolte)</span>':''):hcText(null),
    ];
    $html.=hcSection('Valeurs calculées',hcDl($derived));
    $stats=null;
    foreach(array_reverse($accounts) as $a){$s=$src['stats'][(string)$a['id']]??null;if($s&&$a['status']==='active'){$stats=$s;break;}}
    if(!$stats)$html.=hcSection('Statistiques UPTEP+','<p class="hc-detail-empty">Aucune donnée UPTEP+ pour ce joueur.</p>');
    else{
        $data=json_decode($stats['data'],true)?:[];
        foreach(STATS_GROUPS as $group=>[$title,$fields]){
            $pairs=[];
            foreach($fields as $key=>$label){$v=$data[$group][$key]??null;$pairs[$label]=is_string($v)&&preg_match('/^[0-9]+$/D',$v)?e(hcGroup($v)):hcText(null);}
            $html.=hcSection($title,hcDl($pairs),$stats[$group.'_synced_at']?'Récolté le '.hcDate($stats[$group.'_synced_at']):'Date de récolte inconnue');
        }
    }
    // Convoys in progress, sent and received
    foreach(['convoys_out'=>['Convois envoyés en cours','Destinataire'],'convoys_in'=>['Convois reçus en cours','Expéditeur']] as $key=>[$heading,$who]){
        $list=$src[$key][$id]??[];
        if(!$list){$html.=hcSection($heading,'<p class="hc-detail-empty">Aucun convoi en cours.</p>');continue;}
        $body='<table class="hc-detail-table"><thead><tr><th scope="col">'.e($who).'</th><th scope="col">Nourriture</th><th scope="col">Matériaux</th><th scope="col">Parti le (heure de Paris)</th><th scope="col">Arrive le (heure de Paris)</th></tr></thead><tbody>';
        foreach($list as $c)$body.='<tr><td>'.e((string)($key==='convoys_out'?$c['pseudo_demandeur']:$c['pseudo_envoyeur'])).'</td><td>'.e(hcGroup((string)$c['nbpomme'])).'</td><td>'.e(hcGroup((string)$c['nbmateriaux'])).'</td><td>'.e(hcDate($c['date_convoi'])).'</td><td>'.e(hcDate($c['date_arrivee'])).'</td></tr>';
        $html.=hcSection($heading,$body.'</tbody></table>',(string)count($list));
    }
    // Hunts in progress (UPTEP+ collection on the hunting page)
    $hunts=$src['hunts'][$id]??[];$sync=$src['hunts_sync'][$id]??null;
    $note=!$sync||!$sync['enabled']?'Collecte des chasses désactivée':($sync['synced']?'Relevé du '.hcDate($sync['synced']):'Aucun relevé pour le moment');
    if(!$hunts)$html.=hcSection('Chasses en cours','<p class="hc-detail-empty">Aucune chasse en cours enregistrée.</p>',$note);
    else{
        $total=array_sum(array_map(static fn($h)=>(int)$h['area'],$hunts));
        $body='<table class="hc-detail-table"><thead><tr><th scope="col">Surface</th><th scope="col">Arrive le (heure de Paris)</th><th scope="col">Source</th></tr></thead><tbody>';
        foreach($hunts as $h)$body.='<tr><td>'.e(hcGroup($h['area'])).' cm²</td><td>'.e(hcDate($h['arrival_at'])).'</td><td>'.($h['source']==='discord'?'Discord':'UPTEP+').'</td></tr>';
        $html.=hcSection('Chasses en cours',$body.'</tbody></table><p class="hc-detail-note">Total : '.e(hcGroup((string)$total)).' cm² à venir.</p>',count($hunts).' · '.$note);
    }
    // Floods in progress, sent and received
    foreach(['floods_out'=>['Floods envoyés en cours','Cible'],'floods_in'=>['Floods reçus en cours','Attaquant']] as $key=>[$heading,$who]){
        $list=$src[$key][$id]??[];
        if(!$list){$html.=hcSection($heading,'<p class="hc-detail-empty">Aucun flood en cours.</p>');continue;}
        $body='<table class="hc-detail-table"><thead><tr><th scope="col">'.e($who).'</th><th scope="col">Lieu</th><th scope="col">Troupes</th><th scope="col">Arrive le (heure de Paris)</th></tr></thead><tbody>';
        foreach($list as $f){
            $name=$key==='floods_out'?$f['target_pseudo']:$f['sender_pseudo'];
            $troops=$f['troops']===null||trim((string)$f['troops'])===''?'—':mb_strimwidth(trim((string)$f['troops']),0,200,'…');
            $body.='<tr><td>'.e((string)$name).'</td><td>'.e((string)$f['location']).'</td><td class="hc-detail-troops">'.e($troops).'</td><td>'.e(hcDate($f['arrival_at'])).'</td></tr>';
        }
        $html.=hcSection($heading,$body.'</tbody></table>',(string)count($list));
    }
    return $html;
}
