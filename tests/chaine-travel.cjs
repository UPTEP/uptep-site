const assert=require('node:assert/strict'),fs=require('node:fs'),vm=require('node:vm');
const ctx=vm.createContext({});vm.runInContext(fs.readFileSync('/root/uptep-site/userscript/chaine.js','utf8'),ctx);
const calculate=(target,speed)=>ctx.chaineTravel({x:0,y:0},target,speed);
assert.equal(calculate({x:3,y:4},0).distance,5);
assert.equal(calculate({x:3,y:4},0).seconds,9038);
assert.equal(calculate({x:3,y:4},10).seconds,3151);
assert.equal(calculate({x:350,y:0},0).seconds,402787);
assert.equal(calculate({x:350,y:0},10).seconds,140443);
assert.equal(calculate({x:0,y:0},0).seconds,0);
for(const speed of [null,undefined,'',-1,1.5]){assert.equal(calculate({x:3,y:4},speed).distance,5);assert.equal(calculate({x:3,y:4},speed).seconds,null);}
for(const x of [null,undefined,'',NaN,Infinity,-1])assert.equal(calculate({x,y:4},10).distance,null);
assert.equal(ctx.chaineTravelDuration(3151),'00:52:31');
assert.equal(ctx.chaineTravelDuration(86400),'1j 00:00:00');
assert.equal(ctx.chaineTravelDuration(140443),'1j 15:00:43');
assert.equal(ctx.chaineTravelDuration(null),'—');
console.log('OK: Toolzzz reference values, zero coordinates/VA, missing data and duration formatting.');
