<?php
declare(strict_types=1);
require '/home/uptep/private/app/bootstrap.php';
require '/home/uptep/private/app/view.php';
$u = requireUser(); workspaceStart($u, 'Accueil');
?>
<link rel="stylesheet" href="/assets/public-guide.css?v=1">
<h1>Tableau de bord</h1>
<section class="public-guide" aria-labelledby="public-guide-title">
<span class="public-guide-label">GUIDE PUBLIC · À PARTAGER</span>
<h2 id="public-guide-title">L’attaque à la 59e seconde</h2>
<p>Un tutoriel illustré pour comprendre le timing des attaques sur Fourmizzz, avec les astuces pour vérifier son horloge et son envoi. Le téléchargement est accessible à tous, sans compte UPTEP.</p>
<a class="public-guide-download" href="/assets/guides/fourmizzz-59e-seconde.pdf" download="Fourmizzz-le-secret-de-la-59e-seconde.pdf">Télécharger le guide <?= icon('arrow') ?></a>
<span class="public-guide-details">PDF · 6 pages · 2,6 Mo</span>
</section>
<?php workspaceEnd(); ?>
