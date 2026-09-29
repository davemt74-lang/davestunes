(() => {
  'use strict';
  const overlay=document.querySelector('[data-desktop-splash]');
  if(!overlay)return;

  const mode=overlay.dataset.splashMode||'public';
  const pending=new Set(mode==='desktop'
    ? ['window','desktop','library','featured','objects']
    : ['window','content']);

  let released=false;
  let failSafe=null;

  const mark=key=>{
    pending.delete(key);
    overlay.dataset.pending=Array.from(pending).join(',');
    if(!pending.size)release('ready');
  };

  const release=reason=>{
    if(released)return;
    released=true;
    if(failSafe)clearTimeout(failSafe);
    const root=document.getElementById('dt-desktop-root');
    if(root){
      root.dataset.desktopReady='true';
      root.dataset.desktopReadyReason=reason;
    }
    overlay.dataset.splashState='leaving';
    const done=()=>{
      if(!overlay.isConnected)return;
      overlay.remove();
      document.documentElement.classList.remove('desktop-loading');
      document.dispatchEvent(new CustomEvent('davestunes:desktop:splash-cleared',{detail:{reason}}));
    };
    overlay.addEventListener('animationend',done,{once:true});
    setTimeout(done,900);
  };

  document.documentElement.classList.add('desktop-loading');
  overlay.dataset.pending=Array.from(pending).join(',');

  if(document.readyState==='complete')mark('window');
  else window.addEventListener('load',()=>mark('window'),{once:true});

  if(mode==='public'){
    requestAnimationFrame(()=>requestAnimationFrame(()=>mark('content')));
  }else{
    const listen=(name,key)=>document.addEventListener('davestunes:desktop:'+name,()=>mark(key),{once:true});
    listen('ready','desktop');
    listen('library-loaded','library');
    listen('library-error','library');
    listen('featured-loaded','featured');
    listen('featured-error','featured');
    listen('objects-loaded','objects');
    listen('object-error','objects');

    if(window.DaveTunesDesktop?.state?.ready)mark('desktop');
    if(!document.querySelector('[data-desktop-featured]'))mark('featured');
    if(!document.querySelector('[data-library-canvas]'))mark('library');
    if(!document.querySelector('[data-desktop-layer="objects"]'))mark('objects');
  }

  failSafe=setTimeout(()=>release('failsafe'),7000);
})();