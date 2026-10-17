'use strict';
const window={};
/* Moteur de combat Fourmizzz (joueur contre joueur), reconstitué par backtest sur le simulateur officiel (serveur de test S0) :
   mécanique exacte sur 120 combats aléatoires (unités tuées à chaque tour, tours, vainqueur) et promotions exactes (86 cas). */
(function (global) {
  // ordre officiel des unités (= ordre dans lequel les dégâts sont encaissés) ; p = index de l'unité obtenue par promotion
  var U = [
    {id:1, hp:8,  a:3,  d:2,  w:85,   p:1},
    {id:2, hp:10, a:5,  d:4,  w:125,  p:2},
    {id:3, hp:13, a:7,  d:6,  w:170},
    {id:4, hp:16, a:10, d:9,  w:227,  p:4},
    {id:5, hp:20, a:15, d:14, w:312,  p:9},
    {id:6, hp:30, a:1,  d:25, w:434},
    {id:14,hp:40, a:1,  d:35, w:592},
    {id:7, hp:10, a:30, d:15, w:295,  p:8},
    {id:8, hp:12, a:35, d:18, w:349},
    {id:9, hp:27, a:24, d:23, w:460},
    {id:10,hp:35, a:55, d:1,  w:642},
    {id:13,hp:50, a:80, d:1,  w:990},
    {id:11,hp:50, a:50, d:50, w:909,  p:13},
    {id:12,hp:55, a:55, d:55, w:1000}
  ];

  /* o : { aBou, aArm, aCoch, dBou, dArm, dCoch, lieu: 'terrain'|'dome'|'loge', niveau }
     - Armes : +10 % d'attaque (attaquant) ou de défense (défenseur) par niveau
     - vie du défenseur × (1 + 0,1 Bouclier + bonus du lieu) ; Dôme : 0,10 + 0,05 × niveau ; Loge : 0,30 + 0,15 × niveau
     - tour 1 : si l'attaquant tue tout, la réplique est réduite selon attaque / vie de base du défenseur (sans Bouclier ni lieu)
       > 3 : 10 % ; > 2 : 30 % ; > 1,5 : 50 %
     - dégâts encaissés dans l'ordre des unités ; tours simultanés jusqu'à la mort d'un camp ; si les deux meurent, l'attaquant perd
     - promotions du vainqueur : q = (1 + 0,1 Cochenilles) × (0,66 × poids perdant × √(vie×att perdant) / (poids vainqueur × √(vie×att vainqueur)))² */
  function combat(A, D, o) {
    var loc = o.lieu === 'dome' ? 0.1 + 0.05 * o.niveau : o.lieu === 'loge' ? 0.3 + 0.15 * o.niveau : 0;
    var aH = 1 + 0.1 * (o.aBou || 0), dH = 1 + 0.1 * (o.dBou || 0) + loc, aM = 1 + 0.1 * (o.aArm || 0), dM = 1 + 0.1 * (o.dArm || 0);
    function side(arr, H, M, key) {
      var s = [];
      U.forEach(function (u, i) { var n = arr[i] || 0; if (n > 0) s.push({ i: i, n: n, hp: u.hp * H, v: u[key] * M, pool: n * u.hp * H }); });
      return s;
    }
    var a = side(A, aH, aM, 'a'), d = side(D, dH, dM, 'd'), log = [], round = 0;
    var baseLifeD = D.reduce(function (q, n, i) { return q + (n || 0) * U[i].hp; }, 0);
    function alive(s) { return s.map(function (x) { return Math.ceil(x.pool / x.hp - 1e-9); }); }
    function hit(s, dmg) { for (var k = 0; k < s.length && dmg > 0; k++) { var t = Math.min(s[k].pool, dmg); s[k].pool -= t; dmg -= t; } }
    function killed(s, before) { var q = 0; s.forEach(function (x, k) { q += before[k] - Math.ceil(x.pool / x.hp - 1e-9); }); return q; }
    while (round < 500) {
      round++;
      var dA = 0, dD = 0, lA = 0, lD = 0, rep = 1;
      a.forEach(function (x) { dA += x.pool / x.hp * x.v; lA += x.pool; });
      d.forEach(function (x) { dD += x.pool / x.hp * x.v; lD += x.pool; });
      if (lA <= 1e-9 || lD <= 1e-9) break;
      var dDshown = dD;
      if (round === 1 && dA >= lD - 1e-9) {
        var r = dA / baseLifeD; rep = r > 3 + 1e-9 ? 0.1 : r > 2 + 1e-9 ? 0.3 : r > 1.5 + 1e-9 ? 0.5 : 1;
        dDshown = dD * rep; dD = Math.floor(dD * rep + 1e-9);
      }
      var a0 = alive(a), d0 = alive(d);
      hit(d, dA); hit(a, dD);
      log.push({ round: round, rep: rep, aBase: dA / aM, aBonus: dA - dA / aM, killedD: killed(d, d0),
        dBase: dDshown / dM, dBonus: dDshown - dDshown / dM, killedA: killed(a, a0) });
    }
    var restA = a.reduce(function (q, x) { return q + x.pool; }, 0), restD = d.reduce(function (q, x) { return q + x.pool; }, 0);
    var attWin = restD <= 1e-9 && restA > 1e-9, both = restD <= 1e-9 && restA <= 1e-9;
    function weight(arr, H, M) { return arr.reduce(function (q, n, i) { return q + (n || 0) * U[i].w; }, 0) * Math.sqrt(H * M); }
    var Wa = weight(A, aH, aM), Wd = weight(D, dH, dM);
    // survivantes (une unité entamée rentre) et promotions du vainqueur
    var survA = new Array(14).fill(0), survD = new Array(14).fill(0), promoA = new Array(14).fill(0), promoD = new Array(14).fill(0);
    a.forEach(function (x) { survA[x.i] = Math.ceil(x.pool / x.hp - 1e-9); });
    d.forEach(function (x) { survD[x.i] = Math.ceil(x.pool / x.hp - 1e-9); });
    var winner = attWin ? a : (restD > 1e-9 ? d : null);
    if (winner) {
      var ratio = attWin ? 0.66 * Wd / Wa : 0.66 * Wa / Wd, q = (1 + 0.1 * ((attWin ? o.aCoch : o.dCoch) || 0)) * ratio * ratio, out = attWin ? promoA : promoD;
      winner.forEach(function (x) { if (U[x.i].p == null) return; var rem = x.pool / x.hp; out[x.i] = Math.min(Math.floor(rem + 1e-9), Math.floor(q * rem + 1e-9)); });
    }
    return { log: log, attWin: attWin, both: both, survA: survA, survD: survD, promoA: promoA, promoD: promoD, weightRatio: Wa / Wd };
  }

  global.Combat = { UNITS: U, combat: combat };
})(window);

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

function analyze(text, choice='auto') {
 const r=parseRc(text), K=window.Combat;
 for(const label of ['attaque','d[ée]fense']){
   const section=text.replace(/ | /g,' ').match(new RegExp('Troupes en '+label+'\\s*:\\s*([^\\n.]+)','i'));
   if(section)for(const part of section[1].split(',')){
     const m=part.trim().match(/^([\d ]+)\s+(.+)$/);
     if(!m || !ARMY_NAMES.some(([name])=>name===armyKey(m[2])))throw new Error('Une unité ou un effectif n’est pas reconnu. Vérifie les lignes de troupes.');
   }
 }
 if (!r.ok || r.att.length !== r.def.length) throw new Error('RC incomplet : les deux armées et les dégâts des deux camps sont nécessaires.');
 if (![...r.A,...r.D,...r.att.flatMap(x=>[x.base,x.bonus,x.kills]),...r.def.flatMap(x=>[x.base,x.bonus,x.kills])].every(n=>Number.isSafeInteger(n)&&n>=0&&n<=1e12)) throw new Error('Effectifs ou dégâts hors limites.');
    // Armes : bonus / base × 10 (premier tour, le plus précis)
    const armOf = l => l && l.base > 0 ? Math.round(10 * l.bonus / l.base) : null;
    const aArm = armOf(r.att[0]), dArm = armOf(r.def[0]);
    const wanted = r.att.map((l, i) => [l.kills, r.def[i]?.kills]);
    const same = log => log.length === wanted.length && log.every((l, i) => l.killedD === wanted[i][0] && l.killedA === wanted[i][1]);
    // 1) Bouclier de l'attaquant et vie du défenseur, filtrés sur le 1er tour, puis vérification complète
    const lieuChoice = choice, lieux = lieuChoice !== "auto" ? [lieuChoice] : r.lieu ? [r.lieu] : ["terrain", "dome", "loge"];
    const MAXB = 40, MAXL = 40, found = [];
    const aBous = [];
    for (let b = 0; b <= MAXB; b++) { const g = K.combat(r.A, r.D, { aArm, dArm, aBou: b, dBou: 0, lieu: "terrain", niveau: 0 }); if (g.log[0] && g.log[0].killedA === wanted[0][1]) aBous.push(b); }
    for (const lieu of lieux) for (let db = 0; db <= MAXB; db++) for (let L = 0; L <= (lieu === "terrain" ? 0 : MAXL); L++) {
      const o0 = { aArm, dArm, aBou: aBous[0] ?? 0, dBou: db, lieu, niveau: L };
      if (K.combat(r.A, r.D, o0).log[0]?.killedD !== wanted[0][0]) continue;
      for (const ab of aBous) { const g = K.combat(r.A, r.D, { ...o0, aBou: ab }); if (same(g.log)) found.push({ lieu, dBou: db, niveau: L, aBou: ab }); }
    }

 const exact = xs => xs.length && xs.every(x=>typeof x==='number'?Math.abs(x-xs[0])<1e-9:x===xs[0]) ? xs[0] : null;
 const sides = [r.A,r.D].map((army,i)=>{
   const arms = i ? dArm : aArm;
   const shield=exact(found.map(f=>i?f.dBou:f.aBou));
   const location=exact(found.map(f=>f.lieu)) || (choice!=='auto'?choice:r.lieu);
   const building = key => i && location===key ? exact(found.map(f=>f.niveau)) : null;
   const attack=army.reduce((n,c,j)=>n+c*K.UNITS[j].a,0);
   const hp=army.reduce((n,c,j)=>n+c*K.UNITS[j].hp,0);
   const factors=found.map(f=>1+.1*(i?f.dBou:f.aBou)+(i?(f.lieu==='dome'?.1+.05*f.niveau:f.lieu==='loge'?.3+.15*f.niveau:0):0));
   let casualties=(i?r.att:r.def).reduce((sum,round)=>sum+round.kills,0);
   const survivors_before_xp=army.map(n=>{const lost=Math.min(n,casualties);casualties-=lost;return n-lost;});
   if(casualties>0)throw new Error('Pertes supérieures à l’armée initiale.');
   return {army,survivors_before_xp,arms,shield,dome:building('dome'),loge:building('loge'),attack_base:attack,attack_bonus:arms===null?null:attack*(1+.1*arms),hp_base:hp,hp_bonus:exact(factors)===null?null:hp*exact(factors),hp_min:factors.length?hp*factors.reduce((a,b)=>Math.min(a,b)):null,hp_max:factors.length?hp*factors.reduce((a,b)=>Math.max(a,b)):null};
 });
 const date=(text.match(/\b(\d{2})[\/.-](\d{2})[\/.-](\d{4})\s+(?:à\s*)?(\d{2}):([0-5]\d)/)||[]);
 const names={attacker:null,defender:null};
 for(const [key,label] of [['attacker','Attaquant'],['defender','Défenseur']]) {
   const m=text.match(new RegExp('^'+label+'\\s*:\\s*([^\\n\\r]+)','im'));
   if(m) names[key]=m[1].trim().slice(0,255);
 }
 const target=text.match(/Vous attaquez (?:le terrain de chasse de|le d[ôo]me de|la fourmili[èe]re de|la loge imp[ée]riale de)\s+([^\n.!]+)[.!]?/i);
 if(target) names.defender=target[1].trim();
 const incoming=text.match(/^([^\n]+?)\s+attaque votre (?:terrain|d[ôo]me|fourmili[èe]re|loge)/im);
 if(incoming) names.attacker=incoming[1].trim();
 return {version:1,view:r.view,location:exact(found.map(f=>f.lieu))||(choice!=='auto'?choice:r.lieu),date:date.length?`${date[3]}-${date[2]}-${date[1]}T${date[4]}:${date[5]}`:null,names,sides,candidates:found,units:K.UNITS.map((u,i)=>({id:u.id,name:UNIT_SG[i]}))};
}
module.exports={analyze,parseRc};
if(require.main===module){let input='';process.stdin.setEncoding('utf8');process.stdin.on('data',s=>{input+=s;if(input.length>110000)process.exit(1)});process.stdin.on('end',()=>{try{const x=JSON.parse(input);if(!['auto','terrain','dome','loge'].includes(x.location))throw new Error('Lieu invalide');process.stdout.write(JSON.stringify(analyze(x.raw,x.location)))}catch(e){process.stdout.write(JSON.stringify({error:e.message}));process.exitCode=1}})}
