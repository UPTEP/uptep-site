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
