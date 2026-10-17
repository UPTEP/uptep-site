<?php
declare(strict_types=1);
// Reporting is deliberately stricter than convoy administration: the private allowlist is mandatory.
function botReportAccess(PDO $p,PDO $game,array $query): void {
    $id=botNumber($query['discord_id']??null,true);
    if(!in_array($id,botConfiguredAdmins(),true))throw new DomainException('Ces historiques et statistiques sont réservés aux chefs listés dans ADMIN_DISCORD_IDS.',403);
    botActor($p,$game,$id,false);
}
// Website reports use the effective profile and recheck the trusted account binding.
function botSiteReportRights(array $u): bool {
    return ($u['role']??'')==='admin' || ($u['is_chef']??false)===true;
}
function botSiteReportAccess(PDO $p,PDO $game,array $query,array $u): void {
    $discord=botNumber($query['discord_id']??null,true);
    $legacy=!isset($u['_preview']) && in_array($discord,botConfiguredAdmins(),true);
    if(!botSiteReportRights($u) && !$legacy)throw new DomainException('Les historiques, statistiques et récapitulatifs sont réservés aux chefs et administrateurs.',403);
    $q=$p->prepare('SELECT role,is_chef,status,must_change_password,discord_login_id::text FROM users WHERE id=?');$q->execute([$u['id']??0]);$actual=$q->fetch(PDO::FETCH_ASSOC);
    if(!$actual || $actual['status']!=='active' || $actual['must_change_password'] || $actual['discord_login_id']!==$discord || (!botSiteReportRights($actual) && !in_array($discord,botConfiguredAdmins(),true)))throw new DomainException('Tes droits ou ton association Discord ont changé. Recharge la page.',403);
    botActor($p,$game,$discord,false);
}
function botReportPeriod(array $query,?DateTimeImmutable $now=null): array {
    $tz=new DateTimeZone('Europe/Paris');$today=($now??new DateTimeImmutable('now',$tz))->setTimezone($tz)->setTime(0,0);
    $period=$query['periode']??'7j';
    if(!in_array($period,['7j','30j','personnalisee'],true))throw new DomainException('Période attendue : 7j, 30j ou personnalisee.',422);
    if(isset($query['debut'])||isset($query['fin']))$period='personnalisee';
    if($period==='personnalisee'){
        $dates=[];
        foreach(['debut','fin'] as $key){$raw=$query[$key]??null;
            if(!is_string($raw)||!preg_match('/\A\d{2}-\d{2}-\d{4}\z/',$raw))throw new DomainException('Renseigne début et fin au format JJ-MM-AAAA.',422);
            $date=DateTimeImmutable::createFromFormat('!d-m-Y',$raw,$tz);
            if(!$date||$date->format('d-m-Y')!==$raw)throw new DomainException('Date de période invalide.',422);
            $dates[]=$date;
        }
        [$start,$end]=$dates;
    }else{$end=$today;$start=$today->modify($period==='30j'?'-29 days':'-6 days');}
    if($start>$end||$end>$today)throw new DomainException('La période doit être passée ou en cours, avec début avant fin.',422);
    $days=(int)$start->diff($end)->days+1;
    if($days>366)throw new DomainException('La période est limitée à 366 jours.',422);
    return ['debut'=>$start->format('d-m-Y'),'fin'=>$end->format('d-m-Y'),'jours'=>$days,'fuseau'=>'Europe/Paris','depuis'=>$start->format('c'),'avant'=>$end->modify('+1 day')->format('c')];
}
function botReportRows(PDO $p,string $sql,array $args,int $page,bool $paginate=true,int $pageSize=8): array {
    if(!$paginate)$page=1;
    $q=$p->prepare("SELECT report.*,count(*) OVER()::text AS total_lignes FROM ($sql) report".($paginate?" LIMIT ".$pageSize." OFFSET ".(($page-1)*$pageSize):""));$q->execute($args);$rows=$q->fetchAll(PDO::FETCH_ASSOC);
    $total=isset($rows[0])?(int)$rows[0]['total_lignes']:0;
    // An empty late page can occur when the period changes at midnight or data is removed.
    if(!$rows&&$page>1){$q=$p->prepare("SELECT count(*) FROM ($sql) report");$q->execute($args);$total=(int)$q->fetchColumn();}
    foreach($rows as &$r){unset($r['total_lignes']);foreach($r as $key=>&$value){if($value!==null&&preg_match('/^(?:id|iddemande|idenvoyeur|iddemandeur|idvalidateur|idannulateur|discord_.*|nbpomme.*|nbmateriaux.*)$/',$key))$value=(string)$value;}unset($value);}unset($r);
    return ['lignes'=>$rows,'page'=>$page,'pages'=>$paginate?max(1,(int)ceil($total/$pageSize)):1,'total'=>$total];
}
function botReports(PDO $p,PDO $game,string $kind,array $query,?string $id=null,bool $paginate=true,?array $siteUser=null): array {
    if($siteUser===null)botReportAccess($p,$game,$query);else botSiteReportAccess($p,$game,$query,$siteUser);
    $pageSize=$siteUser!==null?50:8;
    $allowed=$kind==='recap-demandes'?['discord_id','page']:['discord_id','periode','debut','fin','page'];
    $allowed=array_merge($allowed,match($kind){'demandes'=>['joueur','etat'],'convois'=>['expediteur','destinataire','demande'],'statistiques'=>['joueur'],default=>[]});
    if(array_diff(array_keys($query),$allowed))throw new DomainException('Paramètre de rapport inconnu.',422);
    $page=botNumber($query['page']??'1',true);if((int)$page>10000)throw new DomainException('Page invalide.',422);$page=(int)$page;
    if($kind==='recap-demandes'){
        $sql="WITH recap AS (
            SELECT iddemandeur AS id_joueur,
                (array_agg(pseudo_demandeur ORDER BY date_demande DESC,id DESC))[1] AS pseudo,
                count(*) AS demandes_effectuees,
                count(*) FILTER (WHERE valide AND NOT annulee) AS demandes_validees,
                count(*) FILTER (WHERE fini) AS demandes_terminees,
                count(*) FILTER (WHERE annulee) AS demandes_annulees,
                max(date_demande) AS derniere_demande
            FROM demandes_convois WHERE serveur='s5' GROUP BY iddemandeur
        ), joueurs AS (SELECT player_id,pseudo FROM chaine_members WHERE server='s5')
        SELECT coalesce(j.player_id,r.id_joueur)::text AS id_joueur,
            coalesce(nullif(j.pseudo,''),r.pseudo,'Joueur '||j.player_id::text) AS pseudo,
            coalesce(r.demandes_effectuees,0)::text AS demandes_effectuees,
            coalesce(r.demandes_validees,0)::text AS demandes_validees,
            coalesce(r.demandes_terminees,0)::text AS demandes_terminees,
            coalesce(r.demandes_annulees,0)::text AS demandes_annulees,
            r.derniere_demande
        FROM joueurs j FULL OUTER JOIN recap r ON r.id_joueur=j.player_id
        ORDER BY coalesce(r.demandes_effectuees,0) DESC,coalesce(nullif(j.pseudo,''),r.pseudo,'Joueur '||j.player_id::text),coalesce(j.player_id,r.id_joueur)";
        return ['ok'=>true]+botReportRows($p,$sql,[],$page,$paginate,$pageSize);
    }
    if($id!==null){
        $d=botDemand($p,botNumber($id,true));
        return ['ok'=>true,'demande'=>$d]+botReportRows($p,'SELECT c.* FROM convois_effectues c WHERE c.iddemande=? ORDER BY c.date_convoi DESC,c.id DESC',[$id],$page,true,$pageSize);
    }
    $period=botReportPeriod($query);$args=[$period['depuis'],$period['avant']];
    $filter=function(string $key,string $column)use($query,&$args):string {
        if(!isset($query[$key]))return '';
        if(!is_string($query[$key])||trim($query[$key])===''||mb_strlen($query[$key])>100)throw new DomainException('Pseudo invalide.',422);
        $args[]=trim($query[$key]);return " AND lower($column)=lower(?)";
    };
    if($kind==='demandes'){
        $where='d.date_demande>=?::timestamptz AND d.date_demande<?::timestamptz'.$filter('joueur','d.pseudo_demandeur');
        $state=$query['etat']??'toutes';
        $conditions=['toutes'=>'true','attente'=>'NOT d.annulee AND NOT d.valide','en_cours'=>'NOT d.annulee AND d.valide AND NOT d.fini','terminee'=>'d.fini','annulee'=>'d.annulee'];
        if(!is_string($state)||!isset($conditions[$state]))throw new DomainException('État de demande invalide.',422);
        $sql="SELECT d.*,CASE WHEN d.annulee THEN 'annulee' WHEN d.fini THEN 'terminee' WHEN d.valide THEN 'en_cours' ELSE 'attente' END AS etat FROM demandes_convois d WHERE $where AND ".$conditions[$state].' ORDER BY d.date_demande DESC,d.id DESC';
    }elseif($kind==='convois'){
        $where='c.date_convoi>=?::timestamptz AND c.date_convoi<?::timestamptz'.$filter('expediteur','c.pseudo_envoyeur').$filter('destinataire','d.pseudo_demandeur');
        if(isset($query['demande'])){$where.=' AND c.iddemande=?';$args[]=botNumber($query['demande'],true);}
        $sql="SELECT c.*,d.pseudo_demandeur FROM convois_effectues c JOIN demandes_convois d ON d.id=c.iddemande WHERE $where ORDER BY c.date_convoi DESC,c.id DESC";
    }else{
        // Numeric SUM stays exact even when totals exceed bigint. Cancelled declarations never count.
        $where='c.date_convoi>=?::timestamptz AND c.date_convoi<?::timestamptz AND NOT c.annule';
        $cte="WITH sends AS (SELECT c.*,d.iddemandeur,d.pseudo_demandeur FROM convois_effectues c JOIN demandes_convois d ON d.id=c.iddemande WHERE $where), movements AS (
            SELECT idenvoyeur AS joueur_id,pseudo_envoyeur AS pseudo,date_convoi,id,nbpomme::numeric AS pommes_envoyees,nbmateriaux::numeric AS materiaux_envoyes,0::numeric AS pommes_recues,0::numeric AS materiaux_recus,1 AS envois,0 AS receptions FROM sends
            UNION ALL SELECT iddemandeur,pseudo_demandeur,date_convoi,id,0,0,nbpomme,nbmateriaux,0,1 FROM sends), grouped AS (
            SELECT joueur_id,(array_agg(pseudo ORDER BY date_convoi DESC,id DESC))[1] AS pseudo,sum(pommes_envoyees) AS pommes_envoyees,sum(materiaux_envoyes) AS materiaux_envoyes,sum(pommes_recues) AS pommes_recues,sum(materiaux_recus) AS materiaux_recus,sum(envois) AS envois,sum(receptions) AS receptions FROM movements GROUP BY joueur_id)
            SELECT joueur_id::text,pseudo,envois::text,receptions::text";
        foreach(['pommes_envoyees','materiaux_envoyes','pommes_recues','materiaux_recus'] as $col)$cte.=",$col::text,round($col/".$period['jours'].",2)::text AS moyenne_$col";
        $sql=$cte.' FROM grouped WHERE true'.$filter('joueur','pseudo').' ORDER BY (pommes_envoyees::numeric+materiaux_envoyes::numeric) DESC,joueur_id';
    }
    return ['ok'=>true,'periode'=>$period]+botReportRows($p,$sql,$args,$page,$kind!=='statistiques'||$paginate,$pageSize);
}
