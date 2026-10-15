/* Adapted from the Spyzzz RC archive in references/spyzzz-rc (2026-10-14).
   Calculation engine, level search, labels and result ordering preserved. */
(() => {
'use strict';
const app = document.getElementById('rc-calculator');
if (!app) return;
const $ = selector => app.querySelector(selector);
const loadCombat = async () => {
  if (!window.Combat) throw new Error('Moteur de combat indisponible.');
};
const UNIT_SG = ["Jeune Soldate Naine", "Soldate Naine", "Naine d’Elite", "Jeune Soldate", "Soldate", "Concierge", "Concierge d’élite",
  "Artilleuse", "Artilleuse d’élite", "Soldate d’élite", "Tank", "Tank d’élite", "Tueuse", "Tueuse d’élite"];
const armyKey = s => s.normalize("NFD").replace(/[\u0300-\u036f]/g, "").toLowerCase().replace(/[’'`]/g, "")
  .split(/\s+/).filter(Boolean).map(w => w.replace(/s$/, "")).join(" ");
const ARMY_NAMES = UNIT_SG.map((n, i) => [armyKey(n), i]).sort((a, b) => b[0].length - a[0].length);
// Extrait de https://spyzzz.fr/outils/rc ; dépendances présentes dans page-source.html.
function parseRc(text) {
  const t = text.replace(/ | /g, " "), num = s => +s.replace(/[^\d]/g, "");
  const list = s => { const army = new Array(14).fill(0);
    for (const part of (s || "").replace(/\.\s*$/, "").split(",")) { const m = part.trim().match(/^([\d ]+)\s+(.+)$/); if (!m) continue;
      const k = armyKey(m[2]); const f = ARMY_NAMES.find(([n]) => n === k); if (f) army[f[1]] += num(m[1]); }
    return army; };
  // une liste de troupes s'arrête au premier point (les nombres n'en contiennent pas) : marche aussi sans sauts de ligne
  const A = list((t.match(/Troupes en attaque\s*:\s*([^\n.]+)/i) || [])[1]), D = list((t.match(/Troupes en d[ée]fense\s*:\s*([^\n.]+)/i) || [])[1]);
  // lignes de dégâts dans l'ordre ; le camp de « Vous » dépend du point de vue (attaquant si la 1re ligne est « Vous infligez »)
  const lines = [...t.matchAll(/(Vous infligez|L.ennemie inflige)\s+([\d ]+)\s*\(\+\s*([\d ]+)\)\s*d[ée]g[aâ]ts.*?tue[zr]?\s+([\d ]+)/gi)]
    .map(m => ({ me: /^Vous/i.test(m[1]), base: num(m[2]), bonus: num(m[3]), kills: num(m[4]) }));
  const view = lines.length && lines[0].me ? "att" : "def";
  const att = lines.filter(l => l.me === (view === "att")), def = lines.filter(l => l.me !== (view === "att"));
  const lieu = /loge/i.test(t) ? "loge" : /d[ôo]me|fourmili[èe]re/i.test(t) ? "dome" : null;
  return { A, D, att, def, view, lieu, ok: A.some(Boolean) && D.some(Boolean) && att.length > 0 };
}
async function rcCalc() {
  await loadCombat(); const K = window.Combat;
  app.innerHTML = `<h2>Calculateur Dôme &amp; Loge</h2>
  <p class="note">Colle un rapport de combat (côté attaquant ou défenseur). L'outil lit les Armes dans les dégâts « base (+ bonus) », puis teste toutes les combinaisons de Bouclier, Dôme et Loge avec le moteur du simulateur de combat, et garde celles qui redonnent exactement les mêmes pertes à chaque tour.</p>
  <div class="card"><label class="rc-input-label" for="rc-in">Rapport de combat</label><textarea id="rc-in" maxlength="100000" class="bigta" placeholder="Troupes en attaque : …&#10;Troupes en défense : …&#10;&#10;Vous infligez 5 000 (+ 2 000) dégâts et tuez 140 ennemies.&#10;L’ennemie inflige …"></textarea>
    <div class="simrow"><span class="radios row" id="rc-lieu"><label><input type="radio" name="rcl" value="auto" checked> Lieu : détecter</label><label><input type="radio" name="rcl" value="terrain"> Terrain</label><label><input type="radio" name="rcl" value="dome"> Dôme</label><label><input type="radio" name="rcl" value="loge"> Loge</label></span></div>
    <button class="btn big" id="rc-go">Analyser</button></div>
  <div id="rc-out" aria-live="polite" aria-atomic="true"></div>`;
  $("#rc-go").onclick = () => {
    const r = parseRc($("#rc-in").value), out = $("#rc-out");
    if (!r.ok) { out.innerHTML = `<div class="card"><p class="down">Je ne reconnais pas ce texte : il faut les lignes « Troupes en attaque / en défense » et au moins un tour de combat.</p></div>`; return; }
    // Armes : bonus / base × 10 (premier tour, le plus précis)
    const armOf = l => l && l.base > 0 ? Math.round(10 * l.bonus / l.base) : null;
    const aArm = armOf(r.att[0]), dArm = armOf(r.def[0]);
    const wanted = r.att.map((l, i) => [l.kills, r.def[i]?.kills]);
    const same = log => log.length === wanted.length && log.every((l, i) => l.killedD === wanted[i][0] && l.killedA === wanted[i][1]);
    // 1) Bouclier de l'attaquant et vie du défenseur, filtrés sur le 1er tour, puis vérification complète
    const lieuChoice = app.querySelector("[name=rcl]:checked").value, lieux = lieuChoice !== "auto" ? [lieuChoice] : r.lieu ? [r.lieu] : ["terrain", "dome", "loge"];
    const MAXB = 40, MAXL = 40, found = [];
    const aBous = [];
    for (let b = 0; b <= MAXB; b++) { const g = K.combat(r.A, r.D, { aArm, dArm, aBou: b, dBou: 0, lieu: "terrain", niveau: 0 }); if (g.log[0] && g.log[0].killedA === wanted[0][1]) aBous.push(b); }
    for (const lieu of lieux) for (let db = 0; db <= MAXB; db++) for (let L = 0; L <= (lieu === "terrain" ? 0 : MAXL); L++) {
      const o0 = { aArm, dArm, aBou: aBous[0] ?? 0, dBou: db, lieu, niveau: L };
      if (K.combat(r.A, r.D, o0).log[0]?.killedD !== wanted[0][0]) continue;
      for (const ab of aBous) { const g = K.combat(r.A, r.D, { ...o0, aBou: ab }); if (same(g.log)) found.push({ lieu, dBou: db, niveau: L, aBou: ab }); }
    }
    const aBouSet = [...new Set(found.map(f => f.aBou))], byLieu = l => found.filter(f => f.lieu === l);
    const range = arr => arr.length ? (Math.min(...arr) === Math.max(...arr) ? `${Math.min(...arr)}` : `${Math.min(...arr)} à ${Math.max(...arr)}`) : "–";
    const lieuBlock = (l, title) => { const f = byLieu(l); if (!lieux.includes(l)) return "";
      if (!f.length) return `<div class="card"><h3>${title}</h3><p class="muted">Aucune combinaison ne colle.</p></div>`;
      if (l === "terrain") return `<div class="card"><h3>${title}</h3><p>Bouclier du défenseur : <b>${range([...new Set(f.map(x => x.dBou))])}</b></p></div>`;
      const pairs = [...new Map(f.map(x => [x.dBou + "|" + x.niveau, x])).values()].sort((a, b) => a.dBou - b.dBou || a.niveau - b.niveau);
      return `<div class="card"><h3>${title}</h3><p class="note">${pairs.length} combinaison${pairs.length > 1 ? "s" : ""} possible${pairs.length > 1 ? "s" : ""} (Bouclier → niveau)</p>
        <div class="pairs">${pairs.slice(0, 60).map(p => `<span class="tag">Bouclier ${p.dBou} → niv. ${p.niveau}</span>`).join("")}${pairs.length > 60 ? `<span class="muted">…</span>` : ""}</div></div>`; };
    const hpLo = found.length ? Math.min(...found.map(f => 1 + .1 * f.dBou + (f.lieu === "dome" ? .1 + .05 * f.niveau : f.lieu === "loge" ? .3 + .15 * f.niveau : 0))) : 0;
    const hpHi = found.length ? Math.max(...found.map(f => 1 + .1 * f.dBou + (f.lieu === "dome" ? .1 + .05 * f.niveau : f.lieu === "loge" ? .3 + .15 * f.niveau : 0))) : 0;
    out.innerHTML = `
    <div class="kpis">
      <div class="card kpi"><div class="l">Armes attaquant</div><div class="v">${aArm ?? "?"}</div></div>
      <div class="card kpi"><div class="l">Bouclier attaquant</div><div class="v">${aBouSet.length ? range(aBouSet) : "?"}</div></div>
      <div class="card kpi"><div class="l">Armes défenseur</div><div class="v">${dArm ?? "?"}</div></div>
      <div class="card kpi"><div class="l">Vie du défenseur</div><div class="v">${found.length ? "×" + (hpLo.toFixed(2) === hpHi.toFixed(2) ? hpLo.toFixed(2) : hpLo.toFixed(2) + "–" + hpHi.toFixed(2)).replace(/\./g, ",") : "?"}</div><div class="muted">1 + 0,1 × Bouclier + lieu</div></div>
    </div>
    ${!found.length ? `<div class="card"><p class="down">Aucune combinaison de niveaux (0 à ${MAXB}) ne redonne exactement ce combat. Vérifie que le RC est complet.</p></div>` : `
    <div class="grid2">${lieuBlock("terrain", "Sur le terrain de chasse")}${lieuBlock("dome", "Dans le Dôme")}${lieuBlock("loge", "Dans la Loge impériale")}</div>
    <p class="note">Le Bouclier du défenseur et le niveau du Dôme ou de la Loge s'additionnent dans sa vie : plusieurs couples donnent souvent le même résultat. Si tu connais son Bouclier (par exemple grâce à un RC sur son terrain de chasse), lis directement le niveau correspondant.${r.lieu ? "" : " Le lieu n'apparaît pas dans le RC : choisis-le au-dessus pour affiner."}</p>`}`;
  };
}


window.UptepRc = { parseRc };
rcCalc().catch(() => {
 app.textContent = 'Le calculateur n’a pas pu être chargé. Recharge la page pour réessayer.';
});
})();
