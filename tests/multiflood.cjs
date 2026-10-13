const assert=require('node:assert/strict'),fs=require('node:fs'),vm=require('node:vm');
const root=require('node:path').resolve(__dirname,'..'),scope={};vm.createContext(scope);vm.runInContext(fs.readFileSync(root+'/userscript/src/multiflood.js','utf8'),scope);
const {multifloodPlan:plan,multifloodNumber:number,multifloodRounded:round,multifloodRange:range}=scope;
const input=(a,b,options={})=>({self:{field:String(a)},targets:[{id:'2',pseudo:'Khan',field:String(b),distance:10}],army:'100000000',slots:20,...options});
let p=plan(input(10721,8711));assert.deepEqual(Array.from(p.attacks,r=>String(r.amount)),['1742','491','1295']);assert.equal(p.gain,3528n);assert.equal(p.final,14249n);
p=plan(input(10721,8711,{slots:2}));assert.deepEqual(Array.from(p.attacks,r=>String(r.amount)),['1742','1393'],'Last available slot uses its full possible capture');
p=plan(input(10721,8711,{army:'228'}));assert.equal(p.gain,228n);assert.equal(p.attacks.length,1);
p=plan(input(10721,8711,{excluded:['2:0']}));assert.equal(p.attacks.length,2);assert.equal(p.attacks[0].own,10721n);assert.equal(p.attacks[0].field,8711n);assert.equal(p.gain,3135n);
p=plan(input(10721,8711,{excluded:['2:0','2:1','2:2']}));assert.equal(p.attacks.length,0);assert.equal(p.gain,0n);
assert(range(1000n,500n));assert(range(1000n,3000n));assert(!range(1000n,499n));assert(!range(1000n,3001n));
for(const field of [499,3001])assert.equal(plan(input(1000,field)).attacks.length,0);
for(const options of [{army:'0'},{slots:0}])assert.equal(plan(input(1000,1000,options)).attacks.length,0);
p=plan(input(1000,1000,{self:{field:'1000',max:'111'}}));assert.equal(p.gain,111n);
p=plan(input(1000,1000,{self:{field:'1000',final:'1050'}}));assert.equal(p.gain,50n);
p=plan(input(1000,1000,{targets:[{id:'2',field:'1000',final:'950',max:'100'}]}));assert.equal(p.gain,50n);
p=plan(input(1000,1000,{targets:[{id:'2',field:'1000',final:'1000'}]}));assert.equal(p.gain,0n);
p=plan(input(1000,1000,{targets:[{id:'2',field:'1000',max:'0'}]}));assert.equal(p.gain,0n);
p=plan(input(1000,1000,{targets:[{id:'3',field:'1000',distance:20},{id:'2',field:'1000',distance:10},{id:'2',field:'1000',distance:10}]}));assert.deepEqual(Array.from(p.groups,g=>g.target.id),['2','3']);assert.equal(p.groups[1].rows[0].own,p.initial+p.groups[0].gain);
assert.equal(number('9 007\u202f199\u00a0254 740 993'),9007199254740993n);assert.throws(()=>number('1e6'));assert.throws(()=>number('-1'));assert.throws(()=>number('1.5'));assert.throws(()=>number('9223372036854775808'));
p=plan(input('9007199254740993','9007199254740993',{army:'9223372036854775807'}));assert(p.final>9007199254740993n);assert.equal(p.initial+p.gain,p.final);
assert.equal(round(3528n,1),2500n);assert.equal(round(3528n,2),3500n);assert.equal(round(3528n,3),3525n);assert.equal(round(3528n,4),3528n);
// Exhaustive small cases: no chosen row violates range, army, slots, or 20%.
for(let a=1;a<=50;a++)for(let b=1;b<=150;b++)for(const slots of [1,2,5]){
 const p=plan(input(a,b,{slots,army:'20'}));let own=BigInt(a),target=BigInt(b),gain=0n;
 for(const row of p.attacks){assert(range(own,target));assert(row.amount>0n&&row.amount<=target/5n);assert.equal(row.own,own);assert.equal(row.field,target);gain+=row.amount;own+=row.amount;target-=row.amount;}
 assert(gain<=20n);assert(p.attacks.length<=slots);assert.equal(own,p.final);
}
console.log('OK: optimal boundary series, limited army/slots, deselection recomputation, range, goals, rounding, target deduplication, exact integers, 22,500 small-case invariants.');
