function mountTraffic(context,data,serverTime){
 const doc=context.document,win=context.window;
 if(!data||!Array.isArray(data.outgoing)||!Array.isArray(data.incoming))return ()=>{};
 const make=(tag,text,parent)=>{const n=doc.createElement(tag);if(text!==null)n.textContent=text;if(parent)parent.append(n);return n;};
 const style=make('style',`#uptep-traffic{position:fixed;top:50px;right:15px;width:300px;max-width:calc(100vw - 24px);z-index:150;box-sizing:border-box;color:#302d1c;font:11px/1.45 Verdana,sans-serif;text-align:left;border:1px solid #625c39;border-radius:5px;background:#e0d3a2;box-shadow:0 2px 8px #0003}#uptep-traffic *{box-sizing:border-box}#uptep-traffic [hidden]{display:none!important}#uptep-traffic>summary{cursor:pointer;padding:9px 10px;background:#4f5940;color:#f1e8c9;border-radius:4px;font-weight:bold}#uptep-traffic :focus-visible{outline:2px solid #76551d;outline-offset:2px}#uptep-traffic>summary:focus-visible{outline-color:#e8ba63}#uptep-traffic .uptep-traffic-total{float:right;font-variant-numeric:tabular-nums;font-size:10px;background:#ffffff18;border-radius:8px;padding:0 6px}#uptep-traffic .uptep-traffic-body{padding:0 10px 8px;max-height:calc(100vh - 120px);overflow:auto}#uptep-traffic .uptep-traffic-tabs{display:flex;gap:5px;padding:10px 0 2px}#uptep-traffic .uptep-traffic-tab{flex:1;cursor:pointer;font:700 11px/1.4 Verdana,sans-serif;color:#62583b;background:#ffffff26;border:1px solid #b6a777;border-radius:4px;padding:7px 4px;white-space:nowrap}#uptep-traffic .uptep-traffic-tab[aria-selected="true"]{background:#4f5940;border-color:#4f5940;color:#fff5d6}#uptep-traffic section{--direction:#976018;--tint:#aa711213;margin-top:10px;border-left:3px solid var(--direction);padding:0 7px;background:var(--tint);border-radius:3px}#uptep-traffic section[data-direction="incoming"]{--direction:#2e7067;--tint:#287c7212}#uptep-traffic h3{display:flex;align-items:center;gap:5px;font:700 11px/1.4 Verdana,sans-serif;margin:0;padding:7px 0 3px;color:var(--direction)}#uptep-traffic .uptep-traffic-direction-icon{font-size:17px;line-height:1}#uptep-traffic ul{list-style:none;margin:0;padding:0;max-height:26vh;overflow:auto;overscroll-behavior:contain;scrollbar-width:thin}#uptep-traffic li{padding:7px 0;border-top:1px solid #b8aa7655;overflow-wrap:anywhere}#uptep-traffic li:first-child{border-top:0}#uptep-traffic .uptep-traffic-route{display:flex;gap:6px;align-items:baseline;font-size:11px}#uptep-traffic .uptep-traffic-target{font-weight:bold;min-width:0}#uptep-traffic .uptep-traffic-me{font-size:10px;color:#71684a;flex-shrink:0}#uptep-traffic .uptep-traffic-arrow{font-size:16px;color:var(--direction);font-weight:bold;line-height:1}#uptep-traffic .uptep-traffic-meta{color:#665e40;font-size:10px;margin:2px 0}#uptep-traffic .uptep-traffic-arrival{font-size:10px;font-variant-numeric:tabular-nums}#uptep-traffic .uptep-traffic-countdown{font-weight:bold;color:#485439}#uptep-traffic .uptep-traffic-empty{font-size:10px;color:#71684a;margin:3px 0 0;padding-bottom:8px}#uptep-traffic .uptep-traffic-note{font-size:9px;color:#71684a;border-top:1px solid #b8aa76;padding-top:6px;margin:10px 0 0}#uptep-traffic[data-overlay="true"]{top:auto;bottom:12px;right:12px!important}#uptep-traffic[data-overlay="true"]:not([open]){width:auto!important}#uptep-traffic[data-overlay="true"] .uptep-traffic-body{max-height:65vh}`,doc.head);
 const box=make('details',null,doc.body);box.id='uptep-traffic';box.setAttribute('aria-label','Mes attaques et convois en cours');
 const summary=make('summary',null,box);make('span','Attaques & convois',summary);const total=make('span','0',summary);total.className='uptep-traffic-total';
 const body=make('div',null,box);body.className='uptep-traffic-body';
 const tablist=make('div',null,body);tablist.className='uptep-traffic-tabs';tablist.setAttribute('role','tablist');tablist.setAttribute('aria-label','Type de trajet');
 const storageKey='uptep:traffic:'+encodeURIComponent(context.pseudo)+':';
 const clock=Date.parse(serverTime),reference=win.performance.now();
 const now=()=>Number.isFinite(clock)?clock+win.performance.now()-reference:Date.now();
 const date=new Intl.DateTimeFormat('fr-FR',{timeZone:'Europe/Paris',day:'2-digit',month:'2-digit',hour:'2-digit',minute:'2-digit'});
 const exact=new Intl.DateTimeFormat('fr-FR',{timeZone:'Europe/Paris',dateStyle:'short',timeStyle:'medium'});
 const format=value=>{try{return BigInt(value).toLocaleString('fr-FR');}catch{return '—';}};
 const duration=ms=>{let s=Math.max(0,Math.ceil(ms/1000));const days=Math.floor(s/86400);s%=86400;const h=Math.floor(s/3600),m=Math.floor(s%3600/60);return days?`${days} j ${h} h`:h?`${h} h ${String(m).padStart(2,'0')} min`:m?`${m} min ${String(s%60).padStart(2,'0')} s`:`${s} s`;};
 const tabs=[],groups=[];
 for(const [kind,label,icon] of [['flood','Attaques','⚔'],['convoy','Convois','📦']]){
  const button=make('button',null,tablist);button.type='button';button.className='uptep-traffic-tab';button.id='uptep-traffic-tab-'+kind;button.setAttribute('role','tab');
  const panel=make('div',null,body);panel.id='uptep-traffic-panel-'+kind;panel.setAttribute('role','tabpanel');panel.setAttribute('aria-labelledby',button.id);panel.tabIndex=0;button.setAttribute('aria-controls',panel.id);
  tabs.push({kind,label,icon,button,panel});
  for(const [direction,directionLabel,arrow] of [['incoming','Vers moi','↙'],['outgoing','Envoyés','↗']]){
   const section=make('section',null,panel);section.dataset.direction=direction;
   const heading=make('h3',null,section);const marker=make('span',arrow,heading);marker.className='uptep-traffic-direction-icon';marker.setAttribute('aria-hidden','true');const headingText=make('span',directionLabel,heading);
   const list=make('ul',null,section);list.setAttribute('aria-label',label+' · '+directionLabel);
   const empty=make('p',direction==='outgoing'?'Aucun envoi en cours.':'Aucune réception en cours.',section);empty.className='uptep-traffic-empty';
   const entries=[];
   for(const item of [...data[direction]].sort((a,b)=>Date.parse(a.arrival_at)-Date.parse(b.arrival_at))){
    const arrival=Date.parse(item.arrival_at);if(item.kind!==kind||!Number.isFinite(arrival)||arrival<=now())continue;
    const li=make('li',null,list);li.dataset.kind=item.kind;li.dataset.id=item.id;
    const route=make('div',null,li);route.className='uptep-traffic-route';route.setAttribute('aria-label',direction==='outgoing'?'De moi vers '+item.pseudo:'De '+item.pseudo+' vers moi');
    make('span',direction==='outgoing'?'Moi':item.pseudo,route).className=direction==='outgoing'?'uptep-traffic-me':'uptep-traffic-target';
    make('span','→',route).className='uptep-traffic-arrow';
    make('span',direction==='outgoing'?item.pseudo:'Moi',route).className=direction==='outgoing'?'uptep-traffic-target':'uptep-traffic-me';
    make('div',item.kind==='convoy'?`🍎 ${format(item.food)} · 🪵 ${format(item.materials)}`:item.location||'Lieu non renseigné',li).className='uptep-traffic-meta';
    const time=make('div',null,li);time.className='uptep-traffic-arrival';const countdown=make('span','',time);countdown.className='uptep-traffic-countdown';
    const at=make('time',' · '+date.format(arrival),time);at.dateTime=item.arrival_at;at.title='Arrivée le '+exact.format(arrival)+' (Paris)';
    entries.push({li,countdown,arrival});
   }
   groups.push({kind,headingText,list,empty,entries,label:directionLabel});
  }
 }
 make('p','Trajets déclarés · heures de Paris · actualisation 30 s',body).className='uptep-traffic-note';
 let selected='flood';try{const saved=win.sessionStorage.getItem(storageKey+'tab');if(tabs.some(t=>t.kind===saved))selected=saved;}catch{}
 function select(kind){selected=kind;for(const tab of tabs){const active=tab.kind===kind;tab.button.setAttribute('aria-selected',String(active));tab.button.tabIndex=active?0:-1;tab.panel.hidden=!active;}try{win.sessionStorage.setItem(storageKey+'tab',kind);}catch{}}
 function clickTab(event){const tab=tabs.find(t=>t.button===event.target.closest('button'));if(tab)select(tab.kind);}
 function keyTab(event){const index=tabs.findIndex(t=>t.button===event.target);if(index<0)return;let next;if(event.key==='ArrowRight')next=(index+1)%tabs.length;else if(event.key==='ArrowLeft')next=(index+tabs.length-1)%tabs.length;else if(event.key==='Home')next=0;else if(event.key==='End')next=tabs.length-1;else return;event.preventDefault();select(tabs[next].kind);tabs[next].button.focus();}
 let mode=null,disposed=false;
 function place(){
  const centre=doc.getElementById('centre');const gap=centre?win.innerWidth-centre.getBoundingClientRect().right:0;
  const overlay=gap<240,width=overlay?300:Math.min(300,gap-24);box.dataset.overlay=String(overlay);box.style.width=width+'px';
  if(!overlay)box.style.right=Math.max(12,(gap-width)/2)+'px';
  const next=overlay?'overlay':'sidebar';
  if(mode!==next){mode=next;let saved=null;try{saved=win.sessionStorage.getItem(storageKey+mode);}catch{}box.open=saved===null?!overlay:saved==='open';}
 }
 function toggle(){try{win.sessionStorage.setItem(storageKey+mode,box.open?'open':'closed');}catch{}}
 function tick(){
  if(disposed)return;const current=now();let count=0;const counts={flood:0,convoy:0};
  for(const group of groups){
   group.entries=group.entries.filter(entry=>{if(entry.arrival<=current){entry.li.remove();return false;}entry.countdown.textContent='Dans '+duration(entry.arrival-current);return true;});
   const n=group.entries.length;group.headingText.textContent=group.label+' · '+n;group.empty.hidden=n>0;group.list.hidden=n===0;count+=n;counts[group.kind]+=n;
  }
  for(const tab of tabs)tab.button.textContent=tab.icon+' '+tab.label+' · '+counts[tab.kind];
  total.textContent=String(count);total.setAttribute('aria-label',count+' trajets en cours');
 }
 select(selected);place();tick();tablist.addEventListener('click',clickTab);tablist.addEventListener('keydown',keyTab);box.addEventListener('toggle',toggle);win.addEventListener('resize',place);doc.addEventListener('visibilitychange',tick);
 const timer=win.setInterval(tick,1000);
 return ()=>{disposed=true;win.clearInterval(timer);win.removeEventListener('resize',place);doc.removeEventListener('visibilitychange',tick);tablist.removeEventListener('click',clickTab);tablist.removeEventListener('keydown',keyTab);box.removeEventListener('toggle',toggle);box.remove();style.remove();};
}
