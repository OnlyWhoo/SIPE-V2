import {createRequire} from 'node:module';
import {writeFileSync} from 'node:fs';
import {fileURLToPath} from 'node:url';
import assert from 'node:assert/strict';
const root=fileURLToPath(new URL('../',import.meta.url));const require=createRequire(import.meta.url);
const {chromium}=require(process.env.SIPE_PLAYWRIGHT||'playwright');
const browser=await chromium.launch({headless:true,executablePath:process.env.SIPE_BROWSER_PATH});
try{
 const page=await browser.newPage();await page.goto('http://127.0.0.1:8085/AUTO/',{timeout:60000});await page.waitForURL(/r=login/,{timeout:60000});
 assert.equal(await page.getByRole('heading',{name:'Preparar SIPE V2'}).count(),0);
 await page.getByLabel('Correo electrónico').fill('profesor@sipe.local');await page.getByLabel('Contraseña',{exact:true}).fill('Sipe2026!');await page.getByRole('button',{name:'Iniciar sesión'}).click();await page.waitForURL(/r=dashboard/);
 await page.getByRole('button',{name:'Salir',exact:true}).click();await page.waitForURL(/r=login/);
 const text='OK ruta automática desde ZIP: primer GET crea base, esquema y DEMO sin formulario, login y logout correctos. Defaults del servidor de laboratorio apuntan a 3312; no se certifica un servidor ajeno.\n';console.log(text);writeFileSync(root+'var/runtime/auto-web-results.txt',text);
}finally{await browser.close();}
