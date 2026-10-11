(function (root) {
'use strict';
// Integer weights and largest remainders preserve every unit of wood.
function allocate(players) {
  if (!players.length) return {pool:0n, rows:[]};
  for (const p of players) {
    if (!/^\d+$/.test(String(p.field)) || !Number.isInteger(p.tdp) || p.tdp<0 || p.tdp>2997) throw new Error('Vérifie les terrains et les niveaux TDP (entiers de 0 à 999).');
  }
  const min=Math.min(...players.map(p=>p.tdp)), max=Math.max(...players.map(p=>p.tdp));
  const weights=players.map(p=>6n**BigInt(p.tdp-min)*5n**BigInt(max-p.tdp));
  const total=weights.reduce((a,b)=>a+b,0n);
  const pool=players.reduce((a,p)=>a+BigInt(p.field)*2n,0n);
  const rows=players.map((p,i)=>({id:p.id,production:BigInt(p.field)*2n,share:pool*weights[i]/total,remainder:pool*weights[i]%total}));
  let remaining=pool-rows.reduce((a,p)=>a+p.share,0n);
  const ranked=rows.map((p,i)=>({p,i})).sort((a,b)=>a.p.remainder===b.p.remainder?a.i-b.i:a.p.remainder>b.p.remainder?-1:1);
  for(const {p} of ranked){if(remaining===0n)break;p.share++;remaining--;}
  return {pool,rows};
}
if(typeof module==='object' && module.exports){module.exports={allocate};return;}
const elements=Array.from(document.querySelectorAll('[data-resource-player]'));
if(!elements.length)return;
const button=document.getElementById('resource-simulate'), error=document.getElementById('resource-error');
const balances=elements.map(()=>0n);let hours=0,preview,sortKey=null,sortDirection=-1,convoyCount=0;
const format=n=>n.toLocaleString('fr-FR');
function refresh(){
  try {
    const players=elements.map(el=>{
      const levels=Array.from(el.querySelectorAll('input')).map(input=>{
        if(!input.checkValidity() || !/^\d+$/.test(input.value))throw new Error('Renseigne chaque niveau fictif avec un entier de 0 à 999.');
        return Number(input.value);
      });
      const tdp=levels.reduce((a,b)=>a+b,0);el.querySelector('[data-value="tdp"]').textContent=tdp;
      return {id:el.dataset.resourcePlayer,field:el.dataset.field,tdp};
    });
    preview=allocate(players);
    elements.forEach((el,i)=>{
      el.querySelector('[data-value="production"]').textContent=format(preview.rows[i].production);
      el.querySelector('[data-value="share"]').textContent=format(preview.rows[i].share);
    });
    document.getElementById('resource-pool').textContent=format(preview.pool);
    error.hidden=true;button.disabled=false;applySort();return true;
  }catch(ex){preview=null;error.textContent=ex.message;error.hidden=false;button.disabled=true;return false;}
}
function renderBalances(){
  elements.forEach((el,i)=>{const cell=el.querySelector('[data-value="balance"]'), value=balances[i];cell.textContent=(value>0n?'+':'')+format(value);cell.className=value<0n?'resource-negative':value>0n?'resource-positive':'';});
  document.getElementById('resource-hours').textContent=format(hours);
  document.getElementById('resource-total').textContent=format(balances.reduce((a,b)=>a+b,0n));
  applySort();
}
function applySort(){
  if(!sortKey)return;
  const value=i=>sortKey==='balance'?balances[i]:sortKey==='field'?(/^\d+$/.test(elements[i].dataset.field)?BigInt(elements[i].dataset.field):null):(/^\d+$/.test(elements[i].querySelector('[data-value="tdp"]').textContent)?BigInt(elements[i].querySelector('[data-value="tdp"]').textContent):null);
  const indices=elements.map((_,i)=>i).sort((a,b)=>{const x=value(a),y=value(b);if(x===null||y===null)return x===y?a-b:x===null?1:-1;return x===y?a-b:(x<y?-1:1)*sortDirection;});
  const body=elements[0].parentElement;indices.forEach(i=>body.append(elements[i]));
}
document.querySelectorAll('[data-resource-sort]').forEach(control=>control.addEventListener('click',()=>{
  sortDirection=sortKey===control.dataset.resourceSort?-sortDirection:-1;sortKey=control.dataset.resourceSort;
  document.querySelectorAll('[data-resource-sort]').forEach(c=>{const active=c===control;c.parentElement.setAttribute('aria-sort',active?(sortDirection===1?'ascending':'descending'):'none');c.querySelector('span').textContent=active?(sortDirection===1?' ↑':' ↓'):' ↕';});applySort();
}));
const sender=document.getElementById('convoy-sender'),recipient=document.getElementById('convoy-recipient'),convoyStatus=document.getElementById('convoy-status'),history=document.getElementById('convoy-history');
const names=elements.map(el=>el.querySelector('.user-display-name').textContent);
elements.forEach((el,i)=>{for(const select of [sender,recipient]){const option=document.createElement('option');option.value=el.dataset.resourcePlayer;option.textContent=names[i];select.append(option);}});
if(elements.length>1)recipient.selectedIndex=1;
document.getElementById('resource-convoy-form').addEventListener('submit',event=>{
  event.preventDefault();
  const from=elements.findIndex(el=>el.dataset.resourcePlayer===sender.value),to=elements.findIndex(el=>el.dataset.resourcePlayer===recipient.value),raw=document.getElementById('convoy-amount').value;
  if(from<0||to<0||from===to){convoyStatus.textContent='Choisis deux membres différents.';return;}
  if(!/^\d{1,30}$/.test(raw)||BigInt(raw)<=0n){convoyStatus.textContent='Saisis une quantité entière de bois supérieure à zéro (30 chiffres maximum).';return;}
  const amount=BigInt(raw);balances[from]+=amount;balances[to]-=amount;convoyCount++;renderBalances();
  const item=document.createElement('li'),title=document.createElement('strong'),detail=document.createElement('span');
  title.textContent=names[from]+' → '+names[to]+' · '+format(amount)+' bois';
  detail.textContent='Convoi n° '+convoyCount+' · après '+hours+' h simulée'+(hours>1?'s':'')+' · '+new Date().toLocaleTimeString('fr-FR',{timeZone:'Europe/Paris'})+' (Paris)';
  item.append(title,detail);history.prepend(item);document.getElementById('convoy-empty').hidden=true;document.getElementById('convoy-count').textContent=convoyCount+' convoi'+(convoyCount>1?'s':'');
  convoyStatus.textContent='Convoi simulé : '+format(amount)+' bois de '+names[from]+' vers '+names[to]+'. Soldes mis à jour.';
  document.getElementById('convoy-amount').value='';
});
elements.forEach(el=>el.addEventListener('input',refresh));
button.addEventListener('click',()=>{if(!refresh())return;preview.rows.forEach((p,i)=>{balances[i]+=p.share-p.production;});hours++;renderBalances();document.getElementById('resource-status').textContent=`${hours} heure${hours>1?'s':''} simulée${hours>1?'s':''}. Soldes équilibrés.`;});
document.getElementById('resource-reset').addEventListener('click',()=>{balances.fill(0n);hours=0;convoyCount=0;history.replaceChildren();document.getElementById('convoy-empty').hidden=false;document.getElementById('convoy-count').textContent='0 convoi';convoyStatus.textContent='';document.getElementById('convoy-amount').value='';renderBalances();document.getElementById('resource-status').textContent='Soldes, heures et historique remis à zéro. Niveaux fictifs conservés.';});
refresh();
})(typeof globalThis==='undefined'?this:globalThis);
