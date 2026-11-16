// Run against the isolated hotfix fixture (25+ kiosks), never production.
const {chromium} = require('./node_modules/playwright-core');
const fs = require('fs');
const path = require('path');
const BASE = process.env.BASE || 'http://127.0.0.1:8142';
const SHOTS = path.join(__dirname, 'shots', 'kiosk-insight-hotfix');
fs.mkdirSync(SHOTS, {recursive: true});
(async () => {
 const browser = await chromium.launch({executablePath:'C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe',headless:true});
 const results=[];
 const errors=[];
 async function check(v,msg){results.push({ok:!!v,msg});if(!v)throw new Error(msg);}
 async function login(email){const ctx=await browser.newContext({viewport:{width:1280,height:900}});const p=await ctx.newPage();p.on('pageerror',e=>{errors.push(e.message); console.error(e.stack);}); p.on('console',m=>{if(m.type()==='error')console.error(m.text());});p.on('dialog',async d=>{errors.push('DIALOG:'+d.message());await d.dismiss();});await p.goto(BASE + '/login');await p.locator('#email').fill(email);await p.locator('#password').fill('password');await p.locator('button[type=submit]').click();await p.waitForURL(/\/owner\/dashboard|\/admin$/,{timeout:30000});return p;}
 try {
  const p=await login('hotfix.owner@example.test');
  for (const mode of [500,419,'network']) {
   await p.goto(BASE + '/kiosk-insights');
   await p.getByRole('button',{name:'Export Excel + Foto',exact:true}).click();
   await p.locator('[data-kiosk-insight-active]').waitFor();
   let intercepted=false;
   await p.route('**/livewire/update',async r=>{
    const body=r.request().postData()||'';
    if(!intercepted && body.includes('advance')){intercepted=true; if(mode==='network') await r.abort('failed'); else await r.fulfill({status:mode,contentType:'text/html',body:'Injected error'});}
    else await r.continue();
   });
   await p.getByRole('button',{name:'Lanjutkan',exact:true}).waitFor({timeout:30000});
   await check(intercepted,mode+' intercepted poll');
   const id=new URL(p.url()).searchParams.get('export');
   await check(!!id,mode+' UUID saved in current URL');
   const paused=p.locator('[data-kiosk-insight-active]');
   await check(await paused.getByRole('alert').isVisible(),mode+' inline error visible');
   await p.screenshot({path:path.join(SHOTS, 'hotfix-error-'+mode+'.png'),fullPage:true});
   await p.unroute('**/livewire/update');
   await p.getByRole('button',{name:'Lanjutkan',exact:true}).click();
   await p.getByRole('link',{name:'Unduh Laporan',exact:true}).waitFor({timeout:60000});
   await check(new URL(p.url()).searchParams.get('export')===id,mode+' resumes same export');
   await p.screenshot({path:path.join(SHOTS, 'hotfix-owner-'+mode+'.png'),fullPage:true});
  }
  const a=await login('hotfix.admin@example.test');
  await a.goto(BASE + '/kiosk-insights');
  await a.waitForURL('**/admin/laporan-kedai');
  await check(await a.getByRole('link',{name:'Laporan Kedai',exact:true}).count()>0,'admin sidebar menu');
  await check(await a.getByRole('link',{name:'← Dashboard',exact:true}).getAttribute('href')===BASE + '/admin','admin back URL');
  await check(!(await a.getByText('Panel Owner',{exact:true}).count()),'admin has no owner layout');
  await a.waitForFunction(()=>getComputedStyle(document.body).opacity==='1');
  await a.screenshot({path:path.join(SHOTS, 'hotfix-admin.png'),fullPage:true});
  await check(!errors.some(x=>x.startsWith('DIALOG:')),'no expired popup / dialogs');
  await check(errors.length===0,'no browser JS errors');
 } finally {console.log(JSON.stringify({results,errors},null,2));await browser.close();}
})().catch(e=>{console.error(e);process.exitCode=1;});
