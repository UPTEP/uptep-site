<?php
require '/root/uptep-site/app/chaine.php';
function e($s){return htmlspecialchars($s,ENT_QUOTES,'UTF-8');}
function csrf(){return 'fixture';}
function workspaceEnd(){}
$error='';$success=false;$unavailable=false;$latest='';
$focus=['reason'=>'unlinked','self'=>null,'targets'=>[]];
$players=[];
foreach(['Grenier','Hors chaîne'] as $i=>$grade)$players[]=['player_id'=>$i+1,'pseudo'=>'Joueur'.($i+1),'chaine_grade'=>$grade,'field'=>100,'sort_order'=>1,'version'=>0,'export_version'=>'202610091200','on_holiday'=>false,'is_banned'=>false];
$groups=['main'=>[$players[0]],'outside'=>[$players[1]]];$movements=chaineMovements($players);
$source=file_get_contents('/root/uptep-site/public/chaine.php');$template=substr($source,strpos($source,'<div class="chaine-page">'));
foreach([true,false] as $canEdit){
 ob_start();eval('?>'.$template);$html=ob_get_clean();
 preg_match_all('~<table.*?</table>~s',$html,$tables);
 if(count($tables[0])!==2 || !str_contains($tables[0][0],'Joueur1') || str_contains($tables[0][0],'Joueur2') || !str_contains($tables[0][1],'Joueur2') || str_contains($tables[0][1],'Joueur1'))throw new RuntimeException('Wrong partition');
 if($canEdit && !str_contains($tables[0][1],'value="Hors chaîne" selected'))throw new RuntimeException('Missing selected grade');
 if(!$canEdit && str_contains($html,'<form'))throw new RuntimeException('Reader has edit form');
}
echo "OK : deux tableaux distincts, grade sélectionné, édition réservée au droit chaine.\n";
