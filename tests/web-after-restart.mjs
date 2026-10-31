import {createRequire} from 'node:module';
import {writeFileSync} from 'node:fs';
import {fileURLToPath} from 'node:url';
import assert from 'node:assert/strict';
const root=fileURLToPath(new URL('../',import.meta.url));const require=createRequire(import.meta.url);
const {chromium}=require(process.env.SIPE_PLAYWRIGHT||'playwright');
const base=process.env.SIPE_TEST_URL;
const browser=await chromium.launch({headless:true,executablePath:process.env.SIPE_BROWSER_PATH});
try{
 const context=await browser.newContext();const page=await context.newPage();
 await page.goto(base);assert.match(page.url(),/r=login/);assert.equal(await page.getByRole('heading',{name:'Preparar SIPE V2'}).count(),0);
 await page.getByLabel('Correo electrónico').fill('profesor@sipe.local');await page.getByLabel('Contraseña',{exact:true}).fill('Sipe2026!');await page.getByRole('button',{name:'Iniciar sesión'}).click();await page.waitForURL(/r=dashboard/);
 await page.goto(base+'index.php?r=requests&q=DEMO-TAB-01&estado=TODAS');assert.match(await page.locator('main').innerText(),/DEMO-TAB-01/);
 await page.getByRole('button',{name:'Salir',exact:true}).click();await page.waitForURL(/r=login/);
 const result='OK reinicio de Apache y MariaDB aislados: login DEMO, registros persistentes, sin asistente ni reinstalación, logout correcto.\n';console.log(result);writeFileSync(root+'var/runtime/restart-web-results.txt',result);
}finally{await browser.close();}
