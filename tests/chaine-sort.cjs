const assert=require('node:assert/strict');
const puppeteer=require('/root/maudits-lighthouse/node_modules/puppeteer-core');
(async()=>{const browser=await puppeteer.launch({executablePath:'/usr/bin/chromium',headless:true,args:['--no-sandbox']});try{
for(const edit of [false,true]){
 const p=await browser.newPage();
 const data=[['A','Alpha','20','Grenier','10'],['B','Beta','100','Chasseur','2'],['C','Charlie','9','',''],['D','Delta','9007199254740993','Passeur','12']];
 const rows=data.map(([id,name,field,grade,order])=>`<tr data-player="${name}" data-player-id="${id}" data-field="${field}" data-grade="${grade}" data-order="${order}"><td>${name}</td><td>${field}</td><td>${edit?`<select class="chaine-grade"><option value="">Non attribué</option>${['Grenier','Passeur','Chasseur','A flooder','Ne pas toucher'].map(g=>`<option ${g===grade?'selected':''}>${g}</option>`).join('')}</select>`:grade}</td><td>${edit?`<input class="chaine-order" value="${order}">`:order}</td></tr>`).join('');
 await p.setContent(`<section class="users-panel"><table class="chaine-table"><thead><tr>${['player','field','grade','order'].map(key=>`<th aria-sort="none"><button data-sort="${key}">${key}</button></th>`).join('')}</tr></thead><tbody>${rows}</tbody></table><span data-sort-status></span></section>`);
 await p.addScriptTag({path:'/root/uptep-site/public/assets/site.js'});
 async function sort(key,expected){await p.click(`[data-sort=${key}]`);assert.deepEqual(await p.$$eval('tbody tr',es=>es.map(e=>e.dataset.playerId)),expected);}
 await sort('field',['C','A','B','D']);await sort('field',['D','B','A','C']);
 await sort('player',['A','B','C','D']);await sort('player',['D','C','B','A']);
 await sort('order',['A','D','B','C']);await sort('order',['A','D','B','C']);
 await sort('grade',['A','D','B','C']);await sort('grade',['A','D','B','C']);
 if(edit){await p.select('[data-player-id=B] select','Grenier');await p.$eval('[data-player-id=B] input',e=>e.value='1');await sort('grade',['B','A','D','C']);assert.equal(await p.$eval('[data-player-id=B] input',e=>e.value),'1');await sort('order',['B','A','D','C']);await sort('order',['A','B','D','C']);}
 assert.equal(await p.$$eval('th[aria-sort]:not([aria-sort=none])',es=>es.length),1);
 await p.close();
}console.log('OK : 4 colonnes, deux sens, tri numérique exact, valeurs vides en dernier, lecture/édition et saisies conservées.');
}finally{await browser.close();}})().catch(e=>{console.error(e);process.exitCode=1;});
