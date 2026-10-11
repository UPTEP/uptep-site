<?php
declare(strict_types=1);
require '/home/uptep/private/app/bootstrap.php';
require '/home/uptep/private/app/view.php';
require '/home/uptep/private/app/stats.php';
$u=requireUser();$error='';$unavailable=false;$saved=['data'=>[],'version'=>0,'updated_at'=>null];
$success=$_SESSION['stats_saved']??false;unset($_SESSION['stats_saved']);
try{$saved=loadStats((int)$u['id']);}catch(Throwable $e){error_log('UPTEP stats read: '.$e->getMessage());http_response_code(503);$unavailable=true;}
$values=$saved['data'];$version=$saved['version'];
if($_SERVER['REQUEST_METHOD']==='POST' && !$unavailable){
 $input=$_POST['stats']??null;
 // Retain submitted strings on validation errors, never trust a client user ID.
 if(is_array($input))foreach(STATS_GROUPS as $group=>[$title,$fields])foreach($fields as $key=>$label)$values[$group][$key]=isset($input[$group]) && is_array($input[$group]) && isset($input[$group][$key]) && is_string($input[$group][$key])?mb_substr($input[$group][$key],0,64):'';
 try{
  if(!validCsrf())throw new DomainException('Le formulaire a expiré. Recharge la page avant de réessayer.',403);
  $postedVersion=filter_var(post('version'),FILTER_VALIDATE_INT,['options'=>['min_range'=>0]]);
  if($postedVersion===false || !is_array($input))throw new DomainException('Formulaire invalide.',422);
  $version=$postedVersion;
  $clean=statsValues($input);saveStats($u,$clean,$version);
  $_SESSION['stats_saved']=true;redirect('/stats.php');
 }catch(DomainException $e){http_response_code($e->getCode());$error=$e->getMessage();}
 catch(Throwable $e){error_log('UPTEP stats save: '.$e->getMessage());http_response_code(503);$error='Enregistrement indisponible. Tes saisies sont conservées ci-dessous.';}
}
workspaceStart($u,'Mes stats');
?>
<div class="stats-page"><h1>Mes stats</h1><p class="admin-intro">Tes données du jeu · Serveur 5</p>
<?php if($error): ?><div class="form-error" role="alert"><?= e($error) ?></div><?php endif; ?>
<?php if($success): ?><div class="admin-success" role="status">Tes statistiques ont été enregistrées.</div><?php endif; ?>
<?php if($unavailable): ?><div class="form-error" role="alert">Tes statistiques sont momentanément indisponibles. Réessaie dans quelques instants.</div><?php else: ?>
<form action="/stats.php" method="post" class="stats-form"><input type="hidden" name="csrf" value="<?= e(csrf()) ?>"><input type="hidden" name="version" value="<?= (int)$version ?>">
<div class="stats-toolbar"><p><?= $saved['updated_at']?'Dernière mise à jour : '.e((new DateTimeImmutable($saved['updated_at']))->setTimezone(new DateTimeZone('Europe/Paris'))->format('d/m/Y à H:i')).' (Paris)':'Aucune statistique enregistrée.' ?></p><button class="save-user" type="submit">Enregistrer mes stats</button></div>
<p class="stats-hint">Laisse vide ce que tu n’as pas encore renseigné. Indique 0 pour une quantité ou un niveau nul.</p>
<div class="stats-sections">
<?php foreach(STATS_GROUPS as $group=>[$title,$fields]): $levels=in_array($group,['buildings','research'],true); ?>
<section class="users-panel stats-section" aria-labelledby="stats-<?= e($group) ?>"><div class="users-panel-heading"><h2 id="stats-<?= e($group) ?>"><?= e($title) ?></h2><span class="user-count"><?= $levels?'Niveaux':'Quantités' ?></span></div><div class="stats-fields">
<?php foreach($fields as $key=>$label): $id='stat-'.$group.'-'.$key; ?><div class="stats-field"><label for="<?= e($id) ?>"><?= e($label) ?></label><input id="<?= e($id) ?>" name="stats[<?= e($group) ?>][<?= e($key) ?>]" type="text" inputmode="numeric" maxlength="<?= $levels?3:25 ?>" pattern="[0-9\s\u00a0\u202f]*" placeholder="—" autocomplete="off" value="<?= e((string)($values[$group][$key]??'')) ?>"></div><?php endforeach; ?>
</div></section><?php endforeach; ?>
</div><div class="stats-toolbar stats-bottom"><button class="save-user" type="submit">Enregistrer mes stats</button></div></form><?php endif; ?></div>
<?php workspaceEnd(); ?>
