function mountTraffic(context,data,serverTime){
 const doc=context.document,win=context.window;
 if(!data||!Array.isArray(data.outgoing)||!Array.isArray(data.incoming))return ()=>{};
 const make=(tag,text,parent)=>{const n=doc.createElement(tag);if(text!==null)n.textContent=text;if(parent)parent.append(n);return n;};
 const style=make('style',`#uptep-traffic{position:fixed;top:50px;right:15px;width:220px;max-width:calc(100vw - 24px);z-index:150;box-sizing:border-box;color:#302d1c;font:11px/1.45 Verdana,sans-serif;text-align:left;border:1px solid #625c39;border-radius:5px;background:#e0d3a2;box-shadow:0 2px 8px #0003}#uptep-traffic *{box-sizing:border-box}#uptep-traffic>summary{cursor:pointer;padding:9px 10px;background:#4f5940;color:#f1e8c9;border-radius:4px;font-weight:bold}#uptep-traffic>summary:focus-visible{outline:2px solid #e8ba63;outline-offset:2px}#uptep-traffic .uptep-traffic-total{float:right;font-variant-numeric:tabular-nums;font-size:10px;background:#ffffff18;border-radius:8px;padding:0 6px}#uptep-traffic .uptep-traffic-body{padding:0 9px 7px;max-height:calc(100vh - 120px);overflow:auto}#uptep-traffic h3{font:700 11px/1.4 Verdana,sans-serif;margin:10px 0 5px;color:#55583b}#uptep-traffic ul{list-style:none;margin:0;padding:0;max-height:26vh;overflow:auto;overscroll-behavior:contain;scrollbar-width:thin}#uptep-traffic li{padding:7px 0;border-top:1px solid #b8aa762e;overflow-wrap:anywhere}#uptep-traffic li:first-child{border-top:0}#uptep-traffic .uptep-traffic-target{font-weight:bold}#uptep-traffic .uptep-traffic-kind{font-size:10px;color:#686047}#uptep-traffic .uptep-traffic-meta{color:#665e40;font-size:10px;margin:2px 0}#uptep-traffic .uptep-traffic-arrival{font-size:10px;font-variant-numeric:tabular-nums}#uptep-traffic .uptep-traffic-countdown{font-weight:bold;color:#485439}#uptep-traffic .uptep-traffic-empty{font-size:10px;color:#71684a;margin:5px 0 10px}#uptep-traffic .uptep-traffic-note{font-size:9px;color:#71684a;border-top:1px solid #b8aa76;padding-top:6px;margin:8px 0 0}#uptep-traffic[data-overlay="true"]{top:auto;bottom:12px;right:12px!important;width:250px}#uptep-traffic[data-overlay="true"]:not([open]){width:auto}#uptep-traffic[data-overlay="true"] .uptep-traffic-body{max-height:65vh}`,doc.head);
 const box=make('details',null,doc.body);box.id='uptep-traffic';box.setAttribute('aria-label','Mes floods et convois en cours');
 const summary=make('summary',null,box);make('span','Floods & convois',summary);const total=make('span','0',summary);total.className='uptep-traffic-total';
 const body=make('div',null,box);body.className='uptep-traffic-body';
 const clock=Date.parse(serverTime),reference=win.performance.now();
 const now=()=>Number.isFinite(clock)?clock+win.performance.now()-reference:Date.now();
 const date=new Intl.DateTimeFormat('fr-FR',{timeZone:'Europe/Paris',day:'2-digit',month:'2-digit',hour:'2-digit',minute:'2-digit'});
 const exact=new Intl.DateTimeFormat('fr-FR',{timeZone:'Europe/Paris',dateStyle:'short',timeStyle:'medium'});
 const format=value=>{try{return BigInt(value).toLocaleString('fr-FR');}catch{return '—';}};
 const duration=ms=>{let s=Math.max(0,Math.ceil(ms/1000));const days=Math.floor(s/86400);s%=86400;const h=Math.floor(s/3600),m=Math.floor(s%3600/60);return days?`${days} j ${h} h`:h?`${h} h ${String(m).padStart(2,'0')} min`:m?`${m} min ${String(s%60).padStart(2,'0')} s`:`${s} s`;};
 const groups=[];
 for(const [direction,label] of [['outgoing','Envois'],['incoming','Réceptions']]){
  const section=make('section',null,body);section.dataset.direction=direction;
  const heading=make('h3',label,section);const list=make('ul',null,section);list.setAttribute('aria-label',label+' en cours');
  const empty=make('p',direction==='outgoing'?'Aucun envoi en cours.':'Aucune réception en cours.',section);empty.className='uptep-traffic-empty';
  const entries=[];
  for(const item of [...data[direction]].sort((a,b)=>Date.parse(a.arrival_at)-Date.parse(b.arrival_at))){
   const arrival=Date.parse(item.arrival_at);if(!Number.isFinite(arrival)||arrival<=now()||!['convoy','flood'].includes(item.kind))continue;
   const li=make('li',null,list);li.dataset.kind=item.kind;li.dataset.id=item.id;
   make('div',(item.kind==='flood'?'⚔ Flood':'📦 Convoi')+' · '+(direction==='outgoing'?'vers':'de'),li).className='uptep-traffic-kind';
   make('div',item.pseudo,li).className='uptep-traffic-target';
   make('div',item.kind==='convoy'?`🍎 ${format(item.food)} · 🪵 ${format(item.materials)}`:item.location||'Lieu non renseigné',li).className='uptep-traffic-meta';
   const time=make('div',null,li);time.className='uptep-traffic-arrival';const countdown=make('span','',time);countdown.className='uptep-traffic-countdown';
   const at=make('time',' · '+date.format(arrival),time);at.dateTime=item.arrival_at;at.title='Arrivée le '+exact.format(arrival)+' (Paris)';
   entries.push({li,countdown,arrival});
  }
  groups.push({heading,list,empty,entries,label});
 }
 make('p','Trajets déclarés · heures de Paris · actualisation 30 s',body).className='uptep-traffic-note';
 let mode=null,disposed=false;
 const storageKey='uptep:traffic:'+encodeURIComponent(context.pseudo)+':';
 function place(){
  const centre=doc.getElementById('centre');const gap=centre?win.innerWidth-centre.getBoundingClientRect().right:0;
  const overlay=gap<240;box.dataset.overlay=String(overlay);
  if(!overlay)box.style.right=Math.max(12,(gap-220)/2)+'px';
  const next=overlay?'overlay':'sidebar';
  if(mode!==next){mode=next;let saved=null;try{saved=win.sessionStorage.getItem(storageKey+mode);}catch{}box.open=saved===null?!overlay:saved==='open';}
 }
 function toggle(){try{win.sessionStorage.setItem(storageKey+mode,box.open?'open':'closed');}catch{}}
 function tick(){
  if(disposed)return;const current=now();let count=0;
  for(const group of groups){
   group.entries=group.entries.filter(entry=>{if(entry.arrival<=current){entry.li.remove();return false;}entry.countdown.textContent='Dans '+duration(entry.arrival-current);return true;});
   const n=group.entries.length;group.heading.textContent=group.label+' · '+n;group.empty.hidden=n>0;group.list.hidden=n===0;count+=n;
  }
  total.textContent=String(count);total.setAttribute('aria-label',count+' trajets en cours');
 }
 place();tick();box.addEventListener('toggle',toggle);win.addEventListener('resize',place);doc.addEventListener('visibilitychange',tick);
 const timer=win.setInterval(tick,1000);
 return ()=>{disposed=true;win.clearInterval(timer);win.removeEventListener('resize',place);doc.removeEventListener('visibilitychange',tick);box.removeEventListener('toggle',toggle);box.remove();style.remove();};
}
