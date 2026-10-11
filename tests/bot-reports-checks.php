<?php
require __DIR__.'/bot-reports-service.php';
$fixed=new DateTimeImmutable('2026-10-11T10:00:00+02:00');
check(botReportPeriod([],$fixed)['jours']===7,'default seven calendar days');
check(botReportPeriod(['debut'=>'24-10-2026','fin'=>'26-10-2026'],new DateTimeImmutable('2026-10-27'))['avant']==='2026-10-27T00:00:00+01:00','Paris DST inclusive end');
rejects(fn()=>botReportPeriod(['debut'=>'31-02-2026','fin'=>'01-03-2026'],$fixed),422);
rejects(fn()=>botReportPeriod(['debut'=>'01-10-2026'],$fixed),422);
rejects(fn()=>botReportPeriod(['debut'=>'01-01-2024','fin'=>'11-10-2026'],$fixed),422);
rejects(fn()=>botReportPeriod(['debut'=>'12-10-2026','fin'=>'13-10-2026'],$fixed),422);
file_put_contents(UPTEP_BOT_ENV_FILE,"ADMIN_DISCORD_IDS=102,104,999\n");
rejects(fn()=>botReports($p,$p,'demandes',['discord_id'=>'103']),403); // chain permission alone must not grant reports
rejects(fn()=>botReports($p,$p,'demandes',['discord_id'=>'104']),403); // left alliance
rejects(fn()=>botReports($p,$p,'demandes',['discord_id'=>'999']),403); // no association
for($n=0;$n<10;$n++)botConvoyAction($p,$p,'demande',null,array_replace($create,['commande_id'=>'report-page-'.$n]));
$r=botReports($p,$p,'demandes',['discord_id'=>'102']);check($r['total']>8&&count($r['lignes'])===8&&$r['pages']>1,'history pagination: '.json_encode([$r['total'],count($r['lignes']),$r['pages']]));
$next=botReports($p,$p,'demandes',['discord_id'=>'102','page'=>'2']);check(!array_intersect(array_column($r['lignes'],'id'),array_column($next['lignes'],'id')),'distinct pages');
$cancelled=botReports($p,$p,'demandes',['discord_id'=>'102','etat'=>'annulee']);check($cancelled['total']>0&&$cancelled['lignes'][0]['annulee'],'cancelled request history');
$detail=botReports($p,$p,'demandes',['discord_id'=>'102'],$id);check($detail['demande']['id']===$id&&$detail['total']===2,'completed request detail');
$all=botReports($p,$p,'convois',['discord_id'=>'102']);check($all['total']>0,'convoy history');
$stats=botReports($p,$p,'statistiques',['discord_id'=>'102']);check($stats['total']>=2,'stats include sender and recipient');
foreach($stats['lignes'] as $row){
 $q=$p->prepare('SELECT coalesce(sum(nbpomme),0)::text FROM convois_effectues WHERE idenvoyeur=? AND NOT annule AND date_convoi>=? AND date_convoi<?');$q->execute([$row['joueur_id'],$stats['periode']['depuis'],$stats['periode']['avant']]);
 check($row['pommes_envoyees']===$q->fetchColumn(),'exact sums excluding cancellation');
 $q=$p->prepare('SELECT round(?::numeric/7,2)::text');$q->execute([$row['pommes_envoyees']]);check($row['moyenne_pommes_envoyees']===$q->fetchColumn(),'average includes inactive days');
}
// Two maximum bigint sends exercise SUM beyond signed 64-bit without float conversion.
for($n=0;$n<2;$n++){
 $large=botConvoyAction($p,$p,'demande',null,array_replace($create,['commande_id'=>'report-big-'.$n,'nbpomme'=>'9223372036854775807','nbmateriaux'=>'0']))[1]['demande']['id'];
 botConvoyAction($p,$p,'validation',$large,['commande_id'=>'report-big-valid-'.$n,'discord_id'=>'102']);
 botConvoyAction($p,$p,'convoi',$large,['commande_id'=>'report-big-send-'.$n,'discord_id'=>'102','nbpomme'=>'9223372036854775807','nbmateriaux'=>'0']);
}
$largeStats=botReports($p,$p,'statistiques',['discord_id'=>'102','joueur'=>'Sender']);
check(strlen($largeStats['lignes'][0]['pommes_envoyees'])>=20,'totals exceed bigint exactly as strings');
$old=(new DateTimeImmutable($stats['periode']['depuis']))->modify('-1 second')->format('Y-m-d H:i:sP');
$q=$p->prepare('UPDATE convois_effectues SET date_convoi=? WHERE iddemande=?');$q->execute([$old,$large]);
$expected=$p->prepare('SELECT sum(nbpomme)::text FROM convois_effectues WHERE idenvoyeur=2 AND NOT annule AND date_convoi>=? AND date_convoi<?');$expected->execute([$stats['periode']['depuis'],$stats['periode']['avant']]);
$bounded=botReports($p,$p,'statistiques',['discord_id'=>'102','joueur'=>'Sender']);check($bounded['lignes'][0]['pommes_envoyees']===$expected->fetchColumn(),'out of period convoy excluded');
$cancelHistory=botReports($p,$p,'convois',['discord_id'=>'102','demande'=>$idConcurrent]);check($cancelHistory['total']===1&&$cancelHistory['lignes'][0]['annule'],'cancelled convoy retained in history');
$sender=$stats['lignes'][0];$filtered=botReports($p,$p,'statistiques',['discord_id'=>'102','joueur'=>$sender['pseudo']]);check($filtered['total']===1,'stats player filter');
rejects(fn()=>botReports($p,$p,'convois',['discord_id'=>'102','demande'=>'-1']),422);
rejects(fn()=>botReports($p,$p,'demandes',['discord_id'=>'102','etat'=>'unknown']),422);
file_put_contents(UPTEP_BOT_ENV_FILE,"ADMIN_DISCORD_IDS=\n");rejects(fn()=>botReports($p,$p,'demandes',['discord_id'=>'102']),403);
unlink(UPTEP_BOT_ENV_FILE);
echo "OK: reports allowlist, current association, dates/DST, pagination, cancelled history, exact sums and daily averages.\n";
