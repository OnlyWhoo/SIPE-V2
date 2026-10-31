'use strict';
document.querySelectorAll('form[data-confirm]').forEach(form=>form.addEventListener('submit',event=>{if(!window.confirm(form.dataset.confirm)){event.preventDefault();}}));
const scanButton=document.getElementById('scan-qr');
if(scanButton){scanButton.addEventListener('click',async()=>{
  const status=document.getElementById('scan-status');const video=document.getElementById('qr-video');let stream;
  if(!('BarcodeDetector' in window)||!navigator.mediaDevices){status.textContent='Este navegador no admite lectura QR con cámara. Utiliza un lector externo, el identificador QR o la búsqueda por código en Estudiantes.';return;}
  try{const detector=new BarcodeDetector({formats:['qr_code']});stream=await navigator.mediaDevices.getUserMedia({video:{facingMode:'environment'}});video.srcObject=stream;video.hidden=false;scanButton.disabled=true;status.textContent='Acerca el código QR a la cámara.';
    const stop=()=>{stream.getTracks().forEach(track=>track.stop());video.hidden=true;scanButton.disabled=false;};
    window.addEventListener('pagehide',stop,{once:true});
    const endAt=Date.now()+60000;
    const read=async()=>{try{if(Date.now()>endAt){stop();status.textContent='Lectura finalizada. Puedes volver a intentar.';return;}const codes=await detector.detect(video);const code=codes.find(item=>/^[a-f0-9]{64}$/.test(item.rawValue));if(code){document.getElementById('qr-token').value=code.rawValue;stop();status.textContent='Código leído. Confirma la consulta del estudiante.';}else{setTimeout(read,250);}}catch{stop();status.textContent='No fue posible leer el QR. Utiliza el identificador o busca al estudiante.';}};setTimeout(read,500);
  }catch{if(stream){stream.getTracks().forEach(track=>track.stop());}scanButton.disabled=false;video.hidden=true;status.textContent='No se pudo acceder a la cámara. Revisa el permiso o utiliza la búsqueda por código.';}
});}
