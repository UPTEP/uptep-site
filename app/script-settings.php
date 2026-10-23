<?php
declare(strict_types=1);
require_once __DIR__.'/stats.php';
require_once __DIR__.'/hourly-collection.php';
require_once __DIR__.'/script-hunts.php';
const SCRIPT_OPTIONS=['playerProfile'=>['Profils joueurs enrichis','Replie le profil et ajoute les statistiques UPTEP+ du joueur sur Membre.php, dans deux blocs fermés par défaut.'],'convoys'=>['Demandes de ressources et convois','Affiche les demandes et les envois sur Commerce, et permet de créer une demande sous les coûts des bâtiments sur Construction et des recherches sur Laboratoire.'],'floodDeclarations'=>['Déclaration des floods sur Armée','Affiche les boutons de déclaration et les badges des floods déjà enregistrés à côté des attaques en cours.'],'multiflood'=>['Multiflood','Affiche les cases de sélection, les boutons Multiflood et le calculateur pour préparer tes attaques depuis la page Membres.'],'traffic'=>['Floods et convois en cours','Affiche à droite du jeu tes envois et réceptions déclarés, jusqu’à leur arrivée.'],'laboratory'=>['Comparaison du laboratoire','Compare Armes et Bouclier à la ponte, avec tes bâtiments, technologies et ton armée enregistrés.'],'economy'=>['Ressources et ouvrières','Ressources relevées sur les pages du jeu, au maximum une fois toutes les 5 minutes ; ouvrières relevées à chaque visite de la page Reine.'],'army'=>['Collecte de l’armée','Enregistre, uniquement sur clic, le total de chaque unité sur le terrain, dans le dôme et dans la loge.'],'buildings'=>['Collecte des bâtiments','Enregistre tes niveaux quand tu visites Construction.'],'research'=>['Collecte du laboratoire','Enregistre tes niveaux quand tu visites Laboratoire.'],'hunts'=>['Collecte des chasses','Enregistre tes chasses en cours (surface et heure d’arrivée) quand tu visites la page Chasse ou Ressources.'],'chaine'=>['Chaîne dans le jeu','Affiche les grades, les positions et les cibles à portée sur la page Membres.'],'clock'=>['Horloge S5','Affiche l’heure synchronisée avec le serveur du jeu.'],'statistics'=>['Statistiques','Permet de consulter les fiches joueurs et alliances dans UPTEP+.']];
function scriptSettings(int $id): array {
 $defaults=array_replace(array_fill_keys(array_keys(SCRIPT_OPTIONS),true),['economy'=>false,'army'=>false,'research'=>false,'buildings'=>false,'hunts'=>false]);
 $q=db()->prepare('INSERT INTO script_settings(user_id,preferences) VALUES (?,?::jsonb) ON CONFLICT DO NOTHING');$q->execute([$id,json_encode($defaults,JSON_THROW_ON_ERROR)]);
 $q=db()->prepare('SELECT s.preferences,s.version,s.hunts_synced_at,u.buildings_synced_at,u.research_synced_at,u.army_synced_at,u.resources_synced_at,u.workers_synced_at FROM script_settings s LEFT JOIN user_stats u ON u.user_id=s.user_id WHERE s.user_id=?');$q->execute([$id]);$r=$q->fetch(PDO::FETCH_ASSOC);$prefs=array_replace($defaults,json_decode($r['preferences'],true,512,JSON_THROW_ON_ERROR));
 return ['preferences'=>$prefs,'version'=>(int)$r['version'],'synced'=>['buildings'=>$r['buildings_synced_at'],'research'=>$r['research_synced_at'],'army'=>$r['army_synced_at'],'economy'=>$r['resources_synced_at'],'resources'=>$r['resources_synced_at'],'workers'=>$r['workers_synced_at'],'hunts'=>$r['hunts_synced_at']],'options'=>SCRIPT_OPTIONS];
}
function scriptSettingsAction(array $actor,array $body): array {
 $id=(int)$actor['id'];$action=$body['action']??'';
 if($action==='settings')return scriptSettings($id);
 scriptSettings($id);$p=db();$p->beginTransaction();
 try{
  $q=$p->prepare("SELECT id FROM users WHERE id=? AND game_player_id=? AND status='active' AND script_enabled=true AND must_change_password=false FOR SHARE");$q->execute([$id,$actor['game_player_id']]);if(!$q->fetchColumn())throw new DomainException('Accès UPTEP+ désactivé.',403);
  if($action==='save-settings'){
   $prefs=$body['preferences']??null;$version=$body['version']??null;
   if(!is_array($prefs)||count($prefs)!==count(SCRIPT_OPTIONS)||!is_int($version))throw new DomainException('Paramètres invalides.',422);
   foreach(SCRIPT_OPTIONS as $key=>$unused)if(!array_key_exists($key,$prefs)||!is_bool($prefs[$key]))throw new DomainException('Paramètre invalide.',422);
   $q=$p->prepare('UPDATE script_settings SET preferences=?::jsonb,version=version+1,updated_at=now() WHERE user_id=? AND version=? RETURNING version');$q->execute([json_encode($prefs,JSON_THROW_ON_ERROR),$id,$version]);if(!$q->fetchColumn())throw new DomainException('Paramètres modifiés dans un autre onglet. Recharge-les avant de réessayer.',409);
  }elseif($action==='collect'){
   $group=$body['group']??null;$values=$body['values']??null;
   if($group==='workers'){
    $q=$p->prepare('SELECT preferences FROM script_settings WHERE user_id=? FOR UPDATE');$q->execute([$id]);$prefs=json_decode($q->fetchColumn(),true);
    if(($prefs['economy']??false)!==true)throw new DomainException('Collecte des ressources et ouvrières désactivée.',409);
    saveWorkersCollection($id,$body);$p->commit();return scriptSettings($id);
   }
   if($group==='economy'){
    $q=$p->prepare('SELECT preferences FROM script_settings WHERE user_id=? FOR UPDATE');$q->execute([$id]);$prefs=json_decode($q->fetchColumn(),true);
    if(($prefs['economy']??false)!==true)throw new DomainException('Collecte des ressources et ouvrières désactivée.',409);
    $collected=saveHourlyCollection($id,$body);$p->commit();return ['collected'=>$collected]+scriptSettings($id);
   }
   if($group==='hunts'||$group==='hunts_seen'){
    $q=$p->prepare('SELECT preferences FROM script_settings WHERE user_id=? FOR UPDATE');$q->execute([$id]);$prefs=json_decode($q->fetchColumn(),true);
    if(($prefs['hunts']??false)!==true)throw new DomainException('Collecte des chasses désactivée dans tes paramètres.',409);
    scriptHuntsSync($p,$id,(string)$actor['game_player_id'],$values,$group==='hunts');
    $q=$p->prepare('UPDATE script_settings SET hunts_synced_at=now() WHERE user_id=?');$q->execute([$id]);
    $p->commit();return scriptSettings($id);
   }
   if(!is_string($group)||!in_array($group,['buildings','research','army'],true)||!is_array($values)||!count($values)||count($values)>count(STATS_GROUPS[$group][1]))throw new DomainException('Relevé invalide.',422);
   $q=$p->prepare('SELECT preferences FROM script_settings WHERE user_id=? FOR SHARE');$q->execute([$id]);$prefs=json_decode($q->fetchColumn(),true);
   if(($prefs[$group]??false)!==true)throw new DomainException('Cette collecte est désactivée dans tes paramètres.',409);
   $clean=[];if($group==='army'&&count($values)!==14)throw new DomainException('Armée incomplète.',422);
   foreach($values as $key=>$value){
    if(!array_key_exists($key,STATS_GROUPS[$group][1]))throw new DomainException('Champ invalide.',422);
    if($group==='army'){$v=is_int($value)?(string)$value:$value;if(!is_string($v)||!preg_match('/^(0|[1-9][0-9]{0,18})$/D',$v)||strlen($v)>strlen((string)PHP_INT_MAX)||(strlen($v)===strlen((string)PHP_INT_MAX)&&strcmp($v,(string)PHP_INT_MAX)>0))throw new DomainException('Effectif invalide.',422);$clean[$key]=$v;}
    else{if(!is_int($value)||$value<0||$value>999)throw new DomainException('Niveau invalide.',422);$clean[$key]=(string)$value;}
   }
   // Merge only the collected fields; never erase army, resources or the other group.
   $column=$group.'_synced_at';
   $q=$p->prepare("INSERT INTO user_stats(user_id,data,$column) VALUES (?,jsonb_build_object(?::text,?::jsonb),now()) ON CONFLICT(user_id) DO UPDATE SET data=jsonb_set(user_stats.data,ARRAY[?::text],coalesce(user_stats.data->?::text,'{}'::jsonb)||?::jsonb),version=user_stats.version+1,updated_at=now(),$column=now()");$json=json_encode($clean,JSON_THROW_ON_ERROR);$q->execute([$id,$group,$json,$group,$group,$json]);
   if($group!=='army'){$q=$p->prepare("UPDATE script_settings SET $column=now() WHERE user_id=?");$q->execute([$id]);}
  }else throw new DomainException('Action inconnue.',422);
  $p->commit();return scriptSettings($id);
 }catch(Throwable $e){if($p->inTransaction())$p->rollBack();throw $e;}
}
