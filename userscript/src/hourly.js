function readEconomy(doc){
 const values={};// Resources only: workers are read on Reine.php (readGameWorkers), where the game gives its own count.
 for(const [key,id] of Object.entries({materiaux:'nb_materiaux',nourriture:'nb_nourriture',terrain:'quantite_tdc'})){
  const text=doc.getElementById(id)?.textContent.replace(/[\s\u00a0\u202f]/g,'');if(!text||!/^\d+(?:[.,]\d+)?$/.test(text))return null;
  const value=BigInt(text.split(/[.,]/)[0]);if(value>9223372036854775807n)return null;values[key]=String(value);
 }return {group:'economy',values};
}
function mountHourlyCollection(context,auth){
 const doc=context.document,win=context.window,key='uptep:economy:s5:'+encodeURIComponent(context.pseudo),leaseKey=key+':pending';
 const read=key=>{try{return JSON.parse(win.localStorage.getItem(key));}catch{return null;}};
 const write=(key,value)=>{try{win.localStorage.setItem(key,JSON.stringify(value));}catch{}};
 const recent=(stamp,duration)=>Number.isFinite(stamp)&&Date.now()-stamp>=0&&Date.now()-stamp<duration;
 const blocked=()=>recent(read(key)?.at,300000)||recent(read(leaseKey)?.at,30000);
 // Check the browser cache before reading the DOM or creating any network frame.
 if(!auth||blocked())return ()=>{};
 let frame=null,timeout=null,stopped=false,release=null;
 function cleanup(){stopped=true;win.clearTimeout(timeout);win.removeEventListener('message',receive);if(frame){frame.removeEventListener('load',send);frame.remove();}if(release){release();release=null;}}
 let collection=null;
 function send(){if(!stopped)frame?.contentWindow?.postMessage({type:'uptep-auth',token:auth.token,pseudo:context.pseudo,collection},'https://uptep.top');}
 function receive(e){if(!frame||e.source!==frame.contentWindow||e.origin!=='https://uptep.top')return;if(e.data?.type==='uptep-ready')send();if(e.data?.type==='uptep-collection-result'){if(e.data.ok===true)write(key,{at:Date.now()});cleanup();}}
 function start(){
  if(stopped||blocked()){cleanup();return;}collection=readEconomy(doc);if(!collection){cleanup();return;}
  // The short shared lease also limits retries on navigation or network failure.
  write(leaseKey,{at:Date.now()});
  frame=doc.createElement('iframe');frame.hidden=true;frame.title='Synchronisation des ressources UPTEP+';frame.referrerPolicy='no-referrer';frame.src='https://uptep.top/portal.php?bridge=1&hourly=1';
  win.addEventListener('message',receive);frame.addEventListener('load',send);timeout=win.setTimeout(cleanup,20000);doc.body.append(frame);
 }
 // Web Locks serialize simultaneous tabs; the storage lease is the fallback.
 if(win.navigator.locks?.request){win.navigator.locks.request(key,{ifAvailable:true},lock=>{if(!lock||stopped)return;return new Promise(resolve=>{release=resolve;start();});}).catch(()=>{if(!stopped)start();});}else start();
 return cleanup;
}
