<?php
$s=file_get_contents(dirname(__DIR__).'/public/convois.php');
function e($v){return htmlspecialchars((string)$v,ENT_QUOTES);}function convoyHidden($a,$d){}function convoyAutoBadge($r){}function convoyAmount($v){return $v;}
// Select the actual declaration panel and delivery table from the production template.
$panel=explode("\n",explode("<?php if(\$open && \$d['valide'] && !\$own): ?>",$s)[1])[0];
$panel="<?php if(\$open && \$d['valide'] && !\$own): ?>".$panel;
$table=explode("\n",explode('<div class="users-panel users-scroll"><table class="users-table"><thead><tr><th>Demande</th>',$s)[1])[0];
$table='<div class="users-panel users-scroll"><table class="users-table"><thead><tr><th>Demande</th>'.$table;
foreach([false,true] as $auto){$open=true;$own=false;$d=['auto_production'=>$auto,'valide'=>true,'iddemandeur'=>'2'];$data=['lignes'=>[$d+['id'=>'8','pseudo_demandeur'=>'Target','nbpomme_restant'=>'1','nbmateriaux_restant'=>'2','distance'=>null,'seconds'=>null]]];foreach([$panel,$table] as $part){ob_start();eval('?>'.$part);$html=ob_get_clean();if(str_contains($html,'href="https://s5.fourmizzz.fr/commerce.php')===$auto)throw new Exception('Incorrect game link');if($auto&&!str_contains($html,'aria-disabled="true"'))throw new Exception('Missing disabled state');}}
echo "Website: normal send links available, auto production links disabled in detail and deliveries.\n";
