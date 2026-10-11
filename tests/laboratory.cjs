const assert=require('node:assert/strict'),fs=require('node:fs'),vm=require('node:vm'),path=require('node:path');
const root=path.resolve(__dirname,'..'),src=fs.existsSync(path.join(root,'userscript/src/laboratory.js'))?'userscript/src/laboratory.js':'userscript/laboratory.js';
const ctx=vm.createContext({});vm.runInContext(fs.readFileSync(path.join(root,src),'utf8')+';globalThis.units=laboratoryUnits;',ctx);
const data={buildings:{couveuse:'0',solarium:'0',salle_combat:'0',caserne:'0'},research:{technique_ponte:'0',armes:'0',bouclier:'0',genetique:'0',acide:'0',poison:'0'},army:Object.fromEntries(ctx.units.map(u=>[u[0],'0']))};
for(const unit of ctx.units){if(unit[2]===null){assert.equal(ctx.laboratoryUnlocked(unit,data),false);continue;}
 const d=structuredClone(data);
 for(const [group,index] of [['buildings',6],['research',7]])for(const [key,n] of Object.entries(unit[index]))d[group][key]=String(n);
 assert.equal(ctx.laboratoryUnlocked(unit,d),true,unit[1]+' at exact prerequisites');
 for(const [group,index] of [['buildings',6],['research',7]])for(const [key,n] of Object.entries(unit[index])){
  const lower=structuredClone(d);lower[group][key]=String(n-1);assert.equal(ctx.laboratoryUnlocked(unit,lower),false);
  lower[group][key]=null;assert.equal(ctx.laboratoryUnlocked(unit,lower),null);
 }
}
data.buildings.salle_combat='1';data.army.jsn='1000';
let result=ctx.laboratoryCompare(data,'armes','80');
assert.equal(result.gain,300n);assert.equal(result.alternatives.length,1);assert.equal(result.alternatives[0].count,16n);assert.equal(result.alternatives[0].gain,48n);assert.equal(result.seconds,4800);
data.research.armes='10';result=ctx.laboratoryCompare(data,'armes','80');assert.equal(result.gain,300n,'Not 10% of already boosted army');assert.equal(result.alternatives[0].gain,96n);
data.buildings.couveuse='10';data.buildings.solarium='5';data.research.technique_ponte='5';result=ctx.laboratoryCompare(data,'armes','80');assert.equal(result.tdp,20);assert.equal(result.alternatives[0].count,16n);assert.ok(Math.abs(result.seconds-4800*.9**20)<1e-9);
data.army.ce='10';data.army.te='10';result=ctx.laboratoryCompare(data,'bouclier','80');assert.equal(result.gain,890n,'XP elites count toward current army');assert.ok(result.alternatives.every(u=>!['ce','te'].includes(u.key)));
data.army.jsn='9223372036854775807';result=ctx.laboratoryCompare(data,'armes','9223372036854775807');assert.equal(result.alternatives[0].count,1844674407370955161n,'No rounding through Number');
data.army.jsn=null;assert.throws(()=>ctx.laboratoryCompare(data,'armes','80'),/Armée incomplète/);
data.army.jsn='0';data.buildings.couveuse=null;assert.throws(()=>ctx.laboratoryCompare(data,'armes','80'),/Complète/);
for(const bad of [null,'',-1,NaN,'1.1',Number.MAX_SAFE_INTEGER+1])assert.equal(ctx.laboratoryInteger(bad),null);
assert.equal(ctx.laboratoryDuration(4800),'01:20:00');assert.equal(ctx.laboratoryDuration(86400),'1 j 00:00:00');
console.log('OK: all unlock thresholds, unknown levels, XP exclusions, marginal gains, TDP and large integers');
