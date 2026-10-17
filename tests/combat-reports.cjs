'use strict';
const fs=require('node:fs'),vm=require('node:vm'),assert=require('node:assert/strict'),path=require('node:path');
const root=process.env.UPTEP_SITE_ROOT||path.resolve(__dirname,'..');
const {analyze}=require(root+'/app/combat-analyze.cjs');
const ctx={window:{}};vm.createContext(ctx);vm.runInContext(fs.readFileSync(root+'/public/assets/tools-combat.js','utf8'),ctx);
const K=ctx.window.Combat,names=analyzeUnits();
function analyzeUnits(){return ['Jeune Soldate Naine','Soldate Naine','Naine d’Elite','Jeune Soldate','Soldate','Concierge','Concierge d’élite','Artilleuse','Artilleuse d’élite','Soldate d’élite','Tank','Tank d’élite','Tueuse','Tueuse d’élite'];}
let fixture;
for(const lieu of ['terrain','dome','loge'])for(const view of ['att','def']){
 const A=Array(14).fill(0),D=Array(14).fill(0);A[0]=10000;A[7]=300;D[0]=8000;D[5]=200;
 const result=K.combat(A,D,{aArm:12,dArm:9,aBou:8,dBou:10,lieu,niveau:12});
 const units=a=>a.flatMap((n,i)=>n?[`${n} ${names[i]}`]:[]).join(', ');
 let raw=`15/10/2026 à 12:34\nAttaquant : Alpha\nDéfenseur : Beta\nTroupes en attaque : ${units(A)}.\nTroupes en défense : ${units(D)}.\n`;
 for(const t of result.log)raw+=`${view==='att'?'Vous infligez':'L’ennemie inflige'} ${Math.ceil(t.aBase)} (+ ${Math.ceil(t.aBonus)}) dégâts et tuez ${t.killedD} ennemies.\n${view==='def'?'Vous infligez':'L’ennemie inflige'} ${Math.ceil(t.dBase)} (+ ${Math.ceil(t.dBonus)}) dégâts et tue ${t.killedA} ennemies.\n`;
 const a=analyze(raw,lieu);assert.equal(a.view,view);assert.equal(a.date,'2026-10-15T12:34');assert.deepEqual(a.names,{attacker:'Alpha',defender:'Beta'});
 assert.deepEqual(a.sides[0].survivors_before_xp,JSON.parse(JSON.stringify(result.survA)));assert.deepEqual(a.sides[1].survivors_before_xp,JSON.parse(JSON.stringify(result.survD)));assert.deepEqual(a.sides[0].survivors_before_xp,JSON.parse(JSON.stringify(result.survA)));assert.deepEqual(a.sides[1].survivors_before_xp,JSON.parse(JSON.stringify(result.survD)));assert.deepEqual(a.sides[0].survivors_before_xp,JSON.parse(JSON.stringify(result.survA)));assert.deepEqual(a.sides[1].survivors_before_xp,JSON.parse(JSON.stringify(result.survD)));assert.equal(a.sides[0].arms,12);assert.equal(a.sides[1].arms,9);assert.equal(a.sides[0].attack_base,39000);assert.equal(a.sides[1].attack_base,24200);
 assert(a.candidates.some(c=>c.aBou===8&&c.dBou===10&&(lieu==='terrain'||c.niveau===12)));
 for(const c of a.candidates){const sim=K.combat(A,D,{aArm:12,dArm:9,...c});assert.deepEqual(JSON.parse(JSON.stringify(sim.log.map(t=>[t.killedA,t.killedD]))),JSON.parse(JSON.stringify(result.log.map(t=>[t.killedA,t.killedD]))));}
 if(lieu==='dome'){assert.equal(a.sides[1].shield,null);assert.equal(a.sides[1].dome,null);assert(Math.abs(a.sides[1].hp_bonus-189000)<1e-6);fixture={raw,location:lieu,analysis:a};}
}
assert.throws(()=>analyze('invalide'));
assert.throws(()=>analyze(fixture.raw.replace('Jeune Soldate Naine','Unité inconnue')));
assert.throws(()=>analyze(fixture.raw.replace('10000','9007199254740993')));
assert.throws(()=>analyze(fixture.raw.split('\n').slice(0,-2).join('\n')));
assert.equal(analyze(fixture.raw.replace('15/10/2026 à 12:34',''),'dome').date,null);
if(process.env.COMBAT_FIXTURE)fs.writeFileSync(process.env.COMBAT_FIXTURE,JSON.stringify(fixture));
console.log('Archive RC : 6 combats, deux points de vue, trois lieux, validation de toutes les hypothèses, niveaux ambigus, précision des PV, métadonnées et entrées invalides OK.');
