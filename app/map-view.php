<?php
declare(strict_types=1);
/**
 * Markup for the shared player map (public/assets/uptep-map.js): group chips, labels, zoom, full screen.
 * $badgeLabel names the optional badge next to each pseudo ("grade", "alliance").
 */
function uptepMapMarkup(string $kind, string $titleId, string $groupsLegend, string $badgeLabel, string $hint): string {
    return '<div class="chaine-map" data-uptep-map="'.e($kind).'"><form class="chaine-map-filters" data-map-filters aria-label="Filtres de la carte">'
        .'<fieldset><legend>'.e($groupsLegend).'</legend><div class="chaine-map-chips" data-map-options></div></fieldset>'
        .'<fieldset><legend>Affichage</legend><div class="chaine-map-display"><label class="chaine-map-select"><span class="sr-only">Étiquettes</span><select name="labels">'
        .'<option value="badge">Étiquettes : pseudo + '.e($badgeLabel).'</option><option value="pseudo">Étiquettes : pseudo</option><option value="none">Sans étiquettes</option></select></label>'
        .'<label class="chaine-map-toggle"><input type="checkbox" name="sized"> Taille selon le TDC</label></div></fieldset>'
        .'<button type="reset" class="chaine-map-reset">Réinitialiser</button></form>'
        .'<div class="chaine-map-view"><div class="chaine-map-toolbar"><button type="button" data-map-zoom="in" aria-label="Zoom avant">+</button><button type="button" data-map-zoom="out" aria-label="Zoom arrière">−</button><button type="button" data-map-zoom="fit" aria-label="Recentrer sur les points affichés">↺</button><button type="button" class="chaine-map-full" data-map-fullscreen aria-pressed="false">Plein écran</button><span class="chaine-map-hint">'.e($hint).'</span></div>'
        .'<div class="chaine-map-surface" data-map-surface><svg data-map-svg role="img" aria-labelledby="'.e($titleId).'" tabindex="0"></svg><div class="chaine-map-labels" data-map-labels aria-hidden="true"></div><div class="chaine-map-tip" data-map-tip hidden></div></div>'
        .'<div class="chaine-map-legend" data-map-legend aria-hidden="true"></div></div></div>';
}
