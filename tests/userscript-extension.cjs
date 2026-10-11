const fs=require('node:fs'),assert=require('node:assert/strict'),pp=require('/root/maudits-lighthouse/node_modules/puppeteer-core');
(async()=>{const b=await pp.launch({executablePath:'/usr/bin/chromium',headless:true,enableExtensions:['/root/uptep-site/tests/vendor/violentmonkey'],args:['--no-sandbox','--enable-unsafe-extension-debugging']});try{
 const target=await b.waitForTarget(t=>t.type()==='service_worker'&&t.url().startsWith('chrome-extension://'),{timeout:15000});const id=new URL(target.url()).host;
 const settings=await b.newPage();await settings.goto('chrome://extensions');
 await settings.evaluate(async id=>{await chrome.developerPrivate.updateProfileConfiguration({inDeveloperMode:true});await chrome.developerPrivate.updateExtensionConfiguration({extensionId:id,userScriptsAccess:true});},id);
 const page=await b.newPage();await page.goto(`chrome-extension://${id}/options/index.html`);
 const code=fs.readFileSync('/root/uptep-script-install-test.user.js','utf8');const result=await page.evaluate(code=>chrome.runtime.sendMessage({cmd:'ParseScript',data:{code,url:'https://uptep.top/uptep.user.js',from:'https://uptep.top/script.php'}}),code);
 if(result?.error)throw Error('Extension installation failed');
 const game=await b.newPage(),player=JSON.parse(fs.readFileSync('/root/uptep-script-test-player.json'));
 await game.setRequestInterception(true);game.on('request',r=>r.respond({status:200,contentType:'text/html',body:'<!doctype html><html><head><meta http-equiv="Content-Security-Policy" content="script-src \'self\'"></head><body><div id="pseudo">'+player.pseudo+'</div><div id="tag_alliance">UPTEP</div><div id="menuBoite"><div id="boiteComptePlus"><div class="titre_colonne_cliquable">Compte +</div><div class="contenu_boite_compte_plus">Contenu</div></div></div></body></html>'}));
 await game.goto('https://s5.fourmizzz.fr/uptep-test');await game.waitForSelector('#uptep-plus-badge',{timeout:20000});assert.equal(await game.$eval('#uptep-plus-badge .titre_colonne_cliquable',e=>e.textContent),'UPTEP+');
 console.log('OK : extension Violentmonkey 2.49.0 réelle sur Chromium, requête authentifiée et code distant exécuté malgré la CSP de la page.');
}finally{await b.close();}})().catch(e=>{console.error(e.message);process.exitCode=1});
