function mountClock(context,root){
 const win=context.window,document=context.document,performance=win.performance;
 const el=(tag,text)=>{const n=document.createElement(tag);if(text!==undefined)n.textContent=text;return n;};
 const bridge={getOption:(key,fallback)=>{try{return JSON.parse(win.sessionStorage.getItem(key))||fallback;}catch{return fallback;}},setOption:(key,value)=>{try{win.sessionStorage.setItem(key,JSON.stringify(value));}catch{}}};
 let cleanup=()=>{};
function estimateClock(samples){
 const valid=samples.filter(s=>Number.isFinite(s.serverMs)&&Number.isFinite(s.start)&&Number.isFinite(s.end)&&s.end>=s.start);
 if(!valid.length)throw Error('Aucune mesure valide');
 const lower=Math.max(...valid.map(s=>s.serverMs-s.end));
 const upper=Math.min(...valid.map(s=>s.serverMs+1000-s.start));
 const fastest=valid.reduce((a,b)=>a.end-a.start<=b.end-b.start?a:b);
 const consistent=lower<=upper;
 const lo=consistent?lower:fastest.serverMs-fastest.end;
 const hi=consistent?upper:fastest.serverMs+1000-fastest.start;
 return {offset:(lo+hi)/2,uncertainty:(hi-lo)/2,rtt:fastest.end-fastest.start,consistent,samples:valid.length};
}


 // Estimate the HTTP server clock directly from the visitor's connection.
 {
  const clock=el('div');clock.className='uptep-s5-clock';clock.style.cssText='position:relative;box-sizing:border-box;border-top:1px solid #898d70;margin:0 8px;padding:10px 0 9px;color:#e5edce;text-align:center;';
  const title=el('div','S5 · Heure de Paris');title.style.cssText='font-size:10px;color:#b7cd99';
  const digits=el('strong','--:--:--');digits.className='clock-digits';digits.style.cssText='display:block;font-size:23px;font-variant-numeric:tabular-nums;letter-spacing:1px;margin:3px 0';
  const accuracy=el('div','Synchronisation…');accuracy.className='clock-accuracy';accuracy.style.cssText='font-size:9px;color:#bad29c';
  const resync=el('button','↻');resync.type='button';resync.setAttribute('aria-label','Resynchroniser l’heure S5');resync.style.cssText='position:absolute;top:4px;right:5px;border:0;background:transparent;color:#c5dbac;font-size:16px;cursor:pointer';clock.append(title,digits,accuracy,resync);root.append(clock);
  const feedback=el('div');feedback.className='clock-sync-status';feedback.setAttribute('role','status');feedback.style.cssText='font-size:10px;color:#e5edce;min-height:13px;margin-top:4px;';
  const icon=el('span','↻');icon.className='clock-sync-icon';icon.setAttribute('aria-hidden','true');resync.replaceChildren(icon);
  const animation=el('style');animation.textContent='@keyframes uptep-clock-spin{to{transform:rotate(360deg)}}.uptep-s5-clock .clock-sync-icon{display:inline-block}.uptep-s5-clock button[aria-busy="true"]{cursor:wait}.uptep-s5-clock button[aria-busy="true"] .clock-sync-icon{animation:uptep-clock-spin .8s linear infinite}@media(prefers-reduced-motion:reduce){.uptep-s5-clock button[aria-busy="true"] .clock-sync-icon{animation:none}}';clock.append(feedback,animation);
  let model=null,busy=false,tick=null,lastSync=0,lastWall=0,disposed=false;
  const formatter=new Intl.DateTimeFormat('fr-FR',{timeZone:'Europe/Paris',hour:'2-digit',minute:'2-digit',second:'2-digit',hourCycle:'h23'});
  const cacheKey='uptep_s5_clock_cache_v1';
  const globalNow=()=>performance.timeOrigin+performance.now();
  function saveClock(){
   if(!model)return;
   bridge.setOption?.(cacheKey,{version:1,serverAtReference:performance.now()+model.offset,globalAtReference:globalNow(),wallAtReference:Date.now(),model:{uncertainty:model.uncertainty,rtt:model.rtt,consistent:model.consistent,samples:model.samples}});
  }
  function restoreClock(){
   const cached=bridge.getOption?.(cacheKey,null);if(!cached||cached.version!==1)return false;
   const age=globalNow()-cached.globalAtReference,wallAge=Date.now()-cached.wallAtReference;
   if(!Number.isFinite(age)||age<0||age>600000||Math.abs(wallAge-age)>2000||!Number.isFinite(cached.serverAtReference)||!Number.isFinite(cached.model?.uncertainty)||!Number.isFinite(cached.model?.rtt))return false;
   if(model&&cached.globalAtReference<=performance.timeOrigin+lastSync)return false;
   model={...cached.model,offset:cached.serverAtReference+age-performance.now()};lastSync=performance.now()-age;lastWall=cached.wallAtReference;display();return true;
  }

  let controller=null;
  const sample=async function(){
   controller=new win.AbortController();const timeout=win.setTimeout(()=>controller?.abort(),8000);
   try{const start=performance.now();const response=await win.fetch(win.location.origin+'/api/exports/?_uptep_clock='+Date.now()+'-'+Math.random(),{method:'HEAD',cache:'no-store',credentials:'omit',signal:controller.signal});const end=performance.now();
    if(!response.ok||response.redirected)throw Error('Réponse HTTP S5 indisponible');const serverMs=Date.parse(response.headers.get('Date')),age=Number(response.headers.get('Age')||0);
    if(!Number.isFinite(serverMs)||!Number.isFinite(age)||age>0)throw Error('Heure HTTP absente ou réponse en cache');return {start,end,serverMs};
   }finally{win.clearTimeout(timeout);controller=null;}
  };

  function display(){
   clearTimeout(tick);if(disposed||!model)return;
   const now=performance.now()+model.offset;digits.textContent=(model.consistent?'':'≈ ')+formatter.format(now);
   const drift=Math.max(0,performance.now()-lastSync)*.0001;
   accuracy.textContent=model.consistent?`RTT ${Math.round(model.rtt)} ms · ±${Math.ceil(model.uncertainty+drift)} ms estimés`:`RTT ${Math.round(model.rtt)} ms · source instable`;
   clock.title=`Source : Date HTTP de S5. ${model.samples} mesures. ${model.consistent?'Intervalles cohérents.':'Réponses incohérentes : approximation sur la réponse la plus rapide.'} Incertitude estimée, pas une garantie sur l’horloge du moteur du jeu. Écart estimé au PC : ${Math.round(now-Date.now())} ms. Cliquer ↻ pour resynchroniser.`;
   tick=setTimeout(display,Math.max(25,Math.min(1000,1000-((now%1000)+1000)%1000+3)));
  }
  async function synchronize(){
   if(busy||disposed)return;busy=true;resync.disabled=true;resync.setAttribute('aria-busy','true');resync.setAttribute('aria-label','Synchronisation en cours');feedback.textContent='Synchronisation en cours…';if(!model)accuracy.textContent='Mesure de la latence…';const samples=[],count=model?10:16;const provisional=!model;
   try{
    for(let i=0;i<count;i++){
     if(disposed)return;
     try{samples.push(await sample());}catch(e){if(i>=2&&!samples.length)throw e;}
     if(disposed)return;
     if(provisional&&samples.length&&(samples.length===1||samples.length%4===0)){model=estimateClock(samples);lastSync=performance.now();lastWall=Date.now();saveClock();display();}
     if(i<count-1)await new Promise(r=>setTimeout(r,140));
    }
    model=estimateClock(samples);lastSync=performance.now();lastWall=Date.now();saveClock();display();feedback.textContent='✓ Heure synchronisée';
   }catch(e){if(disposed)return;feedback.textContent=model?'Échec · dernière synchronisation conservée':'Échec de la synchronisation';if(!model){digits.textContent='Indisponible';accuracy.textContent=e.message;}else{display();accuracy.textContent+=' · ancien relevé';}}
   finally{busy=false;resync.disabled=false;resync.setAttribute('aria-busy','false');resync.setAttribute('aria-label','Resynchroniser l’heure S5');}
  }
  resync.onclick=synchronize;
  const onVisibility=()=>{if(!document.hidden){restoreClock();display();if(!model||performance.now()-lastSync>=300000||Math.abs((Date.now()-lastWall)-(performance.now()-lastSync))>2000)synchronize();}};document.addEventListener('visibilitychange',onVisibility);
  const syncTimer=setInterval(()=>{if(!document.hidden){restoreClock();if(!model||performance.now()-lastSync>=300000)synchronize();}},60000);
  cleanup=()=>{disposed=true;controller?.abort();clearTimeout(tick);clearInterval(syncTimer);document.removeEventListener('visibilitychange',onVisibility);clock.remove();};
  restoreClock();
  if(!model||performance.now()-lastSync>=300000)synchronize();
 }

 return ()=>cleanup();
}
