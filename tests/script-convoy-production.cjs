const assert=require('node:assert/strict'),fs=require('node:fs');
const {estimate,duration}=new Function(fs.readFileSync(__dirname+'/../userscript/src/convoys.js','utf8')+'\nreturn {estimate:convoyProductionEstimate,duration:convoyProductionDuration};')();
const d={nbpomme_restant:'100',nbmateriaux_restant:'100',production:{tdc:'80',capacity_per_cycle:'0'}};
assert.equal(estimate(d).cycles,3n);assert.equal(duration(3n),'1 h 30 min');
// After a convoy the stored remainder is the only balance used; time is never subtracted.
const delivered={...d,nbmateriaux_restant:'20'};assert.equal(estimate(delivered).cycles,2n);assert.equal(estimate(delivered).cycles,2n);
assert.equal(estimate({...d,nbpomme_restant:'0',nbmateriaux_restant:'0'}).cycles,0n);
assert.equal(estimate({...d,nbpomme_restant:'0',nbmateriaux_restant:'80'}).cycles,1n);
assert.equal(estimate({...d,nbpomme_restant:'0',nbmateriaux_restant:'81'}).cycles,2n);
for(const auto_production of [true,false])assert.equal(estimate({...d,auto_production}).cycles,3n);
assert.equal(estimate({...d,production:{tdc:'0',capacity_per_cycle:'0'}}).cycles,null);
assert.equal(estimate({...d,production:{tdc:'100',capacity_per_cycle:'0'}}).cycles,2n);
assert.equal(estimate({...d,production:{tdc:'100',capacity_per_cycle:'101'}}).cycles,2n);
for(const bad of [null,undefined,'-1','1.5','NaN','9223372036854775808'])assert.equal(estimate({...d,production:{tdc:bad}}).cycles,null);
assert.equal(estimate({...d,nbpomme_restant:'9223372036854775807',nbmateriaux_restant:'9223372036854775807',production:{tdc:'1',capacity_per_cycle:'1'}}).cycles,18446744073709551614n);
assert.equal(duration(48n),'1 j');console.log('Production estimates: rounding, convoy updates, all demands, missing/zero data and exact large integers OK');

const projection={anchor_at:'2026-10-18T10:00:00Z',anchor_kind:'validation',calculated_at:'2026-10-18T11:15:00Z',elapsed_cycles:'2',history_complete:true,segments:[{cycles:'1',capacity_per_cycle:'20'},{cycles:'1',capacity_per_cycle:'40'}]};
const anchored={...d,production:{...d.production,projection}};
assert.equal(estimate(anchored).harvested,60n);assert.equal(estimate(anchored).remaining,140n);assert.equal(estimate(anchored).cycles,2n);assert.equal(estimate(anchored).minutes,45n);assert.equal(estimate(anchored).finish_at,Date.parse('2026-10-18T12:00:00Z'));
assert.equal(estimate({...anchored,nbpomme_restant:'20',nbmateriaux_restant:'80'}).cycles,1n,'receipt reduces real remainder once, harvested production unchanged');
assert.equal(estimate({...anchored,production:{...anchored.production,projection:{...projection,anchor_kind:'adjustment',anchor_at:'2026-10-18T11:15:00Z',elapsed_cycles:'0',segments:[]}}}).harvested,0n);
assert.equal(estimate({...anchored,nbpomme_restant:'0',nbmateriaux_restant:'10'}).cycles,0n,'covered projection does not mutate actual remainder');
assert.equal(estimate({...anchored,production:{...anchored.production,projection:{...projection,history_complete:false}}}).cycles,null);
assert.equal(anchored.nbpomme_restant,'100');assert.equal(anchored.nbmateriaux_restant,'100');console.log('Anchored projection: past harvest, future ETA, convoy accounting, manual rebase and unchanged real balance OK');

const colonyExpression={own:'1000',loss:{terrain:'1000',percent:22},gains:[{terrain:'500',percent:20}]};
const colonies={nbpomme_restant:'0',nbmateriaux_restant:'1760',production:{tdc:'1000',colonies_included:true,capacity_per_cycle:colonyExpression}};
assert.equal(estimate(colonies).capacity,880n);assert.equal(estimate(colonies).cycles,2n);
const master={...colonies,production:{tdc:'2000',colonies_included:true,capacity_per_cycle:{own:'2000',loss:null,gains:[{terrain:'1000',percent:22}]}}};assert.equal(estimate(master).capacity,2220n,'colony income may exceed own terrain');
assert.equal(estimate({...colonies,production:{tdc:'0',colonies_included:true,capacity_per_cycle:{own:'0',loss:null,gains:[{terrain:'101',percent:22}]}}}).capacity,22n);
const oldColony={...colonies,production:{...colonies.production,projection:{...projection,segments:[{cycles:'2',capacity_per_cycle:colonyExpression}]}}};assert.equal(estimate(oldColony).harvested,1760n);assert.equal(estimate(oldColony).cycles,0n);
const largeColony={...colonies,production:{tdc:'9223372036854775807',colonies_included:true,capacity_per_cycle:{own:'9223372036854775807',loss:{terrain:'9223372036854775807',percent:22},gains:[]}}};assert.equal(estimate(largeColony).capacity,7194230188746725130n);
console.log('Colony arithmetic OK: equal debit/credit, rounding, historical production, income above own terrain and exact large integers');
