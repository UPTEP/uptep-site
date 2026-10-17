<?php
declare(strict_types=1);
function registrationForm(array $target, bool $creating): void { ?>
<dialog id="registration-terms" open aria-labelledby="registration-title" aria-describedby="registration-description">
<h2 id="registration-title">Bienvenue sur UPTEP</h2>
<p id="registration-description"><?= $creating ? 'Tu vas créer le compte de' : 'Tu es connecté en tant que' ?> <strong><?= e($target['display_name']) ?></strong> sur le site privé de l’alliance UPTEP, Fourmizzz S5.</p>

<p><a href="/cgu.html" target="_blank" rel="noopener noreferrer">Lire les CGU (nouvel onglet)</a> · <a href="/confidentialite.html" target="_blank" rel="noopener noreferrer">Confidentialité (nouvel onglet)</a> · <a href="/mentions-legales.html" target="_blank" rel="noopener noreferrer">Mentions légales</a></p>
<form method="post" action="<?= $creating ? '/discord-login.php' : '/conditions.php' ?>" class="login-form">
<input type="hidden" name="csrf" value="<?= e(csrf()) ?>"><input type="hidden" name="action" value="<?= $creating ? 'confirm' : 'accept' ?>">
<input type="hidden" name="terms_version" value="<?= e(DISCORD_REGISTRATION_TERMS_VERSION) ?>">
<label class="registration-choice"><input type="checkbox" name="accept_membership" value="1" required> <span>J’accepte de perdre l’accès au site et aux outils UPTEP si je quitte l’alliance UPTEP sur S5 ou si j’en suis expulsé.</span></label>
<label class="registration-choice"><input type="checkbox" name="accept_terms" value="1" required> <span>J’accepte les conditions générales d’utilisation du site, du userscript et du bot Discord.</span></label>
<label class="registration-choice"><input type="checkbox" name="privacy_notice" value="1" required> <span>J’ai pris connaissance de la politique de confidentialité et du traitement de mes données.</span></label>
<p class="registration-note">L’acceptation des CGU n’active aucune collecte facultative du userscript. Tes choix se gèrent séparément dans les paramètres.</p>
<button class="primary-button" type="submit"><?= $creating ? 'Accepter et créer mon compte' : 'Accepter et accéder au site' ?></button>
</form>
<form id="registration-decline" method="post" action="<?= $creating ? '/discord-login.php' : '/conditions.php' ?>">
<input type="hidden" name="csrf" value="<?= e(csrf()) ?>"><input type="hidden" name="action" value="cancel">
<button class="registration-cancel" type="submit"><?= $creating ? 'Refuser et quitter sans créer de compte' : 'Refuser et me déconnecter' ?></button>
</form>
<?php if ($creating): ?><p class="registration-note">Confirme uniquement si tu viens d’utiliser /connexion sur Discord. Le lien est valable 2 minutes : s’il expire pendant ta lecture, redemande un lien. Aucun compte n’est créé sans ton acceptation.</p><?php else: ?><p class="registration-note">Ton compte existe déjà. Cette confirmation est nécessaire pour continuer à utiliser le site. Refuser te déconnecte sans supprimer ton compte ni tes données.</p><?php endif; ?>
</dialog>
<?php }
