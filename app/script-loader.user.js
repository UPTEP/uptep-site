// ==UserScript==
// @name         UPTEP+
// @namespace    https://uptep.top/
// @version      1.0.0
// @description  UPTEP+ pour les membres autorisés de l’alliance UPTEP sur le serveur 5. Fonctionnalités chargées depuis uptep.top.
// @match        https://s5.fourmizzz.fr/*
// @match        http://s5.fourmizzz.fr/*
// @connect      uptep.top
// @grant        GM_xmlhttpRequest
// @grant        GM.xmlHttpRequest
// @run-at       document-end
// @noframes
// @inject-into  content
// @homepageURL  https://uptep.top/script.php
// @downloadURL  none
// @updateURL    none
// ==/UserScript==
(function () {
 'use strict';
 if(location.hostname!=='s5.fourmizzz.fr' || window.top!==window.self)return;
 // Personal installation key. Never share this file or expose the key to the game page.
 const token=__UPTEP_TOKEN__;
 const endpoint='https://uptep.top/script-api.php';
 const gm=typeof GM_xmlhttpRequest==='function'?GM_xmlhttpRequest:(typeof GM!=='undefined'&&typeof GM.xmlHttpRequest==='function'?GM.xmlHttpRequest.bind(GM):null);
 if(!gm){console.warn('UPTEP+ : gestionnaire de userscripts compatible requis.');return;}
 let cleanup=null,revision='',identity='',busy=false,generation=0;
 function player(){return {pseudo:(document.getElementById('pseudo')?.textContent||'').trim(),alliance:(document.getElementById('tag_alliance')?.textContent||'').trim()};}
 function stop(){generation++;if(cleanup){try{cleanup();}catch{}cleanup=null;}document.getElementById('uptep-plus-badge')?.remove();revision='';identity='';}
 function request(data){return new Promise((resolve,reject)=>{
  const options={method:'POST',url:endpoint,anonymous:true,timeout:15000,headers:{'Content-Type':'application/json','Authorization':'Bearer '+token},data:JSON.stringify(data),onload:r=>{
   if(r.status!==200 || (r.finalUrl && new URL(r.finalUrl).origin!=='https://uptep.top'))return reject(new Error('Accès indisponible'));
   try{resolve(JSON.parse(r.responseText));}catch(e){reject(e);}
  },onerror:()=>reject(new Error('Réseau indisponible')),ontimeout:()=>reject(new Error('Délai dépassé'))};
  try{const result=gm(options);if(result&&typeof result.catch==='function')result.catch(reject);}catch(e){reject(e);}
 });}
 async function refresh(){
  const current=player();if(current.alliance!=='UPTEP'||!current.pseudo){stop();return;}
  if(identity && identity!==current.pseudo)stop();
  if(busy)return;busy=true;const turn=generation;
  try{
   const response=await request({...current,revision});const latest=player();
   if(turn!==generation || latest.pseudo!==current.pseudo || latest.alliance!=='UPTEP')return;
   if(response.ok!==true || typeof response.revision!=='string')throw new Error('Accès refusé');
   if(response.revision!==revision){
    stop();if(typeof response.code!=='string')throw new Error('Code indisponible');
    const mount=new Function('context','"use strict";\n'+response.code);
    cleanup=mount(Object.freeze({pseudo:current.pseudo,document,window}));
    revision=response.revision;identity=current.pseudo;
   }
  }catch(e){stop();console.warn('UPTEP+ : accès indisponible ou désactivé.');}finally{busy=false;}
 }
 // Authorize every page and recheck/reload remote revisions without reinstalling.
 refresh();setInterval(refresh,30000);
 setInterval(()=>{const p=player();if(p.alliance!=='UPTEP'||(identity&&p.pseudo!==identity))stop();},1000);
 document.addEventListener('visibilitychange',()=>{if(!document.hidden)refresh();});
 window.addEventListener('pagehide',stop);
})();
