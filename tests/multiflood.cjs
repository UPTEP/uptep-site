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

const {multifloodTiming:timing,multifloodDuration:duration,multifloodTargetPlan:targetPlan,multifloodClock:clock}=scope;
assert.equal(timing(15*3600+9*60+8,NaN).clickSecond,51);
assert.equal(timing(0,NaN).clickSecond,59);
assert.equal(timing(59,NaN).clickSecond,0);
assert.equal(timing(null,0),null);
assert.equal(duration('15:09:08',0),54548);assert.equal(duration('',123),123);
assert.throws(()=>duration('15:60:08'));assert.throws(()=>duration('abc'));
for(let seconds=0;seconds<3600;seconds++)for(const now of [59999,60000,1234567890000]){
 const t=timing(seconds,now);assert.equal(Math.floor(t.arrivalAt/1000)%60,59);assert(t.clickAt>=Math.floor(now/1000)*1000);assert(t.clickAt-now<60000);
}
assert(timing(8,51000).inWindow);assert(timing(8,51999).inWindow);assert.equal(timing(8,52000).clickAt,111000);
const target={id:'3',pseudo:'Morel',field:'20000'},draft={self:{field:'10721'},excluded:[],observed:1000};
const records={'2':{state:'done',gain:'3528',arrivalAt:2000}};
let per=targetPlan(draft,{army:'50000',slots:10},target,records,3000);assert.equal(per.initial,14249n);assert.equal(per.forecast,3528n);
per=targetPlan(draft,{army:'50000',slots:10},target,records,1500);assert.equal(per.initial,10721n,'Later arrivals do not inflate an earlier flood');
per=targetPlan({...draft,self:{field:'14249'},observed:2500},{army:'50000',slots:10},target,records,3000);assert.equal(per.initial,14249n,'Arrived gains already in the member snapshot are not added twice');
per=targetPlan({...draft,self:{field:'10721',max:'3600'}},{army:'50000',slots:10},target,records,3000);assert.equal(per.gain,72n,'Global max subtracts previously sent amounts');
per=targetPlan({...draft,self:{field:'10721',final:'14299'}},{army:'50000',slots:10},target,records,1500);assert.equal(per.gain,50n,'Later pending gains still count toward a final terrain goal');
const fake={performance:{timeOrigin:0,now:()=>100},sessionStorage:{getItem:()=>JSON.stringify({version:1,globalAtReference:0,wallAtReference:Date.now()-100,serverAtReference:1000,model:{consistent:true,uncertainty:20}})}};
assert.equal(clock(fake).now,1100);fake.performance.now=()=>600001;assert.equal(clock(fake),null);
console.log('OK: tutorial :59 timing, minute rollover, exact duration override, pending arrival order, no double counting, shared objectives and stale clock.');

const remainderTarget={id:'2',pseudo:'Khan',field:'8711',useRemainingArmy:true},remainderDraft={self:{field:'10721'},excluded:[],observed:1000};
per=targetPlan(remainderDraft,{army:'50000',slots:10},remainderTarget,{},3000);
assert.deepEqual(Array.from(per.attacks,r=>r.amount),[1742n,491n,47767n]);
assert.equal(per.sent,50000n);assert.equal(per.armyLeft,0n);assert.equal(per.gain,3528n);assert.equal(per.attacks.at(-1).capture,1295n);
per=targetPlan({...remainderDraft,excluded:['2:2']},{army:'50000',slots:10},remainderTarget,{},3000);
assert.deepEqual(Array.from(per.attacks,r=>r.amount),[1742n,48258n],'Surplus moves to the last checked flood');
assert.equal(per.gain,3135n,'A reinforced bridge takes up to 20%, not its former small amount');assert.equal(per.final,13856n);
per=targetPlan({...remainderDraft,excluded:['2:0','2:1','2:2']},{army:'50000',slots:10},remainderTarget,{},3000);
assert.equal(per.sent,0n);assert.equal(per.armyLeft,50000n);assert.equal(per.attacks.length,0);
per=targetPlan({...remainderDraft,self:{field:'10721',max:'50'}},{army:'50000',slots:10},remainderTarget,{},3000);
assert.equal(per.sent,50000n);assert.equal(per.gain,1742n,'Actual capture reflects the larger last flood even with a smaller goal');
per=targetPlan(remainderDraft,{army:'351',slots:2},remainderTarget,{},3000);assert.equal(per.sent,351n);assert.equal(per.gain,351n);
per=targetPlan(remainderDraft,{army:'50000',slots:0},remainderTarget,{},3000);assert.equal(per.sent,0n);
per=targetPlan(remainderDraft,{army:'50000',slots:10},{...remainderTarget,useRemainingArmy:false},{},3000);assert.equal(per.sent,3528n);assert.equal(per.armyLeft,46472n);
console.log('OK: remaining army on last checked flood, capture ceiling, bridge reinforcement, smaller objectives, empty selection and no free slots.');

const campaign=scope.multifloodCampaignPlan;
const campaignDraft={self:{field:'10721'},observed:1000,excluded:[],targets:[{id:'2',pseudo:'Khan',field:'9711',distance:10},{id:'3',pseudo:'serafino',field:'8147',distance:20}]};
const arrivals={'2':5000,'3':9000};
let series=campaign(campaignDraft,{army:'361',slots:2},{},arrivals);
assert.equal(series[0].result.sent,361n);assert.equal(series[1].result.sent,0n);assert.equal(series[1].result.initial,11082n,'Screenshot: later target includes the first planned gain');
assert.equal(series[1].result.attacks.length,0,'Screenshot: the same 361 ants cannot be allocated twice');
const splitDraft={...campaignDraft,targets:campaignDraft.targets.map((p,i)=>({...p,max:i===0?'100':''}))};
series=campaign(splitDraft,{army:'361',slots:2},{},arrivals);
assert.equal(series[0].result.sent,100n);assert.equal(series[1].result.sent,261n);assert.equal(series[1].result.initial,10821n);
series=campaign({...campaignDraft,excluded:['2:0']},{army:'361',slots:2},{},arrivals);
assert.equal(series[0].result.sent,0n);assert.equal(series[1].result.sent,361n);assert.equal(series[1].result.initial,10721n);
series=campaign(splitDraft,{army:'361',slots:1},{},arrivals);assert.equal(series[1].result.attacks.length,0,'Slots are shared even before sending');
series=campaign({...splitDraft,self:{field:'10721',max:'150'}},{army:'361',slots:2},{},arrivals);assert.equal(series[1].result.gain,50n,'Global goal is shared by pending targets');
series=campaign(campaignDraft,{army:'361',slots:2},{},{'2':9000,'3':5000});assert.equal(series[0].target.id,'3');assert.equal(series[1].result.initial,11082n,'Arrival times, not distance or click order, determine terrain');
const capped={...campaignDraft,targets:campaignDraft.targets.map((p,i)=>({...p,max:i===0?'100':'200'}))};
const before=campaign(capped,{army:'361',slots:2},{},arrivals);
const laterDone={'3':{state:'done',gain:'200',sent:'200',arrivalAt:9000}};
series=campaign(capped,{army:'161',slots:1},laterDone,arrivals);
assert.equal(series.length,1);assert.equal(series[0].result.initial,10721n);assert.equal(series[0].result.sent,before[0].result.sent,'Sending the later arrival first preserves the earlier reservation');
series=campaign(capped,{army:'261',slots:1},{'2':{state:'done',gain:'100',arrivalAt:5000}},arrivals);
assert.equal(series[0].result.initial,before[1].result.initial);assert.equal(series[0].result.sent,before[1].result.sent,'Confirmed gains replace planned gains without double counting');
series=campaign({...capped,self:{field:'10821'},observed:6000},{army:'261',slots:1},{'2':{state:'done',gain:'100',arrivalAt:5000}},arrivals);assert.equal(series[0].result.initial,10821n);
const reinforced={...splitDraft,targets:splitDraft.targets.map((p,i)=>({...p,useRemainingArmy:i===1}))};
series=campaign(reinforced,{army:'50000',slots:10},{},arrivals);assert.equal(series[0].result.sent+series[1].result.sent,50000n);assert(series[1].result.sent>series[1].result.gain);
series=campaign({self:{field:'1000'},observed:0,excluded:[],targets:[{id:'2',field:'1000'},{id:'3',field:'500'}]},{army:'50000',slots:10},{},arrivals);assert.equal(series[1].result.attacks.length,0,'Earlier planned gains can put a later target out of range');
console.log('OK: shared campaign army, slots and goals; planned terrain; deselection; arrival order; reverse sends; confirmed/arrived gains; remainder and changing range.');
series=campaign({...campaignDraft,self:{field:'10721',max:'200'}},{army:'361',slots:2},{'9':{state:'done',gain:'300',arrivalAt:3000}},arrivals);
assert.equal(series[0].result.gain,200n);assert.equal(series[0].result.initial,11021n,'Older unrelated selections affect arrival terrain, not the new cap');
series=campaign({...campaignDraft,self:{field:'10721',max:'200'}},{army:'361',slots:2},{'history:0':{state:'done',gain:'300',arrivalAt:3000,countsTowardGoal:false}},arrivals);
assert.equal(series[0].result.gain,200n);assert.equal(series[1].result.gain,0n);assert.match(series[1].result.groups[0].reason,/Prise maximale totale/);
