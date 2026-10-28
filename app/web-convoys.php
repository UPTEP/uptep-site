<?php
declare(strict_types=1);
require_once __DIR__.'/bot-convoys.php';
require_once __DIR__.'/bot-deliveries.php';
require_once __DIR__.'/bot-reports.php';
function convoyIdentity(array $u): array {
    $q=db()->prepare('SELECT discord_login_id::text FROM users WHERE id=?');$q->execute([$u['id']]);$discord=$q->fetchColumn();
    if(!$discord)throw new DomainException('Ton compte doit être associé à ton ID Discord par un administrateur pour utiliser les demandes et convois.',403);
    $member=botMember(db(),db(true),$discord)['membre'];
    if(isset($u['_preview']) && $u['role']!=='admin')$member['is_chaine']=(bool)$u['is_chaine'] && $member['is_chaine'];
    $member['suspend']=in_array($discord,botConfiguredAdmins(),true) && (!isset($u['_preview']) || $u['role']==='admin');
    $member['reports']=botSiteReportRights($u) || $member['suspend'];
    return $member;
}
function convoyQuantity(string $raw): string {
    $raw=strtolower(preg_replace('/[\s\x{00a0}\x{202f}]+/u','',trim($raw)));
    if(!preg_match('/\A(\d+)(?:[.,](\d+))?([kmg]?)\z/',$raw,$m))throw new DomainException('Quantité invalide : utilise un entier ou 1,5k / 2m / 3g.',422);
    $power=[''=>0,'k'=>3,'m'=>6,'g'=>9][$m[3]??''];$fraction=$m[2]??'';
    if(strlen($fraction)>$power && trim(substr($fraction,$power),'0')!=='')throw new DomainException('Les ressources doivent être des quantités entières.',422);
    $value=ltrim($m[1].str_pad(substr($fraction,0,$power),$power,'0'),'0');
    return botNumber($value===''?'0':$value);
}
function convoyDuration(string $raw): int {
    $raw=preg_replace('/\s+/u','',strtolower(trim($raw)));
    if(!preg_match('/\A(?:(\d+)j)?(?:(\d+)h)?(?:(\d+)m)?(?:(\d+)s)?\z/',$raw,$m)||$raw==='')throw new DomainException('Durée attendue : par exemple 2h30m ou 45m.',422);
    $seconds=0;foreach([1=>86400,2=>3600,3=>60,4=>1] as $i=>$factor)$seconds+=(float)($m[$i]??0)*$factor;
    if($seconds>366*86400)throw new DomainException('La durée est limitée à 366 jours.',422);
    return (int)$seconds;
}
function convoyPrepare(array $m): array {
    $action=post('operation');
    if(!in_array($action,['demande','modification','ajustement','validation','suspension','annulation','terminaison','convoi','annulation_convoi'],true))throw new DomainException('Action inconnue.',422);
    $id=$action==='demande'?null:botNumber(post('id'),true);
    $body=['commande_id'=>'web:'.bin2hex(random_bytes(24)),'discord_id'=>$m['discord_id']];
    if(in_array($action,['demande','modification','convoi'],true)){
        $body['nbpomme']=convoyQuantity(post('pommes'));$body['nbmateriaux']=convoyQuantity(post('materiaux'));
    }
    if(in_array($action,['demande','modification'],true)){
        $body['objet']=trim(post('objet'));$body['niveau']=post('niveau');$body['date_souhaitee']=post('date_souhaitee')?:null;
    }
    if(in_array($action,['demande','modification','ajustement'],true))$body['auto_production']=post('auto_production')==='1';
    if(in_array($action,['validation','modification','ajustement'],true))$body['version_attendue']=post('version');
    if($action==='ajustement'){
        $body+=['nbpomme_restant'=>convoyQuantity(post('pommes')),'nbmateriaux_restant'=>convoyQuantity(post('materiaux')),'nbpomme_attendu'=>post('pommes_attendu'),'nbmateriaux_attendu'=>post('materiaux_attendu')];
    }
    if($action==='convoi'){
        $now=new DateTimeImmutable('now',new DateTimeZone('UTC'));$duration=post('duree');
        if(post('rapport')!==''){
            $d=botDemand(db(),$id);$report=preg_replace('/\s+/u',' ',str_replace('**','',trim(post('rapport'))));
            if(!preg_match('/\A(?:-\s*)?Vous allez livrer (\d[\d ]*)(?: nourritures?)? et (\d[\d ]*)(?: mat[eé]riaux?)? [àa] (.+?) dans ((?:\d+\s*[jHhms]\s*)+)(?:[·•]\s*arriv[ée]e\s+[^-·•]*)?(?:\s*-\s*Retour le [^-]+)?(?:\s*-\s*Annuler)?(?:\s*-)?\s*\z/u',$report,$match))throw new DomainException('Rapport de convoi non reconnu. Colle la ligne « Vous allez livrer… ».',422);
            if(mb_strtolower(trim($match[3]))!==mb_strtolower($d['pseudo_demandeur']))throw new DomainException('Le destinataire du rapport ne correspond pas à cette demande.',422);
            $body['nbpomme']=convoyQuantity($match[1]);$body['nbmateriaux']=convoyQuantity($match[2]);$duration=$match[4];
        }
        $body['date_convoi']=$now->format('c');$body['date_arrivee']=$now->modify('+'.convoyDuration($duration).' seconds')->format('c');
    }
    $excess=$action==='convoi'?botConvoyOverage(botDemand(db(),$id),$body['nbpomme'],$body['nbmateriaux']):null;
    return ['overage'=>$excess,'action'=>$action,'id'=>$id,'body'=>$body,'auto_warning'=>$action==='convoi'&&(bool)botDemand(db(),$id)['auto_production'],'expires'=>time()+3600];
}
function convoyEvent(PDO $p,string $kind,array $payload): void {
    $q=$p->prepare('INSERT INTO convoy_web_events(kind,payload) VALUES(?,?::jsonb)');$q->execute([$kind,json_encode($payload,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE)]);
}
function convoyDate(?string $raw): string {
    return $raw?(new DateTimeImmutable($raw))->setTimezone(new DateTimeZone('Europe/Paris'))->format('d/m/Y H:i'):'—';
}
function convoyAmount(mixed $n): string {return preg_replace('/(?<=\d)(?=(?:\d{3})+$)/u',' ',(string)$n);}

function convoyAutoBadge(array $d): void {if($d['auto_production']??false)echo '<span class="convoy-auto-badge">Auto production</span>';}
