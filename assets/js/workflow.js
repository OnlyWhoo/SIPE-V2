'use strict';
document.querySelectorAll('input[name="movement"]').forEach(input=>input.addEventListener('change',()=>{
  document.querySelectorAll('[data-selected-id]').forEach(field=>field.value=input.value);
  document.querySelectorAll('[data-selection-action]').forEach(control=>{
    const enabled=input.dataset[control.dataset.selectionAction]==='1';
    if(control.tagName==='A'){
      control.classList.toggle('disabled',!enabled);control.setAttribute('aria-disabled',String(!enabled));
      if(enabled){control.href=control.dataset.base+'&id='+encodeURIComponent(input.value);}else{control.removeAttribute('href');}
    }else{control.disabled=!enabled;}
  });
  document.getElementById('selection-status').textContent='Registro seleccionado. Las acciones disponibles corresponden a su estado actual.';
}));
document.querySelectorAll('[data-print]').forEach(button=>button.addEventListener('click',()=>window.print()));
