<?php
declare(strict_types=1);
require '/home/uptep/private/app/bootstrap.php';
require '/home/uptep/private/app/view.php';
require '/home/uptep/private/app/web-convoys.php';
require '/home/uptep/private/app/resource-activity.php';
$u=requireUser();$error='';$member=null;$confirmation=null;$data=[];
$tabs=['mes-convois'=>'Mes convois','floods'=>'Floods','chasses'=>'Chasses'];
$tab=is_string($_GET['vue']??null)?$_GET['vue']:'';
$success=$_SESSION['history_success']??null;unset($_SESSION['history_success']);
try {
    $member=convoyIdentity($u);
    if($member['reports']){
        $tabs=['convois'=>'Historique des convois','demandes'=>'Historique des demandes','recap-demandes'=>'Récapitulatif demandes']+$tabs;
    }
    if($tab==='')$tab=array_key_first($tabs);
    if(!isset($tabs[$tab]))throw new DomainException('Cette rubrique n’est pas accessible avec tes droits.',403);
    if($_SERVER['REQUEST_METHOD']==='POST'){
        if(!validCsrf())throw new DomainException('Le formulaire a expiré. Recharge la page.',403);
        if(isset($u['_preview']))throw new DomainException('Le mode visualisation est en lecture seule.',403);
        if(post('action')==='confirmer'){
            $key=post('confirmation');$job=$_SESSION['convoy_pending'][$key]??null;
            if(!$job||$job['action']!=='annulation_convoi'||$job['expires']<time()||$job['body']['discord_id']!==$member['discord_id'])throw new DomainException('Confirmation expirée. Recommence depuis le tableau.',409);
            [$status,$out]=botCancelConvoy(db(),db(true),$job['id'],$job['body']);
            if($out!==null){
                $_SESSION['history_success']='Convoi annulé. Le tableau Discord sera actualisé automatiquement.';
                redirect('/historiques.php?vue='.urlencode($tab));
            }
        }else{
            // This page only cancels convoys: other operations stay on convois.php.
            if(post('operation')!=='annulation_convoi')throw new DomainException('Action inconnue.',422);
            $job=convoyPrepare($member);$key=bin2hex(random_bytes(20));
            $_SESSION['convoy_pending']=array_filter($_SESSION['convoy_pending']??[],fn($v)=>$v['expires']>=time());
            if(count($_SESSION['convoy_pending'])>=30)array_shift($_SESSION['convoy_pending']);
            $_SESSION['convoy_pending'][$key]=$job;$confirmation=['key'=>$key]+$job;
        }
    }
    $page=is_string($_GET['page']??null)?$_GET['page']:'1';
    $after=is_string($_GET['apres']??null)?$_GET['apres']:'0';
    if($tab==='mes-convois'){
        $cursor=botNumber($after);
        // Newest first: the cursor is the oldest id already shown, so the next page goes below it.
        $q=db()->prepare('SELECT c.*,d.pseudo_demandeur FROM convois_effectues c JOIN demandes_convois d ON d.id=c.iddemande WHERE c.idenvoyeur=?'.($cursor==='0'?'':' AND c.id<?').' ORDER BY c.id DESC LIMIT 51');
        $q->execute($cursor==='0'?[$member['player_id']]:[$member['player_id'],$cursor]);$rows=$q->fetchAll(PDO::FETCH_ASSOC);$more=count($rows)>50;$rows=array_slice($rows,0,50);
        $data=['lignes'=>$rows,'suite_apres'=>$more?(string)end($rows)['id']:null];
    }elseif(in_array($tab,['convois','demandes','recap-demandes'],true)){
        $query=['discord_id'=>$member['discord_id'],'page'=>$page];
        if($tab!=='recap-demandes'){
            foreach(['periode','debut','fin','joueur','etat','expediteur','destinataire','demande'] as $key){
                if(is_string($_GET[$key]??null)&&$_GET[$key]!=='')$query[$key]=$_GET[$key];
            }
        }
        $data=botReports(db(),db(true),$tab,$query,null,$tab!=='recap-demandes',$u);
    }elseif($tab==='floods'){
        $pageNum=(int)botNumber($page,true);if($pageNum>10000)throw new DomainException('Page invalide.',422);
        $qPeriod=[];foreach(['periode','debut','fin'] as $k)if(is_string($_GET[$k]??null)&&$_GET[$k]!=='')$qPeriod[$k]=$_GET[$k];
        $period=botReportPeriod($qPeriod);$args=[$period['depuis'],$period['avant']];
        $where='f.arrival_at>=?::timestamptz AND f.arrival_at<?::timestamptz';
        foreach(['expediteur'=>'f.sender_pseudo','destinataire'=>'f.target_pseudo'] as $k=>$col){
            if(is_string($_GET[$k]??null)&&trim($_GET[$k])!==''){
                $val=trim($_GET[$k]);if(mb_strlen($val)>100)throw new DomainException('Pseudo invalide.',422);
                $where.=" AND lower($col)=lower(?)";$args[]=$val;
            }
        }
        $sql="SELECT f.id::text,f.sender_id::text,f.sender_pseudo,f.target_id::text,f.target_pseudo,f.location,f.troops,f.arrival_at,f.created_at FROM floods f WHERE $where ORDER BY f.arrival_at DESC,f.id DESC";
        $data=['periode'=>$period]+botReportRows(db(),$sql,$args,$pageNum,true,50);
    }elseif($tab==='chasses'){
        $pageNum=(int)botNumber($page,true);if($pageNum>10000)throw new DomainException('Page invalide.',422);
        $qPeriod=[];foreach(['periode','debut','fin'] as $k)if(is_string($_GET[$k]??null)&&$_GET[$k]!=='')$qPeriod[$k]=$_GET[$k];
        $period=botReportPeriod($qPeriod);$args=[$period['depuis'],$period['avant']];
        $where='h.cancelled_at IS NULL AND h.arrival_at>=?::timestamptz AND h.arrival_at<?::timestamptz';
        if(is_string($_GET['joueur']??null)&&trim($_GET['joueur'])!==''){
            $val=trim($_GET['joueur']);if(mb_strlen($val)>100)throw new DomainException('Pseudo invalide.',422);
            $qP=db(true)->prepare("SELECT player_id::text FROM players WHERE server='s5' AND lower(pseudo)=lower(?)");
            $qP->execute([$val]);$pids=$qP->fetchAll(PDO::FETCH_COLUMN);
            if(!$pids){$qC=db()->prepare("SELECT player_id::text FROM chaine_members WHERE server='s5' AND lower(pseudo)=lower(?)");$qC->execute([$val]);$pids=$qC->fetchAll(PDO::FETCH_COLUMN);}
            if(!$pids){$where.=' AND false';}
            else{$where.=' AND h.player_id IN ('.implode(',',array_fill(0,count($pids),'?')).')';$args=array_merge($args,$pids);}
        }
        // The game only shows a countdown: first_seen_at, read by UPTEP+ right after the launch, stands for it.
        $sql="SELECT h.id::text,h.player_id::text,h.area::text,h.first_seen_at,h.arrival_at,h.source FROM script_hunts h WHERE $where ORDER BY h.arrival_at DESC,h.id DESC";
        $res=botReportRows(db(),$sql,$args,$pageNum,true,50);
        if($res['lignes']){
            $ids=array_values(array_unique(array_column($res['lignes'],'player_id')));$names=[];
            $qN=db(true)->prepare("SELECT player_id::text,pseudo FROM players WHERE server='s5' AND player_id IN (".implode(',',array_fill(0,count($ids),'?')).")");
            $qN->execute($ids);foreach($qN->fetchAll(PDO::FETCH_ASSOC) as $r)$names[$r['player_id']]=$r['pseudo'];
            foreach($res['lignes'] as &$row)$row['pseudo']=$names[$row['player_id']]??('Joueur '.$row['player_id']);
            unset($row);
        }
        $data=['periode'=>$period]+$res;
    }
}catch(DomainException $ex){http_response_code($ex->getCode()?:422);$error=$ex->getMessage();}
catch(Throwable $ex){error_log('UPTEP web history: '.$ex->getMessage());http_response_code(503);$error='Les historiques sont momentanément indisponibles. Réessaie dans quelques instants.';}

function historyHidden(string $action,?array $d=null): void { ?>
<input type="hidden" name="csrf" value="<?= e(csrf()) ?>"><input type="hidden" name="action" value="preparer"><input type="hidden" name="operation" value="<?= e($action) ?>"><?php if($d): ?><input type="hidden" name="id" value="<?= e((string)$d['id']) ?>"><?php endif; ?>
<?php }
function historyLink(array $replace,string $label): void {$q=array_merge($_GET,$replace); ?><a class="save-user" href="/historiques.php?<?= e(http_build_query($q)) ?>"><?= e($label) ?></a><?php }
function historyConvoyTable(array $rows,array $member): void { ?>
<div class="users-scroll"><table class="users-table"><thead><tr><th>Convoi / demande</th><th>Expéditeur</th><th>Destinataire</th><th>Pommes</th><th>Matériaux</th><th>Départ</th><th>Arrivée</th><th>État</th><th>Action</th></tr></thead><tbody><?php foreach($rows as $c): ?><tr><td>#<?= e((string)$c['id']) ?> · <a href="/convois.php?id=<?= e((string)$c['iddemande']) ?>">demande #<?= e((string)$c['iddemande']) ?></a></td><td><?= e($c['pseudo_envoyeur']) ?></td><td><?= e($c['pseudo_demandeur']??'—') ?></td><td><?= convoyAmount($c['nbpomme']) ?></td><td><?= convoyAmount($c['nbmateriaux']) ?></td><td><?= e(convoyDate($c['date_convoi'])) ?></td><td><?= e(convoyDate($c['date_arrivee'])) ?></td><td><?= $c['annule']?'Annulé':(isset($c['date_arrivee'])&&strtotime((string)$c['date_arrivee'])>time()?'En cours':'Déclaré') ?></td><td><?php if(!$c['annule'] && ($member['is_chaine']||(string)$c['idenvoyeur']===$member['player_id'])): ?><form method="post"><?php historyHidden('annulation_convoi',['id'=>(string)$c['id']]); ?><button class="save-user">Annuler</button></form><?php endif; ?></td></tr><?php endforeach; ?></tbody></table><?php if(!$rows): ?><p class="chaine-empty">Aucun convoi.</p><?php endif; ?></div>
<?php }

workspaceStart($u,'Historiques'); ?>
<div class="convoy-page"><h1>Historiques</h1><p class="admin-intro">Consulte l’historique des convois, demandes, floods et chasses de l’alliance.</p>
<?php if($success): ?><div class="admin-success" role="status"><?= e($success) ?></div><?php endif; ?>
<?php if($error): ?><div class="form-error" role="alert"><?= e($error) ?></div><?php endif; ?>
<?php if($member): ?>
<nav class="convoy-tabs" aria-label="Historiques"><?php foreach($tabs as $value=>$label): ?><a href="/historiques.php?vue=<?= e($value) ?>" <?= $value===$tab?'aria-current="page"':'' ?>><?= e($label) ?></a><?php endforeach; ?></nav>
<?php if($confirmation): ?>
<section class="users-panel convoy-card"><h2>Confirmer l’annulation</h2><p><strong>Annuler le convoi #<?= e((string)$confirmation['id']) ?></strong></p><form method="post"><input type="hidden" name="csrf" value="<?= e(csrf()) ?>"><input type="hidden" name="action" value="confirmer"><input type="hidden" name="confirmation" value="<?= e($confirmation['key']) ?>"><button class="save-user" type="submit">Confirmer</button> <a href="/historiques.php?vue=<?= e($tab) ?>">Abandonner</a></form></section>
<?php elseif($tab==='mes-convois'): ?>
<section class="users-panel convoy-card"><h2>Mes convois</h2><?php historyConvoyTable($data['lignes']??[],$member); ?><?php if($data['suite_apres']??null)historyLink(['apres'=>$data['suite_apres']],'Convois suivants'); ?></section>
<?php else: ?>
<?php if($tab!=='recap-demandes'): ?>
<form method="get" class="convoy-filters"><input type="hidden" name="vue" value="<?= e($tab) ?>"><label>Période<select name="periode"><?php foreach(['7j'=>'7 derniers jours','30j'=>'30 derniers jours','personnalisee'=>'Dates personnalisées'] as $v=>$l): ?><option value="<?= e($v) ?>" <?= ($_GET['periode']??'7j')===$v?'selected':'' ?>><?= e($l) ?></option><?php endforeach; ?></select></label><label>Du (JJ-MM-AAAA)<input name="debut" value="<?= e(is_string($_GET['debut']??null)?$_GET['debut']:'') ?>" placeholder="01-10-2026"></label><label>Au (JJ-MM-AAAA)<input name="fin" value="<?= e(is_string($_GET['fin']??null)?$_GET['fin']:'') ?>" placeholder="14-10-2026"></label>
<?php
$filterFields=match($tab){
    'convois'=>['expediteur'=>'Expéditeur','destinataire'=>'Destinataire','demande'=>'N° demande'],
    'floods'=>['expediteur'=>'Attaquant','destinataire'=>'Cible'],
    default=>['joueur'=>'Pseudo du joueur']
};
foreach($filterFields as $key=>$label): ?><label><?= e($label) ?><input name="<?= e($key) ?>" value="<?= e(is_string($_GET[$key]??null)?$_GET[$key]:'') ?>"></label><?php endforeach; ?>
<?php if($tab==='demandes'): ?><label>État<select name="etat"><?php foreach(['toutes'=>'Tous','attente'=>'En attente','en_cours'=>'En cours','terminee'=>'Terminées','annulee'=>'Annulées'] as $v=>$l): ?><option value="<?= e($v) ?>" <?= ($_GET['etat']??'')===$v?'selected':'' ?>><?= e($l) ?></option><?php endforeach; ?></select></label><?php endif; ?>
<button class="save-user">Afficher</button></form>
<?php endif; ?>
<?php if(isset($data['periode'])): ?><p>Du <?= e($data['periode']['debut']) ?> au <?= e($data['periode']['fin']) ?> inclus · heures de Paris</p><?php endif; ?>
<?php if($tab==='convois'): ?>
<section class="users-panel convoy-card"><?php historyConvoyTable($data['lignes']??[],$member); ?></section>
<?php elseif($tab==='floods'): ?>
<div class="users-panel users-scroll"><table class="users-table"><thead><tr><th>#</th><th>Attaquant</th><th>Cible</th><th>Lieu</th><th>Troupes</th><th>Arrivée</th><th>Déclaré le</th></tr></thead><tbody>
<?php foreach($data['lignes']??[] as $row): ?><tr><td>#<?= e((string)$row['id']) ?></td><td><?= e($row['sender_pseudo']) ?></td><td><?= e($row['target_pseudo']) ?></td><td><?= e($row['location']??'—') ?></td><td><?php $troops=resourceTroops($row['troops']); ?><?= $troops===null?'Non renseignées':convoyAmount((string)(int)$troops) ?></td><td><?= e(convoyDate($row['arrival_at'])) ?></td><td><?= e(convoyDate($row['created_at'])) ?></td></tr><?php endforeach; ?>
</tbody></table><?php if(empty($data['lignes'])): ?><p class="chaine-empty">Aucun flood sur cette période.</p><?php endif; ?></div>
<?php elseif($tab==='chasses'): ?>
<div class="users-panel users-scroll"><table class="users-table"><thead><tr><th>#</th><th>Joueur</th><th>Surface chassée</th><th>Lancement</th><th>Arrivée</th><th>Source</th></tr></thead><tbody>
<?php foreach($data['lignes']??[] as $row): ?><tr><td>#<?= e((string)$row['id']) ?></td><td><?= e($row['pseudo']) ?></td><td><?= convoyAmount($row['area']) ?> cm²</td><td><?= e(convoyDate($row['first_seen_at'])) ?></td><td><?= e(convoyDate($row['arrival_at'])) ?></td><td><?= e(($row['source']??'')==='discord'?'Discord':'UPTEP+') ?></td></tr><?php endforeach; ?>
</tbody></table><?php if(empty($data['lignes'])): ?><p class="chaine-empty">Aucune chasse sur cette période.</p><?php endif; ?></div>
<p>Lancement : heure à laquelle la chasse a été relevée la première fois, par UPTEP+ ou sur Discord. Elle correspond au lancement quand la chasse est lancée avec UPTEP+ actif, ou déclarée aussitôt sur Discord.</p>
<?php else:
$columns=$tab==='demandes'?['id'=>'Demande','pseudo_demandeur'=>'Joueur','objet'=>'Objet','niveau'=>'Niveau','auto_production'=>'Auto production','nbpomme_original'=>'Pommes demandées','nbmateriaux_original'=>'Matériaux demandés','nbpomme_restant'=>'Pommes restantes','nbmateriaux_restant'=>'Matériaux restants','etat'=>'État','date_demande'=>'Date']:['pseudo'=>'Joueur','demandes_effectuees'=>'Demandes','demandes_validees'=>'Validées','demandes_terminees'=>'Terminées','demandes_annulees'=>'Annulées','derniere_demande'=>'Dernière demande']; ?>
<div class="users-panel users-scroll"><table class="users-table"><thead><tr><?php foreach($columns as $label): ?><th><?= e($label) ?></th><?php endforeach; ?></tr></thead><tbody><?php foreach($data['lignes']??[] as $row): ?><tr><?php foreach($columns as $key=>$label): ?><td><?php if($key==='id'): ?><a href="/convois.php?id=<?= e((string)$row[$key]) ?>">#<?= e((string)$row[$key]) ?></a><?php elseif($key==='auto_production'): ?><?= ($row[$key]??false)?'Auto production':'—' ?><?php elseif(in_array($key,['date_demande','derniere_demande'],true)): ?><?= e(convoyDate($row[$key])) ?><?php elseif(str_starts_with($key,'nb')): ?><?= convoyAmount($row[$key]) ?><?php else: ?><?= e((string)($row[$key]??'—')) ?><?php endif; ?></td><?php endforeach; ?></tr><?php endforeach; ?></tbody></table><?php if(empty($data['lignes'])): ?><p class="chaine-empty">Aucun résultat pour ces critères.</p><?php endif; ?></div>
<?php endif; ?>
<?php if($tab!=='recap-demandes' && isset($data['page'],$data['pages'])): ?><nav class="convoy-pagination" aria-label="Pagination"><span>Page <?= (int)$data['page'] ?> / <?= (int)$data['pages'] ?> · <?= (int)$data['total'] ?> résultats</span><?php if($data['page']>1)historyLink(['page'=>(string)($data['page']-1)],'Précédente'); ?><?php if($data['page']<$data['pages'])historyLink(['page'=>(string)($data['page']+1)],'Suivante'); ?></nav><?php endif; ?>
<?php endif; ?>
<?php endif; ?></div><script src="/assets/convoy-tables.js?v=1" defer></script><?php workspaceEnd(); ?>