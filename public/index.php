<?php
declare(strict_types=1);
require '/home/uptep/private/app/bootstrap.php';
require '/home/uptep/private/app/view.php';
$u = requireUser(); workspaceStart($u, 'Accueil');
?>
<h1>Tableau de bord</h1>
<?php workspaceEnd(); ?>
