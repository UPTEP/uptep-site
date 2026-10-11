const fs=require('node:fs');const assert=require('node:assert/strict');const puppeteer=require('/root/maudits-lighthouse/node_modules/puppeteer-core');
(async()=>{const b=await puppeteer.launch({executablePath:'/usr/bin/chromium',headless:true,args:['--no-sandbox']});try{
 const p=await b.newPage();const [u]=JSON.parse(fs.readFileSync('/root/uptep-admin-tests.json'));const errors=[];
 p.on('pageerror',e=>errors.push(e.message));p.on('console',m=>{if(m.text().includes('Content Security Policy'))errors.push(m.text());});
 await p.goto('https://uptep.top/login.php',{waitUntil:'networkidle0'});await p.type('#username',u.username);await p.type('#password',u.password);await Promise.all([p.waitForNavigation({waitUntil:'networkidle0'}),p.click('.primary-button')]);await p.goto('https://uptep.top/admin.php',{waitUntil:'networkidle0'});
 for(const width of [320,390,768,1440]){await p.setViewport({width,height:900});assert.equal(await p.evaluate(()=>document.documentElement.scrollWidth>innerWidth),false,`overflow ${width}`);}
 await p.screenshot({path:'/root/uptep-site/previews/administration-desktop.png'});
 await p.setViewport({width:390,height:844});assert.equal(await p.$eval('.users-scroll',e=>e.scrollWidth>e.clientWidth),true);await p.screenshot({path:'/root/uptep-site/previews/administration-mobile.png',fullPage:true});
 await p.$eval('.users-scroll',e=>e.scrollLeft=e.scrollWidth);assert.ok(await p.$eval('.save-user',e=>e.getBoundingClientRect().right<=innerWidth));
 assert.deepEqual(errors,[]);console.log('OK: 320, 390, 768 et 1440 px ; tableau défilant ; bouton Enregistrer accessible ; aucune erreur JS/CSP.');
}finally{await b.close();}})().catch(e=>{console.error(e);process.exitCode=1;});
