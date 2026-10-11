const assert=require('node:assert/strict');
const {allocate}=require('../public/assets/ressources.js');
const p=(field,tdp,id='1')=>({field:String(field),tdp,id});
let r=allocate([p(100,0),p(100,1)]);
assert.deepEqual(r.rows.map(x=>x.share),[182n,218n]);
assert.equal(r.pool,400n);
r=allocate([p(10,4),p(90,4)]);
assert.deepEqual(r.rows.map(x=>x.share-x.production),[80n,-80n]);
for(let t=0;t<100;t++){
 const players=Array.from({length:19},(_,i)=>p(1000+i*137,(i*7+t)%45,String(i)));
 const {pool,rows}=allocate(players);
 assert.equal(rows.reduce((s,r)=>s+r.share,0n),pool);
 assert.equal(rows.reduce((s,r)=>s+r.share-r.production,0n),0n);
 assert(rows.every(r=>r.share>=0n));
}
r=allocate([p('9007199254740993',0),p('9007199254740993',2997)]);
assert.equal(r.rows.reduce((s,r)=>s+r.share,0n),36028797018963972n);
assert.equal(allocate([p(0,0)]).pool,0n);
assert.throws(()=>allocate([p(-1,0)]));assert.throws(()=>allocate([p(1,2998)]));
console.log('Resource calculations: exact allocation, signs, rounding, extreme levels and large integers OK');
